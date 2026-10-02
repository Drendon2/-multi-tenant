<?php

namespace App\Http\Middleware;

use App\Support\InstitucionActual;
use App\Support\Panel;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Las pantallas del panel de todas las instituciones solo existen en su host
 * (paso 4b). En el de una institucion son un 404, igual que en una instalacion
 * de una sola casa, que no tiene panel.
 *
 * Y la peticion del panel no es de NINGUNA institucion: ni la por defecto ni
 * la de una cuenta. Lo que el panel lee no tiene RLS; una tabla de datos, desde
 * aqui, no devuelve nada.
 */
class SoloEnElPanel
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(Panel::esLaPeticion($request), 404);

        InstitucionActual::ninguna();

        return $next($request);
    }
}
