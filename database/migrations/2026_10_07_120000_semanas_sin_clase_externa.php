<?php

use App\Support\GuionSql;
use Illuminate\Database\Migrations\Migration;

/**
 * La alerta semanal de los programas externos. La fuente de verdad es el guion.
 *
 * Como el DUEÑO de las tablas: php artisan migrate --database=pgsql_dueno
 */
return new class extends Migration
{
    public function up(): void
    {
        GuionSql::correr('postgres/08-semanas-sin-clase-externa.sql');
    }

    public function down(): void
    {
        GuionSql::correr('postgres/08-semanas-sin-clase-externa.revertir.sql');
    }
};
