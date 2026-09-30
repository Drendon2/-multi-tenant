<?php

namespace App\Console\Commands;

use App\Models\Acudiente;
use App\Models\Area;
use App\Models\Asistencia;
use App\Models\Clase;
use App\Models\ConfirmacionClase;
use App\Models\CupoPromotoria;
use App\Models\DatosEstudiante;
use App\Models\EncuestaDemografica;
use App\Models\EncuestaSatisfaccion;
use App\Models\Grupo;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Models\User;
use App\Support\InstitucionActual;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Puebla la base con una institucion de mentira, para probar el sistema con
 * volumen.
 *
 * Con dieciseis usuarios no se ve si una pantalla aguanta, si una regla se
 * sostiene o si una cifra dice lo que uno cree. Este comando arma un escenario
 * completo —trescientas personas de los cuatro roles, con sus matriculas, sus
 * clases y su asistencia— y lo arma A PROPOSITO: no son trescientas filas al
 * azar, son los casos que el sistema tiene que saber manejar, cada uno colocado
 * donde se pueda ir a mirar. El comando imprime al final donde esta cada cual.
 *
 * Todo lo que crea queda marcado:
 *
 * - las cuentas, con el usuario `sim.algo`;
 * - el catalogo (departamentos, promotorias, periodos), con el sufijo ` (sim)`.
 *
 * Por eso `--limpiar` puede borrarlo entero sin tocar nada tuyo. La simulacion
 * tampoco altera tu configuracion: usa el periodo que ya este en curso en vez de
 * crear uno y activarlo —eso romperia el «un solo periodo activo»— y se inventa
 * su propio catalogo en vez de meter gente en tus promotorias reales.
 *
 *     php artisan simular              # siembra ~300 usuarios
 *     php artisan simular --limpiar    # borra TODO lo sembrado
 *     php artisan simular --semilla=7  # otro reparto, igual de reproducible
 *
 * Es una herramienta de desarrollo: se niega a correr en produccion salvo que se
 * insista con --forzar.
 */
class Simular extends Command
{
    protected $signature = 'simular
        {--estudiantes=270 : Cuantos estudiantes sembrar. El resto hasta ~300 es personal.}
        {--semilla=2026 : Semilla del azar. La misma semilla da el mismo reparto.}
        {--limpiar : Borra todo lo que sembro este comando y no siembra nada nuevo.}
        {--forzar : Deja correr aunque el entorno sea produccion.}';

    protected $description = 'Siembra (o borra, con --limpiar) una institucion de prueba con ~300 usuarios.';

    /**
     * Las dos marcas que hacen reversible la simulacion. Cambiarlas deja
     * huerfano lo ya sembrado: `--limpiar` busca por ellas.
     */
    private const PREFIJO_USUARIO = 'sim.';

    private const SUFIJO_CATALOGO = ' (sim)';

    private const NOMBRES = [
        'Ana', 'Santiago', 'Valentina', 'Mateo', 'Isabella', 'Samuel', 'Sofía',
        'Emiliano', 'Camila', 'Sebastián', 'Mariana', 'Nicolás', 'Luciana', 'Tomás',
        'Salomé', 'Andrés', 'Antonia', 'Juan', 'Gabriela', 'Felipe', 'Manuela',
        'Daniel', 'Juliana', 'Alejandro', 'Paula', 'Miguel', 'Sara', 'David',
        'Laura', 'Esteban', 'Catalina', 'Ricardo', 'Verónica', 'Óscar', 'Natalia',
    ];

    private const APELLIDOS = [
        'Rendón', 'Gómez', 'Cardona', 'Ospina', 'Zapata', 'Betancur', 'Restrepo',
        'Arango', 'Vélez', 'Quintero', 'Hoyos', 'Marín', 'Agudelo', 'Muñoz',
        'Ramírez', 'Pineda', 'Loaiza', 'Grisales', 'Salazar', 'Torres', 'Bedoya',
    ];

    private const BARRIOS = [
        'Centro', 'La Playa', 'San José', 'El Carmen', 'Vereda La Cristalina',
        'Los Naranjos', 'Villa Nueva', 'El Progreso', 'La Floresta',
    ];

    private const SALONES = ['Salón 1', 'Salón 2', 'Salón 3', 'Aula múltiple', 'Tarima'];

