<?php

use App\Support\GuionSql;
use Illuminate\Database\Migrations\Migration;

/**
 * Por que no se dicto una clase, y con que clase se repuso. La fuente de
 * verdad es el guion.
 *
 * Como el DUEÑO de las tablas: php artisan migrate --database=pgsql_dueno
 */
return new class extends Migration
{
    public function up(): void
    {
        GuionSql::correr('postgres/06-causa-de-omision.sql');
    }

    public function down(): void
    {
        GuionSql::correr('postgres/06-causa-de-omision.revertir.sql');
    }
};
