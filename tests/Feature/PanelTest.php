<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Asistencia;
use App\Models\Clase;
use App\Models\ConfiguracionInstitucion;
use App\Models\CupoPromotoria;
use App\Models\DatosEstudiante;
use App\Models\Grupo;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Models\User;
use App\Support\Permisos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * El Panel: la contraparte de lo que ve el estudiante.
 *
 * Lo que se prueba aqui, en orden de importancia: que confirmar y rechazar
 * cierren de verdad el ciclo de una solicitud, que el limite de promotorias se
 * reaplique en la confirmacion (y no solo al matricularse), y que un profesor no
 * pueda tocar promotorias que no dicta.
 */
class PanelTest extends TestCase
{
    use RefreshDatabase;

    private Periodo $periodo;

    private Promotoria $violin;

    private Promotoria $danza;

    private Perfil $profesor;

    private Perfil $director;

    private Perfil $estudiante;

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

        $this->profesor = $this->crearPerfil('profe', 'profesor');
        $this->director = $this->crearPerfil('dire', 'director');
        // Dirige todos los departamentos. Desde el 12/09/2026 un director
        // solo ve lo suyo, y estas pruebas no van del recorte: sin esto
        // fallarian por no tener ninguno asignado, que es otro fallo.
        $this->dirige($this->director);
        $this->estudiante = $this->crearEstudiante('ana');

        $this->violin = Promotoria::create([
            'nombre' => 'Violin',
            'area_id' => $area->id,
            'profesor_id' => $this->profesor->id,
        ]);

