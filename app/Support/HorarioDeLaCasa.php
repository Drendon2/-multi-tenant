<?php

namespace App\Support;

use App\Models\Area;
use App\Models\Grupo;
use App\Models\Perfil;
use App\Models\Promotoria;
use App\Models\SesionGrupo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * El horario de la casa, para Gestion → Horarios: que se dicta cada dia, por
 * promotoria.
 *
 * Nace el 27/09/2026 a pedido del usuario, con la forma decidida por el: UN DIA
 * a la vez y un filtro por promotoria. La rejilla semanal de todas las
 * promotorias juntas se descarto de entrada —«no seria practico y se veria
 * demasiada informacion en una sola pantalla»—: con 195 grupos en produccion,
 * una semana entera son cientos de celdas.
 *
 * Devuelve la semana ENTERA y la pantalla esconde lo que no toca. No es un
 * descuido de rendimiento: el filtro cambia de dia sin pedir nada al servidor,
 * que es lo que el usuario pidio con «tabla dinamica». Son unas trescientas
 * filas cortas en el peor caso.
 *
 * Sale de TRES consultas fijas, sin hidratar modelos —la regla de las pantallas
 * que barren la institucion—: las sesiones con su grupo, promotoria, area y
 * profesor en una; las promotorias visibles para el desplegable en otra; los
 * grupos sin horario en la tercera.
 *
 * El director ve solo sus departamentos, por `Promotoria::queVe()`, que es la
 * misma puerta del resto de Gestion. Los CRUCES DE SALON, en cambio, se miran
 * contra toda la casa: el salon es fisico y el choque existe aunque el otro
 * grupo sea de un departamento ajeno. Lo que no se le dice es de QUIEN es ese
 * otro grupo; ver `cruces()`.
 */
class HorarioDeLaCasa
{
    /**
     * @return array{
     *     bloques: list<array{id: int, nombre: string, area: string, color: string, profesor: ?string, filas: list<array{dia: int, rango: string, grupo: string, salon: ?string, cruces: list<string>}>}>,
     *     promotorias: array<string, array<int, string>>,
     *     sinHorario: int,
     * }
     */
    public static function para(Perfil $perfil): array
    {
        $visibles = Promotoria::queVe($perfil)->pluck('id')->all();

        $sesiones = DB::table('sesiones_grupo as s')
            ->join('grupos as g', 'g.id', '=', 's.grupo_id')
            ->join('promotorias as p', 'p.id', '=', 'g.promotoria_id')
            ->join('areas as a', 'a.id', '=', 'p.area_id')
            ->leftJoin('perfiles as prof', 'prof.id', '=', 'p.profesor_id')
            ->select([
                's.id', 's.dia', 's.hora_inicio', 's.hora_fin',
                'g.nombre as grupo', 'g.nivel', 'g.salon',
                'p.id as promotoria_id', 'p.nombre as promotoria',
                'a.id as area_id', 'a.nombre as area',
                'prof.nombre_completo as profesor',
            ])
            ->orderBy('a.nombre')
            ->orderBy('p.nombre')
            ->orderBy('s.hora_inicio')
            ->orderBy('g.nombre')
            ->get();

        $cruces = self::cruces($sesiones, array_flip($visibles));

        $bloques = [];

        foreach ($sesiones as $s) {
            if (! in_array($s->promotoria_id, $visibles, true)) {
                continue;
            }

            $bloques[$s->promotoria_id] ??= [
                'id' => (int) $s->promotoria_id,
                'nombre' => $s->promotoria,
                'area' => $s->area,
                // Pregunta a `Area::tag_color` con el area rellenada en memoria.
                'color' => (new Area)->forceFill(['id' => $s->area_id])->tag_color,
                'profesor' => $s->profesor,
                'filas' => [],
            ];

            $bloques[$s->promotoria_id]['filas'][] = [
                'dia' => (int) $s->dia,
                'rango' => SesionGrupo::rangoCorto($s->hora_inicio, $s->hora_fin),
                'grupo' => self::nombreDelGrupo($s->grupo, $s->nivel),
                'salon' => self::limpio($s->salon),
                'cruces' => $cruces[$s->id] ?? [],
            ];
        }

        $promotorias = [];
        foreach (Promotoria::queVe($perfil)
            ->join('areas', 'areas.id', '=', 'promotorias.area_id')
            ->orderBy('areas.nombre')->orderBy('promotorias.nombre')
            ->get(['promotorias.id', 'promotorias.nombre', 'areas.nombre as area']) as $p) {
            $promotorias[$p->area][$p->id] = $p->nombre;
        }

        // Los grupos que no salen en ningun dia. Sin este aviso, un grupo al
        // que nadie le puso horario desaparece de la pantalla sin dejar rastro,
        // y la vista pareceria completa.
        $sinHorario = DB::table('grupos')
            ->whereIn('promotoria_id', $visibles)
            ->whereNotExists(fn ($q) => $q->from('sesiones_grupo')->whereColumn('sesiones_grupo.grupo_id', 'grupos.id'))
            ->count();

        return [
            'bloques' => array_values($bloques),
            'promotorias' => $promotorias,
            'sinHorario' => $sinHorario,
        ];
    }

