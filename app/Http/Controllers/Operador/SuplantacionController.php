<?php

namespace App\Http\Controllers\Operador;

use App\Http\Controllers\Controller;
use App\Models\Institucion;
use App\Models\Operador;
use App\Support\Panel;
use App\Support\Suplantacion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * El lado del panel de «entrar como»: deja el token y manda al navegador al
 * dominio de la institucion, que lo canjea (paso 4c). Ver
 * `App\Support\Suplantacion`.
 */
class SuplantacionController extends Controller
{
    public function emitir(Institucion $institucion, int $perfil): RedirectResponse
    {
        /** @var Operador $operador */
        $operador = Auth::guard('operador')->user();
        $url = Panel::urlDe($institucion, '/');

        $token = $url === null ? null : Suplantacion::emitir($operador, $institucion, $perfil);

        if ($token === null) {
            return redirect()->route('operador.institucion', $institucion)
                ->with('error', 'No se puede entrar como esa persona: ya no es administradora, está desactivada, o la institución no tiene dirección.');
        }

        return redirect()->away((string) Panel::urlDe($institucion, '/suplantacion/'.$token));
    }
}
