<?php

namespace Tests\Feature;

use App\Models\Actividad;
use App\Models\AsistenciaActividad;
use App\Models\InscritoActividad;
use App\Models\InstitucionExterna;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\SesionActividad;
use App\Models\User;
use App\Support\CarneQr;
use App\Support\Permisos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * PROGRAMAS EXTERNOS: lo que un profesor de la casa dicta en OTRA institucion,
 * y la cuenta con la que esa institucion da fe de que fue.
 *
 * ─── LO QUE VIGILA ESTE ARCHIVO ────────────────────────────────────────────
 *
 * 1. LA PUERTA PUBLICA CERRADA. Los otros tres tipos de actividad se llenan por
 *    un enlace que alguien comparte; este NO, y su lista son nombres de menores
 *    de otra entidad. Todas las actividades tienen `token` —la columna es
 *    obligatoria— asi que lo unico que cierra esa puerta es el corte POR TIPO.
 * 2. QUE CADA INSTITUCION SOLO VEA Y FIRME LO SUYO.
 * 3. EL PLAZO DEL QR: el mismo dia si, otro dia no, Y LO DICE. Un aviso que
 *    solo habla en el camino feliz es un adorno — costo el 21/09/2026 en
 *    produccion con el carne del estudiante.
 * 4. QUE EL ROL NUEVO NO SE CUELE por las pantallas del personal de la casa.
 *
 * Todas estas se han visto FALLAR quitando el arreglo que protegen: una prueba
 * que no se ha visto en rojo no prueba nada.
 */
class ProgramaExternoTest extends TestCase
{
    use RefreshDatabase;

    private Perfil $admin;

    private Perfil $profesor;

    private InstitucionExterna $escuela;

    private InstitucionExterna $otraEscuela;

    protected function setUp(): void
    {
        parent::setUp();

        Periodo::create([
            'nombre' => '2026-2',
            'fecha_inicio' => Carbon::today()->subMonth()->toDateString(),
            'fecha_fin' => Carbon::today()->addMonths(4)->toDateString(),
            'activo' => true,
            'matriculas_abiertas' => true,
        ]);

        $this->admin = $this->perfil('jefa', 'administrador');
        $this->profesor = $this->perfil('profe', 'profesor');
        $this->escuela = $this->institucion('I. E. Rural El Carmen', 'carmen');
        $this->otraEscuela = $this->institucion('Colegio San José', 'sanjose');
    }

    // ------------------------------------------------------------------
    // 1. La puerta publica
    // ------------------------------------------------------------------

    /**
     * EL ENLACE DE UN PROGRAMA EXTERNO NO ABRE NADA.
     *
     * Es la prueba mas importante del archivo. El token existe —`actividades.token`
     * es NOT NULL para los cuatro tipos— asi que una URL valida se puede
     * componer, y al otro lado hay una lista de ninos de otra institucion. Lo
     * unico que lo impide es el `whereIn` por tipo de
     * `InscripcionActividadController::buscar()`.
     */
    public function test_el_enlace_publico_de_un_programa_externo_no_existe(): void
    {
        $programa = $this->programa();

        $this->get('/inscribirse/'.$programa->token)->assertNotFound();
        $this->post('/inscribirse/'.$programa->token, [
            'nombre_completo' => 'Intruso Pérez',
            'documento' => '1234567890',
            'telefono' => '3001112233',
            'fecha_nacimiento' => '2010-01-01',
        ])->assertNotFound();

        // Y nadie entro en la lista por ese camino.
        $this->assertSame(0, $programa->inscritos()->count());
    }

    /** Y el de un taller corriente sigue abriendo, que es la otra mitad. */
    public function test_el_enlace_de_un_taller_sigue_abriendo(): void
    {
        $taller = Actividad::create([
            'nombre' => 'Taller de cerámica',
            'tipo' => Actividad::TALLER,
            'responsable_id' => $this->profesor->id,
        ]);

        $this->get('/inscribirse/'.$taller->token)->assertOk();
    }

