<?php

namespace App\Support;

use App\Models\Actividad;
use App\Models\AsistenciaActividad;
use App\Models\Matricula;
use App\Models\Periodo;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Las cifras de la gente que pasa por la casa SIN matricula: cursos, talleres,
 * grupos de proyeccion y programas externos.
 *
 * ─── NUNCA SE SUMAN A LAS DE MATRICULA (decision del usuario, 25/09/2026) ──
 *
 * Un estudiante de semestre y quien fue a un taller de un dia no responden a
 * la misma pregunta, y una cifra que los mezcle no dice nada de ninguno. Por
 * eso cada tipo va por su lado y lo unico que cruza las dos mitades es
 * `tambienMatriculados`: cuantos de estos son ademas estudiantes del periodo.
 * Es el mismo argumento por el que no se colapsan las dos cifras de
 * verificacion de una clase.
 *
 * LA UNICA EXCEPCION es «Poblacion impactada», en la cinta de arriba
 * (`ResumenInstitucion::poblacionImpactada()`): una cifra de ALCANCE que el
 * usuario pidio el mismo dia, con esta regla delante. No abre la puerta a
 * medias ni repartos mezclados.
 *
 * ─── A QUE PERIODO PERTENECE UNA ACTIVIDAD ─────────────────────────────────
 *
 * `actividades.periodo_id` admite NULL a proposito (se monta un taller sin
 * periodo en curso). La regla decidida: con periodo, cuenta en el suyo; sin
 * el, en los periodos donde cayeron sus sesiones; sin periodo y sin sesiones,
 * en ninguno.
 *
 * Y para las que NO tienen periodo solo cuentan las sesiones que caen dentro
 * de las fechas del periodo mirado. Un grupo de proyeccion suele vivir todo el
 * ano sin periodo: sin este corte apareceria en los dos semestres con TODOS sus
 * ensayos en cada uno, y la suma de los dos doblaria lo que paso.
 *
 * ─── CONSULTAS FIJAS ───────────────────────────────────────────────────────
 *
 * Cinco, agrupadas por tipo, sin hidratar un modelo por fila: esta pantalla
 * barre la institucion entera, y «Fichas por completar» ya revento la memoria
 * haciendo lo contrario.
 */
class ResumenActividades
{
    /** El orden en que se pintan los tipos. */
    private const ORDEN = [Actividad::CURSO, Actividad::TALLER, Actividad::PROYECCION, Actividad::EXTERNO];

    /**
     * En plural, y por eso no es `Actividad::ETIQUETA_TIPO`: aqui el rotulo
     * encabeza una cinta que cuenta todas las de ese tipo, no nombra una.
     */
    private const TITULO = [
        Actividad::CURSO => 'Cursos',
        Actividad::TALLER => 'Talleres',
        Actividad::PROYECCION => 'Grupos de proyección',
        Actividad::EXTERNO => 'Programas externos',
    ];

    /**
     * Las actividades que pertenecen a un periodo: las que lo tienen puesto, y
     * las que no tienen ninguno pero dieron alguna sesion dentro de sus fechas.
     *
     * Publica porque la usa tambien `ResumenInstitucion` para la poblacion
     * impactada: escrita dos veces, las dos cifras de la misma pantalla
     * acabarian contando actividades distintas.
     *
     * @return array<int, string> id => tipo
     */
    public static function actividadesDelPeriodo(Periodo $periodo): array
    {
        $inicio = Carbon::parse($periodo->fecha_inicio)->toDateString();
        $fin = Carbon::parse($periodo->fecha_fin)->toDateString();

        return DB::table('actividades as a')
            ->where('a.periodo_id', $periodo->id)
            ->orWhere(fn (Builder $q) => $q
                ->whereNull('a.periodo_id')
                ->whereExists(fn (Builder $s) => $s
                    ->from('sesiones_actividad as s')
                    ->whereColumn('s.actividad_id', 'a.id')
                    ->whereBetween('s.fecha', [$inicio, $fin])))
            ->pluck('a.tipo', 'a.id')
            ->all();
    }

