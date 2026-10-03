<?php

namespace Tests\Feature;

use App\Models\Actividad;
use App\Models\ConfiguracionInstitucion;
use App\Models\InstitucionExterna;
use App\Models\OmisionExterna;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\User;
use App\Support\Alertas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * La alerta semanal de los programas externos (03/10/2026).
 *
 * Una semana de lunes a domingo sin ninguna clase iniciada alla, entre las
 * fechas de clases de la institucion. Solo administracion.
 *
 * El reloj va fijo en el MIERCOLES 18/03/2026: las semanas que ya terminaron
 * son la del lunes 02/03 y la del lunes 09/03. La del 16 todavia no acaba.
 */
class AlertaSemanalExternaTest extends TestCase
{
    use RefreshDatabase;

    private Perfil $admin;

    private Perfil $profesor;

    private InstitucionExterna $escuela;

    private Actividad $programa;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-03-01 09:00:00'));

        Periodo::create([
            'nombre' => '2026-1', 'fecha_inicio' => '2026-02-01', 'fecha_fin' => '2026-06-30',
            'activo' => true, 'matriculas_abiertas' => true,
        ]);

        $this->admin = $this->perfil('jefa', 'administrador');
        $this->profesor = $this->perfil('profe', 'profesor');

        $this->escuela = InstitucionExterna::create([
            'nombre' => 'I. E. Rural El Carmen',
            'perfil_id' => $this->perfil('carmen', Perfil::INSTITUCION_EXTERNA)->id,
            'clases_desde' => '2026-03-02',
        ]);

