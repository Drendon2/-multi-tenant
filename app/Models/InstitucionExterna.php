<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * La otra institucion: una escuela rural, un colegio, una fundacion.
 *
 * NO ES UN DEPARTAMENTO NI UNA SEDE de la casa de la cultura. Es una entidad
 * AJENA a la que un profesor de la casa va a dictar, y lo unico que tiene
 * dentro de este sistema es una cuenta: la del funcionario que da fe de que el
 * profesor fue. Ver la migracion `2026_09_23_100000_programas_externos`.
 *
 * DOS NOMBRES QUE NO SON EL MISMO, y confundirlos sale caro al pintar: el de
 * AQUI es el de la escuela —«I. E. Rural El Carmen»—, y el de la PERSONA vive
 * en `perfil->nombre_completo`. La escuela sobrevive al funcionario: cuando esa
 * persona cambie de trabajo se le edita el nombre a la cuenta y la institucion
 * sigue siendo la misma, con sus clases verificadas intactas.
 *
 * El QR de la institucion es el `codigo_qr` de ESE perfil, el mismo mecanismo
 * del carne del estudiante (ver `App\Support\CarneQr`). Nace vacio y se crea al
 * imprimirlo por primera vez.
 */
class InstitucionExterna extends Model
{
    protected $table = 'instituciones_externas';

    protected $fillable = [
        'nombre',
        'direccion',
        'telefono',
        'perfil_id',
    ];

    /**
     * La cuenta con la que entra su funcionario. Una sola, por el unico de la base.
     *
     * @return BelongsTo<Perfil, $this>
     */
    public function perfil(): BelongsTo
    {
        return $this->belongsTo(Perfil::class, 'perfil_id');
    }

    /**
     * Los programas que esta institucion recibe.
     *
     * Acotada al tipo a proposito aunque el CHECK de la base ya lo garantice:
     * quien lea esta relacion no tiene por que saberse la restriccion, y si
     * algun dia se afloja, aqui sigue significando lo mismo.
     *
     * @return HasMany<Actividad, $this>
     */
    public function programas(): HasMany
    {
        return $this->hasMany(Actividad::class, 'institucion_id')
            ->where('tipo', Actividad::EXTERNO);
    }

    public function __toString(): string
    {
        return $this->nombre;
    }
}
