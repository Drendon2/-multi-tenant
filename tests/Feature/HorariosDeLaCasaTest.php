<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Grupo;
use App\Models\Perfil;
use App\Models\Promotoria;
use App\Models\SesionGrupo;
use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * GESTION → HORARIOS (27/09/2026): que se dicta cada dia, por promotoria.
 *
 * Las afirmaciones van atadas a atributos (`data-dia`, `data-promotoria`,
 * `hidden`, `data-cruce`) y no a rotulos, que se quedan mudos al renombrarlos.
 * El DIA va siempre escrito en la URL: una prueba que dedujera el de hoy
 * heredaria el calendario, y el domingo la pantalla arranca en el lunes.
 */
class HorariosDeLaCasaTest extends TestCase
{
    use RefreshDatabase;

    private Perfil $admin;

    private Perfil $director;

    private Area $musica;

    private Area $danza;

    private Promotoria $piano;

    private Promotoria $violin;

    private Promotoria $ballet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->musica = Area::create(['nombre' => 'Música']);
        $this->danza = Area::create(['nombre' => 'Danza']);

        $profe = $this->perfil('profe', 'profesor');
        $this->piano = Promotoria::create(['nombre' => 'Piano', 'area_id' => $this->musica->id, 'profesor_id' => $profe->id]);
        $this->violin = Promotoria::create(['nombre' => 'Violín', 'area_id' => $this->musica->id]);
        $this->ballet = Promotoria::create(['nombre' => 'Ballet', 'area_id' => $this->danza->id]);

