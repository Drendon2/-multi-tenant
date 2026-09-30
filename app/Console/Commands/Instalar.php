<?php

namespace App\Console\Commands;

use App\Models\Area;
use App\Models\ConfiguracionInstitucion;
use App\Models\DocumentoRequerido;
use App\Models\Institucion;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\User;
use App\Support\InstitucionActual;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Monta desde cero el catalogo minimo de una institucion nueva.
 *
 * Hasta ahora, estrenar una instalacion era nueve pasos a mano y EN ORDEN
 * ESTRICTO --institucion, documentos, departamentos, periodo, ponerlo en curso,
 * promotorias, cupos, grupos, abrir matriculas-- repartidos entre `tinker` y
 * seis pantallas de Gestion (ver DESPLIEGUE.md, partes 1.8 y 2). Saltarse uno no
 * da error: da una pantalla vacia mas adelante, que es peor.
 *
 * Este comando cubre los CINCO primeros, que son los que se atascan entre si:
 *
 *     institucion -> documentos -> departamentos -> periodo -> ponerlo en curso
 *
 * y ademas las DOS cuentas de administrador, que hoy nacen a mano en `tinker`
 * porque a Gestion solo entra un administrador y todavia no hay ninguno.
 *
 * Lo que NO cubre --promotorias, cupos y grupos-- se queda a proposito en
 * Gestion: un grupo lleva horario de rejilla semanal y un cupo depende del
 * periodo, y esas pantallas ya lo resuelven mejor de lo que lo haria una
 * consola. Con el periodo en curso puesto por fin se ven, que es justo lo que
 * bloqueaba a quien empezaba.
 *
 * Dos modos:
 *
 *     php artisan instalar             # pregunta los datos de la casa de verdad
 *     php artisan instalar --ejemplo   # catalogo de juguete, sin preguntar
 *
 * El segundo existe para el desarrollador que acaba de clonar: la base arranca
 * vacia a proposito (ver DatabaseSeeder) y sin catalogo no se abre casi ninguna
 * pantalla. Crea contrasenas conocidas y por eso se niega en produccion, sin
 * bandera que lo salte. Encadena con la simulacion, que necesita un periodo en
 * curso y no lo crea ella:
 *
 *     php artisan instalar --ejemplo && php artisan simular
 *
 * Se niega a correr si la base ya tiene datos, y dice cuales encontro.
 *
 * Con varias instituciones en la misma base:
 *
 *     php artisan instalar                      # monta la institucion 1
 *     php artisan instalar --nueva              # da de alta OTRA y la monta
 *     php artisan instalar --nueva --subdominio=guarne
 *
 * «Tiene datos» se mira DENTRO de la institucion que se monta. `--nueva` crea
 * la fila de `instituciones` en la misma transaccion que el resto: si algo
 * falla, no queda una institucion vacia.
 */
class Instalar extends Command
{
    protected $signature = 'instalar
        {--ejemplo : Siembra un catalogo de juguete con contrasenas conocidas, sin preguntar. Solo para desarrollo.}
        {--nueva : Da de alta OTRA institucion en esta base y la monta.}
        {--subdominio= : Con --nueva, el subdominio que tendra (se puede dejar vacio).}';

    protected $description = 'Monta una institución nueva: institución, documentos, departamentos, periodo en curso y dos administradores.';

    /** La contrasena de las cuentas que crea --ejemplo. Es publica a proposito. */
    private const CLAVE_DE_EJEMPLO = 'administrador';

    /** La que crea `--nueva`, para el resumen. */
    private ?Institucion $nueva = null;

