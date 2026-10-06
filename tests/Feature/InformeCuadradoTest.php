<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\DatosEstudiante;
use App\Models\DocumentoRequerido;
use App\Models\EncuestaDemografica;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Que cada celda de un informe caiga bajo la cabecera que le toca.
 *
 * EL FALLO, del 04/09/2026: en el informe completo de la institucion las dos
 * ramas del generador escribian su propia lista de columnas, y la de «esta
 * persona no tiene matriculas» tenia SEIS huecos donde la otra ponia SIETE
 * columnas. Todo lo que va de «Departamento» en adelante se corria una posicion
 * a la izquierda, asi que la encuesta demografica salia bajo cabeceras que no
 * eran las suyas.
 *
 * NO ERA UN CASO RARO. Le pasaba a todo el PERSONAL —un profesor no tiene
 * matriculas nunca— y a cualquier estudiante sin matricula activa en el periodo
 * en curso. El informe salia torcido siempre, y la parte torcida era justo la
 * que la institucion reporta a quien la financia.
 *
 * Y no lo veia nadie: el archivo se genera, se descarga y se abre. Solo se nota
 * leyendo una fila de personal y viendo que el barrio esta en la columna del
 * genero. Ninguna prueba miraba los anchos.
 *
 * LO QUE ESTA CLASE VIGILA es eso: que toda fila mida lo que mide su cabecera.
 * Es una comprobacion tonta y es exactamente la que faltaba — cualquier columna
 * que se anada a un lado y se olvide en el otro cae aqui, en los dos informes.
 */
class InformeCuadradoTest extends TestCase
{
    use RefreshDatabase;

    private Periodo $periodo;

    private Perfil $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->periodo = Periodo::create([
            'nombre' => '2026-1',
            'fecha_inicio' => Carbon::today()->subMonth()->toDateString(),
            'fecha_fin' => Carbon::today()->addMonths(4)->toDateString(),
            'activo' => true,
            'matriculas_abiertas' => true,
        ]);

        $this->admin = $this->perfil('jefa', 'administrador');

