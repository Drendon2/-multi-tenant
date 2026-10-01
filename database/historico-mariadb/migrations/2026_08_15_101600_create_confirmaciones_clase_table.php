<?php

/**
 * Un estudiante da fe, desde su propia sesion, de que la clase se dio.
 *
 * Es el contrapeso del boton de quien dicta: quien registra la clase es parte
 * interesada, asi que el registro por si solo no prueba nada. La clase se tiene
 * por dictada cuando la confirman suficientes estudiantes.
 *
 * Confirma cualquier estudiante inscrito en el grupo, no solo aquel a quien
 * marcaron presente: lo que se verifica es que la clase EXISTIO, y hacerlo
 * depender de la asistencia que marca la propia persona verificada dejaria la
 * verificacion en sus manos.
 *
 * Se puede retirar. Una confirmacion es una afirmacion de alguien sobre lo que
 * vio, y quien se equivoco de renglon tiene que poder deshacerlo; dejar la
 * marca fija por miedo a que alguien juegue con ella tendria el precio de
 * volver permanente justo el error que este registro existe para evitar. El
 * plazo (48 h) es el mismo para poner y para quitar, y se calcula al leer — no
 * hay tarea programada que cierre nada.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('confirmaciones_clase', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clase_id')->constrained('clases')->cascadeOnDelete();
            $table->foreignId('matricula_id')->constrained('matriculas')->cascadeOnDelete();
            $table->dateTime('fecha');
            $table->timestamps();

            $table->unique(['clase_id', 'matricula_id'], 'una_confirmacion_por_clase_y_estudiante');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('confirmaciones_clase');
    }
};
