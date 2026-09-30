<?php

use App\Support\GuionSql;
use Illuminate\Database\Migrations\Migration;

/**
 * Multi-institucion, paso 01. Tabla `instituciones` y la 1, la institucion que ya usa esta base.
 *
 * La migracion no escribe SQL: corre el guion versionado
 * `database/sql/multi-tenant/01-instituciones.sql`, que tambien se puede correr a mano.
 * Los dos lados son idempotentes. El detalle, en el propio guion.
 */
return new class extends Migration
{
    public function up(): void
    {
        GuionSql::correr('multi-tenant/01-instituciones.sql');
    }

    public function down(): void
    {
        GuionSql::correr('multi-tenant/01-instituciones.revertir.sql');
    }
};
