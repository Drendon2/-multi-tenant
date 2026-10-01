<?php

/**
 * Quien se inscribio a una actividad por su enlace.
 *
 * Estas personas NO tienen cuenta y NO tienen matricula, y ese es el punto: el
 * enlace se comparte por WhatsApp, se abre en el celular y se llena en veinte
 * segundos. Meterlas en `perfiles` habria significado crearles usuario y
 * contrasena a gente que a lo mejor viene a un taller de un dia.
 *
 * De ahi que los tamanos de columna copien a `perfiles` y a `datos_estudiante`:
 * son los mismos datos de la misma gente, escritos por otra puerta, y si algun
 * dia una de estas personas se matricula de verdad, lo que ya escribio tiene
 * que caber tal cual.
 *
 * `perfil_id` es el puente cuando SI tienen cuenta. Se rellena solo, al
 * inscribirse, si el documento coincide con el de un estudiante ya registrado.
 * Admite NULL porque la mayoria no lo sera, y va con NULL al borrar: que se
 * borre el perfil no borra el hecho de que esa persona fue al taller.
 *
 * `origen` separa a quien llego por el enlace de quien aparecio el dia de la
 * clase y lo anadio el responsable a mano. Del segundo solo se sabe el nombre
 * —nadie le va a pedir el documento con la clase empezando—, y por eso todo lo
 * demas admite NULL.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inscritos_actividad', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actividad_id')->constrained('actividades')->cascadeOnDelete();
            $table->string('nombre_completo', 90);
            $table->string('documento', 15)->nullable();
            $table->string('telefono', 15)->nullable();
            $table->string('correo', 120)->nullable();
            $table->date('fecha_nacimiento')->nullable();
            $table->foreignId('perfil_id')->nullable()->constrained('perfiles')->nullOnDelete();
            $table->string('origen', 12);
            $table->timestamps();

            // Una vez por actividad y documento. Es lo que impide que alguien se
            // apunte tres veces desde el enlace por no estar seguro de si el
            // primero entro, y lo que hace que "cuantos van" signifique algo.
            //
            // Los NULL no chocan entre si en MariaDB, y eso es justo lo que hace
            // falta: los que anade el responsable el dia de la clase no traen
            // documento, y son gente distinta con el mismo hueco.
            $table->unique(['actividad_id', 'documento'], 'una_inscripcion_por_documento');
        });

        DB::statement("
            ALTER TABLE inscritos_actividad
            ADD CONSTRAINT origen_de_inscrito_valido
            CHECK (origen IN ('enlace', 'en_sesion'))
        ");

        DB::statement("
            ALTER TABLE inscritos_actividad
            ADD CONSTRAINT nombre_de_inscrito_no_vacio
            CHECK (nombre_completo <> '')
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('inscritos_actividad');
    }
};
