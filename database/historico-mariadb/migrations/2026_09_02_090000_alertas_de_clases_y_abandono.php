<?php

/**
 * Las dos alertas de la bandeja: clases que no se dictaron y posibles abandonos.
 *
 * LO QUE NO SE GUARDA, que es casi todo. Ninguna de las dos alertas se almacena:
 * las dos se CALCULAN al abrir la pantalla, cruzando el horario del grupo con lo
 * que hay en `clases`, y las faltas seguidas con lo que hay en `asistencias`.
 * Una alerta guardada seria un dato que puede quedar viejo —el estudiante
 * vuelve, el profesor registra la clase tarde— y habria que decidir quien la
 * borra y cuando. Calculada, siempre dice la verdad de ahora.
 *
 * LO QUE SI HAY QUE GUARDAR es una sola cosa, y por un motivo concreto: una
 * clase que no se dio el 12 de marzo no se puede arreglar nunca. Esa alerta no
 * desaparece sola, asi que se puede ARCHIVAR —«ya hable con el profesor»— y ese
 * gesto es lo unico que esta tabla conserva. La otra alerta no la necesita: la
 * de abandono se va sola en cuanto el estudiante vuelve a aparecer o alguien
 * retira su matricula.
 *
 * La clave unica es (grupo, fecha) y no lleva id de clase, porque justamente lo
 * que se archiva es que NO hay clase. Con `ON DELETE CASCADE` en el grupo: si el
 * grupo se borra, sus omisiones archivadas dejan de significar nada.
 *
 * Y tres columnas en la configuracion. Los dos interruptores se piden en la
 * peticion original —cada institucion decide si quiere estos avisos—, y el
 * umbral va con ellos porque «mas de cuatro» es una politica y no una constante:
 * una promotoria que se ve una vez por semana y otra que se ve tres no aguantan
 * el mismo numero.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('omisiones_archivadas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grupo_id')->constrained('grupos')->cascadeOnDelete();
            $table->date('fecha');
            // Quien la archivo. `nullOnDelete` y no cascade: si esa persona se
            // va del sistema, la omision sigue archivada — lo que se guardo es
            // que alguien ya se ocupo, no quien.
            $table->foreignId('archivada_por_id')->nullable()
                ->constrained('perfiles')->nullOnDelete();
            $table->timestamps();

            $table->unique(['grupo_id', 'fecha'], 'omision_unica_por_grupo_y_fecha');
        });

        Schema::table('configuracion_institucion', function (Blueprint $table) {
            $table->boolean('alerta_clase_no_dictada')->default(true);
            $table->boolean('alerta_abandono')->default(true);
            // «Mas de cuatro clases seguidas» son cinco. Se deja editable
            // porque el numero justo depende de cada cuanto se ve el grupo.
            $table->unsignedTinyInteger('faltas_para_abandono')->default(5);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('omisiones_archivadas');

        Schema::table('configuracion_institucion', function (Blueprint $table) {
            $table->dropColumn([
                'alerta_clase_no_dictada',
                'alerta_abandono',
                'faltas_para_abandono',
            ]);
        });
    }
};
