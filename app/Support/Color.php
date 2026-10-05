<?php

namespace App\Support;

/**
 * Derivacion del color de marca. Puerto directo de las funciones del original
 * (`acento_oscuro`, `acento_suave`, `contraste` en models.py), sin dependencias.
 *
 * De UN solo color de acento salen el tono de hover y el de fondo suave, en vez
 * de pedir tres colores en la configuracion: asi cambiar de marca conserva la
 * relacion entre los tres y no obliga a nadie a inventarse una paleta.
 *
 * Los tres parametros de abajo estan medidos sobre el par de verdes que el
 * sistema ya usaba (#0a7a59 -> #065a41 / #dbf2e7): derivar con estos numeros
 * reproduce esos mismos tonos.
 *
 * Ojo con el redondeo: Python redondea al par mas cercano ("banker's rounding")
 * y PHP redondea alejandose del cero. En un canal de color la diferencia solo
 * aparece en el .5 exacto, pero ahi cambiaria el hex resultante, asi que se
 * replica el redondeo de Python — el objetivo es que los dos sistemas den
 * exactamente el mismo color, no uno parecido.
 */
class Color
{
    public const FACTOR_ACENTO_OSCURO = 0.727;

    public const LUZ_ACENTO_SUAVE = 0.904;

    public const SATURACION_MAXIMA_SUAVE = 0.469;

    /**
     * Los tres numeros del modo oscuro, medidos el 08/09/2026.
     *
     * `SATURACION_MAXIMA_OSCURO` es el techo de croma al aclarar el acento: con
     * el verde de fabrica sin tope salia `#13ecac`, un menta fluorescente.
     * `CONTRASTE_MINIMO_ACENTO` es el AA de texto normal, porque el acento se
     * usa como color de enlace y no solo de adorno. `SALTO_HOVER` es cuanta luz
     * separa el hover del reposo: menos no se nota al tacto y mas parece otro
     * color.
     */
    public const SATURACION_MAXIMA_OSCURO = 0.42;

    public const CONTRASTE_MINIMO_ACENTO = 4.5;

    public const SALTO_HOVER = 0.10;

    /** Tono de hover/activo: el mismo color con la luminosidad bajada. */
    public static function acentoOscuro(string $hex): string
    {
        [$h, $l, $s] = self::rgbAHls(self::hexARgb($hex));

        return self::rgbAHex(self::hlsARgb($h, $l * self::FACTOR_ACENTO_OSCURO, $s));
    }

    /** Tinte claro para anillos de foco y fondos de mensaje de exito. */
    public static function acentoSuave(string $hex): string
    {
        [$h, $l, $s] = self::rgbAHls(self::hexARgb($hex));

        return self::rgbAHex(self::hlsARgb(
            $h,
            self::LUZ_ACENTO_SUAVE,
            min($s, self::SATURACION_MAXIMA_SUAVE)
        ));
    }

