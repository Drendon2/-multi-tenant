<?php

/**
 * Una promotoria (ej. Violin). La dicta una sola persona, o nadie todavia.
 *
 * Quien la dicta NO tiene por que tener el rol "profesor": un director de
 * escuela que ademas da su propia promotoria es un caso real, y con el rol como
 * unico criterio no podria ni quedar asignado aqui ni pasar lista en su propio
 * grupo. Lo que manda es este vinculo, no el rol. Los estudiantes si quedan
 * fuera — el filtro es "roles del personal", y se aplica en la aplicacion.
 *
 * `area_id` con RESTRICT: borrar un departamento que todavia tiene promotorias
 * dejaria el catalogo colgando.
 * `profesor_id` con RESTRICT: borrar el perfil de quien dicta borraria de paso
 * la promotoria entera si fuera CASCADE. Se desasigna primero, a mano.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotorias', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 60);
            $table->foreignId('area_id')->constrained('areas')->restrictOnDelete();
            $table->foreignId('profesor_id')->nullable()->constrained('perfiles')->restrictOnDelete();
            $table->timestamps();

            $table->index('nombre');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotorias');
    }
};
