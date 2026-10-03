<?php

namespace App\Http\Controllers\Gestion;

use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\Asistencia;
use App\Models\Clase;
use App\Models\EncuestaDemografica;
use App\Models\EncuestaSatisfaccion;
use App\Models\Grupo;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Support\Grafica;
use App\Support\OrdenPorNombre;
use App\Support\ResumenActividades;
use App\Support\ResumenAsistencia;
use App\Support\ResumenDemografico;
use App\Support\ResumenInstitucion;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Panel de estadisticas. Solo administrador.
 *
 * Matricula por departamento y promotoria, grupos por nivel, y la encuesta
 * demografica agregada — nunca datos de una persona identificable.
 */
class EstadisticasController extends Controller
{
    /**
     * Nota por debajo de la cual una respuesta se considera mala experiencia.
     *
     * En una escala de 1 a 5, el 3 es el "ni bien ni mal" y no pide llamar a
     * nadie: lo que hay que atender de verdad es el 1 y el 2.
     */
    private const NOTA_BAJA = 2;

    /**
     * Cuantas clases marcadas hacen falta para entrar en el ranking de
     * constancia.
     *
     * Sin minimo, un 1 de 1 es un 100 % y desplaza a quien lleva veinte de
     * veintidos: el ranking se llenaria de gente que apenas empezo. Cinco es
     * poco mas de un mes de clases semanales — suficiente para que el
     * porcentaje signifique algo y no tanto como para dejar fuera a quien entro
     * a mitad de periodo.
     */
    private const MINIMO_CLASES_CONSTANCIA = 5;

