<?php

namespace Tests\Feature;

use App\Models\Actividad;
use App\Models\Acudiente;
use App\Models\Area;
use App\Models\DatosEstudiante;
use App\Models\DocumentoEstudiante;
use App\Models\DocumentoRequerido;
use App\Models\EncuestaDemografica;
use App\Models\EncuestaSatisfaccion;
use App\Models\Grupo;
use App\Models\InscritoActividad;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Models\User;
use App\Support\SupresionDeDatos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * El titular pide que se borren sus datos (Ley 1581) y ya tiene matriculas.
 *
 * Hasta el 30/09/2026 esa cuenta no se podia borrar, solo desactivar. Ahora se
 * ANONIMIZA: se va todo lo que dice quien era y se quedan las matriculas, para
 * que las cifras de los periodos pasados no cambien. Ver `SupresionDeDatos`.
 *
 * Varias de estas pruebas miran el DISCO y no solo la base: el borrado de
 * cuentas que ya existia dejaba la foto y los papeles en `storage` sin que
 * nada lo notara, porque la fila si se iba.
 */
class SupresionDeDatosTest extends TestCase
{
    use RefreshDatabase;

    private Periodo $periodo;

    private Promotoria $violin;

    private Grupo $lunes;

    private Perfil $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->periodo = Periodo::create([
            'nombre' => '2026-2',
            'fecha_inicio' => '2026-07-15',
            'fecha_fin' => '2026-12-15',
            'activo' => true,
            'matriculas_abiertas' => true,
        ]);

        $musica = Area::create(['nombre' => 'Musica']);
        $this->admin = $this->crearPerfil('admin', 'administrador');
        $profesor = $this->crearPerfil('profe', 'profesor');

        $this->violin = Promotoria::create([
            'nombre' => 'Violin',
            'area_id' => $musica->id,
            'profesor_id' => $profesor->id,
        ]);

