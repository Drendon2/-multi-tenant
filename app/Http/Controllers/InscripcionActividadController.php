<?php

namespace App\Http\Controllers;

use App\Models\Actividad;
use App\Models\InscritoActividad;
use App\Support\ErrorDeBaseDeDatos;
use App\Support\InstitucionActual;
use App\Support\Reglas;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * El formulario que abre el enlace de una actividad.
 *
 * Es la unica pantalla del sistema que no pide sesion NI crea cuenta. Lo que
 * autoriza a entrar es el token de la URL, y nada mas: de ahi que sea largo.
 *
 * Un token que no existe da 404 y ya. Uno que existe pero esta cerrado SI dice
 * el nombre de la actividad y por que no admite gente, y eso es deliberado:
 * quien llega con ese enlace lo recibio de alguien y necesita saber si llego
 * tarde o si se equivoco de sitio. No hay nada que proteger ahi —el enlace ya
 * lo tiene— y callarselo solo confunde a la persona equivocada.
 *
 * No pide foto ni copia de documento, por la misma razon que la inscripcion de
 * estudiantes: no se suben archivos desde un formulario publico sin autenticar.
 */
class InscripcionActividadController extends Controller
{
    public function mostrar(string $token): View
    {
        $actividad = $this->buscar($token);

        return view('publico.inscripcion-actividad', [
            'actividad' => $actividad,
            'sesiones' => $actividad->sesiones()->get(),
            'admite' => $actividad->admiteInscripciones(),
        ]);
    }

    public function guardar(Request $request, string $token): RedirectResponse
    {
        $actividad = $this->buscar($token);

        // Se comprueba en el POST y no solo al pintar: esconder el formulario
        // no cierra la URL, y entre que se abrio la pagina y se envio pudo
        // llenarse el cupo o cerrarse el enlace.
        if (! $actividad->admiteInscripciones()) {
            return back()->with('error', $this->porQueNoAdmite($actividad));
        }

        $datos = $request->validate([
            'nombre_completo' => Reglas::nombreDePersona(90),
            'documento' => Reglas::documento(),
            'telefono' => Reglas::celular(),
            // El correo NO es obligatorio: a un taller de ninos se apunta gente
            // que no tiene uno, y bloquear la inscripcion por eso es perder a la
            // persona, no ganar el dato.
            'correo' => Reglas::correoSegunLaInstitucion(120),
            'fecha_nacimiento' => ['required', 'date', 'before:today'],
        ], Reglas::mensajes(), [
            'nombre_completo' => 'nombre completo',
            'fecha_nacimiento' => 'fecha de nacimiento',
        ]);

        $seLleno = false;

        try {
            DB::transaction(function () use ($actividad, $datos, &$seLleno) {
                // Dentro de la transaccion y con bloqueo: dos personas enviando
                // el formulario a la vez para el ultimo cupo tienen que
                // quedarse una fuera, y contar sin bloquear deja pasar a las
                // dos. Es la misma carrera que el trigger resuelve del lado de
                // las matriculas.
                $bloqueada = Actividad::whereKey($actividad->id)->lockForUpdate()->first();

                if (! $bloqueada->admiteInscripciones()) {
                    $seLleno = true;

                    return;
                }

                $actividad->inscritos()->create([
                    ...$datos,
                    // Si el documento es de un estudiante del sistema, queda
                    // atado a su ficha. Para casi todos sera null.
                    'perfil_id' => InscritoActividad::perfilConDocumento($datos['documento'])?->id,
                    'origen' => InscritoActividad::ENLACE,
                ]);
            });
        } catch (QueryException $e) {
            // SOLO el unico de (actividad, documento) significa "ya se habia
            // apuntado", y eso si es buena noticia: el resultado que queria ya
            // esta puesto.
            //
            // Cualquier otro fallo del motor NO puede contarse como exito.
            // Antes este catch los tragaba todos y respondia "ya estabas
            // inscrito" ante una base caida o un CHECK violado: la persona se
            // iba creyendo que estaba dentro sin estarlo, y como aqui no hay
            // cuenta ni correo, nadie volvia a saber de ella. Es el unico
            // formulario publico que escribe, asi que era el peor sitio del
            // sistema donde tener un catch ancho.
            if (! ErrorDeBaseDeDatos::esInscripcionRepetida($e)) {
                // Se registra ANTES de relanzar: sin esto, en produccion
                // (`LOG_LEVEL=error`) queda la traza pero no de que actividad
                // era ni con que documento, que es lo unico que permite avisar
                // luego a quien se quedo fuera.
                Log::error('Fallo una inscripcion por enlace', [
                    'actividad_id' => $actividad->id,
                    'documento' => $datos['documento'],
                    'motivo' => $e->getMessage(),
                ]);

                throw $e;
            }

            return redirect()
                ->route('actividad-inscribirse', $token)
                ->with('success', 'Ya estabas inscrito con ese documento. No hace falta hacer nada más.');
        }

        if ($seLleno) {
            return back()->with('error', $this->porQueNoAdmite($actividad->fresh()));
        }

        return redirect()
            ->route('actividad-inscribirse', $token)
            ->with('success', "¡Listo! Quedaste inscrito en {$actividad->nombre}.");
    }

    /**
     * La actividad de ese token, si es de las que tienen puerta publica.
     *
     * Un token que no existe y uno que existe se distinguen solo por lo que
     * pasa despues: aqui los dos responden lo mismo mientras el token no sea
     * valido, que es lo que impide usar esta ruta para adivinar tokens.
     *
     * EL CORTE POR TIPO VA AQUI Y NO EN CADA METODO, que es lo que hace que
     * valga: por este metodo pasan el GET y el POST, y una de las dos sin el
     * corte es la que deja entrar.
     *
     * Un PROGRAMA EXTERNO tiene token —la columna es obligatoria para los
     * cuatro tipos— y ese token NO abre nada. Su lista la escribe el profesor
     * en la otra institucion, con nombres de menores que no son de esta casa;
     * una URL publica que anada gente a esa lista, o peor, que la ensene, es
     * exactamente lo que no puede existir. Y NO BASTA con que nazca
     * `abierta = false`: eso solo lo deja «cerrado», que esta pantalla cuenta
     * —con el nombre del programa— a quien llegue con el enlace.
     *
     * 404 y no un aviso: para quien llega por ahi, esa URL no existe.
     */
    private function buscar(string $token): Actividad
    {
        // Enlace publico: se busca SIN el filtro de institucion y se adopta
        // la de la fila (ver `InstitucionActual::adoptar()`).
        $actividad = Actividad::sinFiltroDeInstitucion()
            ->where('token', $token)
            ->whereIn('tipo', Actividad::TIPOS_CON_ENLACE)
            ->firstOrFail();

        InstitucionActual::adoptar($actividad->institucionId());

        return $actividad;
    }

    /** Por que esta cerrada, dicho para quien acaba de llegar por el enlace. */
    private function porQueNoAdmite(Actividad $actividad): string
    {
        return $actividad->abierta
            ? "{$actividad->nombre} ya llenó sus cupos."
            : "Las inscripciones a {$actividad->nombre} están cerradas.";
    }
}
