<?php

namespace App\Http\Controllers\Operador;

use App\Http\Controllers\Controller;
use App\Models\EncuestaDemografica;
use App\Models\Institucion;
use App\Support\Auditoria;
use App\Support\Csv;
use App\Support\Grafica;
use App\Support\InformeActividades;
use App\Support\InformeInstitucion;
use App\Support\InstitucionActual;
use App\Support\Panel;
use App\Support\ResumenDemografico;
use App\Support\ResumenGlobal;
use Generator;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * El resumen de TODAS las instituciones y sus descargas consolidadas (paso 5,
 * 02/10/2026). Lo que entra lo decidio el usuario: poblacion impactada,
 * promotorias, datos demograficos y profesores por promotoria, sin nada de
 * asistencia a clase. Y «muy importante»: descargarlo todo consolidado.
 *
 * Las cifras salen de `ResumenGlobal`, institucion por institucion y bajo
 * RLS. Las descargas repiten los informes de cada institucion
 * (`InformeInstitucion`, `InformeActividades`) una tras otra, con una columna
 * «Institución» delante: el mismo codigo que la descarga de cada una.
 *
 * TODAS LAS DESCARGAS QUEDAN EN LA AUDITORIA, con el operador: dos llevan
 * datos de menores de todas las instituciones.
 */
class ResumenController extends Controller
{
    public function index(): View
    {
        $filas = ResumenGlobal::porInstitucion();
        $demografia = ResumenGlobal::demografia();
        $promotorias = ResumenGlobal::instituciones()
            ->map(fn (Institucion $institucion) => [
                'institucion' => $institucion,
                'promotorias' => InstitucionActual::mientras($institucion->id, fn () => ResumenGlobal::promotoriasDeLaInstitucion()),
            ])->all();

        return view('operador.resumen', [
            'filas' => $filas,
            'totales' => ResumenGlobal::totales($filas),
            'demografia' => $demografia['todas'],
            'graficas' => ResumenDemografico::graficas($demografia['todas']),
            'promotorias' => $promotorias,
        ]);
    }

    /** Una fila por institucion con sus cifras, y la de totales al final. */
    public function descargarResumen(): StreamedResponse
    {
        $this->registrar('resumen');

        $filas = function (): Generator {
            $porInstitucion = ResumenGlobal::porInstitucion();

            foreach ($porInstitucion as $f) {
                yield $this->filaDeResumen($f['institucion']->nombre, $f['institucion'], $f['periodo'], $f['cifras'], $f['cuentasActivas'], $f['encuestas'], $f['ultimaMatricula']);
            }

            yield $this->filaDeResumen('Todas', null, null, ResumenGlobal::totales($porInstitucion), null, null, null);
        };

        return Csv::descargar('instituciones-resumen', [
            'Institución', 'Dirección', 'Estado', 'Periodo en curso',
            'Estudiantes activos', 'Población impactada', 'Profesores', 'Promotorías', 'Grupos',
            'Cursos y talleres', 'Grupos de proyección', 'Programas externos', 'Cupos disponibles',
            'Cuentas activas', 'Encuestas demográficas', 'Última matrícula',
        ], $filas());
    }

    public function descargarPromotorias(): StreamedResponse
    {
        $this->registrar('promotorias');

        $filas = function (): Generator {
            foreach (ResumenGlobal::promotorias() as $p) {
                $f = $p['fila'];

                yield array_map(Csv::celda(...), [
                    $p['institucion']->nombre, $f['departamento'], $f['promotoria'], $f['profesor'],
                    $f['telefono'], $f['correo'], $f['inscritos'], $f['cupo'],
                ]);
            }
        };

        return Csv::descargar('instituciones-promotorias', [
            'Institución', 'Departamento', 'Promotoría', 'Profesor', 'Teléfono del profesor',
            'Correo del profesor', 'Inscritos en el periodo en curso', 'Cupo',
        ], $filas());
    }