        $this->lunes = Grupo::create([
            'promotoria_id' => $this->violin->id, 'nombre' => 'Lunes',
            'nivel' => 'basico', 'salon' => 'A1', 'cupo_maximo' => 10,
        ]);
    }

    private function crearPerfil(string $username, string $rol): Perfil
    {
        $user = User::create([
            'username' => $username,
            'email' => "{$username}@correo.test",
            'password' => 'x',
            'activo' => true,
        ]);

        return Perfil::create([
            'user_id' => $user->id,
            'rol' => $rol,
            'nombre_completo' => 'Nombre '.ucfirst($username),
            'fecha_nacimiento' => Carbon::today()->subYears(15)->toDateString(),
            'telefono' => '3001112233',
        ]);
    }

    /** Un estudiante con TODO lo que se le puede recoger: foto, papel, acudiente y encuestas. */
    private function estudianteCompleto(string $username = 'ana'): Perfil
    {
        $perfil = $this->crearPerfil($username, 'estudiante');

        Storage::disk('local')->put("fotos_perfil/{$perfil->id}.webp", 'foto');
        $perfil->foto_perfil = "fotos_perfil/{$perfil->id}.webp";
        $perfil->codigoQr();
        $perfil->save();

        $acudiente = Acudiente::create(['nombre' => 'Madre De Ana', 'telefono' => '3009998877']);
        $ficha = DatosEstudiante::create([
            'perfil_id' => $perfil->id,
            'documento_identidad' => '10203040',
            'acudiente_id' => $acudiente->id,
        ]);

        $papel = DocumentoRequerido::create(['nombre' => 'Documento de identidad', 'orden' => 1]);
        Storage::disk('local')->put("documentos/cedula-{$perfil->id}.pdf", 'cedula');
        DocumentoEstudiante::create([
            'datos_estudiante_id' => $ficha->id,
            'requerido_id' => $papel->id,
            'archivo' => "documentos/cedula-{$perfil->id}.pdf",
            'subido' => now(),
        ]);

        EncuestaDemografica::create([
            'perfil_id' => $perfil->id,
            'genero' => 'f',
            'barrio' => 'El Centro',
            'estrato' => 2,
            'nivel_educativo' => 'secundaria_com',
            'ocupacion' => 'estudiante',
            'autoriza_tratamiento_datos' => true,
        ]);

        return $perfil;
    }

    private function matricular(Perfil $perfil, Periodo $periodo, string $estado): Matricula
    {
        $matricula = new Matricula([
            'estudiante_id' => $perfil->id,
            'promotoria_id' => $this->violin->id,
            'periodo_id' => $periodo->id,
            'estado' => $estado,
        ]);
        $matricula->save();

        return $matricula;
    }

    private function suprimir(Perfil $perfil): TestResponse
    {
        return $this->actingAs($this->admin->user)
            ->post(route('usuario-eliminar', $perfil), ['password' => 'x']);
    }

    public function test_con_matriculas_se_anonimiza_y_la_matricula_se_queda(): void
    {
        $ana = $this->estudianteCompleto();
        $matricula = $this->matricular($ana, $this->periodo, Matricula::ACTIVA);
        $acudienteId = $ana->datosEstudiante->acudiente_id;

        $this->suprimir($ana)
            ->assertRedirect(route('usuario-lista'))
            ->assertSessionHas('success');

        $ana->refresh();
        $this->assertTrue($ana->estaSuprimido());
        $this->assertSame(SupresionDeDatos::NOMBRE, $ana->nombre_completo);
        $this->assertNull($ana->fecha_nacimiento);
        $this->assertNull($ana->telefono);
        $this->assertSame('', $ana->foto_perfil);
        $this->assertNull($ana->codigo_qr);

        $this->assertSame("suprimido-{$ana->id}", $ana->user->username);
        $this->assertNull($ana->user->email);
        $this->assertFalse((bool) $ana->user->activo);
        $this->assertFalse(Hash::check('x', $ana->user->password));

        $this->assertNull(DatosEstudiante::where('perfil_id', $ana->id)->first());
        $this->assertNull(Acudiente::find($acudienteId));
        $this->assertSame(0, DocumentoEstudiante::count());
        $this->assertSame(0, EncuestaDemografica::where('perfil_id', $ana->id)->count());

        // El historial: la fila sigue y sigue siendo suya.
        $this->assertSame($ana->id, Matricula::find($matricula->id)?->estudiante_id);
    }

    /** Las filas se iban solas; los ARCHIVOS no, y son cedulas. */
    public function test_se_borran_la_foto_y_los_papeles_del_disco(): void
    {
        $ana = $this->estudianteCompleto();
        $this->matricular($ana, $this->periodo, Matricula::ACTIVA);

        $this->suprimir($ana);

        Storage::disk('local')->assertMissing("fotos_perfil/{$ana->id}.webp");
        Storage::disk('local')->assertMissing("documentos/cedula-{$ana->id}.pdf");
    }

    /**
     * El borrado que ya existia (sin historial) tenia los mismos agujeros: la
     * cuenta se iba y los archivos, el acudiente y la fila del taller no.
     */
    public function test_sin_historial_se_borra_la_cuenta_y_tampoco_quedan_restos(): void
    {
        $ana = $this->estudianteCompleto();
        $acudienteId = $ana->datosEstudiante->acudiente_id;
        $taller = Actividad::create([
            'tipo' => Actividad::TALLER,
            'nombre' => 'Taller de cuerdas',
            'responsable_id' => $this->violin->profesor_id,
            'periodo_id' => $this->periodo->id,
        ]);
        $inscrito = InscritoActividad::create([
            'actividad_id' => $taller->id,
            'nombre_completo' => 'Nombre Ana',
            'documento' => '10203040',
            'telefono' => '3001112233',
            'perfil_id' => $ana->id,
            'origen' => 'enlace',
        ]);

        $this->suprimir($ana)->assertSessionHas('success');

        $this->assertNull(User::find($ana->user_id));
        $this->assertNull(Acudiente::find($acudienteId));
        Storage::disk('local')->assertMissing("fotos_perfil/{$ana->id}.webp");
        Storage::disk('local')->assertMissing("documentos/cedula-{$ana->id}.pdf");

        // La fila del taller se queda —cuenta en su asistencia— pero vacia.
        $inscrito->refresh();
        $this->assertSame(SupresionDeDatos::NOMBRE, $inscrito->nombre_completo);
        $this->assertNull($inscrito->documento);
        $this->assertNull($inscrito->telefono);
    }

    /** Decision del usuario: la silla de alguien que ya no existe es un cupo que le falta a otro. */
    public function test_la_matricula_en_curso_se_retira_y_suelta_el_grupo(): void
    {
        $ana = $this->estudianteCompleto();
        $matricula = $this->matricular($ana, $this->periodo, Matricula::ACTIVA);
        $matricula->repartirEn([$this->lunes->id]);

        $this->suprimir($ana);

        $matricula->refresh();
        $this->assertSame(Matricula::RETIRADA, $matricula->estado);
        $this->assertSame(Matricula::RETIRO_SUPRESION, $matricula->motivo_retiro);
        $this->assertSame(0, $matricula->grupos()->count());
    }

    /**
     * Una ACTIVA de un periodo que termino es lo que la pantalla enseña como
     * «finalizada». Retirarla reescribiria la historia que se quiere guardar.
     */
    public function test_la_matricula_de_un_periodo_terminado_no_se_toca(): void
    {
        $pasado = Periodo::create([
            'nombre' => '2026-1',
            'fecha_inicio' => '2026-01-15',
            'fecha_fin' => '2026-06-30',
            'activo' => false,
        ]);
        $ana = $this->estudianteCompleto();
        $vieja = $this->matricular($ana, $pasado, Matricula::ACTIVA);
        $rechazada = $this->matricular($ana, $this->periodo, Matricula::RETIRADA);
        $rechazada->motivo_retiro = Matricula::RETIRO_RECHAZO;
        $rechazada->save();

        $this->suprimir($ana);

        $this->assertSame(Matricula::ACTIVA, $vieja->refresh()->estado);
        $this->assertNull($vieja->motivo_retiro);
        // Y una ya retirada conserva su motivo.
        $this->assertSame(Matricula::RETIRO_RECHAZO, $rechazada->refresh()->motivo_retiro);
    }

    public function test_la_encuesta_de_satisfaccion_tambien_se_va(): void
    {
        $ana = $this->estudianteCompleto();
        $this->matricular($ana, $this->periodo, Matricula::ACTIVA);
        EncuestaSatisfaccion::create([
            'perfil_id' => $ana->id,
            'promotoria_id' => $this->violin->id,
            'periodo_id' => $this->periodo->id,
            'satisfaccion_general' => 4,
            'calificacion_profesor' => 4,
            'horario_funciono' => true,
            'recomendaria' => true,
            'comentario' => 'Soy Ana, la de las trenzas',
        ]);

        $this->suprimir($ana);

        $this->assertSame(0, EncuestaSatisfaccion::count());
    }

    /** La pantalla dice que NO se borra la cuenta antes de pedir la contraseña. */
    public function test_la_confirmacion_explica_que_se_anonimiza(): void
    {
        $ana = $this->estudianteCompleto();
        $this->matricular($ana, $this->periodo, Matricula::ACTIVA);

        $this->actingAs($this->admin->user)
            ->get(route('usuario-eliminar', $ana))
            ->assertOk()
            ->assertSee('Suprimir los datos', false)
            ->assertSee('name="password"', false);
    }

    /** Y en el listado el enlace esta vivo aunque tenga matriculas. */
    public function test_el_listado_ofrece_eliminar_a_quien_tiene_matriculas(): void
    {
        $ana = $this->estudianteCompleto();
        $this->matricular($ana, $this->periodo, Matricula::ACTIVA);

        $this->actingAs($this->admin->user)
            ->get(route('usuario-lista'))
            ->assertOk()
            ->assertSee(route('usuario-eliminar', $ana), false);
    }

    /**
     * Despues, la fila no se gestiona: no sale en Usuarios, no se edita (se
     * guardaria un nombre nuevo sobre lo que la ley dejo vacio), no se asiste
     * y no tiene carne (sacarlo le crearia un codigo nuevo).
     */
    public function test_una_persona_suprimida_no_se_vuelve_a_tocar(): void
    {
        $ana = $this->estudianteCompleto();
        $this->matricular($ana, $this->periodo, Matricula::ACTIVA);
        $this->suprimir($ana);
        $ana->refresh();

        $this->actingAs($this->admin->user)
            ->get(route('usuario-lista'))
            ->assertOk()
            ->assertDontSee(SupresionDeDatos::NOMBRE);

        $this->actingAs($this->admin->user)
            ->get(route('usuario-editar', $ana))
            ->assertNotFound();

        $this->actingAs($this->admin->user)
            ->get(route('usuario-eliminar', $ana))
            ->assertNotFound();

        $this->actingAs($this->admin->user)
            ->post(route('gestion-asistida-iniciar', $ana))
            ->assertSessionMissing('gestion_asistida_admin');

        $this->actingAs($this->admin->user)->get(route('carne-estudiante-imagen', $ana))->assertNotFound();
        $this->assertNull($ana->refresh()->codigo_qr);
    }

    /** Lo que tiene A SU CARGO sigue bloqueando: borrarlo dejaria a otros sin quien responda. */
    public function test_quien_dicta_una_promotoria_sigue_sin_poder_suprimirse(): void
    {
        $profesor = Perfil::find($this->violin->profesor_id);

        $this->suprimir($profesor)->assertSessionHas('error');

        $this->assertFalse($profesor->refresh()->estaSuprimido());
        $this->assertSame('Nombre Profe', $profesor->nombre_completo);
    }
}
