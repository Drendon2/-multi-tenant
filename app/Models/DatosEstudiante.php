<?php

namespace App\Models;

use App\Models\Concerns\DeLaInstitucion;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

/**
 * Datos que solo tienen los usuarios con rol 'estudiante'.
 *
 * La COPIA del documento solo la puede ver el administrador (el control esta en
 * la capa de permisos). Queda en blanco tras la inscripcion publica, se sube
 * despues en "Mi perfil" y NO bloquea que el profesor confirme la matricula
 * mientras tanto.
 */
class DatosEstudiante extends Model
{
    use DeLaInstitucion;

    protected $table = 'datos_estudiante';

    protected $fillable = [
        'perfil_id',
        'documento_identidad',
        'acudiente_id',
    ];

    public function perfil(): BelongsTo
    {
        return $this->belongsTo(Perfil::class);
    }

    /** @return BelongsTo<Acudiente, $this> */
    public function acudiente(): BelongsTo
    {
        return $this->belongsTo(Acudiente::class);
    }

    /**
     * Las copias de documento entregadas.
     *
     * Anotada por lo mismo que sus vecinas: sin el tipo, cada `filter()` o
     * `first()` sobre esta coleccion se lee como si trajera `Model` a secas y
     * el cierre tipado de al lado no cuadra.
     *
     * @return HasMany<DocumentoEstudiante, $this>
     */
    public function documentos(): HasMany
    {
        return $this->hasMany(DocumentoEstudiante::class, 'datos_estudiante_id');
    }

    /**
     * Regla de negocio: los menores de edad DEBEN tener acudiente.
     *
     * Vive aqui y no en el esquema porque la minoria de edad se deduce de
     * `perfiles.fecha_nacimiento` —otra tabla— y ningun CHECK de SQL puede
     * consultar otra tabla. Ni Postgres ni MariaDB; no es una limitacion de la
     * migracion.
     */
    public function validar(): void
    {
        $perfil = $this->perfil ?? Perfil::find($this->perfil_id);

        if ($perfil === null || ! $perfil->es_menor) {
            return;
        }

        if ($this->acudiente_id === null) {
            throw ValidationException::withMessages([
                'acudiente' => 'Los estudiantes menores de edad deben registrar un acudiente.',
            ]);
        }

        // Y con telefono. Un acudiente sin numero no cumple la funcion por la
        // que se pide: la institucion lo registra para poder LLAMARLO —al
        // resolver una cancelacion, al hacer seguimiento de una mala
        // experiencia, o si pasa algo en clase—, no para que figure en una
        // ficha. La regla vive aqui y no en las reglas de un formulario porque
        // hay dos caminos que crean estudiantes menores, la inscripcion publica
        // y Gestion → Usuarios, y por separado acabarian discrepando.
        $acudiente = $this->acudiente ?? Acudiente::find($this->acudiente_id);

        if ($acudiente === null || trim((string) $acudiente->telefono) === '') {
            throw ValidationException::withMessages([
                'acudiente_telefono' => 'El acudiente de un menor de edad debe tener teléfono: '
                    .'es el número al que llamaría la institución.',
            ]);
        }
    }

    /**
     * Los papeles pedidos que este estudiante todavia no ha subido.
     *
     * `$exigidos` evita repetir la consulta cuando quien llama va a preguntar
     * por una lista entera de estudiantes — el listado del panel lo hace por
     * cada fila y sin esto seria una consulta por estudiante.
     *
     * @param  Collection<int, DocumentoRequerido>|null  $exigidos
     * @return list<DocumentoRequerido>
     */
    public function documentosFaltantes(?Collection $exigidos = null): array
    {
        $exigidos ??= DocumentoRequerido::where('activo', true)->get();

        $entregados = $this->documentos
            ->filter(fn (DocumentoEstudiante $d) => $d->archivo !== '' && $d->archivo !== null)
            ->pluck('requerido_id')
            ->all();

        return $exigidos
            ->reject(fn (DocumentoRequerido $d) => in_array($d->id, $entregados, true))
            ->values()
            ->all();
    }

    public function __toString(): string
    {
        return $this->perfil->nombre_completo;
    }
}
