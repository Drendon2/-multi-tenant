<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\ConfiguracionInstitucion;
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
 * Quien cancela por accidente tiene vuelta (28/09/2026).
 *
 * Hasta ese dia no la tenia: siendo mayor, la direccion solo podia APROBAR la
 * cancelacion; ya retirado, «Corregir promotoria» no ofrece la promotoria en la
 * que esta y «Deshacer el rechazo» solo vale para rechazos. Lo unico que
 * quedaba era el «Matricularme» del catalogo, y solo con la ventana abierta.
 *
 * Dos salidas, una por momento:
 *   - con la cancelacion EN TRAMITE, el propio estudiante se echa atras y
 *     sigue activo, con sus grupos;
 *   - ya RETIRADO, direccion lo readmite en la misma promotoria, pendiente.
 */
class ReadmisionTest extends TestCase
{
    use RefreshDatabase;

    private Periodo $periodo;

    private Area $musica;

    private Promotoria $violin;

    private Perfil $estudiante;

    private Perfil $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->periodo = Periodo::create([
            'nombre' => '2026-2',
            'fecha_inicio' => Carbon::today()->subMonth()->toDateString(),
            'fecha_fin' => Carbon::today()->addMonths(3)->toDateString(),
            'activo' => true,
            'matriculas_abiertas' => false,
        ]);

        $this->musica = Area::create(['nombre' => 'Musica']);
        $this->violin = Promotoria::create(['nombre' => 'Violin', 'area_id' => $this->musica->id]);

        $this->estudiante = $this->perfil('ana', 'estudiante');
        DatosEstudiante::create([
            'perfil_id' => $this->estudiante->id,
            'documento_identidad' => '11111111',
        ]);

