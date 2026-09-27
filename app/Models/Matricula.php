<?php

namespace App\Models;

use App\Support\Auditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Inscripcion de un estudiante en una PROMOTORIA, para un periodo dado.
 *
 * El estudiante no elige grupo/horario al matricularse: la matricula nace sin
 * ninguno y quien dicta reparte a los matriculados segun su horario. Desde el
 * 10/09/2026 puede estar en VARIOS de la misma promotoria —quien va el lunes y
 * el miercoles—, y por eso los grupos viven en `asignaciones_grupo` y no en una
 * columna. Se reparte con `repartirEn()`.
 *
 * Toda matricula nace 'pendiente'. Quien dicta la promotoria debe confirmarla
 * antes de que cuente como activa, y solo entonces se le puede asignar grupo.
 *
 * Un estudiante no puede acumular mas promotorias sin retirar en el mismo
 * periodo que las que permita la configuracion de la institucion.
 */
class Matricula extends Model
{
    public const PENDIENTE = 'pendiente';

    public const ACTIVA = 'activa';

    public const CANCELACION_SOLICITADA = 'cancelacion_solicitada';

    public const RETIRADA = 'retirada';

    /**
     * Etiquetas legibles. Van acentuadas porque se pintan tal cual en pantalla
     * — los comentarios de este proyecto van sin tildes, el texto de cara al
     * usuario no.
     */
    public const ESTADOS = [
        self::PENDIENTE => 'Pendiente de confirmación',
        self::ACTIVA => 'Activa',
        self::CANCELACION_SOLICITADA => 'Cancelación solicitada',
        self::RETIRADA => 'Retirada',
    ];

    /**
     * "Sigue inscrito ahora mismo": la activa y la que esta pidiendo salir.
     *
     * Se usa donde importa quien esta EN la clase —los listados de quien dicta,
     * el cupo de un grupo— para que una cancelacion en tramite no borre al
     * estudiante de la vista de su propio profesor antes de resolverse.
     */
    public const ESTADOS_INSCRITO = [self::ACTIVA, self::CANCELACION_SOLICITADA];

    /**
     * 'finalizada' NO es un estado guardado, y no esta en ESTADOS a proposito:
     * es lo que se MUESTRA de una matricula activa cuyo periodo ya quedo atras.
     *
     * No se guarda porque el dato no cambia —la matricula siguio activa hasta el
     * final—, lo que cambia es el calendario. Convertirlo en un cuarto valor
     * real obligaria a migrar las filas y a reescribir la veintena de consultas
     * que filtran por 'activa'; entre ellas la de renovacion, que busca
     * precisamente matriculas ACTIVAS de periodos anteriores. Con las filas
     * migradas no encontraria ninguna y ningun estudiante antiguo podria
     * renovar.
     */
    public const FINALIZADA = 'finalizada';

    /**
     * 'rechazada' tampoco es un estado guardado, y por la misma razon que
     * 'finalizada': es lo que se MUESTRA de una retirada que llego ahi porque
     * alguien dijo que no. Lo guardado son dos cosas —el estado 'retirada' y el
     * motivo 'rechazo'—, y esto es como se leen juntas.
     *
     * Se hace asi y no con un sexto valor en `estado` porque un estado nuevo
     * obliga a revisar las casi cincuenta consultas que filtran por los que ya
     * hay; una columna que EXPLICA no cambia ninguna respuesta. Es la misma
     * leccion que dejo escrita 'finalizada' aqui arriba.
     */
    public const RECHAZADA = 'rechazada';

    /**
     * POR QUE quedo retirada una matricula.
     *
     * 'retirada' es el desenlace de cuatro cosas que no se parecen en nada: que
     * te digan que no, echarte atras tu, que la direccion tramite tu
     * cancelacion, y que te retiren por no aparecer. Hasta el 04/09/2026 las
     * cuatro dejaban la misma fila y el estudiante leia «Retirada» sin poder
     * saber cual de las cuatro le paso.
     *
     * NULO es un valor legitimo: significa «no se registro». Lo llevan las filas
     * anteriores al cambio, de las que no hay forma de deducirlo.
     */
    public const RETIRO_RECHAZO = 'rechazo';

