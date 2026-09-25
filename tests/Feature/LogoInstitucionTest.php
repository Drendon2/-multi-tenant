<?php

namespace Tests\Feature;

use App\Models\ConfiguracionInstitucion;
use App\Support\LogoInstitucion;
use GdImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * El logo de la institucion y su respaldo (25/09/2026).
 *
 * Hasta ese dia el respaldo era el logo de la Casa de la Cultura de El
 * Santuario, servido desde `public/`: cualquier otra entidad que no subiera el
 * suyo salia con el de El Santuario en todas partes. Lo que vigila este
 * archivo es que el respaldo salga de LA PROPIA instalacion —sus iniciales y su
 * color— y que nadie se quede sin logo por un archivo roto.
 */
class LogoInstitucionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_las_iniciales_saltan_los_articulos_y_se_quedan_en_dos(): void
    {
        $this->assertSame('CC', LogoInstitucion::iniciales('Casa de la Cultura Luis Norberto Gómez Ramírez de El Santuario'));
        $this->assertSame('FS', LogoInstitucion::iniciales('Fundación Semillas'));
        $this->assertSame('B', LogoInstitucion::iniciales('Bellas'));
        $this->assertSame('ÁE', LogoInstitucion::iniciales('álamo escuela'));
        $this->assertSame('', LogoInstitucion::iniciales('  '));
    }

    /** Sin logo propio, /logo contesta con las iniciales sobre SU color, no un 404. */
    public function test_sin_logo_propio_sirve_el_generado_con_el_color_de_la_institucion(): void
    {
        $this->configurar('Fundación Semillas', '#1d4ed8');

        $logo = $this->imagen($this->get(route('logo-institucion'))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->getContent());

        // Dentro del circulo y lejos de las letras: el color de acento.
        $color = imagecolorat($logo, 40, 160);
        $this->assertSame([0x1D, 0x4E, 0xD8], [($color >> 16) & 0xFF, ($color >> 8) & 0xFF, $color & 0xFF]);
    }

    public function test_con_logo_propio_sirve_el_propio(): void
    {
        $lienzo = imagecreatetruecolor(50, 50);
        ob_start();
        imagewebp($lienzo);
        Storage::disk('local')->put('institucion/logo.webp', (string) ob_get_clean());
        $this->configurar('Casa de la Cultura', '#0a7a59', 'institucion/logo.webp');

        $this->get(route('logo-institucion'))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/webp');
    }

    /** Un archivo que no se puede leer cae a las iniciales, no a un hueco. */
    public function test_un_logo_roto_cae_a_las_iniciales(): void
    {
        Storage::disk('local')->put('institucion/roto.webp', 'esto no es una imagen');
        $this->configurar('Casa de la Cultura', '#0a7a59', 'institucion/roto.webp');

        $this->get(route('logo-institucion'))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }

    /** La cabecera pide el logo por su ruta, con la version: nunca un archivo del proyecto. */
    public function test_la_pagina_publica_pide_el_logo_por_su_ruta(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee(route('logo-institucion').'?v='.LogoInstitucion::version(), false)
            ->assertDontSee('img/logo.webp', false);
    }

    /** Las iniciales salen del nombre y el fondo del color: los dos mueven la version. */
    public function test_la_version_cambia_con_el_nombre_y_con_el_color(): void
    {
        $inicial = LogoInstitucion::version();

        $this->configurar('Otra Institución', '#0a7a59');
        $conOtroNombre = LogoInstitucion::version();

        $this->configurar('Otra Institución', '#1d4ed8');

        $this->assertNotSame($inicial, $conOtroNombre);
        $this->assertNotSame($conOtroNombre, LogoInstitucion::version());
    }

    // ------------------------------------------------------------------

    private function configurar(string $nombre, string $color, string $logo = ''): void
    {
        $configuracion = ConfiguracionInstitucion::actual();
        $configuracion->nombre_institucion = $nombre;
        $configuracion->color_acento = $color;
        $configuracion->logo = $logo;
        $configuracion->save();
    }

    private function imagen(string|false $png): GdImage
    {
        $imagen = imagecreatefromstring((string) $png);
        $this->assertNotFalse($imagen);

        return $imagen;
    }
}
