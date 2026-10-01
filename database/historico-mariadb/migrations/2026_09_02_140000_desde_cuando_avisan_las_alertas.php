<?php

/**
 * Desde cuando cuentan las alertas.
 *
 * Sale de produccion el mismo dia que las alertas: aparecieron 596 avisos desde
 * enero, y casi todos eran del tiempo en que el periodo ya habia empezado pero
 * las clases todavia no. Un periodo academico INCLUYE su periodo de matriculas
 * —semanas de inscribir gente y armar grupos antes de que nadie de una clase—
 * asi que `periodos.fecha_inicio` no sirve como origen. Los avisos eran
 * correctos y eran inutiles.
 *
 * Va junto a los dos interruptores de las alertas y no en el periodo, que fue el
 * primer intento: quien enciende una alerta es quien decide desde cuando cuenta,
 * y tener el interruptor en una pantalla y su fecha en otra obliga a saberse las
 * dos. Es una decision de quien administra, no un dato del calendario academico.
 *
 * NULA significa «desde el inicio del periodo en curso», que es como se
 * comportaban hasta hoy: las instalaciones que ya existen no cambian al migrar y
 * quien no quiera usar esto no tiene que llenar nada.
 *
 * El riesgo conocido de tenerla aqui y no en el periodo es que se quede vieja al
 * cambiar de semestre y apague las alertas sin decirlo. No se resuelve con el
 * esquema sino con la pantalla: Configuracion avisa cuando la fecha se sale del
 * periodo en curso.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuracion_institucion', function (Blueprint $table) {
            $table->date('alertas_desde')->nullable()->after('faltas_para_abandono');
        });
    }

    public function down(): void
    {
        Schema::table('configuracion_institucion', function (Blueprint $table) {
            $table->dropColumn('alertas_desde');
        });
    }
};