    /**
     * Los horarios, como DATO y no como texto.
     *
     * Antes eran cadenas —«Martes 4:00-6:00 p. m.»— porque `grupos.horario` era
     * un varchar libre. Esa columna se fue el 20/08 y el horario vive ahora en
     * `sesiones_grupo`, con su dia y sus horas. Este comando se quedo atras y
     * llevaba cuatro dias reventando al crear el primer grupo.
     *
     * Cada entrada es [dia (1=lunes), hora de inicio, hora de fin].
     */
    private const HORARIOS = [
        [1, '14:00', '16:00'],
        [2, '16:00', '18:00'],
        [3, '08:00', '10:00'],
        [4, '15:00', '17:00'],
        [5, '10:00', '12:00'],
        [6, '09:00', '11:00'],
    ];

    /** Como se llaman los grupos. El unico de la base es (promotoria, nombre). */
    private const NOMBRES_DE_GRUPO = ['Grupo A', 'Grupo B', 'Grupo C'];

    private int $documento = 900000;

    public function handle(): int
    {
        if (app()->environment('production') && ! $this->option('forzar')) {
            $this->error('Esto es una herramienta de desarrollo y el entorno es producción.');
            $this->line('Si de verdad es lo que quieres, vuelve a lanzarlo con --forzar.');

            return self::FAILURE;
        }

        // Semilla fija: el mismo numero da exactamente el mismo reparto, que es
        // lo que permite volver a mirar un caso raro despues de arreglarlo.
        mt_srand((int) $this->option('semilla'));

        if ($this->option('limpiar')) {
            return $this->limpiar();
        }

        $periodo = Periodo::enCurso();

        if ($periodo === null) {
            $this->error('No hay un periodo en curso. Marca uno en Gestión → Iniciar / finalizar matrículas.');
            $this->line('La simulación usa el tuyo a propósito: crear uno y activarlo rompería el');
            $this->line('«un solo periodo activo» y te cambiaría la configuración.');

            return self::FAILURE;
        }

        $inicio = microtime(true);
        $this->sembrar($periodo, (int) $this->option('estudiantes'), $inicio);

        return self::SUCCESS;
    }

    // -----------------------------------------------------------------------
    // Siembra
    // -----------------------------------------------------------------------

    private function sembrar(Periodo $periodo, int $cuantosEstudiantes, float $inicio): void
    {
        $this->info("Sembrando sobre {$periodo->nombre}…");

        // Los periodos pasados SI los crea la simulacion, y no rompen nada
        // porque nacen inactivos: el indice unico solo prohibe dos ACTIVOS. Sin
        // ellos no existen el historial, la renovacion ni las cifras de
        // desercion, que comparan quien siguio y quien no volvio.
        $anteriores = $this->sembrarPeriodosPasados($periodo);

        $personal = $this->sembrarPersonal();
        $promotorias = $this->sembrarCatalogo($personal);
        $grupos = $this->sembrarGrupos($promotorias);
        $estudiantes = $this->sembrarEstudiantes($cuantosEstudiantes);

        $this->line('  · historia de periodos anteriores');
        $this->sembrarHistoria($estudiantes, $promotorias, $anteriores);

        $this->line('  · matrículas del periodo en curso');
        $this->sembrarMatriculas($estudiantes, $promotorias, $grupos, $periodo);

        // Los cupos van DESPUES de matricular, y no es un detalle de orden: el
        // tope lo impone un trigger sobre las altas, asi que fijarlo antes
        // habria hecho fallar la siembra al llegar al limite. Puestos despues,
        // dejan justo el escenario interesante —una promotoria llena y otra por
        // llenarse—, que es legitimo: bajar el cupo no retira a nadie.
        $this->sembrarCupos($promotorias, $periodo);

        $this->line('  · clases y asistencia');
        $this->sembrarClases($grupos, $periodo);

        $this->resumen($periodo, $anteriores, $personal, $promotorias, $grupos, $inicio);
    }

    /**
     * Dos periodos ya cerrados, para que haya trayectoria de verdad.
     *
     * @return list<Periodo>
     */
    private function sembrarPeriodosPasados(Periodo $actual): array
    {
        $periodos = [];

        foreach ([1, 2] as $atras) {
            $inicio = $actual->fecha_inicio->copy()->subMonths(6 * $atras);

            $periodos[] = Periodo::create([
                'nombre' => $inicio->year.'-'.($inicio->month <= 6 ? 1 : 2).self::SUFIJO_CATALOGO,
                'fecha_inicio' => $inicio,
                'fecha_fin' => $inicio->copy()->addMonths(5),
                'activo' => false,
                'matriculas_abiertas' => false,
            ]);
        }

        // Del mas reciente al mas antiguo: la renovacion mira el ultimo cursado.
        return $periodos;
    }

