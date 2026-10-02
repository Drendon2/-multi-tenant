<?php

namespace App\Support;

use App\Models\Institucion;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use WeakMap;

/**
 * La institucion a la que pertenece esta peticion, y como se entera la BASE.
 *
 * Desde el paso 3 (01/10/2026) el aislamiento entre instituciones lo hace
 * PostgreSQL con Row Level Security: cada tabla de datos tiene una politica que
 * solo deja ver y escribir las filas de `institucion_de_la_sesion()`, que lee
 * la variable de sesion `app.institucion_id` (ver
 * `database/sql/postgres/02-rls.sql`). Lo que hace esta clase es decirle a la
 * base, antes de cada consulta, de que institucion es la peticion
 * (`alConsultar()`).
 *
 * `filtrar()` queda VACIA para las tablas con RLS, como estaba previsto: era
 * el unico `where institucion_id` del codigo y ahora lo pone el motor. Sigue
 * filtrando, en PHP, las tablas que NO tienen RLS (`SIN_RLS`, vacia desde el
 * paso 4a). El sitio sigue siendo uno solo.
 *
 * Quien es la institucion, por orden:
 *
 * 1. La FIJADA. En una peticion web la fija SIEMPRE el middleware
 *    `InstitucionPorDominio` con la del host (`delHost()`), antes de que nada
 *    consulte; un comando la fija con `usar()` o `mientras()`.
 * 2. La de la CUENTA con sesion. En la web ya no se llega aqui; queda para
 *    las pruebas que trabajan fuera de una peticion despues de `actingAs`.
 * 3. La POR DEFECTO de la instalacion (`INSTITUCION_POR_DEFECTO`): la de la
 *    consola cuando el comando no dice otra.
 *
 * Sin ninguna de las tres, `id()` LANZA y la base no recibe ninguna: sus
 * politicas dan NULL y no sale ninguna fila. Cerrar en falso es a proposito.
 *
 * La fijada vive en el contenedor y no en una estatica, por lo mismo que
 * `ConfiguracionInstitucion::actual()`: el contenedor muere con la peticion y
 * con cada prueba.
 */
class InstitucionActual
{
    private const FIJADA = 'institucion.actual';

    /** La peticion es del panel de todas: de ninguna institucion. */
    private const NINGUNA = 'institucion.ninguna';

    /** Columna que llevan todas las tablas de datos. */
    public const COLUMNA = 'institucion_id';

    /** La variable de sesion que leen las politicas de RLS. */
    public const VARIABLE = 'app.institucion_id';

    /**
     * Las tablas con `institucion_id` que NO tienen RLS, y que por eso siguen
     * filtrandose aqui.
     *
     * VACIA desde el paso 4a (02/10/2026). Hasta entonces estaba `users`, que
     * se quedo fuera de RLS porque la institucion de la peticion salia de la
     * cuenta. Desde que la dice el dominio, `users` tiene RLS como las demas
     * (`03-dominios.sql`). El mecanismo se queda: es donde iria una tabla que
     * algun dia tuviera que quedarse fuera.
     *
     * Tiene que coincidir con lo que la base deja fuera:
     * `FiltroDeInstitucionUnicoTest` lo comprueba contra el catalogo.
     *
     * @var list<string>
     */
    public const SIN_RLS = [];

    /**
     * Lo que ya se le dijo a cada conexion: la institucion, y el PDO al que se
     * le dijo. Un PDO nuevo (una reconexion) es una sesion nueva de la base, sin
     * la variable puesta.
     *
     * Clave: la `Connection`; valor: `array{pdo: int, id: ?int}`. Sin el
     * generico en la anotacion: PHPStan trata `WeakMap` como invariante y no
     * acepta que se le asigne una entrada.
     */
    private static ?WeakMap $dicha = null;

    /** Para no volver a entrar mientras se averigua la institucion. */
    private static bool $resolviendo = false;

    public static function id(): int
    {
        return self::idSiSeSabe()
            ?? throw new RuntimeException('No se sabe de qué institución es esta petición.');
    }

    /** Como `id()`, pero null en vez de lanzar. */
    public static function idSiSeSabe(): ?int
    {
        if (app()->bound(self::NINGUNA)) {
            return null;
        }

        if (app()->bound(self::FIJADA)) {
            return app()->make(self::FIJADA);
        }

        $deLaCuenta = Auth::user()?->institucion_id;

        if ($deLaCuenta !== null) {
            return (int) $deLaCuenta;
        }

        $porDefecto = config('institucion.por_defecto');

        if ($porDefecto !== null && $porDefecto !== '') {
            return (int) $porDefecto;
        }

        return null;
    }

