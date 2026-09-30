<?php

namespace App\Support;

use App\Models\Institucion;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * La institucion a la que pertenece esta peticion, y EL UNICO SITIO donde se
 * filtra por ella.
 *
 * Todo pasa por `filtrar()`: el alcance global de los modelos
 * (`Models\Concerns\DeLaInstitucion`), las consultas sin modelo (`tabla()`) y
 * las reglas de validacion que van a la base (`Reglas::existe()` y
 * `Reglas::unica()`). Esta hecho asi porque el filtro se va a reemplazar por
 * Row Level Security: el dia que lo haga el motor, se vacia `filtrar()` y
 * nada mas.
 *
 * Quien es la institucion, por orden:
 *
 * 1. La FIJADA en esta peticion: un enlace publico con token (`adoptar()`),
 *    o un comando que trabaja para una institucion concreta (`usar()`).
 * 2. La de la CUENTA con sesion abierta.
 * 3. La POR DEFECTO de la instalacion (`INSTITUCION_POR_DEFECTO`), para quien
 *    llega sin sesion a una pagina publica. Mientras no haya enrutamiento por
 *    dominio, el login, la inscripcion y la politica de datos son de esta.
 *
 * Sin ninguna de las tres, LANZA. Cerrar en falso es a proposito: un filtro
 * que ante la duda no filtra devuelve la casa de todos.
 *
 * La fijada vive en el contenedor y no en una estatica, por lo mismo que
 * `ConfiguracionInstitucion::actual()`: el contenedor muere con la peticion y
 * con cada prueba.
 */
class InstitucionActual
{
    private const FIJADA = 'institucion.actual';

    /** Columna que llevan todas las tablas de datos. */
    public const COLUMNA = 'institucion_id';

    public static function id(): int
    {
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

        throw new RuntimeException('No se sabe de qué institución es esta petición.');
    }

    public static function modelo(): Institucion
    {
        return Institucion::findOrFail(self::id());
    }

    /**
     * EL filtro. Cualquier otro `where institucion_id` en el codigo es un
     * error: la prueba `FiltroUnicoTest` lo busca.
     *
     * `$tabla` es el nombre o el alias con el que la tabla aparece en la
     * consulta; hace falta calificar la columna porque todas las tablas la
     * tienen y en un `join` seria ambigua.
     */
    public static function filtrar(Builder $consulta, string $tabla): Builder
    {
        return $consulta->where($tabla.'.'.self::COLUMNA, self::id());
    }

    /**
     * `DB::table()` ya filtrado. Acepta alias: `tabla('clases as c')`.
     *
     * El filtro va el PRIMERO, y justo antes de ejecutar se agrupa lo demas
     * entre parentesis. Sin eso, un `->where(a)->orWhere(b)` detras daria
     * `institucion AND a OR b`, y la rama del OR saldria de la institucion.
     * Eloquent hace lo mismo con sus alcances; el constructor de consultas no.
     *
     * Lo que NO cubre: usada como SUBconsulta (`whereIn('id', tabla(...))`) se
     * compila sin pasar por `beforeQuery`, asi que ahi un `orWhere` de primer
     * nivel seguiria saliendose. Ninguna lo hace; si alguna lo necesita, que
     * meta sus condiciones en un `where(fn ...)`.
     */
    public static function tabla(string $tabla): Builder
    {
        $consulta = self::filtrar(DB::table($tabla), self::alias($tabla));

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

    /** El nombre con el que se referencia la tabla dentro de la consulta. */
    public static function alias(string $from): string
    {
        $partes = preg_split('/\s+as\s+/i', trim($from));

        return trim(end($partes));
    }

    /**
     * Fija la institucion para el resto de esta peticion o comando.
     */
    public static function usar(int $id): void
    {
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
        self::usar($id);

        try {
            return $trabajo();
        } finally {
            $antes === null ? self::olvidar() : self::usar($antes);
        }
    }

    /**
     * Un enlace publico con token dice de que institucion es: la de su fila.
     *
     * Si hay una sesion abierta de OTRA institucion, el enlace no existe para
     * ella (404) en vez de mezclar las dos en la misma peticion.
     */
    public static function adoptar(int $id): void
    {
        $deLaCuenta = Auth::user()?->institucion_id;

        if ($deLaCuenta !== null && (int) $deLaCuenta !== $id) {
            throw new NotFoundHttpException;
        }

        self::usar($id);
    }
}
