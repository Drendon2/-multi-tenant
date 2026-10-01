<?php

/**
 * Cuantos estudiantes admite una promotoria en un periodo concreto.
 *
 * Va por periodo y no como columna de `promotorias` a proposito: al abrir
 * matriculas, quien dicta fija un cupo nuevo sin borrar el del periodo
 * anterior, asi el historico queda reconstruible.
 *
 * Una promotoria SIN fila aqui para el periodo NO tiene tope: es el estado por
 * defecto y no bloquea a nadie. Esa ausencia tambien significa que no hay fila
 * que bloquear, y por tanto el camino sin limite no paga el coste del cerrojo
 * (ver el trigger de cupo).
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cupos_promotoria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotoria_id')->constrained('promotorias')->cascadeOnDelete();
            $table->foreignId('periodo_id')->constrained('periodos')->cascadeOnDelete();
            $table->unsignedInteger('cupo_maximo');
            $table->timestamps();

            $table->unique(['promotoria_id', 'periodo_id'], 'un_cupo_por_promotoria_y_periodo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cupos_promotoria');
    }
};
