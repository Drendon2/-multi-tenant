<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\DatosEstudiante;
use App\Models\Grupo;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Models\SesionGrupo;
use App\Models\User;
use App\Support\HorarioSemanal;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * El horario de los grupos: dias y horas de verdad, y la rejilla semanal que
 * sale de ellos.
 *
 * Lo que se prueba aqui es que el horario dejo de ser prosa. Antes era un
 * varchar que cada quien escribia como queria, y con eso no se puede construir
 * una semana ni detectar un cruce.
 */
class HorarioTest extends TestCase
{
    use RefreshDatabase;

    private Periodo $periodo;

    private Promotoria $violin;

    private Promotoria $danza;

    private Perfil $profesor;

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

        $musica = Area::create(['nombre' => 'Musica']);
        $this->profesor = $this->crearPerfil('profe', 'profesor');
        $this->violin = Promotoria::create([
            'nombre' => 'Violin', 'area_id' => $musica->id, 'profesor_id' => $this->profesor->id,
        ]);
        $this->danza = Promotoria::create(['nombre' => 'Danza', 'area_id' => $musica->id]);
    }

    private function crearPerfil(string $username, string $rol): Perfil
    {
        $user = User::create(['username' => $username, 'password' => 'x', 'activo' => true]);

        $perfil = Perfil::create([
            'user_id' => $user->id,
            'rol' => $rol,
            'nombre_completo' => ucfirst($username),
            'fecha_nacimiento' => Carbon::today()->subYears(25)->toDateString(),
            'telefono' => '3000000000',
        ]);

        if ($rol === 'estudiante') {
            DatosEstudiante::create([
                'perfil_id' => $perfil->id,
                'documento_identidad' => '10'.$perfil->id,
            ]);
        }

        return $perfil->refresh();
    }

    private function crearGrupo(Promotoria $promotoria, string $nombre, array $sesiones): Grupo
    {
        $grupo = Grupo::create([
            'promotoria_id' => $promotoria->id,
            'nombre' => $nombre,
            'nivel' => 'basico',
            'salon' => 'A1',
            'cupo_maximo' => 10,
        ]);

        foreach ($sesiones as [$dia, $desde, $hasta]) {
            SesionGrupo::create([
                'grupo_id' => $grupo->id,
                'dia' => $dia,
                'hora_inicio' => $desde,
                'hora_fin' => $hasta,
            ]);
        }

        return $grupo->refresh();
    }

    // -----------------------------------------------------------------------
    // El horario del grupo
    // -----------------------------------------------------------------------

    public function test_un_grupo_de_un_solo_dia_se_lee_en_doce_horas(): void
    {
        $grupo = $this->crearGrupo($this->violin, 'Martes tarde', [[2, '16:00', '18:00']]);

        $this->assertSame('Martes 4:00 p. m. a 6:00 p. m.', $grupo->horario);
    }

    /** Los dias que comparten hora se juntan: es como lo dice la gente. */
    public function test_dos_dias_a_la_misma_hora_se_enuncian_juntos(): void
    {
        $grupo = $this->crearGrupo($this->violin, 'Martes y jueves', [
            [2, '16:00', '18:00'],
            [4, '16:00', '18:00'],
        ]);

        $this->assertSame('Martes y jueves 4:00 p. m. a 6:00 p. m.', $grupo->horario);
    }

    public function test_dos_dias_a_horas_distintas_se_enuncian_por_separado(): void
    {
        $grupo = $this->crearGrupo($this->violin, 'Semana', [
            [1, '08:00', '10:00'],
            [3, '16:00', '18:00'],
        ]);

        $this->assertSame(
            'Lunes 8:00 a. m. a 10:00 a. m. · Miércoles 4:00 p. m. a 6:00 p. m.',
            $grupo->horario
        );
    }

    /** El mediodia se dice «12:00 m.» y no «12:00 p. m.». */
    public function test_el_mediodia_se_escribe_como_se_dice(): void
    {
        $grupo = $this->crearGrupo($this->violin, 'Mediodia', [[5, '10:00', '12:00']]);

        $this->assertSame('Viernes 10:00 a. m. a 12:00 m.', $grupo->horario);
    }

    public function test_las_sesiones_salen_en_orden_de_semana(): void
    {
        $grupo = $this->crearGrupo($this->violin, 'Desordenado', [
            [6, '09:00', '11:00'],
            [1, '09:00', '11:00'],
            [4, '09:00', '11:00'],
        ]);

        $this->assertSame([1, 4, 6], $grupo->sesiones->pluck('dia')->all());
    }

    // -----------------------------------------------------------------------
    // Como se lee el grupo entero
    // -----------------------------------------------------------------------

    public function test_el_rotulo_lleva_nombre_nivel_horario_y_salon(): void
    {
        $grupo = $this->crearGrupo($this->violin, 'A', [[2, '16:00', '18:00']]);

        $this->assertSame(
            'A · Básico · Martes 4:00 p. m. a 6:00 p. m. · A1',
            $grupo->rotulo
        );
        $this->assertSame('A · Básico · Martes 4:00 p. m. a 6:00 p. m.', $grupo->rotulo_breve);
    }

    /**
     * Un grupo recien creado no tiene sesiones hasta que le pongan el horario,
     * y el horario se DERIVA de ellas. Compuesto a mano salia «A · Básico ·»,
     * con el separador colgando.
     */
    public function test_un_grupo_sin_sesiones_no_deja_el_separador_colgando(): void
    {
        $grupo = $this->crearGrupo($this->violin, 'A', []);

        $this->assertSame('', $grupo->horario);
        $this->assertSame('A · Básico', $grupo->rotulo_breve);
        $this->assertSame('A · Básico · A1', $grupo->rotulo);
    }

    // -----------------------------------------------------------------------
    // Lo que el motor no deja pasar
    // -----------------------------------------------------------------------

    public function test_una_sesion_que_termina_antes_de_empezar_se_rechaza(): void
    {
        $grupo = $this->crearGrupo($this->violin, 'Normal', [[2, '16:00', '18:00']]);

        $this->expectException(QueryException::class);

        SesionGrupo::create([
            'grupo_id' => $grupo->id, 'dia' => 3, 'hora_inicio' => '18:00', 'hora_fin' => '16:00',
        ]);
    }

    public function test_un_grupo_no_puede_tener_dos_sesiones_el_mismo_dia(): void
    {
        $grupo = $this->crearGrupo($this->violin, 'Normal', [[2, '16:00', '18:00']]);

        $this->expectException(QueryException::class);

        SesionGrupo::create([
            'grupo_id' => $grupo->id, 'dia' => 2, 'hora_inicio' => '19:00', 'hora_fin' => '21:00',
        ]);
    }

    /** El domingo (7) es dia de clase desde el 05/10/2026. */
    public function test_el_domingo_se_admite(): void
    {
        $grupo = $this->crearGrupo($this->violin, 'Normal', [[2, '16:00', '18:00']]);

        SesionGrupo::create([
            'grupo_id' => $grupo->id, 'dia' => 7, 'hora_inicio' => '09:00', 'hora_fin' => '11:00',
        ]);

        $this->assertSame([2, 7], $grupo->sesiones()->orderBy('dia')->pluck('dia')->all());
    }

    /** Un octavo dia no existe: el motor lo rechaza. */
    public function test_el_dia_ocho_se_rechaza(): void
    {
        $grupo = $this->crearGrupo($this->violin, 'Normal', [[2, '16:00', '18:00']]);

        $this->expectException(QueryException::class);

        SesionGrupo::create([
            'grupo_id' => $grupo->id, 'dia' => 8, 'hora_inicio' => '09:00', 'hora_fin' => '11:00',
        ]);
    }

    /** Al borrar el grupo se van sus sesiones: solas no significan nada. */
    public function test_borrar_el_grupo_se_lleva_sus_sesiones(): void
    {
        $grupo = $this->crearGrupo($this->violin, 'Efimero', [[2, '16:00', '18:00'], [4, '16:00', '18:00']]);

        $this->assertSame(2, DB::table('sesiones_grupo')->where('grupo_id', $grupo->id)->count());

        $grupo->delete();

        $this->assertSame(0, DB::table('sesiones_grupo')->where('grupo_id', $grupo->id)->count());
    }

    // -----------------------------------------------------------------------
    // Cruces
    // -----------------------------------------------------------------------

    public function test_dos_franjas_del_mismo_dia_que_se_pisan_se_detectan(): void
    {
        $a = new SesionGrupo(['dia' => 2, 'hora_inicio' => '16:00', 'hora_fin' => '18:00']);
        $b = new SesionGrupo(['dia' => 2, 'hora_inicio' => '17:00', 'hora_fin' => '19:00']);

        $this->assertTrue($a->seCruzaCon($b));
        $this->assertTrue($b->seCruzaCon($a));
    }

    /** Tocarse por un extremo no es cruzarse: caben seguidas en la misma tarde. */
    public function test_una_clase_que_empieza_cuando_acaba_la_otra_no_se_cruza(): void
    {
        $a = new SesionGrupo(['dia' => 2, 'hora_inicio' => '16:00', 'hora_fin' => '18:00']);
        $b = new SesionGrupo(['dia' => 2, 'hora_inicio' => '18:00', 'hora_fin' => '20:00']);

        $this->assertFalse($a->seCruzaCon($b));
    }

    public function test_la_misma_hora_en_dias_distintos_no_se_cruza(): void
    {
        $a = new SesionGrupo(['dia' => 2, 'hora_inicio' => '16:00', 'hora_fin' => '18:00']);
        $b = new SesionGrupo(['dia' => 3, 'hora_inicio' => '16:00', 'hora_fin' => '18:00']);

        $this->assertFalse($a->seCruzaCon($b));
    }

    // -----------------------------------------------------------------------
    // La rejilla semanal
    // -----------------------------------------------------------------------

    public function test_el_estudiante_ve_los_grupos_donde_esta_repartido(): void
    {
        $estudiante = $this->crearPerfil('ana', 'estudiante');
        $grupo = $this->crearGrupo($this->violin, 'Martes tarde', [[2, '16:00', '18:00']]);

        $matricula = Matricula::create([
            'estudiante_id' => $estudiante->id,
            'promotoria_id' => $this->violin->id,
            'periodo_id' => $this->periodo->id,
            'estado' => Matricula::ACTIVA,
        ]);
        $matricula->repartirEn([$grupo->id]);

        $horario = HorarioSemanal::de($estudiante->refresh(), $this->periodo);

        $this->assertNotNull($horario);
        $this->assertCount(1, $horario['franjas']);
        $this->assertSame('4:00 a 6:00 p. m.', $horario['franjas'][0]['etiqueta']);
        // Martes ocupado, lunes libre.
        $this->assertCount(1, $horario['franjas'][0]['celdas'][2]);
        $this->assertSame([], $horario['franjas'][0]['celdas'][1]);
        $this->assertSame('Violin', $horario['franjas'][0]['celdas'][2][0]['titulo']);
    }

    /** Sin grupo asignado no hay horario: todavia no tiene dónde estar. */
    public function test_una_matricula_sin_grupo_no_pinta_horario(): void
    {
        $estudiante = $this->crearPerfil('ana', 'estudiante');
        $this->crearGrupo($this->violin, 'Martes tarde', [[2, '16:00', '18:00']]);

        Matricula::create([
            'estudiante_id' => $estudiante->id,
            'promotoria_id' => $this->violin->id,
            'periodo_id' => $this->periodo->id,
            'estado' => Matricula::ACTIVA,
        ]);

        $this->assertNull(HorarioSemanal::de($estudiante->refresh(), $this->periodo));
    }

    /** Quien dicta ve los grupos de SUS promotorias, y solo esos. */
    public function test_quien_dicta_ve_sus_grupos_y_no_los_ajenos(): void
    {
        $this->crearGrupo($this->violin, 'Martes tarde', [[2, '16:00', '18:00']]);
        $this->crearGrupo($this->danza, 'Jueves tarde', [[4, '16:00', '18:00']]);

        $horario = HorarioSemanal::de($this->profesor, $this->periodo);

        $this->assertNotNull($horario);
        $this->assertCount(1, $horario['franjas']);
        $this->assertCount(1, $horario['franjas'][0]['celdas'][2]);
        // El jueves es de Danza, que dicta otra persona.
        $this->assertSame([], $horario['franjas'][0]['celdas'][4]);
    }

    /** Un grupo de dos dias ocupa dos sitios en la semana. */
    public function test_un_grupo_de_dos_dias_ocupa_los_dos(): void
    {
        $this->crearGrupo($this->violin, 'Martes y jueves', [
            [2, '16:00', '18:00'],
            [4, '16:00', '18:00'],
        ]);

        $horario = HorarioSemanal::de($this->profesor, $this->periodo);

        $this->assertCount(1, $horario['franjas']);
        $this->assertCount(1, $horario['franjas'][0]['celdas'][2]);
        $this->assertCount(1, $horario['franjas'][0]['celdas'][4]);
    }

    /**
     * Dos clases a la misma hora el mismo dia se ENSEÑAN las dos. Para quien
     * dicta eso es un problema suyo que conviene ver, no esconder.
     */
    public function test_dos_grupos_a_la_misma_hora_se_ven_los_dos(): void
    {
        $otra = Promotoria::create([
            'nombre' => 'Guitarra',
            'area_id' => $this->violin->area_id,
            'profesor_id' => $this->profesor->id,
        ]);

        $this->crearGrupo($this->violin, 'Martes tarde', [[2, '16:00', '18:00']]);
        $this->crearGrupo($otra, 'Martes tarde tambien', [[2, '16:00', '18:00']]);

        $horario = HorarioSemanal::de($this->profesor, $this->periodo);

        $this->assertCount(1, $horario['franjas']);
        $this->assertCount(2, $horario['franjas'][0]['celdas'][2]);
    }

    public function test_las_franjas_salen_ordenadas_por_hora(): void
    {
        $this->crearGrupo($this->violin, 'Tarde', [[2, '16:00', '18:00']]);
        $this->crearGrupo($this->violin, 'Manana', [[3, '08:00', '10:00']]);

        $horario = HorarioSemanal::de($this->profesor, $this->periodo);

        $this->assertSame('8:00 a 10:00 a. m.', $horario['franjas'][0]['etiqueta']);
        $this->assertSame('4:00 a 6:00 p. m.', $horario['franjas'][1]['etiqueta']);
    }

    public function test_sin_periodo_en_curso_no_hay_horario(): void
    {
        $this->crearGrupo($this->violin, 'Martes tarde', [[2, '16:00', '18:00']]);

        $this->assertNull(HorarioSemanal::de($this->profesor, null));
    }

    // -----------------------------------------------------------------------
    // En pantalla
    // -----------------------------------------------------------------------

    public function test_el_perfil_del_estudiante_pinta_su_horario(): void
    {
        $estudiante = $this->crearPerfil('ana', 'estudiante');
        $grupo = $this->crearGrupo($this->violin, 'Martes tarde', [[2, '16:00', '18:00']]);

        $matricula = Matricula::create([
            'estudiante_id' => $estudiante->id,
            'promotoria_id' => $this->violin->id,
            'periodo_id' => $this->periodo->id,
            'estado' => Matricula::ACTIVA,
        ]);
        $matricula->repartirEn([$grupo->id]);

        $this->actingAs($estudiante->user)
            ->get(route('mi-perfil'))
            ->assertOk()
            ->assertSee('Mi horario', false)
            ->assertSee('Sáb', false)
            ->assertSee('4:00 a 6:00 p. m.', false);
    }

    public function test_el_perfil_de_quien_dicta_pinta_su_horario(): void
    {
        $this->crearGrupo($this->violin, 'Martes tarde', [[2, '16:00', '18:00']]);

        $this->actingAs($this->profesor->user)
            ->get(route('mi-perfil'))
            ->assertOk()
            ->assertSee('Mi horario de clases', false);
    }

    /** Sin nada que hacer esta semana, la seccion no aparece. */
    public function test_sin_grupos_el_perfil_no_pinta_rejilla(): void
    {
        $this->actingAs($this->profesor->user)
            ->get(route('mi-perfil'))
            ->assertOk()
            ->assertDontSee('Mi horario', false);
    }

    // -----------------------------------------------------------------------
    // Los formularios
    // -----------------------------------------------------------------------

    public function test_se_crea_un_grupo_de_dos_dias_desde_el_panel(): void
    {
        $this->actingAs($this->profesor->user)
            ->post(route('panel-grupo-nuevo', $this->violin), [
                'nombre' => 'Martes y jueves',
                'nivel' => 'basico',
                'sesiones' => [
                    2 => ['activo' => 1, 'desde' => '16:00', 'hasta' => '18:00'],
                    4 => ['activo' => 1, 'desde' => '16:00', 'hasta' => '18:00'],
                ],
                'salon' => 'A1',
                'cupo_maximo' => 10,
            ])
            ->assertSessionHas('success');

        $grupo = Grupo::where('nombre', 'Martes y jueves')->first();

        $this->assertSame(2, $grupo->sesiones()->count());
        $this->assertSame('Martes y jueves 4:00 p. m. a 6:00 p. m.', $grupo->horario);
    }

    public function test_un_grupo_puede_reunirse_todo_s_los_dias(): void
    {
        // No hay tope de dias, y esta prueba existe para que no aparezca uno.
        // Se reporto desde produccion que un grupo «no dejaba escoger mas de 2
        // dias» teniendo clase 3; en el codigo no hay tal limite —ni en el
        // formulario, que pinta los siete, ni en `HorarioDeGrupo::leer()`, que
        // los recorre todos— asi que esto fija la garantia por si alguien la
        // rompe al tocar la rejilla.
        $marcados = [];

        foreach (array_keys(SesionGrupo::DIAS) as $dia) {
            $marcados[$dia] = ['activo' => 1, 'desde' => '16:00', 'hasta' => '18:00'];
        }

        $this->actingAs($this->profesor->user)
            ->post(route('panel-grupo-nuevo', $this->violin), [
                'nombre' => 'Todos los días',
                'nivel' => 'basico',
                'sesiones' => $marcados,
                'salon' => 'A1',
                'cupo_maximo' => 10,
            ])
            ->assertSessionHas('success');

        $this->assertSame(
            count(SesionGrupo::DIAS),
            Grupo::where('nombre', 'Todos los días')->first()->sesiones()->count()
        );
    }

    public function test_una_promotoria_no_tiene_tope_de_grupos(): void
    {
        // Tampoco hay tope de grupos. Se reporto que «al llegar a 9 no dejaba
        // crear mas»; lo unico que no se puede repetir dentro de una promotoria
        // es el NOMBRE (`un_nombre_por_promotoria`), y el nivel si se repite a
        // proposito. Doce con nombres distintos tienen que entrar los doce.
        foreach (range(1, 12) as $n) {
            $this->actingAs($this->profesor->user)
                ->post(route('panel-grupo-nuevo', $this->violin), [
                    'nombre' => "Grupo {$n}",
                    // Todos del mismo nivel a proposito: es el caso real de una
                    // promotoria con mucha gente, y el que rompia el unico viejo
                    // (promotoria, nivel) antes de que lo relevara el del nombre.
                    'nivel' => 'basico',
                    'sesiones' => [2 => ['activo' => 1, 'desde' => '16:00', 'hasta' => '18:00']],
                    'salon' => 'A1',
                    'cupo_maximo' => 10,
                ])
                ->assertSessionHas('success', fn ($m) => str_contains($m, 'creado'));
        }

        $this->assertSame(12, $this->violin->grupos()->count());
    }

    public function test_al_rebotar_el_formulario_conserva_los_dias_marcados(): void
    {
        // Es lo que decide si un profesor cree que «no le deja» algo. Si el
        // formulario rebota —por un nombre repetido, por una hora al reves— y
        // ademas borra el horario que ya habia puesto, a la segunda o tercera
        // vez cualquiera concluye que hay un tope. `paraElFormulario()` lee
        // `old('sesiones')` justamente para esto, y esta prueba lo fija.
        // `actingAs` UNA sola vez: vaciar la sesion es justo lo que hace
        // `Tests\TestCase::actingAs`, y volver a llamarlo se llevaria por
        // delante el `old()` que esta prueba viene a comprobar.
        $this->actingAs($this->profesor->user);

        $this->post(route('panel-grupo-nuevo', $this->violin), [
            'nombre' => 'Repetido',
            'nivel' => 'basico',
            'sesiones' => [2 => ['activo' => 1, 'desde' => '16:00', 'hasta' => '18:00']],
            'salon' => 'A1',
            'cupo_maximo' => 10,
        ])->assertSessionHas('success');

        // El mismo nombre otra vez, ahora con TRES dias puestos: tiene que
        // rebotar por el nombre, no por el horario.
        $this->post(route('panel-grupo-nuevo', $this->violin), [
            'nombre' => 'Repetido',
            'nivel' => 'basico',
            'sesiones' => [
                1 => ['activo' => 1, 'desde' => '16:00', 'hasta' => '18:00'],
                3 => ['activo' => 1, 'desde' => '16:00', 'hasta' => '18:00'],
                5 => ['activo' => 1, 'desde' => '16:00', 'hasta' => '18:00'],
            ],
            'salon' => 'A1',
            'cupo_maximo' => 10,
        ])
            ->assertSessionHasErrors('nombre');

        // Y al volver al formulario, los tres dias siguen marcados con su hora.
        $html = $this->get(route('panel-grupo-nuevo', $this->violin))
            ->assertOk()
            ->getContent();

        foreach ([1, 3, 5] as $dia) {
            $this->assertMatchesRegularExpression(
                '/id="sesion-'.$dia.'"[^>]*checked/',
                $html,
                "El día {$dia} perdió su marca al rebotar el formulario."
            );
        }
    }

    public function test_el_choque_de_nombre_explica_que_el_nivel_no_lo_salva(): void
    {
        // El caso REAL que se reporto desde produccion: un profesor creo dos
        // grupos con el mismo nombre y distinto nivel, y leyo el rechazo como
        // un tope de grupos. El mensaje tiene que responder a lo que esta
        // pensando —«pero si son de niveles distintos»— o no sirve de nada.
        $this->actingAs($this->profesor->user);

        $this->post(route('panel-grupo-nuevo', $this->violin), [
            'nombre' => 'Grupo A',
            'nivel' => 'basico',
            'sesiones' => [2 => ['activo' => 1, 'desde' => '16:00', 'hasta' => '18:00']],
            'salon' => 'A1',
            'cupo_maximo' => 10,
        ])->assertSessionHas('success');

        $this->post(route('panel-grupo-nuevo', $this->violin), [
            'nombre' => 'Grupo A',
            'nivel' => 'avanzado',
            'sesiones' => [2 => ['activo' => 1, 'desde' => '16:00', 'hasta' => '18:00']],
            'salon' => 'A1',
            'cupo_maximo' => 10,
        ])->assertSessionHasErrors('nombre');

        $mensaje = session('errors')->get('nombre')[0];

        $this->assertStringContainsString('aunque sea de otro nivel', $mensaje);
        $this->assertStringContainsString('Violin', $mensaje);
    }

    public function test_gestion_da_el_mismo_mensaje_y_no_el_generico_de_laravel(): void
    {
        // Aqui llegaba «Ya existe un registro con ese nombre»: ni dice en que
        // promotoria choca ni por que choca teniendo otro nivel.
        $admin = $this->crearPerfil('admin', 'administrador');

        $this->actingAs($admin->user);

        $this->post(route('grupo-nuevo'), [
            'promotoria_id' => $this->violin->id,
            'nombre' => 'Grupo A',
            'nivel' => 'basico',
            'sesiones' => [2 => ['activo' => 1, 'desde' => '16:00', 'hasta' => '18:00']],
            'salon' => 'A1',
            'cupo_maximo' => 10,
        ]);

        $this->post(route('grupo-nuevo'), [
            'promotoria_id' => $this->violin->id,
            'nombre' => 'Grupo A',
            'nivel' => 'avanzado',
            'sesiones' => [2 => ['activo' => 1, 'desde' => '16:00', 'hasta' => '18:00']],
            'salon' => 'A1',
            'cupo_maximo' => 10,
        ])->assertSessionHasErrors('nombre');

        $mensaje = session('errors')->get('nombre')[0];

        $this->assertStringContainsString('aunque sea de otro nivel', $mensaje);
        $this->assertStringNotContainsString('Ya existe un registro', $mensaje);
    }

    public function test_lunes_miercoles_y_sabado_es_un_horario_valido(): void
    {
        // El caso exacto que se reporto desde produccion. El sabado es el dia 6
        // y es el ultimo del CHECK `dia_valido` (BETWEEN 1 AND 6): si alguna vez
        // se cuela un off-by-one ahi, es este dia el que se cae y ningun otro.
        $this->actingAs($this->profesor->user)
            ->post(route('panel-grupo-nuevo', $this->violin), [
                'nombre' => 'Lunes, miércoles y sábado',
                'nivel' => 'basico',
                'sesiones' => [
                    1 => ['activo' => 1, 'desde' => '08:00', 'hasta' => '10:00'],
                    3 => ['activo' => 1, 'desde' => '08:00', 'hasta' => '10:00'],
                    6 => ['activo' => 1, 'desde' => '09:00', 'hasta' => '11:00'],
                ],
                'salon' => 'A1',
                'cupo_maximo' => 10,
            ])
            ->assertSessionHas('success');

        $grupo = Grupo::where('nombre', 'Lunes, miércoles y sábado')->first();

        $this->assertSame([1, 3, 6], $grupo->sesiones()->pluck('dia')->all());
    }

    public function test_un_rechazo_se_anuncia_a_nivel_de_pagina(): void
    {
        // La causa de fondo de lo reportado: un error de campo, por bien puesto
        // que este, no se ve si queda fuera de pantalla — y `acciones.js`
        // restaura el scroll anterior, asi que tras un rechazo la persona sigue
        // mirando exactamente el mismo sitio. Sin un aviso de pagina, el
        // formulario parece no haber hecho nada.
        $this->actingAs($this->profesor->user);

        $this->post(route('panel-grupo-nuevo', $this->violin), [
            'nombre' => 'Grupo A',
            'nivel' => 'basico',
            'sesiones' => [2 => ['activo' => 1, 'desde' => '16:00', 'hasta' => '18:00']],
            'salon' => 'A1',
            'cupo_maximo' => 10,
        ])->assertSessionHas('success');

        $this->post(route('panel-grupo-nuevo', $this->violin), [
            'nombre' => 'Grupo A',
            'nivel' => 'avanzado',
            'sesiones' => [2 => ['activo' => 1, 'desde' => '16:00', 'hasta' => '18:00']],
            'salon' => 'A1',
            'cupo_maximo' => 10,
        ])->assertSessionHasErrors('nombre');

        $this->get(route('panel-grupo-nuevo', $this->violin))
            ->assertOk()
            ->assertSee('No se guardó.')
            ->assertSee('Hay un campo por corregir');
    }

    public function test_un_grupo_sin_ningun_dia_marcado_se_rechaza(): void
    {
        $this->actingAs($this->profesor->user)
            ->post(route('panel-grupo-nuevo', $this->violin), [
                'nombre' => 'Sin horario',
                'nivel' => 'basico',
                'salon' => 'A1',
                'cupo_maximo' => 10,
            ])
            ->assertSessionHasErrors('sesiones');

        $this->assertSame(0, Grupo::where('nombre', 'Sin horario')->count());
    }

    public function test_una_hora_de_fin_anterior_a_la_de_inicio_se_rechaza(): void
    {
        $this->actingAs($this->profesor->user)
            ->post(route('panel-grupo-nuevo', $this->violin), [
                'nombre' => 'Al reves',
                'nivel' => 'basico',
                'sesiones' => [2 => ['activo' => 1, 'desde' => '18:00', 'hasta' => '16:00']],
                'salon' => 'A1',
                'cupo_maximo' => 10,
            ])
            ->assertSessionHasErrors('sesiones.2.hasta');

        // Y no queda un grupo a medias: el horario se comprueba antes de crear.
        $this->assertSame(0, Grupo::where('nombre', 'Al reves')->count());
    }

    public function test_un_dia_marcado_sin_hora_se_rechaza(): void
    {
        $this->actingAs($this->profesor->user)
            ->post(route('panel-grupo-nuevo', $this->violin), [
                'nombre' => 'A medias',
                'nivel' => 'basico',
                'sesiones' => [2 => ['activo' => 1, 'desde' => '', 'hasta' => '']],
                'salon' => 'A1',
                'cupo_maximo' => 10,
            ])
            ->assertSessionHasErrors('sesiones.2.desde');
    }

    /** Editar el horario quita los dias que se desmarcan. */
    public function test_al_editar_se_quitan_los_dias_desmarcados(): void
    {
        $grupo = $this->crearGrupo($this->violin, 'Martes y jueves', [
            [2, '16:00', '18:00'],
            [4, '16:00', '18:00'],
        ]);

        $this->actingAs($this->profesor->user)
            ->post(route('panel-grupo-editar', $grupo), [
                'nombre' => 'Solo martes',
                'nivel' => 'basico',
                'sesiones' => [2 => ['activo' => 1, 'desde' => '16:00', 'hasta' => '18:00']],
                'salon' => 'A1',
                'cupo_maximo' => 10,
            ])
            ->assertSessionHas('success');

        $this->assertSame([2], $grupo->refresh()->sesiones->pluck('dia')->all());
    }

    /** El formulario de Gestion sigue las mismas reglas que el del Panel. */
    public function test_gestion_crea_un_grupo_con_horario(): void
    {
        $director = $this->crearPerfil('dire', 'director');

        $this->actingAs($director->user)->post(route('grupo-nuevo'), [
            'promotoria_id' => $this->violin->id,
            'nombre' => 'Sabado manana',
            'nivel' => 'basico',
            'sesiones' => [6 => ['activo' => 1, 'desde' => '09:00', 'hasta' => '11:00']],
            'salon' => 'A1',
            'cupo_maximo' => 10,
        ]);

        $grupo = Grupo::where('nombre', 'Sabado manana')->first();

        $this->assertNotNull($grupo);
        $this->assertSame('Sábado 9:00 a. m. a 11:00 a. m.', $grupo->horario);
    }

    public function test_gestion_rechaza_un_grupo_sin_horario(): void
    {
        $director = $this->crearPerfil('dire', 'director');

        $this->actingAs($director->user)->post(route('grupo-nuevo'), [
            'promotoria_id' => $this->violin->id,
            'nombre' => 'Sin horas',
            'nivel' => 'basico',
            'salon' => 'A1',
            'cupo_maximo' => 10,
        ])->assertSessionHasErrors('sesiones');

        $this->assertSame(0, Grupo::where('nombre', 'Sin horas')->count());
    }
}
