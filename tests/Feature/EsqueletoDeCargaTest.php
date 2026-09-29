<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * El esqueleto de carga (29/09/2026): el cuerpo de una promotoria del Panel y
 * el modal, mientras llegan.
 *
 * LO QUE ESTA CLASE NO PUEDE VER: que el esqueleto salga y se vaya. PHPUnit no
 * ejecuta JavaScript; eso se comprobo en Chrome retrasando `fetch`. Aqui se
 * vigilan las piezas que alguien puede quitar sin romper nada que vaya a mirar.
 */
class EsqueletoDeCargaTest extends TestCase
{
    /**
     * El esqueleto lo pone el guion, NUNCA la plantilla: sin JavaScript nadie
     * lo quitaria y quedaria una promesa de contenido que no llega. La
     * plantilla deja su texto y el enlace del <noscript>.
     */
    public function test_el_panel_no_trae_el_esqueleto_desde_el_servidor(): void
    {
        $vista = (string) File::get(resource_path('views/panel/index.blade.php'));
        $panel = (string) File::get(public_path('js/panel.js'));

        $this->assertStringNotContainsString('esqueleto', $vista);
        $this->assertStringContainsString('data-cuerpo-cargando', $vista);
        $this->assertStringContainsString('destino.innerHTML = ESQUELETO', $panel);
    }

    /** El modal solo lo ensena si la respuesta tarda, y cerrarlo anula el pedido. */
    public function test_el_modal_espera_antes_de_ensenar_el_esqueleto(): void
    {
        $js = (string) File::get(public_path('js/acciones.js'));

        $this->assertMatchesRegularExpression('/ESPERA_ESQUELETO\s*=\s*\d+/', $js);
        $this->assertStringContainsString('if (turno !== pedidoModal) { return; }', $js);
    }

    /** El brillo se mueve solo con el movimiento permitido. */
    public function test_la_animacion_respeta_el_movimiento_reducido(): void
    {
        $css = (string) File::get(public_path('css/app.css'));

        $this->assertMatchesRegularExpression(
            '/@media \(prefers-reduced-motion: no-preference\) \{\s*\.esqueleto [^{]*\{ animation: esqueleto-brillo/',
            $css
        );
        $this->assertDoesNotMatchRegularExpression('/^\s*\.esqueleto-linea[^{]*\{[^}]*animation/m', $css);
    }
}
