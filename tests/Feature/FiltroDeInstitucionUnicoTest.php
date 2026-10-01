<?php

namespace Tests\Feature;

use App\Models\Concerns\DeLaInstitucion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\ExpectationFailedException;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * El filtro por institucion vive en UN solo sitio, y nada lo rodea.
 *
 * `App\Support\InstitucionActual::filtrar()` es el unico `where
 * institucion_id` del codigo. Lo llaman el alcance de los modelos, `tabla()`
 * y las reglas `Reglas::existe()`/`unica()`. Ese punto unico es lo que se
 * reemplazara por Row Level Security, y solo sirve si de verdad es unico:
 * una consulta escrita por fuera no falla, no avisa y devuelve lo de todas
 * las instituciones.
 *
 * Estas pruebas leen el CODIGO y el ESQUEMA real, no una lista escrita a
 * mano: una tabla o un modelo nuevos entran solos en la comprobacion.
 */
class FiltroDeInstitucionUnicoTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Las unicas excepciones, cada una con su porque. Clave: archivo relativo
     * a app/; valor: el patron permitido ahi.
     */
    private const EXCEPCIONES = [
        // Donde vive el filtro.
        'Support/InstitucionActual.php' => '*',
        // Corre los guiones de migracion; no consulta datos.
        'Support/GuionSql.php' => 'DB::unprepared',
        // El token de «olvide mi contrasena» llega por correo SIN sesion y no
        // dice de que institucion es: se busca en toda la tabla y despues se
        // adopta la de la cuenta (`InstitucionActual::adoptar`).
        'Support/RestablecerClave.php' => 'DB::table(self::TABLA)',
        // `sessions` es de Laravel, no tiene institucion.
        'Support/SupresionDeDatos.php' => "DB::table('sessions')",
    ];

    /** Tablas de Laravel: no llevan institucion. */
    private const DEL_FRAMEWORK = ['migrations', 'cache', 'cache_locks', 'jobs', 'job_batches',
        'failed_jobs', 'sessions', 'password_reset_tokens', 'instituciones'];

    public function test_toda_tabla_de_datos_lleva_institucion_id(): void
    {
        $sin = collect($this->tablas())
            ->reject(fn ($t) => in_array($t, self::DEL_FRAMEWORK, true))
            ->reject(fn ($t) => in_array($t, $this->tablasConInstitucion(), true))
            ->values()->all();

        $this->assertSame([], $sin, 'Tablas de datos sin institucion_id: '.implode(', ', $sin));
    }

    public function test_toda_tabla_con_institucion_tiene_un_modelo_filtrado(): void
    {
        $filtradas = collect($this->modelos())
            ->filter(fn (string $clase) => in_array(DeLaInstitucion::class, class_uses_recursive($clase), true))
            ->map(fn (string $clase) => (new $clase)->getTable())
            ->all();

        // `users` es la identidad con la que se entra: se resuelve ANTES de
        // saber la institucion y por eso no lleva el filtro (ver `User`).
        // `restablecimientos_clave` se lee por un token que llega SIN sesion
        // (ver EXCEPCIONES): tampoco puede llevar el filtro de lectura.
        $sinModelo = array_values(array_diff($this->tablasConInstitucion(), $filtradas, ['users', 'restablecimientos_clave']));

        $this->assertSame([], $sinModelo, 'Tablas con institucion_id y sin modelo con DeLaInstitucion: '.implode(', ', $sinModelo));
    }

    public function test_ninguna_consulta_se_salta_el_filtro(): void
    {
        $datos = $this->tablasConInstitucion();
        $fallos = [];

        foreach ($this->archivos() as $relativo => $codigo) {
            $permitido = self::EXCEPCIONES[$relativo] ?? null;

            if ($permitido === '*') {
                continue;
            }

            foreach ($this->lineas($codigo) as $n => $linea) {
                $sitio = "app/$relativo:$n";

                // DB::table / DB::select / DB::statement... en crudo.
                if (preg_match('/DB::(table|select|selectOne|statement|insert|update|delete|unprepared|scalar|cursor)\b/', $linea, $m)
                    && ! ($permitido !== null && str_contains($linea, $permitido))) {
                    $fallos[] = "$sitio  DB::{$m[1]} en crudo";
                }

                // Reglas de validacion que van a la base sin el filtro.
                if (preg_match("/'(exists|unique):/", $linea)) {
                    $fallos[] = "$sitio  regla '...:' en texto: usa Reglas::existe()/unica()";
                }
                if (preg_match("/Rule::(exists|unique)\\(\\s*'([a-z_]+)'/", $linea, $m)
                    && ! ($m[2] === 'users' && str_contains($linea, "'username'"))
                    && $relativo !== 'Support/Reglas.php') {
                    $fallos[] = "$sitio  Rule::{$m[1]}('{$m[2]}') sin filtro: usa Reglas::existe()/unica()";
                }

                // Subconsultas sobre una tabla de datos que no pasan por filtrar().
                if (preg_match("/->from\\(\\s*'([a-z_]+)/", $linea, $m)
                    && in_array($m[1], $datos, true)
                    && ! str_contains($linea, 'InstitucionActual::filtrar(')) {
                    $fallos[] = "$sitio  ->from('{$m[1]}') sin InstitucionActual::filtrar()";
                }

                // Nadie escribe su propio where por institucion.
                if (preg_match("/where\\w*\\(\\s*['\"](\\w+\\.)?institucion_id['\"]/", $linea)) {
                    $fallos[] = "$sitio  where institucion_id escrito a mano: el filtro vive en InstitucionActual::filtrar()";
                }

                // `User` no lleva el filtro (es la identidad con la que se
                // entra): consultarlo suelto devuelve cuentas de todas. Solo
                // se permite donde se resuelve una cuenta SIN sesion.
                if (preg_match('/\bUser::(where\w*|query|all|count|pluck|first\w*|find\w*|latest|oldest|chunk\w*|lazy\w*|cursor)\(/', $linea, $m)
                    && $relativo !== 'Support/RestablecerClave.php') {
                    $fallos[] = "$sitio  User::{$m[1]}() recorre todas las instituciones: usa InstitucionActual::tabla('users') o llega por Perfil";
                }

                // Quitar TODOS los alcances se lleva el de institucion.
                if (str_contains($linea, 'withoutGlobalScopes(')) {
                    $fallos[] = "$sitio  withoutGlobalScopes() quita tambien el filtro de institucion";
                }

                // Quitar el de institucion solo esta permitido para resolver
                // un enlace por token, y quien lo haga tiene que adoptar.
                if (str_contains($linea, 'sinFiltroDeInstitucion(') && ! str_contains($relativo, 'Concerns/')
                    && ! str_contains($codigo, 'InstitucionActual::adoptar(')) {
                    $fallos[] = "$sitio  sinFiltroDeInstitucion() sin InstitucionActual::adoptar() en el mismo archivo";
                }
            }
        }

        $this->assertSame([], $fallos, "Consultas que rodean el filtro de institucion:\n  ".implode("\n  ", $fallos));
    }

    /**
     * La guardia de arriba tiene que ver lo que dice ver. Se le pasa codigo
     * con cada forma de saltarse el filtro y tiene que cazarlas todas: sin
     * esto, un patron mal escrito la deja en verde para siempre.
     */
    public function test_la_guardia_caza_cada_forma_de_saltarse_el_filtro(): void
    {
        $trampas = [
            "DB::table('matriculas')->get();",
            "'grupo_id' => ['required', 'exists:grupos,id'],",
            "Rule::unique('areas', 'nombre'),",
            "\$q->from('asignaciones_grupo')->whereColumn('a', 'b');",
            "Area::where('institucion_id', 2)->get();",
            'Matricula::withoutGlobalScopes()->get();',
            "User::whereNull('email')->count();",
        ];

        foreach ($trampas as $trampa) {
            $this->assertNotSame([], $this->fallosDe($trampa), "La guardia no caza: $trampa");
        }

        $this->assertSame([], $this->fallosDe("Rule::unique('users', 'username'),"));
        $this->assertSame([], $this->fallosDe("InstitucionActual::tabla('matriculas')->get();"));
    }

    // ------------------------------------------------------------------

    /** @return list<string> */
    private function fallosDe(string $linea): array
    {
        $original = $this->archivosFalsos;
        $this->archivosFalsos = ['Falso.php' => "<?php\n$linea\n"];

        try {
            $this->test_ninguna_consulta_se_salta_el_filtro();

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
