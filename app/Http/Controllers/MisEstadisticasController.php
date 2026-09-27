<?php

namespace App\Http\Controllers;

use App\Models\Periodo;
use App\Support\EstadisticasDeProfesor;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * «Estadísticas» del profesor, desde su menu (27/09/2026, pedido del usuario):
 * como van SUS promotorias en un periodo. Las definiciones de cada cifra estan
 * en `EstadisticasDeProfesor`.
 *
 * El periodo va en el camino, como en Estadisticas del administrador: la
 * pantalla ENTERA es de ese periodo. Abre en el que esta en curso y las
 * flechas llevan a los que tienen algo suyo.
 */
class MisEstadisticasController extends Controller
{
    public function __invoke(Request $request, ?Periodo $periodo = null): View
    {
        $perfil = $request->attributes->get('perfil');
        $periodos = collect(EstadisticasDeProfesor::periodos($perfil));

        // Un periodo sin nada suyo, escrito a mano en la URL, cae en el que
        // toca en vez de ensenar una pantalla de ceros que se leeria como «no
        // hice nada».
        if ($periodo !== null && ! $periodos->contains('id', $periodo->id)) {
            $periodo = null;
        }

        $enCurso = Periodo::enCurso();
        $periodo ??= $periodos->firstWhere('id', $enCurso?->id) ?? $periodos->first();

        $indice = $periodo === null ? false : $periodos->search(fn (Periodo $p) => $p->id === $periodo->id);

        return view('panel.estadisticas', [
            'periodo' => $periodo,
            'enCurso' => $periodo !== null && $periodo->id === $enCurso?->id,
            'haciaAtras' => $indice === false ? null : $periodos->get($indice + 1),
            'haciaAdelante' => $indice === false || $indice === 0 ? null : $periodos->get($indice - 1),
            'datos' => $periodo === null ? null : EstadisticasDeProfesor::de($perfil, $periodo),
        ]);
    }
}
