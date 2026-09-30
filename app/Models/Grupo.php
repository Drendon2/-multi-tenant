<?php

namespace App\Models;

use App\Models\Concerns\DeLaInstitucion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un horario concreto de una promotoria, creado por quien la dicta.
 *
 * Se crea segun la disponibilidad de quien ensena, y ahi se reparte a los
 * estudiantes que YA se matricularon en la promotoria — el estudiante nunca
 * elige horario.
 *
 * Lo que identifica a un grupo es su NOMBRE, unico dentro de la promotoria, y
 * no su nivel: una promotoria puede tener varios grupos de Basico —es el caso
 * corriente cuando atiende a mucha gente— y ahi «Basico» no dice cual. El nivel
 * sigue estando porque agrupa por dificultad, pero paso a ser un dato del grupo
 * y no su identidad.
 */
class Grupo extends Model
{
    use DeLaInstitucion;

    public const NIVELES = [
        'basico' => 'Básico',
        'intermedio' => 'Intermedio',
        'avanzado' => 'Avanzado',
    ];

    protected $table = 'grupos';

    protected $fillable = [
        'promotoria_id',
        'nombre',
        'nivel',
        'salon',
        'cupo_maximo',
    ];

    protected function casts(): array
    {
        return [
            'cupo_maximo' => 'integer',
        ];
    }

    /**
     * Anotada por lo mismo que la de `Clase`: sin el tipo, el analizador ve un
     * `Model` generico y toda la cadena `$clase->grupo->promotoria` acaba
     * llegando como generica a `Permisos`, que espera una `Promotoria`.
     *
     * @return BelongsTo<Promotoria, $this>
     */
    public function promotoria(): BelongsTo
    {
        return $this->belongsTo(Promotoria::class);
    }

    /**
     * Quien esta repartido en este grupo.
     *
     * PASA POR `asignaciones_grupo` DESDE EL 10/09/2026, no por
     * `matriculas.grupo_id`. Es lo que permite que una misma matricula este en
     * dos grupos de la misma promotoria —el lunes y el miercoles—, que con una
     * columna no se puede ni escribir.
     *
     * Mientras la columna siga existiendo la mantiene al dia la escritura doble
     * de `Matricula`, asi que las dos dicen lo mismo; la columna se borra en el
     * ultimo paso.
     *
     * OJO AL FILTRAR POR ELLA: ahora hay un JOIN, y `asignaciones_grupo` tiene
     * su propio `id`. Un `where('id', ...)` a secas es ambiguo y revienta la
     * consulta; hay que escribir `matriculas.id`. Es la unica cosa que cambia
     * para quien la use, y no avisa hasta que corre.
     *
     * @return BelongsToMany<Matricula, $this, AsignacionGrupo>
     */
    public function matriculas(): BelongsToMany
    {
        return $this->belongsToMany(Matricula::class, 'asignaciones_grupo')
            ->using(AsignacionGrupo::class)
            ->withTimestamps();
    }

    public function clases(): HasMany
    {
        return $this->hasMany(Clase::class);
    }

    /**
     * Los encuentros semanales, en orden de semana.
     *
     * Ordenadas aqui y no en cada consulta: un horario que salga «jueves,
     * lunes» no esta mal por poco, esta mal.
     */
    public function sesiones(): HasMany
    {
        return $this->hasMany(SesionGrupo::class, 'grupo_id')
            ->orderBy('dia')
            ->orderBy('hora_inicio');
    }

    public function getNivelDisplayAttribute(): string
    {
        return self::NIVELES[$this->nivel] ?? $this->nivel;
    }

    /**
     * El horario como texto, armado desde las sesiones.
     *
     * Se DERIVA y no se guarda: cuando existian las dos cosas —la columna de
     * texto y las horas— acababan discrepando al primer descuido, y entonces no
     * hay forma de saber cual de las dos miente.
     *
     * Los dias que comparten hora se juntan («Martes y jueves 4:00 p. m. a 6:00
     * p. m.») porque es como lo dice la gente, y repetir la hora en cada dia
     * hace una linea que nadie lee entera.
     */
    public function getHorarioAttribute(): string
    {
        $sesiones = $this->sesiones;

        if ($sesiones->isEmpty()) {
            return '';
        }

        return $sesiones
            ->groupBy(fn (SesionGrupo $s) => $s->hora_inicio.'-'.$s->hora_fin)
            ->map(function ($delMismoHorario) {
                $dias = $delMismoHorario->map(fn (SesionGrupo $s) => $s->dia_display)->all();
                $primera = $delMismoHorario->first();

                return $this->enumerar($dias)
                    ." {$primera->inicio_display} a {$primera->fin_display}";
            })
            ->implode(' · ');
    }

    /** «Lunes», «Lunes y jueves», «Lunes, martes y jueves». */
    private function enumerar(array $dias): string
    {
        if (count($dias) === 1) {
            return $dias[0];
        }

        $ultimo = array_pop($dias);

        // Los dias que no van primero se escriben en minuscula: «Martes y
        // jueves», no «Martes y Jueves».
        $previos = implode(', ', $dias);

        return $previos.' y '.mb_strtolower($ultimo);
    }

