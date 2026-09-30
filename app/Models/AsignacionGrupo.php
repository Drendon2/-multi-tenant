<?php

namespace App\Models;

use App\Models\Concerns\DeLaInstitucion;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Fila de `asignaciones_grupo` (una matricula en un grupo).
 *
 * Existe solo para que `sync()` y `attach()` pasen por un modelo: sin el, el
 * puente se escribe con un INSERT suelto que no dispara eventos, la fila
 * nace sin `institucion_id` y la base la rechaza.
 */
class AsignacionGrupo extends Pivot
{
    use DeLaInstitucion;

    protected $table = 'asignaciones_grupo';

    public $incrementing = true;
}
