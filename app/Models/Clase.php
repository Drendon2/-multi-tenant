<?php

namespace App\Models;

use App\Models\Concerns\DeLaInstitucion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Una sesion de clase concreta, registrada por quien la dicta al darla.
 *
 * No se programa por adelantado ni se deduce del `horario` del grupo: se oprime
 * "Iniciar clase" cuando la clase empieza y lo que queda guardado es la hora
 * REAL en que se oprimio. Esa es toda la diferencia entre el horario (lo que
 * deberia pasar cada semana) y esta tabla (lo que paso).
 *
 * Va atada al grupo y no a la promotoria porque la lista que se pasa es la de un
 * horario concreto: dos grupos de la misma promotoria se ven en dias distintos y
 * cada uno tiene su propia asistencia.
 *
 * Que la clase este registrada no significa todavia que se haya dado: eso lo dan
 * por cierto los propios estudiantes (ver ConfirmacionClase).
 */
class Clase extends Model
{
    use DeLaInstitucion;

    /**
     * Cuantos estudiantes tienen que dar fe de una clase para tenerla por
     * dictada. Tres es el numero normal; en un grupo de uno o dos no se puede
     * pedir mas gente de la que hay, asi que basta uno.
     */
    public const CONFIRMACIONES_REQUERIDAS = 3;

    public const GRUPO_PEQUENO = 2;

    /**
     * Cuanto tiempo queda abierta la confirmacion despues de la clase.
     *
     * Dos dias cubren al que no llevaba el celular encima y al que solo entra al
     * sistema en la noche, sin llegar a la semana siguiente: lo que se confirma
     * es que una clase concreta se dio, y eso se recuerda con precision el mismo
     * dia, no cuando ya se mezclo con la del martes que viene. Vencido el plazo,
     * lo que haya quedado registrado es definitivo — ni se confirma ni se retira.
     */
    public const VENTANA_CONFIRMACION_HORAS = 48;

    protected $table = 'clases';

    protected $fillable = [
        'grupo_id',
        'periodo_id',
        'fecha_hora',
        'registrada_por_id',
        'confirmaciones_requeridas',
    ];

    protected function casts(): array
    {
        return [
            'fecha_hora' => 'datetime',
            'confirmaciones_requeridas' => 'integer',
        ];
    }

    /** La hora real en que se oprimio el boton. Nunca editable a mano. */
    protected static function booted(): void
    {
        static::creating(function (self $clase) {
            $clase->fecha_hora ??= now();
        });
    }

    /**
     * El tipo va ANOTADO, como en `Matricula`: sin el, el analizador ve un
     * `Model` generico y `$this->grupo->matriculas()` —que es de donde sale
     * ahora la lista de quien esta en la clase— sale como metodo inexistente.
     *
     * @return BelongsTo<Grupo, $this>
     */
    public function grupo(): BelongsTo
    {
        return $this->belongsTo(Grupo::class);
    }

    public function periodo(): BelongsTo
    {
        return $this->belongsTo(Periodo::class);
    }

    public function registradaPor(): BelongsTo
    {
        return $this->belongsTo(Perfil::class, 'registrada_por_id');
    }

    /**
     * Las dos van ANOTADAS por lo mismo que `grupo()`: sin el tipo, el
     * analizador ve un `Model` generico y `->first()->estado` —o `->origen`—
     * sale como propiedad inexistente en quien las recorra.
     *
     * @return HasMany<Asistencia, $this>
     */
    public function asistencias(): HasMany
    {
        return $this->hasMany(Asistencia::class);
    }

    /** @return HasMany<ConfirmacionClase, $this> */
    public function confirmaciones(): HasMany
    {
        return $this->hasMany(ConfirmacionClase::class);
    }

    public function scopeRecientesPrimero(Builder $query): Builder
    {
        return $query->orderByDesc('fecha_hora');
    }

