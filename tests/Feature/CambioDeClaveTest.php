<?php

namespace Tests\Feature;

use App\Models\Perfil;
use App\Models\User;
use App\Support\GestionAsistida;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Cambiarse la propia contrasena, desde «Mi perfil».
 *
 * Pedido por el usuario el 05/09/2026, y hasta ese dia NO EXISTIA por ningun
 * camino: la unica forma de cambiar una clave era que un administrador la
 * escribiera. El campo de aquel formulario se llama «Contrasena temporal», o
 * sea que el nombre ya daba por hecho este otro lado y llevaba meses sin el.
 *
 * LO QUE ESTE ARCHIVO VIGILA:
 *
 * 1. Que pida la contrasena ACTUAL. Una sesion abierta en un celular prestado
 *    basta para llegar hasta aqui, y sin ese campo cualquiera que pase por
 *    delante del telefono de otro le quita la cuenta para siempre.
 * 2. Que no se pueda desde una gestion asistida, y que el corte este en el
 *    CONTROLADOR: esconder la seccion no cierra la peticion.
 * 3. Lo de siempre de un formulario: que las dos nuevas coincidan y que la
 *    nueva no sea la que ya tenias.
 *
 * LO QUE ESTE ARCHIVO NO PUEDE VIGILAR, y esta escrito en su sitio mas abajo:
 * que cambiar la clave no te EXPULSE. Es el fallo mas caro de este metodo y
 * PHPUnit no lo ve. Se comprueba con `curl`.
 */
class CambioDeClaveTest extends TestCase
{
    use RefreshDatabase;

    private const VIEJA = 'ClaveVieja2026';

    private const NUEVA = 'ClaveNueva2026';

