<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\DatosEstudiante;
use App\Models\DocumentoRequerido;
use App\Models\EncuestaDemografica;
use App\Models\Institucion;
use App\Models\Matricula;
use App\Models\Operador;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Models\User;
use App\Support\InstitucionActual;
use App\Support\ResumenGlobal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * El resumen de todas las instituciones y sus descargas consolidadas (paso 5,
 * 02/10/2026): poblacion impactada, promotorias, datos demograficos y
 * profesores por promotoria, institucion por institucion y sumados.
 *
 * Las dos casas se montan con los MISMOS nombres de catalogo y personas
 * distintas, como en `AislamientoEntreInstitucionesTest`: un recorrido que se
 * quedara en una sola institucion no se esconderia detras de un nombre.
 */
class ResumenGeneralTest extends TestCase
{
    use RefreshDatabase;

    private const PANEL = 'http://panel.localhost';

    private Operador $operador;

    private Institucion $otra;

    protected function setUp(): void
    {
        parent::setUp();

        config(['institucion.dominio_base' => 'localhost']);
        $this->institucionDePrueba->update(['nombre' => 'Casa de El Santuario', 'subdominio' => 'santuario']);
        $this->montar('Ana Santuario', 'f', 'Profe Santuario', 'Cédula');

        $this->otra = Institucion::create(['nombre' => 'Casa de Guarne', 'subdominio' => 'guarne']);
        InstitucionActual::mientras($this->otra->id, fn () => $this->montar('Beto Guarne', 'm', 'Profe Guarne', 'Registro civil'));

        $this->operador = Operador::create([
            'usuario' => 'soporte', 'nombre' => 'Soporte', 'password' => 'clave-del-operador', 'activo' => true,
        ]);
    }

    public function test_las_cifras_de_cada_una_y_la_suma(): void
    {
        InstitucionActual::ninguna();
        $filas = collect(ResumenGlobal::porInstitucion())->keyBy(fn ($f) => $f['institucion']->nombre);

        $this->assertSame(1, $filas['Casa de El Santuario']['cifras']['estudiantesActivos']);
        $this->assertSame(1, $filas['Casa de Guarne']['cifras']['estudiantesActivos']);
        $this->assertSame('2026-1', $filas['Casa de Guarne']['periodo']);
        // La 1 existe siempre y aqui esta vacia: cuenta como una institucion
        // mas, con sus ceros.
        $this->assertSame(0, $filas['Institución']['cifras']['estudiantesActivos']);

        $totales = ResumenGlobal::totales($filas->values()->all());
        $this->assertSame(2, $totales['estudiantesActivos']);
        $this->assertSame(2, $totales['poblacionImpactada']);
        $this->assertSame(2, $totales['profesores']);
        $this->assertSame(2, $totales['promotorias']);

        // Y el panel sigue siendo de ninguna despues de recorrerlas.
        $this->assertNull(InstitucionActual::idSiSeSabe());
    }

    /**
     * Las descargas van saliendo fila a fila: `recorriendo()` fija la
     * institucion al pedir la primera, y al acabar devuelve al panel a
     * ninguna.
     */
    public function test_recorrer_una_institucion_la_fija_solo_mientras_dura(): void
    {
        InstitucionActual::ninguna();

        $vistas = iterator_to_array(InstitucionActual::recorriendo(
            $this->otra->id,
            fn () => [InstitucionActual::idSiSeSabe(), Periodo::count()]
        ));

        $this->assertSame([$this->otra->id, 1], $vistas);
        $this->assertNull(InstitucionActual::idSiSeSabe());
    }

    public function test_la_encuesta_se_suma_opcion_a_opcion(): void
    {
        $demografia = ResumenGlobal::demografia();

        $this->assertSame(2, $demografia['todas']['total']);
        $this->assertSame(1, $demografia['todas']['conteos']['genero']['f']);
        $this->assertSame(1, $demografia['todas']['conteos']['genero']['m']);
        $this->assertSame(2, $demografia['todas']['conteos']['estrato'][2]);
    }

