<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Un dia concreto de una actividad: una clase, el taller, o un ensayo.
 *
 * La fila puede existir mucho antes de que el dia llegue —las fechas de un
 * curso se escriben al crearlo—, asi que "existe" y "se dio" son dos cosas
 * distintas y hacen falta dos columnas para contarlas. `iniciada_en` es la
 * segunda: en null mientras nadie oprima el boton.
 *
 * Y DESDE EL 23/09/2026 HAY UNA TERCERA, que solo usa el programa externo:
 * «se dio» lo dice quien la dicto, y eso es parte interesada. `verificada_en`
 * es lo que dice un TERCERO —el funcionario de la institucion que recibio la
 * clase— y por eso son columnas distintas y no un estado. Es el equivalente de
 * `confirmaciones_clase` del lado de las promotorias, con una diferencia que
 * gobierna el diseno: alli firman muchos y lo que hace fuerza es el numero;
 * aqui firma UNO, la institucion, asi que cabe en la propia fila.
 *
 * LAS TRES FECHAS VAN ANOTADAS y no es decoracion: el `cast` vive en el metodo
 * `casts()` —la forma de Laravel 11— y el analisis estatico no lo sigue, asi
 * que sin estas lineas lee `string` y cualquier `->toDateString()` sobre ellas
 * sale como llamada a un metodo de algo que no es un objeto. Es la forma que
 * `phpstan.neon` dice de vaciar la linea base: poner `@property` en los
 * modelos, que ademas los documenta.
 *
 * `iniciada_en` y `verificada_en` son anulables porque su vacio SIGNIFICA algo
 * —«nadie ha oprimido Iniciar» y «nadie ha dado fe»—; `fecha` no lo es.
 *
 * @property Carbon $fecha
 * @property ?Carbon $iniciada_en
 * @property ?Carbon $verificada_en
 */
class SesionActividad extends Model
{
    protected $table = 'sesiones_actividad';

    protected $fillable = [
        'actividad_id',
        'fecha',
        'iniciada_en',
        'iniciada_por_id',
    ];

    /**
     * Las tres columnas de la verificacion NO estan en `$fillable`, y es
     * deliberado: se escriben por `App\Support\VerificacionExterna` y por
     * ningun otro sitio. Un `fill()` que las aceptara seria la segunda puerta
     * de escritura de una firma, que es justo lo que esa clase existe para
     * impedir.
     */
    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'iniciada_en' => 'datetime',
            'verificada_en' => 'datetime',
        ];
    }

    /**
     * La actividad de la que es un dia.
     *
     * ANOTADA con su tipo a proposito: sin esto el analizador ve un `Model`
     * generico y toda la cadena que cuelga de aqui --`->esExterno()`,
     * `->nombre`, `->inscritos()`-- sale como metodo inexistente en los dos
     * controladores que la recorren.
     *
     * @return BelongsTo<Actividad, $this>
     */
    public function actividad(): BelongsTo
    {
        return $this->belongsTo(Actividad::class);
    }

    public function iniciadaPor(): BelongsTo
    {
        return $this->belongsTo(Perfil::class, 'iniciada_por_id');
    }

    /** El funcionario de la institucion externa que dio fe. Solo en un programa externo. */
    public function verificadaPor(): BelongsTo
    {
        return $this->belongsTo(Perfil::class, 'verificada_por_id');
    }

    public function asistencias(): HasMany
    {
        return $this->hasMany(AsistenciaActividad::class, 'sesion_id');
    }

    /** Si ya se oprimio "Iniciar". */
    public function yaEmpezo(): bool
    {
        return $this->iniciada_en !== null;
    }

    /**
     * Si la institucion externa ya dio fe de que esta clase se dio.
     *
     * Mira `verificada_en` y no el origen ni quien firmo: `verificada_por_id`
     * admite NULL —se pone a NULL si algun dia se borra esa cuenta, porque el
     * hecho sobrevive a quien lo conto— y preguntarlo por ahi convertiria una
     * clase verificada en una sin verificar sin que nadie la tocara.
     */
    public function estaVerificada(): bool
    {
        return $this->verificada_en !== null;
    }
}
