<?php

namespace Tests\Feature;

use App\Models\Actividad;
use App\Models\Area;
use App\Models\ConfiguracionInstitucion;
use App\Models\DatosEstudiante;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Models\User;
use App\Support\Reglas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Lo que un formulario ACEPTA y lo que rechaza.
 *
 * Hasta el 07/09/2026 el telefono era `string|max:15` y el documento otro
 * tanto: «pepito» era un telefono valido y «<script>» un documento valido. Se
 * guardaban sin una queja, y lo unico que impedia que ese `<script>` hiciera
 * algo era que Blade escapa al imprimir — o sea, UNA capa sola.
 *
 * Estas pruebas vigilan la OTRA capa, la de entrada. Hacen falta las dos: la de
 * salida protege de lo que ya esta guardado, esta de lo que entra.
 *
 * Se aflojaron las reglas a lo que habia antes y se miro cuales se ponian
 * rojas: 23 de 38. Sin esa comprobacion una prueba de rechazo no prueba nada,
 * porque el campo puede estar siendo rechazado por una regla ANTERIOR —el
 * `required`, el `max:15` de antes— y pasaria igual con la regla nueva sin
 * poner. Es el fallo que ya costo dos pruebas inutiles el 21/08.
 *
 * De las que NO enrojecieron, la mitad son las de camino BUENO —«acepta diez
 * dígitos», «un nombre con tildes sí se guarda»— que tienen que seguir verdes
 * por definicion. Las otras seis son rechazos que la regla vieja ya cubria por
 * accidente, y se dejan escritas porque documentan la intencion, pero conviene
 * saber CUALES son las que de verdad separan una regla de la otra:
 *
 * - Del telefono, todas menos `<script>x</script>`, que el `max:15` ya cortaba
 *   por largo.
 * - Del correo, solo `pepe@localhost` y `pepe@correo.c`. Las demas —sin arroba,
 *   con espacio, con dos arrobas— las rechaza tambien el `email` de Laravel.
 * - Del documento, todas.
 */
class FormulariosEstrictosTest extends TestCase
{
    use RefreshDatabase;

    private Perfil $administrador;

    private Periodo $periodo;

    private Promotoria $violin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->administrador = $this->crearPerfil('jefa', 'administrador');

        $this->periodo = Periodo::create([
            'nombre' => '2026-1',
            'fecha_inicio' => '2026-01-15',
            'fecha_fin' => '2026-06-30',
            'activo' => true,
            'matriculas_abiertas' => true,
        ]);

