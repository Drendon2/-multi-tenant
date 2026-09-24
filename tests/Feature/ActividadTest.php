<?php

namespace Tests\Feature;

use App\Models\Actividad;
use App\Models\DatosEstudiante;
use App\Models\InscritoActividad;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\SesionActividad;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Cursos, talleres y grupos de proyeccion, de punta a punta.
 *
 * Lo que sostiene este archivo son las cinco cosas que separan una actividad de
 * una promotoria, y ninguna de ellas se ve leyendo una pantalla suelta:
 *
 * - Cada boton de Gestion administra lo suyo y no lo del otro.
 * - El tipo NO se elige: sale del numero de clases, al crear y cada vez que se
 *   guardan las fechas.
 * - Al enlace se entra sin cuenta y sin matricula, y deja de admitir gente por
 *   dos motivos distintos —lleno y cerrado— que la pantalla distingue.
 * - Direccion VE y quien dirige ESCRIBE, en las tres pantallas del Panel.
 * - "Sin marcar" no es un estado: es que no hay fila.
 *
 * Dos avisos concuerdan en genero con tres palabras que no concuerdan igual
 * ("la clase", "el taller", "el ensayo"). Las dos pruebas que los recorren
 * existen porque las dos frases salieron mal la primera vez, y las dos se
 * vieron en el navegador y no aqui.
 */
class ActividadTest extends TestCase
{
    use RefreshDatabase;

    private Perfil $admin;

    private Perfil $director;

    private Perfil $profesor;

    private Perfil $estudiante;

    private Periodo $periodo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->periodo = Periodo::create([
            'nombre' => '2026-1',
            'fecha_inicio' => '2026-01-15',
            'fecha_fin' => '2026-06-30',
            'activo' => true,
            'matriculas_abiertas' => true,
        ]);

