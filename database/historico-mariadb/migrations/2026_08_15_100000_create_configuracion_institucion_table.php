<?php

/**
 * Ajustes de la institucion, editables sin tocar codigo (Gestion → Institucion).
 *
 * Fila unica de id fijo = 1: el sistema sirve a UNA institucion a la vez, pero
 * ninguno de estos datos deberia estar quemado en el codigo si se quiere
 * reinstalar para otra entidad sin tocar plantillas.
 *
 * Los tonos derivados del acento (hover, fondo suave) y el contraste WCAG NO se
 * guardan: se calculan a partir de `color_acento`. Ver el servicio de color en
 * la aplicacion, puerto de `acento_oscuro` / `acento_suave` / `contraste`.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuracion_institucion', function (Blueprint $table) {
            $table->id();
            $table->string('nombre_institucion', 80)->default('Casa de la Cultura');
            // Ruta del archivo, no el archivo. Vacio = se usa el logo que trae
            // el proyecto por defecto.
            $table->string('logo', 255)->default('');
            $table->char('color_acento', 7)->default('#0a7a59');
            $table->unsignedTinyInteger('limite_promotorias_por_periodo')->default(2);
            $table->boolean('promotorias_visibles_para_estudiantes')->default(true);
            $table->timestamps();
        });

        // El tope de 6 es el mismo RANURA_MAXIMA_ABSOLUTA que graba el CHECK de
        // `matriculas`: subir el limite operativo por encima del numero de
        // ranuras que admite el esquema dejaria matriculas imposibles de crear.
        DB::statement('
            ALTER TABLE configuracion_institucion
            ADD CONSTRAINT limite_promotorias_valido
            CHECK (limite_promotorias_por_periodo BETWEEN 1 AND 6)
        ');

        // El formato del color se valida tambien en la aplicacion, que es donde
        // se le puede dar un mensaje legible; esto ataja lo que entre por otra via.
        DB::statement("
            ALTER TABLE configuracion_institucion
            ADD CONSTRAINT color_acento_hex
            CHECK (color_acento REGEXP '^#[0-9a-fA-F]{6}$')
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('configuracion_institucion');
    }
};
