<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Asistencia;
use App\Models\Clase;
use App\Models\ConfiguracionInstitucion;
use App\Models\DatosEstudiante;
use App\Models\Grupo;
use App\Models\Matricula;
use App\Models\OmisionArchivada;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Models\User;
use App\Support\Alertas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * «Mis estadísticas» del profesor (27/09/2026).
 *
 * Cada prueba afirma las dos mitades: que cuenta lo suyo Y que no se cuela lo
 * de la promotoria de otro profesor, que vive en la misma base y el mismo
 * periodo. Sin la segunda, un recorte roto daria verde igual.
 *
 * La fecha va FIJA —un miercoles, como en `AlertasTest`— porque las clases
 * perdidas se cuentan hasta ayer: con la de hoy, la prueba heredaria el
 * calendario y cambiaria de resultado segun el dia en que se corra.
 */
class EstadisticasDeProfesorTest extends TestCase
{
    use RefreshDatabase;

    private Periodo $anterior;

    private Periodo $actual;

    private Perfil $profe;

    private Promotoria $piano;

    private Promotoria $violin;

    private Grupo $grupoPiano;

    private Grupo $grupoViolin;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-03-11 10:00:00'));

        $this->anterior = Periodo::create([
            'nombre' => '2025-2', 'fecha_inicio' => '2025-08-01', 'fecha_fin' => '2025-12-15',
            'activo' => false, 'matriculas_abiertas' => false,
        ]);
        $this->actual = Periodo::create([
            'nombre' => '2026-1', 'fecha_inicio' => '2026-03-02', 'fecha_fin' => '2026-06-30',
            'activo' => true, 'matriculas_abiertas' => true,
        ]);

        $config = ConfiguracionInstitucion::actual();
        $config->alerta_clase_no_dictada = true;
        $config->save();

        $area = Area::create(['nombre' => 'Música']);
        $this->profe = $this->perfil('profe', 'profesor');
        $otro = $this->perfil('otro', 'profesor');

        $this->piano = Promotoria::create(['nombre' => 'Piano', 'area_id' => $area->id, 'profesor_id' => $this->profe->id]);
        $this->violin = Promotoria::create(['nombre' => 'Violín', 'area_id' => $area->id, 'profesor_id' => $otro->id]);

        // Los dos grupos tienen clase los MARTES. Desde el 02/03 hasta ayer
        // (10/03) hubo dos martes: el 03 y el 10.
        $this->grupoPiano = $this->grupo($this->piano);
        $this->grupoViolin = $this->grupo($this->violin);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function perfil(string $username, string $rol): Perfil
    {
        $user = User::create(['username' => $username, 'password' => 'demo1234', 'activo' => true]);

        $perfil = Perfil::create([
            'user_id' => $user->id, 'rol' => $rol, 'nombre_completo' => ucfirst($username).' Ruiz',
            'fecha_nacimiento' => '1990-01-01', 'telefono' => '3000000000',
        ]);

        if ($rol === 'estudiante') {
            DatosEstudiante::create(['perfil_id' => $perfil->id, 'documento_identidad' => '10'.$perfil->id]);
        }

        return $perfil;
    }

    private function grupo(Promotoria $promotoria): Grupo
    {
        $grupo = $promotoria->grupos()->create(['nombre' => 'Grupo A', 'nivel' => 'basico', 'cupo_maximo' => 30, 'salon' => '']);
        $grupo->sesiones()->create(['dia' => 2, 'hora_inicio' => '08:00', 'hora_fin' => '10:00']);

        return $grupo;
    }

    private function clase(Grupo $grupo, string $fecha, ?Periodo $periodo = null): Clase
    {
        return Clase::create([
            'grupo_id' => $grupo->id,
            'periodo_id' => ($periodo ?? $this->actual)->id,
            'fecha_hora' => $fecha,
            'registrada_por_id' => $this->profe->id,
        ]);
    }

    private function matricula(string $username, Promotoria $promotoria, Periodo $periodo, string $estado = Matricula::ACTIVA, ?string $motivo = null): Matricula
    {
        $estudiante = Perfil::whereHas('user', fn ($q) => $q->where('username', $username))->first()
            ?? $this->perfil($username, 'estudiante');

        $matricula = new Matricula([
            'estudiante_id' => $estudiante->id,
            'promotoria_id' => $promotoria->id,
            'periodo_id' => $periodo->id,
            'estado' => $estado,
        ]);
        $matricula->motivo_retiro = $motivo;
        $matricula->save();

        return $matricula;
    }

    /** @return array<string, mixed> */
    private function datos(?Periodo $periodo = null): array
    {
        $ruta = $periodo ? route('mis-estadisticas-periodo', $periodo) : route('mis-estadisticas');

        return $this->actingAs($this->profe->user)->get($ruta)->assertOk()->viewData('datos');
    }

