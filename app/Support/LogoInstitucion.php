<?php

namespace App\Support;

use App\Models\ConfiguracionInstitucion;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * El logo de la institucion, en UN solo sitio (25/09/2026).
 *
 * EL PROPIO SI LO HAY Y SE PUEDE LEER; SI NO, UNO GENERADO CON SUS INICIALES
 * sobre su color de acento. Hasta hoy el respaldo era un archivo del proyecto
 * —el logo de la Casa de la Cultura de El Santuario, la primera entidad que lo
 * uso— y cualquier otra institucion que no subiera el suyo salia con el de El
 * Santuario en la cabecera, los certificados, el consentimiento, el icono del
 * telefono y la vista previa al compartir. Eso es exactamente lo que PRODUCT.md
 * prohibe: nada de la entidad se quema en el codigo. El generado sale del
 * nombre y del color de la propia instalacion, asi que cada una tiene algo suyo
 * desde el primer dia.
 *
 * La regla estaba escrita a mano en SIETE sitios (tres plantillas, dos
 * controladores de PDF, los iconos y una prueba). Ahora todos preguntan aqui:
 * con siete copias, el dia que se cambiara el respaldo se habria quedado alguna
 * con el viejo sin que nada fallara.
 *
 * UN LOGO PROPIO QUE NO SE PUEDE LEER CAE AL GENERADO, no a un error: lo piden
 * pantallas sin sesion, el telefono y los PDF, y un 500 en cualquiera de ellos
 * es peor que unas iniciales.
 */
class LogoInstitucion
{
    /** El lado del logo generado, en pixeles: el mismo al que se guarda el propio. */
    private const LADO = 320;

    /** Las palabras que no dan inicial: «Casa de la Cultura» es «CC», no «CDLC». */
    private const SIN_INICIAL = ['de', 'del', 'la', 'las', 'el', 'los', 'y', 'e', 'en', 'para', 'por', 'a'];

    /** Los bytes del logo: el propio (WebP, PNG o JPEG) o el generado (PNG). */
    public static function binario(): string
    {
        $configuracion = ConfiguracionInstitucion::actual();
        $ruta = $configuracion->logo;

        if ($ruta !== '' && Storage::disk('local')->exists($ruta)) {
            $propio = (string) Storage::disk('local')->get($ruta);

            // Se comprueba que GD lo entienda: un archivo que existe pero no
            // se puede decodificar dejaria un hueco en cada sitio que lo usa.
            if (@imagecreatefromstring($propio) !== false) {
                return $propio;
            }
        }

        return self::generado($configuracion->nombre_institucion, $configuracion->color_acento);
    }

    /** Si la institucion subio el suyo. Para decirlo en la pantalla de configuracion. */
    public static function esPropio(): bool
    {
        $ruta = ConfiguracionInstitucion::actual()->logo;

        return $ruta !== '' && Storage::disk('local')->exists($ruta);
    }

    /**
     * Una huella corta de lo que cambia el logo, para las URL.
     *
     * Entran el nombre y el color porque de ellos sale el generado: cambiar el
     * nombre cambia las iniciales. Sin esto en la URL, el telefono, el
     * navegador y el CDN se quedarian dias con el viejo.
     */
    public static function version(): string
    {
        $c = ConfiguracionInstitucion::actual();

        return substr(md5($c->logo.'|'.$c->nombre_institucion.'|'.$c->color_acento), 0, 8);
    }

    /** Las iniciales del nombre: dos como mucho, que es lo que cabe en el circulo. */
    public static function iniciales(string $nombre): string
    {
        $palabras = preg_split('/\s+/u', trim($nombre)) ?: [];
        $letras = '';

        foreach ($palabras as $palabra) {
            if ($palabra === '' || in_array(mb_strtolower($palabra), self::SIN_INICIAL, true)) {
                continue;
            }

            $letras .= mb_strtoupper(mb_substr($palabra, 0, 1));

            if (mb_strlen($letras) === 2) {
                break;
            }
        }

        return $letras;
    }

    /** El logo generado: un circulo del color de acento con las iniciales en blanco. */
    public static function generado(string $nombre, string $colorAcento): string
    {
        $lado = self::LADO;
        $lienzo = imagecreatetruecolor($lado, $lado);

        if ($lienzo === false) {
            throw new RuntimeException('No se pudo crear el lienzo del logo.');
        }

        // Fondo transparente: en la cabecera el circulo se posa sobre el color
        // de la pagina, y el icono ya le pone su propio fondo blanco.
        imagesavealpha($lienzo, true);
        imagealphablending($lienzo, false);
        imagefilledrectangle($lienzo, 0, 0, $lado, $lado, (int) imagecolorallocatealpha($lienzo, 255, 255, 255, 127));
        imagealphablending($lienzo, true);

        [$r, $g, $b] = self::rgb($colorAcento);
        imagefilledellipse($lienzo, (int) ($lado / 2), (int) ($lado / 2), $lado - 4, $lado - 4, (int) imagecolorallocate($lienzo, $r, $g, $b));

        $texto = self::iniciales($nombre);

        if ($texto !== '') {
            $tamano = mb_strlen($texto) === 1 ? 150 : 120;
            $caja = imagettfbbox($tamano, 0, CarneQr::fuente(), $texto);

            if ($caja !== false) {
                // Centrado por la caja REAL del texto, no por el tamano: la «C»
                // y la «J» no ocupan lo mismo por encima y por debajo de la linea.
                $x = (int) (($lado - ($caja[2] - $caja[0])) / 2 - $caja[0]);
                $y = (int) (($lado - ($caja[1] - $caja[7])) / 2 - $caja[7]);
                imagettftext($lienzo, $tamano, 0, $x, $y, (int) imagecolorallocate($lienzo, 255, 255, 255), CarneQr::fuente(), $texto);
            }
        }

        ob_start();
        imagepng($lienzo, null, 6);
        $png = (string) ob_get_clean();
        imagedestroy($lienzo);

        return $png;
    }

    /** @return array{int, int, int} */
    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        if (preg_match('/^[0-9a-fA-F]{6}$/', $hex) !== 1) {
            // El verde de fabrica: un color mal guardado no deja sin logo.
            $hex = '0a7a59';
        }

        return [(int) hexdec(substr($hex, 0, 2)), (int) hexdec(substr($hex, 2, 2)), (int) hexdec(substr($hex, 4, 2))];
    }
}