    /**
     * @return array{
     *     tipos: array<string, array{etiqueta: string, actividades: int, inscritos: int, sesiones: int, asistencias: int}>,
     *     verificacion: array{iniciadas: int, propia: int, qr: int}|null,
     *     tambienMatriculados: int,
     * }|null null si en ese periodo no hay ninguna actividad
     */
    public static function delPeriodo(?Periodo $periodo): ?array
    {
        if ($periodo === null) {
            return null;
        }

        $inicio = Carbon::parse($periodo->fecha_inicio)->toDateString();
        $fin = Carbon::parse($periodo->fecha_fin)->toDateString();

        $actividades = self::actividadesDelPeriodo($periodo);

        if ($actividades === []) {
            return null;
        }

        $ids = array_keys($actividades);

        // Las sesiones que cuentan: todas las de una actividad con periodo, y
        // solo las del rango en una sin el. Ver la cabecera.
        $sesionesDelPeriodo = fn (Builder $q) => $q
            ->whereIn('s.actividad_id', $ids)
            ->where(fn (Builder $w) => $w
                ->whereNotNull('a.periodo_id')
                ->orWhereBetween('s.fecha', [$inicio, $fin]));

        $inscritos = DB::table('inscritos_actividad as i')
            ->join('actividades as a', 'a.id', '=', 'i.actividad_id')
            ->whereIn('i.actividad_id', $ids)
            ->groupBy('a.tipo')
            ->selectRaw('a.tipo, COUNT(*) as total')
            ->pluck('total', 'tipo');

        // Sesiones CON LISTA TOMADA, medido por existencia de marcas y no por
        // `iniciada_en`: es el mismo criterio que el certificado de actividad
        // (`AsistenciaDeActividad`). Una sesion iniciada sin marcas no dice
        // nada de nadie.
        $marcas = DB::table('asistencias_actividad as x')
            ->join('sesiones_actividad as s', 's.id', '=', 'x.sesion_id')
            ->join('actividades as a', 'a.id', '=', 's.actividad_id')
            ->where($sesionesDelPeriodo)
            ->groupBy('a.tipo')
            ->selectRaw('a.tipo, COUNT(DISTINCT s.id) as sesiones, SUM(x.estado = ?) as asistencias', [AsistenciaActividad::ASISTIO])
            ->get()
            ->keyBy('tipo');

        $tipos = [];

        foreach (self::ORDEN as $tipo) {
            $cuantas = count(array_filter($actividades, fn ($t) => $t === $tipo));

            if ($cuantas === 0) {
                continue;
            }

            $tipos[$tipo] = [
                'etiqueta' => self::TITULO[$tipo],
                'actividades' => $cuantas,
                'inscritos' => (int) ($inscritos[$tipo] ?? 0),
                'sesiones' => (int) ($marcas[$tipo]->sesiones ?? 0),
                'asistencias' => (int) ($marcas[$tipo]->asistencias ?? 0),
            ];
        }

        return [
            'tipos' => $tipos,
            'verificacion' => isset($tipos[Actividad::EXTERNO])
                ? self::verificacion($sesionesDelPeriodo)
                : null,
            'tambienMatriculados' => DB::table('inscritos_actividad')
                ->whereIn('actividad_id', $ids)
                ->whereIn('perfil_id', Matricula::query()
                    ->where('periodo_id', $periodo->id)
                    ->where('estado', Matricula::ACTIVA)
                    ->select('estudiante_id'))
                ->distinct()
                ->count('perfil_id'),
        ];
    }

    /**
     * Cuantas clases iniciadas de programas externos dio por buenas la otra
     * institucion, y por que camino.
     *
     * Las dos cifras NO se colapsan (`VerificacionExterna`): firmar desde la
     * cuenta y leer el QR que escanea el propio profesor no dan la misma
     * garantia, y una suma de las dos no se puede auditar.
     *
     * @param  \Closure(Builder): Builder  $sesionesDelPeriodo
     * @return array{iniciadas: int, propia: int, qr: int}
     */
    private static function verificacion(\Closure $sesionesDelPeriodo): array
    {
        $porOrigen = DB::table('sesiones_actividad as s')
            ->join('actividades as a', 'a.id', '=', 's.actividad_id')
            ->where('a.tipo', Actividad::EXTERNO)
            ->whereNotNull('s.iniciada_en')
            ->where($sesionesDelPeriodo)
            ->groupBy('s.verificacion_origen')
            ->selectRaw("COALESCE(s.verificacion_origen, '') as origen, COUNT(*) as total")
            ->pluck('total', 'origen');

        return [
            'iniciadas' => (int) $porOrigen->sum(),
            'propia' => (int) ($porOrigen[VerificacionExterna::PROPIA] ?? 0),
            'qr' => (int) ($porOrigen[VerificacionExterna::QR] ?? 0),
        ];
    }
}
