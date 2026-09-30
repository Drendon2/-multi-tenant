<?php

namespace App\Models;

use App\Models\Concerns\DeLaInstitucion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Responsable de un estudiante menor de edad.
 */
class Acudiente extends Model
{
    use DeLaInstitucion;

    protected $table = 'acudientes';

    protected $fillable = [
        'nombre',
        'telefono',
        'autoriza_tratamiento_datos',
        'fecha_autorizacion',
    ];

    protected function casts(): array
    {
        return [
            'autoriza_tratamiento_datos' => 'boolean',
            'fecha_autorizacion' => 'datetime',
        ];
    }

    public function estudiantes(): HasMany
    {
        return $this->hasMany(DatosEstudiante::class);
    }

    public function __toString(): string
    {
        return $this->nombre;
    }
}
