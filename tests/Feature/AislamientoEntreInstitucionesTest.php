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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Dos instituciones en la misma base: ninguna ve, toca ni cuenta lo de la otra.
 *
 * Es el criterio de terminado del paso 1 de multi-institucion. Cada prueba
 * monta las dos casas con los MISMOS nombres —«Musica», «2026-1», «Violin»—
 * porque es el caso real (dos casas de la cultura se parecen) y porque asi un
 * filtro que falte no se esconde detras de un nombre distinto.
 */
class AislamientoEntreInstitucionesTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $casa;

    /** @var array<string, mixed> */
    private array $otra;

    protected function setUp(): void
    {
        parent::setUp();

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
            ->post('/gestion/areas/nueva', ['nombre' => 'Danza'])
            ->assertSessionHasNoErrors();

        $danza = Area::sinFiltroDeInstitucion()->where('nombre', 'Danza')->sole();
        $this->assertSame($this->otra['institucion']->id, $danza->institucionId());
    }

    public function test_el_mismo_nombre_se_repite_entre_instituciones_y_no_dentro_de_una(): void
    {
        // «Musica» ya existe en las dos (lo monta setUp); dentro de una, no.
        $this->actingAs($this->otra['admin']->user)
            ->post('/gestion/areas/nueva', ['nombre' => 'Musica'])
            ->assertSessionHasErrors('nombre');
    }

    public function test_la_gestion_de_una_no_abre_las_filas_de_la_otra(): void
    {
        $admin = $this->actingAs($this->otra['admin']->user);

        $admin->get('/gestion/promotorias/'.$this->casa['promotoria']->id.'/editar')->assertNotFound();
        $admin->get('/gestion/areas/'.$this->casa['area']->id.'/editar')->assertNotFound();
        $admin->post('/gestion/promotorias/'.$this->casa['promotoria']->id.'/eliminar')->assertNotFound();
        $this->assertTrue(Promotoria::sinFiltroDeInstitucion()->whereKey($this->casa['promotoria']->id)->exists());

        $admin->get('/gestion/promotorias/'.$this->otra['promotoria']->id.'/editar')->assertOk();
    }

    public function test_las_listas_de_gestion_solo_ensenan_lo_suyo(): void
    {
        $this->actingAs($this->otra['admin']->user)
            ->get('/gestion/usuarios')
            ->assertOk()
            ->assertSee('Estudiante guarne')
            ->assertDontSee('Estudiante santuario');
    }

    public function test_la_validacion_no_acepta_ids_de_la_otra_institucion(): void
    {
        // Crear un grupo en una promotoria AJENA: el `exists` suelto la
        // habria aceptado, porque la fila existe en la base.
        $this->actingAs($this->otra['admin']->user)
            ->post('/gestion/grupos/nuevo', [
                'promotoria_id' => $this->casa['promotoria']->id,
                'nivel' => 'basico',
                'nombre' => 'Intruso',
            ])
            ->assertSessionHasErrors('promotoria_id');

        $this->assertSame(0, Grupo::sinFiltroDeInstitucion()->where('nombre', 'Intruso')->count());
    }

    public function test_el_documento_de_identidad_es_unico_dentro_de_cada_institucion(): void
    {
        // La misma persona puede inscribirse en dos municipios.
        $this->assertSame(
            DatosEstudiante::sinFiltroDeInstitucion()->where('perfil_id', $this->casa['estudiante']->id)->value('documento_identidad'),
            DatosEstudiante::sinFiltroDeInstitucion()->where('perfil_id', $this->otra['estudiante']->id)->value('documento_identidad'),
        );
    }

    public function test_la_marca_es_la_de_la_institucion_de_la_sesion(): void
    {
        $this->actingAs($this->otra['admin']->user)
            ->get('/mi-perfil')
            ->assertOk()
            ->assertSee('Casa de Guarne')
            ->assertDontSee('Casa de El Santuario');

        // Y la otra mitad, sin la cual esta prueba pasaba con el filtro
        // apagado: sin sesion, la marca es la de la institucion por defecto.
        $this->flushSession();
        auth()->logout();
        $this->get('/entrar')
            ->assertOk()
            ->assertSee('Casa de El Santuario')
            ->assertDontSee('Casa de Guarne');
    }

    public function test_el_enlace_publico_de_una_promotoria_trae_su_institucion(): void
    {
        $token = InstitucionActual::mientras($this->otra['institucion']->id, function () {
            $this->otra['promotoria']->abrirEnlace(true);

            return $this->otra['promotoria']->fresh()->enlace_token;
        });

        // Sin sesion, la institucion por defecto es la 1; el enlace manda.
        $this->get('/unirse/'.$token)
            ->assertOk()
            ->assertSee('Casa de Guarne')
            ->assertDontSee('Casa de El Santuario');
    }

    public function test_el_enlace_de_otra_institucion_no_existe_para_quien_tiene_sesion_aqui(): void
    {
        $token = InstitucionActual::mientras($this->otra['institucion']->id, function () {
            $this->otra['promotoria']->abrirEnlace(true);

            return $this->otra['promotoria']->fresh()->enlace_token;
        });

        $this->actingAs($this->casa['estudiante']->user)
            ->get('/unirse/'.$token)
            ->assertNotFound();
    }

    public function test_las_cifras_sin_modelo_no_suman_la_otra_institucion(): void
    {
        // El informe de la institucion se arma con consultas que no hidratan
        // modelos (`InstitucionActual::tabla()`), que es donde un filtro se
        // olvida sin que nada falle.
        $csv = $this->actingAs($this->otra['admin']->user)
            ->get('/informes/institucion')
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Estudiante guarne', $csv);
        $this->assertStringNotContainsString('Estudiante santuario', $csv);
    }

    public function test_un_or_detras_del_filtro_no_se_sale_de_la_institucion(): void
    {
        // Sin agrupar, esto seria `institucion = 1 AND nombre = 'Musica' OR
        // nombre = 'Musica'`, y la rama del OR traeria la «Musica» de Guarne.
        $ids = InstitucionActual::tabla('areas')
            ->where('nombre', 'Musica')
            ->orWhere('nombre', 'Musica')
            ->pluck('id')->all();

        $this->assertSame([$this->casa['area']->id], $ids);
    }

    public function test_sin_sesion_ni_institucion_por_defecto_no_se_consulta_nada(): void
    {
        config(['institucion.por_defecto' => null]);

        $this->expectException(RuntimeException::class);

        Area::count();
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
        $user = User::create(['username' => $usuario, 'password' => Str::random(12), 'activo' => true]);

        return Perfil::create([
            'user_id' => $user->id,
            'rol' => $rol,
            'nombre_completo' => $nombre,
            'fecha_nacimiento' => Carbon::today()->subYears(30)->toDateString(),
            'telefono' => '3000000000',
        ]);
    }
}
