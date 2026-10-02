<?php

namespace App\Http\Middleware;

use App\Models\Operador;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Desactivar a un operador echa tambien a quien ya esta dentro, como
 * `CuentaActiva` con las cuentas de una institucion.
 */
class OperadorActivo
{
    public function handle(Request $request, Closure $next): Response
    {
        $operador = Auth::guard('operador')->user();

        if ($operador instanceof Operador && ! $operador->activo) {
            Auth::guard('operador')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('operador.entrar');
        }

        return $next($request);
    }
}