    /**
     * El grupo sin el salon: nombre, nivel y horario.
     *
     * Lo piden las pantallas donde el salon no aporta —los desplegables de
     * filtro y de reparto, y las tablas de una fila por matricula—, que hasta
     * ahora escribian «nombre · horario» a mano. Escrito a mano, un grupo al
     * que todavia no le han puesto sesiones sale «A · Básico ·», con el
     * separador colgando, porque el horario se DERIVA de las sesiones y sin
     * ellas es cadena vacia. Aqui las partes vacias se caen solas.
     */
    public function getRotuloBreveAttribute(): string
    {
        return implode(' · ', array_filter([$this->nombre_con_nivel, $this->horario]));
    }

    /**
     * Como se lee el grupo de un vistazo: nombre, nivel, horario y salon.
     *
     * Existe para que las pantallas no compongan cada una su version: son diez
     * las que pintan un grupo, y cuando el nombre se antepuso al nivel hubo que
     * tocarlas todas. La proxima vez que cambie el orden, se cambia aqui.
     *
     * El salon va al final y solo si lo hay: es lo unico que puede faltar en la
     * practica, y una linea que termine en un separador suelto se ve rota.
     */
    public function getRotuloAttribute(): string
    {
        $partes = [$this->rotulo_breve];

        if ($this->salon !== '' && $this->salon !== null) {
            $partes[] = $this->salon;
        }

        return implode(' · ', array_filter($partes));
    }

    /**
     * Nombre y nivel juntos, salvo cuando el nivel repetiria el nombre.
     *
     * Un grupo puede llamarse «Básico» —es como quedaron los que existian antes
     * de que los grupos tuvieran nombre, y sigue siendo un nombre razonable
     * cuando de ese nivel solo hay uno—. Pintar «Básico · Básico» no informa de
     * nada y se lee como un error del sistema.
     */
    public function getNombreConNivelAttribute(): string
    {
        return $this->nombre === $this->nivel_display
            ? $this->nombre
            : "{$this->nombre} · {$this->nivel_display}";
    }

    /**
     * Cuanta gente ocupa sitio en el grupo en ese periodo.
     *
     * QUE CUENTA Y POR QUE, que es lo unico que hay que entender de aqui:
     *
     * - Las ACTIVAS, obviamente.
     * - Las CANCELACIONES EN TRAMITE tambien. No es contabilidad: quien pidio
     *   cancelar sigue yendo a clase hasta que direccion lo resuelva —por eso
     *   conserva su grupo, y esta escrito en `MisMatriculasController`—, asi
     *   que su silla esta ocupada. Contarla libre es decir que en el salon cabe
     *   alguien que no cabe.
     * - Las PENDIENTES no, aunque tengan grupo asignado. La regla del proyecto
     *   es que mientras nadie confirme la solicitud esa persona no esta en la
     *   clase, y es la misma razon por la que el Panel las lista aparte de los
     *   grupos. `ESTADOS_INSCRITO` es exactamente esa medida, ni mas ni menos.
     *
     * OJO, ES DISTINTO DEL CUPO DE PROMOTORIA: aquel cuenta todo lo que no este
     * retirado, PENDIENTES INCLUIDAS, porque una solicitud sin confirmar ya
     * reserva el sitio en la promotoria para que quien dicta no acabe
     * rechazando una lista de espera entera. Son dos preguntas distintas —un
     * sitio en la lista y una silla en el salon— y por eso las condiciones no
     * coinciden. No las "unifiques".
     *
     * HASTA EL 10/09/2026 ESTO SE CONTABA DE DOS MANERAS. `Matricula::validar()`
     * llevaba su propia consulta y miraba solo las ACTIVAS, mientras la pantalla
     * y este metodo miraban `ESTADOS_INSCRITO`. Probado: un grupo de cupo 1 con
     * su unico sitio ocupado por una cancelacion en tramite se pintaba «1/1,
     * lleno» y aun asi aceptaba a otro — dos filas en un grupo de uno, y
     * `cuposDisponibles()` devolviendo -1. Nadie lo habia visto porque este
     * metodo, que era el que seguia la regla buena, NO LO LLAMABA NADIE.
     */
    public function ocupadosEn(?Periodo $periodo, ?int $excluirMatriculaId = null): int
    {
        if ($periodo === null) {
            return 0;
        }

        return $this->matriculas()
            ->where('matriculas.periodo_id', $periodo->id)
            ->whereIn('matriculas.estado', Matricula::ESTADOS_INSCRITO)
            ->when(
                $excluirMatriculaId !== null,
                fn ($q) => $q->where('matriculas.id', '!=', $excluirMatriculaId)
            )
            ->count();
    }

    /** Sitios libres en el grupo para ese periodo. */
    public function cuposDisponibles(?Periodo $periodo, ?int $excluirMatriculaId = null): int
    {
        if ($periodo === null) {
            return $this->cupo_maximo;
        }

        return $this->cupo_maximo - $this->ocupadosEn($periodo, $excluirMatriculaId);
    }

    /**
     * El nombre CON su promotoria delante, para los mensajes que salen de la
     * pantalla del grupo — «Fulano fue asignado a Guitarra - Martes tarde».
     * Fuera de la promotoria, «Martes tarde» a secas no ubica a nadie.
     */
    public function __toString(): string
    {
        return "{$this->promotoria->nombre} - {$this->nombre}";
    }
}
