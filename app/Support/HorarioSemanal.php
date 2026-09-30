<?php

namespace App\Support;

use App\Models\Grupo;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Models\SesionGrupo;
use Illuminate\Support\Collection;

/**
 * La rejilla semanal de una persona: donde tiene que estar cada dia.
 *
 * Sirve a los dos roles con la misma forma de salida, igual que hace
 * `ResumenAsistencia`, para que una sola plantilla los pinte sin preguntar de
 * quien es el horario:
 *
 * - Al ESTUDIANTE, los grupos en los que esta repartido y sigue inscrito.
 * - A quien DICTA, los grupos de las promotorias que tiene a su cargo.
 *
 * Solo del periodo EN CURSO. Un horario dice donde estar esta semana; el
 * historial de semestres pasados es otra cosa y ya tiene su pantalla.
 *
 * Las filas de la rejilla NO son horas fijas de reloj: son las franjas que de
 * verdad se usan. Una casa que solo da clase a las 4 y a las 6 no tiene por que
 * ensenar catorce filas vacias desde las siete de la manana.
 */
class HorarioSemanal
{
    /**
     * El horario de esta persona, o null si no hay nada que pintar.
     *
     * @return array{franjas: list<array{inicio: string, fin: string, etiqueta: string, celdas: array<int, list<array{titulo: string, detalle: string, color: string}>>}>, dias: array<int, string>}|null
     */
    public static function de(Perfil $perfil, ?Periodo $periodo): ?array
    {
        if ($periodo === null) {
            return null;
        }

        $grupos = $perfil->rol === 'estudiante'
            ? self::gruposDelEstudiante($perfil, $periodo)
            : self::gruposDeQuienDicta($perfil);

        if ($grupos->isEmpty()) {
            return null;
        }

        // Una entrada por SESION, no por grupo: el grupo que se reune martes y
        // jueves ocupa dos sitios en la semana.
        $ocupaciones = [];

        foreach ($grupos as $grupo) {
            foreach ($grupo->sesiones as $sesion) {
                $ocupaciones[] = ['sesion' => $sesion, 'grupo' => $grupo];
            }
        }

        if ($ocupaciones === []) {
            return null;
        }

        return [
            'dias' => SesionGrupo::DIAS_CORTOS,
            'franjas' => self::enFranjas($ocupaciones),
        ];
    }

    /**
     * Los grupos donde el estudiante esta repartido y sigue inscrito.
     *
     * `ESTADOS_INSCRITO` y no solo 'activa': quien pidio cancelar sigue yendo a
     * clase mientras direccion resuelve, y su horario no ha cambiado todavia.
     *
     * @return Collection<int, Grupo>
     */
    private static function gruposDelEstudiante(Perfil $perfil, Periodo $periodo)
    {
        // Por `asignaciones_grupo` desde el 10/09/2026, no por la columna. Una
        // matricula puede estar repartida en dos grupos de la misma promotoria
        // —el lunes y el miercoles— y con la columna el segundo no salia en el
        // horario de nadie: la persona veia media semana.
        //
        // Esta rejilla ya sabia pintar VARIOS grupos, porque quien cursa tres
        // promotorias tiene tres; lo unico que cambia es de donde sale la lista.
        $ids = InstitucionActual::tabla('asignaciones_grupo')
            ->join('matriculas', 'matriculas.id', '=', 'asignaciones_grupo.matricula_id')
            ->where('matriculas.estudiante_id', $perfil->id)
            ->where('matriculas.periodo_id', $periodo->id)
            ->whereIn('matriculas.estado', Matricula::ESTADOS_INSCRITO)
            ->pluck('asignaciones_grupo.grupo_id');

        return Grupo::with(['sesiones', 'promotoria.area'])->whereIn('id', $ids)->get();
    }

    /**
     * Los grupos de las promotorias que dicta.
     *
     * Sale del VINCULO y no del rol: un director que ademas dicta tiene su
     * horario como cualquiera, y quien no dicta nada no tiene ninguno.
     *
     * @return Collection<int, Grupo>
     */
    private static function gruposDeQuienDicta(Perfil $perfil)
    {
        return Grupo::with(['sesiones', 'promotoria.area'])
            ->whereIn('promotoria_id', Promotoria::where('profesor_id', $perfil->id)->pluck('id'))
            ->get();
    }

    /**
     * Agrupa las ocupaciones en filas por franja horaria.
     *
     * Dos grupos comparten fila cuando empiezan y terminan a la misma hora, que
     * es el caso corriente —la casa programa por bloques—. Uno que empiece a
     * las 4:30 se lleva su propia fila en vez de deformar la de las 4:00.
     *
     * @param  list<array{sesion: SesionGrupo, grupo: Grupo}>  $ocupaciones
     */
    private static function enFranjas(array $ocupaciones): array
    {
        $franjas = [];

        foreach ($ocupaciones as $ocupacion) {
            $sesion = $ocupacion['sesion'];
            $grupo = $ocupacion['grupo'];
            $clave = $sesion->hora_inicio.'|'.$sesion->hora_fin;

            if (! isset($franjas[$clave])) {
                $franjas[$clave] = [
                    'inicio' => $sesion->hora_inicio,
                    'fin' => $sesion->hora_fin,
                    'etiqueta' => SesionGrupo::rangoCorto($sesion->hora_inicio, $sesion->hora_fin),
                    'celdas' => array_fill_keys(array_keys(SesionGrupo::DIAS), []),
                ];
            }

            // Una lista y no un valor suelto: dos grupos pueden caer en el mismo
            // dia y la misma hora. Para quien dicta eso seria un problema suyo
            // que conviene VER, no esconder pisando una de las dos.
            $franjas[$clave]['celdas'][$sesion->dia][] = [
                'titulo' => $grupo->promotoria->nombre,
                'detalle' => $grupo->nombre_con_nivel.($grupo->salon ? ' · '.$grupo->salon : ''),
                'color' => $grupo->promotoria->area->tag_color,
            ];
        }

        // Por hora de inicio, que es como se lee un horario: de la manana a la
        // noche.
        ksort($franjas);

        return array_values($franjas);
    }
}