    /**
     * El tablero, del periodo que se pida.
     *
     * Sin periodo en la URL se ensena el EN CURSO, que es lo que se viene a
     * mirar el 99 % de las veces; las flechas caminan por el resto. Es la misma
     * forma que ya usa la pantalla de cupos, para que moverse entre periodos se
     * haga igual en los dos sitios.
     */
    public function __invoke(Request $request, ?Periodo $periodo = null): View
    {
        // Del mas reciente al mas antiguo: es el orden en que se piensan y el
        // que hace que la flecha «izquierda» sea siempre «hacia atras».
        $periodos = Periodo::orderByDesc('fecha_inicio')->orderByDesc('id')->get();
        $periodoActual = $periodo ?? Periodo::enCurso() ?? $periodos->first();

        $indice = $periodoActual === null
            ? false
            : $periodos->search(fn (Periodo $p) => $p->id === $periodoActual->id);

        $periodoPrevio = $periodoActual === null ? null : Periodo::query()
            ->where('fecha_inicio', '<', $periodoActual->fecha_inicio)
            ->orderByDesc('fecha_inicio')
            ->first();

        [$baseRenovacion, $noRenovaron] = $this->renovacion($periodoActual, $periodoPrevio);

        // La encuesta, CONTADA. Desde el paso 5 la cuenta `ResumenDemografico`,
        // que es tambien lo que suma el panel de todas las instituciones: una
        // pregunta contada en dos sitios acabaria con dos respuestas. Alli esta
        // la historia de por que se cuenta asi (C-02).
        $demografia = ResumenDemografico::deLaInstitucion();
        $totalEncuestas = $demografia['total'];

        $cifras = ResumenInstitucion::cifras($periodoActual);

        return view('gestion.estadisticas', [
            'arbolDepartamentos' => $this->arbol($periodoActual, $baseRenovacion, $noRenovaron),
            'periodoActual' => $periodoActual,
            'periodoPrevio' => $periodoPrevio,
            // La navegación: hacia atras es el SIGUIENTE de la lista, porque va
            // ordenada del mas reciente al mas antiguo.
            'periodos' => $periodos,
            'haciaAtras' => $indice === false ? null : $periodos->get($indice + 1),
            'haciaAdelante' => $indice === false || $indice === 0 ? null : $periodos->get($indice - 1),
            'esElEnCurso' => $periodoActual !== null && $periodoActual->activo,
            'mapaInstitucion' => ResumenAsistencia::deInstitucion($periodoActual),
            // La gente SIN matricula, aparte y sin sumarse a nada de lo de
            // arriba. Ver la cabecera de `ResumenActividades`.
            'actividades' => ResumenActividades::delPeriodo($periodoActual),
            'profesoresActivos' => $this->profesoresMasActivos($periodoActual),
            'estudiantesConstantes' => $this->estudiantesMasConstantes($periodoActual),
            'minimoConstancia' => self::MINIMO_CLASES_CONSTANCIA,
            'gruposPorCurso' => Grafica::conPorcentaje($this->gruposPorNivel()),
            'estudiantesPorPeriodo' => Grafica::conPorcentaje($this->porPeriodo()),
            // Acotado al periodo que se esta MIRANDO, no a todos. Sin el filtro
            // contaba a quien estuvo activo en cualquier periodo: 251 contra
            // 231 en la base de desarrollo, y la diferencia crece con cada
            // periodo que pasa. La cifra se lee bajo la barra de navegacion,
            // que promete tres lineas mas arriba que «el periodo mueve las
            // matriculas», y esta es una cifra de matriculas.
            //
            // No es una matricula RETIRADA lo que sobraba: son activas de
            // periodos cerrados, que la aplicacion ya ensena como «Finalizada»
            // en todas partes (`Matricula::estado_visible`). El estado no se
            // toca al cerrar un periodo A PROPOSITO --de ahi cuelgan la
            // renovacion, los certificados y la antiguedad--, asi que quien
            // pregunte «cuantos hay ahora» tiene que acotar por periodo, que es
            // lo que hacen las demas consultas de esta pantalla.
            //
            // Se aparta del Django, que cuenta igual sin filtro
            // (`views_gestion.py:634,769`). Es la etiqueta la que decide: dice
            // «Estudiantes activos», no «han cursado alguna vez».
            // Las cinco cifras de cabecera salen de `ResumenInstitucion`, que es
            // el mismo sitio del que las saca la portada de Gestion. Dos
            // pantallas que ensenan la misma cifra no pueden calcularla cada una
            // por su cuenta: acaban diciendo cosas distintas.
            'totalEstudiantesActivos' => $cifras['estudiantesActivos'],
            'totalPromotorias' => $cifras['promotorias'],
            'totalGrupos' => $cifras['grupos'],
            // Y el resto de la cinta entero, que desde el 07/09/2026 lleva
            // ademas cursos, proyeccion y cupos. Se pasa el array completo en
            // vez de seguir desgranandolo en variables sueltas: cada cifra
            // nueva pedia otra linea aqui y otra alla, y la que se olvidara
            // dejaria la cinta a medias sin que nada fallara.
            'cifras' => $cifras,
            'totalEncuestas' => $totalEncuestas,
            'encuestasIncompletas' => $this->encuestasIncompletas(),
            'totalConRol' => Perfil::where('rol', '!=', '')->count(),
            ...ResumenDemografico::graficas($demografia),
            ...$this->autorizacion($totalEncuestas),
            // El nombre de quien contesto solo lo ve el administrador, y solo
            // en las notas bajas. Ver `satisfaccion()`.
            'satisfaccion' => $this->satisfaccion(
                $request->attributes->get('perfil')?->rol === 'administrador'
            ),
        ]);
    }

