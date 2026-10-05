<?php

use App\Support\GuionSql;
use Illuminate\Database\Migrations\Migration;

/**
 * El documento del personal. La fuente de verdad es el guion.
 *
 * Como el DUEÑO de las tablas: php artisan migrate --database=pgsql_dueno
 */
return new class extends Migration
{
    public function up(): void
    {
        GuionSql::correr('postgres/09-documento-del-personal.sql');
    }

    public function down(): void
    {
        GuionSql::correr('postgres/09-documento-del-personal.revertir.sql');
    }
};
