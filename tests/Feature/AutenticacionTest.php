<?php

namespace Tests\Feature;

use App\Models\Perfil;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Entrada al sistema: quien puede iniciar sesion y a donde llega cada rol.
 */
class AutenticacionTest extends TestCase
{
    use RefreshDatabase;

    private function crearCuenta(string $rol, string $username = 'ana', bool $activo = true): User
    {
        $user = User::create([
            'username' => $username,
            'password' => 'secreto123',
            'activo' => $activo,
        ]);

        Perfil::create([
            'user_id' => $user->id,
            'rol' => $rol,
            'nombre_completo' => 'Ana Ruiz',
            'fecha_nacimiento' => '2000-05-01',
            'telefono' => '3000000000',
        ]);

        return $user->refresh();
    }

    public function test_se_entra_con_usuario_y_contrasena(): void
    {
        $this->crearCuenta('estudiante');

        $respuesta = $this->post(route('login.entrar'), [
            'username' => 'ana',
            'password' => 'secreto123',
        ]);

        $respuesta->assertRedirect(route('post-login'));
        $this->assertAuthenticated();
    }

    /**
     * Una contrasena guardada en un formato ilegible NO tumba la pantalla.
     *
     * El comprobador de bcrypt LANZA cuando lo que hay guardado no es un hash
     * suyo, y esa excepcion salia sin capturar hasta el navegador: la pantalla
     * mas publica del sistema respondia un 500 a esa persona, que ni siquiera
     * llegaba a leer «usuario o contraseña incorrectos».
     *
     * Se escribe con `DB::table()->update()` a proposito: pasando por el modelo,
     * el cast `hashed` lo hashearia y no habria forma de reproducir la fila
     * rota. Asi es como llegan de verdad — por SQL o por una importacion.
     *
     * En produccion no hay ninguna fila asi (885 de 885 con bcrypt, comprobado
     * el 07/09/2026); esta prueba cierra la puerta al dia que alguien importe
     * usuarios de otro sistema.
     */
    public function test_una_contrasena_guardada_en_mal_formato_no_tumba_la_pantalla(): void
    {
        $user = $this->crearCuenta('estudiante');

        DB::table('users')->where('id', $user->id)->update(['password' => 'x']);

        $respuesta = $this->post(route('login.entrar'), [
            'username' => 'ana',
            'password' => 'secreto123',
        ]);

        // Login fallido y NO un 500: un hash ilegible no autentica a nadie.
        $respuesta->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    /**
     * Y queda en el registro, que es la mitad que de verdad importa.
     *
     * Un hash roto y una contrasena equivocada no son lo mismo: el primero no lo
     * arregla la persona reintentando. Sin esta linea se quedaria escondido
     * detras de un mensaje que le echa la culpa a ella.
     */
    public function test_una_contrasena_en_mal_formato_queda_registrada(): void
    {
        $user = $this->crearCuenta('estudiante');

        DB::table('users')->where('id', $user->id)->update(['password' => 'x']);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $mensaje, array $contexto) => $contexto['username'] === 'ana');

