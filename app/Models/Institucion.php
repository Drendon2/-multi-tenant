<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una entidad cliente del sistema. Cada fila de datos cuelga de una
 * (`institucion_id`); el filtro vive en `App\Support\InstitucionActual`.
 *
 * NO es `InstitucionExterna`: esas son las escuelas y colegios donde un
 * profesor de la casa dicta un programa externo, y pertenecen a una
 * institucion como cualquier otro dato.
 *
 * Esta tabla no se filtra: es la que define el filtro.
 *
 * @property int $id
 * @property string $nombre
 * @property string|null $subdominio
 * @property string $estado
 */
class Institucion extends Model
{
    public const ACTIVA = 'activa';

    public const SUSPENDIDA = 'suspendida';

    protected $table = 'instituciones';

    public $timestamps = false;

    protected $fillable = ['nombre', 'subdominio', 'estado', 'fecha_alta'];

    protected $attributes = ['estado' => self::ACTIVA];

    protected function casts(): array
    {
        return ['fecha_alta' => 'datetime'];
    }
}
