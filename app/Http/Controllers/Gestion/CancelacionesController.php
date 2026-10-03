<?php

namespace App\Http\Controllers\Gestion;

use App\Http\Controllers\Controller;
use App\Models\Clase;
use App\Models\ConfiguracionInstitucion;
use App\Models\Grupo;
use App\Models\Matricula;
use App\Models\OmisionArchivada;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Support\Alertas;
use App\Support\Auditoria;
use App\Support\FichasIncompletas;
use App\Support\Permisos;
use App\Support\Reglas;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * ALERTAS Y CANCELACIONES: la bandeja de lo que hay que atender.
 *
 * Nacio para las cancelaciones pedidas por estudiantes y desde el 02/09/2026
 * lleva ademas dos alertas que NO las pide nadie — las deduce el sistema
 * cruzando lo que deberia haber pasado con lo que hay registrado:
 *
 * - Clases que un grupo tenia en su horario y no se dictaron.
 * - Estudiantes con demasiadas faltas seguidas, o sea un posible abandono sin
 *   cancelar la matricula.
 *
 * Las tres cosas comparten pantalla porque comparten publico y momento: son lo
 * que direccion revisa cuando se sienta a ver que hace falta atender. Las dos
 * alertas se calculan al abrir y no se guardan (ver `Support\Alertas`), y cada
 * institucion las enciende o las apaga desde Configuracion.
 *
 * Solo direccion: quien dicta ve sus cancelaciones marcadas en su panel, pero no
 * decide. Aprobar retira la matricula de verdad y libera el cupo; rechazar la
 * devuelve a activa, y eso ultimo solo cabe con menores de edad (ver
 * `Matricula::$cancelacion_es_rechazable`).
 *
 * NINGUNA alerta cambia nada por su cuenta. La de abandono trae al lado la
 * accion de retirar, y la aprieta una persona: retirar libera el cupo y la
 * ranura, que es una consecuencia sobre quien esta esperando ese cupo.
 */
class CancelacionesController extends Controller
{
    /**
     * Cuantas solicitudes por pagina.
     *
     * La bandeja se vacia de a tandas y cada resolucion vuelve por `acciones.js`
     * sin recargar, asi que la pagina se va quedando corta sola: 50 es holgado
     * para una jornada de trabajo sin que la respuesta cargue el historico
     * entero de solicitudes que nadie atendio.
     */
    public const POR_PAGINA = 50;

    /**
     * Cuantas clases no dictadas se enseñan de una vez.
     *
     * No se paginan: se archivan, y una lista que se vacia por arriba con la
     * pagina 2 debajo se lee mal. Se enseñan las mas recientes —que son las que
     * todavia se pueden recuperar hablando con quien dicta— y se dice cuantas
     * quedan detras.
     */
    public const OMISIONES_VISIBLES = 50;

    public function index(Request $request): View|RedirectResponse
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');

        // `queVe()`: el director, solo las de sus departamentos. Faltaba desde
        // el 12/09/2026 —las dos alertas de abajo si lo llevaban— y lo reporto
        // el usuario el 27/09 mirando desde la cuenta de un director.
        $pendientes = Matricula::queVe($perfil)
            ->where('estado', Matricula::CANCELACION_SOLICITADA)
            ->with([
                'estudiante.datosEstudiante.acudiente',
                'promotoria.area',
                'periodo',
                'grupos',
            ])
            ->join('periodos', 'periodos.id', '=', 'matriculas.periodo_id')
            ->join('promotorias', 'promotorias.id', '=', 'matriculas.promotoria_id')
            ->orderBy('periodos.fecha_inicio')
            ->orderBy('promotorias.nombre')
            // Desempate por id: dos solicitudes de la misma promotoria y el
            // mismo periodo quedan en un orden que el motor no esta obligado a
            // repetir, y al paginar eso reparte filas repetidas o perdidas
            // entre una pagina y la siguiente. Sin prueba, y por la misma razon
            // que su gemelo en UsuarioController: ver el comentario de alli.
            ->orderBy('matriculas.id')
            ->select('matriculas.*')
            ->paginate(self::POR_PAGINA);

        // Resolver una solicitud la SACA de esta lista, asi que vaciar la
        // ultima pagina la hace desaparecer y deja la peticion apuntando mas
        // alla del final. Sin esto se veria una bandeja vacia con «No hay
        // cancelaciones pendientes» mientras quedan cincuenta en la pagina
        // anterior. No hace falta en la lista de usuarios: alli desactivar a
        // alguien le cambia el estado, no lo quita de la lista.
        if ($pendientes->currentPage() > $pendientes->lastPage()) {
            return redirect()->route('gestion-cancelaciones', ['page' => $pendientes->lastPage()]);
        }

