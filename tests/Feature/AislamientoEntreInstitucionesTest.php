<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\ConfiguracionInstitucion;
use App\Models\DatosEstudiante;
use App\Models\Grupo;
use App\Models\Institucion;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Models\User;
use App\Support\InstitucionActual;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Dos instituciones en la misma base: ninguna ve, toca ni cuenta lo de la otra.
 *
 * Es el criterio de terminado del paso 1 de multi-institucion, y desde el paso
 * 3 lo sostiene Row Level Security: las pruebas corren como el rol de la
 * APLICACION, al que RLS si le aplica (ver `Tests\TestCase::artisan()`). Cada
 * prueba monta las dos casas con los MISMOS nombres —«Musica», «2026-1»,
 * «Violin»— porque es el caso real (dos casas de la cultura se parecen) y
 * porque asi un filtro que falte no se esconde detras de un nombre distinto.
 *
 * Para mirar la OTRA institucion dentro de una prueba se usa
 * `InstitucionActual::mientras()`: RLS no se quita con un `withoutGlobalScope`,
 * y la conexion del dueño no veria las filas de la transaccion de la prueba.
 *
 * Desde el paso 4a cada casa se visita POR SU DOMINIO (`CASA` y `OTRA`): la
 * institucion de una peticion la dice el host, no la cuenta.
 */
class AislamientoEntreInstitucionesTest extends TestCase
{
    use RefreshDatabase;

    private const CASA = 'http://santuario.localhost';

    private const OTRA = 'http://guarne.localhost';

    /** @var array<string, mixed> */
    private array $casa;

    /** @var array<string, mixed> */
    private array $otra;

    protected function setUp(): void
    {
        parent::setUp();

        config(['institucion.dominio_base' => 'localhost']);
        Institucion::findOrFail(1)->update(['subdominio' => 'santuario']);

        ConfiguracionInstitucion::actual()->update(['nombre_institucion' => 'Casa de El Santuario']);
        $this->casa = $this->montar('santuario');

        $otra = Institucion::create(['nombre' => 'Casa de Guarne', 'subdominio' => 'guarne']);
        $this->otra = InstitucionActual::mientras($otra->id, function () {
            ConfiguracionInstitucion::actual()->update(['nombre_institucion' => 'Casa de Guarne']);

            return $this->montar('guarne');
        });
        $this->otra['institucion'] = $otra;
    }

    public function test_los_modelos_solo_ven_la_institucion_actual(): void
    {
        $this->assertSame(1, Area::count());
        $this->assertSame([$this->casa['area']->id], Area::pluck('id')->all());
        $this->assertNull(Promotoria::find($this->otra['promotoria']->id));
        $this->assertNull(Perfil::whereKey($this->otra['estudiante']->id)->first());
        $this->assertSame(1, Matricula::count());

        // Y por relaciones, que tambien pasan por el filtro del modelo.
        $this->assertSame(0, $this->casa['area']->promotorias()->whereKey($this->otra['promotoria']->id)->count());
    }

    public function test_cada_una_tiene_su_periodo_en_curso(): void
    {
        $this->assertSame($this->casa['periodo']->id, Periodo::where('activo', true)->value('id'));

        InstitucionActual::mientras($this->otra['institucion']->id, function () {
            $this->assertSame($this->otra['periodo']->id, Periodo::where('activo', true)->value('id'));
        });
    }

    public function test_las_filas_nuevas_nacen_en_la_institucion_de_la_sesion(): void
    {
        $this->actingAs($this->otra['admin']->user)
            ->post(self::OTRA.'/gestion/areas/nueva', ['nombre' => 'Danza'])
            ->assertSessionHasNoErrors();

        $danza = InstitucionActual::mientras(
            $this->otra['institucion']->id,
            fn () => Area::where('nombre', 'Danza')->sole()
        );
        $this->assertSame($this->otra['institucion']->id, $danza->institucionId());
        $this->assertFalse(
            InstitucionActual::mientras(1, fn () => Area::where('nombre', 'Danza')->exists())
        );
    }

    public function test_el_mismo_nombre_se_repite_entre_instituciones_y_no_dentro_de_una(): void
    {
        // «Musica» ya existe en las dos (lo monta setUp); dentro de una, no.
        $this->actingAs($this->otra['admin']->user)
            ->post(self::OTRA.'/gestion/areas/nueva', ['nombre' => 'Musica'])
            ->assertSessionHasErrors('nombre');
    }

