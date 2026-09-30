<?php

namespace App\Models;

use App\Models\Concerns\DeLaInstitucion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Periodo semestral de matricula. Ej.: '2026-1'.
 *
 * `activo` dice cual es el periodo EN CURSO (el que reciben todas las
 * pantallas). `matriculas_abiertas` es otra cosa: la ventana en la que se
 * admite gente nueva o renovaciones. La institucion no recibe matriculas todo
 * el ano, solo al principio y a mitad, asi que el periodo puede estar en curso
 * con las matriculas ya cerradas.
 */
class Periodo extends Model
{
    use DeLaInstitucion;

    protected $table = 'periodos';

    protected $fillable = [
        'nombre',
        'fecha_inicio',
        'fecha_fin',
        'activo',
        'matriculas_abiertas',
    ];

    protected function casts(): array
    {
        return [
            'fecha_inicio' => 'date',
            'fecha_fin' => 'date',
            'activo' => 'boolean',
            'matriculas_abiertas' => 'boolean',
        ];
    }

    // La tabla tiene ademas `activo_marca`, la columna generada que emula el
    // indice unico parcial de Postgres. La calcula la base de datos y por eso
    // NO esta en $fillable: se lee, nunca se escribe.

    public function matriculas(): HasMany
    {
        return $this->hasMany(Matricula::class);
    }

    public function cuposPromotoria(): HasMany
    {
        return $this->hasMany(CupoPromotoria::class);
    }

    public function clases(): HasMany
    {
        return $this->hasMany(Clase::class);
    }

    /**
     * Las encuestas que EVALUAN este periodo, no las que se llenaron durante el.
     *
     * Se contestan al renovar, es decir ya empezado el periodo siguiente.
     */
    public function encuestasSatisfaccion(): HasMany
    {
        return $this->hasMany(EncuestaSatisfaccion::class);
    }

    /** El periodo activo, o null si el personal no ha marcado ninguno. */
    public static function enCurso(): ?self
    {
        return static::where('activo', true)->first();
    }

    /**
     * Deja `$periodo` como el unico en curso, en una sola transaccion.
     *
     * Al periodo que SALE se le cierran tambien las matriculas: dejar el flag
     * abierto en un periodo que ya no esta en curso solo serviria para confundir
     * a quien lo reactive meses despues.
     *
     * El orden importa y no es negociable: primero se apagan los demas, luego se
     * enciende este. Al reves, el indice unico `un_solo_periodo_activo` rechaza
     * el guardado por haber dos activos a la vez, aunque sea un instante.
     */
    public static function ponerEnCurso(self $periodo): self
    {
        DB::transaction(function () use ($periodo) {
            static::where('activo', true)
                ->where('id', '!=', $periodo->id)
                ->update(['activo' => false, 'matriculas_abiertas' => false]);

            $periodo->activo = true;
            $periodo->save();
        });

        return $periodo;
    }

    public function getAdmiteMatriculasAttribute(): bool
    {
        return $this->activo && $this->matriculas_abiertas;
    }

    /**
     * ¿El periodo quedo atras? Ni esta en curso ni su fecha llego.
     *
     * Hacen falta las dos condiciones, y cada una tapa un agujero de la otra.
     * Mirar solo `activo` daria por terminado un periodo FUTURO, que no ha
     * empezado siquiera. Mirar solo la fecha daria por terminado el periodo en
     * curso en cuanto el personal se retrase un dia en cerrarlo — y ahi las
     * pantallas seguirian, con razon, admitiendo retiros y matriculas.
     */
    public function getTerminoAttribute(): bool
    {
        return ! $this->activo && $this->fecha_fin->lt(Carbon::today());
    }

    public function __toString(): string
    {
        return $this->nombre;
    }
}
