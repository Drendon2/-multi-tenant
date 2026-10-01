<?php

/**
 * Los dos indices que las pantallas de actividades piden y no tenian (C-06).
 *
 * Los dos son COMPUESTOS, y no los de una columna que el hallazgo proponia. La
 * diferencia no es de gusto: un indice suelto en `inscritos_actividad.
 * nombre_completo` no lo elegiria el optimizador nunca, porque no hay una sola
 * consulta que ordene por ese campo sin filtrar antes por `actividad_id`. Las
 * dos que hay --la ficha de la actividad y la hoja de pasar lista-- son
 *
 *     WHERE actividad_id = ? ORDER BY nombre_completo, id
 *
 * y hasta hoy se resolvian entrando por el unico `(actividad_id, documento)` y
 * ordenando el resultado en memoria. El compuesto las sirve enteras: filtra por
 * la primera columna, sale ya ordenado por la segunda y la tercera, y ademas
 * cubre el desempate por `id` del que depende que la paginacion no reparta
 * filas repetidas.
 *
 * El de `actividades` lleva `(tipo, nombre)` porque los dos listados de Gestion
 * son `WHERE tipo IN (...) ORDER BY nombre`. Este solo lo usa UNO de los dos, y
 * conviene saber cual antes de creer que arregla ambos: medido con EXPLAIN
 * sobre 600 filas sembradas y revertidas,
 *
 *   - Proyecciones (1 tipo, ~7% de la tabla) entra por el indice: `ref`, 40
 *     filas leidas, sin filesort.
 *   - Cursos y talleres (2 tipos de 3, ~93%) lo ignora y hace barrido completo
 *     con filesort. Eso es lo CORRECTO: leer 560 de 600 filas por el indice son
 *     560 saltos aleatorios, mas caro que barrer y ordenar.
 *
 * O sea que el indice sirve al listado pequeno y es inerte en el grande, y esa
 * proporcion no mejora con el tamano: solo hay tres tipos, y el listado grande
 * siempre pide dos. Se queda por el primero.
 *
 * Solo indices: ni columnas nuevas, ni datos tocados, ni un `CHECK` mas. La
 * reversion es tirarlos.
 *
 * Hoy las dos tablas son diminutas y esto no se nota en el reloj --que es por
 * lo que el informe lo dejaba para "cuando pasen de unos cientos de filas"--.
 * Se adelanta porque el coste es nulo ahora y porque el sitio donde duele es
 * el hosting compartido, no esta maquina.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('actividades', function (Blueprint $table) {
            $table->index(['tipo', 'nombre'], 'actividades_por_tipo_y_nombre');
        });

        Schema::table('inscritos_actividad', function (Blueprint $table) {
            $table->index(
                ['actividad_id', 'nombre_completo', 'id'],
                'inscritos_por_actividad_y_nombre'
            );
        });
    }

    public function down(): void
    {
        Schema::table('actividades', function (Blueprint $table) {
            $table->dropIndex('actividades_por_tipo_y_nombre');
        });

        Schema::table('inscritos_actividad', function (Blueprint $table) {
            $table->dropIndex('inscritos_por_actividad_y_nombre');
        });
    }
};
