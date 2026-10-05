<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Perfil;
use App\Models\User;
use App\Support\Reglas;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Autorregistro publico, pensado para profesores nuevos.
 *
 * La cuenta queda SIN rol: un director o administrador se lo asigna despues
 * desde Gestion → Usuarios. Hasta entonces la persona puede entrar, pero solo
 * ve la pantalla de "cuenta pendiente".
 *
 * Pide documento y correo, los dos OBLIGATORIOS (05/10/2026, decision del
 * usuario): son los datos con los que la institucion identifica y contacta a
 * quien dicta. El correo aqui no mira el interruptor de la institucion
 * (`correo_obligatorio`), que existe por los estudiantes menores sin correo
 * propio; un profesor lo tiene.
 *
 * NO pide foto de perfil, y no es un olvido: por seguridad, los archivos no se
 * suben desde un formulario publico sin autenticar. La persona la sube despues,
 * ya con sesion, en "Mi perfil".
 */
class RegistroController extends Controller
{
    public function mostrar(): View
    {
        return view('auth.registro');
    }

    public function guardar(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'username' => Reglas::usuario(Rule::unique('users', 'username')),
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'nombre_completo' => Reglas::nombreDePersona(90),
            'fecha_nacimiento' => ['required', 'date', 'before:today'],
            'telefono' => Reglas::celular(),
            'documento_identidad' => Reglas::documentoDelPersonal(),
            'correo' => Reglas::correo(obligatorio: true),
        ], Reglas::mensajes() + [
            'username.unique' => 'Ya existe una cuenta con ese nombre de usuario.',
            'documento_identidad.unique' => 'Ya hay una cuenta registrada con ese documento.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
        ], [
            'username' => 'usuario',
            'password' => 'contraseña',
            'nombre_completo' => 'nombre completo',
            'fecha_nacimiento' => 'fecha de nacimiento',
            'telefono' => 'teléfono',
            'documento_identidad' => 'documento de identidad',
            'correo' => 'correo electrónico',
        ]);

        // La cuenta y el perfil nacen juntos o no nacen: una cuenta sin perfil
        // no puede ni pasar por la redireccion posterior al login.
        DB::transaction(function () use ($datos) {
            $user = User::create([
                'username' => $datos['username'],
                'password' => $datos['password'],
                'email' => $datos['correo'],
                'activo' => true,
            ]);

            Perfil::create([
                'user_id' => $user->id,
                'rol' => '',
                'nombre_completo' => $datos['nombre_completo'],
                'fecha_nacimiento' => $datos['fecha_nacimiento'],
                'telefono' => $datos['telefono'],
                'documento_identidad' => $datos['documento_identidad'],
            ]);
        });

        return redirect()->route('login')->with(
            'success',
            'Tu cuenta quedó creada. Un director o administrador debe asignarte un rol antes '
            .'de que puedas entrar al sistema. Cuando entres, ve a "Mi perfil" para subir tu foto.'
        );
    }
}
