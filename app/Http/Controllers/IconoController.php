<?php

namespace App\Http\Controllers;

use App\Models\ConfiguracionInstitucion;
use App\Support\IconoInstitucion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Los iconos del acceso directo, la imagen de compartir y el manifiesto
 * (25/09/2026). Todo sale del logo de la entidad; ver `IconoInstitucion`.
 *
 * PUBLICO Y SIN SESION, como el logo: lo piden el telefono al poner el acceso
 * directo y el robot de WhatsApp al armar la vista previa, y ninguno de los dos
 * tiene cuenta. No entrega nada que la pantalla de entrar no ensene ya.
 */
class IconoController extends Controller
{
    public function icono(int $lado): Response
    {
        abort_unless(in_array($lado, IconoInstitucion::LADOS, true), 404);

        return $this->png(IconoInstitucion::icono($lado));
    }

    public function compartir(): Response
    {
        return $this->png(IconoInstitucion::paraCompartir());
    }

    /**
     * El manifiesto web: con el, Android toma el nombre y el icono al poner el
     * acceso directo en la pantalla de inicio.
     *
     * `display: browser` A PROPOSITO. Con `standalone` el acceso directo abriria
     * la aplicacion sin barra de direcciones, y eso cambia como se comporta todo
     * lo demas —las descargas de PDF y Excel, los enlaces que se comparten, el
     * boton de volver— sin que nadie lo haya pedido. Lo pedido es el ICONO.
     */
    public function manifiesto(): JsonResponse
    {
        $configuracion = ConfiguracionInstitucion::actual();
        $version = IconoInstitucion::version();

        return response()->json([
            'name' => $configuracion->nombre_institucion,
            'short_name' => $configuracion->nombre_institucion,
            'start_url' => '/',
            'display' => 'browser',
            'background_color' => '#ffffff',
            'theme_color' => $configuracion->color_acento,
            'icons' => array_map(fn (int $lado) => [
                'src' => route('icono-institucion', $lado).'?v='.$version,
                'sizes' => "{$lado}x{$lado}",
                'type' => 'image/png',
                // El logo va al 72% sobre blanco, asi que el mismo archivo sirve
                // recortado en circulo o entero.
                'purpose' => 'any maskable',
            ], [192, 512]),
        ], 200, [
            'Content-Type' => 'application/manifest+json',
            'Cache-Control' => 'public, max-age=3600',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Un dia de cache y PUBLICA: es la misma imagen para todo el mundo, y la
     * URL cambia con el logo (`?v=`), asi que un logo nuevo no espera a que
     * caduque nada.
     */
    private function png(string $png): Response
    {
        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
