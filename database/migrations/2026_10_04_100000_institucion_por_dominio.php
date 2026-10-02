<?php

use App\Support\GuionSql;
use Illuminate\Database\Migrations\Migration;

/**
 * La institucion la dice el dominio: `dominio_propio`, `username` unico por
 * institucion y `users` con RLS. La fuente de verdad es el guion.
 *
 * Como el DUEÑO de las tablas: php artisan migrate --database=pgsql_dueno
 */
return new class extends Migration
{
    public function up(): void
    {
        GuionSql::correr('postgres/03-dominios.sql');
    }

    public function down(): void
    {
        GuionSql::correr('postgres/03-dominios.revertir.sql');
    }
};