    /**
     * Fuera las REPOSICIONES (03/10/2026): las que reponen una falta que la
     * bandeja de alertas marco. Para preguntar «¿ya hay clase de hoy?» una
     * reposicion no cuenta: quien repone la del martes pasado y luego da la
     * suya acabaria pasando lista de la de hoy en la hoja de la reposicion.
     */
    public function scopeSinReposiciones(Builder $query): Builder
    {
        return $query->whereNotIn(
            $query->qualifyColumn('id'),
            OmisionArchivada::whereNotNull('repuesta_en_id')->select('repuesta_en_id')
        );
    }

    /**
     * Cuantas confirmaciones necesita una clase de un grupo de ese tamano.
     *
     * Tres es el numero normal. Un grupo de uno o dos estudiantes no puede
     * reunirlas nunca, asi que ahi basta con una: el requisito tiene que ser
     * alcanzable o deja de verificar nada.
     *
     * Un grupo VACIO devuelve cero, y eso no significa "ya esta confirmada":
     * significa que no hay nadie que pueda confirmarla (ver `estaConfirmada`,
     * que exige un requisito mayor que cero).
     */
    public static function confirmacionesPara(int $inscritos): int
    {
        if ($inscritos <= 0) {
            return 0;
        }

        return $inscritos <= self::GRUPO_PEQUENO ? 1 : self::CONFIRMACIONES_REQUERIDAS;
    }

    /**
     * Registra la clase que empieza ahora, con su requisito ya fijado.
     *
     * El numero de confirmaciones se CONGELA aqui y no se recalcula despues, a
     * diferencia de la lista de asistencia, que si se resuelve cada vez que se
     * abre. Son dos cosas distintas: la lista tiene que reflejar quien esta hoy
     * en el grupo, mientras que el requisito describe el grupo tal como era el
     * dia de la clase. Si se recalculara, una clase ya confirmada volveria a
     * quedar en falta solo porque despues entro gente nueva.
     */
    public static function abrir(Grupo $grupo, Periodo $periodo, ?Perfil $perfil): self
    {
        // Se le pregunta al GRUPO en vez de filtrar por su columna: desde el
        // 10/09/2026 quien esta repartido en un grupo vive en
        // `asignaciones_grupo`, y ahi una misma matricula puede aparecer en dos
        // grupos de la misma promotoria. `ocupadosEn()` es la unica que cuenta
        // sillas, y ya aplica el mismo filtro de estados que habia aqui.
        $inscritos = $grupo->ocupadosEn($periodo);

        return static::create([
            'grupo_id' => $grupo->id,
            'periodo_id' => $periodo->id,
            'registrada_por_id' => $perfil?->id,
            'confirmaciones_requeridas' => self::confirmacionesPara($inscritos),
        ]);
    }

    /**
     * ¿Ya la dieron por dictada suficientes estudiantes?
     *
     * `$total` evita otra consulta cuando quien llama ya conto las
     * confirmaciones (los listados las traen agregadas de una vez).
     */
    public function estaConfirmada(?int $total = null): bool
    {
        if ($this->confirmaciones_requeridas <= 0) {
            return false;
        }

        $total ??= $this->confirmaciones()->count();

        return $total >= $this->confirmaciones_requeridas;
    }

    /** Hasta cuando se puede confirmar o retirar la confirmacion. */
    public function getLimiteConfirmacionAttribute(): Carbon
    {
        return $this->fecha_hora->copy()->addHours(self::VENTANA_CONFIRMACION_HORAS);
    }

    /**
     * ¿Sigue dentro del plazo?
     *
     * Rige el mismo plazo para confirmar y para retirar la confirmacion: es la
     * misma ventana en la que todo se puede corregir, y pasada ella lo
     * registrado queda como esta. Si retirar siguiera abierto despues, una clase
     * ya verificada podria dejar de estarlo semanas mas tarde.
     */
    public function confirmacionAbierta(?Carbon $ahora = null): bool
    {
        return ($ahora ?? now())->lt($this->limite_confirmacion);
    }

