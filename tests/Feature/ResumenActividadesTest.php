<?php

namespace Tests\Feature;

use App\Models\Actividad;
use App\Models\Area;
use App\Models\AsistenciaActividad;
use App\Models\InscritoActividad;
use App\Models\InstitucionExterna;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Models\SesionActividad;
use App\Models\User;
use App\Support\ResumenActividades;
use App\Support\VerificacionExterna;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La gente SIN matricula en Estadisticas (25/09/2026).
 *
 * Lo que vigila: que cada tipo vaya por su lado, que una actividad sin periodo
 * caiga en el periodo de sus sesiones —y solo con las sesiones de ese
 * periodo—, que una sesion sin marcas no cuente, que las dos verificaciones
 * de un programa externo no se colapsen, y que el cruce con matriculados
 * cuente solo a quien esta matriculado EN ESE periodo.
 *
 * Las fechas van escritas y no deducidas de hoy: una prueba que hereda el
 * calendario se pone roja sola algun dia.
 */
class ResumenActividadesTest extends TestCase
{
    use RefreshDatabase;

    private Periodo $periodo;

    private Periodo $anterior;

    private Perfil $admin;

    private Perfil $profesor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->anterior = Periodo::create([
            'nombre' => '2026-1',
            'fecha_inicio' => '2026-01-15',
            'fecha_fin' => '2026-06-15',
            'activo' => false,
        ]);

        $this->periodo = Periodo::create([
            'nombre' => '2026-2',
            'fecha_inicio' => '2026-07-15',
            'fecha_fin' => '2026-12-15',
            'activo' => true,
        ]);

