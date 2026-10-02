<?php

namespace Tests\Feature;

use App\Models\Actividad;
use App\Models\InscritoActividad;
use App\Support\InstitucionActual;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Los indices que las pantallas de actividades necesitan (C-06 de la auditoria).
 *
 * La primera prueba es la que vale, y NO comprueba que el indice exista sino
 * que el motor lo ELIJA: pide el plan de la consulta real de la ficha y exige
 * que no haya un nodo `Sort` (lo que en MariaDB era el `filesort`). La diferencia importa porque la tabla ya tenia otro
 * indice que empieza por `actividad_id` --el unico de `(actividad_id,
 * documento)`--, y ese sirve para FILTRAR igual de bien. Lo que decide entre
 * los dos es el `ORDER BY nombre_completo, id`: con el viejo, el motor filtra
 * por indice y ordena las filas en memoria; con el nuevo sale ya ordenado. Un
 * `assertTrue` de que el indice existe pasaria con los dos y no probaria nada.
 *
 * La segunda SI es de existencia, y a proposito. El indice de `actividades`
 * solo lo usa el listado de proyecciones --el de cursos y talleres pide dos de
 * los tres tipos, o sea casi la tabla entera, y ahi el motor acierta barriendo--
 * asi que un plan esperado dependeria de la proporcion entre tipos que haya
 * sembrada. Eso es una prueba que enrojece cuando alguien cambia el fixture,
 * que es justo la clase de prueba que este proyecto ya ha tenido que borrar.
 * Se comprueba lo unico estable: que el indice esta, con sus columnas y en su
 * orden. El razonamiento medido esta en la migracion.
 */
class IndicesActividadTest extends TestCase
{
    use RefreshDatabase;

    /**
     * La lista de inscritos sale ordenada del indice, no de la memoria, con
     * RLS puesto.
     *
     * Se siembran tres actividades y no una: con una sola, `actividad_id = ?`
     * abarca la tabla entera y el motor barre --con razon-- sin mirar ningun
     * indice, y la prueba pasaria por el camino equivocado.
     *
     * Desde el paso 3 (RLS) los datos los siembra y los ANALIZA el DUEÑO, ya
     * confirmados, y el plan lo pide la APLICACION. Es como pasa en
     * produccion: las estadisticas las pone el autovacuum, y la consulta la
     * hace un rol al que RLS le añade su condicion. Con los datos dentro de la
     * transaccion de la prueba no se puede: la aplicacion no es dueña de la
     * tabla y PostgreSQL ignora su `ANALYZE` con un aviso (asi fallo, con el
     * plan sin estadisticas: `Limit, Sort, Seq Scan`), y el dueño no ve las
     * filas de una transaccion ajena. Por eso esta prueba limpia lo suyo.
     */
    public function test_la_ficha_de_una_actividad_no_ordena_a_los_inscritos_en_memoria(): void
    {
        $dueno = DB::connection('pgsql_dueno');

        try {
            $suya = $this->sembrarComoDueno($dueno);
            $dueno->statement('ANALYZE inscritos_actividad');

            // Como la 1, que es donde se sembro: la conexion del dueño no ve
            // la institucion de la prueba, que vive en su transaccion.
            $plan = InstitucionActual::mientras(1, fn () => $this->plan(
                'SELECT * FROM inscritos_actividad WHERE actividad_id = ?'
                .' ORDER BY nombre_completo, id LIMIT 50 OFFSET 0',
                [$suya]
            ));
        } finally {
            $this->limpiarComoDueno($dueno);
        }

        $this->assertNotContains(
            'Sort',
            $plan['nodos'],
            'La ficha ordena a los inscritos en memoria: el motor entro por otro '
            .'indice o por ninguno (nodos: '.implode(', ', $plan['nodos']).').'
        );

        $this->assertContains('inscritos_por_actividad_y_nombre', $plan['indices']);
    }

    public function test_las_actividades_tienen_indice_por_tipo_y_nombre(): void
    {
        $this->assertSame(
            ['tipo', 'nombre'],
            $this->columnasDe('actividades', 'actividades_por_tipo_y_nombre')
        );
    }

    public function test_los_inscritos_tienen_indice_por_actividad_y_nombre(): void
    {
        $this->assertSame(
            ['actividad_id', 'nombre_completo', 'id'],
            $this->columnasDe('inscritos_actividad', 'inscritos_por_actividad_y_nombre')
        );
    }

