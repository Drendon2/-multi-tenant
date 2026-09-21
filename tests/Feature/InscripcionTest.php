<?php

namespace Tests\Feature;

use App\Models\Acudiente;
use App\Models\Area;
use App\Models\ConfiguracionInstitucion;
use App\Models\CupoPromotoria;
use App\Models\DatosEstudiante;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Autorregistro de estudiante: crea la cuenta Y la inscribe de una vez.
 *
 * Es el flujo que ejerce a la vez el limite de promotorias, el cupo con su
 * trigger y la creacion de acudiente, asi que cubre casi toda la logica de
 * dominio en una sola pantalla.
 */
class InscripcionTest extends TestCase
{
    use RefreshDatabase;

    private Periodo $periodo;

    private Promotoria $violin;

    private Promotoria $danza;

    private Promotoria $teatro;

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
        $this->teatro = Promotoria::create(['nombre' => 'Teatro', 'area_id' => $area->id]);
    }

    private function datos(array $extra = []): array
    {
        return [
            'username' => 'ana.nueva',
            'password' => 'secreto123',
            'password_confirmation' => 'secreto123',
            'nombre_completo' => 'Ana Ruiz',
            'fecha_nacimiento' => Carbon::today()->subYears(25)->toDateString(),
            'telefono' => '3001234567',
            'documento_identidad' => '1234567890',
            'promotoria' => $this->violin->id,
            ...$extra,
        ];
    }

    /**
     * DESDE EL 21/09/2026 LA INSCRIPCION TERMINA EN EL CARNE, no en la pantalla
     * de entrar.
     *
     * Es el unico momento en que esta persona esta mirando y todavia no tiene
     * que recordar nada para llegar a ningun sitio, asi que ahi es donde se le
     * ofrece guardarse el carne QR en el telefono. El mensaje de siempre viaja
     * igual y de esa pantalla se sigue a entrar.
     */
    public function test_crea_cuenta_perfil_datos_y_matricula(): void
    {
        $this->post(route('inscripcion.guardar'), $this->datos())
            ->assertRedirect(route('carne-recien-inscrito'))
            ->assertSessionHas('success');

        $user = User::where('username', 'ana.nueva')->first();
        $this->assertNotNull($user);
        $this->assertSame('estudiante', $user->perfil->rol);
        $this->assertSame('1234567890', $user->perfil->datosEstudiante->documento_identidad);

        $matricula = Matricula::where('estudiante_id', $user->perfil->id)->first();
        $this->assertNotNull($matricula);
        // Toda matricula nace pendiente: el profesor la confirma.
        $this->assertSame(Matricula::PENDIENTE, $matricula->estado);
        $this->assertSame(0, $matricula->grupos()->count());
        $this->assertSame($this->violin->id, $matricula->promotoria_id);
    }

    /**
     * Y esa pantalla se abre de verdad, con el carne dentro y la puerta a
     * entrar.
     *
     * VA EN LA MISMA PETICION ENCADENADA a proposito: quien es sale de la
     * SESION y no de la URL —una URL con el perfil dentro entregaria el carne
     * de cualquiera— asi que esta prueba solo vale si conserva la sesion que
     * dejo la inscripcion.
     */
    public function test_la_pantalla_final_ensena_el_carne_y_lleva_a_entrar(): void
    {
        $this->post(route('inscripcion.guardar'), $this->datos());

        $respuesta = $this->get(route('carne-recien-inscrito'));

        $respuesta->assertOk();
        $respuesta->assertSee('data:image/png;base64,', false);
        $respuesta->assertSee(route('login'), false);

        // Y el codigo quedo creado, que es lo que hace que el papel sirva.
        $this->assertNotNull(User::where('username', 'ana.nueva')->first()->perfil->codigo_qr);
    }

    /** Sin haberse inscrito, esa pantalla no ensena el carne de nadie. */
    public function test_sin_inscripcion_reciente_la_pantalla_del_carne_manda_a_entrar(): void
    {
        $this->get(route('carne-recien-inscrito'))->assertRedirect(route('login'));
    }

    public function test_puede_inscribirse_en_dos_promotorias(): void
    {
        $this->post(route('inscripcion.guardar'), $this->datos([
            'promotoria_2' => $this->danza->id,
        ]))->assertSessionHas('success');

        $perfil = Perfil::first();
        $this->assertSame(2, Matricula::where('estudiante_id', $perfil->id)->count());
        // Ranuras distintas, asignadas solas.
        $this->assertEqualsCanonicalizing(
            [1, 2],
            Matricula::where('estudiante_id', $perfil->id)->pluck('ranura')->all()
        );
    }

    /** La misma promotoria no puede ocupar dos cupos. */
    public function test_no_admite_la_misma_promotoria_dos_veces(): void
    {
        $this->post(route('inscripcion.guardar'), $this->datos([
            'promotoria_2' => $this->violin->id,
        ]))->assertSessionHasErrors('promotoria_2');

        $this->assertSame(0, User::count());
    }

    // -----------------------------------------------------------------------
    // Menores de edad
    // -----------------------------------------------------------------------

    public function test_un_menor_sin_acudiente_se_rechaza(): void
    {
        $this->post(route('inscripcion.guardar'), $this->datos([
            'fecha_nacimiento' => Carbon::today()->subYears(12)->toDateString(),
        ]))->assertSessionHasErrors('acudiente_nombre');

        $this->assertSame(0, User::count());
    }

    /**
     * El telefono es tan obligatorio como el nombre: el acudiente de un menor no
     * esta ahi para figurar en una ficha, sino para que la institucion pueda
     * llamarlo —al resolver una cancelacion, al hacer seguimiento de una mala
     * experiencia, o si pasa algo en clase—. Un acudiente sin telefono no sirve
     * para ninguna de las tres.
     */
    public function test_un_menor_con_acudiente_sin_telefono_se_rechaza(): void
    {
        $this->post(route('inscripcion.guardar'), $this->datos([
            'fecha_nacimiento' => Carbon::today()->subYears(12)->toDateString(),
            'acudiente_nombre' => 'Marta Ruiz',
        ]))->assertSessionHasErrors('acudiente_telefono');

        $this->assertSame(0, User::count());
        $this->assertSame(0, Acudiente::count());
    }

    /** A un mayor de edad el telefono del acudiente le sigue siendo opcional. */
    public function test_un_adulto_no_necesita_telefono_de_acudiente(): void
    {
        $this->post(route('inscripcion.guardar'), $this->datos([
            'fecha_nacimiento' => Carbon::today()->subYears(30)->toDateString(),
        ]))->assertSessionHas('success');
    }

    public function test_un_menor_con_acudiente_entra_y_se_crea_el_acudiente(): void
    {
        $this->post(route('inscripcion.guardar'), $this->datos([
            'fecha_nacimiento' => Carbon::today()->subYears(12)->toDateString(),
            'acudiente_nombre' => 'Marta Ruiz',
            'acudiente_telefono' => '3009999999',
        ]))->assertSessionHas('success');

        $acudiente = Acudiente::first();
        $this->assertNotNull($acudiente);
        $this->assertSame('Marta Ruiz', $acudiente->nombre);
        $this->assertSame($acudiente->id, DatosEstudiante::first()->acudiente_id);
    }

    /** Justo el dia en que cumple 18 ya no necesita acudiente. */
    public function test_el_dia_del_cumpleanos_dieciocho_no_necesita_acudiente(): void
    {
        $this->post(route('inscripcion.guardar'), $this->datos([
            'fecha_nacimiento' => Carbon::today()->subYears(18)->toDateString(),
        ]))->assertSessionHas('success');

        $this->assertNull(DatosEstudiante::first()->acudiente_id);
    }

    // -----------------------------------------------------------------------
    // Ventana de matriculas
    // -----------------------------------------------------------------------

    public function test_con_matriculas_cerradas_no_se_inscribe_nadie(): void
    {
        $this->periodo->update(['matriculas_abiertas' => false]);

        $this->post(route('inscripcion.guardar'), $this->datos())
            ->assertRedirect(route('inscripcion'))
            ->assertSessionHas('error');

        $this->assertSame(0, User::count());
    }

    /** El formulario ni se pinta si las matriculas estan cerradas. */
    public function test_el_formulario_no_aparece_con_matriculas_cerradas(): void
    {
        $this->periodo->update(['matriculas_abiertas' => false]);

        $this->get(route('inscripcion'))
            ->assertOk()
            ->assertSee('están cerradas en este momento', false)
            ->assertDontSee('Crear cuenta e inscribirme');
    }

    // -----------------------------------------------------------------------
    // Cupo
    // -----------------------------------------------------------------------

    /**
     * Si la promotoria esta llena NO se crea nada: ni la cuenta suelta. La
     * transaccion cubre las cuatro escrituras.
     */
    public function test_una_promotoria_llena_no_deja_cuenta_a_medias(): void
    {
        CupoPromotoria::create([
            'promotoria_id' => $this->violin->id,
            'periodo_id' => $this->periodo->id,
            'cupo_maximo' => 1,
        ]);

        // Alguien ya tomo el unico sitio.
        $otro = User::create(['username' => 'otro', 'password' => 'x', 'activo' => true]);
        $perfilOtro = Perfil::create([
            'user_id' => $otro->id, 'rol' => 'estudiante', 'nombre_completo' => 'Otro',
            'fecha_nacimiento' => '2000-01-01', 'telefono' => '300',
        ]);
        Matricula::create([
            'estudiante_id' => $perfilOtro->id,
            'promotoria_id' => $this->violin->id,
            'periodo_id' => $this->periodo->id,
        ]);

        $this->post(route('inscripcion.guardar'), $this->datos())->assertSessionHasErrors();

        // Ni la cuenta, ni el perfil, ni los datos, ni la matricula: la
        // transaccion cubre las cuatro escrituras. Quien ya estaba sigue estando
        // (ese se creo sin DatosEstudiante, de ahi que su cuenta sea cero).
        $this->assertNull(User::where('username', 'ana.nueva')->first());
        $this->assertSame(0, DatosEstudiante::count());
        $this->assertSame(1, Matricula::count());
    }

    /** Sin fila de cupo, la promotoria no tiene tope. */
    public function test_sin_cupo_definido_no_hay_tope(): void
    {
        $this->post(route('inscripcion.guardar'), $this->datos())->assertSessionHas('success');

        $this->post(route('inscripcion.guardar'), $this->datos([
            'username' => 'beto.nuevo',
            'documento_identidad' => '9999999999',
        ]))->assertSessionHas('success');

        $this->assertSame(2, Matricula::where('promotoria_id', $this->violin->id)->count());
    }

    // -----------------------------------------------------------------------
    // Limite configurable
    // -----------------------------------------------------------------------

    /**
     * El formulario ofrece tantos cupos como permita la configuracion: subirlo
     * anade selectores sin tocar codigo.
     */
    public function test_el_formulario_ofrece_tantos_cupos_como_el_limite(): void
    {
        ConfiguracionInstitucion::actual()->update(['limite_promotorias_por_periodo' => 3]);

        $respuesta = $this->get(route('inscripcion'))->assertOk();

        $respuesta->assertSee('name="promotoria"', false);
        $respuesta->assertSee('name="promotoria_2"', false);
        $respuesta->assertSee('name="promotoria_3"', false);
        $respuesta->assertDontSee('name="promotoria_4"', false);
    }

    public function test_con_limite_uno_solo_hay_un_cupo(): void
    {
        ConfiguracionInstitucion::actual()->update(['limite_promotorias_por_periodo' => 1]);

        $this->get(route('inscripcion'))
            ->assertOk()
            ->assertSee('name="promotoria"', false)
            ->assertDontSee('name="promotoria_2"', false)
            ->assertSee('una promotoría', false);
    }

    /** Un cupo de mas que el limite no cuela aunque se envie a mano. */
    public function test_no_se_puede_forzar_mas_promotorias_que_el_limite(): void
    {
        ConfiguracionInstitucion::actual()->update(['limite_promotorias_por_periodo' => 1]);

        $this->post(route('inscripcion.guardar'), $this->datos([
            'promotoria_2' => $this->danza->id,
        ]))->assertSessionHas('success');

        // El campo extra se ignora: con limite 1 no esta en las reglas.
        $this->assertSame(1, Matricula::count());
    }

    /** Los formularios publicos sin sesion no aceptan archivos. */
    public function test_la_inscripcion_no_sube_archivos(): void
    {
        $this->get(route('inscripcion'))
            ->assertOk()
            ->assertDontSee('<input type="file"', false)
            ->assertSee('Mi perfil');
    }

    /** Ocho caracteres como minimo, tambien en la puerta del publico. */
    public function test_una_contrasena_corta_no_crea_cuenta_ni_matricula(): void
    {
        $this->post(route('inscripcion.guardar'), $this->datos([
            'password' => 'corta7c',
            'password_confirmation' => 'corta7c',
        ]))->assertSessionHasErrors('password');

        // Lo que importa de este formulario: no queda nada a medias. La cuenta,
        // el perfil, los datos del estudiante y la matricula nacen en la misma
        // transaccion, y si la contrasena no pasa no llega a abrirse.
        $this->assertNull(User::where('username', 'ana.nueva')->first());
        $this->assertSame(0, Matricula::count());
        $this->assertSame(0, DatosEstudiante::count());
    }

    /**
     * La inscripcion admite diez por minuto desde una direccion.
     *
     * Mas holgada que el registro porque esta es la puerta que usa el publico de
     * verdad —una familia inscribiendo a varios hijos, o un grupo en la sala de
     * computo— y no la de los profesores. Sigue siendo por IP: cada inscripcion
     * estrena usuario, asi que no hay cuenta previa contra la cual contar.
     */
    public function test_la_inscripcion_se_corta_tras_diez_seguidas(): void
    {
        for ($n = 1; $n <= 10; $n++) {
            $this->post(route('inscripcion.guardar'), $this->datos([
                'username' => "ana.nueva{$n}",
                'documento_identidad' => "10000000{$n}",
            ]))->assertSessionHas('success');
        }

        $this->post(route('inscripcion.guardar'), $this->datos([
            'username' => 'ana.nueva11',
            'documento_identidad' => '100000011',
        ]))->assertSessionHas('error');

        $this->assertSame(10, Matricula::count());
        $this->assertNull(User::where('username', 'ana.nueva11')->first());
    }
}