    /**
     * Los roles de arriba, cada uno con un caso que probar.
     *
     * Incluye a proposito un director que ADEMAS dicta: es el caso que obliga a
     * que «quien puede pasar lista» salga del vinculo con la promotoria y no del
     * rol.
     *
     * @return array<string, mixed>
     */
    private function sembrarPersonal(): array
    {
        $this->line('  · personal');

        $profesores = [];

        for ($i = 1; $i <= 22; $i++) {
            $profesores[] = $this->crearPerfil(
                sprintf('prof%03d', $i),
                'profesor',
                $this->nacimiento($this->entre(26, 60))
            );
        }

        $directores = [];

        foreach ([1, 2, 3] as $i) {
            $directores[] = $this->crearPerfil("dir{$i}", 'director', $this->nacimiento($this->entre(35, 60)));
        }

        $admins = [
            $this->crearPerfil('admin1', 'administrador', $this->nacimiento($this->entre(35, 60))),
            $this->crearPerfil('admin2', 'administrador', $this->nacimiento($this->entre(35, 60))),
        ];

        // Cuentas creadas por autorregistro que nadie ha aprobado todavia: el
        // estado en que de verdad llega un profesor nuevo.
        $sinRol = [];

        foreach ([1, 2, 3] as $i) {
            $sinRol[] = $this->crearPerfil("pend{$i}", '', $this->nacimiento($this->entre(25, 55)));
        }

        // Una cuenta desactivada, para que la lista de usuarios tenga los dos
        // estados.
        $inactivo = $this->crearPerfil('inactivo', 'profesor', $this->nacimiento(40));
        $inactivo->user->update(['activo' => false]);

        return [
            'profesores' => $profesores,
            'directores' => $directores,
            'admins' => $admins,
            'sin_rol' => $sinRol,
            'inactivo' => $inactivo,
        ];
    }

    /**
     * El catalogo de mentira.
     *
     * La ultima promotoria queda SIN profesor a proposito: es el caso en que
     * nadie puede registrar clases, y la pantalla tiene que saber decirlo.
     *
     * @param  array<string, mixed>  $personal
     * @return list<Promotoria>
     */
    private function sembrarCatalogo(array $personal): array
    {
        $this->line('  · catálogo');

        $disciplinas = [
            'Música' => ['Violín', 'Guitarra', 'Piano', 'Canto', 'Percusión', 'Flauta'],
            'Danza' => ['Ballet', 'Salsa', 'Danza folclórica', 'Danza contemporánea'],
            'Teatro' => ['Actuación', 'Improvisación', 'Títeres'],
            'Artes plásticas' => ['Dibujo', 'Pintura', 'Cerámica'],
        ];

        // Un director que ADEMAS dicta. Es el caso real que obligo a que pasar
        // lista dependa del vinculo y no del rol.
        $quienesDictan = [...$personal['profesores'], $personal['directores'][0]];

        $promotorias = [];
        $n = 0;

        foreach ($disciplinas as $area => $nombres) {
            $departamento = Area::create(['nombre' => $area.self::SUFIJO_CATALOGO]);

            foreach ($nombres as $nombre) {
                $ultima = $n === count($disciplinas, COUNT_RECURSIVE) - count($disciplinas) - 1;

                $promotorias[] = Promotoria::create([
                    'nombre' => $nombre.self::SUFIJO_CATALOGO,
                    'area_id' => $departamento->id,
                    'profesor_id' => $ultima ? null : $quienesDictan[$n % count($quienesDictan)]->id,
                ]);

                $n++;
            }
        }

        return $promotorias;
    }

    /**
     * Grupos por promotoria, entre uno y tres.
     *
     * @param  list<Promotoria>  $promotorias
     * @return array<int, list<Grupo>>
     */
    private function sembrarGrupos(array $promotorias): array
    {
        $this->line('  · grupos');

        $niveles = array_keys(Grupo::NIVELES);
        $porPromotoria = [];

        foreach ($promotorias as $indice => $promotoria) {
            // Una promotoria se queda SIN grupos: es donde se ve el mensaje de
            // «crea un grupo primero» al intentar repartir.
            $cuantos = $indice % 7 === 0 ? 0 : $this->entre(1, 3);

            for ($n = 0; $n < $cuantos; $n++) {
                $grupo = Grupo::create([
                    'promotoria_id' => $promotoria->id,
                    'nombre' => self::NOMBRES_DE_GRUPO[$n],
                    'nivel' => $niveles[$n],
                    'salon' => self::SALONES[$this->entre(0, count(self::SALONES) - 1)],
                    'cupo_maximo' => $this->entre(8, 20),
                ]);

                // El horario va en filas aparte desde el 20/08. Una sola sesion
                // por grupo: basta para que el mapa de calor tenga patron, que
                // es para lo que este comando siembra clases.
                [$dia, $inicio, $fin] = self::HORARIOS[$this->entre(0, count(self::HORARIOS) - 1)];
                $grupo->sesiones()->create(['dia' => $dia, 'hora_inicio' => $inicio, 'hora_fin' => $fin]);

                $porPromotoria[$promotoria->id][] = $grupo;
            }
        }

        return $porPromotoria;
    }

