<?php

/**
 * La tabla que permite que una matricula este en VARIOS grupos.
 *
 * ---------------------------------------------------------------------------
 * POR QUE, Y QUE CASO RESUELVE
 * ---------------------------------------------------------------------------
 * Lo pidio el usuario el 10/09/2026 y es un caso que se repite: alguien que va
 * al Grupo A el lunes Y al Grupo B el miercoles, de la MISMA promotoria. Son
 * dos clases distintas y se le pasa lista en las dos. Hasta hoy no se podia:
 * `matriculas.grupo_id` es una sola columna, y el indice
 * `unica_matricula_por_periodo` impide crear una segunda matricula en la misma
 * promotoria.
 *
 * ---------------------------------------------------------------------------
 * POR QUE UNA PUENTE Y NO DOS MATRICULAS
 * ---------------------------------------------------------------------------
 * La via aparentemente facil —quitar el indice unico y dejar dos matriculas—
 * cuesta mucho mas de lo que parece, y conviene que quede escrito para que
 * nadie la reabra:
 *
 * - `Matricula::promotoriasOcupadas()` cuenta FILAS, no promotorias distintas.
 *   Con dos matriculas, quien va a dos grupos de Piano gastaria DOS de sus
 *   ranuras y pareceria que cursa dos promotorias.
 * - El trigger de cupo de promotoria tambien cuenta filas: le quitaria el sitio
 *   a otra persona.
 * - El certificado listaria la promotoria dos veces.
 * - `MatricularController` REUTILIZA la fila retirada apoyandose justamente en
 *   ese indice.
 *
 * Con la puente la matricula sigue siendo UNA por promotoria, asi que el cupo
 * de promotoria, las ranuras, el certificado y la renovacion no se enteran.
 * Decision del usuario ese dia: quien va a dos grupos ocupa UN cupo de
 * promotoria y UNA silla en cada grupo, que es lo que pasa fisicamente.
 *
 * ---------------------------------------------------------------------------
 * ESTA MIGRACION NO BORRA `matriculas.grupo_id`, Y ES A PROPOSITO
 * ---------------------------------------------------------------------------
 * Quitarla aqui tumbaria de golpe los quince archivos que la leen, y con la
 * suite entera en rojo se pierde la unica red que tiene un cambio de este
 * tamano. El trabajo va en tres pasos: esta tabla (nada la usa todavia), luego
 * los lectores uno a uno con la suite verde en cada paso, y la columna se borra
 * al final — cuando lo que siga leyendola se caiga ruidosamente, que es como se
 * quiere que falle.
 *
 * Mientras las dos convivan, la COLUMNA manda y esta tabla es una copia. Por
 * eso el volcado de abajo esta escrito para poder REPETIRSE: la migracion que
 * borre la columna tiene que volver a correrlo antes, o se perderian las
 * asignaciones hechas entre una y otra.
 *
 * ---------------------------------------------------------------------------
 * LO QUE ESTA TABLA NO PUEDE GARANTIZAR
 * ---------------------------------------------------------------------------
 * Que el grupo sea de la MISMA promotoria que la matricula. Es una condicion
 * entre dos tablas y ningun CHECK de SQL puede consultar otra fila; la impone
 * `Matricula::validar()`, que ya lo hacia. Y el CUPO del grupo tampoco: es un
 * dato variable, no una forma fija de la tabla, y sigue en la misma validacion
 * — con la salvedad de que ahi no hay trigger, decidido el 10/09 sabiendo que
 * deja abierta la carrera entre dos asignaciones simultaneas.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asignaciones_grupo', function (Blueprint $table) {
            $table->id();

            // CASCADE: las asignaciones son de la matricula y no tienen vida
            // propia. Si la matricula se va, se van con ella.
            $table->foreignId('matricula_id')->constrained('matriculas')->cascadeOnDelete();

            // RESTRICT, igual que la columna a la que sustituye: un grupo con
            // gente dentro no se borra. Borrarlo se llevaria por delante a
            // quien esta repartido en el.
            $table->foreignId('grupo_id')->constrained('grupos')->restrictOnDelete();

            $table->timestamps();

            // La misma persona no puede estar dos veces en el mismo grupo. Es
            // lo unico que la base puede afirmar por si sola de esta tabla, y
            // hace falta: sin esto, pulsar «Asignar» dos veces la contaria dos
            // veces contra el cupo del grupo.
            $table->unique(['matricula_id', 'grupo_id'], 'una_vez_por_grupo');

            // «¿Quien esta en el grupo X?» es la consulta de la mitad de las
            // pantallas del personal —el Panel, la lista de quien dicta, el
            // informe— y ahora entra por aqui. El unico de arriba no sirve:
            // empieza por `matricula_id`.
            $table->index('grupo_id');
        });

        $this->volcarDesdeLaColumna();
    }

    /**
     * Copia `matriculas.grupo_id` a la tabla nueva.
     *
     * SE PUEDE REPETIR SIN DUPLICAR: el `whereNotExists` y el indice unico lo
     * cubren por los dos lados. Tiene que poder repetirse porque la columna
     * sigue siendo la que manda hasta que se borre, y entre esta migracion y
     * aquella se van a seguir asignando grupos.
     *
     * Va en una sentencia y no en un bucle a proposito. Son 594 filas en
     * desarrollo y unas 1.148 en produccion; en un bucle serian mil consultas,
     * y una migracion que tarda invita a interrumpirla a mitad.
     */
    private function volcarDesdeLaColumna(): void
    {
        if (! Schema::hasColumn('matriculas', 'grupo_id')) {
            return;
        }

        DB::statement('
            INSERT INTO asignaciones_grupo (matricula_id, grupo_id, created_at, updated_at)
            SELECT m.id, m.grupo_id, NOW(), NOW()
              FROM matriculas m
             WHERE m.grupo_id IS NOT NULL
               AND NOT EXISTS (
                   SELECT 1 FROM asignaciones_grupo a
                    WHERE a.matricula_id = m.id AND a.grupo_id = m.grupo_id
               )
        ');
    }

    public function down(): void
    {
        // La columna sigue estando y sigue siendo la que manda, asi que aqui no
        // se pierde nada: lo que hay en esta tabla es una copia suya.
        Schema::dropIfExists('asignaciones_grupo');
    }
};
