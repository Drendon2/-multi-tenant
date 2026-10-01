<?php

/**
 * Inscripcion de un estudiante en una PROMOTORIA, para un periodo dado.
 *
 * El estudiante no elige grupo/horario al matricularse: `grupo_id` queda en
 * blanco hasta que quien dicta divide a los matriculados segun su horario.
 *
 * Toda matricula nace 'pendiente'. Quien dicta la promotoria debe confirmarla
 * antes de que cuente como activa, y solo entonces se le puede asignar grupo.
 *
 * NO se borra nunca al terminar el periodo: el estudiante que se va queda como
 * 'retirada'. Por eso no hace falta ninguna tabla de historial — la trayectoria
 * completa ya esta aqui, cada fila con su periodo.
 *
 * 'finalizada' NO es un estado guardado y por eso no esta en el CHECK: es lo
 * que se MUESTRA de una matricula activa cuyo periodo ya quedo atras. Guardarlo
 * obligaria a migrar filas y romperia la renovacion, que busca precisamente
 * matriculas ACTIVAS de periodos anteriores.
 *
 * ---------------------------------------------------------------------------
 * TRAMPA (a) DE LA MIGRACION: el indice unico PARCIAL de ranura
 * ---------------------------------------------------------------------------
 * El original usa:
 *
 *     UNIQUE (estudiante, periodo, ranura) WHERE NOT (estado = 'retirada')
 *
 * Misma emulacion que en `periodos`: una columna generada que copia la ranura
 * mientras la matricula cuenta, y vale NULL cuando esta retirada. Como un
 * indice unico de MariaDB admite multiples NULL, las retiradas quedan fuera del
 * indice y LIBERAN su ranura, que es justo lo que hace la version de Postgres.
 *
 * Ojo con lo que este indice ya NO es: desde que el limite de promotorias es
 * configurable, el numero de ranuras del esquema (6) y el tope operativo
 * dejaron de coincidir. Quien impone el limite real es la validacion del
 * modelo. Este indice sigue siendo lo que impide duplicar una ranura en una
 * carrera entre dos peticiones simultaneas, y lo que acota el dano a 6 si algo
 * se salta la capa de aplicacion.
 *
 * ---------------------------------------------------------------------------
 * TRAMPA (c): el CHECK que no puede consultar otra tabla
 * ---------------------------------------------------------------------------
 * `ranura_valida` graba un techo FIJO de 6, que no es la regla de negocio. La
 * regla —cuantas promotorias puede cursar alguien a la vez— vive en
 * `configuracion_institucion` y se edita en caliente; ningun motor SQL permite
 * un CHECK que lea una fila de otra tabla, ni Postgres ni MariaDB. El 6 se
 * eligio holgado para que subir el limite operativo nunca exija una migracion.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matriculas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estudiante_id')->constrained('perfiles')->cascadeOnDelete();
            // RESTRICT: una promotoria con matriculas no se borra; borrarla se
            // llevaria el historial de quienes pasaron por ella.
            $table->foreignId('promotoria_id')->constrained('promotorias')->restrictOnDelete();
            $table->foreignId('grupo_id')->nullable()->constrained('grupos')->restrictOnDelete();
            $table->foreignId('periodo_id')->constrained('periodos')->restrictOnDelete();
            $table->dateTime('fecha');
            $table->string('estado', 24)->default('pendiente');
            // Cual de los cupos del estudiante ocupa esta matricula en el
            // periodo. La asigna el sistema; es lo que permite que la propia
            // base de datos impida que dos matriculas compartan cupo.
            $table->unsignedTinyInteger('ranura')->default(1);
            $table->timestamps();

            $table->unique(
                ['estudiante_id', 'promotoria_id', 'periodo_id'],
                'unica_matricula_por_periodo'
            );

            // Los listados del panel y del profesor filtran siempre por esta
            // combinacion.
            $table->index(['promotoria_id', 'periodo_id', 'estado']);
            $table->index(['grupo_id', 'periodo_id', 'estado']);
        });

        DB::statement('
            ALTER TABLE matriculas
            ADD CONSTRAINT ranura_valida
            CHECK (ranura BETWEEN 1 AND 6)
        ');

        DB::statement("
            ALTER TABLE matriculas
            ADD CONSTRAINT estado_valido
            CHECK (estado IN ('pendiente', 'activa', 'cancelacion_solicitada', 'retirada'))
        ");

        // Una cancelacion pedida y sin resolver NO libera nada, y sale gratis:
        // solo 'retirada' queda fuera del indice, asi que el estudiante sigue
        // ocupando su ranura mientras nadie apruebe la salida. Es lo correcto.
        DB::statement("
            ALTER TABLE matriculas
            ADD COLUMN ranura_activa TINYINT UNSIGNED
                GENERATED ALWAYS AS (IF(estado <> 'retirada', ranura, NULL)) VIRTUAL
        ");

        DB::statement('
            ALTER TABLE matriculas
            ADD UNIQUE INDEX una_matricula_por_ranura_y_periodo
                (estudiante_id, periodo_id, ranura_activa)
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('matriculas');
    }
};