    /**
     * Estudiantes con la mezcla de casos que el sistema tiene que aguantar.
     *
     * Un tercio son menores y llevan acudiente CON TELEFONO, que es lo que exige
     * la regla. La encuesta demografica queda en tres estados —completa, a medias
     * y sin empezar— porque las tres existen en una base real y las cifras tienen
     * que saber contarlas.
     *
     * @return list<Perfil>
     */
    private function sembrarEstudiantes(int $cuantos): array
    {
        $this->line("  · {$cuantos} estudiantes");

        $estudiantes = [];
        $barra = $this->output->createProgressBar($cuantos);

        for ($i = 1; $i <= $cuantos; $i++) {
            $menor = $i % 3 === 0;
            $edad = $menor ? $this->entre(7, 16) : $this->entre(18, 67);
            $perfil = $this->crearPerfil(sprintf('est%03d', $i), 'estudiante', $this->nacimiento($edad));

            $acudiente = null;

            if ($menor) {
                $acudiente = Acudiente::create([
                    'nombre' => $this->nombre().self::SUFIJO_CATALOGO,
                    'telefono' => $this->telefono(),
                ]);
            }

            DatosEstudiante::create([
                'perfil_id' => $perfil->id,
                'documento_identidad' => (string) ++$this->documento,
                'acudiente_id' => $acudiente?->id,
            ]);

            $suerte = $this->suerte();

            if ($suerte < 0.7) {
                $this->encuesta($perfil, completa: true);
            } elseif ($suerte < 0.85) {
                $this->encuesta($perfil, completa: false);
            }
            // El resto se queda sin encuesta: aparece como pendiente.

            $estudiantes[] = $perfil;
            $barra->advance();
        }

        $barra->finish();
        $this->newLine();

        return $estudiantes;
    }

    private function encuesta(Perfil $perfil, bool $completa): void
    {
        EncuestaDemografica::create([
            'perfil_id' => $perfil->id,
            'genero' => $this->deEntre(array_keys(EncuestaDemografica::GENEROS)),
            'barrio' => $this->deEntre(self::BARRIOS),
            'estrato' => $this->entre(1, 6),
            // A medias = con los campos que quedaron vacios al pasar a listas
            // cerradas. Es un estado real de la base, no un caso inventado.
            'nivel_educativo' => $completa ? $this->deEntre(array_keys(EncuestaDemografica::NIVELES_EDUCATIVOS)) : '',
            'ocupacion' => $completa ? $this->deEntre(array_keys(EncuestaDemografica::OCUPACIONES)) : '',
            'zona' => $this->deEntre(['urbana', 'rural', 'centro_poblado', '']),
            'afiliacion_salud' => $this->deEntre(['contributivo', 'subsidiado', '']),
            'grupo_etnico' => $this->deEntre(['ninguno', 'indigena', 'afro', '']),
            'discapacidad' => $this->deEntre(['ninguna', 'ninguna', 'visual', '']),
            'victima_conflicto_armado' => $this->deEntre(['no', 'si', 'ns', '']),
            'autoriza_tratamiento_datos' => true,
            'fecha_autorizacion' => now(),
        ]);
    }

