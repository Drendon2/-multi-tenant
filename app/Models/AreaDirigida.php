<?php

namespace App\Models;

use App\Models\Concerns\DeLaInstitucion;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Fila de `areas_dirigidas` (un director y un departamento suyo).
 *
 * Mismo motivo que `AsignacionGrupo`: que `sync()` pase por un modelo y la
 * fila nazca con su `institucion_id`.
 */
class AreaDirigida extends Pivot
{
    use DeLaInstitucion;

    protected $table = 'areas_dirigidas';

    public $incrementing = true;
}
