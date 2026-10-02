<?php

namespace Tests\Feature;

use App\Models\ConfiguracionInstitucion;
use App\Models\Institucion;
use App\Models\Operador;
use App\Models\Perfil;
use App\Models\Suplantacion as Fila;
use App\Models\User;
use App\Support\InstitucionActual;
use App\Support\Suplantacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Entrar a una institucion desde el panel como uno de sus administradores
 * (paso 4c, 02/10/2026). El panel deja un token de un solo uso y la
 * institucion lo canjea en su propio dominio.
 */
class SuplantacionTest extends TestCase
{
    use RefreshDatabase;

    private const PANEL = 'http://panel.localhost';

    private const CASA = 'http://santuario.localhost';

    private const OTRA = 'http://guarne.localhost';

    private Operador $operador;

    private Institucion $otra;

    private Perfil $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['institucion.dominio_base' => 'localhost']);
        Institucion::findOrFail(1)->update(['subdominio' => 'santuario']);
        $this->otra = Institucion::create(['nombre' => 'Casa de Guarne', 'subdominio' => 'guarne']);

        $this->admin = InstitucionActual::mientras($this->otra->id, function () {
            ConfiguracionInstitucion::actual()->update(['nombre_institucion' => 'Casa de Guarne']);

            return $this->persona('admin.guarne', 'administrador', 'Admin de Guarne');
        });

        $this->operador = Operador::create([
            'usuario' => 'soporte', 'nombre' => 'Soporte', 'password' => 'clave-del-operador', 'activo' => true,
        ]);
    }

    public function test_el_operador_entra_como_el_administrador_y_lo_ve_dicho(): void
    {
        $enlace = $this->emitir($this->admin)->headers->get('Location');
        $this->assertStringStartsWith(self::OTRA.'/suplantacion/', (string) $enlace);

        $this->salirDelPanel();
        $this->get($enlace)->assertRedirect(route('post-login'));
        $this->assertAuthenticatedAs($this->admin->user);

        $this->get(self::OTRA.'/mi-perfil')
            ->assertOk()
            ->assertSee('Desde el panel de instituciones')
            ->assertSee('Soporte')
            ->assertSee('Casa de Guarne');

        $fila = InstitucionActual::mientras($this->otra->id, fn () => Fila::sole());
        $this->assertNotNull($fila->usado_en);
        $this->assertSame($this->operador->id, $fila->operador_id);
    }

    public function test_el_token_sirve_una_sola_vez(): void
    {
        $enlace = $this->emitir($this->admin)->headers->get('Location');
        $this->salirDelPanel();

        $this->get($enlace)->assertRedirect(route('post-login'));
        $this->post(self::OTRA.'/salir');
        $this->assertGuest();

        $this->get($enlace)->assertRedirect(route('login'))->assertSessionHas('error');
        $this->assertGuest();
    }

    /**
     * El token es de SU institucion: en el dominio de otra, RLS ni lo
     * encuentra. Y no se gasta: despues sirve en el suyo.
     */
    public function test_el_token_no_sirve_en_el_dominio_de_otra_institucion(): void
    {
        $enlace = (string) $this->emitir($this->admin)->headers->get('Location');
        $this->salirDelPanel();

        $this->get(str_replace(self::OTRA, self::CASA, $enlace))->assertSessionHas('error');
        $this->assertGuest();

        $this->get($enlace)->assertRedirect(route('post-login'));
        $this->assertAuthenticatedAs($this->admin->user);
    }

    public function test_el_token_caduca_al_minuto(): void
    {
        $enlace = $this->emitir($this->admin)->headers->get('Location');
        $this->salirDelPanel();

        InstitucionActual::mientras($this->otra->id, fn () => DB::table('suplantaciones')
            ->update(['created_at' => Carbon::now()->subSeconds(Suplantacion::VALIDEZ_SEGUNDOS + 30)]));

        $this->get($enlace)->assertSessionHas('error');
        $this->assertGuest();
    }

    /** Solo a administradores; desde ahi, la gestion asistida. */
    public function test_solo_se_entra_como_un_administrador(): void
    {
        $profesor = InstitucionActual::mientras($this->otra->id, fn () => $this->persona('profe.guarne', 'profesor', 'Profe de Guarne'));

        $this->emitir($profesor)
            ->assertRedirect(self::PANEL.'/instituciones/'.$this->otra->id)
            ->assertSessionHas('error');

        $this->assertSame(0, InstitucionActual::mientras($this->otra->id, fn () => Fila::count()));
    }

    /** Si deja de ser administrador entre emitir y canjear, no se entra. */
    public function test_no_entra_si_la_cuenta_se_desactivo_en_medio(): void
    {
        $enlace = $this->emitir($this->admin)->headers->get('Location');
        $this->salirDelPanel();

        InstitucionActual::mientras($this->otra->id, fn () => $this->admin->user->update(['activo' => false]));

        $this->get($enlace)->assertSessionHas('error');
        $this->assertGuest();
    }

    /**
     * Decision del usuario: suspendida, la institucion no atiende a nadie,
     * pero el soporte entra. Y al salir vuelve a no atender.
     */
    public function test_se_entra_aunque_la_institucion_este_suspendida(): void
    {
        $this->otra->update(['estado' => Institucion::SUSPENDIDA]);
        $enlace = $this->emitir($this->admin)->headers->get('Location');
        $this->salirDelPanel();

        $this->get($enlace)->assertRedirect(route('post-login'));
        $this->get(self::OTRA.'/mi-perfil')
            ->assertOk()
            ->assertSee('La institución está suspendida');

        $this->post(self::OTRA.'/suplantacion/salir')->assertRedirect(self::PANEL.'/instituciones');
        $this->assertGuest();
        $this->get(self::OTRA.'/entrar')->assertStatus(503);
    }

    public function test_desde_el_panel_no_se_cambia_la_contrasena(): void
    {
        $enlace = $this->emitir($this->admin)->headers->get('Location');
        $this->salirDelPanel();
        $this->get($enlace);

        $this->post(self::OTRA.'/mi-perfil', [
            'accion' => 'clave',
            'clave_actual' => 'clave-del-admin',
            'password' => 'otra-clave-nueva-1',
            'password_confirmation' => 'otra-clave-nueva-1',
        ])->assertSessionHas('error');

        $this->assertTrue(Hash::check('clave-del-admin', InstitucionActual::mientras(
            $this->otra->id,
            fn () => User::findOrFail($this->admin->user_id)->password
        )));

        $this->get(self::OTRA.'/mi-perfil')->assertSee('entrando desde el panel de instituciones');
    }

    public function test_volver_al_panel_cierra_la_sesion_de_la_institucion(): void
    {
        $enlace = $this->emitir($this->admin)->headers->get('Location');
        $this->salirDelPanel();
        $this->get($enlace);

        $this->post(self::OTRA.'/suplantacion/salir')->assertRedirect(self::PANEL.'/instituciones');
        $this->assertGuest();
        $this->get(self::OTRA.'/mi-perfil')->assertRedirect(route('login'));
    }

    /** Sin suplantacion, la barra no sale. */
    public function test_la_barra_solo_sale_entrando_desde_el_panel(): void
    {
        $this->actingAs($this->admin->user)
            ->get(self::OTRA.'/mi-perfil')
            ->assertOk()
            ->assertDontSee('Desde el panel de instituciones');
    }

    /**
     * La ficha del panel ofrece a los administradores de ESA institucion, y a
     * nadie de otra.
     */
    public function test_el_panel_ofrece_los_administradores_de_esa_institucion(): void
    {
        $this->persona('admin.santuario', 'administrador', 'Admin de El Santuario');

        $this->actingAs($this->operador, 'operador')
            ->get(self::PANEL.'/instituciones/'.$this->otra->id)
            ->assertOk()
            ->assertSee('Admin de Guarne')
            ->assertDontSee('Admin de El Santuario');
    }

    /** El panel mira una institucion con `mientras()` y vuelve a ser de ninguna. */
    public function test_mientras_devuelve_al_panel_a_ninguna_institucion(): void
    {
        InstitucionActual::ninguna();
        InstitucionActual::mientras($this->otra->id, fn () => null);

        $this->assertNull(InstitucionActual::idSiSeSabe());
    }

    private function emitir(Perfil $perfil): TestResponse
    {
        return $this->actingAs($this->operador, 'operador')
            ->post(self::PANEL.'/instituciones/'.$this->otra->id.'/entrar-como/'.$perfil->id);
    }

    /**
     * `actingAs(..., 'operador')` deja ese guard por defecto el resto de la
     * prueba. En la vida real son dos hosts con dos cookies.
     */
    private function salirDelPanel(): void
    {
        $this->app['auth']->shouldUse('web');
        $this->app['auth']->guard('operador')->logout();
        $this->flushSession();
    }

    private function persona(string $usuario, string $rol, string $nombre): Perfil
    {
        $user = User::create(['username' => $usuario, 'password' => 'clave-del-admin', 'activo' => true]);

        return Perfil::create([
            'user_id' => $user->id,
            'rol' => $rol,
            'nombre_completo' => $nombre,
            'fecha_nacimiento' => Carbon::today()->subYears(30)->toDateString(),
            'telefono' => '3000000000',
        ])->setRelation('user', $user);
    }
}