    public static function modelo(): Institucion
    {
        return Institucion::findOrFail(self::id());
    }

    /**
     * La institucion de un host, o null si no es de ninguna.
     *
     * Sin dominio base la instalacion es de UNA sola casa y todos los hosts son
     * de la institucion por defecto (decision del usuario, 02/10/2026). Con
     * dominio base: el dominio propio de alguna, o `<subdominio>.<base>`. Un
     * host que no es de nadie NO cae en la por defecto: eso enseñaria la
     * pantalla de entrar de una casa a quien escribio mal el nombre de otra.
     *
     * `instituciones` no tiene RLS (es la que lo define), y la consulta va con
     * el gancho apagado: todavia no hay institucion que decirle a la base, y
     * averiguarla preguntaria por la cuenta antes de tiempo.
     */
    public static function delHost(string $host): ?Institucion
    {
        $host = strtolower(rtrim($host, '.'));
        $base = strtolower(trim((string) config('institucion.dominio_base')));

        self::$resolviendo = true;

        try {
            if ($base === '') {
                $porDefecto = config('institucion.por_defecto');

                return $porDefecto === null || $porDefecto === ''
                    ? null
                    : Institucion::find((int) $porDefecto);
            }

            $propia = Institucion::where('dominio_propio', $host)->first();

            if ($propia !== null) {
                return $propia;
            }

            if (! str_ends_with($host, '.'.$base)) {
                return null;
            }

            $sub = substr($host, 0, -strlen('.'.$base));

            // Un solo nivel: `a.b.<base>` no es de nadie.
            if ($sub === '' || str_contains($sub, '.')) {
                return null;
            }

            return Institucion::where('subdominio', $sub)->first();
        } finally {
            self::$resolviendo = false;
        }
    }

    /**
     * Le dice a la base de que institucion es la peticion. Corre antes de CADA
     * consulta (`DB::beforeExecuting`, en `AppServiceProvider`) y solo habla con
     * la base cuando algo cambio, asi que cuesta una consulta por peticion.
     *
     * Antes de cada consulta y no una vez al empezar, porque la institucion
     * CAMBIA a mitad de camino: un enlace con token la adopta, `instalar
     * --nueva` trabaja como otra, una prueba cambia de cuenta.
     *
     * Tres cosas que no se ven y que este metodo sostiene:
     *
     * - Averiguar la institucion puede cargar la cuenta (`Auth::user()`), y esa
     *   consulta vuelve a pasar por aqui. Mientras se averigua no se hace nada:
     *   esa consulta es a `users`, que no tiene RLS.
     * - La variable se pone directamente en el PDO y no con `DB::select`: asi
     *   no vuelve a entrar aqui, y las pruebas que CUENTAN consultas siguen
     *   contando las de la aplicacion.
     * - Una variable puesta dentro de una transaccion que se deshace VUELVE
     *   ATRAS en la base. `olvidar()` borra lo recordado cuando eso pasa (lo
     *   llama el evento `TransactionRolledBack`); sin eso, las consultas de
     *   despues irian sin institucion y no verian nada, sin fallar.
     */
    public static function alConsultar(Connection $conexion): void
    {
        if (self::$resolviendo) {
            return;
        }

        self::$resolviendo = true;

        try {
            $id = self::idSiSeSabe();
        } finally {
            self::$resolviendo = false;
        }

        $pdo = $conexion->getPdo();
        self::$dicha ??= new WeakMap;
        /** @var array{pdo: int, id: ?int}|null $antes */
        $antes = self::$dicha[$conexion] ?? null;
        $mismoPdo = $antes !== null && $antes['pdo'] === spl_object_id($pdo);

        if ($mismoPdo && $antes['id'] === $id) {
            return;
        }

        // Una sesion recien abierta ya esta sin institucion: no hace falta
        // decirselo.
        if ($id !== null || $mismoPdo) {
            $pdo->prepare('SELECT set_config(?, ?, false)')
                ->execute([self::VARIABLE, $id === null ? '' : (string) $id]);
        }

        self::$dicha[$conexion] = ['pdo' => spl_object_id($pdo), 'id' => $id];
    }

