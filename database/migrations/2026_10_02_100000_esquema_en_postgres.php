<?php

use App\Support\GuionSql;
use Illuminate\Database\Migrations\Migration;

/**
 * El esquema entero, de una vez, en PostgreSQL.
 *
 * Sustituye a las 55 migraciones de MariaDB y a los cuatro guiones de
 * `database/sql/multi-tenant/`, que quedan archivados en
 * `database/historico-mariadb/` y ya no se ejecutan: llevaban SQL de MariaDB
 * (`MODIFY`, `SHOW COLUMNS`, `SIGNAL`, columnas VIRTUAL) que aqui no corre.
 *
 * La fuente de verdad es el guion: esta migracion solo lo llama, igual que
 * hacian las del paso 1.
 */
return new class extends Migration
{
    public function up(): void
    {
        GuionSql::correr('postgres/01-esquema.sql');
    }

    public function down(): void
    {
        GuionSql::correr('postgres/01-esquema.revertir.sql');
    }
};
