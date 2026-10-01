<?php

/**
 * El interruptor del recordatorio de la encuesta demografica.
 *
 * Pedido por el usuario el 05/09/2026 con el motivo escrito: «las personas no
 * responden de manera voluntaria la encuesta demografica». La encuesta es lo
 * que sostiene Gestion → Estadisticas —barrio, estrato, zona, grupo etnico— y
 * es tambien lo que una entidad publica reporta; con la mitad sin contestar,
 * esas cifras no describen a nadie.
 *
 * VA CON INTERRUPTOR, y a peticion suya: un aviso en cada entrada es de las
 * cosas que cansan, y la institucion tiene que poder apagarlo sin que haya que
 * tocar codigo. Por defecto ENCENDIDO, que es lo que se pidio.
 *
 * Nace apagado para nadie: el aviso solo aparece a quien de verdad tiene la
 * encuesta a medias, asi que encenderlo no molesta a quien ya la contesto.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuracion_institucion', function (Blueprint $table) {
            $table->boolean('recordar_encuesta')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('configuracion_institucion', function (Blueprint $table) {
            $table->dropColumn('recordar_encuesta');
        });
    }
};
