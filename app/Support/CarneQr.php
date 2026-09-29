<?php

namespace App\Support;

use App\Models\ConfiguracionInstitucion;
use App\Models\Perfil;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;
use GdImage;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * El carne QR con el que a un estudiante se le pasa lista sin que teclee nada.
 *
 * POR QUE EXISTE: casi todo el uso de este sistema es desde el celular, y una
 * parte del publico no se maneja con el. Quien olvido su usuario y su
 * contrasena no puede entrar, y hasta hoy eso significaba que nadie podia
 * confirmar su clase. Un papel con un codigo impreso —o una foto en la
 * galeria— lo devuelve a la lista sin pedirle que recuerde nada.
 *
 * LO QUE LLEVA IMPRESO Y LO QUE NO. Lleva el logo de la entidad si lo subio
 * (desde el 25/09/2026), el nombre de la institucion, el nombre de la persona
 * y el codigo. NO lleva el documento de identidad: un
 * carne se fotografia, se deja sobre una mesa y se pega en una pared, y la
 * cedula de un menor es el dato mas protegido que guarda este sistema (ver la
 * puerta del administrador en `ArchivoController`). El nombre si va, porque sin
 * el son todos el mismo cuadrito negro y el carne se vuelve imposible de
 * repartir.
 *
 * EL CODIGO ES ANONIMO: 16 caracteres aleatorios que no dicen nada de nadie y
 * que se pueden cambiar (`Perfil::renovarCodigoQr`). Quien lo lea con cualquier
 * aplicacion de codigos vera `MTR:` y un revoltijo — a proposito no es una URL,
 * porque una URL invita a abrirla y este codigo no lleva a ninguna pantalla:
 * solo tiene sentido dentro de una lista de clase que ya abrio el profesor que
 * la dicta.
 *
 * EL PREFIJO NO ES DECORACION. Sin el, el lector de la hoja de asistencia
 * tendria que probar suerte con CUALQUIER codigo que le pase por delante —el de
 * un producto del supermercado, el de una factura— y ponerse a cotejarlo. Con
 * el, lo que no empieza por `MTR:` se descarta antes de mirar nada y el
 * profesor recibe «ese codigo no es de un carne» en vez de «no esta en la
 * lista», que manda a buscar por donde no es.
 *
 * SE DIBUJA CON GD y no con Imagick por lo de siempre: produccion tiene las dos
 * y esta maquina solo GD, asi que con Imagick ni las pruebas de aqui ni las del
 * CI comprobarian lo que de verdad corre. La fuente sale del propio paquete del
 * QR (`endroid/qr-code` trae `open_sans.ttf`) en vez de la de dompdf: las dos
 * viven en `vendor/`, pero pedirsela al paquete que ya estamos usando no
 * inventa una dependencia nueva entre dos cosas que no se conocen.
 */
class CarneQr
{
    /**
     * Lo que antecede al codigo dentro del QR.
     *
     * Corto a proposito: cada caracter de mas engorda la rejilla del QR, y esta
     * se imprime en papel y se lee con la camara de un telefono de gama baja.
     */
    public const PREFIJO = 'MTR:';

    /** El lado del QR dentro del carne, en pixeles. */
    private const LADO_QR = 560;

    /** El ancho de la imagen que se descarga. */
    private const ANCHO = 720;

    private const MARGEN = 40;

    /** El tamano del nombre de la institucion, arriba. */
    private const TAMANO_INSTITUCION = 22;

    /**
     * La caja en la que se encaja el logo, en pixeles.
     *
     * Se encaja sin deformar: un logo apaisado llega al ancho y uno cuadrado al
     * alto. 110 de alto es lo que deja al QR seguir siendo lo mas grande del
     * carne, que es lo que tiene que leer una camara.
     */
    private const LOGO_ANCHO = 360;

    private const LOGO_ALTO = 110;

    /**
     * Lo que queda codificado dentro del cuadrito.
     *
     * Crea el codigo si esta persona todavia no tenia (ver `Perfil::codigoQr`).
     */
    public static function contenido(Perfil $estudiante): string
    {
        return self::PREFIJO.$estudiante->codigoQr();
    }

