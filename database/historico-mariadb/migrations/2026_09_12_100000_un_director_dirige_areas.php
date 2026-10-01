<?php

/**
 * Un director dirige AREAS concretas, no la casa entera.
 *
 * ---------------------------------------------------------------------------
 * POR QUE
 * ---------------------------------------------------------------------------
 * Lo pidio el usuario el 12/09/2026: «el director en este momento puede ver
 * todo, pero solo deberia ver las areas a las que se le asigne desde la
 * administracion; si es director de musica solo veria las promotorias de
 * musica».
 *
 * Hasta hoy el rol `director` era un administrador con menos pantallas: en
 * `PanelController::visiblesPara()` el unico recorte era para el profesor, y en
 * todo lo demas —Gestion, cupos, alertas, fichas— director y administrador iban
 * en el mismo `in_array`. Esta tabla es lo que convierte ese rol en uno acotado.
 *
 * ---------------------------------------------------------------------------
 * UNA PUENTE Y NO UNA COLUMNA EN `perfiles`
 * ---------------------------------------------------------------------------
 * Porque un director puede dirigir VARIAS areas —en produccion hay cinco y un
 * solo director, y la casa crecera al reves: varias personas, cada una con lo
 * suyo, y alguna con dos—. Una columna `area_id` obligaria a inventar un
 * segundo director de la misma persona el dia que dirija dos.
 *
 * ---------------------------------------------------------------------------
 * LO QUE ESTA TABLA NO PUEDE GARANTIZAR
 * ---------------------------------------------------------------------------
 * Que el perfil sea un director. Es una condicion sobre OTRA columna de otra
 * fila y ningun CHECK la alcanza; ademas el rol cambia despues, y una fila que
 * era valida dejaria de serlo sin que nadie la toque. Se impone donde se
 * asigna, en el formulario de usuario, y se LEE siempre junto al rol: las
 * areas de quien ya no es director no dicen nada porque nadie se las pregunta.
 *
 * ---------------------------------------------------------------------------
 * EL DIRECTOR QUE YA ESTA NACE CON TODAS
 * ---------------------------------------------------------------------------
 * Decision del usuario ese dia, y la razon es el dia del despliegue: en
 * produccion hay UN director, y sin este volcado se quedaria sin ver nada en
 * cuanto suba el cambio —su Panel vacio, sus promotorias fuera— hasta que
 * alguien entre a asignarle las areas a mano. Asi el despliegue no le cambia el
 * dia a nadie y la decision de que le sobra se toma con calma desde Gestion.
 *
 * Solo corre si HAY areas: en una instalacion nueva no hay nada que asignar, y
 * el instalador crea al director antes que los departamentos.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('areas_dirigidas', function (Blueprint $table) {
            $table->id();

            // CASCADE: la asignacion es del perfil y no tiene vida propia.
            $table->foreignId('perfil_id')->constrained('perfiles')->cascadeOnDelete();

            // CASCADE tambien: si el area desaparece, dirigirla no significa
            // nada. No es como un grupo con gente dentro — aqui no se pierde
            // ningun dato del estudiante, solo un permiso que ya no aplica.
            $table->foreignId('area_id')->constrained('areas')->cascadeOnDelete();

            $table->timestamps();

            // La misma persona no dirige dos veces la misma area. Sin esto,
            // guardar el formulario dos veces duplicaria las filas y cualquier
            // conteo de «cuantas areas dirige» mentiria.
            $table->unique(['perfil_id', 'area_id'], 'un_area_una_vez_por_director');

            // «¿Quien dirige esta area?» hace falta al borrar un area y en la
            // ficha; el unico de arriba no sirve porque empieza por `perfil_id`.
            $table->index('area_id');
        });

        $this->darleTodasALosDirectoresQueYaEstan();
    }

    public function down(): void
    {
        Schema::dropIfExists('areas_dirigidas');
    }

    /**
     * Cada director existente pasa a dirigir TODAS las areas de hoy.
     *
     * Es exactamente lo que ya podia ver, escrito por fin en alguna parte.
     */
    private function darleTodasALosDirectoresQueYaEstan(): void
    {
        $areas = DB::table('areas')->pluck('id');
        $directores = DB::table('perfiles')->where('rol', 'director')->pluck('id');

        if ($areas->isEmpty() || $directores->isEmpty()) {
            return;
        }

        $ahora = now();
        $filas = [];

        foreach ($directores as $perfil) {
            foreach ($areas as $area) {
                $filas[] = [
                    'perfil_id' => $perfil,
                    'area_id' => $area,
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ];
            }
        }

        DB::table('areas_dirigidas')->insert($filas);
    }
};