    /**
     * La encuesta CONTADA, en formato largo: una fila por institucion, pregunta
     * y opcion, con «Todas» al final. Largo y no ancho porque es el que se
     * convierte en tabla dinamica sin tocarlo, y no depende de cuantas
     * instituciones haya.
     */
    public function descargarDemografia(): StreamedResponse
    {
        $this->registrar('demografia');

        $filas = function (): Generator {
            $demografia = ResumenGlobal::demografia();
            $grupos = array_map(fn ($d) => [$d['institucion']->nombre, $d['resumen']], $demografia['porInstitucion']);
            $grupos[] = ['Todas', $demografia['todas']];

            foreach ($grupos as [$nombre, $resumen]) {
                foreach (EncuestaDemografica::OPCIONES as $campo => $opciones) {
                    foreach (Grafica::porOpcion($resumen['conteos'][$campo] ?? [], $opciones, $resumen['total']) as $opcion) {
                        yield array_map(Csv::celda(...), [
                            $nombre,
                            ResumenDemografico::PREGUNTAS[$campo],
                            $campo === 'estrato' && ! ($opcion['sin_responder'] ?? false) ? 'Estrato '.$opcion['etiqueta'] : $opcion['etiqueta'],
                            $opcion['total'],
                            $resumen['total'],
                        ]);
                    }
                }
            }
        };

        return Csv::descargar('instituciones-demografia', [
            'Institución', 'Pregunta', 'Respuesta', 'Personas', 'Encuestas de la institución',
        ], $filas());
    }

    /** El informe completo de cada institucion, todas en un archivo. */
    public function descargarPersonas(): StreamedResponse
    {
        $this->registrar('personas');

        $filas = function (): Generator {
            foreach (ResumenGlobal::instituciones() as $institucion) {
                yield from InstitucionActual::recorriendo($institucion->id, function () use ($institucion) {
                    foreach ((new InformeInstitucion(consolidado: true))->filas() as $fila) {
                        yield [Csv::celda($institucion->nombre), ...$fila];
                    }
                });
            }
        };

        return Csv::descargar('instituciones-personas',
            ['Institución', ...InformeInstitucion::cabeceraConsolidada()], $filas());
    }

    /** La gente de cursos, talleres, proyeccion y programas externos de todas. */
    public function descargarActividades(): StreamedResponse
    {
        $this->registrar('actividades');

        $filas = function (): Generator {
            foreach (ResumenGlobal::instituciones() as $institucion) {
                yield from InstitucionActual::recorriendo($institucion->id, function () use ($institucion) {
                    foreach (InformeActividades::filas() as $fila) {
                        yield [Csv::celda($institucion->nombre), ...array_map(Csv::celda(...), $fila)];
                    }
                });
            }
        };

        // En este informe «Institución» es la EXTERNA donde se dicta un
        // programa; aqui la primera columna ya se llama asi, y dos cabeceras
        // iguales rompen cualquier tabla dinamica.
        $cabecera = array_map(
            fn (string $c) => str_contains($c, 'institución') || $c === 'Institución' ? $c.' externa' : $c,
            InformeActividades::cabecera()
        );

        return Csv::descargar('instituciones-actividades', ['Institución', ...$cabecera], $filas());
    }

    /**
     * @param  array<string, int>  $cifras
     * @return list<string>
     */
    private function filaDeResumen(string $nombre, ?Institucion $institucion, ?string $periodo, array $cifras, ?int $cuentas, ?int $encuestas, ?string $ultima): array
    {
        return array_map(Csv::celda(...), [
            $nombre,
            $institucion ? Panel::urlDe($institucion, '/') : null,
            $institucion ? ($institucion->estado === Institucion::SUSPENDIDA ? 'Suspendida' : 'Activa') : null,
            $periodo,
            ...array_map(fn (string $c) => $cifras[$c] ?? 0, ResumenGlobal::SUMABLES),
            $cuentas ?? $cifras['cuentasActivas'] ?? null,
            $encuestas ?? $cifras['encuestas'] ?? null,
            $ultima ? substr($ultima, 0, 10) : null,
        ]);
    }

    private function registrar(string $descarga): void
    {
        Auditoria::registrar('operador.descarga', [
            'operador_id' => Auth::guard('operador')->id(),
            'descarga' => $descarga,
        ]);
    }
}