    /**
     * Los cuatro estados de una matricula, con y sin grupo asignado.
     *
     * @param  list<Perfil>  $estudiantes
     * @param  list<Promotoria>  $promotorias
     * @param  array<int, list<Grupo>>  $grupos
     */
    private function sembrarMatriculas(array $estudiantes, array $promotorias, array $grupos, Periodo $periodo): void
    {
        $limite = Matricula::limitePromotorias();
        $barra = $this->output->createProgressBar(count($estudiantes));

        foreach ($estudiantes as $indice => $estudiante) {
            $cuantas = $this->suerte() < 0.35 ? min(2, $limite) : 1;
            $elegidas = $this->variasDeEntre($promotorias, $cuantas);

            foreach ($elegidas as $n => $promotoria) {
                $estado = $this->estadoDeMatricula($indice + $n);

                $matricula = new Matricula([
                    'estudiante_id' => $estudiante->id,
                    'promotoria_id' => $promotoria->id,
                    'periodo_id' => $periodo->id,
                    'estado' => $estado,
                ]);

                // Un grupo solo si esta inscrito de verdad y la promotoria tiene
                // alguno: repartir es lo que hace quien dicta despues de
                // confirmar.
                $suyos = $grupos[$promotoria->id] ?? [];
                $suGrupo = in_array($estado, Matricula::ESTADOS_INSCRITO, true)
                    && $suyos !== [] && $this->suerte() < 0.8
                        ? $this->deEntre($suyos)->id
                        : null;

                try {
                    $matricula->save();

                    // Repartir va DESPUES de guardar: la puente cuelga del id de
                    // la matricula, que al crear todavia no existe.
                    if ($suGrupo !== null) {
                        $matricula->repartirEn([$suGrupo]);
                    }
                } catch (\Throwable) {
                    // El cupo del grupo o la ranura pueden rechazar una: es el
                    // sistema haciendo su trabajo, no un fallo de la siembra.
                    continue;
                }

                // La fecha la pone un hook al crear; se atrasa a mano para que
                // las clases de hace tres semanas no sean anteriores a la
                // matricula de sus propios estudiantes.
                Matricula::where('id', $matricula->id)->update([
                    'fecha' => now()->subDays($this->entre(25, 60)),
                ]);
            }

            $barra->advance();
        }

        $barra->finish();
        $this->newLine();
    }

    /**
     * Reparto fijo por posicion, no al azar: garantiza que los cuatro estados
     * existan aunque la semilla cambie.
     */
    private function estadoDeMatricula(int $indice): string
    {
        return match (true) {
            $indice % 11 === 0 => Matricula::PENDIENTE,
            $indice % 17 === 0 => Matricula::CANCELACION_SOLICITADA,
            $indice % 13 === 0 => Matricula::RETIRADA,
            default => Matricula::ACTIVA,
        };
    }

    /**
     * Matriculas de los periodos pasados, que es lo que hace existir tres cosas:
     * el historial del estudiante, la renovacion —que busca matriculas ACTIVAS
     * de un periodo previo— y las cifras de desercion de Estadisticas.
     *
     * @param  list<Perfil>  $estudiantes
     * @param  list<Promotoria>  $promotorias
     * @param  list<Periodo>  $anteriores
     */
    private function sembrarHistoria(array $estudiantes, array $promotorias, array $anteriores): void
    {
        foreach ($anteriores as $vuelta => $anterior) {
            // Menos gente cuanto mas atras: una institucion crece.
            $cuantos = (int) (count($estudiantes) / (2 + $vuelta));

            foreach (array_slice($estudiantes, 0, $cuantos) as $estudiante) {
                $promotoria = $this->deEntre($promotorias);
                $activa = $this->suerte() < 0.75;

                $matricula = new Matricula([
                    'estudiante_id' => $estudiante->id,
                    'promotoria_id' => $promotoria->id,
                    'periodo_id' => $anterior->id,
                    'estado' => $activa ? Matricula::ACTIVA : Matricula::RETIRADA,
                ]);

                try {
                    $matricula->save();
                } catch (\Throwable) {
                    continue;
                }

                Matricula::where('id', $matricula->id)->update([
                    'fecha' => $anterior->fecha_inicio->copy()->addDays($this->entre(1, 20)),
                ]);

                // La mitad de quienes cursaron dejaron su valoracion. Va atada a
                // la promotoria, que es lo que permite saber de quien se habla.
                if ($activa && $this->suerte() < 0.5) {
                    EncuestaSatisfaccion::create([
                        'perfil_id' => $estudiante->id,
                        'promotoria_id' => $promotoria->id,
                        'periodo_id' => $anterior->id,
                        'satisfaccion_general' => $this->entre(2, 5),
                        'calificacion_profesor' => $this->entre(2, 5),
                        'horario_funciono' => $this->suerte() < 0.8,
                        'recomendaria' => $this->suerte() < 0.85,
                        'comentario' => $this->deEntre([
                            '', '', '',
                            'Muy buena experiencia.',
                            'El salón cambiaba mucho.',
                            'Ojalá abran nivel intermedio.',
                            'Me quedaba lejos el horario.',
                        ]),
                    ]);
                }
            }
        }
    }

