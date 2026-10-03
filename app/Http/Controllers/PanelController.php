<?php

namespace App\Http\Controllers;

use App\Exceptions\LoteNoCabe;
use App\Models\Actividad;
use App\Models\Area;
use App\Models\Clase;
use App\Models\CupoPromotoria;
use App\Models\DocumentoRequerido;
use App\Models\Grupo;
use App\Models\Matricula;
use App\Models\OmisionArchivada;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Models\SesionGrupo;
use App\Support\Fragmento;
use App\Support\Permisos;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * El Panel: la contraparte de lo que ve el estudiante.
 *
 * Aqui quien dicta una promotoria ve quien pidio entrar, confirma o rechaza esas
 * solicitudes, fija el cupo del periodo y reparte a los matriculados en grupos.
 *
 * Visibilidad (ver el recordatorio en `Perfil`): nombre, foto, edad, telefono y
 * acudiente se ensenan al personal. La encuesta demografica y la copia del
 * documento NO se asoman por aqui.
 */
class PanelController extends Controller
{
    /**
     * Listado de promotorias con sus grupos, sus matriculados y sus pendientes.
     *
     * El profesor solo ve las que dicta; direccion las ve todas.
     *
     * A diferencia del original, que lanzaba tres consultas por promotoria
     * —grupos, sin grupo, pendientes—, aqui las matriculas se traen de una vez y
     * se reparten en memoria. El reparto es identico; lo que cambia es que un
     * panel con veinte promotorias deja de costar sesenta consultas.
     */
    public function index(Request $request): View
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');

