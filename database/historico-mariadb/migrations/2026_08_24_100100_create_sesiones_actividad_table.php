<?php

/**
 * Los dias de una actividad: una fila por clase, taller o ensayo.
 *
 * De donde sale la fecha es lo unico que separa a los tres tipos:
 *
 * - CURSO y TALLER  las traen decididas. Se escriben en la pantalla de fechas
 *                   justo despues de crear la actividad, y se pueden cambiar
 *                   despues mientras nadie haya pasado lista.
 * - PROYECCION      no las tiene. La fila nace al oprimir "Iniciar ensayo", con
 *                   la fecha de ese dia.
 *
 * `iniciada_en` es lo que separa "esta clase esta prevista" de "esta clase se
 * dio". Es la misma distincion que hace `clases.fecha_hora` en el lado de las
 * promotorias, pero al reves de aquella: alli la fila NACE al oprimir el boton
 * y por eso la hora es la real; aqui la fila puede existir desde semanas antes,
 * asi que hace falta una segunda columna que diga cuando se oprimio de verdad.
 *
 * Un solo encuentro por dia y actividad. Es el mismo limite que ya se tomo en
 * `sesiones_grupo` y por la misma razon: nadie da la misma clase dos veces el
 * mismo dia, y a cambio la pantalla de fechas se queda en una rejilla sin
 * botones de "anadir" ni JavaScript que mantener. Ademas ataja el descuido de
 * escribir dos veces la misma fecha en esa rejilla, que si es facil.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sesiones_actividad', function (Blueprint $table) {
            $table->id();
            // CASCADE: una sesion sin su actividad no es nada. Es lo contrario
            // de una matricula, que sobrevive a todo porque es historial.
            $table->foreignId('actividad_id')->constrained('actividades')->cascadeOnDelete();
            $table->date('fecha');
            // Cuando se oprimio "Iniciar", o NULL si todavia no ha pasado. La
            // ausencia es informacion: una clase prevista que nadie inicio.
            $table->dateTime('iniciada_en')->nullable();
            // Quien la inicio. NULL si aun no empieza, y tambien si ese perfil
            // se borro despues: la sesion se dio igual, y perder el nombre de
            // quien la abrio no es motivo para perder la asistencia.
            $table->foreignId('iniciada_por_id')->nullable()->constrained('perfiles')->nullOnDelete();
            $table->timestamps();

            $table->unique(['actividad_id', 'fecha'], 'un_encuentro_por_dia');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sesiones_actividad');
    }
};
