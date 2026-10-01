<?php

/**
 * Los grupos pasan a tener NOMBRE, y una promotoria pasa a admitir varios
 * grupos del mismo nivel.
 *
 * El modelo original daba por hecho una promotoria pequena: un grupo por nivel,
 * tres como mucho, y el nivel bastaba para nombrarlos. La realidad de la casa
 * es otra —una sola promotoria atiende a mucha gente, con varios grupos de
 * Basico, algunos de Intermedio y alguno de Avanzado—, asi que el nivel dejo de
 * identificar a nadie: con ocho grupos de Basico, «Basico» no dice cual.
 *
 * Dos cambios que van juntos y no se pueden separar:
 *
 * - Se AGREGA `nombre`, obligatorio, y pasa a ser lo que distingue un grupo de
 *   otro para quien lo mira.
 * - Se QUITA el unico (promotoria, nivel), que es justo lo que impedia el caso
 *   nuevo. Su papel —que dos grupos no se confundan— lo hereda el unico
 *   (promotoria, nombre), que es mas fiel a lo que se queria: lo que no puede
 *   repetirse dentro de una promotoria es el NOMBRE.
 *
 * A los grupos que ya existen se les pone de nombre su nivel. No es un relleno
 * de compromiso: hoy hay como mucho uno por nivel y es exactamente como los
 * llama la gente, asi que en produccion esto no cambia ni una etiqueta de sitio.
 *
 * TRAMPA, la misma que costo una vez en `encuestas_satisfaccion`: el orden de
 * los dos indices no es negociable. El unico (promotoria_id, nivel) es tambien
 * el indice que sostiene la clave foranea de `promotoria_id`, y MariaDB se niega
 * a dejarla sin ninguno ni un instante («Cannot drop index ...: needed in a
 * foreign key constraint», error 1553). Primero entra el relevo —que empieza
 * igual por `promotoria_id`, y por eso sirve— y despues se va el viejo.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Nace NULL porque MariaDB rechaza una columna obligatoria sin valor por
        // defecto en una tabla que ya tiene filas. Se rellena y se cierra a
        // continuacion.
        Schema::table('grupos', function (Blueprint $table) {
            $table->string('nombre', 60)->nullable()->after('promotoria_id');
        });

        DB::statement("
            UPDATE grupos
            SET nombre = CASE nivel
                WHEN 'basico' THEN 'Básico'
                WHEN 'intermedio' THEN 'Intermedio'
                WHEN 'avanzado' THEN 'Avanzado'
                ELSE nivel
            END
            WHERE nombre IS NULL
        ");

        Schema::table('grupos', function (Blueprint $table) {
            $table->string('nombre', 60)->nullable(false)->change();
        });

        // NOT NULL no basta: la cadena vacia pasa el filtro y deja un grupo sin
        // nombre, que es la situacion que este cambio venia a eliminar. La
        // aplicacion tambien lo valida —ahi el mensaje se puede leer—, pero esto
        // ataja lo que entre por otra via.
        DB::statement("
            ALTER TABLE grupos
            ADD CONSTRAINT nombre_no_vacio
            CHECK (nombre <> '')
        ");

        // El relevo primero (ver la TRAMPA de arriba).
        Schema::table('grupos', function (Blueprint $table) {
            $table->unique(['promotoria_id', 'nombre'], 'un_nombre_por_promotoria');
        });

        Schema::table('grupos', function (Blueprint $table) {
            $table->dropUnique('un_nivel_por_promotoria');
        });
    }

    public function down(): void
    {
        // Al reves, con el mismo cuidado y por el mismo motivo. Ojo: volver
        // atras solo es posible si no se ha creado ningun segundo grupo del
        // mismo nivel; si se creo, el unico (promotoria, nivel) ya no puede
        // existir y esta bajada falla — correctamente, porque los datos nuevos
        // no caben en el modelo viejo.
        Schema::table('grupos', function (Blueprint $table) {
            $table->unique(['promotoria_id', 'nivel'], 'un_nivel_por_promotoria');
        });

        Schema::table('grupos', function (Blueprint $table) {
            $table->dropUnique('un_nombre_por_promotoria');
        });

        DB::statement('ALTER TABLE grupos DROP CONSTRAINT nombre_no_vacio');

        Schema::table('grupos', function (Blueprint $table) {
            $table->dropColumn('nombre');
        });
    }
};