    public function test_clases_dadas_y_perdidas_son_las_de_sus_grupos(): void
    {
        // Suyas: el martes 03 (tocaba) y el jueves 05 (fuera de horario, pero
        // dada). La del martes 10 falto.
        $this->clase($this->grupoPiano, '2026-03-03 08:00:00');
        $this->clase($this->grupoPiano, '2026-03-05 08:00:00');
        // De otro periodo: no cuenta en este.
        $this->clase($this->grupoPiano, '2025-09-02 08:00:00', $this->anterior);
        // De la promotoria de otro profesor, que ademas falto los dos martes.
        $this->clase($this->grupoViolin, '2026-03-04 08:00:00');

        $datos = $this->datos();

        $this->assertSame(2, $datos['clasesDadas']);
        $this->assertSame(1, $datos['perdidas']['total']);
        // Y es la MISMA cifra que da la bandeja de alertas acotada a el: una
        // sola cuenta, no dos que puedan separarse.
        $this->assertSame(Alertas::clasesNoDictadas($this->actual, $this->profe)->count(), $datos['perdidas']['total']);
        // La contraparte: la casa entera tiene mas faltas (las dos de Violin).
        $this->assertSame(3, Alertas::clasesNoDictadas($this->actual)->count());
    }

    /**
     * Sin una sola clase registrada, el periodo en curso sale igual: ese
     * profesor es justo el que tiene clases perdidas que ver.
     */
    public function test_sin_clases_registradas_ve_el_periodo_en_curso_con_sus_perdidas(): void
    {
        $datos = $this->datos();

        $this->assertSame(0, $datos['clasesDadas']);
        $this->assertSame(2, $datos['perdidas']['total']);
    }

    /**
     * UNA ARCHIVADA SIGUE SIENDO UNA CLASE PERDIDA (decision del usuario,
     * 27/09/2026). En produccion Percusion salia con 2 perdidas y le faltaron
     * 18: 16 estaban archivadas. Archivar limpia la BANDEJA y nada mas.
     */
    public function test_una_clase_perdida_archivada_sigue_contando_y_se_dice(): void
    {
        $this->clase($this->grupoPiano, '2026-03-03 08:00:00');
        OmisionArchivada::create(['grupo_id' => $this->grupoPiano->id, 'fecha' => '2026-03-10']);

        $perdidas = $this->datos()['perdidas'];

        $this->assertSame(1, $perdidas['total']);
        $this->assertSame(1, $perdidas['archivadas']);
        // La contraparte: de la bandeja de alertas si sale.
        $this->assertSame(0, Alertas::clasesNoDictadas($this->actual, $this->profe)->count());

        $this->actingAs($this->profe->user)->get(route('mis-estadisticas'))->assertSee('se archivó');
    }

    /**
     * LA CAUSA DECIDE SI CUENTA (03/10/2026, decision del usuario): la excusa
     * y el festivo o cierre salen de la cifra; la falta se queda.
     */
    public function test_la_excusa_y_el_festivo_no_cuentan_como_perdidas(): void
    {
        OmisionArchivada::create(['grupo_id' => $this->grupoPiano->id, 'fecha' => '2026-03-03', 'causa' => OmisionArchivada::EXCUSA]);
        OmisionArchivada::create(['grupo_id' => $this->grupoPiano->id, 'fecha' => '2026-03-10', 'causa' => OmisionArchivada::INSTITUCION]);

        $perdidas = $this->datos()['perdidas'];

        $this->assertSame(0, $perdidas['total']);
        $this->assertSame(1, $perdidas['excusas']);
        $this->assertSame(1, $perdidas['institucion']);
        $this->assertSame([], $perdidas['porGrupo']);
    }

    /**
     * Una falta REPUESTA sigue contando: la clase de ese dia no se dio igual. La
     * reposicion se dice aparte.
     */
    public function test_una_falta_repuesta_sigue_contando_y_se_dice(): void
    {
        $reposicion = $this->clase($this->grupoPiano, '2026-03-05 08:00:00');
        OmisionArchivada::create([
            'grupo_id' => $this->grupoPiano->id, 'fecha' => '2026-03-03',
            'causa' => OmisionArchivada::FALTA, 'repuesta_en_id' => $reposicion->id,
        ]);
        OmisionArchivada::create(['grupo_id' => $this->grupoPiano->id, 'fecha' => '2026-03-10', 'causa' => OmisionArchivada::FALTA]);

        $perdidas = $this->datos()['perdidas'];

        $this->assertSame(2, $perdidas['total']);
        $this->assertSame(2, $perdidas['faltas']);
        $this->assertSame(1, $perdidas['repuestas']);
        $this->assertSame(0, $perdidas['archivadas']);
    }

    public function test_con_las_alertas_apagadas_no_pinta_un_cero_de_perdidas(): void
    {
        $config = ConfiguracionInstitucion::actual();
        $config->alerta_clase_no_dictada = false;
        $config->save();

        $datos = $this->datos();

        $this->assertNull($datos['perdidas']['total']);
        $this->assertSame('apagadas', $datos['perdidas']['motivo']);
    }

