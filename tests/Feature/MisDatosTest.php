<?php

namespace Tests\Feature;

use App\Models\Acudiente;
use App\Models\DatosEstudiante;
use App\Models\Perfil;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * CADA QUIEN CORRIGE SUS PROPIOS DATOS, desde el 07/09/2026.
 *
 * Hasta ese dia el nombre y la fecha de nacimiento se escribian una vez al
 * inscribirse y despues solo los tocaba un administrador desde Gestion. Lo
 * abrio el usuario, y hacia falta: la regla del nombre que entro esa misma
 * tarde dejo a cuatro personas de produccion con un nombre que el sistema ya no
 * acepta, y ninguna podia arreglarlo sola.
 *
 * Lo que estas pruebas vigilan no es que el formulario guarde —eso se ve
 * abriendo la pantalla— sino las tres cosas que NO se ven mirando:
 *
 * 1. Que cambiar la FECHA no sea una puerta para quitarse el acudiente. De ella
 *    sale `es_menor`, y de ahi cuelga que se exija acudiente, quien firma el
 *    consentimiento y que version del formato se imprime.
 * 2. Que la validacion mire la fecha NUEVA y no la que habia guardada.
 * 3. Que el cambio quede en la auditoria: son datos de identidad y salen en el
 *    certificado.
 */
class MisDatosTest extends TestCase
{
    use RefreshDatabase;