        // Sin profesor asignado: es la que el profesor NO puede tocar.
        $this->danza = Promotoria::create(['nombre' => 'Danza', 'area_id' => $area->id]);
    }

    private function crearPerfil(string $username, string $rol): Perfil
    {
        $user = User::create(['username' => $username, 'password' => 'x', 'activo' => true]);

        return Perfil::create([
            'user_id' => $user->id,
            'rol' => $rol,
            'nombre_completo' => ucfirst($username),
            'fecha_nacimiento' => Carbon::today()->subYears(35)->toDateString(),
            'telefono' => '3000000000',
        ]);
    }

    private function crearEstudiante(string $username): Perfil
    {
        $perfil = $this->crearPerfil($username, 'estudiante');

        DatosEstudiante::create([
            'perfil_id' => $perfil->id,
            'documento_identidad' => '1'.$perfil->id,
        ]);

        return $perfil;
    }

    private function matricular(Promotoria $promotoria, ?Perfil $estudiante = null, string $estado = Matricula::PENDIENTE): Matricula
    {
        $matricula = new Matricula([
            'estudiante_id' => ($estudiante ?? $this->estudiante)->id,
            'promotoria_id' => $promotoria->id,
            'periodo_id' => $this->periodo->id,
            'estado' => $estado,
        ]);
        $matricula->save();

        return $matricula;
    }

    // -----------------------------------------------------------------------
    // El panel se acota al periodo que se esta mirando
    // -----------------------------------------------------------------------

    /**
     * Quien curso la promotoria en un periodo anterior NO sale en el panel.
     *
     * DIVERGENCIA DELIBERADA del original, decidida el 21/08/2026: el Django de
     * origen no filtra por periodo (`views.py:861`), asi que ensena a todo el
     * que haya pasado por la promotoria desde que existe. Y como nada retira las
     * matriculas al cerrar un periodo —se quedan en `activa`—, el panel acababa
     * afirmando que gente de hace tres semestres seguia en el salon.
     *
     * Esta prueba existe para que la divergencia no se deshaga por descuido al
     * portar algo nuevo desde el original.
     */
    public function test_el_panel_no_ensena_a_los_de_periodos_anteriores(): void
    {
        $viejo = $this->crearPeriodoViejo();
        $antiguo = $this->crearEstudiante('samu');

        // Matriculado en el periodo pasado, y nunca retirado: es el caso real.
        $this->matricularEn($viejo, $this->violin, $antiguo);

        $deAhora = $this->matricular($this->violin, estado: Matricula::ACTIVA);

        $this->actingAs($this->profesor->user)
            ->get(route('panel-promotoria-cuerpo', $this->violin))
            ->assertOk()
            ->assertSee($this->estudiante->nombre_completo)
            ->assertDontSee($antiguo->nombre_completo);

        $this->assertNotNull($deAhora->fresh());
    }

    /**
     * Acotar por periodo NO se lleva por delante la marca de renovacion.
     *
     * Es lo que mas facil se rompe con este cambio: quien vuelve tiene que
     * seguir apareciendo como que vuelve. `renovacionesDe()` hace su propia
     * consulta a todos los periodos y del panel solo saca a QUIEN preguntar por,
     * asi que acotar la lista no le quita informacion.
     */
    public function test_quien_vuelve_sigue_marcado_como_renovacion(): void
    {
        $viejo = $this->crearPeriodoViejo();

        $this->matricularEn($viejo, $this->violin, $this->estudiante);

        // Pendiente y no activa a proposito: la marca de renovacion solo se
        // pinta en la tabla de solicitudes (`item.blade.php:120`), que es donde
        // sirve — es lo que mira quien decide si confirmar y en que nivel ubicar.
        $this->matricular($this->violin, estado: Matricula::PENDIENTE);

        $this->actingAs($this->profesor->user)
            ->get(route('panel-promotoria-cuerpo', $this->violin))
            ->assertOk()
            ->assertSee($this->estudiante->nombre_completo)
            // La etiqueta que distingue a quien vuelve de quien empieza.
            ->assertSee('Renovación', false);
    }

    /**
     * Entre dos semestres se ensena el mas reciente, no una pantalla vacia.
     *
     * Misma regla que ya siguen Estadisticas y Cupos. Sin esto, acotar por
     * periodo dejaria el panel en blanco justo en el hueco entre semestres, que
     * es cuando se reparte a la gente del que acaba de terminar.
     */
    public function test_sin_periodo_en_curso_el_panel_ensena_el_mas_reciente(): void
    {
        $this->matricular($this->violin, estado: Matricula::ACTIVA);

        $this->periodo->activo = false;
        $this->periodo->save();

        $this->actingAs($this->profesor->user)
            ->get(route('panel-promotoria-cuerpo', $this->violin))
            ->assertOk()
            ->assertSee($this->estudiante->nombre_completo);
    }

    /** Matricula ACTIVA en un periodo que no es el que hay en curso. */
    private function matricularEn(Periodo $periodo, Promotoria $promotoria, Perfil $estudiante): Matricula
    {
        $matricula = new Matricula([
            'estudiante_id' => $estudiante->id,
            'promotoria_id' => $promotoria->id,
            'periodo_id' => $periodo->id,
            'estado' => Matricula::ACTIVA,
        ]);
        $matricula->save();

        return $matricula;
    }

    /** Un periodo anterior al que hay en curso, para las pruebas de arriba. */
    private function crearPeriodoViejo(): Periodo
    {
        return Periodo::create([
            'nombre' => '2025-2',
            'fecha_inicio' => '2025-07-15',
            'fecha_fin' => '2025-12-15',
            'activo' => false,
            'matriculas_abiertas' => false,
        ]);
    }

    // -----------------------------------------------------------------------
    // Puerta de entrada
    // -----------------------------------------------------------------------

    public function test_un_estudiante_no_entra_al_panel(): void
    {
        $this->actingAs($this->estudiante->user)
            ->get(route('panel'))
            ->assertRedirect(route('post-login'));
    }

    public function test_el_profesor_solo_ve_las_promotorias_que_dicta(): void
    {
        $this->matricular($this->violin);
        $this->matricular($this->danza, $this->crearEstudiante('samu'));

        $respuesta = $this->actingAs($this->profesor->user)->get(route('panel'));

        $respuesta->assertOk();
        $respuesta->assertSee('Violin');
        $respuesta->assertDontSee('Danza');
    }

    public function test_el_director_ve_todas(): void
    {
        $respuesta = $this->actingAs($this->director->user)->get(route('panel'));

        $respuesta->assertOk();
        $respuesta->assertSee('Violin');
        $respuesta->assertSee('Danza');
    }

    /**
     * El indice manda solo los titulos: el cuerpo de cada promotoria llega al
     * desplegarla. Antes iba todo dentro y un director descargaba el catalogo
     * entero para ver una lista plegada.
     */
    public function test_el_indice_no_trae_el_cuerpo_de_las_promotorias(): void
    {
        $this->matricular($this->violin);

        $respuesta = $this->actingAs($this->director->user)->get(route('panel'));

        $respuesta->assertOk();
        $respuesta->assertSee('Violin');
        // Ni los estudiantes ni los controles: eso vive en el cuerpo.
        $respuesta->assertDontSee('Pendientes de confirmación', false);
        $respuesta->assertDontSee('Ana');
    }

    public function test_el_cuerpo_trae_lo_que_el_indice_ya_no_manda(): void
    {
        $this->matricular($this->violin);

        $this->actingAs($this->director->user)
            ->get(route('panel-promotoria-cuerpo', $this->violin))
            ->assertOk()
            ->assertSee('Pendientes de confirmación', false)
            ->assertSee('Ana')
            ->assertSee('Confirmar');
    }

    /**
     * Esconder una promotoria de la lista no cierra su URL: la puerta del cuerpo
     * tiene que ser la misma que la del indice.
     */
    public function test_un_profesor_no_lee_el_cuerpo_de_una_promotoria_ajena(): void
    {
        $this->matricular($this->danza, $this->crearEstudiante('samu'));

        $this->actingAs($this->profesor->user)
            ->get(route('panel-promotoria-cuerpo', $this->danza))
            ->assertNotFound();
    }

    public function test_un_estudiante_no_lee_el_cuerpo(): void
    {
        $this->actingAs($this->estudiante->user)
            ->get(route('panel-promotoria-cuerpo', $this->violin))
            ->assertRedirect(route('post-login'));
    }

    // -----------------------------------------------------------------------
    // Confirmar y rechazar
    // -----------------------------------------------------------------------

    public function test_confirmar_activa_la_matricula(): void
    {
        $matricula = $this->matricular($this->violin);

        $this->actingAs($this->profesor->user)
            ->post(route('panel-confirmar-matricula', $matricula))
            ->assertRedirect(route('panel'))
            ->assertSessionHas('success');

        $this->assertSame(Matricula::ACTIVA, $matricula->fresh()->estado);
    }

    public function test_rechazar_la_deja_retirada(): void
    {
        $matricula = $this->matricular($this->violin);

        $this->actingAs($this->profesor->user)
            ->post(route('panel-rechazar-matricula', $matricula))
            ->assertRedirect(route('panel'));

        $this->assertSame(Matricula::RETIRADA, $matricula->fresh()->estado);
    }

    /**
     * Rechazar libera la ranura: es lo que le permite al estudiante volver a
     * pedir otra promotoria despues de que le digan que no.
     */
    public function test_rechazar_libera_el_cupo_del_estudiante(): void
    {
        $matricula = $this->matricular($this->violin);

        $this->actingAs($this->profesor->user)
            ->post(route('panel-rechazar-matricula', $matricula));

        $this->assertSame(
            0,
            Matricula::promotoriasOcupadas($this->estudiante->id, $this->periodo->id)
        );
    }

    public function test_una_matricula_ya_activa_no_se_vuelve_a_confirmar(): void
    {
        $matricula = $this->matricular($this->violin, estado: Matricula::ACTIVA);

        $this->actingAs($this->profesor->user)
            ->post(route('panel-confirmar-matricula', $matricula))
            ->assertSessionMissing('success');

        $this->assertSame(Matricula::ACTIVA, $matricula->fresh()->estado);
    }

    /**
     * Entre la solicitud y la confirmacion pueden pasar cosas: que el estudiante
     * llene su cupo por otro lado, o que bajen el limite. Confirmar sin mirar lo
     * dejaria por encima del tope.
     */
    public function test_no_se_confirma_si_el_estudiante_ya_esta_en_el_tope(): void
    {
        // Las dos matriculas nacen con el limite en 2, que es cuando el
        // estudiante pudo pedirlas. Bajarlo DESPUES es justo el caso: la
        // solicitud ya existe y confirmarla lo dejaria por encima del tope.
        $this->matricular($this->danza, estado: Matricula::ACTIVA);
        $pendiente = $this->matricular($this->violin);

        $configuracion = ConfiguracionInstitucion::actual();
        $configuracion->limite_promotorias_por_periodo = 1;
        $configuracion->save();

        $this->actingAs($this->director->user)
            ->post(route('panel-confirmar-matricula', $pendiente))
            ->assertSessionHas('error');

        $this->assertSame(Matricula::PENDIENTE, $pendiente->fresh()->estado);
    }

    public function test_un_profesor_no_confirma_matriculas_de_otra_promotoria(): void
    {
        $matricula = $this->matricular($this->danza);

        $this->actingAs($this->profesor->user)
            ->post(route('panel-confirmar-matricula', $matricula))
            ->assertSessionHas('error');

        $this->assertSame(Matricula::PENDIENTE, $matricula->fresh()->estado);
    }

    // -----------------------------------------------------------------------
    // Cupo de la promotoria
    // -----------------------------------------------------------------------

    public function test_fijar_el_cupo_crea_la_fila(): void
    {
        $this->actingAs($this->profesor->user)
            ->post(route('panel-cupo-promotoria', $this->violin), ['cupo_maximo' => '12'])
            ->assertSessionHas('success');

        $this->assertSame(12, $this->violin->cupoEn($this->periodo));
    }

    public function test_dejar_el_cupo_en_blanco_quita_el_tope(): void
    {
        CupoPromotoria::create([
            'promotoria_id' => $this->violin->id,
            'periodo_id' => $this->periodo->id,
            'cupo_maximo' => 3,
        ]);

        $this->actingAs($this->profesor->user)
            ->post(route('panel-cupo-promotoria', $this->violin), ['cupo_maximo' => '']);

        $this->assertNull($this->violin->fresh()->cupoEn($this->periodo));
    }

    /**
     * Bajar el cupo por debajo de lo ocupado es legitimo y no retira a nadie:
     * solo cierra la puerta. Se avisa porque la cifra queda en "2 / 1" y sin
     * explicacion parece un error del sistema.
     */
    public function test_bajar_el_cupo_por_debajo_de_lo_ocupado_avisa_pero_no_retira(): void
    {
        $this->matricular($this->violin, estado: Matricula::ACTIVA);
        $this->matricular($this->violin, $this->crearEstudiante('samu'), Matricula::ACTIVA);

        $this->actingAs($this->profesor->user)
            ->post(route('panel-cupo-promotoria', $this->violin), ['cupo_maximo' => '1'])
            ->assertSessionHas('error');

        $this->assertSame(2, $this->violin->ocupadosEn($this->periodo));
    }

    public function test_el_cupo_no_admite_negativos(): void
    {
        $this->actingAs($this->profesor->user)
            ->post(route('panel-cupo-promotoria', $this->violin), ['cupo_maximo' => '-3'])
            ->assertSessionHas('error');

        $this->assertNull($this->violin->cupoEn($this->periodo));
    }

    // -----------------------------------------------------------------------
    // Grupos
    // -----------------------------------------------------------------------

    public function test_se_crea_un_grupo(): void
    {
        $this->actingAs($this->profesor->user)
            ->post(route('panel-grupo-nuevo', $this->violin), [
                'nombre' => 'Martes tarde',
                'nivel' => 'basico',
                'sesiones' => [2 => ['activo' => 1, 'desde' => '16:00', 'hasta' => '18:00']],
                'salon' => 'A1',
                'cupo_maximo' => 10,
            ])
            ->assertSessionHas('success');

        $this->assertSame(1, $this->violin->grupos()->count());
    }

    /**
     * Varios grupos del mismo nivel en una promotoria: el caso corriente cuando
     * atiende a mucha gente. Ocho Basicos de Guitarra a horas distintas no son
     * un error, son la semana.
     */
    public function test_se_crean_varios_grupos_del_mismo_nivel(): void
    {
        Grupo::create([
            'promotoria_id' => $this->violin->id,
            'nombre' => 'Lunes tarde',
            'nivel' => 'basico',
            'salon' => 'A1',
            'cupo_maximo' => 5,
        ]);

        $this->actingAs($this->profesor->user)
            ->post(route('panel-grupo-nuevo', $this->violin), [
                'nombre' => 'Martes tarde',
                'nivel' => 'basico',
                'sesiones' => [2 => ['activo' => 1, 'desde' => '16:00', 'hasta' => '18:00']],
                'salon' => 'A2',
                'cupo_maximo' => 5,
            ])
            ->assertSessionHas('success');

        $this->assertSame(2, $this->violin->grupos()->count());
    }

    /** Lo que NO puede repetirse es el nombre: es lo que distingue uno de otro. */
    public function test_no_hay_dos_grupos_con_el_mismo_nombre(): void
    {
        Grupo::create([
            'promotoria_id' => $this->violin->id,
            'nombre' => 'Lunes tarde',
            'nivel' => 'basico',
            'salon' => 'A1',
            'cupo_maximo' => 5,
        ]);

        $this->actingAs($this->profesor->user)
            ->post(route('panel-grupo-nuevo', $this->violin), [
                'nombre' => 'Lunes tarde',
                'nivel' => 'intermedio',
                'sesiones' => [2 => ['activo' => 1, 'desde' => '16:00', 'hasta' => '18:00']],
                'salon' => 'A2',
                'cupo_maximo' => 5,
            ])
            ->assertSessionHasErrors('nombre');

        $this->assertSame(1, $this->violin->grupos()->count());
    }

    /** Sin nombre no hay grupo: es lo unico que lo identifica. */
    public function test_un_grupo_sin_nombre_se_rechaza(): void
    {
        $this->actingAs($this->profesor->user)
            ->post(route('panel-grupo-nuevo', $this->violin), [
                'nivel' => 'basico',
                'sesiones' => [2 => ['activo' => 1, 'desde' => '16:00', 'hasta' => '18:00']],
                'salon' => 'A2',
                'cupo_maximo' => 5,
            ])
            ->assertSessionHasErrors('nombre');

        $this->assertSame(0, $this->violin->grupos()->count());
    }

    /**
     * El mismo nombre en OTRA promotoria si vale: «Martes tarde» de Guitarra y
     * «Martes tarde» de Danza son dos grupos distintos y nadie los confunde,
     * porque el nombre solo tiene que distinguir dentro de su promotoria.
     */
    public function test_el_mismo_nombre_vale_en_otra_promotoria(): void
    {
        Grupo::create([
            'promotoria_id' => $this->violin->id,
            'nombre' => 'Martes tarde',
            'nivel' => 'basico',
            'salon' => 'A1',
            'cupo_maximo' => 5,
        ]);

        $otra = Grupo::create([
            'promotoria_id' => $this->danza->id,
            'nombre' => 'Martes tarde',
            'nivel' => 'basico',
            'salon' => 'B1',
            'cupo_maximo' => 5,
        ]);

        $this->assertTrue($otra->exists);
    }

    /**
     * La matricula apunta al grupo con RESTRICT: borrar un horario no puede
     * llevarse por delante la inscripcion de nadie.
     */
    public function test_no_se_elimina_un_grupo_con_estudiantes(): void
    {
        $grupo = Grupo::create([
            'promotoria_id' => $this->violin->id,
            'nombre' => 'Lunes',
            'nivel' => 'basico',
            'salon' => 'A1',
            'cupo_maximo' => 5,
        ]);

        $matricula = $this->matricular($this->violin, estado: Matricula::ACTIVA);
        $matricula->repartirEn([$grupo->id]);

        $this->actingAs($this->profesor->user)
            ->post(route('panel-grupo-eliminar', $grupo))
            ->assertSessionHas('error');

        $this->assertNotNull($grupo->fresh());
    }

    // -----------------------------------------------------------------------
    // Reparto en grupos
    // -----------------------------------------------------------------------

    private function crearGrupo(int $cupo = 5, string $nivel = 'basico'): Grupo
    {
        return Grupo::create([
            'promotoria_id' => $this->violin->id,
            'nombre' => 'Grupo '.(Grupo::count() + 1),
            'nivel' => $nivel,
            'salon' => 'A1',
            'cupo_maximo' => $cupo,
        ]);
    }

    /**
     * La lista de estudiantes de un grupo va PLEGADA, y su encabezado no.
     *
     * Lo pidio la institucion: con tres grupos de veinte, una promotoria abierta
     * eran sesenta filas antes de llegar a «Sin grupo asignado», que es donde de
     * verdad se trabaja al empezar un periodo.
     *
     * Se afirman las TRES cosas que definen el arreglo, y no solo que exista un
     * <details>:
     *
     * 1. Que la tabla este DENTRO del <details> --si quedara fuera el desplegable
     *    saldria vacio y la pagina igual de llena, que es el fallo silencioso de
     *    este cambio: se ve un triangulo y todo lo demas parece correcto--.
     * 2. Que NO traiga `open`, porque plegado es el estado de partida y es todo
     *    el punto.
     * 3. Que el encabezado con sus acciones siga a la vista: «Iniciar clase» es
     *    lo que se pulsa a diario y esconderlo cambiaria un problema de sitio por
     *    uno de pasos.
     *
     * El `id` se comprueba aparte porque no es decorativo: es lo que permite que
     * el grupo siga abierto tras un repintado (ver `public/js/panel.js`).
     */
    public function test_la_lista_de_un_grupo_va_plegada(): void
    {
        $grupo = $this->crearGrupo();
        $matricula = $this->matricular($this->violin, estado: Matricula::ACTIVA);
        $matricula->repartirEn([$grupo->id]);

        $html = $this->actingAs($this->profesor->user)
            ->get(route('panel-promotoria-cuerpo', $this->violin))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            '<details class="grupo-lista" id="grupo-'.$grupo->id.'" data-grupo>',
            (string) $html
        );

        // Plegado de partida: un `open` aqui vaciaria el cambio de contenido.
        $this->assertStringNotContainsString('data-grupo open', (string) $html);

        // La tabla, DENTRO del desplegable.
        $desde = strpos((string) $html, 'id="grupo-'.$grupo->id.'"');
        $hasta = strpos((string) $html, '</details>', $desde);
        $dentro = substr((string) $html, $desde, $hasta - $desde);

        $this->assertStringContainsString('<table class="tabla-personas">', $dentro);
        $this->assertStringContainsString($this->estudiante->nombre_completo, $dentro);

        // Y el encabezado del grupo FUERA, con sus acciones.
        $antes = substr((string) $html, 0, $desde);
        $this->assertStringContainsString('grupo-cabecera', $antes);
        $this->assertStringContainsString('Iniciar clase', $antes);
    }

    /**
     * Cada barra de acciones en bloque va con SU tabla dentro del mismo
     * envoltorio.
     *
     * La barra es `position: sticky`, y sticky se suelta al acabar su bloque
     * contenedor. Sueltas como hermanas, el bloque era la seccion entera y la
     * barra se quedaba flotando sobre lo que viniera despues: en un ancho de
     * telefono tapaba dos encabezados de grupo con sus botones, medido el
     * 28/08/2026.
     *
     * Esto se prueba porque es lo que un cambio futuro rompe SIN QUE FALLE NADA.
     * Sacar la tabla del `<div>` no da error, no cambia ninguna cifra y no se ve
     * en pantalla ancha; solo vuelve a tapar cosas en un celular, que es desde
     * donde entra la mayoria. El CSS por si solo no puede garantizarlo: la
     * acotacion es del marcado.
     */
    /**
     * Toda celda de una tabla de personas dice QUE es.
     *
     * En un telefono esas tablas dejan de ser tablas: cada fila se pinta como
     * una ficha y la cabecera se retira, asi que lo unico que identifica a un
     * valor es su `data-label`. Una celda sin el sale como un numero desnudo
     * —«11» sin decir que es la edad— y las que tienen papel propio (la foto, el
     * nombre, la casilla, los botones) se marcan con `data-celda` para que la
     * ficha sepa donde colocarlas.
     *
     * Se prueba porque es EXACTAMENTE lo que se rompe al anadir una columna: en
     * pantalla ancha la cabecera de la tabla sigue explicandola y todo parece
     * correcto; el hueco solo aparece en el celular, que es desde donde entra la
     * mayoria. Nada falla, nada se ve raro en el sitio donde se programa.
     *
     * Medido antes de este cambio, a 375 px: confirmar una matricula pedia
     * arrastrar 380 px de lado para alcanzar el boton, y repartir a alguien en
     * un grupo, 741 px.
     */
    public function test_las_celdas_de_las_fichas_dicen_que_son(): void
    {
        $grupo = $this->crearGrupo();

        // Las tres tablas a la vez: pendientes, la de un grupo y la de sin
        // repartir. Con una sola la prueba dejaria las otras dos sin mirar.
        $this->matricular($this->violin, $this->crearEstudiante('uno'), Matricula::PENDIENTE);
        $this->matricular($this->violin, $this->crearEstudiante('dos'), Matricula::PENDIENTE);
        $this->matricular($this->violin, $this->crearEstudiante('tres'), Matricula::ACTIVA);

        $conGrupo = $this->matricular($this->violin, $this->crearEstudiante('cuatro'), Matricula::ACTIVA);
        $conGrupo->repartirEn([$grupo->id]);

        $html = (string) $this->actingAs($this->director->user)
            ->get(route('panel-promotoria-cuerpo', $this->violin))
            ->assertOk()
            ->getContent();

        $doc = new \DOMDocument;
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div>'.$html.'</div>');
        libxml_clear_errors();

        $xpath = new \DOMXPath($doc);
        $tablas = $xpath->query('//table[contains(@class, "tabla-personas")]');

        // Que existan las tres es parte de lo que se afirma: sin esto la prueba
        // pasaria en vacio el dia que alguien le quite la clase a una tabla.
        $this->assertSame(3, $tablas->length, 'Se esperaban las tres tablas de personas del Panel.');

        foreach ($tablas as $tabla) {
            foreach ($xpath->query('.//tbody//td', $tabla) as $celda) {
                $this->assertInstanceOf(\DOMElement::class, $celda);

                $etiqueta = (string) $celda->getAttribute('data-label');
                $papel = (string) $celda->getAttribute('data-celda');

                $this->assertTrue(
                    $etiqueta !== '' || $papel !== '',
                    'Una celda de una tabla de personas no lleva ni `data-label` ni `data-celda`, '
                    .'asi que en un telefono saldria sin decir que es. Contenido: "'.
                    trim(preg_replace('/\s+/', ' ', (string) $celda->textContent)).'"'
                );
            }
        }
    }

    public function test_cada_barra_de_lote_va_con_su_tabla_en_un_envoltorio(): void
    {
        // Dos pendientes para que salga la barra de confirmar, y un grupo con
        // dos sin repartir para que salga la de asignar. Con uno solo de cada
        // cosa el marcado no pinta ninguna barra y la prueba no miraria nada.
        $this->crearGrupo();
        $this->matricular($this->violin, $this->crearEstudiante('uno'), Matricula::PENDIENTE);
        $this->matricular($this->violin, $this->crearEstudiante('dos'), Matricula::PENDIENTE);
        $this->matricular($this->violin, $this->crearEstudiante('tres'), Matricula::ACTIVA);
        $this->matricular($this->violin, $this->crearEstudiante('cuatro'), Matricula::ACTIVA);

        $html = (string) $this->actingAs($this->director->user)
            ->get(route('panel-promotoria-cuerpo', $this->violin))
            ->assertOk()
            ->getContent();

        $doc = new \DOMDocument;
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div>'.$html.'</div>');
        libxml_clear_errors();

        $xpath = new \DOMXPath($doc);
        $barras = $xpath->query('//*[contains(@class, "lote-barra")]');

        // Que haya barras es parte de la afirmacion: sin ellas el resto pasaria
        // en vacio y la prueba diria que todo esta bien sin haber mirado nada.
        $this->assertSame(2, $barras->length, 'Se esperaban las dos barras de lote.');

        foreach ($barras as $barra) {
            $envoltorio = $barra->parentNode;

            $this->assertInstanceOf(\DOMElement::class, $envoltorio);
            $this->assertStringContainsString(
                'lote-bloque',
                (string) $envoltorio->getAttribute('class'),
                'Una barra de lote quedo fuera de su envoltorio: el sticky vuelve a escaparse.'
            );

            $this->assertGreaterThan(
                0,
                $xpath->query('.//table', $envoltorio)->length,
                'El envoltorio de una barra de lote no contiene su tabla.'
            );
        }
    }

    public function test_se_asigna_un_estudiante_a_un_grupo(): void
    {
        $grupo = $this->crearGrupo();
        $matricula = $this->matricular($this->violin, estado: Matricula::ACTIVA);

        $this->actingAs($this->profesor->user)
            ->post(route('panel-asignar-grupo', $matricula), ['grupo_id' => $grupo->id])
            ->assertSessionHas('success');

        $this->assertSame([$grupo->id], $matricula->grupos()->pluck('grupos.id')->all());
    }

    public function test_asignar_sin_grupo_lo_saca_del_horario(): void
    {
        $grupo = $this->crearGrupo();
        $matricula = $this->matricular($this->violin, estado: Matricula::ACTIVA);
        $matricula->repartirEn([$grupo->id]);

        $this->actingAs($this->profesor->user)
            ->post(route('panel-asignar-grupo', $matricula), ['grupo_id' => '']);

        $this->assertSame(0, $matricula->grupos()->count());
        $this->assertSame(Matricula::ACTIVA, $matricula->fresh()->estado);
    }

    public function test_no_se_asigna_a_un_grupo_lleno(): void
    {
        $grupo = $this->crearGrupo(cupo: 1);

        $primera = $this->matricular($this->violin, estado: Matricula::ACTIVA);
        $primera->repartirEn([$grupo->id]);

        $segunda = $this->matricular($this->violin, $this->crearEstudiante('samu'), Matricula::ACTIVA);

        $this->actingAs($this->profesor->user)
            ->post(route('panel-asignar-grupo', $segunda), ['grupo_id' => $grupo->id])
            ->assertSessionHas('error');

        $this->assertSame(0, $segunda->grupos()->count());
    }

    /**
     * El lote es todo o nada: dejar a unos dentro y a otros fuera obliga a
     * averiguar a mano cuales entraron, que es justo el trabajo que el lote
     * viene a evitar.
     */
    public function test_el_lote_que_no_cabe_no_asigna_a_nadie(): void
    {
        $grupo = $this->crearGrupo(cupo: 2);

        $matriculas = [$this->matricular($this->violin, estado: Matricula::ACTIVA)];

        foreach (['samu', 'beto'] as $nombre) {
            $matriculas[] = $this->matricular(
                $this->violin,
                $this->crearEstudiante($nombre),
                Matricula::ACTIVA
            );
        }

        $this->actingAs($this->profesor->user)
            ->post(route('panel-asignar-grupo-lote', $this->violin), [
                'grupo_id' => $grupo->id,
                'matricula_ids' => array_map(fn (Matricula $m) => $m->id, $matriculas),
            ])
            // Se comprueba el TEXTO y no solo que haya error: el mensaje dice
            // cuantos cabian, que es lo unico que permite reintentar sin ir
            // probando. Antes esta prueba solo miraba que la clave existiera, y
            // habria dejado pasar cualquier cambio de redaccion.
            ->assertSessionHas('error', fn (string $aviso) => str_contains($aviso, 'No caben 3 en')
                && str_contains($aviso, 'solo quedaban 2 cupos')
                && str_contains($aviso, 'No se asignó a nadie'));

        foreach ($matriculas as $matricula) {
            $this->assertSame(0, $matricula->grupos()->count());
        }
    }

    public function test_el_lote_que_cabe_los_asigna_a_todos(): void
    {
        $grupo = $this->crearGrupo(cupo: 5);

        $matriculas = [
            $this->matricular($this->violin, estado: Matricula::ACTIVA),
            $this->matricular($this->violin, $this->crearEstudiante('samu'), Matricula::ACTIVA),
        ];

        $this->actingAs($this->profesor->user)
            ->post(route('panel-asignar-grupo-lote', $this->violin), [
                'grupo_id' => $grupo->id,
                'matricula_ids' => array_map(fn (Matricula $m) => $m->id, $matriculas),
            ])
            ->assertSessionHas('success');

        foreach ($matriculas as $matricula) {
            $this->assertSame([$grupo->id], $matricula->grupos()->pluck('grupos.id')->all());
        }
    }

    // -----------------------------------------------------------------------
    // Pendientes en lote
    // -----------------------------------------------------------------------

    public function test_confirmar_en_lote_activa_las_marcadas(): void
    {
        $matriculas = [
            $this->matricular($this->violin),
            $this->matricular($this->violin, $this->crearEstudiante('samu')),
            $this->matricular($this->violin, $this->crearEstudiante('beto')),
        ];

        $this->actingAs($this->profesor->user)
            ->post(route('panel-pendientes-lote', $this->violin), [
                'decision' => 'confirmar',
                'matricula_ids' => array_map(fn (Matricula $m) => $m->id, $matriculas),
            ])
            ->assertSessionHas('success');

        foreach ($matriculas as $matricula) {
            $this->assertSame(Matricula::ACTIVA, $matricula->fresh()->estado);
        }
    }

    public function test_rechazar_en_lote_las_retira(): void
    {
        $matriculas = [
            $this->matricular($this->violin),
            $this->matricular($this->violin, $this->crearEstudiante('samu')),
        ];

        $this->actingAs($this->profesor->user)
            ->post(route('panel-pendientes-lote', $this->violin), [
                'decision' => 'rechazar',
                'matricula_ids' => array_map(fn (Matricula $m) => $m->id, $matriculas),
            ])
            ->assertSessionHas('success');

        foreach ($matriculas as $matricula) {
            $this->assertSame(Matricula::RETIRADA, $matricula->fresh()->estado);
        }
    }

    /**
     * A diferencia del reparto por grupo, esto NO es todo o nada.
     *
     * Cada matricula falla por su cuenta y por un motivo que se puede nombrar,
     * asi que deshacer las que si valian para castigar a la que no seria peor
     * que resolverlas. Se confirma lo que se puede y se dice quien quedo fuera.
     */
    /**
     * El escenario tiene que montarse BAJANDO el limite, y eso importa: mientras
     * el limite no cambie, el indice unico sobre la ranura impide siquiera crear
     * la solicitud que sobra. La unica forma de llegar a una pendiente que no se
     * puede confirmar es que el administrador recorte el cupo despues —que es
     * justo el caso que el `confirmar` de a uno ya contemplaba.
     */
    public function test_confirmar_en_lote_salta_a_quien_no_tiene_cupo_y_sigue(): void
    {
        // Con el limite en 2, este alcanza a pedir dos promotorias.
        $lleno = $this->crearEstudiante('samu');
        $suyaViolin = $this->matricular($this->violin, $lleno);
        $this->matricular($this->danza, $lleno);

        $libre = $this->matricular($this->violin, $this->crearEstudiante('beto'));

        // Y ahora direccion lo baja a una: las dos suyas dejan de caber.
        ConfiguracionInstitucion::actual()->update(['limite_promotorias_por_periodo' => 1]);

        $respuesta = $this->actingAs($this->profesor->user)
            ->post(route('panel-pendientes-lote', $this->violin), [
                'decision' => 'confirmar',
                'matricula_ids' => [$suyaViolin->id, $libre->id],
            ]);

        // La que cabia entro; la otra sigue esperando, no se perdio.
        $this->assertSame(Matricula::ACTIVA, $libre->fresh()->estado);
        $this->assertSame(Matricula::PENDIENTE, $suyaViolin->fresh()->estado);

        // Y el aviso dice a QUIEN, no solo cuantos: con veinte filas, «1 de 2»
        // obliga a comparar la lista a ojo.
        $respuesta->assertSessionHas(
            'error',
            fn (string $mensaje) => str_contains($mensaje, $lleno->nombre_completo)
        );
    }

    /** Lo que no sea de esta promotoria o ya este resuelto no entra en el lote. */
    public function test_el_lote_de_pendientes_ignora_lo_ajeno_y_lo_ya_resuelto(): void
    {
        $ajena = $this->matricular($this->danza, $this->crearEstudiante('samu'));
        $yaActiva = $this->matricular($this->violin, $this->crearEstudiante('beto'), Matricula::ACTIVA);
        $buena = $this->matricular($this->violin);

        $this->actingAs($this->profesor->user)
            ->post(route('panel-pendientes-lote', $this->violin), [
                'decision' => 'rechazar',
                'matricula_ids' => [$ajena->id, $yaActiva->id, $buena->id],
            ]);

        $this->assertSame(Matricula::PENDIENTE, $ajena->fresh()->estado);
        $this->assertSame(Matricula::ACTIVA, $yaActiva->fresh()->estado);
        $this->assertSame(Matricula::RETIRADA, $buena->fresh()->estado);
    }

    public function test_un_profesor_ajeno_no_resuelve_pendientes_en_lote(): void
    {
        $otro = $this->crearPerfil('otro', 'profesor');
        $matricula = $this->matricular($this->violin);

        $this->actingAs($otro->user)
            ->post(route('panel-pendientes-lote', $this->violin), [
                'decision' => 'confirmar',
                'matricula_ids' => [$matricula->id],
            ])
            ->assertSessionHas('error');

        $this->assertSame(Matricula::PENDIENTE, $matricula->fresh()->estado);
    }

    // -----------------------------------------------------------------------
    // Clases y asistencia
    // -----------------------------------------------------------------------

    /**
     * Registrar una clase es de quien la dicta, no de quien administra el
     * catalogo: un registro que puede reescribir alguien que no dio la clase
     * deja de ser evidencia de lo que paso.
     */
    public function test_el_director_no_abre_clases_de_una_promotoria_ajena(): void
    {
        $grupo = $this->crearGrupo();

        $this->actingAs($this->director->user)
            ->post(route('panel-clase-nueva', $grupo))
            ->assertSessionHas('error');

        $this->assertSame(0, Clase::count());
    }

    public function test_iniciar_clase_lleva_a_pasar_lista(): void
    {
        $grupo = $this->crearGrupo();

        $this->actingAs($this->profesor->user)
            ->post(route('panel-clase-nueva', $grupo))
            ->assertRedirect(route('clase-asistencia', Clase::first()));

        $this->assertSame(1, Clase::count());
    }

    /**
     * Dos registros el mismo dia son casi siempre el mismo boton pulsado dos
     * veces, y partir la asistencia del dia en dos listas a medias es peor que
     * cualquier caso raro que esto impida.
     */
    public function test_dos_clases_el_mismo_dia_son_la_misma(): void
    {
        $grupo = $this->crearGrupo();

        $this->actingAs($this->profesor->user)->post(route('panel-clase-nueva', $grupo));
        $this->actingAs($this->profesor->user)->post(route('panel-clase-nueva', $grupo));

        $this->assertSame(1, Clase::count());
    }

    public function test_se_guarda_la_asistencia(): void
    {
        $grupo = $this->crearGrupo();
        $matricula = $this->matricular($this->violin, estado: Matricula::ACTIVA);
        $matricula->repartirEn([$grupo->id]);

        $clase = Clase::abrir($grupo, $this->periodo, $this->profesor);

        $this->actingAs($this->profesor->user)
            ->post(route('clase-asistencia', $clase), ["estado_{$matricula->id}" => 'asistio'])
            ->assertSessionHas('success');

        $this->assertSame('asistio', $clase->asistencias()->first()->estado);
    }

    /** Se puede corregir cuantas veces haga falta: es el caso normal. */
    public function test_la_asistencia_se_puede_corregir(): void
    {
        $grupo = $this->crearGrupo();
        $matricula = $this->matricular($this->violin, estado: Matricula::ACTIVA);
        $matricula->repartirEn([$grupo->id]);

        $clase = Clase::abrir($grupo, $this->periodo, $this->profesor);

        $this->actingAs($this->profesor->user)
            ->post(route('clase-asistencia', $clase), ["estado_{$matricula->id}" => 'falto']);
        $this->actingAs($this->profesor->user)
            ->post(route('clase-asistencia', $clase), ["estado_{$matricula->id}" => 'excusa']);

        $this->assertSame(1, $clase->asistencias()->count());
        $this->assertSame('excusa', $clase->asistencias()->first()->estado);
    }

    public function test_el_director_ve_la_asistencia_pero_no_la_escribe(): void
    {
        $grupo = $this->crearGrupo();
        $matricula = $this->matricular($this->violin, estado: Matricula::ACTIVA);
        $matricula->repartirEn([$grupo->id]);

        $clase = Clase::abrir($grupo, $this->periodo, $this->profesor);

        $this->actingAs($this->director->user)
            ->get(route('clase-asistencia', $clase))
            ->assertOk()
            ->assertSee('Un registro que puede reescribir alguien que no dio la clase', false);

        $this->actingAs($this->director->user)
            ->post(route('clase-asistencia', $clase), ["estado_{$matricula->id}" => 'asistio']);

        $this->assertSame(0, $clase->asistencias()->count());
    }

    public function test_la_pantalla_de_clases_del_grupo_abre(): void
    {
        $grupo = $this->crearGrupo();
        Clase::abrir($grupo, $this->periodo, $this->profesor);

        $this->actingAs($this->profesor->user)
            ->get(route('grupo-clases', $grupo))
            ->assertOk();
    }

    // -----------------------------------------------------------------------
    // Fichas
    // -----------------------------------------------------------------------

    public function test_un_profesor_no_abre_la_ficha_de_otro_profesor(): void
    {
        $otro = $this->crearPerfil('otra', 'profesor');

        $this->actingAs($this->profesor->user)
            ->get(route('detalle-usuario', $otro))
            ->assertRedirect(route('panel'))
            ->assertSessionHas('error');
    }

    public function test_el_profesor_abre_la_ficha_de_un_estudiante(): void
    {
        $this->matricular($this->violin, estado: Matricula::ACTIVA);

        $this->actingAs($this->profesor->user)
            ->get(route('detalle-usuario', $this->estudiante))
            ->assertOk()
            ->assertSee('Ana');
    }

    /**
     * Desde el 05/10/2026 el profesor solo abre la ficha de SUS estudiantes
     * (`Permisos::esEstudianteDe`, la misma regla que la foto). Antes abria la
     * de cualquiera probando ids.
     */
    public function test_un_profesor_no_abre_la_ficha_de_un_estudiante_ajeno(): void
    {
        $this->matricular($this->danza, estado: Matricula::ACTIVA);   // Danza no es suya

        $this->actingAs($this->profesor->user)
            ->get(route('detalle-usuario', $this->estudiante))
            ->assertRedirect(route('panel'))
            ->assertSessionHas('error');
    }

    public function test_tampoco_la_de_un_estudiante_sin_matricula(): void
    {
        $this->actingAs($this->profesor->user)
            ->get(route('detalle-usuario', $this->crearEstudiante('beto')))
            ->assertRedirect(route('panel'));
    }

    /** La retirada de su historial tambien es suya: cualquier estado. */
    public function test_si_la_de_un_estudiante_suyo_ya_retirado(): void
    {
        $this->matricular($this->violin, estado: Matricula::RETIRADA);

        $this->actingAs($this->profesor->user)
            ->get(route('detalle-usuario', $this->estudiante))
            ->assertOk();
    }

    /**
     * Las listas preguntan por cada fila para pintar el enlace a la ficha: la
     * respuesta cuesta UNA consulta por peticion, no una por estudiante.
     */
    public function test_saber_si_es_suyo_cuesta_una_consulta_por_peticion(): void
    {
        $alumnos = [];
        foreach (['b1', 'b2', 'b3', 'b4', 'b5'] as $u) {
            $alumnos[] = $alumno = $this->crearEstudiante($u);
            $this->matricular($this->violin, $alumno, Matricula::ACTIVA);
        }

        DB::enableQueryLog();
        foreach ($alumnos as $alumno) {
            $this->assertTrue(Permisos::esEstudianteDe($this->profesor, $alumno));
        }
        $this->assertCount(1, DB::getQueryLog());
        DB::disableQueryLog();
    }

    /**
     * El panel de asistencia de la ficha solo se pinta cuando hay algo que
     * contar, y por eso ninguna prueba lo habia renderizado: un error de
     * compilacion de Blade vivio ahi sin que nada lo delatara.
     */
    public function test_la_ficha_con_asistencia_abre(): void
    {
        $grupo = $this->crearGrupo();
        $matricula = $this->matricular($this->violin, estado: Matricula::ACTIVA);
        $matricula->repartirEn([$grupo->id]);

        $clase = Clase::abrir($grupo, $this->periodo, $this->profesor);
        Asistencia::create([
            'clase_id' => $clase->id,
            'matricula_id' => $matricula->id,
            'estado' => 'asistio',
        ]);

        $this->actingAs($this->director->user)
            ->get(route('detalle-usuario', $this->estudiante))
            ->assertOk()
            ->assertSee('Asistencia')
            ->assertSee('Racha');
    }

    /**
     * El contacto de un estudiante ajeno no le llega al profesor. Hasta el
     * 05/10/2026 abria la ficha con el contacto recortado; desde ese dia ni
     * siquiera la abre (`Permisos::esEstudianteDe`).
     */
    public function test_el_profesor_no_ve_el_contacto_de_un_estudiante_ajeno(): void
    {
        $ajeno = $this->crearEstudiante('beto');
        $this->matricular($this->danza, $ajeno, Matricula::ACTIVA);

        $this->actingAs($this->profesor->user)
            ->get(route('detalle-usuario', $ajeno))
            ->assertRedirect(route('panel'))
            ->assertDontSee('3000000000');
    }

    /**
     * La edad del PERSONAL no la ve nadie, ni el administrador.
     *
     * Es un dato del estudiante --de el salen la minoria de edad, el acudiente
     * obligatorio y el nivel del grupo-- y en un profesor no lo usa nadie. La
     * suya la sigue viendo cada quien en Mi perfil.
     *
     * DIVERGENCIA DELIBERADA del original: `detalle_usuario.html` del Django la
     * pinta para cualquier rol. Esta prueba existe para que no vuelva por
     * descuido al portar algo.
     *
     * El telefono se comprueba en la misma pasada a proposito: la edad y el
     * contacto son dos puertas distintas, y esconder la edad apagando
     * `$veContacto` dejaria al personal sin telefono, que si hace falta.
     */
    public function test_la_ficha_no_ensena_la_edad_del_personal(): void
    {
        $this->profesor->fecha_nacimiento = Carbon::today()->subYears(47)->subDays(3);
        $this->profesor->save();

        $admin = $this->crearPerfil('admin', 'administrador');

        $this->actingAs($admin->user)
            ->get(route('detalle-usuario', $this->profesor))
            ->assertOk()
            ->assertSee('Profe')
            ->assertDontSee('<th>Edad</th>', false)
            ->assertDontSee('47 años', false)
            ->assertSee('3000000000');
    }

    /** Y en un estudiante sigue estando, que es donde el dato trabaja. */
    public function test_la_ficha_de_un_estudiante_si_ensena_la_edad(): void
    {
        $this->estudiante->fecha_nacimiento = Carbon::today()->subYears(14)->subDays(3);
        $this->estudiante->save();

        $this->actingAs($this->director->user)
            ->get(route('detalle-usuario', $this->estudiante))
            ->assertOk()
            ->assertSee('<th>Edad</th>', false)
            ->assertSee('14 años', false);
    }

    public function test_solo_el_administrador_abre_la_ficha_con_documento(): void
    {
        $this->actingAs($this->director->user)
            ->get(route('detalle-estudiante', $this->estudiante))
            ->assertRedirect(route('post-login'));

        $admin = $this->crearPerfil('admin', 'administrador');

        $this->actingAs($admin->user)
            ->get(route('detalle-estudiante', $this->estudiante))
            ->assertOk();
    }

    public function test_el_historial_lo_ve_todo_el_personal(): void
    {
        $this->matricular($this->danza, estado: Matricula::ACTIVA);

        $this->actingAs($this->profesor->user)
            ->get(route('historial-estudiante', $this->estudiante))
            ->assertOk()
            // El historial es ENTERO a proposito: saber que lleva periodos en
            // otra disciplina es lo que sirve para ubicarlo en un nivel.
            ->assertSee('Danza');
    }
}