    // -----------------------------------------------------------------------

    /**
     * Los nodos y los indices del plan que PostgreSQL elige para una consulta.
     *
     * Lo pide la conexion de la aplicacion, asi que lleva la condicion de RLS.
     *
     * @param  array<int, mixed>  $enlaces
     * @return array{nodos: list<string>, indices: list<string>}
     */
    private function plan(string $sql, array $enlaces = []): array
    {
        $fila = (array) DB::select('EXPLAIN (FORMAT JSON) '.$sql, $enlaces)[0];
        $raiz = json_decode((string) reset($fila), true)[0]['Plan'];

        $plan = ['nodos' => [], 'indices' => []];
        $recorrer = function (array $nodo) use (&$recorrer, &$plan): void {
            $plan['nodos'][] = $nodo['Node Type'];

            if (isset($nodo['Index Name'])) {
                $plan['indices'][] = $nodo['Index Name'];
            }

            foreach ($nodo['Plans'] ?? [] as $hijo) {
                $recorrer($hijo);
            }
        };
        $recorrer($raiz);

        return $plan;
    }

    /**
     * Las columnas de un indice, en su orden.
     *
     * Por el catalogo y no por el `Schema` de Laravel: hace falta el orden
     * dentro del indice, que es lo que decide si sirve, y quien lo dice es la
     * posicion en `pg_index.indkey`.
     *
     * @return list<string>
     */
    private function columnasDe(string $tabla, string $indice): array
    {
        $filas = DB::select(
            'SELECT a.attname AS columna
               FROM pg_index x
               JOIN pg_class i ON i.oid = x.indexrelid
               JOIN pg_class t ON t.oid = x.indrelid
               JOIN unnest(x.indkey) WITH ORDINALITY AS k(attnum, posicion) ON true
               JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = k.attnum
              WHERE t.relname = ? AND i.relname = ?
              ORDER BY k.posicion',
            [$tabla, $indice]
        );

        return array_map(fn (object $f) => $f->columna, $filas);
    }

    /** Ids altos para no chocar con nada de lo que siembre la prueba. */
    private const PRIMER_ID = 900001;

    /**
     * Tres actividades de 150 inscritos en la institucion 1, CONFIRMADAS.
     * Devuelve el id de la primera.
     *
     * Nombres desordenados respecto al id: si se sembraran en orden
     * alfabetico, ordenar por `id` daria el mismo resultado que ordenar por
     * nombre y un plan que ordena en memoria se veria correcto.
     */
    private function sembrarComoDueno(ConnectionInterface $dueno): int
    {
        $id = self::PRIMER_ID;

        $dueno->insert('INSERT INTO users (id, institucion_id, username, password) VALUES (?, 1, ?, ?)', [$id, 'plan_indices', 'x']);
        $dueno->insert('INSERT INTO perfiles (id, institucion_id, user_id, rol, nombre_completo) VALUES (?, 1, ?, ?, ?)', [$id, $id, 'administrador', 'Plan']);

        foreach ([0, 1, 2] as $n) {
            $dueno->insert(
                'INSERT INTO actividades (id, institucion_id, tipo, nombre, responsable_id, token) VALUES (?, 1, ?, ?, ?, ?)',
                [$id + $n, Actividad::TALLER, "Plan {$n}", $id, str_repeat((string) $n, 32)]
            );
            $dueno->insert(
                "INSERT INTO inscritos_actividad (institucion_id, actividad_id, nombre_completo, documento, origen)
                 SELECT 1, ?, 'Persona ' || lpad(((g * 7) % 150)::text, 4, '0'), ? || '-' || g, ?
                   FROM generate_series(1, 150) g",
                [$id + $n, (string) $n, InscritoActividad::ENLACE]
            );
        }

        return $id;
    }

    private function limpiarComoDueno(ConnectionInterface $dueno): void
    {
        $id = self::PRIMER_ID;

        $dueno->delete('DELETE FROM inscritos_actividad WHERE actividad_id BETWEEN ? AND ?', [$id, $id + 2]);
        $dueno->delete('DELETE FROM actividades WHERE id BETWEEN ? AND ?', [$id, $id + 2]);
        $dueno->delete('DELETE FROM perfiles WHERE id = ?', [$id]);
        $dueno->delete('DELETE FROM users WHERE id = ?', [$id]);
    }
}
