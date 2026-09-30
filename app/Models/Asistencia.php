<?php

namespace App\Models;

use App\Models\Concerns\DeLaInstitucion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Como le fue a UN estudiante en UNA clase: vino, falto, o falto con excusa.
 *
 * Las tres opciones son excluyentes y no hay un cuarto estado "sin marcar": eso
 * se representa por la AUSENCIA de fila. Que no exista la fila es informacion
 * real —la clase se registro y a esa persona nadie la paso, por ejemplo porque
 * llego tarde y se cerro antes—, y convertirlo en un valor guardado haria
 * imposible distinguirlo de una respuesta deliberada.
 *
 * Va contra la MATRICULA y no contra el perfil: es lo que ata la asistencia a la
 * promotoria y el periodo concretos en los que se dio la clase. El mismo
 * estudiante puede estar en dos promotorias a la vez, y sus faltas de una no son
 * las de la otra.
 */
class Asistencia extends Model
{
    use DeLaInstitucion;

    public const ASISTIO = 'asistio';

    public const FALTO = 'falto';

    public const EXCUSA = 'excusa';

    public const ESTADOS = [
        self::ASISTIO => 'Asistió',
        self::FALTO => 'Faltó',
        self::EXCUSA => 'Faltó con excusa',
    ];

    protected $table = 'asistencias';

    protected $fillable = [
        'clase_id',
        'matricula_id',
        'estado',
        'fecha_registro',
    ];

    protected function casts(): array
    {
        return [
            'fecha_registro' => 'datetime',
        ];
    }

    /** Se refresca en cada guardado: es la marca de la ultima correccion. */
    protected static function booted(): void
    {
        static::saving(function (self $asistencia) {
            $asistencia->fecha_registro = now();
        });
    }

    public function clase(): BelongsTo
    {
        return $this->belongsTo(Clase::class);
    }

    public function matricula(): BelongsTo
    {
        return $this->belongsTo(Matricula::class);
    }

    public function getEstadoDisplayAttribute(): string
    {
        return self::ESTADOS[$this->estado] ?? $this->estado;
    }

    public function __toString(): string
    {
        return "{$this->matricula->estudiante->nombre_completo}: {$this->estado_display}";
    }
}