    /**
     * El codigo que hay dentro de lo que leyo la camara, o null.
     *
     * Devuelve null para TODO lo que no sea un carne de este sistema, que es
     * justo lo que hace falta para poder decirselo a quien escanea. La longitud
     * se comprueba aqui y no solo en la base: asi una cadena larguisima leida de
     * un codigo cualquiera no llega a convertirse en una consulta.
     */
    public static function codigoLeido(string $leido): ?string
    {
        $leido = trim($leido);

        if (! str_starts_with($leido, self::PREFIJO)) {
            return null;
        }

        $codigo = substr($leido, strlen(self::PREFIJO));

        // `Str::random` devuelve letras, digitos, y nada mas.
        return preg_match('/^[A-Za-z0-9]{16}$/', $codigo) === 1 ? $codigo : null;
    }

    /**
     * La huella del codigo, que es lo unico del carne que viaja a la pantalla
     * del profesor.
     *
     * LA HOJA DE ASISTENCIA NO LLEVA LOS CODIGOS. Si los llevara, el profesor
     * —o cualquiera que abriera el inspector en su telefono— se iria con el
     * carne de sus cuarenta estudiantes, y esos codigos sirven en las clases de
     * los demas profesores, no solo en la suya. Con la huella, el navegador
     * puede reconocer al vuelo a quien acaba de escanear —vuelve a calcular el
     * SHA-256 de lo que leyo la camara y compara— sin que la pagina contenga
     * nada que sirva para hacerse pasar por nadie.
     *
     * Es SHA-256 pelado y no bcrypt, por lo mismo que el token de restablecer la
     * clave: aqui no se guarda un secreto que alguien elige y repite en otros
     * sitios, sino 16 caracteres del generador seguro, y hace falta poder
     * cotejarlo miles de veces en el telefono sin calentarlo.
     */
    public static function huella(string $codigo): string
    {
        return hash('sha256', $codigo);
    }

    /** El PNG del cuadrito solo, sin nombre ni marco. Para pintarlo en pantalla. */
    public static function qr(string $contenido, int $lado = self::LADO_QR): string
    {
        return (new Builder(
            writer: new PngWriter,
            data: $contenido,
            // Alta a proposito: un carne vive doblado en un bolsillo o impreso
            // en una fotocopia gastada. Con correccion alta el codigo sigue
            // leyendose con hasta un 30% de la superficie estropeada, y lo que
            // cuesta —una rejilla mas densa— no se nota a este tamano.
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: $lado,
            margin: 16,
        ))->build()->getString();
    }

    /**
     * El carne entero como PNG: institucion, cuadrito, nombre y la instruccion.
     *
     * PNG y no PDF aunque el proyecto ya sepa hacer PDF: esto tiene que poder
     * quedarse en la GALERIA de un telefono, y un PDF ahi no entra. Se imprime
     * igual de bien.
     */
    public static function carne(Perfil $estudiante): string
    {
        return self::tarjeta(
            self::contenido($estudiante),
            $estudiante->nombre_completo,
            'Muéstralo para que te marquen la asistencia'
        );
    }

    /**
     * El QR de una institucion externa, del 23/09/2026.
     *
     * LA MISMA TARJETA Y EL MISMO CODIGO, con dos textos cambiados, y no un
     * segundo dibujante: el carne y esto son el mismo objeto —un carton con un
     * cuadrito que alguien lee con una camara— y dos copias del mismo trazado
     * se separan en cuanto una de las dos crezca un renglon.
     *
     * LO GRANDE ES EL NOMBRE DE LA INSTITUCION, no el del funcionario. Este
     * carton vive EN LA ESCUELA y lo que hay que poder leer de un vistazo es de
     * que escuela es: la persona que firma puede cambiar de trabajo mañana y el
     * carton sigue siendo el mismo. El codigo, eso si, es el de SU perfil —es
     * ahi donde vive `codigo_qr`— asi que renovarlo es renovar el de la cuenta.
     *
     * Y EL PIE DICE OTRA COSA. «Muéstralo para que te marquen la asistencia» es
     * la instruccion de un estudiante; aqui quien lee es el profesor y lo que
     * hay que decir es cuando. El del plazo —solo el mismo dia— NO va impreso:
     * un carton no se reimprime cuando una regla cambia, y una instruccion
     * desfasada en papel es peor que ninguna. Eso lo dice la pantalla, que si
     * se actualiza.
     */
    public static function carneDeInstitucion(Perfil $perfil, string $institucion): string
    {
        return self::tarjeta(self::contenido($perfil), $institucion, 'El profesor lo lee al terminar la clase');
    }

