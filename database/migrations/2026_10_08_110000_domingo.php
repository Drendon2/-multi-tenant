<?php

use App\Support\GuionSql;
use Illuminate\Database\Migrations\Migration;

/**
 * El domingo tambien es dia de clase. La fuente de verdad es el guion.
 *
 * Como el DUEÑO de las tablas: php artisan migrate --database=pgsql_dueno
 */
return new class extends Migration
{
    public function up(): void
    {
        GuionSql::correr('postgres/10-domingo.sql');
    }

    public function down(): void
    {
        GuionSql::correr('postgres/10-domingo.revertir.sql');
    }
};
