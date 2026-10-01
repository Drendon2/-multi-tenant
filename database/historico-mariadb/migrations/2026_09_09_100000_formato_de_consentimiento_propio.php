<?php

/**
 * Las dos ranuras donde la entidad sube SU PROPIO formato de autorizacion.
 *
 * Hasta hoy el formato lo imprimia el sistema y no habia otra opcion. Es un
 * papel legal, y una entidad puede tener el suyo ya aprobado por su area
 * juridica —o heredado de un convenio, o exigido por quien la financia—; con
 * uno solo posible, la unica salida era repartirlo por fuera del sistema y
 * pedirlo por una ranura distinta, que es como se pierden los papeles.
 *
 * SON DOS COLUMNAS Y NO UNA, y esa es la decision que hay que respetar: la
 * version de mayor de edad y la de menor no son el mismo papel con otro titulo.
 * Un menor no otorga esta autorizacion por si mismo (Ley 1581 de 2012, art. 7):
 * la da su acudiente, asi que ese formato identifica a DOS personas y lo firma
 * la segunda. Una sola ranura obligaria a la entidad a elegir cual de los dos
 * papeles sube, y el otro quedaria mal.
 *
 * VACIA SIGNIFICA «usa el que imprime el sistema», igual que `politica_datos`
 * significa «publica el de fabrica». No se siembra nada: sembrar dejaria una
 * ruta apuntando a un archivo que no existe.
 *
 * Y VAN POR SEPARADO: se puede subir solo la del menor y dejar que el sistema
 * imprima la del mayor. No hay razon para atarlas.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuracion_institucion', function (Blueprint $table) {
            $table->string('consentimiento_mayor', 255)->default('')->after('finalidad_imagen');
            $table->string('consentimiento_menor', 255)->default('')->after('consentimiento_mayor');
        });
    }

    public function down(): void
    {
        Schema::table('configuracion_institucion', function (Blueprint $table) {
            $table->dropColumn(['consentimiento_mayor', 'consentimiento_menor']);
        });
    }
};
