<?php

namespace App\Support;

use App\Models\Asistencia;
use App\Models\ConfiguracionInstitucion;
use App\Models\Grupo;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use Illuminate\Support\Carbon;

/**
 * Las estadisticas de un profesor sobre SUS promotorias, en un periodo.
 *
 * Pedidas por el usuario el 27/09/2026, con las definiciones tomadas por el:
 *
 * - CLASES DADAS son las registradas en los grupos de sus promotorias, las haya
 *   iniciado el o un reemplazo. No `registrada_por`, que es lo que ensena «Mi
 *   perfil»: aqui la pregunta es por SUS grupos, y asi cuadra con las perdidas,
 *   que tambien son de sus grupos.
 * - CLASES PERDIDAS salen de la misma cuenta que la bandeja de alertas
 *   (`Alertas::clasesNoDictadas`), no de una segunda: cuentan desde que se
 *   encendieron las alertas. Las ARCHIVADAS SI cuentan (decision del usuario,
 *   27/09/2026, viendo Percusion en produccion: 2 perdidas a la vista y 18
 *   reales): archivar limpia la bandeja, pero la clase no se dio. Se dice
 *   cuantas estan archivadas.
 * - CANCELACIONES son solo las TRAMITADAS: pedidas por el estudiante y
 *   aprobadas por la direccion (`motivo_retiro = cancelacion`). Quien se retiro
 *   solo o fue retirado por inasistencia no entra.
 * - RENOVACION es la de Estadisticas del administrador: de quienes cursaron
 *   con el el periodo ANTERIOR, cuantos siguen en la misma promotoria en este.
 *
 * El profesor solo ve cifras, nunca nombres: nada de aqui abre un dato de una
 * persona que no pudiera ver ya en su Panel.
 */
class EstadisticasDeProfesor
{
    /**
     * Los periodos que tienen algo de este profesor, del mas reciente al mas
     * antiguo: matriculas en sus promotorias o clases en sus grupos.
     *
     * @return list<Periodo>
     */
    public static function periodos(Perfil $profesor): array
    {
        $promotorias = Promotoria::where('profesor_id', $profesor->id)->pluck('id');

        $ids = Matricula::whereIn('promotoria_id', $promotorias)->distinct()->pluck('periodo_id')
            ->merge(InstitucionActual::tabla('clases')
                ->join('grupos', 'grupos.id', '=', 'clases.grupo_id')
                ->whereIn('grupos.promotoria_id', $promotorias)
                ->distinct()->pluck('clases.periodo_id'))
            ->unique();

        // El periodo EN CURSO entra siempre que tenga alguna promotoria, aunque
        // aun no haya clases ni matriculas: quien tiene grupos con horario y no
        // ha registrado ninguna clase es justo quien tiene clases perdidas que
        // ver, y sin esto se quedaba con una pantalla vacia.
        if ($promotorias->isNotEmpty() && ($enCurso = Periodo::enCurso()) !== null) {
            $ids->push($enCurso->id);
        }

        return Periodo::whereIn('id', $ids->unique())->orderByDesc('fecha_inicio')->get()->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function de(Perfil $profesor, Periodo $periodo): array
    {
        $promotorias = Promotoria::where('profesor_id', $profesor->id)->orderBy('nombre')->get(['id', 'nombre']);
        $grupos = Grupo::whereIn('promotoria_id', $promotorias->pluck('id'))
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'nivel', 'promotoria_id']);
        $idsGrupos = $grupos->pluck('id');

        $dadas = InstitucionActual::tabla('clases')
            ->where('periodo_id', $periodo->id)
            ->whereIn('grupo_id', $idsGrupos)
            ->selectRaw('grupo_id, COUNT(*) as n')
            ->groupBy('grupo_id')
            ->pluck('n', 'grupo_id');

        // Las marcas de asistencia por grupo y estado, en una consulta.
        $marcas = InstitucionActual::tabla('asistencias')
            ->join('clases', 'clases.id', '=', 'asistencias.clase_id')
            ->where('clases.periodo_id', $periodo->id)
            ->whereIn('clases.grupo_id', $idsGrupos)
            ->selectRaw('clases.grupo_id, asistencias.estado, COUNT(*) as n')
            ->groupBy('clases.grupo_id', 'asistencias.estado')
            ->get();

