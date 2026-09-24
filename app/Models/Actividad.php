<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Un curso, un taller, un grupo de proyeccion o un programa externo.
 *
 * Lo que separa esto de una promotoria no es el tamano ni la duracion: es COMO
 * se entra. A una promotoria se entra con una matricula que alguien confirma; a
 * una actividad NO se entra con matricula. Por eso esta clase no habla con
 * `Matricula` en ninguna parte, y no deberia empezar a hacerlo.
 *
 * PERO «SIN MATRICULA» NO QUIERE DECIR «POR UN ENLACE» EN LOS CUATRO TIPOS, y
 * esta linea es la que hay que leer antes de tocar nada aqui. Hasta el
 * 23/09/2026 los tres tipos que habia compartian la puerta —un enlace publico
 * que alguien comparte— y este docblock decia justamente eso. El cuarto la
 * rompe:
 *
 * - CURSO, TALLER y PROYECCION: se entra por el enlace. Quien lo abre se
 *   apunta solo, sin cuenta.
 * - EXTERNO: NO SE ENTRA. Es lo que un profesor de la casa va a dictar a otra
 *   institucion, y la lista de asistentes la escribe el alli mismo —nombre y
 *   edad— porque quienes estan en ese salon son estudiantes de la OTRA
 *   entidad. No tiene enlace que compartir, tiene una institucion que le da fe.
 *
 * De ahi que `esExterno()` y `llevaEnlace()` existan y que casi todo lo que
 * pregunte por el enlace tenga que pasar por el segundo: un programa externo
 * TIENE `token` —la columna es obligatoria— y ese token no abre nada, porque
 * `InscripcionActividadController` cierra por TIPO. Quien quite ese corte
 * creyendo que basta con `abierta = false` abre una puerta publica a una lista
 * de menores de otra institucion.
 *
 * El responsable puede ser profesor, director o administrador. No se pide el
 * rol "profesor" por lo mismo que en `Promotoria`: quien dirige una banda
 * sinfonica es a menudo el director de la escuela, y con el rol como unico
 * criterio no podria ni quedar a cargo ni pasar lista.
 */
class Actividad extends Model
{
    /** Un solo dia. */
    public const TALLER = 'taller';

    /** Varios dias, con sus fechas decididas al crearlo. */
    public const CURSO = 'curso';

    /** Sin fechas: se ensaya cuando toque. */
    public const PROYECCION = 'proyeccion';

    /**
     * Lo que se dicta EN OTRA INSTITUCION, que da fe de que se dicto.
     *
     * Sin fechas puestas, como la proyeccion: la clase nace el dia que el
     * profesor llega alla y oprime "Iniciar". Lo que lo separa de los otros
     * tres esta en la cabecera de la clase — no tiene puerta publica.
     */
    public const EXTERNO = 'externo';

    public const TIPOS = [self::TALLER, self::CURSO, self::PROYECCION, self::EXTERNO];

    /** Los que se administran juntos, en el boton "Cursos y talleres". */
    public const TIPOS_CON_FECHAS = [self::CURSO, self::TALLER];

    /**
     * Los tres que viven de un enlace publico.
     *
     * Se declara la lista de los que SI y no la del que no, a proposito: asi
     * un quinto tipo que alguien anada manana nace SIN puerta publica y hay
     * que ponersela aposta. Al reves —una lista de excluidos— el tipo nuevo
     * nace con el enlace abierto y nadie se entera.
     */
    public const TIPOS_CON_ENLACE = [self::TALLER, self::CURSO, self::PROYECCION];

    /**
     * Como se llama cada tipo en pantalla.
     *
     * Con tildes, que es texto de interfaz; las constantes de arriba viajan a la
     * base y van sin ellas.
     */
    public const ETIQUETA_TIPO = [
        self::TALLER => 'Taller',
        self::CURSO => 'Curso',
        self::PROYECCION => 'Grupo de proyección',
        self::EXTERNO => 'Programa externo',
    ];

    /**
     * Como se llama UNA reunion de cada tipo.
     *
     * El boton dice "Iniciar clase" en un curso y "Iniciar ensayo" en una banda,
     * porque es como lo llama quien lo va a oprimir.
     */
    public const ETIQUETA_SESION = [
        self::TALLER => 'taller',
        self::CURSO => 'clase',
        self::PROYECCION => 'ensayo',
        self::EXTERNO => 'clase',
    ];

    protected $table = 'actividades';

    protected $fillable = [
        'tipo',
        'nombre',
        'responsable_id',
        'periodo_id',
        'cupo_maximo',
        'institucion_id',
    ];

