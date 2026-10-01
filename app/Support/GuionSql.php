<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Corre un guion de `database/sql/` sentencia a sentencia.
 *
 * Los guiones son la fuente de verdad: se pueden correr a mano con el cliente
 * de consola (`psql -f guion.sql`) y las migraciones de Laravel corren
 * EXACTAMENTE esos archivos, asi que no hay dos versiones que se separen.
 *
 * Entiende los bloques entre `$$` (el cuerpo de una funcion o un `DO`), que
 * llevan `;` por dentro: dentro de uno, un `;` al final de linea no cierra la
 * sentencia. Es lo que en MariaDB hacia `DELIMITER`.
 *
 * No se manda el archivo entero en un solo `unprepared()`: asi un fallo dice
 * en que sentencia fue, y no deja a medias un guion que se creia corrido.
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
        $actual = '';
        $dentroDeBloque = false;
        $sentencias = [];

        foreach (preg_split('/\R/', $sql) ?: [] as $linea) {
            $limpia = trim($linea);

            if ($actual === '' && ($limpia === '' || str_starts_with($limpia, '--'))) {
                continue;
            }

            $actual .= $linea."\n";

            // Un numero impar de `$$` en la linea abre o cierra un bloque.
            if (substr_count($linea, '$$') % 2 === 1) {
                $dentroDeBloque = ! $dentroDeBloque;
            }

            if (! $dentroDeBloque && str_ends_with($limpia, ';')) {
                $sentencias[] = trim(substr(rtrim($actual), 0, -1));
                $actual = '';
            }
        }

        if (trim($actual) !== '') {
            throw new RuntimeException('El guion termina con una sentencia sin cerrar.');
        }

        return $sentencias;
    }
}
