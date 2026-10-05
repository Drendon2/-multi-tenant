<?php

namespace Tests\Feature;

use App\Models\Perfil;
use App\Models\User;
use App\Support\GestionAsistida;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Al profesor o director sin documento o sin correo no se le deja seguir hasta
 * que los escriba (05/10/2026, decision del usuario).
 */
class DatosDelPersonalTest extends TestCase
{
    use RefreshDatabase;

    protected bool $conBarreraDeDatosDelPersonal = true;

    private function perfil(string $username, string $rol, ?string $correo = null, ?string $documento = null): Perfil
    {
        $user = User::create([
            'username' => $username,
            'password' => 'secreto123',
            'email' => $correo,
            'activo' => true,
        ]);

        return Perfil::create([
            'user_id' => $user->id,
            'rol' => $rol,
            'nombre_completo' => ucfirst($username).' Prueba',
            'fecha_nacimiento' => '1980-01-01',
            'telefono' => '3001112233',
            'documento_identidad' => $documento,
        ]);
    }

    public function test_al_profesor_sin_datos_no_lo_deja_seguir(): void
    {
        $profe = $this->perfil('profe', 'profesor');

        $this->actingAs($profe->user)->get(route('panel'))
            ->assertRedirect(route('completar-datos'));
        $this->actingAs($profe->user)->get(route('mi-perfil'))
            ->assertRedirect(route('completar-datos'));

        $this->actingAs($profe->user)->get(route('completar-datos'))
            ->assertOk()
            ->assertSee('name="documento_identidad"', false)
            ->assertSee('name="correo"', false);
    }

    /** Basta con que falte UNO de los dos. */
    public function test_tambien_si_solo_falta_uno(): void
    {
        $conCorreo = $this->perfil('con.correo', 'director', correo: 'a@correo.com');
        $conDocumento = $this->perfil('con.documento', 'profesor', documento: '71222333');

        $this->actingAs($conCorreo->user)->get(route('panel'))
            ->assertRedirect(route('completar-datos'));
        $this->actingAs($conDocumento->user)->get(route('panel'))
            ->assertRedirect(route('completar-datos'));
    }

    /** Un formulario de otra pantalla tampoco pasa: la barrera no es solo de GET. */
    public function test_tampoco_deja_guardar_otra_cosa(): void
    {
        $profe = $this->perfil('profe', 'profesor');

        $this->actingAs($profe->user)
            ->post(route('mi-perfil.guardar'), ['accion' => 'contacto', 'telefono' => '3009998877'])
            ->assertRedirect(route('completar-datos'));

        $this->assertSame('3001112233', $profe->fresh()->telefono);
    }

    public function test_al_llenarlos_sigue_a_su_pantalla(): void
    {
        $profe = $this->perfil('profe', 'profesor');

        $this->actingAs($profe->user)->post(route('completar-datos.guardar'), [
            'documento_identidad' => '71222333',
            'correo' => 'profe@correo.com',
        ])->assertRedirect(route('post-login'));

        $profe->refresh();
        $this->assertSame('71222333', $profe->documento_identidad);
        $this->assertSame('profe@correo.com', $profe->user->email);

        $this->actingAs($profe->user)->get(route('panel'))->assertOk();
    }

    public function test_los_dos_son_obligatorios(): void
    {
        $profe = $this->perfil('profe', 'profesor');

        $this->actingAs($profe->user)->post(route('completar-datos.guardar'), [
            'documento_identidad' => '',
            'correo' => '',
        ])->assertSessionHasErrors(['documento_identidad', 'correo']);

        $this->assertNull($profe->fresh()->documento_identidad);
    }

    public function test_el_documento_de_otro_del_personal_se_rechaza(): void
    {
        $this->perfil('ya.esta', 'profesor', 'ya@correo.com', '71222333');
        $profe = $this->perfil('profe', 'profesor');

        $this->actingAs($profe->user)->post(route('completar-datos.guardar'), [
            'documento_identidad' => '71222333',
            'correo' => 'profe@correo.com',
        ])->assertSessionHasErrors('documento_identidad');
    }