    /**
     * Dos sesiones chocan si caen el mismo dia, sus horas se pisan y el salon es
     * el mismo. Devuelve, por id de sesion, la frase que se pinta en su fila.
     *
     * El salon se compara sin mayusculas ni espacios de sobra: es texto libre, y
     * «Salon 2» y «salón 2 » tecleados por dos personas son el mismo cuarto. Un
     * salon VACIO no choca con nada: no saber donde es no es saber que es en el
     * mismo sitio.
     *
     * Horas que se TOCAN no se pisan: la clase de 4 a 6 y la de 6 a 8 en el mismo
     * salon es la programacion normal de una casa que trabaja por bloques.
     *
     * Al director se le nombra el otro grupo solo si es de sus departamentos. Si
     * no, se le dice que hay un cruce y a que hora, que es lo que necesita para
     * ir a preguntar, sin enseñarle el catalogo de otro departamento.
     *
     * @param  Collection<int, stdClass>  $sesiones
     * @param  array<int, int>  $visibles  ids de promotoria visibles, como claves
     * @return array<int, list<string>>
     */
    private static function cruces(Collection $sesiones, array $visibles): array
    {
        $porSalon = [];

        foreach ($sesiones as $s) {
            $salon = self::limpio($s->salon);
            if ($salon === null) {
                continue;
            }
            $porSalon[$s->dia.'|'.mb_strtolower($salon)][] = $s;
        }

        $cruces = [];

        foreach ($porSalon as $mismas) {
            $n = count($mismas);
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $a = $mismas[$i];
                    $b = $mismas[$j];

                    if ($a->hora_inicio < $b->hora_fin && $b->hora_inicio < $a->hora_fin) {
                        $cruces[$a->id][] = self::frase($b, $visibles);
                        $cruces[$b->id][] = self::frase($a, $visibles);
                    }
                }
            }
        }

        return $cruces;
    }

    /** @param  array<int, int>  $visibles */
    private static function frase(stdClass $otra, array $visibles): string
    {
        $cuando = SesionGrupo::rangoCorto($otra->hora_inicio, $otra->hora_fin);

        if (isset($visibles[$otra->promotoria_id])) {
            return "{$otra->promotoria} · ".self::nombreDelGrupo($otra->grupo, $otra->nivel).", de {$cuando}";
        }

        return "otro grupo, de {$cuando}";
    }

    /**
     * El nombre del grupo con su nivel. Pregunta a `Grupo::nombre_con_nivel` en
     * vez de copiar la regla —que no repite «Básico · Básico»—: el modelo se
     * rellena en memoria y no toca la base.
     */
    private static function nombreDelGrupo(string $nombre, ?string $nivel): string
    {
        return (new Grupo)->forceFill(['nombre' => $nombre, 'nivel' => $nivel])->nombre_con_nivel;
    }

    /** Recorta espacios, incluidos los duros que pega un copiado de WhatsApp. */
    private static function limpio(?string $texto): ?string
    {
        $texto = trim(str_replace("\u{00A0}", ' ', (string) $texto));

        return $texto === '' ? null : $texto;
    }
}