    /**
     * El cartel de un ENLACE DE INSCRIPCION (29/09/2026): el de un curso,
     * taller o grupo de proyeccion, o el de una promotoria.
     *
     * El mismo carton otra vez, y por lo mismo que el de la institucion: es un
     * cuadrito que alguien lee con una camara, con el logo y el nombre de la
     * casa encima. Aqui dentro no va un codigo sino la DIRECCION entera, que
     * es lo que una camara de telefono sabe abrir sin ninguna aplicacion.
     * Sirve igual en un cartel pegado en la pared que en una publicacion de
     * redes, porque es un PNG.
     */
    public static function cartel(string $url, string $nombre, string $pie): string
    {
        return self::tarjeta($url, $nombre, $pie);
    }

    /**
     * El trazado que comparten los cartones.
     *
     * `$contenido` es lo que va dentro del cuadrito; `$nombre`, lo que se
     * imprime en grande. En el carne de un estudiante salen de la misma
     * persona; en el de una institucion no, y en un cartel de inscripcion el
     * contenido es una direccion. Por eso son dos parametros y no uno.
     */
    private static function tarjeta(string $contenido, string $nombre, string $pie): string
    {
        $configuracion = ConfiguracionInstitucion::actual();
        $qr = self::imagenDesdePng(self::qr($contenido));
        $logo = self::logo($configuracion->logo);

        $anchoQr = imagesx($qr);
        $altoQr = imagesy($qr);

        // El alto se calcula, no se fija: un nombre largo parte en dos renglones
        // y el carne crece con el. Fijarlo dejaba el nombre pisando el borde
        // justo en los nombres largos, que son los de siempre aqui.
        $renglones = self::partirEnRenglones($nombre, 34, self::ANCHO - 2 * self::MARGEN, 30);

        // EL NOMBRE DE LA INSTITUCION TAMBIEN SE PARTE (25/09/2026). Iba en un
        // solo renglon, y el de produccion no cabe en 720 pixeles a este
        // tamano: `imagettftext` no avisa, dibuja fuera del lienzo y el carne
        // sale con el nombre cortado por los dos lados. El tope de letras es
        // mas alto que el del nombre de la persona porque la letra es menor.
        $renglonesInstitucion = self::partirEnRenglones(
            $configuracion->nombre_institucion,
            60,
            self::ANCHO - 2 * self::MARGEN,
            self::TAMANO_INSTITUCION
        );

        // El alto sale de RECORRER la misma lista de pasos que despues se
        // dibuja, y no de una suma escrita aparte: escrita aparte, cambiar un
        // renglon de sitio deja el carne cortado por abajo, y eso no falla —
        // sale una imagen con el nombre a medias.
        $pasos = self::pasos($logo, $renglonesInstitucion, $altoQr, $renglones, $pie);
        $alto = self::MARGEN;

        foreach ($pasos as $paso) {
            $alto += $paso['antes'] + $paso['alto'];
        }

        $alto += self::MARGEN;

        $lienzo = imagecreatetruecolor(self::ANCHO, $alto);

        if ($lienzo === false) {
            throw new RuntimeException('No se pudo crear el lienzo del carne.');
        }

        $blanco = (int) imagecolorallocate($lienzo, 255, 255, 255);
        $negro = (int) imagecolorallocate($lienzo, 17, 17, 17);
        $gris = (int) imagecolorallocate($lienzo, 110, 110, 110);

        imagefilledrectangle($lienzo, 0, 0, self::ANCHO, $alto, $blanco);

        $y = self::MARGEN;

        foreach ($pasos as $paso) {
            $y += $paso['antes'];

            if ($paso['tipo'] === 'qr') {
                imagecopy($lienzo, $qr, (int) ((self::ANCHO - $anchoQr) / 2), $y, 0, 0, $anchoQr, $altoQr);
            } elseif ($paso['tipo'] === 'logo' && $logo !== null) {
                // Remuestreado y no copiado: el logo se guarda a 320 de lado y
                // aqui se encaja en su caja. Con la mezcla alfa del lienzo
                // encendida —la de fabrica en color verdadero— un logo con
                // fondo transparente se posa sobre el blanco en vez de salir
                // con un recuadro negro.
                imagecopyresampled(
                    $lienzo, $logo,
                    (int) ((self::ANCHO - $paso['ancho']) / 2), $y, 0, 0,
                    $paso['ancho'], $paso['alto'], imagesx($logo), imagesy($logo)
                );
            } else {
                // `imagettftext` toma la LINEA BASE, no el borde de arriba: el
                // texto se dibuja a la altura del paso mas su ascendente, o
                // sale una fila mas arriba de donde se conto.
                self::centrado($lienzo, $paso['texto'], $paso['tamano'], $y + $paso['tamano'], $paso['color'] === 'negro' ? $negro : $gris);
            }

            $y += $paso['alto'];
        }

        imagedestroy($qr);

        if ($logo !== null) {
            imagedestroy($logo);
        }

        return self::aPng($lienzo);
    }

