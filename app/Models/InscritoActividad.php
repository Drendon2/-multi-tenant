<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una persona apuntada a una actividad.
 *
 * Sin cuenta y sin matricula. Si ademas resulta ser un estudiante del sistema,
 * `perfil` lo dice; para casi todos sera null y eso no es un dato incompleto,
 * es lo normal.
 *
 * TRES ORIGENES Y DOS JUEGOS DE DATOS DISTINTOS. A quien llega por el enlace se
 * le piden documento, telefono, correo y fecha de nacimiento, porque los
 * escribe el mismo con calma. A quien escribe el responsable —el que aparece el
 * dia de la clase, y la lista entera de un PROGRAMA EXTERNO— solo se le pide el
 * nombre y, en el externo, la EDAD: eso se pregunta de pie en un salon ajeno.
 * Por eso casi todas las columnas admiten NULL, y por eso `edad` y
 * `fecha_nacimiento` conviven en vez de deducirse una de otra — no son el mismo
 * dato preguntado de dos formas, son dos datos con precio distinto.
 */
class InscritoActividad extends Model
{
    /** Llego por el enlace y lleno el formulario. */
    public const ENLACE = 'enlace';

    /** Aparecio el dia de la clase y lo anadio el responsable. */
    public const EN_SESION = 'en_sesion';

    /**
     * Lo escribio quien dirige, armando la lista.
     *
     * Es como se puebla un PROGRAMA EXTERNO entero: alli no hay enlace que
     * compartir, asi que el profesor escribe la lista del salon —nombre y
     * edad— de pie y en el sitio. Se distingue de `EN_SESION` porque aquel
     * significa algo mas estrecho y util: «esta persona no estaba y aparecio
     * el dia de la clase». Colapsarlos perderia esa segunda cosa.
     */
    public const LISTA = 'lista';

    protected $table = 'inscritos_actividad';

    protected $fillable = [
        'actividad_id',
        'nombre_completo',
        'documento',
        'telefono',
        'correo',
        'fecha_nacimiento',
        'edad',
        'perfil_id',
        'origen',
    ];

    protected function casts(): array
    {
        return [
            'fecha_nacimiento' => 'date',
            'edad' => 'integer',
        ];
    }

    /** @return BelongsTo<Actividad, $this> */
    public function actividad(): BelongsTo
    {
        return $this->belongsTo(Actividad::class);
    }

    /** El estudiante del sistema, si el documento coincidio con alguno. */
    public function perfil(): BelongsTo
    {
        return $this->belongsTo(Perfil::class);
    }

    /**
     * El perfil al que pertenece ese documento, o null.
     *
     * Se busca por el documento y no por el nombre a proposito: el nombre lo
     * escribe cada quien como le sale —con o sin segundo apellido, con o sin
     * tildes— y emparejar por ahi habria atado a la persona equivocada. El
     * documento es unico en `datos_estudiante`, asi que o coincide o no.
     */
    public static function perfilConDocumento(?string $documento): ?Perfil
    {
        if ($documento === null || $documento === '') {
            return null;
        }

        return DatosEstudiante::where('documento_identidad', $documento)->first()?->perfil;
    }
}
