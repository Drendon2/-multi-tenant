<?php

/**
 * Responsable de un estudiante menor de edad.
 *
 * Tabla propia y no unas columnas en `datos_estudiante` porque un mismo
 * acudiente responde a menudo por varios hermanos.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acudientes', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 90);
            $table->string('telefono', 15);
            $table->boolean('autoriza_tratamiento_datos')->default(false);
            $table->dateTime('fecha_autorizacion')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acudientes');
    }
};