    public function handle(): int
    {
        // Una institucion que todavia no existe no tiene datos.
        $ocupada = $this->option('nueva') ? [] : $this->loQueYaHay();

        if ($ocupada !== []) {
            return $this->rechazarBaseConDatos($ocupada);
        }

        if ($this->option('ejemplo')) {
            if (app()->environment('production')) {
                $this->error('«--ejemplo» crea cuentas con contraseñas conocidas y el entorno es producción.');
                $this->line('No hay bandera que lo salte. Para instalar de verdad, lanza el comando sin «--ejemplo».');

                return self::FAILURE;
            }

            $plan = $this->planDeEjemplo();
        } else {
            // Sin terminal no hay a quien preguntarle, y sin esta puerta el
            // comando se CUELGA. Comprobado: con `--no-interaction`, `ask()` no
            // pregunta y devuelve el valor por defecto --null en las que no
            // tienen--, la validacion lo rechaza y el bucle que vuelve a
            // preguntar recibe otra vez lo mismo, para siempre. Tres minutos
            // sin una sola linea de salida y sin escribir nada, porque el error
            // se imprime a un buffer que nunca se vacia.
            //
            // Con las respuestas canalizadas desde un archivo el sintoma es
            // otro y tambien malo: la consola sigue contando como interactiva,
            // asi que esta puerta no salta y Symfony corta con un «Aborted.»
            // pelado en la primera pregunta.
            if (! $this->input->isInteractive()) {
                $this->error('Este comando pregunta los datos de la institución y aquí no hay terminal.');
                $this->line('Lánzalo desde una consola, o usa «--ejemplo» si lo que quieres es un catálogo de prueba.');

                return self::FAILURE;
            }

            $plan = $this->planPreguntado();
        }

        // Todo se pregunta antes y se escribe de una vez: una instalacion a
        // medias --con institucion pero sin periodo, o con periodo y sin
        // administrador-- deja la base en el unico estado que este comando ya no
        // sabria retomar, porque la barrera de arriba le cerraria la puerta.
        DB::transaction(fn () => $this->escribirEnSuInstitucion($plan));

        $this->resumen($plan);

        return self::SUCCESS;
    }

    /**
     * Que hay ya en las tablas que este comando escribe, en castellano.
     *
     * NO mira `configuracion_institucion`, y no es un olvido: esa fila la crea
     * sola `ConfiguracionInstitucion::actual()` en la primera pagina que alguien
     * abra, incluida la de entrar. Existe en cuanto el sitio se ha visitado una
     * vez, asi que no dice nada sobre si hay una institucion montada. Las cuatro
     * de abajo si: ninguna nace sin que alguien la cree.
     *
     * @return list<string>
     */
    private function loQueYaHay(): array
    {
        $tablas = [
            // Las cuentas no llevan el filtro de institucion (ver `User`).
            ['cuenta de usuario', 'cuentas de usuario', InstitucionActual::tabla('users')->count()],
            ['departamento', 'departamentos', Area::count()],
            ['periodo', 'periodos', Periodo::count()],
            ['documento requerido', 'documentos requeridos', DocumentoRequerido::count()],
        ];

        $hallazgos = [];

        foreach ($tablas as [$singular, $plural, $cuantos]) {
            if ($cuantos > 0) {
                $hallazgos[] = $cuantos.' '.($cuantos === 1 ? $singular : $plural);
            }
        }

        return $hallazgos;
    }

    /** @param  list<string>  $ocupada */
    private function rechazarBaseConDatos(array $ocupada): int
    {
        $this->error('Esto no parece una instalación nueva: la base ya tiene datos.');
        $this->newLine();

        foreach ($ocupada as $hallazgo) {
            $this->line('  · '.$hallazgo);
        }

        $this->newLine();
        $this->line('Este comando solo monta una institución desde cero, así que no ha escrito nada.');
        $this->line('Si lo que falta es una parte del catálogo, complétala desde Gestión.');

        return self::FAILURE;
    }

