<?php

namespace App\Models\Concerns;

use App\Support\InstitucionActual;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * El modelo vive en una institucion: solo ve las filas de la actual y las
 * nuevas nacen en ella.
 *
 * Lo que ve lo decide la BASE desde el paso 3 (Row Level Security, ver
 * `InstitucionActual`): el alcance global sigue pasando por
 * `InstitucionActual::filtrar()`, que esta vacia, para que el sitio siga
 * siendo uno solo. Lo que SI hace este trait es poner la institucion a las
 * filas nuevas: la columna no tiene valor por defecto a proposito, y RLS
 * rechaza una fila con la institucion de otra.
 *
 * Ya no hay forma de leer «sin el filtro» desde aqui: RLS no se quita con un
 * `withoutGlobalScope`. Un enlace con token pregunta de que institucion es con
 * `InstitucionActual::deEnlace()`.
 */
trait DeLaInstitucion
{
    public const ALCANCE_INSTITUCION = 'institucion';

    public static function bootDeLaInstitucion(): void
    {
        static::addGlobalScope(self::ALCANCE_INSTITUCION, function (Builder $consulta) {
            InstitucionActual::filtrar($consulta->getQuery(), (string) $consulta->getQuery()->from);
        });

        static::creating(function (Model $modelo) {
            if ($modelo->getAttribute(InstitucionActual::COLUMNA) === null) {
                $modelo->setAttribute(InstitucionActual::COLUMNA, InstitucionActual::id());
            }
        });
    }

    /** La institucion duena de esta fila. */
    public function institucionId(): int
    {
        return (int) $this->getAttribute(InstitucionActual::COLUMNA);
    }
}
