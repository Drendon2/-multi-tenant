<?php

/**
 * Retira `matriculas.grupo_id`. Es el ultimo de los cuatro pasos.
 *
 * ---------------------------------------------------------------------------
 * ESTE ES EL PASO QUE GARANTIZA QUE NO QUEDEN DOS VERDADES
 * ---------------------------------------------------------------------------
 * Mientras la columna existio, ella mandaba y `asignaciones_grupo` la seguia
 * con una escritura doble en `Matricula`. Eso hacia posible mover los lectores
 * uno a uno con la suite verde en medio, y a cambio dejaba dos sitios donde
 * mirar. Al quitarla, lo que siga leyendola se cae RUIDOSAMENTE — que es como
 * se quiere que falle.
 *
 * ---------------------------------------------------------------------------
 * SE VUELVE A VOLCAR ANTES DE BORRAR, Y NO ES POR PRUDENCIA
 * ---------------------------------------------------------------------------
 * Entre la migracion que creo la tabla y esta se siguieron asignando grupos, y
 * cada una de esas escrituras fue a la COLUMNA (la escritura doble las copiaba,
 * pero una fila escrita por fuera del modelo no pasa por ella). Sin este
 * volcado, esas asignaciones se irian con la columna sin que nada fallara: la
 * pantalla diria que esa gente no tiene grupo.
 *
 * Es la misma sentencia de la migracion que creo la tabla, escrita para poder
 * repetirse: `NOT EXISTS` mas el indice unico lo cubren por los dos lados.
 *
 * ---------------------------------------------------------------------------
 * EL ORDEN IMPORTA: indice, clave ajena, columna
 * ---------------------------------------------------------------------------
 * `matriculas_grupo_id_periodo_id_estado_index` empieza por `grupo_id`, y la
 * clave ajena cuelga de ella. MariaDB no deja soltar una columna con una FK
 * encima, y quitar el indice antes que la FK tampoco: la FK necesita un indice
 * donde apoyarse. Primero la FK, luego el indice, luego la columna.
 *
 * ---------------------------------------------------------------------------
 * LA VUELTA ATRAS DEVUELVE LA COLUMNA, PERO NO PUEDE DEVOLVERLO TODO
 * ---------------------------------------------------------------------------
 * `down()` recrea la columna y copia UN grupo por matricula, porque en una
 * columna no cabe mas. Quien este en dos se queda con uno. Se elige el de id
 * mas bajo —el primero en el que entro— y no uno cualquiera, para que revertir
 * dos veces de la misma base de el mismo resultado.
 *
 * Esto NO es una perdida de datos: `asignaciones_grupo` se queda intacta, con
 * los dos. Lo que se pierde es lo que la columna nunca pudo expresar.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('matriculas', 'grupo_id')) {
            return;
        }

        DB::statement('
            INSERT INTO asignaciones_grupo (matricula_id, grupo_id, created_at, updated_at)
            SELECT m.id, m.grupo_id, NOW(), NOW()
              FROM matriculas m
             WHERE m.grupo_id IS NOT NULL
               AND NOT EXISTS (
                   SELECT 1 FROM asignaciones_grupo a
                    WHERE a.matricula_id = m.id AND a.grupo_id = m.grupo_id
               )
        ');

        Schema::table('matriculas', function (Blueprint $tabla) {
            $tabla->dropForeign(['grupo_id']);
            $tabla->dropIndex('matriculas_grupo_id_periodo_id_estado_index');
            $tabla->dropColumn('grupo_id');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('matriculas', 'grupo_id')) {
            return;
        }

        Schema::table('matriculas', function (Blueprint $tabla) {
            $tabla->foreignId('grupo_id')->nullable()->after('promotoria_id')
                ->constrained('grupos')->restrictOnDelete();
            $tabla->index(['grupo_id', 'periodo_id', 'estado']);
        });

        // El de id mas bajo: el primero en el que entro. Con `MIN` la vuelta
        // atras es repetible, que es lo unico que se le puede pedir.
        DB::statement('
            UPDATE matriculas m
              JOIN (
                  SELECT matricula_id, MIN(grupo_id) AS grupo_id
                    FROM asignaciones_grupo
                   GROUP BY matricula_id
              ) a ON a.matricula_id = m.id
               SET m.grupo_id = a.grupo_id
        ');
    }
};
