<?php

namespace App\Http\Controllers;

use App\Models\Clase;
use App\Models\ConfirmacionClase;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Support\ClasesPendientes;
use App\Support\GestionAsistida;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Las clases de sus grupos, para que el estudiante confirme que se dieron.
 *
 * Es la otra mitad del boton del profesor: quien registra la clase es parte
 * interesada, asi que hasta que suficientes estudiantes den fe, la clase queda
 * registrada pero sin verificar.
 */
class MisClasesController extends Controller
{
    public function index(Request $request): View
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');
        $periodo = Periodo::enCurso();
        // Por `ClasesPendientes` y no `Clase::porConfirmar` directo: el
        // envoltorio pide la misma cifra para el punto rojo del menu, y sin la
        // memoria esta pantalla la calcularia DOS veces por peticion.
        $filas = ClasesPendientes::filas($perfil, $periodo);

        return view('estudiante.mis-clases', [
            // La accion no se esconde: se queda apagada y diciendo por que.
            'enAsistida' => GestionAsistida::activa(),
            'periodo' => $periodo,
            'filas' => $filas,
            'porConfirmar' => Clase::esperanConfirmacion($filas),
            'horasPlazo' => Clase::VENTANA_CONFIRMACION_HORAS,
        ]);
    }

    /**
     * Dar fe de una clase NO se hace desde una gestion asistida.
     *
     * Es la otra cara de la garantia que ya protege la asistencia. Quien
     * registra la clase es parte interesada; por eso la verifican los
     * estudiantes, desde su propia sesion y con plazo. Si el administrador puede
     * confirmar desde la cuenta del estudiante, la clase acaba avalada por la
     * misma casa que la registro y la confirmacion deja de probar nada.
     *
     * Una sola funcion para las dos acciones —confirmar y quitar la
     * confirmacion— a proposito: repartida en dos, la que se olvide no falla,
     * deja pasar. Y el mensaje dice el motivo de VERDAD; reusar el del plazo
     * vencido habria sido mas corto y habria mentido.
     */
    private function vetadoPorAsistida(): ?RedirectResponse
    {
        if (! GestionAsistida::activa()) {
            return null;
        }

        return $this->volver(
            'Confirmar una clase solo lo puede hacer el propio estudiante desde su sesión: '
            .'es lo que hace que la confirmación valga como prueba de que la clase se dio.'
        );
    }

    public function confirmar(Request $request, Clase $clase): RedirectResponse
    {
        if ($veto = $this->vetadoPorAsistida()) {
            return $veto;
        }

        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');
        $fila = $this->filaDeClase($perfil, $clase->id);

        if ($fila === null) {
            return $this->volver(
                'Esa clase no es de ninguno de tus grupos, o es anterior a tu matrícula, '
                .'así que no la puedes confirmar.'
            );
        }

        // Va ANTES que el plazo a proposito: los dos motivos pueden darse a la
        // vez y este es el que explica por que a esta persona no le sale el
        // boton y a la de al lado si. Decir «venció el plazo» seria cierto y
        // mandaria a reclamar por donde no es.
        if ($fila['consta_ausente']) {
            return $this->volver(
                'En esa clase quedaste marcado como que no asististe, así que no la puedes '
                .'confirmar: dar fe de una clase es decir que la viste. Si crees que la falta '
                .'está mal puesta, díselo a tu profesor.'
            );
        }

        // El plazo se comprueba aqui y no solo escondiendo el boton: una
        // peticion enviada desde una pestana que quedo abierta antes de que
        // venciera llegaria igual, y a destiempo.
        if (! $fila['abierta']) {
            return $this->volver(
                'El plazo para confirmar esa clase ya venció: solo se puede hasta '
                .Clase::VENTANA_CONFIRMACION_HORAS.' horas después, y esa terminó el '
                .$this->textoPlazo($fila['limite']).'.'
            );
        }

        // Las dos comprobaciones de arriba se quedan porque son las que saben
        // DECIR que pasa; esta escribe. `registrar()` vuelve a mirar el plazo y
        // la falta —es la unica puerta de escritura, y por ella entra tambien el
        // carne QR que lee el profesor— asi que un null aqui solo puede ser una
        // carrera contra el reloj o contra una falta recien puesta: se vuelve
        // sin ruido, igual que cuando ya estaba confirmada.
        $confirmacion = ConfirmacionClase::registrar(
            $fila['clase'],
            $fila['matricula'],
            $fila['asistencia'],
            ConfirmacionClase::PROPIA,
        );

        if ($confirmacion === null || ! $confirmacion->wasRecentlyCreated) {
            return $this->volver('');
        }

        return $this->volver(
            "Confirmaste la clase de {$fila['clase']->grupo->promotoria->nombre} del "
            .$fila['clase']->fecha_hora->format('d/m/Y').'.',
            exito: true
        );
    }

    /**
     * Deshace la confirmacion propia: se equivoco de renglon.
     *
     * Rige el mismo plazo que para confirmar. Si retirar siguiera abierto
     * despues, una clase ya verificada podria dejar de estarlo semanas mas
     * tarde, cuando el registro ya se dio por cerrado.
     *
     * NO lleva el corte de `consta_ausente` que si lleva confirmar, y es a
     * proposito: una falta puede marcarse DESPUES de que la persona confirmara
     * —el profesor corrige la lista al dia siguiente— y con el corte aqui esa
     * confirmacion quedaria clavada, sin nadie que pudiera quitarla. Cerrar una
     * puerta de entrada no es razon para cerrar la de salida.
     */
    public function retirar(Request $request, Clase $clase): RedirectResponse
    {
        if ($veto = $this->vetadoPorAsistida()) {
            return $veto;
        }

        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');
        $fila = $this->filaDeClase($perfil, $clase->id);

        if ($fila === null) {
            return $this->volver('');
        }

        if (! $fila['abierta']) {
            return $this->volver(
                'Ya no puedes cambiar esa clase: el plazo terminó el '
                .$this->textoPlazo($fila['limite']).'.'
            );
        }

        $borradas = ConfirmacionClase::where('clase_id', $fila['clase']->id)
            ->where('matricula_id', $fila['matricula']->id)
            ->delete();

        return $borradas
            ? $this->volver('Quitaste tu confirmación de esa clase.', exito: true)
            : $this->volver('');
    }

    /**
     * La clase dentro de lo que este estudiante puede confirmar, o null.
     *
     * Pasa por `Clase::porConfirmar` a proposito, en vez de comprobar la
     * matricula por su cuenta: asi el boton y el permiso salen de la MISMA regla
     * —solo los grupos donde esta inscrito, y solo las clases posteriores a su
     * matricula— y no pueden separarse el dia que la regla cambie.
     *
     * @return array<string, mixed>|null
     */
    private function filaDeClase(Perfil $perfil, int $claseId): ?array
    {
        foreach (Clase::porConfirmar($perfil, Periodo::enCurso()) as $fila) {
            if ($fila['clase']->id === $claseId) {
                return $fila;
            }
        }

        return null;
    }

    /** El limite del plazo, para un mensaje ("14/08/2026 a las 21:15"). */
    private function textoPlazo(Carbon $limite): string
    {
        return $limite->format('d/m/Y \a \l\a\s H:i');
    }

    private function volver(string $mensaje, bool $exito = false): RedirectResponse
    {
        $respuesta = redirect()->route('mis-clases');

        if ($mensaje !== '') {
            $respuesta->with($exito ? 'success' : 'error', $mensaje);
        }

        return $respuesta;
    }
}