    /**
     * @param  list<Promotoria>  $promotorias
     */
    private function sembrarCupos(array $promotorias, Periodo $periodo): void
    {
        foreach ($promotorias as $indice => $promotoria) {
            if ($indice % 3 === 0) {
                continue; // Sin tope: es el estado por defecto.
            }

            $ocupados = $promotoria->ocupadosEn($periodo);
            $holgura = $indice % 3 === 1 ? 0 : $this->entre(2, 6);

            CupoPromotoria::create([
                'promotoria_id' => $promotoria->id,
                'periodo_id' => $periodo->id,
                'cupo_maximo' => $ocupados + $holgura,
            ]);
        }
    }

    /**
     * Un escenario de verificacion distinto por grupo, no ruido repartido.
     *
     * Los cinco casos que el sistema distingue quedan sembrados a proposito:
     *
     * 0. al dia: varias clases, todas verificadas;
     * 1. con faltas y excusas, verificadas;
     * 2. vencida SIN verificar (el plazo de 48 h se acabo con confirmaciones de
     *    menos);
     * 3. recien dictada, confirmaciones a medias y el plazo todavia abierto;
     * 4. sin ninguna clase registrada.
     *
     * Las confirmaciones se escriben directo, saltandose la ventana de 48 horas
     * que aplica el controlador: es la unica forma de fabricar una clase antigua
     * ya verificada, que es justo lo que hay que poder mirar.
     *
     * @param  array<int, list<Grupo>>  $grupos
     */
    private function sembrarClases(array $grupos, Periodo $periodo): void
    {
        $planos = [];

        foreach ($grupos as $lista) {
            foreach ($lista as $grupo) {
                $planos[] = $grupo;
            }
        }

        $escenarios = ['al_dia', 'con_faltas', 'vencida', 'abierta', 'sin_clases'];
        $barra = $this->output->createProgressBar(count($planos));

        foreach ($planos as $indice => $grupo) {
            $escenario = $escenarios[$indice % count($escenarios)];

            if ($escenario === 'sin_clases') {
                $barra->advance();

                continue;
            }

            $inscritos = $grupo->matriculas()
                ->where('matriculas.periodo_id', $periodo->id)
                ->whereIn('matriculas.estado', Matricula::ESTADOS_INSCRITO)
                ->get();

            if ($inscritos->isEmpty()) {
                $barra->advance();

                continue;
            }

            // Las sesiones caen en el DIA que dice el horario del grupo, no a
            // una cantidad redonda de horas hacia atras. No es adorno: el mapa
            // de calor de una ficha se lee por filas —cada fila es un dia de la
            // semana— y con las clases repartidas al azar no ensena el patron
            // semanal, que es justo para lo que existe.
            //
            // Ninguna se va mas alla de lo que se atrasan las matriculas: una
            // clase anterior a la matricula de sus propios estudiantes no la
            // puede confirmar nadie.
            //
            // La excepcion es 'abierta': esa tiene que caer dentro del plazo de
            // 48 horas para que se pueda ver una confirmacion todavia viva, y
            // eso manda sobre el dia de la semana.
            $cuando = match ($escenario) {
                'abierta' => [now()->subHours(2)],
                'vencida' => [$this->sesion($grupo, 1)],
                default => [
                    $this->sesion($grupo, 3),
                    $this->sesion($grupo, 2),
                    $this->sesion($grupo, 1),
                ],
            };

            foreach ($cuando as $fecha) {
                $this->unaClase($grupo, $periodo, $inscritos, $fecha, $escenario);
            }

            $barra->advance();
        }

        $barra->finish();
        $this->newLine();
    }

    /**
     * La sesion de hace `$semanas` semanas, en el dia que dice el horario.
     *
     * El dia y la hora salen de la SESION del grupo, que es donde viven desde
     * el 20/08. Antes se leian del texto de `grupos.horario` buscando «martes»
     * dentro de la cadena; esa columna ya no existe y adivinar dejo de tener
     * sentido cuando el horario paso a ser dato.
     */
    private function sesion(Grupo $grupo, int $semanas): Carbon
    {
        $sesion = $grupo->sesiones->first();

        // Un grupo sin sesiones cae en miercoles a media tarde, que es cuando de
        // verdad se dan estas clases. Mejor un dia fijo que uno al azar: al azar
        // el mapa de calor deja de tener patron, que es justo lo que se siembra.
        $dia = $sesion?->dia ?? Carbon::WEDNESDAY;
        $hora = $sesion ? (int) substr((string) $sesion->hora_inicio, 0, 2) : 16;

        return Carbon::today()
            ->subWeeks($semanas)
            ->next($dia)
            ->setTime($hora, 0);
    }