    /**
     * Estadisticas de UNA institucion cuenta solo su encuesta. La cuenta vive
     * desde el paso 5 en `ResumenDemografico`, compartida con el panel, y
     * ninguna otra prueba miraba estas graficas.
     */
    public function test_estadisticas_de_una_institucion_cuenta_solo_su_encuesta(): void
    {
        $admin = InstitucionActual::mientras($this->otra->id, fn () => $this->persona('Admin de Guarne', 'administrador'));

        $respuesta = $this->actingAs($admin->user)
            ->get('http://guarne.localhost/gestion/estadisticas')
            ->assertOk();

        $estrato2 = collect($respuesta->viewData('estratoStats'))->firstWhere('etiqueta', 'Estrato 2');
        $this->assertSame(1, $estrato2['total']);
        $this->assertSame(1, $respuesta->viewData('totalEncuestas'));
        $masculino = collect($respuesta->viewData('generoTorta')['leyenda'])->firstWhere('etiqueta', 'Masculino');
        $this->assertSame(1, $masculino['total']);
    }

    public function test_la_pantalla_ensena_las_dos_y_sus_profesores(): void
    {
        $this->actingAs($this->operador, 'operador')
            ->get(self::PANEL.'/instituciones/resumen')
            ->assertOk()
            ->assertSee('Casa de El Santuario')
            ->assertSee('Casa de Guarne')
            ->assertSee('Profe Santuario')
            ->assertSee('Profe Guarne')
            ->assertSee('Víctima del conflicto armado')
            // Agregado: ningun nombre de quien contesto la encuesta.
            ->assertDontSee('Ana Santuario')
            ->assertDontSee('Beto Guarne');
    }

    /** El resumen es del panel: ni sin sesion ni en el host de una casa. */
    public function test_solo_lo_ve_un_operador_en_el_panel(): void
    {
        $this->get(self::PANEL.'/instituciones/resumen')->assertRedirect(self::PANEL.'/instituciones/entrar');
        $this->get(self::PANEL.'/instituciones/resumen/personas.csv')->assertRedirect(self::PANEL.'/instituciones/entrar');

        $this->actingAs($this->operador, 'operador')
            ->get('http://santuario.localhost/instituciones/resumen/personas.csv')
            ->assertNotFound();
    }

    public function test_la_descarga_del_resumen_trae_cada_una_y_los_totales(): void
    {
        $csv = $this->descargar('resumen.csv');

        $this->assertStringContainsString('Casa de El Santuario', $csv);
        $this->assertStringContainsString('Casa de Guarne', $csv);
        $this->assertStringContainsString('http://guarne.localhost/', $csv);
        $todas = collect($this->filas($csv))->first(fn (array $f) => $f[0] === 'Todas');
        $this->assertSame(['2', '2'], array_slice($todas, 4, 2));
    }

    public function test_la_descarga_de_promotorias_trae_las_de_las_dos(): void
    {
        $filas = array_map(fn ($f) => array_slice($f, 0, 4), $this->filas($this->descargar('promotorias.csv')));

        $this->assertContains(['Casa de El Santuario', 'Musica', 'Violin', 'Profe Santuario'], $filas);
        $this->assertContains(['Casa de Guarne', 'Musica', 'Violin', 'Profe Guarne'], $filas);
    }

    public function test_la_descarga_demografica_cuenta_por_institucion_y_todas(): void
    {
        $csv = $this->descargar('demografia.csv');
        $filas = $this->filas($csv);

        $this->assertContains(['Casa de Guarne', 'Género', 'Masculino', '1', '1'], $filas);
        $this->assertContains(['Casa de El Santuario', 'Género', 'Femenino', '1', '1'], $filas);
        $this->assertContains(['Todas', 'Estrato', 'Estrato 2', '2', '2'], $filas);
        $this->assertStringNotContainsString('Beto', $csv);
    }

