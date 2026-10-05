<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Asistencia;
use App\Models\Clase;
use App\Models\Grupo;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Models\SesionGrupo;
use App\Models\User;
use App\Support\Companeros;
use App\Support\Dependencias;
use App\Support\HorarioSemanal;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Que una matricula pueda estar en VARIOS grupos.
 *
 * El caso que se viene a resolver es corriente: alguien que va al Grupo A el
 * lunes Y al Grupo B el miercoles, de la misma promotoria. Son dos clases
 * distintas y se le pasa lista en las dos. Con `matriculas.grupo_id` —una sola
 * columna— no se puede ni escribir.
 *
 * ─── LA COLUMNA `matriculas.grupo_id` YA NO EXISTE ──────────────────────────
 *
 * Se llego aqui en cuatro pasos —la tabla, una escritura doble temporal, los
 * lectores uno a uno, y borrar la columna—, y de esos solo queda el resultado.
 * Las pruebas de la escritura doble se fueron con ella: probaban un andamio.
 *
 * Se reparte con `Matricula::repartirEn()`, que es la unica forma. Ahi vive
 * tambien la regla del cupo de cada grupo.
 */
class VariosGruposPorMatriculaTest extends TestCase
{
    use RefreshDatabase;

    private Periodo $periodo;

    private Promotoria $violin;

    private Grupo $lunes;

    private Grupo $miercoles;

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

