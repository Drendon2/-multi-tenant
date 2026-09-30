<?php

namespace App\Models;

use App\Models\Concerns\DeLaInstitucion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * El archivo que un estudiante subio para uno de los papeles pedidos.
 *
 * Vive aparte de `DatosEstudiante::copia_documento` a proposito: aquella es la
 * cedula o el registro civil, que el sistema pide siempre. Estos son los
 * papeles VARIABLES de cada institucion, y meterlos en la misma columna
 * obligaria a migrar el esquema cada vez que una entidad pida un papel mas.
 */
class DocumentoEstudiante extends Model
{
    use DeLaInstitucion;

    protected $table = 'documentos_estudiante';

    protected $fillable = [
        'datos_estudiante_id',
        'requerido_id',
        'archivo',
        'subido',
    ];

    protected function casts(): array
    {
        return [
            'subido' => 'datetime',
        ];
    }

    /** `subido` es la marca de la ultima entrega: se refresca al reemplazar. */
    protected static function booted(): void
    {
        static::saving(function (self $documento) {
            $documento->subido = now();
        });
    }

    public function datos(): BelongsTo
    {
        return $this->belongsTo(DatosEstudiante::class, 'datos_estudiante_id');
    }

    public function requerido(): BelongsTo
    {
        return $this->belongsTo(DocumentoRequerido::class, 'requerido_id');
    }
}
