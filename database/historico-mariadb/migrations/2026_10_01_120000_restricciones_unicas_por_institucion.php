<?php

use App\Support\GuionSql;
use Illuminate\Database\Migrations\Migration;

/**
 * Multi-institucion, paso 03. Lo que era unico en toda la base pasa a serlo dentro de cada institucion.
 *
 * La migracion no escribe SQL: corre el guion versionado
 * `database/sql/multi-tenant/03-unicos-por-institucion.sql`, que tambien se puede correr a mano.
 * Los dos lados son idempotentes. El detalle, en el propio guion.
 */
return new class extends Migration
{
    public function up(): void
    {
        GuionSql::correr('multi-tenant/03-unicos-por-institucion.sql');
    }

    public function down(): void
    {
        GuionSql::correr('multi-tenant/03-unicos-por-institucion.revertir.sql');
    }
};