        $this->admin = $this->perfil('admin', 'administrador');
    }

    // -----------------------------------------------------------------------
    // El estudiante se echa atras
    // -----------------------------------------------------------------------

    /** Vuelve a activa y conserva su grupo: nunca dejo de estar. */
    public function test_el_estudiante_se_echa_atras_y_conserva_su_grupo(): void
    {
        $matricula = $this->matricula(Matricula::ACTIVA);
        $grupo = Grupo::create([
            'promotoria_id' => $this->violin->id,
            'nombre' => 'Tarde',
            'nivel' => 'basico',
            'salon' => 'A1',
            'cupo_maximo' => 10,
        ]);
        $matricula->repartirEn([$grupo->id]);
        $matricula->estado = Matricula::CANCELACION_SOLICITADA;
        $matricula->save();

        $this->actingAs($this->estudiante->user)
            ->post(route('mis-matriculas.seguir', $matricula))
            ->assertRedirect(route('mis-matriculas'))
            ->assertSessionHas('success');

        $matricula->refresh();
        $this->assertSame(Matricula::ACTIVA, $matricula->estado);
        $this->assertSame([$grupo->id], $matricula->grupos->pluck('id')->all(), 'perdio el grupo.');
    }

    /** Si la direccion ya la aprobo, no revive, y se le dice. */
    public function test_ya_aprobada_no_revive_y_lo_dice(): void
    {
        $matricula = $this->matricula(Matricula::RETIRADA, Matricula::RETIRO_CANCELACION);

        $this->actingAs($this->estudiante->user)
            ->post(route('mis-matriculas.seguir', $matricula))
            ->assertSessionHas('error');

        $this->assertSame(Matricula::RETIRADA, $matricula->fresh()->estado);
    }

    /** Solo la suya. */
    public function test_nadie_se_echa_atras_por_otro(): void
    {
        $matricula = $this->matricula(Matricula::CANCELACION_SOLICITADA);
        $otro = $this->perfil('beto', 'estudiante');

        $this->actingAs($otro->user)
            ->post(route('mis-matriculas.seguir', $matricula))
            ->assertNotFound();

        $this->assertSame(Matricula::CANCELACION_SOLICITADA, $matricula->fresh()->estado);
    }

    // -----------------------------------------------------------------------
    // Direccion readmite
    // -----------------------------------------------------------------------

    /**
     * La cancelacion aprobada vuelve a pendiente en la MISMA promotoria, sin
     * motivo de retiro, y con la ventana CERRADA: el administrador siempre.
     */
    public function test_el_administrador_readmite_en_la_misma_promotoria(): void
    {
        $matricula = $this->matricula(Matricula::RETIRADA, Matricula::RETIRO_CANCELACION);

        $this->actingAs($this->admin->user)
            ->post(route('readmitir-matricula', $matricula))
            ->assertRedirect(route('historial-estudiante', $this->estudiante->id))
            ->assertSessionHas('success');

        $matricula->refresh();
        $this->assertSame(Matricula::PENDIENTE, $matricula->estado);
        $this->assertNull($matricula->motivo_retiro);
        $this->assertSame($this->violin->id, $matricula->promotoria_id);
    }

    /** El boton se ve en la trayectoria; en un rechazo no, porque ya esta el otro. */
    public function test_el_boton_sale_en_la_trayectoria_salvo_en_un_rechazo(): void
    {
        $cancelada = $this->matricula(Matricula::RETIRADA, Matricula::RETIRO_CANCELACION);
        $danza = Promotoria::create(['nombre' => 'Danza', 'area_id' => $this->musica->id]);
        $rechazada = $this->matricula(Matricula::RETIRADA, Matricula::RETIRO_RECHAZO, $danza);

        $this->actingAs($this->admin->user)
            ->get(route('historial-estudiante', $this->estudiante))
            ->assertOk()
            ->assertSee(route('readmitir-matricula', $cancelada), false)
            ->assertDontSee(route('readmitir-matricula', $rechazada), false)
            ->assertSee(route('deshacer-rechazo', $rechazada), false);
    }

    /** El profesor no readmite: es la puerta de la correccion, no la del rechazo. */
    public function test_el_profesor_no_readmite(): void
    {
        $profesor = $this->perfil('profe', 'profesor');
        $this->violin->update(['profesor_id' => $profesor->id]);
        $matricula = $this->matricula(Matricula::RETIRADA, Matricula::RETIRO_CANCELACION);

        $this->actingAs($profesor->user)
            ->post(route('readmitir-matricula', $matricula))
            ->assertSessionHas('error');

        $this->assertSame(Matricula::RETIRADA, $matricula->fresh()->estado);

        $this->actingAs($profesor->user)
            ->get(route('historial-estudiante', $this->estudiante))
            ->assertDontSee(route('readmitir-matricula', $matricula), false);
    }

    /** El director, con la ventana cerrada, no; abierta, solo en lo suyo. */
    public function test_el_director_solo_con_la_ventana_abierta_y_en_lo_suyo(): void
    {
        $director = $this->perfil('dire', 'director');
        $director->areasDirigidas()->attach($this->musica->id);
        $matricula = $this->matricula(Matricula::RETIRADA, Matricula::RETIRO_CANCELACION);

        $this->actingAs($director->user)
            ->post(route('readmitir-matricula', $matricula))
            ->assertSessionHas('error');
        $this->assertSame(Matricula::RETIRADA, $matricula->fresh()->estado, 'readmitio con la ventana cerrada.');

        $this->periodo->update(['matriculas_abiertas' => true]);

        $teatro = Promotoria::create([
            'nombre' => 'Teatro',
            'area_id' => Area::create(['nombre' => 'Artes escenicas'])->id,
        ]);
        $ajena = $this->matricula(Matricula::RETIRADA, Matricula::RETIRO_CANCELACION, $teatro);

        $this->actingAs($director->user)
            ->post(route('readmitir-matricula', $ajena))
            ->assertSessionHas('error');
        $this->assertSame(Matricula::RETIRADA, $ajena->fresh()->estado, 'readmitio en un departamento ajeno.');

        $this->actingAs($director->user)
            ->post(route('readmitir-matricula', $matricula))
            ->assertSessionHas('success');
        $this->assertSame(Matricula::PENDIENTE, $matricula->fresh()->estado);
    }

    /** Readmitir ocupa de nuevo un cupo suyo, y el limite se respeta. */
    public function test_readmitir_respeta_el_limite_de_promotorias(): void
    {
        $config = ConfiguracionInstitucion::actual();
        $config->limite_promotorias_por_periodo = 1;
        $config->save();

        $matricula = $this->matricula(Matricula::RETIRADA, Matricula::RETIRO_CANCELACION);
        $danza = Promotoria::create(['nombre' => 'Danza', 'area_id' => $this->musica->id]);
        $this->matricula(Matricula::ACTIVA, null, $danza);

        $this->actingAs($this->admin->user)
            ->post(route('readmitir-matricula', $matricula))
            ->assertSessionHas('error');

        $this->assertSame(Matricula::RETIRADA, $matricula->fresh()->estado);
    }

    /** Un periodo terminado es historial. */
    public function test_no_se_readmite_en_un_periodo_terminado(): void
    {
        $anterior = Periodo::create([
            'nombre' => '2025-2',
            'fecha_inicio' => '2025-07-01',
            'fecha_fin' => '2025-12-15',
            'activo' => false,
        ]);
        $matricula = Matricula::create([
            'estudiante_id' => $this->estudiante->id,
            'promotoria_id' => $this->violin->id,
            'periodo_id' => $anterior->id,
            'estado' => Matricula::RETIRADA,
            'motivo_retiro' => Matricula::RETIRO_CANCELACION,
        ]);

        $this->actingAs($this->admin->user)
            ->post(route('readmitir-matricula', $matricula))
            ->assertSessionHas('error');

        $this->assertSame(Matricula::RETIRADA, $matricula->fresh()->estado);
    }

    private function matricula(string $estado, ?string $motivo = null, ?Promotoria $promotoria = null): Matricula
    {
        return Matricula::create([
            'estudiante_id' => $this->estudiante->id,
            'promotoria_id' => ($promotoria ?? $this->violin)->id,
            'periodo_id' => $this->periodo->id,
            'estado' => $estado,
            'motivo_retiro' => $motivo,
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
