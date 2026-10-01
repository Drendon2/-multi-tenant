<?php

/**
 * Como le fue a UN estudiante en UNA clase: vino, falto, o falto con excusa.
 *
 * Las tres opciones son excluyentes y NO hay un cuarto estado "sin marcar": eso
 * se representa por la AUSENCIA de fila. Que no exista la fila es informacion
 * real —la clase se registro y a esa persona nadie la paso, por ejemplo porque
 * llego tarde y se cerro antes—, y convertirlo en un valor guardado haria
 * imposible distinguirlo de una respuesta deliberada.
 *
 * Va contra la MATRICULA y no contra el perfil: es lo que ata la asistencia a
 * la promotoria y el periodo concretos en los que se dio la clase. El mismo
 * estudiante puede estar en dos promotorias a la vez, y sus faltas de una no
 * son las de la otra.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asistencias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clase_id')->constrained('clases')->cascadeOnDelete();
            $table->foreignId('matricula_id')->constrained('matriculas')->cascadeOnDelete();
            $table->string('estado', 10);
            $table->dateTime('fecha_registro');
            $table->timestamps();

            $table->unique(['clase_id', 'matricula_id'], 'una_asistencia_por_clase_y_matricula');
        });

        DB::statement("
            ALTER TABLE asistencias
            ADD CONSTRAINT estado_asistencia_valido
            CHECK (estado IN ('asistio', 'falto', 'excusa'))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('asistencias');
    }
};
