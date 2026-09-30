<?php

namespace App\Models;

use App\Models\Concerns\DeLaInstitucion;
use App\Support\Permisos;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Departamento artistico: Musica, Danza, Teatro, Pintura... Los crea el admin.
 */
class Area extends Model
{
    use DeLaInstitucion;

    /**
     * Los departamentos que esta persona del personal puede ver.
     *
     * Hermano de `Promotoria::queVe()` y por la misma razon: desde el 12/09/2026
     * un director solo ve los departamentos que le asignan, y esa lista hace
     * falta en Gestion → Programas, en los filtros de Grupos y en las alertas.
     * Escrita a mano en cada sitio se separan sin que nada falle.
     *
     * `null` de `areasVisiblesPara()` significa «sin recorte» —el administrador—
     * y un array VACIO significa «ninguna», que es lo que ve un director al que
     * todavia no le han asignado nada. No se colapsan los dos casos a proposito.
     *
     * @param  Builder<Area>  $query
     */
    public function scopeQueVe($query, Perfil $perfil): void
    {
        $areas = Permisos::areasVisiblesPara($perfil);

        $query->when($areas !== null, fn ($q) => $q->whereIn('id', $areas ?? []));
    }

    protected $table = 'areas';

    protected $fillable = ['nombre'];

    /**
     * Numero de colores de etiqueta disponibles (ver .tag-0..tag-N en app.css).
     *
     * Cada area recibe un color estable por su id, como un marcador de color
     * distinto por disciplina en la cartelera del estudio.
     */
    public const NUM_COLORES_ETIQUETA = 8;

    public function promotorias(): HasMany
    {
        return $this->hasMany(Promotoria::class);
    }

    /**
     * Clase CSS "tag-N" estable para esta area, segun su id.
     *
     * Vive en el modelo y no en un helper de plantilla porque es una propiedad
     * del area —su color—, no una decision de una pantalla concreta.
     */
    public function getTagColorAttribute(): string
    {
        if (! $this->id) {
            return 'tag-0';
        }

        return 'tag-'.($this->id % self::NUM_COLORES_ETIQUETA);
    }

    public function __toString(): string
    {
        return $this->nombre;
    }
}