    /**
     * El informe completo de cada casa, todas en un archivo, con los papeles en
     * una sola columna: cada institucion pide los suyos y una columna por papel
     * no casaria entre ellas.
     */
    public function test_la_descarga_de_personas_trae_a_todas_con_su_institucion(): void
    {
        $csv = $this->descargar('personas.csv');

        $cabecera = str_getcsv(strtok(ltrim($csv, "\u{FEFF}"), "\n"), ';', '"', '');
        $this->assertSame(['Institución', 'Rol', 'Nombre completo'], array_slice($cabecera, 0, 3));
        $this->assertSame('Papeles entregados', end($cabecera));
        $this->assertStringNotContainsString('Entregó:', $csv);
        $filas = $this->filas($csv);
        $personas = array_map(fn ($f) => array_slice($f, 0, 3), $filas);

        // Cada fila del ancho de la cabecera, y los papeles de SU casa en la
        // ultima columna; al personal no se le piden.
        foreach ($filas as $fila) {
            $this->assertCount(count($cabecera), $fila);
        }
        $papeles = collect($filas)->mapWithKeys(fn ($f) => [$f[2] => end($f)]);
        $this->assertSame('Cédula: No', $papeles['Ana Santuario']);
        $this->assertSame('Registro civil: No', $papeles['Beto Guarne']);
        $this->assertSame('', $papeles['Profe Guarne']);

        $this->assertContains(['Casa de El Santuario', 'Estudiante', 'Ana Santuario'], $personas);
        $this->assertContains(['Casa de Guarne', 'Estudiante', 'Beto Guarne'], $personas);
        $this->assertContains(['Casa de Guarne', 'Profesor', 'Profe Guarne'], $personas);
    }

    /** En este informe «Institución» era la externa; la primera ya se llama asi. */
    public function test_la_descarga_de_actividades_no_repite_la_cabecera(): void
    {
        $csv = $this->descargar('actividades.csv');
        $cabecera = str_getcsv(strtok(ltrim($csv, "\u{FEFF}"), "\n"), ';', '"', '');

        $this->assertSame('Institución', $cabecera[0]);
        $this->assertContains('Institución externa', $cabecera);
        $this->assertSame(count($cabecera), count(array_unique($cabecera)));
    }

    /**
     * Las filas del CSV ya leidas, sin la cabecera: `fputcsv` entrecomilla lo
     * que lleva espacios, y comparar el texto crudo ataria la prueba a eso.
     *
     * @return list<list<string>>
     */
    private function filas(string $csv): array
    {
        $lineas = array_filter(explode("\n", ltrim($csv, "\u{FEFF}")));
        array_shift($lineas);

        return array_values(array_map(fn ($l) => str_getcsv($l, ';', '"', ''), $lineas));
    }

    private function descargar(string $archivo): string
    {
        return $this->actingAs($this->operador, 'operador')
            ->get(self::PANEL.'/instituciones/resumen/'.$archivo)
            ->assertOk()
            ->streamedContent();
    }

    /**
     * Una casa minima en la institucion actual: departamento, periodo en
     * curso, una promotoria con su profesor y un estudiante matriculado que
     * contesto la encuesta.
     */
    private function montar(string $estudiante, string $genero, string $profesor, string $papel): void
    {
        // Cada casa pide un papel distinto: es lo que impide que el
        // consolidado lleve una columna por papel.
        DocumentoRequerido::create(['nombre' => $papel]);

        $area = Area::create(['nombre' => 'Musica']);
        $periodo = Periodo::create([
            'nombre' => '2026-1', 'fecha_inicio' => '2026-01-15', 'fecha_fin' => '2026-06-30',
            'activo' => true, 'matriculas_abiertas' => true,
        ]);
        $profe = $this->persona($profesor, 'profesor');
        $alumno = $this->persona($estudiante, 'estudiante');
        DatosEstudiante::create(['perfil_id' => $alumno->id, 'documento_identidad' => (string) random_int(10000000, 99999999)]);

        $promotoria = Promotoria::create(['nombre' => 'Violin', 'area_id' => $area->id, 'profesor_id' => $profe->id]);
        Matricula::create([
            'estudiante_id' => $alumno->id, 'promotoria_id' => $promotoria->id,
            'periodo_id' => $periodo->id, 'estado' => Matricula::ACTIVA,
        ]);
        EncuestaDemografica::create([
            'perfil_id' => $alumno->id, 'genero' => $genero, 'barrio' => 'Centro', 'estrato' => 2,
            'nivel_educativo' => 'primaria_com', 'ocupacion' => 'estudiante',
        ]);
    }

    private function persona(string $nombre, string $rol): Perfil
    {
        $user = User::create(['username' => strtolower(str_replace(' ', '.', $nombre)), 'password' => 'x', 'activo' => true]);

        return Perfil::create([
            'user_id' => $user->id, 'rol' => $rol, 'nombre_completo' => $nombre,
            'fecha_nacimiento' => Carbon::today()->subYears(30)->toDateString(), 'telefono' => '3000000000',
        ])->setRelation('user', $user);
    }
}
