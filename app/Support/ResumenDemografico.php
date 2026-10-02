<?php

namespace App\Support;

use App\Models\EncuestaDemografica;

/**
 * La encuesta demografica de una institucion, CONTADA: cuantas personas por
 * opcion en cada pregunta. Nunca una respuesta suelta.
 *
 * Vive aqui y no en `EstadisticasController` desde el paso 5 (02/10/2026),
 * porque desde entonces la cuentan dos pantallas: Estadisticas, para una
 * institucion, y el panel de todas, que SUMA las de cada una (`sumar()`).
 * Contada en dos sitios, la misma pregunta acabaria con dos respuestas.
 *
 * La base es TODA la encuesta de la institucion, no la del periodo: la
 * encuesta es de la persona y se contesta una vez.
 */
final class ResumenDemografico
{
    /** Como se llama cada pregunta en pantalla y en las descargas. */
    public const PREGUNTAS = [
        'genero' => 'Género',
        'estrato' => 'Estrato',
        'nivel_educativo' => 'Nivel educativo',
        'ocupacion' => 'Ocupación',
        'zona' => 'Zona',
        'afiliacion_salud' => 'Afiliación a salud',
        'grupo_etnico' => 'Grupo étnico',
        'discapacidad' => 'Discapacidad',
        'victima_conflicto_armado' => 'Víctima del conflicto armado',
    ];

    /**
     * Cuantas encuestas hay por cada valor de cada campo, en la institucion
     * actual. (Era `EstadisticasController::conteo()` hasta el paso 5.)
     *
     * Cuenta sobre la coleccion que ya esta en memoria, no con un GROUP BY.
     *
     * Antes era una consulta por campo, y son nueve: la tabla entera se leia ya
     * de todas formas —`$encuestas` hace falta para contar las incompletas, que
     * no se puede resolver en SQL— y encima se recorria otras nueve veces en el
     * motor. Diez pasadas por la misma tabla para pintar una pantalla.
     *
     * La alternativa contraria tambien valia: dejar los GROUP BY y contar las
     * incompletas en SQL. Se eligio esta porque la de las incompletas parecia
     * la que no tenia una version buena en SQL, y hacer las dos cosas a la vez
     * es justo lo que estaba mal.
     *
     * De paso desaparece un `selectRaw` con el nombre de columna interpolado.
     *
     * ACTUALIZACION (C-02): aquella premisa era falsa. El motivo que se dio
     * --«`estrato` es entero y los demas texto»-- no se sostiene: los cinco
     * campos obligatorios son NOT NULL, asi que «sin responder» es la cadena
     * vacia, y sobre un entero la comprobacion de cadena vacia no dispara nunca
     * ni aqui ni antes. Las incompletas SI se cuentan en SQL, en
     * `encuestasIncompletas()`.
     *
     * Lo que NO cambio es esto: se sigue contando en memoria y no con nueve
     * GROUP BY, porque serian nueve recorridos de la tabla en vez de uno. Lo
     * que cambio es lo que se trae: filas planas con las nueve columnas que se
     * cuentan, en vez de la tabla entera hidratada en modelos. Nueve veces
     * menos memoria, medido sobre los 227 registros de desarrollo.
     *
     * Por eso el parametro es una coleccion de `stdClass` y no de modelos: son
     * las filas crudas de `toBase()`. `countBy` funciona igual sobre las dos.
     *
     * @return array{total: int, conteos: array<string, array<int|string, int>>}
     */
    public static function deLaInstitucion(): array
    {
        $encuestas = EncuestaDemografica::query()
            ->select(array_keys(EncuestaDemografica::OPCIONES))
            ->toBase()
            ->get();

        $conteos = [];

        foreach (array_keys(EncuestaDemografica::OPCIONES) as $campo) {
            $conteos[$campo] = $encuestas->countBy($campo)->all();
        }

        return ['total' => $encuestas->count(), 'conteos' => $conteos];
    }

    /**
     * Varias instituciones en una: suma opcion a opcion. Se puede porque son
     * CONTEOS de personas distintas, cada una en su casa; una media no se
     * sumaria asi.
     *
     * @param  iterable<array{total: int, conteos: array<string, array<int|string, int>>}>  $resumenes
     * @return array{total: int, conteos: array<string, array<int|string, int>>}
     */
    public static function sumar(iterable $resumenes): array
    {
        $total = 0;
        $conteos = array_fill_keys(array_keys(EncuestaDemografica::OPCIONES), []);

        foreach ($resumenes as $resumen) {
            $total += $resumen['total'];

            foreach ($resumen['conteos'] as $campo => $porOpcion) {
                foreach ($porOpcion as $opcion => $n) {
                    $conteos[$campo][$opcion] = ($conteos[$campo][$opcion] ?? 0) + $n;
                }
            }
        }

        return ['total' => $total, 'conteos' => $conteos];
    }

    /**
     * Lo que pintan las graficas de la encuesta, con las claves que esperan
     * las vistas.
     *
     * Genero y zona van en torta y no en barras: en las dos la pregunta es que
     * parte del total es cada opcion, y son pocas (4 y 3). El resto va en
     * barras, que es lo correcto para comparar magnitudes y para escalas con
     * orden propio como el estrato o el nivel educativo, donde una torta
     * obligaria a comparar angulos parecidos.
     *
     * @param  array{total: int, conteos: array<string, array<int|string, int>>}  $resumen
     * @return array<string, mixed>
     */
    public static function graficas(array $resumen): array
    {
        $total = $resumen['total'];
        $c = $resumen['conteos'];
        $barras = fn (string $campo, array $opciones) => Grafica::porOpcion($c[$campo] ?? [], $opciones, $total);

        return [
            // Sin `$total`: la torta pone su propio sector gris y contaria dos
            // veces esa fila.
            'generoTorta' => Grafica::torta(Grafica::porOpcion($c['genero'] ?? [], EncuestaDemografica::GENEROS), $total),
            'zonaTorta' => Grafica::torta(Grafica::porOpcion($c['zona'] ?? [], EncuestaDemografica::ZONAS), $total),
            'estratoStats' => $barras('estrato', array_map(fn ($e) => "Estrato {$e}", EncuestaDemografica::ESTRATOS)),
            'nivelEducativoStats' => $barras('nivel_educativo', EncuestaDemografica::NIVELES_EDUCATIVOS),
            'ocupacionStats' => $barras('ocupacion', EncuestaDemografica::OCUPACIONES),
            'afiliacionSaludStats' => $barras('afiliacion_salud', EncuestaDemografica::AFILIACIONES_SALUD),
            'grupoEtnicoStats' => $barras('grupo_etnico', EncuestaDemografica::GRUPOS_ETNICOS),
            'discapacidadStats' => $barras('discapacidad', EncuestaDemografica::DISCAPACIDADES),
            'victimaConflictoStats' => $barras('victima_conflicto_armado', EncuestaDemografica::VICTIMAS_CONFLICTO),
        ];
    }
}