    protected function casts(): array
    {
        return [
            'cupo_maximo' => 'integer',
            'abierta' => 'boolean',
        ];
    }

    /**
     * El defecto que declara la migracion, repetido aqui a proposito.
     *
     * Es la trampa que ya esta documentada en `ConfiguracionInstitucion`: el
     * defecto lo pone la BASE al insertar, y el modelo en memoria no lo ha
     * leido. Sin esto, una actividad recien creada responde `null` a `abierta`
     * en la misma peticion que la estrena, y eso es exactamente lo contrario de
     * lo que acaba de pasar.
     */
    protected $attributes = [
        'abierta' => true,
    ];

    /**
     * El enlace se sortea al crear y no se vuelve a tocar.
     *
     * Va en un hook y no en el controlador porque el token no es un dato que
     * alguien elija: es la identidad publica de la actividad, y una creada
     * desde un seeder o desde una prueba tiene que tener el suyo igual. Sin
     * esto, `token` seria NOT NULL sin valor y la insercion fallaria lejos de
     * aqui, con un mensaje del motor.
     */
    protected static function booted(): void
    {
        static::creating(function (self $actividad) {
            $actividad->token ??= Str::random(32);

            // UN PROGRAMA EXTERNO NACE CERRADO, siempre y venga de donde venga.
            //
            // Va aqui y NO en `$fillable` ni en el controlador, y la diferencia
            // ya mordio una vez: `abierta` no esta en `$fillable`, asi que un
            // `new Actividad([... 'abierta' => false])` lo descarta EN SILENCIO
            // y el `$attributes` de mas abajo lo deja en `true`. Lo cazo una
            // prueba; en produccion habria sido un programa externo marcado como
            // «recibe inscripciones» sin que nadie lo pidiera.
            //
            // En un hook vale ademas para lo que no pasa por el controlador —un
            // seeder, una prueba, el comando de simulacion—, que es el mismo
            // motivo por el que el token se sortea aqui arriba.
            //
            // Es el SEGUNDO cerrojo: el primero es el corte por tipo de
            // `InscripcionActividadController`. Se ponen los dos porque se leen
            // en sitios distintos y el primero que alguien mire tiene que decir
            // la verdad.
            if ($actividad->tipo === self::EXTERNO) {
                $actividad->abierta = false;
            }
        });
    }

    /** @param  Builder<self>  $consulta */
    public function scopeConFechas(Builder $consulta): void
    {
        $consulta->whereIn('tipo', self::TIPOS_CON_FECHAS);
    }

    /** @param  Builder<self>  $consulta */
    public function scopeDeProyeccion(Builder $consulta): void
    {
        $consulta->where('tipo', self::PROYECCION);
    }

    /** @param  Builder<self>  $consulta */
    public function scopeExternos(Builder $consulta): void
    {
        $consulta->where('tipo', self::EXTERNO);
    }

    /** Quien la dirige y le pasa lista. Puede ser un director o el administrador. */
    public function responsable(): BelongsTo
    {
        return $this->belongsTo(Perfil::class, 'responsable_id');
    }

    public function periodo(): BelongsTo
    {
        return $this->belongsTo(Periodo::class);
    }

    /**
     * La institucion que recibe la clase. SOLO en un programa externo.
     *
     * NULL en los otros tres tipos, y el CHECK de la base lo exige en las dos
     * direcciones: un externo no puede estar sin ella y un taller no puede
     * tenerla. Preguntarla en una pantalla que mezcle tipos se hace con
     * `esExterno()` delante, no con un `?->` que calla.
     */
    /** @return BelongsTo<InstitucionExterna, $this> */
    public function institucion(): BelongsTo
    {
        return $this->belongsTo(InstitucionExterna::class, 'institucion_id');
    }

    /**
     * Sus dias, del primero al ultimo.
     *
     * ANOTADA con su tipo: sin esto el analizador ve una coleccion de `Model`
     * generico, y todo lo que se le pregunte a una sesion —`yaEmpezo()`,
     * `estaVerificada()`, `iniciada_en`— sale como metodo o propiedad que no
     * existe en los controladores que la recorren.
     *
     * @return HasMany<SesionActividad, $this>
     */
    public function sesiones(): HasMany
    {
        return $this->hasMany(SesionActividad::class, 'actividad_id')->orderBy('fecha');
    }