    /** Un programa externo no admite inscripciones ni aunque alguien lo «abra». */
    public function test_un_programa_externo_no_admite_inscripciones(): void
    {
        $programa = $this->programa();

        // Se fuerza `abierta` a mano: el segundo cerrojo no puede ser el unico.
        $programa->abierta = true;
        $programa->save();

        $this->assertFalse($programa->fresh()->admiteInscripciones(0));
    }

    // ------------------------------------------------------------------
    // 2. La lista la escribe el profesor
    // ------------------------------------------------------------------

    public function test_el_profesor_anade_a_la_lista_con_nombre_y_edad(): void
    {
        $programa = $this->programa();

        $this->actingAs($this->profesor->user)
            ->post(route('panel-externo-anadir', $programa), [
                'nombre_completo' => 'Ana Ruiz',
                'edad' => 9,
            ])->assertRedirect(route('panel-actividad', $programa));

        $inscrito = $programa->inscritos()->first();

        $this->assertSame('Ana Ruiz', $inscrito->nombre_completo);
        $this->assertSame(9, $inscrito->edad);
        // El origen dice COMO entro, y `lista` es lo que significa «lo escribio
        // quien dirige, armando la lista».
        $this->assertSame(InscritoActividad::LISTA, $inscrito->origen);
        // No se le invento documento ni telefono: no se preguntan.
        $this->assertNull($inscrito->documento);
        $this->assertNull($inscrito->fecha_nacimiento);
    }

    /** Quien no dicta el programa no le arma la lista, aunque pueda verlo. */
    public function test_solo_quien_dicta_arma_la_lista(): void
    {
        $programa = $this->programa();

        $this->actingAs($this->admin->user)
            ->post(route('panel-externo-anadir', $programa), [
                'nombre_completo' => 'Ana Ruiz',
                'edad' => 9,
            ]);

        $this->assertSame(0, $programa->inscritos()->count());
    }

    /**
     * EL OTRO CAMINO TAMBIEN PIDE LA EDAD (25/09/2026). «Llego alguien sin
     * inscribirse», dentro de la hoja de asistencia, solo pedia el nombre y
     * dejaba la fila con un «—»: en un programa externo la edad es el unico
     * otro dato, y se cae entero si falta en media lista.
     */
    public function test_anadir_en_clase_a_un_programa_externo_exige_la_edad(): void
    {
        $programa = $this->programa();
        $sesion = $this->sesion($programa, iniciada: true);

        $this->actingAs($this->profesor->user)
            ->post(route('panel-actividad-anadir', $sesion), ['nombre_completo' => 'Pepe Perez'])
            ->assertSessionHasErrors('edad');

        $this->assertSame(0, $programa->inscritos()->count());
    }

    public function test_anadir_en_clase_a_un_programa_externo_guarda_la_edad(): void
    {
        $programa = $this->programa();
        $sesion = $this->sesion($programa, iniciada: true);

        $this->actingAs($this->profesor->user)
            ->post(route('panel-actividad-anadir', $sesion), [
                'nombre_completo' => 'Pepe Perez',
                'edad' => 11,
            ])->assertSessionHas('success');

        $inscrito = $programa->inscritos()->firstOrFail();

        $this->assertSame(11, $inscrito->edad);
        // El origen dice COMO entro, no que datos trae: aparecio en una clase.
        $this->assertSame(InscritoActividad::EN_SESION, $inscrito->origen);
        $this->assertSame('asistio', $sesion->asistencias()->where('inscrito_id', $inscrito->id)->value('estado'));
    }

    /** Y la hoja lo pregunta: el campo esta en el formulario de ese camino. */
    public function test_la_hoja_de_un_programa_externo_pregunta_la_edad(): void
    {
        $programa = $this->programa();
        $sesion = $this->sesion($programa, iniciada: true);

        $this->actingAs($this->profesor->user)
            ->get(route('panel-actividad-lista', $sesion))
            ->assertOk()
            ->assertSee('name="edad"', false);
    }

