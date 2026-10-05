<?php

namespace App\Http\Controllers\Gestion;

use App\Http\Controllers\Controller;
use App\Models\SesionGrupo;
use App\Support\HorarioDeLaCasa;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * GESTION → HORARIOS: que se dicta cada dia, promotoria por promotoria.
 *
 * Administrador y director (27/09/2026, decision del usuario); el director
 * acotado a sus departamentos. El porque de la forma —un dia a la vez, nunca la
 * semana entera de toda la casa— esta en `HorarioDeLaCasa`.
 *
 * `?dia=` y `?promotoria=` son la version SIN JavaScript del filtro y la URL
 * que se puede guardar o mandar. Con JavaScript el filtro no navega:
 * `horarios.js` esconde filas y reescribe la URL, que vuelve aqui igual.
 */
class HorariosController extends Controller
{
    public function __invoke(Request $request): View
    {
        $horario = HorarioDeLaCasa::para($request->attributes->get('perfil'));

        // Arranca en HOY, tambien el domingo desde que hay clases ese dia. Un
        // dia que no existe, escrito a mano en la URL, cae en hoy.
        $hoy = now()->dayOfWeekIso;
        $dia = (int) $request->query('dia', $hoy);
        if (! isset(SesionGrupo::DIAS[$dia])) {
            $dia = $hoy;
        }

        // Solo una promotoria que esta persona puede ver. Un id ajeno escrito a
        // mano en la URL se ignora en vez de contestar con un error: el filtro
        // ya no ensena nada que el recorte no deje ver.
        $promotoria = (int) $request->query('promotoria', 0);
        $visibles = array_replace([], ...array_values($horario['promotorias']));
        if (! isset($visibles[$promotoria])) {
            $promotoria = 0;
        }

        return view('gestion.horarios', $horario + [
            'dia' => $dia,
            'hoy' => $hoy,
            'promotoria' => $promotoria,
            'nombrePromotoria' => $visibles[$promotoria] ?? null,
            'cuentas' => self::cuentas($horario['bloques'], $promotoria),
        ]);
    }

    /**
     * Cuantas clases hay cada dia con el filtro puesto: es la cifra de cada
     * pestana. Con ella se ve sin probar uno por uno que dias tiene clase una
     * promotoria.
     *
     * @param  list<array{id: int, filas: list<array{dia: int}>}>  $bloques
     * @return array<int, int>
     */
    public static function cuentas(array $bloques, int $promotoria): array
    {
        $cuentas = array_fill_keys(array_keys(SesionGrupo::DIAS), 0);

        foreach ($bloques as $bloque) {
            if ($promotoria && $bloque['id'] !== $promotoria) {
                continue;
            }
            foreach ($bloque['filas'] as $fila) {
                $cuentas[$fila['dia']]++;
            }
        }

        return $cuentas;
    }
}