    /**
     * El plazo se acabo y la clase no reunio las confirmaciones que pedia.
     *
     * Es un desenlace, no un estado a medias: ya no puede cambiar. Se separa de
     * "todavia faltan" porque las dos cosas se leen igual en un conteo (1 de 3)
     * y significan lo contrario — una espera respuesta y la otra ya no la va a
     * tener.
     */
    public function verificacionVencida(?int $total = null, ?Carbon $ahora = null): bool
    {
        return ! $this->confirmacionAbierta($ahora) && ! $this->estaConfirmada($total);
    }

    /**
     * Los estudiantes a los que hay que pasar lista en esta clase.
     *
     * Se resuelve al abrir la lista y NO se congela al crear la clase: si a
     * alguien lo movieron de grupo entre una sesion y la siguiente, la lista de
     * hoy tiene que ser la de hoy.
     *
     * Incluye a quien pidio cancelar porque mientras la direccion no resuelva la
     * solicitud el estudiante sigue yendo a clase, y hay que poder marcarlo
     * igual que a los demas.
     *
     * @return Collection<int, Matricula>
     */
    public function matriculasAPasar(): Collection
    {
        // Quien esta en el grupo sale de `asignaciones_grupo` desde el
        // 10/09/2026, no de `matriculas.grupo_id`. Con la columna, a quien va a
        // dos horarios de la misma promotoria solo se le podia pasar lista en
        // uno de los dos.
        //
        // Las columnas van CUALIFICADAS —`matriculas.periodo_id`,
        // `matriculas.estado`— porque ahora hay dos joins y los nombres sueltos
        // se vuelven ambiguos en cuanto coincidan. El `select` ya estaba por lo
        // mismo, por el join con `perfiles`.
        return Matricula::query()
            ->whereIn('matriculas.id', $this->grupo->matriculas()->select('matriculas.id'))
            ->where('matriculas.periodo_id', $this->periodo_id)
            ->whereIn('matriculas.estado', Matricula::ESTADOS_INSCRITO)
            ->with('estudiante')
            ->join('perfiles', 'perfiles.id', '=', 'matriculas.estudiante_id')
            ->orderBy('perfiles.nombre_completo')
            ->select('matriculas.*')
            ->get();
    }

