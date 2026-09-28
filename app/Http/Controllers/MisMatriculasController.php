<?php

namespace App\Http\Controllers;

use App\Models\EncuestaSatisfaccion;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Support\Reglas;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * El historial del estudiante: en que promotorias esta y en cuales estuvo, mas
 * el boton para salirse de las del periodo en curso.
 */
class MisMatriculasController extends Controller
{
    /**
     * Va agrupado por periodo y no como una lista plana por fecha, porque el
     * periodo es la unidad en la que el estudiante piensa su paso por la casa
     * («el semestre pasado hice guitarra») y porque es lo que separa lo vigente
     * —donde todavia puede retirarse— del historial ya cerrado.
     */
    public function index(Request $request): View
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');
        $periodo = Periodo::enCurso();

        return view('estudiante.mis-matriculas', [
            'historial' => Matricula::historialPorPeriodo($perfil),
            'resumen' => Matricula::resumenTrayectoria($perfil),
            // Sin esto la plantilla no puede distinguir el periodo en curso de
            // uno terminado, y ofreceria "Cancelar matricula" en matriculas ya
            // cerradas.
            'periodoActualId' => $periodo?->id,
        ]);
    }

    /**
     * Salirse de una matricula. Que significa eso depende de en que estado este.
     */
    public function retirar(Request $request, Matricula $matricula): RedirectResponse
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');

        // Solo las suyas. Un 404 y no un 403: que exista o no la matricula de
        // otro no es asunto de quien pregunta.
        abort_unless($matricula->estudiante_id === $perfil->id, 404);

        // Retirarse solo aplica al periodo en curso: un periodo terminado es
        // historial cerrado, no algo de lo que uno pueda salirse a posteriori.
        $periodo = Periodo::enCurso();

        if ($periodo === null || $matricula->periodo_id !== $periodo->id) {
            return $this->volver(
                "{$matricula->periodo} ya terminó: sus matrículas quedan como historial y no se "
                .'pueden retirar. Solo puedes retirarte de una matrícula del periodo en curso.'
            );
        }

        // Una matricula que nadie confirmo todavia NO es una desercion: es una
        // solicitud que el estudiante retira antes de que le respondan, y
        // obligarlo a esperar el visto bueno de un director para eso solo
        // llenaria la cola de la direccion. Se cancela en el acto.
        if ($matricula->estado === Matricula::PENDIENTE) {
            $matricula->estado = Matricula::RETIRADA;
            $matricula->motivo_retiro = Matricula::RETIRO_PROPIO;
            $matricula->repartirEn([]);
            $matricula->save();

            return $this->volver(
                "Retiraste tu solicitud a {$matricula->promotoria}. Como todavía no estaba "
                .'confirmada, no hace falta que nadie la apruebe.',
                exito: true
            );
        }

        // Desde una matricula ya activa, salirse pasa a ser una SOLICITUD: la
        // decision es de un director o administrador. Hasta que la resuelvan, la
        // matricula sigue ocupando cupo y ranura, porque el estudiante sigue
        // inscrito.
        if ($matricula->estado === Matricula::ACTIVA) {
            $this->guardarEncuesta($request, $perfil, $matricula);

            $matricula->estado = Matricula::CANCELACION_SOLICITADA;
            $matricula->save();

            return $this->volver(
                $matricula->cancelacion_es_rechazable
                    ? "Tu solicitud para cancelar {$matricula->promotoria} quedó registrada. "
                        .'Como eres menor de edad, la dirección hablará con tu acudiente antes '
                        .'de resolverla; mientras tanto sigues inscrito.'
                    : "Tu solicitud para cancelar {$matricula->promotoria} quedó registrada. "
                        .'Sigues inscrito hasta que la dirección la tramite.',
                exito: true
            );
        }

        // Ya retirada, o con la cancelacion en tramite: no hay nada que hacer y
        // tampoco nada que avisar — el boton ni siquiera se pinta en esos casos.
        return $this->volver('', exito: true);
    }

    /**
     * Echarse atras de una cancelacion que la direccion todavia no resolvio.
     *
     * Existe desde el 28/09/2026, pedido por el usuario: quien cancelaba por
     * accidente no tenia vuelta. Siendo MAYOR, la direccion solo puede aprobar
     * su salida —a un mayor no se le discute—, asi que la cancelacion iba a
     * acabar retirandolo si o si. Aqui la decision sigue siendo suya: la misma
     * persona que pidio salir dice que se queda.
     *
     * Vuelve a ACTIVA con sus grupos intactos, porque nunca dejo de estar: la
     * cancelacion en tramite ocupa su silla y su cupo hasta que se resuelve.
     * La encuesta de salida, si la contesto, se queda: es una valoracion de
     * verdad, igual que la que se contesta al renovar.
     */
    public function seguir(Request $request, Matricula $matricula): RedirectResponse
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');

        abort_unless($matricula->estudiante_id === $perfil->id, 404);

        // Si la direccion ya la resolvio entre que se pinto el boton y se
        // pulso, no hay nada de que echarse atras, y hay que decirlo: callar
        // dejaria creer que sigue inscrito.
        if ($matricula->estado !== Matricula::CANCELACION_SOLICITADA) {
            return $this->volver(
                $matricula->estado === Matricula::RETIRADA
                    ? "La dirección ya aprobó tu salida de {$matricula->promotoria}. Si fue un "
                        .'error, habla con la institución: pueden readmitirte.'
                    : ''
            );
        }

        $matricula->estado = Matricula::ACTIVA;
        $matricula->save();

        return $this->volver(
            "Sigues matriculado en {$matricula->promotoria}. Tu solicitud de cancelación quedó anulada.",
            exito: true
        );
    }

    /**
     * La pantalla que pide la encuesta antes de tramitar una salida.
     *
     * Solo aparece cuando la matricula esta ACTIVA. Una pendiente no ha tenido
     * ni una clase: preguntarle a alguien que nunca entro «¿como te fue?» no
     * recoge una opinion, recoge ruido, y ademas pone un formulario entre esa
     * persona y un boton que hasta ahora era inmediato.
     */
    public function confirmarRetiro(Request $request, Matricula $matricula): View|RedirectResponse
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');

        abort_unless($matricula->estudiante_id === $perfil->id, 404);

        $periodo = Periodo::enCurso();

        if ($matricula->estado !== Matricula::ACTIVA || $matricula->periodo_id !== $periodo?->id) {
            return redirect()->route('mis-matriculas');
        }

        return view('estudiante.retirar', [
            'matricula' => $matricula,
            // Si ya la valoro —por ejemplo al renovar— no se le vuelve a
            // preguntar lo mismo.
            'yaValoro' => EncuestaSatisfaccion::where('perfil_id', $perfil->id)
                ->where('periodo_id', $matricula->periodo_id)
                ->where('promotoria_id', $matricula->promotoria_id)
                ->exists(),
        ]);
    }

    /**
     * Guarda la encuesta de salida, si es que vino.
     *
     * NO es obligatoria, y esa es una decision deliberada: quien se va puede
     * estar molesto o tener una urgencia, y poner cinco preguntas entre esa
     * persona y la puerta es a la vez una grosería y una forma segura de
     * recoger respuestas puestas al azar para poder salir. La pantalla la pide
     * con claridad y deja marcharse sin contestar.
     *
     * `firstOrCreate` y no `create`: puede haberla contestado ya al renovar, y
     * el indice unico rechazaria la segunda.
     */
    private function guardarEncuesta(Request $request, Perfil $perfil, Matricula $matricula): void
    {
        if (! $request->filled('satisfaccion_general')) {
            return;
        }

        $datos = $request->validate([
            'satisfaccion_general' => ['required', 'integer', 'between:1,5'],
            'calificacion_profesor' => ['required', 'integer', 'between:1,5'],
            'horario_funciono' => ['required', 'boolean'],
            'recomendaria' => ['required', 'boolean'],
            // Mil caracteres, que es un comentario largo de verdad. La columna
            // es TEXT y hasta hoy este campo no tenia NINGUN tope: cualquiera
            // con sesion podia escribir megabytes en la base de una peticion.
            'comentario' => Reglas::texto(1000, obligatorio: false),
        ], Reglas::mensajes());

        EncuestaSatisfaccion::firstOrCreate(
            [
                'perfil_id' => $perfil->id,
                'periodo_id' => $matricula->periodo_id,
                'promotoria_id' => $matricula->promotoria_id,
            ],
            $datos
        );
    }

    private function volver(string $mensaje, bool $exito = false): RedirectResponse
    {
        $respuesta = redirect()->route('mis-matriculas');

        if ($mensaje !== '') {
            $respuesta->with($exito ? 'success' : 'error', $mensaje);
        }

        return $respuesta;
    }
}
