<?php

namespace Tests\Feature;

use App\Models\Institucion;
use App\Models\Operador;
use App\Models\Perfil;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * El panel de todas las instituciones (paso 4b, 02/10/2026): solo en su host,
 * solo para operadores, y lo que hace es la direccion y el estado de cada
 * institucion.
 */
class PanelDeInstitucionesTest extends TestCase
{
    use RefreshDatabase;

    private const PANEL = 'http://panel.localhost';

    private const CASA = 'http://santuario.localhost';

    private Operador $operador;

    private Institucion $otra;

    protected function setUp(): void
    {
        parent::setUp();

        config(['institucion.dominio_base' => 'localhost']);
        $this->institucionDePrueba->update(['subdominio' => 'santuario']);
        $this->otra = Institucion::create(['nombre' => 'Casa de Guarne', 'subdominio' => 'guarne']);

        $this->operador = Operador::create([
            'usuario' => 'soporte',
            'nombre' => 'Soporte',
            'password' => 'clave-del-operador',
            'activo' => true,
        ]);
    }

    public function test_el_panel_solo_existe_en_su_host(): void
    {
        $this->get(self::PANEL.'/instituciones/entrar')->assertOk()->assertSee('Panel de instituciones');

        // En el host de una institucion, no existe.
        $this->get(self::CASA.'/instituciones/entrar')->assertNotFound();
        $this->actingAs($this->operador, 'operador')
            ->get(self::CASA.'/instituciones')
            ->assertNotFound();
    }

    public function test_sin_dominio_base_no_hay_panel(): void
    {
        config(['institucion.dominio_base' => null]);

        $this->get(self::PANEL.'/instituciones/entrar')->assertNotFound();
    }

    /** Lo que no es del panel, en su host, lleva a su portada. */
    public function test_en_el_host_del_panel_lo_demas_lleva_al_panel(): void
    {
        $this->get(self::PANEL.'/')->assertRedirect(self::PANEL.'/instituciones');
        $this->get(self::PANEL.'/entrar')->assertRedirect(self::PANEL.'/instituciones');
    }

    public function test_sin_sesion_lleva_al_login_del_panel(): void
    {
        $this->get(self::PANEL.'/instituciones')
            ->assertRedirect(self::PANEL.'/instituciones/entrar');
    }

    public function test_un_operador_entra_y_ve_todas_las_instituciones(): void
    {
        $this->post(self::PANEL.'/instituciones/entrar', ['usuario' => 'soporte', 'password' => 'clave-del-operador'])
            ->assertRedirect(self::PANEL.'/instituciones');
        $this->assertAuthenticatedAs($this->operador, 'operador');
        $this->assertNotNull($this->operador->fresh()->ultimo_acceso);

        $this->get(self::PANEL.'/instituciones')
            ->assertOk()
            ->assertSee('Casa de Guarne')
            ->assertSee('guarne.localhost')
            ->assertSee('santuario.localhost');
    }

    public function test_no_entra_con_clave_mala_ni_desactivado(): void
    {
        $this->post(self::PANEL.'/instituciones/entrar', ['usuario' => 'soporte', 'password' => 'otra'])
            ->assertSessionHasErrors('usuario');
        $this->assertGuest('operador');

        $this->operador->update(['activo' => false]);
        $this->post(self::PANEL.'/instituciones/entrar', ['usuario' => 'soporte', 'password' => 'clave-del-operador'])
            ->assertSessionHasErrors('usuario');
        $this->assertGuest('operador');
    }

    /** Desactivarlo echa tambien a quien ya estaba dentro. */
    public function test_un_operador_desactivado_sale_del_panel(): void
    {
        $this->actingAs($this->operador, 'operador');
        $this->operador->update(['activo' => false]);

        $this->get(self::PANEL.'/instituciones')->assertRedirect(self::PANEL.'/instituciones/entrar');
        $this->assertGuest('operador');
    }

    /**
     * Las cuentas de una institucion no son operadores, ni siquiera su
     * administrador: son otra tabla.
     */
    public function test_el_administrador_de_una_institucion_no_entra_al_panel(): void
    {
        $user = User::create(['username' => 'admin', 'password' => 'clave-del-admin', 'activo' => true]);
        Perfil::create([
            'user_id' => $user->id,
            'rol' => 'administrador',
            'nombre_completo' => 'Admin',
            'fecha_nacimiento' => Carbon::today()->subYears(30)->toDateString(),
            'telefono' => '3000000000',
        ]);

        $this->post(self::PANEL.'/instituciones/entrar', ['usuario' => 'admin', 'password' => 'clave-del-admin'])
            ->assertSessionHasErrors('usuario');
        $this->assertGuest('operador');

        $this->actingAs($user)->get(self::PANEL.'/instituciones')
            ->assertRedirect(self::PANEL.'/instituciones/entrar');
    }

