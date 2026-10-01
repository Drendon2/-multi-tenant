<?php

namespace App\Support;

use Collator;
use Illuminate\Support\Str;

/**
 * Como se desempatan por NOMBRE las listas que se ordenan en PHP.
 *
 * Existe por el paso a PostgreSQL (01/10/2026). Varias listas se ordenan por
 * una cifra o una fecha y despues se RECORTAN —la portada enseña tres alertas,
 * Estadisticas los diez primeros—, y con un empate en el corte cada motor
 * dejaba pasar a una fila distinta, porque el orden de los empates era el que
 * trajera la consulta. Decision del usuario: entre empatados, por nombre.
 *
 * Compara como la base: sin distinguir mayusculas ni tildes (el cotejo
 * `insensible` es ICU de fuerza primaria). Con `intl`, con ese mismo cotejo;
 * sin `intl` —que este proyecto no exige—, quitando tildes y mayusculas a mano,
 * que da el mismo orden en todo nombre corriente.
 */
class OrdenPorNombre
{
    private static ?Collator $cotejo = null;

    /** Negativo si `$a` va antes que `$b`, cero si son el mismo nombre. */
    public static function comparar(string $a, string $b): int
    {
        if (class_exists(Collator::class)) {
            self::$cotejo ??= self::cotejo();

            return (int) self::$cotejo->compare($a, $b);
        }

        return strcmp(Str::lower(Str::ascii($a)), Str::lower(Str::ascii($b)));
    }

    private static function cotejo(): Collator
    {
        $cotejo = new Collator('root');
        $cotejo->setStrength(Collator::PRIMARY);

        return $cotejo;
    }
}
