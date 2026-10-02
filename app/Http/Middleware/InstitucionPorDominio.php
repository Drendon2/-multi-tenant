<?php

namespace App\Http\Middleware;

use App\Models\Institucion;
use App\Support\InstitucionActual;
use App\Support\Panel;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * De que institucion es la peticion: la del HOST (paso 4a, 02/10/2026).
 *
 * Va el PRIMERO del grupo `web`, antes de la sesion y de cualquier consulta:
 * con `users` bajo RLS, cargar la cuenta de la sesion ya necesita saber la
 * institucion. Una sesion de otra institucion no encuentra su cuenta (RLS la
 * esconde) y la peticion sigue como la de alguien sin sesion; las cookies,
 * ademas, son de cada host (`SESSION_DOMAIN` vacio).
 *
 * Un host que no es de nadie da 404, sin caer en la institucion por defecto.
 * Lo que pasa con una institucion SUSPENDIDA lo decide `InstitucionSuspendida`,
 * que corre despues de la sesion porque necesita saber si quien llega viene
 * del panel (paso 4c).
 */
class InstitucionPorDominio
{
    public function handle(Request $request, Closure $next): Response
    {
        // El host del panel no es de ninguna institucion: lo que se pida ahi
        // fuera del panel (`/`, `/entrar`) lleva a su portada (paso 4b).
        if (Panel::esLaPeticion($request)) {
            return redirect()->route('operador.instituciones');
        }

        $institucion = InstitucionActual::delHost($request->getHost());

        abort_if($institucion === null, 404);

        InstitucionActual::usar($institucion->id);
        $request->attributes->set(Institucion::class, $institucion);

        return $next($request);
    }
}