    // ----------------------------------------------------------------------
    // Los dos modos: preguntar, o inventarse un catalogo de juguete.
    // ----------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function planPreguntado(): array
    {
        $this->line('Vamos a montar la institución. Nada se escribe hasta el final.');
        $this->newLine();

        $this->info('── La institución ──');

        $institucion = [
            'nombre_institucion' => $this->preguntarTexto(
                'Nombre de la institución',
                'nombre',
                ['required', 'string', 'max:80'],
            ),
            'color_acento' => $this->preguntarTexto(
                'Color de acento, en formato #rrggbb',
                'color',
                ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
                '#0a7a59',
                ['color.regex' => 'El color de acento debe ir en formato #rrggbb.'],
            ),
            'limite_promotorias_por_periodo' => (int) $this->preguntarTexto(
                'Cuántas promotorías puede cursar una persona a la vez',
                'límite',
                ['required', 'integer', 'min:1', 'max:'.ConfiguracionInstitucion::RANURA_MAXIMA_ABSOLUTA],
                '2',
            ),
            'promotorias_visibles_para_estudiantes' => $this->confirm(
                '¿El catálogo de promotorías se ve desde fuera, sin cuenta?',
                true,
            ),
        ];

        $this->newLine();
        $this->info('── Los documentos que se le piden al estudiante ──');
        $this->line('Separados por comas. Déjalo en blanco si no pides ninguno.');
        $documentos = $this->preguntarLista('Documentos', 60, obligatoria: false);

        $this->newLine();
        $this->info('── Los departamentos ──');
        $this->line('Las áreas artísticas: Música, Danza, Teatro… Separadas por comas.');
        $this->line('Hace falta al menos uno: de un departamento cuelgan las promotorías.');
        $departamentos = $this->preguntarLista('Departamentos', 60, obligatoria: true);

        $this->newLine();
        $this->info('── El periodo ──');

        $nombrePeriodo = $this->preguntarTexto(
            'Nombre del periodo',
            'nombre',
            ['required', 'string', 'max:20'],
            (string) Carbon::today()->year,
        );

        $inicio = $this->preguntarFecha('Fecha de inicio (AAAA-MM-DD)', ['required', 'date_format:Y-m-d']);

        $fin = $this->preguntarFecha(
            'Fecha de fin (AAAA-MM-DD)',
            ['required', 'date_format:Y-m-d', 'after:'.$inicio],
            ['fecha.after' => 'La fecha de fin tiene que ir después del '.$inicio.'.'],
        );

        $this->newLine();
        $this->info('── Los dos administradores ──');
        $this->line('El segundo no es opcional. La cuenta de un administrador solo la puede');
        $this->line('editar otro: con uno solo, perder el acceso obliga a entrar a la base a mano.');
        $this->newLine();

        $primero = $this->preguntarAdministrador('primer administrador', []);
        $this->newLine();
        $segundo = $this->preguntarAdministrador('segundo administrador', [$primero['username']]);

        return [
            'institucion' => $institucion,
            'documentos' => $documentos,
            'departamentos' => $departamentos,
            'periodo' => ['nombre' => $nombrePeriodo, 'fecha_inicio' => $inicio, 'fecha_fin' => $fin],
            // Abrir matriculas es el ultimo interruptor y va aparte a proposito
            // (DESPLIEGUE.md 2.10): quedan promotorias, cupos y grupos por
            // montar, y abrirlas antes deja entrar gente a un catalogo vacio.
            'matriculas_abiertas' => false,
            'administradores' => [$primero, $segundo],
        ];
    }

    /** @return array<string, mixed> */
    private function planDeEjemplo(): array
    {
        $anio = Carbon::today()->year;

        return [
            'institucion' => [
                'nombre_institucion' => 'Casa de la Cultura',
                'color_acento' => '#0a7a59',
                'limite_promotorias_por_periodo' => 2,
                'promotorias_visibles_para_estudiantes' => true,
            ],
            'documentos' => ['Documento de identidad', 'Certificado de EPS', 'Foto reciente'],
            'departamentos' => ['Música', 'Danza', 'Teatro', 'Artes plásticas'],
            'periodo' => [
                'nombre' => (string) $anio,
                'fecha_inicio' => $anio.'-01-01',
                'fecha_fin' => $anio.'-12-31',
            ],
            // Al reves que en la ruta preguntada: aqui el punto es poder recorrer
            // la aplicacion entera nada mas clonar, y la inscripcion publica es
            // media aplicacion.
            'matriculas_abiertas' => true,
            'administradores' => [
                [
                    'username' => 'admin',
                    'password' => self::CLAVE_DE_EJEMPLO,
                    'nombre_completo' => 'Administrador de Ejemplo',
                    'fecha_nacimiento' => '1990-01-01',
                    'telefono' => '3000000000',
                ],
                [
                    'username' => 'admin.dos',
                    'password' => self::CLAVE_DE_EJEMPLO,
                    'nombre_completo' => 'Segunda Administradora de Ejemplo',
                    'fecha_nacimiento' => '1990-01-01',
                    'telefono' => '3000000001',
                ],
            ],
        ];
    }

