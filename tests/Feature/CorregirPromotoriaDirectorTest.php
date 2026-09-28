<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\DatosEstudiante;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * «Corregir promotoria» con el recorte del director (28/09/2026).
 *
 * Hasta ese dia solo miraba el rol y la ventana de matriculas: con la ventana
 * abierta, un director podia mover la matricula de cualquier estudiante a
 * cualquier promotoria —el desplegable las ofrecia todas—. Es el caso de las
 * cancelaciones del 27/09 otra vez: el recorte vivia en las listas y no en
 * esta puerta.
 */
class CorregirPromotoriaDirectorTest extends TestCase
{
    use RefreshDatabase;

    private Promotoria $violin;

    private Promotoria $piano;

    private Promotoria $teatro;

    private Perfil $director;

    private Perfil $estudiante;

    private Periodo $periodo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->periodo = Periodo::create([
            'nombre' => '2026-2',
            'fecha_inicio' => Carbon::today()->subMonth()->toDateString(),
            'fecha_fin' => Carbon::today()->addMonths(3)->toDateString(),
            'activo' => true,
            'matriculas_abiertas' => true,
        ]);

        $musica = Area::create(['nombre' => 'Musica']);
        $escenicas = Area::create(['nombre' => 'Artes escenicas']);

        $this->violin = Promotoria::create(['nombre' => 'Violin', 'area_id' => $musica->id]);
        $this->piano = Promotoria::create(['nombre' => 'Piano', 'area_id' => $musica->id]);
        $this->teatro = Promotoria::create(['nombre' => 'Teatro', 'area_id' => $escenicas->id]);

        $this->director = $this->perfil('dire', 'director');
        $this->director->areasDirigidas()->attach($musica->id);

        $this->estudiante = $this->perfil('ana', 'estudiante');
        DatosEstudiante::create([
            'perfil_id' => $this->estudiante->id,
            'documento_identidad' => '11111111',
        ]);
    }

    /** Dentro de lo suyo, corrige como siempre. */
    public function test_el_director_corrige_dentro_de_su_departamento(): void
    {
        $matricula = $this->matricula($this->violin);

        $this->corregir($matricula, $this->piano)->assertSessionHas('success');

        $this->assertSame($this->piano->id, $matricula->fresh()->promotoria_id);
    }

    /** No mete a nadie en una promotoria ajena. */
    public function test_no_mueve_hacia_una_promotoria_ajena(): void
    {
        $matricula = $this->matricula($this->violin);

        $this->corregir($matricula, $this->teatro)->assertSessionHas('error');

        $this->assertSame($this->violin->id, $matricula->fresh()->promotoria_id);
    }

    /** Ni saca a nadie de una ajena hacia una suya. */
    public function test_no_mueve_desde_una_promotoria_ajena(): void
    {
        $matricula = $this->matricula($this->teatro);

        $this->corregir($matricula, $this->piano)->assertSessionHas('error');

        $this->assertSame($this->teatro->id, $matricula->fresh()->promotoria_id);
    }

    /**
     * La pantalla: el desplegable solo ofrece las suyas, y la matricula ajena
     * no trae el formulario.
     */
    public function test_la_trayectoria_solo_ofrece_lo_suyo(): void
    {
        $suya = $this->matricula($this->violin);
        $ajena = $this->matricula($this->teatro);

        $html = $this->actingAs($this->director->user)
            ->get(route('historial-estudiante', $this->estudiante))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(route('corregir-promotoria', $suya), $html);
        $this->assertStringNotContainsString(route('corregir-promotoria', $ajena), $html);
        $this->assertStringNotContainsString('value="'.$this->teatro->id.'"', $html, 'ofrece una promotoria ajena.');
        $this->assertStringContainsString('value="'.$this->piano->id.'"', $html);
    }

    /** El administrador no tiene recorte. */
    public function test_el_administrador_mueve_a_cualquiera(): void
    {
        $admin = $this->perfil('admin', 'administrador');
        $matricula = $this->matricula($this->violin);

        $this->actingAs($admin->user)
            ->post(route('corregir-promotoria', $matricula), ['promotoria_id' => $this->teatro->id])
            ->assertSessionHas('success');

        $this->assertSame($this->teatro->id, $matricula->fresh()->promotoria_id);
    }

    private function corregir(Matricula $matricula, Promotoria $destino)
    {
        return $this->actingAs($this->director->user)
            ->post(route('corregir-promotoria', $matricula), ['promotoria_id' => $destino->id]);
    }

    private function matricula(Promotoria $promotoria): Matricula
    {
        return Matricula::create([
            'estudiante_id' => $this->estudiante->id,
            'promotoria_id' => $promotoria->id,
            'periodo_id' => $this->periodo->id,
            'estado' => Matricula::ACTIVA,
        ]);
    }

    private function perfil(string $username, string $rol): Perfil
    {
        $user = User::create(['username' => $username, 'password' => 'x', 'activo' => true]);

        return Perfil::create([
            'user_id' => $user->id,
            'rol' => $rol,
            'nombre_completo' => ucfirst($username),
            'fecha_nacimiento' => Carbon::today()->subYears(25)->toDateString(),
            'telefono' => '3000000000',
        ]);
    }
}
