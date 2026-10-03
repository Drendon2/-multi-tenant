<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Clase;
use App\Models\Grupo;
use App\Models\OmisionArchivada;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Models\User;
use App\Support\Alertas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Clasificar una clase no dictada y reponer las faltas (03/10/2026).
 *
 * La bandeja de alertas dice POR QUE no se dio —excusa, falta, festivo o
 * cierre— y solo la falta llega al Panel de quien dicta, en «Clases por
 * reemplazar», desde donde se registra la reposicion.
 */
class ClasesPorReponerTest extends TestCase
{
    use RefreshDatabase;

    private Perfil $admin;

    private Perfil $profe;

    private Periodo $periodo;

    private Grupo $grupo;

    protected function setUp(): void
    {
        parent::setUp();

        // Miercoles 11/03: el grupo tiene clase los martes, asi que el 03 y el
        // 10 ya pasaron sin clase.
        Carbon::setTestNow(Carbon::parse('2026-03-11 10:00:00'));

        $this->periodo = Periodo::create([
            'nombre' => '2026-1',
            'fecha_inicio' => '2026-03-02',
            'fecha_fin' => '2026-06-30',
            'activo' => true,
            'matriculas_abiertas' => true,
        ]);

        $this->admin = $this->crearPerfil('admin', 'administrador');
        $this->profe = $this->crearPerfil('profe', 'profesor');

        $promotoria = Promotoria::create([
            'nombre' => 'Violin',
            'area_id' => Area::create(['nombre' => 'Musica'])->id,
            'profesor_id' => $this->profe->id,
        ]);

        /** @var Grupo $grupo */
        $grupo = $promotoria->grupos()->create([
            'nombre' => 'Grupo A', 'nivel' => 'basico', 'cupo_maximo' => 10, 'salon' => '',
        ]);
        $grupo->sesiones()->create(['dia' => 2, 'hora_inicio' => '08:00', 'hora_fin' => '10:00']);

        $this->grupo = $grupo;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function crearPerfil(string $username, string $rol): Perfil
    {
        $user = User::create(['username' => $username, 'password' => 'x', 'activo' => true]);

        return Perfil::create([
            'user_id' => $user->id,
            'rol' => $rol,
            'nombre_completo' => ucfirst($username),
            'fecha_nacimiento' => '1990-01-01',
            'telefono' => '3000000000',
        ]);
    }

    private function clasificar(string $fecha, ?string $causa): TestResponse
    {
        return $this->actingAs($this->admin->user)
            ->post(route('gestion-clasificar-omision'), array_filter([
                'grupo_id' => $this->grupo->id,
                'fecha' => $fecha,
                'causa' => $causa,
            ]));
    }

    private function falta(string $fecha): OmisionArchivada
    {
        return OmisionArchivada::create([
            'grupo_id' => $this->grupo->id, 'fecha' => $fecha, 'causa' => OmisionArchivada::FALTA,
        ]);
    }

    // -----------------------------------------------------------------------
    // La bandeja

    public function test_clasificar_guarda_la_causa_y_saca_la_alerta_de_la_bandeja(): void
    {
        $this->clasificar('2026-03-03', OmisionArchivada::FALTA)->assertSessionHas('success');

        $this->assertDatabaseHas('omisiones_archivadas', [
            'grupo_id' => $this->grupo->id,
            'causa' => OmisionArchivada::FALTA,
            'archivada_por_id' => $this->admin->id,
        ]);
        $this->assertCount(1, Alertas::clasesNoDictadas($this->periodo));
    }

    /** Sin causa no se atiende: es lo unico que este gesto añade. */
    public function test_sin_causa_o_con_una_inventada_no_se_guarda(): void
    {
        $this->clasificar('2026-03-03', null)->assertSessionHasErrors('causa');
        $this->clasificar('2026-03-03', 'vacaciones')->assertSessionHasErrors('causa');

        $this->assertSame(0, OmisionArchivada::count());
    }

    /**
     * Una causa equivocada se CORRIGE desde la misma bandeja, en «Ya
     * atendidas»: sin esa puerta, un «Falta» pulsado por error dejaria al
     * profesor con una perdida y una reposicion que no le tocan.
     */
    public function test_una_causa_se_corrige_y_la_bandeja_ensena_las_atendidas(): void
    {
        $this->clasificar('2026-03-03', OmisionArchivada::FALTA);
        $this->clasificar('2026-03-03', OmisionArchivada::EXCUSA);

        $this->assertSame(1, OmisionArchivada::count());
        $this->assertSame(OmisionArchivada::EXCUSA, OmisionArchivada::first()->causa);

        $this->actingAs($this->admin->user)->get(route('gestion-cancelaciones'))
            ->assertOk()
            ->assertSee('id="omisiones-atendidas"', false);
    }

    // -----------------------------------------------------------------------
    // El Panel de quien dicta

    public function test_solo_la_falta_llega_al_panel_del_profesor(): void
    {
        $falta = $this->falta('2026-03-03');
        $excusa = OmisionArchivada::create([
            'grupo_id' => $this->grupo->id, 'fecha' => '2026-03-10', 'causa' => OmisionArchivada::EXCUSA,
        ]);

        $html = $this->actingAs($this->profe->user)->get(route('panel'))->assertOk()->getContent();

        $this->assertStringContainsString(route('panel-reponer-clase', $falta), $html);
        $this->assertStringNotContainsString(route('panel-reponer-clase', $excusa), $html);
    }

    /** Las archivadas de antes, sin causa, no le piden reponer nada a nadie. */
    public function test_una_archivada_sin_causa_no_llega_al_panel(): void
    {
        $vieja = OmisionArchivada::create(['grupo_id' => $this->grupo->id, 'fecha' => '2026-03-03']);

        $this->actingAs($this->profe->user)->get(route('panel'))
            ->assertOk()
            ->assertDontSee(route('panel-reponer-clase', $vieja), false);
    }

    // -----------------------------------------------------------------------
    // Reponer

    public function test_reponer_registra_la_clase_y_la_enlaza_con_la_falta(): void
    {
        $falta = $this->falta('2026-03-03');

        $respuesta = $this->actingAs($this->profe->user)->post(route('panel-reponer-clase', $falta));

        $clase = Clase::sole();
        $respuesta->assertRedirect(route('clase-asistencia', $clase));
        $this->assertSame($clase->id, $falta->refresh()->repuesta_en_id);
        $this->assertSame($this->profe->id, $clase->registrada_por_id);

        // Repuesta, se va del Panel.
        $this->actingAs($this->profe->user)->get(route('panel'))
            ->assertDontSee(route('panel-reponer-clase', $falta), false);
    }

    /** El doble toque no deja dos clases para una falta. */
    public function test_reponer_dos_veces_no_crea_dos_clases(): void
    {
        $falta = $this->falta('2026-03-03');

        $this->actingAs($this->profe->user)->post(route('panel-reponer-clase', $falta));
        $this->actingAs($this->profe->user)->post(route('panel-reponer-clase', $falta))
            ->assertRedirect(route('clase-asistencia', Clase::sole()));
    }

    /** Solo quien dicta: la misma puerta que «Iniciar clase». */
    public function test_reponer_es_de_quien_dicta(): void
    {
        $falta = $this->falta('2026-03-03');
        $otro = $this->crearPerfil('otro', 'profesor');

        foreach ([$otro, $this->admin] as $quien) {
            $this->actingAs($quien->user)->post(route('panel-reponer-clase', $falta))
                ->assertRedirect(route('panel'));
        }

        $this->assertSame(0, Clase::count());
        $this->assertNull($falta->refresh()->repuesta_en_id);
    }

    public function test_solo_se_repone_una_falta(): void
    {
        $excusa = OmisionArchivada::create([
            'grupo_id' => $this->grupo->id, 'fecha' => '2026-03-03', 'causa' => OmisionArchivada::EXCUSA,
        ]);

        $this->actingAs($this->profe->user)->post(route('panel-reponer-clase', $excusa))->assertNotFound();
        $this->assertSame(0, Clase::count());
    }

    /**
     * UNA REPOSICION NO ES LA CLASE DE SU DIA. Reponer el martes 10 la falta
     * del 03, y no dar la del 10: la del 10 tiene que seguir saliendo como no
     * dictada, o la reposicion taparia una falta con otra.
     */
    public function test_una_reposicion_no_tapa_la_falta_del_dia_en_que_se_da(): void
    {
        $falta = $this->falta('2026-03-03');

        Carbon::setTestNow(Carbon::parse('2026-03-10 14:00:00'));
        $this->actingAs($this->profe->user)->post(route('panel-reponer-clase', $falta));

        Carbon::setTestNow(Carbon::parse('2026-03-11 10:00:00'));
        $fechas = Alertas::clasesNoDictadas($this->periodo)
            ->map(fn ($f) => $f['fecha']->toDateString())->all();

        $this->assertSame(['2026-03-10'], $fechas);
    }

    /** Y tampoco es la clase de HOY para «Iniciar clase». */
    public function test_iniciar_clase_el_dia_de_una_reposicion_abre_otra(): void
    {
        $falta = $this->falta('2026-03-03');

        $this->actingAs($this->profe->user)->post(route('panel-reponer-clase', $falta));
        $this->actingAs($this->profe->user)->post(route('panel-clase-nueva', $this->grupo));

        $this->assertSame(2, Clase::count());
    }

    /** Quien supervisa ve que esa clase es una reposicion y de que dia. */
    public function test_las_clases_del_grupo_dicen_que_es_una_reposicion(): void
    {
        $falta = $this->falta('2026-03-03');
        $this->actingAs($this->profe->user)->post(route('panel-reponer-clase', $falta));

        $this->actingAs($this->admin->user)->get(route('grupo-clases', $this->grupo))
            ->assertOk()
            ->assertSee('Reposición del 03/03/2026');
    }
}