    // ----------------------------------------------------------------------
    // Preguntas
    // ----------------------------------------------------------------------

    /**
     * Pregunta hasta que la respuesta pase las reglas, y las reglas son las
     * MISMAS que las de la pantalla equivalente de Gestion.
     *
     * @param  list<mixed>  $reglas
     * @param  array<string, string>  $mensajes
     */
    private function preguntarTexto(
        string $pregunta,
        string $campo,
        array $reglas,
        ?string $porDefecto = null,
        array $mensajes = [],
    ): string {
        while (true) {
            $respuesta = $this->ask($pregunta, $porDefecto);

            $validador = Validator::make([$campo => $respuesta], [$campo => $reglas], $mensajes, [$campo => $campo]);

            if ($validador->passes()) {
                return trim((string) $respuesta);
            }

            foreach ($validador->errors()->all() as $error) {
                $this->error($error);
            }
        }
    }

    /**
     * @param  list<mixed>  $reglas
     * @param  array<string, string>  $mensajes
     */
    private function preguntarFecha(string $pregunta, array $reglas, array $mensajes = []): string
    {
        return $this->preguntarTexto($pregunta, 'fecha', $reglas, null, $mensajes + [
            'fecha.date_format' => 'La fecha va en formato AAAA-MM-DD. Por ejemplo: 2026-02-01.',
        ]);
    }

    /**
     * Una lista escrita en una linea, separada por comas.
     *
     * Quita los repetidos ANTES de escribir: `areas.nombre` y
     * `documentos_requeridos.nombre` son unicos en la base, y un dedazo con dos
     * comas seguidas reventaria la transaccion entera al final, cuando ya no
     * queda nada preguntado que salvar.
     *
     * @return list<string>
     */
    private function preguntarLista(string $pregunta, int $maximo, bool $obligatoria): array
    {
        while (true) {
            $linea = (string) $this->ask($pregunta, $obligatoria ? null : '');

            $nombres = collect(explode(',', $linea))
                ->map(fn (string $nombre) => trim($nombre))
                ->filter()
                ->unique(fn (string $nombre) => mb_strtolower($nombre))
                ->values();

            if ($obligatoria && $nombres->isEmpty()) {
                $this->error('Hace falta al menos uno.');

                continue;
            }

            $largos = $nombres->filter(fn (string $nombre) => mb_strlen($nombre) > $maximo);

            if ($largos->isNotEmpty()) {
                $this->error('No puede pasar de '.$maximo.' caracteres: '.$largos->implode(', '));

                continue;
            }

            /** @var list<string> */
            return $nombres->all();
        }
    }

    /**
     * @param  list<string>  $usuariosTomados
     * @return array<string, string>
     */
    private function preguntarAdministrador(string $cual, array $usuariosTomados): array
    {
        $this->line('Datos del '.$cual.':');

        return [
            'username' => $this->preguntarTexto(
                '  Nombre de usuario',
                'usuario',
                array_merge(
                    ['required', 'string', 'max:150'],
                    $usuariosTomados === [] ? [] : [Rule::notIn($usuariosTomados)],
                ),
                null,
                ['usuario.not_in' => 'Ese nombre de usuario ya lo tiene el otro administrador.'],
            ),
            'password' => $this->preguntarClave(),
            'nombre_completo' => $this->preguntarTexto('  Nombre completo', 'nombre', ['required', 'string', 'max:90']),
            'fecha_nacimiento' => $this->preguntarFecha(
                '  Fecha de nacimiento (AAAA-MM-DD)',
                ['required', 'date_format:Y-m-d', 'before:today'],
                ['fecha.before' => 'La fecha de nacimiento tiene que ser anterior a hoy.'],
            ),
            'telefono' => $this->preguntarTexto('  Teléfono', 'teléfono', ['required', 'string', 'max:15']),
        ];
    }

