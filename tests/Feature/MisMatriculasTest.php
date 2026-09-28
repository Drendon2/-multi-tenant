<?php

namespace Tests\Feature;

use App\Models\Acudiente;
use App\Models\Area;
use App\Models\DatosEstudiante;
use App\Models\Grupo;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * «Mis matriculas»: el historial del estudiante y el boton para salirse.
 *
 * Salirse significa dos cosas distintas segun el estado, y esa diferencia es lo
 * central de esta pantalla: retirar una solicitud sin confirmar es inmediato;
 * salirse de una matricula activa es una SOLICITUD que resuelve direccion.
 */
class MisMatriculasTest extends TestCase
{
    use RefreshDatabase;

    private Periodo $periodo;

    private Promotoria $violin;

    private Promotoria $danza;

    private User $user;

    private Perfil $perfil;

    protected function setUp(): void
    {
        parent::setUp();

        $this->periodo = Periodo::create([
            'nombre' => '2026-1',
            'fecha_inicio' => '2026-01-15',
            'fecha_fin' => '2026-06-30',
            'activo' => true,
            'matriculas_abiertas' => true,
        ]);

        $area = Area::create(['nombre' => 'Musica']);
        $this->violin = Promotoria::create(['nombre' => 'Violin', 'area_id' => $area->id]);
        $this->danza = Promotoria::create(['nombre' => 'Danza', 'area_id' => $area->id]);

        [$this->user, $this->perfil] = $this->crearEstudiante('ana', '1111');
    }

    /** @return array{0: User, 1: Perfil} */
    private function crearEstudiante(string $username, string $documento, ?string $nacimiento = null): array
    {
        $user = User::create(['username' => $username, 'password' => 'x', 'activo' => true]);

        $perfil = Perfil::create([
            'user_id' => $user->id,
            'rol' => 'estudiante',
            'nombre_completo' => ucfirst($username),
            'fecha_nacimiento' => $nacimiento ?? Carbon::today()->subYears(25)->toDateString(),
            'telefono' => '3000000000',
        ]);

        DatosEstudiante::create([
            'perfil_id' => $perfil->id,
            'documento_identidad' => $documento,
            'acudiente_id' => $nacimiento !== null
                ? Acudiente::create(['nombre' => 'Tutor', 'telefono' => '300'])->id
                : null,
        ]);

        return [$user, $perfil->refresh()];
    }

    private function matricular(Promotoria $promotoria, string $estado, ?Periodo $periodo = null): Matricula
    {
        return Matricula::create([
            'estudiante_id' => $this->perfil->id,
            'promotoria_id' => $promotoria->id,
            'periodo_id' => ($periodo ?? $this->periodo)->id,
            'estado' => $estado,
        ]);
    }

    // -----------------------------------------------------------------------
    // La pantalla
    // -----------------------------------------------------------------------

    public function test_sin_matriculas_lo_dice(): void
    {
        $this->actingAs($this->user)
            ->get(route('mis-matriculas'))
            ->assertOk()
            ->assertSee('Todavía no tienes matrículas', false);
    }

    public function test_agrupa_por_periodo_del_mas_reciente_al_mas_antiguo(): void
    {
        $anterior = Periodo::create([
            'nombre' => '2025-2',
            'fecha_inicio' => '2025-07-01',
            'fecha_fin' => '2025-12-15',
            'activo' => false,
        ]);

        $this->matricular($this->violin, Matricula::ACTIVA, $anterior);
        $this->matricular($this->danza, Matricula::ACTIVA);

        $historial = Matricula::historialPorPeriodo($this->perfil);

        $this->assertCount(2, $historial);
        $this->assertSame('2026-1', $historial[0]['periodo']->nombre);
        $this->assertTrue($historial[0]['en_curso']);
        $this->assertSame('2025-2', $historial[1]['periodo']->nombre);
        $this->assertFalse($historial[1]['en_curso']);
    }

    /** El historial incluye las retiradas: es lo que cuenta de dónde viene. */
    public function test_el_historial_incluye_las_retiradas(): void
    {
        $this->matricular($this->violin, Matricula::RETIRADA);

        $this->actingAs($this->user)
            ->get(route('mis-matriculas'))
            ->assertOk()
            ->assertSee('Violin')
            ->assertSee('Retirada');
    }