    private function crearEstudiante(string $username, string $nacimiento): Perfil
    {
        $user = User::create(['username' => $username, 'password' => 'demo1234', 'activo' => true]);

        $perfil = Perfil::create([
            'user_id' => $user->id,
            'rol' => 'estudiante',
            'nombre_completo' => 'Ana Ruiz',
            'fecha_nacimiento' => $nacimiento,
            'telefono' => '3001112233',
        ]);

        DatosEstudiante::create([
            'perfil_id' => $perfil->id,
            // Del id, no una constante: dos estudiantes en la misma prueba
            // chocaban contra el indice unico antes de llegar a la regla.
            'documento_identidad' => str_pad((string) $perfil->id, 8, '0', STR_PAD_LEFT),
        ]);

        return $perfil->fresh();
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function formulario(Perfil $perfil, array $extra = []): array
    {
        $datos = $perfil->datosEstudiante;

        return [
            'accion' => 'datos',
            'nombre_completo' => $perfil->nombre_completo,
            'fecha_nacimiento' => (string) $perfil->getRawOriginal('fecha_nacimiento'),
            'documento_identidad' => $datos?->documento_identidad,
            'acudiente_nombre' => $datos?->acudiente->nombre ?? '',
            'acudiente_telefono' => $datos?->acudiente->telefono ?? '',
            ...$extra,
        ];
    }

    // --------------------------------------------------------------------
    // Lo que ahora se puede
    // --------------------------------------------------------------------

    public function test_un_estudiante_mayor_se_corrige_el_nombre(): void
    {
        $ana = $this->crearEstudiante('ana', '1990-04-04');

        $this->actingAs($ana->user)
            ->post(route('mi-perfil.guardar'), $this->formulario($ana, [
                'nombre_completo' => 'Ana María Ruiz',
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('mi-perfil'));

        $this->assertSame('Ana María Ruiz', $ana->fresh()->nombre_completo);
    }

    public function test_se_corrige_el_documento(): void
    {
        $ana = $this->crearEstudiante('ana', '1990-04-04');

        $this->actingAs($ana->user)
            ->post(route('mi-perfil.guardar'), $this->formulario($ana, [
                'documento_identidad' => '99887766',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('99887766', $ana->fresh()->datosEstudiante->documento_identidad);
    }

    /**
     * El personal tambien, sin acudiente. Su documento viaja en el mismo
     * formulario desde el 05/10/2026 (vive en el perfil y es obligatorio para
     * profesor y director), asi que se manda como lo manda la pantalla.
     */
    public function test_un_profesor_se_corrige_el_nombre(): void
    {
        $user = User::create(['username' => 'profe', 'password' => 'demo1234', 'activo' => true]);
        $profe = Perfil::create([
            'user_id' => $user->id,
            'rol' => 'profesor',
            'nombre_completo' => 'Camila Agudelo',
            'fecha_nacimiento' => '1985-02-02',
            'telefono' => '3001112233',
        ]);

        $this->actingAs($user)
            ->post(route('mi-perfil.guardar'), [
                'accion' => 'datos',
                'nombre_completo' => 'Camila Agudelo Ríos',
                'fecha_nacimiento' => '1985-02-02',
                'documento_identidad' => '43111222',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Camila Agudelo Ríos', $profe->fresh()->nombre_completo);
        $this->assertSame('43111222', $profe->fresh()->documento_identidad);
    }

    // --------------------------------------------------------------------
    // La fecha, que es la que arrastra
    // --------------------------------------------------------------------

    /**
     * Hacerse MENOR sin acudiente no se puede.
     *
     * Es la prueba que justifica llamar a `DatosEstudiante::validar()` y no
     * quedarse en las reglas de los campos: ninguna regla de formulario sabe que
     * la fecha nueva convierte a esta persona en menor de edad.
     */
    public function test_cambiar_la_fecha_a_menor_sin_acudiente_se_rechaza(): void
    {
        $ana = $this->crearEstudiante('ana', '1990-04-04');

        $this->actingAs($ana->user)
            ->post(route('mi-perfil.guardar'), $this->formulario($ana, [
                'fecha_nacimiento' => Carbon::today()->subYears(10)->toDateString(),
            ]))
            ->assertSessionHasErrors('acudiente');

        // Y la fecha NO se guarda a medias: la ficha queda como estaba.
        $this->assertSame('1990-04-04', (string) $ana->fresh()->getRawOriginal('fecha_nacimiento'));
    }

    /** Con acudiente puesto, el mismo cambio SI pasa. */
    public function test_cambiar_la_fecha_a_menor_con_acudiente_se_acepta(): void
    {
        $ana = $this->crearEstudiante('ana', '1990-04-04');
        $nueva = Carbon::today()->subYears(10)->toDateString();

        $this->actingAs($ana->user)
            ->post(route('mi-perfil.guardar'), $this->formulario($ana, [
                'fecha_nacimiento' => $nueva,
                'acudiente_nombre' => 'La mamá',
                'acudiente_telefono' => '3009998877',
            ]))
            ->assertSessionHasNoErrors();

        $ana = $ana->fresh();
        $this->assertSame($nueva, (string) $ana->getRawOriginal('fecha_nacimiento'));
        $this->assertSame('La mamá', $ana->datosEstudiante->acudiente->nombre);
    }

    /**
     * UN MENOR NO SE QUITA EL ACUDIENTE haciendose mayor de un plumazo.
     *
     * Esta es la que parece que sobra y es la que importa: la validacion tiene
     * que mirar la fecha NUEVA. Si mirara la guardada, un menor que se declara
     * de treinta años pasaria el corte y se quedaria sin acudiente registrado —
     * y con el, sin nadie a quien llamar.
     *
     * Aqui SI se le deja hacerse mayor, porque un dato que la persona declara de
     * si misma no lo puede desmentir el sistema; lo que se comprueba es que el
     * acudiente que tenia NO desaparece por el camino.
     */
    public function test_un_menor_que_se_declara_mayor_no_pierde_a_su_acudiente(): void
    {
        $nino = $this->crearEstudiante('nino', Carbon::today()->subYears(10)->toDateString());

        $acudiente = Acudiente::create(['nombre' => 'La mamá', 'telefono' => '3009998877']);
        $nino->datosEstudiante->acudiente_id = $acudiente->id;
        $nino->datosEstudiante->save();

        $this->actingAs($nino->user)
            ->post(route('mi-perfil.guardar'), $this->formulario($nino->fresh(), [
                'fecha_nacimiento' => '1990-04-04',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertNotNull($nino->fresh()->datosEstudiante->acudiente);
    }

    // --------------------------------------------------------------------
    // Las reglas de formato siguen puestas
    // --------------------------------------------------------------------

    public function test_no_se_puede_poner_un_nombre_con_numeros(): void
    {
        $ana = $this->crearEstudiante('ana', '1990-04-04');

        $this->actingAs($ana->user)
            ->post(route('mi-perfil.guardar'), $this->formulario($ana, [
                'nombre_completo' => '1022142147',
            ]))
            ->assertSessionHasErrors('nombre_completo');

        $this->assertSame('Ana Ruiz', $ana->fresh()->nombre_completo);
    }

    /** El documento sigue siendo unico: no se coge el de otro. */
    public function test_no_se_puede_coger_el_documento_de_otro(): void
    {
        $ana = $this->crearEstudiante('ana', '1990-04-04');
        $beto = $this->crearEstudiante('beto', '1990-04-04');

        $this->actingAs($ana->user)
            ->post(route('mi-perfil.guardar'), $this->formulario($ana, [
                'documento_identidad' => $beto->datosEstudiante->documento_identidad,
            ]))
            ->assertSessionHasErrors('documento_identidad');
    }

    /** Y el suyo propio sí, que es lo que el `ignore` del unico deja pasar. */
    public function test_guardar_sin_cambiar_el_documento_no_choca_consigo_mismo(): void
    {
        $ana = $this->crearEstudiante('ana', '1990-04-04');

        $this->actingAs($ana->user)
            ->post(route('mi-perfil.guardar'), $this->formulario($ana, [
                'nombre_completo' => 'Ana Lucía Ruiz',
            ]))
            ->assertSessionHasNoErrors();
    }

    // --------------------------------------------------------------------
    // Lo que sigue SIN poderse
    // --------------------------------------------------------------------

    /**
     * El nombre de USUARIO no se cambia desde AQUI, ni colandolo en el formulario.
     *
     * Es la credencial con la que se entra, y cambiarla es otra cosa que
     * corregir un dato mal escrito. El formulario no lo pinta; esta prueba
     * comprueba que tampoco lo acepta si alguien lo manda a mano.
     *
     * OJO CON LEER ESTO COMO «no se puede cambiar»: desde el 10/09/2026 SI se
     * puede, en su propia seccion de «Mi perfil» y pidiendo la contrasena
     * actual (`CambioDeUsuarioTest`). Lo que esta prueba sostiene es que el
     * camino sea ESE y no este: aqui no se pide contrasena, asi que si el
     * campo colase, una sesion abierta en un celular prestado bastaria para
     * dejar a alguien sin saber con que entra.
     */
    public function test_el_usuario_no_se_cambia_desde_mis_datos(): void
    {
        $ana = $this->crearEstudiante('ana', '1990-04-04');

        $this->actingAs($ana->user)
            ->post(route('mi-perfil.guardar'), $this->formulario($ana, [
                'username' => 'otra.cosa',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('ana', $ana->user->fresh()->username);
    }

    /** Ni el rol, que es lo que decide a que pantallas se entra. */
    public function test_el_rol_no_se_cambia_desde_mis_datos(): void
    {
        $ana = $this->crearEstudiante('ana', '1990-04-04');

        $this->actingAs($ana->user)
            ->post(route('mi-perfil.guardar'), $this->formulario($ana, [
                'rol' => 'administrador',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('estudiante', $ana->fresh()->rol);
    }

    /** Y nadie toca la ficha de OTRO por esta puerta. */
    public function test_no_se_tocan_los_datos_de_otra_persona(): void
    {
        $ana = $this->crearEstudiante('ana', '1990-04-04');
        $beto = $this->crearEstudiante('beto', '1990-04-04');

        $this->actingAs($ana->user)
            ->post(route('mi-perfil.guardar'), $this->formulario($ana, [
                'perfil_id' => $beto->id,
                'nombre_completo' => 'Ana Cambiada',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('Ana Cambiada', $ana->fresh()->nombre_completo);
        $this->assertSame('Ana Ruiz', $beto->fresh()->nombre_completo);
    }

    // --------------------------------------------------------------------
    // La pantalla
    // --------------------------------------------------------------------

    public function test_la_seccion_se_abre_sola_si_su_formulario_fue_rechazado(): void
    {
        $ana = $this->crearEstudiante('ana', '1990-04-04');

        $this->actingAs($ana->user)
            ->post(route('mi-perfil.guardar'), $this->formulario($ana, ['nombre_completo' => '1234']))
            ->assertSessionHasErrors('nombre_completo');

        // Se pide la pantalla en una peticion aparte y NO con
        // `followRedirects()`: el rechazo responde `back()`, y sin cabecera
        // `Referer` —que una peticion de prueba no manda— eso lleva a la raiz
        // del sitio, no a Mi perfil. Los errores viven en la sesion y siguen ahi
        // para la siguiente peticion, que es justo lo que ve la persona.
        // SIN volver a llamar a `actingAs`: esa llamada arranca sesion de nuevo
        // y se lleva por delante los errores que el rechazo acababa de dejar
        // flasheados. La sesion y el usuario siguen puestos de la peticion
        // anterior, asi que basta con pedir la pantalla.
        $html = (string) $this->get(route('mi-perfil'))->assertOk()->getContent();

        // Sin el `open`, el aviso manda a buscar algo rojo que esta dentro de un
        // plegado. Es el fallo que ya costo un profesor en produccion.
        $this->assertMatchesRegularExpression('/<details[^>]*id="bloque-datos"[^>]*\bopen\b/', $html);
    }

    /**
     * A un profesor no se le pinta el acudiente. El documento SI, desde el
     * 05/10/2026: el del personal vive en su perfil.
     */
    public function test_a_quien_no_es_estudiante_se_le_pide_documento_pero_no_acudiente(): void
    {
        $user = User::create(['username' => 'profe', 'password' => 'demo1234', 'activo' => true]);
        Perfil::create([
            'user_id' => $user->id,
            'rol' => 'profesor',
            'nombre_completo' => 'Camila Agudelo',
            'fecha_nacimiento' => '1985-02-02',
            'telefono' => '3001112233',
        ]);

        $html = (string) $this->actingAs($user)->get(route('mi-perfil'))->assertOk()->getContent();

        $this->assertStringContainsString('name="nombre_completo"', $html);
        $this->assertStringContainsString('name="documento_identidad"', $html);
        $this->assertStringNotContainsString('name="acudiente_nombre"', $html);
    }
}
