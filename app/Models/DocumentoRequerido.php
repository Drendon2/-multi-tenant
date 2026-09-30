<?php

namespace App\Models;

use App\Models\Concerns\DeLaInstitucion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un papel que ESTA institucion exige para dar por valida una matricula.
 *
 * Que papeles hacen falta cambia de una entidad a otra —una pide certificado de
 * EPS, otra el recibo de servicios, otra nada—, asi que la lista es un registro
 * editable desde Gestion y no una constante del codigo.
 *
 * Se DESACTIVA en vez de borrarse cuando deja de pedirse: los archivos que ya
 * subieron los estudiantes cuelgan de aqui, y borrar el requisito se los
 * llevaria por delante junto con la prueba de que en su momento cumplieron.
 */
class DocumentoRequerido extends Model
{
    use DeLaInstitucion;

    /**
     * El nombre con el que NACE el consentimiento de datos.
     *
     * Es solo el nombre inicial —el que le ponen la migracion y el instalador—:
     * la entidad puede renombrarlo desde Gestion como a cualquier otro. Lo que
     * lo identifica es la columna `plantilla`, no esta constante.
     */
    public const CONSENTIMIENTO = 'Autorización de tratamiento de datos y uso de imagen';

    /**
     * El unico formato que el sistema sabe imprimir hoy.
     *
     * Es el valor que lleva `plantilla` en ese requerido. Vacia en todos los
     * demas, que es lo corriente: los otros papeles son ranuras donde se sube
     * algo que ya existe, y este hay que generarlo para poder firmarlo.
     */
    public const FORMATO_CONSENTIMIENTO = 'consentimiento';

    protected $table = 'documentos_requeridos';

    protected $fillable = [
        'nombre',
        'descripcion',
        'plantilla',
        'obligatorio',
        'activo',
        'orden',
    ];

    protected function casts(): array
    {
        return [
            'obligatorio' => 'boolean',
            'activo' => 'boolean',
            'orden' => 'integer',
        ];
    }

    public function entregas(): HasMany
    {
        return $this->hasMany(DocumentoEstudiante::class, 'requerido_id');
    }

    /** Menor primero. Con el mismo numero manda el nombre. */
    public function scopeOrdenados(Builder $query): Builder
    {
        return $query->orderBy('orden')->orderBy('nombre');
    }

    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('activo', true);
    }

    public function __toString(): string
    {
        return $this->nombre;
    }
}