    /**
     * Las cifras de cabecera solo cuentan lo ACTIVO: si contaran las pendientes,
     * quien pidio cinco promotorias y no entro a ninguna se leeria como el
     * estudiante mas veterano de la casa.
     */
    public function test_el_resumen_solo_cuenta_lo_cursado(): void
    {
        $this->matricular($this->violin, Matricula::ACTIVA);
        $this->matricular($this->danza, Matricula::PENDIENTE);

        $resumen = Matricula::resumenTrayectoria($this->perfil);

        $this->assertSame(1, $resumen['periodos']);
        $this->assertSame(1, $resumen['promotorias']);
        $this->assertSame('2026-1', $resumen['desde']->nombre);
    }

    public function test_sin_nada_activo_no_hay_cifras(): void
    {
        $this->matricular($this->violin, Matricula::PENDIENTE);

        $resumen = Matricula::resumenTrayectoria($this->perfil);

        $this->assertSame(0, $resumen['periodos']);
        $this->assertNull($resumen['desde']);
    }

    // -----------------------------------------------------------------------
    // Retirar una PENDIENTE: inmediato
    // -----------------------------------------------------------------------

    /**
     * Una solicitud que nadie confirmo no es una desercion: el estudiante la
     * retira antes de que le respondan y no hace falta que nadie la apruebe.
     */
    public function test_retirar_una_pendiente_es_inmediato(): void
    {
        $matricula = $this->matricular($this->violin, Matricula::PENDIENTE);

        $this->actingAs($this->user)
            ->post(route('mis-matriculas.retirar', $matricula))
            ->assertRedirect(route('mis-matriculas'))
            ->assertSessionHas('success');

        $this->assertSame(Matricula::RETIRADA, $matricula->fresh()->estado);
    }

    public function test_retirar_una_pendiente_le_quita_el_grupo(): void
    {
        $grupo = Grupo::create([
            'promotoria_id' => $this->violin->id, 'nombre' => 'Lun 8am', 'nivel' => 'basico',
            'salon' => 'A1', 'cupo_maximo' => 10,
        ]);

        $matricula = $this->matricular($this->violin, Matricula::PENDIENTE);
        $matricula->repartirEn([$grupo->id]);

        $this->actingAs($this->user)->post(route('mis-matriculas.retirar', $matricula));

        $this->assertSame(0, $matricula->grupos()->count());
    }

    /** Retirarse SI libera la ranura: se puede entrar a otra en su lugar. */
    public function test_retirarse_libera_el_cupo_del_estudiante(): void
    {
        $primera = $this->matricular($this->violin, Matricula::PENDIENTE);
        $this->matricular($this->danza, Matricula::PENDIENTE);

        $this->actingAs($this->user)->post(route('mis-matriculas.retirar', $primera));

        $this->assertSame(1, Matricula::promotoriasOcupadas($this->perfil->id, $this->periodo->id));
    }

    // -----------------------------------------------------------------------
    // Retirar una ACTIVA: pasa a solicitud
    // -----------------------------------------------------------------------

    /**
     * Desde una matricula ya activa, salirse es una SOLICITUD. Hasta que
     * direccion la resuelva el estudiante SIGUE inscrito, y su sitio sigue
     * ocupado — que es justo lo que distingue este estado de 'retirada'.
     */
    public function test_retirar_una_activa_deja_la_cancelacion_en_tramite(): void
    {
        $matricula = $this->matricular($this->violin, Matricula::ACTIVA);

        $this->actingAs($this->user)
            ->post(route('mis-matriculas.retirar', $matricula))
            ->assertSessionHas('success');

        $this->assertSame(Matricula::CANCELACION_SOLICITADA, $matricula->fresh()->estado);
    }

    public function test_la_cancelacion_en_tramite_sigue_ocupando_cupo(): void
    {
        $matricula = $this->matricular($this->violin, Matricula::ACTIVA);

        $this->actingAs($this->user)->post(route('mis-matriculas.retirar', $matricula));

        // Sigue contando para el limite del estudiante...
        $this->assertSame(1, Matricula::promotoriasOcupadas($this->perfil->id, $this->periodo->id));
        // ...y para el cupo de la promotoria.
        $this->assertSame(1, $this->violin->ocupadosEn($this->periodo));
    }

