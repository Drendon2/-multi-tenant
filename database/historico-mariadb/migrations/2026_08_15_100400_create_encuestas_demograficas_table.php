<?php

/**
 * Encuesta demografica: obligatoria para todos los usuarios, con los campos
 * SENSIBLES opcionales.
 *
 * Salvo `barrio`, todo se responde eligiendo de una lista cerrada. Lo que se
 * guarda es el CODIGO corto y estable ("f", "secundaria_com"), no la etiqueta,
 * asi que renombrar un texto en pantalla no obliga a migrar datos. Las listas
 * completas viven en la aplicacion (enum/constantes del modelo), copiadas del
 * original.
 *
 * `barrio` es texto libre a proposito: una lista fija de barrios y veredas
 * ataria el proyecto a un municipio concreto, y la idea es poder reinstalarlo
 * en otra institucion sin tocar codigo.
 *
 * Los opcionales van NOT NULL con default '' y no NULL, igual que el original
 * (Django `blank=True` sobre CharField). Importa: el calculo de "preguntas que
 * faltan" compara contra vacio, y mezclar NULL con '' daria dos maneras
 * distintas de estar sin contestar.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('encuestas_demograficas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('perfil_id')->unique()->constrained('perfiles')->cascadeOnDelete();

            // Obligatorias.
            $table->string('genero', 2);
            $table->string('barrio', 60);
            $table->unsignedTinyInteger('estrato');
            $table->string('nivel_educativo', 20);
            $table->string('ocupacion', 20);

            // Opcionales, y no por sensibles: son datos que mucha gente
            // sencillamente no sabe de si misma al inscribirse —sobre todo el
            // regimen de salud— y obligar a contestarlos solo produciria
            // respuestas inventadas.
            $table->string('zona', 20)->default('');
            $table->string('afiliacion_salud', 20)->default('');

            // Datos sensibles (Ley 1581): opcionales aunque la encuesta sea
            // obligatoria. La condicion de victima va aparte porque, ademas de
            // sensible por el mismo criterio, tiene deber de reserva propio
            // (Ley 1448).
            $table->string('grupo_etnico', 20)->default('');
            $table->string('discapacidad', 20)->default('');
            $table->string('victima_conflicto_armado', 20)->default('');

            // Autorizacion de tratamiento de datos. Para menores la otorga el
            // acudiente. NO cuenta como pregunta obligatoria: es un booleano y
            // "no autorizo" es una respuesta legitima, no una casilla en blanco.
            $table->boolean('autoriza_tratamiento_datos')->default(false);
            $table->dateTime('fecha_autorizacion')->nullable();

            $table->timestamps();
        });

        DB::statement('
            ALTER TABLE encuestas_demograficas
            ADD CONSTRAINT estrato_valido
            CHECK (estrato BETWEEN 1 AND 6)
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('encuestas_demograficas');
    }
};