        // DOS PAPELES PEDIDOS, y no es decorado: desde el 06/09/2026 el informe
        // de la institucion lleva una columna POR PAPEL, o sea que su ancho es
        // dinamico. Sin ninguno sembrado esas columnas miden cero y esta clase
        // —que existe justamente para cazar una columna anadida a un lado y
        // olvidada en el otro— pasaria sin mirarlas. Una prueba que no puede
        // fallar por lo que dice vigilar es peor que no tenerla.
        DocumentoRequerido::create(['nombre' => 'Documento de identidad', 'orden' => 1]);
        DocumentoRequerido::create(['nombre' => 'Certificado de EPS', 'orden' => 2]);
    }

    /**
     * TODA fila del informe de la institucion mide lo que mide su cabecera.
     *
     * Se siembran a proposito los tres casos que recorren las dos ramas del
     * generador: personal (nunca tiene matriculas), un estudiante con matricula
     * activa, y un estudiante sin ninguna.
     */
    public function test_ninguna_fila_del_informe_de_institucion_se_descuadra(): void
    {
        $this->sembrar();

        $filas = $this->descargar(route('informe-institucion'));
        $ancho = count($filas[0]);

        $this->assertGreaterThan(1, count($filas), 'la sonda no vale: el informe salio vacio.');

        foreach ($filas as $i => $fila) {
            $this->assertCount(
                $ancho,
                $fila,
                "la fila {$i} mide ".count($fila)." y la cabecera {$ancho}: el informe sale corrido."
            );
        }
    }

    /**
     * Y la encuesta cae DONDE DICE la cabecera, no una columna antes.
     *
     * La de arriba comprueba el ancho; esta comprueba el sitio, que es lo que de
     * verdad se rompio. Sin ella, dos errores que se compensaran —una columna de
     * mas aqui y una de menos alla— pasarian el ancho y seguirian mintiendo.
     *
     * El caso elegido es el que estaba roto: alguien SIN matricula que si
     * contesto la encuesta.
     */
    public function test_la_encuesta_de_quien_no_tiene_matricula_cae_en_su_columna(): void
    {
        $sinMatricula = $this->estudiante('sola');

        EncuestaDemografica::create([
            'perfil_id' => $sinMatricula->id,
            'genero' => 'f',
            'barrio' => 'La Loma',
            'estrato' => 2,
            'nivel_educativo' => array_key_first(EncuestaDemografica::NIVELES_EDUCATIVOS),
            'ocupacion' => array_key_first(EncuestaDemografica::OCUPACIONES),
            'zona' => array_key_first(EncuestaDemografica::ZONAS),
            'afiliacion_salud' => array_key_first(EncuestaDemografica::AFILIACIONES_SALUD),
            'grupo_etnico' => array_key_first(EncuestaDemografica::GRUPOS_ETNICOS),
            'discapacidad' => array_key_first(EncuestaDemografica::DISCAPACIDADES),
            'victima_conflicto_armado' => array_key_first(EncuestaDemografica::VICTIMAS_CONFLICTO),
            'autoriza_tratamiento_datos' => true,
        ]);

        $filas = $this->descargar(route('informe-institucion'));
        $cabecera = array_shift($filas);

        $columnaBarrio = array_search('Barrio', $cabecera, true);
        $columnaGenero = array_search('Género', $cabecera, true);
        $this->assertNotFalse($columnaBarrio, 'la sonda no vale: no hay columna «Barrio».');

        $suya = null;
        foreach ($filas as $fila) {
            if (in_array('Sola', $fila, true)) {
                $suya = $fila;
                break;
            }
        }

        $this->assertNotNull($suya, 'la sonda no vale: esa persona no salio en el informe.');

        $this->assertSame(
            'La Loma',
            $suya[$columnaBarrio],
            'el barrio no cayo bajo «Barrio». Es el sintoma del informe corrido: a quien no '
            .'tiene matricula se le escribian menos columnas y la encuesta se desplazaba.'
        );

        $this->assertSame('Femenino', $suya[$columnaGenero], 'el género tampoco cayo en su sitio.');
    }

    /**
     * El documento va AL LADO DEL NOMBRE (05/10/2026, pedido del usuario): el
     * del estudiante sale de su ficha y el del personal, de su perfil.
     */
    public function test_el_documento_va_al_lado_del_nombre(): void
    {
        $ana = $this->estudiante('ana');
        $profe = $this->perfil('profe', 'profesor');
        $profe->documento_identidad = '71222333';
        $profe->save();

        $filas = $this->descargar(route('informe-institucion'));
        $cabecera = array_shift($filas);

        $nombre = array_search('Nombre completo', $cabecera, true);
        $this->assertSame('Documento', $cabecera[$nombre + 1]);

        $documentoDe = function (string $quien) use ($filas, $nombre) {
            foreach ($filas as $fila) {
                if ($fila[$nombre] === $quien) {
                    return $fila[$nombre + 1];
                }
            }

            return null;
        };

        $this->assertSame('1'.$ana->id, $documentoDe('Ana'));
        $this->assertSame('71222333', $documentoDe('Profe'));
    }

    /**
     * La misma comprobacion en el informe de estudiantes.
     *
     * Este NO estaba roto —tiene una sola rama— y precisamente por eso conviene
     * la guarda: la proxima columna que se anada puede tocarle a el.
     */
    public function test_ninguna_fila_del_informe_de_estudiantes_se_descuadra(): void
    {
        $this->sembrar();

        $filas = $this->descargar(route('informe-estudiantes'));
        $ancho = count($filas[0]);

        $this->assertGreaterThan(1, count($filas), 'la sonda no vale: el informe salio vacio.');

        foreach ($filas as $i => $fila) {
            $this->assertCount($ancho, $fila, "la fila {$i} del informe de estudiantes se descuadro.");
        }
    }

    /** Personal, un estudiante matriculado y uno sin matricula. */
    private function sembrar(): void
    {
        $profesor = $this->perfil('profe', 'profesor');

        $promotoria = Promotoria::create([
            'nombre' => 'Violin',
            'area_id' => Area::create(['nombre' => 'Musica'])->id,
            'profesor_id' => $profesor->id,
        ]);

        $matriculado = $this->estudiante('ana');

        $matricula = new Matricula([
            'estudiante_id' => $matriculado->id,
            'promotoria_id' => $promotoria->id,
            'periodo_id' => $this->periodo->id,
            'estado' => Matricula::ACTIVA,
        ]);
        $matricula->save();

        $this->estudiante('beto');
    }

    /**
     * Descarga el CSV y lo devuelve ya partido en celdas.
     *
     * Se quita el BOM antes de partir: si no, la primera cabecera llega con tres
     * bytes invisibles pegados y no la encuentra ninguna comparacion. Y el
     * separador es punto y coma, que es el que usa `Support\Csv` — con coma
     * saldria una sola celda por fila y el ancho cuadraria siempre.
     *
     * @return list<list<string>>
     */
    private function descargar(string $url): array
    {
        $respuesta = $this->actingAs($this->admin->user)->get($url);
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

    private function estudiante(string $username): Perfil
    {
        $perfil = $this->perfil($username, 'estudiante');

        DatosEstudiante::create([
            'perfil_id' => $perfil->id,
            'documento_identidad' => '1'.$perfil->id,
        ]);

        return $perfil;
    }

    private function perfil(string $username, string $rol): Perfil
    {
        $user = User::create(['username' => $username, 'password' => 'x', 'activo' => true]);

        return Perfil::create([
            'user_id' => $user->id,
            'rol' => $rol,
            'nombre_completo' => ucfirst($username),
            'fecha_nacimiento' => Carbon::today()->subYears(20)->toDateString(),
            'telefono' => '3000000000',
        ]);
    }
}
