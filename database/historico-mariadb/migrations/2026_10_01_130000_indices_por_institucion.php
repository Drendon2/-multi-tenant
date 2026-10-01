<?php

use App\Support\GuionSql;
use Illuminate\Database\Migrations\Migration;

/**
 * Multi-institucion, paso 04. Indices compuestos que empiezan por `institucion_id`.
 *
 * La migracion no escribe SQL: corre el guion versionado
 * `database/sql/multi-tenant/04-indices-por-institucion.sql`, que tambien se puede correr a mano.
 * Los dos lados son idempotentes. El detalle, en el propio guion.
 */
return new class extends Migration
{
    public function up(): void
    {
        GuionSql::correr('multi-tenant/04-indices-por-institucion.sql');
    }

    public function down(): void
    {
        GuionSql::correr('multi-tenant/04-indices-por-institucion.revertir.sql');
    }
};
