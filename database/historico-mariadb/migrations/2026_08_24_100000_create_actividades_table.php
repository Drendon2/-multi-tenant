<?php

/**
 * Actividades: cursos, talleres y grupos de proyeccion.
 *
 * No son promotorias, y esta tabla no conoce la matricula. Una actividad se
 * crea desde Gestion, se le pone un responsable, y a partir de ahi vive de un
 * ENLACE que se comparte: quien lo abre se inscribe directamente ahi, sin
 * cuenta y sin matricula. Esa es toda la diferencia con el catalogo academico,
 * y es la razon de que no cuelgue de `promotorias`: lo que da acceso a una
 * promotoria es una matricula confirmada por alguien; lo que da acceso a esto
 * es tener el enlace.
 *
 * Los tres tipos comparten responsable, enlace, cupo, inscritos y asistencia.
 * Lo unico que cambia entre ellos es de donde salen las fechas:
 *
 * - TALLER      un solo dia, con su fecha.
 * - CURSO       varios dias, con sus fechas, decididas al crearlo.
 * - PROYECCION  sin fechas: se ensaya cuando toque y la sesion se crea al
 *               oprimir "Iniciar ensayo".
 *
 * Por eso van en UNA tabla y no en tres: separarlas seria repetir cuatro
 * columnas y dos pantallas para no repetir una. En Gestion si se ven por
 * separado —cursos y talleres en un boton, proyeccion en otro—, que es como se
 * piensan.
 *
 * `periodo_id` admite NULL a proposito: se puede montar un taller sin haber
 * puesto ningun periodo en curso, y esa es la primera cosa que hace quien
 * estrena el sistema. Cuando lo hay, queda anotado.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('actividades', function (Blueprint $table) {
            $table->id();
            $table->string('tipo', 20);
            $table->string('nombre', 80);
            // RESTRICT: quien responde por la actividad no puede desaparecer y
            // dejarla sin nadie que le pase lista. Es la misma politica que
            // protege una promotoria con matriculas.
            $table->foreignId('responsable_id')->constrained('perfiles')->restrictOnDelete();
            $table->foreignId('periodo_id')->nullable()->constrained('periodos')->restrictOnDelete();
            // NULL = sin tope. La ausencia es lo que no limita, igual que en
            // `cupos_promotoria`: no hay un cero magico que signifique infinito.
            $table->unsignedSmallInteger('cupo_maximo')->nullable();
            // El enlace. Largo a proposito: es lo UNICO que separa el formulario
            // publico del resto de internet, asi que tiene que ser imposible de
            // adivinar probando. 32 caracteres alfanumericos son ~190 bits.
            $table->char('token', 32)->unique('un_enlace_por_actividad');
            // El interruptor de "ya no recibo mas gente", a mano. El cupo lleno
            // cierra solo; esto cierra porque alguien lo decide.
            $table->boolean('abierta')->default(true);
            $table->timestamps();
        });

        DB::statement("
            ALTER TABLE actividades
            ADD CONSTRAINT tipo_de_actividad_valido
            CHECK (tipo IN ('curso', 'taller', 'proyeccion'))
        ");

        // El nombre es lo que se ve en el enlace compartido: una actividad sin
        // nombre no se puede ni anunciar.
        DB::statement("
            ALTER TABLE actividades
            ADD CONSTRAINT nombre_de_actividad_no_vacio
            CHECK (nombre <> '')
        ");

        // Un cupo de cero no es "sin tope", es una actividad a la que nadie
        // puede entrar. Si esa es la intencion, se cierra el enlace.
        DB::statement('
            ALTER TABLE actividades
            ADD CONSTRAINT cupo_de_actividad_positivo
            CHECK (cupo_maximo IS NULL OR cupo_maximo > 0)
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('actividades');
    }
};
