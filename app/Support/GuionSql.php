<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Corre un guion de `database/sql/` sentencia a sentencia.
 *
 * Los guiones son la fuente de verdad: se pueden correr a mano con el cliente
 * de consola (`mysql base < guion.sql`) y las migraciones de Laravel corren
 * EXACTAMENTE esos archivos, asi que no hay dos versiones que se separen.
 *
 * Entiende `DELIMITER`, igual que el cliente de consola, porque los bloques
 * `BEGIN NOT ATOMIC ... END` llevan `;` por dentro.
 *
 * No se manda el archivo entero en un solo `unprepared()`: con varias
 * sentencias en una llamada, PDO solo informa del error de la PRIMERA, y un
 * fallo en la veinte pasaria sin avisar.
 */
class GuionSql
{
    public static function correr(string $archivo): void
    {
        $ruta = database_path('sql/'.$archivo);

        if (! is_file($ruta)) {
            throw new RuntimeException("No existe el guion {$ruta}.");
        }

        foreach (self::sentencias((string) file_get_contents($ruta)) as $sentencia) {
            DB::unprepared($sentencia);
        }
    }

    /**
     * @return list<string>
     */
    public static function sentencias(string $sql): array
    {
        $delimitador = ';';
        $actual = '';
        $sentencias = [];

        foreach (preg_split('/\R/', $sql) ?: [] as $linea) {
            $limpia = trim($linea);

            if ($actual === '' && ($limpia === '' || str_starts_with($limpia, '--'))) {
                continue;
            }

            if (preg_match('/^DELIMITER\s+(\S+)$/i', $limpia, $m)) {
                $delimitador = $m[1];

                continue;
            }

            $actual .= $linea."\n";

            if (str_ends_with($limpia, $delimitador)) {
                $sentencias[] = trim(substr(rtrim($actual), 0, -strlen($delimitador)));
                $actual = '';
            }
        }

        if (trim($actual) !== '') {
            throw new RuntimeException('El guion termina con una sentencia sin cerrar.');
        }

        return $sentencias;
    }
}
