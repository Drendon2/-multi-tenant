<?php

namespace App\Models;

use App\Models\Concerns\DeLaInstitucion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Una semana sin clase de un programa externo que administracion ya atendio.
 *
 * La alerta se calcula (ver `Alertas::semanasSinClaseExterna`); esta fila solo
 * guarda la causa que alguien le puso. Las causas son las de los grupos
 * (`OmisionArchivada::CAUSAS`) y la semana se guarda por su lunes.
 *
 * @property Carbon $semana
 * @property string $causa
 */
class OmisionExterna extends Model
{
    use DeLaInstitucion;

    protected $table = 'omisiones_externas';

    protected $fillable = ['actividad_id', 'semana', 'causa', 'clasificada_por_id'];

    protected function casts(): array
    {
        return ['semana' => 'date'];
    }

    /** @return BelongsTo<Actividad, $this> */
    public function actividad(): BelongsTo
    {
        return $this->belongsTo(Actividad::class);
    }
}
