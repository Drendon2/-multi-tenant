<?php

namespace Tests\Feature;

use App\Models\Actividad;
use App\Models\Area;
use App\Models\CupoPromotoria;
use App\Models\DatosEstudiante;
use App\Models\InstitucionExterna;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * El enlace de UNA promotoria y los QR de inscripcion (29/09/2026).
 *
 * Lo que se vigila es lo que abre una puerta con la ventana CERRADA: que solo
 * matricula en ESA promotoria, que apagado no matricula a nadie, que lo
 * encienden administracion, la direccion de su departamento y su profesor
 * —nadie de fuera—, y que renovarlo mata el viejo. Mas el cartel de las actividades, que no
 * puede salir para un programa externo.
 */
class EnlaceDePromotoriaTest extends TestCase
{
    use RefreshDatabase;

    private Periodo $periodo;

    private Area $musica;

    private Area $danza;

    private Promotoria $violin;

    private Promotoria $ballet;

    private Perfil $admin;

    private Perfil $profesor;

    protected function setUp(): void
    {
        parent::setUp();

        // LA VENTANA CERRADA: es el caso para el que existe el enlace.
        $this->periodo = Periodo::create([
            'nombre' => '2026-2',
            'fecha_inicio' => Carbon::today()->subMonth()->toDateString(),
            'fecha_fin' => Carbon::today()->addMonths(4)->toDateString(),
            'activo' => true,
            'matriculas_abiertas' => false,
        ]);

        $this->musica = Area::create(['nombre' => 'Musica']);
        $this->danza = Area::create(['nombre' => 'Danza']);
        $this->admin = $this->perfil('jefa', 'administrador');
        $this->profesor = $this->perfil('profe', 'profesor');

        $this->violin = Promotoria::create([
            'nombre' => 'Violin', 'area_id' => $this->musica->id, 'profesor_id' => $this->profesor->id,
        ]);
        $this->ballet = Promotoria::create(['nombre' => 'Ballet', 'area_id' => $this->danza->id]);
    }

    // ------------------------------------------------------------------
    // 1. Encender, apagar, renovar: quien puede
    // ------------------------------------------------------------------

    public function test_nace_apagado_y_sin_token(): void
    {
        $this->assertFalse($this->violin->fresh()->enlace_abierto);
        $this->assertNull($this->violin->fresh()->enlace_token);
    }

    public function test_el_administrador_lo_enciende_y_nace_el_token(): void
    {
        $this->actingAs($this->admin->user)
            ->post(route('panel-enlace-promotoria-abrir', $this->violin), ['abierto' => '1'])
            ->assertRedirect(route('panel-enlace-promotoria', $this->violin));

        $violin = $this->violin->fresh();
        $this->assertTrue($violin->enlace_abierto);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{16}$/', (string) $violin->enlace_token);
    }

    /**
     * EL PROFESOR ENCIENDE Y APAGA EL DE SU PROMOTORIA: el enlace existe para
     * que el registre gente nueva cuando quiera (usuario, 29/09/2026, que
     * corrigio la primera version, donde solo lo veia).
     */
    public function test_el_profesor_enciende_apaga_y_renueva_el_suyo(): void
    {
        $this->actingAs($this->profesor->user)
            ->get(route('panel-enlace-promotoria', $this->violin))
            ->assertOk()
            // El interruptor, por su campo: su ruta es la MISMA URL de la
            // pantalla (GET y POST), asi que por la URL no se distingue.
            ->assertSee('name="abierto"', false);

        $this->actingAs($this->profesor->user)
            ->post(route('panel-enlace-promotoria-abrir', $this->violin), ['abierto' => '1'])
            ->assertRedirect(route('panel-enlace-promotoria', $this->violin));
        $this->assertTrue($this->violin->fresh()->enlace_abierto);
        $viejo = $this->violin->fresh()->enlace_token;

        $this->actingAs($this->profesor->user)
            ->post(route('panel-enlace-promotoria-renovar', $this->violin))
            ->assertRedirect();
        $this->assertNotSame($viejo, $this->violin->fresh()->enlace_token);

        $this->actingAs($this->profesor->user)
            ->post(route('panel-enlace-promotoria-abrir', $this->violin), ['abierto' => '0'])
            ->assertRedirect();
        $this->assertFalse($this->violin->fresh()->enlace_abierto);
    }

    /** Un profesor no ve ni toca el enlace de una promotoria que no dicta. */
    public function test_el_profesor_no_toca_el_de_otra_promotoria(): void
    {
        $this->ballet->abrirEnlace(true);
        $token = $this->ballet->enlace_token;

        $this->actingAs($this->profesor->user)
            ->get(route('panel-enlace-promotoria', $this->ballet))
            ->assertNotFound();
        $this->actingAs($this->profesor->user)
            ->get(route('panel-enlace-promotoria-qr', $this->ballet))
            ->assertNotFound();
        $this->actingAs($this->profesor->user)
            ->post(route('panel-enlace-promotoria-abrir', $this->ballet), ['abierto' => '0'])
            ->assertNotFound();
        $this->actingAs($this->profesor->user)
            ->post(route('panel-enlace-promotoria-renovar', $this->ballet))
            ->assertNotFound();

        $this->assertTrue($this->ballet->fresh()->enlace_abierto);
        $this->assertSame($token, $this->ballet->fresh()->enlace_token);
    }