        $this->admin = $this->perfil('jefa', 'administrador');
        $this->director = $this->perfil('dire', 'director');
        $this->dirige($this->director, $this->musica);
    }

    private function perfil(string $username, string $rol): Perfil
    {
        $user = User::create(['username' => $username, 'password' => 'demo1234', 'activo' => true]);

        return Perfil::create([
            'user_id' => $user->id,
            'rol' => $rol,
            'nombre_completo' => ucfirst($username).' Ruiz',
            'fecha_nacimiento' => Carbon::today()->subYears(35)->toDateString(),
            'telefono' => '3001112233',
        ]);
    }

    /** @param  list<array{0: int, 1: string, 2: string}>  $sesiones */
    private function grupo(Promotoria $promotoria, string $nombre, string $salon, array $sesiones): Grupo
    {
        $grupo = Grupo::create([
            'promotoria_id' => $promotoria->id,
            'nombre' => $nombre,
            'nivel' => 'basico',
            'salon' => $salon,
            'cupo_maximo' => 10,
        ]);

        foreach ($sesiones as [$dia, $desde, $hasta]) {
            SesionGrupo::create(['grupo_id' => $grupo->id, 'dia' => $dia, 'hora_inicio' => $desde, 'hora_fin' => $hasta]);
        }

        return $grupo;
    }

    private function pagina(Perfil $quien, array $query): DOMXPath
    {
        $html = (string) $this->actingAs($quien->user)
            ->get(route('gestion-horarios', $query))
            ->assertOk()
            ->getContent();

        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();

        return new DOMXPath($dom);
    }

    /** Los ids de promotoria de los bloques que quedan A LA VISTA. */
    private function bloquesVisibles(DOMXPath $x): array
    {
        $ids = [];
        foreach ($x->query('//section[contains(@class,"horarios-bloque")][not(@hidden)]') as $s) {
            $this->assertInstanceOf(DOMElement::class, $s);
            $ids[] = (int) $s->getAttribute('data-promotoria');
        }
        sort($ids);

        return $ids;
    }

    /** Los ids de promotoria que estan en la pagina, visibles o no. */
    private function bloquesEnLaPagina(DOMXPath $x): array
    {
        $ids = [];
        foreach ($x->query('//section[contains(@class,"horarios-bloque")]') as $s) {
            $this->assertInstanceOf(DOMElement::class, $s);
            $ids[] = (int) $s->getAttribute('data-promotoria');
        }
        sort($ids);

        return $ids;
    }

    // ------------------------------------------------------------------
    // El dia y el filtro
    // ------------------------------------------------------------------

    public function test_ensena_solo_las_clases_del_dia_pedido(): void
    {
        $this->grupo($this->piano, 'Grupo A', 'Salón 1', [[2, '16:00', '18:00'], [4, '16:00', '18:00']]);
        $this->grupo($this->ballet, 'Grupo B', 'Salón 2', [[3, '09:00', '11:00']]);

        $x = $this->pagina($this->admin, ['dia' => 2]);

        // Piano tiene martes; Ballet no, y su bloque llega escondido (la
        // semana entera viaja en el HTML para filtrar sin recargar).
        $this->assertSame([$this->piano->id], $this->bloquesVisibles($x));
        $this->assertSame([$this->piano->id, $this->ballet->id], $this->bloquesEnLaPagina($x));

        // Dentro de Piano, la fila del jueves tambien viene escondida.
        $this->assertSame(1, $x->query('//tr[@data-dia="2"][not(@hidden)]')->length);
        $this->assertSame(1, $x->query('//tr[@data-dia="4"][@hidden]')->length);

        // Y las cifras de las pestañas cuentan la semana: martes 1, miercoles 1.
        $this->assertSame('1', trim($x->query('//a[@data-dia="2"]//*[@data-cuenta]')->item(0)->textContent));
        $this->assertSame('1', trim($x->query('//a[@data-dia="3"]//*[@data-cuenta]')->item(0)->textContent));
        $this->assertSame('0', trim($x->query('//a[@data-dia="1"]//*[@data-cuenta]')->item(0)->textContent));
    }

    public function test_el_filtro_deja_una_sola_promotoria_y_las_pestanas_cuentan_solo_la_suya(): void
    {
        $this->grupo($this->piano, 'Grupo A', 'Salón 1', [[2, '16:00', '18:00']]);
        $this->grupo($this->violin, 'Grupo V', 'Salón 3', [[2, '16:00', '18:00'], [5, '08:00', '10:00']]);

        $x = $this->pagina($this->admin, ['dia' => 2, 'promotoria' => $this->violin->id]);

        $this->assertSame([$this->violin->id], $this->bloquesVisibles($x));
        $this->assertSame('1', trim($x->query('//a[@data-dia="2"]//*[@data-cuenta]')->item(0)->textContent));
        $this->assertSame('1', trim($x->query('//a[@data-dia="5"]//*[@data-cuenta]')->item(0)->textContent));
    }

    public function test_un_dia_que_no_existe_cae_en_uno_valido_y_no_revienta(): void
    {
        $this->grupo($this->piano, 'Grupo A', 'Salón 1', [[1, '16:00', '18:00']]);

        // El domingo (7) y la basura caen en un dia de la casa: siempre hay una
        // pestaña marcada.
        foreach ([7, 99, 'x'] as $dia) {
            $x = $this->pagina($this->admin, ['dia' => $dia]);
            $this->assertSame(1, $x->query('//a[contains(@class,"horarios-dia-actual")][@aria-current="true"]')->length);
        }
    }

    // ------------------------------------------------------------------
    // Quien la ve
    // ------------------------------------------------------------------

    public function test_el_director_ve_sus_departamentos_y_no_los_ajenos(): void
    {
        $this->grupo($this->piano, 'Grupo A', 'Salón 1', [[2, '16:00', '18:00']]);
        $this->grupo($this->ballet, 'Grupo B', 'Salón 2', [[2, '16:00', '18:00']]);

        $x = $this->pagina($this->director, ['dia' => 2]);

        // Las dos mitades: ve Piano, y Ballet no llega ni escondido.
        $this->assertSame([$this->piano->id], $this->bloquesEnLaPagina($x));
        $this->assertSame(0, $x->query('//option[@value="'.$this->ballet->id.'"]')->length);
        $this->assertSame(1, $x->query('//option[@value="'.$this->piano->id.'"]')->length);
    }

    public function test_una_promotoria_ajena_en_la_url_se_ignora(): void
    {
        $this->grupo($this->piano, 'Grupo A', 'Salón 1', [[2, '16:00', '18:00']]);
        $this->grupo($this->ballet, 'Grupo B', 'Salón 2', [[2, '16:00', '18:00']]);

        $x = $this->pagina($this->director, ['dia' => 2, 'promotoria' => $this->ballet->id]);

        // No filtra por la ajena ni la enseña: vuelve a «todas las suyas».
        $this->assertSame([$this->piano->id], $this->bloquesVisibles($x));
        $this->assertSame(0, $x->query('//option[@selected]')->length);
    }

    public function test_profesor_y_estudiante_no_entran(): void
    {
        foreach (['profesor', 'estudiante'] as $rol) {
            $perfil = $this->perfil('otro-'.$rol, $rol);
            // `RequiereRol` no contesta 403: devuelve a su portada con un aviso.
            $this->actingAs($perfil->user)->get(route('gestion-horarios'))->assertRedirect(route('post-login'));
        }
    }

    // ------------------------------------------------------------------
    // Cruces de salon
    // ------------------------------------------------------------------

    public function test_dos_grupos_en_el_mismo_salon_a_horas_que_se_pisan_se_marcan_los_dos(): void
    {
        // Mismo salon escrito distinto: mayusculas, tilde igual, espacio de sobra.
        $this->grupo($this->piano, 'Grupo A', 'Salón 1', [[2, '16:00', '18:00']]);
        $this->grupo($this->violin, 'Grupo V', ' salón 1 ', [[2, '17:00', '19:00']]);

        $x = $this->pagina($this->admin, ['dia' => 2]);

        $this->assertSame(2, $x->query('//tr[@data-dia="2"][@data-cruce]')->length);
        $this->assertSame(2, $x->query('//tr[@data-cruce]//*[contains(@class,"estado-cruce")]')->length);
    }

    public function test_horas_que_solo_se_tocan_otro_salon_u_otro_dia_no_son_cruce(): void
    {
        $this->grupo($this->piano, 'Grupo A', 'Salón 1', [[2, '16:00', '18:00']]);
        // Empieza cuando la otra termina: programacion por bloques, normal.
        $this->grupo($this->violin, 'Grupo V', 'Salón 1', [[2, '18:00', '20:00']]);
        // Misma hora, otro salon.
        $this->grupo($this->violin, 'Grupo W', 'Salón 2', [[2, '16:00', '18:00']]);
        // Mismo salon y hora, otro dia.
        $this->grupo($this->ballet, 'Grupo B', 'Salón 1', [[3, '16:00', '18:00']]);
        // Sin salon —la columna no admite NULL: vacio, o solo espacios con el
        // duro que pega WhatsApp—. No saber donde no es saber que es en el
        // mismo sitio.
        $this->grupo($this->ballet, 'Grupo C', '', [[2, '16:00', '18:00']]);
        $this->grupo($this->ballet, 'Grupo D', " \u{00A0} ", [[2, '16:00', '18:00']]);

        $x = $this->pagina($this->admin, ['dia' => 2]);

        $this->assertSame(0, $x->query('//tr[@data-cruce]')->length);
    }

    /**
     * AL DIRECTOR, SOLO LO SUYO «Y YA» (decision del usuario, 27/09/2026). La
     * primera version le decia «cruce con otro grupo» cuando chocaba con un
     * departamento ajeno; ahora ese choque no le sale. El administrador si lo ve.
     */
    public function test_el_director_no_ve_cruces_con_grupos_de_otro_departamento(): void
    {
        $this->grupo($this->piano, 'Grupo A', 'Salón 1', [[2, '16:00', '18:00']]);
        $this->grupo($this->ballet, 'Grupo Secreto', 'Salón 1', [[2, '17:00', '19:00']]);

        $html = (string) $this->actingAs($this->director->user)
            ->get(route('gestion-horarios', ['dia' => 2]))
            ->getContent();

        $this->assertStringContainsString('Grupo A', $html);
        $this->assertStringNotContainsString('data-cruce', $html);
        $this->assertStringNotContainsString('Grupo Secreto', $html);
        $this->assertStringNotContainsString('Ballet', $html);

        // El administrador ve el choque, nombrado, en las dos filas.
        $x = $this->pagina($this->admin, ['dia' => 2]);
        $this->assertSame(2, $x->query('//tr[@data-cruce]')->length);
        $this->actingAs($this->admin->user)
            ->get(route('gestion-horarios', ['dia' => 2]))
            ->assertSee('Grupo Secreto');
    }

    /** Entre dos grupos SUYOS el director si ve el cruce: el recorte no lo apaga. */
    public function test_el_director_si_ve_los_cruces_entre_sus_propios_grupos(): void
    {
        $this->grupo($this->piano, 'Grupo A', 'Salón 1', [[2, '16:00', '18:00']]);
        $this->grupo($this->violin, 'Grupo V', 'Salón 1', [[2, '17:00', '19:00']]);

        $x = $this->pagina($this->director, ['dia' => 2]);

        $this->assertSame(2, $x->query('//tr[@data-cruce]')->length);
    }

    // ------------------------------------------------------------------
    // Lo que no sale
    // ------------------------------------------------------------------

    public function test_cuenta_los_grupos_sin_horario_de_lo_que_se_ve(): void
    {
        $this->grupo($this->piano, 'Grupo A', 'Salón 1', [[2, '16:00', '18:00']]);
        $this->grupo($this->piano, 'Sin horario', 'Salón 1', []);
        $this->grupo($this->ballet, 'Ajeno sin horario', 'Salón 2', []);

        $admin = $this->pagina($this->admin, ['dia' => 2]);
        $director = $this->pagina($this->director, ['dia' => 2]);

        $this->assertStringContainsString('2 grupos', $admin->query('//*[contains(@class,"horarios-sin-horario")]')->item(0)->textContent);
        // El del director no cuenta el grupo de Danza.
        $this->assertStringContainsString('1 grupo ', $director->query('//*[contains(@class,"horarios-sin-horario")]')->item(0)->textContent);
    }

    public function test_la_consulta_no_crece_con_los_grupos(): void
    {
        foreach (range(1, 12) as $i) {
            $this->grupo($i % 2 ? $this->piano : $this->ballet, "Grupo {$i}", "Salón {$i}", [[1 + $i % 6, '16:00', '18:00']]);
        }

        $contar = function (): int {
            DB::flushQueryLog();
            $this->actingAs($this->admin->user)->get(route('gestion-horarios', ['dia' => 2]))->assertOk();

            return count(DB::getQueryLog());
        };

        // La primera peticion carga ademas lo que el resto de la prueba deja en
        // memoria: se descarta para comparar dos peticiones iguales.
        DB::enableQueryLog();
        $contar();
        $conDoce = $contar();

        foreach (range(13, 30) as $i) {
            $this->grupo($this->violin, "Grupo {$i}", "Salón {$i}", [[1 + $i % 6, '08:00', '10:00']]);
        }

        $this->assertSame($conDoce, $contar());
    }
}