    /**
     * @param  Collection<int, Matricula>  $inscritos
     */
    private function unaClase(Grupo $grupo, Periodo $periodo, $inscritos, Carbon $fecha, string $escenario): void
    {
        $clase = Clase::abrir($grupo, $periodo, $grupo->promotoria->profesor);

        Clase::where('id', $clase->id)->update(['fecha_hora' => $fecha]);
        $clase->refresh();

        foreach ($inscritos as $matricula) {
            // En la clase recien dictada se deja gente sin marcar: es valido y
            // es el estado en el que de verdad se encuentra una lista a medias.
            if ($escenario === 'abierta' && $this->suerte() < 0.3) {
                continue;
            }

            $estado = $escenario === 'con_faltas'
                ? $this->conPesos(['asistio' => 70, 'falto' => 20, 'excusa' => 10])
                : $this->conPesos(['asistio' => 92, 'falto' => 8]);

            Asistencia::create([
                'clase_id' => $clase->id,
                'matricula_id' => $matricula->id,
                'estado' => $estado,
            ]);
        }

        // Una menos de las que pide deja los dos escenarios incompletos; lo que
        // los separa es la FECHA, no el conteo: la vieja ya no puede cambiar y la
        // de hace dos horas si.
        $confirman = in_array($escenario, ['vencida', 'abierta'], true)
            ? max(0, $clase->confirmaciones_requeridas - 1)
            : $clase->confirmaciones_requeridas;

        foreach ($inscritos->take($confirman) as $matricula) {
            ConfirmacionClase::create([
                'clase_id' => $clase->id,
                'matricula_id' => $matricula->id,
            ]);
        }
    }

    // -----------------------------------------------------------------------
    // Limpieza
    // -----------------------------------------------------------------------

    /**
     * Borra lo sembrado, y en un orden que las claves foraneas admiten.
     *
     * No es alfabetico ni arbitrario: cada linea existe porque la de abajo esta
     * bloqueada hasta que esta corra. Las encuestas de satisfaccion van primero
     * de todo porque apuntan a promotoria y periodo con RESTRICT; los periodos,
     * al final, porque los sostienen matriculas, clases y encuestas.
     */
    private function limpiar(): int
    {
        $this->info('Borrando lo sembrado…');

        $perfiles = Perfil::whereHas('user', fn ($q) => $q->where('username', 'like', self::PREFIJO_USUARIO.'%'))
            ->pluck('id');

        $promotorias = Promotoria::where('nombre', 'like', '%'.self::SUFIJO_CATALOGO)->pluck('id');
        $grupos = Grupo::whereIn('promotoria_id', $promotorias)->pluck('id');

        DB::transaction(function () use ($perfiles, $promotorias, $grupos) {
            EncuestaSatisfaccion::whereIn('perfil_id', $perfiles)->delete();
            ConfirmacionClase::whereIn('clase_id', Clase::whereIn('grupo_id', $grupos)->select('id'))->delete();
            Asistencia::whereIn('clase_id', Clase::whereIn('grupo_id', $grupos)->select('id'))->delete();
            Clase::whereIn('grupo_id', $grupos)->delete();
            Matricula::whereIn('estudiante_id', $perfiles)->delete();
            Matricula::whereIn('promotoria_id', $promotorias)->delete();
            Grupo::whereIn('id', $grupos)->delete();
            CupoPromotoria::whereIn('promotoria_id', $promotorias)->delete();
            Promotoria::whereIn('id', $promotorias)->delete();
            Area::where('nombre', 'like', '%'.self::SUFIJO_CATALOGO)->delete();

            // Al borrar la cuenta se van en cascada el perfil, sus datos de
            // estudiante y su encuesta demografica.
            // Por `tabla()`: las cuentas no llevan el filtro de institucion
            // (ver `User`) y sin el se borrarian las simuladas de todas.
            InstitucionActual::tabla('users')->where('username', 'like', self::PREFIJO_USUARIO.'%')->delete();

            Acudiente::where('nombre', 'like', '%'.self::SUFIJO_CATALOGO)->delete();
            Periodo::where('nombre', 'like', '%'.self::SUFIJO_CATALOGO)->delete();
        });

        $this->info('Listo: la base queda como estaba antes de simular.');

        return self::SUCCESS;
    }

    // -----------------------------------------------------------------------
    // Utilidades
    // -----------------------------------------------------------------------

    private function crearPerfil(string $sufijo, string $rol, Carbon $nacimiento): Perfil
    {
        $user = User::create([
            'username' => self::PREFIJO_USUARIO.$sufijo,
            'password' => 'simular',
            'activo' => true,
        ]);

        $perfil = Perfil::create([
            'user_id' => $user->id,
            'rol' => $rol,
            'nombre_completo' => $this->nombre(),
            'fecha_nacimiento' => $nacimiento,
            'telefono' => $this->telefono(),
        ]);

        $perfil->setRelation('user', $user);

        return $perfil;
    }

