<?php

namespace App\Models;

use App\Models\Concerns\DeLaInstitucion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un estudiante da fe, desde su propia sesion, de que la clase se dio.
 *
 * Es el contrapeso del boton de quien dicta: quien registra la clase es parte
 * interesada, asi que el registro por si solo no prueba nada.
 *
 * Confirma cualquier estudiante inscrito en el grupo, no solo aquel a quien
 * marcaron presente: lo que se verifica es que la clase EXISTIO, y hacerlo
 * depender de la asistencia que marca la propia persona verificada dejaria la
 * verificacion en sus manos.
 *
 * Se puede retirar. Una confirmacion es una afirmacion de alguien sobre lo que
 * vio, y quien se equivoco de renglon tiene que poder deshacerlo; dejar la marca
 * fija por miedo a que alguien juegue con ella tendria el precio de volver
 * permanente justo el error que este registro existe para evitar.
 *
 * Quien dicta ve CUANTAS confirmaciones lleva, no quienes las dieron.
 *
 * DESDE EL 21/09/2026 HAY DOS CAMINOS y no uno: el boton del estudiante desde su
 * sesion, y el profesor leyendo su carne QR en el salon. Los dos escriben aqui
 * y cuentan igual, que es la decision del usuario tomada con la objecion
 * delante; `origen` los distingue para despues, porque una cifra que mezcla las
 * dos no se puede auditar. Lo que NO se duplica son las reglas de cuando se
 * puede escribir una: viven en `registrar()`, abajo, y por ahi pasan los dos.
 */
class ConfirmacionClase extends Model
{
    use DeLaInstitucion;

    protected $table = 'confirmaciones_clase';

    /** La confirmo el propio estudiante desde su sesion. */
    public const PROPIA = 'propia';

    /** La dio por buena el profesor leyendo el carne QR en clase. */
    public const CARNE = 'carne';

    protected $fillable = [
        'clase_id',
        'matricula_id',
        'fecha',
        'origen',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $confirmacion) {
            $confirmacion->fecha ??= now();
        });
    }

    /**
     * Escribe la confirmacion si las reglas lo permiten, o devuelve null.
     *
     * ES LA UNICA PUERTA DE ESCRITURA, y existe porque desde el 21/09/2026 hay
     * dos caminos que llegan aqui. Escritas a mano en cada controlador, las dos
     * copias se separan sin que nada falle: el dia que alguien mueva el plazo o
     * el corte de la falta, lo arreglaria en la pantalla que estuviera mirando y
     * el otro camino seguiria con la regla vieja. Eso ya paso en esta casa con
     * el contador de clases pendientes.
     *
     * LAS DOS REGLAS:
     *
     * 1. EL PLAZO. Se comprueba aqui ademas de donde se pinte el boton: una
     *    peticion enviada desde una pestana que quedo abierta llega igual, y a
     *    destiempo.
     * 2. QUIEN CONSTA AUSENTE NO CONFIRMA. Dar fe de una clase es decir que se
     *    vio, y a quien esta marcado «Falto» —o «Falto con excusa», que avisar
     *    es lo contrario de desaparecer pero tampoco es haber estado— el sistema
     *    tiene escrito que no estuvo. NO HAY FILA NO ES LO MISMO: significa que
     *    no la paso nadie, y eso no afirma que faltara; por eso el corte mira el
     *    ESTADO y `null` pasa.
     *
     * `firstOrCreate` y no `create`: dos pulsaciones del mismo boton —o el
     * mismo carne leido dos veces, que con una camara encendida es lo normal—
     * no pueden acabar en un error de integridad contra el indice unico. Quien
     * llama distingue si escribio de verdad con `wasRecentlyCreated`.
     *
     * EL `origen` SOLO SE ESCRIBE AL CREAR. Una clase que el estudiante ya
     * confirmo por su cuenta y despues se lee su carne conserva `propia`, que es
     * lo cierto: la dio el, y el carne solo repitio lo que ya estaba.
     */
    public static function registrar(
        Clase $clase,
        Matricula $matricula,
        ?string $estadoAsistencia,
        string $origen,
    ): ?self {
        if (! $clase->confirmacionAbierta()) {
            return null;
        }

        if ($estadoAsistencia !== null && $estadoAsistencia !== Asistencia::ASISTIO) {
            return null;
        }

        return static::firstOrCreate(
            ['clase_id' => $clase->id, 'matricula_id' => $matricula->id],
            ['origen' => $origen],
        );
    }

    public function clase(): BelongsTo
    {
        return $this->belongsTo(Clase::class);
    }

    public function matricula(): BelongsTo
    {
        return $this->belongsTo(Matricula::class);
    }
}
