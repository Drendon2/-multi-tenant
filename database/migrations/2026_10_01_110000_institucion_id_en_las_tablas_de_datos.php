<?php

use App\Support\GuionSql;
use Illuminate\Database\Migrations\Migration;

/**
 * Multi-institucion, paso 02. `institucion_id` en las 28 tablas de datos: nula, rellena con 1, obligatoria y con FK.
 *
 * La migracion no escribe SQL: corre el guion versionado
 * `database/sql/multi-tenant/02-columna-institucion.sql`, que tambien se puede correr a mano.
 * Los dos lados son idempotentes. El detalle, en el propio guion.
 */
return new class extends Migration
{
    public function up(): void
    {
        GuionSql::correr('multi-tenant/02-columna-institucion.sql');
    }

    public function down(): void
    {
        GuionSql::correr('multi-tenant/02-columna-institucion.revertir.sql');
    }
};
