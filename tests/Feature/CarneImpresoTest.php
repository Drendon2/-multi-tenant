<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\ConfiguracionInstitucion;
use App\Models\Grupo;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Models\User;
use App\Support\CarneQr;
use GdImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * El carne impreso (25/09/2026): el nombre largo de la institucion, el logo, y
 * la hoja carta de nueve carnes por grupo o por promotoria.
 *
 * Lo que vigila:
 *
 * 1. QUE NADA SE SALGA DEL CARNE. El nombre de la institucion en produccion no
 *    cabia en un renglon y GD lo dibujaba fuera del lienzo sin avisar. Se mide
 *    mirando los PIXELES del borde, no contando renglones: lo que se ve cortado
 *    es lo que hay que comprobar.
 * 2. QUE EL LOGO SALGA, y que un logo que no se puede leer no deje a nadie sin
 *    carne.
 * 3. QUE LA HOJA SEA SOLO DE ADMINISTRACION y traiga a quien tiene que traer:
 *    los del periodo en curso, una vez cada uno.
 */
class CarneImpresoTest extends TestCase
{
    use RefreshDatabase;

    /** El nombre real de produccion que se cortaba. */
    private const NOMBRE_LARGO = 'Casa de la Cultura Luis Norberto Gómez Ramírez de El Santuario';

    private Periodo $periodo;

    private Perfil $admin;

    private Perfil $profesor;

    private Promotoria $piano;

    private Grupo $manana;

    private Grupo $tarde;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->periodo = Periodo::create([
            'nombre' => '2026-2',
            'fecha_inicio' => '2026-07-15',
            'fecha_fin' => '2026-12-15',
            'activo' => true,
        ]);

        $this->admin = $this->perfil('jefa', 'administrador');
        $this->profesor = $this->perfil('profe', 'profesor');

        $this->piano = Promotoria::create([
            'nombre' => 'Piano',
            'area_id' => Area::create(['nombre' => 'Música'])->id,
            'profesor_id' => $this->profesor->id,
        ]);

