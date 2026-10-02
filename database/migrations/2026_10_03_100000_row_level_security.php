<?php

use App\Support\GuionSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Row Level Security: el aislamiento entre instituciones lo hace el motor.
 *
 * La fuente de verdad es el guion. Esta migracion solo le dice como se llaman
 * los otros dos roles, que salen del `.env`, y lo corre.
 *
 * Tiene que correr como el DUEÑO de las tablas:
 *   php artisan migrate --database=pgsql_dueno
 * Corrida como la aplicacion, el guion se niega: RLS no aplicaria a su dueño.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::select("SELECT set_config('app.rol_aplicacion', ?, false)", [config('database.connections.pgsql.username')]);
        DB::select("SELECT set_config('app.rol_global', ?, false)", [config('database.rol_global')]);

        GuionSql::correr('postgres/02-rls.sql');
    }

    public function down(): void
    {
        GuionSql::correr('postgres/02-rls.revertir.sql');
    }
};
