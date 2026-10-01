<?php

/**
 * Las dos FINALIDADES que se autorizan, editables por la entidad.
 *
 * POR QUE SOLO LAS FINALIDADES Y NO EL DOCUMENTO ENTERO. El formato de
 * consentimiento es un papel con estructura —identificacion de quien firma,
 * casillas si/no, espacio de firma, nota legal— y casi todo eso es maquinaria
 * que no cambia de una institucion a otra. Lo unico que si cambia es QUE se
 * autoriza, que son dos frases. Abrir el documento entero a un textarea
 * dejaria a cualquiera capaz de romper el papel sin darse cuenta.
 *
 * Y POR QUE ESTAS DOS COLUMNAS DAN DE COMER A LOS DOS SITIOS. La politica tiene
 * que ANUNCIAR lo que el consentimiento autoriza: si no coincide, lo firmado no
 * vale. Hasta ahora eso dependia de que alguien se acordara de tocar los dos
 * textos —esta escrito como aviso en `Support\PoliticaDatos`—. Con la finalidad
 * en UNA columna que leen los dos, no hay forma de que diverjan.
 *
 * VACIAS SIGNIFICA «la de fabrica», igual que `politica_datos`. Los textos por
 * defecto viven en `ConfiguracionInstitucion::FINALIDAD_DATOS` y
 * `FINALIDAD_IMAGEN`, y no se siembran aqui por la misma razon que aquella: un
 * texto sembrado se queda viejo y deja de seguir a lo que diga el codigo.
 *
 * OJO CON LA FORMA GRAMATICAL, que no es un capricho de redaccion: cada una se
 * incrusta en dos frases distintas y tiene que encajar en las dos.
 *
 *   datos  -> sintagma nominal: «Para EL ANALISIS…» y «y para EL ANALISIS…»
 *   imagen -> infinitivo:       «Para COMUNICAR…» y «con el fin de COMUNICAR…»
 *
 * La pantalla de Gestion lo dice con la frase entera delante, para que quien
 * escriba vea donde va a caer lo suyo.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuracion_institucion', function (Blueprint $table) {
            $table->string('finalidad_datos', 255)->default('')->after('politica_datos');
            $table->string('finalidad_imagen', 255)->default('')->after('finalidad_datos');
        });
    }

    public function down(): void
    {
        Schema::table('configuracion_institucion', function (Blueprint $table) {
            $table->dropColumn(['finalidad_datos', 'finalidad_imagen']);
        });
    }
};
