<?php

/**
 * Datos que solo tienen los usuarios con rol 'estudiante'.
 *
 * `copia_documento` es la cedula o el registro civil, y SOLO la puede ver el
 * administrador — el control es de la capa de permisos, no del esquema. Queda
 * vacia tras la inscripcion publica, se sube despues desde Mi perfil, y su
 * ausencia NO impide que el profesor confirme la matricula.
 *
 * `acudiente_id` va con SET NULL y no CASCADE: borrar a un acudiente no puede
 * llevarse por delante el expediente del estudiante.
 *
 * REGLA DE NEGOCIO que el esquema NO impone: un estudiante menor de edad debe
 * tener acudiente. No se puede grabar aqui porque la minoria de edad se deduce
 * de `perfiles.fecha_nacimiento` —otra tabla— y ningun CHECK de SQL puede
 * consultar otra tabla. Vive en la validacion del modelo, igual que en el
 * original.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('datos_estudiante', function (Blueprint $table) {
            $table->id();
            $table->foreignId('perfil_id')->unique()->constrained('perfiles')->cascadeOnDelete();
            $table->string('documento_identidad', 15)->unique();
            $table->string('copia_documento', 255)->default('');
            $table->foreignId('acudiente_id')->nullable()->constrained('acudientes')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('datos_estudiante');
    }
};
