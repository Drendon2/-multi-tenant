<?php

/**
 * El enlace de inscripcion de UNA promotoria (29/09/2026).
 *
 * Existe para matricular gente en una promotoria concreta con la ventana de
 * matriculas CERRADA —un profesor que abre un cupo a mitad de semestre, un
 * cartel en la puerta del salon— sin abrir la inscripcion para todas las demas.
 * Decisiones del usuario, tomadas con la pregunta delante:
 *
 * - Entran por el los NUEVOS (crean cuenta con esa promotoria fija) y los que
 *   YA TIENEN cuenta de estudiante (un boton «Matricularme»).
 * - NACE APAGADO, y lo encienden, apagan o renuevan el administrador y el
 *   director de su departamento. El profesor lo VE y lo comparte, pero no lo
 *   enciende: encenderlo es saltarse la ventana, y esa es una decision de
 *   direccion.
 * - RENOVAR cambia el token y el enlace viejo deja de servir en el acto: un QR
 *   publicado donde no debia no se puede despegar de una pared.
 *
 * El token NACE VACIO y se crea la primera vez que alguien lo enciende, por lo
 * mismo que `perfiles.codigo_qr`: la mayoria de promotorias no lo va a usar
 * nunca, y ahi vacio significa «nunca se encendio». 16 caracteres y no 32
 * porque va dentro de un QR, y el largo decide lo tupida que sale la rejilla.
 *
 * El cupo y el limite de promotorias por estudiante NO se tocan: la matricula
 * que entra por aqui pasa por las mismas comprobaciones y el mismo trigger que
 * cualquier otra, y nace pendiente como todas.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotorias', function (Blueprint $table) {
            // UNICO: es lo que convierte «coincide con este token» en «es esta
            // promotoria».
            $table->char('enlace_token', 16)->nullable()->unique()->after('profesor_id');
            $table->boolean('enlace_abierto')->default(false)->after('enlace_token');
        });
    }

    public function down(): void
    {
        Schema::table('promotorias', function (Blueprint $table) {
            $table->dropUnique(['enlace_token']);
            $table->dropColumn(['enlace_token', 'enlace_abierto']);
        });
    }
};