        $perdidas = self::perdidas($profesor, $periodo);

        $porGrupo = [];
        $totales = [Asistencia::ASISTIO => 0, Asistencia::EXCUSA => 0, Asistencia::FALTO => 0];

        // Con una sola promotoria el nombre sobra en cada fila; con varias, es
        // lo que distingue «Grupo A» de «Grupo A».
        $variasPromotorias = $promotorias->count() > 1;
        $nombres = $promotorias->pluck('nombre', 'id');

        foreach ($grupos as $grupo) {
            $suyas = $marcas->where('grupo_id', $grupo->id);
            $cuenta = [Asistencia::ASISTIO => 0, Asistencia::EXCUSA => 0, Asistencia::FALTO => 0];
            foreach ($suyas as $m) {
                if (isset($cuenta[$m->estado])) {
                    $cuenta[$m->estado] += (int) $m->n;
                    $totales[$m->estado] += (int) $m->n;
                }
            }
            $marcadas = array_sum($cuenta);
            $clases = (int) ($dadas[$grupo->id] ?? 0);
            $falto = (int) ($perdidas['porGrupo'][$grupo->id] ?? 0);

            // Un grupo sin nada en este periodo no se pinta: una fila de ceros
            // no dice nada y alarga la pantalla.
            if (! $clases && ! $falto && ! $marcadas) {
                continue;
            }

            $porGrupo[] = [
                'etiqueta' => ($variasPromotorias ? $nombres[$grupo->promotoria_id].' · ' : '').$grupo->nombre_con_nivel,
                'dadas' => $clases,
                'perdidas' => $falto,
                'asistencia' => $marcadas ? (int) floor($cuenta[Asistencia::ASISTIO] / $marcadas * 100) : null,
            ];
        }

        $marcadas = array_sum($totales);

