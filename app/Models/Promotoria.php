<?php

namespace App\Models;

use App\Models\Concerns\DeLaInstitucion;
use App\Support\InstitucionActual;
use App\Support\Permisos;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Una promotoria (ej. Violin). La dicta una sola persona, o nadie todavia.
 *
 * Quien la dicta NO tiene por que tener el rol "profesor": un director de
 * escuela que ademas da su propia promotoria es un caso real, y con el rol como
 * unico criterio no podria ni quedar asignado aqui ni pasar lista en su propio
 * grupo. Lo que manda es este vinculo, no el rol.
 *
 * Los estudiantes si quedan fuera: el filtro es `Perfil::ROLES_PERSONAL`.
 */
class Promotoria extends Model
{
    use DeLaInstitucion;

    /**
     * Las promotorias que esta persona del personal puede ver.
     *
     * ─── UNA SOLA CASA PARA EL RECORTE ────────────────────────────────────
     *
     * Desde el 12/09/2026 un director solo ve las areas que dirige, y esa regla
     * hace falta en una docena de listados: el Panel, Gestion → Promotorias,
     * Grupos, Cupos, Programas, las alertas, las fichas incompletas y los
     * informes. Escrita a mano en cada uno se separan sin que nada falle — y el
     * que se olvide no enseña de mas en su pantalla, sino que deja una puerta
     * por la que se lee lo de otra area.
     *
     * El profesor se acota por su VINCULO y no por area, que es como ya estaba.
     *
     * OJO CON EL ARRAY VACIO: un director sin areas asignadas da `[]` y este
     * `whereIn` no devuelve nada, que es lo que tiene que pasar. La tentacion al
     * leerlo es «si esta vacio, no filtres»; eso le devolveria la casa entera a
     * quien no dirige ninguna area.
     *
     * @param  Builder<Promotoria>  $query
     */
    public function scopeQueVe($query, Perfil $perfil): void
    {
        $areas = Permisos::areasVisiblesPara($perfil);

        $query
            ->when($perfil->rol === 'profesor', fn ($q) => $q->where('profesor_id', $perfil->id))
            ->when($areas !== null, fn ($q) => $q->whereIn('area_id', $areas ?? []));
    }

    protected $table = 'promotorias';

    protected $fillable = [
        'nombre',
        'area_id',
        'profesor_id',
    ];

    // El enlace de inscripcion NO va en `$fillable` a proposito: el formulario
    // de la promotoria no lo toca, y lo que lo enciende es una accion aparte
    // con su propia puerta (ver `Permisos::puedeAbrirEnlace`).
    protected $attributes = [
        'enlace_abierto' => false,
    ];

    protected function casts(): array
    {
        return ['enlace_abierto' => 'boolean'];
    }

    /**
     * La promotoria de ese enlace, este encendido o no: un enlace APAGADO dice
     * «ahora no recibe inscripciones», que es distinto de uno que no existe.
     * Quien lo use tiene que mirar `enlace_abierto` antes de matricular a nadie.
     */
    public static function porEnlace(string $token): ?self
    {
        if (preg_match('/^[A-Za-z0-9]{16}$/', $token) !== 1) {
            return null;
        }

        // El enlace es publico y no sabe de que institucion es: la base dice
        // de cual es el token (RLS no deja buscarlo en la tabla), se adopta, y
        // la promotoria se lee ya como cualquier otra. Si hay una sesion
        // abierta de otra institucion, `adoptar()` da 404.
        $institucion = InstitucionActual::deEnlace('promotoria', $token);

        if ($institucion === null) {
            return null;
        }

        InstitucionActual::adoptar($institucion);

        return self::where('enlace_token', $token)->first()?->load('area');
    }

    /** La direccion que se comparte. Null si nunca se encendio. */
    public function enlace(): ?string
    {
        return $this->enlace_token ? route('promotoria-enlace', $this->enlace_token) : null;
    }

    /** Enciende o apaga. Al encenderlo por primera vez nace el token. */
    public function abrirEnlace(bool $abierto): void
    {
        if ($abierto) {
            $this->enlace_token ??= Str::random(16);
        }

        $this->enlace_abierto = $abierto;
        $this->save();
    }

