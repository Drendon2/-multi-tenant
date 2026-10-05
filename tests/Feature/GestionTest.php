<?php

namespace Tests\Feature;

use App\Http\Controllers\Gestion\GrupoController;
use App\Http\Controllers\Gestion\UsuarioController;
use App\Models\Acudiente;
use App\Models\Area;
use App\Models\Asistencia;
use App\Models\Clase;
use App\Models\ConfiguracionInstitucion;
use App\Models\ConfirmacionClase;
use App\Models\CupoPromotoria;
use App\Models\DatosEstudiante;
use App\Models\DocumentoRequerido;
use App\Models\EncuestaDemografica;
use App\Models\EncuestaSatisfaccion;
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
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Gestion: el catalogo academico, la ventana de matriculas, las cancelaciones y
 * los usuarios.
 *
 * Lo que mas importa aqui son dos cosas que el resto del sistema da por
 * sentadas: que las cancelaciones se puedan resolver —hasta que exista esta
 * pantalla, una solicitud en tramite no tiene salida— y que no se pueda borrar
 * del catalogo nada que sostenga historial.
 */
class GestionTest extends TestCase
{
    use RefreshDatabase;

    private Periodo $periodo;

    private Area $musica;

    private Promotoria $violin;

    private Perfil $director;

    private Perfil $admin;

    private Perfil $profesor;

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

        $this->musica = Area::create(['nombre' => 'Musica']);
        $this->director = $this->crearPerfil('dire', 'director');
        // Dirige todos los departamentos: estas pruebas no van del recorte del
        // 12/09/2026 sino de lo que Gestion hace, y un director sin
        // departamentos no ve nada. Las del recorte estan en su propio archivo.
        $this->dirige($this->director);
        $this->admin = $this->crearPerfil('admin', 'administrador');
        $this->profesor = $this->crearPerfil('profe', 'profesor');
        $this->estudiante = $this->crearEstudiante('ana');

