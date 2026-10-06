<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Grupo;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Los nombres de promotoria SIN el departamento entre parentesis, y el
 * horario de cada grupo en Gestion (05/10/2026, pedido del usuario: el
 * parentesis «alarga demasiado los nombres y confunde», sobre todo en los
 * formularios).
 */
class NombresDePromotoriaTest extends TestCase
{
    use RefreshDatabase;

    private Perfil $admin;

    private Promotoria $guitarra;

    protected function setUp(): void
    {
        parent::setUp();

        Periodo::create([
            'nombre' => '2026-2', 'fecha_inicio' => now()->subMonth()->toDateString(),
            'fecha_fin' => now()->addMonths(3)->toDateString(), 'activo' => true, 'matriculas_abiertas' => true,
        ]);

        $user = User::create(['username' => 'jefa', 'password' => 'x', 'activo' => true]);
        $this->admin = Perfil::create([
            'user_id' => $user->id, 'rol' => 'administrador', 'nombre_completo' => 'Jefa',
            'fecha_nacimiento' => '1980-01-01', 'telefono' => '3000000000',
        ]);

        $musica = Area::create(['nombre' => 'Música']);
        $this->guitarra = Promotoria::create(['nombre' => 'Guitarra', 'area_id' => $musica->id]);
    }

    public function test_una_promotoria_como_texto_es_solo_su_nombre(): void
    {
        $this->assertSame('Guitarra', (string) $this->guitarra->fresh());
    }

    /** La inscripcion publica: el desplegable de promotorias. */
    public function test_la_inscripcion_no_pone_el_departamento_entre_parentesis(): void
    {
        $this->get(route('inscripcion'))
            ->assertOk()
            ->assertSee('Guitarra')
            ->assertDontSee('Guitarra (Música)');
    }

    /** El formulario de grupo: el desplegable de promotoria. */
    public function test_el_formulario_de_grupo_no_pone_el_departamento(): void
    {
        $this->actingAs($this->admin->user)->get(route('grupo-nuevo'))
            ->assertOk()
            ->assertSee('>Guitarra</option>', false)
            ->assertDontSee('Guitarra (Música)');
    }

    /** Cada grupo, con su dia y su hora pequeños bajo el nombre. */
    public function test_los_grupos_llevan_su_horario_en_gestion(): void
    {
        /** @var Grupo $conHorario */
        $conHorario = $this->guitarra->grupos()->create(['nombre' => 'Martes', 'nivel' => 'basico', 'cupo_maximo' => 10, 'salon' => '']);
        $conHorario->sesiones()->create(['dia' => 2, 'hora_inicio' => '16:00', 'hora_fin' => '18:00']);
        $this->guitarra->grupos()->create(['nombre' => 'Suelto', 'nivel' => 'basico', 'cupo_maximo' => 10, 'salon' => '']);

        $html = (string) $this->actingAs($this->admin->user)->get(route('grupo-lista'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#class="lista-nota lista-nota-bloque lista-nota-sutil">\s*'.preg_quote($conHorario->fresh()->horario, '#').'\s*<#', $html);
        $this->assertMatchesRegularExpression('#class="lista-nota lista-nota-bloque lista-nota-sutil">\s*Sin horario\s*<#', $html);
    }

    /**
     * Dentro de UNA promotoria el grupo va a secas; la lista plana, que mezcla
     * promotorias, lleva el nombre entero.
     */
    public function test_dentro_de_la_promotoria_el_grupo_no_la_repite(): void
    {
        $this->guitarra->grupos()->create(['nombre' => 'Grupo A', 'nivel' => 'basico', 'cupo_maximo' => 10, 'salon' => '']);

        $this->actingAs($this->admin->user)->get(route('grupos-por-promotoria', $this->guitarra))
            ->assertOk()
            ->assertSee('>Grupo A</a>', false)
            ->assertDontSee('Guitarra - Grupo A');

        $this->actingAs($this->admin->user)->get(route('grupo-lista'))
            ->assertSee('Guitarra - Grupo A');
    }
}
