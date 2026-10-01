<?php

/**
 * Una sesion de clase concreta, registrada por quien la dicta al darla.
 *
 * No se programa por adelantado ni se deduce del `horario` del grupo: se oprime
 * "Iniciar clase" cuando la clase empieza y lo que queda guardado es la hora
 * REAL en que se oprimio. Esa es toda la diferencia entre el horario (lo que
 * deberia pasar cada semana) y esta tabla (lo que paso). `fecha_hora` NUNCA es
 * editable a mano.
 *
 * Va atada al grupo y no a la promotoria porque la lista que se pasa es la de
 * un horario concreto: dos grupos de la misma promotoria se ven en dias
 * distintos y cada uno tiene su propia asistencia.
 *
 * Guarda tambien el periodo, aunque se pueda deducir de la fecha: es lo que
 * permite reconstruir la asistencia de un periodo cerrado sin depender de
 * comparar fechas contra los periodos que existan en ese momento.
 *
 * `confirmaciones_requeridas` se CONGELA al abrir la clase y no se recalcula
 * despues. Describe el grupo tal como era el dia de la clase; si se
 * recalculara, una clase ya confirmada volveria a quedar en falta solo porque
 * despues entro gente nueva. Tres es el numero normal; en un grupo de uno o dos
 * basta una, porque el requisito tiene que ser alcanzable o deja de verificar
 * nada.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grupo_id')->constrained('grupos')->cascadeOnDelete();
            $table->foreignId('periodo_id')->constrained('periodos')->restrictOnDelete();
            $table->dateTime('fecha_hora');
            // Quien oprimio el boton. Queda en blanco si esa cuenta se elimina:
            // la clase se dio igual, y perder el registro entero por eso seria
            // peor que perder el nombre.
            $table->foreignId('registrada_por_id')->nullable()
                ->constrained('perfiles')->nullOnDelete();
            $table->unsignedTinyInteger('confirmaciones_requeridas')->default(3);
            $table->timestamps();

            $table->index(['grupo_id', 'periodo_id', 'fecha_hora']);
            $table->index(['registrada_por_id', 'periodo_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clases');
    }
};
