<?php

use App\Support\GuionSql;
use Illuminate\Database\Migrations\Migration;

/**
 * El plazo para reponer una falta. La fuente de verdad es el guion.
 *
 * Como el DUEÑO de las tablas: php artisan migrate --database=pgsql_dueno
 */
return new class extends Migration
{
    public function up(): void
    {
        GuionSql::correr('postgres/07-plazo-para-reponer.sql');
    }

    public function down(): void
    {
        GuionSql::correr('postgres/07-plazo-para-reponer.revertir.sql');
    }
};