    /**
     * Lo que lleva el carne, de arriba abajo, con lo que ocupa cada cosa.
     *
     * `antes` es el aire que va encima y `alto` lo que ocupa. Un texto se cuenta
     * como tamano + un tercio, que es lo que baja una «g» por debajo de la linea
     * base: sin ese margen el ultimo renglon queda pegado al borde.
     *
     * @param  list<string>  $renglonesInstitucion
     * @param  list<string>  $renglones
     * @return list<array{tipo: string, texto: string, tamano: int, color: string, antes: int, alto: int, ancho: int}>
     */
    private static function pasos(?GdImage $logo, array $renglonesInstitucion, int $altoQr, array $renglones, string $pie): array
    {
        $texto = fn (string $t, int $tamano, string $color, int $antes) => [
            'tipo' => 'texto',
            'texto' => $t,
            'tamano' => $tamano,
            'color' => $color,
            'antes' => $antes,
            'alto' => (int) round($tamano * 1.34),
            'ancho' => 0,
        ];

        $pasos = [];

        // El logo arriba del todo, y solo si la entidad subio uno: sin logo el
        // carne queda como era, sin un hueco donde iba a ir.
        if ($logo !== null) {
            $escala = min(self::LOGO_ANCHO / imagesx($logo), self::LOGO_ALTO / imagesy($logo));

            $pasos[] = [
                'tipo' => 'logo',
                'texto' => '',
                'tamano' => 0,
                'color' => '',
                'antes' => 0,
                'alto' => max(1, (int) round(imagesy($logo) * $escala)),
                'ancho' => max(1, (int) round(imagesx($logo) * $escala)),
            ];
        }

        foreach ($renglonesInstitucion as $indice => $renglon) {
            $antes = $indice === 0 ? ($logo !== null ? 14 : 0) : 2;
            $pasos[] = $texto($renglon, self::TAMANO_INSTITUCION, 'gris', $antes);
        }

        $pasos[] = ['tipo' => 'qr', 'texto' => '', 'tamano' => 0, 'color' => '', 'antes' => 18, 'alto' => $altoQr, 'ancho' => 0];

        foreach ($renglones as $indice => $renglon) {
            $pasos[] = $texto($renglon, 30, 'negro', $indice === 0 ? 22 : 6);
        }

        $pasos[] = $texto($pie, 16, 'gris', 20);

        return $pasos;
    }

    /**
     * El mismo carne en JPEG, para la hoja de imprimir.
     *
     * MEDIDO el 25/09/2026: dompdf decodifica y vuelve a comprimir cada PNG
     * —unos 160 ms por imagen distinta, en paleta o no— y un JPEG lo incrusta
     * tal cual. Sesenta carnes pasaban de 20 s, que con el CDN de Hostinger
     * cortando hacia los 60 dejaba fuera a cualquier promotoria grande; en JPEG
     * dompdf tarda 1,2 s. Calidad alta a proposito: el carne es blanco, negro y
     * gris, y con la correccion de errores alta del QR unos pocos pixeles de
     * ruido junto a los bordes no le quitan lectura.
     *
     * Solo para la hoja. La descarga suelta sigue en PNG, que es la que se
     * guarda en la galeria del telefono y se reimprime.
     */
    public static function comoJpeg(string $png): string
    {
        $imagen = self::imagenDesdePng($png);

        ob_start();
        imagejpeg($imagen, null, 92);
        $jpeg = (string) ob_get_clean();
        imagedestroy($imagen);

        return $jpeg;
    }

    /**
     * El nombre del archivo que baja: que se distinga en la galeria.
     *
     * `$nombre` lo sobreescribe para el QR de una institucion externa, donde lo
     * que distingue el archivo es la ESCUELA y no el funcionario: dos cartones
     * llamados «carne-maria-lopez» en la carpeta de descargas de quien los
     * imprime no se distinguen, y son de dos veredas distintas.
     */
    public static function nombreDeArchivo(Perfil $estudiante, ?string $nombre = null): string
    {
        $nombre = preg_replace('/[^A-Za-z0-9]+/', '-', self::sinTildes($nombre ?? $estudiante->nombre_completo));

        return 'carne-'.trim((string) $nombre, '-').'.png';
    }

