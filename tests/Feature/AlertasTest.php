<?php

namespace Tests\Feature;

use App\Models\Actividad;
use App\Models\Area;
use App\Models\Asistencia;
use App\Models\Clase;
use App\Models\ConfiguracionInstitucion;
use App\Models\DatosEstudiante;
use App\Models\Grupo;
use App\Models\InstitucionExterna;
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
 * Las dos alertas de la bandeja (02/09/2026).
 *
 * - CLASE NO DICTADA: el grupo tenia el martes en su horario, el martes paso y
 *   nadie registro la clase.
 * - POSIBLE ABANDONO: demasiadas faltas SEGUIDAS y sin excusa.
 *
 * Lo que mas importa probar aqui son las FRONTERAS, porque son decisiones de
 * producto y no detalles: que hoy no cuenta todavia —quien dicta tiene el dia
 * entero—, que una excusa corta la racha, y que ninguna alerta cambia nada por
 * su cuenta. Esa ultima es la que separa esta funcionalidad de un sistema que
 * retira gente solo.
 *
 * Las fechas se fijan con `Carbon::setTestNow`: una alerta que depende de «ayer»
 * probada contra el reloj de verdad pasa un martes y falla un lunes.
 */
class AlertasTest extends TestCase
{
    use RefreshDatabase;

    private Perfil $admin;

    private Periodo $periodo;

    private Grupo $grupo;