        $area = Area::create(['nombre' => 'Música']);
        $this->violin = Promotoria::create(['nombre' => 'Violín', 'area_id' => $area->id]);
    }

    private function crearPerfil(string $username, string $rol): Perfil
    {
        $user = User::create(['username' => $username, 'password' => 'demo1234', 'activo' => true]);

        return Perfil::create([
            'user_id' => $user->id,
            'rol' => $rol,
            'nombre_completo' => ucfirst($username),
            'fecha_nacimiento' => '1990-04-04',
            'telefono' => '3001112233',
        ]);
    }

    /**
     * El formulario publico de inscripcion, tal como lo manda el navegador.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function inscripcion(array $extra = []): array
    {
        return [
            'username' => 'nuevo.estudiante',
            'password' => 'clavelarga2026',
            'password_confirmation' => 'clavelarga2026',
            'nombre_completo' => 'Pedro Nel Gómez',
            'fecha_nacimiento' => '1995-03-03',
            'telefono' => '3001112233',
            'documento_identidad' => '99887766',
            'promotoria' => $this->violin->id,
            ...$extra,
        ];
    }

    // --------------------------------------------------------------------
    // El telefono
    // --------------------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function telefonosQueNoLoSon(): array
    {
        return [
            'con espacios' => ['300 111 2233'],
            'con guiones' => ['300-111-2233'],
            'con el prefijo de pais' => ['+573001112233'],
            'de nueve digitos' => ['300111223'],
            'de once digitos' => ['30011122334'],
            'con letras' => ['300111223a'],
            'que son letras' => ['no tengo'],
            'con una etiqueta dentro' => ['<script>x</script>'],
        ];
    }

    #[DataProvider('telefonosQueNoLoSon')]
    public function test_la_inscripcion_publica_rechaza_un_telefono_que_no_son_diez_digitos(string $telefono): void
    {
        $this->post(route('inscripcion.guardar'), $this->inscripcion(['telefono' => $telefono]))
            ->assertSessionHasErrors('telefono');

        // La cuenta de la administradora es la unica que debe seguir habiendo:
        // si aparece una segunda, la inscripcion se guardo pese al rechazo.
        $this->assertDatabaseCount('users', 1);
    }

    public function test_la_inscripcion_publica_acepta_diez_digitos(): void
    {
        $this->post(route('inscripcion.guardar'), $this->inscripcion(['telefono' => '3001112233']))
            ->assertSessionHasNoErrors();

        $this->assertSame(
            '3001112233',
            Perfil::where('nombre_completo', 'Pedro Nel Gómez')->first()?->telefono
        );
    }

    /** El fijo de diez digitos que existe en Colombia desde 2022 tambien vale. */
    public function test_un_fijo_de_diez_digitos_tambien_vale(): void
    {
        $this->assertMatchesRegularExpression(Reglas::CELULAR, '6045551234');
    }

    public function test_mi_perfil_no_deja_guardar_un_telefono_con_espacios(): void
    {
        $this->actingAs($this->administrador->user)
            ->post(route('mi-perfil.guardar'), ['accion' => 'contacto', 'telefono' => '300 111 2233'])
            ->assertSessionHasErrors('telefono');

        $this->assertSame('3001112233', $this->administrador->fresh()->telefono);
    }

    /**
     * El mensaje dice QUE hay que hacer, no que «el formato no es válido».
     *
     * No es cosmetico: quien teclea «300 111 2233» desde un telefono no puede
     * adivinar que lo que sobra son los espacios, y este sistema no tiene
     * ningun otro canal para decirselo.
     */
    public function test_el_rechazo_del_telefono_explica_que_sobra(): void
    {
        $this->actingAs($this->administrador->user)
            ->post(route('mi-perfil.guardar'), ['accion' => 'contacto', 'telefono' => '300 111 2233'])
            ->assertSessionHasErrors([
                'telefono' => 'Escribe el número de celular con 10 dígitos, sin espacios ni guiones. Ejemplo: 3001112233.',
            ]);
    }

    /** El de la ENTIDAD es otro campo y tiene otra regla, a proposito. */
    public function test_el_telefono_de_la_entidad_admite_un_fijo_con_extension(): void
    {
        $this->actingAs($this->administrador->user)
            ->post(route('gestion-configuracion'), $this->formularioDeInstitucion([
                'entidad_telefono' => '604 555 1234 ext. 102',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('604 555 1234 ext. 102', ConfiguracionInstitucion::actual()->fresh()->entidad_telefono);
    }

    public function test_el_telefono_de_la_entidad_sigue_rechazando_texto(): void
    {
        $this->actingAs($this->administrador->user)
            ->post(route('gestion-configuracion'), $this->formularioDeInstitucion([
                'entidad_telefono' => 'llámenos cuando quiera',
            ]))
            ->assertSessionHasErrors('entidad_telefono');
    }

    // --------------------------------------------------------------------
    // El correo
    // --------------------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function correosQueNoLoSon(): array
    {
        return [
            'sin arroba' => ['nombrecorreo.com'],
            'sin dominio' => ['nombre@'],
            'sin punto' => ['pepe@localhost'],
            'con una sola letra de extension' => ['pepe@correo.c'],
            'con espacio' => ['con espacio@correo.com'],
            'con dos arrobas' => ['a@b@correo.com'],
            'con una etiqueta dentro' => ['<script>@correo.com'],
        ];
    }

    #[DataProvider('correosQueNoLoSon')]
    public function test_mi_perfil_rechaza_lo_que_no_es_un_correo(string $correo): void
    {
        $this->actingAs($this->administrador->user)
            ->post(route('mi-perfil.guardar'), ['accion' => 'correo', 'correo' => $correo])
            ->assertSessionHasErrors('correo');

        $this->assertNull($this->administrador->user->fresh()->email);
    }

    public function test_mi_perfil_acepta_un_correo_de_verdad(): void
    {
        $this->actingAs($this->administrador->user)
            ->post(route('mi-perfil.guardar'), [
                'accion' => 'correo',
                'correo' => 'nombre.apellido+etiqueta@sub.dominio.co',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('nombre.apellido+etiqueta@sub.dominio.co', $this->administrador->user->fresh()->email);
    }

    /**
     * `pepe@localhost` es la prueba que separa esta regla de la de Laravel.
     *
     * El `email` de Laravel lo da por bueno. Si algun dia alguien quita el
     * `regex` de `Reglas::correo()` creyendo que el `email` ya lo cubre, esta
     * se pone roja y el resto no.
     */
    public function test_un_dominio_sin_punto_no_es_un_correo_aunque_laravel_lo_acepte(): void
    {
        $validador = validator(['correo' => 'pepe@localhost'], ['correo' => ['email:rfc']]);
        $this->assertTrue($validador->passes(), 'el email de Laravel cambió: esta prueba ya no separa nada.');

        $this->assertDoesNotMatchRegularExpression(Reglas::CORREO, 'pepe@localhost');
    }

    // --------------------------------------------------------------------
    // El documento
    // --------------------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function documentosQueNoLoSon(): array
    {
        return [
            'de cinco digitos' => ['99999'],
            'de trece digitos' => ['1234567890123'],
            'con puntos' => ['1.017.234'],
            'con espacios' => ['1017 234'],
            'con letras' => ['AB123456'],
            'con una etiqueta dentro' => ['<script>'],
        ];
    }

    #[DataProvider('documentosQueNoLoSon')]
    public function test_la_inscripcion_publica_rechaza_un_documento_que_no_son_solo_numeros(string $documento): void
    {
        $this->post(route('inscripcion.guardar'), $this->inscripcion(['documento_identidad' => $documento]))
            ->assertSessionHasErrors('documento_identidad');

        $this->assertDatabaseCount('datos_estudiante', 0);
    }

    public function test_la_inscripcion_a_una_actividad_rechaza_un_documento_con_letras(): void
    {
        $actividad = Actividad::create([
            'nombre' => 'Taller de cajón',
            'tipo' => Actividad::TALLER,
            'responsable_id' => $this->administrador->id,
            'token' => str_repeat('a', 32),
            'enlace_abierto' => true,
        ]);

        $this->post(route('actividad-inscribirse', $actividad->token), [
            'nombre_completo' => 'Pedro Nel Gómez',
            'documento' => 'AB1234',
            'telefono' => '3001234567',
            'fecha_nacimiento' => '2010-05-04',
        ])->assertSessionHasErrors('documento');

        $this->assertDatabaseCount('inscritos_actividad', 0);
    }

    // --------------------------------------------------------------------
    // Lo que solo sirve para colar codigo
    // --------------------------------------------------------------------

    /**
     * Un nombre con una etiqueta dentro no llega a guardarse.
     *
     * Blade lo escaparia al imprimirlo —hay pruebas de eso desde antes— pero
     * esa es la capa de SALIDA. Esta es la de entrada.
     */
    public function test_un_nombre_con_una_etiqueta_dentro_no_se_guarda(): void
    {
        $this->post(route('inscripcion.guardar'), $this->inscripcion([
            'nombre_completo' => '<script>alert(1)</script>',
        ]))->assertSessionHasErrors('nombre_completo');

        $this->assertDatabaseCount('users', 1);
    }

    /**
     * Y un nombre de verdad sigue entrando.
     *
     * Esta es la mitad que evita el exceso: una lista blanca de letras habria
     * dejado fuera el apostrofo y el guion, que estan en apellidos reales.
     */
    public function test_un_nombre_con_tildes_y_apostrofo_si_se_guarda(): void
    {
        $this->post(route('inscripcion.guardar'), $this->inscripcion([
            'nombre_completo' => "María José O'Higgins-Peña",
        ]))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('perfiles', ['nombre_completo' => "María José O'Higgins-Peña"]);
    }

    /**
     * El usuario corta la etiqueta, y NADA MAS. Los espacios y la arroba pasan.
     *
     * Esta prueba tiene dos mitades y la segunda es la importante. La regla se
     * escribio primero como lista blanca —solo letras, digitos y punto— y se
     * midio contra produccion antes de dejarla: **435 de las 841 cuentas no
     * cumplian**, porque 179 personas usan su correo de nombre de usuario y 239
     * su nombre con espacios. Con aquella regla, un administrador no podia
     * guardar la ficha de ninguna de esas 435: el `username` viaja en el mismo
     * formulario que el rol.
     *
     * Si alguien vuelve a estrechar este campo «para que sea consistente con el
     * teléfono», esta prueba se pone roja. **No lo veria de otra forma**: su
     * propio usuario de desarrollo si cumpliria la lista blanca.
     */
    public function test_el_usuario_corta_la_etiqueta_pero_no_el_espacio_ni_la_arroba(): void
    {
        $this->post(route('inscripcion.guardar'), $this->inscripcion(['username' => '<script>']))
            ->assertSessionHasErrors('username');

        foreach (['Ainhoa Davila', 'adelaconta@gmail.com', 'Aleja castaño'] as $n => $usuario) {
            $this->post(route('inscripcion.guardar'), $this->inscripcion([
                'username' => $usuario,
                // Cada vuelta necesita su propio documento: el de la anterior ya
                // esta cogido.
                'documento_identidad' => '9988770'.$n,
            ]))->assertSessionHasNoErrors();
        }

        $this->assertSame(3, User::whereIn('username', [
            'Ainhoa Davila', 'adelaconta@gmail.com', 'Aleja castaño',
        ])->count());
    }

    /**
     * El texto de la politica es el UNICO sitio del proyecto que se imprime sin
     * escapar en la plantilla, asi que es el que mas importa cerrar por delante.
     */
    public function test_la_politica_de_datos_rechaza_una_etiqueta(): void
    {
        $this->actingAs($this->administrador->user)
            ->post(route('gestion-configuracion'), $this->formularioDeInstitucion([
                'politica_datos' => 'Nuestra política <script>alert(1)</script>',
            ]))
            ->assertSessionHasErrors('politica_datos');

        $this->assertSame('', (string) ConfiguracionInstitucion::actual()->fresh()->politica_datos);
    }

    /** Y sigue admitiendo varias lineas, que es como se escribe un texto legal. */
    public function test_la_politica_de_datos_admite_varias_lineas(): void
    {
        $this->actingAs($this->administrador->user)
            ->post(route('gestion-configuracion'), $this->formularioDeInstitucion([
                'politica_datos' => "## Nuestra política\n\nDos párrafos, con tildes y ñ.",
            ]))
            ->assertSessionHasNoErrors();

        $this->assertStringContainsString(
            'Dos párrafos',
            (string) ConfiguracionInstitucion::actual()->fresh()->politica_datos
        );
    }

    /**
     * Los campos de texto que NO tenian tope dejan de aceptar lo que les echen.
     *
     * Este es el que no se ve leyendo la pantalla: el comentario de la encuesta
     * de satisfaccion era `nullable|string` a secas, o sea una via de escribir
     * megabytes en la base por peticion, con una sesion de estudiante.
     */
    public function test_el_comentario_de_la_encuesta_tiene_tope(): void
    {
        $estudiante = $this->crearPerfil('nino', 'estudiante');
        DatosEstudiante::create([
            'perfil_id' => $estudiante->id,
            'documento_identidad' => '10012345',
        ]);

        $matricula = Matricula::create([
            'estudiante_id' => $estudiante->id,
            'promotoria_id' => $this->violin->id,
            'periodo_id' => $this->periodo->id,
            'estado' => Matricula::ACTIVA,
        ]);

        // La encuesta de satisfaccion viaja pegada al formulario de retiro: es
        // la unica pantalla desde la que se contesta.
        $this->actingAs($estudiante->user)
            ->post(route('mis-matriculas.retirar', $matricula), [
                'satisfaccion_general' => 5,
                'calificacion_profesor' => 5,
                'horario_funciono' => 1,
                'recomendaria' => 1,
                'comentario' => str_repeat('a', 1001),
            ])
            ->assertSessionHasErrors('comentario');

        $this->assertDatabaseCount('encuestas_satisfaccion', 0);
    }

    // --------------------------------------------------------------------
    // El nombre de una persona (07/09, tarde)
    // --------------------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function nombresQueNoLoSon(): array
    {
        return [
            // Los cuatro primeros son casos REALES de produccion, copiados tal
            // cual: alguien tecleo su cedula en la casilla del nombre.
            'un documento tecleado en el nombre' => ['1022142147'],
            'con parentesis' => ['Alex (Sofia) Hernandez Hoyos'],
            'con comas' => ['Maria, angel Gomez, Giraldo'],
            'con un cero por una o' => ['Adrian0 Octaviano Alzate'],
            'con una cifra al final' => ['Inscrito 4'],
            'con una etiqueta dentro' => ['<script>alert(1)</script>'],
            'que empieza por signo' => ['=cmd|calc'],
        ];
    }

    #[DataProvider('nombresQueNoLoSon')]
    public function test_la_inscripcion_publica_rechaza_un_nombre_con_numeros_o_signos(string $nombre): void
    {
        $this->post(route('inscripcion.guardar'), $this->inscripcion(['nombre_completo' => $nombre]))
            ->assertSessionHasErrors('nombre_completo');

        $this->assertDatabaseCount('users', 1);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nombresQueSiLoSon(): array
    {
        return [
            'corriente' => ['Pedro Nel Gomez'],
            'con tildes y ñ' => ['María José Muñoz Peña'],
            // Este es de produccion, y es la razon por la que el punto entra
            // aunque no estuviera en la lista que se pidio: es la inicial de un
            // segundo nombre y una forma normal de escribir un nombre.
            'con una inicial' => ['Elide del C. Puerta Rodriguez'],
            'con apostrofo' => ["Ana D'Angelo"],
            'con guion' => ['Ana Pérez-Reverte'],
        ];
    }

    #[DataProvider('nombresQueSiLoSon')]
    public function test_un_nombre_de_verdad_sigue_entrando(string $nombre): void
    {
        $this->post(route('inscripcion.guardar'), $this->inscripcion(['nombre_completo' => $nombre]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('perfiles', ['nombre_completo' => $nombre]);
    }

    /**
     * El nombre de un CATALOGO sigue admitiendo numeros, y esa asimetria importa.
     *
     * «Nivel 2» y «2026-1» son rotulos donde el numero ES el dato. Si alguien
     * aplica la regla del nombre de persona a los catalogos «para que sea
     * consistente», deja el sistema sin poder crear un periodo.
     */
    public function test_el_nombre_de_un_catalogo_si_admite_numeros(): void
    {
        $this->actingAs($this->administrador->user)
            ->post(route('periodo-nuevo'), [
                'nombre' => '2027-1',
                'fecha_inicio' => '2027-01-15',
                'fecha_fin' => '2027-06-30',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('periodos', ['nombre' => '2027-1']);
    }

    // --------------------------------------------------------------------
    // El acudiente sin telefono (07/09, tarde)
    // --------------------------------------------------------------------

    /**
     * Un acudiente con nombre y sin telefono deja de poder guardarse.
     *
     * Lo pidio el usuario despues de encontrarse 14 en produccion. NO es la
     * regla de los MENORES —a ellos se les exige el acudiente entero— sino la
     * de cualquiera: en cuanto hay un nombre ahi hace falta el numero, porque
     * un acudiente al que no se puede llamar no cumple la funcion por la que se
     * registra.
     */
    public function test_un_acudiente_con_nombre_y_sin_telefono_no_se_guarda(): void
    {
        $this->post(route('inscripcion.guardar'), $this->inscripcion([
            // Mayor de edad: asi la regla que muerde es `required_with` y no la
            // de los menores, que es otra y ya estaba.
            'fecha_nacimiento' => '1990-04-04',
            'acudiente_nombre' => 'La mamá',
            'acudiente_telefono' => '',
        ]))->assertSessionHasErrors('acudiente_telefono');

        $this->assertDatabaseCount('acudientes', 0);
    }

    /** Y sin acudiente NINGUNO, un mayor de edad sigue entrando. */
    public function test_un_mayor_sin_acudiente_sigue_entrando(): void
    {
        $this->post(route('inscripcion.guardar'), $this->inscripcion([
            'fecha_nacimiento' => '1990-04-04',
        ]))->assertSessionHasNoErrors();

        $this->assertDatabaseCount('acudientes', 0);
    }

    /**
     * Un MENOR que no escribe nada recibe UN aviso por campo, no dos.
     *
     * Las dos reglas se solapan —`required_with` y la de los menores— y sin el
     * corte que hay en `comprobarAcudienteDeMenor()` el mismo campo saldria
     * marcado dos veces diciendo lo mismo.
     */
    public function test_un_menor_sin_acudiente_recibe_un_solo_aviso_por_campo(): void
    {
        $this->post(route('inscripcion.guardar'), $this->inscripcion([
            'fecha_nacimiento' => now()->subYears(10)->toDateString(),
            'acudiente_nombre' => '',
            'acudiente_telefono' => '',
        ]))->assertSessionHasErrors(['acudiente_nombre', 'acudiente_telefono']);

        $errores = session('errors');

        $this->assertCount(1, $errores->get('acudiente_nombre'));
        $this->assertCount(1, $errores->get('acudiente_telefono'));
    }

    // --------------------------------------------------------------------
    // El correo, obligatorio o no segun la institucion (07/09, tarde)
    // --------------------------------------------------------------------

    public function test_de_fabrica_el_correo_es_opcional(): void
    {
        $this->assertFalse((bool) ConfiguracionInstitucion::actual()->correo_obligatorio);

        $this->actingAs($this->administrador->user)
            ->post(route('mi-perfil.guardar'), ['accion' => 'correo', 'correo' => ''])
            ->assertSessionHasNoErrors();
    }

    public function test_encendido_el_interruptor_el_correo_pasa_a_ser_obligatorio(): void
    {
        $config = ConfiguracionInstitucion::actual();
        $config->correo_obligatorio = true;
        $config->save();

        $this->actingAs($this->administrador->user)
            ->post(route('mi-perfil.guardar'), ['accion' => 'correo', 'correo' => ''])
            ->assertSessionHasErrors('correo');
    }

    /**
     * El interruptor alcanza a los TRES formularios que piden correo.
     *
     * Esta es la que evita el fallo de verdad: si uno se olvidara de mirarlo, el
     * administrador lo enciende, ve que una pantalla lo exige y otra no, y no
     * tiene forma de saber cual es la que esta mal.
     */
    public function test_el_interruptor_alcanza_al_formulario_de_usuario(): void
    {
        $config = ConfiguracionInstitucion::actual();
        $config->correo_obligatorio = true;
        $config->save();

        $this->actingAs($this->administrador->user)
            ->post(route('usuario-nuevo'), [
                'username' => 'nuevo.profe',
                'password' => 'clavelarga2026',
                'rol' => 'profesor',
                'nombre_completo' => 'Nueva Profesora',
                'fecha_nacimiento' => '1990-04-04',
                'telefono' => '3001112233',
                'correo' => '',
            ])
            ->assertSessionHasErrors('correo');
    }

    /** Y la pantalla dice a cuanta gente le rompe la ficha encenderlo. */
    public function test_institucion_avisa_de_a_cuantos_les_falta_el_correo(): void
    {
        $html = (string) $this->actingAs($this->administrador->user)
            ->get(route('gestion-configuracion'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Exigir el correo electrónico', $html);
        $this->assertStringContainsString('sin correo guardado', $html);
    }

    // --------------------------------------------------------------------
    // Buscar por documento, entero (07/09, tarde)
    // --------------------------------------------------------------------

    /**
     * El buscador de Usuarios encuentra por documento, pero SOLO entero.
     *
     * La mitad que se queda fuera es la que importa, y por eso son dos
     * afirmaciones en la misma prueba: con el numero completo aparece, y con un
     * trozo no aparece NADIE. Sin la segunda, un `like` daria verde igual y
     * habriamos reabierto el sondeo de cedulas que la regla vieja cerraba.
     */
    public function test_el_buscador_encuentra_por_documento_entero_y_no_por_un_trozo(): void
    {
        $ana = $this->crearPerfil('ana', 'estudiante');
        DatosEstudiante::create([
            'perfil_id' => $ana->id,
            'documento_identidad' => '1017234567',
        ]);

        $entero = (string) $this->actingAs($this->administrador->user)
            ->get(route('usuario-lista', ['buscar' => '1017234567']))
            ->assertOk()->getContent();

        $this->assertStringContainsString('Ana', $entero);

        $trozo = (string) $this->actingAs($this->administrador->user)
            ->get(route('usuario-lista', ['buscar' => '1017']))
            ->assertOk()->getContent();

        $this->assertStringNotContainsString('Ana', $trozo, 'se puede sondear el documento por partes.');
    }

    /** Y el nombre se sigue buscando por partes, que es como se usa. */
    public function test_el_buscador_sigue_encontrando_el_nombre_por_partes(): void
    {
        $this->crearPerfil('ana', 'estudiante');

        $html = (string) $this->actingAs($this->administrador->user)
            ->get(route('usuario-lista', ['buscar' => 'An']))
            ->assertOk()->getContent();

        $this->assertStringContainsString('Ana', $html);
    }

    /** El TELEFONO sigue fuera del buscador: eso no se abrio. */
    public function test_el_buscador_no_encuentra_por_telefono(): void
    {
        $this->crearPerfil('ana', 'estudiante');

        $html = (string) $this->actingAs($this->administrador->user)
            ->get(route('usuario-lista', ['buscar' => '3001112233']))
            ->assertOk()->getContent();

        $this->assertStringNotContainsString('>Ana<', $html);
    }

    // --------------------------------------------------------------------
    // Lo que ve quien mira la pantalla
    // --------------------------------------------------------------------

    /**
     * El navegador tambien rechaza, y en un telefono eso es lo que se siente.
     *
     * `pattern` evita el viaje entero e `inputmode` decide QUE TECLADO sale.
     * Sin `inputmode="numeric"` un telefono ofrece letras justo en el campo
     * donde no valen. No lo ve ninguna prueba salvo esta, que mira el atributo:
     * PHPUnit no tiene navegador.
     */
    #[DataProvider('pantallasConCampoDeTelefono')]
    public function test_el_campo_de_telefono_pide_el_teclado_de_numeros(string $ruta): void
    {
        $html = $this->get($ruta)->assertOk()->getContent();

        $this->assertStringContainsString('inputmode="numeric"', (string) $html);
        $this->assertStringContainsString('pattern="[0-9]{10}"', (string) $html);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function pantallasConCampoDeTelefono(): array
    {
        return [
            'registro del profesor' => ['/registro'],
            'inscripcion del estudiante' => ['/inscripcion'],
        ];
    }

    /**
     * NINGUNA pantalla le pone `pattern` al campo de usuario.
     *
     * Es la mitad de delante de la prueba de arriba, y por la misma razon: un
     * `pattern` de lista blanca en el campo de usuario deja a 435 personas de
     * produccion sin poder guardar su ficha, y en la pantalla de entrar les
     * cerraria la puerta del todo.
     */
    public function test_ninguna_pantalla_restringe_la_forma_del_usuario(): void
    {
        $publicas = ['/entrar', '/registro', '/inscripcion'];

        foreach ($publicas as $ruta) {
            $html = (string) $this->get($ruta)->assertOk()->getContent();

            $this->assertStringContainsString('name="username"', $html, "{$ruta} perdió el campo.");
            $this->assertStringNotContainsString('pattern="[A-Za-z0-9._-]+"', $html, "{$ruta} restringe el usuario.");
        }

        $this->assertStringNotContainsString(
            'pattern="[A-Za-z0-9._-]+"',
            (string) $this->actingAs($this->administrador->user)
                ->get(route('usuario-nuevo'))->assertOk()->getContent()
        );
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function formularioDeInstitucion(array $extra = []): array
    {
        return [
            'seccion' => 'entidad',
            'nombre_institucion' => 'Casa de la Cultura',
            'color_acento' => '#0a7a59',
            'limite_promotorias_por_periodo' => 2,
            'faltas_para_abandono' => 5,
            ...$extra,
        ];
    }
}