        $config = ConfiguracionInstitucion::actual();
        $periodo = Periodo::enCurso();

        // Sin periodo en curso no hay nada que cruzar: ni horario que mirar ni
        // clases que buscar. Las dos alertas se apagan solas.
        //
        // Se piden CON las ya atendidas y se parten aqui: las de la bandeja y
        // las que ya tienen causa, que se enseñan aparte para poder CORREGIR
        // una clasificacion equivocada. Es el mismo recorrido —las mismas
        // cuatro consultas— y asi las dos listas no pueden discrepar.
        $todas = ($config->alerta_clase_no_dictada && $periodo)
            ? Alertas::clasesNoDictadas($periodo, $perfil, conArchivadas: true)
            : collect();
        $omisiones = $todas->where('archivada', false)->values();
        $atendidas = $todas->where('archivada', true)->values();

        return view('gestion.cancelaciones', [
            'pendientes' => $pendientes,
            // Se enseñan las mas recientes y se dice cuantas hay. En la base de
            // desarrollo salieron 596 de golpe —un periodo desde enero con 26
            // grupos y 41 clases registradas—, y una tabla de 596 filas no es
            // una bandeja de trabajo: es un muro. Las recientes son ademas las
            // unicas que todavia se pueden recuperar hablando con quien dicta.
            'clasesNoDictadas' => $omisiones->take(self::OMISIONES_VISIBLES),
            'omisionesTotales' => $omisiones->count(),
            'omisionesAtendidas' => $atendidas->take(self::OMISIONES_VISIBLES),
            'atendidasTotales' => $atendidas->count(),
            'causas' => OmisionArchivada::CAUSAS,
            'faltasVencidas' => $atendidas->where('vencida', true)->count(),
            'diasParaReponer' => $config->dias_para_reponer,
            // El formulario del festivo solo tiene sentido con la alerta
            // encendida: apagada, no hay bandeja que limpiar.
            'alertaClases' => (bool) $config->alerta_clase_no_dictada,
            'abandonos' => ($config->alerta_abandono && $periodo)
                ? Alertas::posiblesAbandonos($periodo, $perfil)
                : collect(),
            'periodo' => $periodo,
            // La tercera bandeja entra por un BOTON con su cifra, y no como una
            // seccion mas: son ~810 personas en produccion y desplegarlas aqui
            // convertiria la pantalla en un muro. Lo que se calcula aqui es solo
            // el numero; la lista se arma en su propia pantalla.
            //
            // La cifra cuenta PERSONAS y no motivos: a quien le faltan tres
            // cosas es una ficha que atender, no tres. Y el desglose va debajo
            // del boton porque un «810» a secas no dice si vale la pena entrar.
            'fichasIncompletas' => $this->resumenDeFichas($perfil),
        ]);
    }

    /**
     * Cuantas fichas incompletas hay y por que, para el boton de entrada.
     *
     * Se calcula entero aunque aqui solo se usen las cifras, y eso es a
     * proposito: el desglose sale del MISMO recorrido que la lista, asi que el
     * numero del boton y lo que se ve al entrar no pueden discrepar. Contar por
     * separado con ocho consultas sueltas seria mas barato y dejaria dos
     * verdades que se separan en cuanto alguien toque un motivo.
     *
     * @return array{total: int, porMotivo: array<string, int>}
     */
    private function resumenDeFichas(Perfil $quienMira): array
    {
        $fichas = FichasIncompletas::todas($quienMira);

        return [
            'total' => count($fichas),
            'porMotivo' => FichasIncompletas::porMotivo($fichas),
        ];
    }

    /**
     * Atiende una clase no dictada diciendo POR QUE no se dio.
     *
     * Es lo UNICO que se guarda de las alertas, y es porque esta no se arregla
     * nunca: el martes 12 ya paso. Hasta el 03/10/2026 solo se ARCHIVABA; desde
     * ese dia se elige la causa (decision del usuario), porque una incapacidad
     * y una falta sin aviso no pesan igual: la falta le cuenta al profesor y le
     * aparece en el Panel para reponerla, la excusa y el dia sin clase de la
     * institucion no. Ver `OmisionArchivada::CAUSAS`.
     *
     * Sirve tambien para CORREGIR una causa ya puesta: la bandeja enseña las
     * atendidas con el mismo formulario. Si una falta ya repuesta pasa a
     * excusa, la reposicion se queda enlazada —la clase se dio, y borrar el
     * enlace la convertiria en la clase de su dia en las alertas—.
     *
     * `firstOrNew` y no `create`: dos personas pueden clasificar la misma
     * desde dos pestañas, y la clave unica (grupo, fecha) haria fallar la
     * segunda. Que gane la ultima es exactamente lo que se quiere.
     *
     * `clasificada_en` cambia SOLO si cambia la causa: es desde cuando corre el
     * plazo para reponer, y volver a pulsar «Falta» no puede regalar otros
     * quince dias.
     */
    public function clasificarOmision(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'grupo_id' => ['required', Reglas::existe('grupos')],
            'fecha' => ['required', 'date'],
            'causa' => ['required', Rule::in(array_keys(OmisionArchivada::CAUSAS))],
        ], [
            'causa.required' => 'Elige por qué no se dio la clase.',
            'causa.in' => 'Elige por qué no se dio la clase.',
        ]);

        $this->cerrarSiEsAjena(Grupo::findOrFail($datos['grupo_id'])->promotoria);

        $this->ponerCausa(
            OmisionArchivada::firstOrNew(['grupo_id' => $datos['grupo_id'], 'fecha' => $datos['fecha']]),
            $datos['causa'],
        );

        return $this->volver(match ($datos['causa']) {
            OmisionArchivada::FALTA => 'Quedó como falta. A quien dicta le aparece en su Panel para reponerla.',
            OmisionArchivada::EXCUSA => 'Quedó como excusa: no se repone ni le cuenta como perdida.',
            default => 'Quedó como día sin clase por la institución.',
        }, exito: true);
    }

    /**
     * Clasifica VARIAS de una vez (03/10/2026, pedido del usuario): en
     * produccion la bandeja puede traer cientos, y las archivadas de antes
     * quedaron «Sin clasificar».
     *
     * Cada casilla manda «grupo|fecha», porque una omision pendiente no tiene
     * fila todavia. Lo que llegue mal formado o de un grupo que quien marca no
     * ve se queda fuera EN SILENCIO, como en el lote de cancelaciones: la
     * pantalla no lo pinta, asi que solo llega compuesto a mano. Y por la misma
     * puerta que la fila suelta (`ponerCausa`), asi que el plazo de una falta
     * ya marcada no se reinicia por estar en el lote.
     */
    public function clasificarLote(Request $request): RedirectResponse
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');

        $request->validate([
            'causa' => ['required', Rule::in(array_keys(OmisionArchivada::CAUSAS))],
        ], [
            'causa.required' => 'Elige por qué no se dieron las clases.',
            'causa.in' => 'Elige por qué no se dieron las clases.',
        ]);
        $causa = (string) $request->input('causa');

        $pares = collect((array) $request->input('omisiones', []))
            ->filter(fn ($v) => is_string($v) && preg_match('/^(\d+)\|(\d{4})-(\d{2})-(\d{2})$/', $v, $m)
                && checkdate((int) $m[3], (int) $m[4], (int) $m[2]))
            ->map(fn (string $v) => array_combine(['grupo_id', 'fecha'], explode('|', $v)))
            ->unique(fn ($p) => $p['grupo_id'].'|'.$p['fecha']);

        $visibles = Grupo::whereIn('id', $pares->pluck('grupo_id')->unique())
            ->whereHas('promotoria', function ($q) use ($perfil) {
                /** @var Builder<Promotoria> $q */
                $q->queVe($perfil);
            })
            ->pluck('id')
            ->flip();

        $pares = $pares->filter(fn ($p) => $visibles->has((int) $p['grupo_id']));

        if ($pares->isEmpty()) {
            return $this->volver('No marcaste ninguna clase.');
        }

        // Las filas que ya existen, de una vez: una consulta y no una por casilla.
        $existentes = OmisionArchivada::whereIn('grupo_id', $pares->pluck('grupo_id')->unique())
            ->whereIn('fecha', $pares->pluck('fecha')->unique())
            ->get()
            ->keyBy(fn (OmisionArchivada $o) => $o->grupo_id.'|'.$o->fecha->toDateString());

        DB::transaction(function () use ($pares, $existentes, $causa) {
            foreach ($pares as $p) {
                $this->ponerCausa(
                    $existentes->get($p['grupo_id'].'|'.$p['fecha'])
                        ?? new OmisionArchivada(['grupo_id' => (int) $p['grupo_id'], 'fecha' => $p['fecha']]),
                    $causa,
                );
            }
        });

        $n = $pares->count();
        $clases = $n === 1 ? '1 clase quedó' : "{$n} clases quedaron";

        return $this->volver(match ($causa) {
            OmisionArchivada::FALTA => "{$clases} como falta. A quien dicta le aparecen en su Panel para reponerlas.",
            OmisionArchivada::EXCUSA => "{$clases} como excusa: no se reponen ni cuentan como perdidas.",
            default => "{$clases} como festivo o cierre.",
        }, exito: true);
    }

    /**
     * La UNICA forma de ponerle causa a una omision, para la fila y el lote.
     *
     * `clasificada_en` cambia SOLO si cambia la causa: es desde cuando corre el
     * plazo para reponer, y volver a decir «Falta» no puede regalar otros
     * quince dias. Escrita dos veces, una de las copias acabaria olvidandolo.
     */
    private function ponerCausa(OmisionArchivada $omision, string $causa): void
    {
        if ($omision->causa !== $causa) {
            $omision->causa = $causa;
            $omision->clasificada_en = now();
        }

        $omision->archivada_por_id = auth()->user()?->perfil?->id;
        $omision->save();
    }

    /**
     * Marca un dia como FESTIVO O CIERRE para todos los grupos que tenian clase
     * ese dia de la semana (03/10/2026, pedido del usuario).
     *
     * Un lunes festivo dejaba una alerta por cada grupo de los lunes, y habia
     * que atenderlas de una en una. Vale tambien para una fecha FUTURA —se
     * marca el festivo de la semana que viene y la alerta no llega a salir—,
     * siempre dentro del periodo en curso.
     *
     * Lo que NO toca, a proposito:
     * - Lo que ya tiene causa. Si a alguien se le marco falta ese dia, eso fue
     *   una decision sobre una persona, y se corrige a mano en «Ya atendidas».
     * - Los grupos que SI dieron clase ese dia: no hay omision que marcar. La
     *   reposicion no cuenta como clase de su dia (`sinReposiciones`).
     * - Los grupos que quien marca no ve: el director, solo los suyos.
     */
    public function marcarFestivo(Request $request): RedirectResponse
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');
        $periodo = Periodo::enCurso();

        abort_if($periodo === null, 404);

        $datos = $request->validate([
            'fecha_festivo' => [
                'required', 'date',
                'after_or_equal:'.Carbon::parse($periodo->fecha_inicio)->toDateString(),
                'before_or_equal:'.Carbon::parse($periodo->fecha_fin)->toDateString(),
            ],
        ], [
            'fecha_festivo.required' => 'Escribe la fecha del festivo o del cierre.',
            'fecha_festivo.date' => 'Escribe la fecha del festivo o del cierre.',
            'fecha_festivo.after_or_equal' => "La fecha tiene que estar dentro de {$periodo->nombre}.",
            'fecha_festivo.before_or_equal' => "La fecha tiene que estar dentro de {$periodo->nombre}.",
        ]);

        $fecha = Carbon::parse($datos['fecha_festivo'])->startOfDay();

        $grupos = Grupo::query()
            ->whereHas('sesiones', fn ($q) => $q->where('dia', $fecha->dayOfWeekIso))
            ->whereHas('promotoria', function ($q) use ($perfil) {
                /** @var Builder<Promotoria> $q */
                $q->queVe($perfil);
            })
            ->whereDoesntHave('clases', function ($q) use ($fecha) {
                // Anotada por lo mismo que la de arriba: la relacion llega sin
                // tipo y el analizador no sabe que `sinReposiciones()` existe.
                /** @var Builder<Clase> $q */
                $q->whereDate('fecha_hora', $fecha)->sinReposiciones();
            })
            ->pluck('id');

        $yaTenian = OmisionArchivada::whereIn('grupo_id', $grupos)
            ->whereDate('fecha', $fecha)
            ->get()
            ->keyBy('grupo_id');

        $marcados = 0;

        DB::transaction(function () use ($grupos, $yaTenian, $fecha, $perfil, &$marcados) {
            foreach ($grupos as $grupoId) {
                $omision = $yaTenian->get($grupoId)
                    ?? new OmisionArchivada(['grupo_id' => $grupoId, 'fecha' => $fecha->toDateString()]);

                if ($omision->causa !== null) {
                    continue;
                }

                $omision->causa = OmisionArchivada::INSTITUCION;
                $omision->clasificada_en = now();
                $omision->archivada_por_id = $perfil->id;
                $omision->save();
                $marcados++;
            }
        });

        $dia = ucfirst($fecha->isoFormat('dddd D/MM/YYYY'));
        $respetadas = $grupos->count() - $marcados;

        if ($marcados === 0) {
            return $this->volver($respetadas
                ? "{$dia}: los grupos con clase ese día ya tenían causa; no se cambió ninguno."
                : "{$dia}: ningún grupo tenía clase ese día, o todos la registraron.");
        }

        return $this->volver(
            "{$dia} quedó como festivo o cierre para {$marcados} ".($marcados === 1 ? 'grupo' : 'grupos').'.'
            .($respetadas ? " {$respetadas} ya tenían causa y no se tocaron." : ''),
            exito: true
        );
    }

    /**
     * Retira la matricula de quien dejo de venir.
     *
     * Es la misma consecuencia que aprobar una cancelacion —la matricula queda
     * retirada y el cupo se libera— pero por un camino distinto: aqui nadie
     * pidio salirse. Por eso queda en la auditoria: es una salida que decidio
     * la institucion, no el estudiante, y de esas conviene saber quien y cuando.
     */
    public function retirarPorAbandono(Matricula $matricula): RedirectResponse
    {
        abort_unless($matricula->estado === Matricula::ACTIVA, 404);
        $this->cerrarSiEsAjena($matricula->promotoria);

        $nombre = $matricula->estudiante->nombre_completo;
        $promotoria = $matricula->promotoria;

        $matricula->estado = Matricula::RETIRADA;
        $matricula->motivo_retiro = Matricula::RETIRO_ABANDONO;
        $matricula->repartirEn([]);
        $matricula->save();

        Auditoria::registrar('matricula.retirada_por_abandono', [
            'matricula_id' => $matricula->id,
            'estudiante_id' => $matricula->estudiante_id,
            'promotoria_id' => $matricula->promotoria_id,
        ], auth()->user()?->perfil);

        return $this->volver(
            "{$nombre} quedó retirado de {$promotoria}. Su cupo vuelve a estar libre.",
            exito: true
        );
    }

    public function resolver(Matricula $matricula, string $decision): RedirectResponse
    {
        abort_unless($matricula->estado === Matricula::CANCELACION_SOLICITADA, 404);
        abort_unless(in_array($decision, ['aprobar', 'rechazar'], true), 404);
        $this->cerrarSiEsAjena($matricula->promotoria);

        $nombre = $matricula->estudiante->nombre_completo;

        if ($decision === 'rechazar') {
            // La comprobacion va aqui y no solo en la plantilla: ocultar el
            // boton no impide que alguien envie el formulario a mano.
            if (! $matricula->cancelacion_es_rechazable) {
                return $this->volver(
                    "{$nombre} es mayor de edad, así que su decisión de salir no se rechaza: "
                    .'esta cancelación solo se puede aprobar.'
                );
            }

            $matricula->estado = Matricula::ACTIVA;
            $matricula->save();

            // Rechazar no se deshace por pantalla: quien quiera salir tiene que
            // volver a pedirlo. La RETIRADA la registra el propio modelo (ver
            // `Matricula::booted`), pero por ahi no pasa lo que se NIEGA, y una
            // salida negada a un menor es justo lo que alguien puede querer
            // revisar despues.
            Auditoria::registrar('cancelacion.rechazada', [
                'matricula_id' => $matricula->id,
                'estudiante_id' => $matricula->estudiante_id,
                'promotoria_id' => $matricula->promotoria_id,
            ], auth()->user()?->perfil);

            return $this->volver(
                "Cancelación rechazada: {$nombre} sigue matriculado en {$matricula->promotoria}.",
                exito: true
            );
        }

        $matricula->estado = Matricula::RETIRADA;
        $matricula->motivo_retiro = Matricula::RETIRO_CANCELACION;
        $matricula->repartirEn([]);
        $matricula->save();

        return $this->volver(
            "{$nombre} quedó retirado de {$matricula->promotoria}. Su cupo vuelve a estar libre.",
            exito: true
        );
    }

    /**
     * Resuelve varias cancelaciones de una vez.
     *
     * Al cerrar un periodo la cola se llena, y casi todas se resuelven igual.
     *
     * NO es todo o nada: cada cancelacion es independiente y la unica que puede
     * fallar lo hace por un motivo que se nombra —es de un mayor de edad y a un
     * mayor no se le rechaza la salida—. Se resuelven las que se puede y se dice
     * quien quedo fuera.
     *
     * Lo que NO cambia respecto de resolver de a una: rechazar sigue siendo solo
     * para menores. La pausa existe para hablar con el acudiente antes de que un
     * nino se salga por su cuenta; irse siendo mayor es decision propia y el
     * sistema no se la discute. Comprobarlo aqui tambien es obligatorio, porque
     * esconder el boton no impide componer el envio a mano.
     */
    public function resolverLote(Request $request): RedirectResponse
    {
        $decision = $request->input('decision');

        abort_unless(in_array($decision, ['aprobar', 'rechazar'], true), 404);

        // Las ajenas se quedan fuera en silencio, como si no se hubieran
        // marcado: la pantalla no las pinta, asi que solo llegan compuestas a mano.
        $matriculas = Matricula::queVe($request->attributes->get('perfil'))
            ->whereIn('id', (array) $request->input('matricula_ids', []))
            ->where('estado', Matricula::CANCELACION_SOLICITADA)
            ->with(['estudiante.datosEstudiante', 'promotoria'])
            ->get();

        if ($matriculas->isEmpty()) {
            return $this->volver('No marcaste ninguna cancelación.');
        }

        $resueltas = 0;
        $mayores = [];

        DB::transaction(function () use ($matriculas, $decision, &$resueltas, &$mayores) {
            foreach ($matriculas as $matricula) {
                if ($decision === 'rechazar' && ! $matricula->cancelacion_es_rechazable) {
                    $mayores[] = $matricula->estudiante->nombre_completo;

                    continue;
                }

                if ($decision === 'aprobar') {
                    $matricula->estado = Matricula::RETIRADA;
                    $matricula->motivo_retiro = Matricula::RETIRO_CANCELACION;
                    $matricula->repartirEn([]);
                } else {
                    $matricula->estado = Matricula::ACTIVA;
                }

                $matricula->save();
                $resueltas++;
            }
        });

        if ($decision === 'aprobar') {
            return $this->volver(
                $resueltas === 1
                    ? '1 retiro aprobado. Su cupo vuelve a estar libre.'
                    : "{$resueltas} retiros aprobados. Sus cupos vuelven a estar libres.",
                exito: true
            );
        }

        $hechas = $resueltas === 1
            ? '1 cancelación rechazada: sigue matriculado.'
            : "{$resueltas} cancelaciones rechazadas: siguen matriculados.";

        if ($mayores === []) {
            return $this->volver($hechas, exito: true);
        }

        $quienes = implode(', ', $mayores);
        $cola = count($mayores) === 1
            ? "{$quienes} es mayor de edad, así que su salida no se rechaza: solo se puede aprobar."
            : "{$quienes} son mayores de edad, así que su salida no se rechaza: solo se puede aprobar.";

        return $this->volver(
            $resueltas === 0 ? "No se rechazó ninguna. {$cola}" : "{$hechas} {$cola}"
        );
    }

    /**
     * Vuelve a la bandeja, a LA MISMA pagina desde la que se resolvio.
     *
     * Era `route('gestion-cancelaciones')` a secas, que sin paginacion daba la
     * misma URL y no se notaba. Con paginacion devolvia a la pagina 1 en cada
     * resolucion: quien estuviera trabajando en la 2 tenia que volver a
     * navegar hasta ella despues de cada clic, y esta pantalla se vacia de a
     * tandas — es justo el trabajo que `acciones.js` existe para no romper.
     *
     * `back()` lee la ultima URL GET de la sesion, que durante estos POST por
     * `fetch` sigue siendo la de la bandeja con su `?page=`.
     */
    /**
     * 404 si la promotoria no es de las que esta persona ve, igual que
     * `buscar()` en el catalogo: esconder la fila no cierra la puerta, y una
     * accion ajena compuesta a mano tiene que rebotar AQUI. Un 404 y no un 403,
     * para no confirmar siquiera que esa matricula existe.
     */
    private function cerrarSiEsAjena(?Promotoria $promotoria): void
    {
        abort_unless(
            $promotoria !== null && Permisos::veLaPromotoria(request()->attributes->get('perfil'), $promotoria),
            404
        );
    }

    private function volver(string $mensaje, bool $exito = false): RedirectResponse
    {
        return back()->with($exito ? 'success' : 'error', $mensaje);
    }
}