    /** La conexion deshizo una transaccion: lo que se le dijo puede no valer. */
    public static function olvidarLoDicho(Connection $conexion): void
    {
        if (self::$dicha !== null) {
            unset(self::$dicha[$conexion]);
        }
    }

    /**
     * El filtro por institucion.
     *
     * VACIO para las tablas con RLS desde el paso 3: la condicion la pone el
     * motor. Para las de `SIN_RLS` sigue siendo el `where` de siempre.
     *
     * Todo lo que filtraba sigue pasando por aqui (el alcance global de los
     * modelos, `tabla()`, `Reglas::existe()` y `Reglas::unica()`).
     *
     * `$from` es la tabla como aparece en la consulta, con su alias si lo
     * lleva (`clases as c`): hace falta el nombre de verdad para saber si
     * tiene RLS, y el alias para calificar la columna en un `join`.
     */
    public static function filtrar(Builder $consulta, string $from): Builder
    {
        if (! self::sinRls(self::nombre($from))) {
            return $consulta;
        }

        return $consulta->where(self::alias($from).'.'.self::COLUMNA, self::id());
    }

    /**
     * `DB::table()`, con el filtro si la tabla no tiene RLS.
     *
     * En ese caso el filtro va el PRIMERO, y justo antes de ejecutar se agrupa
     * lo demas entre parentesis. Sin eso, un `->where(a)->orWhere(b)` detras
     * daria `institucion AND a OR b`, y la rama del OR saldria de la
     * institucion. Con RLS no hace falta: la condicion del motor va FUERA de la
     * consulta y ningun `orWhere` la alcanza.
     *
     * Lo que NO cubre: usada como SUBconsulta se compila sin pasar por
     * `beforeQuery`, asi que ahi un `orWhere` de primer nivel seguiria
     * saliendose. Hoy no hay ninguna sobre `users`.
     */
    public static function tabla(string $tabla): Builder
    {
        $consulta = self::filtrar(DB::table($tabla), $tabla);

        if (! self::sinRls(self::nombre($tabla))) {
            return $consulta;
        }

        return $consulta->beforeQuery(function (Builder $consulta) {
            $resto = array_slice($consulta->wheres, 1);

            if (! collect($resto)->contains(fn ($w) => str_contains($w['boolean'], 'or'))) {
                return;
            }

            $filtro = $consulta->wheres[0];
            $bindings = $consulta->bindings['where'];

            $grupo = $consulta->forNestedWhere();
            $grupo->wheres = $resto;
            $grupo->bindings['where'] = array_slice($bindings, 1);

            $consulta->wheres = [$filtro];
            $consulta->bindings['where'] = [$bindings[0]];
            $consulta->addNestedWhereQuery($grupo);
        });
    }

    /**
     * ¿Esta tabla se queda fuera de RLS?
     *
     * Por un metodo y no leyendo `SIN_RLS` en linea: con la lista vacia, el
     * analisis estatico da la rama del filtro por inalcanzable y cada
     * `filtrar()` por una llamada sin efecto. El mecanismo tiene que seguir
     * analizandose como lo que es, para el dia que la lista vuelva a tener algo.
     * Por eso tambien la lista entra como argumento: leida aqui dentro, la
     * misma deduccion se haria en este metodo.
     *
     * @param  list<string>|null  $lista  la lista a mirar; sin ella, `SIN_RLS`
     */
    public static function sinRls(string $tabla, ?array $lista = null): bool
    {
        return in_array($tabla, $lista ?? self::SIN_RLS, true);
    }

    /** El nombre de verdad de la tabla, sin alias: `clases as c` es `clases`. */
    public static function nombre(string $from): string
    {
        return trim((string) preg_split('/\s+as\s+/i', trim($from))[0]);
    }

    /** El nombre con el que se referencia la tabla dentro de la consulta. */
    public static function alias(string $from): string
    {
        $partes = preg_split('/\s+as\s+/i', trim($from));

        return trim(end($partes));
    }

