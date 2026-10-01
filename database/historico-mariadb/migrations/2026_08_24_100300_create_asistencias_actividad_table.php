<?php

/**
 * Quien vino a que sesion de una actividad.
 *
 * Es la hermana de `asistencias`, y esta aparte por lo mismo que todo este
 * lado: aquella cuelga de una MATRICULA —que ata la asistencia a la promotoria
 * y el periodo en los que se dio la clase— y aqui no hay matricula ninguna. Lo
 * que hay es un inscrito, que puede no tener ni cuenta.
 *
 * Las tres opciones y la ausencia de fila significan exactamente lo mismo que
 * en `asistencias`, y esa lectura es deliberada: "sin marcar" NO es un cuarto
 * estado, es que no hay fila. Que no la haya es informacion real —la sesion se
 * dio y a esa persona nadie la paso— y convertirlo en un valor guardado haria
 * imposible distinguirlo de una respuesta deliberada.
 *
 * Lo que esta tabla NO tiene, y `asistencias` si: la confirmacion de los
 * estudiantes. Alli una clase la dan por dictada tres de los que estuvieron, y
 * esa es la garantia de que el registro dice la verdad. Aqui los inscritos no
 * tienen cuenta con la que confirmar nada, asi que la unica firma es la de
 * quien oprimio "Iniciar" —y por eso pasar lista esta reservado a esa persona.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asistencias_actividad', function (Blueprint $table) {
            $table->id();
            // CASCADE en los dos lados: esto no es historial academico de
            // nadie. Si se borra la actividad entera, su asistencia se va con
            // ella; si se borra un inscrito, sus marcas tambien.
            $table->foreignId('sesion_id')->constrained('sesiones_actividad')->cascadeOnDelete();
            $table->foreignId('inscrito_id')->constrained('inscritos_actividad')->cascadeOnDelete();
            $table->string('estado', 12);
            $table->dateTime('fecha_registro');
            $table->timestamps();

            // Una marca por persona y sesion. Volver a guardar la hoja corrige
            // la que hay, no acumula una segunda.
            $table->unique(['sesion_id', 'inscrito_id'], 'una_marca_por_sesion');
        });

        DB::statement("
            ALTER TABLE asistencias_actividad
            ADD CONSTRAINT estado_de_asistencia_actividad_valido
            CHECK (estado IN ('asistio', 'falto', 'excusa'))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('asistencias_actividad');
    }
};
