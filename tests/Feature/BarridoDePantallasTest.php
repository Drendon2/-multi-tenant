<?php

namespace Tests\Feature;

use App\Models\Actividad;
use App\Models\Area;
use App\Models\Clase;
use App\Models\DatosEstudiante;
use App\Models\DocumentoEstudiante;
use App\Models\DocumentoRequerido;
use App\Models\Grupo;
use App\Models\InscritoActividad;
use App\Models\Institucion;
use App\Models\InstitucionExterna;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Models\SesionActividad;
use App\Models\User;
use App\Support\InstitucionActual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * EL BARRIDO (paso 5, 02/10/2026): todas las pantallas, con dos casas pobladas.
 *
 * Las pruebas de aislamiento miran unas pocas pantallas escogidas; esta las
 * recorre TODAS, leyendo las rutas reales, asi que una pantalla nueva entra
 * sola. Dos mitades:
 *
 * 1. Cada rol de una casa —administrador, director, profesor, estudiante— abre
 *    por su dominio cada pantalla GET sin parametros. Ninguna revienta, y en
 *    ninguna aparece «Señuelo», que solo llevan los datos de la otra casa.
 * 2. El administrador abre cada pantalla CON parametros usando los ids de la
 *    OTRA casa. Ninguna contesta 200: RLS no encuentra la fila.
 *
 * El catalogo se llama igual en las dos casas (es el caso real, y asi un
 * filtro que falte no se esconde detras de un nombre distinto); la marca va en
 * las PERSONAS y en lo que se ve de cada fila.
 */
class BarridoDePantallasTest extends TestCase
{
    use RefreshDatabase;

    private const CASA = 'http://santuario.localhost';

    private const MARCA = 'Señuelo';

    /**
     * Pantallas que no se visitan, cada una con su porque. Nada mas.
     */
    private const FUERA = [
        // La salud del servidor: no es del grupo `web` ni de ninguna casa.
        'up',
    ];

    /** @var array<string, Perfil> */
    private array $casa = [];

