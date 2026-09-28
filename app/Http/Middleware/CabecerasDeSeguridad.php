<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Las cabeceras de seguridad de cada respuesta de la aplicacion.
 *
 * Nacen de la revision de seguridad del 27/09/2026, pedida por el usuario: el
 * sitio no mandaba NINGUNA de las habituales y anunciaba la version exacta de
 * PHP. Van en un middleware y no en `.htaccess` porque en produccion LiteSpeed
 * y el CDN reescriben cabeceras a su manera, y aqui se pueden probar.
 *
 * Solo cubren lo que pasa por Laravel. Los CSS, JS e imagenes los sirve el
 * servidor directamente; eso no lleva datos de nadie.
 *
 * Lo que NO se pone, a proposito: una `Content-Security-Policy` completa. La de
 * produccion (`upgrade-insecure-requests`) la pone Hostinger, y una politica
 * estricta pide revisar cada `style=` y cada `<script>` en linea de sesenta
 * pantallas —una tarea en si misma, no un anadido de esta—. El marco
 * (`frame-ancestors`) si va, por `X-Frame-Options`.
 */
class CabecerasDeSeguridad
{
    public function handle(Request $request, Closure $next): Response
    {
        $respuesta = $next($request);
        $cabeceras = $respuesta->headers;

        // Nadie puede meter estas pantallas dentro de un <iframe> de otro
        // sitio. Sin esto, una pagina ajena podia enmarcar la de entrar y
        // poner encima botones falsos (clickjacking). SAMEORIGIN y no DENY por
        // si algun dia la propia aplicacion enmarca una pantalla suya.
        $cabeceras->set('X-Frame-Options', 'SAMEORIGIN');

        // El navegador no «adivina» el tipo de un archivo: un documento subido
        // que dijera ser imagen no se ejecuta como otra cosa.
        $cabeceras->set('X-Content-Type-Options', 'nosniff');

        // Al salir hacia otro sitio solo viaja el dominio, nunca la ruta. Y la
        // ruta lleva secretos en dos pantallas: el token de inscribirse a una
        // actividad y el de la contrasena nueva.
        $cabeceras->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // La camara SI, para este mismo sitio: la usan los dos lectores QR (el
        // carne del estudiante y el cartel de la institucion externa). Lo
        // demas, apagado.
        $cabeceras->set('Permissions-Policy', 'camera=(self), microphone=(), geolocation=(), payment=()');

        // HSTS solo por HTTPS de verdad: mandado sobre http no vale nada, y en
        // desarrollo dejaria al navegador empenado en https://localhost.
        // Sin `includeSubDomains`: el dominio de la entidad tiene otros
        // subdominios (el WordPress) que no son de este sistema.
        if ($request->isSecure() || app()->environment('production')) {
            $cabeceras->set('Strict-Transport-Security', 'max-age=31536000');
        }

        // La version exacta de PHP no le sirve a nadie mas que a quien busca
        // un fallo conocido de esa version.
        $cabeceras->remove('X-Powered-By');
        if (! headers_sent()) {
            header_remove('X-Powered-By');
        }

        return $respuesta;
    }
}
