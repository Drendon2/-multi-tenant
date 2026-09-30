<?php

namespace App\Http\Controllers\Gestion;

use App\Models\Actividad;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Support\Auditoria;
use App\Support\Reglas;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Lo que comparten las dos pantallas de actividades.
 *
 * Cursos y talleres viven en un boton de Gestion y los grupos de proyeccion en
 * otro, porque asi se piensan; pero por debajo son la misma tabla y el mismo
 * formulario, y lo unico que las separa es que filtran por tipo.
 *
 * El listado NO pasa por `gestion.lista`. Aquella plantilla sirve a los cuatro
 * catalogos del arbol academico y todos ellos ensenan lo mismo: un nombre, que
 * cuelga de el y que impide borrarlo. Una actividad tiene ademas un enlace que
 * copiar, un cupo y un interruptor de abierta o cerrada, y estirar la plantilla
 * compartida para que quepan es como se acaba con una plantilla que pregunta de
 * que pantalla se trata.
 */
abstract class ActividadController extends RecursoController
{
    /** El tipo, o los tipos, que administra esta pantalla. */
    abstract protected function tipos(): array;

    protected function modelo(): string
    {
        return Actividad::class;
    }

    /**
     * El listado propio.
     *
     * `listado()` sigue existiendo porque la clase de arriba la exige, pero no
     * se usa: quien pinta aqui es `index()`.
     */
    protected function listado(Request $request): array
    {
        return [];
    }

    public function index(Request $request): View
    {
        return view('gestion.actividades', $this->seccion($request));
    }

    /**
     * Lo mismo que `index()` le pasa a la vista, para poder pintar esta tabla
     * dentro de «Programas formativos». Ver `RecursoController::seccion()`.
     *
     * @return array<string, mixed>
     */
    public function seccion(Request $request): array
    {
        return [
            ...$this->textos(),
            'modal' => $this->cabeEnModal(),
            // EL DIRECTOR SOLO LAS QUE DIRIGE, desde el 12/09/2026. Una
            // actividad no cuelga de un departamento —lo que tiene es una
            // PERSONA responsable— asi que «asignarsela» a un director es
            // ponerlo de responsable. Misma regla que `Permisos::puedeVerActividad()`.
            'actividades' => Actividad::with(['responsable', 'periodo'])
                ->when(
                    $request->attributes->get('perfil')->rol !== 'administrador',
                    fn ($q) => $q->where('responsable_id', $request->attributes->get('perfil')->id)
                )
                // El conteo por `withCount` y no recorriendo la relacion: el
                // listado pinta una fila por actividad y `sesiones` dentro del
                // bucle costaria una consulta por fila.
                ->withCount(['sesiones', 'inscritos'])
                ->whereIn('tipo', $this->tipos())
                ->orderBy('nombre')
                ->get(),
        ];
    }

    /**
     * El periodo en curso queda anotado al crear, si lo hay.
     *
     * No se pregunta en el formulario a proposito: no es una decision, es el
     * momento en que se creo. Y admite no haber ninguno, porque montar un
     * taller es de las primeras cosas que hace quien estrena el sistema, antes
     * de tener periodos.
     */
    protected function atributosFijos(Request $request): array
    {
        return ['periodo_id' => Periodo::enCurso()?->id];
    }

    /**
     * Abre o cierra el enlace a mano.
     *
     * Es lo unico que puede parar una actividad sin cupo: esa no se llena
     * nunca. Y en una con cupo sirve para lo otro —"ya empezamos, no reciban
     * mas"—, que no es lo mismo que estar llena.
     */
    public function alternarEnlace(string $id): RedirectResponse
    {
        /** @var Actividad $actividad */
        $actividad = $this->buscar($id);
        $actividad->abierta = ! $actividad->abierta;
        $actividad->save();

        // Cerrar el enlace deja fuera a quien llegue despues, y quien llega por
        // un enlace no tiene cuenta: no hay a quien preguntarle luego si se
        // quedo sin entrar. Se registra tambien la reapertura porque la
        // pregunta que se hace de verdad es «¿cuanto tiempo estuvo cerrado?», y
        // con un solo extremo no se responde.
        Auditoria::registrar('actividad.enlace', [
            'actividad_id' => $actividad->id,
            'abierta' => $actividad->abierta,
        ], auth()->user()?->perfil);

        return redirect()->route($this->textos()['ruta_lista'])->with(
            'success',
            $actividad->abierta
                ? "El enlace de «{$actividad->nombre}» vuelve a recibir inscripciones."
                : "El enlace de «{$actividad->nombre}» queda cerrado."
        );
    }

    /** @return array<string, array<string, mixed>> */
    protected function campos(Request $request, ?Model $objeto): array
    {
        return [
            'nombre' => ['etiqueta' => 'Nombre', 'tipo' => 'text', 'max' => 80],
            'responsable_id' => [
                'etiqueta' => 'Responsable',
                'tipo' => 'select',
                // Profesor, director o administrador. Quien dirige una banda
                // suele ser el director de la escuela, y pedir el rol
                // "profesor" lo dejaria fuera de su propio ensayo.
                'opciones' => Perfil::whereIn('rol', Perfil::ROLES_PERSONAL)
                    ->orderBy('nombre_completo')
                    ->pluck('nombre_completo', 'id')
                    ->all(),
                'ayuda' => 'Quien inicia las sesiones y pasa lista.',
            ],
            'cupo_maximo' => [
                'etiqueta' => 'Cupo máximo',
                'tipo' => 'number',
                'min' => 1,
                'opcional' => true,
                'ayuda' => 'Déjalo en blanco si no hay tope. Al llenarse, el enlace deja de admitir gente.',
            ],
        ];
    }

    /** @return array<string, array<int, mixed>> */
    protected function reglas(Request $request, ?Model $objeto): array
    {
        return [
            'nombre' => Reglas::texto(80),
            'responsable_id' => [
                'required',
                Reglas::existe('perfiles')->whereIn('rol', Perfil::ROLES_PERSONAL),
            ],
            // `nullable` y no `sometimes`: el campo SIEMPRE llega, vacio cuando
            // no hay tope, y un vacio tiene que valer.
            'cupo_maximo' => ['nullable', 'integer', 'min:1', 'max:65535'],
        ];
    }

    /**
     * Solo se llega a lo que administra ESTA pantalla.
     *
     * Sin esto, la pantalla de cursos podria editar un grupo de proyeccion con
     * solo cambiar el id de la URL, y su desplegable de tipo —que solo ofrece
     * curso y taller— lo convertiria en otra cosa al guardar. Las dos pantallas
     * las ve el mismo personal, asi que no es un agujero de permisos; es que
     * cada una tiene que responder por lo suyo.
     */
    protected function buscar(string $id): Model
    {
        /** @var Perfil $perfil */
        $perfil = request()->attributes->get('perfil');

        // Y DESDE EL 12/09/2026 tambien por quien mira: un director solo toca
        // las actividades que dirige. Va aqui porque `buscar()` es por donde
        // pasan editar, actualizar y borrar — el listado ya estaba acotado, y
        // sin esta linea el recorte era solo cosmetico: se llegaba por URL.
        return Actividad::whereIn('tipo', $this->tipos())
            ->when(
                $perfil->rol !== 'administrador',
                fn ($q) => $q->where('responsable_id', $perfil->id)
            )
            ->findOrFail($id);
    }
}