    public function test_la_gestion_de_una_no_abre_las_filas_de_la_otra(): void
    {
        $admin = $this->actingAs($this->otra['admin']->user);

        $admin->get(self::OTRA.'/gestion/promotorias/'.$this->casa['promotoria']->id.'/editar')->assertNotFound();
        $admin->get(self::OTRA.'/gestion/areas/'.$this->casa['area']->id.'/editar')->assertNotFound();
        $admin->post(self::OTRA.'/gestion/promotorias/'.$this->casa['promotoria']->id.'/eliminar')->assertNotFound();
        $this->assertTrue(InstitucionActual::mientras(1, fn () => Promotoria::whereKey($this->casa['promotoria']->id)->exists()));

        $admin->get(self::OTRA.'/gestion/promotorias/'.$this->otra['promotoria']->id.'/editar')->assertOk();
    }

    public function test_las_listas_de_gestion_solo_ensenan_lo_suyo(): void
    {
        $this->actingAs($this->otra['admin']->user)
            ->get(self::OTRA.'/gestion/usuarios')
            ->assertOk()
            ->assertSee('Estudiante guarne')
            ->assertDontSee('Estudiante santuario');
    }

    public function test_la_validacion_no_acepta_ids_de_la_otra_institucion(): void
    {
        // Crear un grupo en una promotoria AJENA: el `exists` suelto la
        // habria aceptado, porque la fila existe en la base.
        $this->actingAs($this->otra['admin']->user)
            ->post(self::OTRA.'/gestion/grupos/nuevo', [
                'promotoria_id' => $this->casa['promotoria']->id,
                'nivel' => 'basico',
                'nombre' => 'Intruso',
            ])
            ->assertSessionHasErrors('promotoria_id');

        foreach ([1, $this->otra['institucion']->id] as $institucion) {
            $this->assertSame(0, InstitucionActual::mientras($institucion, fn () => Grupo::where('nombre', 'Intruso')->count()));
        }
    }

    public function test_el_documento_de_identidad_es_unico_dentro_de_cada_institucion(): void
    {
        // La misma persona puede inscribirse en dos municipios.
        $this->assertSame(
            InstitucionActual::mientras(1, fn () => DatosEstudiante::where('perfil_id', $this->casa['estudiante']->id)->value('documento_identidad')),
            InstitucionActual::mientras($this->otra['institucion']->id, fn () => DatosEstudiante::where('perfil_id', $this->otra['estudiante']->id)->value('documento_identidad')),
        );
    }

    public function test_la_marca_es_la_del_dominio(): void
    {
        $this->actingAs($this->otra['admin']->user)
            ->get(self::OTRA.'/mi-perfil')
            ->assertOk()
            ->assertSee('Casa de Guarne')
            ->assertDontSee('Casa de El Santuario');

        // Y las dos mitades sin sesion, sin las cuales esta prueba pasaba con
        // el filtro apagado: cada dominio pinta su casa.
        $this->flushSession();
        auth()->logout();
        $this->get(self::CASA.'/entrar')
            ->assertOk()
            ->assertSee('Casa de El Santuario')
            ->assertDontSee('Casa de Guarne');
        $this->get(self::OTRA.'/entrar')
            ->assertOk()
            ->assertSee('Casa de Guarne')
            ->assertDontSee('Casa de El Santuario');
    }

    public function test_el_enlace_publico_de_una_promotoria_se_abre_en_su_dominio(): void
    {
        $this->get(self::OTRA.'/unirse/'.$this->enlaceDeLaOtra())
            ->assertOk()
            ->assertSee('Casa de Guarne')
            ->assertDontSee('Casa de El Santuario');
    }

    /**
     * Decision del usuario (02/10/2026): un token de otra institucion abierto
     * en este dominio no existe aqui. Ni se adopta su casa ni se redirige a
     * la suya, que diria de quien es el token a quien lo prueba.
     */
    public function test_el_enlace_de_otra_institucion_no_existe_en_este_dominio(): void
    {
        $token = $this->enlaceDeLaOtra();

        $this->get(self::CASA.'/unirse/'.$token)->assertNotFound();

        $this->actingAs($this->casa['estudiante']->user)
            ->get(self::CASA.'/unirse/'.$token)
            ->assertNotFound();
    }

