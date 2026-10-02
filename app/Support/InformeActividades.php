<?php

namespace App\Support;

use App\Models\Actividad;
use App\Models\AsistenciaActividad;
use App\Models\InscritoActividad;
use Generator;

/**
 * El informe de la gente SIN matricula: cursos, talleres, grupos de proyeccion
 * y programas externos. Una fila por persona y actividad, con TODO el
 * historico.
 *
 * Vivia dentro de `InformeController::actividades()` y salio aqui en el paso 5
 * (02/10/2026), porque desde entonces lo arman la descarga de cada institucion
 * y la consolidada del panel. Por que es como es esta en
 * `InformeController::actividades()`.
 *
 * Se construye y se recorre DENTRO de la institucion que se informa.
 * Consultas fijas: dos agregados y la lista recorrida con `lazy()`.
 */
final class InformeActividades
{
    /** Cuantas filas se traen por tanda (ver `InformeController::POR_TANDA`). */
    private const POR_TANDA = 100;

    private const ORIGEN = [
        InscritoActividad::ENLACE => 'Por el enlace',
        InscritoActividad::EN_SESION => 'Añadido en clase',
        InscritoActividad::LISTA => 'Lista del programa',
    ];

    /** @return list<string> */
    public static function cabecera(): array
    {
        return [
            'Tipo',
            'Actividad',
            'Responsable',
            'Institución',
            'Teléfono de la institución',
            'Funcionario de la institución',
            'Periodo',
            'Nombre completo',
            'Cómo entró',
            'Documento',
            'Teléfono',
            'Correo',
            'Fecha de nacimiento',
            'Edad declarada',
            'Registrado el',
            'Reconocido como estudiante',
            'Sesiones con lista',
            'Asistió',
            'Asistencia',
        ];
    }

    /** @return Generator<int, list<string|int|null>> */
    public static function filas(): Generator
    {
        $sesionesConLista = InstitucionActual::tabla('asistencias_actividad as x')
            ->join('sesiones_actividad as s', 's.id', '=', 'x.sesion_id')
            ->groupBy('s.actividad_id')
            ->selectRaw('s.actividad_id, COUNT(DISTINCT s.id) as total')
            ->pluck('total', 'actividad_id');

        $asistio = InstitucionActual::tabla('asistencias_actividad')
            ->where('estado', AsistenciaActividad::ASISTIO)
            ->groupBy('inscrito_id')
            ->selectRaw('inscrito_id, COUNT(*) as total')
            ->pluck('total', 'inscrito_id');

        $filas = InstitucionActual::tabla('inscritos_actividad as i')
            ->join('actividades as a', 'a.id', '=', 'i.actividad_id')
            ->leftJoin('perfiles as r', 'r.id', '=', 'a.responsable_id')
            ->leftJoin('instituciones_externas as e', 'e.id', '=', 'a.institucion_externa_id')
            ->leftJoin('perfiles as fu', 'fu.id', '=', 'e.perfil_id')
            ->leftJoin('periodos as p', 'p.id', '=', 'a.periodo_id')
            ->select([
                'i.id', 'i.actividad_id', 'i.nombre_completo', 'i.documento', 'i.telefono',
                'i.correo', 'i.fecha_nacimiento', 'i.edad', 'i.perfil_id', 'i.origen', 'i.created_at',
                'a.tipo', 'a.nombre as actividad', 'r.nombre_completo as responsable',
                'e.nombre as institucion', 'e.telefono as telefono_institucion', 'fu.nombre_completo as funcionario', 'p.nombre as periodo',
            ])
            ->orderBy('a.tipo')
            ->orderBy('a.nombre')
            ->orderBy('i.nombre_completo')
            ->orderBy('i.id');

        foreach ($filas->lazy(self::POR_TANDA) as $f) {
            $sesiones = (int) ($sesionesConLista[$f->actividad_id] ?? 0);
            $fue = (int) ($asistio[$f->id] ?? 0);

            yield [
                Actividad::ETIQUETA_TIPO[$f->tipo] ?? $f->tipo,
                $f->actividad,
                $f->responsable,
                $f->institucion,
                // El de la ENTIDAD, no el del funcionario: es el contacto que
                // sigue sirviendo cuando esa persona cambia de trabajo.
                $f->telefono_institucion,
                // Quien da fe de las clases desde la cuenta de la institucion.
                $f->funcionario,
                $f->periodo ?? 'Sin periodo',
                $f->nombre_completo,
                self::ORIGEN[$f->origen] ?? $f->origen,
                $f->documento,
                $f->telefono,
                $f->correo,
                $f->fecha_nacimiento ? substr((string) $f->fecha_nacimiento, 0, 10) : null,
                $f->edad,
                substr((string) $f->created_at, 0, 10),
                // Solo se reconoce por el documento: quien no lo dio sale
                // «No» aunque este matriculado. Por eso la cabecera dice
                // «reconocido» y no «matriculado».
                $f->perfil_id ? 'Sí' : 'No',
                $sesiones,
                $fue,
                $sesiones > 0 ? ((int) floor($fue * 100 / $sesiones)).'%' : null,
            ];
        }
    }
}