    /** Cambia el token: el enlace y el QR anteriores dejan de servir en el acto. */
    public function renovarEnlace(): void
    {
        $this->enlace_token = Str::random(16);
        $this->save();
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    /**
     * Quien la dicta y pasa lista en sus grupos. Puede ser un director.
     *
     * @return BelongsTo<Perfil, $this>
     */
    public function profesor(): BelongsTo
    {
        return $this->belongsTo(Perfil::class, 'profesor_id');
    }

    /**
     * Anotada por lo mismo que `profesor()`: sin el tipo, recorrer los grupos de
     * una promotoria los entrega como `Model` generico y cualquier `$grupo->id`
     * o `$grupo->cupo_maximo` sale como propiedad inexistente.
     *
     * @return HasMany<Grupo, $this>
     */
    public function grupos(): HasMany
    {
        return $this->hasMany(Grupo::class);
    }

    public function cupos(): HasMany
    {
        return $this->hasMany(CupoPromotoria::class);
    }

    public function matriculas(): HasMany
    {
        return $this->hasMany(Matricula::class);
    }

    /**
     * Cupo maximo fijado para ese periodo, o null si la promotoria no tiene tope.
     *
     * Si la relacion ya viene cargada se usa esa —los listados del catalogo la
     * traen con `with('cupos')` y aqui se llama una vez por fila—; si no, se
     * consulta solo la que hace falta en vez de arrastrar todos los periodos.
     */
    public function cupoEn(?Periodo $periodo): ?int
    {
        if ($periodo === null) {
            return null;
        }

        $cupo = $this->relationLoaded('cupos')
            ? $this->cupos->firstWhere('periodo_id', $periodo->id)
            : $this->cupos()->where('periodo_id', $periodo->id)->first();

        return $cupo?->cupo_maximo;
    }

    /**
     * Matriculas que ocupan cupo: pendientes y activas.
     *
     * Las retiradas lo liberan. Una cancelacion en tramite NO: mientras nadie
     * la apruebe, el estudiante sigue inscrito y su sitio sigue tomado.
     */
    public function ocupadosEn(?Periodo $periodo, ?int $excluirMatriculaId = null): int
    {
        if ($periodo === null) {
            return 0;
        }

        return $this->matriculas()
            ->where('periodo_id', $periodo->id)
            ->where('estado', '!=', Matricula::RETIRADA)
            ->when($excluirMatriculaId !== null, fn ($q) => $q->where('id', '!=', $excluirMatriculaId))
            ->count();
    }

    /**
     * Lo mismo que `ocupadosEn()`, pero para VARIAS promotorias de una vez.
     *
     * Existe porque `ocupadosEn()` siempre consulta, asi que llamarla dentro de
     * un bucle cuesta tantas consultas como filas haya. Las pantallas que
     * pintan un listado entero —el catalogo del estudiante y el reparto de
     * cupos— usan esta.
     *
     * Vive aqui pegada a `ocupadosEn()` a proposito: las condiciones de las dos
     * tienen que ser LAS MISMAS —las retiradas liberan cupo, una cancelacion en
     * tramite no— y separarlas por archivos es como se acaban desincronizando.
     * Si alguna vez cambia una, tiene que cambiar la otra tres lineas mas
     * arriba, a la vista.
     *
     * Devuelve un mapa `promotoria_id => total`. Las promotorias sin ninguna
     * matricula NO aparecen: un GROUP BY solo devuelve las que tienen alguna,
     * asi que quien lea el mapa debe tratar la ausencia como cero.
     *
     * @param  Collection<int, self>  $promotorias
     * @return Collection<int, int>
     */
    public static function ocupadosEnLote(?Periodo $periodo, Collection $promotorias): Collection
    {
        if ($periodo === null || $promotorias->isEmpty()) {
            return collect();
        }

        return Matricula::query()
            ->whereIn('promotoria_id', $promotorias->pluck('id'))
            ->where('periodo_id', $periodo->id)
            ->where('estado', '!=', Matricula::RETIRADA)
            ->groupBy('promotoria_id')
            ->selectRaw('promotoria_id, COUNT(*) as total')
            ->pluck('total', 'promotoria_id');
    }

    /** Cupos libres en el periodo, o null si no hay tope definido. */
    public function cuposDisponibles(?Periodo $periodo): ?int
    {
        $maximo = $this->cupoEn($periodo);

        if ($maximo === null) {
            return null;
        }

        return $maximo - $this->ocupadosEn($periodo);
    }

    public function __toString(): string
    {
        return "{$this->nombre} ({$this->area->nombre})";
    }
}