    /** El nombre del archivo de un cartel de inscripcion: `qr-inscripcion-violin.png`. */
    public static function nombreDeCartel(string $nombre): string
    {
        $nombre = preg_replace('/[^A-Za-z0-9]+/', '-', self::sinTildes($nombre));

        return 'qr-inscripcion-'.strtolower(trim((string) $nombre, '-')).'.png';
    }

    /**
     * Parte un nombre en renglones que quepan.
     *
     * Mide de verdad con `imagettfbbox` en vez de contar caracteres: una «W» y
     * una «i» no ocupan lo mismo y el corte por numero de letras deja unos
     * renglones cortos y otros desbordados. `$maximo` acota aparte por letras
     * porque un nombre sin espacios no se puede partir por ningun lado y ahi la
     * unica salida es recortarlo.
     *
     * @return list<string>
     */
    public static function partirEnRenglones(string $texto, int $maximo, int $ancho, int $tamano): array
    {
        $palabras = preg_split('/\s+/u', trim($texto)) ?: [];
        $renglones = [];
        $actual = '';

        foreach ($palabras as $palabra) {
            $prueba = $actual === '' ? $palabra : "{$actual} {$palabra}";

            if ($actual !== '' && (self::anchoDe($prueba, $tamano) > $ancho || mb_strlen($prueba) > $maximo)) {
                $renglones[] = $actual;
                $actual = $palabra;

                continue;
            }

            $actual = $prueba;
        }

        if ($actual !== '') {
            $renglones[] = $actual;
        }

        // Nunca vacio: un carne sin nombre sigue siendo un carne, y devolver
        // una lista vacia dejaria el calculo del alto sin renglones y el bucle
        // de dibujo sin nada que pintar.
        return $renglones === [] ? [''] : $renglones;
    }

    public static function anchoDe(string $texto, int $tamano): int
    {
        $caja = imagettfbbox($tamano, 0, self::fuente(), $texto);

        return $caja === false ? 0 : (int) ($caja[2] - $caja[0]);
    }

    private static function centrado(GdImage $lienzo, string $texto, int $tamano, int $y, int $color): void
    {
        if ($texto === '') {
            return;
        }

        $x = (int) ((self::ANCHO - self::anchoDe($texto, $tamano)) / 2);

        imagettftext($lienzo, $tamano, 0, $x, $y, $color, self::fuente(), $texto);
    }

    /**
     * La fuente con la que se dibuja el texto del carne.
     *
     * Viene con `endroid/qr-code`, que es una dependencia declarada: si algun
     * dia no esta, lo que falta es el paquete entero y el carne no se dibuja de
     * ninguna manera. Por eso esto revienta claro en vez de caer a la fuente de
     * mapa de bits de GD, que dibujaria un nombre ilegible y sin tildes en un
     * papel que la gente tiene que reconocer como suyo.
     */
    public static function fuente(): string
    {
        $ruta = base_path('vendor/endroid/qr-code/assets/open_sans.ttf');

        if (! is_file($ruta)) {
            throw new RuntimeException("Falta la fuente del carne: {$ruta}");
        }

        return $ruta;
    }

    /**
     * El logo de la entidad como imagen de GD, o null.
     *
     * NULL Y NO UNA EXCEPCION cuando falta o no se puede leer: un carne sin
     * logo sigue sirviendo para pasar lista, y un error aqui dejaria sin carne
     * a todo el mundo —tambien al que lo saca recien inscrito— por un adorno.
     * Es la misma caida que el consentimiento propio de la entidad, que vuelve
     * al formato impreso si su archivo no esta.
     */
    private static function logo(string $ruta): ?GdImage
    {
        if ($ruta === '') {
            return null;
        }

        $disco = Storage::disk('local');

        if (! $disco->exists($ruta)) {
            return null;
        }

        $imagen = @imagecreatefromstring((string) $disco->get($ruta));

        return $imagen === false ? null : $imagen;
    }

    private static function imagenDesdePng(string $png): GdImage
    {
        $imagen = imagecreatefromstring($png);

        if ($imagen === false) {
            throw new RuntimeException('No se pudo leer el PNG del código QR.');
        }

        return $imagen;
    }

    private static function aPng(GdImage $lienzo): string
    {
        ob_start();
        imagepng($lienzo, null, 6);
        $png = (string) ob_get_clean();
        imagedestroy($lienzo);

        return $png;
    }

    /** Para el nombre del archivo: «Muñoz» baja como «Munoz», no como «Mu-oz». */
    private static function sinTildes(string $texto): string
    {
        return strtr($texto, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
        ]);
    }
}
