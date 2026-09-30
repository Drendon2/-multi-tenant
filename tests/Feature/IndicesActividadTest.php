<?php

namespace Tests\Feature;

use App\Models\Actividad;
use App\Models\InscritoActividad;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\User;
use App\Support\InstitucionActual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Los indices que las pantallas de actividades necesitan (C-06 de la auditoria).
 *
 * La primera prueba es la que vale, y NO comprueba que el indice exista sino
 * que el motor lo ELIJA: pide el plan de la consulta real de la ficha y exige
 * que no haya `filesort`. La diferencia importa porque la tabla ya tenia otro
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

    private Perfil $admin;

    private Periodo $periodo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->periodo = Periodo::create([
            'nombre' => '2026-1',
            'fecha_inicio' => '2026-01-15',
            'fecha_fin' => '2026-06-30',
            'activo' => true,
            'matriculas_abiertas' => true,
        ]);

        $user = User::create(['username' => 'admin', 'password' => 'x', 'activo' => true]);
        $this->admin = Perfil::create([
            'user_id' => $user->id,
            'rol' => 'administrador',
            'nombre_completo' => 'Admin',
            'fecha_nacimiento' => Carbon::today()->subYears(30)->toDateString(),
            'telefono' => '3000000000',
        ]);
    }

    /**
     * La lista de inscritos sale ordenada del indice, no de la memoria.
     *
     * Se siembran tres actividades y no una: con una sola, `actividad_id = ?`
     * abarca la tabla entera y el motor barre --con razon-- sin mirar ningun
     * indice, y la prueba pasaria por el camino equivocado.
     */
    public function test_la_ficha_de_una_actividad_no_ordena_a_los_inscritos_en_memoria(): void
    {
        $suya = $this->sembrarActividad(Actividad::TALLER, 'Con inscritos', 150);
        $this->sembrarActividad(Actividad::TALLER, 'Otra', 150);
        $this->sembrarActividad(Actividad::CURSO, 'Otra mas', 150);

        $plan = $this->plan(
            'SELECT * FROM inscritos_actividad WHERE actividad_id = ?'
            .' ORDER BY nombre_completo, id LIMIT 50 OFFSET 0',
            [$suya->id]
        );

        $this->assertStringNotContainsStringIgnoringCase(
            'filesort',
            $plan->Extra ?? '',
            'La ficha ordena a los inscritos en memoria: el motor entro por otro '
            ."indice o por ninguno (clave elegida: {$plan->key}, extra: {$plan->Extra})."
        );

        $this->assertSame('inscritos_por_actividad_y_nombre', $plan->key);
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

    /** La fila del plan de ejecucion que MariaDB elige para una consulta. */
    private function plan(string $sql, array $enlaces = []): object
    {
        return DB::select('EXPLAIN '.$sql, $enlaces)[0];
    }

    /**
     * Las columnas de un indice, en su orden.
     *
     * Por `information_schema` y no por el `Schema` de Laravel: hace falta el
     * orden dentro del indice, que es lo que decide si sirve, y quien lo dice
     * es `SEQ_IN_INDEX`. (`SHOW INDEX` tambien lo trae, pero no admite un
     * `ORDER BY` detras de su `WHERE`.)
     *
     * @return list<string>
     */
    private function columnasDe(string $tabla, string $indice): array
    {
        $filas = DB::select(
            'SELECT COLUMN_NAME FROM information_schema.STATISTICS'
            .' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
            .' ORDER BY SEQ_IN_INDEX',
            [$tabla, $indice]
        );

        return array_map(fn (object $f) => $f->COLUMN_NAME, $filas);
    }

    private function sembrarActividad(string $tipo, string $nombre, int $inscritos): Actividad
    {
        $actividad = Actividad::create([
            'tipo' => $tipo,
            'nombre' => $nombre,
            'responsable_id' => $this->admin->id,
            'periodo_id' => $this->periodo->id,
        ]);

        $filas = [];
        for ($i = 0; $i < $inscritos; $i++) {
            $filas[] = [
                // Insercion en crudo: sin el evento del modelo, la institucion
                // va a mano o la base rechaza la fila.
                InstitucionActual::COLUMNA => InstitucionActual::id(),
                'actividad_id' => $actividad->id,
                // Nombres desordenados respecto al id: si se sembraran en orden
                // alfabetico, ordenar por `id` daria el mismo resultado que
                // ordenar por nombre y un plan con filesort se veria correcto.
                'nombre_completo' => 'Persona '.str_pad((string) (($i * 7) % $inscritos), 4, '0', STR_PAD_LEFT),
                'documento' => $actividad->id.'-'.$i,
                'origen' => InscritoActividad::ENLACE,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        DB::table('inscritos_actividad')->insert($filas);

        return $actividad;
    }
}
