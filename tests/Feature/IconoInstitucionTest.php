<?php

namespace Tests\Feature;

use App\Models\ConfiguracionInstitucion;
use App\Support\IconoInstitucion;
use GdImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * El icono del acceso directo y la vista previa al compartir (25/09/2026).
 *
 * Lo que vigila: que salgan del logo de la ENTIDAD (y del de respaldo si no
 * hay), que el icono sea opaco —el iPhone pinta en negro lo transparente—, que
 * un logo roto no tumbe una URL que piden los telefonos sin que nadie mire, y
 * que las paginas publicas, que son las que se comparten, lleven las etiquetas.
 */
class IconoInstitucionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_el_icono_es_un_png_cuadrado_y_opaco_de_cada_lado(): void
    {
        foreach (IconoInstitucion::LADOS as $lado) {
            $icono = $this->imagen($this->get(route('icono-institucion', $lado))
                ->assertOk()
                ->assertHeader('Content-Type', 'image/png')
                ->getContent());

            $this->assertSame([$lado, $lado], [imagesx($icono), imagesy($icono)]);
            // La esquina, fuera del logo: blanca y opaca, no transparente.
            $this->assertSame(0xFFFFFF, imagecolorat($icono, 1, 1) & 0xFFFFFF);
            $this->assertSame(0, (imagecolorat($icono, 1, 1) >> 24) & 0x7F);
        }
    }

    /** Solo los tres lados: cualquier otro numero no es una imagen a medida. */
    public function test_un_lado_que_no_se_sirve_es_404(): void
    {
        $this->get('/icono-7.png')->assertNotFound();
        $this->get('/icono-4000.png')->assertNotFound();
    }

    public function test_el_icono_sale_del_logo_de_la_entidad(): void
    {
        $this->logo($this->pngAzul());

        $icono = $this->imagen($this->get(route('icono-institucion', 192))->getContent());

        $this->assertTrue($this->esAzul(imagecolorat($icono, 96, 96)), 'El centro del icono no es el logo propio.');
    }

    /** Sin logo propio, el de la cabecera: nunca un icono en blanco. */
    public function test_sin_logo_propio_usa_el_de_respaldo(): void
    {
        $sinLogo = (string) $this->get(route('icono-institucion', 512))->getContent();

        $this->assertFalse($this->todoBlanco($this->imagen($sinLogo)));

        // Identico al que sale subiendo ESE MISMO archivo como logo propio: es
        // el de la cabecera y no otro.
        Storage::disk('local')->put('institucion/copia.webp', (string) file_get_contents(public_path('img/logo.webp')));
        $this->logo('institucion/copia.webp');

        $this->assertSame($sinLogo, (string) $this->get(route('icono-institucion', 512))->getContent());
    }

    public function test_un_logo_roto_cae_al_de_respaldo_en_vez_de_fallar(): void
    {
        Storage::disk('local')->put('institucion/roto.webp', 'esto no es una imagen');
        $this->logo('institucion/roto.webp');

        $icono = $this->imagen($this->get(route('icono-institucion', 192))->assertOk()->getContent());

        $this->assertFalse($this->todoBlanco($icono));
    }

    public function test_la_imagen_de_compartir_mide_lo_que_piden_las_redes(): void
    {
        $imagen = $this->imagen($this->get(route('imagen-compartir'))->assertOk()->getContent());

        $this->assertSame([1200, 630], [imagesx($imagen), imagesy($imagen)]);
    }

    public function test_el_manifiesto_lleva_el_nombre_y_los_iconos_con_version(): void
    {
        $configuracion = ConfiguracionInstitucion::actual();
        $configuracion->nombre_institucion = 'Casa de la Cultura de Prueba';
        $configuracion->save();

        $manifiesto = $this->get(route('manifiesto'))->assertOk()->json();

        $this->assertSame('Casa de la Cultura de Prueba', $manifiesto['name']);
        // `browser`: lo pedido es el icono, no cambiar como abre la aplicacion.
        $this->assertSame('browser', $manifiesto['display']);
        $this->assertCount(2, $manifiesto['icons']);
        $this->assertStringContainsString('?v='.IconoInstitucion::version(), $manifiesto['icons'][0]['src']);
    }

    /** La pagina que se comparte (entrar) lleva el icono y la vista previa. */
    public function test_la_pagina_publica_lleva_las_etiquetas(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('rel="apple-touch-icon"', false)
            ->assertSee('rel="manifest"', false)
            ->assertSee('property="og:image" content="'.route('imagen-compartir').'?v='.IconoInstitucion::version(), false);
    }

    /** Cambiar el logo cambia la URL: si no, el telefono se queda con el viejo. */
    public function test_la_version_cambia_con_el_logo(): void
    {
        $antes = IconoInstitucion::version();
        $this->logo($this->pngAzul());

        $this->assertNotSame($antes, IconoInstitucion::version());
    }

    // ------------------------------------------------------------------

    private function logo(string $ruta): void
    {
        $configuracion = ConfiguracionInstitucion::actual();
        $configuracion->logo = $ruta;
        $configuracion->save();
    }

    private function pngAzul(): string
    {
        $lienzo = imagecreatetruecolor(100, 100);
        imagefill($lienzo, 0, 0, (int) imagecolorallocate($lienzo, 0, 40, 220));
        ob_start();
        imagepng($lienzo);
        Storage::disk('local')->put('institucion/logo-azul.png', (string) ob_get_clean());

        return 'institucion/logo-azul.png';
    }

    private function imagen(string|false $png): GdImage
    {
        $imagen = imagecreatefromstring((string) $png);
        $this->assertNotFalse($imagen);

        return $imagen;
    }

    /**
     * AZUL y no rojo a proposito: el logo de respaldo tiene rojo justo en el
     * centro, y con un logo de prueba rojo la prueba pasaba aunque se ignorara
     * el logo propio. El respaldo no tiene nada azul.
     */
    private function esAzul(int $color): bool
    {
        return ($color & 0xFF) > 180 && (($color >> 16) & 0xFF) < 60;
    }

    private function todoBlanco(GdImage $imagen): bool
    {
        for ($y = 0; $y < imagesy($imagen); $y += 4) {
            for ($x = 0; $x < imagesx($imagen); $x += 4) {
                if ((imagecolorat($imagen, $x, $y) & 0xFFFFFF) !== 0xFFFFFF) {
                    return false;
                }
            }
        }

        return true;
    }
}
