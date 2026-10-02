<?php

use App\Support\GuionSql;
use Illuminate\Database\Migrations\Migration;

/**
 * El panel de todas las instituciones: la tabla `operadores` y los subdominios
 * reservados. La fuente de verdad es el guion.
 *
 * Como el DUEÑO de las tablas: php artisan migrate --database=pgsql_dueno
 */
return new class extends Migration
{
    public function up(): void
    {
        GuionSql::correr('postgres/04-panel.sql');
    }

    public function down(): void
    {
        GuionSql::correr('postgres/04-panel.revertir.sql');
    }
};
