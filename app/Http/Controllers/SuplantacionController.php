<?php

namespace App\Http\Controllers;

use App\Support\Panel;
use App\Support\Suplantacion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * El lado de la institucion de «entrar como» (paso 4c): canjea el token que
 * dejo el panel y, al salir, devuelve al panel. Ver `App\Support\Suplantacion`.
 */
class SuplantacionController extends Controller
{
    public function entrar(Request $request, string $token): RedirectResponse
    {
        // Quien tuviera sesion en este navegador y en esta institucion la
        // pierde: el operador entra en limpio, sin heredar nada de otro.
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if (! Suplantacion::canjear($token)) {
            return redirect()->route('login')->with(
                'error',
                'Ese enlace para entrar desde el panel ya se usó o caducó. Vuelve a pedirlo desde el panel.'
            );
        }

        $request->session()->regenerate();

        return redirect()->route('post-login');
    }

    public function salir(Request $request): RedirectResponse
    {
        Suplantacion::terminar();
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->away(Panel::urlDelPanel('/instituciones'));
    }
}