    private Perfil $yo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->yo = $this->perfil('ana', 'estudiante', self::VIEJA);
    }

    /** El camino bueno: la cambia y la nueva es la que vale. */
    public function test_se_cambia_la_propia_contrasena(): void
    {
        $this->actingAs($this->yo->user)
            ->post(route('mi-perfil.guardar'), $this->formulario(self::VIEJA, self::NUEVA))
            ->assertRedirect(route('mi-perfil'))
            ->assertSessionHas('success');

        $this->yo->user->refresh();

        $this->assertTrue(Hash::check(self::NUEVA, $this->yo->user->password), 'no quedo guardada la nueva.');
        $this->assertFalse(Hash::check(self::VIEJA, $this->yo->user->password), 'la vieja sigue valiendo.');
    }

    /*
     * AQUI NO HAY UNA PRUEBA DE «cambiar la clave no te expulsa», Y ES A
     * PROPOSITO.
     *
     * Se escribio y se borro el 05/09/2026, porque no probaba nada. El fallo es
     * real y grave —guardar en `$perfil->user` en vez de en `$request->user()`
     * hace que `AuthenticateSession` te mande al login en la peticion siguiente,
     * sin fallar y sin aviso— pero PHPUnit no puede verlo: `actingAs()` deja el
     * usuario fijado en el guard durante todo el test, asi que la comparacion
     * del middleware no llega a fallar nunca.
     *
     * Se comprobo: con la instancia mala puesta, las siete pruebas de este
     * archivo pasan en verde. Una prueba que no se ve fallar no vale, y una que
     * ademas promete vigilar la trampa mas cara de este metodo es peor que no
     * tenerla — la proxima persona confiaria en ella.
     *
     * Se verifico con `curl`, en cuatro peticiones seguidas: entrar, abrir el
     * perfil, cambiar la clave y volver a pedir el perfil. Con la instancia mala
     * esa cuarta responde 302 al login; con la buena, 200. Si tocas
     * `guardarClave()`, repite eso — no te fies de este archivo.
     */

    /** Sin la contrasena actual no se cambia nada. */
    public function test_sin_la_contrasena_actual_no_se_cambia(): void
    {
        $this->actingAs($this->yo->user)
            ->post(route('mi-perfil.guardar'), $this->formulario('MeLaInvente2026', self::NUEVA))
            ->assertSessionHasErrors('clave_actual');

        $this->yo->user->refresh();
        $this->assertTrue(Hash::check(self::VIEJA, $this->yo->user->password), 'la cambio sin saber la actual.');
    }

    /** Y las dos nuevas tienen que coincidir. */
    public function test_si_la_confirmacion_no_coincide_no_se_cambia(): void
    {
        $datos = $this->formulario(self::VIEJA, self::NUEVA);
        $datos['password_confirmation'] = 'OtraCosa2026';

        $this->actingAs($this->yo->user)
            ->post(route('mi-perfil.guardar'), $datos)
            ->assertSessionHasErrors('password');

        $this->yo->user->refresh();
        $this->assertTrue(Hash::check(self::VIEJA, $this->yo->user->password));
    }

    /** Poner la misma que ya tenias no es un cambio. */
    public function test_la_nueva_tiene_que_ser_distinta(): void
    {
        $this->actingAs($this->yo->user)
            ->post(route('mi-perfil.guardar'), $this->formulario(self::VIEJA, self::VIEJA))
            ->assertSessionHasErrors('password');
    }

    /**
     * DESDE UNA GESTION ASISTIDA NO.
     *
     * El administrador entra en la cuenta de otro para ayudarle, no para
     * quedarse con ella: cambiarle la clave desde dentro la deja fuera de su
     * propia cuenta y sin forma de volver, porque en este sistema nada avisa a
     * nadie de nada.
     *
     * Se prueba por el CONTROLADOR y no mirando si la seccion se pinta:
     * esconderla no cierra la peticion, y esa es justo la mitad que se olvida.
     */
    public function test_desde_una_gestion_asistida_no_se_cambia_la_clave(): void
    {
        $admin = $this->perfil('jefa', 'administrador', 'LoQueSea2026');
        $profe = $this->perfil('profe', 'profesor', self::VIEJA);

        $this->actingAs($admin->user)->post(route('gestion-asistida-iniciar', $profe));
        $this->assertTrue(GestionAsistida::activa(), 'la sonda no vale: no entro en la asistida.');

        $this->post(route('mi-perfil.guardar'), $this->formulario(self::VIEJA, self::NUEVA))
            ->assertSessionHas('error');

        $profe->user->refresh();
        $this->assertTrue(
            Hash::check(self::VIEJA, $profe->user->password),
            'el administrador le cambio la contrasena desde su cuenta.'
        );
    }

    /** La pantalla ofrece cambiarla, y con su ojo puesto por el guion. */
    public function test_mi_perfil_ofrece_cambiar_la_contrasena(): void
    {
        $html = $this->actingAs($this->yo->user)->get(route('mi-perfil'))->assertOk()->getContent();

        $this->assertStringContainsString('Cambiar la contrase', $html);
        $this->assertStringContainsString('name="clave_actual"', $html);
        $this->assertStringContainsString('js/ver-clave.js', $html, 'sus campos se quedan sin ojo.');
    }

    /**
     * @return array<string, string>
     */
    private function formulario(string $actual, string $nueva): array
    {
        return [
            'accion' => 'clave',
            'clave_actual' => $actual,
            'password' => $nueva,
            'password_confirmation' => $nueva,
        ];
    }

    private function perfil(string $username, string $rol, string $clave): Perfil
    {
        $user = User::create(['username' => $username, 'password' => $clave, 'activo' => true]);

        return Perfil::create([
            'user_id' => $user->id,
            'rol' => $rol,
            'nombre_completo' => ucfirst($username),
            'fecha_nacimiento' => Carbon::today()->subYears(30)->toDateString(),
            'telefono' => '3000000000',
        ]);
    }

    /**
     * Al CAMBIARLA tambien, y ahi el usuario sale de la sesion: el formulario
     * de Mi perfil no lo trae.
     */
    public function test_la_nueva_no_puede_ser_comun_ni_el_usuario(): void
    {
        $largo = $this->perfil('anamaria2026', 'estudiante', self::VIEJA);

        foreach (['password123', 'AnaMaria2026'] as $mala) {
            $this->actingAs($largo->user)
                ->post(route('mi-perfil.guardar'), $this->formulario(self::VIEJA, $mala))
                ->assertSessionHasErrors('password');
        }

        $this->assertTrue(Hash::check(self::VIEJA, $largo->user->fresh()->password));
    }
}
