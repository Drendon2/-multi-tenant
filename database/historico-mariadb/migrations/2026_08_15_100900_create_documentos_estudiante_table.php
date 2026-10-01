<?php

/**
 * El archivo que un estudiante subio para uno de los papeles pedidos.
 *
 * Vive aparte de `datos_estudiante.copia_documento` a proposito: aquella es la
 * cedula o el registro civil, que el sistema pide siempre y a la que el resto
 * del codigo ya se refiere por su nombre. Estos son los papeles VARIABLES de
 * cada institucion, y meterlos en la misma columna obligaria a migrar el
 * esquema cada vez que una entidad pida un papel mas.
 *
 * Un archivo por documento y estudiante: volver a subir REEMPLAZA, no acumula.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documentos_estudiante', function (Blueprint $table) {
            $table->id();
            $table->foreignId('datos_estudiante_id')->constrained('datos_estudiante')->cascadeOnDelete();
            $table->foreignId('requerido_id')->constrained('documentos_requeridos')->cascadeOnDelete();
            $table->string('archivo', 255);
            $table->dateTime('subido');
            $table->timestamps();

            $table->unique(
                ['datos_estudiante_id', 'requerido_id'],
                'un_archivo_por_documento_y_estudiante'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documentos_estudiante');
    }
};