    /** El director, solo en su departamento. */
    public function test_el_director_lo_enciende_solo_en_su_departamento(): void
    {
        $director = $this->perfil('dire', 'director');
        $director->areasDirigidas()->attach($this->musica->id);

        $this->actingAs($director->user)
            ->post(route('panel-enlace-promotoria-abrir', $this->violin), ['abierto' => '1'])
            ->assertRedirect();
        $this->actingAs($director->user)
            ->post(route('panel-enlace-promotoria-abrir', $this->ballet), ['abierto' => '1'])
            ->assertNotFound();

        $this->assertTrue($this->violin->fresh()->enlace_abierto);
        $this->assertFalse($this->ballet->fresh()->enlace_abierto);
    }

    public function test_renovar_mata_el_enlace_viejo(): void
    {
        $this->violin->abrirEnlace(true);
        $viejo = $this->violin->enlace_token;

        $this->actingAs($this->admin->user)
            ->post(route('panel-enlace-promotoria-renovar', $this->violin))
            ->assertRedirect();

        $this->assertNotSame($viejo, $this->violin->fresh()->enlace_token);
        $this->get(route('promotoria-enlace', $viejo))->assertNotFound();
    }

    // ------------------------------------------------------------------
    // 2. La puerta publica
    // ------------------------------------------------------------------

    /** Con la ventana cerrada, crea la cuenta y la matricula en ESA promotoria. */
    public function test_alguien_sin_cuenta_se_inscribe_con_la_ventana_cerrada(): void
    {
        $this->violin->abrirEnlace(true);

        $this->get(route('promotoria-enlace', $this->violin->enlace_token))
            ->assertOk()
            ->assertSee(route('promotoria-enlace.inscribir', $this->violin->enlace_token), false);

        $this->post(route('promotoria-enlace.inscribir', $this->violin->enlace_token), $this->datos())
            ->assertRedirect(route('carne-recien-inscrito'));

        $matriculas = Matricula::all();
        $this->assertCount(1, $matriculas);
        $this->assertSame($this->violin->id, $matriculas[0]->promotoria_id);
        $this->assertSame(Matricula::PENDIENTE, $matriculas[0]->estado);
    }

    /**
     * La promotoria la pone el ENLACE, no el formulario: un campo colado a mano
     * no puede meter a nadie en otra con la ventana cerrada.
     */
    public function test_no_se_cuela_otra_promotoria_por_el_formulario(): void
    {
        $this->violin->abrirEnlace(true);

        $this->post(route('promotoria-enlace.inscribir', $this->violin->enlace_token), $this->datos([
            'promotoria' => $this->ballet->id,
            'promotoria_2' => $this->ballet->id,
        ]));

        $this->assertSame([$this->violin->id], Matricula::pluck('promotoria_id')->all());
    }

    /** Apagado no matricula a nadie, ni por el formulario que quedo abierto. */
    public function test_apagado_no_matricula(): void
    {
        $this->violin->abrirEnlace(true);
        $token = $this->violin->enlace_token;
        $this->violin->abrirEnlace(false);

        $this->get(route('promotoria-enlace', $token))
            ->assertOk()
            ->assertSee('no está recibiendo inscripciones');

        $this->post(route('promotoria-enlace.inscribir', $token), $this->datos())
            ->assertRedirect(route('promotoria-enlace', $token));

        $this->assertSame(0, Matricula::count());
        $this->assertNull(User::where('username', 'ana.nueva')->first());
    }

    public function test_un_token_inventado_es_404(): void
    {
        $this->get(route('promotoria-enlace', 'AAAAAAAAAAAAAAAA'))->assertNotFound();
        $this->get('/unirse/corto')->assertNotFound();
    }

    /** El cupo se sigue respetando: el trigger no sabe de enlaces. */
    public function test_el_cupo_lleno_no_deja_pasar(): void
    {
        CupoPromotoria::create([
            'promotoria_id' => $this->violin->id, 'periodo_id' => $this->periodo->id, 'cupo_maximo' => 0,
        ]);
        $this->violin->abrirEnlace(true);

        $this->post(route('promotoria-enlace.inscribir', $this->violin->enlace_token), $this->datos());

        $this->assertSame(0, Matricula::count());
    }

