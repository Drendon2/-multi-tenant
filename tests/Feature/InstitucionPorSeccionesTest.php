<?php

namespace Tests\Feature;

use App\Models\ConfiguracionInstitucion;
use App\Models\Perfil;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Gestion → Institucion guarda POR SECCION (05/10/2026, pedido del usuario:
 * «botones individuales de guardado y al guardar debe cambiar
 * inmediatamente», y «al cargar debe volver al mismo lugar»).
 *
 * Cada formulario manda su `seccion`; el controlador valida y escribe solo
 * esos campos y vuelve a su ancla, tambien al rechazar.
 */
class InstitucionPorSeccionesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create(['username' => 'jefa', 'password' => 'x', 'activo' => true]);
        Perfil::create([
            'user_id' => $this->admin->id, 'rol' => 'administrador', 'nombre_completo' => 'Jefa',
            'fecha_nacimiento' => '1980-01-01', 'telefono' => '3000000000',
        ]);
    }

    /** Guardar la marca no toca las reglas, aunque lleguen sus campos. */
    public function test_una_seccion_no_toca_las_demas(): void
    {
        $antes = ConfiguracionInstitucion::actual()->limite_promotorias_por_periodo;

        $this->actingAs($this->admin)->post(route('gestion-configuracion'), [
            'seccion' => 'marca',
            'nombre_institucion' => 'Casa Nueva',
            'limite_promotorias_por_periodo' => $antes + 3,
        ])->assertRedirect(route('gestion-configuracion').'#marca');

        $config = ConfiguracionInstitucion::actual()->fresh();
        $this->assertSame('Casa Nueva', $config->nombre_institucion);
        $this->assertSame($antes, $config->limite_promotorias_por_periodo);
    }

    /** Y al reves: las reglas no exigen el nombre, que es de otra seccion. */
    public function test_cada_seccion_exige_solo_lo_suyo(): void
    {
        $this->actingAs($this->admin)->post(route('gestion-configuracion'), [
            'seccion' => 'reglas',
            'limite_promotorias_por_periodo' => 3,
        ])->assertSessionHasNoErrors()->assertRedirect(route('gestion-configuracion').'#reglas');

        $this->assertSame(3, ConfiguracionInstitucion::actual()->fresh()->limite_promotorias_por_periodo);
    }

    /** El rechazo vuelve a su seccion, no arriba: el aviso se ve. */
    public function test_el_rechazo_vuelve_a_su_seccion(): void
    {
        $this->actingAs($this->admin)->post(route('gestion-configuracion'), [
            'seccion' => 'alertas',
            'faltas_para_abandono' => 99,
        ])
            ->assertRedirect(route('gestion-configuracion').'#alertas')
            ->assertSessionHasErrors('faltas_para_abandono');
    }

    /**
     * La clave del correo no se guarda en la sesion al rechazar: no se llama
     * `password` y Laravel no la excluye sola (trampa escrita en CLAUDE.md).
     */
    public function test_el_rechazo_del_correo_no_guarda_la_clave_en_la_sesion(): void
    {
        $this->actingAs($this->admin)->post(route('gestion-configuracion'), [
            'seccion' => 'correo',
            'correo_servidor' => 'https://mal escrito',
            'correo_clave' => 'la-del-buzon',
        ])
            ->assertSessionHasErrors('correo_servidor')
            ->assertSessionMissing('_old_input.correo_clave');
    }

    /** La seccion plegada se abre al volver de guardarla: si no, no se ve que guardo. */
    public function test_los_datos_de_la_entidad_se_abren_al_volver(): void
    {
        $respuesta = $this->actingAs($this->admin)->post(route('gestion-configuracion'), [
            'seccion' => 'entidad',
            'entidad_nit' => '900123456',
        ]);

        $html = (string) $this->followRedirects($respuesta)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<details[^>]*id="bloque-datos-entidad"[^>]*\sopen#', $html);

        // Y sin haber guardado nada, plegada.
        $html = (string) $this->actingAs($this->admin)->get(route('gestion-configuracion'))->getContent();
        $this->assertDoesNotMatchRegularExpression('#<details[^>]*id="bloque-datos-entidad"[^>]*\sopen#', $html);
    }

    public function test_una_seccion_que_no_existe_es_404(): void
    {
        $this->actingAs($this->admin)->post(route('gestion-configuracion'), ['seccion' => 'todo'])->assertNotFound();
        $this->actingAs($this->admin)->post(route('gestion-configuracion'), [])->assertNotFound();
    }
}