        $this->manana = $this->grupo('Mañana');
        $this->tarde = $this->grupo('Tarde');
    }

    // ------------------------------------------------------------------
    // 1. El nombre largo
    // ------------------------------------------------------------------

    public function test_el_nombre_largo_de_la_institucion_no_se_sale_del_carne(): void
    {
        $this->institucion(self::NOMBRE_LARGO);

        $carne = $this->imagen(CarneQr::carne($this->perfil('ana', 'estudiante')));

        // Las dos franjas de los lados tienen que quedar en blanco: un texto
        // que no cabe se dibuja encima de ellas antes de salirse del lienzo.
        $this->assertTrue($this->franjaEnBlanco($carne, 0, 12), 'El texto toca el borde izquierdo.');
        $this->assertTrue($this->franjaEnBlanco($carne, imagesx($carne) - 12, imagesx($carne)), 'El texto toca el borde derecho.');
    }

    /** Partido en renglones, el carne crece: no se encoge la letra. */
    public function test_el_nombre_largo_parte_en_renglones_y_el_carne_crece(): void
    {
        $ana = $this->perfil('ana', 'estudiante');

        $this->institucion('Casa de la Cultura');
        $corto = imagesy($this->imagen(CarneQr::carne($ana)));

        $this->institucion(self::NOMBRE_LARGO);
        $largo = imagesy($this->imagen(CarneQr::carne($ana)));

        $this->assertGreaterThan($corto, $largo);
    }

    // ------------------------------------------------------------------
    // 2. El logo
    // ------------------------------------------------------------------

    public function test_el_carne_lleva_el_logo_de_la_institucion(): void
    {
        $ana = $this->perfil('ana', 'estudiante');
        $this->institucion('Casa de la Cultura');

        $sinLogo = $this->imagen(CarneQr::carne($ana));
        $this->assertFalse($this->hayRojo($sinLogo));

        $this->institucion('Casa de la Cultura', $this->logoRojo());
        $conLogo = $this->imagen(CarneQr::carne($ana));

        $this->assertTrue($this->hayRojo($conLogo), 'El logo no aparece en el carné.');
        $this->assertGreaterThan(imagesy($sinLogo), imagesy($conLogo));
    }

    /** Un logo roto no deja a nadie sin carne: sale sin logo. */
    public function test_un_logo_que_no_se_puede_leer_no_rompe_el_carne(): void
    {
        Storage::disk('local')->put('institucion/roto.webp', 'esto no es una imagen');
        $this->institucion('Casa de la Cultura', 'institucion/roto.webp');

        $carne = $this->imagen(CarneQr::carne($this->perfil('ana', 'estudiante')));

        $this->assertFalse($this->hayRojo($carne));
    }

    // ------------------------------------------------------------------
    // 3. La hoja carta
    // ------------------------------------------------------------------

    /** Los carnes de cuarenta estudiantes de una vez: nunca para el profesor. */
    public function test_la_hoja_de_carnes_es_solo_de_administracion(): void
    {
        foreach ([$this->profesor, $this->perfil('dire', 'director')] as $quien) {
            $this->actingAs($quien->user)->get(route('carnes-grupo', $this->manana))->assertRedirect();
            $this->actingAs($quien->user)->get(route('carnes-promotoria', $this->piano))->assertRedirect();
        }

        $this->actingAs($this->admin->user)
            ->get(route('carnes-grupo', $this->manana))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    /** Solo el periodo en curso: el grupo no tiene periodo, la matricula si. */
    public function test_la_hoja_del_grupo_trae_solo_a_los_inscritos_de_ahora(): void
    {
        $this->inscribir($this->perfil('ana', 'estudiante'), [$this->manana]);
        $this->inscribir($this->perfil('luis', 'estudiante'), [$this->manana]);
        $this->inscribir($this->perfil('otro', 'estudiante'), [$this->tarde]);

        $anterior = Periodo::create([
            'nombre' => '2026-1',
            'fecha_inicio' => '2026-01-15',
            'fecha_fin' => '2026-06-15',
            'activo' => false,
        ]);
        $this->inscribir($this->perfil('vieja', 'estudiante'), [$this->manana], $anterior);

        $this->assertSame(2, $this->carnesEnLaHoja(route('carnes-grupo', $this->manana)));
    }

    /** Quien esta en dos grupos de la promotoria sale UNA vez. */
    public function test_la_hoja_de_la_promotoria_no_repite_a_quien_esta_en_dos_grupos(): void
    {
        $this->inscribir($this->perfil('ana', 'estudiante'), [$this->manana, $this->tarde]);
        $this->inscribir($this->perfil('luis', 'estudiante'), [$this->tarde]);
        // Sin grupo todavia: tambien necesita su carne.
        $this->inscribir($this->perfil('sin', 'estudiante'), []);

        $this->assertSame(3, $this->carnesEnLaHoja(route('carnes-promotoria', $this->piano)));
    }

    public function test_los_botones_solo_los_ve_administracion(): void
    {
        $this->actingAs($this->admin->user)
            ->get(route('grupo-estudiantes', $this->manana))
            ->assertSee(route('carnes-grupo', $this->manana), false);

        $this->actingAs($this->admin->user)
            ->get(route('grupos-por-promotoria', $this->piano))
            ->assertSee(route('carnes-promotoria', $this->piano), false);

        $director = $this->perfil('dire', 'director');
        $this->actingAs($director->user)
            ->get(route('grupo-estudiantes', $this->manana))
            ->assertDontSee(route('carnes-grupo', $this->manana), false);
    }

    /**
     * La vista del grupo —sus clases y su asistencia, adonde lleva Horarios—
     * tambien ofrece los carnes (03/10/2026, pedido del usuario), y tambien
     * solo a administracion. El profesor y el director ENTRAN a esa pantalla
     * (se comprueba con `assertOk`): sin eso, el `assertDontSee` pasaria por
     * la barrera equivocada, la de la puerta y no la del boton.
     */
    public function test_la_vista_de_clases_del_grupo_ofrece_los_carnes_solo_a_administracion(): void
    {
        $this->actingAs($this->admin->user)
            ->get(route('grupo-clases', $this->manana))
            ->assertOk()
            ->assertSee(route('carnes-grupo', $this->manana), false);

        $director = $this->dirige($this->perfil('dire', 'director'));
        foreach ([$this->profesor, $director] as $quien) {
            $this->actingAs($quien->user)
                ->get(route('grupo-clases', $this->manana))
                ->assertOk()
                ->assertDontSee(route('carnes-grupo', $this->manana), false);
        }
    }

    /**
     * NUEVE CABEN EN UNA HOJA, y el decimo abre la segunda. Se cuenta en el
     * PDF y no se deduce de la cuenta de filas: la primera version tenia las
     * tres filas midiendo el alto exacto de la hoja, los bordes las empujaban
     * medio punto y la tercera fila saltaba sola a otra pagina — 11 carnes en
     * tres hojas, con la primera a dos tercios.
     */
    public function test_nueve_carnes_caben_en_una_hoja_carta(): void
    {
        $this->institucion(self::NOMBRE_LARGO, $this->logoRojo());

        for ($i = 0; $i < 9; $i++) {
            $this->inscribir($this->perfil("e{$i}", 'estudiante'), [$this->manana]);
        }
        $this->assertSame(1, $this->paginas(route('carnes-grupo', $this->manana)));

        $this->inscribir($this->perfil('decimo', 'estudiante'), [$this->manana]);
        $this->assertSame(2, $this->paginas(route('carnes-grupo', $this->manana)));
    }

    private function paginas(string $url): int
    {
        $pdf = (string) $this->actingAs($this->admin->user)->get($url)->assertOk()->getContent();

        return preg_match_all('#/Type\s*/Page[^s]#', $pdf);
    }

    // ------------------------------------------------------------------

    /** Cuantos carnes trae el PDF: uno por imagen incrustada. */
    private function carnesEnLaHoja(string $url): int
    {
        $pdf = $this->actingAs($this->admin->user)->get($url)->assertOk()->getContent();

        return substr_count((string) $pdf, '/Subtype /Image');
    }

    private function institucion(string $nombre, string $logo = ''): void
    {
        $configuracion = ConfiguracionInstitucion::actual();
        $configuracion->nombre_institucion = $nombre;
        $configuracion->logo = $logo;
        $configuracion->save();
    }

    private function logoRojo(): string
    {
        $lienzo = imagecreatetruecolor(200, 80);
        imagefill($lienzo, 0, 0, (int) imagecolorallocate($lienzo, 220, 0, 0));
        ob_start();
        imagepng($lienzo);
        Storage::disk('local')->put('institucion/logo.png', (string) ob_get_clean());

        return 'institucion/logo.png';
    }

    private function imagen(string $png): GdImage
    {
        $imagen = imagecreatefromstring($png);
        $this->assertNotFalse($imagen);

        return $imagen;
    }

    private function franjaEnBlanco(GdImage $imagen, int $desde, int $hasta): bool
    {
        for ($x = $desde; $x < $hasta; $x++) {
            for ($y = 0; $y < imagesy($imagen); $y++) {
                if ((imagecolorat($imagen, $x, $y) & 0xFFFFFF) !== 0xFFFFFF) {
                    return false;
                }
            }
        }

        return true;
    }

    /** El carne es blanco, negro y gris: el rojo solo puede venir del logo. */
    private function hayRojo(GdImage $imagen): bool
    {
        for ($y = 0; $y < min(200, imagesy($imagen)); $y += 2) {
            for ($x = 0; $x < imagesx($imagen); $x += 4) {
                $c = imagecolorat($imagen, $x, $y);
                if ((($c >> 16) & 0xFF) > 180 && (($c >> 8) & 0xFF) < 60) {
                    return true;
                }
            }
        }

        return false;
    }

    private function grupo(string $nombre): Grupo
    {
        return Grupo::create([
            'promotoria_id' => $this->piano->id,
            'nombre' => $nombre,
            'nivel' => 'basico',
            'salon' => 'A1',
            'cupo_maximo' => 20,
        ]);
    }

    /** @param  list<Grupo>  $grupos */
    private function inscribir(Perfil $estudiante, array $grupos, ?Periodo $periodo = null): void
    {
        $matricula = new Matricula([
            'estudiante_id' => $estudiante->id,
            'promotoria_id' => $this->piano->id,
            'periodo_id' => ($periodo ?? $this->periodo)->id,
            'estado' => Matricula::ACTIVA,
        ]);
        $matricula->save();

        if ($grupos !== []) {
            $matricula->repartirEn(array_map(fn (Grupo $g) => $g->id, $grupos));
        }
    }

    private function perfil(string $username, string $rol): Perfil
    {
        $user = User::create(['username' => $username, 'password' => 'x', 'activo' => true]);

        /** @var Perfil $perfil */
        $perfil = Perfil::create([
            'user_id' => $user->id,
            'nombre_completo' => ucfirst($username).' Pérez',
            'rol' => $rol,
            'telefono' => '3001112233',
            'fecha_nacimiento' => '1995-05-05',
        ]);

        return $perfil;
    }
}
