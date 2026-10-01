<?php

/**
 * Periodo semestral de matricula. Ej.: '2026-1'.
 *
 * `activo` dice cual es el periodo EN CURSO (el que reciben todas las
 * pantallas). `matriculas_abiertas` es otra cosa: la ventana en la que se
 * admite gente nueva o renovaciones. La institucion no recibe matriculas todo
 * el ano, solo al principio y a mitad, asi que el periodo puede estar en curso
 * con las matriculas ya cerradas.
 *
 * ---------------------------------------------------------------------------
 * TRAMPA (a) DE LA MIGRACION: indice unico PARCIAL
 * ---------------------------------------------------------------------------
 * El original usa un indice unico parcial de PostgreSQL:
 *
 *     UNIQUE (activo) WHERE activo = true
 *
 * MariaDB no tiene indices parciales. Se emula con una columna generada que
 * vale 1 cuando el periodo esta activo y NULL cuando no, mas un indice unico
 * normal: en MySQL/MariaDB un indice unico admite multiples NULL (porque
 * NULL != NULL), asi que las filas inactivas quedan de hecho FUERA del indice.
 * El efecto es identico al indice parcial de Postgres.
 *
 * Sin esto, `where('activo', true)->first()` devolveria una fila arbitraria —no
 * hay ORDER BY— y de esa funcion cuelgan la ventana de matriculas, el retiro y
 * la renovacion.
 *
 * VIRTUAL y no PERSISTENT: la columna no se almacena, se calcula al leer y al
 * indexar. Verificado contra MariaDB 10.5.29 (la rama de Hostinger): admite
 * indice unico sobre columna generada virtual.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('periodos', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 20)->unique();
            $table->date('fecha_inicio');
            $table->date('fecha_fin');
            $table->boolean('activo')->default(false);
            $table->boolean('matriculas_abiertas')->default(false);
            $table->timestamps();
        });

        DB::statement('
            ALTER TABLE periodos
            ADD COLUMN activo_marca TINYINT
                GENERATED ALWAYS AS (IF(activo = 1, 1, NULL)) VIRTUAL
        ');

        DB::statement('
            ALTER TABLE periodos
            ADD UNIQUE INDEX un_solo_periodo_activo (activo_marca)
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('periodos');
    }
};
