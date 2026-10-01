<?php

/**
 * Borra de la base lo que quedo de los tipos de promotoria.
 *
 * Los dos commits que los trajeron (`87f2b88`, `f418c42`) se revirtieron: la
 * idea era buena y la forma equivocada, y ahora un curso, un taller o un grupo
 * de proyeccion son ACTIVIDADES, con su tabla, su enlace y su asistencia. Pero
 * revertir el codigo no deshace lo que ya corrio en un servidor.
 *
 * El resultado era el peor de los dos mundos: una instalacion NUEVA y la que
 * esta en produccion salian del mismo codigo con esquemas distintos. Produccion
 * arrastra `promotorias.tipo` y una `matriculas.ranura` que admite NULL; una
 * recien instalada no tiene ni lo uno ni lo otro.
 *
 * Y la de la ranura no es cosmetica. Lo que impide que un estudiante se pase
 * del limite de promotorias es el indice unico sobre `ranura_activa`, que se
 * calcula desde `ranura`. Con la columna obligatoria, toda matricula viva ocupa
 * un numero y el indice las cuenta; admitiendo NULL, una fila sin ranura se
 * escapa del indice. Hoy no hay codigo que escriba ese NULL —se fue con el
 * revert—, asi que es una puerta abierta y no un agujero en uso. Se cierra
 * igual: las puertas abiertas se cierran cuando se ven, no cuando alguien pasa.
 *
 * TODO lo de aqui va detras de una comprobacion de que exista lo que se va a
 * quitar. En una instalacion nueva esta migracion corre igual que las demas y
 * no encuentra nada que hacer, que es exactamente lo que tiene que pasar.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** El techo que graba el CHECK `ranura_valida`, y el que se puede repartir. */
    private const RANURA_MAXIMA = 6;

    public function up(): void
    {
        $this->devolverLasRanuras();
        $this->quitarLaColumnaTipo();
    }

    /**
     * `ranura` vuelve a ser obligatoria.
     *
     * Antes hay que darle una a las que no la tengan: con la columna NOT NULL
     * otra vez, una fila a NULL no cabe y el ALTER fallaria a mitad del
     * despliegue. Se les da la primera libre de su periodo, que es la que
     * habrian tenido de no existir los tipos.
     *
     * En produccion no deberia haber ninguna —las 25 promotorias quedaron como
     * `programa`, que es el unico que gastaba ranura—, pero "no deberia" no es
     * una garantia que se pueda comprobar desde aqui.
     */
    private function devolverLasRanuras(): void
    {
        if ($this->ranuraYaEsObligatoria()) {
            return;
        }

        foreach (DB::table('matriculas')->whereNull('ranura')->get() as $fila) {
            $ocupadas = DB::table('matriculas')
                ->where('estudiante_id', $fila->estudiante_id)
                ->where('periodo_id', $fila->periodo_id)
                ->where('estado', '!=', 'retirada')
                ->whereNotNull('ranura')
                ->pluck('ranura')
                ->map(fn ($r) => (int) $r)
                ->all();

            for ($ranura = 1; $ranura <= self::RANURA_MAXIMA; $ranura++) {
                if (! in_array($ranura, $ocupadas, true)) {
                    DB::table('matriculas')->where('id', $fila->id)->update(['ranura' => $ranura]);

                    break;
                }
            }
        }

        // Si alguna se quedo sin sitio, se para AQUI y con un mensaje que se
        // entiende. El ALTER de abajo fallaria igual, pero con un error del
        // motor a mitad del despliegue y sin decir de quien es la fila.
        $sinSitio = DB::table('matriculas')->whereNull('ranura')->count();

        if ($sinSitio > 0) {
            throw new RuntimeException(
                "Hay {$sinSitio} matricula(s) sin ranura y sin ninguna libre en su periodo. "
                .'Esos datos no caben en el modelo sin tipos: retira alguna de esas matriculas '
                .'antes de volver a desplegar.'
            );
        }

        // A mano y no con `Schema::table()->change()`: `ranura_activa` es una
        // columna GENERADA a partir de esta, y el `change()` de Laravel
        // reescribe la definicion con lo que cree que sabe de ella. En una tabla
        // con una generada encima, eso es pedir problemas. Es la misma cautela
        // que ya estaba escrita en la migracion que la hizo opcional.
        DB::statement("ALTER TABLE matriculas MODIFY ranura TINYINT UNSIGNED NOT NULL DEFAULT '1'");
    }

    private function quitarLaColumnaTipo(): void
    {
        if (! Schema::hasColumn('promotorias', 'tipo')) {
            return;
        }

        // El CHECK primero: una columna con una restriccion encima no se va.
        DB::statement('ALTER TABLE promotorias DROP CONSTRAINT IF EXISTS tipo_valido');

        Schema::table('promotorias', function (Blueprint $table) {
            $table->dropColumn('tipo');
        });
    }

    private function ranuraYaEsObligatoria(): bool
    {
        $columna = collect(DB::select('SHOW COLUMNS FROM matriculas'))
            ->firstWhere('Field', 'ranura');

        return $columna !== null && $columna->Null === 'NO';
    }

    /**
     * Deshacer esto NO devuelve los tipos de promotoria.
     *
     * El codigo que los leia ya no existe: se fue con el revert. Lo que hace
     * este `down()` es dejar la BASE como estaba, y solo sirve para
     * desatascar un despliegue que haya que echar atras entero. La columna
     * vuelve con su defecto y su CHECK, escritos a mano porque las constantes
     * de las que salian tampoco existen ya.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('promotorias', 'tipo')) {
            Schema::table('promotorias', function (Blueprint $table) {
                $table->string('tipo', 20)->default('programa')->after('area_id');
            });

            DB::statement("
                ALTER TABLE promotorias
                ADD CONSTRAINT tipo_valido
                CHECK (tipo IN ('taller', 'curso', 'programa', 'proyeccion'))
            ");
        }

        if ($this->ranuraYaEsObligatoria()) {
            DB::statement('ALTER TABLE matriculas MODIFY ranura TINYINT UNSIGNED NULL DEFAULT NULL');
        }
    }
};
