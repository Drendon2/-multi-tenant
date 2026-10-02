<?php

namespace App\Support;

use App\Models\Institucion;
use App\Models\Matricula;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Models\User;
use Generator;
use Illuminate\Support\Collection;

/**
 * Las cifras de TODAS las instituciones, para el panel (paso 5, 02/10/2026).
 *
 * ─── COMO SE CALCULAN (decision del usuario) ───────────────────────────────
 *
 * Institucion por institucion, con `InstitucionActual::mientras()`, bajo RLS
 * y con las MISMAS clases que pintan la cinta de Gestion
 * (`ResumenInstitucion`) y la encuesta de Estadisticas (`ResumenDemografico`).
 * No con el rol `matriculas_global`, que se salta RLS y que se habia pensado
 * para esto en el paso 3: obligaba a reescribir esas cuentas en SQL propio, y
 * una cifra calculada en dos sitios acaba diciendo dos cosas. Asi ninguna
 * consulta se salta RLS. Medido: 13 consultas y ~60 ms por institucion.
 *
 * ─── QUE ENTRA (decision del usuario) ──────────────────────────────────────
 *
 * Poblacion impactada, promotorias, datos demograficos y profesores por
 * promotoria. NADA de asistencia a clase: el panel general no la necesita.
 *
 * ─── EL PERIODO ES EL DE CADA UNA ──────────────────────────────────────────
 *
 * Cada institucion tiene su calendario, asi que «estudiantes activos» es el
 * de SU periodo en curso. Los totales suman cifras de periodos distintos, y
 * una persona inscrita en dos municipios cuenta dos veces: el documento es
 * unico DENTRO de cada institucion, no entre ellas.
 */
final class ResumenGlobal
{
    /** Las cifras de la cinta que se suman en la fila de totales. */
    public const SUMABLES = ['estudiantesActivos', 'poblacionImpactada', 'profesores', 'promotorias',
        'grupos', 'cursosYTalleres', 'proyeccion', 'programasExternos', 'cuposDisponibles'];

    /** @return Collection<int, Institucion> */
    public static function instituciones(): Collection
    {
        return Institucion::orderBy('nombre')->orderBy('id')->get();
    }

    /**
     * Una fila por institucion.
     *
     * @return list<array{institucion: Institucion, periodo: ?string, cifras: array<string, int>, cuentasActivas: int, ultimaMatricula: ?string, encuestas: int}>
     */
    public static function porInstitucion(): array
    {
        return self::instituciones()->map(fn (Institucion $institucion) => InstitucionActual::mientras(
            $institucion->id,
            function () use ($institucion) {
                $periodo = Periodo::enCurso();

                return [
                    'institucion' => $institucion,
                    'periodo' => $periodo?->nombre,
                    'cifras' => ResumenInstitucion::cifras($periodo),
                    'cuentasActivas' => User::where('activo', true)->count(),
                    // Si esa casa se esta usando: la ultima matricula que entro.
                    'ultimaMatricula' => Matricula::max('fecha'),
                    'encuestas' => ResumenDemografico::deLaInstitucion()['total'],
                ];
            }
        ))->all();
    }

    /**
     * La fila de totales: cada cifra sumable, sumada.
     *
     * @param  list<array{cifras: array<string, int>, cuentasActivas: int, encuestas: int}>  $filas
     * @return array<string, int>
     */
    public static function totales(array $filas): array
    {
        $totales = array_fill_keys([...self::SUMABLES, 'cuentasActivas', 'encuestas'], 0);

        foreach ($filas as $fila) {
            foreach (self::SUMABLES as $cifra) {
                $totales[$cifra] += (int) ($fila['cifras'][$cifra] ?? 0);
            }
            $totales['cuentasActivas'] += $fila['cuentasActivas'];
            $totales['encuestas'] += $fila['encuestas'];
        }

        return $totales;
    }

    /**
     * La encuesta de cada institucion, contada, y la de todas sumada.
     *
     * @return array{todas: array{total: int, conteos: array<string, array<int|string, int>>}, porInstitucion: array<int, array{institucion: Institucion, resumen: array{total: int, conteos: array<string, array<int|string, int>>}}>}
     */
    public static function demografia(): array
    {
        $porInstitucion = self::instituciones()->map(fn (Institucion $institucion) => [
            'institucion' => $institucion,
            'resumen' => InstitucionActual::mientras($institucion->id, fn () => ResumenDemografico::deLaInstitucion()),
        ])->all();

        return [
            'todas' => ResumenDemografico::sumar(array_column($porInstitucion, 'resumen')),
            'porInstitucion' => $porInstitucion,
        ];
    }

    /**
     * Las promotorias de una institucion con su profesor y cuantos tiene
     * inscritos en el periodo en curso (pendientes y activas, la cuenta del
     * cupo: `Promotoria::ocupadosEnLote()`). Corre en la institucion actual.
     *
     * @return list<array{departamento: string, promotoria: string, profesor: ?string, telefono: ?string, correo: ?string, inscritos: int, cupo: ?int}>
     */
    public static function promotoriasDeLaInstitucion(): array
    {
        $periodo = Periodo::enCurso();
        // Ordenadas en la base, con su cotejo (sin mayusculas ni tildes), y
        // con desempate por id.
        $promotorias = Promotoria::query()
            ->select('promotorias.*')
            ->join('areas', 'areas.id', '=', 'promotorias.area_id')
            ->with(['area', 'profesor.user', 'cupos'])
            ->orderBy('areas.nombre')
            ->orderBy('promotorias.nombre')
            ->orderBy('promotorias.id')
            ->get();
        $inscritos = Promotoria::ocupadosEnLote($periodo, $promotorias);

        return $promotorias->map(fn (Promotoria $p) => [
            'departamento' => (string) $p->area?->nombre,
            'promotoria' => $p->nombre,
            'profesor' => $p->profesor?->nombre_completo,
            'telefono' => $p->profesor?->telefono,
            'correo' => $p->profesor?->user?->email,
            'inscritos' => (int) ($inscritos[$p->id] ?? 0),
            'cupo' => $p->cupoEn($periodo),
        ])->all();
    }

    /**
     * Las promotorias de TODAS, una institucion tras otra, para la descarga.
     *
     * @return Generator<int, array{institucion: Institucion, fila: array<string, mixed>}>
     */
    public static function promotorias(): Generator
    {
        foreach (self::instituciones() as $institucion) {
            yield from InstitucionActual::recorriendo($institucion->id, function () use ($institucion) {
                foreach (self::promotoriasDeLaInstitucion() as $fila) {
                    yield ['institucion' => $institucion, 'fila' => $fila];
                }
            });
        }
    }
}