    /**
     * Como valoraron los estudiantes el periodo que cursaron.
     *
     * La encuesta se contesta al renovar y evalua el periodo que TERMINO, asi
     * que lo que se ensena es el ultimo periodo del que haya respuestas, no el
     * que esta en curso.
     *
     * Va agregada y sin nombres a proposito. Poner "Ana Ruiz — 2 al profesor"
     * en un tablero convierte una encuesta en un marcador, y la siguiente vez la
     * gente contesta pensando en quien va a leerla. La excepcion es el
     * SEGUIMIENTO: quien administra si ve quien tuvo una mala experiencia, con su
     * telefono, porque el motivo de recogerla es poder llamar a esa persona.
     *
     * Una limitacion que conviene tener presente: la encuesta cuelga de la
     * persona y del periodo, NO de la promotoria. Un estudiante que curso dos
     * responde una sola vez, asi que «calificacion del profesor» no se puede
     * atribuir a un profesor concreto — es como valoro su paso por la casa ese
     * semestre. Atribuirla exigiria cambiar el modelo.
     *
     * @return array<string, mixed>|null null si todavia no hay ninguna respuesta
     */
    private function satisfaccion(bool $veNombres): ?array
    {
        $periodo = Periodo::query()
            ->whereHas('encuestasSatisfaccion')
            ->orderByDesc('fecha_inicio')
            ->first();

        if ($periodo === null) {
            return null;
        }

        $respuestas = EncuestaSatisfaccion::where('periodo_id', $periodo->id)
            ->with(['perfil.datosEstudiante.acudiente', 'promotoria.area'])
            ->get();

        $total = $respuestas->count();

        // Cobertura: cuantos de los que cursaron ese periodo llegaron a
        // contestar. Sin esto, una media de 5,0 sacada de dos respuestas se lee
        // igual que una sacada de doscientas.
        $cursaron = Matricula::where('periodo_id', $periodo->id)
            ->where('estado', Matricula::ACTIVA)
            ->distinct()
            ->count('estudiante_id');

        return [
            'periodo' => $periodo,
            'respuestas' => $total,
            'cursaron' => $cursaron,
            'cobertura' => $cursaron ? (int) round($total / $cursaron * 100) : null,
            'media_general' => round($respuestas->avg('satisfaccion_general'), 1),
            'media_profesor' => round($respuestas->avg('calificacion_profesor'), 1),
            'general' => Grafica::porOpcion(
                $respuestas->countBy('satisfaccion_general')->all(),
                EncuestaSatisfaccion::ESCALA
            ),
            'profesor' => Grafica::porOpcion(
                $respuestas->countBy('calificacion_profesor')->all(),
                EncuestaSatisfaccion::ESCALA
            ),
            'horario' => $this->siONo($respuestas, 'horario_funciono'),
            'recomienda' => $this->siONo($respuestas, 'recomendaria'),
            // Sin nombre y sin orden que delate quien es quien: solo lo que
            // quisieron contar.
            'comentarios' => $respuestas
                ->pluck('comentario')
                ->filter(fn (?string $c) => trim((string) $c) !== '')
                ->values()
                ->all(),
            'veNombres' => $veNombres,
            'seguimiento' => $veNombres ? $this->seguimiento($respuestas) : [],
            'porPeriodo' => $this->mediasPorPeriodo(),
            'porPromotoria' => $this->mediasPorPromotoria($respuestas),
            // Respuestas anteriores a que la encuesta distinguiera promotorias y
            // que no se pudieron atribuir. No se reparten a ojo: se dicen.
            'sinPromotoria' => $respuestas->whereNull('promotoria_id')->count(),
        ];
    }

    /**
     * Media por promotoria dentro del periodo evaluado.
     *
     * Es lo que la encuesta no podia decir antes de colgar de la promotoria: que
     * disciplina va bien y cual no. Se ordena de peor a mejor a proposito —lo
     * que se viene a mirar aqui es donde hay un problema, no donde no lo hay— y
     * cada fila lleva cuantas respuestas la sostienen, porque una media de 2,0
     * sacada de una sola respuesta no es un problema todavia.
     *
     * @param  Collection<int, EncuestaSatisfaccion>  $respuestas
     * @return list<array<string, mixed>>
     */
    private function mediasPorPromotoria($respuestas): array
    {
        return $respuestas
            ->whereNotNull('promotoria_id')
            ->groupBy('promotoria_id')
            ->map(fn ($grupo) => [
                'promotoria' => $grupo->first()->promotoria,
                'total' => $grupo->count(),
                'general' => round($grupo->avg('satisfaccion_general'), 1),
                'profesor' => round($grupo->avg('calificacion_profesor'), 1),
                // Contra el 5 de la escala, no contra la promotoria mejor
                // valorada: si no, la diferencia entre un 4,9 y un 4,8 se
                // dibuja como un precipicio.
                'porcentaje' => (int) round($grupo->avg('satisfaccion_general') / 5 * 100),
                'recomiendan' => $grupo->where('recomendaria', true)->count(),
            ])
            ->sortBy('general')
            ->values()
            ->all();
    }

