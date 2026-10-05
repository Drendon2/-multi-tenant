<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Clase;
use App\Models\ConfiguracionInstitucion;
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

    // -----------------------------------------------------------------------
    // Festivo para todos

    /** Un segundo grupo con clase el dia que se le diga (ISO: 2 = martes). */
    private function otroGrupo(int $dia): Grupo
    {
        /** @var Grupo $grupo */
        $grupo = $this->grupo->promotoria->grupos()->create([
            'nombre' => 'Grupo '.$dia, 'nivel' => 'basico', 'cupo_maximo' => 10, 'salon' => '',
        ]);
        $grupo->sesiones()->create(['dia' => $dia, 'hora_inicio' => '14:00', 'hora_fin' => '16:00']);

        return $grupo;
    }

    private function festivo(string $fecha): TestResponse
    {
        return $this->actingAs($this->admin->user)
            ->post(route('gestion-marcar-festivo'), ['fecha_festivo' => $fecha]);
    }

    public function test_el_festivo_marca_a_todos_los_grupos_de_ese_dia_y_solo_a_esos(): void
    {
        $martes = $this->otroGrupo(2);
        $miercoles = $this->otroGrupo(3);

        $this->festivo('2026-03-10')->assertSessionHas('success');

        foreach ([$this->grupo, $martes] as $grupo) {
            $this->assertDatabaseHas('omisiones_archivadas', [
                'grupo_id' => $grupo->id, 'causa' => OmisionArchivada::INSTITUCION,
            ]);
        }
        $this->assertDatabaseMissing('omisiones_archivadas', ['grupo_id' => $miercoles->id]);
        // Y el martes 10 sale de la bandeja; el 03 y el miercoles 04 siguen.
        $fechas = Alertas::clasesNoDictadas($this->periodo)->map(fn ($f) => $f['fecha']->toDateString())->all();
        $this->assertNotContains('2026-03-10', $fechas);
        $this->assertContains('2026-03-03', $fechas);
    }

    /** Lo que ya tiene causa fue una decision sobre una persona: no se pisa. */
    public function test_el_festivo_no_pisa_una_falta_ya_puesta(): void
    {
        $this->falta('2026-03-10');

        $this->festivo('2026-03-10');

        $this->assertSame(OmisionArchivada::FALTA, OmisionArchivada::sole()->causa);
    }

    /** Quien SI dio clase ese dia no tiene omision que marcar. */
    public function test_el_festivo_no_marca_a_quien_dio_clase(): void
    {
        Clase::create([
            'grupo_id' => $this->grupo->id, 'periodo_id' => $this->periodo->id,
            'fecha_hora' => '2026-03-10 08:00:00', 'registrada_por_id' => $this->profe->id,
        ]);

        $this->festivo('2026-03-10');

        $this->assertSame(0, OmisionArchivada::count());
    }

    /** Un festivo que todavia no llega: la alerta no llega a salir. */
    public function test_un_festivo_futuro_evita_la_alerta(): void
    {
        $this->festivo('2026-03-17')->assertSessionHas('success');

        Carbon::setTestNow(Carbon::parse('2026-03-18 10:00:00'));

        $fechas = Alertas::clasesNoDictadas($this->periodo)->map(fn ($f) => $f['fecha']->toDateString())->all();
        $this->assertNotContains('2026-03-17', $fechas);
    }

    public function test_el_festivo_tiene_que_caer_dentro_del_periodo(): void
    {
        $this->festivo('2026-08-04')->assertSessionHasErrors('fecha_festivo');
        $this->festivo('')->assertSessionHasErrors('fecha_festivo');

        $this->assertSame(0, OmisionArchivada::count());
    }

    // -----------------------------------------------------------------------
    // El plazo para reponer

    private function vencida(): bool
    {
        return (bool) Alertas::clasesNoDictadas($this->periodo, conArchivadas: true)
            ->firstWhere(fn ($f) => $f['fecha']->toDateString() === '2026-03-03')['vencida'];
    }

    /**
     * Cuenta desde que se CLASIFICO, no desde el dia de la clase: el 11/03 con
     * quince dias, el ultimo dia es el 26 y el 27 ya esta vencida.
     */
    public function test_la_falta_vence_cuando_pasa_el_plazo_desde_que_se_marco(): void
    {
        ConfiguracionInstitucion::actual()->update(['dias_para_reponer' => 15]);
        $this->clasificar('2026-03-03', OmisionArchivada::FALTA);

        Carbon::setTestNow(Carbon::parse('2026-03-26 18:00:00'));
        $this->assertFalse($this->vencida());

        Carbon::setTestNow(Carbon::parse('2026-03-27 08:00:00'));
        $this->assertTrue($this->vencida());

        $this->actingAs($this->profe->user)->get(route('panel'))
            ->assertOk()
            ->assertSee('estado-rechazada', false);
    }

    /** Volver a pulsar «Falta» no regala otros quince dias. */
    public function test_volver_a_marcar_falta_no_reinicia_el_plazo(): void
    {
        ConfiguracionInstitucion::actual()->update(['dias_para_reponer' => 15]);
        $this->clasificar('2026-03-03', OmisionArchivada::FALTA);

        Carbon::setTestNow(Carbon::parse('2026-03-25 10:00:00'));
        $this->clasificar('2026-03-03', OmisionArchivada::FALTA);

        $this->assertSame('2026-03-11', OmisionArchivada::sole()->clasificada_en->toDateString());
    }

    public function test_repuesta_o_sin_plazo_no_vence(): void
    {
        ConfiguracionInstitucion::actual()->update(['dias_para_reponer' => 15]);
        $falta = $this->falta('2026-03-03');
        $falta->update(['clasificada_en' => '2026-03-03 10:00:00']);
        // Pasado el plazo (el 18): sin reponer, estaria vencida.
        Carbon::setTestNow(Carbon::parse('2026-03-25 10:00:00'));
        $this->assertTrue($this->vencida());

        $this->actingAs($this->profe->user)->post(route('panel-reponer-clase', $falta));
        $this->assertFalse($this->vencida());

        $falta->update(['repuesta_en_id' => null]);
        ConfiguracionInstitucion::actual()->update(['dias_para_reponer' => null]);
        $this->assertFalse($this->vencida());
    }

    /** Vaciar el campo es «sin plazo»: NULL, no cero. */
    public function test_vaciar_el_plazo_lo_deja_sin_plazo(): void
    {
        $this->actingAs($this->admin->user)
            ->post(route('gestion-configuracion'), [
                'seccion' => 'alertas',
                'nombre_institucion' => 'Casa de la Cultura',
                'color_acento' => '#0a7a59',
                'limite_promotorias_por_periodo' => 2,
                'faltas_para_abandono' => 5,
                'dias_para_reponer' => '',
            ])
            ->assertSessionHas('success');

        $this->assertNull(ConfiguracionInstitucion::actual()->fresh()->dias_para_reponer);
    }

    // -----------------------------------------------------------------------
    // Clasificar varias de una vez

    /** @param  list<string>  $omisiones */
    private function lote(array $omisiones, ?string $causa): TestResponse
    {
        return $this->actingAs($this->admin->user)
            ->post(route('gestion-clasificar-omisiones-lote'), array_filter([
                'omisiones' => $omisiones,
                'causa' => $causa,
            ]));
    }

    public function test_el_lote_clasifica_todas_las_marcadas(): void
    {
        $id = $this->grupo->id;

        $this->lote(["{$id}|2026-03-03", "{$id}|2026-03-10"], OmisionArchivada::EXCUSA)
            ->assertSessionHas('success');

        $this->assertSame(2, OmisionArchivada::where('causa', OmisionArchivada::EXCUSA)->count());
        $this->assertCount(0, Alertas::clasesNoDictadas($this->periodo));
    }

    /**
     * Las archivadas de antes, «Sin clasificar», se clasifican por el mismo
     * lote: es la razon por la que se pidio.
     */
    public function test_el_lote_clasifica_las_archivadas_sin_causa(): void
    {
        OmisionArchivada::create(['grupo_id' => $this->grupo->id, 'fecha' => '2026-03-03']);

        $this->lote([$this->grupo->id.'|2026-03-03'], OmisionArchivada::FALTA);

        $vieja = OmisionArchivada::sole();
        $this->assertSame(OmisionArchivada::FALTA, $vieja->causa);
        $this->assertNotNull($vieja->clasificada_en);
    }

    /** Pasa por la misma puerta que la fila: el lote no reinicia el plazo. */
    public function test_el_lote_no_reinicia_el_plazo_de_una_falta(): void
    {
        $this->clasificar('2026-03-03', OmisionArchivada::FALTA);

        Carbon::setTestNow(Carbon::parse('2026-03-25 10:00:00'));
        $this->lote([$this->grupo->id.'|2026-03-03'], OmisionArchivada::FALTA);

        $this->assertSame('2026-03-11', OmisionArchivada::sole()->clasificada_en->toDateString());
    }

    /** Lo mal formado se queda fuera en silencio; sin nada valido, se dice. */
    public function test_el_lote_ignora_lo_mal_formado_y_exige_causa(): void
    {
        $this->lote(['basura', $this->grupo->id.'|2026-02-31', '|2026-03-03'], OmisionArchivada::EXCUSA)
            ->assertSessionHas('error');
        $this->lote([$this->grupo->id.'|2026-03-03'], null)->assertSessionHasErrors('causa');

        $this->assertSame(0, OmisionArchivada::count());
    }

    /** Con dos o más pendientes, la tabla trae las casillas y la barra. */
    public function test_la_bandeja_pinta_las_casillas_del_lote(): void
    {
        $this->actingAs($this->admin->user)->get(route('gestion-cancelaciones'))
            ->assertOk()
            ->assertSee('data-lote-tabla="lote-omisiones"', false)
            ->assertSee('value="'.$this->grupo->id.'|2026-03-10"', false);
    }
}