    /**
     * Los tres tonos del acento CUANDO EL FONDO ES OSCURO.
     *
     * No son los de arriba con otro nombre: sobre un fondo oscuro el acento de
     * marca deja de servir. Medido el 08/09/2026 con el verde de fabrica,
     * `#0a7a59` sobre la superficie oscura da 2,98:1 — no llega ni al minimo de
     * texto grande. La marca no puede pedir prestada su legibilidad al fondo
     * claro que ya no esta.
     *
     * Asi que el acento se ACLARA hasta cumplir, conservando su tono: sigue
     * siendo el verde de la institucion, o el color que esa institucion haya
     * elegido, pero a una luminosidad que se lee.
     *
     * Y LOS OTROS DOS INVIERTEN SU PAPEL, que es lo que no se ve leyendo los
     * nombres:
     *
     * - `acentoOscuro` es el tono de hover. En claro se oscurece para separarse
     *   del fondo; en oscuro tiene que ACLARARSE, por lo mismo.
     * - `acentoSuave` es el fondo de una pastilla con texto de acento encima.
     *   En claro es un tinte palido; en oscuro tiene que ser un tinte OSCURO, o
     *   la pastilla se convierte en un parche brillante con texto ilegible.
     *
     * Se busca la luminosidad por pasos y no con una constante como sus vecinas
     * porque el acento lo elige cada institucion: una constante que funciona con
     * el verde de fabrica deja fuera un azul marino o un amarillo. Se sube de
     * 0,02 en 0,02 hasta pasar el minimo contra la superficie oscura, con un
     * tope por si el color es tan saturado que no llega — ahi manda el ultimo
     * valor probado, que es el mas legible que ese tono admite.
     *
     * @return array{claro: string, hover: string, suave: string}
     */
    public static function acentoParaFondoOscuro(string $hex, string $superficie = '#1c2421'): array
    {
        [$h, , $s] = self::rgbAHls(self::hexARgb($hex));

        // Se DESATURA al aclarar, y no es cosmetico. Subir la luz conservando la
        // saturacion de un verde de marca da un menta de neon —medido con el de
        // fabrica: `#13ecac`— que no pertenece a este sistema visual: el mundo
        // de esta aplicacion es sobrio y una pantalla llena de fluorescente
        // cansa justo a quien enciende el modo oscuro para descansar la vista.
        // Es ademas lo que hace cualquier paleta oscura seria: los tonos claros
        // pierden croma.
        $sClaro = min($s, self::SATURACION_MAXIMA_OSCURO);

        $luzBase = self::luzQueCumple($h, $sClaro, $superficie, self::CONTRASTE_MINIMO_ACENTO);
        $claro = self::rgbAHex(self::hlsARgb($h, $luzBase, $sClaro));
        $sTinte = min($s, self::SATURACION_MAXIMA_SUAVE);

        return [
            'claro' => $claro,
            // El hover es un ESCALON FIJO por encima, no otro objetivo de
            // contraste: buscando un contraste mayor, el primer valor que ya lo
            // cumplia devolvia el mismo color y el boton se quedaba sin
            // respuesta al tacto. Se vio midiendo, no mirando.
            'hover' => self::rgbAHex(self::hlsARgb($h, min(0.92, $luzBase + self::SALTO_HOVER), $sClaro)),
            // Y el tinte de fondo, en el otro sentido: se OSCURECE hasta que el
            // acento claro se lee encima. Con una constante (0,16) el par bajaba
            // a 3,48:1 con el verde de fabrica y a 3,99 con un ocre — por debajo
            // del AA de texto normal, y ese par ES texto: la pastilla que dice
            // cuantos filtros hay puestos.
            'suave' => self::rgbAHex(self::hlsARgb($h, self::luzDeFondoQueCumple($h, $claro, $sTinte), $sTinte)),
        ];
    }

    /**
     * La luz mas ALTA de un tinte que todavia deja leer el acento claro encima.
     *
     * El gemelo de `luzQueCumple`, en el otro sentido: aqui lo que se mueve es
     * el fondo y lo que se queda quieto es el texto. Se empieza claro y se baja
     * para que el tinte sea lo mas suave posible sin dejar de cumplir — un
     * fondo mas oscuro de lo necesario convierte la pastilla en un agujero.
     */
    private static function luzDeFondoQueCumple(float $h, string $textoHex, float $s): float
    {
        for ($l = 0.24; $l >= 0.06; $l -= 0.02) {
            if (self::contraste($textoHex, self::rgbAHex(self::hlsARgb($h, $l, $s))) >= self::CONTRASTE_MINIMO_ACENTO) {
                return $l;
            }
        }

        return 0.06;
    }

    /**
     * La luz minima de ese tono que contrasta lo pedido sobre ese fondo.
     *
     * Por pasos y no resolviendo la ecuacion porque la luminancia WCAG no es
     * lineal con la luminosidad HLS —lleva la correccion gamma dentro— y el
     * bucle son como mucho cuarenta vueltas de aritmetica.
     *
     * Si el tono no llega ni al maximo de luz, devuelve ese maximo: es lo mas
     * legible que ese color admite, y forzarlo mas seria dejar de ser el color
     * que la institucion eligio.
     */
    private static function luzQueCumple(float $h, float $s, string $fondo, float $minimo): float
    {
        for ($l = 0.40; $l <= 0.92; $l += 0.02) {
            if (self::contraste(self::rgbAHex(self::hlsARgb($h, $l, $s)), $fondo) >= $minimo) {
                return $l;
            }
        }

        return 0.92;
    }

    /** El texto oscuro de la paleta (`--ink` en modo claro). */
    public const TINTA = '#182420';

    /** El texto secundario de la paleta (`--ink-soft` en modo claro). */
    public const TINTA_SUAVE = '#55645c';