    public function test_las_cifras_sin_modelo_no_suman_la_otra_institucion(): void
    {
        // El informe de la institucion se arma con consultas que no hidratan
        // modelos (`InstitucionActual::tabla()`), que es donde un filtro se
        // olvida sin que nada falle.
        $csv = $this->actingAs($this->otra['admin']->user)
            ->get(self::OTRA.'/informes/institucion')
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Estudiante guarne', $csv);
        $this->assertStringNotContainsString('Estudiante santuario', $csv);
    }

    public function test_un_or_detras_del_filtro_no_se_sale_de_la_institucion(): void
    {
        // Con RLS la condicion la pone el motor FUERA de la consulta, y ningun
        // `orWhere` la alcanza. Antes de RLS, sin agrupar, esto era
        // `institucion = 1 AND nombre = 'Musica' OR nombre = 'Musica'`, y la
        // rama del OR traia la «Musica» de Guarne.
        $ids = InstitucionActual::tabla('areas')
            ->where('nombre', 'Musica')
            ->orWhere('nombre', 'Musica')
            ->pluck('id')->all();

        $this->assertSame([$this->casa['area']->id], $ids);
    }

    /**
     * Sin institucion no sale NADA: ni por el modelo ni sin el. Antes lo
     * cerraba PHP lanzando; con RLS, la base no recibe institucion y sus
     * politicas no dejan pasar ninguna fila. Escribir sigue lanzando, porque
     * la fila nueva necesita a quien pertenecer.
     */
    public function test_sin_sesion_ni_institucion_por_defecto_no_se_consulta_nada(): void
    {
        config(['institucion.por_defecto' => null]);

        $this->assertSame(0, Area::count());
        $this->assertSame(0, DB::table('areas')->count());

        $this->expectException(RuntimeException::class);

        Area::create(['nombre' => 'Danza']);
    }

    /**
     * LA PRUEBA DE RLS: una consulta sin ningun filtro, ni del modelo ni de
     * `InstitucionActual`, solo ve su institucion. Si el aislamiento
     * dependiera de PHP, aqui saldrian las dos.
     */
    public function test_una_consulta_sin_ningun_filtro_solo_ve_su_institucion(): void
    {
        $this->assertSame([1], DB::table('matriculas')->distinct()->pluck('institucion_id')->all());
        $this->assertSame([1], DB::table('perfiles')->distinct()->pluck('institucion_id')->all());

        // Y la otra mitad: desde la otra, solo la otra.
        InstitucionActual::mientras($this->otra['institucion']->id, function () {
            $this->assertSame(
                [$this->otra['institucion']->id],
                DB::table('matriculas')->distinct()->pluck('institucion_id')->all()
            );
        });
    }

    public function test_no_se_puede_escribir_una_fila_con_la_institucion_de_otra(): void
    {
        try {
            DB::transaction(fn () => DB::table('areas')->insert([
                InstitucionActual::COLUMNA => $this->otra['institucion']->id,
                'nombre' => 'Colada',
            ]));
            $this->fail('La base acepto una fila de otra institucion.');
        } catch (QueryException $e) {
            // 42501: la fila no cumple la politica (WITH CHECK).
            $this->assertSame('42501', $e->getCode());
        }
    }

    public function test_no_se_puede_tocar_una_fila_de_otra(): void
    {
        $ajena = $this->otra['area']->id;

        $this->assertSame(0, DB::table('areas')->where('id', $ajena)->update(['nombre' => 'Pisada']));
        $this->assertSame(0, DB::table('matriculas')->where('promotoria_id', $this->otra['promotoria']->id)->delete());

        InstitucionActual::mientras($this->otra['institucion']->id, function () use ($ajena) {
            $this->assertSame('Musica', DB::table('areas')->where('id', $ajena)->value('nombre'));
            $this->assertSame(1, DB::table('matriculas')->count());
        });
    }