    /** Quien ya es estudiante entra y pulsa un boton. */
    public function test_un_estudiante_con_cuenta_se_matricula_con_el_boton(): void
    {
        $this->violin->abrirEnlace(true);
        $estudiante = $this->estudiante();

        $this->actingAs($estudiante->user)
            ->get(route('promotoria-enlace', $this->violin->enlace_token))
            ->assertOk()
            ->assertSee(route('promotoria-enlace.matricularme', $this->violin->enlace_token), false);

        $this->actingAs($estudiante->user)
            ->post(route('promotoria-enlace.matricularme', $this->violin->enlace_token))
            ->assertRedirect(route('mis-matriculas'));

        $this->assertSame(1, Matricula::where('estudiante_id', $estudiante->id)
            ->where('promotoria_id', $this->violin->id)->count());
    }

    public function test_el_boton_no_sirve_con_el_enlace_apagado(): void
    {
        $this->violin->abrirEnlace(true);
        $token = $this->violin->enlace_token;
        $this->violin->abrirEnlace(false);
        $estudiante = $this->estudiante();

        $this->actingAs($estudiante->user)
            ->post(route('promotoria-enlace.matricularme', $token))
            ->assertRedirect(route('promotoria-enlace', $token));

        $this->assertSame(0, Matricula::count());
    }

    /** El boton del catalogo sigue cerrado: el enlace no abre la ventana para las demas. */
    public function test_el_catalogo_sigue_cerrado(): void
    {
        $this->violin->abrirEnlace(true);
        $estudiante = $this->estudiante();

        $this->actingAs($estudiante->user)->post(route('matricular', $this->ballet));

        $this->assertSame(0, Matricula::count());
    }

    /** Quien fue rechazado no entra por la puerta de al lado. */
    public function test_un_rechazado_no_vuelve_por_el_enlace(): void
    {
        $this->violin->abrirEnlace(true);
        $estudiante = $this->estudiante();
        Matricula::create([
            'estudiante_id' => $estudiante->id,
            'promotoria_id' => $this->violin->id,
            'periodo_id' => $this->periodo->id,
            'estado' => Matricula::RETIRADA,
            'motivo_retiro' => Matricula::RETIRO_RECHAZO,
        ]);

        $this->actingAs($estudiante->user)
            ->post(route('promotoria-enlace.matricularme', $this->violin->enlace_token));

        $this->assertSame(Matricula::RETIRADA, Matricula::first()->estado);
    }

    // ------------------------------------------------------------------
    // 3. Los carteles con QR
    // ------------------------------------------------------------------

    public function test_el_cartel_de_la_promotoria_es_un_png(): void
    {
        $this->violin->abrirEnlace(true);

        $respuesta = $this->actingAs($this->profesor->user)
            ->get(route('panel-enlace-promotoria-qr', $this->violin));

        $respuesta->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertStringStartsWith("\x89PNG", (string) $respuesta->getContent());
    }

    public function test_el_cartel_de_un_curso_es_un_png(): void
    {
        $curso = Actividad::create([
            'tipo' => Actividad::CURSO,
            'nombre' => 'Fotografia',
            'responsable_id' => $this->profesor->id,
            'periodo_id' => $this->periodo->id,
        ]);

        $respuesta = $this->actingAs($this->profesor->user)->get(route('panel-actividad-qr', $curso));

        $respuesta->assertOk()->assertHeader('Content-Type', 'image/png');
    }

    /** Un programa externo no tiene puerta publica, asi que tampoco cartel. */
    public function test_un_programa_externo_no_tiene_cartel(): void
    {
        $escuela = InstitucionExterna::create([
            'nombre' => 'Escuela Rural',
            'perfil_id' => $this->perfil('escuela', Perfil::INSTITUCION_EXTERNA)->id,
        ]);
        $programa = Actividad::create([
            'tipo' => Actividad::EXTERNO,
            'nombre' => 'Guitarra alla',
            'responsable_id' => $this->profesor->id,
            'institucion_id' => $escuela->id,
        ]);

        $this->actingAs($this->admin->user)
            ->get(route('panel-actividad-qr', $programa))
            ->assertNotFound();
    }

    // ------------------------------------------------------------------

    private function datos(array $extra = []): array
    {
        return [
            'username' => 'ana.nueva',
            'password' => 'secreto123',
            'password_confirmation' => 'secreto123',
            'nombre_completo' => 'Ana Ruiz',
            'fecha_nacimiento' => Carbon::today()->subYears(25)->toDateString(),
            'telefono' => '3001234567',
            'documento_identidad' => '1234567890',
            ...$extra,
        ];
    }

    private function estudiante(): Perfil
    {
        $perfil = $this->perfil('beto', 'estudiante');
        DatosEstudiante::create(['perfil_id' => $perfil->id, 'documento_identidad' => '99887766']);

        return $perfil;
    }

    private function perfil(string $username, string $rol): Perfil
    {
        $user = User::create(['username' => $username, 'password' => 'x', 'activo' => true]);

        /** @var Perfil $perfil */
        $perfil = Perfil::create([
            'user_id' => $user->id,
            'rol' => $rol,
            'nombre_completo' => ucfirst($username),
            'fecha_nacimiento' => Carbon::today()->subYears(30)->toDateString(),
            'telefono' => '3000000000',
        ]);

        return $perfil;
    }
}