        $this->admin = $this->perfil('jefa', 'administrador');
        $this->profesor = $this->perfil('profe', 'profesor');
    }

    public function test_sin_actividades_no_hay_resumen(): void
    {
        $this->assertNull(ResumenActividades::delPeriodo($this->periodo));
    }

    public function test_cuenta_cada_tipo_por_su_lado(): void
    {
        $curso = $this->actividad(Actividad::CURSO, $this->periodo);
        $ana = $this->inscrito($curso, 'Ana Ruiz');
        $this->inscrito($curso, 'Luis Mora');

        $clase = $this->sesionEn($curso, '2026-08-03');
        $this->marca($clase, $ana, AsistenciaActividad::ASISTIO);
        // Una sesion iniciada SIN marcas no es una sesion con lista.
        $this->sesionEn($curso, '2026-08-10');

        $this->actividad(Actividad::TALLER, $this->periodo);

        $resumen = ResumenActividades::delPeriodo($this->periodo);

        $this->assertSame(['curso', 'taller'], array_keys($resumen['tipos']));
        $this->assertSame(1, $resumen['tipos']['curso']['actividades']);
        $this->assertSame(2, $resumen['tipos']['curso']['inscritos']);
        $this->assertSame(1, $resumen['tipos']['curso']['sesiones']);
        $this->assertSame(1, $resumen['tipos']['curso']['asistencias']);
        $this->assertSame(0, $resumen['tipos']['taller']['inscritos']);
        $this->assertNull($resumen['verificacion']);
    }

    /** Una falta es una marca, pero no una asistencia. */
    public function test_una_falta_toma_lista_pero_no_suma_asistencia(): void
    {
        $curso = $this->actividad(Actividad::CURSO, $this->periodo);
        $clase = $this->sesionEn($curso, '2026-08-03');
        $this->marca($clase, $this->inscrito($curso, 'Ana Ruiz'), 'falto');

        $resumen = ResumenActividades::delPeriodo($this->periodo);

        $this->assertSame(1, $resumen['tipos']['curso']['sesiones']);
        $this->assertSame(0, $resumen['tipos']['curso']['asistencias']);
    }

    public function test_una_actividad_de_otro_periodo_no_cuenta(): void
    {
        $this->actividad(Actividad::CURSO, $this->anterior);

        $this->assertNull(ResumenActividades::delPeriodo($this->periodo));
    }

    /**
     * SIN PERIODO: cae donde cayeron sus sesiones, y con SOLO esas.
     *
     * Un grupo de proyeccion vive todo el ano sin periodo. Sin el corte por
     * fechas apareceria en los dos semestres con todos sus ensayos en cada uno.
     */
    public function test_sin_periodo_cuenta_solo_las_sesiones_del_periodo(): void
    {
        $banda = $this->actividad(Actividad::PROYECCION, null);
        $ana = $this->inscrito($banda, 'Ana Ruiz');

        $this->marca($this->sesionEn($banda, '2026-03-02'), $ana, AsistenciaActividad::ASISTIO);
        $this->marca($this->sesionEn($banda, '2026-09-07'), $ana, AsistenciaActividad::ASISTIO);
        $this->marca($this->sesionEn($banda, '2026-09-14'), $ana, AsistenciaActividad::ASISTIO);

        $ahora = ResumenActividades::delPeriodo($this->periodo);
        $antes = ResumenActividades::delPeriodo($this->anterior);

        $this->assertSame(2, $ahora['tipos']['proyeccion']['sesiones']);
        $this->assertSame(1, $antes['tipos']['proyeccion']['sesiones']);
    }

    public function test_sin_periodo_y_sin_sesiones_no_cae_en_ninguno(): void
    {
        $this->actividad(Actividad::TALLER, null);

        $this->assertNull(ResumenActividades::delPeriodo($this->periodo));
    }

    /** Las dos vias de verificacion NO se colapsan: no dan la misma garantia. */
    public function test_la_verificacion_externa_va_separada_por_origen(): void
    {
        $programa = $this->actividad(Actividad::EXTERNO, $this->periodo);

        $this->sesionEn($programa, '2026-08-03', VerificacionExterna::PROPIA);
        $this->sesionEn($programa, '2026-08-10', VerificacionExterna::QR);
        $this->sesionEn($programa, '2026-08-17', VerificacionExterna::QR);
        $this->sesionEn($programa, '2026-08-24');

        $resumen = ResumenActividades::delPeriodo($this->periodo);

        $this->assertSame(['iniciadas' => 4, 'propia' => 1, 'qr' => 2], $resumen['verificacion']);
    }

    /**
     * EL CRUCE: solo quien esta matriculado EN ESTE periodo, y una sola vez
     * aunque vaya a dos actividades.
     */
    public function test_tambien_matriculados_cuenta_solo_los_del_periodo(): void
    {
        $promotoria = Promotoria::create([
            'nombre' => 'Violin',
            'area_id' => Area::create(['nombre' => 'Musica'])->id,
            'profesor_id' => $this->profesor->id,
        ]);

        $deAhora = $this->perfil('ahora', 'estudiante');
        $deAntes = $this->perfil('antes', 'estudiante');

        foreach ([[$deAhora, $this->periodo], [$deAntes, $this->anterior]] as [$quien, $cuando]) {
            Matricula::create([
                'estudiante_id' => $quien->id,
                'promotoria_id' => $promotoria->id,
                'periodo_id' => $cuando->id,
                'estado' => Matricula::ACTIVA,
            ]);
        }

        $curso = $this->actividad(Actividad::CURSO, $this->periodo);
        $taller = $this->actividad(Actividad::TALLER, $this->periodo);

        $this->inscrito($curso, 'Ahora', $deAhora);
        $this->inscrito($taller, 'Ahora', $deAhora);
        $this->inscrito($curso, 'Antes', $deAntes);
        $this->inscrito($curso, 'Sin cuenta');

        $this->assertSame(1, ResumenActividades::delPeriodo($this->periodo)['tambienMatriculados']);
    }

    public function test_la_pantalla_pinta_la_seccion_aparte(): void
    {
        $programa = $this->actividad(Actividad::EXTERNO, $this->periodo);
        $this->inscrito($programa, 'Ana Ruiz');
        $this->sesionEn($programa, '2026-08-03', VerificacionExterna::QR);

        $this->actingAs($this->admin->user)
            ->get(route('gestion-estadisticas-periodo', $this->periodo))
            ->assertOk()
            ->assertSee('data-tipo-actividad="externo"', false)
            ->assertSee('data-verificacion-externa', false)
            ->assertSee('data-tambien-matriculados', false);
    }

    public function test_sin_actividades_la_pantalla_no_pinta_la_seccion(): void
    {
        $this->actingAs($this->admin->user)
            ->get(route('gestion-estadisticas-periodo', $this->periodo))
            ->assertOk()
            ->assertDontSee('data-tambien-matriculados', false);
    }

    // ------------------------------------------------------------------
    // El informe descargable
    // ------------------------------------------------------------------

    /** Lleva nombres de menores, algunos de otra institucion: solo el admin. */
    public function test_el_informe_de_actividades_es_solo_del_administrador(): void
    {
        foreach (['director' => 'dire', 'profesor' => 'otro', 'estudiante' => 'est'] as $rol => $usuario) {
            $this->actingAs($this->perfil($usuario, $rol)->user)
                ->get(route('informe-actividades'))
                ->assertRedirect(route('post-login'));
        }

        // Ni siquiera el responsable de la actividad: se eligio lo cerrado.
        $this->actividad(Actividad::CURSO, $this->periodo);
        $this->actingAs($this->profesor->user)
            ->get(route('informe-actividades'))
            ->assertRedirect(route('post-login'));
    }

    /**
     * La edad declarada va TAL CUAL y sin fecha inventada, y el porcentaje se
     * TRUNCA sobre las sesiones con lista: 2 de 3 es 66 %, no 67.
     */
    public function test_el_informe_trae_la_edad_sin_calcular_y_el_porcentaje_truncado(): void
    {
        $programa = $this->actividad(Actividad::EXTERNO, $this->periodo);
        /** @var InscritoActividad $nino */
        $nino = $programa->inscritos()->create([
            'nombre_completo' => 'Pepe Perez',
            'edad' => 9,
            'origen' => InscritoActividad::LISTA,
        ]);

        foreach (['2026-08-03' => 'asistio', '2026-08-10' => 'asistio', '2026-08-17' => 'falto'] as $fecha => $estado) {
            $this->marca($this->sesionEn($programa, $fecha), $nino, $estado);
        }
        // Iniciada y sin marcas: no es una sesion con lista.
        $this->sesionEn($programa, '2026-08-24');

        $filas = $this->descargar();
        $fila = array_combine($filas[0], $filas[1]);

        $this->assertCount(2, $filas);
        $this->assertSame('Programa externo', $fila['Tipo']);
        $this->assertSame('I. E. Rural El Carmen', $fila['Institución']);
        $this->assertSame('Lista del programa', $fila['Cómo entró']);
        $this->assertSame('9', $fila['Edad declarada']);
        $this->assertSame('', $fila['Fecha de nacimiento']);
        $this->assertSame('3', $fila['Sesiones con lista']);
        $this->assertSame('2', $fila['Asistió']);
        $this->assertSame('66%', $fila['Asistencia']);
    }

    /** Cabecera y filas del mismo ancho, y el cruce por documento. */
    public function test_el_informe_esta_cuadrado_y_dice_quien_es_estudiante(): void
    {
        $taller = $this->actividad(Actividad::TALLER, null);
        $this->inscrito($taller, 'Con cuenta', $this->perfil('alumna', 'estudiante'));
        $this->inscrito($taller, 'Sin cuenta');

        $filas = $this->descargar();

        foreach ($filas as $fila) {
            $this->assertCount(count($filas[0]), $fila);
        }

        $porNombre = [];
        foreach (array_slice($filas, 1) as $fila) {
            $f = array_combine($filas[0], $fila);
            $porNombre[$f['Nombre completo']] = $f;
        }

        $this->assertSame('Sí', $porNombre['Con cuenta']['Reconocido como estudiante']);
        $this->assertSame('No', $porNombre['Sin cuenta']['Reconocido como estudiante']);
        $this->assertSame('Sin periodo', $porNombre['Sin cuenta']['Periodo']);
        // Sin sesiones con lista el porcentaje va vacio, no «0%»: no es que no
        // fuera, es que nadie paso lista.
        $this->assertSame('', $porNombre['Sin cuenta']['Asistencia']);
    }

    /** @return list<list<string>> */
    private function descargar(): array
    {
        $respuesta = $this->actingAs($this->admin->user)->get(route('informe-actividades'));
        $respuesta->assertOk();

        $texto = ltrim($respuesta->streamedContent(), "\xEF\xBB\xBF");

        $filas = [];
        foreach (preg_split('/\r\n|\n/', trim($texto)) as $linea) {
            if ($linea !== '') {
                $filas[] = str_getcsv($linea, ';', '"', '');
            }
        }

        return $filas;
    }

    // ------------------------------------------------------------------

    private function actividad(string $tipo, ?Periodo $periodo): Actividad
    {
        $datos = [
            'nombre' => 'Actividad '.$tipo,
            'tipo' => $tipo,
            'responsable_id' => $this->profesor->id,
            'periodo_id' => $periodo?->id,
        ];

        if ($tipo === Actividad::EXTERNO) {
            $datos['institucion_id'] = InstitucionExterna::create([
                'nombre' => 'I. E. Rural El Carmen',
                'perfil_id' => $this->perfil('carmen', Perfil::INSTITUCION_EXTERNA)->id,
            ])->id;
        }

        /** @var Actividad $actividad */
        $actividad = Actividad::create($datos);

        return $actividad;
    }

    private function inscrito(Actividad $actividad, string $nombre, ?Perfil $perfil = null): InscritoActividad
    {
        /** @var InscritoActividad $inscrito */
        $inscrito = $actividad->inscritos()->create([
            'nombre_completo' => $nombre,
            'perfil_id' => $perfil?->id,
            'origen' => InscritoActividad::ENLACE,
        ]);

        return $inscrito;
    }

    private function sesionEn(Actividad $actividad, string $fecha, ?string $verificada = null): SesionActividad
    {
        /** @var SesionActividad $sesion */
        $sesion = SesionActividad::create([
            'actividad_id' => $actividad->id,
            'fecha' => $fecha,
            'iniciada_en' => $fecha.' 10:00:00',
            'iniciada_por_id' => $this->profesor->id,
        ]);

        // A mano y no por `create()`: las columnas de la verificacion NO estan
        // en `$fillable` a proposito (solo las escribe `VerificacionExterna`),
        // y el `create()` las descartaria sin avisar.
        if ($verificada) {
            $sesion->forceFill([
                'verificada_en' => $fecha.' 12:00:00',
                'verificacion_origen' => $verificada,
            ])->save();
        }

        return $sesion;
    }

    private function marca(SesionActividad $sesion, InscritoActividad $inscrito, string $estado): void
    {
        AsistenciaActividad::create([
            'sesion_id' => $sesion->id,
            'inscrito_id' => $inscrito->id,
            'estado' => $estado,
        ]);
    }

    private function perfil(string $username, string $rol): Perfil
    {
        $user = User::create(['username' => $username, 'password' => 'x', 'activo' => true]);

        /** @var Perfil $perfil */
        $perfil = Perfil::create([
            'user_id' => $user->id,
            'rol' => $rol,
            'nombre_completo' => ucfirst($username),
            'fecha_nacimiento' => '1990-05-05',
            'telefono' => '3000000000',
        ]);

        return $perfil;
    }
}