    /**
     * La aplicacion no es la dueña de las tablas, y por eso no puede apagar
     * RLS: una inyeccion de SQL no se lo llevaria por delante.
     */
    public function test_la_aplicacion_no_puede_apagar_rls(): void
    {
        try {
            DB::transaction(fn () => DB::statement('ALTER TABLE areas DISABLE ROW LEVEL SECURITY'));
            $this->fail('La aplicacion pudo apagar RLS.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('must be owner', $e->getMessage());
        }
    }

    /**
     * `users` tiene RLS desde el paso 4a: una consulta suelta solo ve las
     * cuentas de su casa. Es la consulta de Configuracion, con su `orWhere`.
     */
    public function test_las_cuentas_solo_se_ven_en_su_institucion(): void
    {
        $sinCorreo = fn () => DB::table('users')->whereNull('email')->orWhere('email', '')->count();

        // Tres cuentas por casa, ninguna con correo.
        $this->assertSame(3, $sinCorreo());
        $this->assertSame(3, InstitucionActual::mientras($this->otra['institucion']->id, $sinCorreo));
        $this->assertNull(User::find($this->otra['admin']->user_id));
    }

    /**
     * El nombre de usuario es unico DENTRO de cada casa: las dos pueden tener
     * su «admin», y la base sigue negando dos en la misma.
     */
    public function test_el_nombre_de_usuario_se_repite_entre_instituciones_y_no_dentro_de_una(): void
    {
        InstitucionActual::mientras($this->otra['institucion']->id, fn () => $this->persona('admin.santuario', 'administrador', 'Tocayo'));

        try {
            DB::transaction(fn () => $this->persona('admin.santuario', 'administrador', 'Repetido'));
            $this->fail('La base acepto dos cuentas con el mismo usuario en la misma casa.');
        } catch (QueryException $e) {
            $this->assertSame('23505', $e->getCode());
        }
    }

    /**
     * El tope de intentos del login es por cuenta, y una cuenta es de una
     * casa: agotarlo contra el «tocayo» de Guarne no deja sin entrar al de El
     * Santuario.
     */
    public function test_el_tope_de_intentos_no_cruza_de_una_institucion_a_otra(): void
    {
        $this->persona('tocayo', 'administrador', 'Tocayo santuario')->user->update(['password' => 'clave-buena']);
        InstitucionActual::mientras($this->otra['institucion']->id, fn () => $this->persona('tocayo', 'administrador', 'Tocayo guarne'));

        // Diez fallos desde IPs distintas: el tope POR CUENTA, no el de IP.
        foreach (range(1, 10) as $i) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.$i"])
                ->post(self::OTRA.'/entrar', ['username' => 'tocayo', 'password' => 'mala']);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.99'])
            ->post(self::OTRA.'/entrar', ['username' => 'tocayo', 'password' => 'mala'])
            ->assertSessionHas('error');

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.99'])
            ->post(self::CASA.'/entrar', ['username' => 'tocayo', 'password' => 'clave-buena'])
            ->assertSessionHasNoErrors();
        $this->assertAuthenticated();
    }

    /**
     * Se entra por el dominio de SU casa. La misma cuenta, por el de la otra,
     * no existe: el login ni la encuentra.
     */
    public function test_se_entra_solo_por_el_dominio_de_la_propia_casa(): void
    {
        $credenciales = ['username' => 'admin.guarne', 'password' => 'clave-de-guarne'];

        $this->post(self::CASA.'/entrar', $credenciales)->assertSessionHasErrors();
        $this->assertGuest();

        $this->post(self::OTRA.'/entrar', $credenciales)->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($this->otra['admin']->user);
    }

    /**
     * Una sesion de otra casa no vale en este dominio: la cuenta de la sesion
     * se carga con RLS, en la casa del dominio, y no aparece. En la vida real
     * ni siquiera llega (las cookies son de cada host); esto cubre a quien
     * copie la cookie. Con una sesion de verdad y no con `actingAs`, que pone
     * la cuenta en el guard sin pasar por la base.
     */
    public function test_una_sesion_de_otra_institucion_no_vale_en_este_dominio(): void
    {
        $sesion = [auth()->guard()->getName() => $this->otra['admin']->user_id];

        $this->withSession($sesion)
            ->get(self::CASA.'/mi-perfil')
            ->assertRedirect(route('login'));

        // Y la otra mitad: la misma sesion, en su dominio, si entra.
        $this->withSession($sesion)
            ->get(self::OTRA.'/mi-perfil')
            ->assertOk()
            ->assertSee('Casa de Guarne');
    }

    public function test_un_dominio_que_no_es_de_nadie_da_404(): void
    {
        $this->get('http://otra.localhost/entrar')->assertNotFound();
        $this->get('http://a.guarne.localhost/entrar')->assertNotFound();
        $this->get('http://localhost/entrar')->assertNotFound();
    }

    public function test_una_institucion_con_dominio_propio_entra_por_el(): void
    {
        $this->otra['institucion']->update(['dominio_propio' => 'matriculas.guarne.gov.co']);

        $this->get('http://matriculas.guarne.gov.co/entrar')
            ->assertOk()
            ->assertSee('Casa de Guarne');

        // Y el subdominio sigue sirviendo.
        $this->get(self::OTRA.'/entrar')->assertOk()->assertSee('Casa de Guarne');
    }

    /**
     * Decision del usuario (02/10/2026): suspendida, todas sus pantallas dicen
     * «servicio suspendido»; no se borra nada y la otra casa sigue igual.
     */
    public function test_una_institucion_suspendida_no_atiende_y_la_otra_si(): void
    {
        $this->otra['institucion']->update(['estado' => Institucion::SUSPENDIDA]);

        $this->get(self::OTRA.'/entrar')->assertStatus(503)->assertSee('Servicio suspendido');
        $this->post(self::OTRA.'/entrar', ['username' => 'admin.guarne', 'password' => 'clave-de-guarne'])->assertStatus(503);
        $this->assertGuest();

        // Su logo se sigue sirviendo: lo pide la propia pantalla.
        $this->get(self::OTRA.'/logo')->assertOk();

        $this->get(self::CASA.'/entrar')->assertOk()->assertDontSee('Servicio suspendido');
    }

    /**
     * Sin dominio base, la instalacion es de una sola casa: todo host es de la
     * institucion por defecto, y el token de otra no existe.
     */
    public function test_sin_dominio_base_todo_es_de_la_institucion_por_defecto(): void
    {
        config(['institucion.dominio_base' => null]);

        $this->get('http://cualquiera.example/entrar')
            ->assertOk()
            ->assertSee('Casa de El Santuario');
        $this->get('/unirse/'.$this->enlaceDeLaOtra())->assertNotFound();
    }

    /**
     * Una casa minima: departamento, periodo en curso, promotoria con profesor,
     * un administrador y un estudiante matriculado. Los nombres se repiten
     * entre casas a proposito; solo el sufijo de las personas cambia.
     *
     * @return array<string, mixed>
     */
    private function montar(string $sufijo): array
    {
        $area = Area::create(['nombre' => 'Musica']);
        $periodo = Periodo::create([
            'nombre' => '2026-1',
            'fecha_inicio' => '2026-01-15',
            'fecha_fin' => '2026-06-30',
            'activo' => true,
            'matriculas_abiertas' => true,
        ]);
        $admin = $this->persona("admin.$sufijo", 'administrador', "Admin $sufijo");
        $profesor = $this->persona("profe.$sufijo", 'profesor', "Profe $sufijo");
        $estudiante = $this->persona("est.$sufijo", 'estudiante', "Estudiante $sufijo");
        DatosEstudiante::create(['perfil_id' => $estudiante->id, 'documento_identidad' => '10203040']);

        $promotoria = Promotoria::create([
            'nombre' => 'Violin',
            'area_id' => $area->id,
            'profesor_id' => $profesor->id,
        ]);
        Matricula::create([
            'estudiante_id' => $estudiante->id,
            'promotoria_id' => $promotoria->id,
            'periodo_id' => $periodo->id,
            'estado' => Matricula::ACTIVA,
        ]);

        return compact('area', 'periodo', 'admin', 'profesor', 'estudiante', 'promotoria');
    }

    private function persona(string $usuario, string $rol, string $nombre): Perfil
    {
        // La clave del admin de Guarne es conocida: con ella se prueba el login.
        $clave = $usuario === 'admin.guarne' ? 'clave-de-guarne' : Str::random(12);
        $user = User::create(['username' => $usuario, 'password' => $clave, 'activo' => true]);

        // La cuenta va puesta en el perfil: con RLS en `users`, cargarla
        // despues, fuera de su casa, no la encontraria.
        return Perfil::create([
            'user_id' => $user->id,
            'rol' => $rol,
            'nombre_completo' => $nombre,
            'fecha_nacimiento' => Carbon::today()->subYears(30)->toDateString(),
            'telefono' => '3000000000',
        ])->setRelation('user', $user);
    }

    private function enlaceDeLaOtra(): string
    {
        return InstitucionActual::mientras($this->otra['institucion']->id, function () {
            $this->otra['promotoria']->abrirEnlace(true);

            return $this->otra['promotoria']->fresh()->enlace_token;
        });
    }
}
