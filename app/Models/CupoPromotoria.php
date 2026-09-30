<?php

namespace App\Models;

use App\Models\Concerns\DeLaInstitucion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cuantos estudiantes admite una promotoria en un periodo concreto.
 *
 * Va por periodo y no como columna de `Promotoria` a proposito: al abrir
 * matriculas se fija un cupo nuevo sin borrar el del periodo anterior, asi el
 * historico queda reconstruible.
 *
 * Una promotoria SIN fila aqui no tiene tope: es el estado por defecto y no
 * bloquea a nadie. Esa ausencia es tambien la que hace que el camino sin limite
 * no pague el coste del cerrojo del trigger — no hay fila que bloquear.
 */
class CupoPromotoria extends Model
{
    use DeLaInstitucion;

    protected $table = 'cupos_promotoria';

    protected $fillable = [
        'promotoria_id',
        'periodo_id',
        'cupo_maximo',
    ];

    protected function casts(): array
    {
        return [
            'cupo_maximo' => 'integer',
        ];
    }

    public function promotoria(): BelongsTo
    {
        return $this->belongsTo(Promotoria::class);
    }

    public function periodo(): BelongsTo
    {
        return $this->belongsTo(Periodo::class);
    }
}