    /**
     * Quien esta en la lista: apuntado por el enlace, anadido en una sesion, o
     * escrito por el profesor si es un programa externo.
     *
     * @return HasMany<InscritoActividad, $this>
     */
    public function inscritos(): HasMany
    {
        return $this->hasMany(InscritoActividad::class, 'actividad_id');
    }

    /**
     * La direccion que se comparte.
     *
     * Absoluta, no relativa: esto se pega en un WhatsApp, no se pulsa dentro de
     * la aplicacion.
     */
    public function enlace(): string
    {
        return route('actividad-inscribirse', $this->token);
    }

    /**
     * Si el enlace todavia admite gente.
     *
     * Dos cosas lo cierran y son distintas a proposito: el CUPO lo cierra solo
     * cuando se llena, y `abierta` lo cierra porque alguien lo decidio. Una
     * actividad sin cupo no se llena nunca, asi que ahi el interruptor es lo
     * unico que puede pararla.
     *
     * Recibe el conteo en vez de consultarlo para que un listado no pague una
     * consulta por fila; sin el, lo pregunta.
     */
    public function admiteInscripciones(?int $inscritos = null): bool
    {
        // UN PROGRAMA EXTERNO NO ADMITE NUNCA, y el corte va aqui ademas de en
        // el controlador publico. Este metodo es el que deciden las pantallas
        // para pintar o no el enlace, y con la respuesta buena de entrada no
        // hay forma de que una pantalla nueva ofrezca compartir la URL de una
        // lista que no se puebla por ahi.
        if (! $this->llevaEnlace()) {
            return false;
        }

        if (! $this->abierta) {
            return false;
        }

        if ($this->cupo_maximo === null) {
            return true;
        }

        return ($inscritos ?? $this->inscritos()->count()) < $this->cupo_maximo;
    }

    /**
     * Que es una actividad de tantas clases.
     *
     * El tipo NO se elige: se deduce de cuantos dias tiene, porque un taller es
     * exactamente eso —"los talleres son solo de un dia"—. Preguntarlo aparte
     * dejaba crear un taller de cuatro dias y un curso de uno, y entonces el
     * nombre del tipo dejaba de querer decir nada.
     *
     * Se aplica al crear, con el numero que se pidio, y otra vez cada vez que se
     * guardan las fechas: quitarle dias a un curso hasta dejarlo en uno lo
     * convierte en taller, que es lo que ha pasado de verdad.
     */
    public static function tipoSegunClases(int $clases): string
    {
        return $clases <= 1 ? self::TALLER : self::CURSO;
    }

    /** El nombre del tipo tal como se pinta. */
    public function etiquetaTipo(): string
    {
        return self::ETIQUETA_TIPO[$this->tipo] ?? $this->tipo;
    }

    /** Como se llama una reunion suya: clase, taller o ensayo. */
    public function etiquetaSesion(): string
    {
        return self::ETIQUETA_SESION[$this->tipo] ?? 'sesión';
    }

    /**
     * Lo mismo, con su articulo delante.
     *
     * Existe porque las tres palabras no concuerdan igual: "clase" es femenina
     * y "taller" y "ensayo" masculinos, asi que cualquier frase que las lleve
     * con un participio detras sale mal en dos de los tres casos. Se vio en
     * pantalla —decia «Taller iniciada»— y no en las pruebas, que miraban la
     * redireccion y no el texto.
     *
     * Con el articulo delante la frase se construye al reves —«Empezo el
     * taller»— y el participio deja de tener que concordar con nada.
     */
    public function etiquetaSesionConArticulo(): string
    {
        return match ($this->tipo) {
            self::CURSO, self::EXTERNO => 'la clase',
            self::TALLER => 'el taller',
            self::PROYECCION => 'el ensayo',
            default => 'la sesión',
        };
    }

    /** Si lleva fechas propias, que es lo que separa un curso de una proyeccion. */
    public function llevaFechas(): bool
    {
        return in_array($this->tipo, self::TIPOS_CON_FECHAS, true);
    }

    /** Lo que se dicta en otra institucion, que es quien le da fe. */
    public function esExterno(): bool
    {
        return $this->tipo === self::EXTERNO;
    }

    /**
     * Si tiene puerta publica: la URL que alguien comparte para apuntarse.
     *
     * TODAS las actividades tienen `token` —la columna es obligatoria— asi que
     * preguntar por el token no responde a esto. Lo que decide es el TIPO.
     */
    public function llevaEnlace(): bool
    {
        return in_array($this->tipo, self::TIPOS_CON_ENLACE, true);
    }

    public function __toString(): string
    {
        return "{$this->nombre} ({$this->etiquetaTipo()})";
    }
}