    protected function setUp(): void
    {
        parent::setUp();

        // Un miercoles cualquiera, para que «ayer» sea martes y el horario de
        // los martes tenga exactamente un dia vencido cerca.
        Carbon::setTestNow(Carbon::parse('2026-03-11 10:00:00'));

        $this->periodo = Periodo::create([
            'nombre' => '2026-1',
            'fecha_inicio' => '2026-03-02',
            'fecha_fin' => '2026-06-30',
            'activo' => true,
            'matriculas_abiertas' => true,
        ]);

        $this->admin = $this->crearPerfil('admin', 'administrador');

        $area = Area::create(['nombre' => 'Musica']);
        $promotoria = Promotoria::create([
            'nombre' => 'Violin',
            'area_id' => $area->id,
            'profesor_id' => $this->crearPerfil('profe', 'profesor')->id,
        ]);

        /** @var Grupo $grupo */
        $grupo = $promotoria->grupos()->create([
            'nombre' => 'Grupo A',
            'nivel' => 'basico',
            'cupo_maximo' => 10,
            'salon' => '',
        ]);

        $this->grupo = $grupo;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function crearPerfil(string $username, string $rol): Perfil
    {
        $user = User::create(['username' => $username, 'password' => 'x', 'activo' => true]);

        return Perfil::create([
            'user_id' => $user->id,
            'rol' => $rol,
            'nombre_completo' => ucfirst($username),
            'fecha_nacimiento' => '1990-01-01',
            'telefono' => '3000000000',
        ]);
    }

    /** Le pone al grupo ese dia de la semana (ISO: 1 = lunes). */
    private function horarioEn(int $dia): void
    {
        $this->grupo->sesiones()->create([
            'dia' => $dia,
            'hora_inicio' => '08:00',
            'hora_fin' => '10:00',
        ]);
    }

    private function clase(string $fecha): Clase
    {
        return Clase::create([
            'grupo_id' => $this->grupo->id,
            'periodo_id' => $this->periodo->id,
            'fecha_hora' => $fecha,
            'registrada_por_id' => $this->admin->id,
        ]);
    }

    private function estudianteMatriculado(string $username): Matricula
    {
        $perfil = $this->crearPerfil($username, 'estudiante');
        DatosEstudiante::create([
            'perfil_id' => $perfil->id,
            'documento_identidad' => '1'.$perfil->id,
        ]);

        $matricula = new Matricula([
            'estudiante_id' => $perfil->id,
            'promotoria_id' => $this->grupo->promotoria_id,
            'periodo_id' => $this->periodo->id,
            'estado' => Matricula::ACTIVA,
        ]);
        $matricula->save();
        $matricula->repartirEn([$this->grupo->id]);

        return $matricula;
    }

    // -----------------------------------------------------------------------
    // Clases no dictadas

    public function test_un_dia_del_horario_sin_clase_registrada_sale_como_alerta(): void
    {
        // Martes. Entre el 02/03 y ayer (10/03) hay dos: el 3 y el 10.
        $this->horarioEn(2);

        $faltantes = Alertas::clasesNoDictadas($this->periodo);

        $this->assertCount(2, $faltantes);
        $this->assertSame(
            ['2026-03-10', '2026-03-03'],
            $faltantes->map(fn ($f) => $f['fecha']->toDateString())->all(),
            'La más reciente va primero: es la que todavía se puede recuperar hablando con quien dicta.'
        );
    }

    public function test_el_dia_que_si_hubo_clase_no_sale(): void
    {
        $this->horarioEn(2);
        $this->clase('2026-03-10 08:15:00');

        $faltantes = Alertas::clasesNoDictadas($this->periodo);

        $this->assertCount(1, $faltantes);
        $this->assertSame('2026-03-03', $faltantes->first()['fecha']->toDateString());
    }

    /**
     * HOY no cuenta todavia, y es la frontera de esta alerta: quien dicta tiene
     * todo el dia para iniciar la clase y pasar lista. Avisar a las nueve de la
     * mañana de una clase que es a las seis de la tarde seria avisar de nada.
     */
    public function test_la_clase_de_hoy_no_cuenta_todavia(): void
    {
        // Miercoles, que es hoy. El miercoles 4 tambien cae dentro del periodo
        // y ese SI cuenta: lo que se exige es que HOY no aparezca.
        $this->horarioEn(3);

        $fechas = Alertas::clasesNoDictadas($this->periodo)
            ->map(fn ($f) => $f['fecha']->toDateString())
            ->all();

        $this->assertContains('2026-03-04', $fechas);
        $this->assertNotContains('2026-03-11', $fechas, 'Hoy no ha terminado: quien dicta tiene todo el día.');
    }

    public function test_lo_anterior_al_periodo_no_cuenta(): void
    {
        // Lunes. El 2 de marzo es el primer dia del periodo; el 23 de febrero
        // tambien fue lunes y queda fuera.
        $this->horarioEn(1);

        $faltantes = Alertas::clasesNoDictadas($this->periodo);

        $this->assertCount(2, $faltantes);
        $this->assertSame(
            ['2026-03-09', '2026-03-02'],
            $faltantes->map(fn ($f) => $f['fecha']->toDateString())->all()
        );
    }

    public function test_una_omision_archivada_desaparece_de_la_lista(): void
    {
        $this->horarioEn(2);

        OmisionArchivada::create([
            'grupo_id' => $this->grupo->id,
            'fecha' => '2026-03-03',
            'archivada_por_id' => $this->admin->id,
        ]);

        $faltantes = Alertas::clasesNoDictadas($this->periodo);

        $this->assertCount(1, $faltantes);
        $this->assertSame('2026-03-10', $faltantes->first()['fecha']->toDateString());
    }

    public function test_un_grupo_sin_horario_no_falta_a_nada(): void
    {
        $this->assertCount(0, Alertas::clasesNoDictadas($this->periodo));
    }

    // -----------------------------------------------------------------------
    // Desde cuando cuentan

    /**
     * Un periodo academico INCLUYE su periodo de matriculas: semanas de
     * inscribir gente y armar grupos antes de que nadie de una clase. Sin esta
     * fecha, la bandeja se llenaba de dias en los que nadie tenia que dar clase
     * todavia — 596 avisos el primer dia en produccion, correctos e inutiles.
     *
     * La pone quien administra, junto a los interruptores de las alertas: quien
     * enciende una es quien decide desde cuando cuenta.
     */
    public function test_lo_anterior_al_arranque_de_las_clases_no_avisa(): void
    {
        // Martes: el 3 y el 10 caen dentro del periodo.
        $this->horarioEn(2);

        ConfiguracionInstitucion::actual()->update(['alertas_desde' => '2026-03-09']);

        $faltantes = Alertas::clasesNoDictadas($this->periodo);

        $this->assertCount(1, $faltantes);
        $this->assertSame('2026-03-10', $faltantes->first()['fecha']->toDateString());
    }

    /** Vacia se comporta como antes de que la columna existiera. */
    public function test_sin_fecha_cuenta_desde_el_inicio_del_periodo(): void
    {
        $this->horarioEn(2);

        $this->assertNull(ConfiguracionInstitucion::actual()->alertas_desde);
        $this->assertCount(2, Alertas::clasesNoDictadas($this->periodo));
    }

    /**
     * Una fecha ANTERIOR al periodo no puede hacer que se miren dias que ese
     * periodo no tiene. Importa porque la fecha es global y los periodos van
     * cambiando: la de un semestre queda vieja para el siguiente.
     */
    public function test_una_fecha_anterior_al_periodo_no_amplia_la_ventana(): void
    {
        $this->horarioEn(2);

        ConfiguracionInstitucion::actual()->update(['alertas_desde' => '2025-01-01']);

        // Las mismas dos del periodo, ni una mas.
        $this->assertCount(2, Alertas::clasesNoDictadas($this->periodo));
    }

    /**
     * Y la MISMA frontera para la otra alerta. Si solo la respetara una, mover
     * la fecha limpiaria una bandeja y dejaria la otra contando faltas de
     * cuando no habia clases.
     */
    public function test_las_faltas_anteriores_al_arranque_no_cuentan(): void
    {
        $matricula = $this->estudianteMatriculado('ana');

        foreach (['03-02', '03-03', '03-04', '03-05', '03-06'] as $dia) {
            $clase = $this->clase("2026-{$dia} 08:00:00");
            Asistencia::create([
                'clase_id' => $clase->id,
                'matricula_id' => $matricula->id,
                'estado' => Asistencia::FALTO,
            ]);
        }

        $this->assertCount(1, Alertas::posiblesAbandonos($this->periodo));

        ConfiguracionInstitucion::actual()->update(['alertas_desde' => '2026-03-05']);

        // Solo quedan dos faltas dentro de la ventana: por debajo del umbral.
        $this->assertCount(0, Alertas::posiblesAbandonos($this->periodo));
    }

    public function test_la_fecha_se_edita_donde_se_encienden_las_alertas(): void
    {
        $this->actingAs($this->admin->user)
            ->post(route('gestion-configuracion'), [
                'nombre_institucion' => 'Casa de la Cultura',
                'color_acento' => '#0a7a59',
                'limite_promotorias_por_periodo' => 2,
                'faltas_para_abandono' => 5,
                'alertas_desde' => '2026-03-09',
            ])
            ->assertSessionHas('success');

        $this->assertSame(
            '2026-03-09',
            Carbon::parse(ConfiguracionInstitucion::actual()->fresh()->alertas_desde)->toDateString()
        );
    }

    /**
     * Y vaciarla vuelve a «desde el inicio del periodo», no a una cadena vacia.
     *
     * OJO: quien hace pasar esto es el middleware `ConvertEmptyStringsToNull` de
     * Laravel, no el `?: null` del controlador — se comprobo quitandolo y la
     * prueba siguio verde. Lo que vigila es el camino entero: que el formulario
     * vacio acabe en NULL. Con una cadena vacia, MariaDB rechaza el INSERT.
     */
    public function test_vaciar_la_fecha_la_deja_nula(): void
    {
        ConfiguracionInstitucion::actual()->update(['alertas_desde' => '2026-03-09']);

        $this->actingAs($this->admin->user)
            ->post(route('gestion-configuracion'), [
                'nombre_institucion' => 'Casa de la Cultura',
                'color_acento' => '#0a7a59',
                'limite_promotorias_por_periodo' => 2,
                'faltas_para_abandono' => 5,
                'alertas_desde' => '',
            ])
            ->assertSessionHas('success');

        $this->assertNull(ConfiguracionInstitucion::actual()->fresh()->alertas_desde);
    }

    /**
     * El riesgo de tener la fecha aqui y no en el periodo es que se quede vieja
     * al cambiar de semestre y apague las alertas SIN DECIRLO. No lo arregla el
     * esquema: lo arregla que la pantalla avise.
     */
    public function test_la_pantalla_avisa_si_la_fecha_dejo_fuera_al_periodo(): void
    {
        ConfiguracionInstitucion::actual()->update(['alertas_desde' => '2026-12-01']);

        $this->actingAs($this->admin->user)
            ->get(route('gestion-configuracion'))
            ->assertOk()
            ->assertSee('no sale ninguna alerta', false);
    }

    // -----------------------------------------------------------------------
    // Posibles abandonos

    public function test_las_faltas_seguidas_levantan_la_alerta(): void
    {
        $matricula = $this->estudianteMatriculado('ana');

        foreach (['03-02', '03-03', '03-04', '03-05', '03-06'] as $dia) {
            $clase = $this->clase("2026-{$dia} 08:00:00");
            Asistencia::create([
                'clase_id' => $clase->id,
                'matricula_id' => $matricula->id,
                'estado' => Asistencia::FALTO,
            ]);
        }

        $casos = Alertas::posiblesAbandonos($this->periodo);

        $this->assertCount(1, $casos);
        $this->assertSame(5, $casos->first()['faltas']);
        $this->assertTrue($matricula->is($casos->first()['matricula']));
    }

    public function test_con_una_falta_menos_no_hay_alerta(): void
    {
        $matricula = $this->estudianteMatriculado('ana');

        foreach (['03-03', '03-04', '03-05', '03-06'] as $dia) {
            $clase = $this->clase("2026-{$dia} 08:00:00");
            Asistencia::create([
                'clase_id' => $clase->id,
                'matricula_id' => $matricula->id,
                'estado' => Asistencia::FALTO,
            ]);
        }

        $this->assertCount(0, Alertas::posiblesAbandonos($this->periodo));
    }

    /**
     * La excusa CORTA la racha, y es la decision que hace util a esta alerta:
     * quien avisa de que no puede ir es lo contrario de quien desaparece.
     */
    public function test_una_excusa_corta_la_racha(): void
    {
        $matricula = $this->estudianteMatriculado('ana');

        $marcas = [
            '03-02' => Asistencia::FALTO,
            '03-03' => Asistencia::FALTO,
            '03-04' => Asistencia::EXCUSA,
            '03-05' => Asistencia::FALTO,
            '03-06' => Asistencia::FALTO,
            '03-09' => Asistencia::FALTO,
        ];

        foreach ($marcas as $dia => $estado) {
            $clase = $this->clase("2026-{$dia} 08:00:00");
            Asistencia::create([
                'clase_id' => $clase->id,
                'matricula_id' => $matricula->id,
                'estado' => $estado,
            ]);
        }

        // Tres seguidas después de la excusa: por debajo del umbral de cinco,
        // aunque en total haya cinco faltas.
        $this->assertCount(0, Alertas::posiblesAbandonos($this->periodo));
    }

    /** Y si vuelve a venir, la racha se rompe y la alerta desaparece sola. */
    public function test_volver_a_clase_apaga_la_alerta(): void
    {
        $matricula = $this->estudianteMatriculado('ana');

        foreach (['03-02', '03-03', '03-04', '03-05', '03-06'] as $dia) {
            $clase = $this->clase("2026-{$dia} 08:00:00");
            Asistencia::create([
                'clase_id' => $clase->id,
                'matricula_id' => $matricula->id,
                'estado' => Asistencia::FALTO,
            ]);
        }

        $this->assertCount(1, Alertas::posiblesAbandonos($this->periodo));

        $ultima = $this->clase('2026-03-09 08:00:00');
        Asistencia::create([
            'clase_id' => $ultima->id,
            'matricula_id' => $matricula->id,
            'estado' => Asistencia::ASISTIO,
        ]);

        $this->assertCount(0, Alertas::posiblesAbandonos($this->periodo));
    }

    public function test_una_matricula_retirada_no_abandona_nada(): void
    {
        $matricula = $this->estudianteMatriculado('ana');

        foreach (['03-02', '03-03', '03-04', '03-05', '03-06'] as $dia) {
            $clase = $this->clase("2026-{$dia} 08:00:00");
            Asistencia::create([
                'clase_id' => $clase->id,
                'matricula_id' => $matricula->id,
                'estado' => Asistencia::FALTO,
            ]);
        }

        $matricula->estado = Matricula::RETIRADA;
        $matricula->save();

        $this->assertCount(0, Alertas::posiblesAbandonos($this->periodo));
    }

    // -----------------------------------------------------------------------
    // Los interruptores

    public function test_apagadas_no_se_calculan(): void
    {
        $this->horarioEn(2);

        ConfiguracionInstitucion::actual()->update([
            'alerta_clase_no_dictada' => false,
            'alerta_abandono' => false,
        ]);

        $html = $this->actingAs($this->admin->user)
            ->get(route('gestion-cancelaciones'))->assertOk()->getContent();

        $this->assertStringNotContainsString('03/03/2026', $html);
    }

    public function test_encendidas_salen_en_la_pantalla(): void
    {
        $this->horarioEn(2);

        $html = $this->actingAs($this->admin->user)
            ->get(route('gestion-cancelaciones'))->assertOk()->getContent();

        $this->assertStringContainsString('Alertas y cancelaciones', $html);
        $this->assertStringContainsString('Clases que no se dictaron', $html);
        $this->assertStringContainsString('03/03/2026', $html);
    }

    // -----------------------------------------------------------------------
    // Las acciones

    public function test_archivar_saca_la_omision_de_la_bandeja(): void
    {
        $this->horarioEn(2);

        $this->actingAs($this->admin->user)
            ->post(route('gestion-clasificar-omision'), [
                'grupo_id' => $this->grupo->id,
                'fecha' => '2026-03-03',
                'causa' => OmisionArchivada::FALTA,
            ])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('omisiones_archivadas', [
            'grupo_id' => $this->grupo->id,
            'archivada_por_id' => $this->admin->id,
        ]);

        $this->assertCount(1, Alertas::clasesNoDictadas($this->periodo));
    }

    /** Dos pestañas archivando lo mismo no pueden reventar contra la clave unica. */
    public function test_archivar_dos_veces_no_falla(): void
    {
        $this->horarioEn(2);

        foreach ([1, 2] as $vez) {
            $this->actingAs($this->admin->user)
                ->post(route('gestion-clasificar-omision'), [
                    'grupo_id' => $this->grupo->id,
                    'fecha' => '2026-03-03',
                    'causa' => OmisionArchivada::EXCUSA,
                ])
                ->assertSessionHas('success');
        }

        $this->assertSame(1, OmisionArchivada::count());
    }

    /**
     * Y la alerta de abandono NO retira a nadie sola: trae la accion al lado y
     * la aprieta una persona. Retirar libera el cupo y la ranura, que es una
     * consecuencia sobre quien esta esperando ese cupo.
     */
    public function test_la_alerta_no_retira_a_nadie_sola(): void
    {
        $matricula = $this->estudianteMatriculado('ana');

        foreach (['03-02', '03-03', '03-04', '03-05', '03-06'] as $dia) {
            $clase = $this->clase("2026-{$dia} 08:00:00");
            Asistencia::create([
                'clase_id' => $clase->id,
                'matricula_id' => $matricula->id,
                'estado' => Asistencia::FALTO,
            ]);
        }

        $this->actingAs($this->admin->user)->get(route('gestion-cancelaciones'))->assertOk();

        $this->assertSame(Matricula::ACTIVA, $matricula->fresh()->estado);

        // Y con la accion, si.
        $this->actingAs($this->admin->user)
            ->post(route('gestion-retirar-abandono', $matricula))
            ->assertSessionHas('success');

        $this->assertSame(Matricula::RETIRADA, $matricula->fresh()->estado);
        $this->assertSame(0, $matricula->grupos()->count());
    }

    public function test_un_profesor_no_entra_a_la_bandeja(): void
    {
        $this->actingAs($this->crearPerfil('otro', 'profesor')->user)
            ->get(route('gestion-cancelaciones'))
            ->assertRedirect(route('post-login'));
    }
    // -----------------------------------------------------------------------
    // Desempates (05/10/2026). Los empatados se crean en el orden CONTRARIO al
    // del nombre: sin desempate saldrian en el de la consulta, que es el de
    // creacion, y la prueba se pondria roja.

    /** Otro grupo con clase los dias dados, en una promotoria nueva o en Violin. */
    private function otroGrupo(string $promotoria, string $grupo, int $dia): Grupo
    {
        $prom = $promotoria === 'Violin'
            ? Promotoria::findOrFail($this->grupo->promotoria_id)
            : Promotoria::create([
                'nombre' => $promotoria,
                'area_id' => Promotoria::findOrFail($this->grupo->promotoria_id)->area_id,
                'profesor_id' => Promotoria::findOrFail($this->grupo->promotoria_id)->profesor_id,
            ]);

        /** @var Grupo $nuevo */
        $nuevo = $prom->grupos()->create(['nombre' => $grupo, 'nivel' => 'basico', 'cupo_maximo' => 10, 'salon' => '']);
        $nuevo->sesiones()->create(['dia' => $dia, 'hora_inicio' => '08:00', 'hora_fin' => '10:00']);

        return $nuevo;
    }

    public function test_las_clases_del_mismo_dia_desempatan_por_promotoria_y_grupo(): void
    {
        $this->horarioEn(2);                          // Violin · Grupo A, el primero creado
        $this->otroGrupo('Violin', 'Grupo 0', 2);     // mismo nombre de promotoria, grupo antes
        $this->otroGrupo('Arpa', 'Grupo Z', 2);       // promotoria antes en el alfabeto

        $orden = Alertas::clasesNoDictadas($this->periodo)
            ->map(fn ($f) => $f['fecha']->format('d/m').' '.$f['grupo']->promotoria->nombre.' · '.$f['grupo']->nombre)
            ->all();

        $this->assertSame([
            '10/03 Arpa · Grupo Z', '10/03 Violin · Grupo 0', '10/03 Violin · Grupo A',
            '03/03 Arpa · Grupo Z', '03/03 Violin · Grupo 0', '03/03 Violin · Grupo A',
        ], $orden);
    }

    public function test_los_abandonos_con_las_mismas_faltas_desempatan_por_nombre(): void
    {
        $zuleta = $this->estudianteMatriculado('zuleta');
        $alvarez = $this->estudianteMatriculado('álvarez');

        foreach (['03-02', '03-03', '03-04', '03-05', '03-06'] as $dia) {
            $clase = $this->clase("2026-{$dia} 08:00:00");
            foreach ([$zuleta, $alvarez] as $m) {
                Asistencia::create(['clase_id' => $clase->id, 'matricula_id' => $m->id, 'estado' => Asistencia::FALTO]);
            }
        }

        $this->assertSame(
            ['álvarez', 'Zuleta'],   // en minuscula y con tilde: no altera el orden
            Alertas::posiblesAbandonos($this->periodo)->map(fn ($c) => $c['matricula']->estudiante->nombre_completo)->all()
        );
    }

    /**
     * La portada recorta a tres y MEZCLA las bandejas. El unico empate real
     * entre tipos distintos es el de una clase sin dictar de un lunes y la
     * semana sin clase de un programa externo que empieza ese lunes: las dos
     * van a medianoche. Sin desempate ganaba la que se apilo antes (las
     * clases); con el, la que va antes por lo que se lee.
     */
    public function test_la_portada_desempata_por_lo_que_se_lee(): void
    {
        $this->horarioEn(1);                          // Violin · Grupo A, los lunes: 02/03 y 09/03

        Carbon::setTestNow(Carbon::parse('2026-03-01 09:00:00'));
        $escuela = InstitucionExterna::create([
            'nombre' => 'I. E. Rural El Carmen',
            'perfil_id' => $this->crearPerfil('carmen', Perfil::INSTITUCION_EXTERNA)->id,
            'clases_desde' => '2026-03-02',
        ]);
        Actividad::create([
            'nombre' => 'Arpa — El Carmen',
            'tipo' => Actividad::EXTERNO,
            'responsable_id' => Promotoria::findOrFail($this->grupo->promotoria_id)->profesor_id,
            'institucion_externa_id' => $escuela->id,
        ]);
        Carbon::setTestNow(Carbon::parse('2026-03-11 10:00:00'));

        $html = (string) $this->actingAs($this->admin->user)->get(route('gestion-inicio'))->assertOk()->getContent();
        preg_match_all('#<span class="alertas-banner-texto">\s*<strong>(.*?)</strong>#s', $html, $m);

        $this->assertSame(
            ['Violin · Grupo A', 'Arpa — El Carmen', 'Violin · Grupo A'],
            array_map('html_entity_decode', $m[1])
        );
    }
}