    public function test_la_asistencia_es_el_porcentaje_de_asistio_y_la_excusa_no_suma(): void
    {
        $matricula = $this->matricula('ana', $this->piano, $this->actual);
        $ajena = $this->matricula('beto', $this->violin, $this->actual);

        $estados = [Asistencia::ASISTIO, Asistencia::ASISTIO, Asistencia::ASISTIO, Asistencia::EXCUSA, Asistencia::FALTO, Asistencia::FALTO];
        foreach ($estados as $i => $estado) {
            $clase = $this->clase($this->grupoPiano, '2026-03-0'.($i + 2).' 08:00:00');
            Asistencia::create(['clase_id' => $clase->id, 'matricula_id' => $matricula->id, 'estado' => $estado]);
        }
        // Una falta en la promotoria de otro: no le baja el porcentaje.
        Asistencia::create([
            'clase_id' => $this->clase($this->grupoViolin, '2026-03-03 08:00:00')->id,
            'matricula_id' => $ajena->id,
            'estado' => Asistencia::FALTO,
        ]);

        $datos = $this->datos();

        // 3 de 6 = 50 %. Con la excusa sumando serian 66.
        $this->assertSame(50, $datos['asistencia']);
        $this->assertSame(6, $datos['tortaAsistencia']['total']);
        $this->assertSame(50, $datos['porGrupo'][0]['asistencia']);
    }

    public function test_cancelaciones_son_solo_las_tramitadas_de_sus_promotorias(): void
    {
        $this->matricula('a', $this->piano, $this->actual, Matricula::RETIRADA, Matricula::RETIRO_CANCELACION);
        $this->matricula('b', $this->piano, $this->actual, Matricula::RETIRADA, Matricula::RETIRO_PROPIO);
        $this->matricula('c', $this->piano, $this->actual, Matricula::RETIRADA, Matricula::RETIRO_ABANDONO);
        $this->matricula('d', $this->piano, $this->actual, Matricula::CANCELACION_SOLICITADA);
        $this->matricula('e', $this->piano, $this->actual, Matricula::RETIRADA, Matricula::RETIRO_RECHAZO);
        $this->matricula('f', $this->piano, $this->actual);
        // De otro profesor.
        $this->matricula('g', $this->violin, $this->actual, Matricula::RETIRADA, Matricula::RETIRO_CANCELACION);

        $c = $this->datos()['cancelaciones'];

        $this->assertSame(1, $c['tramitadas']);
        $this->assertSame(1, $c['enTramite']);
        // Llegaron a entrar a, b, c, d y f: la solicitud no aceptada (e) no.
        $this->assertSame(5, $c['matriculados']);
    }

    public function test_renovaron_son_los_del_periodo_anterior_que_siguen_en_la_misma_promotoria(): void
    {
        foreach (['uno', 'dos', 'tres'] as $u) {
            $this->matricula($u, $this->piano, $this->anterior);
        }
        // «uno» sigue en Piano. «dos» se paso a Violin: no renovo con el.
        // «tres» no volvio.
        $this->matricula('uno', $this->piano, $this->actual);
        $this->matricula('dos', $this->violin, $this->actual);
        // Alguien de Violin del periodo anterior no entra en su base.
        $this->matricula('cuatro', $this->violin, $this->anterior);

        $r = $this->datos()['renovacion'];

        $this->assertSame('2025-2', $r['anterior']);
        $this->assertSame(3, $r['base']);
        $this->assertSame(1, $r['renovaron']);
    }

    public function test_el_selector_lleva_a_un_periodo_anterior_y_rechaza_uno_sin_nada_suyo(): void
    {
        $this->clase($this->grupoPiano, '2025-09-02 08:00:00', $this->anterior);
        $this->clase($this->grupoPiano, '2026-03-03 08:00:00');
        $vacio = Periodo::create([
            'nombre' => '2024-1', 'fecha_inicio' => '2024-02-01', 'fecha_fin' => '2024-06-30',
            'activo' => false, 'matriculas_abiertas' => false,
        ]);

        $this->assertSame(1, $this->datos($this->anterior)['clasesDadas']);

        // Un periodo sin nada suyo cae en el que esta en curso.
        $this->actingAs($this->profe->user)->get(route('mis-estadisticas-periodo', $vacio))
            ->assertOk()->assertViewHas('periodo', fn (Periodo $p) => $p->id === $this->actual->id);
    }

    public function test_solo_entra_el_profesor(): void
    {
        foreach (['estudiante', 'director', 'administrador'] as $rol) {
            $perfil = $this->perfil('x-'.$rol, $rol);
            $this->actingAs($perfil->user)->get(route('mis-estadisticas'))->assertRedirect(route('post-login'));
        }
    }

    public function test_la_pantalla_pinta_las_cifras_sin_nombres_de_estudiantes(): void
    {
        $matricula = $this->matricula('ana', $this->piano, $this->actual);
        Asistencia::create([
            'clase_id' => $this->clase($this->grupoPiano, '2026-03-03 08:00:00')->id,
            'matricula_id' => $matricula->id,
            'estado' => Asistencia::ASISTIO,
        ]);

        $html = (string) $this->actingAs($this->profe->user)->get(route('mis-estadisticas'))->getContent();

        $this->assertStringContainsString('data-cifra="dadas"', $html);
        $this->assertStringContainsString('data-grupo-clases', $html);
        $this->assertStringNotContainsString('Ana Ruiz', $html);
    }
}