        $this->admin = $this->crearPerfil('admin', 'administrador');
        $this->director = $this->crearPerfil('dire', 'director');
        $this->profesor = $this->crearPerfil('profe', 'profesor');
        $this->estudiante = $this->crearPerfil('ana', 'estudiante');
    }

    private function crearPerfil(string $username, string $rol): Perfil
    {
        $user = User::create(['username' => $username, 'password' => 'x', 'activo' => true]);

        return Perfil::create([
            'user_id' => $user->id,
            'rol' => $rol,
            'nombre_completo' => ucfirst($username),
            'fecha_nacimiento' => Carbon::today()->subYears(30)->toDateString(),
            'telefono' => '3000000000',
        ]);
    }

    /**
     * Cierra el enlace sin pasar por la pantalla.
     *
     * A mano y no con `create(['abierta' => false])`: `abierta` esta fuera del
     * `$fillable`, asi que la asignacion en masa la ignora en silencio y la
     * actividad nace abierta igual.
     */
    private function cerrarEnlace(Actividad $actividad): Actividad
    {
        $actividad->abierta = false;
        $actividad->save();

        return $actividad;
    }

    /** @param  array<string, mixed>  $extra */
    /**
     * Las dos pantallas del enlace traen lo que el boton de copiar necesita.
     *
     * El BOTON lo crea `copiar-enlace.js` y por eso no se puede comprobar
     * aqui: una prueba de servidor no ejecuta JavaScript. Lo que si se fija es
     * el andamiaje sin el cual el boton no aparece --el envoltorio y el script
     * en la pagina--, que es justo lo que se rompe al mover un bloque de sitio.
     *
     * Lo que hace el boton se comprobo en el navegador, que es donde se
     * comprueba un boton. De ahi salio ademas su unico fallo: `writeText`
     * RECHAZA la promesa cuando no puede copiar, no devuelve false, y sin un
     * `catch` el boton no hacia nada de nada.
     */
    public function test_las_pantallas_del_enlace_traen_el_andamiaje_para_copiarlo(): void
    {
        $taller = $this->crearActividad(Actividad::TALLER, 'Taller de cajón');

        foreach ([route('panel-actividad', $taller), route('actividad-curso-lista')] as $url) {
            $html = $this->actingAs($this->admin->user)->get($url)->assertOk()->getContent();

            // La clase, no el atributo entero: desde el 06/09/2026 puede
            // llevar al lado `enlace-fila-plegada`, que esconde el campo hasta
            // que se pulsa. Fijar `class="enlace-fila"` clavaba tambien lo que
            // NO puede acompaniarla, y esta prueba se puso roja por un cambio
            // que no rompia nada de lo que dice vigilar.
            $this->assertMatchesRegularExpression(
                '#class="enlace-fila[ "]#',
                $html,
                "Falta el envoltorio en {$url}"
            );
            $this->assertMatchesRegularExpression('#js/copiar-enlace\.js\?v=\d+#', $html, "Falta el script en {$url}");
            $this->assertStringContainsString($taller->enlace(), $html);
        }
    }

    private function crearActividad(string $tipo, string $nombre, array $extra = []): Actividad
    {
        return Actividad::create([
            'tipo' => $tipo,
            'nombre' => $nombre,
            'responsable_id' => $this->profesor->id,
            'periodo_id' => $this->periodo->id,
            ...$extra,
        ]);
    }

    // -----------------------------------------------------------------------
    // Puertas
    // -----------------------------------------------------------------------

    public function test_un_profesor_no_administra_actividades(): void
    {
        $this->actingAs($this->profesor->user)
            ->get(route('actividad-curso-lista'))
            ->assertRedirect(route('post-login'));
    }

    public function test_un_estudiante_tampoco(): void
    {
        $this->actingAs($this->estudiante->user)
            ->get(route('actividad-proyeccion-lista'))
            ->assertRedirect(route('post-login'));
    }

    public function test_el_director_si(): void
    {
        $this->actingAs($this->director->user)
            ->get(route('actividad-curso-lista'))
            ->assertOk();
    }

    // -----------------------------------------------------------------------
    // Cada boton administra lo suyo
    // -----------------------------------------------------------------------

    public function test_los_cursos_y_la_proyeccion_no_se_mezclan_en_pantalla(): void
    {
        $this->crearActividad(Actividad::TALLER, 'Taller de cajón');
        $this->crearActividad(Actividad::PROYECCION, 'Banda sinfónica');

        $this->actingAs($this->admin->user)
            ->get(route('actividad-curso-lista'))
            ->assertSee('Taller de cajón')
            ->assertDontSee('Banda sinfónica');

        $this->actingAs($this->admin->user)
            ->get(route('actividad-proyeccion-lista'))
            ->assertSee('Banda sinfónica')
            ->assertDontSee('Taller de cajón');
    }

    public function test_la_pantalla_de_cursos_no_edita_un_grupo_de_proyeccion(): void
    {
        $banda = $this->crearActividad(Actividad::PROYECCION, 'Banda sinfónica');

        // Cada pantalla responde por lo suyo. Sin esto, la de cursos tocaria
        // un grupo de proyeccion con solo cambiar el id de la URL.
        $this->actingAs($this->admin->user)
            ->get(route('actividad-curso-editar', $banda))
            ->assertNotFound();
    }

    // -----------------------------------------------------------------------
    // El numero de clases decide el tipo
    // -----------------------------------------------------------------------

    public function test_una_sola_clase_es_un_taller(): void
    {
        $this->actingAs($this->admin->user)->post(route('actividad-curso-nueva'), [
            'nombre' => 'Taller de cajón',
            'clases' => '1',
            'responsable_id' => $this->profesor->id,
            'cupo_maximo' => '',
        ]);

        $this->assertSame(Actividad::TALLER, Actividad::firstWhere('nombre', 'Taller de cajón')->tipo);
    }

    public function test_dos_o_mas_clases_son_un_curso(): void
    {
        $this->actingAs($this->admin->user)->post(route('actividad-curso-nueva'), [
            'nombre' => 'Iniciación a la guitarra',
            'clases' => '4',
            'responsable_id' => $this->profesor->id,
            'cupo_maximo' => '',
        ]);

        $this->assertSame(Actividad::CURSO, Actividad::firstWhere('nombre', 'Iniciación a la guitarra')->tipo);
    }

    public function test_el_formulario_no_pregunta_el_tipo(): void
    {
        // Se quito a proposito: el tipo es consecuencia del numero de clases, y
        // preguntarlo aparte dejaba crear un taller de cuatro dias.
        $html = $this->actingAs($this->admin->user)
            ->get(route('actividad-curso-nueva'))
            ->assertOk()
            ->assertSee('¿Cuántas clases?', escape: false)
            ->getContent();

        $this->assertStringNotContainsString('name="tipo"', $html);
    }

    public function test_al_crear_se_va_a_poner_las_fechas_y_no_al_listado(): void
    {
        // Un curso sin fechas esta a medio crear: no se puede iniciar nada ni
        // decirle a nadie cuando es.
        $this->actingAs($this->admin->user)->post(route('actividad-curso-nueva'), [
            'nombre' => 'Iniciación a la guitarra',
            'clases' => '4',
            'responsable_id' => $this->profesor->id,
            'cupo_maximo' => '',
        ])->assertRedirect(route('actividad-curso-fechas', [
            Actividad::firstWhere('nombre', 'Iniciación a la guitarra'),
            'clases' => 4,
        ]));
    }

    public function test_la_pantalla_de_fechas_pinta_una_casilla_por_clase_pedida(): void
    {
        $curso = $this->crearActividad(Actividad::CURSO, 'Iniciación a la guitarra');

        $html = $this->actingAs($this->admin->user)
            ->get(route('actividad-curso-fechas', [$curso, 'clases' => 4]))
            ->assertOk()
            ->getContent();

        $this->assertSame(4, substr_count($html, 'name="fechas[]"'));
    }

    public function test_al_volver_a_entrar_hay_casillas_de_sobra_para_crecer(): void
    {
        $curso = $this->crearActividad(Actividad::CURSO, 'Iniciación a la guitarra');
        $curso->sesiones()->create(['fecha' => '2026-09-03']);
        $curso->sesiones()->create(['fecha' => '2026-09-10']);

        $html = $this->actingAs($this->admin->user)
            ->get(route('actividad-curso-fechas', $curso))
            ->assertOk()
            ->getContent();

        // Las dos que tiene, mas tres vacias: asi se le anaden dias sin un
        // boton que las invente.
        $this->assertSame(5, substr_count($html, 'name="fechas[]"'));
        $this->assertStringContainsString('value="2026-09-03"', $html);
    }

    // -----------------------------------------------------------------------
    // Guardar las fechas
    // -----------------------------------------------------------------------

    public function test_las_fechas_se_guardan_y_las_vacias_no_cuentan(): void
    {
        $curso = $this->crearActividad(Actividad::CURSO, 'Iniciación a la guitarra');

        $this->actingAs($this->admin->user)->post(route('actividad-curso-fechas', $curso), [
            'fechas' => ['2026-09-03', '', '2026-09-10', ''],
        ])->assertRedirect(route('gestion-programas'));

        $this->assertSame(
            ['2026-09-03', '2026-09-10'],
            $curso->sesiones()->get()->map(fn ($s) => $s->fecha->toDateString())->all()
        );
    }

    public function test_quitarle_dias_a_un_curso_hasta_dejarlo_en_uno_lo_hace_taller(): void
    {
        $curso = $this->crearActividad(Actividad::CURSO, 'Iniciación a la guitarra');
        $curso->sesiones()->create(['fecha' => '2026-09-03']);
        $curso->sesiones()->create(['fecha' => '2026-09-10']);

        $this->actingAs($this->admin->user)->post(route('actividad-curso-fechas', $curso), [
            'fechas' => ['2026-09-03'],
        ]);

        $this->assertSame(Actividad::TALLER, $curso->fresh()->tipo);
        $this->assertSame(1, $curso->sesiones()->count());
    }

    public function test_anadirle_un_dia_a_un_taller_lo_hace_curso(): void
    {
        $taller = $this->crearActividad(Actividad::TALLER, 'Taller de cajón');
        $taller->sesiones()->create(['fecha' => '2026-09-03']);

        $this->actingAs($this->admin->user)->post(route('actividad-curso-fechas', $taller), [
            'fechas' => ['2026-09-03', '2026-09-10'],
        ]);

        $this->assertSame(Actividad::CURSO, $taller->fresh()->tipo);
    }

    public function test_una_fecha_repetida_se_rechaza(): void
    {
        $curso = $this->crearActividad(Actividad::CURSO, 'Iniciación a la guitarra');

        $this->actingAs($this->admin->user)->post(route('actividad-curso-fechas', $curso), [
            'fechas' => ['2026-09-03', '2026-09-03'],
        ])->assertSessionHas('error');

        $this->assertSame(0, $curso->sesiones()->count());
    }

    public function test_no_se_puede_dejar_sin_ninguna_fecha(): void
    {
        $curso = $this->crearActividad(Actividad::CURSO, 'Iniciación a la guitarra');
        $curso->sesiones()->create(['fecha' => '2026-09-03']);

        $this->actingAs($this->admin->user)->post(route('actividad-curso-fechas', $curso), [
            'fechas' => ['', ''],
        ])->assertSessionHas('error');

        $this->assertSame(1, $curso->sesiones()->count());
    }

    public function test_una_sesion_ya_iniciada_no_se_borra_quitandole_la_fecha(): void
    {
        // Lo que ocurrio, ocurrio: con la sesion se iria su lista de asistencia.
        $curso = $this->crearActividad(Actividad::CURSO, 'Iniciación a la guitarra');
        $curso->sesiones()->create(['fecha' => '2026-09-03', 'iniciada_en' => now()]);
        $curso->sesiones()->create(['fecha' => '2026-09-10']);

        $this->actingAs($this->admin->user)->post(route('actividad-curso-fechas', $curso), [
            'fechas' => ['2026-09-10'],
        ])->assertSessionHas('error');

        $this->assertSame(2, $curso->sesiones()->count());
    }

    public function test_cambiar_las_fechas_no_borra_la_sesion_que_se_queda(): void
    {
        $curso = $this->crearActividad(Actividad::CURSO, 'Iniciación a la guitarra');
        $iniciada = $curso->sesiones()->create(['fecha' => '2026-09-03', 'iniciada_en' => now()]);
        $curso->sesiones()->create(['fecha' => '2026-09-10']);

        $this->actingAs($this->admin->user)->post(route('actividad-curso-fechas', $curso), [
            'fechas' => ['2026-09-03', '2026-09-17'],
        ])->assertRedirect(route('gestion-programas'));

        // La que sigue en la lista conserva su id y su hora de inicio: no se
        // borra y se vuelve a crear.
        $this->assertNotNull($iniciada->fresh()?->iniciada_en);
        $this->assertSame($iniciada->id, $curso->sesiones()->first()->id);
    }

    public function test_un_grupo_de_proyeccion_no_tiene_pantalla_de_fechas(): void
    {
        $banda = $this->crearActividad(Actividad::PROYECCION, 'Banda sinfónica');

        $this->actingAs($this->admin->user)
            ->get(route('actividad-curso-fechas', $banda))
            ->assertNotFound();
    }

    public function test_la_pantalla_de_proyeccion_crea_siempre_proyeccion(): void
    {
        // El tipo no se ofrece en ese formulario. Mandarlo a mano no lo cambia.
        $this->actingAs($this->admin->user)->post(route('actividad-proyeccion-nueva'), [
            'nombre' => 'Coro institucional',
            'responsable_id' => $this->director->id,
            'cupo_maximo' => '',
            'tipo' => Actividad::CURSO,
        ])->assertRedirect(route('gestion-programas'));

        $this->assertSame(Actividad::PROYECCION, Actividad::firstWhere('nombre', 'Coro institucional')->tipo);
    }

    public function test_un_curso_se_crea_con_su_tipo_y_su_responsable(): void
    {
        $this->actingAs($this->admin->user)->post(route('actividad-curso-nueva'), [
            'nombre' => 'Iniciación a la guitarra',
            'clases' => '4',
            'responsable_id' => $this->profesor->id,
            'cupo_maximo' => '20',
        ]);

        $curso = Actividad::firstWhere('nombre', 'Iniciación a la guitarra');

        $this->assertSame(Actividad::CURSO, $curso->tipo);
        $this->assertSame($this->profesor->id, $curso->responsable_id);
        $this->assertSame(20, $curso->cupo_maximo);
        // El periodo en curso queda anotado sin preguntarlo.
        $this->assertSame($this->periodo->id, $curso->periodo_id);
    }

    public function test_un_estudiante_no_puede_quedar_a_cargo(): void
    {
        $this->actingAs($this->admin->user)->post(route('actividad-curso-nueva'), [
            'nombre' => 'Taller de cajón',
            'clases' => '1',
            'responsable_id' => $this->estudiante->id,
            'cupo_maximo' => '',
        ])->assertSessionHasErrors('responsable_id');

        $this->assertSame(0, Actividad::count());
    }

    // -----------------------------------------------------------------------
    // El cupo y el enlace
    // -----------------------------------------------------------------------

    public function test_el_cupo_en_blanco_es_sin_tope_y_no_cero(): void
    {
        $this->actingAs($this->admin->user)->post(route('actividad-curso-nueva'), [
            'nombre' => 'Taller de cajón',
            'clases' => '1',
            'responsable_id' => $this->profesor->id,
            'cupo_maximo' => '',
        ]);

        $this->assertNull(Actividad::firstWhere('nombre', 'Taller de cajón')->cupo_maximo);
    }

    public function test_el_formulario_no_exige_el_cupo(): void
    {
        // La prueba de arriba NO cubre esto: manda el POST directamente, asi que
        // pasaria igual con el campo marcado como obligatorio en el HTML. Y con
        // `required` puesto, el navegador no deja ni enviar el formulario — el
        // "sin tope" seria inalcanzable desde la pantalla aunque la regla de
        // validacion lo admita.
        $html = $this->actingAs($this->admin->user)
            ->get(route('actividad-curso-nueva'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/<input type="number"[^>]*id="cupo_maximo"(?![^>]*required)/', $html);
        // Y el nombre, que si lo es, sigue marcado.
        $this->assertMatchesRegularExpression('/<input type="text"[^>]*id="nombre"[^>]*required/', $html);
    }

    public function test_un_cupo_de_cero_se_rechaza(): void
    {
        // Cero no es "sin tope": es una actividad a la que nadie puede entrar.
        // Para eso esta el interruptor de cerrar el enlace.
        $this->actingAs($this->admin->user)->post(route('actividad-curso-nueva'), [
            'nombre' => 'Taller de cajón',
            'clases' => '1',
            'responsable_id' => $this->profesor->id,
            'cupo_maximo' => '0',
        ])->assertSessionHasErrors('cupo_maximo');
    }

    public function test_cada_actividad_nace_con_su_propio_enlace(): void
    {
        $uno = $this->crearActividad(Actividad::TALLER, 'Taller de cajón');
        $otro = $this->crearActividad(Actividad::PROYECCION, 'Banda sinfónica');

        $this->assertSame(32, strlen($uno->token));
        $this->assertNotSame($uno->token, $otro->token);
    }

    // -----------------------------------------------------------------------
    // El enlace publico
    // -----------------------------------------------------------------------

    /** @param  array<string, mixed>  $extra */
    private function inscribirse(Actividad $actividad, array $extra = []): TestResponse
    {
        return $this->post(route('actividad-inscribirse', $actividad->token), [
            'nombre_completo' => 'Pedro Nel Gómez',
            'documento' => '99887766',
            'telefono' => '3001234567',
            'correo' => 'pedro@example.com',
            'fecha_nacimiento' => '2010-05-04',
            ...$extra,
        ]);
    }

    public function test_el_enlace_se_abre_sin_cuenta(): void
    {
        $taller = $this->crearActividad(Actividad::TALLER, 'Taller de cajón');

        // Sin `actingAs`: es la unica pantalla del sistema que no pide sesion.
        $this->get(route('actividad-inscribirse', $taller->token))
            ->assertOk()
            ->assertSee('Taller de cajón');
    }

    public function test_un_token_que_no_existe_da_404(): void
    {
        $this->get(route('actividad-inscribirse', str_repeat('x', 32)))->assertNotFound();
    }

    public function test_inscribirse_no_crea_cuenta_ni_matricula(): void
    {
        $taller = $this->crearActividad(Actividad::TALLER, 'Taller de cajón');
        $usuarios = User::count();

        $this->inscribirse($taller)->assertSessionHas('success');

        $inscrito = $taller->inscritos()->first();

        $this->assertSame('Pedro Nel Gómez', $inscrito->nombre_completo);
        $this->assertSame(InscritoActividad::ENLACE, $inscrito->origen);
        // Lo que separa esto de la inscripcion de estudiantes: ni cuenta ni
        // matricula. Solo una fila diciendo quien se apunto.
        $this->assertSame($usuarios, User::count());
        $this->assertSame(0, Matricula::count());
    }

    public function test_el_correo_es_opcional(): void
    {
        // A un taller de ninos se apunta gente que no tiene uno, y bloquear la
        // inscripcion por eso es perder a la persona, no ganar el dato.
        $taller = $this->crearActividad(Actividad::TALLER, 'Taller de cajón');

        $this->inscribirse($taller, ['correo' => ''])->assertSessionHas('success');

        $this->assertNull($taller->inscritos()->first()->correo);
    }

    public function test_el_documento_si_es_obligatorio(): void
    {
        $taller = $this->crearActividad(Actividad::TALLER, 'Taller de cajón');

        $this->inscribirse($taller, ['documento' => ''])->assertSessionHasErrors('documento');
    }

    public function test_el_mismo_documento_no_se_apunta_dos_veces(): void
    {
        $taller = $this->crearActividad(Actividad::TALLER, 'Taller de cajón');

        $this->inscribirse($taller);
        // Quien no esta seguro de si el primer envio entro, lo manda otra vez.
        // Se cuenta como buena noticia: el resultado que queria ya esta puesto.
        $this->inscribirse($taller)->assertSessionHas('success');

        $this->assertSame(1, $taller->inscritos()->count());
    }

    public function test_el_mismo_documento_si_se_apunta_a_dos_actividades(): void
    {
        $taller = $this->crearActividad(Actividad::TALLER, 'Taller de cajón');
        $banda = $this->crearActividad(Actividad::PROYECCION, 'Banda sinfónica');

        $this->inscribirse($taller);
        $this->inscribirse($banda);

        $this->assertSame(1, $taller->inscritos()->count());
        $this->assertSame(1, $banda->inscritos()->count());
    }

    public function test_si_el_documento_es_de_un_estudiante_queda_vinculado(): void
    {
        $ana = $this->estudiante;
        DatosEstudiante::create(['perfil_id' => $ana->id, 'documento_identidad' => '55554444']);

        $banda = $this->crearActividad(Actividad::PROYECCION, 'Banda sinfónica');
        $this->inscribirse($banda, ['documento' => '55554444']);

        $this->assertSame($ana->id, $banda->inscritos()->first()->perfil_id);
    }

    public function test_un_documento_desconocido_no_vincula_nada(): void
    {
        $banda = $this->crearActividad(Actividad::PROYECCION, 'Banda sinfónica');
        $this->inscribirse($banda, ['documento' => '00000000']);

        // Lo normal: casi nadie que llegue por el enlace tendra cuenta.
        $this->assertNull($banda->inscritos()->first()->perfil_id);
    }

    // -----------------------------------------------------------------------
    // Cuando el enlace deja de admitir gente
    // -----------------------------------------------------------------------

    public function test_con_el_cupo_lleno_no_entra_nadie_mas(): void
    {
        $taller = $this->crearActividad(Actividad::TALLER, 'Taller de cajón', ['cupo_maximo' => 1]);

        $this->inscribirse($taller, ['documento' => '111111']);
        $this->inscribirse($taller, ['documento' => '222222'])->assertSessionHas('error');

        $this->assertSame(1, $taller->inscritos()->count());
    }

    public function test_el_formulario_desaparece_cuando_esta_lleno(): void
    {
        $taller = $this->crearActividad(Actividad::TALLER, 'Taller de cajón', ['cupo_maximo' => 1]);
        $this->inscribirse($taller, ['documento' => '111111']);

        $this->get(route('actividad-inscribirse', $taller->token))
            ->assertOk()
            ->assertSee('Ya no quedan cupos')
            ->assertDontSee('name="documento"', escape: false);
    }

    public function test_cerrar_el_enlace_a_mano_lo_cierra_de_verdad(): void
    {
        // Es lo unico que puede parar una actividad SIN cupo: esa no se llena.
        $banda = $this->crearActividad(Actividad::PROYECCION, 'Banda sinfónica');

        $this->actingAs($this->admin->user)
            ->post(route('actividad-proyeccion-enlace', $banda))
            ->assertRedirect(route('gestion-programas'));

        $this->assertFalse($banda->fresh()->abierta);

        // Y el POST tambien queda cerrado, no solo el formulario: esconderlo no
        // cierra la URL.
        $this->inscribirse($banda)->assertSessionHas('error');
        $this->assertSame(0, $banda->inscritos()->count());
    }

    public function test_el_enlace_cerrado_dice_cual_es_la_actividad(): void
    {
        // Quien llega con ese enlace lo recibio de alguien: necesita saber si
        // llego tarde o si se equivoco de sitio, no un 404.
        $banda = $this->cerrarEnlace($this->crearActividad(Actividad::PROYECCION, 'Banda sinfónica'));

        $this->get(route('actividad-inscribirse', $banda->token))
            ->assertOk()
            ->assertSee('Banda sinfónica')
            ->assertSee('inscripciones están cerradas', escape: false);
    }

    public function test_volver_a_abrir_el_enlace_readmite_gente(): void
    {
        $banda = $this->cerrarEnlace($this->crearActividad(Actividad::PROYECCION, 'Banda sinfónica'));

        $this->actingAs($this->admin->user)->post(route('actividad-proyeccion-enlace', $banda));

        $this->assertTrue($banda->fresh()->abierta);
        $this->inscribirse($banda)->assertSessionHas('success');
    }

    public function test_el_enlace_no_se_cierra_por_el_formulario_de_editar(): void
    {
        // Lo que la protege NO es el `$fillable` —se comprobo, y con `abierta`
        // dentro esta prueba pasaba igual—, sino que `reglas()` no la nombra:
        // `validate()` devuelve solo lo que valido, y `fill()` nunca la ve. El
        // dia que alguien la anada a las reglas del formulario, esto se rompe,
        // que es justo para lo que esta.
        $banda = $this->crearActividad(Actividad::PROYECCION, 'Banda sinfónica');

        $this->actingAs($this->admin->user)->post(route('actividad-proyeccion-editar', $banda), [
            'nombre' => 'Banda sinfónica',
            'responsable_id' => $this->profesor->id,
            'cupo_maximo' => '',
            'abierta' => '0',
        ])->assertRedirect(route('gestion-programas'));

        $this->assertTrue($banda->fresh()->abierta);
    }

    public function test_un_estudiante_no_cierra_el_enlace_de_nadie(): void
    {
        $banda = $this->crearActividad(Actividad::PROYECCION, 'Banda sinfónica');

        $this->actingAs($this->estudiante->user)
            ->post(route('actividad-proyeccion-enlace', $banda))
            ->assertRedirect(route('post-login'));

        $this->assertTrue($banda->fresh()->abierta);
    }

    public function test_el_curso_ensena_sus_fechas_a_quien_abre_el_enlace(): void
    {
        $curso = $this->crearActividad(Actividad::CURSO, 'Iniciación a la guitarra');
        $curso->sesiones()->create(['fecha' => '2026-09-03']);
        $curso->sesiones()->create(['fecha' => '2026-09-10']);

        $this->get(route('actividad-inscribirse', $curso->token))
            ->assertOk()
            ->assertSee('03/09/2026')
            ->assertSee('10/09/2026');
    }

    // -----------------------------------------------------------------------
    // El Panel de quien la dirige
    // -----------------------------------------------------------------------

    public function test_el_responsable_ve_las_suyas_y_no_las_ajenas(): void
    {
        $otro = $this->crearPerfil('otra', 'profesor');
        $mia = $this->crearActividad(Actividad::PROYECCION, 'Banda sinfónica');
        $ajena = $this->crearActividad(Actividad::PROYECCION, 'Coro institucional', [
            'responsable_id' => $otro->id,
        ]);

        $this->actingAs($this->profesor->user)
            ->get(route('panel-actividades'))
            ->assertOk()
            ->assertSee('Banda sinfónica')
            ->assertDontSee('Coro institucional');

        // Y esconderla de la lista no cierra su URL.
        $this->actingAs($this->profesor->user)
            ->get(route('panel-actividad', $ajena))
            ->assertNotFound();

        $this->actingAs($this->profesor->user)
            ->get(route('panel-actividad', $mia))
            ->assertOk();
    }

    public function test_el_administrador_ve_las_de_todos(): void
    {
        $banda = $this->crearActividad(Actividad::PROYECCION, 'Banda sinfónica');

        $this->actingAs($this->admin->user)
            ->get(route('panel-actividad', $banda))
            ->assertOk()
            ->assertSee('Banda sinfónica');
    }

    public function test_un_estudiante_no_entra_al_panel_de_actividades(): void
    {
        $banda = $this->crearActividad(Actividad::PROYECCION, 'Banda sinfónica');

        $this->actingAs($this->estudiante->user)
            ->get(route('panel-actividad', $banda))
            ->assertRedirect(route('post-login'));
    }

    public function test_iniciar_una_clase_guarda_la_hora_real_y_quien_la_abrio(): void
    {
        $curso = $this->crearActividad(Actividad::CURSO, 'Iniciación a la guitarra');
        $sesion = $curso->sesiones()->create(['fecha' => '2026-09-03']);

        $this->actingAs($this->profesor->user)
            ->post(route('panel-actividad-iniciar', $sesion))
            ->assertRedirect(route('panel-actividad', $curso));

        $sesion->refresh();

        // La fecha dice cuando TOCABA; `iniciada_en`, cuando paso. Son dos
        // datos distintos y por eso hay dos columnas.
        $this->assertNotNull($sesion->iniciada_en);
        $this->assertSame($this->profesor->id, $sesion->iniciada_por_id);
        $this->assertSame('2026-09-03', $sesion->fecha->toDateString());
    }

    public function test_el_aviso_concuerda_con_los_tres_nombres(): void
    {
        // Se vio en pantalla, no aqui: la version anterior decia «Taller
        // iniciada», porque el participio concordaba con "clase" y las otras
        // dos palabras son masculinas. Las pruebas de al lado no lo veian —
        // miran la redireccion, no el texto.
        $esperado = [
            Actividad::CURSO => 'Empezó la clase.',
            Actividad::TALLER => 'Empezó el taller.',
            Actividad::PROYECCION => 'Empezó el ensayo.',
        ];

        foreach ($esperado as $tipo => $frase) {
            $actividad = $this->crearActividad($tipo, "Prueba {$tipo}");
            $sesion = $actividad->sesiones()->create(['fecha' => '2026-09-0'.count($esperado)]);

            $this->actingAs($this->profesor->user)
                ->post(route('panel-actividad-iniciar', $sesion))
                ->assertSessionHas('success', fn (string $m) => str_starts_with($m, $frase));
        }
    }

    public function test_volver_a_oprimir_iniciar_no_reescribe_la_hora(): void
    {
        // Volver a oprimir por si acaso es lo que hace cualquiera, y reescribir
        // la hora borraria la de verdad.
        $curso = $this->crearActividad(Actividad::CURSO, 'Iniciación a la guitarra');
        $sesion = $curso->sesiones()->create([
            'fecha' => '2026-09-03',
            'iniciada_en' => '2026-09-03 18:00:00',
            'iniciada_por_id' => $this->profesor->id,
        ]);

        $this->actingAs($this->profesor->user)->post(route('panel-actividad-iniciar', $sesion));

        $this->assertSame('2026-09-03 18:00:00', $sesion->fresh()->iniciada_en->toDateTimeString());
    }

    public function test_direccion_ve_la_pantalla_pero_no_inicia_lo_ajeno(): void
    {
        // La regla estrecha, la misma que separa gestionar una promotoria de
        // dictarla: ver es cosa de direccion, iniciar es de quien estuvo.
        $curso = $this->crearActividad(Actividad::CURSO, 'Iniciación a la guitarra');
        $sesion = $curso->sesiones()->create(['fecha' => '2026-09-03']);

        $this->actingAs($this->admin->user)
            ->post(route('panel-actividad-iniciar', $sesion))
            ->assertSessionHas('error');

        $this->assertNull($sesion->fresh()->iniciada_en);
    }

    public function test_a_quien_no_dirige_no_se_le_pinta_el_boton(): void
    {
        // Pintar un boton que al pulsarlo rebota es peor que no pintarlo.
        $curso = $this->crearActividad(Actividad::CURSO, 'Iniciación a la guitarra');
        $curso->sesiones()->create(['fecha' => '2026-09-03']);

        $html = $this->actingAs($this->admin->user)
            ->get(route('panel-actividad', $curso))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(route('panel-actividad-iniciar', $curso->sesiones()->first()), $html);
    }

    public function test_un_ensayo_nace_al_oprimir_el_boton(): void
    {
        // Un grupo de proyeccion no tiene fechas puestas: la sesion nace al
        // oprimir, como `Clase` del lado de las promotorias.
        $banda = $this->crearActividad(Actividad::PROYECCION, 'Banda sinfónica');

        $this->actingAs($this->profesor->user)
            ->post(route('panel-actividad-iniciar-hoy', $banda))
            // ATERRIZA EN LA HOJA DE ASISTENCIA desde el 23/09/2026, no en la
            // ficha. El aviso decia «ya puedes pasar lista» y dejaba a la
            // persona donde estaba, con «Pasar lista» en un boton blanco dentro
            // de la tabla y dos botones verdes al lado que no eran ese. El
            // gesto es uno —llego, inicio, marco— y se hace de pie.
            ->assertRedirect(route('panel-actividad-lista', $banda->sesiones()->first()));

        $sesion = $banda->sesiones()->first();

        $this->assertSame(Carbon::today()->toDateString(), $sesion->fecha->toDateString());
        $this->assertNotNull($sesion->iniciada_en);
    }

    public function test_dos_toques_seguidos_dan_un_ensayo_y_no_dos(): void
    {
        $banda = $this->crearActividad(Actividad::PROYECCION, 'Banda sinfónica');

        $this->actingAs($this->profesor->user)->post(route('panel-actividad-iniciar-hoy', $banda));

        // Que no salgan DOS lo ataja el unico de la base por su cuenta. Lo que
        // se comprueba aqui es lo otro: que el segundo toque devuelva una
        // pantalla y no un error del motor. Sin `firstOrCreate` esta linea es
        // un 500 —se comprobo— y la de abajo pasa igual, porque un 500 tampoco
        // crea nada.
        // Y el segundo toque TAMBIÉN lleva a la hoja, no a la ficha en
        // silencio. Antes devolvía sin decir nada, así que el botón más visible
        // de la pantalla era una acción agotada: se oprimía y no pasaba nada.
        // La hora NO se reescribe —eso borraría la de verdad—, que es lo que
        // comprueba la última línea.
        $this->actingAs($this->profesor->user)
            ->post(route('panel-actividad-iniciar-hoy', $banda))
            ->assertRedirect(route('panel-actividad-lista', $banda->sesiones()->first()));

        $this->assertSame(1, $banda->sesiones()->count());
    }

    // -----------------------------------------------------------------------
    // Pasar lista
    // -----------------------------------------------------------------------

    /** Una sesion ya iniciada, con gente apuntada, lista para pasar lista. */
    private function sesionEnMarcha(int $cuantosInscritos = 2): SesionActividad
    {
        $taller = $this->crearActividad(Actividad::TALLER, 'Taller de cajón');

        foreach (range(1, $cuantosInscritos) as $n) {
            $taller->inscritos()->create([
                'nombre_completo' => "Inscrito {$n}",
                'documento' => "10000{$n}",
                'origen' => InscritoActividad::ENLACE,
            ]);
        }

        return $taller->sesiones()->create([
            'fecha' => '2026-09-03',
            'iniciada_en' => now(),
            'iniciada_por_id' => $this->profesor->id,
        ]);
    }

    public function test_no_hay_lista_que_pasar_de_algo_que_no_empezo(): void
    {
        $taller = $this->crearActividad(Actividad::TALLER, 'Taller de cajón');
        $sesion = $taller->sesiones()->create(['fecha' => '2026-09-03']);

        // El boton de la pantalla anterior ya lo tiene en cuenta; esto cierra
        // la URL.
        $this->actingAs($this->profesor->user)
            ->get(route('panel-actividad-lista', $sesion))
            ->assertRedirect(route('panel-actividad', $taller));
    }

    public function test_el_sello_de_la_sesion_concuerda_con_los_tres_nombres(): void
    {
        // El mismo tropiezo que ya costo una vez en el aviso de iniciar: la
        // primera version de esta pantalla decia «Taller iniciada el». Aqui la
        // frase lleva a la persona de sujeto, asi que no concuerda con nada.
        $esperado = [
            Actividad::CURSO => 'inició la clase',
            Actividad::TALLER => 'inició el taller',
            Actividad::PROYECCION => 'inició el ensayo',
        ];

        foreach ($esperado as $tipo => $frase) {
            $actividad = $this->crearActividad($tipo, "Prueba {$tipo}");
            $sesion = $actividad->sesiones()->create([
                'fecha' => '2026-09-03',
                'iniciada_en' => now(),
                'iniciada_por_id' => $this->profesor->id,
            ]);

            $this->actingAs($this->profesor->user)
                ->get(route('panel-actividad-lista', $sesion))
                ->assertOk()
                ->assertSee($frase, escape: false);
        }
    }

    public function test_las_marcas_se_guardan(): void
    {
        $sesion = $this->sesionEnMarcha();
        [$uno, $dos] = $sesion->actividad->inscritos()->orderBy('nombre_completo')->get()->all();

        $this->actingAs($this->profesor->user)->post(route('panel-actividad-lista', $sesion), [
            "estado_{$uno->id}" => 'asistio',
            "estado_{$dos->id}" => 'excusa',
        ])->assertSessionHas('success');

        $this->assertSame(
            ['asistio', 'excusa'],
            [
                $sesion->asistencias()->where('inscrito_id', $uno->id)->value('estado'),
                $sesion->asistencias()->where('inscrito_id', $dos->id)->value('estado'),
            ]
        );
    }

    public function test_sin_marcar_no_guarda_fila(): void
    {
        // "Sin marcar" NO es un cuarto estado: es que no hay fila. Que no la
        // haya es informacion real —la sesion se dio y a esa persona nadie la
        // paso— y guardarla como valor la volveria indistinguible.
        $sesion = $this->sesionEnMarcha();
        $uno = $sesion->actividad->inscritos()->orderBy('nombre_completo')->first();

        $this->actingAs($this->profesor->user)->post(route('panel-actividad-lista', $sesion), [
            "estado_{$uno->id}" => 'asistio',
        ]);

        $this->assertSame(1, $sesion->asistencias()->count());
    }

    public function test_volver_a_guardar_corrige_en_vez_de_acumular(): void
    {
        $sesion = $this->sesionEnMarcha(1);
        $uno = $sesion->actividad->inscritos()->first();

        $this->actingAs($this->profesor->user)
            ->post(route('panel-actividad-lista', $sesion), ["estado_{$uno->id}" => 'falto']);
        $this->actingAs($this->profesor->user)
            ->post(route('panel-actividad-lista', $sesion), ["estado_{$uno->id}" => 'asistio']);

        $this->assertSame(1, $sesion->asistencias()->count());
        $this->assertSame('asistio', $sesion->asistencias()->first()->estado);
    }

    public function test_un_estado_inventado_se_ignora(): void
    {
        $sesion = $this->sesionEnMarcha(1);
        $uno = $sesion->actividad->inscritos()->first();

        // Que no se GUARDE lo ataja el CHECK de la base por su cuenta. Lo que
        // se comprueba aqui es lo otro: que se descarte en PHP y la peticion
        // devuelva una pantalla. Sin la comprobacion esta linea es un 500 —se
        // comprobo— y la de abajo pasa igual, porque un 500 tampoco guarda.
        $this->actingAs($this->profesor->user)
            ->post(route('panel-actividad-lista', $sesion), ["estado_{$uno->id}" => 'vino_tarde'])
            ->assertRedirect(route('panel-actividad-lista', $sesion));

        $this->assertSame(0, $sesion->asistencias()->count());
    }

    public function test_la_marca_de_un_inscrito_ajeno_no_entra(): void
    {
        // El bucle recorre los inscritos de ESTA actividad, no lo que llegue en
        // el POST: un id de otra actividad no tiene donde encajar.
        $sesion = $this->sesionEnMarcha(1);
        $otra = $this->crearActividad(Actividad::PROYECCION, 'Banda sinfónica');
        $ajeno = $otra->inscritos()->create([
            'nombre_completo' => 'Alguien de la banda',
            'documento' => '777777',
            'origen' => InscritoActividad::ENLACE,
        ]);

        $this->actingAs($this->profesor->user)
            ->post(route('panel-actividad-lista', $sesion), ["estado_{$ajeno->id}" => 'asistio']);

        $this->assertSame(0, $sesion->asistencias()->count());
    }

    public function test_direccion_ve_la_lista_pero_no_la_escribe(): void
    {
        $sesion = $this->sesionEnMarcha(1);
        $uno = $sesion->actividad->inscritos()->first();

        $this->actingAs($this->admin->user)
            ->get(route('panel-actividad-lista', $sesion))
            ->assertOk()
            ->assertSee('Inscrito 1');

        $this->actingAs($this->admin->user)
            ->post(route('panel-actividad-lista', $sesion), ["estado_{$uno->id}" => 'asistio'])
            ->assertSessionHas('error');

        $this->assertSame(0, $sesion->asistencias()->count());
    }

    public function test_a_direccion_no_se_le_pintan_los_botones_de_marcar(): void
    {
        $sesion = $this->sesionEnMarcha(1);

        $html = $this->actingAs($this->admin->user)
            ->get(route('panel-actividad-lista', $sesion))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('type="radio"', $html);
        $this->assertStringNotContainsString(route('panel-actividad-anadir', $sesion), $html);
    }

    // -----------------------------------------------------------------------
    // Quien llega sin inscribirse
    // -----------------------------------------------------------------------

    public function test_anadir_en_clase_deja_inscrito_y_marcado(): void
    {
        $sesion = $this->sesionEnMarcha(1);

        $this->actingAs($this->profesor->user)->post(route('panel-actividad-anadir', $sesion), [
            'nombre_completo' => 'Pedro Nel Gómez',
        ])->assertSessionHas('success');

        $nuevo = $sesion->actividad->inscritos()->firstWhere('nombre_completo', 'Pedro Nel Gómez');

        $this->assertSame(InscritoActividad::EN_SESION, $nuevo->origen);
        // Sin documento: nadie se lo va a pedir con la clase empezando.
        $this->assertNull($nuevo->documento);
        // Y marcado de una vez: se le anade PORQUE esta aqui.
        $this->assertSame('asistio', $sesion->asistencias()->where('inscrito_id', $nuevo->id)->value('estado'));
    }

    public function test_anadir_el_mismo_nombre_dos_veces_avisa_en_vez_de_duplicar(): void
    {
        // El unico de (actividad, documento) no ataja esto: quien se anade en
        // clase entra sin documento, y en MariaDB los NULL no chocan. Pulsar
        // dos veces creaba dos personas con el mismo nombre.
        $sesion = $this->sesionEnMarcha(1);

        $this->actingAs($this->profesor->user)
            ->post(route('panel-actividad-anadir', $sesion), ['nombre_completo' => 'Pedro Nel Gómez'])
            ->assertSessionHas('success');

        $this->actingAs($this->profesor->user)
            ->post(route('panel-actividad-anadir', $sesion), ['nombre_completo' => 'Pedro Nel Gómez'])
            ->assertSessionHas('error');

        $this->assertSame(1, $sesion->actividad->inscritos()
            ->where('nombre_completo', 'Pedro Nel Gómez')->count());
    }

    public function test_se_puede_anadir_a_varios_sin_documento(): void
    {
        // El unico de (actividad, documento) no puede chocar consigo mismo: en
        // MariaDB los NULL no colisionan, y estos son gente distinta con el
        // mismo hueco.
        $sesion = $this->sesionEnMarcha(1);

        foreach (['Ana Restrepo', 'Luis Ossa'] as $nombre) {
            $this->actingAs($this->profesor->user)
                ->post(route('panel-actividad-anadir', $sesion), ['nombre_completo' => $nombre])
                ->assertSessionHas('success');
        }

        $this->assertSame(3, $sesion->actividad->inscritos()->count());
    }

    public function test_anadir_en_clase_se_salta_el_cupo(): void
    {
        // El cupo gobierna el ENLACE. A quien esta de pie en el salon no lo
        // echa un numero.
        $taller = $this->crearActividad(Actividad::TALLER, 'Taller de cajón', ['cupo_maximo' => 1]);
        $taller->inscritos()->create([
            'nombre_completo' => 'El único',
            'documento' => '111111',
            'origen' => InscritoActividad::ENLACE,
        ]);
        $sesion = $taller->sesiones()->create([
            'fecha' => '2026-09-03',
            'iniciada_en' => now(),
            'iniciada_por_id' => $this->profesor->id,
        ]);

        $this->actingAs($this->profesor->user)
            ->post(route('panel-actividad-anadir', $sesion), ['nombre_completo' => 'El que llegó'])
            ->assertSessionHas('success');

        $this->assertSame(2, $taller->inscritos()->count());
    }

    public function test_direccion_no_anade_a_nadie(): void
    {
        $sesion = $this->sesionEnMarcha(1);

        $this->actingAs($this->admin->user)
            ->post(route('panel-actividad-anadir', $sesion), ['nombre_completo' => 'Pedro Nel Gómez'])
            ->assertSessionHas('error');

        $this->assertSame(1, $sesion->actividad->inscritos()->count());
    }

    public function test_anadir_sin_nombre_se_rechaza(): void
    {
        $sesion = $this->sesionEnMarcha(1);

        $this->actingAs($this->profesor->user)
            ->post(route('panel-actividad-anadir', $sesion), ['nombre_completo' => ''])
            ->assertSessionHasErrors('nombre_completo');

        $this->assertSame(1, $sesion->actividad->inscritos()->count());
    }

    public function test_el_panel_no_ofrece_el_enlace_si_no_hay_ninguna(): void
    {
        // Mientras la institucion no use actividades, el enlace lleva a una
        // pantalla vacia y solo estorba.
        $this->actingAs($this->profesor->user)
            ->get(route('panel'))
            ->assertOk()
            ->assertDontSee(route('panel-actividades'));

        $this->crearActividad(Actividad::PROYECCION, 'Banda sinfónica');

        $this->actingAs($this->profesor->user)
            ->get(route('panel'))
            ->assertSee(route('panel-actividades'));
    }

    public function test_una_actividad_nace_abierta(): void
    {
        // El defecto lo pone la base, y el modelo en memoria no lo ha leido:
        // sin el `$attributes` del modelo, esto responderia null en la misma
        // peticion que la crea.
        $this->assertTrue($this->crearActividad(Actividad::TALLER, 'Taller de cajón')->abierta);
    }

    public function test_se_puede_montar_un_taller_sin_ningun_periodo_en_curso(): void
    {
        // Es de las primeras cosas que hace quien estrena el sistema.
        $this->periodo->update(['activo' => false]);

        $this->actingAs($this->admin->user)->post(route('actividad-curso-nueva'), [
            'nombre' => 'Taller de cajón',
            'clases' => '1',
            'responsable_id' => $this->profesor->id,
            'cupo_maximo' => '',
        ]);

        $this->assertNull(Actividad::firstWhere('nombre', 'Taller de cajón')->periodo_id);
    }

    /**
     * EL DIRECTOR SOLO VE LAS QUE DIRIGE, desde el 12/09/2026.
     *
     * Hasta ese dia «direccion» —director y administrador— veia todas las
     * actividades, y esta prueba sustituye a la que lo afirmaba. Una actividad
     * no cuelga de un departamento: lo que tiene es una PERSONA responsable, asi
     * que «asignarle» una a un director es ponerlo de responsable.
     *
     * Se afirman las DOS mitades. Sin la segunda, poner al administrador de
     * responsable de todo dejaria esta prueba en verde sin comprobar el recorte.
     */
    public function test_el_director_solo_ve_las_actividades_que_dirige(): void
    {
        $ajena = $this->crearActividad(Actividad::PROYECCION, 'Banda ajena');

        // 404 y no 403: la actividad ajena no se distingue de una que no
        // existe, que es como responde esta pantalla a proposito.
        $this->actingAs($this->director->user)
            ->get(route('panel-actividad', $ajena))
            ->assertNotFound();

        $suya = $this->crearActividad(Actividad::PROYECCION, 'Banda suya');
        $suya->responsable_id = $this->director->id;
        $suya->save();

        $this->actingAs($this->director->user)
            ->get(route('panel-actividad', $suya))
            ->assertOk()
            ->assertSee('Banda suya');
    }
}
