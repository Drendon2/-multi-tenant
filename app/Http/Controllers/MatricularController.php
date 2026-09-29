<?php

namespace App\Http\Controllers;

use App\Models\ConfiguracionInstitucion;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Support\SolicitudDeMatricula;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * El boton "Matricularme" del catalogo.
 *
 * Lo unico que decide aqui es la VENTANA de matriculas; el resto vive en
 * `SolicitudDeMatricula`, que comparte con el enlace de una promotoria.
 */
class MatricularController extends Controller
{
    public function __invoke(Request $request, Promotoria $promotoria): RedirectResponse
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');
        $periodo = Periodo::enCurso();

        if ($periodo === null) {
            return $this->volver('No hay un periodo de matrícula activo en este momento.');
        }

        if (! $periodo->matriculas_abiertas) {
            $institucion = ConfiguracionInstitucion::actual()->nombre_institucion;

            return $this->volver(
                "Las matrículas de {$periodo} están cerradas. Espera a que {$institucion} las abra de nuevo."
            );
        }

        [$exito, $mensaje] = SolicitudDeMatricula::pedir($perfil, $promotoria, $periodo);

        return $this->volver($mensaje, $exito);
    }

    private function volver(string $mensaje, bool $exito = false): RedirectResponse
    {
        return redirect()
            ->route('promotorias-disponibles')
            ->with($exito ? 'success' : 'error', $mensaje);
    }
}
