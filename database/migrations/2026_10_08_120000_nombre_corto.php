<?php

use App\Support\GuionSql;
use Illuminate\Database\Migrations\Migration;

/**
 * El nombre corto de la institucion. La fuente de verdad es el guion.
 *
 * Como el DUEÑO de las tablas: php artisan migrate --database=pgsql_dueno
 */
return new class extends Migration
{
    public function up(): void
    {
        GuionSql::correr('postgres/11-nombre-corto.sql');
    }

    public function down(): void
    {
        GuionSql::correr('postgres/11-nombre-corto.revertir.sql');
    }
};