        return [
            'promotorias' => $promotorias->pluck('nombre')->all(),
            'clasesDadas' => (int) $dadas->sum(),
            'perdidas' => $perdidas,
            // `floor` y no `round`, por la misma razon que el certificado: un
            // 79,6 % no se pinta como «80 %».
            'asistencia' => $marcadas ? (int) floor($totales[Asistencia::ASISTIO] / $marcadas * 100) : null,
            // El ORDEN decide el color (la rampa se asigna en orden fijo): asi
            // «Faltó» cae en el naranja y no en el aqua, que se leia como algo
            // bueno. Visto en el navegador, no en las pruebas.
            'tortaAsistencia' => Grafica::torta([
                ['etiqueta' => 'Asistió', 'total' => $totales[Asistencia::ASISTIO]],
                ['etiqueta' => 'Faltó', 'total' => $totales[Asistencia::FALTO]],
                ['etiqueta' => 'Faltó con excusa', 'total' => $totales[Asistencia::EXCUSA]],
            ], $marcadas),
            'porGrupo' => $porGrupo,
            'maxClases' => max([1, ...array_map(fn ($g) => $g['dadas'] + $g['perdidas'], $porGrupo)]),
            'cancelaciones' => self::cancelaciones($promotorias->pluck('id')->all(), $periodo),
            'renovacion' => self::renovacion($promotorias->pluck('id')->all(), $periodo),
        ];
    }

    /**
     * Las clases perdidas, o por que no se pueden contar.
     *
     * Dos casos devuelven `null` en la cifra y un motivo en su lugar, porque un
     * cero ahi se leeria como «no falto a ninguna»: las alertas apagadas, y un
     * periodo que termino antes de que empezaran a contar.
     *
     * @return array{total: ?int, archivadas: int, porGrupo: array<int, int>, motivo: ?string, desde: ?Carbon}
     */
    private static function perdidas(Perfil $profesor, Periodo $periodo): array
    {
        if (! ConfiguracionInstitucion::actual()->alerta_clase_no_dictada) {
            return ['total' => null, 'archivadas' => 0, 'porGrupo' => [], 'motivo' => 'apagadas', 'desde' => null];
        }

        $desde = Alertas::desde($periodo);

        if ($desde->gt(Carbon::parse($periodo->fecha_fin)->startOfDay())) {
            return ['total' => null, 'archivadas' => 0, 'porGrupo' => [], 'motivo' => 'fuera', 'desde' => $desde];
        }

        $faltas = Alertas::clasesNoDictadas($periodo, $profesor, conArchivadas: true);

        return [
            'total' => $faltas->count(),
            'archivadas' => $faltas->where('archivada', true)->count(),
            'porGrupo' => $faltas->countBy(fn ($f) => $f['grupo']->id)->all(),
            'motivo' => null,
            'desde' => $desde,
        ];
    }

    /**
     * Las cancelaciones TRAMITADAS del periodo, y las que esperan a la
     * direccion, que aun no son ni una cosa ni la otra.
     *
     * @param  list<int>  $promotorias
     * @return array{tramitadas: int, enTramite: int, matriculados: int}
     */
    private static function cancelaciones(array $promotorias, Periodo $periodo): array
    {
        $fila = InstitucionActual::tabla('matriculas')
            ->where('periodo_id', $periodo->id)
            ->whereIn('promotoria_id', $promotorias)
            ->selectRaw(
                'SUM(CASE WHEN estado = ? AND motivo_retiro = ? THEN 1 ELSE 0 END) as tramitadas,
                 SUM(CASE WHEN estado = ? THEN 1 ELSE 0 END) as en_tramite,
                 SUM(CASE WHEN estado <> ? AND NOT (estado = ? AND motivo_retiro IS NOT DISTINCT FROM ?) THEN 1 ELSE 0 END) as matriculados',
                [
                    Matricula::RETIRADA, Matricula::RETIRO_CANCELACION,
                    Matricula::CANCELACION_SOLICITADA,
                    Matricula::PENDIENTE, Matricula::RETIRADA, Matricula::RETIRO_RECHAZO,
                ]
            )
            ->first();

        return [
            'tramitadas' => (int) ($fila->tramitadas ?? 0),
            'enTramite' => (int) ($fila->en_tramite ?? 0),
            // Quienes llegaron a entrar: ni pendientes ni solicitudes no
            // aceptadas. Es la base contra la que se lee la cifra de arriba.
            'matriculados' => (int) ($fila->matriculados ?? 0),
        ];
    }

    /**
     * Quienes cursaron con el el periodo ANTERIOR y siguen en la misma
     * promotoria en este. La misma cuenta que Estadisticas del administrador.
     *
     * @param  list<int>  $promotorias
     * @return array{anterior: ?string, base: int, renovaron: int, torta: ?array<string, mixed>}
     */
    private static function renovacion(array $promotorias, Periodo $periodo): array
    {
        $previo = Periodo::where('fecha_inicio', '<', $periodo->fecha_inicio)->orderByDesc('fecha_inicio')->first();

        if ($previo === null) {
            return ['anterior' => null, 'base' => 0, 'renovaron' => 0, 'torta' => null];
        }

        $siguen = Matricula::where('periodo_id', $periodo->id)
            ->whereIn('promotoria_id', $promotorias)
            ->where('estado', '!=', Matricula::RETIRADA)
            ->get(['estudiante_id', 'promotoria_id'])
            ->map(fn (Matricula $m) => "{$m->estudiante_id}:{$m->promotoria_id}")
            ->flip();

        $anteriores = Matricula::where('periodo_id', $previo->id)
            ->whereIn('promotoria_id', $promotorias)
            ->where('estado', Matricula::ACTIVA)
            ->get(['estudiante_id', 'promotoria_id']);

        $renovaron = $anteriores->filter(fn (Matricula $m) => $siguen->has("{$m->estudiante_id}:{$m->promotoria_id}"))->count();
        $base = $anteriores->count();

        return [
            'anterior' => $previo->nombre,
            'base' => $base,
            'renovaron' => $renovaron,
            'torta' => $base ? Grafica::torta([
                ['etiqueta' => 'Renovaron', 'total' => $renovaron],
                ['etiqueta' => 'No volvieron', 'total' => $base - $renovaron],
            ], $base) : null,
        ];
    }
}