    /** @var array<string, int> los ids de la otra casa, por parametro */
    private array $ajenos = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['institucion.dominio_base' => 'localhost']);
        $this->institucionDePrueba->update(['subdominio' => 'santuario']);
        $this->casa = $this->montar('');

        $otra = Institucion::create(['nombre' => 'Casa de al lado', 'subdominio' => 'guarne']);
        InstitucionActual::mientras($otra->id, function () {
            $this->montar(' '.self::MARCA);
        });
    }

    public function test_ninguna_pantalla_ensena_lo_de_la_otra_casa_a_ningun_rol(): void
    {
        $rutas = $this->rutasSinParametros();
        $this->assertGreaterThan(40, count($rutas), 'El barrido no encontro las pantallas.');

        $fallos = [];
        $vistas = 0;

        foreach (['administrador', 'director', 'profesor', 'estudiante'] as $rol) {
            foreach ($rutas as $uri) {
                $respuesta = $this->actingAs($this->casa[$rol]->user)->get(self::CASA.'/'.$uri);
                $estado = $respuesta->getStatusCode();

                if ($estado >= 500) {
                    $fallos[] = "$rol GET /$uri: $estado";

                    continue;
                }

                if ($estado === 200) {
                    $vistas++;

                    if (str_contains($this->contenido($respuesta), self::MARCA)) {
                        $fallos[] = "$rol GET /$uri ensena datos de la otra casa";
                    }
                }
            }
        }

        $this->assertSame([], $fallos, implode("\n", $fallos));
        // Que el barrido de verdad VEA pantallas: sin esto, un login roto
        // dejaria todo en redirecciones y la prueba en verde.
        // Medido el 02/10/2026: 80 de 268 visitas (cada rol ve solo las suyas).
        $this->assertGreaterThan(60, $vistas, "Solo $vistas pantallas contestaron 200.");
    }

    public function test_ninguna_pantalla_abre_una_fila_de_la_otra_casa(): void
    {
        $fallos = [];
        $probadas = 0;

        foreach ($this->rutasConParametros() as $uri) {
            $url = $this->conIdsAjenos($uri);

            if ($url === null) {
                continue;
            }

            $probadas++;
            $respuesta = $this->actingAs($this->casa['administrador']->user)->get(self::CASA.'/'.$url);
            $estado = $respuesta->getStatusCode();

            if ($estado >= 500 || $estado === 200) {
                $fallos[] = "GET /$url ($uri): $estado";
            }
        }

        $this->assertSame([], $fallos, implode("\n", $fallos));
        $this->assertGreaterThan(40, $probadas, "Solo se probaron $probadas pantallas con parametros.");
    }

    // ------------------------------------------------------------------

    /** @return list<string> */
    private function rutasSinParametros(): array
    {
        return $this->rutasWeb()
            ->reject(fn (string $uri) => str_contains($uri, '{'))
            ->values()->all();
    }

    /** @return list<string> */
    private function rutasConParametros(): array
    {
        return $this->rutasWeb()
            ->filter(fn (string $uri) => str_contains($uri, '{'))
            ->values()->all();
    }

    /** @return Collection<int, string> */
    private function rutasWeb()
    {
        return collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn (Route $r) => in_array('GET', $r->methods(), true))
            ->filter(fn (Route $r) => in_array('web', $r->gatherMiddleware(), true))
            ->map(fn (Route $r) => $r->uri())
            ->reject(fn (string $uri) => in_array($uri, self::FUERA, true))
            ->unique();
    }

    /**
     * La URL con cada parametro puesto al id de una fila de la OTRA casa, o
     * null si algun parametro no es un id de algo (un token, un tipo).
     */
    private function conIdsAjenos(string $uri): ?string
    {
        $segmentos = explode('/', $uri);
        $resultado = [];

        foreach ($segmentos as $i => $segmento) {
            if (! preg_match('/^\{(\w+)\??\}$/', $segmento, $m)) {
                $resultado[] = $segmento;

                continue;
            }

            $clave = $m[1] === 'objeto'
                ? self::OBJETO_POR_PANTALLA[$segmentos[$i - 1]] ?? null
                : $m[1];

            if ($clave === null || ! isset($this->ajenos[$clave])) {
                return null;
            }

            $resultado[] = $this->ajenos[$clave];
        }

        return implode('/', $resultado);
    }

    /** A que modelo apunta `{objeto}` en cada catalogo de Gestion. */
    private const OBJETO_POR_PANTALLA = [
        'areas' => 'area',
        'cursos' => 'actividad',
        'externos' => 'externo',
        'grupos' => 'grupo',
        'instituciones' => 'institucion',
        'periodos' => 'periodo',
        'promotorias' => 'promotoria',
        'proyeccion' => 'proyeccion',
    ];

    private function contenido(TestResponse $respuesta): string
    {
        return $respuesta->baseResponse instanceof StreamedResponse
            ? $respuesta->streamedContent()
            : (string) $respuesta->getContent();
    }

    /**
     * Una casa con un poco de todo, en la institucion actual. Los nombres de
     * las personas y de lo que se ve de cada fila llevan `$marca`; el
     * catalogo no.
     *
     * @return array<string, Perfil>
     */
    private function montar(string $marca): array
    {
        $area = Area::create(['nombre' => 'Musica']);
        $periodo = Periodo::create([
            'nombre' => '2026-1', 'fecha_inicio' => Carbon::today()->subMonth()->toDateString(),
            'fecha_fin' => Carbon::today()->addMonths(4)->toDateString(),
            'activo' => true, 'matriculas_abiertas' => true,
        ]);

        $admin = $this->persona("Admin{$marca}", 'administrador');
        $director = $this->persona("Director{$marca}", 'director');
        $director->areasDirigidas()->sync([$area->id]);
        $profesor = $this->persona("Profe{$marca}", 'profesor');
        $estudiante = $this->persona("Estudiante{$marca}", 'estudiante');

        $promotoria = Promotoria::create(['nombre' => 'Violin', 'area_id' => $area->id, 'profesor_id' => $profesor->id]);
        $grupo = Grupo::create([
            'promotoria_id' => $promotoria->id, 'nombre' => "Lunes{$marca}", 'nivel' => 'basico',
            'salon' => 'A1', 'cupo_maximo' => 10,
        ]);
        $matricula = Matricula::create([
            'estudiante_id' => $estudiante->id, 'promotoria_id' => $promotoria->id,
            'periodo_id' => $periodo->id, 'estado' => Matricula::ACTIVA,
        ]);
        $matricula->repartirEn([$grupo->id]);
        $clase = Clase::create([
            'grupo_id' => $grupo->id, 'periodo_id' => $periodo->id,
            'fecha_hora' => Carbon::now()->subDay(), 'registrada_por_id' => $profesor->id,
        ]);

        $datos = DatosEstudiante::create(['perfil_id' => $estudiante->id, 'documento_identidad' => (string) random_int(10000000, 99999999)]);
        $papel = DocumentoRequerido::create(['nombre' => 'Cedula']);
        $entrega = DocumentoEstudiante::create([
            'datos_estudiante_id' => $datos->id, 'requerido_id' => $papel->id,
            'archivo' => 'documentos/no-existe.pdf', 'subido' => Carbon::now(),
        ]);

        $taller = Actividad::create(['nombre' => "Taller{$marca}", 'tipo' => Actividad::TALLER, 'responsable_id' => $profesor->id]);
        $inscrito = InscritoActividad::create([
            'actividad_id' => $taller->id, 'nombre_completo' => "Inscrito{$marca}", 'origen' => InscritoActividad::ENLACE,
        ]);
        $sesion = SesionActividad::create(['actividad_id' => $taller->id, 'fecha' => Carbon::today()->toDateString()]);
        $proyeccion = Actividad::create(['nombre' => "Coro{$marca}", 'tipo' => Actividad::PROYECCION, 'responsable_id' => $profesor->id]);

        $funcionario = $this->persona("Funcionario{$marca}", 'institucion_externa');
        $escuela = InstitucionExterna::create(['nombre' => "Escuela{$marca}", 'perfil_id' => $funcionario->id]);
        $externo = Actividad::create([
            'nombre' => "Programa{$marca}", 'tipo' => Actividad::EXTERNO,
            'responsable_id' => $profesor->id, 'institucion_externa_id' => $escuela->id,
        ]);

        // Los ids que se usan como «ajenos»: los de la ultima casa montada.
        $this->ajenos = [
            'actividad' => $taller->id, 'inscrito' => $inscrito->id, 'estudiante' => $estudiante->id,
            'matricula' => $matricula->id, 'entrega' => $entrega->id, 'perfil' => $estudiante->id,
            'area' => $area->id, 'periodo' => $periodo->id, 'grupo' => $grupo->id,
            'institucion' => $escuela->id, 'promotoria' => $promotoria->id, 'usuario' => $estudiante->id,
            'clase' => $clase->id, 'sesion' => $sesion->id, 'externo' => $externo->id,
            'proyeccion' => $proyeccion->id,
        ];

        return ['administrador' => $admin, 'director' => $director, 'profesor' => $profesor, 'estudiante' => $estudiante];
    }

    private function persona(string $nombre, string $rol): Perfil
    {
        $user = User::create(['username' => strtolower(str_replace(' ', '.', $nombre)).'.'.$rol, 'password' => 'x', 'activo' => true]);

        return Perfil::create([
            'user_id' => $user->id, 'rol' => $rol, 'nombre_completo' => $nombre,
            'fecha_nacimiento' => Carbon::today()->subYears(30)->toDateString(), 'telefono' => '3000000000',
        ])->setRelation('user', $user);
    }
}
