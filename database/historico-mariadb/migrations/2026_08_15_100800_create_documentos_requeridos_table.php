<?php

/**
 * Un papel que ESTA institucion exige para dar por valida una matricula.
 *
 * Que papeles hacen falta cambia de una entidad a otra —una pide certificado de
 * EPS, otra el recibo de servicios, otra nada—, asi que la lista es un registro
 * editable desde Gestion y no una constante del codigo.
 *
 * Se DESACTIVA (`activo = false`) en vez de borrarse cuando deja de pedirse:
 * los archivos que ya subieron los estudiantes cuelgan de aqui, y borrar el
 * requisito se los llevaria por delante junto con la prueba de que en su
 * momento cumplieron.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documentos_requeridos', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 60)->unique('un_documento_por_nombre');
            $table->string('descripcion', 200)->default('');
            // Si no es obligatorio se pide igual, pero su ausencia no marca la
            // matricula como incompleta.
            $table->boolean('obligatorio')->default(true);
            $table->boolean('activo')->default(true);
            // Menor primero. Con el mismo numero manda el nombre.
            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamps();

            $table->index(['orden', 'nombre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documentos_requeridos');
    }
};
