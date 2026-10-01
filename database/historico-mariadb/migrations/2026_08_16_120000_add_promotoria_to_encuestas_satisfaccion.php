<?php

/**
 * Ata la encuesta de satisfaccion a la PROMOTORIA, no solo al periodo.
 *
 * Antes colgaba de (perfil, periodo), asi que un estudiante que cursaba dos
 * promotorias contestaba una sola vez. La consecuencia era que la pregunta
 * «¿como calificas el acompanamiento del profesor?» no se podia atribuir a
 * nadie: la respuesta describia su paso por la casa ese semestre, no a un
 * profesor concreto, y en Estadisticas no habia forma de decir que promotoria
 * iba bien y cual no.
 *
 * Con la promotoria dentro, la encuesta pasa a ser lo que su enunciado ya decia
 * que era: la valoracion de UNA promotoria en UN periodo.
 *
 * La columna se anade como NULL y despues se rellena, en vez de declararla NOT
 * NULL de una: en una base con encuestas ya contestadas, MariaDB rechazaria la
 * columna obligatoria sin valor por defecto. Las respuestas viejas se reparten
 * a la promotoria que esa persona cursaba en ese periodo cuando hay UNA sola;
 * las de quien cursaba varias no se pueden atribuir sin inventar, y se quedan
 * sin promotoria — por eso la columna admite NULL tambien al final.
 *
 * Ese NULL es informacion, no un hueco: significa «contestada cuando la encuesta
 * todavia no distinguia promotorias». Las pantallas lo cuentan aparte en vez de
 * repartirlo a ojo.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('encuestas_satisfaccion', function (Blueprint $table) {
            // RESTRICT como el periodo: una encuesta que pierde su promotoria
            // deja de significar nada, y borrar una promotoria ya esta bloqueado
            // por sus matriculas.
            $table->foreignId('promotoria_id')
                ->nullable()
                ->after('perfil_id')
                ->constrained('promotorias')
                ->restrictOnDelete();
        });

        // Las respuestas anteriores al cambio: solo se pueden atribuir cuando la
        // persona cursaba exactamente una promotoria en ese periodo.
        DB::statement("
            UPDATE encuestas_satisfaccion e
            SET promotoria_id = (
                SELECT m.promotoria_id
                FROM matriculas m
                WHERE m.estudiante_id = e.perfil_id
                  AND m.periodo_id = e.periodo_id
                  AND m.estado = 'activa'
            )
            WHERE (
                SELECT COUNT(*)
                FROM matriculas m
                WHERE m.estudiante_id = e.perfil_id
                  AND m.periodo_id = e.periodo_id
                  AND m.estado = 'activa'
            ) = 1
        ");

        // El indice unico cambia de forma: ahora se contesta una vez por
        // promotoria y periodo, no una por periodo.
        //
        // El ORDEN no es negociable: primero se crea el nuevo y despues se borra
        // el viejo. Al reves, MariaDB responde «Cannot drop index ...: needed in
        // a foreign key constraint» (error 1553), porque el unico
        // (perfil_id, periodo_id) es tambien el indice que sostiene la clave
        // foranea de `perfil_id` y no puede quedarse sin ninguno ni un instante.
        // El nuevo empieza igual por `perfil_id`, asi que puede relevarlo.
        Schema::table('encuestas_satisfaccion', function (Blueprint $table) {
            $table->unique(
                ['perfil_id', 'periodo_id', 'promotoria_id'],
                'una_encuesta_por_promotoria_y_periodo'
            );
        });

        Schema::table('encuestas_satisfaccion', function (Blueprint $table) {
            $table->dropUnique('una_encuesta_satisfaccion_por_periodo');
        });
    }

    public function down(): void
    {
        // Mismo cuidado que al subir, y por el mismo motivo: el indice que se va
        // sostiene la clave foranea, asi que primero entra su relevo.
        Schema::table('encuestas_satisfaccion', function (Blueprint $table) {
            $table->unique(['perfil_id', 'periodo_id'], 'una_encuesta_satisfaccion_por_periodo');
        });

        Schema::table('encuestas_satisfaccion', function (Blueprint $table) {
            $table->dropUnique('una_encuesta_por_promotoria_y_periodo');
            $table->dropConstrainedForeignId('promotoria_id');
        });
    }
};