    private function nombre(): string
    {
        return $this->deEntre(self::NOMBRES).' '.$this->deEntre(self::APELLIDOS);
    }

    private function telefono(): string
    {
        return '3'.$this->entre(100000000, 199999999);
    }

    private function nacimiento(int $edad): Carbon
    {
        return Carbon::today()->subYears($edad)->subDays($this->entre(0, 364));
    }

    private function entre(int $desde, int $hasta): int
    {
        return mt_rand($desde, $hasta);
    }

    private function suerte(): float
    {
        return mt_rand() / mt_getrandmax();
    }

    /** @param array<int, mixed> $opciones */
    private function deEntre(array $opciones): mixed
    {
        $valores = array_values($opciones);

        return $valores[$this->entre(0, count($valores) - 1)];
    }

    /**
     * @param  array<int, mixed>  $opciones
     * @return list<mixed>
     */
    private function variasDeEntre(array $opciones, int $cuantas): array
    {
        $indices = (array) array_rand($opciones, min($cuantas, count($opciones)));

        return array_map(fn ($i) => $opciones[$i], $indices);
    }

    /** @param array<string, int> $pesos */
    private function conPesos(array $pesos): string
    {
        $tirada = $this->entre(1, array_sum($pesos));

        foreach ($pesos as $valor => $peso) {
            $tirada -= $peso;

            if ($tirada <= 0) {
                return $valor;
            }
        }

        return array_key_first($pesos);
    }

    /**
     * @param  list<Periodo>  $anteriores
     * @param  array<string, mixed>  $personal
     * @param  list<Promotoria>  $promotorias
     * @param  array<int, list<Grupo>>  $grupos
     */
    private function resumen(
        Periodo $periodo,
        array $anteriores,
        array $personal,
        array $promotorias,
        array $grupos,
        float $inicio,
    ): void {
        $segundos = round(microtime(true) - $inicio, 1);

        $this->newLine();
        $this->info("Sembrado en {$segundos} s. Entra con cualquier cuenta y la contraseña «simular».");
        $this->newLine();

        $this->table(['Qué', 'Cuánto'], [
            ['Usuarios', InstitucionActual::tabla('users')->where('username', 'like', self::PREFIJO_USUARIO.'%')->count()],
            ['  · estudiantes', Perfil::whereHas('user', fn ($q) => $q->where('username', 'like', self::PREFIJO_USUARIO.'est%'))->count()],
            ['  · profesores', count($personal['profesores'])],
            ['  · directores', count($personal['directores'])],
            ['  · administradores', count($personal['admins'])],
            ['  · sin rol asignado', count($personal['sin_rol'])],
            ['Promotorías', count($promotorias)],
            ['Grupos', array_sum(array_map('count', $grupos))],
            ['Matrículas', Matricula::count()],
            ['  · activas', Matricula::where('estado', Matricula::ACTIVA)->count()],
            ['  · pendientes', Matricula::where('estado', Matricula::PENDIENTE)->count()],
            ['  · cancelación en trámite', Matricula::where('estado', Matricula::CANCELACION_SOLICITADA)->count()],
            ['  · retiradas', Matricula::where('estado', Matricula::RETIRADA)->count()],
            ['Clases', Clase::count()],
            ['Marcas de asistencia', Asistencia::count()],
            ['Encuestas de satisfacción', EncuestaSatisfaccion::count()],
        ]);

        $this->newLine();
        $this->line('<comment>Dónde mirar:</comment>');
        $this->line('  Panel .............. entra como <info>sim.prof001</info>');
        $this->line('  Gestión ............ entra como <info>sim.dir1</info>');
        $this->line('  Estadísticas ....... entra como <info>sim.admin1</info>');
        $this->line('  Cancelaciones ...... hay '.Matricula::where('estado', Matricula::CANCELACION_SOLICITADA)->count().' por resolver');
        $this->line('  Renovación ......... entra como <info>sim.est001</info> (cursó '.$anteriores[0]->nombre.')');
        $this->line('  Cuenta sin rol ..... entra como <info>sim.pend1</info>');
        $this->line('  Cuenta desactivada . <info>sim.inactivo</info> (no debe poder entrar)');
        $this->newLine();
        $this->line('Para dejarlo todo como estaba: <info>php artisan simular --limpiar</info>');
    }
}