    /**
     * QUITAR SOLO MIENTRAS NO TENGA MARCAS.
     *
     * El corte no es un permiso: la clave foranea es CASCADE, asi que borrar la
     * fila se llevaria sus marcas de asistencia sin que nada avisara.
     */
    public function test_no_se_quita_de_la_lista_a_quien_ya_tiene_asistencia(): void
    {
        $programa = $this->programa();
        $sesion = $this->sesion($programa, iniciada: true);
        $inscrito = $this->enLaLista($programa, 'Ana Ruiz');

        AsistenciaActividad::create([
            'sesion_id' => $sesion->id,
            'inscrito_id' => $inscrito->id,
            'estado' => AsistenciaActividad::ASISTIO,
        ]);

        $this->actingAs($this->profesor->user)
            ->post(route('panel-externo-quitar', $inscrito));

        $this->assertNotNull($inscrito->fresh());
        $this->assertSame(1, AsistenciaActividad::count());
    }

    /** Y a quien no tiene ninguna, si: es el error de tecleo del primer dia. */
    public function test_se_quita_de_la_lista_a_quien_no_tiene_asistencia(): void
    {
        $programa = $this->programa();
        $inscrito = $this->enLaLista($programa, 'Ana Riuz');

        $this->actingAs($this->profesor->user)
            ->post(route('panel-externo-quitar', $inscrito));

        $this->assertNull($inscrito->fresh());
    }

    // ------------------------------------------------------------------
    // 2b. Iniciar la clase lleva a marcarla
    // ------------------------------------------------------------------

    /**
     * INICIAR ATERRIZA EN LA HOJA DE ASISTENCIA, no en la ficha.
     *
     * Decision del usuario el 23/09/2026, tomada mirando la pantalla: el aviso
     * decia «ya puedes pasar lista» y dejaba a la persona donde estaba, con
     * «Pasar lista» en un boton blanco pequeno dentro de la tabla y DOS botones
     * verdes al lado que no eran ese. El gesto real es uno solo —llego, inicio,
     * marco—, de pie en un salon ajeno y desde un celular.
     */
    public function test_iniciar_la_clase_lleva_a_pasar_lista(): void
    {
        $programa = $this->programa();

        $this->actingAs($this->profesor->user)
            ->post(route('panel-actividad-iniciar-hoy', $programa))
            ->assertRedirect(route('panel-actividad-lista', $programa->sesiones()->first()));

        $this->assertNotNull($programa->sesiones()->first()->iniciada_en);
    }

    /**
     * El segundo toque LLEVA A LA HOJA y NO reescribe la hora.
     *
     * Las dos mitades importan y por eso se afirman las dos. Antes este camino
     * devolvia a la ficha en silencio: el boton mas visible de la pantalla era
     * una accion agotada que no hacia nada ni lo decia. Y la hora no se puede
     * tocar —reescribirla borraria la de verdad, que es el dato por el que esa
     * columna existe—.
     */
    public function test_volver_a_oprimir_no_reescribe_la_hora_y_lleva_a_la_hoja(): void
    {
        $programa = $this->programa();

        $this->actingAs($this->profesor->user)
            ->post(route('panel-actividad-iniciar-hoy', $programa));

        $sesion = $programa->sesiones()->first();
        $primera = $sesion->iniciada_en;

        $this->travel(5)->minutes();

        $this->actingAs($this->profesor->user)
            ->post(route('panel-actividad-iniciar-hoy', $programa))
            ->assertRedirect(route('panel-actividad-lista', $sesion));

        $this->assertSame(1, $programa->sesiones()->count());
        $this->assertSame(
            $primera->toDateTimeString(),
            $sesion->fresh()->iniciada_en->toDateTimeString()
        );
    }

