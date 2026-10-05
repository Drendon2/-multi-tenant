<?php

namespace Tests\Feature;

use App\Mail\CorreoDePrueba;
use App\Mail\EnlaceParaLaClave;
use App\Models\ConfiguracionInstitucion;
use App\Models\Perfil;
use App\Models\User;
use App\Support\CorreoDeLaInstitucion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Las credenciales del servidor de correo, editables desde Gestion → Institucion.
 *
 * ─── POR QUE EXISTE ESTA PANTALLA ──────────────────────────────────────────
 *
 * El `.env` es el peor sitio posible para esto EN ESTE PRODUCTO, que se instala
 * en casas ajenas: encender la recuperacion de contrasena en una entidad nueva
 * exigia entrar por SSH, que es justo lo que no se hace. Y cuando no se hace, la
 * funcion no falla, MIENTE — el enlace se escribe en un registro y quien lo
 * pidio ve la misma pantalla tranquilizadora.
 *
 * ─── LO QUE VIGILA ESTE ARCHIVO ────────────────────────────────────────────
 *
 * 1. QUE LA CONTRASENA NO SE PUEDA LEER DESDE LA PANTALLA. Es la afirmacion mas
 *    importante y la que no se ve mirando: el campo es `type=password`, que la
 *    esconde a la VISTA y no al codigo fuente. Sin esta prueba, la contrasena
 *    del buzon de la entidad viaja en el HTML de una pagina que abre cualquier
 *    administrador.
 *
 * 2. QUE VACIA SIGNIFIQUE «DEJA LA QUE HAY». Si no, guardar cualquier otra cosa
 *    de esa pantalla —el color de acento— borraria la contrasena del correo, y
 *    la recuperacion se apagaria sin que nada lo dijera.
 *
 * 3. QUE SE GUARDE CIFRADA. Una copia de la base robada no puede entregar la
 *    contrasena del buzon de la entidad.
 *
 * 4. QUE MANDE LA PANTALLA SOBRE EL `.env`, y que sin pantalla siga valiendo el
 *    `.env`. Son los dos caminos y los dos tienen que funcionar.
 *
 * 5. QUE LA PRUEBA DE ENVIO CUENTE LA VERDAD. Incluido el caso que no lanza y
 *    es el que mas confunde: con el `.env` en `log` el envio «sale bien» y el
 *    correo se queda en un archivo.
 */
class CorreoDeLaInstitucionTest extends TestCase
{
    use RefreshDatabase;

    /** Los campos sin los que la pantalla de Institucion no guarda. */
    private const MINIMO = [
        'seccion' => 'correo',
        'nombre_institucion' => 'Casa de la Cultura',
        'color_acento' => '#0a7a59',
        'limite_promotorias_por_periodo' => 2,
        'faltas_para_abandono' => 5,
    ];

    /** Unas credenciales completas y validas de forma. */
    private const CREDENCIALES = [
        'correo_servidor' => 'smtp.hostinger.com',
        'correo_puerto' => 465,
        'correo_cifrado' => 'smtps',
        'correo_usuario' => 'admin@ejemplo.com',
        'correo_clave' => 'la-del-buzon',
    ];