    /**
     * La contrasena, dos veces y sin eco.
     *
     * Se pide dos veces porque no se ve al teclearla y porque estas son las
     * unicas cuentas del sistema que no se pueden recuperar desde otra pantalla:
     * un dedazo aqui deja fuera al administrador que acaba de instalar.
     */
    private function preguntarClave(): string
    {
        while (true) {
            $clave = (string) $this->secret('  Contraseña (mínimo 8 caracteres)');

            $validador = Validator::make(
                ['clave' => $clave],
                ['clave' => ['required', 'string', Password::defaults()]],
                [],
                ['clave' => 'contraseña'],
            );

            if ($validador->fails()) {
                foreach ($validador->errors()->all() as $error) {
                    $this->error($error);
                }

                continue;
            }

            if ($clave !== (string) $this->secret('  Repítela')) {
                $this->error('Las dos contraseñas no coinciden.');

                continue;
            }

            return $clave;
        }
    }

    // ----------------------------------------------------------------------
    // Escritura
    // ----------------------------------------------------------------------

    /** @param  array<string, mixed>  $plan */
    private function escribirEnSuInstitucion(array $plan): void
    {
        if (! $this->option('nueva')) {
            $this->escribir($plan);

            return;
        }

        $this->nueva = Institucion::create([
            'nombre' => $plan['institucion']['nombre_institucion'],
            'subdominio' => $this->option('subdominio') ?: null,
        ]);

        InstitucionActual::mientras($this->nueva->id, fn () => $this->escribir($plan));
    }

    /** @param  array<string, mixed>  $plan */
    private function escribir(array $plan): void
    {
        // El registro de la institucion lleva el mismo nombre que su marca.
        InstitucionActual::modelo()->update(['nombre' => $plan['institucion']['nombre_institucion']]);

        // `actual()` y no `create()`: la fila puede existir ya con los valores
        // por defecto, puesta por la primera visita a cualquier pagina.
        $configuracion = ConfiguracionInstitucion::actual();
        $configuracion->fill($plan['institucion']);
        $configuracion->save();

        // LA AUTORIZACION DE TRATAMIENTO DE DATOS LA CREA EL INSTALADOR SIEMPRE,
        // y no se pregunta con los demas. No es un papel que la entidad decida
        // si pide: es el que sostiene legalmente que este sistema guarde datos
        // de menores, y ademas es el unico que el sistema sabe IMPRIMIR — uno
        // tecleado a mano con el mismo nombre nace sin `plantilla` y por tanto
        // sin boton de descarga, que es un fallo callado.
        //
        // Va PRIMERO para llevarse el orden 0. Si la entidad no lo quiere, lo
        // apaga en Gestion → Institucion: eso es una decision suya y no un
        // descuido al instalar.
        DocumentoRequerido::firstOrCreate(
            ['nombre' => DocumentoRequerido::CONSENTIMIENTO],
            [
                'descripcion' => 'Descárgalo, fírmalo y súbelo. Si eres menor de edad lo firma tu acudiente.',
                'plantilla' => DocumentoRequerido::FORMATO_CONSENTIMIENTO,
                'orden' => 0,
            ]
        );

        foreach ($plan['documentos'] as $posicion => $nombre) {
            // `firstOrCreate` y no `create`, desde el 05/09/2026: la migracion
            // que convirtio el documento de identidad en un requerido mas lo
            // deja YA CREADO en cualquier base recien migrada, y como
            // `documentos_requeridos.nombre` es unico, un `create` reventaba la
            // transaccion entera del instalador. Le paso a `--ejemplo`, que
            // trae «Documento de identidad» en su lista, y le pasaria igual a
            // quien lo teclee al instalar.
            //
            // El que ya existe conserva SU orden en vez de recibir este: viene
            // de la migracion con orden 0 y ahi es donde tiene que estar, que es
            // el papel que la entidad ya venia pidiendo.
            DocumentoRequerido::firstOrCreate(
                ['nombre' => $nombre],
                // De diez en diez para poder colar uno en medio desde Gestion
                // sin renumerar los demas.
                ['orden' => ($posicion + 1) * 10]
            );
        }

        foreach ($plan['departamentos'] as $nombre) {
            Area::create(['nombre' => $nombre]);
        }

        $periodo = Periodo::create($plan['periodo'] + [
            'matriculas_abiertas' => $plan['matriculas_abiertas'],
        ]);

        // Ponerlo en curso es un paso aparte del de crearlo, tambien aqui: la
        // regla de «un solo periodo activo» vive en `ponerEnCurso`, y escribir
        // `activo => true` a mano seria la primera grieta por donde se cuelan
        // dos.
        Periodo::ponerEnCurso($periodo);

        foreach ($plan['administradores'] as $datos) {
            $user = User::create([
                'username' => $datos['username'],
                'password' => $datos['password'],
                'activo' => true,
            ]);

            Perfil::create([
                'user_id' => $user->id,
                'rol' => 'administrador',
                'nombre_completo' => $datos['nombre_completo'],
                'fecha_nacimiento' => $datos['fecha_nacimiento'],
                'telefono' => $datos['telefono'],
            ]);
        }
    }