    /**
     * EL BOTÓN DEJA DE DECIR «INICIAR» cuando la de hoy ya empezó.
     *
     * Se afirma sobre el texto porque el texto ES el fallo: el control seguía
     * anunciando en verde macizo una acción agotada. Va atado al rótulo a
     * sabiendas de que eso se queda mudo si alguien lo renombra — aquí no hay
     * otra cosa a la que atarlo, porque la ruta y el formulario son los mismos
     * antes y después.
     */
    public function test_el_boton_deja_de_decir_iniciar_cuando_ya_empezo(): void
    {
        $programa = $this->programa();

        $antes = (string) $this->actingAs($this->profesor->user)
            ->get(route('panel-actividad', $programa))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Iniciar clase de hoy', $antes);

        $this->sesion($programa, iniciada: true);

        $despues = (string) $this->actingAs($this->profesor->user)
            ->get(route('panel-actividad', $programa))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Iniciar clase de hoy', $despues);
        $this->assertStringContainsString('Pasar lista de hoy', $despues);
    }

    // ------------------------------------------------------------------
    // 3. La institucion da fe
    // ------------------------------------------------------------------

    public function test_la_institucion_verifica_su_clase_sin_plazo(): void
    {
        $programa = $this->programa();
        // De hace tres semanas: el funcionario NO tiene plazo, a diferencia del
        // QR. Sin esto la fecha de hoy pasaria por las dos reglas a la vez y la
        // prueba no distinguiria cual la dejo pasar.
        $sesion = $this->sesion($programa, iniciada: true, hace: 21);

        $this->actingAs($this->escuela->perfil->user)
            ->post(route('externa-verificar', $sesion))
            ->assertRedirect();

        $sesion->refresh();

        $this->assertTrue($sesion->estaVerificada());
        $this->assertSame('propia', $sesion->verificacion_origen);
        $this->assertSame($this->escuela->perfil->id, $sesion->verificada_por_id);
    }

    /** Y puede retirarla: es lo unico que tiene para desconocer una firma. */
    public function test_la_institucion_retira_una_verificacion(): void
    {
        $programa = $this->programa();
        $sesion = $this->sesion($programa, iniciada: true);

        $this->actingAs($this->escuela->perfil->user)
            ->post(route('externa-verificar', $sesion));
        $this->actingAs($this->escuela->perfil->user)
            ->post(route('externa-retirar', $sesion));

        $sesion->refresh();

        $this->assertFalse($sesion->estaVerificada());
        // Las TRES columnas se vacian juntas: el CHECK de la base lo exige, y
        // media firma no significa nada.
        $this->assertNull($sesion->verificada_por_id);
        $this->assertNull($sesion->verificacion_origen);
    }

    /** No se da fe de una clase que no ha empezado. */
    public function test_no_se_verifica_una_clase_sin_iniciar(): void
    {
        $programa = $this->programa();
        $sesion = $this->sesion($programa, iniciada: false);

        $this->actingAs($this->escuela->perfil->user)
            ->post(route('externa-verificar', $sesion));

        $this->assertFalse($sesion->fresh()->estaVerificada());
    }

    /**
     * UNA INSTITUCION NO TOCA LAS CLASES DE OTRA.
     *
     * 404 y no 403: para esa cuenta, esa clase no existe.
     */
    public function test_una_institucion_no_verifica_la_clase_de_otra(): void
    {
        $programa = $this->programa();
        $sesion = $this->sesion($programa, iniciada: true);

        $this->actingAs($this->otraEscuela->perfil->user)
            ->post(route('externa-verificar', $sesion))
            ->assertNotFound();

        $this->assertFalse($sesion->fresh()->estaVerificada());
    }

