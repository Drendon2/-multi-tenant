<?php

/**
 * Encuesta que llena un estudiante ANTIGUO al renovar, sobre el periodo cursado.
 *
 * Es distinta de la demografica: aquella describe a la persona y se llena una
 * sola vez; esta evalua un periodo concreto y se repite cada vez que el
 * estudiante renueva. Por eso va atada a (perfil, periodo) y no es 1:1 con el
 * perfil.
 *
 * `periodo_id` es el periodo que se EVALUA, no aquel al que se renueva. Va con
 * RESTRICT: si la encuesta perdiera su periodo, dejaria de significar nada.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('encuestas_satisfaccion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('perfil_id')->constrained('perfiles')->cascadeOnDelete();
            $table->foreignId('periodo_id')->constrained('periodos')->restrictOnDelete();
            $table->unsignedTinyInteger('satisfaccion_general');
            $table->unsignedTinyInteger('calificacion_profesor');
            $table->boolean('horario_funciono');
            $table->boolean('recomendaria');
            // El unico campo opcional: es el tramite que acompana al boton de
            // renovar, no un estudio.
            $table->text('comentario')->nullable();
            $table->dateTime('fecha');
            $table->timestamps();

            $table->unique(['perfil_id', 'periodo_id'], 'una_encuesta_satisfaccion_por_periodo');
        });

        DB::statement('
            ALTER TABLE encuestas_satisfaccion
            ADD CONSTRAINT escalas_validas
            CHECK (satisfaccion_general BETWEEN 1 AND 5
                   AND calificacion_profesor BETWEEN 1 AND 5)
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('encuestas_satisfaccion');
    }
};
