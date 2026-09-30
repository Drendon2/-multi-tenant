<?php

namespace App\Models;

use App\Models\Concerns\DeLaInstitucion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un encuentro semanal de un grupo: dia, hora de inicio y hora de fin.
 *
 * El grupo que se reune martes y jueves tiene dos sesiones. Uno solo por dia:
 * lo garantiza el unico (grupo, dia) del esquema.
 *
 * Existe porque el horario dejo de ser texto para pintar y paso a ser un dato
 * con el que hay que razonar —la rejilla semanal del perfil, los cruces de
 * horas, el orden por dia—, y eso no se hace leyendo prosa.
 */
class SesionGrupo extends Model
{
    use DeLaInstitucion;

    /**
     * Los dias en que la casa abre, numerados como ISO-8601 (1 = lunes).
     *
     * Llega hasta el SABADO y no hasta el viernes: hay promotorias que solo se
     * dan en fin de semana, y son justo las que mas gente reunen. El domingo se
     * queda fuera porque la casa no abre.
     */
    public const DIAS = [
        1 => 'Lunes',
        2 => 'Martes',
        3 => 'Miércoles',
        4 => 'Jueves',
        5 => 'Viernes',
        6 => 'Sábado',
    ];

    /** La forma corta, para las cabeceras de la rejilla del horario. */
    public const DIAS_CORTOS = [
        1 => 'Lun',
        2 => 'Mar',
        3 => 'Mié',
        4 => 'Jue',
        5 => 'Vie',
        6 => 'Sáb',
    ];

    protected $table = 'sesiones_grupo';

    protected $fillable = [
        'grupo_id',
        'dia',
        'hora_inicio',
        'hora_fin',
    ];

    protected function casts(): array
    {
        return [
            'dia' => 'integer',
        ];
    }

    public function grupo(): BelongsTo
    {
        return $this->belongsTo(Grupo::class, 'grupo_id');
    }

    public function getDiaDisplayAttribute(): string
    {
        return self::DIAS[$this->dia] ?? (string) $this->dia;
    }

    public function getDiaCortoAttribute(): string
    {
        return self::DIAS_CORTOS[$this->dia] ?? (string) $this->dia;
    }

    /**
     * La hora como se lee en Colombia: «4:00 p. m.», no «16:00».
     *
     * Con espacio fino entre las letras y el punto, que es como lo escribe la
     * Academia y como esta escrito el resto de la interfaz.
     */
    public static function enDoceHoras(?string $hora): string
    {
        if ($hora === null || $hora === '') {
            return '';
        }

        [$h, $m] = array_map('intval', explode(':', $hora) + [1 => '0']);

        $sufijo = $h < 12 ? 'a. m.' : 'p. m.';
        $docenas = $h % 12;

        if ($docenas === 0) {
            $docenas = 12;
        }

        // Las 12:00 del mediodia se dicen «12:00 m.» y no «12:00 p. m.».
        if ($h === 12 && $m === 0) {
            return '12:00 m.';
        }

        return sprintf('%d:%02d %s', $docenas, $m, $sufijo);
    }

    /**
     * Un rango de horas dicho como se dice: «9:00 a 11:00 a. m.», con el
     * sufijo una sola vez cuando las dos horas caen en la misma mitad del dia.
     *
     * Existe para la columna de horas de la rejilla semanal, que es estrecha y
     * se repite en cada fila: «9:00 a. m. a 11:00 a. m.» ocupaba el doble para
     * decir lo mismo, y empujaba el sabado fuera de la pantalla.
     */
    public static function rangoCorto(?string $inicio, ?string $fin): string
    {
        $desde = self::enDoceHoras($inicio);
        $hasta = self::enDoceHoras($fin);

        if ($desde === '' || $hasta === '') {
            return trim("{$desde} {$hasta}");
        }

        foreach (['a. m.', 'p. m.'] as $sufijo) {
            if (str_ends_with($desde, $sufijo) && str_ends_with($hasta, $sufijo)) {
                return trim(str_replace($sufijo, '', $desde))." a {$hasta}";
            }
        }

        return "{$desde} a {$hasta}";
    }

    public function getInicioDisplayAttribute(): string
    {
        return self::enDoceHoras($this->hora_inicio);
    }

    public function getFinDisplayAttribute(): string
    {
        return self::enDoceHoras($this->hora_fin);
    }

    /** «Martes 4:00 p. m. a 6:00 p. m.» */
    public function getRangoAttribute(): string
    {
        return "{$this->dia_display} {$this->inicio_display} a {$this->fin_display}";
    }

    /**
     * ¿Se pisa con otra sesion?
     *
     * Dos franjas del mismo dia se cruzan cuando cada una empieza antes de que
     * la otra termine. Tocarse por un extremo NO es cruzarse: un grupo que
     * acaba a las 6 y otro que empieza a las 6 caben seguidos, y es lo normal
     * en una tarde de salon.
     */
    public function seCruzaCon(self $otra): bool
    {
        return $this->dia === $otra->dia
            && $this->hora_inicio < $otra->hora_fin
            && $otra->hora_inicio < $this->hora_fin;
    }

    public function __toString(): string
    {
        return $this->rango;
    }
}