        $this->violin = Promotoria::create([
            'nombre' => 'Violin',
            'area_id' => $this->musica->id,
            'profesor_id' => $this->profesor->id,
        ]);
    }

    private function crearPerfil(string $username, string $rol, ?string $nacimiento = null): Perfil
    {
        $user = User::create(['username' => $username, 'password' => 'x', 'activo' => true]);

        return Perfil::create([
            'user_id' => $user->id,
            'rol' => $rol,
            'nombre_completo' => ucfirst($username),
            'fecha_nacimiento' => $nacimiento ?? Carbon::today()->subYears(30)->toDateString(),
            'telefono' => '3000000000',
        ]);
    }

    private function crearEstudiante(string $username, ?string $nacimiento = null): Perfil
    {
        $perfil = $this->crearPerfil($username, 'estudiante', $nacimiento);

        DatosEstudiante::create([
            'perfil_id' => $perfil->id,
            'documento_identidad' => str_pad((string) $perfil->id, 8, '0', STR_PAD_LEFT),
        ]);

        return $perfil;
    }

    private function matricular(Perfil $perfil, Promotoria $promotoria, string $estado): Matricula
    {
        $matricula = new Matricula([
            'estudiante_id' => $perfil->id,
            'promotoria_id' => $promotoria->id,
            'periodo_id' => $this->periodo->id,
            'estado' => $estado,
        ]);
        $matricula->save();

        return $matricula;
    }

    // -----------------------------------------------------------------------
    // Puertas
    // -----------------------------------------------------------------------

    public function test_un_profesor_no_entra_a_gestion(): void
    {
        $this->actingAs($this->profesor->user)
            ->get(route('gestion-inicio'))
            ->assertRedirect(route('post-login'));
    }

    public function test_el_director_no_toca_la_configuracion_de_la_institucion(): void
    {
        $this->actingAs($this->director->user)
            ->get(route('gestion-configuracion'))
            ->assertRedirect(route('post-login'));
    }

    public function test_el_director_no_ve_las_estadisticas(): void
    {
        $this->actingAs($this->director->user)
            ->get(route('gestion-estadisticas'))
            ->assertRedirect(route('post-login'));
    }

    public function test_el_administrador_si(): void
    {
        $this->actingAs($this->admin->user)->get(route('gestion-configuracion'))->assertOk();
        $this->actingAs($this->admin->user)->get(route('gestion-estadisticas'))->assertOk();
    }

    // -----------------------------------------------------------------------
    // Cancelaciones: la deuda que esta pantalla viene a cerrar
    // -----------------------------------------------------------------------

    public function test_aprobar_la_cancelacion_retira_y_libera_el_cupo(): void
    {
        $matricula = $this->matricular($this->estudiante, $this->violin, Matricula::CANCELACION_SOLICITADA);

        $this->actingAs($this->admin->user)
            ->post(route('gestion-resolver-cancelacion', [$matricula, 'aprobar']))
            ->assertSessionHas('success');

        $this->assertSame(Matricula::RETIRADA, $matricula->fresh()->estado);
        $this->assertSame(0, $this->violin->ocupadosEn($this->periodo));
    }

    /**
     * Rechazar solo cabe con menores: la pausa existe para hablar con el
     * acudiente antes de que un nino se salga por su cuenta.
     */
    public function test_la_cancelacion_de_un_menor_se_puede_rechazar(): void
    {
        $menor = $this->crearEstudiante('nino', Carbon::today()->subYears(10)->toDateString());
        $matricula = $this->matricular($menor, $this->violin, Matricula::CANCELACION_SOLICITADA);

        $this->actingAs($this->admin->user)
            ->post(route('gestion-resolver-cancelacion', [$matricula, 'rechazar']))
            ->assertSessionHas('success');

        $this->assertSame(Matricula::ACTIVA, $matricula->fresh()->estado);
    }

    /** A un mayor de edad no se le discute la decision de irse. */
    public function test_la_cancelacion_de_un_adulto_no_se_puede_rechazar(): void
    {
        $matricula = $this->matricular($this->estudiante, $this->violin, Matricula::CANCELACION_SOLICITADA);

        $this->actingAs($this->admin->user)
            ->post(route('gestion-resolver-cancelacion', [$matricula, 'rechazar']))
            ->assertSessionHas('error');

        $this->assertSame(Matricula::CANCELACION_SOLICITADA, $matricula->fresh()->estado);
    }

    public function test_aprobar_en_lote_retira_a_todos_los_marcados(): void
    {
        $otra = Promotoria::create(['nombre' => 'Piano', 'area_id' => $this->musica->id]);

        $unas = [
            $this->matricular($this->estudiante, $this->violin, Matricula::CANCELACION_SOLICITADA),
            $this->matricular($this->crearEstudiante('otro'), $otra, Matricula::CANCELACION_SOLICITADA),
        ];

        $this->actingAs($this->admin->user)
            ->post(route('gestion-cancelaciones-lote'), [
                'decision' => 'aprobar',
                'matricula_ids' => array_map(fn (Matricula $m) => $m->id, $unas),
            ])
            ->assertSessionHas('success');

        foreach ($unas as $matricula) {
            $this->assertSame(Matricula::RETIRADA, $matricula->fresh()->estado);
        }

        $this->assertSame(0, $this->violin->ocupadosEn($this->periodo));
    }

    /**
     * Rechazar en lote resuelve las de los menores y deja fuera a los mayores,
     * diciendo quienes son.
     *
     * Es la misma regla que de a una —a un mayor no se le discute la salida—
     * aplicada sobre una seleccion mezclada, que es como va a llegar de verdad:
     * quien marca «todas» no va mirando la edad fila por fila.
     */
    public function test_rechazar_en_lote_solo_toca_a_los_menores(): void
    {
        $menor = $this->crearEstudiante('nino', Carbon::today()->subYears(10)->toDateString());
        $delMenor = $this->matricular($menor, $this->violin, Matricula::CANCELACION_SOLICITADA);
        $delAdulto = $this->matricular($this->estudiante, $this->violin, Matricula::CANCELACION_SOLICITADA);

        $respuesta = $this->actingAs($this->admin->user)
            ->post(route('gestion-cancelaciones-lote'), [
                'decision' => 'rechazar',
                'matricula_ids' => [$delMenor->id, $delAdulto->id],
            ]);

        $this->assertSame(Matricula::ACTIVA, $delMenor->fresh()->estado);
        $this->assertSame(Matricula::CANCELACION_SOLICITADA, $delAdulto->fresh()->estado);

        $respuesta->assertSessionHas(
            'error',
            fn (string $mensaje) => str_contains($mensaje, $this->estudiante->nombre_completo)
        );
    }

    /** Lo que ya no esta en tramite no entra en el lote. */
    public function test_el_lote_de_cancelaciones_ignora_lo_ya_resuelto(): void
    {
        $activa = $this->matricular($this->estudiante, $this->violin, Matricula::ACTIVA);
        $enTramite = $this->matricular(
            $this->crearEstudiante('otro'),
            $this->violin,
            Matricula::CANCELACION_SOLICITADA
        );

        $this->actingAs($this->admin->user)
            ->post(route('gestion-cancelaciones-lote'), [
                'decision' => 'aprobar',
                'matricula_ids' => [$activa->id, $enTramite->id],
            ]);

        $this->assertSame(Matricula::ACTIVA, $activa->fresh()->estado);
        $this->assertSame(Matricula::RETIRADA, $enTramite->fresh()->estado);
    }

    /**
     * La ficha de la portada dice cuantas cosas hay esperando.
     *
     * Desde el 02/09/2026 se llama «Alertas y cancelaciones» y su cifra cuenta
     * las TRES cosas de esa bandeja: si contara solo las cancelaciones, diria
     * «0» teniendo veinte clases sin registrar dentro.
     */
    public function test_la_portada_avisa_de_lo_que_hay_por_atender(): void
    {
        $this->matricular($this->estudiante, $this->violin, Matricula::CANCELACION_SOLICITADA);

        $this->actingAs($this->admin->user)
            ->get(route('gestion-inicio'))
            ->assertOk()
            ->assertSee('Alertas y cancelaciones');
    }

    // -----------------------------------------------------------------------
    // Ventana de matriculas
    // -----------------------------------------------------------------------

    public function test_se_cierran_y_se_abren_las_matriculas(): void
    {
        $this->actingAs($this->admin->user)
            ->post(route('gestion-matriculas'), ['accion' => 'cerrar'])
            ->assertSessionHas('success');

        $this->assertFalse($this->periodo->fresh()->matriculas_abiertas);

        $this->actingAs($this->admin->user)->post(route('gestion-matriculas'), ['accion' => 'abrir']);

        $this->assertTrue($this->periodo->fresh()->matriculas_abiertas);
    }

    /**
     * Solo puede haber un periodo en curso: el indice unico lo impone y la
     * transaccion apaga el anterior antes de encender el nuevo.
     */
    public function test_poner_otro_periodo_en_curso_apaga_el_anterior(): void
    {
        $nuevo = Periodo::create([
            'nombre' => '2026-2',
            'fecha_inicio' => '2026-07-15',
            'fecha_fin' => '2026-12-15',
            'activo' => false,
            'matriculas_abiertas' => false,
        ]);

        $this->actingAs($this->admin->user)
            ->post(route('gestion-matriculas'), ['accion' => 'poner_en_curso', 'periodo_id' => $nuevo->id])
            ->assertSessionHas('success');

        $this->assertTrue($nuevo->fresh()->activo);
        $this->assertFalse($this->periodo->fresh()->activo);
        // Al que sale se le cierran tambien las matriculas.
        $this->assertFalse($this->periodo->fresh()->matriculas_abiertas);
    }

    // -----------------------------------------------------------------------
    // Cupos en lote
    // -----------------------------------------------------------------------

    public function test_se_reparten_los_cupos_de_todo_el_catalogo(): void
    {
        $danza = Promotoria::create(['nombre' => 'Danza', 'area_id' => $this->musica->id]);

        $this->actingAs($this->admin->user)
            ->post(route('gestion-cupos-periodo', $this->periodo), [
                "cupo_{$this->violin->id}" => '10',
                "cupo_{$danza->id}" => '',
            ])
            ->assertSessionHas('success');

        $this->assertSame(10, $this->violin->cupoEn($this->periodo));
        $this->assertNull($danza->cupoEn($this->periodo));
    }

    /** Nada a medias: si un valor viene mal, no se guarda ninguno. */
    public function test_un_cupo_invalido_no_guarda_ninguno(): void
    {
        $danza = Promotoria::create(['nombre' => 'Danza', 'area_id' => $this->musica->id]);

        $this->actingAs($this->admin->user)
            ->post(route('gestion-cupos-periodo', $this->periodo), [
                "cupo_{$this->violin->id}" => '10',
                "cupo_{$danza->id}" => 'muchos',
            ])
            ->assertSessionHas('error');

        $this->assertNull($this->violin->cupoEn($this->periodo));
    }

    /**
     * Bajar el cupo por debajo de lo ya ocupado avisa, pero guarda igual.
     *
     * El aviso se compone FUERA de la transaccion, con los ocupados leidos antes
     * de abrirla: es texto para quien acaba de guardar, no una condicion de la
     * escritura —el propio mensaje lo dice, «no se retiro a nadie»—. Esta prueba
     * fija esa doble condicion: que el aviso salga y que el cupo se guarde.
     */
    public function test_bajar_el_cupo_por_debajo_de_lo_ocupado_avisa_pero_guarda(): void
    {
        $this->matricular($this->estudiante, $this->violin, Matricula::ACTIVA);

        $respuesta = $this->actingAs($this->admin->user)
            ->post(route('gestion-cupos-periodo', $this->periodo), [
                "cupo_{$this->violin->id}" => '0',
            ]);

        $respuesta->assertSessionHas('success');
        $respuesta->assertSessionHas('error', fn (string $aviso) => str_contains($aviso, 'por debajo de las 1 matrículas ya ocupando sitio')
            && str_contains($aviso, 'No se retiró a nadie'));

        $this->assertSame(0, $this->violin->cupoEn($this->periodo));
    }

    /**
     * Ni la pantalla de cupos ni el guardado consultan una vez por promotoria.
     *
     * El guardado es el que mas importa: ese COUNT por fila corria con la
     * transaccion ABIERTA, alargando el rato que las filas de
     * `cupos_promotoria` quedan bloqueadas — y esto es «abrir matriculas», que
     * se usa el dia que mas gente esta empujando contra esas mismas filas.
     *
     * Como en el catalogo, no se fija un numero: se compara el mismo catalogo
     * con 1 promotoria y con 16, y lo que tiene que quedar plano es la cifra.
     */
    public function test_los_cupos_no_consultan_una_vez_por_promotoria(): void
    {
        $this->consultasDeCupos();

        $verUna = $this->consultasDeCupos();
        $guardarUna = $this->consultasDeCupos(guardando: true);

        for ($i = 1; $i <= 15; $i++) {
            $promotoria = Promotoria::create(['nombre' => "Taller {$i}", 'area_id' => $this->musica->id]);
            $this->matricular($this->crearEstudiante("alumno{$i}"), $promotoria, Matricula::ACTIVA);
        }

        $verDieciseis = $this->consultasDeCupos();
        $guardarDieciseis = $this->consultasDeCupos(guardando: true);

        $this->assertSame(
            $verUna,
            $verDieciseis,
            "Ver los cupos costo {$verUna} lecturas con 1 promotoria y {$verDieciseis} con 16."
        );

        $this->assertSame(
            $guardarUna,
            $guardarDieciseis,
            "Guardar costo {$guardarUna} lecturas con 1 promotoria y {$guardarDieciseis} con 16."
        );
    }

    /**
     * Cuantas LECTURAS cuesta abrir la pantalla de cupos, o guardarla.
     *
     * Se cuentan solo los SELECT para que esta prueba juzgue una sola cosa: leer
     * una vez por promotoria. Las escrituras tienen la suya
     * (`test_guardar_los_cupos_no_escribe_una_vez_por_promotoria`) desde que
     * dejaron de crecer.
     *
     * Aqui decia que meterlas en el mismo saco no valia porque «guardar
     * dieciseis cupos son dieciseis escrituras, y no hay otra forma». Si la
     * habia, y es C-05: un `upsert` masivo. El comentario razonaba sobre un
     * limite que el codigo ya no tiene.
     */
    private function consultasDeCupos(bool $guardando = false): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        if ($guardando) {
            $this->actingAs($this->admin->user)
                ->post(route('gestion-cupos-periodo', $this->periodo), [])
                ->assertRedirect();
        } else {
            $this->actingAs($this->admin->user)
                ->get(route('gestion-cupos-periodo', $this->periodo))
                ->assertOk();
        }

        $lecturas = collect(DB::getQueryLog())
            ->filter(fn (array $registro) => str_starts_with(strtolower(ltrim((string) $registro['query'])), 'select'))
            ->count();

        DB::disableQueryLog();

        return $lecturas;
    }

    /**
     * Guardar los cupos tampoco ESCRIBE una vez por promotoria (C-05).
     *
     * Es la otra mitad de la prueba de arriba, y la que faltaba: alli se
     * sacaron los COUNT de dentro de la transaccion, aqui se sacan las
     * escrituras de una en una. Importa por lo mismo y no por el reloj: cada
     * fila que la transaccion toca queda bloqueada mientras dura, y el trigger
     * de cupos lee esas filas en cada matricula que alguien intente — o sea
     * justo el dia de abrir matriculas, cuando mas gente empuja contra ellas.
     *
     * Se comprueban los DOS caminos, porque son dos sentencias distintas y cada
     * una crecia por su cuenta: poner tope (antes un `updateOrCreate` por
     * promotoria, ahora un `upsert`) y quitarlo (antes un `delete` por
     * promotoria, ahora uno con `whereIn`).
     */
    public function test_guardar_los_cupos_no_escribe_una_vez_por_promotoria(): void
    {
        $conTopeUna = $this->escriturasDeCupos(conTope: true);
        $sinTopeUna = $this->escriturasDeCupos(conTope: false);

        for ($i = 1; $i <= 15; $i++) {
            Promotoria::create(['nombre' => "Taller {$i}", 'area_id' => $this->musica->id]);
        }

        $conTopeDieciseis = $this->escriturasDeCupos(conTope: true);
        $sinTopeDieciseis = $this->escriturasDeCupos(conTope: false);

        $this->assertSame(
            $conTopeUna,
            $conTopeDieciseis,
            "Poner tope costo {$conTopeUna} escrituras con 1 promotoria y {$conTopeDieciseis} con 16."
        );

        $this->assertSame(
            $sinTopeUna,
            $sinTopeDieciseis,
            "Quitar el tope costo {$sinTopeUna} escrituras con 1 promotoria y {$sinTopeDieciseis} con 16."
        );
    }

    /**
     * Cuantas ESCRITURAS contra `cupos_promotoria` cuesta guardar la pantalla.
     *
     * Se filtra por la tabla: lo demas que escriba la peticion no es lo que esta
     * prueba juzga, y contarlo solo haria la cifra fragil.
     */
    private function escriturasDeCupos(bool $conTope): int
    {
        $campos = Promotoria::pluck('id')
            ->mapWithKeys(fn (int $id) => ["cupo_{$id}" => $conTope ? '10' : ''])
            ->all();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($this->admin->user)
            ->post(route('gestion-cupos-periodo', $this->periodo), $campos)
            ->assertRedirect();

        $escrituras = collect(DB::getQueryLog())
            ->filter(fn (array $registro) => ! str_starts_with(strtolower(ltrim((string) $registro['query'])), 'select'))
            ->filter(fn (array $registro) => str_contains((string) $registro['query'], 'cupos_promotoria'))
            ->count();

        DB::disableQueryLog();

        return $escrituras;
    }

    /** Un periodo que ya paso es historico: sus cupos no se editan. */
    public function test_no_se_editan_los_cupos_de_un_periodo_cerrado(): void
    {
        $viejo = Periodo::create([
            'nombre' => '2025-2',
            'fecha_inicio' => '2025-07-15',
            'fecha_fin' => '2025-12-15',
            'activo' => false,
            'matriculas_abiertas' => false,
        ]);

        $this->actingAs($this->admin->user)
            ->post(route('gestion-cupos-periodo', $viejo), ["cupo_{$this->violin->id}" => '5'])
            ->assertSessionHas('error');

        $this->assertNull($this->violin->cupoEn($viejo));
    }

    // -----------------------------------------------------------------------
    // Catalogo
    // -----------------------------------------------------------------------

    public function test_se_crea_un_departamento(): void
    {
        $this->actingAs($this->admin->user)
            ->post(route('area-nueva'), ['nombre' => 'Teatro'])
            ->assertRedirect(route('gestion-programas'));

        $this->assertNotNull(Area::where('nombre', 'Teatro')->first());
    }

    /**
     * La pantalla de confirmacion dice la verdad ANTES de preguntar: si algo lo
     * bloquea, no ofrece boton.
     */
    public function test_la_confirmacion_avisa_de_lo_que_bloquea_el_borrado(): void
    {
        $this->actingAs($this->admin->user)
            ->get(route('area-eliminar', $this->musica))
            ->assertOk()
            ->assertSee('No se puede eliminar')
            ->assertSee('1 promotoría', false);
    }

    public function test_no_se_borra_una_promotoria_con_matriculas(): void
    {
        $this->matricular($this->estudiante, $this->violin, Matricula::RETIRADA);

        $this->actingAs($this->admin->user)
            ->post(route('promotoria-eliminar', $this->violin))
            ->assertSessionHas('error');

        $this->assertNotNull($this->violin->fresh());
    }

    /** Un area sin promotorias si se borra, y la pantalla lo ofrece. */
    public function test_se_borra_un_departamento_vacio(): void
    {
        $vacia = Area::create(['nombre' => 'Sin nada']);

        $this->actingAs($this->admin->user)
            ->get(route('area-eliminar', $vacia))
            ->assertOk()
            ->assertSee('¿Eliminar', false);

        $this->actingAs($this->admin->user)->post(route('area-eliminar', $vacia));

        $this->assertNull($vacia->fresh());
    }

    /**
     * Borrar una promotoria arrastra sus grupos en cascada, y la confirmacion
     * tiene que decirlo: «no se puede deshacer» a secas se queda corto.
     */
    public function test_la_confirmacion_avisa_de_lo_que_se_arrastra(): void
    {
        $sinMatriculas = Promotoria::create(['nombre' => 'Tiple', 'area_id' => $this->musica->id]);

        Grupo::create([
            'promotoria_id' => $sinMatriculas->id,
            'nombre' => 'Lunes',
            'nivel' => 'basico',
            'salon' => 'A1',
            'cupo_maximo' => 5,
        ]);

        $this->actingAs($this->admin->user)
            ->get(route('promotoria-eliminar', $sinMatriculas))
            ->assertOk()
            ->assertSee('Se llevará también');
    }

    public function test_la_promotoria_nueva_vuelve_a_su_departamento(): void
    {
        $this->actingAs($this->admin->user)
            ->post(route('promotoria-nueva'), [
                'nombre' => 'Tiple',
                'area_id' => $this->musica->id,
                'profesor_id' => '',
            ])
            ->assertRedirect(route('promotorias-por-area', $this->musica));
    }

    // -----------------------------------------------------------------------
    // Usuarios
    // -----------------------------------------------------------------------

    public function test_se_crea_un_usuario_estudiante_con_sus_datos(): void
    {
        $this->actingAs($this->admin->user)
            ->post(route('usuario-nuevo'), [
                'username' => 'nuevo',
                'password' => 'secreta123',
                'rol' => 'estudiante',
                'nombre_completo' => 'Nuevo Estudiante',
                'fecha_nacimiento' => Carbon::today()->subYears(20)->toDateString(),
                'telefono' => '3001112233',
                'documento_identidad' => '99999999',
            ])
            ->assertRedirect(route('usuario-lista'));

        $perfil = Perfil::where('nombre_completo', 'Nuevo Estudiante')->first();

        $this->assertNotNull($perfil);
        $this->assertSame('99999999', $perfil->datosEstudiante->documento_identidad);
    }

    /** La regla vive en el modelo porque la minoria de edad esta en otra tabla. */
    public function test_un_estudiante_menor_necesita_acudiente(): void
    {
        $this->actingAs($this->admin->user)
            ->post(route('usuario-nuevo'), [
                'username' => 'nino',
                'password' => 'secreta123',
                'rol' => 'estudiante',
                'nombre_completo' => 'Nino Pequeno',
                'fecha_nacimiento' => Carbon::today()->subYears(9)->toDateString(),
                'telefono' => '3001112233',
                'documento_identidad' => '88888888',
            ])
            ->assertSessionHasErrors('acudiente');

        $this->assertNull(User::where('username', 'nino')->first());
    }

    public function test_editar_sin_contrasena_no_la_cambia(): void
    {
        $antes = $this->estudiante->user->password;

        $this->actingAs($this->admin->user)
            ->post(route('usuario-editar', $this->estudiante), [
                'username' => 'ana',
                'password' => '',
                'rol' => 'estudiante',
                'nombre_completo' => 'Ana Maria',
                'fecha_nacimiento' => $this->estudiante->fecha_nacimiento->toDateString(),
                'telefono' => '3009998877',
                'documento_identidad' => $this->estudiante->datosEstudiante->documento_identidad,
            ])
            ->assertRedirect(route('usuario-lista'));

        $this->assertSame($antes, $this->estudiante->fresh()->user->password);
        $this->assertSame('Ana Maria', $this->estudiante->fresh()->nombre_completo);
    }

    /**
     * Dejarla en blanco sigue valiendo, pero escribir una corta no.
     *
     * Es la pareja de la prueba de arriba y la razon por la que el campo se
     * queda en `nullable` en vez de pasar a `required`: son dos cosas distintas
     * —«no la toques» y «ponle esta»— y solo la segunda tiene que cumplir el
     * minimo. Si se confundieran, editar el telefono de alguien obligaria a
     * inventarle una contrasena nueva.
     */
    public function test_al_editar_una_contrasena_corta_se_rechaza(): void
    {
        $antes = $this->estudiante->user->password;

        $this->actingAs($this->admin->user)
            ->post(route('usuario-editar', $this->estudiante), [
                'username' => 'ana',
                'password' => 'corta7c',
                'rol' => 'estudiante',
                'nombre_completo' => 'Ana Maria',
                'fecha_nacimiento' => $this->estudiante->fecha_nacimiento->toDateString(),
                'telefono' => '3009998877',
                'documento_identidad' => $this->estudiante->datosEstudiante->documento_identidad,
            ])
            ->assertSessionHasErrors('password');

        // Y no se cambio nada mas por el camino: el nombre sigue como estaba.
        $this->assertSame($antes, $this->estudiante->fresh()->user->password);
        $this->assertNotSame('Ana Maria', $this->estudiante->fresh()->nombre_completo);
    }

    /** Una contrasena corta tampoco crea la cuenta desde Gestion. */
    public function test_al_crear_una_contrasena_corta_se_rechaza(): void
    {
        $this->actingAs($this->admin->user)
            ->post(route('usuario-nuevo'), [
                'username' => 'nuevo.corto',
                'password' => 'corta7c',
                'rol' => 'profesor',
                'nombre_completo' => 'Profe Corto',
                'fecha_nacimiento' => Carbon::today()->subYears(30)->toDateString(),
                'telefono' => '3001112233',
            ])
            ->assertSessionHasErrors('password');

        $this->assertNull(User::where('username', 'nuevo.corto')->first());
    }

    // -----------------------------------------------------------------------
    // El director no llega a administrador
    // -----------------------------------------------------------------------
    //
    // Las rutas de usuarios estan abiertas a director Y administrador, pero el
    // enrutado reserva al administrador tres pantallas: la configuracion de la
    // institucion, las estadisticas con la encuesta demografica y la descarga de
    // copias de documentos de identidad. Sin estas puertas, un director se daba
    // el rol a si mismo —o le cambiaba la clave al administrador— y esas tres
    // reservas dejaban de significar nada.

    /** @return array<string, mixed> */
    private function datosDeUsuario(array $extra = []): array
    {
        return [
            'username' => 'nuevo.usuario',
            'password' => 'secreta123',
            'rol' => 'profesor',
            'nombre_completo' => 'Nuevo Usuario',
            'fecha_nacimiento' => Carbon::today()->subYears(30)->toDateString(),
            'telefono' => '3001112233',
            ...$extra,
        ];
    }

    // -- Usuarios es SOLO DEL ADMINISTRADOR desde el 12/09/2026 --------------
    //
    // Hasta ese dia un director entraba a Gestion → Usuarios y repartia roles,
    // y estas pruebas comprobaban donde estaban sus limites DENTRO de esa
    // pantalla: no ascender a nadie a administrador, no tocar la ficha de uno.
    //
    // Ahora no entra. A las personas llega por los grupos de los departamentos
    // que administra —su ficha se abre desde el Panel— y no por un listado de
    // la institucion entera. Asi que el limite dejo de estar dentro de la
    // pantalla y pasó a ser la puerta.
    //
    // LAS REGLAS DE `Permisos` SE QUEDAN Y SE COMPRUEBAN APARTE, abajo. No son
    // codigo muerto por capricho: son la segunda cerradura del dia que alguien
    // vuelva a abrirle esta pantalla a un director, y sin prueba se irian
    // pudriendo sin que nada fallara.

    /**
     * El director rebota en TODAS las rutas de Usuarios, y no cambia nada.
     *
     * Se afirman las dos cosas. Que rebote es lo que se pidio; que no cambie
     * nada es lo que distingue una puerta cerrada de un cartel.
     *
     * OJO: el middleware `rol:` REDIRIGE, no da 403 — asi que esperar un 403
     * aqui falla con el codigo bueno. Lo comprobado es que NO sea 200.
     */
    public function test_un_director_ya_no_entra_a_usuarios(): void
    {
        $this->actingAs($this->director->user)
            ->get(route('usuario-lista'))
            ->assertRedirect();

        $this->actingAs($this->director->user)
            ->post(route('usuario-nuevo'), $this->datosDeUsuario(['username' => 'colado']))
            ->assertRedirect();

        $this->assertNull(User::where('username', 'colado')->first());

        $this->actingAs($this->director->user)
            ->post(route('usuario-editar', $this->profesor), $this->datosDeUsuario([
                'username' => 'profe',
                'password' => '',
                'rol' => 'administrador',
                'nombre_completo' => 'Profe',
            ]))
            ->assertRedirect();

        $this->assertSame('profesor', $this->profesor->fresh()->rol);

        $this->actingAs($this->director->user)
            ->post(route('usuario-alternar-activo', $this->profesor))
            ->assertRedirect();

        $this->assertTrue($this->profesor->fresh()->user->activo);
    }

    /**
     * Y las dos reglas que protegian esa pantalla siguen escritas.
     *
     * Se comprueban directamente y no por HTTP porque ya no hay HTTP que las
     * alcance. Si algun dia se le vuelve a abrir Usuarios a un director, estas
     * son las que impiden que se ascienda solo.
     */
    public function test_las_reglas_que_acotaban_al_director_siguen_en_pie(): void
    {
        $this->assertNotContains(
            'administrador',
            Permisos::rolesAsignablesPor($this->director),
            'un director volveria a poder repartir el rol de administrador'
        );

        $this->assertFalse(
            Permisos::puedeEditarUsuario($this->director, $this->admin),
            'un director volveria a poder abrir la ficha de un administrador'
        );

        // Y la contraparte, que es la mitad que importa: el administrador sigue
        // pudiendo con todo. Una restriccion que de paso le cerrara la puerta a
        // el seria peor que el problema.
        $this->assertContains('administrador', Permisos::rolesAsignablesPor($this->admin));
        $this->assertTrue(Permisos::puedeEditarUsuario($this->admin, $this->profesor));
    }

    // -- Y el administrador sigue pudiendo con todo -------------------------
    //
    // La contraparte, y es la mitad que importa: una restriccion que de paso
    // hubiera cerrado la puerta al administrador seria peor que el problema.

    public function test_el_administrador_si_crea_administradores(): void
    {
        $this->actingAs($this->admin->user)
            ->post(route('usuario-nuevo'), $this->datosDeUsuario([
                'username' => 'otro.admin',
                'rol' => 'administrador',
            ]))
            ->assertRedirect(route('usuario-lista'));

        $this->assertSame('administrador', User::where('username', 'otro.admin')->first()->perfil->rol);
    }

    public function test_el_administrador_si_edita_a_otro_administrador(): void
    {
        $otro = $this->crearPerfil('admin2', 'administrador');

        $this->actingAs($this->admin->user)
            ->post(route('usuario-editar', $otro), $this->datosDeUsuario([
                'username' => 'admin2',
                'password' => '',
                'rol' => 'administrador',
                'nombre_completo' => 'Admin Dos',
            ]))
            ->assertRedirect(route('usuario-lista'));

        $this->assertSame('Admin Dos', $otro->fresh()->nombre_completo);
    }

    public function test_el_alta_guarda_el_correo(): void
    {
        $this->actingAs($this->admin->user)
            ->post(route('usuario-nuevo'), $this->datosDeUsuario([
                'username' => 'con.correo',
                'correo' => 'profe@ejemplo.co',
            ]))
            ->assertRedirect(route('usuario-lista'));

        $this->assertSame('profe@ejemplo.co', User::where('username', 'con.correo')->first()->email);
    }

    /**
     * El correo PUEDE repetirse, y es deliberado.
     *
     * Dos hermanos matriculados comparten el de su acudiente, y en una casa de
     * la cultura ese caso es corriente. Un indice unico lo convertiria en un
     * error que la familia no sabria como resolver.
     */
    public function test_dos_cuentas_pueden_compartir_correo(): void
    {
        foreach (['hermano.uno', 'hermano.dos'] as $username) {
            $this->actingAs($this->admin->user)
                ->post(route('usuario-nuevo'), $this->datosDeUsuario([
                    'username' => $username,
                    'correo' => 'acudiente@ejemplo.co',
                ]))
                ->assertRedirect(route('usuario-lista'));
        }

        $this->assertSame(2, User::where('email', 'acudiente@ejemplo.co')->count());
    }

    /** Sin correo se guarda null, no cadena vacia. */
    public function test_el_alta_sin_correo_guarda_null(): void
    {
        $this->actingAs($this->admin->user)
            ->post(route('usuario-nuevo'), $this->datosDeUsuario(['username' => 'sin.correo']))
            ->assertRedirect(route('usuario-lista'));

        $this->assertNull(User::where('username', 'sin.correo')->first()->email);
    }

    public function test_un_correo_mal_escrito_no_crea_la_cuenta(): void
    {
        $this->actingAs($this->admin->user)
            ->post(route('usuario-nuevo'), $this->datosDeUsuario([
                'username' => 'correo.malo',
                'correo' => 'arroba-ninguna',
            ]))
            ->assertSessionHasErrors('correo');

        $this->assertNull(User::where('username', 'correo.malo')->first());
    }

    /**
     * Desactivar y no borrar: borrar el usuario se llevaria su perfil y con el
     * todo su historial de matriculas.
     */
    public function test_se_desactiva_una_cuenta(): void
    {
        $this->actingAs($this->admin->user)
            ->post(route('usuario-alternar-activo', $this->estudiante))
            ->assertSessionHas('success');

        $this->assertFalse($this->estudiante->fresh()->user->activo);
    }

    public function test_nadie_se_desactiva_a_si_mismo(): void
    {
        $this->actingAs($this->admin->user)
            ->post(route('usuario-alternar-activo', $this->admin))
            ->assertSessionHas('error');

        $this->assertTrue($this->admin->fresh()->user->activo);
    }

    /**
     * Filtrar por promotoria devuelve las dos formas de estar vinculado a ella:
     * quien la cursa y quien la dicta.
     */
    public function test_el_filtro_por_promotoria_trae_estudiantes_y_profesor(): void
    {
        $this->matricular($this->estudiante, $this->violin, Matricula::ACTIVA);

        $respuesta = $this->actingAs($this->admin->user)
            ->get(route('usuario-lista', ['promotoria' => $this->violin->id]));

        $respuesta->assertOk();
        $respuesta->assertSee('Ana');
        $respuesta->assertSee('Profe');
        $respuesta->assertDontSee('>Dire<', false);
    }

    // -----------------------------------------------------------------------
    // Corregir la promotoria de una matricula
    // -----------------------------------------------------------------------

    private function otraPromotoria(string $nombre = 'Guitarra'): Promotoria
    {
        return Promotoria::create([
            'nombre' => $nombre,
            'area_id' => $this->musica->id,
            'profesor_id' => $this->profesor->id,
        ]);
    }

    /** Mueve la fila: no retira una y crea otra. */
    public function test_corregir_mueve_la_misma_matricula(): void
    {
        $guitarra = $this->otraPromotoria();
        $m = $this->matricular($this->estudiante, $this->violin, Matricula::ACTIVA);

        $this->actingAs($this->admin->user)
            ->post(route('corregir-promotoria', $m), ['promotoria_id' => $guitarra->id])
            ->assertSessionHas('success');

        $m->refresh();
        $this->assertSame($guitarra->id, $m->promotoria_id);
        // Una sola fila: si hubiera retirado y creado, serian dos.
        $this->assertSame(1, Matricula::where('estudiante_id', $this->estudiante->id)->count());
        $this->assertSame(0, Matricula::where('estado', Matricula::RETIRADA)->count());
    }

    /**
     * Vuelve a pendiente aunque estuviera activa: confirmar es un acto de quien
     * dicta, y el profesor de la promotoria nueva no la ha visto nunca.
     */
    public function test_corregir_devuelve_la_matricula_a_pendiente(): void
    {
        $guitarra = $this->otraPromotoria();
        $m = $this->matricular($this->estudiante, $this->violin, Matricula::ACTIVA);

        $this->actingAs($this->admin->user)
            ->post(route('corregir-promotoria', $m), ['promotoria_id' => $guitarra->id]);

        $this->assertSame(Matricula::PENDIENTE, $m->refresh()->estado);
    }

    /** El grupo cuelga de la promotoria vieja: no puede sobrevivir al cambio. */
    public function test_corregir_suelta_el_grupo_asignado(): void
    {
        $guitarra = $this->otraPromotoria();
        $grupo = Grupo::create([
            'promotoria_id' => $this->violin->id, 'nombre' => 'A',
            'nivel' => 'basico', 'salon' => 'S1', 'cupo_maximo' => 10,
        ]);
        $m = $this->matricular($this->estudiante, $this->violin, Matricula::ACTIVA);
        $m->repartirEn([$grupo->id]);

        $this->actingAs($this->admin->user)
            ->post(route('corregir-promotoria', $m), ['promotoria_id' => $guitarra->id]);

        $this->assertSame(0, $m->grupos()->count(), 'se llevo el grupo a la promotoria nueva.');
    }

    /** La fecha de inscripcion es del dato original y el movimiento no la toca. */
    public function test_corregir_conserva_la_fecha_de_inscripcion(): void
    {
        $guitarra = $this->otraPromotoria();
        $m = $this->matricular($this->estudiante, $this->violin, Matricula::ACTIVA);
        $fecha = $m->fresh()->fecha;

        $this->actingAs($this->admin->user)
            ->post(route('corregir-promotoria', $m), ['promotoria_id' => $guitarra->id]);

        $m->refresh();
        // Que se movio de verdad va primero: sin esta linea la prueba pasaba
        // aunque la correccion no hiciera nada en absoluto.
        $this->assertSame($guitarra->id, $m->promotoria_id);
        $this->assertEquals($fecha, $m->fecha);
    }

    /** Un profesor no mueve estudiantes entre promotorias: es cosa de direccion. */
    public function test_un_profesor_no_puede_corregir(): void
    {
        $guitarra = $this->otraPromotoria();
        $m = $this->matricular($this->estudiante, $this->violin, Matricula::ACTIVA);

        $this->actingAs($this->profesor->user)
            ->post(route('corregir-promotoria', $m), ['promotoria_id' => $guitarra->id])
            ->assertSessionHas('error');

        $this->assertSame($this->violin->id, $m->refresh()->promotoria_id);
    }

    /** Mover una de un periodo cerrado cambiaria certificados ya emitidos. */
    public function test_no_se_corrige_una_matricula_de_un_periodo_cerrado(): void
    {
        $guitarra = $this->otraPromotoria();
        $anterior = Periodo::create([
            'nombre' => '2025-2', 'fecha_inicio' => '2025-07-01',
            'fecha_fin' => '2025-12-15', 'activo' => false, 'matriculas_abiertas' => false,
        ]);
        $m = new Matricula([
            'estudiante_id' => $this->estudiante->id,
            'promotoria_id' => $this->violin->id,
            'periodo_id' => $anterior->id,
            'estado' => Matricula::ACTIVA,
        ]);
        $m->save();

        $this->actingAs($this->admin->user)
            ->post(route('corregir-promotoria', $m), ['promotoria_id' => $guitarra->id])
            ->assertSessionHas('error');

        $this->assertSame($this->violin->id, $m->refresh()->promotoria_id);
    }

    /** Una retirada no se corrige: el estudiante ya no esta en ella. */
    /**
     * Una retirada tambien se mueve, y al moverla REVIVE: quien se salio y
     * quiere entrar a otra no esta corrigiendo un dato viejo, esta entrando.
     */
    public function test_una_matricula_retirada_se_mueve_y_revive(): void
    {
        $guitarra = $this->otraPromotoria();
        $m = $this->matricular($this->estudiante, $this->violin, Matricula::RETIRADA);

        $this->actingAs($this->admin->user)
            ->post(route('corregir-promotoria', $m), ['promotoria_id' => $guitarra->id])
            ->assertSessionHas('success');

        $m->refresh();
        $this->assertSame($guitarra->id, $m->promotoria_id);
        $this->assertSame(Matricula::PENDIENTE, $m->estado);
    }

    /**
     * Y al revivir vuelve a contar para el limite de promotorias: si el
     * estudiante ya llego al tope con otras, readmitirla no puede colarse.
     */
    public function test_revivir_una_retirada_respeta_el_limite_de_promotorias(): void
    {
        $guitarra = $this->otraPromotoria();
        $piano = $this->otraPromotoria('Piano');
        $canto = $this->otraPromotoria('Canto');

        $retirada = $this->matricular($this->estudiante, $this->violin, Matricula::RETIRADA);
        // El limite por defecto son 2, y ya las tiene ocupadas.
        $this->matricular($this->estudiante, $piano, Matricula::ACTIVA);
        $this->matricular($this->estudiante, $canto, Matricula::ACTIVA);

        $this->actingAs($this->admin->user)
            ->post(route('corregir-promotoria', $retirada), ['promotoria_id' => $guitarra->id])
            // El MOTIVO, no un error cualquiera: con solo `assertSessionHas`
            // esta prueba pasaba tambien cuando lo que fallaba era otra cosa
            // —por ejemplo un bloqueo a las retiradas—, y entonces no estaba
            // comprobando el limite en absoluto.
            ->assertSessionHas('error', fn ($mensaje) => str_contains($mensaje, 'máximo de'));

        $this->assertSame(Matricula::RETIRADA, $retirada->refresh()->estado);
    }

    /**
     * Con la ventana de matriculas CERRADA el director ya no mueve: el periodo
     * esta repartido y mover a alguien deja de ser corregir una captura.
     */
    public function test_con_la_ventana_cerrada_el_director_no_mueve(): void
    {
        $guitarra = $this->otraPromotoria();
        $m = $this->matricular($this->estudiante, $this->violin, Matricula::ACTIVA);
        $this->periodo->update(['matriculas_abiertas' => false]);

        $this->actingAs($this->director->user)
            ->post(route('corregir-promotoria', $m), ['promotoria_id' => $guitarra->id])
            ->assertSessionHas('error', fn ($mensaje) => str_contains($mensaje, 'solo el administrador'));

        $this->assertSame($this->violin->id, $m->refresh()->promotoria_id);
    }

    /** Y el administrador sí, que es justamente la excepcion. */
    public function test_con_la_ventana_cerrada_el_administrador_si_mueve(): void
    {
        $guitarra = $this->otraPromotoria();
        $m = $this->matricular($this->estudiante, $this->violin, Matricula::ACTIVA);
        $this->periodo->update(['matriculas_abiertas' => false]);

        $this->actingAs($this->admin->user)
            ->post(route('corregir-promotoria', $m), ['promotoria_id' => $guitarra->id])
            ->assertSessionHas('success');

        $this->assertSame($guitarra->id, $m->refresh()->promotoria_id);
    }

    /**
     * La pantalla y la accion comparten la regla: con la ventana cerrada el
     * director tampoco VE el panel, para que no se le ofrezca un boton que va a
     * contestarle que no.
     */
    public function test_con_la_ventana_cerrada_el_director_no_ve_el_panel(): void
    {
        $this->matricular($this->estudiante, $this->violin, Matricula::ACTIVA);
        $this->periodo->update(['matriculas_abiertas' => false]);

        $respuesta = $this->actingAs($this->director->user)
            ->get(route('historial-estudiante', $this->estudiante));

        $respuesta->assertDontSee('Mover matrícula');
        // Y en su sitio queda dicho POR QUE, no un hueco: un hueco donde antes
        // habia un boton se lee como un fallo, no como una regla.
        $respuesta->assertSee('Matrículas cerradas');
    }

    /** Al profesor no se le dice nada: nunca tuvo esa accion. */
    public function test_al_profesor_no_se_le_explica_por_que_no_puede_mover(): void
    {
        $this->matricular($this->estudiante, $this->violin, Matricula::ACTIVA);
        $this->periodo->update(['matriculas_abiertas' => false]);

        $this->actingAs($this->profesor->user)
            ->get(route('historial-estudiante', $this->estudiante))
            ->assertDontSee('Matrículas cerradas');
    }

    /**
     * El unico `(estudiante, promotoria, periodo)` cuenta TAMBIEN las retiradas,
     * asi que mover a una promotoria por la que ya paso choca contra la base. El
     * mensaje crudo de MariaDB no lo explicaria, y esta prueba es la que fija
     * que se traduzca.
     */
    public function test_corregir_avisa_si_ya_estuvo_en_la_promotoria_destino(): void
    {
        $guitarra = $this->otraPromotoria();
        $this->matricular($this->estudiante, $guitarra, Matricula::RETIRADA);
        $m = $this->matricular($this->estudiante, $this->violin, Matricula::ACTIVA);

        $this->actingAs($this->admin->user)
            ->post(route('corregir-promotoria', $m), ['promotoria_id' => $guitarra->id])
            ->assertSessionHas('error', fn ($mensaje) => str_contains($mensaje, 'ya tiene una matrícula'));

        $this->assertSame($this->violin->id, $m->refresh()->promotoria_id);
    }

    /**
     * El trigger de cupo corre tambien en UPDATE, asi que mover a una promotoria
     * llena lo rechaza el motor. Se traduce en vez de reventar.
     */
    public function test_corregir_avisa_si_la_promotoria_destino_no_tiene_cupo(): void
    {
        $guitarra = $this->otraPromotoria();
        CupoPromotoria::create([
            'promotoria_id' => $guitarra->id, 'periodo_id' => $this->periodo->id, 'cupo_maximo' => 1,
        ]);
        $this->matricular($this->crearEstudiante('lleno'), $guitarra, Matricula::ACTIVA);

        $m = $this->matricular($this->estudiante, $this->violin, Matricula::ACTIVA);

        $this->actingAs($this->admin->user)
            ->post(route('corregir-promotoria', $m), ['promotoria_id' => $guitarra->id])
            ->assertSessionHas('error', fn ($mensaje) => str_contains($mensaje, 'cupo libre'));

        $this->assertSame($this->violin->id, $m->refresh()->promotoria_id);
    }

    /** Mover a la que ya tiene no es una corrección, es un clic perdido. */
    public function test_corregir_a_la_misma_promotoria_avisa(): void
    {
        $m = $this->matricular($this->estudiante, $this->violin, Matricula::ACTIVA);

        $this->actingAs($this->admin->user)
            ->post(route('corregir-promotoria', $m), ['promotoria_id' => $this->violin->id])
            ->assertSessionHas('error');
    }

    /** El panel de corregir no se le ofrece al profesor que solo mira. */
    public function test_el_profesor_no_ve_el_panel_de_corregir(): void
    {
        $this->matricular($this->estudiante, $this->violin, Matricula::ACTIVA);

        $this->actingAs($this->profesor->user)
            ->get(route('historial-estudiante', $this->estudiante))
            ->assertDontSee('Mover matrícula');
    }

    /** Y a dirección sí. */
    public function test_direccion_ve_el_panel_de_corregir(): void
    {
        $this->matricular($this->estudiante, $this->violin, Matricula::ACTIVA);

        $this->actingAs($this->admin->user)
            ->get(route('historial-estudiante', $this->estudiante))
            ->assertSee('Mover matrícula');
    }

    /** Quien se retiro de Violin ya no es de Violin. */
    public function test_el_filtro_no_trae_a_los_retirados(): void
    {
        $this->matricular($this->estudiante, $this->violin, Matricula::RETIRADA);

        $this->actingAs($this->admin->user)
            ->get(route('usuario-lista', ['promotoria' => $this->violin->id]))
            ->assertDontSee('>Ana<', false);
    }

    // -----------------------------------------------------------------------
    // Buscador por texto del listado de usuarios
    // -----------------------------------------------------------------------

    public function test_el_buscador_encuentra_por_nombre(): void
    {
        $respuesta = $this->actingAs($this->admin->user)
            ->get(route('usuario-lista', ['buscar' => 'Ana']));

        $respuesta->assertOk();
        $respuesta->assertSee('>Ana<', false);
        $respuesta->assertDontSee('>Dire<', false);
        $respuesta->assertDontSee('>Profe<', false);
    }

    /**
     * Se busca tambien por el nombre de la cuenta, no solo por el de la persona:
     * quien administra suele acordarse del usuario antes que del nombre completo.
     */
    public function test_el_buscador_encuentra_por_nombre_de_usuario(): void
    {
        $respuesta = $this->actingAs($this->admin->user)
            ->get(route('usuario-lista', ['buscar' => 'profe']));

        $respuesta->assertSee('>Profe<', false);
        // Sin esta segunda mitad la prueba pasaba igual con el buscador
        // desconectado, porque sin filtrar sale TODO el mundo.
        $respuesta->assertDontSee('>Ana<', false);
        $respuesta->assertDontSee('>Dire<', false);
    }

    /**
     * El cotejo de la base ignora tildes y mayusculas, y de eso depende que
     * buscar «gomez» encuentre a «Gómez». Si alguien cambia el cotejo de
     * `perfiles.nombre_completo`, esta prueba es la que lo cuenta.
     */
    public function test_el_buscador_ignora_tildes_y_mayusculas(): void
    {
        $conTilde = $this->crearPerfil('jgomez', 'profesor');
        $conTilde->update(['nombre_completo' => 'Jorge Gómez']);

        $respuesta = $this->actingAs($this->admin->user)
            ->get(route('usuario-lista', ['buscar' => 'GOMEZ']));

        $respuesta->assertSee('Jorge Gómez', false);
        $respuesta->assertDontSee('>Ana<', false);
        $respuesta->assertDontSee('>Profe<', false);
    }

    /**
     * Un `%` tecleado es texto, no un comodin. Sin escapar, buscar «%» devolvia
     * la lista ENTERA — que es justo lo contrario de buscar.
     */
    public function test_el_buscador_no_trata_el_porcentaje_como_comodin(): void
    {
        $this->actingAs($this->admin->user)
            ->get(route('usuario-lista', ['buscar' => '%']))
            ->assertSee('Ningún usuario coincide con estos filtros.');
    }

    /** Y el guion bajo, que casa con cualquier letra suelta. */
    public function test_el_buscador_no_trata_el_guion_bajo_como_comodin(): void
    {
        $this->actingAs($this->admin->user)
            ->get(route('usuario-lista', ['buscar' => 'An_']))
            ->assertSee('Ningún usuario coincide con estos filtros.');
    }

    /**
     * Un espacio suelto no es un filtro: no debe encender «Limpiar».
     *
     * OJO con lo que esta prueba prueba: quien recorta la cadena por HTTP es el
     * middleware `TrimStrings` de Laravel, NO el `trim()` del controlador —
     * quitando ese `trim()` esta prueba sigue verde. Se queda porque el
     * comportamiento visible merece quedar fijado, pero no vale como guardia
     * del `trim()`, y nadie deberia creer que lo es.
     */
    public function test_un_buscador_en_blanco_no_cuenta_como_filtro(): void
    {
        $this->actingAs($this->admin->user)
            ->get(route('usuario-lista', ['buscar' => '   ']))
            ->assertDontSee('Limpiar');
    }

    /** El buscador se combina con los demas filtros, no los sustituye. */
    public function test_el_buscador_se_cruza_con_el_filtro_de_rol(): void
    {
        $otra = $this->crearPerfil('anadir', 'profesor');
        $otra->update(['nombre_completo' => 'Ana Profesora']);

        $respuesta = $this->actingAs($this->admin->user)
            ->get(route('usuario-lista', ['buscar' => 'Ana', 'rol' => 'profesor']));

        $respuesta->assertSee('Ana Profesora', false);
        // El rol deja fuera a la estudiante Ana...
        $respuesta->assertDontSee('>Ana<', false);
        // ...y el BUSCADOR deja fuera al otro profesor. Sin esta linea, el
        // filtro de rol solo explicaba el resultado y la prueba pasaba con el
        // buscador apagado.
        $respuesta->assertDontSee('>Profe<', false);
    }

    /** Lo tecleado vuelve en el campo: si no, no se sabe que se busco. */
    public function test_el_buscador_conserva_lo_tecleado(): void
    {
        $this->actingAs($this->admin->user)
            ->get(route('usuario-lista', ['buscar' => 'Ana']))
            ->assertSee('value="Ana"', false);
    }

    /**
     * Lo tecleado vuelve DENTRO de un atributo HTML, asi que una comilla o una
     * etiqueta tienen que salir escapadas. Blade lo hace solo con `{{ }}`, pero
     * el dia que alguien lo pase a `{!! !!}` para «que se vea bien» esta prueba
     * es la que lo para.
     */
    public function test_el_buscador_escapa_lo_tecleado_en_el_atributo(): void
    {
        $respuesta = $this->actingAs($this->admin->user)
            ->get(route('usuario-lista', ['buscar' => '"><script>x</script>']));

        $respuesta->assertDontSee('<script>x</script>', false);
        $respuesta->assertSee('&quot;&gt;&lt;script&gt;', false);
    }

    // -----------------------------------------------------------------------
    // Institucion
    // -----------------------------------------------------------------------

    public function test_se_guarda_la_configuracion(): void
    {
        $this->actingAs($this->admin->user)
            ->post(route('gestion-configuracion'), [
                'nombre_institucion' => 'Casa de la Cultura El Santuario',
                'color_acento' => '#0a7a59',
                'limite_promotorias_por_periodo' => 3,
                'faltas_para_abandono' => 5,
                'promotorias_visibles_para_estudiantes' => 1,
            ])
            ->assertSessionHas('success');

        $configuracion = ConfiguracionInstitucion::actual();

        $this->assertSame('Casa de la Cultura El Santuario', $configuracion->nombre_institucion);
        $this->assertSame(3, $configuracion->limite_promotorias_por_periodo);
    }

    /**
     * El contraste no bloquea —una marca clara puede ser legitima— pero el texto
     * blanco de los botones deja de leerse y hay que avisarlo.
     */
    public function test_un_acento_demasiado_claro_avisa_del_contraste(): void
    {
        $this->actingAs($this->admin->user)
            ->post(route('gestion-colores'), ['color_acento' => '#ffee00'])
            ->assertSessionHas('success')
            ->assertSessionHas('error');

        $this->assertSame('#ffee00', ConfiguracionInstitucion::actual()->color_acento);
    }

    /**
     * Un papel no se borra: los archivos que ya subieron los estudiantes cuelgan
     * de el, y borrarlo se llevaria la prueba de que en su momento cumplieron.
     */
    public function test_un_documento_requerido_se_desactiva_en_vez_de_borrarse(): void
    {
        $documento = DocumentoRequerido::create([
            'nombre' => 'Certificado de EPS',
            'obligatorio' => true,
            'activo' => true,
            'orden' => 1,
        ]);

        $this->actingAs($this->admin->user)
            ->post(route('documento-requerido-alternar', $documento))
            ->assertSessionHas('success');

        $this->assertFalse($documento->fresh()->activo);
        $this->assertNotNull($documento->fresh());
    }

    // -----------------------------------------------------------------------
    // Estadisticas
    // -----------------------------------------------------------------------

    public function test_las_estadisticas_cuentan_lo_que_hay(): void
    {
        $this->matricular($this->estudiante, $this->violin, Matricula::ACTIVA);

        EncuestaDemografica::create([
            'perfil_id' => $this->estudiante->id,
            'genero' => 'f',
            'barrio' => 'El Centro',
            'estrato' => 2,
            'nivel_educativo' => 'secundaria_com',
            'ocupacion' => 'estudiante',
            'autoriza_tratamiento_datos' => true,
        ]);

        $this->actingAs($this->admin->user)
            ->get(route('gestion-estadisticas'))
            ->assertOk()
            ->assertSee('Estudiantes por departamento y promotoría', false)
            ->assertSee('Musica');
    }

    /**
     * «Estudiantes activos» cuenta el periodo que se mira, no la historia.
     *
     * La distincion no la inventa esta prueba: la pantalla la promete en prosa
     * tres lineas encima de la cifra --«el periodo mueve las matriculas»-- y
     * esta es una cifra de matriculas. Sin el filtro contaba a quien estuvo
     * activo en CUALQUIER periodo, y la diferencia crece con cada periodo que
     * pasa.
     *
     * La matricula del periodo viejo se queda en `activa` y eso es correcto: la
     * aplicacion la ensena como «Finalizada» por `estado_visible`, y de que el
     * estado NO cambie al cerrar un periodo cuelgan la renovacion, los
     * certificados y la antiguedad. Lo que estaba mal era preguntar sin acotar.
     */
    public function test_los_estudiantes_activos_son_los_del_periodo_que_se_mira(): void
    {
        $viejo = Periodo::create([
            'nombre' => '2025-2',
            'fecha_inicio' => '2025-07-15',
            'fecha_fin' => '2025-12-15',
            'activo' => false,
            'matriculas_abiertas' => false,
        ]);

        // DOS en el periodo cerrado y UNA en el de ahora, y personas distintas
        // en cada uno. Los dos detalles compran una barrera cada uno:
        //
        //  - Personas distintas, porque con la misma en ambos el `distinct()`
        //    devolveria 1 con filtro y sin el.
        //  - Conteos distintos (2 y 1), porque con uno en cada periodo los dos
        //    lados de la prueba valen 1 y la segunda mitad no discrimina nada.
        //    Comprobado: atando la consulta al periodo EN CURSO fijo --que es
        //    el error que la segunda mitad existe para atrapar-- la prueba
        //    pasaba igual antes de desequilibrar los conteos.
        foreach (['pedro', 'lucia'] as $nombre) {
            $antiguo = $this->crearEstudiante($nombre);
            $matriculaVieja = new Matricula([
                'estudiante_id' => $antiguo->id,
                'promotoria_id' => $this->violin->id,
                'periodo_id' => $viejo->id,
                'estado' => Matricula::ACTIVA,
            ]);
            $matriculaVieja->save();
        }

        $this->matricular($this->estudiante, $this->violin, Matricula::ACTIVA);

        $respuesta = $this->actingAs($this->admin->user)
            ->get(route('gestion-estadisticas'))
            ->assertOk();

        // Uno, no tres: los dos del periodo cerrado siguen en `activa` y no se
        // cuentan aqui.
        $this->assertSame(1, $respuesta->viewData('totalEstudiantesActivos'));

        // Y al retroceder con la flecha, la cifra se mueve con ella.
        $this->actingAs($this->admin->user)
            ->get(route('gestion-estadisticas-periodo', $viejo))
            ->assertOk()
            ->assertViewHas('totalEstudiantesActivos', 2);
    }

    /**
     * La encuesta se agrega en memoria, no con nueve GROUP BY.
     *
     * La tabla se lee entera de todas formas —hace falta para contar las
     * incompletas, que no tiene una version buena en SQL—, asi que agregarla
     * ademas en el motor eran diez pasadas por la misma tabla para pintar una
     * pantalla.
     *
     * Aqui no sirve la prueba de «que no crezca» que usan el catalogo y los
     * cupos: estas nueve consultas eran una cifra fija, no una por fila. Lo que
     * se comprueba es que ya no se emitan.
     */
    public function test_las_estadisticas_no_agrupan_la_encuesta_en_sql(): void
    {
        EncuestaDemografica::create([
            'perfil_id' => $this->estudiante->id,
            'genero' => 'f',
            'barrio' => 'El Centro',
            'estrato' => 2,
            'nivel_educativo' => 'secundaria_com',
            'ocupacion' => 'estudiante',
            'autoriza_tratamiento_datos' => true,
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($this->admin->user)
            ->get(route('gestion-estadisticas'))
            ->assertOk();

        $consultas = collect(DB::getQueryLog())
            ->pluck('query')
            ->map(fn ($sql) => strtolower((string) $sql));

        DB::disableQueryLog();

        foreach (array_keys(EncuestaDemografica::OPCIONES) as $campo) {
            $this->assertFalse(
                $consultas->contains(fn (string $sql) => str_contains($sql, "group by `{$campo}`")),
                "La pantalla sigue agrupando en SQL por `{$campo}`, y la tabla de "
                .'encuestas ya estaba entera en memoria.'
            );
        }
    }

    // -----------------------------------------------------------------------
    // Satisfaccion: agregada para todos, con nombre solo para administracion
    // -----------------------------------------------------------------------

    /**
     * Deja una encuesta de satisfaccion sobre un periodo ya terminado, que es
     * como llegan de verdad: se contestan al renovar.
     */
    private function encuestar(Perfil $perfil, int $general, int $profesor, string $comentario = ''): Periodo
    {
        $anterior = Periodo::firstOrCreate(
            ['nombre' => '2025-2'],
            [
                'fecha_inicio' => '2025-07-15',
                'fecha_fin' => '2025-12-15',
                'activo' => false,
                'matriculas_abiertas' => false,
            ]
        );

        EncuestaSatisfaccion::create([
            'perfil_id' => $perfil->id,
            'periodo_id' => $anterior->id,
            'satisfaccion_general' => $general,
            'calificacion_profesor' => $profesor,
            'horario_funciono' => true,
            'recomendaria' => $general >= 3,
            'comentario' => $comentario,
        ]);

        return $anterior;
    }

    public function test_la_satisfaccion_sale_agregada(): void
    {
        $this->encuestar($this->estudiante, 5, 4, 'Todo muy bien.');
        $this->encuestar($this->crearEstudiante('samu'), 3, 3);

        $respuesta = $this->actingAs($this->admin->user)->get(route('gestion-estadisticas'));

        $respuesta->assertOk();
        $respuesta->assertSee('Satisfacción', false);
        // Media de 5 y 3.
        $respuesta->assertSee('4.0');
        $respuesta->assertSee('Todo muy bien.');
    }

    /**
     * El comentario se publica sin nombre al lado: es lo que permite que la
     * gente escriba lo que piensa.
     */
    public function test_el_comentario_no_lleva_nombre(): void
    {
        $this->encuestar($this->estudiante, 5, 5, 'Me encantó el curso.');

        $html = $this->actingAs($this->admin->user)
            ->get(route('gestion-estadisticas'))
            ->getContent();

        $comentario = strpos($html, 'Me encantó el curso.');
        $bloque = substr($html, $comentario - 400, 500);

        $this->assertStringNotContainsString('Ana', $bloque);
    }

    /**
     * El seguimiento es la excepcion deliberada al anonimato: administracion ve
     * quien lo paso mal, con su telefono, porque el motivo de recoger la
     * encuesta es poder llamarlo.
     */
    public function test_el_administrador_ve_quien_puntuo_bajo(): void
    {
        $this->encuestar($this->estudiante, 2, 1, 'No me gustó el horario.');

        $this->actingAs($this->admin->user)
            ->get(route('gestion-estadisticas'))
            ->assertOk()
            ->assertSee('Para seguimiento')
            ->assertSee('Ana')
            // Sin el telefono la lista no sirve para lo unico que la justifica.
            ->assertSee('3000000000');
    }

    /**
     * A un menor no se le llama: la conversacion es con su acudiente. Dar el
     * telefono del nino seria dar por bueno un contacto que ni la ley ni el
     * sentido comun admiten.
     */
    public function test_de_un_menor_se_da_el_telefono_del_acudiente(): void
    {
        $menor = $this->crearEstudiante('nino', Carbon::today()->subYears(11)->toDateString());

        $acudiente = Acudiente::create([
            'nombre' => 'Lucía Ortiz',
            'telefono' => '3111111111',
        ]);

        $datos = $menor->datosEstudiante;
        $datos->acudiente_id = $acudiente->id;
        $datos->save();

        $this->encuestar($menor, 1, 1, 'No me gustó.');

        $this->actingAs($this->admin->user)
            ->get(route('gestion-estadisticas'))
            ->assertOk()
            ->assertSee('Lucía Ortiz', false)
            ->assertSee('3111111111')
            ->assertSee('acudiente')
            // El telefono del propio menor no aparece.
            ->assertDontSee('3000000000');
    }

    /** Una nota de 3 es "ni bien ni mal": no pide llamar a nadie. */
    public function test_una_nota_media_no_entra_en_el_seguimiento(): void
    {
        $this->encuestar($this->estudiante, 3, 3);

        $this->actingAs($this->admin->user)
            ->get(route('gestion-estadisticas'))
            ->assertOk()
            ->assertSee('No hay a quién llamar', false)
            ->assertDontSee('3000000000');
    }

    public function test_sin_respuestas_la_pantalla_lo_dice_y_no_se_cae(): void
    {
        $this->actingAs($this->admin->user)
            ->get(route('gestion-estadisticas'))
            ->assertOk()
            ->assertSee('Todavía nadie ha contestado la encuesta de satisfacción', false);
    }

    /**
     * Sin periodo EN CURSO se cae al mas reciente, no a una pantalla vacia.
     *
     * Entre dos semestres no hay ninguno activo, y en ese hueco lo que se quiere
     * mirar es justamente el que acaba de terminar. Es lo mismo que ya hace la
     * pantalla de cupos, para que las dos se comporten igual.
     */
    public function test_sin_periodo_en_curso_las_estadisticas_ensenan_el_mas_reciente(): void
    {
        $this->periodo->activo = false;
        $this->periodo->save();

        $this->actingAs($this->admin->user)
            ->get(route('gestion-estadisticas'))
            ->assertOk()
            ->assertSee($this->periodo->nombre)
            ->assertDontSee('no hay nada que medir');
    }

    /** Sin NINGUN periodo si no hay nada que medir, y la pantalla no se cae. */
    public function test_las_estadisticas_abren_sin_ningun_periodo(): void
    {
        Matricula::query()->delete();
        Periodo::query()->delete();

        $this->actingAs($this->admin->user)
            ->get(route('gestion-estadisticas'))
            ->assertOk()
            ->assertSee('no hay nada que medir');
    }

    // -----------------------------------------------------------------------
    // Filtros del listado de grupos
    // -----------------------------------------------------------------------

    /**
     * Monta un segundo departamento con su promotoria, su profesor y su grupo,
     * para que cada filtro tenga algo que dejar fuera.
     *
     * @return array{grupo: Grupo, otroGrupo: Grupo, otroProfesor: Perfil, danza: Area}
     */
    private function montarCatalogoParaFiltrar(): array
    {
        $danza = Area::create(['nombre' => 'Danza']);
        $otroProfesor = $this->crearPerfil('profe2', 'profesor');

        $ballet = Promotoria::create([
            'nombre' => 'Ballet',
            'area_id' => $danza->id,
            'profesor_id' => $otroProfesor->id,
        ]);

        return [
            'grupo' => Grupo::create([
                'promotoria_id' => $this->violin->id,
                'nombre' => 'Lunes tarde',
                'nivel' => 'basico',
                'salon' => 'Salon 1',
                'cupo_maximo' => 10,
            ]),
            'otroGrupo' => Grupo::create([
                'promotoria_id' => $ballet->id,
                'nombre' => 'Martes tarde',
                'nivel' => 'basico',
                'salon' => 'Salon 2',
                'cupo_maximo' => 10,
            ]),
            'otroProfesor' => $otroProfesor,
            'danza' => $danza,
        ];
    }

    /**
     * El desplegable de grupos del listado de usuarios.
     *
     * Componia «nombre · horario» a mano, y el horario se DERIVA de las
     * sesiones: un grupo al que todavia no le han puesto el suyo salia
     * «Lunes tarde · Básico ·», con el separador colgando. Ahora sale del
     * rotulo del modelo, que deja caer las partes vacias.
     */
    public function test_un_grupo_sin_horario_no_cuelga_el_separador_en_el_filtro(): void
    {
        $this->montarCatalogoParaFiltrar();

        $this->actingAs($this->admin->user)
            ->get(route('usuario-lista'))
            ->assertOk()
            ->assertSee('Lunes tarde · Básico')
            ->assertDontSee('Lunes tarde · Básico ·');
    }

    // -----------------------------------------------------------------------
    // Eliminar una cuenta
    // -----------------------------------------------------------------------

    /** Alguien que se registro por el enlace de profesor: sin rol y sin nada colgando. */
    private function cuentaPendiente(string $username = 'confundido'): Perfil
    {
        return $this->crearPerfil($username, '');
    }

    public function test_el_administrador_elimina_una_cuenta_sin_historial(): void
    {
        $suelta = $this->cuentaPendiente();
        $userId = $suelta->user_id;

        $this->actingAs($this->admin->user)
            ->post(route('usuario-eliminar', $suelta), ['password' => 'x'])
            ->assertRedirect(route('usuario-lista'));

        // La cuenta Y el perfil: `perfiles.user_id` va en cascada.
        $this->assertNull(User::find($userId));
        $this->assertNull(Perfil::find($suelta->id));
    }

    /**
     * La barrera que sostiene todo lo demas.
     *
     * En el esquema `matriculas.estudiante_id` es CASCADE: la base borra el
     * historial sin una queja. Desde el 30/09/2026 una cuenta con matriculas
     * SI se puede «eliminar», pero lo que pasa es que se ANONIMIZA
     * (`SupresionDeDatos`): el perfil y sus matriculas tienen que seguir ahi.
     * El detalle de lo que se borra esta en `SupresionDeDatosTest`.
     */
    public function test_una_cuenta_con_matriculas_se_anonimiza_y_no_pierde_el_historial(): void
    {
        $estudiante = $this->crearEstudiante('conhistorial');
        $this->matricular($estudiante, $this->violin, Matricula::ACTIVA);

        $this->actingAs($this->admin->user)
            ->post(route('usuario-eliminar', $estudiante), ['password' => 'x'])
            ->assertRedirect(route('usuario-lista'));

        $this->assertNotNull(Perfil::find($estudiante->id)?->suprimido_en);
        $this->assertSame(1, Matricula::count());
    }

    public function test_no_se_elimina_a_quien_dicta_una_promotoria(): void
    {
        $this->violin->profesor_id = $this->profesor->id;
        $this->violin->save();

        $this->actingAs($this->admin->user)
            ->post(route('usuario-eliminar', $this->profesor), ['password' => 'x']);

        $this->assertNotNull(Perfil::find($this->profesor->id));
    }

    public function test_sin_la_contrasena_correcta_no_se_elimina_nada(): void
    {
        $suelta = $this->cuentaPendiente();

        $this->actingAs($this->admin->user)
            ->post(route('usuario-eliminar', $suelta), ['password' => 'la-que-no-es'])
            ->assertSessionHasErrors('password');

        $this->assertNotNull(Perfil::find($suelta->id));
    }

    /**
     * Con OTRO administrador de por medio, a proposito.
     *
     * La primera version de esta prueba dejaba un solo administrador y pasaba
     * igual con la comprobacion quitada: lo que la salvaba era otra guarda, no
     * esta. Con dos administradores, lo unico que impide el borrado es que la
     * cuenta sea la de quien pulsa.
     */
    public function test_el_administrador_no_se_elimina_a_si_mismo(): void
    {
        $this->crearPerfil('admin2', 'administrador');

        $this->actingAs($this->admin->user)
            ->post(route('usuario-eliminar', $this->admin), ['password' => 'x']);

        $this->assertNotNull(Perfil::find($this->admin->id));
    }

    /**
     * La propiedad que importa: el sistema no se queda sin administradores.
     *
     * No la sostiene ninguna comprobacion que se llame asi —se escribio una y
     * resulto inalcanzable, ver `UsuarioController::impedimento()`—, sino dos
     * cosas juntas: a la ruta solo llega un administrador, y nadie puede
     * borrarse a si mismo. Con dos, uno puede eliminar al otro; el que queda ya
     * no tiene a quien pedirselo ni puede hacerlo el.
     */
    public function test_nunca_se_llega_a_cero_administradores(): void
    {
        $otro = $this->crearPerfil('admin2', 'administrador');

        $this->actingAs($this->admin->user)
            ->post(route('usuario-eliminar', $otro), ['password' => 'x']);
        $this->assertNull(Perfil::find($otro->id));

        // Queda uno, y ese uno es el unico que puede entrar a esta ruta.
        $this->actingAs($this->admin->user)
            ->post(route('usuario-eliminar', $this->admin), ['password' => 'x']);

        $this->assertSame(1, Perfil::where('rol', 'administrador')->count());
    }

    /**
     * Cinco intentos de contrasena por minuto, como el propio inicio de sesion.
     *
     * La contrasena es lo UNICO que separa una sesion prestada de un borrado
     * permanente. Sin tope se puede tantear desde dentro todas las veces que
     * haga falta, con la cuenta ya abierta y sin que nadie se entere.
     */
    public function test_el_borrado_se_corta_tras_cinco_intentos_de_contrasena(): void
    {
        $suelta = $this->cuentaPendiente();

        for ($n = 1; $n <= 5; $n++) {
            $this->actingAs($this->admin->user)
                ->post(route('usuario-eliminar', $suelta), ['password' => "intento{$n}"])
                ->assertSessionHasErrors('password');
        }

        // El sexto ya no llega al controlador: contesta el limitador, y por eso
        // el aviso va como `error` de sesion y no colgado del campo.
        $this->actingAs($this->admin->user)
            ->post(route('usuario-eliminar', $suelta), ['password' => 'x'])
            ->assertSessionHasNoErrors();

        // Y la cuenta sigue ahi pese a que la sexta llevaba la contrasena BUENA:
        // es lo que prueba que el tope cortó antes de comprobarla.
        $this->assertNotNull(Perfil::find($suelta->id));
    }

    /** El borrado es del administrador y de nadie mas: la puerta es la ruta. */
    public function test_el_director_no_puede_eliminar_cuentas(): void
    {
        $suelta = $this->cuentaPendiente();

        // El middleware de rol no contesta 403: devuelve a la portada con el
        // aviso, que es como este sistema rechaza por rol en todas partes.
        $this->actingAs($this->director->user)
            ->get(route('usuario-eliminar', $suelta))
            ->assertRedirect(route('post-login'));

        $this->actingAs($this->director->user)
            ->post(route('usuario-eliminar', $suelta), ['password' => 'x'])
            ->assertRedirect(route('post-login'));

        $this->assertNotNull(Perfil::find($suelta->id));
    }

    /**
     * La pantalla dice la verdad ANTES de preguntar: si esta protegida, no
     * ofrece ni el boton ni el campo de contrasena.
     */
    public function test_la_pantalla_de_confirmacion_no_pregunta_cuando_no_se_puede(): void
    {
        // Lo que protege una cuenta ya no son sus matriculas (esas se
        // anonimizan) sino lo que tiene A SU CARGO.
        $this->violin->profesor_id = $this->profesor->id;
        $this->violin->save();

        $this->actingAs($this->admin->user)
            ->get(route('usuario-eliminar', $this->profesor))
            ->assertOk()
            ->assertSee('No se puede eliminar esta cuenta')
            ->assertDontSee('name="password"', false);
    }

    /** Y en el listado tampoco es un enlace, para no hacer perder el viaje. */
    public function test_el_listado_apaga_eliminar_en_una_cuenta_protegida(): void
    {
        $this->violin->profesor_id = $this->profesor->id;
        $this->violin->save();
        $suelta = $this->cuentaPendiente();

        $html = $this->actingAs($this->admin->user)
            ->get(route('usuario-lista'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(route('usuario-eliminar', $suelta), $html);
        $this->assertStringNotContainsString(route('usuario-eliminar', $this->profesor), $html);
    }

    // -----------------------------------------------------------------------
    // El modal y la vuelta al listado filtrado
    // -----------------------------------------------------------------------

    /**
     * El modal se lleva ESA tarjeta y ninguna otra.
     *
     * Es un contrato entre la plantilla y `acciones.js`: si el marcador
     * desaparece de la vista, el modal no encuentra que ensenar y el enlace pasa
     * a navegar como antes. No se rompe nada visible, que es justo por lo que
     * conviene que haya una prueba.
     */
    public function test_las_confirmaciones_traen_el_marcador_que_busca_el_modal(): void
    {
        $suelta = $this->cuentaPendiente();

        $this->actingAs($this->admin->user)
            ->get(route('usuario-eliminar', $suelta))
            ->assertOk()
            ->assertSee('data-modal-cuerpo', false);

        // Y la de los catalogos, que es la que usan los otros seis sitios.
        $this->actingAs($this->admin->user)
            ->get(route('area-eliminar', $this->musica))
            ->assertOk()
            ->assertSee('data-modal-cuerpo', false);
    }

    /**
     * Borrar devuelve al listado TAL COMO ESTABA.
     *
     * Sin esto, quien filtra por «Pendiente de rol» y borra a uno aparece en la
     * lista entera y por el principio, asi que para borrar a cinco hay que
     * volver a filtrar cinco veces. Es lo mismo que `alternarActivo` resolvio
     * con `back()`; aqui no vale porque en medio esta la confirmacion.
     */
    public function test_al_eliminar_se_vuelve_al_listado_con_sus_filtros(): void
    {
        $suelta = $this->cuentaPendiente();

        $filtrada = route('usuario-lista', ['rol' => UsuarioController::ROL_PENDIENTE, 'page' => 2]);

        // La confirmacion recoge los filtros del referente y los deja en el
        // formulario.
        $this->actingAs($this->admin->user)
            ->get(route('usuario-eliminar', $suelta), ['referer' => $filtrada])
            ->assertOk()
            ->assertSee('name="volver" value="rol=__sin__&amp;page=2"', false);

        $this->actingAs($this->admin->user)
            ->post(route('usuario-eliminar', $suelta), [
                'password' => 'x',
                'volver' => 'rol=__sin__&page=2',
            ])
            ->assertRedirect($filtrada);
    }

    /** Y tambien cuando se NIEGA: el viaje perdido no debe costar el filtro. */
    public function test_un_borrado_rechazado_tambien_vuelve_al_listado_filtrado(): void
    {
        $this->violin->profesor_id = $this->profesor->id;
        $this->violin->save();

        $this->actingAs($this->admin->user)
            ->post(route('usuario-eliminar', $this->profesor), [
                'password' => 'x',
                'volver' => 'rol=profesor',
            ])
            ->assertRedirect(route('usuario-lista', ['rol' => 'profesor']))
            ->assertSessionHas('error');
    }

    /**
     * El campo oculto no es una puerta para mandar a nadie fuera.
     *
     * Lo compone quien quiera, y ademas en una pantalla donde la persona acaba
     * de escribir su contrasena. El destino lo pone siempre el servidor; ver
     * `Support\Regreso` y sus pruebas.
     */
    public function test_el_campo_de_vuelta_no_saca_del_sitio(): void
    {
        $suelta = $this->cuentaPendiente();

        $respuesta = $this->actingAs($this->admin->user)
            ->post(route('usuario-eliminar', $suelta), [
                'password' => 'x',
                'volver' => 'https://malo.example.com',
            ]);

        $this->assertStringStartsWith(route('usuario-lista'), $respuesta->headers->get('Location'));
    }

    // -----------------------------------------------------------------------
    // Los formularios de catalogo, tambien en modal
    // -----------------------------------------------------------------------

    /** El enlace de esa fila o ese boton, tal como salio pintado. */
    private function enlaceQueApunta(string $html, string $url): string
    {
        preg_match('/<a [^>]*href="'.preg_quote($url, '/').'"[^>]*>/', $html, $coincidencia);

        return $coincidencia[0] ?? '';
    }

    public function test_el_formulario_de_catalogo_lo_puede_llevar_el_modal(): void
    {
        $this->actingAs($this->admin->user)
            ->get(route('area-nueva'))
            ->assertOk()
            ->assertSee('data-modal-cuerpo', false)
            // El titulo va DENTRO de la tarjeta: suelto, en el modal quedaria
            // flotando sobre el fondo oscurecido.
            ->assertSee('<h2 style="margin-top:0;">', false);
    }

    public function test_crear_y_editar_un_departamento_abren_en_modal(): void
    {
        $html = $this->actingAs($this->admin->user)
            ->get(route('area-lista'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            'data-modal',
            $this->enlaceQueApunta($html, route('area-nueva'))
        );
        $this->assertStringContainsString(
            'data-modal',
            $this->enlaceQueApunta($html, route('area-editar', $this->musica))
        );
    }

    /**
     * El grupo NO, y es el motivo de que esto sea un interruptor.
     *
     * Su horario es una rejilla de siete dias con dos horas cada uno: media
     * pantalla. Ojo con lo que comprueba esta prueba — el listado de grupos
     * SIGUE teniendo `data-modal` en «Eliminar», porque borrar solo es una
     * pregunta. Lo que no lo lleva es el formulario.
     */
    public function test_el_formulario_de_grupo_no_abre_en_modal(): void
    {
        $grupo = $this->grupoDeViolin();

        $html = $this->actingAs($this->admin->user)
            ->get(route('grupo-lista'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(
            'data-modal',
            $this->enlaceQueApunta($html, route('grupo-editar', $grupo))
        );
        $this->assertStringNotContainsString(
            'data-modal',
            $this->enlaceQueApunta($html, route('grupo-nuevo'))
        );

        // Pero borrarlo sigue siendo una pregunta, y esa sí.
        $this->assertStringContainsString(
            'data-modal',
            $this->enlaceQueApunta($html, route('grupo-eliminar', $grupo))
        );

        // Y su formulario tampoco se anuncia como llevable: la plantilla es
        // compartida, asi que el marcador tiene que colgar del mismo
        // interruptor. Si no, quedaria diciendo que cabe en un modal un
        // formulario que hemos decidido que no.
        $this->actingAs($this->admin->user)
            ->get(route('grupo-editar', $grupo))
            ->assertOk()
            ->assertDontSee('data-modal-cuerpo', false);
    }

    /**
     * La lista de la que se viene es «Programas formativos» desde el
     * 01/09/2026: ahi es donde vive el catalogo de departamentos, y `Regreso`
     * solo conserva la consulta si el camino del referente coincide con el del
     * destino. Con el listado plano puesto aqui, esta prueba pasaria en verde
     * sin comprobar nada — el referente no cuadraria y `volver` saldria vacio.
     */
    public function test_al_editar_un_catalogo_se_vuelve_a_la_lista_con_sus_filtros(): void
    {
        $this->actingAs($this->admin->user)
            ->get(route('area-editar', $this->musica), ['referer' => route('gestion-programas', ['page' => 2])])
            ->assertOk()
            ->assertSee('name="volver" value="page=2"', false);

        $this->actingAs($this->admin->user)
            ->post(route('area-editar', $this->musica), [
                'nombre' => 'Música y sonido',
                'volver' => 'page=2',
            ])
            ->assertRedirect(route('gestion-programas', ['page' => 2]));
    }

    // -----------------------------------------------------------------------
    // El enlace de registro de profesor
    // -----------------------------------------------------------------------

    /**
     * Fuera del formulario publico: es la causa de que unos estudiantes
     * acabaran registrados como personal sin rol.
     */
    public function test_el_login_ya_no_ofrece_el_registro_de_profesor(): void
    {
        $html = $this->get(route('login'))->assertOk()->getContent();

        $this->assertStringNotContainsString(route('registro'), $html);
        // El del estudiante sigue donde estaba: no se cerro la puerta publica,
        // se quito la que no debia estar.
        $this->assertStringContainsString(route('inscripcion'), $html);
    }

    /** Pero la ruta sigue viva: es un enlace que se manda, no una puerta cerrada. */
    public function test_el_registro_de_profesor_sigue_abierto_por_url(): void
    {
        $this->get(route('registro'))->assertOk();
    }

    public function test_gestion_ensena_el_enlace_para_mandarselo_al_profesor(): void
    {
        $this->actingAs($this->admin->user)
            ->get(route('usuario-lista'))
            ->assertOk()
            ->assertSee(route('registro'));
    }

    // -----------------------------------------------------------------------
    // El rol de una cuenta pendiente
    // -----------------------------------------------------------------------

    /**
     * Sin la opcion vacia ninguna quedaba marcada y el navegador preseleccionaba
     * la PRIMERA, que para un administrador es «Administrador»: abrir la ficha
     * de quien espera su rol y guardar lo ascendia sin que nadie lo pidiera.
     */
    public function test_el_formulario_de_un_pendiente_no_preselecciona_ningun_rol(): void
    {
        $suelta = $this->cuentaPendiente();

        $html = $this->actingAs($this->admin->user)
            ->get(route('usuario-editar', $suelta))
            ->assertOk()
            ->getContent();

        $select = $this->selectDeRol($html);

        $this->assertStringContainsString('<option value="" selected disabled>', $select);
        // Y ningun rol de verdad viene marcado.
        $this->assertDoesNotMatchRegularExpression('/<option value="[a-z]+"\s+selected/', $select);
    }

    /** En una cuenta que ya tiene rol, esa opcion no se pinta: seria una opcion muerta. */
    public function test_una_cuenta_con_rol_no_ensena_la_opcion_vacia(): void
    {
        $html = $this->actingAs($this->admin->user)
            ->get(route('usuario-editar', $this->profesor))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Sin rol asignado', $html);
    }

    private function selectDeRol(string $html): string
    {
        preg_match('/<select name="rol".*?<\/select>/s', $html, $coincidencia);

        return $coincidencia[0] ?? '';
    }

    public function test_los_grupos_se_filtran_por_departamento(): void
    {
        $c = $this->montarCatalogoParaFiltrar();

        $this->actingAs($this->admin->user)
            ->get(route('grupo-lista', ['area' => $c['danza']->id]))
            ->assertOk()
            // La FILA, no el nombre suelto: «Violin» aparece igual como opcion
            // del propio desplegable de promotorias.
            ->assertSee('Ballet - Martes tarde')
            ->assertDontSee('Violin - Lunes tarde');
    }

    public function test_los_grupos_se_filtran_por_promotoria(): void
    {
        $this->montarCatalogoParaFiltrar();

        $this->actingAs($this->admin->user)
            ->get(route('grupo-lista', ['promotoria' => $this->violin->id]))
            ->assertOk()
            ->assertSee('Violin - Lunes tarde')
            ->assertDontSee('Ballet - Martes tarde');
    }

    public function test_los_grupos_se_filtran_por_profesor(): void
    {
        $c = $this->montarCatalogoParaFiltrar();

        $this->actingAs($this->admin->user)
            ->get(route('grupo-lista', ['profesor' => $c['otroProfesor']->id]))
            ->assertOk()
            ->assertSee('Ballet - Martes tarde')
            ->assertDontSee('Violin - Lunes tarde');
    }

    /**
     * «Sin asignar» no es un adorno del desplegable.
     *
     * Una promotoria sin nadie asignado es aquella en la que NADIE puede
     * registrar clases: poder listar sus grupos de un vistazo es lo que
     * convierte un hueco del catalogo en una tarea.
     */
    public function test_los_grupos_se_filtran_por_promotoria_sin_profesor(): void
    {
        $this->montarCatalogoParaFiltrar();

        $huerfana = Promotoria::create(['nombre' => 'Titeres', 'area_id' => $this->musica->id]);
        Grupo::create([
            'promotoria_id' => $huerfana->id,
            'nombre' => 'Viernes tarde',
            'nivel' => 'basico',
            'salon' => 'Salon 3',
            'cupo_maximo' => 10,
        ]);

        $this->actingAs($this->admin->user)
            ->get(route('grupo-lista', ['profesor' => GrupoController::PROFESOR_SIN_ASIGNAR]))
            ->assertOk()
            ->assertSee('Titeres - Viernes tarde')
            ->assertDontSee('Ballet - Martes tarde');
    }

    /**
     * Desde Gestion tambien se crean varios grupos del mismo nivel.
     *
     * Es el mismo caso que en el Panel, y hace falta probarlo aparte porque son
     * dos formularios distintos con dos juegos de reglas: la regla vieja —un
     * nivel por promotoria— estaba escrita en los dos sitios, y quitarla de uno
     * solo dejaba el otro cerrado.
     */
    public function test_gestion_crea_varios_grupos_del_mismo_nivel(): void
    {
        $this->actingAs($this->admin->user)->post(route('grupo-nuevo'), [
            'promotoria_id' => $this->violin->id,
            'nombre' => 'Lunes tarde',
            'nivel' => 'basico',
            'sesiones' => [1 => ['activo' => 1, 'desde' => '16:00', 'hasta' => '18:00']],
            'salon' => 'Salon 1',
            'cupo_maximo' => 10,
        ]);

        $this->actingAs($this->admin->user)->post(route('grupo-nuevo'), [
            'promotoria_id' => $this->violin->id,
            'nombre' => 'Jueves tarde',
            'nivel' => 'basico',
            'sesiones' => [4 => ['activo' => 1, 'desde' => '16:00', 'hasta' => '18:00']],
            'salon' => 'Salon 1',
            'cupo_maximo' => 10,
        ]);

        $this->assertSame(2, $this->violin->grupos()->count());
        $this->assertSame(
            ['Jueves tarde', 'Lunes tarde'],
            $this->violin->grupos()->orderBy('nombre')->pluck('nombre')->all()
        );
    }

    public function test_gestion_rechaza_un_nombre_de_grupo_repetido(): void
    {
        Grupo::create([
            'promotoria_id' => $this->violin->id,
            'nombre' => 'Lunes tarde',
            'nivel' => 'basico',
            'salon' => 'Salon 1',
            'cupo_maximo' => 10,
        ]);

        $this->actingAs($this->admin->user)
            ->post(route('grupo-nuevo'), [
                'promotoria_id' => $this->violin->id,
                'nombre' => 'Lunes tarde',
                'nivel' => 'avanzado',
                'sesiones' => [4 => ['activo' => 1, 'desde' => '16:00', 'hasta' => '18:00']],
                'salon' => 'Salon 2',
                'cupo_maximo' => 10,
            ])
            ->assertSessionHasErrors('nombre');

        $this->assertSame(1, $this->violin->grupos()->count());
    }

    /**
     * La garantia de verdad esta en el motor, no en el formulario.
     *
     * Se comprueban las dos caras: que el unico por NOMBRE existe —es lo que
     * impide dos grupos indistinguibles si algo se salta la validacion— y que
     * el viejo por NIVEL ya no esta, porque mientras siguiera ahi la base
     * rechazaria el segundo Basico por su cuenta, con la aplicacion diciendo
     * que si.
     */
    public function test_el_esquema_limita_el_nombre_y_ya_no_el_nivel(): void
    {
        $indices = collect(DB::select(
            "SELECT indexname FROM pg_indexes WHERE schemaname = current_schema() AND tablename = 'grupos'"
        ))->pluck('indexname')->unique();

        $this->assertTrue($indices->contains('un_nombre_por_promotoria'));
        $this->assertFalse($indices->contains('un_nivel_por_promotoria'));
    }

    /** Los filtros se combinan: departamento Y profesor a la vez. */
    public function test_los_filtros_de_grupos_se_combinan(): void
    {
        $c = $this->montarCatalogoParaFiltrar();

        // Danza + el profesor de Violin: no existe esa combinacion.
        $this->actingAs($this->admin->user)
            ->get(route('grupo-lista', [
                'area' => $c['danza']->id,
                'profesor' => $this->profesor->id,
            ]))
            ->assertOk()
            ->assertSee('Ninguno coincide con estos filtros', false);
    }

    /**
     * Sin nada y sin coincidencias son dos mensajes distintos.
     *
     * «No hay grupos» manda a crear uno; «ninguno coincide» manda a soltar el
     * filtro. Con el mismo texto, quien filtra de mas cree que borro el catalogo.
     */
    public function test_sin_grupos_y_sin_coincidencias_dicen_cosas_distintas(): void
    {
        $this->actingAs($this->admin->user)
            ->get(route('grupo-lista'))
            ->assertOk()
            ->assertSee('Todavía no hay nada aquí', false);
    }

    /** Los otros catalogos no pintan barra de filtros. */
    public function test_los_otros_catalogos_siguen_sin_filtros(): void
    {
        $this->actingAs($this->admin->user)
            ->get(route('area-lista'))
            ->assertOk()
            ->assertDontSee('class="filtros"', false);
    }

    // -----------------------------------------------------------------------
    // Los tres indicadores nuevos del tablero
    // -----------------------------------------------------------------------

    /** Registra $cuantas clases del grupo, y confirma las primeras $verificadas. */
    private function dictar(Grupo $grupo, int $cuantas, int $verificadas = 0): void
    {
        $inscritos = $grupo->matriculas()
            ->whereIn('matriculas.estado', Matricula::ESTADOS_INSCRITO)
            ->get();

        for ($n = 1; $n <= $cuantas; $n++) {
            $clase = Clase::create([
                'grupo_id' => $grupo->id,
                'periodo_id' => $this->periodo->id,
                'fecha_hora' => Carbon::today()->subDays($n)->setTime(10, 0),
                'registrada_por_id' => $this->profesor->id,
                'confirmaciones_requeridas' => 1,
            ]);

            if ($n <= $verificadas && $inscritos->isNotEmpty()) {
                ConfirmacionClase::create([
                    'clase_id' => $clase->id,
                    'matricula_id' => $inscritos->first()->id,
                ]);
            }
        }
    }

    private function grupoDeViolin(): Grupo
    {
        return Grupo::create([
            'promotoria_id' => $this->violin->id,
            'nombre' => 'Lunes 4-6 p. m.',
            'nivel' => 'basico',
            'salon' => 'Salon 1',
            'cupo_maximo' => 20,
        ]);
    }

    public function test_el_mapa_de_actividad_cuenta_las_clases_del_periodo(): void
    {
        $this->dictar($this->grupoDeViolin(), 3);

        $this->actingAs($this->admin->user)
            ->get(route('gestion-estadisticas'))
            ->assertOk()
            ->assertSee('Actividad de la institución', false)
            ->assertSee('Clases dictadas')
            ->assertSee('Días de la semana con más clase', false);
    }

    /**
     * El ranking de profesores enseña las verificadas al lado de las dictadas.
     *
     * No es un adorno: registrar una clase es apretar un boton y quien lo aprieta
     * es parte interesada. Sin la segunda cifra, el ranking premiaria a quien mas
     * veces lo pulsa, que no es lo mismo que quien mas clases dio.
     */
    public function test_el_ranking_de_profesores_separa_dictadas_de_verificadas(): void
    {
        $grupo = $this->grupoDeViolin();
        $matricula = $this->matricular($this->estudiante, $this->violin, Matricula::ACTIVA);
        $matricula->repartirEn([$grupo->id]);

        $this->dictar($grupo, 4, verificadas: 2);

        $this->actingAs($this->admin->user)
            ->get(route('gestion-estadisticas'))
            ->assertOk()
            ->assertSee('Profesores con más clases', false)
            ->assertSee('Profe')
            ->assertSee('(50%)', false);
    }

    /**
     * Con un empate en el corte, entra quien va antes por NOMBRE.
     *
     * El ranking enseña diez y aqui hay once con una clase cada uno. Antes el
     * empate lo resolvia el orden en que llegaban las clases, y al pasar a
     * PostgreSQL cada motor dejaba fuera a uno distinto. Decision del usuario
     * (01/10/2026): entre empatados, por nombre.
     *
     * Se crean en orden alfabetico INVERSO a proposito: si se crearan en orden,
     * el orden de llegada y el del nombre coincidirian y la prueba pasaria sin
     * el desempate.
     */
    public function test_el_ranking_de_profesores_desempata_por_nombre(): void
    {
        $nombres = ['Zoila', 'Yuri', 'Ximena', 'Wilson', 'Valeria', 'Ursula',
            'Tomas', 'Sara', 'Rocio', 'Pablo', 'Anabel'];

        foreach ($nombres as $i => $nombre) {
            $profe = $this->crearPerfil(Str::lower($nombre), 'profesor');
            $profe->update(['nombre_completo' => "{$nombre} Rankin"]);
            $promotoria = Promotoria::create([
                'nombre' => "Taller {$i}",
                'area_id' => $this->violin->area_id,
                'profesor_id' => $profe->id,
            ]);
            $this->dictar(Grupo::create([
                'promotoria_id' => $promotoria->id,
                'nombre' => 'Unico',
                'nivel' => 'basico',
                'salon' => 'Salon 1',
                'cupo_maximo' => 20,
            ]), 1);
        }

        $this->actingAs($this->admin->user)
            ->get(route('gestion-estadisticas'))
            ->assertOk()
            ->assertSee('Anabel Rankin')
            ->assertDontSee('Zoila Rankin');
    }

    /**
     * EL REPARTO POR PROMOTORIA desempata por nombre, y despues por id
     * (03/10/2026, pedido del usuario). Ordena por «continuan» dentro de cada
     * departamento, y dos promotorias con la misma cifra salian en el orden
     * que trajera la agrupacion de PostgreSQL. Se crean en orden alfabetico
     * INVERSO a proposito, y las dos «Arpa» —la base permite el nombre
     * repetido— para que el `id` tenga algo que decidir.
     */
    public function test_el_reparto_por_promotoria_desempata_por_nombre_y_despues_por_id(): void
    {
        $area = Area::create(['nombre' => 'Cuerdas']);
        $creadas = [];
        foreach (['Zampoña', 'Arpa', 'Arpa'] as $i => $nombre) {
            $creadas[] = $promotoria = Promotoria::create(['nombre' => $nombre, 'area_id' => $area->id]);
            $this->matricular($this->crearPerfil("cuerda{$i}", 'estudiante'), $promotoria, Matricula::ACTIVA);
        }
        // La primera «Arpa» se toca para que su fila pase al final de la tabla.
        Promotoria::whereKey($creadas[1]->id)->update(['updated_at' => now()]);

        // Con tan pocas filas el planificador va por indice y acierta por
        // casualidad; sin indices lee en el orden fisico, como con volumen.
        foreach (['enable_indexscan', 'enable_bitmapscan', 'enable_indexonlyscan'] as $ajuste) {
            DB::statement("SET LOCAL {$ajuste} = off");
        }

        $arbol = $this->actingAs($this->admin->user)
            ->get(route('gestion-estadisticas'))
            ->assertOk()
            ->viewData('arbolDepartamentos');

        $cuerdas = collect($arbol)->firstWhere('id', $area->id);
        $this->assertNotNull($cuerdas);
        $this->assertSame(['Arpa', 'Arpa', 'Zampoña'], array_column($cuerdas['promotorias'], 'etiqueta'));
        $this->assertSame(
            [$creadas[1]->id, $creadas[2]->id, $creadas[0]->id],
            array_column($cuerdas['promotorias'], 'id')
        );
    }

    /**
     * El ranking de constancia exige un minimo de clases.
     *
     * Sin el, un 1 de 1 seria un 100 % y desplazaria a quien lleva veinte de
     * veintidos: la lista se llenaria de gente que apenas empezo.
     */
    public function test_el_ranking_de_constancia_deja_fuera_a_quien_tiene_pocas_clases(): void
    {
        $grupo = $this->grupoDeViolin();
        $matricula = $this->matricular($this->estudiante, $this->violin, Matricula::ACTIVA);
        $matricula->repartirEn([$grupo->id]);

        $this->dictar($grupo, 3);

        // Tres asistencias perfectas, por debajo del minimo: no entra.
        foreach (Clase::where('grupo_id', $grupo->id)->get() as $clase) {
            Asistencia::create([
                'clase_id' => $clase->id,
                'matricula_id' => $matricula->id,
                'estado' => Asistencia::ASISTIO,
            ]);
        }

        $this->actingAs($this->admin->user)
            ->get(route('gestion-estadisticas'))
            ->assertOk()
            ->assertSee('Todavía no hay suficientes clases', false);
    }

    public function test_el_ranking_de_constancia_entra_al_llegar_al_minimo(): void
    {
        $grupo = $this->grupoDeViolin();
        $matricula = $this->matricular($this->estudiante, $this->violin, Matricula::ACTIVA);
        $matricula->repartirEn([$grupo->id]);

        $this->dictar($grupo, 6);

        // Cinco presentes y una falta: 83 %.
        foreach (Clase::where('grupo_id', $grupo->id)->orderBy('id')->get() as $indice => $clase) {
            Asistencia::create([
                'clase_id' => $clase->id,
                'matricula_id' => $matricula->id,
                'estado' => $indice === 0 ? Asistencia::FALTO : Asistencia::ASISTIO,
            ]);
        }

        $this->actingAs($this->admin->user)
            ->get(route('gestion-estadisticas'))
            ->assertOk()
            ->assertSee('Estudiantes más constantes', false)
            ->assertSee('5 de 6')
            ->assertSee('83%');
    }

    /** Las flechas caminan por los periodos. */
    public function test_se_puede_pedir_el_tablero_de_otro_periodo(): void
    {
        $viejo = Periodo::create([
            'nombre' => '2025-2',
            'fecha_inicio' => '2025-07-01',
            'fecha_fin' => '2025-12-15',
            'activo' => false,
            'matriculas_abiertas' => false,
        ]);

        $this->actingAs($this->admin->user)
            ->get(route('gestion-estadisticas-periodo', $viejo))
            ->assertOk()
            ->assertSee('2025-2');
    }

    /**
     * «Sigue» y «Deja» del arbol de departamentos.
     *
     * No habia nada que mirara esos dos numeros: la pantalla se probaba con
     * `assertOk` y con la satisfaccion, pero la consulta que los calcula podia
     * cambiar de sentido sin que la suite se enterara. Lo que fija es la parte
     * menos obvia: una CANCELACION SOLICITADA cuenta como que SIGUE, porque
     * hasta que alguien la resuelva la persona esta matriculada.
     */
    public function test_el_arbol_cuenta_las_cancelaciones_pendientes_como_que_siguen(): void
    {
        $this->matricular($this->estudiante, $this->violin, Matricula::ACTIVA);
        $this->matricular($this->crearEstudiante('beto'), $this->violin, Matricula::CANCELACION_SOLICITADA);
        $this->matricular($this->crearEstudiante('caro'), $this->violin, Matricula::RETIRADA);
        // Pendiente: no entra en el arbol ni por un lado ni por el otro.
        $this->matricular($this->crearEstudiante('dani'), $this->violin, Matricula::PENDIENTE);

        $arbol = $this->actingAs($this->admin->user)
            ->get(route('gestion-estadisticas'))
            ->assertOk()
            ->viewData('arbolDepartamentos');

        $this->assertCount(1, $arbol);
        $this->assertSame('Musica', $arbol[0]['nombre']);
        $this->assertSame(2, $arbol[0]['total'], 'Sigue: la activa y la que pidio cancelacion.');
        $this->assertSame(1, $arbol[0]['retirados'], 'Deja: solo la retirada.');
    }
}
