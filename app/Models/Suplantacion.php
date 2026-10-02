<?php

namespace App\Models;

use App\Models\Concerns\DeLaInstitucion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Un token de un solo uso para entrar a una institucion desde el panel, y el
 * registro de que se uso (paso 4c). Ver `App\Support\Suplantacion`.
 *
 * Con `institucion_id` y RLS como cualquier dato: el token solo se encuentra
 * en el dominio de su institucion.
 *
 * @property int $id
 * @property int $institucion_id
 * @property int $operador_id
 * @property int $user_id
 * @property string $token
 * @property Carbon $created_at
 * @property Carbon|null $usado_en
 */
class Suplantacion extends Model
{
    use DeLaInstitucion;

    protected $table = 'suplantaciones';

    public $timestamps = false;

    protected $fillable = ['operador_id', 'user_id', 'token'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'usado_en' => 'datetime'];
    }
}
