<?php

/**
 * Los datos de la entidad que exige la Ley 1581 de 2012, y el texto editable de
 * la politica de tratamiento de datos personales.
 *
 * POR QUE VAN AQUI Y NO EN UNA PLANTILLA. Una politica de tratamiento tiene que
 * identificar al responsable —nombre, NIT, direccion, correo y telefono de
 * atencion— y decir a donde se dirige quien quiere conocer, actualizar o
 * suprimir lo suyo. Nada de eso puede estar quemado en el codigo: este producto
 * se instala para otras instituciones, y la del vecino no comparte ni el NIT ni
 * la direccion.
 *
 * `politica_datos` NACE VACIA A PROPOSITO, y eso no significa «sin politica».
 * Vacia, la pagina publica pinta el texto por defecto de `Support\PoliticaDatos`
 * —redactado sobre la Ley 1581 y el Decreto 1377 de 2013, con el nombre y el
 * contacto de la entidad ya metidos dentro—. Solo cuando alguien la reescribe
 * desde Gestion manda lo que hay en esta columna.
 *
 * La alternativa era sembrar el texto por defecto aqui, y es peor por dos
 * razones: quedaria con el nombre de institucion que hubiera el dia de la
 * migracion —«Casa de la Cultura», el de fabrica, en una base recien montada— y
 * dejaria de ponerse al dia cuando la entidad cambie su nombre o su correo.
 *
 * TEXT y no string: son varias pantallas de texto legal.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuracion_institucion', function (Blueprint $table) {
            // Identificacion del responsable del tratamiento. Los cuatro nacen
            // vacios: la pagina publica se lee igual sin ellos —esconde el
            // renglon que falta en vez de pintar un hueco— y asi actualizar el
            // sistema no obliga a nadie a rellenar un formulario antes de que
            // su sitio vuelva a funcionar.
            $table->string('entidad_nit', 40)->default('')->after('nombre_institucion');
            $table->string('entidad_direccion', 160)->default('')->after('entidad_nit');
            $table->string('entidad_correo', 120)->default('')->after('entidad_direccion');
            $table->string('entidad_telefono', 40)->default('')->after('entidad_correo');

            $table->text('politica_datos')->nullable()->after('entidad_telefono');
        });
    }

    public function down(): void
    {
        Schema::table('configuracion_institucion', function (Blueprint $table) {
            $table->dropColumn([
                'entidad_nit',
                'entidad_direccion',
                'entidad_correo',
                'entidad_telefono',
                'politica_datos',
            ]);
        });
    }
};