        return view('panel.index', $this->datosDelIndice($perfil));
    }

    /**
     * Los datos de la portada del Panel.
     *
     * Esta aparte porque lo usan las DOS ramas: abrir el Panel y responder a una
     * accion sin recargar. Una accion repinta la portada ENTERA —no solo el
     * cuerpo de la promotoria— y la razon es la insignia de «N pendientes», que
     * vive en el <summary>, fuera del cuerpo: confirmar una matricula la cambia,
     * y un fragmento que solo trajera el cuerpo la dejaria mintiendo.
     *
     * @return array<string, mixed>
     */
    /**
     * TODAS las clases de la semana, ordenadas por dia y hora.
     *
     * ─── Por que existe ────────────────────────────────────────────────────
     *
     * Lo pidio el usuario el 06/09/2026: «un filtro por dia y hora para los
     * grupos, para que lleguen rapido al grupo que le van a dar clase». Antes
     * habia que acordarse de en que promotoria estaba el grupo, desplegarla y
     * buscarlo entre los suyos.
     *
     * ─── Y POR QUE SOLO LO DE QUIEN DICTA ──────────────────────────────────
     *
     * Ver el comentario de la consulta. En resumen: para direccion serian las
     * 199 sesiones de la casa, que no las lee nadie; para quien dicta son las
     * suyas, que son pocas y es justo lo que se vino a buscar.
     *
     * ─── POR QUE VIENE LA SEMANA ENTERA Y NO EL DIA QUE SE MIRA ────────────
     *
     * La primera version filtraba por `?dia=` y cada cambio de dia era una
     * NAVEGACION. El usuario lo rechazo con la razon exacta: «no puede quedar
     * recargando toda la pagina», porque al volver la portada llega con todos
     * los desplegables cerrados y quien estaba mirando una promotoria la
     * pierde. Este Panel se apoya en `<details>` abiertos —departamentos,
     * promotorias, actividades— y una recarga los cierra todos.
     *
     * Asi que se manda la semana entera UNA vez y el dia se elige en el
     * navegador, sin pedir nada. Cabe de sobra: son los grupos de quien mira
     * con su horario, sin un solo matriculado — lo unico que crece con los
     * estudiantes.
     *
     * @return list<array{dia: int, grupo: Grupo, sesion: SesionGrupo}>
     */
    private function clasesDeLaSemana(Perfil $perfil): array
    {
        // SOLO LO QUE DICTA ESTA PERSONA, y no lo que puede VER.
        //
        // Es la diferencia entera de este bloque, y la primera version la tenia
        // mal: usaba `visiblesPara()`, que para direccion son TODAS las
        // promotorias de la casa. En produccion eso son 199 sesiones de golpe
        // —medidas— encima de la pantalla mas usada del sistema, y ningun
        // director va a leer eso buscando nada. Palabras del usuario: «en el
        // panel de administrador o director no sirve de nada... eso sirve para
        // los profesores, que si necesitan revisar sus clases por dia».
        //
        // Se acota por `profesor_id` y no por ROL a proposito: un director que
        // ademas dicta —caso que este proyecto ya contempla en otras dos
        // pantallas— ve las SUYAS y solo esas, que son pocas y le sirven. Y
        // quien no dicta nada no ve el bloque, porque se queda vacio y la vista
        // no lo pinta.
        $grupos = Grupo::query()
            ->whereIn('promotoria_id', Promotoria::where('profesor_id', $perfil->id)->select('id'))
            ->whereHas('sesiones')
            ->with(['promotoria.area', 'sesiones'])
            ->get();

        $clases = [];

        foreach ($grupos as $grupo) {
            foreach ($grupo->sesiones as $sesion) {
                // Anotada por lo mismo que sus vecinas de este archivo y de
                // `InformeController`: la relacion no lleva tipo y la sesion
                // llega como un `Model` cualquiera, sin dia ni hora para el
                // analizador. Ponerle el tipo a la relacion seria lo correcto y
                // destapa seis avisos ajenos a esto —comprobado—, o sea otra
                // tarea.
                /** @var SesionGrupo $sesion */
                $clases[] = [
                    'dia' => (int) $sesion->dia,
                    'grupo' => $grupo,
                    'sesion' => $sesion,
                ];
            }
        }

        // Por dia y, dentro del dia, por hora: es el orden en que se vive la
        // semana, y el que hace que «que me toca ahora» se lea de un vistazo.
        usort($clases, fn (array $a, array $b) => [$a['dia'], $a['sesion']->hora_inicio]
            <=> [$b['dia'], $b['sesion']->hora_inicio]);

        return $clases;
    }

    /**
     * Las faltas que quien dicta tiene por reponer (03/10/2026).
     *
     * Las marca la bandeja de alertas —administracion o direccion dicen
     * «Falta»— y se van de aqui en cuanto se registra la reposicion. Solo las
     * del periodo EN CURSO: la reposicion se registra en el periodo en curso,
     * y reponer en 2026-2 una clase de 2026-1 no tiene donde quedar.
     *
     * Acotado por `profesor_id` como `clasesDeLaSemana()`, y por lo mismo:
     * aqui se ve lo que esta persona tiene que hacer, no lo que puede ver.
     *
     * @return Collection<int, OmisionArchivada>
     */
    private function clasesPorReponer(Perfil $perfil): Collection
    {
        $periodo = Periodo::enCurso();

        if ($periodo === null) {
            return new Collection;
        }

        return OmisionArchivada::query()
            ->where('causa', OmisionArchivada::FALTA)
            ->whereNull('repuesta_en_id')
            ->whereBetween('fecha', [$periodo->fecha_inicio, $periodo->fecha_fin])
            ->whereIn('grupo_id', Grupo::whereIn(
                'promotoria_id',
                Promotoria::where('profesor_id', $perfil->id)->select('id')
            )->select('id'))
            ->with('grupo.promotoria.area')
            ->orderBy('fecha')
            ->orderBy('id')
            ->get();
    }

    private function datosDelIndice(Perfil $perfil): array
    {
        $promotorias = $this->visiblesPara($perfil)
            ->with(['area', 'profesor'])
            ->join('areas', 'areas.id', '=', 'promotorias.area_id')
            // Por departamento y por nombre: la portada se agrupa por
            // departamento, asi que el orden que llega ya es el de la pantalla.
            // Antes iba por id, que no significa nada para quien mira.
            ->orderBy('areas.nombre')
            ->orderBy('promotorias.nombre')
            ->select('promotorias.*')
            ->get();

        // Lo unico que necesita la portada de cada promotoria: cuantas
        // solicitudes esperan respuesta. Una sola consulta agrupada.
        $pendientes = Matricula::query()
            ->whereIn('promotoria_id', $promotorias->pluck('id'))
            ->where('estado', Matricula::PENDIENTE)
            ->groupBy('promotoria_id')
            ->selectRaw('promotoria_id, COUNT(*) as total')
            ->pluck('total', 'promotoria_id');

        return [
            'promotorias' => $promotorias,
            // AGRUPADAS POR DEPARTAMENTO, que es como se pinta la portada desde
            // el 04/09/2026: un director ve veintiuna promotorias seguidas y lo
            // que pidio el usuario fue poder plegarlas por departamento.
            //
            // El agrupado se hace aqui y no en la vista para que las dos ramas
            // —abrir el Panel y responder sin recargar— reciban lo mismo ya
            // hecho. Se reparte en memoria sobre la coleccion que ya se trajo:
            // no cuesta una consulta mas.
            'porDepartamento' => $promotorias->groupBy(function (Promotoria $p) {
                // Anotada porque la relacion no lleva tipo y el area llega como
                // un `Model` cualquiera, sin nombre para el analizador.
                /** @var Area $area */
                $area = $p->area;

                return $area->nombre;
            }),
            'pendientes' => $pendientes,
            // LOS CURSOS, TALLERES Y GRUPOS DE PROYECCION, con sus sesiones.
            //
            // Aqui habia un COUNT a proposito —«sin filas, no el listado»— y se
            // cambia sabiendo lo que costaba. La razon es de uso: a la
            // asistencia de una actividad NO SE LLEGABA. Un grupo de promotoria
            // tiene su enlace «clases» dentro de la promotoria desplegada; una
            // actividad estaba detras de un boton suelto y a dos pantallas mas
            // —lista, ficha, sesion—. Palabras del usuario el 06/09/2026: «desde
            // el perfil de administrador no se puede ingresar facilmente», y
            // pidio que fuera «como los grupos de las promotorias».
            //
            // EL COSTE ES CONSTANTE, que es lo que importa aqui: dos consultas
            // —las actividades y sus sesiones— que no crecen con los
            // estudiantes ni con las matriculas, que es lo que si crece en esta
            // pantalla. No se cargan los INSCRITOS de cada sesion, que es lo
            // unico que si crecería; para eso esta la ficha.
            // EL DIRECTOR SOLO VE LAS QUE DIRIGE desde el 12/09/2026. Una
            // actividad no cuelga de un area —lo que tiene es una PERSONA
            // responsable— asi que aqui el recorte no es por area: es el mismo
            // que el del profesor. Ver `Permisos::puedeVerActividad()`.
            'actividades' => Actividad::query()
                ->when(
                    $perfil->rol !== 'administrador',
                    fn ($q) => $q->where('responsable_id', $perfil->id)
                )
                ->withCount('inscritos')
                // Ordenadas como se van a pintar: las mas recientes primero
                // dentro de cada actividad, que es la sesion a la que se va.
                ->with(['sesiones' => fn ($q) => $q->orderByDesc('fecha')])
                ->orderBy('nombre')
                ->get(),
            // LA SEMANA ENTERA, y el dia se elige en el navegador. Ver
            // `clasesDeLaSemana()`.
            'clasesDeLaSemana' => $this->clasesDeLaSemana($perfil),
            'porReponer' => $this->clasesPorReponer($perfil),
            // El dia de HOY, para que el selector arranque ahi. Nulo el
            // domingo, que la casa no abre: entonces se ven los seis dias.
            'diaDeHoy' => (int) Carbon::today()->dayOfWeekIso <= 6
                ? (int) Carbon::today()->dayOfWeekIso
                : null,
            'diasDeClase' => SesionGrupo::DIAS,
            // ABIERTA SIEMPRE que se pinte, porque ya solo se pinta a quien
            // dicta y son sus pocas clases. Estuvo condicionada al rol
            // 'profesor' mientras el bloque le salia tambien a direccion con la
            // casa entera dentro; acotada la consulta, esa condicion sobraba y
            // encima dejaba plegado al director que si dicta.
            'abrirClasesDelDia' => true,
        ];
    }

    /**
     * El cuerpo de UNA promotoria: sus grupos, sus matriculados y sus
     * pendientes.
     *
     * Se pide aparte, al desplegar. Antes iba dentro del indice y el resultado
     * era que un director descargaba el catalogo entero —467 KB con trescientos
     * estudiantes— para ver una lista de veintiun titulos plegados. Ahora la
     * portada pesa unos pocos KB y cada promotoria trae lo suyo cuando alguien
     * la abre de verdad.
     *
     * Lo que se pinta aqui es exactamente la misma plantilla que antes, asi que
     * no hay dos versiones de nada; lo que cambia es cuando se pide.
     */
    public function cuerpo(Request $request, Promotoria $promotoria): View
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');

        // La misma puerta que filtra el indice: esconder una promotoria de la
        // lista no cierra su URL.
        abort_unless(
            $this->visiblesPara($perfil)->where('promotorias.id', $promotoria->id)->exists(),
            404
        );

        return view('panel.item', $this->datosDelCuerpo($promotoria, $perfil));
    }

    /**
     * Lo que necesita `panel.item` para pintarse.
     *
     * Aparte por lo mismo que el indice: lo piden las dos ramas. Al desplegar
     * una promotoria se pide por su URL, y al responder a una accion sin
     * recargar se pinta ya dentro de la portada, para no obligar al navegador a
     * volver a pedirlo en un tercer viaje.
     *
     * @return array<string, mixed>
     */
    private function datosDelCuerpo(Promotoria $promotoria, Perfil $perfil): array
    {
        $promotoria->load(['area', 'profesor', 'grupos.sesiones', 'cupos']);

        // Sin periodo en curso se ensena el mas reciente. Entre dos semestres no
        // hay ninguno activo, y en ese hueco lo que quiere mirar quien dicta es
        // justamente el que acaba de terminar — no una pantalla vacia. Es la
        // misma regla que ya siguen Estadisticas y Cupos, para que las tres se
        // comporten igual.
        $periodo = Periodo::enCurso() ?? Periodo::query()
            ->orderByDesc('fecha_inicio')
            ->orderByDesc('id')
            ->first();

        return [
            'periodo' => $periodo,
            'item' => $this->itemDe($promotoria, $perfil, $periodo),
        ];
    }

    /**
     * Las promotorias que esta persona puede ver en el Panel.
     *
     * El profesor solo las que dicta; el DIRECTOR solo las de las areas que
     * dirige —desde el 12/09/2026, ver `Permisos::areasVisiblesPara()`—; el
     * administrador todas. Vive aparte porque la usan el indice y la carga de un
     * cuerpo suelto, y separadas acabarian discrepando: bastaria que una
     * olvidara el filtro para que alguien leyera por URL una lista que no le
     * toca.
     *
     * OJO CON EL ARRAY VACIO. Un director sin areas asignadas da `[]`, y un
     * `whereIn` con lista vacia no devuelve nada — que es exactamente lo que
     * tiene que pasar. La tentacion al leerlo es «si esta vacio, no filtres»; eso
     * le devolveria la casa entera a quien no dirige ninguna area.
     */
    private function visiblesPara(Perfil $perfil)
    {
        $areas = Permisos::areasVisiblesPara($perfil);

        return Promotoria::query()
            ->when($perfil->rol === 'profesor', fn ($q) => $q->where('profesor_id', $perfil->id))
            ->when($areas !== null, fn ($q) => $q->whereIn('area_id', $areas ?? []));
    }

    /**
     * Todo lo que la plantilla necesita para pintar una promotoria.
     *
     * @return array<string, mixed>
     */
    private function itemDe(Promotoria $promotoria, Perfil $perfil, ?Periodo $periodo): array
    {
        $exigidos = DocumentoRequerido::activos()->ordenados()->get();

        // Acotado al periodo que se esta mirando.
        //
        // DIVERGE DEL ORIGINAL a proposito, y es una decision de producto, no
        // una correccion de migracion: el Django de origen tampoco filtra
        // (`views.py:861`), asi que el panel de alla ensena a todo el que haya
        // pasado por la promotoria desde que existe.
        //
        // Se cambio porque eso hacia dos cosas malas a la vez. La cara: nada
        // retira las matriculas al cerrar un periodo, asi que se quedan en
        // `activa` para siempre y el panel afirmaba que gente de hace tres
        // semestres seguia en el salon. La barata: el coste crecia sin techo
        // —cada fila arrastra su perfil, sus datos, su acudiente y sus
        // documentos—, y al cuarto o quinto ano abrir una promotoria veterana
        // significaba hidratar miles de modelos para ensenar cuarenta filas, en
        // un hosting compartido con la memoria contada.
        //
        // El mapa de renovaciones NO se resiente: `renovacionesDe()` hace su
        // propia consulta a todos los periodos, y de aqui solo saca a QUIEN
        // preguntar por. Quien vuelve sigue apareciendo marcado como tal.
        $suyas = $periodo === null
            ? new Collection
            : Matricula::query()
                ->where('promotoria_id', $promotoria->id)
                ->where('periodo_id', $periodo->id)
                ->whereIn('estado', [...Matricula::ESTADOS_INSCRITO, Matricula::PENDIENTE])
                ->with([
                    'estudiante',
                    'estudiante.datosEstudiante.acudiente',
                    'estudiante.datosEstudiante.documentos',
                    // En que grupos esta repartida cada una. Cargado aqui y no
                    // consultado por fila: el reparto se pinta grupo a grupo, y
                    // preguntarlo dentro del bucle es una consulta por
                    // matricula.
                    'grupos',
                ])
                ->orderBy('id')
                ->get();

        $renovaciones = $this->renovacionesDe($suyas);
        $clasesDeHoy = $this->clasesDeHoy($periodo, $promotoria);

        // Pendientes: solicitudes que nadie ha resuelto todavia. Se listan
        // aparte de los grupos aunque alguna tenga grupo asignado, porque
        // mientras no se confirme no esta en la clase.
        $pendientes = $suyas->where('estado', Matricula::PENDIENTE);

        // Inscritos: activas y cancelaciones en tramite. La cancelacion sin
        // resolver sigue en la lista a proposito — el estudiante sigue yendo a
        // clase hasta que direccion la tramite, y borrarlo de la vista de su
        // propio profesor antes de eso seria mentir sobre quien esta en el salon.
        $inscritas = $suyas->whereIn('estado', Matricula::ESTADOS_INSCRITO);

        $grupos = [];

        /*
         * QUIEN ESTA EN CADA GRUPO SALE DE `asignaciones_grupo`, no de la
         * columna, desde el 10/09/2026.
         *
         * Y eso cambia algo que se ve: una misma persona puede aparecer en DOS
         * grupos de esta misma promotoria, porque va a los dos horarios. No es
         * un duplicado — son dos sillas, dos clases y dos listas de asistencia.
         * Sigue siendo UNA matricula, asi que el contador de arriba, el cupo de
         * la promotoria y su ranura no la cuentan dos veces.
         */
        foreach ($promotoria->grupos as $grupo) {
            $grupos[] = [
                'grupo' => $grupo,
                'estudiantes' => $this->fichas(
                    $inscritas->filter(fn (Matricula $m) => $m->grupos->contains('id', $grupo->id)),
                    $exigidos,
                    $renovaciones
                ),
                'clase_hoy' => $clasesDeHoy[$grupo->id] ?? null,
            ];
        }

        return [
            'promotoria' => $promotoria,
            'grupos' => $grupos,
            // «Sin grupo» es no estar en NINGUNO, y por eso se pregunta por la
            // lista vacia y no por la columna: quien va a dos horarios tiene la
            // columna puesta igual que quien va a uno.
            'sin_grupo' => $this->fichas(
                $inscritas->filter(fn (Matricula $m) => $m->grupos->isEmpty()),
                $exigidos,
                $renovaciones
            ),
            'pendientes' => $this->fichas($pendientes, $exigidos, $renovaciones),
            'puede_gestionar' => Permisos::puedeGestionarPromotoria($perfil, $promotoria),
            // Mas estrecho que lo anterior: el boton de clase es solo de quien la
            // dicta (ver `Permisos::dictaLaPromotoria`).
            'puede_marcar' => Permisos::dictaLaPromotoria($perfil, $promotoria),
            'cupo' => $promotoria->cupoEn($periodo),
            // Se cuenta sobre lo que ya esta en memoria en vez de llamar a
            // `ocupadosEn()`, que consulta. El resultado es identico:
            // `ocupadosEn` cuenta las de ese periodo con estado distinto de
            // 'retirada', y lo que se trajo son exactamente esos tres estados
            // —y ya solo de este periodo, desde que `$suyas` va acotada.
            'ocupados' => $suyas->count(),
        ];
    }

    /**
     * Da por buena una solicitud: pasa de pendiente a activa.
     *
     * Es el momento en que el estudiante entra de verdad a la promotoria. Hasta
     * aqui solo habia pedido sitio.
     */
    public function confirmar(Request $request, Matricula $matricula): RedirectResponse|Response
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');

        if (! Permisos::puedeGestionarPromotoria($perfil, $matricula->promotoria)) {
            return $this->volver('No tienes acceso a esta promotoría.');
        }

        // Solo se confirma lo que sigue pendiente. Si ya la resolvio otra
        // persona mientras esta estaba mirando la pantalla, no se dice nada: el
        // resultado que queria ya esta puesto.
        if ($matricula->estado !== Matricula::PENDIENTE) {
            return $this->volver('', promotoria: $matricula->promotoria);
        }

        // El limite de promotorias por periodo tambien se aplica AQUI, y no solo
        // al matricularse: entre la solicitud y esta confirmacion el estudiante
        // pudo llenar su cupo por otro lado, o el administrador pudo bajar el
        // limite. Confirmar sin mirar lo dejaria por encima del tope.
        $limite = Matricula::limitePromotorias();
        $ocupadas = Matricula::promotoriasOcupadas(
            $matricula->estudiante_id,
            $matricula->periodo_id,
            $matricula->id
        );

        if ($ocupadas >= $limite) {
            $permitidas = $limite === 1
                ? 'la promotoría permitida'
                : "las {$limite} promotorías permitidas";

            return $this->volver(
                "{$matricula->estudiante->nombre_completo} ya ocupa {$permitidas} en este "
                .'periodo. Retira una de sus matrículas antes de confirmar esta.'
            );
        }

        $matricula->estado = Matricula::ACTIVA;
        $matricula->save();

        return $this->volver(
            "Matrícula de {$matricula->estudiante->nombre_completo} confirmada.",
            exito: true,
            promotoria: $matricula->promotoria,
        );
    }

    /**
     * Niega una solicitud pendiente.
     *
     * Queda como 'retirada', el mismo estado que si el estudiante se hubiera
     * echado atras. No hay un estado 'rechazada' aparte: para todo lo que viene
     * despues —el cupo, la ranura, el historial— las dos cosas significan lo
     * mismo, que esa matricula no siguio adelante.
     */
    public function rechazar(Request $request, Matricula $matricula): RedirectResponse|Response
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');

        if (! Permisos::puedeGestionarPromotoria($perfil, $matricula->promotoria)) {
            return $this->volver('No tienes acceso a esta promotoría.');
        }

        if ($matricula->estado !== Matricula::PENDIENTE) {
            return $this->volver('', promotoria: $matricula->promotoria);
        }

        $matricula->estado = Matricula::RETIRADA;
        $matricula->motivo_retiro = Matricula::RETIRO_RECHAZO;
        $matricula->save();

        return $this->volver(
            "Solicitud de {$matricula->estudiante->nombre_completo} rechazada.",
            exito: true,
            promotoria: $matricula->promotoria,
        );
    }

    /**
     * Resuelve de una vez varias solicitudes pendientes de la promotoria.
     *
     * El mismo gesto que el reparto por lote y por el mismo motivo: al abrir
     * matriculas llegan veinte solicitudes juntas y casi todas se responden
     * igual. De a una son veinte idas y vueltas para una decision ya tomada.
     *
     * A DIFERENCIA del reparto por grupo, esto NO es todo o nada, y la
     * diferencia no es un descuido. Alli el lote se deshace entero porque lo que
     * falla es un cupo compartido: si caben ocho de doce, saber CUALES entraron
     * exige mirarlos uno a uno, que es justo el trabajo que el lote viene a
     * evitar. Aqui cada matricula falla por su cuenta y por un motivo que se
     * puede nombrar —ese estudiante ya ocupa todas sus promotorias—, asi que
     * deshacer las diecinueve que si valian para castigar a la que no seria
     * peor: se confirman las que pueden y se dice, por su nombre, quien quedo
     * fuera y por que.
     */
    public function resolverPendientesLote(Request $request, Promotoria $promotoria): RedirectResponse|Response
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');

        if (! Permisos::puedeGestionarPromotoria($perfil, $promotoria)) {
            return $this->volver('No tienes acceso a esta promotoría.');
        }

        $decision = $request->input('decision');

        abort_unless(in_array($decision, ['confirmar', 'rechazar'], true), 404);

        // Se filtra por promotoria y por estado aqui y no solo en la plantilla:
        // lo que no encaje simplemente no entra en el lote.
        $matriculas = Matricula::query()
            ->whereIn('id', (array) $request->input('matricula_ids', []))
            ->where('promotoria_id', $promotoria->id)
            ->where('estado', Matricula::PENDIENTE)
            ->with('estudiante')
            ->get();

        if ($matriculas->isEmpty()) {
            return $this->volver('No marcaste ninguna solicitud.');
        }

        if ($decision === 'rechazar') {
            // Rechazar no tiene condiciones: una solicitud pendiente siempre se
            // puede negar. Queda 'retirada' —no hay un estado propio— pero con
            // el motivo puesto, que es lo que separa «te dijeron que no» de
            // «te echaste atras» cuando el estudiante lo mira.
            DB::transaction(function () use ($matriculas) {
                foreach ($matriculas as $matricula) {
                    $matricula->estado = Matricula::RETIRADA;
                    $matricula->motivo_retiro = Matricula::RETIRO_RECHAZO;
                    $matricula->save();
                }
            });

            $cuantas = $matriculas->count();

            return $this->volver(
                $cuantas === 1
                    ? '1 solicitud rechazada.'
                    : "{$cuantas} solicitudes rechazadas.",
                exito: true,
                promotoria: $promotoria,
            );
        }

        $limite = Matricula::limitePromotorias();
        $confirmadas = 0;
        $sinCupo = [];

        DB::transaction(function () use ($matriculas, $limite, &$confirmadas, &$sinCupo) {
            foreach ($matriculas as $matricula) {
                // El mismo tope que aplica la confirmacion de a una, y por la
                // misma razon: entre la solicitud y esta pantalla el estudiante
                // pudo llenar su cupo por otro lado, o el administrador pudo
                // bajar el limite. Se cuenta DENTRO del bucle para que dos
                // matriculas del mismo estudiante en el mismo lote no se cuelen
                // las dos.
                $ocupadas = Matricula::promotoriasOcupadas(
                    $matricula->estudiante_id,
                    $matricula->periodo_id,
                    $matricula->id
                );

                if ($ocupadas >= $limite) {
                    $sinCupo[] = $matricula->estudiante->nombre_completo;

                    continue;
                }

                $matricula->estado = Matricula::ACTIVA;
                $matricula->save();
                $confirmadas++;
            }
        });

        return $this->volver(
            $this->resumenDeConfirmacion($confirmadas, $sinCupo, $limite),
            exito: $sinCupo === []
        );
    }

    /**
     * El parte de lo que paso con el lote.
     *
     * Nombra a quien quedo fuera en vez de dar solo la cifra: «18 de 20» obliga
     * a comparar la lista a ojo para averiguar quienes son los dos, que es
     * exactamente lo que no se puede hacer con veinte filas.
     *
     * @param  list<string>  $sinCupo
     */
    private function resumenDeConfirmacion(int $confirmadas, array $sinCupo, int $limite): string
    {
        $hechas = $confirmadas === 1 ? '1 matrícula confirmada' : "{$confirmadas} matrículas confirmadas";

        if ($sinCupo === []) {
            return "{$hechas}.";
        }

        $palabra = $limite === 1 ? 'la promotoría permitida' : "las {$limite} promotorías permitidas";

        // Sin repetir: quien tenga dos solicitudes bloqueadas aparecia dos veces
        // en la misma frase, y el aviso se leia como un error del sistema.
        $sinCupo = array_values(array_unique($sinCupo));
        $quienes = implode(', ', $sinCupo);

        $cola = count($sinCupo) === 1
            ? "{$quienes} ya ocupa {$palabra} en este periodo, así que su solicitud sigue pendiente."
            : "{$quienes} ya ocupan {$palabra} en este periodo, así que sus solicitudes siguen pendientes.";

        return $confirmadas === 0
            ? "No se confirmó ninguna: {$cola}"
            : "{$hechas}. {$cola}";
    }

    /**
     * Fija (o quita) el cupo de una promotoria para el periodo en curso.
     *
     * Dejar el campo vacio quita el tope. El profesor lo hace sobre las que
     * dicta; direccion sobre cualquiera.
     */
    public function cupo(Request $request, Promotoria $promotoria): RedirectResponse|Response
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');

        if (! Permisos::puedeGestionarPromotoria($perfil, $promotoria)) {
            return $this->volver('No tienes acceso a esta promotoría.');
        }

        $periodo = Periodo::enCurso();

        if ($periodo === null) {
            return $this->volver('No hay un periodo de matrícula activo en este momento.');
        }

        $bruto = trim((string) $request->input('cupo_maximo', ''));

        if ($bruto === '') {
            CupoPromotoria::where('promotoria_id', $promotoria->id)
                ->where('periodo_id', $periodo->id)
                ->delete();

            return $this->volver(
                "{$promotoria} queda sin tope de cupos en {$periodo}.",
                exito: true,
                promotoria: $promotoria,
            );
        }

        // Se comprueba a mano en vez de con el validador para que el mensaje sea
        // el del original: aqui no hay formulario donde pintar un error de campo,
        // la respuesta es siempre una vuelta al panel con un aviso.
        if (! preg_match('/^-?\d+$/', $bruto)) {
            return $this->volver('El cupo debe ser un número entero.');
        }

        $cupo = (int) $bruto;

        if ($cupo < 0) {
            return $this->volver('El cupo no puede ser negativo.');
        }

        CupoPromotoria::updateOrCreate(
            ['promotoria_id' => $promotoria->id, 'periodo_id' => $periodo->id],
            ['cupo_maximo' => $cupo]
        );

        $ocupados = $promotoria->ocupadosEn($periodo);

        // Bajar el cupo por debajo de lo ya ocupado es legitimo —el personal
        // puede necesitarlo— pero no retira a nadie: solo cierra la puerta. Se
        // avisa en rojo porque la cifra del panel se queda en "12 / 8" y sin
        // explicacion parece un error del sistema.
        if ($cupo < $ocupados) {
            return $this->volver(
                "Cupo de {$promotoria} fijado en {$cupo} para {$periodo}, pero ya hay {$ocupados} "
                .'matrículas ocupando sitio. No se retiró a nadie: simplemente no entrarán '
                .'estudiantes nuevos hasta que el número baje.'
            );
        }

        return $this->volver(
            "Cupo de {$promotoria} fijado en {$cupo} para {$periodo}.",
            exito: true,
            promotoria: $promotoria,
        );
    }

    /**
     * Manda una matricula a un grupo, o la deja sin grupo.
     *
     * Es el segundo paso del reparto: primero se confirma la matricula, luego se
     * le asigna horario. Un `grupo_id` vacio la saca del grupo sin retirarla de
     * la promotoria — el estudiante sigue inscrito, solo que sin horario.
     */
    public function asignarGrupo(Request $request, Matricula $matricula): RedirectResponse|Response
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');

        if (! Permisos::puedeGestionarPromotoria($perfil, $matricula->promotoria)) {
            return $this->volver('No tienes acceso a esta promotoría.');
        }

        $grupoId = $request->input('grupo_id') ?: null;
        $grupo = null;

        if ($grupoId !== null) {
            // Que el grupo sea de ESTA promotoria se exige en la consulta: los
            // ids llegan del formulario y nadie garantiza que sean los que se
            // pintaron.
            $grupo = Grupo::where('id', $grupoId)
                ->where('promotoria_id', $matricula->promotoria_id)
                ->first();

            abort_if($grupo === null, 404);
        }

        try {
            // `repartirEn` y no una asignacion: la columna desaparecio el
            // 10/09/2026 y una matricula puede estar en varios grupos. Una lista
            // de uno REEMPLAZA lo que hubiera, que es lo que este boton siempre
            // ha significado; una vacia la deja sin grupo.
            $matricula->repartirEn($grupo === null ? [] : [$grupo->id]);
        } catch (ValidationException $e) {
            return $this->volver(implode(' ', Arr::flatten($e->errors())));
        }

        return $this->volver(
            $grupo !== null
                ? "{$matricula->estudiante->nombre_completo} fue asignado a {$grupo}."
                : "{$matricula->estudiante->nombre_completo} quedó sin grupo asignado.",
            exito: true,
            promotoria: $matricula->promotoria,
        );
    }

    /**
     * LA PANTALLA PARA REPARTIR A ALGUIEN EN VARIOS GRUPOS.
     *
     * Es una pagina de verdad con su URL, y el Panel la abre en un MODAL. Sin
     * JavaScript se abre y funciona igual: no hay dos versiones del contenido,
     * que es el mismo criterio de las confirmaciones de borrado y del formulario
     * de usuario.
     *
     * ─── POR QUE UN MODAL Y NO UN CONTROL EN LA FILA ───────────────────────
     *
     * Medido el 10/09/2026 con el CSS real, a 390, 641, 1000 y 1440 px. Un
     * `<select multiple>` en la celda sube la fila de 68 a 136 px en escritorio
     * y de 112 a 179 en el telefono; una lista de casillas, a 162. El enlace que
     * abre esto deja la fila en 43 y 58 — mas BAJA que hoy.
     *
     * Y hay una razon que pesa mas que el alto: en escritorio, un
     * `<select multiple>` se maneja con ctrl+clic, y **un clic normal borra la
     * seleccion anterior**. Probado en el navegador: con «Grupo A» marcado, un
     * clic en «Grupo B» dejaba solo el B. En esta pantalla eso es sacar a
     * alguien de su grupo sin decirlo, que es la forma de fallo que este
     * proyecto lleva documentada desde que costo un profesor.
     *
     * En el modal caben ademas los rotulos enteros —52 a 55 caracteres, que es
     * lo que reventó el ancho de la tabla el 04/09— en vez de solo «Grupo A».
     */
    public function grupos(Request $request, Matricula $matricula): View
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');

        abort_unless(Permisos::puedeGestionarPromotoria($perfil, $matricula->promotoria), 403);

        $periodo = $matricula->periodo;

        return view('panel.grupos', [
            'matricula' => $matricula->load(['estudiante', 'promotoria', 'grupos']),
            'grupos' => $matricula->promotoria->grupos()->with('sesiones')->get()
                ->map(fn (Grupo $grupo) => [
                    'grupo' => $grupo,
                    'dentro' => $matricula->grupos->contains('id', $grupo->id),
                    // Cuantos hay y cuantos caben. Quien reparte decide con esto
                    // delante, y sin ello el rechazo por cupo llega despues de
                    // guardar — que es tarde.
                    'ocupados' => $grupo->ocupadosEn($periodo),
                ])
                ->all(),
        ]);
    }

    /**
     * Guarda en que grupos queda esta matricula.
     *
     * SE MANDA LA LISTA ENTERA Y NO UN CAMBIO. Las casillas describen un estado
     * —marcada es «esta dentro»— asi que lo que llega es en que grupos tiene que
     * quedar, y `repartirEn()` calcula solo lo que entra y lo que sale. Mandar
     * «anade este» y «quita ese» por separado obligaria a dos peticiones para lo
     * que la persona vio como un solo cambio.
     *
     * OJO CON LA LISTA VACIA: significa «sacalo de todos», y es legitima. Por
     * eso el campo no puede ser `required` — sin ninguna casilla marcada el
     * navegador no manda nada, y `input('grupo_id')` devuelve null.
     */
    public function guardarGrupos(Request $request, Matricula $matricula): RedirectResponse|Response
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');

        if (! Permisos::puedeGestionarPromotoria($perfil, $matricula->promotoria)) {
            return $this->volver('No tienes acceso a esta promotoría.');
        }

        // Acotado a los grupos de ESTA promotoria en la consulta: los ids llegan
        // del formulario y nadie garantiza que sean los que se pintaron.
        $pedidos = array_map('intval', (array) $request->input('grupo_id', []));

        $ids = $matricula->promotoria->grupos()
            ->whereIn('id', $pedidos)
            ->pluck('id')
            ->all();

        try {
            $matricula->repartirEn($ids);
        } catch (ValidationException $e) {
            return $this->volver(implode(' ', Arr::flatten($e->errors())));
        }

        $nombres = Grupo::whereIn('id', $ids)->pluck('nombre')->implode(' y ');

        /*
         * SIN `promotoria:`, y esto NO es un olvido.
         *
         * Pasarla hace que `volver()` conteste con un FRAGMENTO —solo lo de
         * dentro de `<main>`— cuando la peticion trae la cabecera de
         * `acciones.js`. Eso funciona para los formularios que viven en
         * `<main>`, porque su rama de `acciones.js` le pasa a `pintar()` el dato
         * de si lo que llega es un fragmento. **La rama del MODAL no se lo
         * pasa**: llama a `pintar(html, estado, scroll)` sin el cuarto
         * argumento, asi que busca un `<main>` que un fragmento no trae, se
         * rinde y NAVEGA a la URL del formulario.
         *
         * El sintoma no es un error: el guardado SE HACE y la persona acaba
         * mirando la pagina suelta del modal en vez del Panel. Se vio en el
         * navegador el 10/09/2026; ninguna prueba de PHP lo ve, porque por ahi
         * el cliente no manda esa cabecera.
         *
         * Sin `promotoria:` esto redirige al Panel como los demas modales del
         * proyecto —el borrado y el formulario de usuario hacen lo mismo—, el
         * `fetch` sigue la redireccion, llega la pagina entera con su `<main>` y
         * `pintar()` la pinta detras sin recargar.
         */
        return $this->volver(
            $ids === []
                ? "{$matricula->estudiante->nombre_completo} quedó sin grupo asignado."
                : "{$matricula->estudiante->nombre_completo} queda en {$nombres}.",
            exito: true,
        );
    }

    /**
     * Manda al mismo grupo a varios estudiantes de una vez.
     *
     * Repartir es la tarea del principio de periodo y se hace por tandas: llegan
     * veinte matriculados y casi todos van al mismo horario. Uno por uno son
     * veinte idas y vueltas para una sola decision ya tomada.
     *
     * El lote es TODO O NADA. Si no caben, no se mete a los que quepan: dejar
     * ocho dentro y cuatro fuera obliga a averiguar a mano cuales entraron, que
     * es justo el trabajo que esto viene a evitar. Se deshace el lote entero y
     * se dice cuantos cupos habia.
     */
    public function asignarGrupoLote(Request $request, Promotoria $promotoria): RedirectResponse|Response
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');

        if (! Permisos::puedeGestionarPromotoria($perfil, $promotoria)) {
            return $this->volver('No tienes acceso a esta promotoría.');
        }

        $grupoId = $request->input('grupo_id') ?: null;

        if ($grupoId === null) {
            return $this->volver('Elige a qué grupo mandarlos antes de asignar.');
        }

        $grupo = Grupo::where('id', $grupoId)
            ->where('promotoria_id', $promotoria->id)
            ->first();

        abort_if($grupo === null, 404);

        // Se filtra por promotoria y por "sin grupo" aqui y no solo en la
        // plantilla: lo que no encaje simplemente no entra en el lote.
        $matriculas = Matricula::query()
            ->whereIn('id', (array) $request->input('matricula_ids', []))
            ->where('promotoria_id', $promotoria->id)
            // Lo mismo que arriba: sin grupo es no estar en ninguno.
            ->whereDoesntHave('grupos')
            ->whereIn('estado', Matricula::ESTADOS_INSCRITO)
            ->with('estudiante')
            ->get();

        if ($matriculas->isEmpty()) {
            return $this->volver('No marcaste a ningún estudiante.');
        }

        try {
            DB::transaction(function () use ($matriculas, $grupo) {
                $asignadas = 0;

                foreach ($matriculas as $matricula) {
                    try {
                        // La misma puerta que usa la asignacion de a uno: el
                        // cupo del grupo lo decide `repartirEn()`, y cuenta las
                        // que ya se repartieron en este mismo bucle.
                        $matricula->repartirEn([$grupo->id]);
                    } catch (ValidationException $e) {
                        // Deshace el lote entero. La excepcion es el vehiculo
                        // del rollback y ademas se lleva el motivo REAL: antes
                        // se descartaba y la respuesta afirmaba siempre falta de
                        // cupo, hubiera sido eso o no (ver `LoteNoCabe`).
                        throw new LoteNoCabe(
                            cabian: $asignadas,
                            motivo: $e->errors(),
                            culpable: $matricula->estudiante?->nombre_completo,
                        );
                    }

                    // Sin `save()`: `repartirEn()` ya escribio. La matricula
                    // en si no cambia al repartirla — lo que cambia es en que
                    // grupos esta.
                    $asignadas++;
                }
            });
        } catch (LoteNoCabe $e) {
            return $this->volver($e->explicacion($matriculas->count(), (string) $grupo));
        }

        $cuantos = $matriculas->count();

        return $this->volver(
            $cuantos === 1
                ? "1 estudiante quedó en {$grupo}."
                : "{$cuantos} estudiantes quedaron en {$grupo}.",
            exito: true,
            promotoria: $promotoria,
        );
    }

    // -----------------------------------------------------------------------
    // Armado del listado
    // -----------------------------------------------------------------------

    /**
     * Las clases abiertas HOY, por grupo.
     *
     * Una sola consulta para los grupos de esta promotoria: el boton de clase
     * cambia de texto segun si el grupo ya tiene lista abierta hoy, y
     * preguntarlo grupo por grupo seria una consulta por fila.
     *
     * @return array<int, Clase>
     */
    private function clasesDeHoy(?Periodo $periodo, Promotoria $promotoria): array
    {
        if ($periodo === null) {
            return [];
        }

        return Clase::query()
            ->where('periodo_id', $periodo->id)
            ->whereDate('fecha_hora', today())
            ->sinReposiciones()
            ->whereIn('grupo_id', $promotoria->grupos->pluck('id'))
            ->get()
            ->keyBy('grupo_id')
            ->all();
    }

    /**
     * ¿Que matriculas son una renovacion, es decir de alguien que ya curso ESA
     * promotoria en otro periodo?
     *
     * Es lo que separa a quien vuelve de quien empieza de cero, y es justo lo que
     * quien dicta necesita para decidir en que nivel de grupo lo ubica.
     *
     * Se resuelve en una sola consulta y no matricula por matricula: el original
     * preguntaba por cada fila, y un panel con cuarenta estudiantes hacia
     * cuarenta consultas para pintar cuarenta etiquetas.
     *
     * @param  Collection<int, Matricula>  $matriculas
     * @return array<string, list<int>> "estudianteId:promotoriaId" => periodos cursados
     */
    private function renovacionesDe(Collection $matriculas): array
    {
        if ($matriculas->isEmpty()) {
            return [];
        }

        $previas = Matricula::query()
            ->whereIn('estudiante_id', $matriculas->pluck('estudiante_id')->unique())
            ->whereIn('promotoria_id', $matriculas->pluck('promotoria_id')->unique())
            ->where('estado', Matricula::ACTIVA)
            ->get(['estudiante_id', 'promotoria_id', 'periodo_id']);

        $mapa = [];

        foreach ($previas as $previa) {
            $mapa["{$previa->estudiante_id}:{$previa->promotoria_id}"][] = $previa->periodo_id;
        }

        return $mapa;
    }

    /**
     * Convierte matriculas en las filas que pinta la tabla.
     *
     * @param  \Illuminate\Support\Collection<int, Matricula>  $matriculas
     * @param  Collection<int, DocumentoRequerido>  $exigidos
     * @param  array<string, list<int>>  $renovaciones
     * @return list<array<string, mixed>>
     */
    private function fichas($matriculas, Collection $exigidos, array $renovaciones): array
    {
        return $matriculas
            ->map(fn (Matricula $m) => $this->ficha($m, $exigidos, $renovaciones))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function ficha(Matricula $matricula, Collection $exigidos, array $renovaciones): array
    {
        $datos = $matricula->estudiante->datosEstudiante;

        $cursados = $renovaciones["{$matricula->estudiante_id}:{$matricula->promotoria_id}"] ?? [];

        return [
            'matricula' => $matricula,
            'perfil' => $matricula->estudiante,
            'acudiente' => $datos?->acudiente,
            // Renovacion = ya la curso en OTRO periodo. El propio periodo de esta
            // matricula no cuenta; si contara, toda matricula activa se marcaria
            // a si misma como renovacion.
            'renovacion' => count(array_diff($cursados, [$matricula->periodo_id])) > 0,
            // Quien dicta se entera de que el estudiante esta de salida, pero la
            // decision no es suya: la marca es informativa y no trae botones.
            'cancelacion' => $matricula->cancelacion_pendiente,
            // Solo los OBLIGATORIOS: un papel opcional que falta no deja la
            // matricula incompleta, y marcarla igual haria que la etiqueta dejara
            // de significar "hay que llamar a esta persona".
            'papeles_pendientes' => $datos
                ? array_values(array_filter(
                    $datos->documentosFaltantes($exigidos),
                    fn (DocumentoRequerido $d) => $d->obligatorio
                ))
                : [],
        ];
    }

    /**
     * Vuelve al Panel despues de una accion.
     *
     * CON `$promotoria` y con JavaScript se responde con la portada YA pintada,
     * y con el cuerpo de esa promotoria dentro. Sin eso hacen falta TRES viajes
     * para cambiar una fila: el POST, el GET al que redirige, y el que hace
     * `panel.js` para recuperar el cuerpo que el repintado dejo sin cargar. En
     * una tanda de quince confirmaciones seguidas —que es como se usa esto— son
     * cuarenta y cinco.
     *
     * Se repinta la portada entera y no solo el cuerpo porque la insignia de
     * «N pendientes» esta en el <summary>, fuera del cuerpo: confirmar cambia
     * esa cifra, y un fragmento mas pequeno la dejaria mintiendo. La portada de
     * un profesor son 2 KB, asi que no hay nada que ganar afinando mas.
     *
     * SIN promotoria —los rechazos, donde puede que ni siquiera sea suya— se
     * redirige como siempre. Esa sigue siendo la rama por defecto y la unica
     * que existe sin JavaScript.
     */
    private function volver(
        string $mensaje,
        bool $exito = false,
        ?Promotoria $promotoria = null,
    ): RedirectResponse|Response {
        $peticion = request();

        if ($promotoria !== null && Fragmento::loPide($peticion)) {
            /** @var Perfil $perfil */
            $perfil = $peticion->attributes->get('perfil');

            if ($mensaje !== '') {
                // `now()` y no `with()`: el aviso se pinta en ESTA respuesta. Ver
                // `App\Support\Fragmento`.
                session()->now($exito ? 'success' : 'error', $mensaje);
            }

            return Fragmento::responder('panel.index', $this->datosDelIndice($perfil) + [
                // El cuerpo de la promotoria sobre la que se acaba de actuar,
                // pintado ya dentro de su sitio.
                'cuerpoDe' => $promotoria->id,
                'cuerpo' => $this->datosDelCuerpo($promotoria, $perfil),
            ]);
        }

        $respuesta = redirect()->route('panel');

        if ($mensaje !== '') {
            $respuesta->with($exito ? 'success' : 'error', $mensaje);
        }

        return $respuesta;
    }
}
