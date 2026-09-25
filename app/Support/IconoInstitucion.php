<?php

namespace App\Support;

use App\Models\ConfiguracionInstitucion;
use GdImage;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * El logo de la entidad convertido en lo que piden los telefonos y las redes
 * (25/09/2026): el icono del acceso directo y la imagen que sale al compartir
 * el enlace.
 *
 * POR QUE SE GENERA Y NO SE SUBE. El logo se guarda en WebP (ver
 * `ConfiguracionController`), y ni el iPhone acepta un WebP como icono de
 * inicio ni WhatsApp lo pinta de forma fiable en la vista previa: los dos
 * piden PNG. Pedirle a la entidad tres archivos mas con medidas exactas es como
 * se acaban quedando sin poner; asi, subir el logo en Gestion → Institucion
 * basta para que todo cambie a la vez. Nada de la entidad se quema aqui.
 *
 * EL LOGO DE RESPALDO ES EL MISMO DE LA CABECERA (`public/img/logo.webp`), y
 * con la misma regla: el propio si lo hay, y si no, ese. Dos reglas distintas
 * dejarian una pantalla con un logo y el icono con otro.
 *
 * FONDO BLANCO Y OPACO, a proposito: el iPhone pinta en NEGRO lo transparente
 * de un icono de inicio, y un logo con fondo transparente saldria sobre un
 * cuadro negro.
 */
class IconoInstitucion
{
    /** Los lados que se sirven. Otro numero es un 404, no una imagen a medida. */
    public const LADOS = [180, 192, 512];

    /**
     * Que parte del icono ocupa el logo.
     *
     * Android recorta los iconos «maskable» en circulo o en gota, y garantiza
     * solo el 80% central. Al 72% el logo entero cae dentro de esa zona con
     * cualquier recorte.
     */
    private const PROPORCION_LOGO = 0.72;

    /** La imagen de compartir: la medida que piden WhatsApp, Facebook y compania. */
    private const COMPARTIR_ANCHO = 1200;

    private const COMPARTIR_ALTO = 630;

    /** El icono cuadrado, en PNG. */
    public static function icono(int $lado): string
    {
        $lienzo = self::lienzoBlanco($lado, $lado);
        $logo = self::logo();

        $caja = (int) round($lado * self::PROPORCION_LOGO);
        self::encajar($lienzo, $logo, (int) (($lado - $caja) / 2), (int) (($lado - $caja) / 2), $caja, $caja);

        imagedestroy($logo);

        return self::aPng($lienzo);
    }

    /**
     * La imagen que aparece al compartir el enlace: el logo y el nombre.
     *
     * El nombre va porque en la vista previa de WhatsApp la imagen sale grande
     * y el titulo pequeno debajo; un logo solo no dice de que casa es a quien
     * no lo conoce. Se parte en renglones por lo mismo que en el carne: el de
     * produccion no cabe en uno.
     */
    public static function paraCompartir(): string
    {
        $ancho = self::COMPARTIR_ANCHO;
        $alto = self::COMPARTIR_ALTO;
        $lienzo = self::lienzoBlanco($ancho, $alto);
        $logo = self::logo();

        $tamano = 40;
        $renglones = CarneQr::partirEnRenglones(
            ConfiguracionInstitucion::actual()->nombre_institucion,
            40,
            $ancho - 160,
            $tamano
        );
        $altoRenglon = (int) round($tamano * 1.4);

        // El bloque (logo + aire + renglones) centrado en vertical.
        $ladoLogo = 300;
        $bloque = $ladoLogo + 40 + count($renglones) * $altoRenglon;
        $y = (int) max(30, ($alto - $bloque) / 2);

        self::encajar($lienzo, $logo, (int) (($ancho - $ladoLogo) / 2), $y, $ladoLogo, $ladoLogo);
        imagedestroy($logo);

        $color = (int) imagecolorallocate($lienzo, 34, 34, 34);
        $y += $ladoLogo + 40;

        foreach ($renglones as $renglon) {
            $x = (int) (($ancho - CarneQr::anchoDe($renglon, $tamano)) / 2);
            // `imagettftext` toma la linea base, no el borde de arriba.
            imagettftext($lienzo, $tamano, 0, $x, $y + $tamano, $color, CarneQr::fuente(), $renglon);
            $y += $altoRenglon;
        }

        return self::aPng($lienzo);
    }

    /**
     * Una huella corta de lo que cambia estas imagenes, para la URL.
     *
     * Los telefonos y el CDN guardan un icono mucho tiempo: sin algo en la URL
     * que cambie con el logo, quien cambia el logo seguiria viendo el viejo
     * durante dias y creeria que no funciono.
     */
    public static function version(): string
    {
        $configuracion = ConfiguracionInstitucion::actual();

        return substr(md5($configuracion->logo.'|'.$configuracion->nombre_institucion), 0, 8);
    }

    /**
     * El logo de la entidad, o el de respaldo.
     *
     * Si el propio no se puede leer se cae al de respaldo en vez de fallar:
     * un icono equivocado se corrige subiendo el logo otra vez, y un error aqui
     * seria un 500 en una URL que piden los telefonos sin que nadie mire.
     */
    private static function logo(): GdImage
    {
        $ruta = ConfiguracionInstitucion::actual()->logo;

        if ($ruta !== '' && Storage::disk('local')->exists($ruta)) {
            $propio = @imagecreatefromstring((string) Storage::disk('local')->get($ruta));

            if ($propio !== false) {
                return $propio;
            }
        }

        $respaldo = @imagecreatefromstring((string) @file_get_contents(public_path('img/logo.webp')));

        if ($respaldo === false) {
            throw new RuntimeException('No hay ningún logo que se pueda leer para el icono.');
        }

        return $respaldo;
    }

    private static function lienzoBlanco(int $ancho, int $alto): GdImage
    {
        $lienzo = imagecreatetruecolor($ancho, $alto);

        if ($lienzo === false) {
            throw new RuntimeException('No se pudo crear el lienzo del icono.');
        }

        imagefilledrectangle($lienzo, 0, 0, $ancho, $alto, (int) imagecolorallocate($lienzo, 255, 255, 255));

        return $lienzo;
    }

    /** Pone el logo dentro de la caja, centrado y sin deformarlo. */
    private static function encajar(GdImage $lienzo, GdImage $logo, int $x, int $y, int $ancho, int $alto): void
    {
        $escala = min($ancho / imagesx($logo), $alto / imagesy($logo));
        $w = max(1, (int) round(imagesx($logo) * $escala));
        $h = max(1, (int) round(imagesy($logo) * $escala));

        imagecopyresampled(
            $lienzo, $logo,
            $x + (int) (($ancho - $w) / 2), $y + (int) (($alto - $h) / 2), 0, 0,
            $w, $h, imagesx($logo), imagesy($logo)
        );
    }

    private static function aPng(GdImage $lienzo): string
    {
        ob_start();
        imagepng($lienzo, null, 6);
        $png = (string) ob_get_clean();
        imagedestroy($lienzo);

        return $png;
    }
}
