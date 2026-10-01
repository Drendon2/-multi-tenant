<?php

/**
 * La firma que sella los certificados de matricula.
 *
 * Tres columnas y no una: la imagen sola es un garabato escaneado que no
 * identifica a nadie. Un certificado que se presenta ante un tercero —un
 * colegio, una empresa— tiene que decir QUIEN firma y CON QUE cargo, porque de
 * ahi sale la facultad para certificar.
 *
 * Van aqui, en la fila unica de la institucion, y no en el perfil de quien
 * dirige: lo que firma es la entidad. Cuando cambie el director se sube otra
 * firma y se reescriben los dos textos, sin tocar cuentas ni roles.
 *
 * Los tres nacen vacios a proposito. Sin firma cargada el certificado se sigue
 * pudiendo generar —con el espacio de la firma en blanco— en vez de tumbar la
 * descarga: una institucion recien instalada no tiene por que quedarse sin
 * constancias hasta que alguien pase por el escaner.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuracion_institucion', function (Blueprint $table) {
            // Ruta del archivo, como `logo`. Vacio = no hay firma cargada.
            $table->string('firma', 255)->default('')->after('logo');
            $table->string('firmante_nombre', 120)->default('')->after('firma');
            $table->string('firmante_cargo', 80)->default('')->after('firmante_nombre');
        });
    }

    public function down(): void
    {
        Schema::table('configuracion_institucion', function (Blueprint $table) {
            $table->dropColumn(['firma', 'firmante_nombre', 'firmante_cargo']);
        });
    }
};
