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
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * QUIEN VE QUE FOTO (revision de seguridad del 27/09/2026).
 *
 * Hasta ese dia bastaba ser personal de la casa: cualquier profesor bajaba la
 * foto de cualquiera probando ids seguidos. Ahora el profesor ve la de SUS
 * estudiantes y nada mas. La regla vive en `Permisos::puedeVerFoto()`.
 *
 * Cada prueba afirma las dos mitades: lo suyo se ve Y lo ajeno no.
 */
class FotoPorVinculoTest extends TestCase
{
    use RefreshDatabase;

    private Perfil $profe;

    private Promotoria $piano;

    private Promotoria $violin;

    private Periodo $periodo;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->periodo = Periodo::create([
            'nombre' => '2026-2', 'fecha_inicio' => '2026-07-15', 'fecha_fin' => '2026-12-15',
            'activo' => true, 'matriculas_abiertas' => true,
        ]);
        $area = Area::create(['nombre' => 'Música']);
        $this->profe = $this->perfil('profe', 'profesor');
        $this->piano = Promotoria::create(['nombre' => 'Piano', 'area_id' => $area->id, 'profesor_id' => $this->profe->id]);
        $this->violin = Promotoria::create(['nombre' => 'Violín', 'area_id' => $area->id, 'profesor_id' => $this->perfil('otro', 'profesor')->id]);
    }

    private function perfil(string $username, string $rol): Perfil
    {
        $user = User::create(['username' => $username, 'password' => 'demo1234', 'activo' => true]);
        $perfil = Perfil::create([
            'user_id' => $user->id, 'rol' => $rol, 'nombre_completo' => ucfirst($username).' Ruiz',
            'fecha_nacimiento' => '2012-01-01', 'telefono' => '3000000000',
        ]);

        if ($rol === 'estudiante') {
            DatosEstudiante::create(['perfil_id' => $perfil->id, 'documento_identidad' => '10'.$perfil->id]);
        }

        // Todos con foto: lo que se prueba es quien la puede ver.
        $perfil->foto_perfil = "fotos_perfil/{$username}.webp";
        $perfil->save();
        Storage::disk('local')->put($perfil->foto_perfil, 'x');

        return $perfil;
    }

    private function matricular(Perfil $estudiante, Promotoria $promotoria, string $estado = Matricula::ACTIVA): void
    {
        (new Matricula([
            'estudiante_id' => $estudiante->id,
            'promotoria_id' => $promotoria->id,
            'periodo_id' => $this->periodo->id,
            'estado' => $estado,
        ]))->save();
    }

    public function test_el_profesor_ve_la_de_sus_estudiantes_y_no_la_de_los_ajenos(): void
    {
        $suyo = $this->perfil('suyo', 'estudiante');
        $pendiente = $this->perfil('pendiente', 'estudiante');
        $ajeno = $this->perfil('ajeno', 'estudiante');
        $this->matricular($suyo, $this->piano);
        // La pendiente tambien: es a quien tiene que confirmar desde el Panel.
        $this->matricular($pendiente, $this->piano, Matricula::PENDIENTE);
        $this->matricular($ajeno, $this->violin);

        $this->actingAs($this->profe->user)->get(route('ver-foto', $suyo))->assertOk();
        $this->actingAs($this->profe->user)->get(route('ver-foto', $pendiente))->assertOk();
        $this->actingAs($this->profe->user)->get(route('ver-foto', $ajeno))->assertNotFound();
    }

    public function test_el_profesor_no_ve_la_de_otro_personal_ni_la_de_quien_no_esta_matriculado(): void
    {
        $colega = Perfil::where('rol', 'profesor')->where('id', '!=', $this->profe->id)->firstOrFail();
        $suelto = $this->perfil('suelto', 'estudiante');

        $this->actingAs($this->profe->user)->get(route('ver-foto', $colega))->assertNotFound();
        $this->actingAs($this->profe->user)->get(route('ver-foto', $suelto))->assertNotFound();
        // La suya, siempre.
        $this->actingAs($this->profe->user)->get(route('ver-foto', $this->profe))->assertOk();
    }

    public function test_administrador_y_director_siguen_viendo_todas(): void
    {
        $ajeno = $this->perfil('ajeno', 'estudiante');
        $this->matricular($ajeno, $this->violin);

        foreach (['jefa' => 'administrador', 'dire' => 'director'] as $usuario => $rol) {
            $this->actingAs($this->perfil($usuario, $rol)->user)
                ->get(route('ver-foto', $ajeno))
                ->assertOk();
        }
    }
}