    /** Ni las ve en su bandeja. */
    public function test_la_bandeja_solo_trae_las_clases_propias(): void
    {
        $programa = $this->programa('Guitarra — El Carmen');
        $this->sesion($programa, iniciada: true);

        $otro = $this->programa('Danza — San José', $this->otraEscuela);
        $this->sesion($otro, iniciada: true);

        $html = (string) $this->actingAs($this->escuela->perfil->user)
            ->get(route('externa-clases'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Guitarra', $html);
        $this->assertStringNotContainsString('Danza', $html);
    }

    /**
     * LA BANDEJA NO ENSENA NOMBRES, SOLO CUANTOS.
     *
     * Decision del 23/09/2026: la cifra permite reconocer la clase; una lista
     * nominal de menores en una cuenta ajena al sistema es un precio que esta
     * firma no necesita pagar.
     */
    public function test_la_bandeja_cuenta_asistentes_y_no_los_nombra(): void
    {
        $programa = $this->programa();
        $sesion = $this->sesion($programa, iniciada: true);
        $inscrito = $this->enLaLista($programa, 'Ana Ruiz');

        AsistenciaActividad::create([
            'sesion_id' => $sesion->id,
            'inscrito_id' => $inscrito->id,
            'estado' => AsistenciaActividad::ASISTIO,
        ]);

        $html = (string) $this->actingAs($this->escuela->perfil->user)
            ->get(route('externa-clases'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('1 asistente', $html);
        $this->assertStringNotContainsString('Ana Ruiz', $html);
    }

    // ------------------------------------------------------------------
    // 4. El QR, y su plazo
    // ------------------------------------------------------------------

    public function test_el_qr_verifica_la_clase_del_mismo_dia(): void
    {
        $programa = $this->programa();
        $sesion = $this->sesion($programa, iniciada: true);

        $this->actingAs($this->profesor->user)
            ->post(route('panel-externo-verificar-qr', $sesion), [
                'codigo' => CarneQr::PREFIJO.$this->escuela->perfil->codigoQr(),
            ]);

        $sesion->refresh();

        $this->assertTrue($sesion->estaVerificada());
        // NUNCA se colapsan las dos cifras: aqui quien sostuvo el papel fue el
        // profesor, que es a quien la verificacion vigila.
        $this->assertSame('qr', $sesion->verificacion_origen);
    }

    /**
     * Y NO la de otro dia, que es el unico freno de este camino: un QR es un
     * carton y un carton se fotografia.
     */
    public function test_el_qr_no_verifica_la_clase_de_otro_dia(): void
    {
        $programa = $this->programa();
        $sesion = $this->sesion($programa, iniciada: true, hace: 3);

        $this->actingAs($this->profesor->user)
            ->post(route('panel-externo-verificar-qr', $sesion), [
                'codigo' => CarneQr::PREFIJO.$this->escuela->perfil->codigoQr(),
            ]);

        $this->assertFalse($sesion->fresh()->estaVerificada());
    }

    /**
     * Y LO DICE. Es la leccion del 21/09/2026: el usuario paso lista con el
     * carne en una clase de dias atras, se marco bien, no se verifico nadie y la
     * pantalla no dio ni una pista. Un aviso que solo habla en el camino feliz
     * es un adorno.
     *
     * Se afirma sobre la razon —el plazo— y no sobre la frase entera: una
     * asercion atada a un rotulo se queda muda el dia que alguien lo reescriba.
     */
    public function test_el_rechazo_por_plazo_explica_por_que(): void
    {
        $programa = $this->programa();
        $sesion = $this->sesion($programa, iniciada: true, hace: 3);

        $this->actingAs($this->profesor->user)
            ->post(route('panel-externo-verificar-qr', $sesion), [
                'codigo' => CarneQr::PREFIJO.$this->escuela->perfil->codigoQr(),
            ])
            ->assertSessionHas('error', fn (string $aviso) => str_contains($aviso, 'mismo día')
                && str_contains($aviso, 'cuenta'));
    }

    /** El QR de OTRA institucion no verifica, y el aviso la nombra. */
    public function test_el_qr_de_otra_institucion_no_verifica(): void
    {
        $programa = $this->programa();
        $sesion = $this->sesion($programa, iniciada: true);

        $this->actingAs($this->profesor->user)
            ->post(route('panel-externo-verificar-qr', $sesion), [
                'codigo' => CarneQr::PREFIJO.$this->otraEscuela->perfil->codigoQr(),
            ])
            ->assertSessionHas('error', fn (string $aviso) => str_contains($aviso, 'Colegio San José'));

        $this->assertFalse($sesion->fresh()->estaVerificada());
    }

    /**
     * RENOVAR INVALIDA EL ANTERIOR EN EL ACTO.
     *
     * Es el contrapeso del camino del QR: si el profesor se quedo con una foto
     * del codigo, esto es lo unico que la inutiliza.
     */
    public function test_renovar_el_qr_invalida_el_codigo_anterior(): void
    {
        $programa = $this->programa();
        $sesion = $this->sesion($programa, iniciada: true);
        $viejo = $this->escuela->perfil->codigoQr();

        $this->actingAs($this->escuela->perfil->user)
            ->post(route('externa-qr-renovar'))
            ->assertRedirect(route('externa-qr'));

        $this->actingAs($this->profesor->user)
            ->post(route('panel-externo-verificar-qr', $sesion), ['codigo' => CarneQr::PREFIJO.$viejo]);

        $this->assertFalse($sesion->fresh()->estaVerificada());
    }

    /**
     * CADA BUSQUEDA POR QR ESTA ACOTADA A SU ROL, y esto hay que afirmarlo
     * DIRECTAMENTE sobre la busqueda.
     *
     * Los dos QR del sistema viven en la misma columna y empiezan por el mismo
     * `MTR:`: lo unico que los separa es el ROL de quien lo lleva.
     *
     * POR QUE ESTA PRUEBA EXISTE APARTE de la de abajo, que parece cubrirlo: se
     * quito el `where('rol', ...)` de `porCodigoQrDeInstitucion()` y la de abajo
     * SIGUIO EN VERDE, porque el rechazo lo hacia una segunda barrera
     * —`Permisos::verificaLaActividad()`, que tambien mira el rol—. Una prueba
     * que pasa por la barrera equivocada no vigila la que dice vigilar: el dia
     * que alguien afloje esta, la de abajo seguiria verde y el lector resolveria
     * el perfil equivocado.
     */
    public function test_cada_busqueda_por_qr_esta_acotada_a_su_rol(): void
    {
        $estudiante = $this->perfil('nino', 'estudiante');

        // El carne de un estudiante no resuelve como institucion...
        $this->assertNull(Perfil::porCodigoQrDeInstitucion($estudiante->codigoQr()));
        // ...ni el de una institucion como estudiante.
        $this->assertNull(Perfil::porCodigoQr($this->escuela->perfil->codigoQr()));
    }

    /**
     * Y de punta a punta: el carne de un estudiante no verifica una clase.
     *
     * Aqui hay DOS barreras encima —la busqueda acotada y el permiso— y eso
     * esta bien; lo que no vale es tomar esto por prueba de la primera. Ver la
     * de arriba.
     */
    public function test_el_carne_de_un_estudiante_no_verifica_una_clase(): void
    {
        $programa = $this->programa();
        $sesion = $this->sesion($programa, iniciada: true);
        $estudiante = $this->perfil('nino', 'estudiante');

        $this->actingAs($this->profesor->user)
            ->post(route('panel-externo-verificar-qr', $sesion), [
                'codigo' => CarneQr::PREFIJO.$estudiante->codigoQr(),
            ]);

        $this->assertFalse($sesion->fresh()->estaVerificada());
    }

    // ------------------------------------------------------------------
    // 5. El rol nuevo no se cuela
    // ------------------------------------------------------------------

    /**
     * EL PANEL LE REBOTA, y por eso el menu no se lo pinta.
     *
     * Hasta hoy la barra decia «cualquiera que no sea estudiante es personal de
     * la casa». Por ahi, esta cuenta recibia el boton de Panel — un boton que
     * rebota, en la unica barra que esta persona ve.
     */
    public function test_la_institucion_externa_no_entra_al_panel(): void
    {
        $this->actingAs($this->escuela->perfil->user)
            ->get(route('panel'))
            ->assertRedirect(route('post-login'));

        $this->actingAs($this->escuela->perfil->user)
            ->get(route('gestion-inicio'))
            ->assertRedirect(route('post-login'));
    }

    /** Y la barra no le pinta ese botón: se comprueba lo que SE VE, no solo la puerta. */
    public function test_la_barra_no_le_ofrece_el_panel(): void
    {
        $html = (string) $this->actingAs($this->escuela->perfil->user)
            ->get(route('externa-clases'))
            ->assertOk()
            ->getContent();

        // Atada a la RUTA y no al rótulo «Panel»: una aserción sobre un rótulo
        // se queda muda el día que alguien lo renombre.
        $this->assertStringNotContainsString(route('panel'), $html);
        $this->assertStringContainsString(route('externa-qr'), $html);
    }

    /** Al entrar aterriza en lo suyo, no en un rebote. */
    public function test_al_entrar_aterriza_en_sus_clases(): void
    {
        $this->actingAs($this->escuela->perfil->user)
            ->get(route('post-login'))
            ->assertRedirect(route('externa-clases'));
    }

    /**
     * EL ROL NO SE REPARTE desde Gestion → Usuarios.
     *
     * Su cuenta solo significa algo colgada de una ficha de institucion, y ese
     * formulario no sabe crear una.
     */
    public function test_el_rol_de_institucion_externa_no_se_reparte(): void
    {
        $this->assertNotContains(
            Perfil::INSTITUCION_EXTERNA,
            Permisos::rolesAsignablesPor($this->admin)
        );
    }

    /**
     * NI SU FICHA SE ABRE DESDE ALLI, y esto es lo que de verdad protege.
     *
     * El desplegable de rol se pinta SIN el suyo, asi que guardar la ficha sin
     * bajarlo le cambiaria el rol a otra cosa —y con el se iria la unica cuenta
     * que podia dar fe de las clases de esa escuela—, sin fallar y sin avisar.
     */
    public function test_la_cuenta_de_una_institucion_no_se_edita_desde_usuarios(): void
    {
        $this->actingAs($this->admin->user)
            ->get(route('usuario-editar', $this->escuela->perfil))
            ->assertForbidden();

        $html = (string) $this->actingAs($this->admin->user)
            ->get(route('usuario-lista'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('I. E. Rural El Carmen', $html);
    }

    // ------------------------------------------------------------------
    // 6. Crear desde Gestion
    // ------------------------------------------------------------------

    /** Registrar una institucion crea las TRES filas: cuenta, perfil y ficha. */
    public function test_registrar_una_institucion_crea_su_cuenta(): void
    {
        $this->actingAs($this->admin->user)
            ->post(route('institucion-externa-nueva'), [
                'nombre' => 'Fundación Semillas',
                'direccion' => 'Vereda La María',
                'telefono' => '604 555 1122 ext. 12',
                'funcionario' => 'Marta Gil',
                'username' => 'semillas',
                'password' => 'clave-larga-1',
            ])->assertRedirect(route('gestion-programas'));

        $institucion = InstitucionExterna::where('nombre', 'Fundación Semillas')->first();

        $this->assertNotNull($institucion);
        $this->assertSame('Marta Gil', $institucion->perfil->nombre_completo);
        $this->assertSame(Perfil::INSTITUCION_EXTERNA, $institucion->perfil->rol);
        $this->assertSame('semillas', $institucion->perfil->user->username);
    }

    /**
     * Y si el formulario rebota no queda una cuenta suelta.
     *
     * Una cuenta sin institucion es un usuario que entra, no ve nada y nadie
     * sabe de donde salio.
     */
    public function test_un_formulario_rechazado_no_deja_cuenta_suelta(): void
    {
        $usuariosAntes = User::count();

        $this->actingAs($this->admin->user)
            ->post(route('institucion-externa-nueva'), [
                'nombre' => '',
                'funcionario' => 'Marta Gil',
                'username' => 'semillas',
                'password' => 'clave-larga-1',
            ])->assertSessionHasErrors('nombre');

        $this->assertSame($usuariosAntes, User::count());
        $this->assertSame(2, InstitucionExterna::count());
    }

    /** Un programa externo SIEMPRE queda colgado de su institucion. */
    public function test_crear_un_programa_externo_lo_cuelga_de_su_institucion(): void
    {
        $this->actingAs($this->admin->user)
            ->post(route('programa-externo-nuevo'), [
                'nombre' => 'Guitarra — El Carmen',
                'responsable_id' => $this->profesor->id,
                'institucion_id' => $this->escuela->id,
            ])->assertRedirect();

        $programa = Actividad::externos()->where('nombre', 'Guitarra — El Carmen')->first();

        $this->assertNotNull($programa);
        $this->assertSame($this->escuela->id, $programa->institucion_id);
        // Nace con la puerta publica apagada: es el segundo cerrojo, y los dos
        // se leen en sitios distintos.
        $this->assertFalse((bool) $programa->abierta);
    }

    /** Un director no crea programas externos ni registra instituciones. */
    public function test_un_director_no_crea_programas_externos(): void
    {
        $director = $this->perfil('dire', 'director');
        $this->dirige($director);

        $this->actingAs($director->user)
            ->get(route('programa-externo-nuevo'))
            ->assertRedirect(route('post-login'));

        $this->actingAs($director->user)
            ->get(route('institucion-externa-nueva'))
            ->assertRedirect(route('post-login'));
    }

    // ------------------------------------------------------------------
    // Andamiaje
    // ------------------------------------------------------------------

    private function programa(string $nombre = 'Guitarra — El Carmen', ?InstitucionExterna $donde = null): Actividad
    {
        /** @var Actividad $programa */
        $programa = Actividad::create([
            'nombre' => $nombre,
            'tipo' => Actividad::EXTERNO,
            'responsable_id' => $this->profesor->id,
            'institucion_id' => ($donde ?? $this->escuela)->id,
        ]);

        return $programa;
    }

    /**
     * Una sesion iniciada HACE TANTOS DIAS.
     *
     * `iniciada_en` es lo que mira el plazo del QR, no `fecha`: son dos datos
     * distintos —cuando tocaba y cuando paso— y aqui el que cuenta es el
     * segundo. Las dos se mueven juntas para que la fila sea creible.
     */
    private function sesion(Actividad $actividad, bool $iniciada, int $hace = 0): SesionActividad
    {
        $cuando = Carbon::now()->subDays($hace);

        /** @var SesionActividad $sesion */
        $sesion = SesionActividad::create([
            'actividad_id' => $actividad->id,
            'fecha' => $cuando->toDateString(),
            'iniciada_en' => $iniciada ? $cuando : null,
            'iniciada_por_id' => $iniciada ? $this->profesor->id : null,
        ]);

        return $sesion;
    }

    private function enLaLista(Actividad $programa, string $nombre): InscritoActividad
    {
        /** @var InscritoActividad $inscrito */
        $inscrito = $programa->inscritos()->create([
            'nombre_completo' => $nombre,
            'edad' => 9,
            'origen' => InscritoActividad::LISTA,
        ]);

        return $inscrito;
    }

    private function institucion(string $nombre, string $username): InstitucionExterna
    {
        $perfil = $this->perfil($username, Perfil::INSTITUCION_EXTERNA);

        /** @var InstitucionExterna $institucion */
        $institucion = InstitucionExterna::create([
            'nombre' => $nombre,
            'perfil_id' => $perfil->id,
        ]);

        return $institucion;
    }

    private function perfil(string $username, string $rol): Perfil
    {
        $user = User::create(['username' => $username, 'password' => 'x', 'activo' => true]);

        /** @var Perfil $perfil */
        $perfil = Perfil::create([
            'user_id' => $user->id,
            'rol' => $rol,
            'nombre_completo' => ucfirst($username),
            'fecha_nacimiento' => Carbon::today()->subYears(30)->toDateString(),
            'telefono' => '3000000000',
        ]);

        return $perfil;
    }
}