        $this->post(route('login.entrar'), [
            'username' => 'ana',
            'password' => 'secreto123',
        ])->assertSessionHasErrors('username');
    }

    public function test_una_contrasena_mala_no_entra(): void
    {
        $this->crearCuenta('estudiante');

        $respuesta = $this->post(route('login.entrar'), [
            'username' => 'ana',
            'password' => 'equivocada',
        ]);

        $respuesta->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    /**
     * Una cuenta desactivada no entra, y el mensaje es el MISMO que el de una
     * clave mala: decir "existe pero esta desactivada" le confirmaria a un
     * extrano que ese usuario existe.
     */
    public function test_una_cuenta_desactivada_no_entra(): void
    {
        $this->crearCuenta('profesor', 'beto', activo: false);

        $respuesta = $this->post(route('login.entrar'), [
            'username' => 'beto',
            'password' => 'secreto123',
        ]);

        $respuesta->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    /**
     * Desactivar echa tambien a quien YA estaba dentro.
     *
     * Comprobar `activo` solo al entrar cierra la puerta pero no vacia la sala:
     * sin esto, la persona conservaba acceso completo hasta que caducara la
     * sesion —dos horas, y renovandose con cada clic—. El original no tiene el
     * problema porque Django mira `is_active` en cada peticion.
     *
     * La pantalla elegida es /mi-perfil a proposito: esta solo tras `auth`, sin
     * rol, que es justo el hueco por el que se colaria si la comprobacion
     * viviera dentro de `RequiereRol`.
     */
    public function test_desactivar_una_cuenta_echa_a_quien_ya_estaba_dentro(): void
    {
        $user = $this->crearCuenta('profesor', 'beto');

        // Con la sesion abierta, su pantalla responde.
        $this->actingAs($user)
            ->get(route('mi-perfil'))
            ->assertOk();

        // Direccion la desactiva mientras la persona sigue navegando.
        $user->update(['activo' => false]);

        // La siguiente peticion de esa misma sesion ya no pasa.
        $this->actingAs($user->fresh())
            ->get(route('mi-perfil'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    /** Y tampoco alcanza las pantallas que si piden rol. */
    public function test_la_cuenta_desactivada_tampoco_alcanza_una_pantalla_con_rol(): void
    {
        $user = $this->crearCuenta('profesor', 'beto', activo: false);

        $this->actingAs($user)
            ->get(route('panel'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    /**
     * Volver a activarla devuelve el acceso.
     *
     * La otra mitad del interruptor: si expulsar dejara algo pegado a la cuenta
     * —y no a la sesion—, reactivar no bastaria para volver a entrar.
     */
    public function test_reactivar_la_cuenta_devuelve_el_acceso(): void
    {
        $user = $this->crearCuenta('profesor', 'beto', activo: false);

        $this->actingAs($user)
            ->get(route('panel'))
            ->assertRedirect(route('login'));

        $user->update(['activo' => true]);

        $this->actingAs($user->fresh())
            ->get(route('panel'))
            ->assertOk();
    }

    public function test_el_estudiante_aterriza_en_el_catalogo(): void
    {
        $user = $this->crearCuenta('estudiante');

        $this->actingAs($user)
            ->get(route('post-login'))
            ->assertRedirect(route('promotorias-disponibles'));
    }

    /**
     * Quien DICTA o dirige aterriza en el Panel: es su pantalla de diario.
     *
     * El administrador ya no, desde el 04/09/2026 — ver la prueba de abajo.
     */
    public function test_quien_dicta_y_quien_dirige_aterrizan_en_el_panel(): void
    {
        foreach (['director', 'profesor'] as $indice => $rol) {
            $user = $this->crearCuenta($rol, "usuario{$indice}");

            $this->actingAs($user)
                ->get(route('post-login'))
                ->assertRedirect(route('panel'));
        }
    }

    /**
     * EL ADMINISTRADOR ATERRIZA EN GESTION, pedido por el usuario el
     * 04/09/2026.
     *
     * Su trabajo no es el Panel: confirmar matriculas y pasar lista son tareas
     * de quien dicta. Lo suyo es el catalogo, los periodos, los usuarios y las
     * alertas, y eso vive en Gestion — que ademas ensena desde arriba como va la
     * escuela.
     *
     * El director se queda en el Panel a proposito, y por eso las dos pruebas
     * van separadas: si un dia alguien unifica el aterrizaje del «personal»,
     * una de las dos enrojece.
     */
    public function test_el_administrador_aterriza_en_gestion(): void
    {
        $this->actingAs($this->crearCuenta('administrador', 'jefa'))
            ->get(route('post-login'))
            ->assertRedirect(route('gestion-inicio'));
    }

    /**
     * La cuenta recien autorregistrada existe pero no tiene rol: entra, y lo
     * unico que ve es la pantalla de espera.
     */
    public function test_sin_rol_se_va_a_pendiente_de_aprobacion(): void
    {
        $user = $this->crearCuenta('');

        $this->actingAs($user)
            ->get(route('post-login'))
            ->assertRedirect(route('pendiente-aprobacion'));
    }

    /** Si le asignan el rol mientras esperaba, esa pantalla deja de aplicar. */
    public function test_con_rol_ya_asignado_la_pantalla_de_espera_redirige(): void
    {
        $user = $this->crearCuenta('profesor');

        $this->actingAs($user)
            ->get(route('pendiente-aprobacion'))
            ->assertRedirect(route('post-login'));
    }

    public function test_se_cierra_sesion(): void
    {
        $user = $this->crearCuenta('estudiante');

        $this->actingAs($user)
            ->post(route('logout'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    // -----------------------------------------------------------------------
    // Limite de intentos
    // -----------------------------------------------------------------------

    /**
     * Al sexto intento en el mismo minuto se corta.
     *
     * Cinco fallos y el sexto ya no llega al controlador: sin esto, probar
     * contrasenas contra una cuenta no tiene ningun freno.
     */
    public function test_el_login_se_corta_tras_cinco_intentos_fallidos(): void
    {
        $this->crearCuenta('estudiante');

        for ($intento = 1; $intento <= 5; $intento++) {
            $this->post(route('login.entrar'), [
                'username' => 'ana',
                'password' => 'equivocada',
            ])->assertSessionHasErrors('username');
        }

        $this->post(route('login.entrar'), [
            'username' => 'ana',
            'password' => 'equivocada',
        ])->assertSessionHas('error');

        $this->assertGuest();
    }

    /**
     * Agotar los intentos de una cuenta cierra ESA cuenta, no la direccion.
     *
     * Es la prueba de la decision de fondo: el contador va por usuario+IP. Una
     * escuela entera comparte una sola IP, asi que si el limite fuera por
     * direccion, bastaria con que alguien machacara una cuenta para dejar sin
     * entrar a los demas desde la misma sala.
     */
    public function test_agotar_una_cuenta_no_bloquea_a_las_demas_desde_la_misma_ip(): void
    {
        $this->crearCuenta('estudiante', 'ana');
        $this->crearCuenta('profesor', 'beto');

        for ($intento = 1; $intento <= 6; $intento++) {
            $this->post(route('login.entrar'), [
                'username' => 'ana',
                'password' => 'equivocada',
            ]);
        }

        // Misma IP, otra cuenta, credenciales buenas: entra sin enterarse.
        $this->post(route('login.entrar'), [
            'username' => 'beto',
            'password' => 'secreto123',
        ])->assertRedirect(route('post-login'));

        $this->assertAuthenticated();
    }

    /**
     * CAMBIAR DE IP NO ESTRENA INTENTOS contra la misma cuenta (27/09/2026).
     *
     * Era la pregunta del usuario en la revision de seguridad: con el contador
     * por usuario+IP, cada IP nueva traia cinco intentos mas. Ahora, pasados
     * diez contra la misma cuenta en quince minutos, el siguiente se corta
     * desde la IP que sea. Y las dos mitades: esas mismas IPs siguen entrando
     * con OTRA cuenta, que es la sala de computo.
     */
    public function test_cambiar_de_ip_no_da_intentos_nuevos_contra_la_misma_cuenta(): void
    {
        $this->crearCuenta('estudiante', 'ana');
        $this->crearCuenta('profesor', 'beto');

        // Diez intentos, cada uno desde una IP distinta: ninguna llega a cinco.
        for ($intento = 1; $intento <= 10; $intento++) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$intento}"])
                ->post(route('login.entrar'), ['username' => 'ana', 'password' => 'equivocada'])
                ->assertSessionHasErrors('username');
        }

        // El undecimo, desde una IP nunca vista y con la contrasena BUENA, se
        // corta igual: el atacante no sabe cual acierta, asi que no puede
        // haber una excepcion para el acierto.
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.99'])
            ->post(route('login.entrar'), ['username' => 'ana', 'password' => 'secreto123'])
            ->assertSessionHas('error');
        $this->assertGuest();

        // Otra cuenta desde una de esas IPs entra sin enterarse.
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
            ->post(route('login.entrar'), ['username' => 'beto', 'password' => 'secreto123'])
            ->assertRedirect(route('post-login'));
        $this->assertAuthenticated();
    }

    /** Escribirlo con otras mayusculas no estrena contador. */
    public function test_el_contador_no_distingue_mayusculas(): void
    {
        $this->crearCuenta('estudiante');

        for ($intento = 1; $intento <= 5; $intento++) {
            $this->post(route('login.entrar'), [
                'username' => 'ana',
                'password' => 'equivocada',
            ]);
        }

        $this->post(route('login.entrar'), [
            'username' => 'ANA',
            'password' => 'equivocada',
        ])->assertSessionHas('error');
    }

    /**
     * Quedarse sin intentos no dice si la cuenta existe.
     *
     * Misma razon que el mensaje de clave incorrecta: un aviso distinto para un
     * usuario real le confirmaria a un extrano cuales existen.
     */
    public function test_el_aviso_de_bloqueo_no_revela_si_la_cuenta_existe(): void
    {
        for ($intento = 1; $intento <= 6; $intento++) {
            $respuesta = $this->post(route('login.entrar'), [
                'username' => 'fantasma',
                'password' => 'loquesea',
            ]);
        }

        $respuesta->assertSessionHas('error');
        $respuesta->assertSessionMissing('username');
    }

    // -----------------------------------------------------------------------
    // La contrasena y las demas sesiones
    // -----------------------------------------------------------------------

    /**
     * Una peticion NUEVA sobre la sesion que ya esta abierta.
     *
     * Entre dos peticiones del mismo test, el guard conserva en memoria el
     * usuario que ya resolvio, asi que seguiria leyendo la contrasena vieja y la
     * prueba pasaria sin probar nada. En produccion cada peticion es un proceso
     * limpio: esto es lo que reproduce eso. Se prefiere a `actingAs(fresh())`
     * porque no inyecta el usuario — deja que el guard lo resuelva desde la
     * sesion, que es justo lo que se esta probando.
     */
    private function peticionNueva(string $ruta)
    {
        app('auth')->forgetGuards();

        return $this->get($ruta);
    }

    /**
     * Cambiar la contrasena cierra las sesiones que ya estaban abiertas.
     *
     * Es la mitad que quedo suelta del hallazgo de sesion de la auditoria. En
     * este sistema la contrasena SOLO la cambia un administrador desde
     * Gestion -> Usuarios, asi que el caso real es: se resetea la clave de
     * alguien —porque se fue, o porque su cuenta esta comprometida— y esa
     * persona sigue navegando donde ya estuviera.
     */
    public function test_cambiar_la_contrasena_echa_a_quien_ya_estaba_dentro(): void
    {
        $user = $this->crearCuenta('profesor', 'beto');

        $this->post(route('login.entrar'), ['username' => 'beto', 'password' => 'secreto123']);
        $this->get(route('mi-perfil'))->assertOk();

        // Direccion le cambia la contrasena mientras la persona sigue dentro.
        $user->update(['password' => 'otra-distinta-456']);

        $this->peticionNueva(route('mi-perfil'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    /**
     * Y no echa a nadie mas: la sesion de otra cuenta sigue viva.
     *
     * Lo que cierra la sesion es que el hash NO cuadre, no que exista el
     * middleware. Sin esta prueba, un cambio que echara a todo el mundo en cada
     * peticion pasaria igual la de arriba.
     */
    public function test_cambiar_una_contrasena_no_toca_las_sesiones_de_los_demas(): void
    {
        $this->crearCuenta('profesor', 'beto');
        $otra = $this->crearCuenta('estudiante', 'ana');

        $this->post(route('login.entrar'), ['username' => 'ana', 'password' => 'secreto123']);
        $this->get(route('mi-perfil'))->assertOk();

        User::where('username', 'beto')->first()->update(['password' => 'otra-distinta-456']);

        $this->peticionNueva(route('mi-perfil'))->assertOk();
        $this->assertAuthenticatedAs($otra);
    }

    /**
     * Dos personas seguidas en el mismo navegador siguen pudiendo entrar.
     *
     * Es el riesgo que hay que descartar al atar la sesion al hash de la
     * contrasena: que el hash de quien uso el equipo antes eche a quien entra
     * despues. No pasa, por dos motivos que conviene tener fijados porque
     * cualquiera de los dos podria cambiarse sin darse cuenta:
     *
     * - Cerrar sesion llama a `invalidate()`, que vacia la sesion entera y con
     *   ella el hash guardado.
     * - `/entrar` va detras del middleware `guest`, asi que quien ya tiene
     *   sesion abierta ni siquiera llega al controlador de login.
     */
    public function test_otra_persona_entra_despues_en_el_mismo_navegador(): void
    {
        $this->crearCuenta('profesor', 'beto');
        $ana = $this->crearCuenta('estudiante', 'ana');

        $this->post(route('login.entrar'), ['username' => 'beto', 'password' => 'secreto123']);
        $this->get(route('mi-perfil'))->assertOk();

        $this->post(route('logout'));

        app('auth')->forgetGuards();
        $this->post(route('login.entrar'), ['username' => 'ana', 'password' => 'secreto123']);

        $this->peticionNueva(route('mi-perfil'))->assertOk();
        $this->assertAuthenticatedAs($ana);
    }
}
