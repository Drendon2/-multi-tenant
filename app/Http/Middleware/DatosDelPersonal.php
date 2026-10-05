<?php

namespace App\Http\Middleware;

use App\Support\GestionAsistida;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Al profesor o director sin documento o sin correo no se le deja seguir
 * hasta que los escriba (05/10/2026, decision del usuario).
 *
 * Es la excepcion a «pide y no obliga», y es a proposito: los profesores de
 * produccion se registraron cuando el formulario no los pedia, y nada le avisa
 * a nadie de nada, asi que un aviso que se puede cerrar no los traeria nunca.
 *
 * Es una PAGINA y no un dialogo pintado con JavaScript: este proyecto sostiene
 * que sus pantallas funcionan sin guion, y una barrera que vive en el
 * navegador se salta quitandolo. Cualquier peticion —tambien la de
 * `acciones.js`, que sigue la redireccion— acaba en ella.
 *
 * Va en el grupo `web` y no en `RequiereRol` por lo mismo que `CuentaActiva`:
 * /mi-perfil y /post-login no llevan rol, y por ahi se colaria.
 *
 * Lo que NO pasa por aqui:
 * - La propia pantalla y salir: sin ellas la barrera encierra.
 * - La gestion asistida: el administrador no sabe el documento de nadie, y
 *   para eso tiene Gestion → Usuarios.
 */
class DatosDelPersonal
{
    /** Las rutas que siguen abiertas con los datos sin llenar. */
    private const LIBRES = ['completar-datos', 'completar-datos.guardar', 'logout', 'logo-institucion'];

    public function handle(Request $request, Closure $next): Response
    {
        $perfil = $request->user()?->perfil;

        if ($perfil === null
            || ! $perfil->faltanDocumentoOCorreo()
            || $request->routeIs(...self::LIBRES)
            || GestionAsistida::activa()) {
            return $next($request);
        }

        return redirect()->route('completar-datos');
    }
}