    /**
     * Reparto de una pregunta de si/no.
     *
     * @param  Collection<int, EncuestaSatisfaccion>  $respuestas
     * @return array{si: int, no: int, pct_si: int, pct_no: int}
     */
    private function siONo($respuestas, string $campo): array
    {
        $total = $respuestas->count();
        $si = $respuestas->where($campo, true)->count();
        $pctSi = $total ? (int) round($si / $total * 100) : 0;

        return ['si' => $si, 'no' => $total - $si, 'pct_si' => $pctSi, 'pct_no' => 100 - $pctSi];
    }

    /**
     * Quien tuvo una mala experiencia, para poder llamarlo. Solo administracion.
     *
     * Se incluye el telefono porque sin el la lista no sirve para lo unico que
     * justifica levantar el anonimato: hablar con esa persona.
     *
     * @param  Collection<int, EncuestaSatisfaccion>  $respuestas
     * @return list<array<string, mixed>>
     */
    private function seguimiento($respuestas): array
    {
        return $respuestas
            ->filter(fn (EncuestaSatisfaccion $e) => $e->satisfaccion_general <= self::NOTA_BAJA
                || $e->calificacion_profesor <= self::NOTA_BAJA)
            ->sortBy('satisfaccion_general')
            ->map(function (EncuestaSatisfaccion $e) {
                $perfil = $e->perfil;

                // A quien hay que llamar NO es siempre el estudiante: si es
                // menor de edad, la conversacion es con su acudiente. Dar aqui
                // el telefono del nino seria dar por bueno un contacto que ni la
                // ley ni el sentido comun admiten, y ademas el numero suele ser
                // el mismo de la casa mal apuntado.
                $acudiente = $perfil->es_menor
                    ? $perfil->datosEstudiante?->acudiente
                    : null;

                return [
                    'perfil' => $perfil,
                    'acudiente' => $acudiente,
                    'es_menor' => $perfil->es_menor,
                    'general' => $e->satisfaccion_general,
                    'profesor' => $e->calificacion_profesor,
                    'recomendaria' => $e->recomendaria,
                    'comentario' => trim((string) $e->comentario),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Media de satisfaccion por periodo evaluado, del mas reciente al mas
     * antiguo.
     *
     * La barra va contra el 5 de la escala y no contra el periodo mejor
     * valorado: en una escala acotada, medir cada barra contra la mas alta
     * convierte la diferencia entre un 4,9 y un 4,8 en un precipicio.
     *
     * @return list<array<string, mixed>>
     */
    private function mediasPorPeriodo(): array
    {
        return EncuestaSatisfaccion::query()
            ->join('periodos', 'periodos.id', '=', 'encuestas_satisfaccion.periodo_id')
            ->groupBy('periodos.id', 'periodos.nombre', 'periodos.fecha_inicio')
            ->selectRaw('periodos.nombre as etiqueta, AVG(satisfaccion_general) as media, COUNT(*) as total')
            ->orderByDesc('periodos.fecha_inicio')
            ->get()
            ->map(fn ($fila) => [
                'etiqueta' => $fila->etiqueta,
                'media' => round((float) $fila->media, 1),
                'total' => (int) $fila->total,
                'porcentaje' => (int) round((float) $fila->media / 5 * 100),
            ])
            ->all();
    }

    /**
     * Quien NO volvio: estudiantes con matricula activa en el periodo anterior
     * que no tienen ninguna viva en el actual, contados por promotoria.
     *
     * Se resuelve de una vez para todas las promotorias en lugar de una consulta
     * por fila del arbol.
     *
     * @return array{0: array<int, int>, 1: array<int, int>}
     */
    private function renovacion(?Periodo $actual, ?Periodo $previo): array
    {
        if ($actual === null || $previo === null) {
            return [[], []];
        }

        $siguen = Matricula::where('periodo_id', $actual->id)
            ->where('estado', '!=', Matricula::RETIRADA)
            ->get(['estudiante_id', 'promotoria_id'])
            ->map(fn (Matricula $m) => "{$m->estudiante_id}:{$m->promotoria_id}")
            ->flip();

        $base = [];
        $noRenovaron = [];

        $anteriores = Matricula::where('periodo_id', $previo->id)
            ->where('estado', Matricula::ACTIVA)
            ->get(['estudiante_id', 'promotoria_id']);

        foreach ($anteriores as $matricula) {
            $base[$matricula->promotoria_id] = ($base[$matricula->promotoria_id] ?? 0) + 1;

            if (! $siguen->has("{$matricula->estudiante_id}:{$matricula->promotoria_id}")) {
                $noRenovaron[$matricula->promotoria_id] = ($noRenovaron[$matricula->promotoria_id] ?? 0) + 1;
            }
        }

        return [$base, $noRenovaron];
    }

    /**
     * Arbol Departamento → Promotoria.
     *
     * Una sola estructura responde "por departamento" y "por promotoria" a la
     * vez, en vez de dos listas planas que repetirian la misma informacion.
     *
     * Se acota al PERIODO EN CURSO: sin acotar, las dos cifras de permanencia no
     * significarian nada — mezclarian a quien se retiro hace tres anos con quien
     * sigue yendo esta semana. El desglose historico vive en "Estudiantes por
     * periodo".
     *
     * @param  array<int, int>  $baseRenovacion
     * @param  array<int, int>  $noRenovaron
     * @return list<array<string, mixed>>
     */
    private function arbol(?Periodo $periodo, array $baseRenovacion, array $noRenovaron): array
    {
        if ($periodo === null) {
            return [];
        }

        // Un `?` por estado en vez de coserlos dentro del SQL. Hoy la constante
        // es la unica fuente y no habia inyeccion posible, pero un IN ligado no
        // cuesta nada y deja de depender de que nadie toque la constante.
        $huecos = implode(', ', array_fill(0, count(Matricula::ESTADOS_INSCRITO), '?'));

        $filas = Matricula::query()
            ->where('matriculas.periodo_id', $periodo->id)
            ->where('matriculas.estado', '!=', Matricula::PENDIENTE)
            ->join('promotorias', 'promotorias.id', '=', 'matriculas.promotoria_id')
            ->join('areas', 'areas.id', '=', 'promotorias.area_id')
            ->groupBy('promotorias.id', 'promotorias.nombre', 'areas.id', 'areas.nombre')
            ->selectRaw("
                promotorias.id as promotoria_id,
                promotorias.nombre as promotoria,
                areas.id as area_id,
                areas.nombre as area,
                SUM(CASE WHEN matriculas.estado IN ({$huecos}) THEN 1 ELSE 0 END) as continuan,
                SUM(CASE WHEN matriculas.estado = ? THEN 1 ELSE 0 END) as retirados
            ", [...Matricula::ESTADOS_INSCRITO, Matricula::RETIRADA])
            // EMPATADAS, POR NOMBRE Y DESPUES POR ID (03/10/2026, pedido del
            // usuario). Sin esto, dos promotorias con la misma cifra salian en
            // el orden que trajera la agrupacion de PostgreSQL, distinto de una
            // carga a otra. El `id` decide entre dos que se llaman igual, que
            // la base permite: sale primero la que se creo primero.
            ->orderBy('areas.nombre')->orderBy('areas.id')
            ->orderByDesc('continuan')
            ->orderBy('promotorias.nombre')->orderBy('promotorias.id')
            ->get();

        $departamentos = [];

        foreach ($filas as $fila) {
            $areaId = (int) $fila->area_id;
            $promotoriaId = (int) $fila->promotoria_id;
            $continuan = (int) $fila->continuan;
            $retirados = (int) $fila->retirados;
            $base = $baseRenovacion[$promotoriaId] ?? 0;
            $noVolvieron = $noRenovaron[$promotoriaId] ?? 0;

            $departamentos[$areaId] ??= [
                // Para el id del <details>: es lo que lo deja abierto cuando la
                // pagina se vuelve a pintar sin recargar.
                'id' => $areaId,
                'nombre' => $fila->area,
                'tag_class' => 'tag-'.($areaId % Area::NUM_COLORES_ETIQUETA),
                'total' => 0,
                'retirados' => 0,
                'base_renovacion' => 0,
                'no_renovaron' => 0,
                'promotorias' => [],
            ];

            $departamentos[$areaId]['total'] += $continuan;
            $departamentos[$areaId]['retirados'] += $retirados;
            $departamentos[$areaId]['base_renovacion'] += $base;
            $departamentos[$areaId]['no_renovaron'] += $noVolvieron;
            $departamentos[$areaId]['promotorias'][] = [
                'id' => $promotoriaId,
                'etiqueta' => $fila->promotoria,
                'total' => $continuan,
                'retirados' => $retirados,
                'base_renovacion' => $base,
                'no_renovaron' => $noVolvieron,
            ];
        }

        $arbol = array_values($departamentos);
        // `usort` es estable desde PHP 8: dos departamentos con el mismo total
        // conservan el orden de la consulta, que es por nombre y despues por id.
        usort($arbol, fn (array $a, array $b) => $b['total'] <=> $a['total']);

        return array_map(
            fn (array $d) => Grafica::conPermanencia([
                ...$d,
                'promotorias' => array_map(
                    Grafica::conPermanencia(...),
                    Grafica::conPorcentaje($d['promotorias'])
                ),
            ]),
            Grafica::conPorcentaje($arbol)
        );
    }

    /** @return list<array<string, mixed>> */
    private function gruposPorNivel(): array
    {
        $conteos = Grupo::groupBy('nivel')
            ->selectRaw('nivel, COUNT(*) as total')
            ->pluck('total', 'nivel')
            ->all();

        $filas = [];

        foreach (Grupo::NIVELES as $codigo => $etiqueta) {
            if (isset($conteos[$codigo])) {
                $filas[] = ['etiqueta' => $etiqueta, 'total' => (int) $conteos[$codigo]];
            }
        }

        return $filas;
    }

    /** @return list<array<string, mixed>> */
    private function porPeriodo(): array
    {
        return Matricula::query()
            ->where('matriculas.estado', Matricula::ACTIVA)
            ->join('periodos', 'periodos.id', '=', 'matriculas.periodo_id')
            ->groupBy('periodos.id', 'periodos.nombre', 'periodos.fecha_inicio')
            ->selectRaw('periodos.nombre as etiqueta, COUNT(*) as total')
            ->orderByDesc('periodos.fecha_inicio')
            ->get()
            ->map(fn ($fila) => ['etiqueta' => $fila->etiqueta, 'total' => (int) $fila->total])
            ->all();
    }

    /**
     * Quien dicto mas clases en el periodo, con cuantas le verificaron.
     *
     * La columna de verificadas NO es un adorno: en este sistema registrar una
     * clase es apretar un boton, y quien lo aprieta es parte interesada. Un
     * ranking a secas premiaria a quien mas veces lo pulsa, que no es lo mismo
     * que quien mas clases dio. Con las dos cifras al lado, la lista dice lo que
     * de verdad se puede afirmar: cuantas registro y cuantas dieron por ciertas
     * sus estudiantes.
     *
     * Se cuenta por el VINCULO con la promotoria y no por `registrada_por_id`,
     * igual que en el resto del sistema: un director que ademas dicta tiene que
     * aparecer aqui.
     *
     * @return list<array<string, mixed>>
     */
    private function profesoresMasActivos(?Periodo $periodo, int $cuantos = 10): array
    {
        if ($periodo === null) {
            return [];
        }

        $clases = Clase::query()
            ->where('clases.periodo_id', $periodo->id)
            ->join('grupos', 'grupos.id', '=', 'clases.grupo_id')
            ->join('promotorias', 'promotorias.id', '=', 'grupos.promotoria_id')
            ->whereNotNull('promotorias.profesor_id')
            ->withCount('confirmaciones')
            ->select('clases.*', 'promotorias.profesor_id')
            ->get();

        if ($clases->isEmpty()) {
            return [];
        }

        $porProfesor = [];

        foreach ($clases as $clase) {
            $id = $clase->profesor_id;
            $porProfesor[$id] ??= ['clases' => 0, 'verificadas' => 0, 'grupos' => []];
            $porProfesor[$id]['clases']++;
            $porProfesor[$id]['grupos'][$clase->grupo_id] = true;

            if ($clase->estaConfirmada($clase->confirmaciones_count)) {
                $porProfesor[$id]['verificadas']++;
            }
        }

        $perfiles = Perfil::whereIn('id', array_keys($porProfesor))->get()->keyBy('id');

        // Entre los que dieron las mismas clases, por nombre: la lista se
        // recorta y el corte caia en medio de un empate (ver `OrdenPorNombre`).
        uksort($porProfesor, fn ($a, $b) => $porProfesor[$b]['clases'] <=> $porProfesor[$a]['clases']
            ?: OrdenPorNombre::comparar(
                (string) $perfiles->get($a)?->nombre_completo,
                (string) $perfiles->get($b)?->nombre_completo
            ));
        $filas = [];

        foreach (array_slice($porProfesor, 0, $cuantos, true) as $id => $datos) {
            $perfil = $perfiles->get($id);

            if ($perfil === null) {
                continue;
            }

            $filas[] = [
                'perfil' => $perfil,
                'clases' => $datos['clases'],
                'verificadas' => $datos['verificadas'],
                'grupos' => count($datos['grupos']),
                'pct' => (int) round($datos['verificadas'] / $datos['clases'] * 100),
            ];
        }

        return $filas;
    }

    /**
     * Quien falta menos: el ranking de constancia.
     *
     * Se ordena por PORCENTAJE y no por numero de asistencias, porque la
     * pregunta es quien es constante y no quien tiene mas clases en su horario:
     * a quien cursa dos promotorias le caben el doble de sesiones y por el
     * numero absoluto encabezaria siempre la lista sin ser mas constante que
     * nadie.
     *
     * Y por eso mismo hace falta un minimo. Con una sola clase marcada, un
     * 1 de 1 es un 100 % que desplazaria a quien lleva veinte de veintidos, y
     * eso convertiria el ranking en una lista de gente que apenas ha empezado.
     * El minimo va explicado en la pantalla, no escondido aqui.
     *
     * Las clases sin marcar NO cuentan como falta: que quien dicta no haya
     * pasado lista no es un dato sobre el estudiante.
     *
     * @return list<array<string, mixed>>
     */
    private function estudiantesMasConstantes(?Periodo $periodo, int $cuantos = 10): array
    {
        if ($periodo === null) {
            return [];
        }

        $filas = Asistencia::query()
            ->join('matriculas', 'matriculas.id', '=', 'asistencias.matricula_id')
            ->where('matriculas.periodo_id', $periodo->id)
            ->whereIn('asistencias.estado', [Asistencia::ASISTIO, Asistencia::FALTO, Asistencia::EXCUSA])
            ->groupBy('matriculas.estudiante_id')
            ->selectRaw('
                matriculas.estudiante_id,
                COUNT(*) as marcadas,
                SUM(CASE WHEN asistencias.estado = ? THEN 1 ELSE 0 END) as asistio
            ', [Asistencia::ASISTIO])
            // La expresion y no el alias `marcadas`: PostgreSQL no deja usar
            // en el HAVING un alias del SELECT.
            ->havingRaw('COUNT(*) >= ?', [self::MINIMO_CLASES_CONSTANCIA])
            ->get();

        if ($filas->isEmpty()) {
            return [];
        }

        $perfiles = Perfil::whereIn('id', $filas->pluck('estudiante_id'))->get()->keyBy('id');

        return $filas
            ->map(function ($fila) use ($perfiles) {
                $marcadas = (int) $fila->marcadas;
                $asistio = (int) $fila->asistio;

                return [
                    'perfil' => $perfiles->get($fila->estudiante_id),
                    'asistio' => $asistio,
                    'marcadas' => $marcadas,
                    'pct' => (int) round($asistio / $marcadas * 100),
                ];
            })
            ->filter(fn (array $f) => $f['perfil'] !== null)
            // El desempate va por numero de clases: entre dos que no faltaron
            // nunca, la lista encabeza quien lo sostuvo mas veces. Y si tambien
            // empatan ahi, por nombre (ver `OrdenPorNombre`): la lista se
            // recorta y el corte caia en medio de un empate.
            ->sort(fn (array $a, array $b) => [$b['pct'], $b['marcadas']] <=> [$a['pct'], $a['marcadas']]
                ?: OrdenPorNombre::comparar($a['perfil']->nombre_completo, $b['perfil']->nombre_completo))
            ->take($cuantos)
            ->values()
            ->all();
    }

    /**
     * Cuantas encuestas existen pero estan a medias, contado en SQL (C-02).
     *
     * Antes se resolvia recorriendo la coleccion entera con el accesor
     * `esta_completa`, y el comentario de `conteo()` sostenia que en SQL no
     * habia version buena porque «`estrato` es entero y los demas texto». Al
     * mirar el esquema resulta que los cinco campos obligatorios son NOT NULL:
     * «sin responder» es la cadena vacia, no un nulo. Y sobre `estrato`, que es
     * un entero, la comprobacion de cadena vacia del accesor NO PUEDE disparar
     * nunca --un int nunca es identico a ''--, asi que ese campo no entra en la
     * cuenta ni entraba antes. El filtro de aqui hace exactamente lo mismo que
     * hacia el accesor, ni un caso mas ni uno menos.
     *
     * OJO, esto deja un hueco que ya existia y que NO se cierra aqui: si alguna
     * vez se guardara una encuesta con `estrato` en 0 --el valor que pondria
     * MySQL en una columna NOT NULL sin dato-- no la contaria ninguna de las
     * dos versiones. Hoy no ocurre (los 227 registros van de 1 a 6) y
     * arreglarlo cambiaria la cifra que ve direccion, que es otra conversacion.
     */
    private function encuestasIncompletas(): int
    {
        // `barrio` entra aunque no se cuente en ninguna grafica: es obligatorio,
        // y lo que se mide es si la persona termino de contestar.
        $obligatoriosDeTexto = ['genero', 'barrio', 'nivel_educativo', 'ocupacion'];

        return EncuestaDemografica::where(function ($q) use ($obligatoriosDeTexto) {
            foreach ($obligatoriosDeTexto as $campo) {
                $q->orWhere($campo, '');
            }
        })->count();
    }

    /** @return array<string, int> */
    private function autorizacion(int $total): array
    {
        $si = EncuestaDemografica::where('autoriza_tratamiento_datos', true)->count();
        $pctSi = $total ? (int) round($si / $total * 100) : 0;

        return [
            'autorizaSi' => $si,
            'autorizaNo' => $total - $si,
            'pctAutorizaSi' => $pctSi,
            'pctAutorizaNo' => 100 - $pctSi,
        ];
    }
}
