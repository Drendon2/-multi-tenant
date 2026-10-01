<?php

/**
 * El horario de un grupo, en dato y no en texto.
 *
 * Hasta ahora `grupos.horario` era un varchar libre —«Martes 4-6 p. m.»— y eso
 * bastaba mientras solo hubiera que PINTARLO. Deja de bastar en cuanto hay que
 * razonar con el: una rejilla semanal en el perfil, saber si dos grupos se
 * cruzan, o simplemente ordenar por hora, obligan a adivinar dia y hora leyendo
 * una cadena que cada quien escribe como quiere. La mitad de los grupos decia
 * «4-6 p. m.» y la otra mitad «4:00-6:00 p. m.».
 *
 * Una fila por SESION: el grupo que se reune martes y jueves tiene dos.
 *
 * Un solo encuentro por dia y grupo (el unico de abajo). No es una limitacion
 * que duela —nadie da la misma clase dos veces el mismo dia— y a cambio deja el
 * formulario en una rejilla de seis filas, una por dia, sin botones de «anadir»
 * ni JavaScript que mantener.
 *
 * De lunes a SABADO, no a viernes: la casa tiene grupos de sabado por la manana
 * y dejarlos fuera del calendario los habria dejado fuera del sistema.
 *
 * La columna `grupos.horario` se va en la migracion siguiente, cuando lo que
 * habia en texto ya este convertido: el texto pasa a DERIVARSE de estas filas,
 * asi que guardar las dos cosas seria guardar dos veces la misma verdad y
 * dejarlas discrepar con el primer descuido.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sesiones_grupo', function (Blueprint $table) {
            $table->id();
            // CASCADE: una sesion sin grupo no es nada. Es lo contrario de la
            // matricula, que apunta al grupo con RESTRICT porque si significa
            // algo por si sola.
            $table->foreignId('grupo_id')->constrained('grupos')->cascadeOnDelete();
            // 1 = lunes ... 6 = sabado. El mismo orden que ISO-8601, para que
            // ordenar por este numero sea ordenar por la semana.
            $table->unsignedTinyInteger('dia');
            $table->time('hora_inicio');
            $table->time('hora_fin');
            $table->timestamps();

            $table->unique(['grupo_id', 'dia'], 'una_sesion_por_dia_y_grupo');
            // La rejilla del perfil pide las sesiones de un puñado de grupos
            // ordenadas por dia y hora; este indice es exactamente esa consulta.
            $table->index(['dia', 'hora_inicio']);
        });

        DB::statement('
            ALTER TABLE sesiones_grupo
            ADD CONSTRAINT dia_valido
            CHECK (dia BETWEEN 1 AND 6)
        ');

        // Una clase que termina antes de empezar no es un error de quien la
        // escribe, es una fila que rompe cualquier calculo de duracion y
        // cualquier deteccion de cruces. Se ataja en el motor.
        DB::statement('
            ALTER TABLE sesiones_grupo
            ADD CONSTRAINT hora_fin_posterior
            CHECK (hora_fin > hora_inicio)
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('sesiones_grupo');
    }
};