    /** @param  array<string, mixed>  $plan */
    private function resumen(array $plan): void
    {
        $this->newLine();
        $this->info('Listo. '.$plan['institucion']['nombre_institucion'].' está instalada.');
        $this->newLine();

        if ($this->nueva !== null) {
            $this->line('  · Institución número '.$this->nueva->id
                .($this->nueva->subdominio ? ', subdominio «'.$this->nueva->subdominio.'»' : ', sin subdominio'));
        }

        $this->line('  · Periodo «'.$plan['periodo']['nombre'].'» en curso, del '
            .$plan['periodo']['fecha_inicio'].' al '.$plan['periodo']['fecha_fin']);
        $this->line('  · '.count($plan['departamentos']).' departamentos: '
            .implode(', ', $plan['departamentos']));
        // El +1 y la linea de abajo: el consentimiento no sale de la lista que
        // se tecleo, lo pone el instalador por su cuenta. Contarlo sin decirlo
        // dejaria a quien instala buscando de donde salio un papel de mas.
        $this->line('  · '.(count($plan['documentos']) + 1).' documentos requeridos: '
            .DocumentoRequerido::CONSENTIMIENTO
            .($plan['documentos'] === [] ? '' : ', '.implode(', ', $plan['documentos'])));
        $this->line('    La autorización de tratamiento de datos la pone el sistema y trae');
        $this->line('    su formato imprimible. Se apaga en Gestión → Institución si no se usa.');
        $this->line('  · 2 administradores: '
            .implode(' y ', array_column($plan['administradores'], 'username')));

        if ($this->option('ejemplo')) {
            $this->newLine();
            $this->warn('Las dos cuentas tienen la contraseña «'.self::CLAVE_DE_EJEMPLO.'», que es pública.');
            $this->line('Esto es una instalación de juguete: no la abras a nadie.');
            $this->line('Para llenarla de gente y de clases: php artisan simular');

            return;
        }

        $this->newLine();
        $this->line('Lo que falta, desde Gestión y en este orden:');
        $this->line('  1. Promotorías (nombre, departamento y quién la dicta)');
        $this->line('  2. Cupos del periodo — sin fila de cupo, una promotoría no tiene tope');
        $this->line('  3. Grupos (nivel, horario, salón y cupo)');
        $this->line('  4. Abrir las matrículas, en Gestión → Matrículas');
    }
}
