<?php

namespace App\Http\Middleware;

use App\Models\Institucion;
use App\Support\InstitucionActual;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\ViewErrorBag;
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
 * - Un host que no es de nadie: 404, sin caer en la institucion por defecto.
 * - Una institucion suspendida: «servicio suspendido» en todas sus pantallas.
 *   No se borra nada; reactivarla la devuelve tal cual.
 */
class InstitucionPorDominio
{
    /**
     * Lo que la propia pantalla de «suspendido» pide: el logo y los iconos de
     * la marca. Sin esto saldria con la imagen rota.
     */
    private const ABIERTAS_SI_SUSPENDIDA = ['logo-institucion', 'icono-institucion', 'manifiesto'];

    public function handle(Request $request, Closure $next): Response
    {
        $institucion = InstitucionActual::delHost($request->getHost());

        abort_if($institucion === null, 404);

        InstitucionActual::usar($institucion->id);

        if ($institucion->estado === Institucion::SUSPENDIDA && ! $request->routeIs(self::ABIERTAS_SI_SUSPENDIDA)) {
            // Antes de la sesion no hay bolsa de errores compartida, y el
            // envoltorio publico pinta los mensajes.
            return response()->view('publico.suspendida', ['errors' => new ViewErrorBag], 503);
        }

        return $next($request);
    }
}
