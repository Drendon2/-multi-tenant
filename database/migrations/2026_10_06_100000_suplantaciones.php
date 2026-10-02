<?php

use App\Support\GuionSql;
use Illuminate\Database\Migrations\Migration;

/**
 * Entrar a una institucion desde el panel: la tabla de los tokens de un solo
 * uso. La fuente de verdad es el guion.
 *
 * Como el DUEÑO de las tablas: php artisan migrate --database=pgsql_dueno
 */
return new class extends Migration
{
    public function up(): void
    {
        GuionSql::correr('postgres/05-suplantaciones.sql');
    }

    public function down(): void
    {
        GuionSql::correr('postgres/05-suplantaciones.revertir.sql');
    }
};
