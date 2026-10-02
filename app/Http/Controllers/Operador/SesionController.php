<?php

namespace App\Http\Controllers\Operador;

use App\Http\Controllers\Controller;
use App\Models\Operador;
use App\Support\Auditoria;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

/**
 * Entrar y salir del panel de todas las instituciones (paso 4b).
 *
 * Es el `LoginController` de las instituciones con otro guard y otra tabla, y
 * con las mismas dos decisiones: una cuenta desactivada recibe el mismo
 * mensaje que una clave mala, y un hash ilegible es un login fallido que queda
 * en el registro, no un 500.
 */
class SesionController extends Controller
{
    public function mostrar(): View|RedirectResponse
    {
        if (Auth::guard('operador')->check()) {
            return redirect()->route('operador.instituciones');
        }

        return view('operador.entrar');
    }

    public function entrar(Request $request): RedirectResponse
    {
        $credenciales = $request->validate([
            'usuario' => ['required', 'string'],
            'password' => ['required', 'string'],
        ], [], [
            'usuario' => 'usuario',
            'password' => 'contraseña',
        ]);

        try {
            $valido = Auth::guard('operador')->attempt([...$credenciales, 'activo' => true]);
        } catch (RuntimeException $e) {
            Log::warning('Un operador tiene la contraseña guardada en un formato que no se puede comprobar', [
                'usuario' => $credenciales['usuario'],
                'motivo' => $e->getMessage(),
            ]);
            $valido = false;
        }

        if (! $valido) {
            throw ValidationException::withMessages([
                'usuario' => 'Usuario o contraseña incorrectos.',
            ]);
        }

        $request->session()->regenerate();

        /** @var Operador $operador */
        $operador = Auth::guard('operador')->user();
        $operador->forceFill(['ultimo_acceso' => now()])->save();
        Auditoria::registrar('operador.entrada', ['operador_id' => $operador->id]);

        return redirect()->intended(route('operador.instituciones'));
    }

    public function salir(Request $request): RedirectResponse
    {
        Auth::guard('operador')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('operador.entrar');
    }
}
