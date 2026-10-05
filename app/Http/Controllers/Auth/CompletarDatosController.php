<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\Reglas;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * La pantalla que pide documento y correo al personal que no los tiene.
 *
 * A ella manda `App\Http\Middleware\DatosDelPersonal`. Pide los DOS campos
 * aunque falte uno solo, con lo que ya haya escrito: asi la persona revisa el
 * que tenia, que pudo quedar mal, y la pantalla es siempre la misma.
 */
class CompletarDatosController extends Controller
{
    public function mostrar(Request $request): View|RedirectResponse
    {
        $perfil = $request->user()->perfil;

        // Quien ya los tiene, o cuyo rol no los pide, no tiene nada que hacer
        // aqui: se le manda a donde le toca.
        if ($perfil === null || ! $perfil->faltanDocumentoOCorreo()) {
            return redirect()->route('post-login');
        }

        return view('auth.completar-datos', ['perfil' => $perfil]);
    }

    public function guardar(Request $request): RedirectResponse
    {
        $perfil = $request->user()->perfil;

        if ($perfil === null || ! $perfil->debeTenerDocumentoYCorreo()) {
            return redirect()->route('post-login');
        }

        $datos = $request->validate([
            'documento_identidad' => Reglas::documentoDelPersonal($perfil->id),
            'correo' => Reglas::correo(obligatorio: true),
        ], Reglas::mensajes() + [
            'documento_identidad.unique' => 'Ya hay otra cuenta registrada con ese documento.',
        ], [
            'documento_identidad' => 'documento de identidad',
            'correo' => 'correo electrónico',
        ]);

        DB::transaction(function () use ($perfil, $datos) {
            $perfil->documento_identidad = $datos['documento_identidad'];
            $perfil->save();

            $perfil->user->email = $datos['correo'];
            $perfil->user->save();
        });

        return redirect()->route('post-login')->with('success', 'Gracias. Tus datos quedaron guardados.');
    }
}