    /** Sin la salida, la barrera encierra. */
    public function test_puede_cerrar_sesion(): void
    {
        $profe = $this->perfil('profe', 'profesor');

        $this->actingAs($profe->user)->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    /**
     * El administrador no pasa por aqui —es quien arregla lo de los demas—, ni
     * el estudiante, ni quien espera rol.
     */
    public function test_no_alcanza_a_los_demas_roles(): void
    {
        $admin = $this->perfil('admin', 'administrador');
        $estudiante = $this->perfil('estu', 'estudiante');
        $sinRol = $this->perfil('nuevo', '');

        $this->actingAs($admin->user)->get(route('gestion-inicio'))->assertOk();
        $this->actingAs($estudiante->user)->get(route('mi-perfil'))->assertOk();
        $this->actingAs($sinRol->user)->get(route('pendiente-aprobacion'))->assertOk();

        // Y a la pantalla misma no hay nada que hacer: se le devuelve.
        $this->actingAs($admin->user)->get(route('completar-datos'))
            ->assertRedirect(route('post-login'));
    }

    /** En gestion asistida el administrador no sabe el documento de nadie. */
    public function test_no_se_aplica_en_gestion_asistida(): void
    {
        $admin = $this->perfil('admin', 'administrador');
        $profe = $this->perfil('profe', 'profesor');

        $this->actingAs($admin->user)->post(route('gestion-asistida-iniciar', $profe));
        $this->assertTrue(GestionAsistida::activa());

        $this->get(route('panel'))->assertOk();
    }

    /** Desde Mi perfil se corrige, y no se puede dejar vacio. */
    public function test_mi_perfil_corrige_el_documento_y_no_lo_deja_vacio(): void
    {
        $profe = $this->perfil('profe', 'profesor', 'profe@correo.com', '71222333');
        $datos = ['accion' => 'datos', 'nombre_completo' => 'Profe Prueba', 'fecha_nacimiento' => '1980-01-01'];

        $this->actingAs($profe->user)
            ->post(route('mi-perfil.guardar'), [...$datos, 'documento_identidad' => '71222334'])
            ->assertSessionHasNoErrors();
        $this->assertSame('71222334', $profe->fresh()->documento_identidad);

        $this->actingAs($profe->user)
            ->post(route('mi-perfil.guardar'), [...$datos, 'documento_identidad' => ''])
            ->assertSessionHasErrors('documento_identidad');
        $this->assertSame('71222334', $profe->fresh()->documento_identidad);
    }

    /** El correo tampoco, aunque la institucion no lo exija a los demas. */
    public function test_mi_perfil_no_deja_vaciar_el_correo(): void
    {
        $profe = $this->perfil('profe', 'profesor', 'profe@correo.com', '71222333');

        $this->actingAs($profe->user)
            ->post(route('mi-perfil.guardar'), ['accion' => 'correo', 'correo' => ''])
            ->assertSessionHasErrors('correo');
        $this->assertSame('profe@correo.com', $profe->user->fresh()->email);
    }

    /** El administrador lo ve y lo escribe desde Gestion → Usuarios. */
    public function test_el_administrador_lo_escribe_desde_usuarios(): void
    {
        $admin = $this->perfil('admin', 'administrador');
        $profe = $this->perfil('profe', 'profesor', 'profe@correo.com', '71222333');

        $this->actingAs($admin->user)->get(route('usuario-editar', $profe))
            ->assertOk()
            ->assertSee('value="71222333"', false);

        $this->actingAs($admin->user)->post(route('usuario-editar', $profe), [
            'username' => 'profe',
            'password' => '',
            'rol' => 'profesor',
            'correo' => 'profe@correo.com',
            'nombre_completo' => 'Profe Prueba',
            'fecha_nacimiento' => '1980-01-01',
            'telefono' => '3001112233',
            'documento_identidad' => '80111222',
        ])->assertRedirect();

        $this->assertSame('80111222', $profe->fresh()->documento_identidad);
    }
}
