<?php

namespace App\Http\Controllers;

use App\Models\Asistencia;
use App\Models\Clase;
use App\Models\ConfirmacionClase;
use App\Models\Grupo;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Support\CarneQr;
use App\Support\Fragmento;
use App\Support\PaseDeLista;
use App\Support\Permisos;
use App\Support\ResumenAsistencia;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Clases dictadas y asistencia.
 *
 * Lo LEE todo el panel (quien dicta la promotoria, direccion y administracion);
 * lo ESCRIBE solo quien la dicta. Ver `Permisos::dictaLaPromotoria`.
 */
class ClaseController extends Controller
{
    private const SOLO_EL_PROFESOR =
        'Registrar clases y pasar lista es de quien dicta la promotoría. Si no tiene a '
        .'nadie asignado —o si eres tú quien la dicta—, asígnala en Gestión → Promotorías.';

    /**
     * Registra la clase que empieza ahora y lleva a pasar lista.
     *
     * La hora que queda guardada es la de este momento, que es justo el dato que
     * el boton existe para capturar: no se pide ni se puede escribir a mano.
     *
     * Si el grupo ya tiene una clase registrada HOY no se crea otra: se lleva a
     * la lista de esa. Un grupo tiene un solo horario, asi que dos registros el
     * mismo dia son casi siempre el mismo boton pulsado dos veces —y partir la
     * asistencia del dia en dos listas a medias es peor que cualquier caso raro
     * que esto impida.
     */
    public function nueva(Request $request, Grupo $grupo): RedirectResponse
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');

        if (! Permisos::dictaLaPromotoria($perfil, $grupo->promotoria)) {
            return redirect()->route('panel')->with('error', self::SOLO_EL_PROFESOR);
        }

        $periodo = Periodo::enCurso();

        if ($periodo === null) {
            return redirect()->route('panel')->with(
                'error',
                'No hay un periodo en curso, así que la clase no se puede registrar en ninguno. '
                .'Pide que marquen el periodo en curso desde Gestión.'
            );
        }

        $deHoy = Clase::where('grupo_id', $grupo->id)
            ->whereDate('fecha_hora', today())
            ->first();

        if ($deHoy !== null) {
            // `success` y no un aviso: esto no es un error, quien pulso acaba
            // donde queria, en la lista de su clase de hoy.
            return redirect()->route('clase-asistencia', $deHoy)->with(
                'success',
                'Ya habías registrado una clase de este grupo hoy a las '
                .$deHoy->fecha_hora->format('H:i').'. Esta es su lista.'
            );
        }

        $clase = Clase::abrir($grupo, $periodo, $perfil);

