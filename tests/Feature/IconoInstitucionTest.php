<?php

namespace Tests\Feature;

use App\Models\ConfiguracionInstitucion;
use App\Models\Perfil;
use App\Models\User;
use App\Support\IconoInstitucion;
use App\Support\LogoInstitucion;
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

    /** Sin logo propio, las iniciales: el mismo respaldo que la cabecera. */
    public function test_sin_logo_propio_usa_las_iniciales(): void
    {
        $sinLogo = (string) $this->get(route('icono-institucion', 512))->getContent();

        $this->assertFalse($this->todoBlanco($this->imagen($sinLogo)));

        // Identico al que sale subiendo ESE MISMO logo generado como propio:
        // es el de `LogoInstitucion` y no otro.
        $configuracion = ConfiguracionInstitucion::actual();
        Storage::disk('local')->put('institucion/generado.png', LogoInstitucion::generado($configuracion->nombre_institucion, $configuracion->color_acento));
        $this->logo('institucion/generado.png');

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

    // -----------------------------------------------------------------------
    // El nombre corto (05/10/2026)

    private function nombres(string $largo, string $corto): void
    {
        $configuracion = ConfiguracionInstitucion::actual();
        $configuracion->nombre_institucion = $largo;
        $configuracion->nombre_corto = $corto;
        $configuracion->save();
    }

    /** Bajo el icono caben unos doce caracteres: ahi va el corto. */
    public function test_el_icono_lleva_el_nombre_corto(): void
    {
        $this->nombres('Casa de la Cultura Luis Norberto Gómez', 'Cultura');

        $manifiesto = $this->get(route('manifiesto'))->assertOk()->json();
        $this->assertSame('Cultura', $manifiesto['short_name']);
        $this->assertSame('Casa de la Cultura Luis Norberto Gómez', $manifiesto['name']);

        $this->get(route('login'))->assertSee('<meta name="apple-mobile-web-app-title" content="Cultura">', false);
    }

    /** Vacio es como era hasta hoy: el nombre de la institucion. */
    public function test_sin_nombre_corto_el_icono_lleva_el_largo(): void
    {
        $this->nombres('Casa de la Cultura de Prueba', '');

        $this->assertSame('Casa de la Cultura de Prueba', $this->get(route('manifiesto'))->json()['short_name']);
        $this->get(route('login'))->assertSee('<meta name="apple-mobile-web-app-title" content="Casa de la Cultura de Prueba">', false);
    }

    /** Cambiar el nombre corto cambia la URL del manifiesto, como el logo. */
    public function test_cambiar_el_nombre_corto_cambia_la_url_del_manifiesto(): void
    {
        $url = fn () => preg_match('#rel="manifest" href="([^"]+)"#', (string) $this->get(route('login'))->getContent(), $m) ? $m[1] : '';

        $this->nombres('Casa de la Cultura', 'Cultura');
        $antes = $url();
        $this->nombres('Casa de la Cultura', 'Casa Cultura');

        $this->assertNotSame('', $antes);
        $this->assertNotSame($antes, $url());
    }

    public function test_el_nombre_corto_se_guarda_desde_institucion(): void
    {
        $user = User::create(['username' => 'jefa', 'password' => 'x', 'activo' => true]);
        Perfil::create([
            'user_id' => $user->id, 'rol' => 'administrador', 'nombre_completo' => 'Jefa',
            'fecha_nacimiento' => '1980-01-01', 'telefono' => '3000000000',
        ]);

        $this->actingAs($user)->post(route('gestion-configuracion'), [
            'nombre_institucion' => 'Casa de la Cultura',
            'nombre_corto' => '  Cultura  ',
            'color_acento' => '#0a7a59',
            'limite_promotorias_por_periodo' => 2,
            'faltas_para_abandono' => 5,
        ])->assertSessionHas('success');

        $this->assertSame('Cultura', ConfiguracionInstitucion::actual()->fresh()->nombre_corto);
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
     * AZUL y no rojo a proposito: el logo de respaldo de entonces tenia rojo
     * justo en el centro, y con un logo de prueba rojo la prueba pasaba aunque
     * se ignorara el logo propio. Las iniciales son blancas sobre el verde de
     * fabrica: tampoco tienen nada azul.
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
