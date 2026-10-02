<?php

namespace App\Http\Middleware;

use App\Models\Institucion;
use App\Support\Suplantacion;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Una institucion suspendida desde el panel no atiende: todas sus pantallas
 * dicen «Servicio suspendido» (503), tambien a quien ya habia entrado. No se
 * borra nada; reactivarla la devuelve tal cual (decision del usuario,
 * 02/10/2026).
 *
 * La excepcion es el SOPORTE: quien entra desde el panel (`Suplantacion`) la
 * ve entera, para poder mirar que le pasa antes de reactivarla. Por eso esto va
 * despues de la sesion y no dentro de `InstitucionPorDominio`, que corre antes.
 */
class InstitucionSuspendida
{
    /**
     * Lo que sigue abierto: el logo y los iconos, que pide la propia pantalla
     * de «suspendido» (sin ellos saldria con la imagen rota), y las dos puertas
     * de la suplantacion.
     */
    private const ABIERTAS = ['logo-institucion', 'icono-institucion', 'manifiesto',
        'suplantacion.entrar', 'suplantacion.salir'];

    public function handle(Request $request, Closure $next): Response
    {
        $institucion = $request->attributes->get(Institucion::class);

        if ($institucion instanceof Institucion
            && $institucion->estado === Institucion::SUSPENDIDA
            && ! $request->routeIs(self::ABIERTAS)
            && ! Suplantacion::activa()) {
            return response()->view('publico.suspendida', [], 503);
        }

        return $next($request);
    }
}