    public const RETIRO_PROPIO = 'propio';

    public const RETIRO_CANCELACION = 'cancelacion';

    public const RETIRO_ABANDONO = 'abandono';

    /** @var array<string, string> */
    public const MOTIVOS_RETIRO = [
        self::RETIRO_RECHAZO => 'La solicitud no fue aceptada',
        self::RETIRO_PROPIO => 'La retiró el propio estudiante',
        self::RETIRO_CANCELACION => 'Cancelación tramitada por la dirección',
        self::RETIRO_ABANDONO => 'Retirada por inasistencia',
    ];

    protected $table = 'matriculas';

    protected $fillable = [
        'estudiante_id',
        'promotoria_id',
        'periodo_id',
        'fecha',
        'estado',
        'motivo_retiro',
        'ranura',
    ];

    protected $attributes = [
        'estado' => self::PENDIENTE,
        'ranura' => 1,
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'datetime',
            'ranura' => 'integer',
        ];
    }

    // La tabla tiene ademas `ranura_activa`, la columna generada que emula el
    // indice unico parcial de Postgres. La calcula la base de datos: se lee,
    // nunca se escribe.

    protected static function booted(): void
    {
        static::creating(function (self $matricula) {
            $matricula->fecha ??= now();
        });

        // Colocar la ranura antes de escribir, tanto al crear como al guardar:
        // dos matriculas nuevas nacen ambas con la ranura por defecto y
        // chocarian entre si.
        static::saving(function (self $matricula) {
            $matricula->colocarEnRanuraLibre();
        });

        /*
         * Rastro de las matriculas retiradas (F-02).
         *
         * Va en el MODELO y no en los controladores porque hoy hay TRES caminos
         * que retiran --el estudiante retirando una solicitud suya, aprobar una
         * cancelacion, y aprobarlas en lote-- y escribirlo tres veces es la
         * forma segura de que el cuarto se olvide. Es la misma leccion que dejo
         * B-01 con la asistencia: la regla en un sitio, no una copia por
         * pantalla.
         *
         * Se registra el estado ANTERIOR porque es lo que distingue los casos:
         * `pendiente` es alguien que se echo atras antes de que lo confirmaran,
         * y `cancelacion_solicitada` es una salida que alguien de direccion
         * aprobo. Sin ese dato las dos se leen igual en el archivo.
         *
         * `auth()` puede no haber nadie: `php artisan simular` tambien retira, y
         * ahi `quien` sale null a proposito, que es informacion correcta --lo
         * hizo el sistema, no una persona--.
         */
        static::updated(function (self $matricula) {
            if (! $matricula->wasChanged('estado') || $matricula->estado !== self::RETIRADA) {
                return;
            }

            Auditoria::registrar('matricula.retirada', [
                'matricula_id' => $matricula->id,
                'estudiante_id' => $matricula->estudiante_id,
                'promotoria_id' => $matricula->promotoria_id,
                'periodo_id' => $matricula->periodo_id,
                'desde' => $matricula->getOriginal('estado'),
            ], auth()->user()?->perfil);
        });
    }

    /**
     * Quien cursa la matricula.
     *
     * El tipo va ANOTADO y no solo en el `belongsTo`: sin el, el analizador ve
     * un `Model` generico y cada `$matricula->estudiante->nombre_completo` de
     * las pantallas sale como propiedad inexistente. Habia dos de esas en la
     * linea base y con esto se van las dos.
     *
     * @return BelongsTo<Perfil, $this>
     */
    public function estudiante(): BelongsTo
    {
        return $this->belongsTo(Perfil::class, 'estudiante_id');
    }

    /**
     * La promotoria a la que pertenece.
     *
     * Anotada por lo mismo que `estudiante()`: sin el tipo, el analizador ve un
     * `Model` generico. Aqui ademas ya no es solo cosa de las pantallas —desde
     * que una accion del Panel le pasa `$matricula->promotoria` a `volver()`
     * para responder sin recargar, un `Model` suelto es un error de tipo en el
     * propio controlador.
     *
     * @return BelongsTo<Promotoria, $this>
     */
    public function promotoria(): BelongsTo
    {
        return $this->belongsTo(Promotoria::class);
    }

    /**
     * Las matriculas de las promotorias que esta persona ve: el recorte del
     * director, llevado a las matriculas.
     *
     * No escribe la regla: pregunta a `Promotoria::queVe()`, que es su unica
     * casa. Existe desde el 27/09/2026 porque la bandeja de cancelaciones se
     * quedo sin recorte el 12/09 —las dos alertas de la misma pantalla si lo
     * llevaban— y el director veia y podia resolver las de toda la casa.
     *
     * @param  Builder<Matricula>  $query
     */
    public function scopeQueVe(Builder $query, Perfil $perfil): void
    {
        $query->whereIn(
            $this->qualifyColumn('promotoria_id'),
            Promotoria::queVe($perfil)->select('promotorias.id')
        );
    }

    /**
     * Los grupos en los que esta repartida esta matricula.
     *
     * EXISTE PARA QUE UNA PERSONA PUEDA IR A DOS HORARIOS de la misma
     * promotoria —el Grupo A el lunes y el Grupo B el miercoles—, que es un
     * caso corriente que hasta el 10/09/2026 no se podia representar: la
     * columna `matriculas.grupo_id` solo admite uno.
     *
     * OJO, HOY TODAVIA NO MANDA. Mientras la columna siga existiendo, ELLA es
     * la verdad y esta relacion es una copia que hizo la migracion. El trabajo
     * va en tres pasos —la tabla, los lectores, y borrar la columna— y hasta
     * que caiga la columna no hay que fiarse de esta para decidir nada. Se
     * añade ahora, sin usarla, para que el paso siguiente pueda mover un lector
     * cada vez con la suite verde en medio.
     *
     * Sigue siendo UNA matricula por promotoria: el cupo de promotoria, las
     * ranuras, el certificado y la renovacion no se enteran de esto. Lo que se
     * multiplica son las sillas, no las inscripciones.
     *
     * @return BelongsToMany<Grupo, $this>
     */
    public function grupos(): BelongsToMany
    {
        return $this->belongsToMany(Grupo::class, 'asignaciones_grupo')
            ->withTimestamps();
    }

    /**
     * El periodo en el que se matriculo.
     *
     * Anotado en la misma tanda que `promotoria()`, y no por gusto de simetria:
     * en cuanto la promotoria dejo de ser un `Model` generico, el analizador
     * pudo por fin resolver `cupoEn()` y `ocupadosEn()` — y lo primero que dijo
     * es que el periodo que se les pasa seguia siendo generico. Anotar solo una
     * de las dos cambia dos avisos por otros dos.
     *
     * @return BelongsTo<Periodo, $this>
     */
    public function periodo(): BelongsTo
    {
        return $this->belongsTo(Periodo::class);
    }

    public function asistencias(): HasMany
    {
        return $this->hasMany(Asistencia::class);
    }

    public function confirmacionesClase(): HasMany
    {
        return $this->hasMany(ConfirmacionClase::class);
    }

    // -----------------------------------------------------------------------
    // Ranuras y limite de promotorias
    // -----------------------------------------------------------------------

    /**
     * Cuantas promotorias puede cursar un estudiante en un mismo periodo.
     *
     * Sale de la configuracion, no de una constante: el administrador lo cambia
     * sin tocar codigo ni migrar. Cuentan las pendientes y las activas —una
     * solicitud pendiente ya ocupa cupo, para que nadie pida otra mientras
     * espera confirmacion— y las retiradas (incluidas las rechazadas, que
     * quedan retiradas) lo liberan.
     */
    public static function limitePromotorias(): int
    {
        return ConfiguracionInstitucion::actual()->limite_promotorias_por_periodo;
    }

    /** Cuantos cupos de promotoria tiene ocupados el estudiante en el periodo. */
    public static function promotoriasOcupadas(int $estudianteId, int $periodoId, ?int $excluirId = null): int
    {
        return static::where('estudiante_id', $estudianteId)
            ->where('periodo_id', $periodoId)
            ->where('estado', '!=', self::RETIRADA)
            ->when($excluirId !== null, fn ($q) => $q->where('id', '!=', $excluirId))
            ->count();
    }

    /**
     * Ranuras que ya usan las otras matriculas sin retirar del estudiante.
     *
     * @return list<int>
     */
    private function ranurasOcupadasPorOtras(): array
    {
        return static::where('estudiante_id', $this->estudiante_id)
            ->where('periodo_id', $this->periodo_id)
            ->where('estado', '!=', self::RETIRADA)
            ->when($this->exists, fn ($q) => $q->where('id', '!=', $this->id))
            ->pluck('ranura')
            ->map(fn ($r) => (int) $r)
            ->all();
    }

    /**
     * Primera ranura libre para el estudiante, o null si ya no tiene cupo.
     *
     * Sin cupo = tiene tantas matriculas sin retirar como permite el limite
     * configurado. Se mira la CANTIDAD y no si quedan huecos en 1..limite,
     * porque desde que el limite es configurable las dos cosas dejaron de ser
     * equivalentes: si el administrador lo baja de 2 a 1, un estudiante con su
     * unica matricula en la ranura 2 tiene libre la 1 y aun asi esta en el tope.
     *
     * Las ranuras se numeran hasta RANURA_MAXIMA_ABSOLUTA para poder colocar las
     * que ya existan por encima del limite nuevo.
     */
    private function primeraRanuraLibre(?int $limite = null): ?int
    {
        $limite ??= self::limitePromotorias();
        $ocupadas = $this->ranurasOcupadasPorOtras();

        if (count($ocupadas) >= $limite) {
            return null;
        }

        for ($ranura = 1; $ranura <= ConfiguracionInstitucion::RANURA_MAXIMA_ABSOLUTA; $ranura++) {
            if (! in_array($ranura, $ocupadas, true)) {
                return $ranura;
            }
        }

        return null;
    }

    /**
     * Mueve la matricula a una ranura libre si otra ya ocupa la suya.
     *
     * Pasa al crear la segunda matricula (ambas nacen con la ranura por defecto)
     * y al reactivar una retirada. Si no queda ninguna libre se deja como esta a
     * proposito: asi el indice unico de la base de datos rechaza el guardado.
     *
     * Colocar bien la ranura ya NO basta para imponer el limite de promotorias:
     * cuando la ranura por defecto esta libre esto no hace nada, y el estudiante
     * podria pasarse del tope configurado sin que ningun indice se queje. De eso
     * se ocupa `validar()`.
     */
    private function colocarEnRanuraLibre(?int $limite = null): bool
    {
        if ($this->estado === self::RETIRADA || ! $this->estudiante_id || ! $this->periodo_id) {
            return false;
        }

        if (! in_array((int) $this->ranura, $this->ranurasOcupadasPorOtras(), true)) {
            return false;
        }

        $libre = $this->primeraRanuraLibre($limite);

        if ($libre === null) {
            return false;
        }

        $this->ranura = $libre;

        return true;
    }

    /**
     * ¿Guardar esta matricula SUMA un sitio al cupo de la promotoria?
     *
     * Confirmar, rechazar, asignar grupo o mover de grupo NO cambian cuantos
     * sitios se ocupan, asi que no se les puede aplicar el tope: si no, bajar el
     * cupo dejaria al personal sin poder tocar las matriculas que ya existen.
     * Solo suman: crear una matricula, reactivar una retirada, o moverla a otra
     * promotoria/periodo.
     *
     * Es la misma condicion que aplica el trigger de la base de datos. Las dos
     * tienen que decir lo mismo o una de las dos sobra.
     */
    public function aumentaOcupacion(): bool
    {
        if ($this->estado === self::RETIRADA) {
            return false;
        }

        if (! $this->exists) {
            return true;
        }

        $anterior = static::withoutGlobalScopes()
            ->where('id', $this->id)
            ->first(['estado', 'promotoria_id', 'periodo_id']);

        if ($anterior === null) {
            return true;
        }

        if ($anterior->estado === self::RETIRADA) {
            return true;
        }

        return $anterior->promotoria_id !== $this->promotoria_id
            || $anterior->periodo_id !== $this->periodo_id;
    }

    // -----------------------------------------------------------------------
    // Validacion
    // -----------------------------------------------------------------------

    /**
     * Las reglas que el esquema no puede imponer por si solo.
     *
     * Hay que llamarla ANTES de guardar. No va en un hook de `saving` porque el
     * trigger de cupo y el indice unico ya cubren el caso de que algo se salte
     * esta capa, y porque los mensajes de aqui son los que ve el usuario: tienen
     * que llegar al formulario, no como excepcion suelta.
     */
    public function validar(): void
    {
        // Se resuelve una vez y se reparte: leer la configuracion es una
        // consulta, y aqui hacen falta dos usos del mismo numero.
        $limite = self::limitePromotorias();

        // Colocar la ranura va antes de validar: si no, la segunda matricula
        // chocaria todavia con la ranura por defecto.
        $this->colocarEnRanuraLibre($limite);

        $aumenta = $this->aumentaOcupacion();

        // -- Limite de promotorias por periodo -------------------------------
        // Se comprueba AQUI. Antes bastaba el indice unico parcial sobre
        // `ranura`, porque habia exactamente tantas ranuras como permitia el
        // limite; desde que el limite es configurable el esquema solo conoce el
        // techo absoluto y no puede consultar la configuracion. El indice sigue
        // detras como red: acota cualquier fuga a RANURA_MAXIMA_ABSOLUTA y
        // cierra la carrera entre dos peticiones simultaneas.
        if ($this->estudiante_id && $this->periodo_id && $aumenta) {
            $ocupadas = self::promotoriasOcupadas(
                $this->estudiante_id,
                $this->periodo_id,
                $this->exists ? $this->id : null
            );

            if ($ocupadas >= $limite) {
                $palabra = $limite === 1 ? 'promotoría' : 'promotorías';

                throw ValidationException::withMessages([
                    'promotoria' => "Un estudiante puede estar en un máximo de {$limite} {$palabra} "
                        .'por periodo, y este ya tiene ese cupo ocupado. Retira una matrícula '
                        .'antes de agregar otra.',
                ]);
            }
        }

        // -- Cupo de la promotoria en el periodo -----------------------------
        // Pendientes y activas ocupan cupo por igual: una solicitud sin
        // confirmar ya reserva el sitio, para que quien dicta no acabe
        // rechazando una lista de espera entera. Sin cupo definido no hay tope.
        // El mismo tope lo reaplica el trigger, que ademas serializa las
        // peticiones simultaneas.
        if ($this->promotoria_id && $this->periodo_id && $aumenta) {
            $promotoria = $this->promotoria ?? Promotoria::find($this->promotoria_id);
            $periodo = $this->periodo ?? Periodo::find($this->periodo_id);
            $maximo = $promotoria?->cupoEn($periodo);

            if ($maximo !== null) {
                $ocupados = $promotoria->ocupadosEn($periodo, $this->exists ? $this->id : null);

                if ($ocupados >= $maximo) {
                    throw ValidationException::withMessages([
                        'promotoria' => "{$promotoria} no tiene cupos disponibles para {$periodo}: "
                            ."{$ocupados} de {$maximo} ocupados, contando las solicitudes pendientes.",
                    ]);
                }
            }
        }

        // La coherencia del GRUPO ya no se comprueba aqui: desde el 10/09/2026
        // una matricula esta en varios y se reparte con `repartirEn()`, que es
        // quien lleva esa regla. Aqui quedaria a medias — no hay un grupo
        // «pendiente» en el modelo del que tirar.
    }

    /**
     * Reparte esta matricula en los grupos dados. Es la unica forma de hacerlo.
     *
     * SUSTITUYE A `$matricula->grupo_id = ...`, que desaparecio con la columna
     * el 10/09/2026. Recibe ids de grupo: una lista vacia la deja sin grupo, que
     * es lo que hacen retirar y cancelar.
     *
     * ─── POR QUE LA REGLA VIVE AQUI Y NO EN `validar()` ────────────────────
     *
     * Porque con la columna habia un grupo «pendiente» en el modelo que
     * `validar()` podia mirar antes de guardar, y con una tabla puente no lo
     * hay: lo que se va a escribir es el argumento de este metodo. Partida en
     * dos —comprobar en un sitio y escribir en otro— quedaria una ventana entre
     * las dos, que es justo lo que no se quiere en un cupo.
     *
     * ─── LO QUE COMPRUEBA, y las dos cosas son de la base ───────────────────
     *
     * 1. Que cada grupo sea de la MISMA promotoria. Es una condicion entre dos
     *    tablas y ningun CHECK de SQL puede consultar otra fila.
     * 2. El CUPO de cada grupo, contando lo que ya hay dentro y excluyendose a
     *    si misma. Ojo: se comprueba solo para los grupos en los que ENTRA. Un
     *    grupo en el que ya estaba no se revisa, porque bajar un cupo por debajo
     *    de lo ocupado es legitimo —el personal puede necesitarlo— y si se
     *    revisara, nadie podria tocar a quien ya esta dentro. Es la misma
     *    decision que ya tiene escrita el trigger de la promotoria.
     *
     * Va en una TRANSACCION: si el segundo grupo no cabe, no se queda dentro
     * del primero. Todo o nada, como el reparto en lote del Panel.
     *
     * @param  list<int>  $gruposIds
     *
     * @throws ValidationException
     */
    public function repartirEn(array $gruposIds): void
    {
        $gruposIds = array_values(array_unique(array_map('intval', $gruposIds)));
        $yaEstaba = $this->grupos()->pluck('grupos.id')->all();
        $entra = array_diff($gruposIds, $yaEstaba);

        $grupos = Grupo::whereIn('id', $gruposIds)->get()->keyBy('id');

        foreach ($gruposIds as $id) {
            $grupo = $grupos->get($id);

            if ($grupo === null) {
                throw ValidationException::withMessages([
                    'grupo' => 'El grupo elegido ya no existe.',
                ]);
            }

            if ($grupo->promotoria_id !== $this->promotoria_id) {
                throw ValidationException::withMessages([
                    'grupo' => 'El grupo elegido no pertenece a esta promotoría.',
                ]);
            }

            if (! in_array($id, $entra, true)) {
                continue;
            }

            $periodo = $this->periodo ?? Periodo::find($this->periodo_id);

            if ($grupo->ocupadosEn($periodo, $this->id) >= $grupo->cupo_maximo) {
                throw ValidationException::withMessages([
                    'grupo' => "{$grupo->nombre} no tiene cupos disponibles para este periodo.",
                ]);
            }
        }

        DB::transaction(fn () => $this->grupos()->sync($gruposIds));

        $this->unsetRelation('grupos');
    }

    // -----------------------------------------------------------------------
    // Renovacion
    // -----------------------------------------------------------------------

    /**
     * Que puede renovar un estudiante antiguo, y de que periodo viene.
     *
     * Antiguo = tuvo al menos una matricula ACTIVA en un periodo anterior. Se
     * toma el ultimo periodo que curso (no todos), asi que quien se salto un
     * semestre puede renovar igual desde el ultimo en el que si estuvo.
     *
     * La lista excluye las promotorias en las que ya tiene matricula viva en el
     * periodo actual, para que renovar dos veces no sea posible. Un estudiante
     * nuevo recibe [null, []].
     *
     * @return array{0: ?Periodo, 1: list<self>}
     */
    public static function renovables(Perfil $perfil, ?Periodo $periodoActual): array
    {
        if ($periodoActual === null) {
            return [null, []];
        }

        $anteriores = static::query()
            ->where('estudiante_id', $perfil->id)
            ->where('estado', self::ACTIVA)
            ->whereHas('periodo', fn ($q) => $q->where('fecha_inicio', '<', $periodoActual->fecha_inicio))
            ->with(['periodo', 'promotoria.area'])
            ->join('periodos', 'periodos.id', '=', 'matriculas.periodo_id')
            ->orderByDesc('periodos.fecha_inicio')
            ->select('matriculas.*')
            ->get();

        if ($anteriores->isEmpty()) {
            return [null, []];
        }

        $periodoAnterior = $anteriores->first()->periodo;

        $delUltimoPeriodo = $anteriores
            ->filter(fn (self $m) => $m->periodo_id === $periodoAnterior->id);

        // Lo que ya tiene vivo en el periodo actual no se puede volver a pedir.
        $yaTiene = static::query()
            ->where('estudiante_id', $perfil->id)
            ->where('periodo_id', $periodoActual->id)
            ->where('estado', '!=', self::RETIRADA)
            ->pluck('promotoria_id')
            ->all();

        return [
            $periodoAnterior,
            $delUltimoPeriodo
                ->reject(fn (self $m) => in_array($m->promotoria_id, $yaTiene, true))
                ->values()
                ->all(),
        ];
    }

    // -----------------------------------------------------------------------
    // Historial
    // -----------------------------------------------------------------------

    /**
     * Todas las matriculas de un estudiante, agrupadas por periodo, del mas
     * reciente al mas antiguo.
     *
     * No hace falta ningun modelo de historial: cada matricula ya guarda su
     * periodo y nunca se borra al terminar (quien se va queda como 'retirada'),
     * asi que la trayectoria completa ya esta en la tabla y esto solo la ordena.
     *
     * Incluye a proposito las retiradas y las rechazadas —que tambien quedan
     * 'retirada'—: un historial que solo ensena lo que prospero no sirve para
     * entender de donde viene el estudiante, que es justo para lo que se
     * consulta.
     *
     * `en_curso` marca el periodo activo para que la plantilla separe lo vigente
     * del pasado sin volver a consultarlo, y para que solo ahi se ofrezcan
     * acciones: un periodo terminado es historial cerrado.
     *
     * @return list<array{periodo: Periodo, matriculas: list<self>, en_curso: bool}>
     */
    public static function historialPorPeriodo(Perfil $perfil): array
    {
        $periodoActual = Periodo::enCurso();

        $matriculas = static::query()
            ->where('estudiante_id', $perfil->id)
            ->with(['periodo', 'promotoria.area', 'promotoria.profesor', 'grupos.sesiones'])
            ->join('periodos', 'periodos.id', '=', 'matriculas.periodo_id')
            ->join('promotorias', 'promotorias.id', '=', 'matriculas.promotoria_id')
            ->join('areas', 'areas.id', '=', 'promotorias.area_id')
            // El id del periodo desempata para que las filas de un mismo periodo
            // queden contiguas aunque dos periodos compartan fecha de inicio; si
            // se intercalaran, el agrupado de abajo abriria dos bloques para uno.
            ->orderByDesc('periodos.fecha_inicio')
            ->orderByDesc('matriculas.periodo_id')
            ->orderBy('areas.nombre')
            ->orderBy('promotorias.nombre')
            ->select('matriculas.*')
            ->get();

        $bloques = [];

        foreach ($matriculas as $matricula) {
            $ultimo = count($bloques) - 1;

            if ($ultimo < 0 || $bloques[$ultimo]['periodo']->id !== $matricula->periodo_id) {
                $bloques[] = [
                    'periodo' => $matricula->periodo,
                    'matriculas' => [],
                    'en_curso' => $periodoActual !== null && $matricula->periodo_id === $periodoActual->id,
                ];
                $ultimo++;
            }

            $bloques[$ultimo]['matriculas'][] = $matricula;
        }

        return $bloques;
    }

    /**
     * Cifras de cabecera del historial: cuanto lleva el estudiante y cuanto ha
     * cursado.
     *
     * Solo cuentan las matriculas ACTIVAS, es decir las que un profesor
     * confirmo: eso es lo que el estudiante realmente curso. Las pendientes y
     * las retiradas siguen apareciendo en el detalle, pero no inflan estas
     * cifras — si contaran, quien pidio cinco promotorias y no entro a ninguna
     * se leeria como el estudiante mas veterano de la casa.
     *
     * @return array{periodos: int, promotorias: int, desde: ?Periodo}
     */
    public static function resumenTrayectoria(Perfil $perfil): array
    {
        $activas = static::query()
            ->where('estudiante_id', $perfil->id)
            ->where('estado', self::ACTIVA);

        return [
            'periodos' => (clone $activas)->distinct()->count('periodo_id'),
            'promotorias' => (clone $activas)->distinct()->count('promotoria_id'),
            'desde' => Periodo::query()
                ->whereHas('matriculas', fn ($q) => $q
                    ->where('estudiante_id', $perfil->id)
                    ->where('estado', self::ACTIVA))
                ->orderBy('fecha_inicio')
                ->first(),
        ];
    }

    // -----------------------------------------------------------------------
    // Estado
    // -----------------------------------------------------------------------

    public function getCancelacionPendienteAttribute(): bool
    {
        return $this->estado === self::CANCELACION_SOLICITADA;
    }

    /**
     * ¿Se puede NEGAR esta cancelacion, o solo aprobarla?
     *
     * Depende de la edad, y las dos ramas persiguen cosas distintas:
     *
     * - Menor de edad: si se puede rechazar. La aprobacion es una pausa real,
     *   para dar tiempo a hablar con el acudiente antes de que un nino se salga
     *   de la promotoria por su cuenta. Rechazar la devuelve a activa.
     * - Mayor de edad: no. Irse es decision suya y el sistema no se la discute;
     *   el paso por direccion existe para que quede constancia de en que
     *   promotorias se esta yendo la gente, no para retenerla.
     */
    public function getCancelacionEsRechazableAttribute(): bool
    {
        return $this->estudiante->es_menor;
    }

    /**
     * El estado tal como se LEE en pantalla, no como esta guardado.
     *
     * Se transforman DOS:
     *
     * - La ACTIVA de un periodo que ya termino pasa a "finalizada". Una
     *   pendiente sigue pendiente —fue una solicitud que nadie llego a
     *   confirmar, y decir que finalizo seria inventarse un desenlace—.
     * - La RETIRADA que llego ahi por un rechazo pasa a "rechazada". Las otras
     *   tres formas de quedar retirada siguen leyendose "Retirada", porque
     *   irse a mitad de camino es justo lo que ese estado cuenta del historial;
     *   la que no cuenta nada de eso es la solicitud que nunca se acepto, y
     *   confundirlas dejaba al estudiante sin saber si le dijeron que no.
     *
     * Una retirada SIN motivo tambien sigue leyendose "Retirada": es lo que les
     * toca a las filas anteriores al 04/09/2026, de las que no hay forma de
     * saberlo.
     */
    public function getEstadoVisibleAttribute(): string
    {
        if ($this->estado === self::ACTIVA && $this->periodo->termino) {
            return self::FINALIZADA;
        }

        if ($this->estado === self::RETIRADA && $this->motivo_retiro === self::RETIRO_RECHAZO) {
            return self::RECHAZADA;
        }

        return $this->estado;
    }

    public function getEstadoVisibleDisplayAttribute(): string
    {
        $etiquetas = self::ESTADOS + [
            self::FINALIZADA => 'Finalizada',
            self::RECHAZADA => 'Rechazada',
        ];

        return $etiquetas[$this->estado_visible];
    }

    public function __toString(): string
    {
        // El primero de sus grupos, o la promotoria si no tiene ninguno. Con
        // varios se nombra uno y no todos: esto sale en avisos de una linea.
        $destino = $this->grupos->first() ?? $this->promotoria;

        return "{$this->estudiante->nombre_completo} -> {$destino}";
    }
}