    /**
     * Las clases de este estudiante en el periodo, con su estado de verificacion.
     *
     * Devuelve filas con: clase, matricula, confirmada_por_mi, confirmaciones,
     * requeridas, verificada, abierta, vencida y limite; de la mas reciente a la
     * mas antigua.
     *
     * LA REGLA ES «SE CONFIRMA LO QUE UNO VIO», y se resuelve en este orden:
     *
     * 1. SI CONSTA SU ASISTENCIA A ESA CLASE, entra. Es prueba directa de que
     *    estuvo, la puso quien dicto la clase, y manda sobre todo lo demas.
     * 2. SI NO, entran las clases de su grupo de hoy posteriores a su
     *    matricula: quien acaba de entrar al grupo no estuvo en las de antes.
     *
     * QUIEN CONSTA AUSENTE SALE EN LA LISTA PERO NO CONFIRMA (`consta_ausente`,
     * 11/09/2026). La clase le concierne —hay un hecho registrado sobre el en
     * ella— asi que no desaparece; lo que no puede es dar fe de algo que no
     * vio. Vale para «Falto» y para «Falto con excusa»: avisar es lo contrario
     * de desaparecer, pero tampoco estuvo en el salon.
     *
     * QUE NO HAYA FILA NO ES LO MISMO, y de ahi que el corte mire el ESTADO y
     * no la mera existencia del registro. La ausencia de fila significa que a
     * esa persona no la paso nadie —lo dice `Asistencia`— y eso no afirma que
     * faltara. Cortar tambien ahi parecia lo coherente y se midio antes de
     * decidirlo: en produccion habria dejado 23 clases de 123 sin poder
     * verificarse NUNCA, y 16 de ellas no tienen ni un presente marcado porque
     * nadie paso lista. O sea que castigaria al que no paso lista, no al que
     * falto. Con el corte donde esta, solo 2 clases de 61 verificadas se
     * quedaban cortas.
     *
     * LO QUE ESTA BARRERA CUESTA, para quien venga a moverla: atar la
     * verificacion a la asistencia se la pone en manos de quien pasa lista, que
     * es justo a quien vigila. No le sirve para fabricar una clase —las
     * confirmaciones las pulsa cada quien desde su propia sesion— pero si le da
     * un motivo para marcar presente a quien no fue, porque cada falta que
     * registre le quita un verificador posible. El margen es estrecho: el dia
     * que se midio, 22 clases de 123 tenian tantos presentes como
     * confirmaciones pedian, o sea que tendrian que confirmar todos.
     *
     * EL PUNTO 1 FALTABA HASTA EL 09/09/2026 y costo caro, porque esta lista se
     * deducia solo de la matricula tal como esta HOY. Dos situaciones
     * corrientes la dejaban sin ver una clase a la que si fue:
     *
     * - LA MOVIERON DE GRUPO despues de la clase. Su matricula apunta al grupo
     *   nuevo y las clases del anterior desaparecen de su lista.
     * - LA MATRICULARON EL MISMO DIA, despues de la hora a la que empezo la
     *   clase. `matriculas.fecha` guarda la hora exacta, asi que una clase de
     *   las 9 queda «antes» de una matricula de las 13.
     *
     * En los dos casos el profesor SI la ve —`matriculasAPasar()` no filtra por
     * fecha ni por historia— y le marca asistencia; o sea que el sistema tenia
     * escrito que estuvo y aun asi le escondia la clase. Medido en produccion
     * ese dia: de 578 asistencias de un mes, 34 estaban ocultas a su propio
     * estudiante y 13 seguian dentro del plazo. Lo vigila `MisClasesTest`.
     *
     * Se listan tambien las que ya confirmo, las que alcanzaron el numero
     * requerido y las que se les vencio el plazo, en vez de esconderlas: el
     * estudiante tiene que poder ver que confirmo (y retirarlo mientras este a
     * tiempo), y ocultar las cerradas convertiria la lista en algo que cambia de
     * contenido segun lo que hagan los demas.
     *
     * @return list<array<string, mixed>>
     */
    public static function porConfirmar(Perfil $perfil, ?Periodo $periodo): array
    {
        if ($periodo === null) {
            return [];
        }

        // `with('grupos')` y no una consulta por matricula: son pocas, pero
        // recorrerlas para armar el mapa de abajo cuesta una consulta por fila
        // si no vienen cargadas.
        $matriculas = Matricula::query()
            ->where('estudiante_id', $perfil->id)
            ->where('periodo_id', $periodo->id)
            ->whereIn('estado', Matricula::ESTADOS_INSCRITO)
            ->with('grupos')
            ->get()
            ->keyBy('id');

        if ($matriculas->isEmpty()) {
            return [];
        }

        /*
         * En que grupos esta HOY cada una de sus matriculas, para poder traer
         * las clases de esos grupos.
         *
         * ES UN MAPA grupo => matricula, y NO un `keyBy('grupo_id')` como era
         * hasta el 10/09/2026: desde que una matricula puede estar repartida en
         * VARIOS grupos de la misma promotoria —el lunes y el miercoles— una
         * sola matricula aporta varias entradas. Con el `keyBy` sobre la
         * columna, el segundo horario no existia para esta lista y sus clases
         * no le salian a nadie.
         *
         * Sigue habiendo UNA matricula por grupo, que es lo que hace que el
         * mapa siga valiendo: dos grupos distintos de la misma promotoria
         * cuelgan de la misma matricula, y es la que toca en los dos.
         */
        $porGrupo = [];

        foreach ($matriculas as $matricula) {
            foreach ($matricula->grupos as $grupo) {
                $porGrupo[$grupo->id] = $matricula;
            }
        }

        /*
         * LAS CLASES EN LAS QUE CONSTA QUE ESTUVO, que es prueba directa y
         * manda sobre todo lo demas. Sin esta consulta, esta lista se deducia
         * SOLO de la matricula tal como esta HOY —su grupo actual y su fecha— y
         * la asistencia es un hecho ya registrado del pasado. Cuando las dos
         * cosas no coinciden, la que sabe es la asistencia.
         *
         * Clave por clase y valor la matricula, que es justo lo que hace falta
         * despues: la fila de una clase cuelga de la matricula por la que se
         * asistio, no de la que hoy apunte a ese grupo.
         */
        $asistidas = Asistencia::query()
            ->whereIn('matricula_id', $matriculas->keys())
            ->get(['clase_id', 'matricula_id', 'estado'])
            ->keyBy('clase_id');

        $clases = static::query()
            ->where('periodo_id', $periodo->id)
            ->where(fn (Builder $q) => $q
                ->whereIn('grupo_id', array_keys($porGrupo))
                ->orWhereIn('id', $asistidas->keys()))
            ->with(['grupo.promotoria.area', 'grupo.sesiones'])
            ->withCount('confirmaciones')
            ->orderByDesc('fecha_hora')
            ->get();

        $mias = ConfirmacionClase::query()
            ->whereIn('clase_id', $clases->pluck('id'))
            ->whereIn('matricula_id', $matriculas->keys())
            ->pluck('clase_id')
            ->all();

        // Una sola lectura del reloj para toda la lista: si se leyera por fila,
        // dos clases del mismo momento podrian caer a distinto lado del plazo.
        $ahora = now();

        $filas = [];

        foreach ($clases as $clase) {
            $asistencia = $asistidas->get($clase->id);
            $asistio = $asistencia !== null;

            // La matricula de la fila. Si consta asistencia, la de ESA
            // asistencia: puede ser una que ya no apunte a este grupo, que es
            // exactamente el caso de quien fue movida despues de la clase.
            $matricula = $asistio
                ? $matriculas[$asistencia->matricula_id]
                : ($porGrupo[$clase->grupo_id] ?? null);

            if ($matricula === null) {
                continue;
            }

            // La fecha de la matricula solo decide cuando NO consta que
            // estuviera. Es un sustituto de «se confirma lo que uno vio», y
            // deja de hacer falta en cuanto hay algo mejor que suponer.
            if (! $asistio && $clase->fecha_hora->lt($matricula->fecha)) {
                continue;
            }

            $total = $clase->confirmaciones_count;

            $filas[] = [
                'clase' => $clase,
                'matricula' => $matricula,
                // El estado tal como lo dejo quien paso lista, o null si a esta
                // persona no la paso nadie. La vista distingue las dos faltas
                // porque no se leen igual: «Faltaste» y «Faltaste con excusa».
                'asistencia' => $asistencia?->estado,
                'consta_ausente' => $asistio && $asistencia->estado !== Asistencia::ASISTIO,
                'confirmada_por_mi' => in_array($clase->id, $mias, true),
                'confirmaciones' => $total,
                'requeridas' => $clase->confirmaciones_requeridas,
                'verificada' => $clase->estaConfirmada($total),
                'abierta' => $clase->confirmacionAbierta($ahora),
                'vencida' => $clase->verificacionVencida($total, $ahora),
                'limite' => $clase->limite_confirmacion,
            ];
        }

        return $filas;
    }

    /**
     * De esas filas, cuantas esperan todavia que ESTA persona las confirme.
     *
     * Vive aqui y no en cada controlador porque la cifra se pinta en DOS
     * pantallas —el catalogo, que es donde cae al entrar, y la propia lista— y
     * escrita a mano en las dos se separa sin que nada falle. El dia que se
     * anadio el corte de quien consta ausente, la copia olvidada habria dicho
     * «te falta 1 por confirmar» en una pantalla donde no hay ningun boton que
     * pulsar.
     *
     * @param  list<array<string, mixed>>  $filas
     */
    public static function esperanConfirmacion(array $filas): int
    {
        return count(array_filter(
            $filas,
            fn (array $fila) => $fila['abierta']
                && ! $fila['confirmada_por_mi']
                && ! $fila['consta_ausente']
        ));
    }

    public function __toString(): string
    {
        return "{$this->grupo} — {$this->fecha_hora->format('d/m/Y H:i')}";
    }
}