    /** A un menor se le avisa de que hablarán con su acudiente. */
    public function test_a_un_menor_se_le_avisa_del_acudiente(): void
    {
        [$menorUser, $menorPerfil] = $this->crearEstudiante(
            'nino', '2222', Carbon::today()->subYears(12)->toDateString()
        );

        $matricula = Matricula::create([
            'estudiante_id' => $menorPerfil->id,
            'promotoria_id' => $this->violin->id,
            'periodo_id' => $this->periodo->id,
            'estado' => Matricula::ACTIVA,
        ]);

        $this->actingAs($menorUser)
            ->post(route('mis-matriculas.retirar', $matricula));

        $this->assertStringContainsString('acudiente', session('success'));
    }

    public function test_a_un_adulto_no_se_le_menciona_acudiente(): void
    {
        $matricula = $this->matricular($this->violin, Matricula::ACTIVA);

        $this->actingAs($this->user)->post(route('mis-matriculas.retirar', $matricula));

        $this->assertStringNotContainsString('acudiente', session('success'));
        $this->assertStringContainsString('Sigues inscrito', session('success'));
    }

    // -----------------------------------------------------------------------
    // Limites
    // -----------------------------------------------------------------------

    /** Un periodo terminado es historial cerrado. */
    public function test_no_se_puede_retirar_de_un_periodo_terminado(): void
    {
        $anterior = Periodo::create([
            'nombre' => '2025-2',
            'fecha_inicio' => '2025-07-01',
            'fecha_fin' => '2025-12-15',
            'activo' => false,
        ]);

        $matricula = $this->matricular($this->violin, Matricula::ACTIVA, $anterior);

        $this->actingAs($this->user)
            ->post(route('mis-matriculas.retirar', $matricula))
            ->assertSessionHas('error');

        $this->assertSame(Matricula::ACTIVA, $matricula->fresh()->estado);
    }

    /** El botón no se ofrece siquiera en un periodo cerrado. */
    public function test_el_boton_no_aparece_en_un_periodo_terminado(): void
    {
        $anterior = Periodo::create([
            'nombre' => '2025-2',
            'fecha_inicio' => '2025-07-01',
            'fecha_fin' => '2025-12-15',
            'activo' => false,
        ]);

        $this->matricular($this->violin, Matricula::ACTIVA, $anterior);

        $this->actingAs($this->user)
            ->get(route('mis-matriculas'))
            ->assertSee('Periodo terminado')
            ->assertDontSee('Cancelar matrícula', false);
    }

    /**
     * Con la cancelación ya pedida, el botón de cancelar deja paso al de
     * echarse atrás (28/09/2026). Atado a la ruta, no al rótulo.
     */
    public function test_con_la_cancelacion_pedida_el_boton_desaparece(): void
    {
        $matricula = $this->matricular($this->violin, Matricula::CANCELACION_SOLICITADA);

        $this->actingAs($this->user)
            ->get(route('mis-matriculas'))
            ->assertSee(route('mis-matriculas.seguir', $matricula), false)
            ->assertDontSee(route('mis-matriculas.confirmar-retiro', $matricula), false);
    }

    /** Nadie se retira una matrícula ajena. */
    public function test_no_se_puede_retirar_la_matricula_de_otro(): void
    {
        [$otroUser, $otroPerfil] = $this->crearEstudiante('beto', '3333');

        $ajena = Matricula::create([
            'estudiante_id' => $otroPerfil->id,
            'promotoria_id' => $this->violin->id,
            'periodo_id' => $this->periodo->id,
            'estado' => Matricula::ACTIVA,
        ]);

        $this->actingAs($this->user)
            ->post(route('mis-matriculas.retirar', $ajena))
            ->assertNotFound();

        $this->assertSame(Matricula::ACTIVA, $ajena->fresh()->estado);
    }

    public function test_el_personal_no_entra_a_mis_matriculas(): void
    {
        $profe = User::create(['username' => 'profe', 'password' => 'x', 'activo' => true]);
        Perfil::create([
            'user_id' => $profe->id, 'rol' => 'profesor', 'nombre_completo' => 'Profe',
            'fecha_nacimiento' => '1980-01-01', 'telefono' => '300',
        ]);

        $this->actingAs($profe)
            ->get(route('mis-matriculas'))
            ->assertRedirect(route('post-login'));
    }
}