        // Creado el 01/03: antes de la primera semana que cuenta.
        $this->programa = Actividad::create([
            'nombre' => 'Guitarra — El Carmen',
            'tipo' => Actividad::EXTERNO,
            'responsable_id' => $this->profesor->id,
            'institucion_externa_id' => $this->escuela->id,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-03-18 10:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function perfil(string $username, string $rol): Perfil
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

    private function clase(string $fecha, bool $iniciada = true): void
    {
        $sesion = $this->programa->sesiones()->create(['fecha' => $fecha]);

        if ($iniciada) {
            $sesion->iniciada_en = Carbon::parse($fecha.' 09:00:00');
            $sesion->save();
        }
    }

    /** @return list<string> */
    private function semanas(): array
    {
        return Alertas::semanasSinClaseExterna()
            ->map(fn ($s) => $s['semana']->toDateString())
            ->all();
    }

    // -----------------------------------------------------------------------
    // La cuenta

    public function test_avisa_de_cada_semana_terminada_sin_clase(): void
    {
        $this->assertSame(['2026-03-09', '2026-03-02'], $this->semanas());
    }

    public function test_una_clase_cualquier_dia_de_la_semana_la_cubre(): void
    {
        $this->clase('2026-03-14');

        $this->assertSame(['2026-03-02'], $this->semanas());
    }

    /** Una sesion creada y no iniciada no es una clase. */
    public function test_una_sesion_sin_iniciar_no_cuenta(): void
    {
        $this->clase('2026-03-04', iniciada: false);

        $this->assertContains('2026-03-02', $this->semanas());
    }

    /** Sin fecha de inicio la institucion no avisa: no empieza sola al desplegar. */
    public function test_sin_fecha_de_inicio_no_avisa(): void
    {
        $this->escuela->update(['clases_desde' => null]);

        $this->assertSame([], $this->semanas());
    }

    /** La primera semana que cuenta es la primera COMPLETA. */
    public function test_empezar_a_mitad_de_semana_no_cuenta_esa_semana(): void
    {
        $this->escuela->update(['clases_desde' => '2026-03-04']);

        $this->assertSame(['2026-03-09'], $this->semanas());
    }

    /** Y la ultima tambien: una semana que el fin parte por la mitad no avisa. */
    public function test_la_fecha_de_fin_detiene_la_alerta(): void
    {
        $this->escuela->update(['clases_hasta' => '2026-03-10']);

        $this->assertSame(['2026-03-02'], $this->semanas());
    }

    /** Antes de existir, un programa no puede faltar a nada. */
    public function test_un_programa_nuevo_cuenta_desde_que_existe(): void
    {
        $this->programa->created_at = Carbon::parse('2026-03-10 08:00:00');
        $this->programa->save();

        $this->assertSame([], $this->semanas());
    }

    // -----------------------------------------------------------------------
    // La bandeja

    public function test_clasificar_una_semana_la_saca_y_se_guarda_por_su_lunes(): void
    {
        $this->actingAs($this->admin->user)
            ->post(route('gestion-clasificar-semana-externa'), [
                'actividad_id' => $this->programa->id,
                'semana' => '2026-03-11',
                'causa' => 'excusa',
            ])
            ->assertSessionHas('success');

        $this->assertSame('2026-03-09', OmisionExterna::sole()->semana->toDateString());
        $this->assertSame(['2026-03-02'], $this->semanas());
    }

    public function test_la_bandeja_la_ensena_solo_a_administracion(): void
    {
        $this->actingAs($this->admin->user)->get(route('gestion-cancelaciones'))
            ->assertOk()
            ->assertSee(route('gestion-clasificar-semana-externa'), false);

        $director = $this->perfil('dire', 'director');

        $this->actingAs($director->user)->get(route('gestion-cancelaciones'))
            ->assertOk()
            ->assertDontSee(route('gestion-clasificar-semana-externa'), false);

        $this->actingAs($director->user)
            ->post(route('gestion-clasificar-semana-externa'), [
                'actividad_id' => $this->programa->id, 'semana' => '2026-03-09', 'causa' => 'excusa',
            ])
            ->assertNotFound();
        $this->assertSame(0, OmisionExterna::count());
    }

    /** Solo programas externos: un taller no tiene esta alerta. */
    public function test_no_se_clasifica_una_semana_de_otro_tipo_de_actividad(): void
    {
        $taller = Actividad::create([
            'nombre' => 'Taller', 'tipo' => Actividad::TALLER, 'responsable_id' => $this->profesor->id,
        ]);

        $this->actingAs($this->admin->user)
            ->post(route('gestion-clasificar-semana-externa'), [
                'actividad_id' => $taller->id, 'semana' => '2026-03-09', 'causa' => 'excusa',
            ])
            ->assertNotFound();
    }

    /** Mismo interruptor que la alerta de los grupos. */
    public function test_con_la_alerta_apagada_no_sale(): void
    {
        $config = ConfiguracionInstitucion::actual();
        $config->alerta_clase_no_dictada = false;
        $config->save();

        $this->actingAs($this->admin->user)->get(route('gestion-cancelaciones'))
            ->assertOk()
            ->assertDontSee(route('gestion-clasificar-semana-externa'), false);
    }

    // -----------------------------------------------------------------------
    // Las fechas en la institucion

    public function test_la_institucion_guarda_sus_fechas_y_no_acepta_un_fin_antes_del_inicio(): void
    {
        $this->actingAs($this->admin->user)->get(route('institucion-externa-nueva'))
            ->assertOk()
            ->assertSee('type="date" name="clases_desde"', false)
            ->assertSee('type="date" name="clases_hasta"', false);

        $datos = [
            'nombre' => 'Colegio San José',
            // Vacios y no ausentes: el navegador manda los campos opcionales.
            'direccion' => '',
            'telefono' => '',
            'funcionario' => 'Rectora Gómez',
            'username' => 'sanjose',
            'password' => 'una-clave-larga',
        ];

        $this->actingAs($this->admin->user)
            ->post(route('institucion-externa-nueva'), $datos + [
                'clases_desde' => '2026-04-01', 'clases_hasta' => '2026-03-01',
            ])
            ->assertSessionHasErrors('clases_hasta');

        $this->actingAs($this->admin->user)
            ->post(route('institucion-externa-nueva'), $datos + [
                'clases_desde' => '2026-04-01', 'clases_hasta' => '2026-11-30',
            ])
            ->assertSessionHas('success');

        $nueva = InstitucionExterna::where('nombre', 'Colegio San José')->sole();
        $this->assertSame('2026-04-01', $nueva->clases_desde?->toDateString());
        $this->assertSame('2026-11-30', $nueva->clases_hasta?->toDateString());
    }
}
