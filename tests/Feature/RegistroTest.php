<?php

namespace Tests\Feature;

use App\Models\Perfil;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Autorregistro publico de profesor.
 */
class RegistroTest extends TestCase
{
    use RefreshDatabase;

    private array $datos = [
        'username' => 'profe.nuevo',
        'password' => 'secreto123',
        'password_confirmation' => 'secreto123',
        'nombre_completo' => 'Marta Solis',
        'fecha_nacimiento' => '1985-03-12',
        'telefono' => '3001234567',
        'documento_identidad' => '43123456',
        'correo' => 'marta@correo.com',
    ];

    /** Documento y correo nacen con la cuenta (05/10/2026). */
    public function test_guarda_el_documento_y_el_correo(): void
    {
        $this->post(route('registro.guardar'), $this->datos)->assertRedirect(route('login'));

        $user = User::where('username', 'profe.nuevo')->first();

        $this->assertSame('marta@correo.com', $user->email);
        $this->assertSame('43123456', $user->perfil->documento_identidad);
    }

    /**
     * Los dos son obligatorios, y el correo aunque la institucion tenga
     * apagado `correo_obligatorio` (que es como nace).
     */
    public function test_sin_documento_o_sin_correo_no_se_crea_la_cuenta(): void
    {
        $this->post(route('registro.guardar'), [...$this->datos, 'documento_identidad' => ''])
            ->assertSessionHasErrors('documento_identidad');
        $this->post(route('registro.guardar'), [...$this->datos, 'correo' => ''])
            ->assertSessionHasErrors('correo');

        $this->assertSame(0, User::count());
    }

    public function test_el_documento_repetido_se_rechaza(): void
    {
        $this->post(route('registro.guardar'), $this->datos);

        $this->post(route('registro.guardar'), [...$this->datos, 'username' => 'otro.profe'])
            ->assertSessionHasErrors('documento_identidad');

        $this->assertSame(1, User::count());
    }

    /** La cuenta nace SIN rol: el acceso lo abre despues un director. */
    public function test_la_cuenta_creada_no_tiene_rol(): void
    {
        $this->post(route('registro.guardar'), $this->datos)
            ->assertRedirect(route('login'))
            ->assertSessionHas('success');

        $user = User::where('username', 'profe.nuevo')->first();

        $this->assertNotNull($user);
        $this->assertTrue($user->activo);
        $this->assertSame('', $user->perfil->rol);
        $this->assertSame('Marta Solis', $user->perfil->nombre_completo);
    }

    public function test_el_usuario_repetido_se_rechaza(): void
    {
        $this->post(route('registro.guardar'), $this->datos);

        $this->post(route('registro.guardar'), $this->datos)
            ->assertSessionHasErrors('username');

        $this->assertSame(1, User::where('username', 'profe.nuevo')->count());
    }

    public function test_las_contrasenas_deben_coincidir(): void
    {
        $this->post(route('registro.guardar'), [...$this->datos, 'password_confirmation' => 'otra'])
            ->assertSessionHasErrors('password');

        $this->assertSame(0, User::count());
    }

    /**
     * Cuenta y perfil nacen juntos o no nacen: una cuenta sin perfil no puede
     * ni pasar por la redireccion posterior al login.
     */
    public function test_no_queda_cuenta_suelta_si_falla_la_validacion(): void
    {
        $this->post(route('registro.guardar'), [...$this->datos, 'nombre_completo' => '']);

        $this->assertSame(0, User::count());
        $this->assertSame(0, Perfil::count());
    }

    /**
     * Los formularios publicos sin sesion no aceptan archivos: es una decision
     * de seguridad del original, no una simplificacion.
     *
     * Se busca el elemento `<input type="file"`, no la cadena `type="file"` a
     * secas: el CSS del layout lleva un selector `input[type="file"]` para las
     * pantallas que si suben archivos, y buscar la cadena suelta daria un falso
     * positivo contra la hoja de estilos.
     */
    public function test_el_registro_no_pide_foto(): void
    {
        $this->get(route('registro'))
            ->assertOk()
            ->assertDontSee('<input type="file"', false)
            ->assertSee('Mi perfil');
    }

    /**
     * Ocho caracteres como minimo.
     *
     * No es un puerto: el original no valida la contrasena por ninguna parte
     * (ver el porque en `AppServiceProvider::exigirContrasenaMinima`). Es un
     * anadido, y esta prueba es lo que impide que se pierda por el camino.
     */
    public function test_una_contrasena_corta_se_rechaza(): void
    {
        $this->post(route('registro.guardar'), [
            ...$this->datos,
            'password' => 'corta7c',
            'password_confirmation' => 'corta7c',
        ])->assertSessionHasErrors('password');

        $this->assertSame(0, User::count());
        $this->assertSame(0, Perfil::count());
    }

    /** Ocho justos entran: el limite es "al menos", no "mas de". */
    public function test_ocho_caracteres_justos_se_admiten(): void
    {
        $this->post(route('registro.guardar'), [
            ...$this->datos,
            'password' => 'ochojust',
            'password_confirmation' => 'ochojust',
        ])->assertSessionHasNoErrors();

        $this->assertNotNull(User::where('username', 'profe.nuevo')->first());
    }

    /**
     * El registro admite cinco por minuto desde una direccion.
     *
     * Aqui el contador SI va por IP y no por cuenta: lo que se frena es dar de
     * alta cuentas en masa, y cada una trae un usuario distinto — contar por
     * usuario no frenaria nada porque nunca se repite.
     */
    public function test_el_registro_se_corta_tras_cinco_altas_seguidas(): void
    {
        for ($n = 1; $n <= 5; $n++) {
            $this->post(route('registro.guardar'), [
                ...$this->datos,
                'username' => "profe.nuevo{$n}",
                // El documento es unico: cada alta trae el suyo.
                'documento_identidad' => "4312345{$n}",
            ])->assertSessionHas('success');
        }

        $this->post(route('registro.guardar'), [
            ...$this->datos,
            'username' => 'profe.nuevo6',
            'documento_identidad' => '431234566',
        ])->assertSessionHas('error');

        // El sexto no llego al controlador: no quedo ni la cuenta ni el perfil.
        $this->assertSame(5, User::count());
        $this->assertNull(User::where('username', 'profe.nuevo6')->first());
    }
}