    public function test_asignar_un_subdominio_hace_que_se_entre_por_el(): void
    {
        $this->actingAs($this->operador, 'operador')
            ->post(self::PANEL.'/instituciones/'.$this->otra->id, [
                'subdominio' => '  Rionegro ',
                'dominio_propio' => '',
                'estado' => 'activa',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(self::PANEL.'/instituciones/'.$this->otra->id);

        $this->assertSame('rionegro', $this->otra->fresh()->subdominio);
        $this->assertNull($this->otra->fresh()->dominio_propio);

        $this->salirDelPanel();
        $this->get('http://rionegro.localhost/entrar')->assertOk()->assertSee('Iniciar sesión');
        $this->get('http://guarne.localhost/entrar')->assertNotFound();
    }

    public function test_un_dominio_propio_se_guarda_y_sirve(): void
    {
        $this->actingAs($this->operador, 'operador')
            ->post(self::PANEL.'/instituciones/'.$this->otra->id, [
                'subdominio' => 'guarne',
                'dominio_propio' => 'Matriculas.Guarne.gov.co',
                'estado' => 'activa',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('matriculas.guarne.gov.co', $this->otra->fresh()->dominio_propio);
        $this->salirDelPanel();
        $this->get('http://matriculas.guarne.gov.co/entrar')->assertOk();
    }

    /**
     * Cada rechazo, con su motivo: si alguno se colara, la base lo pararia
     * con un 500 (los CHECK de `03-dominios.sql` y `04-panel.sql`), o peor,
     * se guardaria una direccion por la que nadie puede entrar.
     */
    public function test_rechaza_las_direcciones_que_no_valen(): void
    {
        $casos = [
            ['subdominio' => 'panel'],
            ['subdominio' => 'www'],
            ['subdominio' => 'a.b'],
            ['subdominio' => '-guarne'],
            ['subdominio' => 'guárne'],
            ['subdominio' => 'santuario'],
            ['subdominio' => '', 'dominio_propio' => ''],
            ['dominio_propio' => 'https://guarne.gov.co'],
            ['dominio_propio' => 'guarne.gov.co:8080'],
            ['dominio_propio' => 'otra.localhost'],
        ];

        foreach ($casos as $caso) {
            $datos = $caso + ['subdominio' => 'guarne', 'dominio_propio' => '', 'estado' => 'activa'];
            $campo = array_key_first($caso);

            $this->actingAs($this->operador, 'operador')
                ->post(self::PANEL.'/instituciones/'.$this->otra->id, $datos)
                ->assertSessionHasErrors($campo);
        }

        $this->assertSame('guarne', $this->otra->fresh()->subdominio);
    }

    public function test_un_dominio_propio_no_se_repite(): void
    {
        $this->institucionDePrueba->update(['dominio_propio' => 'matriculas.santuario.gov.co']);

        $this->actingAs($this->operador, 'operador')
            ->post(self::PANEL.'/instituciones/'.$this->otra->id, [
                'subdominio' => 'guarne',
                'dominio_propio' => 'matriculas.santuario.gov.co',
                'estado' => 'activa',
            ])
            ->assertSessionHasErrors('dominio_propio');
    }

    public function test_suspender_y_reactivar(): void
    {
        $guardar = fn (string $estado) => $this->actingAs($this->operador, 'operador')
            ->post(self::PANEL.'/instituciones/'.$this->otra->id, [
                'subdominio' => 'guarne',
                'dominio_propio' => '',
                'estado' => $estado,
            ])
            ->assertSessionHasNoErrors();

        $guardar('suspendida');
        $this->salirDelPanel();
        $this->get('http://guarne.localhost/entrar')->assertStatus(503);
        $this->get(self::CASA.'/entrar')->assertOk();

        $guardar('activa');
        $this->salirDelPanel();
        $this->get('http://guarne.localhost/entrar')->assertOk();
    }

    /**
     * El panel no es de ninguna institucion: una tabla de datos, desde aqui,
     * no devuelve nada. Lo que el panel lee no tiene RLS.
     */
    public function test_desde_el_panel_no_se_ve_ninguna_fila_de_datos(): void
    {
        User::create(['username' => 'alguien', 'password' => 'x', 'activo' => true]);

        $vistas = null;
        $this->app['router']->get('/instituciones/sonda', function () use (&$vistas) {
            $vistas = DB::table('users')->count();

            return 'ok';
        })->middleware('operador');

        $this->get(self::PANEL.'/instituciones/sonda')->assertOk();
        $this->assertSame(0, $vistas);
    }

    /**
     * `actingAs($operador, 'operador')` deja `operador` como guard por defecto
     * el resto de la prueba, y el `guest` de `/entrar` lo leeria como sesion
     * abierta. En la vida real son dos hosts con dos cookies.
     */
    private function salirDelPanel(): void
    {
        $this->app['auth']->shouldUse('web');
    }

    public function test_el_comando_crea_y_desactiva_operadores(): void
    {
        $this->artisan('operador:crear', ['usuario' => 'nuevo', '--nombre' => 'Nuevo'])
            ->expectsQuestion('Contraseña (mínimo 12 caracteres)', 'una-clave-larga')
            ->expectsQuestion('Repítela', 'una-clave-larga')
            ->assertSuccessful();

        $this->post(self::PANEL.'/instituciones/entrar', ['usuario' => 'nuevo', 'password' => 'una-clave-larga'])
            ->assertSessionHasNoErrors();

        $this->artisan('operador:crear', ['usuario' => 'corto'])
            ->expectsQuestion('Contraseña (mínimo 12 caracteres)', 'corta')
            ->assertFailed();
        $this->assertNull(Operador::where('usuario', 'corto')->first());

        $this->artisan('operador:crear', ['usuario' => 'nuevo', '--desactivar' => true])->assertSuccessful();
        $this->assertFalse(Operador::where('usuario', 'nuevo')->sole()->activo);
    }
}