    private Perfil $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->admin = $this->perfil('jefa', 'administrador');
    }

    // ------------------------------------------------------------------
    // 1. La contrasena no se puede leer
    // ------------------------------------------------------------------

    /**
     * NO viaja en el HTML, ni siquiera dentro de un `type=password`.
     *
     * Ese tipo la esconde a la vista y no al codigo fuente: con `value` puesto,
     * la contrasena del buzon de la entidad esta a un «ver código fuente» de
     * cualquiera que abra esa pagina.
     */
    public function test_la_clave_no_viaja_a_la_pantalla(): void
    {
        $this->guardar(self::CREDENCIALES);

        $html = (string) $this->actingAs($this->admin->user)
            ->get(route('gestion-configuracion'))
            ->getContent();

        $this->assertStringNotContainsString('la-del-buzon', $html);
    }

    // ------------------------------------------------------------------
    // 2. Vacia significa «deja la que hay»
    // ------------------------------------------------------------------

    public function test_guardar_otra_cosa_no_borra_la_clave(): void
    {
        $this->guardar(self::CREDENCIALES);

        // Lo que hace de verdad quien vuelve a esta pantalla: cambiar el color.
        // El campo de la clave llega vacio porque la plantilla nunca lo llena.
        $this->guardar([
            ...self::CREDENCIALES,
            'correo_clave' => '',
            'color_acento' => '#123456',
        ]);

        $this->assertSame('la-del-buzon', ConfiguracionInstitucion::actual()->fresh()->correo_clave);
    }

    public function test_una_clave_nueva_reemplaza_a_la_vieja(): void
    {
        $this->guardar(self::CREDENCIALES);
        $this->guardar([...self::CREDENCIALES, 'correo_clave' => 'otra-distinta']);

        $this->assertSame('otra-distinta', ConfiguracionInstitucion::actual()->fresh()->correo_clave);
    }

    /**
     * Y vaciar el servidor se lleva la clave por delante.
     *
     * Si no, queda una clave cifrada de un buzon que ya nadie usa, y el dia que
     * alguien escriba otro servidor el sistema intentaria autenticarse con la
     * contrasena vieja sin que nada lo dijera.
     */
    public function test_quitar_el_servidor_borra_la_clave(): void
    {
        $this->guardar(self::CREDENCIALES);
        $this->guardar([...self::CREDENCIALES, 'correo_servidor' => '', 'correo_clave' => '']);

        $configuracion = ConfiguracionInstitucion::actual()->fresh();

        $this->assertNull($configuracion->correo_clave);
        $this->assertFalse(CorreoDeLaInstitucion::configuradoEnLaPantalla($configuracion));
    }

    // ------------------------------------------------------------------
    // 3. Cifrada en la base
    // ------------------------------------------------------------------

    public function test_la_clave_se_guarda_cifrada(): void
    {
        $this->guardar(self::CREDENCIALES);

        $enBruto = (string) DB::table('configuracion_institucion')->value('correo_clave');

        $this->assertNotSame('', $enBruto);
        $this->assertStringNotContainsString('la-del-buzon', $enBruto);
        // Y se lee bien por el modelo, que es la otra mitad: cifrada y
        // recuperable, no cifrada y perdida.
        $this->assertSame('la-del-buzon', ConfiguracionInstitucion::actual()->fresh()->correo_clave);
    }

    // ------------------------------------------------------------------
    // 4. Quien manda sobre quien
    // ------------------------------------------------------------------

    public function test_sin_credenciales_manda_el_archivo(): void
    {
        config(['mail.default' => 'smtp']);

        $this->assertFalse(CorreoDeLaInstitucion::configuradoEnLaPantalla());
        $this->assertTrue(CorreoDeLaInstitucion::hayPorDondeMandar());
    }

    /**
     * Con el archivo en `log` NO hay por donde mandar, aunque el envio funcione.
     *
     * Es la distincion que hace posible que la pantalla diga la verdad: `log`
     * escribe el correo y no lo manda a nadie, y eso no lanza ninguna excepcion
     * — mirando si el envio fallo, parecería que todo está bien.
     */
    public function test_con_el_archivo_en_log_no_hay_por_donde_mandar(): void
    {
        config(['mail.default' => 'log']);

        $this->assertFalse(CorreoDeLaInstitucion::hayPorDondeMandar());
    }

    public function test_las_credenciales_de_la_pantalla_mandan_sobre_el_archivo(): void
    {
        config(['mail.default' => 'log']);

        $this->guardar(self::CREDENCIALES);

        $this->assertTrue(CorreoDeLaInstitucion::hayPorDondeMandar());
        $this->assertSame(
            'admin@ejemplo.com',
            CorreoDeLaInstitucion::remitente()['address']
        );
    }

    /**
     * Y el correo de recuperacion sale de verdad por ese camino.
     *
     * Es la unica prueba que junta las dos mitades: unas credenciales escritas
     * en Gestion y un enlace que llega. Sin ella, todo lo de arriba podria estar
     * bien y el controlador seguir usando el remitente del archivo.
     */
    public function test_el_enlace_de_la_clave_sale_por_el_remitente_de_la_pantalla(): void
    {
        $this->guardar(self::CREDENCIALES);

        $quien = $this->perfil('ana', 'estudiante');
        $quien->user->update(['email' => 'ana@example.com']);

        // SE CIERRA LA SESION ANTES, y no sobra. `guardar()` entro como
        // administradora y `actingAs` sigue puesto durante todo el test; la
        // ruta de «olvide mi contrasena» va detras de `guest`, asi que con la
        // sesion abierta la peticion se va redirigida y no se manda nada. La
        // prueba salia roja con el codigo BUENO, que es la forma exacta de
        // creerse que algo esta mal cuando lo que esta mal es la prueba.
        auth()->logout();

        $this->post(route('clave-olvidada.enviar'), ['cuenta' => 'ana']);

        // El «De:» se afirma sobre el SOBRE y no con `hasFrom()`: ese metodo
        // mira la propiedad `$from` del Mailable, que se rellena al construir
        // el mensaje para entregarlo — y bajo `Mail::fake()` eso no llega a
        // pasar. Con `hasFrom()` esta prueba sale roja con el codigo bueno.
        Mail::assertSent(EnlaceParaLaClave::class, function (EnlaceParaLaClave $correo) {
            return $correo->hasTo('ana@example.com')
                && $correo->envelope()->from?->address === 'admin@ejemplo.com';
        });
    }

    /**
     * Y el correo sale POR EL SERVIDOR DE LA ENTIDAD, no por el del archivo.
     *
     * ESTA PRUEBA NO PUEDE USAR `Mail::fake()`, y ahi esta el motivo de que
     * exista aparte: con el falso puesto, `Mail::mailer()` y
     * `Mail::mailer('institucion')` devuelven EL MISMO objeto, asi que ninguna
     * afirmacion hecha sobre un correo capturado distingue por donde salio.
     * Comprobado: rompiendo `remitenteQueEnvia()` para que devuelva siempre el
     * del archivo, todo lo demas de este archivo seguia en verde.
     *
     * Asi que se mira el TRANSPORTE que se arma, que es lo unico que de verdad
     * dice a que maquina se va a conectar. No se envia nada: construir el
     * transporte no abre ninguna conexion.
     */
    public function test_el_transporte_apunta_al_servidor_de_la_entidad(): void
    {
        $this->guardar(self::CREDENCIALES);

        $this->sinElCorreoFalso();

        $transporte = (string) CorreoDeLaInstitucion::remitenteQueEnvia()->getSymfonyTransport();

        $this->assertStringContainsString('smtp.hostinger.com', $transporte);
    }

    /** Y sin credenciales en la pantalla, por el del archivo. */
    public function test_sin_credenciales_el_transporte_es_el_del_archivo(): void
    {
        $this->sinElCorreoFalso();

        $transporte = (string) CorreoDeLaInstitucion::remitenteQueEnvia()->getSymfonyTransport();

        $this->assertStringNotContainsString('smtp.hostinger.com', $transporte);
    }

    // ------------------------------------------------------------------
    // 5. La prueba de envio
    // ------------------------------------------------------------------

    public function test_la_prueba_de_envio_manda_un_correo(): void
    {
        $this->guardar([...self::CREDENCIALES, 'correo_prueba' => 'yo@ejemplo.com'])
            ->assertSessionHas('success');

        Mail::assertSent(CorreoDePrueba::class, fn (CorreoDePrueba $correo) => $correo->hasTo('yo@ejemplo.com'));
    }

    /**
     * Y sin nada configurado NO dice que salió bien.
     *
     * Es el caso que no lanza: con el archivo en `log` el envio «funciona» y el
     * correo se queda escrito en la maquina. Un boton que dijera «enviado» ahi
     * es peor que no tener boton.
     */
    public function test_la_prueba_avisa_cuando_no_hay_por_donde_mandar(): void
    {
        config(['mail.default' => 'log']);

        $this->guardar(['correo_prueba' => 'yo@ejemplo.com'])
            ->assertSessionHas('error', fn (string $aviso) => str_contains($aviso, 'No se envió nada'));

        Mail::assertNothingSent();
    }

    /** El campo de la prueba no se guarda: es de una sola vez. */
    public function test_el_correo_de_prueba_no_se_guarda(): void
    {
        $this->guardar([...self::CREDENCIALES, 'correo_prueba' => 'yo@ejemplo.com']);

        $columnas = (array) DB::table('configuracion_institucion')->first();

        $this->assertArrayNotHasKey('correo_prueba', $columnas);
    }

    // ------------------------------------------------------------------
    // Lo que no entra
    // ------------------------------------------------------------------

    /**
     * Un servidor con esquema o barras se rechaza CON UN MENSAJE QUE SIRVE.
     *
     * Pegar «https://smtp.hostinger.com» del panel del proveedor es lo que hace
     * cualquiera. Guardado, fallaria al enviar sin decir por que.
     */
    public function test_un_servidor_mal_escrito_se_rechaza(): void
    {
        $this->actingAs($this->admin->user)
            ->post(route('gestion-configuracion'), self::MINIMO + [
                'correo_servidor' => 'https://smtp.hostinger.com/',
            ])
            ->assertSessionHasErrors('correo_servidor');

        $this->assertSame('', ConfiguracionInstitucion::actual()->fresh()->correo_servidor);
    }

    public function test_una_direccion_que_no_es_correo_se_rechaza(): void
    {
        $this->actingAs($this->admin->user)
            ->post(route('gestion-configuracion'), self::MINIMO + [
                'correo_usuario' => 'esto no es un correo',
            ])
            ->assertSessionHasErrors('correo_usuario');
    }

    /** Y el error ABRE el plegado donde vive el campo, si es que hay plegado. */
    public function test_el_rechazo_se_ve(): void
    {
        $html = (string) $this->actingAs($this->admin->user)
            ->from(route('gestion-configuracion'))
            ->followingRedirects()
            ->post(route('gestion-configuracion'), self::MINIMO + [
                'correo_servidor' => 'https://smtp.hostinger.com/',
            ])
            ->getContent();

        $this->assertStringContainsString('Escribe solo el nombre del servidor', $html);
    }

    /** Solo el administrador llega aquí. */
    public function test_un_director_no_toca_el_correo(): void
    {
        $director = $this->perfil('dire', 'director');

        // `RequiereRol` no contesta 403: devuelve al sitio que le toca a ese rol
        // con un aviso. Se afirma sobre lo que de verdad importa —que no se
        // guardo nada— porque un `assertRedirect()` a secas pasaria en verde
        // aunque la peticion hubiera guardado y redirigido despues.
        $this->actingAs($director->user)
            ->post(route('gestion-configuracion'), self::MINIMO + self::CREDENCIALES)
            ->assertRedirect(route('post-login'))
            ->assertSessionHas('error');

        $this->assertSame('', ConfiguracionInstitucion::actual()->fresh()->correo_servidor);
    }

    // ------------------------------------------------------------------
    // Andamiaje
    // ------------------------------------------------------------------

    /**
     * Deshace el `Mail::fake()` del `setUp` para esta prueba.
     *
     * `fake()` no solo cambia lo que devuelve la fachada: mete el falso en el
     * contenedor como instancia. Por eso hacen falta las dos lineas —olvidar la
     * instancia y limpiar lo ya resuelto— y con una sola sigue saliendo el
     * falso.
     */
    private function sinElCorreoFalso(): void
    {
        $this->app->forgetInstance('mail.manager');
        Mail::clearResolvedInstances();
    }

    /** @param  array<string, mixed>  $campos */
    private function guardar(array $campos): TestResponse
    {
        return $this->actingAs($this->admin->user)
            ->post(route('gestion-configuracion'), $campos + self::MINIMO)
            ->assertSessionHasNoErrors();
    }

    private function perfil(string $username, string $rol): Perfil
    {
        $user = User::create(['username' => $username, 'password' => 'x', 'activo' => true]);

        return Perfil::create([
            'user_id' => $user->id,
            'nombre_completo' => ucfirst($username).' Pérez',
            'rol' => $rol,
            'telefono' => '3001112233',
            'fecha_nacimiento' => Carbon::today()->subYears(30),
        ]);
    }
}
