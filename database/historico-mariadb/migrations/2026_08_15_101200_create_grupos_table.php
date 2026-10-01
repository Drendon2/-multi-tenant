<?php

/**
 * Promotoria + nivel: el horario concreto que crea quien la dicta.
 *
 * El estudiante NO elige grupo al matricularse. Quien dicta crea los grupos
 * segun su disponibilidad y reparte ahi a los que ya estan matriculados en la
 * promotoria.
 *
 * Un nivel por promotoria: no puede haber dos "Basico" de Violin. Si hicieran
 * falta dos horarios del mismo nivel, eso es un cambio de modelo, no un dato.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grupos', function (Blueprint $table) {
            $table->id();
            // Al borrar la promotoria se van sus grupos: un grupo sin
            // promotoria no es nada.
            $table->foreignId('promotoria_id')->constrained('promotorias')->cascadeOnDelete();
            $table->string('nivel', 12);
            $table->string('horario', 60);
            $table->string('salon', 40);
            $table->unsignedInteger('cupo_maximo');
            $table->timestamps();

            $table->unique(['promotoria_id', 'nivel'], 'un_nivel_por_promotoria');
        });

        DB::statement("
            ALTER TABLE grupos
            ADD CONSTRAINT nivel_valido
            CHECK (nivel IN ('basico', 'intermedio', 'avanzado'))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('grupos');
    }
};
