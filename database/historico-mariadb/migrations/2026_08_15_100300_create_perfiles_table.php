<?php

/**
 * Perfil comun a TODOS los roles. Uno por cada cuenta, sin importar el rol.
 *
 * `rol` puede quedar VACIO, y no es un descuido: asi nace toda cuenta creada
 * por autorregistro de profesor, que no tiene acceso a nada hasta que un
 * director o administrador le asigna uno. La redireccion posterior al login
 * mira precisamente esto.
 *
 * `edad` y `es_menor` NO son columnas: se calculan de `fecha_nacimiento`.
 * Guardar la edad obligaria a recalcularla cada dia y a estar mal el resto del
 * tiempo.
 *
 * `foto_perfil` queda vacia tras el autorregistro: los formularios publicos sin
 * sesion no aceptan archivos, y la persona la sube despues desde Mi perfil. Es
 * una decision de seguridad del original — evita que cualquiera suba archivos
 * arbitrarios sin autenticarse.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('perfiles', function (Blueprint $table) {
            $table->id();
            // 1:1 con la cuenta. Al borrar la cuenta se lleva el perfil.
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('rol', 15)->default('');
            $table->string('nombre_completo', 90);
            $table->date('fecha_nacimiento');
            $table->string('telefono', 15);
            $table->string('foto_perfil', 255)->default('');
            $table->timestamps();

            // El panel y los listados ordenan y filtran por estas dos.
            $table->index('rol');
            $table->index('nombre_completo');
        });

        // Los cuatro roles del sistema, mas el vacio de la cuenta sin asignar.
        DB::statement("
            ALTER TABLE perfiles
            ADD CONSTRAINT rol_valido
            CHECK (rol IN ('administrador', 'director', 'profesor', 'estudiante', ''))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('perfiles');
    }
};
