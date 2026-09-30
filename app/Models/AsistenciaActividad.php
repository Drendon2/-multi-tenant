<?php

namespace App\Models;

use App\Models\Concerns\DeLaInstitucion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Como le fue a UN inscrito en UNA sesion de actividad.
 *
 * La hermana de `Asistencia`, que va contra una matricula. Aqui va contra un
 * inscrito, que puede no tener ni cuenta.
 *
 * No hay un cuarto estado "sin marcar": eso se representa por la AUSENCIA de
 * fila, igual que en `Asistencia`, y por la misma razon — que no exista la fila
 * es informacion real, y guardarla como valor la volveria indistinguible de una
 * respuesta deliberada.
 */
class AsistenciaActividad extends Model
{
    use DeLaInstitucion;

    /**
     * Las mismas tres de siempre, tomadas de `Asistencia` y no copiadas.
     *
     * El vocabulario de "vino / no vino / no vino con excusa" es UNO en todo el
     * sistema, y dos listas con los mismos valores son dos listas que un dia
     * discrepan. Las tablas si estan separadas —cuelgan de cosas distintas—,
     * pero lo que significan las marcas no.
     */
    public const ESTADOS = Asistencia::ESTADOS;

    public const ASISTIO = Asistencia::ASISTIO;

    protected $table = 'asistencias_actividad';

    protected $fillable = [
        'sesion_id',
        'inscrito_id',
        'estado',
        'fecha_registro',
    ];

    protected function casts(): array
    {
        return [
            'fecha_registro' => 'datetime',
        ];
    }

    /** Se refresca en cada guardado: es la marca de la ultima correccion. */
    protected static function booted(): void
    {
        static::saving(function (self $asistencia) {
            $asistencia->fecha_registro = now();
        });
    }

    public function sesion(): BelongsTo
    {
        return $this->belongsTo(SesionActividad::class, 'sesion_id');
    }

    public function inscrito(): BelongsTo
    {
        return $this->belongsTo(InscritoActividad::class, 'inscrito_id');
    }

    /**
     * A quien de esta actividad se le ha marcado algo alguna vez.
     *
     * NO ES LO MISMO QUE «cuantas sesiones tienen lista tomada», y confundirlas
     * ya costo una vez al escribir la pantalla de programas externos.
     * `AsistenciaDeActividad` devuelve un DENOMINADOR comun —las sesiones con
     * lista— que es el mismo numero para todos; esto es una pregunta POR
     * PERSONA. Con aquel, en cuanto se pasa una sola lista nadie parece
     * quitable, ni siquiera quien se anadio despues y no tiene una sola marca.
     *
     * De ahi que la pregunta viva aqui y en una sola forma: quien la haga desde
     * una plantilla y quien la haga desde un controlador tienen que estar
     * preguntando lo mismo, o el boton se pinta con una regla y se comprueba
     * con otra.
     *
     * Devuelve las claves para poder consultar con `isset` dentro de un bucle
     * sin una consulta por fila.
     *
     * @return array<int, bool>
     */
    public static function conMarcasEn(int $actividadId): array
    {
        return static::query()
            ->join('sesiones_actividad', 'sesiones_actividad.id', '=', 'asistencias_actividad.sesion_id')
            ->where('sesiones_actividad.actividad_id', $actividadId)
            ->distinct()
            ->pluck('asistencias_actividad.inscrito_id')
            ->mapWithKeys(fn (int $id) => [$id => true])
            ->all();
    }

    /** La misma pregunta para UNA persona, que es la que corta al borrar. */
    public static function tieneMarcas(int $inscritoId): bool
    {
        return static::where('inscrito_id', $inscritoId)->exists();
    }
}