    /**
     * El texto que se lee sobre ese fondo: blanco u oscuro, el que mas
     * contraste de. Es lo que deja elegir CUALQUIER color de cabecera sin que
     * nadie tenga que pensar en el texto.
     */
    public static function textoSobre(string $fondo): string
    {
        return self::contraste('#ffffff', $fondo) >= self::contraste(self::TINTA, $fondo)
            ? '#ffffff'
            : self::TINTA;
    }

    /** Razon de contraste WCAG entre dos colores hex. */
    public static function contraste(string $a, string $b): float
    {
        $la = self::luminancia($a);
        $lb = self::luminancia($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /** ¿Es un hex de 6 digitos con almohadilla? */
    public static function esHexValido(string $valor): bool
    {
        return (bool) preg_match('/^#[0-9a-fA-F]{6}$/', $valor);
    }

    // -----------------------------------------------------------------------

    /** @return array{float,float,float} */
    private static function hexARgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [
            hexdec(substr($hex, 0, 2)) / 255,
            hexdec(substr($hex, 2, 2)) / 255,
            hexdec(substr($hex, 4, 2)) / 255,
        ];
    }

    /** @param array{float,float,float} $rgb */
    private static function rgbAHex(array $rgb): string
    {
        $canales = array_map(
            fn (float $c) => max(0, min(255, self::redondeoAlPar($c * 255))),
            $rgb
        );

        return vsprintf('#%02x%02x%02x', $canales);
    }

    /**
     * Redondeo al entero par mas cercano en el empate — el de Python.
     * PHP redondearia 0.5 a 1 y 1.5 a 2; Python da 0 y 2.
     */
    private static function redondeoAlPar(float $valor): int
    {
        $abajo = floor($valor);
        $resto = $valor - $abajo;

        if (abs($resto - 0.5) < 1e-9) {
            return (int) (fmod($abajo, 2.0) == 0.0 ? $abajo : $abajo + 1);
        }

        return (int) round($valor);
    }

    /**
     * Equivalente de `colorsys.rgb_to_hls`. Devuelve [matiz, luz, saturacion]
     * — en ese orden, que es el de Python y no el habitual HSL.
     *
     * @param  array{float,float,float}  $rgb
     * @return array{float,float,float}
     */
    private static function rgbAHls(array $rgb): array
    {
        [$r, $g, $b] = $rgb;
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $suma = $max + $min;
        $rango = $max - $min;
        $l = $suma / 2.0;

        if ($min === $max) {
            return [0.0, $l, 0.0];
        }

        $s = $l <= 0.5 ? $rango / $suma : $rango / (2.0 - $max - $min);

        $rc = ($max - $r) / $rango;
        $gc = ($max - $g) / $rango;
        $bc = ($max - $b) / $rango;

        if ($r === $max) {
            $h = $bc - $gc;
        } elseif ($g === $max) {
            $h = 2.0 + $rc - $bc;
        } else {
            $h = 4.0 + $gc - $rc;
        }

        return [self::modulo1($h / 6.0), $l, $s];
    }

    /**
     * Equivalente de `colorsys.hls_to_rgb`.
     *
     * @return array{float,float,float}
     */
    private static function hlsARgb(float $h, float $l, float $s): array
    {
        if ($s === 0.0) {
            return [$l, $l, $l];
        }

        $m2 = $l <= 0.5 ? $l * (1.0 + $s) : $l + $s - ($l * $s);
        $m1 = 2.0 * $l - $m2;

        return [
            self::canal($m1, $m2, $h + 1 / 3),
            self::canal($m1, $m2, $h),
            self::canal($m1, $m2, $h - 1 / 3),
        ];
    }

    private static function canal(float $m1, float $m2, float $matiz): float
    {
        $matiz = self::modulo1($matiz);

        if ($matiz < 1 / 6) {
            return $m1 + ($m2 - $m1) * $matiz * 6.0;
        }
        if ($matiz < 0.5) {
            return $m2;
        }
        if ($matiz < 2 / 3) {
            return $m1 + ($m2 - $m1) * (2 / 3 - $matiz) * 6.0;
        }

        return $m1;
    }

    /** `x % 1.0` con el signo de Python: siempre en [0, 1). */
    private static function modulo1(float $valor): float
    {
        $resto = fmod($valor, 1.0);

        return $resto < 0 ? $resto + 1.0 : $resto;
    }

    private static function luminancia(string $hex): float
    {
        [$r, $g, $b] = array_map(
            fn (float $c) => $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4,
            self::hexARgb($hex)
        );

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }
}