        return redirect()->route('clase-asistencia', $clase);
    }

    /**
     * Pasar lista: quien vino, quien falto y quien falto con excusa.
     *
     * Se puede volver a abrir y corregir cuantas veces haga falta —el que llega
     * tarde y el que trae la excusa al dia siguiente son el caso normal, no la
     * excepcion—, asi que guardar actualiza o crea, no da de alta.
     *
     * Dejar a alguien sin marcar es valido a proposito (ver `Asistencia`): la
     * pantalla lo avisa, pero no bloquea el guardado.
     */
    public function asistencia(Request $request, Clase $clase): View|RedirectResponse
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');

        $clase->load(['grupo.promotoria.area', 'grupo.sesiones', 'periodo']);

        if (! Permisos::puedeGestionarPromotoria($perfil, $clase->grupo->promotoria)) {
            return redirect()->route('panel')->with('error', 'No tienes acceso a esta promotoría.');
        }

        return view('panel.asistencia', $this->datosDeLaHoja($clase, $perfil));
    }

    /**
     * Todo lo que la hoja de asistencia necesita para pintarse.
     *
     * Esta aparte porque lo usan las DOS ramas —abrir la pantalla y guardarla
     * sin recargar—, y ese es justo el punto: si cada una armara sus datos, la
     * hoja recien guardada y la hoja recien abierta serian dos renderizados
     * distintos de lo mismo, libres de separarse sin que nada falle y sin que
     * ninguna prueba lo note.
     *
     * SIEMPRE relee de la base. Llamada despues de guardar, las marcas que salen
     * son las que quedaron escritas y no las que venian en el formulario: lo que
     * el profesor ve confirmado es el estado real.
     *
     * @return array<string, mixed>
     */
    private function datosDeLaHoja(Clase $clase, Perfil $perfil): array
    {
        $clase->load(['grupo.promotoria.area', 'grupo.sesiones', 'periodo']);

        $puedeMarcar = Permisos::dictaLaPromotoria($perfil, $clase->grupo->promotoria);
        $matriculas = $clase->matriculasAPasar();

        $yaMarcado = $clase->asistencias()->pluck('estado', 'matricula_id')->all();
        $estudiantes = [];

        foreach ($matriculas as $matricula) {
            $estado = $yaMarcado[$matricula->id] ?? '';

            $estudiantes[] = [
                'matricula' => $matricula,
                'perfil' => $matricula->estudiante,
                'estado' => $estado,
                // La HUELLA del carne, no el carne. Es lo que le permite al
                // lector de QR reconocer al vuelo a quien acaba de escanear sin
                // que la pantalla lleve encima nada que sirva para hacerse pasar
                // por el (ver `CarneQr::huella`). Vacia si esa persona todavia
                // no tiene codigo: NO se le crea uno aqui, porque eso escribiria
                // en cuarenta perfiles cada vez que alguien abre una lista.
                'huella' => ($matricula->estudiante->codigo_qr ?? '') === ''
                    ? ''
                    : CarneQr::huella($matricula->estudiante->codigo_qr),
                // Quien dicta se entera de que esta de salida, igual que en el
                // panel: la marca es informativa y no cambia que haya que
                // pasarle lista.
                'cancelacion' => $matricula->cancelacion_pendiente,
                // Para quien mira sin poder editar: lo mismo, pero como marcador
                // de estado en vez de opcion marcable.
            ];
        }

        // Del lado de quien dicta se ensena CUANTAS confirmaciones lleva la
        // clase, nunca quien la confirmo. El numero le dice lo que necesita
        // saber —si la clase ya quedo verificada—, mientras que la lista de
        // nombres convertiria una verificacion en algo que el verificado puede
        // reclamarle a cada estudiante por su nombre.
        $confirmaciones = $clase->confirmaciones()->count();

        return [
            'clase' => $clase,
            'estudiantes' => $estudiantes,
            'estados' => Asistencia::ESTADOS,
            'puedeMarcar' => $puedeMarcar,
            'sinPasar' => count(array_filter($estudiantes, fn ($e) => $e['estado'] === '')),
            'confirmaciones' => $confirmaciones,
            'requeridas' => $clase->confirmaciones_requeridas,
            'verificada' => $clase->estaConfirmada($confirmaciones),
            'vencida' => $clase->verificacionVencida($confirmaciones),
            'limiteConfirmacion' => $clase->limite_confirmacion,
        ];
    }

    /**
     * Guarda la hoja.
     *
     * Los dos rechazos de arriba siguen REDIRIGIENDO aunque se pida el
     * fragmento, y es a proposito: la respuesta que llega entonces no lleva la
     * cabecera de vuelta, asi que `acciones.js` se encuentra una pagina normal y
     * hace lo de siempre. Solo el camino bueno se acorta.
     */
    public function guardarAsistencia(Request $request, Clase $clase): RedirectResponse|Response
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');

        if (! Permisos::puedeGestionarPromotoria($perfil, $clase->grupo->promotoria)) {
            return redirect()->route('panel')->with('error', 'No tienes acceso a esta promotoría.');
        }

        // Se comprueba aqui y no solo escondiendo los controles: la peticion
        // llega igual si alguien la envia a mano.
        if (! Permisos::dictaLaPromotoria($perfil, $clase->grupo->promotoria)) {
            return redirect()->route('clase-asistencia', $clase)->with('error', self::SOLO_EL_PROFESOR);
        }

        $matriculas = $clase->matriculasAPasar();

        $marcados = PaseDeLista::guardar(
            request: $request,
            asistencias: Asistencia::class,
            sesion: ['clase_id' => $clase->id],
            quien: 'matricula_id',
            ids: $matriculas->pluck('id'),
        );

        // Va DESPUES de guardar la hoja y no antes, y ese orden es la regla:
        // confirma solo a quien acabo de quedar marcado «Asistio». Si corriera
        // antes, confirmaria mirando lo que el formulario TRAIA en vez de lo que
        // quedo escrito, y el profesor que escanea a alguien y luego le cambia
        // la marca a «Falto» dejaria una confirmacion contradiciendo su propia
        // lista.
        $confirmadas = $this->confirmarLosCarnesLeidos($request, $clase, $matriculas);

        $sinMarcar = $matriculas->count() - $marcados;

        $mensaje = $sinMarcar
            ? "Asistencia guardada. Quedaron {$sinMarcar} "
                .($sinMarcar === 1 ? 'estudiante' : 'estudiantes')
                .' sin marcar: puedes volver a esta clase y completarlos.'
            : 'Asistencia guardada.';

        if ($confirmadas > 0) {
            // El VERBO concuerda tambien, no solo el sustantivo: «se verificaron
            // 1 asistencia» es lo que salia, y se vio en el navegador con la
            // suite en verde. Es el mismo descuido que ya se corrigio una vez en
            // una de las dos verticales de asistencia y se repitio dos horas
            // despues en la otra.
            $mensaje .= $confirmadas === 1
                ? ' Con el carné se verificó 1 asistencia.'
                : " Con el carné se verificaron {$confirmadas} asistencias.";
        }

        // Sin JavaScript, la redireccion de siempre. Es la rama por defecto, no
        // el remiendo: la otra solo existe si alguien pidio el fragmento.
        if (! Fragmento::loPide($request)) {
            return redirect()->route('clase-asistencia', $clase)->with('success', $mensaje);
        }

        // Con JavaScript se contesta YA con la hoja recien guardada y se ahorra
        // el viaje del GET al que llevaba la redireccion. Es `now()` y no
        // `with()` porque el mensaje se pinta en ESTA respuesta: encolarlo lo
        // dejaria esperando una peticion que ya no va a haber, y reapareceria
        // pegado a la siguiente pantalla que el profesor abriera.
        session()->now('success', $mensaje);

        return Fragmento::responder('panel.asistencia', $this->datosDeLaHoja($clase, $perfil));
    }

    /**
     * Los carnes que la camara leyo en esta hoja: marca y confirma.
     *
     * POR QUE LOS CODIGOS VIAJAN Y NO SOLO LA CASILLA. El navegador ya sabe a
     * quien escaneo —coteja huellas, ver `CarneQr::huella`— y podria mandar la
     * lista de matriculas y ya. No lo hace: en esa version, para dar por buenas
     * quince clases bastaria con escribir quince numeros en el formulario, y
     * quien tiene la pantalla abierta es justamente a quien estas confirmaciones
     * vigilan. Mandando el CODIGO, escribirlo a mano exige tener el carne
     * delante, que es exactamente lo que la funcion dice que paso: el estudiante
     * estuvo ahi y lo enseno.
     *
     * LO QUE ESTO NO IMPIDE, escrito aqui para quien venga a moverlo: un
     * profesor que se quede con los carnes de sus estudiantes —fotografiandolos
     * mientras los escanea, por ejemplo— puede confirmar sus propias clases.
     * Por eso el carne de otra persona solo lo saca administracion (ver
     * `CarneController`) y por eso existe renovarlo. Es el mismo riesgo del
     * carne de cartulina de toda la vida, asumido a sabiendas el 21/09/2026: la
     * alternativa era dejar fuera de la verificacion a quien no sabe entrar al
     * sistema, que es a quien esto viene a servir.
     *
     * @param  Collection<int, Matricula>  $matriculas
     */
    private function confirmarLosCarnesLeidos(Request $request, Clase $clase, Collection $matriculas): int
    {
        $leidos = $request->input('qr');

        if (! is_array($leidos) || $leidos === []) {
            return 0;
        }

        // Se limpia ANTES de consultar: lo que no tiene forma de carne no llega
        // a ser una consulta. `array_unique` porque una camara encendida lee el
        // mismo cuadrito veinte veces por segundo.
        $codigos = [];

        foreach ($leidos as $leido) {
            $codigo = is_string($leido) ? CarneQr::codigoLeido($leido) : null;

            if ($codigo !== null) {
                $codigos[$codigo] = true;
            }
        }

        if ($codigos === []) {
            return 0;
        }

        // Una consulta para los dos mapas, no una por carne.
        $perfiles = Perfil::query()
            ->whereIn('codigo_qr', array_keys($codigos))
            ->where('rol', 'estudiante')
            ->pluck('id');

        // SOLO LOS DE ESTA HOJA. Un carne de otro grupo se lee igual de bien, y
        // sin este cruce quedaria confirmando una clase a la que esa persona ni
        // pertenece. `matriculasAPasar()` es quien decide quien esta en la lista,
        // y aqui no se vuelve a decidir: se pregunta.
        $deLaHoja = $matriculas->whereIn('estudiante_id', $perfiles);

        if ($deLaHoja->isEmpty()) {
            return 0;
        }

        // El estado TAL COMO QUEDO ESCRITO, releido de la base: es lo que decide
        // si esa persona puede dar fe (ver `ConfirmacionClase::registrar`).
        $estados = $clase->asistencias()
            ->whereIn('matricula_id', $deLaHoja->pluck('id'))
            ->pluck('estado', 'matricula_id');

        $confirmadas = 0;

        foreach ($deLaHoja as $matricula) {
            $confirmacion = ConfirmacionClase::registrar(
                $clase,
                $matricula,
                $estados[$matricula->id] ?? null,
                ConfirmacionClase::CARNE,
            );

            if ($confirmacion?->wasRecentlyCreated) {
                $confirmadas++;
            }
        }

        return $confirmadas;
    }

    /**
     * Las clases dictadas de un grupo en el periodo en curso, y como va cada
     * quien.
     *
     * Es la otra mitad del boton de "Iniciar clase": sin esta pantalla la
     * asistencia seria un dato que solo se escribe. Sirve para las dos preguntas
     * que se hacen de verdad —que dias hubo clase y quien esta dejando de
     * venir— y es el unico camino para volver a una lista y corregirla.
     *
     * La ve todo el panel, incluida direccion: la supervision es justamente para
     * lo que existe este registro. Lo que ellos no tienen es el boton de abrir
     * una clase.
     */
    public function delGrupo(Request $request, Grupo $grupo): View|RedirectResponse
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');

        $grupo->load('promotoria.area');

        if (! Permisos::puedeGestionarPromotoria($perfil, $grupo->promotoria)) {
            return redirect()->route('panel')->with('error', 'No tienes acceso a esta promotoría.');
        }

        $periodo = Periodo::enCurso();
        [$clases, $filas] = ResumenAsistencia::deGrupo($grupo, $periodo);

        return view('panel.grupo-clases', [
            'grupo' => $grupo,
            'periodo' => $periodo,
            'clases' => $clases,
            'filas' => $filas,
            'puedeMarcar' => Permisos::dictaLaPromotoria($perfil, $grupo->promotoria),
        ]);
    }
}