    /**
     * De que institucion es un token de enlace publico, o null si no existe.
     *
     * Con RLS una pagina sin sesion solo ve su institucion por defecto, asi que
     * el token no se puede buscar en su tabla. Lo resuelve una funcion de la
     * base que corre como el dueño y devuelve SOLO el numero (ver `02-rls.sql`).
     * Con el numero, `adoptar()`, y lo demas sigue con RLS.
     *
     * @param  'promotoria'|'actividad'|'restablecimiento'  $enlace
     */
    public static function deEnlace(string $enlace, string $token): ?int
    {
        $funcion = match ($enlace) {
            'promotoria' => 'institucion_del_enlace_de_promotoria',
            'actividad' => 'institucion_del_enlace_de_actividad',
            'restablecimiento' => 'institucion_del_restablecimiento',
        };

        $id = DB::selectOne("SELECT {$funcion}(?) AS id", [$token])?->id;

        return $id === null ? null : (int) $id;
    }

    /**
     * Esta peticion no es de NINGUNA institucion: es la del panel de todas
     * (paso 4b). Ni la cuenta ni la por defecto cuentan: `id()` lanza y la base
     * no recibe institucion, asi que una tabla de datos no devuelve ninguna
     * fila. Lo que el panel lee (`instituciones`, `operadores`) no tiene RLS.
     */
    public static function ninguna(): void
    {
        app()->forgetInstance(self::FIJADA);
        app()->instance(self::NINGUNA, true);
    }

    /**
     * Fija la institucion para el resto de esta peticion o comando.
     */
    public static function usar(int $id): void
    {
        // Las dos marcas se excluyen: una peticion es de una institucion o del
        // panel. Sin esto, en las pruebas —que reutilizan el contenedor entre
        // peticiones— la marca del panel sobrevivia a la peticion siguiente.
        app()->forgetInstance(self::NINGUNA);
        app()->instance(self::FIJADA, $id);
    }

    public static function olvidar(): void
    {
        app()->forgetInstance(self::FIJADA);
    }

    /**
     * Corre `$trabajo` como la institucion `$id` y deja todo como estaba.
     *
     * @template T
     *
     * @param  callable(): T  $trabajo
     * @return T
     */
    public static function mientras(int $id, callable $trabajo): mixed
    {
        $antes = app()->bound(self::FIJADA) ? app()->make(self::FIJADA) : null;
        // El panel trabaja asi con una institucion (paso 4c), y al acabar
        // tiene que volver a ser de NINGUNA: `usar()` le quita esa marca.
        $deNinguna = app()->bound(self::NINGUNA);
        self::usar($id);

        try {
            return $trabajo();
        } finally {
            match (true) {
                $deNinguna => self::ninguna(),
                $antes === null => self::olvidar(),
                default => self::usar($antes),
            };
        }
    }

    /**
     * `mientras()` para un GENERADOR: recorre lo que devuelve `$filas` como la
     * institucion `$id`, y al terminar deja todo como estaba.
     *
     * Existe por las descargas del panel (paso 5), que van saliendo fila a fila
     * mientras se escriben: con `mientras()` la institucion se fijaria al
     * CREAR el generador y se soltaria antes de que corriera una sola
     * consulta. Aqui se fija justo antes de pedir la primera fila y se suelta
     * despues de la ultima. `$filas` se llama ya dentro, para que lo que
     * prepare (una lista de papeles, unos agregados) tambien sea de esa
     * institucion.
     *
     * @template T
     *
     * @param  callable(): iterable<T>  $filas
     * @return \Generator<int, T>
     */
    public static function recorriendo(int $id, callable $filas): \Generator
    {
        $antes = app()->bound(self::FIJADA) ? app()->make(self::FIJADA) : null;
        $deNinguna = app()->bound(self::NINGUNA);
        self::usar($id);

        try {
            foreach ($filas() as $fila) {
                yield $fila;
            }
        } finally {
            match (true) {
                $deNinguna => self::ninguna(),
                $antes === null => self::olvidar(),
                default => self::usar($antes),
            };
        }
    }

    /**
     * Un enlace publico con token dice de que institucion es: la de su fila.
     *
     * Si la peticion ya es de OTRA —en la web, la del dominio por el que se
     * abrio—, el enlace no existe aqui (404, decision del usuario, 02/10/2026)
     * en vez de mezclar las dos en la misma peticion. Tampoco se redirige al
     * dominio bueno: diria de que institucion es un token a quien lo prueba
     * en otra.
     */
    public static function adoptar(int $id): void
    {
        $actual = app()->bound(self::FIJADA)
            ? app()->make(self::FIJADA)
            : Auth::user()?->institucion_id;

        if ($actual !== null && (int) $actual !== $id) {
            throw new NotFoundHttpException;
        }

        self::usar($id);
    }
}
