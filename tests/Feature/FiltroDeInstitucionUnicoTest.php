<?php

namespace Tests\Feature;

use App\Models\Concerns\DeLaInstitucion;
use App\Support\InstitucionActual;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\ExpectationFailedException;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * El aislamiento entre instituciones lo sostiene el MOTOR, y nada lo rodea.
 *
 * Desde el paso 3 (01/10/2026) cada tabla de datos tiene Row Level Security:
 * una politica que solo deja ver y escribir las filas de la institucion de la
 * sesion. Eso solo vale si de verdad esta en TODAS las tablas y si la
 * aplicacion entra con un rol al que le aplica: una tabla sin politica, o un
 * rol dueño de las tablas, no fallan ni avisan, y devuelven lo de todas las
 * instituciones.
 *
 * Antes de RLS esta guardia leia el CODIGO buscando consultas que rodearan
 * `InstitucionActual::filtrar()`. Ahora lee el ESQUEMA, que es donde vive el
 * aislamiento, y del codigo solo mira lo que RLS no cubre: las tablas que no
 * lo tienen (`InstitucionActual::SIN_RLS`, vacia desde el paso 4a) y la
 * conexion del dueño.
 *
 * Lee el catalogo y el codigo reales, no una lista escrita a mano: una tabla o
 * un modelo nuevos entran solos en la comprobacion.
 */
class FiltroDeInstitucionUnicoTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Las unicas excepciones del codigo, cada una con su porque. Clave:
     * archivo relativo a app/; valor: el patron permitido ahi.
     */
    private const EXCEPCIONES = [
        // Donde vive el filtro que queda para las tablas sin RLS.
        'Support/InstitucionActual.php' => '*',
    ];

    /**
     * Las tablas sin RLS que vigila la guardia del codigo. Null es
     * `InstitucionActual::SIN_RLS`; la autoprueba de la guardia finge una para
     * ver que la regla caza aunque hoy la lista este vacia.
     *
     * @var list<string>|null
     */
    private ?array $sinRls = null;

    /**
     * Tablas de Laravel, la de instituciones y la de operadores: no llevan
     * institucion. Los operadores del panel no son de ninguna (paso 4b,
     * decision del usuario del 02/10/2026).
     */
    private const DEL_FRAMEWORK = ['migrations', 'cache', 'cache_locks', 'jobs', 'job_batches',
        'failed_jobs', 'sessions', 'password_reset_tokens', 'instituciones', 'operadores'];

    public function test_toda_tabla_de_datos_lleva_institucion_id(): void
    {
        $sin = collect($this->tablas())
            ->reject(fn ($t) => in_array($t, self::DEL_FRAMEWORK, true))
            ->reject(fn ($t) => in_array($t, $this->tablasConInstitucion(), true))
            ->values()->all();

        $this->assertSame([], $sin, 'Tablas de datos sin institucion_id: '.implode(', ', $sin));
    }

    /**
     * Las filas nuevas nacen en la institucion actual por el trait: la columna
     * no tiene valor por defecto, a proposito.
     */
    public function test_toda_tabla_con_institucion_tiene_un_modelo_que_la_pone(): void
    {
        $conTrait = collect($this->modelos())
            ->filter(fn (string $clase) => in_array(DeLaInstitucion::class, class_uses_recursive($clase), true))
            ->map(fn (string $clase) => (new $clase)->getTable())
            ->all();

        // `restablecimientos_clave` se escribe sin modelo y con la institucion
        // de la cuenta (ver `RestablecerClave::crear()`).
        $sinModelo = array_values(array_diff($this->tablasConInstitucion(), $conTrait, ['restablecimientos_clave']));

        $this->assertSame([], $sinModelo, 'Tablas con institucion_id y sin modelo con DeLaInstitucion: '.implode(', ', $sinModelo));
    }

    /**
     * LA GUARDIA: cada tabla con `institucion_id` tiene RLS activo y su
     * politica, con la condicion de siempre. Y las que NO lo tienen son
     * exactamente las que `InstitucionActual` sigue filtrando en PHP: si
     * alguna se quedara sin ninguna de las dos cosas, enseñaria lo de todas.
     */
    public function test_toda_tabla_con_institucion_tiene_rls_menos_las_que_filtra_php(): void
    {
        $conRls = collect(DB::select(
            "SELECT c.relname AS t FROM pg_class c
               JOIN pg_namespace n ON n.oid = c.relnamespace
              WHERE n.nspname = current_schema() AND c.relkind = 'r' AND c.relrowsecurity"
        ))->pluck('t')->all();

        $sinRls = array_values(array_diff($this->tablasConInstitucion(), $conRls));
        sort($sinRls);

        $this->assertSame(
            InstitucionActual::SIN_RLS,
            $sinRls,
            'Las tablas con institucion_id SIN RLS tienen que ser las de InstitucionActual::SIN_RLS.'
        );

        $politicas = collect(DB::select(
            "SELECT tablename AS t, qual, with_check FROM pg_policies
              WHERE schemaname = current_schema() AND policyname = 'por_institucion'"
        ))->keyBy('t');

        foreach (array_diff($this->tablasConInstitucion(), InstitucionActual::SIN_RLS) as $tabla) {
            $politica = $politicas->get($tabla);

            $this->assertNotNull($politica, "La tabla {$tabla} tiene RLS pero no la politica por_institucion.");
            $this->assertStringContainsString('institucion_de_la_sesion()', (string) $politica->qual, "{$tabla}: la politica no filtra por la institucion de la sesion.");
            $this->assertStringContainsString('institucion_de_la_sesion()', (string) $politica->with_check, "{$tabla}: la politica no impide escribir filas de otra institucion.");
        }
    }

    /**
     * RLS no le aplica a un superusuario, a un rol con BYPASSRLS ni al DUEÑO
     * de la tabla. La aplicacion no puede ser ninguno de los tres.
     */
    public function test_la_aplicacion_entra_con_un_rol_al_que_rls_le_aplica(): void
    {
        $rol = DB::selectOne('SELECT current_user AS nombre, rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');

        $this->assertFalse($rol->rolsuper, 'La aplicacion entra como superusuario: RLS no le aplica.');
        $this->assertFalse($rol->rolbypassrls, 'La aplicacion entra con BYPASSRLS.');

        $suyas = collect(DB::select(
            'SELECT tablename FROM pg_tables WHERE schemaname = current_schema() AND tableowner = current_user'
        ))->pluck('tablename')->all();

        $this->assertSame([], $suyas, "La aplicacion ({$rol->nombre}) es dueña de: ".implode(', ', $suyas));
    }

    public function test_el_codigo_no_rodea_lo_que_rls_no_cubre(): void
    {
        $fallos = [];

        foreach ($this->archivos() as $relativo => $codigo) {
            $permitido = self::EXCEPCIONES[$relativo] ?? null;

            if ($permitido === '*') {
                continue;
            }

            foreach ($this->lineas($codigo) as $n => $linea) {
                $sitio = "app/$relativo:$n";

                // La aplicacion NUNCA usa la conexion del dueño: con ella RLS
                // no aplica y se ven todas las instituciones.
                if (str_contains($linea, 'pgsql_dueno')) {
                    $fallos[] = "$sitio  la conexion del dueño se salta RLS: la aplicacion no la usa";
                }

                // Las tablas SIN RLS solo se consultan por el filtro de PHP.
                foreach ($this->sinRls ?? InstitucionActual::SIN_RLS as $tabla) {
                    if (preg_match("/(DB::table|->from|->join)\\(\\s*'{$tabla}\\b/", $linea)) {
                        $fallos[] = "$sitio  '{$tabla}' no tiene RLS: usa InstitucionActual::tabla('{$tabla}')";
                    }
                }

                // Nadie escribe su propio where por institucion: o lo pone el
                // motor, o `InstitucionActual::filtrar()`.
                if (preg_match("/where\\w*\\(\\s*['\"](\\w+\\.)?institucion_id['\"]/", $linea)) {
                    $fallos[] = "$sitio  where institucion_id escrito a mano: lo pone RLS (o InstitucionActual::filtrar())";
                }
            }
        }

        $this->assertSame([], $fallos, "Codigo que rodea el aislamiento:\n  ".implode("\n  ", $fallos));
    }

    /**
     * La guardia de arriba tiene que ver lo que dice ver. Se le pasa codigo
     * con cada forma de rodear el aislamiento y tiene que cazarlas todas: sin
     * esto, un patron mal escrito la deja en verde para siempre.
     */
    public function test_la_guardia_caza_cada_forma_de_rodear_el_aislamiento(): void
    {
        $trampas = [
            "DB::connection('pgsql_dueno')->table('matriculas')->get();",
            "Area::where('institucion_id', 2)->get();",
        ];

        foreach ($trampas as $trampa) {
            $this->assertNotSame([], $this->fallosDe($trampa), "La guardia no caza: $trampa");
        }

        // Una tabla sin RLS consultada suelta. Hoy no hay ninguna, asi que se
        // finge una: sin esto, la regla podria romperse y nadie lo veria hasta
        // el dia que hiciera falta.
        $this->sinRls = ['tabla_sin_rls'];

        try {
            $this->assertNotSame([], $this->fallosDe("DB::table('tabla_sin_rls')->count();"));
            $this->assertNotSame([], $this->fallosDe("\$q->join('tabla_sin_rls', 'a.id', '=', 'b.id');"));
            $this->assertSame([], $this->fallosDe("InstitucionActual::tabla('tabla_sin_rls')->count();"));
        } finally {
            $this->sinRls = null;
        }

        // Y no protesta por lo que ahora es correcto: una tabla CON RLS se
        // consulta como sea, `users` incluida desde el paso 4a.
        $this->assertSame([], $this->fallosDe("DB::table('matriculas')->get();"));
        $this->assertSame([], $this->fallosDe("User::whereNull('email')->count();"));
    }

    // ------------------------------------------------------------------

    /** @return list<string> */
    private function fallosDe(string $linea): array
    {
        $original = $this->archivosFalsos;
        $this->archivosFalsos = ['Falso.php' => "<?php\n$linea\n"];

        try {
            $this->test_el_codigo_no_rodea_lo_que_rls_no_cubre();

            return [];
        } catch (ExpectationFailedException $e) {
            return [$e->getMessage()];
        } finally {
            $this->archivosFalsos = $original;
        }
    }

    /** @var array<string, string>|null */
    private ?array $archivosFalsos = null;

    /** @return array<string, string> archivo relativo a app/ => codigo */
    private function archivos(): array
    {
        if ($this->archivosFalsos !== null) {
            return $this->archivosFalsos;
        }

        $archivos = [];

        foreach ((new Finder)->files()->in(app_path())->name('*.php') as $f) {
            $archivos[str_replace('\\', '/', $f->getRelativePathname())] = $f->getContents();
        }

        // Las vistas y las rutas tambien pueden consultar; hoy no lo hacen.
        foreach ((new Finder)->files()->in([resource_path('views'), base_path('routes')])->name('*.php') as $f) {
            $archivos['../'.str_replace('\\', '/', $f->getRelativePathname())] = $f->getContents();
        }

        return $archivos;
    }

    /**
     * Lineas de codigo sin comentarios, numeradas desde 1.
     *
     * @return array<int, string>
     */
    private function lineas(string $codigo): array
    {
        $lineas = [];
        $sinComentarios = '';

        foreach (token_get_all($codigo) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                // Conserva los saltos para que los numeros de linea cuadren.
                $sinComentarios .= str_repeat("\n", substr_count($token[1], "\n"));

                continue;
            }
            $sinComentarios .= is_array($token) ? $token[1] : $token;
        }

        foreach (explode("\n", $sinComentarios) as $i => $linea) {
            $lineas[$i + 1] = $linea;
        }

        return $lineas;
    }

    /** @return list<class-string<Model>> */
    private function modelos(): array
    {
        $modelos = [];

        foreach ((new Finder)->files()->in(app_path('Models'))->depth(0)->name('*.php') as $f) {
            $clase = 'App\\Models\\'.Str::beforeLast($f->getFilename(), '.php');

            if (is_subclass_of($clase, Model::class)) {
                $modelos[] = $clase;
            }
        }

        return $modelos;
    }

    /** @return list<string> */
    private function tablas(): array
    {
        return collect(DB::select("SELECT table_name AS t FROM information_schema.tables
            WHERE table_schema = current_schema() AND table_type = 'BASE TABLE'"))->pluck('t')->all();
    }

    /** @return list<string> */
    private function tablasConInstitucion(): array
    {
        return collect(DB::select("SELECT table_name AS t FROM information_schema.columns
            WHERE table_schema = current_schema() AND column_name = 'institucion_id'"))->pluck('t')->all();
    }
}
