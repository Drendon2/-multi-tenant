<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * A donde va cada quien justo despues de iniciar sesion, y la pantalla de la
 * cuenta que todavia no tiene rol.
 */
class PostLoginController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $perfil = $request->user()->perfil;

        if ($perfil === null) {
            return redirect()->route('login')->with(
                'error',
                'Tu cuenta no tiene un perfil asociado. Contacta al administrador.'
            );
        }

        // Cuenta recien autorregistrada: existe, pero no tiene acceso a nada
        // hasta que un director o administrador le asigne rol.
        if ($perfil->rol === '') {
            return redirect()->route('pendiente-aprobacion');
        }

        if ($perfil->rol === 'estudiante') {
            return redirect()->route('promotorias-disponibles');
        }

        // LA INSTITUCION EXTERNA ATERRIZA EN LO UNICO QUE TIENE. Va ANTES del
        // rebote final a `panel`, y ese orden es lo que importa: esta cuenta no
        // es personal de la casa, asi que `rol:administrador,director,profesor`
        // la rechaza y el rebote la devolveria aqui — un bucle entre esta
        // pantalla y el Panel, o en el mejor caso un aviso de «no tienes acceso»
        // nada mas entrar. Ver tambien la barra del menu, que por la misma
        // razon no le puede pintar el botón de Panel.
        if ($perfil->rol === 'institucion_externa') {
            return redirect()->route('externa-clases');
        }

        // EL ADMINISTRADOR ATERRIZA EN GESTION desde el 04/09/2026, a peticion
        // del usuario. Su trabajo no es el Panel —confirmar matriculas y pasar
        // lista son tareas de quien dicta— sino el catalogo, los periodos, los
        // usuarios y las alertas, y todo eso vive en Gestion. El Panel sigue en
        // el menu y a un clic.
        //
        // El director NO: el suyo si es el Panel. Ve todas las promotorias y
        // resuelve solicitudes a diario, que es justo lo que esa pantalla hace.
        if ($perfil->rol === 'administrador') {
            return redirect()->route('gestion-inicio');
        }

        return redirect()->route('panel');
    }

    public function pendienteAprobacion(Request $request): View|RedirectResponse
    {
        $perfil = $request->user()->perfil;

        // Si ya le asignaron rol mientras esperaba, esta pantalla deja de tener
        // sentido y se le manda a donde le corresponde.
        if ($perfil !== null && $perfil->rol !== '') {
            return redirect()->route('post-login');
        }

        return view('auth.pendiente-aprobacion');
    }
}
