<?php

namespace App\Models\Concerns;

use App\Support\InstitucionActual;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * El modelo vive en una institucion: solo ve las filas de la actual y las
 * nuevas nacen en ella.
 *
 * El filtro no se escribe aqui: se le pide a `InstitucionActual::filtrar()`,
 * que es el unico sitio donde existe. El alcance se registra con nombre para
 * que un `withoutGlobalScope` de OTRO alcance no se lo lleve; quitarlo a
 * proposito es `sinFiltroDeInstitucion()`, y solo para leer una fila por su
 * token antes de saber de quien es.
 */
trait DeLaInstitucion
{
    public const ALCANCE_INSTITUCION = 'institucion';

    public static function bootDeLaInstitucion(): void
    {
        static::addGlobalScope(self::ALCANCE_INSTITUCION, function (Builder $consulta) {
            InstitucionActual::filtrar(
                $consulta->getQuery(),
                InstitucionActual::alias((string) $consulta->getQuery()->from)
            );
        });

        static::creating(function (Model $modelo) {
            if ($modelo->getAttribute(InstitucionActual::COLUMNA) === null) {
                $modelo->setAttribute(InstitucionActual::COLUMNA, InstitucionActual::id());
            }
        });
    }

    /**
     * Sin el filtro: SOLO para resolver un enlace publico (token) antes de
     * saber de que institucion es. Quien lo llame tiene que adoptar despues la
     * institucion de la fila (`InstitucionActual::adoptar()`).
     *
     * @return Builder<static>
     */
    public static function sinFiltroDeInstitucion(): Builder
    {
        return static::query()->withoutGlobalScope(self::ALCANCE_INSTITUCION);
    }

    /** La institucion duena de esta fila. */
    public function institucionId(): int
    {
        return (int) $this->getAttribute(InstitucionActual::COLUMNA);
    }
}