        $this->lunes = $this->grupo('Lunes tarde');
        $this->miercoles = $this->grupo('Miercoles tarde');
    }

    /**
     * LO QUE ESTE PASO VIENE A HACER POSIBLE: dos grupos, una matricula.
     *
     * Con la columna sola esto no se puede ni escribir. Es la prueba que da
     * sentido a la tabla; el comportamiento de las pantallas viene despues.
     */
    public function test_una_matricula_puede_estar_en_dos_grupos_de_la_misma_promotoria(): void
    {
        $matricula = $this->matricula('ana');

        $matricula->grupos()->attach([$this->lunes->id, $this->miercoles->id]);

        $suyos = $matricula->fresh()->grupos->pluck('id')->sort()->values()->all();

        $this->assertSame(
            collect([$this->lunes->id, $this->miercoles->id])->sort()->values()->all(),
            $suyos
        );
    }

    /**
     * La misma persona no entra dos veces en el mismo grupo.
     *
     * Sin el indice unico, pulsar «Asignar» dos veces la contaria dos veces
     * contra el cupo del grupo — y el cupo es lo unico que vigila el aforo del
     * salon.
     */
    public function test_no_se_puede_entrar_dos_veces_en_el_mismo_grupo(): void
    {
        $matricula = $this->matricula('ana');
        $matricula->grupos()->attach($this->lunes->id);

        $this->expectException(QueryException::class);

        DB::table('asignaciones_grupo')->insert([
            'matricula_id' => $matricula->id,
            'grupo_id' => $this->lunes->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Borrar la matricula se lleva sus asignaciones (CASCADE).
     *
     * No tienen vida propia. Sin el CASCADE quedarian filas apuntando a una
     * matricula que ya no existe, y esas cuentan contra el cupo de un grupo
     * sin que nadie pueda verlas en ninguna pantalla.
     */
    public function test_borrar_la_matricula_se_lleva_sus_asignaciones(): void
    {
        $matricula = $this->matricula('ana');
        $matricula->grupos()->attach([$this->lunes->id, $this->miercoles->id]);

        $matricula->delete();

        $this->assertSame(0, DB::table('asignaciones_grupo')->count());
    }

    /**
     * UN GRUPO CON GENTE DENTRO NO SE BORRA (RESTRICT).
     *
     * Es lo mismo que ya hacia la FK de la columna. Si esto cayera a CASCADE,
     * borrar un grupo dejaria a sus estudiantes repartidos en la nada sin que
     * nada fallara — que es exactamente el peligro que `Dependencias::MAPA`
     * documenta para los perfiles.
     */
    public function test_un_grupo_con_gente_dentro_no_se_borra(): void
    {
        $matricula = $this->matricula('ana');
        $matricula->grupos()->attach($this->lunes->id);

        $this->expectException(QueryException::class);

        $this->lunes->delete();
    }

    // --------------------------------------------------------------------
    // Los lectores (paso 3)
    // --------------------------------------------------------------------

    /**
     * EL CUPO DEL GRUPO YA CUENTA POR LA PUENTE, no por la columna.
     *
     * Es la prueba que demuestra que `Grupo::matriculas()` cambio de fuente: con
     * la columna sola esto no se puede ni montar, porque una matricula solo
     * cabe en un grupo. Quien va al lunes Y al miercoles ocupa una silla en cada
     * uno, que es lo que pasa fisicamente.
     */
    public function test_quien_esta_en_dos_grupos_ocupa_una_silla_en_cada_uno(): void
    {
        $matricula = $this->matricula('ana');
        $matricula->grupos()->attach([$this->lunes->id, $this->miercoles->id]);

        $this->assertSame(1, $this->lunes->ocupadosEn($this->periodo));
        $this->assertSame(1, $this->miercoles->ocupadosEn($this->periodo));
    }

    /**
     * Y su segunda silla LLENA el segundo grupo de verdad.
     *
     * Sin esto, lo de arriba podria estar contando bien y el cupo seguir
     * decidiendo por la columna — que es justo el fallo que se viene a evitar.
     */
    public function test_la_segunda_silla_llena_el_grupo(): void
    {
        $lleno = $this->grupo('Sabado');
        $lleno->cupo_maximo = 1;
        $lleno->save();

        $ana = $this->matricula('ana');
        $ana->grupos()->attach([$this->lunes->id, $lleno->id]);

        $this->assertSame(0, $lleno->cuposDisponibles($this->periodo), 'la segunda silla no lo llena.');
    }

    /**
     * A QUIEN VA A DOS HORARIOS SE LE PASA LISTA EN LOS DOS.
     *
     * Es el caso que se pidio, visto desde la pantalla de quien dicta. Con la
     * columna sola, la persona aparecia en la lista de UN grupo y en el otro no
     * — y el profesor del miercoles no tenia forma de marcarle asistencia.
     */
    public function test_a_quien_va_a_dos_horarios_se_le_pasa_lista_en_los_dos(): void
    {
        $matricula = $this->matricula('ana');
        $matricula->grupos()->attach([$this->lunes->id, $this->miercoles->id]);

        $delLunes = Clase::abrir($this->lunes, $this->periodo, null);
        $delMiercoles = Clase::abrir($this->miercoles, $this->periodo, null);

        $this->assertSame(
            [$matricula->id],
            $delLunes->matriculasAPasar()->pluck('id')->all(),
            'no sale en la lista del lunes.'
        );
        $this->assertSame(
            [$matricula->id],
            $delMiercoles->matriculasAPasar()->pluck('id')->all(),
            'no sale en la lista del miercoles.'
        );
    }

    /**
     * Y las clases de LOS DOS grupos le salen a ella para confirmar.
     *
     * La otra mitad de lo mismo. Sin esto, el profesor le marcaria asistencia
     * en el miercoles y a ella no le aparecería la clase — que es exactamente
     * el fallo que ya costo una vez, el 09/09, por otra causa.
     */
    public function test_las_clases_de_los_dos_grupos_le_salen_al_estudiante(): void
    {
        $matricula = $this->matricula('ana');
        $matricula->grupos()->attach([$this->lunes->id, $this->miercoles->id]);

        Clase::abrir($this->lunes, $this->periodo, null);
        Clase::abrir($this->miercoles, $this->periodo, null);

        $suyas = Clase::porConfirmar($matricula->estudiante, $this->periodo);

        $this->assertSame(
            [$this->lunes->id, $this->miercoles->id],
            collect($suyas)->pluck('clase.grupo_id')->sort()->values()->all(),
            'le falta la clase de uno de sus dos horarios.'
        );
    }

    /**
     * El numero de confirmaciones que pide una clase cuenta las dos sillas.
     *
     * `Clase::abrir()` congela cuanta gente habia en el grupo ese dia. Si
     * contara por la columna, el segundo horario abriria sus clases creyendo
     * que no hay nadie dentro.
     */
    public function test_la_clase_del_segundo_grupo_sabe_cuanta_gente_hay(): void
    {
        $matricula = $this->matricula('ana');
        $matricula->grupos()->attach([$this->lunes->id, $this->miercoles->id]);

        $clase = Clase::abrir($this->miercoles, $this->periodo, null);

        $this->assertSame(
            Clase::confirmacionesPara(1),
            $clase->confirmaciones_requeridas,
            'abrio la clase del segundo grupo como si estuviera vacio.'
        );
    }

    /**
     * SU HORARIO ENSEÑA LOS DOS DIAS.
     *
     * Con la columna veia media semana: el segundo grupo no salia en el horario
     * de nadie. La rejilla ya sabia pintar varios grupos —quien cursa tres
     * promotorias tiene tres—, asi que lo unico que cambio es de donde sale la
     * lista.
     */
    public function test_el_horario_enseña_los_dos_grupos(): void
    {
        $this->sesion($this->lunes, dia: 1);
        $this->sesion($this->miercoles, dia: 3);

        $matricula = $this->matricula('ana');
        $matricula->grupos()->attach([$this->lunes->id, $this->miercoles->id]);

        $horario = HorarioSemanal::de($matricula->estudiante, $this->periodo);

        $dias = collect($horario['franjas'])
            ->flatMap(fn ($f) => collect($f['celdas'])->filter()->keys())
            ->unique()->sort()->values()->all();

        $this->assertSame([1, 3], $dias, 'le falta uno de sus dos dias.');
    }

    /**
     * EN EL PANEL SALE EN LOS DOS GRUPOS, y eso NO es un duplicado.
     *
     * Son dos sillas, dos clases y dos listas de asistencia. Lo que no se
     * duplica es la matricula, y por eso el contador de la promotoria sigue
     * contando una sola persona — lo comprueba la afirmacion de abajo.
     */
    public function test_en_el_panel_sale_en_los_dos_grupos_y_cuenta_como_una(): void
    {
        $matricula = $this->matricula('ana');
        $matricula->grupos()->attach([$this->lunes->id, $this->miercoles->id]);

        $cuerpo = $this->cuerpoDelPanel();

        $enCadaGrupo = collect($cuerpo['grupos'])
            ->mapWithKeys(fn ($g) => [
                $g['grupo']->id => collect($g['estudiantes'])->pluck('matricula.id')->all(),
            ]);

        $this->assertSame([$matricula->id], $enCadaGrupo[$this->lunes->id], 'no sale en el lunes.');
        $this->assertSame([$matricula->id], $enCadaGrupo[$this->miercoles->id], 'no sale en el miercoles.');

        // Y NO cuenta dos veces contra la promotoria: sigue siendo una persona
        // con una matricula.
        $this->assertSame(1, $cuerpo['ocupados'], 'la promotoria la conto dos veces.');
    }

    /** Y quien esta en un grupo NO sale ademas en la lista de «sin grupo». */
    public function test_quien_tiene_grupo_no_sale_como_sin_grupo(): void
    {
        $conGrupo = $this->matricula('ana');
        $conGrupo->grupos()->attach([$this->lunes->id, $this->miercoles->id]);

        $suelta = $this->matricula('beto');

        $cuerpo = $this->cuerpoDelPanel();

        $this->assertSame(
            [$suelta->id],
            collect($cuerpo['sin_grupo'])->pluck('matricula.id')->all(),
            'la lista de sin grupo no dice quien esta de verdad sin grupo.'
        );
    }

    /**
     * CONFIRMAR UNA CLASE NO CONFIRMA LA OTRA.
     *
     * ES EL RIESGO QUE INTRODUCE TODO ESTO, y por eso esta prueba existe: hasta
     * ahora, dos clases pendientes de la misma persona colgaban siempre de dos
     * matriculas distintas —una por promotoria—. Desde que puede ir a dos
     * grupos de la MISMA promotoria, las dos cuelgan de la MISMA matricula. Si
     * la confirmacion se identificara por matricula, aprobar una aprobaria las
     * dos: el estudiante daria fe de una clase a la que no fue con un solo
     * toque, y eso destruye lo unico que sostiene el registro de asistencia.
     *
     * Lo que lo impide es que `confirmaciones_clase` es unica por
     * `(clase_id, matricula_id)` y que la ruta recibe UNA clase. Se prueba por
     * HTTP y no llamando al modelo: el camino que hay que vigilar es el boton.
     */
    public function test_confirmar_una_clase_no_confirma_la_otra(): void
    {
        [$matricula, $delLunes, $delMiercoles] = $this->dosClasesPendientes();

        $this->actingAs($matricula->estudiante->user)
            ->post(route('confirmar-clase', $delLunes))
            ->assertRedirect();

        $this->assertDatabaseHas('confirmaciones_clase', [
            'clase_id' => $delLunes->id,
            'matricula_id' => $matricula->id,
        ]);
        $this->assertDatabaseMissing('confirmaciones_clase', [
            'clase_id' => $delMiercoles->id,
            'matricula_id' => $matricula->id,
        ]);
        $this->assertSame(1, DB::table('confirmaciones_clase')->count(), 'confirmo mas de una.');
    }

    /** Y la que queda sigue saliendole como pendiente, no como verificada. */
    public function test_la_otra_clase_sigue_pendiente_en_su_pantalla(): void
    {
        [$matricula, $delLunes, $delMiercoles] = $this->dosClasesPendientes();

        $this->actingAs($matricula->estudiante->user)
            ->post(route('confirmar-clase', $delLunes));

        $suyas = collect(Clase::porConfirmar($matricula->estudiante->fresh(), $this->periodo))
            ->keyBy(fn ($f) => $f['clase']->id);

        $this->assertTrue($suyas[$delLunes->id]['confirmada_por_mi'], 'la que confirmo no consta.');
        $this->assertFalse(
            $suyas[$delMiercoles->id]['confirmada_por_mi'],
            'la otra salio confirmada sin que nadie la tocara.'
        );
        $this->assertSame(0, $suyas[$delMiercoles->id]['confirmaciones'], 'le contaron una confirmacion ajena.');
    }

    /** Deshacer una tampoco toca la otra. */
    public function test_deshacer_una_confirmacion_no_toca_la_otra(): void
    {
        [$matricula, $delLunes, $delMiercoles] = $this->dosClasesPendientes();

        $this->actingAs($matricula->estudiante->user)->post(route('confirmar-clase', $delLunes));
        $this->actingAs($matricula->estudiante->user)->post(route('confirmar-clase', $delMiercoles));
        $this->assertSame(2, DB::table('confirmaciones_clase')->count());

        $this->actingAs($matricula->estudiante->user)->post(route('retirar-confirmacion-clase', $delLunes));

        $this->assertDatabaseMissing('confirmaciones_clase', ['clase_id' => $delLunes->id]);
        $this->assertDatabaseHas('confirmaciones_clase', ['clase_id' => $delMiercoles->id]);
    }

    /**
     * Una persona, una matricula, dos grupos, y una clase reciente en cada uno
     * con su asistencia marcada. Las dos dentro del plazo de confirmacion.
     *
     * @return array{0: Matricula, 1: Clase, 2: Clase}
     */
    // -----------------------------------------------------------------------
    // Desde cuando cuenta un grupo AÑADIDO (05/10/2026)

    /** Una clase sin asistencia de nadie, en esa fecha. */
    private function claseEl(Grupo $grupo, string $fecha): Clase
    {
        return Clase::create([
            'grupo_id' => $grupo->id,
            'periodo_id' => $this->periodo->id,
            'fecha_hora' => Carbon::parse($fecha),
            'registrada_por_id' => $this->profesorDeViolin()->id,
            'confirmaciones_requeridas' => 1,
        ]);
    }

    /** @return list<int> */
    private function clasesDe(Matricula $matricula): array
    {
        return collect(Clase::porConfirmar($matricula->estudiante, $this->periodo))
            ->pluck('clase.id')->sort()->values()->all();
    }

    /**
     * Quien entra a un SEGUNDO grupo a mitad de periodo no ve las clases que
     * ese grupo dio antes de que lo metieran: no estuvo. Antes contaban desde
     * su fecha de matricula.
     */
    public function test_el_grupo_añadido_despues_no_trae_sus_clases_anteriores(): void
    {
        Carbon::setTestNow('2026-03-02 08:00:00');
        $matricula = $this->matricula('ana');
        $matricula->grupos()->attach($this->lunes->id);

        $lunesAntes = $this->claseEl($this->lunes, '2026-03-09 16:00:00');
        $miercolesAntes = $this->claseEl($this->miercoles, '2026-03-11 16:00:00');

        Carbon::setTestNow('2026-03-12 08:00:00');
        $matricula->grupos()->attach($this->miercoles->id);

        $miercolesDespues = $this->claseEl($this->miercoles, '2026-03-18 16:00:00');
        Carbon::setTestNow('2026-03-19 08:00:00');

        $this->assertSame([$lunesAntes->id, $miercolesDespues->id], $this->clasesDe($matricula));
        $this->assertNotContains($miercolesAntes->id, $this->clasesDe($matricula));

        Carbon::setTestNow();
    }

    /** Si consta que estuvo, sale igual: la asistencia manda sobre la fecha. */
    public function test_si_consta_su_asistencia_la_clase_anterior_si_sale(): void
    {
        Carbon::setTestNow('2026-03-02 08:00:00');
        $matricula = $this->matricula('ana');
        $matricula->grupos()->attach($this->lunes->id);

        $miercolesAntes = $this->claseEl($this->miercoles, '2026-03-11 16:00:00');
        Asistencia::create(['clase_id' => $miercolesAntes->id, 'matricula_id' => $matricula->id, 'estado' => Asistencia::ASISTIO]);

        Carbon::setTestNow('2026-03-12 08:00:00');
        $matricula->grupos()->attach($this->miercoles->id);
        Carbon::setTestNow('2026-03-13 08:00:00');

        $this->assertContains($miercolesAntes->id, $this->clasesDe($matricula));

        Carbon::setTestNow();
    }

    /**
     * Los PRIMEROS grupos cuentan desde la matricula aunque su asignacion
     * tenga fecha posterior: las que existian el 10/09/2026 se volcaron con la
     * fecha de ese dia, y cortar por ella le esconderia a todos lo de antes.
     */
    public function test_los_primeros_grupos_cuentan_desde_la_matricula(): void
    {
        Carbon::setTestNow('2026-03-02 08:00:00');
        $matricula = $this->matricula('ana');
        $lunes = $this->claseEl($this->lunes, '2026-03-09 16:00:00');

        // La asignacion lleva una fecha posterior a la clase, como las volcadas.
        Carbon::setTestNow('2026-03-20 08:00:00');
        $matricula->grupos()->attach($this->lunes->id);

        $this->assertSame([$lunes->id], $this->clasesDe($matricula));

        Carbon::setTestNow();
    }

    private function dosClasesPendientes(): array
    {
        $matricula = $this->matricula('ana');
        $matricula->grupos()->attach([$this->lunes->id, $this->miercoles->id]);

        $delLunes = $this->claseCon($matricula, $this->lunes, hace: 2);
        $delMiercoles = $this->claseCon($matricula, $this->miercoles, hace: 1);

        return [$matricula, $delLunes, $delMiercoles];
    }

    private function claseCon(Matricula $matricula, Grupo $grupo, int $hace): Clase
    {
        $clase = Clase::create([
            'grupo_id' => $grupo->id,
            'periodo_id' => $this->periodo->id,
            'fecha_hora' => Carbon::now()->subHours($hace),
            'registrada_por_id' => $this->profesorDeViolin()->id,
            'confirmaciones_requeridas' => 1,
        ]);

        Asistencia::create([
            'clase_id' => $clase->id,
            'matricula_id' => $matricula->id,
            'estado' => Asistencia::ASISTIO,
        ]);

        return $clase;
    }

    /**
     * CADA HORARIO TIENE SUS PROPIOS COMPAÑEROS.
     *
     * «Mis compañeros» empareja por GRUPO y no por promotoria desde el 27/08, y
     * su propia pantalla lo explica: quien va los martes no se cruza con quien
     * va los jueves. Con una matricula en dos grupos eso deja de ser una frase y
     * pasa a ser el caso: la misma persona tiene DOS corros distintos, y por eso
     * la clave de `Companeros::porMatricula()` es (matricula, grupo) y no la
     * matricula.
     */
    public function test_cada_horario_tiene_sus_propios_companeros(): void
    {
        $ana = $this->matricula('ana');
        $ana->grupos()->attach([$this->lunes->id, $this->miercoles->id]);

        $soloLunes = $this->matricula('beto');
        $soloLunes->grupos()->attach($this->lunes->id);

        $soloMiercoles = $this->matricula('caro');
        $soloMiercoles->grupos()->attach($this->miercoles->id);

        $html = $this->actingAs($ana->estudiante->user)
            ->get(route('mis-companeros'))->assertOk()->getContent();

        // Dos secciones, una por horario, y cada una con SU gente.
        $secciones = $this->actingAs($ana->estudiante->user)
            ->get(route('mis-companeros'))->viewData('clases');

        $porGrupo = collect($secciones)->mapWithKeys(fn ($s) => [
            $s['grupo']->id => collect($s['companeros'])->pluck('id')->all(),
        ]);

        $this->assertCount(2, $secciones, 'no salen sus dos horarios.');
        $this->assertSame([$soloLunes->estudiante_id], $porGrupo[$this->lunes->id]);
        $this->assertSame([$soloMiercoles->estudiante_id], $porGrupo[$this->miercoles->id]);
        $this->assertStringContainsString('Lunes tarde', $html);
    }

    /**
     * Y quien comparte los DOS horarios sale en los dos corros.
     *
     * Es una persona, no dos, pero aparece en cada uno de sus dos sitios — que
     * es lo que la pantalla ensena. El contador de «Compañeros» de Mi perfil
     * cuenta lo otro: personas distintas, y ahi es UNA.
     */
    public function test_quien_comparte_los_dos_horarios_sale_en_los_dos(): void
    {
        $ana = $this->matricula('ana');
        $ana->grupos()->attach([$this->lunes->id, $this->miercoles->id]);

        $tambienLosDos = $this->matricula('beto');
        $tambienLosDos->grupos()->attach([$this->lunes->id, $this->miercoles->id]);

        $secciones = $this->actingAs($ana->estudiante->user)
            ->get(route('mis-companeros'))->assertOk()->viewData('clases');

        foreach ($secciones as $seccion) {
            $this->assertSame(
                [$tambienLosDos->estudiante_id],
                collect($seccion['companeros'])->pluck('id')->all(),
                'falta en el corro de '.$seccion['grupo']->nombre
            );
        }

        $this->assertSame(
            1,
            Companeros::cuantos($ana->estudiante, collect([$ana->fresh()->load('grupos')])),
            'lo conto dos veces: son dos horarios de la misma persona.'
        );
    }

    /**
     * UN GRUPO CON GENTE SOLO EN LA PUENTE TAMPOCO SE BORRA.
     *
     * `Dependencias::MAPA` cuenta por la relacion `matriculas` del grupo, asi
     * que al cambiarle la fuente cambia tambien lo que ese mapa ve. Sin esta
     * prueba, un grupo con su segundo horario lleno se pintaria como borrable.
     */
    public function test_dependencias_ve_a_quien_solo_esta_en_la_puente(): void
    {
        $matricula = $this->matricula('ana');
        $matricula->grupos()->attach($this->miercoles->id);

        $this->assertTrue(
            Dependencias::estaBloqueado($this->miercoles),
            'el grupo se pinta como borrable con gente dentro.'
        );
    }

    /**
     * El cuerpo del Panel para Violin, tal como lo ve su profesor.
     *
     * Se pide el CUERPO y no la portada: la portada solo trae el contador de
     * pendientes, y el reparto por grupos —que es lo que aqui se mira— lo carga
     * `panel.js` despues, por esta ruta.
     *
     * @return array<string, mixed>
     */
    private function cuerpoDelPanel(): array
    {
        return $this->actingAs($this->profesorDeViolin()->user)
            ->get(route('panel-promotoria-cuerpo', $this->violin))
            ->assertOk()
            ->original
            ->getData()['item'];
    }

    private function profesorDeViolin(): Perfil
    {
        if ($this->violin->profesor_id === null) {
            $this->violin->profesor_id = $this->perfilDe('profe', 'profesor')->id;
            $this->violin->save();
        }

        return Perfil::findOrFail($this->violin->profesor_id);
    }

    private function sesion(Grupo $grupo, int $dia): void
    {
        SesionGrupo::create([
            'grupo_id' => $grupo->id,
            'dia' => $dia,
            'hora_inicio' => '16:00',
            'hora_fin' => '18:00',
        ]);
    }

    private function grupo(string $nombre): Grupo
    {
        return Grupo::create([
            'promotoria_id' => $this->violin->id,
            'nombre' => $nombre,
            'nivel' => 'basico',
            'salon' => 'A1',
            'cupo_maximo' => 10,
        ]);
    }

    private function perfilDe(string $nombre, string $rol): Perfil
    {
        $user = User::create(['username' => $nombre, 'password' => 'demo1234', 'activo' => true]);

        return Perfil::create([
            'user_id' => $user->id,
            'rol' => $rol,
            'nombre_completo' => ucfirst($nombre),
            'fecha_nacimiento' => Carbon::today()->subYears(20)->toDateString(),
            'telefono' => '3000000000',
        ]);
    }

    private function matricula(string $nombre): Matricula
    {
        $perfil = $this->perfilDe($nombre, 'estudiante');

        return Matricula::create([
            'estudiante_id' => $perfil->id,
            'promotoria_id' => $this->violin->id,
            'periodo_id' => $this->periodo->id,
            'fecha' => Carbon::now(),
            'estado' => Matricula::ACTIVA,
        ]);
    }
}
