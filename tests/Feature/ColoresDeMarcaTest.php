<?php

namespace Tests\Feature;

use App\Models\ConfiguracionInstitucion;
use App\Models\Perfil;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * El fondo de pagina y la cabecera propios de la institucion (05/10/2026).
 *
 * Las afirmaciones van atadas a la CLASE `cabecera-propia` y a las variables
 * CSS, no a rotulos. El contraste se exige al guardar; el texto de la cabecera
 * se elige solo.
 */
class ColoresDeMarcaTest extends TestCase
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

    /** @param array<string, mixed> $colores */
    private function guardar(array $colores): TestResponse
    {
        return $this->actingAs($this->admin)->post(route('gestion-configuracion'), [
            'nombre_institucion' => 'Casa de la Cultura',
            'color_acento' => '#0a7a59',
            'limite_promotorias_por_periodo' => 2,
            'faltas_para_abandono' => 5,
        ] + $colores);
    }

    private function pagina(): string
    {
        return (string) $this->actingAs($this->admin)->get(route('mi-perfil'))->assertOk()->getContent();
    }

    public function test_de_fabrica_no_cambia_nada(): void
    {
        $html = $this->pagina();

        $this->assertStringNotContainsString('cabecera-propia', $html);
        $this->assertStringNotContainsString('--cabecera:', $html);
        $this->assertStringNotContainsString('--bg:', $html);
    }

    public function test_un_fondo_y_una_cabecera_propios_se_guardan_y_se_pintan(): void
    {
        $this->guardar(['color_fondo' => '#FFF8E7', 'color_cabecera' => '#123C69'])
            ->assertSessionHasNoErrors();

        $config = ConfiguracionInstitucion::actual()->fresh();
        $this->assertSame('#fff8e7', $config->color_fondo);
        $this->assertSame('#123c69', $config->color_cabecera);

        $html = $this->pagina();
        $this->assertMatchesRegularExpression('/<header\s+class="cabecera-propia"/', $html);
        $this->assertStringContainsString('--bg: light-dark(#fff8e7, #121715);', $html);
        $this->assertStringContainsString('--cabecera: light-dark(#123c69, #1c2421);', $html);
        // Azul oscuro: el texto se pone blanco solo.
        $this->assertStringContainsString('--cabecera-ink: light-dark(#ffffff, #e7ede9);', $html);
    }

    /** Una cabecera clara lleva el texto oscuro de la paleta. */
    public function test_una_cabecera_clara_lleva_texto_oscuro(): void
    {
        $this->guardar(['color_cabecera' => '#ffe08a'])->assertSessionHasNoErrors();

        $this->assertStringContainsString('--cabecera-ink: light-dark(#182420, #e7ede9);', $this->pagina());
    }

    /** El fondo de la pagina publica (entrar) tambien. */
    public function test_el_fondo_llega_a_la_pagina_publica(): void
    {
        $this->guardar(['color_fondo' => '#fff8e7'])->assertSessionHasNoErrors();
        $this->post(route('logout'));

        $this->get(route('login'))->assertSee('--bg: light-dark(#fff8e7, #121715);', false);
    }

    /** Con la casilla «el de fabrica» se vacia, aunque llegue un color. */
    public function test_la_casilla_de_fabrica_los_vacia(): void
    {
        $this->guardar(['color_fondo' => '#fff8e7', 'color_cabecera' => '#123c69'])->assertSessionHasNoErrors();
        $this->guardar([
            'color_fondo' => '#fff8e7', 'color_fondo_fabrica' => '1',
            'color_cabecera' => '#123c69', 'color_cabecera_fabrica' => '1',
        ])->assertSessionHasNoErrors();

        $config = ConfiguracionInstitucion::actual()->fresh();
        $this->assertSame('', $config->color_fondo);
        $this->assertSame('', $config->color_cabecera);
    }

    /** Un fondo oscuro deja ilegible el texto gris de todas las pantallas. */
    public function test_un_fondo_oscuro_se_rechaza(): void
    {
        $this->guardar(['color_fondo' => '#333333'])->assertSessionHasErrors('color_fondo');

        $this->assertSame('', ConfiguracionInstitucion::actual()->fresh()->color_fondo);
    }

    /** Con un gris medio ni el texto blanco ni el oscuro llegan a 4,5:1. */
    public function test_una_cabecera_de_tono_medio_se_rechaza(): void
    {
        $this->guardar(['color_cabecera' => '#808080'])->assertSessionHasErrors('color_cabecera');

        $this->assertSame('', ConfiguracionInstitucion::actual()->fresh()->color_cabecera);
    }

    /** Con la casilla marcada no se mira el contraste del color que llegue. */
    public function test_con_la_casilla_no_se_mira_el_contraste(): void
    {
        $this->guardar(['color_fondo' => '#333333', 'color_fondo_fabrica' => '1'])->assertSessionHasNoErrors();
    }
}
