<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Grupo;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Models\SesionGrupo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * «Clases del dia» en la portada del Panel: llegar rapido al grupo que toca.
 *
 * ─── Por que existe ────────────────────────────────────────────────────────
 *
 * Lo pidio el usuario el 06/09/2026: «que en el panel de los profesores tengan
 * un filtro por dia y hora para los grupos, para que lleguen rapido al grupo
 * que le van a dar clase». Antes habia que acordarse de en que promotoria
 * estaba el grupo, desplegarla y buscarlo entre los suyos.
 *
 * ─── LO QUE VIGILA ESTE ARCHIVO ────────────────────────────────────────────
 *
 * 1. EL ORDEN POR HORA. Es la mitad del asunto: la pregunta que trae aqui a
 *    alguien no es «que promotorias tengo» sino «que me toca ahora». Ordenado
 *    por promotoria, esto no le ahorra nada a nadie.
 * 2. EL DOMINGO, que desde el 05/10/2026 es un dia de clase como los demas
 *    (el CHECK `dia_valido` admite de 1 a 7). Antes la casa no abria y este
 *    proyecto tuvo tres pruebas rojas un dia de cada siete por eso.
 * 3. Que cada quien vea sus clases y no las de otro.
 */
class ClasesDelDiaTest extends TestCase
{
    use RefreshDatabase;

    private Perfil $profesor;

    private Perfil $otro;

    private Area $area;

    protected function setUp(): void
    {
        parent::setUp();

        Periodo::create([
            'nombre' => '2026-1',
            'fecha_inicio' => Carbon::today()->subMonth()->toDateString(),
            'fecha_fin' => Carbon::today()->addMonths(4)->toDateString(),
            'activo' => true,
            'matriculas_abiertas' => true,
        ]);

        $this->area = Area::create(['nombre' => 'Música']);
        $this->profesor = $this->perfil('profe', 'profesor');
        $this->otro = $this->perfil('otra', 'profesor');
    }

    /**
     * ORDENADAS POR DIA Y HORA.
     *
     * Se siembran a propósito en orden inverso al que deben salir: si la
     * consulta no ordenara, saldrían tal como se crearon y la prueba pasaría
     * por casualidad.
     */
    public function test_las_clases_salen_ordenadas_por_dia_y_hora(): void
    {
        $tarde = $this->grupoConClase('Violín', 'Tarde', dia: 1, hora: '16:00');
        $manana = $this->grupoConClase('Guitarra', 'Mañana', dia: 1, hora: '08:00');
        $jueves = $this->grupoConClase('Piano', 'Jueves', dia: 4, hora: '07:00');

        $html = $this->panel($this->profesor);

        $posManana = strpos($html, $manana->nombre_con_nivel);
        $posTarde = strpos($html, $tarde->nombre_con_nivel);
        $posJueves = strpos($html, $jueves->nombre_con_nivel);

        $this->assertNotFalse($posManana, 'no salió la clase de la mañana.');
        $this->assertLessThan($posTarde, $posManana, 'dentro del día no se ordena por hora.');
        $this->assertLessThan($posJueves, $posTarde, 'no se ordena por día.');
    }

    /**
     * LLEGA LA SEMANA ENTERA, cada renglón marcado con su día.
     *
     * Es lo que permite que el filtro no pida nada al servidor. La primera
     * versión traía solo el día mirado y cambiar de día era una NAVEGACIÓN; el
     * usuario la rechazó porque al recargar se cierran todos los desplegables
     * del Panel y se pierde lo que estabas mirando.
     */
    public function test_llega_la_semana_entera_marcada_por_dia(): void
    {
        $this->grupoConClase('Violín', 'Lunes', dia: 1, hora: '08:00');
        $this->grupoConClase('Guitarra', 'Jueves', dia: 4, hora: '08:00');

        $html = $this->panel($this->profesor);

        $this->assertStringContainsString('data-dia="1"', $html);
        $this->assertStringContainsString('data-dia="4"', $html);
        // Y el día de hoy viaja, para que el selector arranque ahí.
        $this->assertStringContainsString('data-hoy=', $html);
    }

    /**
     * EL SELECTOR NO ESTA EN EL HTML: lo monta `clases-del-dia.js`.
     *
     * Misma decisión que el ojo de la contraseña y por la misma razón: un
     * selector que filtra sin JavaScript es un control que no hace nada. Sin el
     * guion se ven los seis días seguidos, cada renglón con el suyo delante,
     * que es una pantalla útil y no una rota.
     *
     * Esta prueba se pone roja si alguien lo mete en el Blade «para que se vea
     * en el HTML».
     */
    public function test_el_selector_no_viene_en_el_html(): void
    {
        $this->grupoConClase('Violín', 'A', dia: 1, hora: '08:00');
        $this->grupoConClase('Piano', 'B', dia: 4, hora: '08:00');

        $html = $this->panel($this->profesor);

        $this->assertStringNotContainsString('dias-selector-boton', $html);
        // Pero el guion sí está cargado, que es lo que lo monta.
        $this->assertStringContainsString('clases-del-dia.js', $html);
    }

    /** Y cada clase lleva su enlace directo a las clases del grupo. */
    public function test_cada_clase_lleva_su_enlace(): void
    {
        $grupo = $this->grupoConClase('Violín', 'A', dia: 1, hora: '08:00');

        $this->assertStringContainsString(
            route('grupo-clases', $grupo),
            $this->panel($this->profesor)
        );
    }

    /**
     * EL DOMINGO ES UN DIA MAS (05/10/2026). Hasta ese dia la casa no abria y
     * el selector arrancaba en «toda la semana»; ahora arranca en el domingo
     * como en cualquier otro dia, y la clase de ese dia sale.
     */
    public function test_el_domingo_es_un_dia_de_clase_como_los_demas(): void
    {
        $this->grupoConClase('Violín', 'Dominical', dia: 7, hora: '08:00');

        Carbon::setTestNow(Carbon::parse('2026-09-06'));   // un domingo
        $domingo = $this->panel($this->profesor);

        Carbon::setTestNow(Carbon::parse('2026-09-07'));   // el lunes siguiente
        $lunes = $this->panel($this->profesor);

        Carbon::setTestNow();

        $this->assertStringContainsString('bloque-clases-dia', $domingo, 'el bloque desapareció un domingo.');
        $this->assertStringContainsString('data-hoy="7"', $domingo);
        $this->assertStringContainsString('data-hoy="1"', $lunes);
        $this->assertStringContainsString('Dominical', $domingo);
    }

    /** Cada quien ve las suyas: un profesor no ve la clase de otro. */
    public function test_un_profesor_no_ve_las_clases_de_otro(): void
    {
        $this->grupoConClase('Violín', 'Mía', dia: 1, hora: '08:00');
        $this->grupoConClase('Trompeta', 'Ajena', dia: 1, hora: '09:00', profesor: $this->otro);

        $html = $this->panel($this->profesor);

        $this->assertStringContainsString('Mía', $html);
        $this->assertStringNotContainsString('Ajena', $html);
    }

    /**
     * A DIRECCIÓN NO SE LE PINTA: no dicta, no tiene clases que buscar.
     *
     * Es la corrección del 06/09/2026 y la razón por la que este bloque se
     * acota por `profesor_id` y no por lo que cada quien puede VER. Con
     * `visiblesPara()` un director se encontraba encima las clases de la casa
     * entera —en producción, 199 sesiones medidas— sobre la pantalla más usada
     * del sistema. Palabras del usuario: «no sirve de nada... eso sirve para
     * los profesores».
     */
    public function test_a_quien_no_dicta_no_se_le_pinta_el_bloque(): void
    {
        $this->grupoConClase('Violín', 'A', dia: 1, hora: '08:00');

        $admin = $this->perfil('jefa', 'administrador');
        $directora = $this->perfil('dire', 'director');

        $this->assertStringNotContainsString('bloque-clases-dia', $this->panel($admin));
        $this->assertStringNotContainsString('bloque-clases-dia', $this->panel($directora));
    }

    /**
     * PERO UN DIRECTOR QUE DICTA SÍ, y solo con LAS SUYAS.
     *
     * Este proyecto ya contempla ese caso en otras dos pantallas —un director
     * que además dicta—, y por eso el corte va por el vínculo y no por el rol:
     * con `rol === 'profesor'` se le habría escondido a quien sí lo necesita.
     */
    public function test_un_director_que_dicta_ve_solo_las_suyas(): void
    {
        $directora = $this->perfil('dire', 'director');

        $this->grupoConClase('Violín', 'Suya', dia: 1, hora: '08:00', profesor: $directora);
        $this->grupoConClase('Trompeta', 'Ajena', dia: 1, hora: '09:00', profesor: $this->otro);

        $html = $this->panel($directora);

        $this->assertStringContainsString('bloque-clases-dia', $html);
        $this->assertStringContainsString('Suya', $html);
        $this->assertStringNotContainsString('Ajena', $html, 'un director vio las clases de otro.');
    }

    /**
     * SIN NINGÚN HORARIO no se pinta el bloque.
     *
     * La portada del Panel es la pantalla más usada del sistema; una sección
     * vacía ahí es ruido para todo el mundo. Misma regla que ya tenían las
     * actividades.
     */
    public function test_sin_horarios_no_se_pinta_el_bloque(): void
    {
        $this->assertStringNotContainsString('bloque-clases-dia', $this->panel($this->profesor));
    }

    // ------------------------------------------------------------------
    // Andamiaje
    // ------------------------------------------------------------------

    private function panel(Perfil $quien): string
    {
        return (string) $this->actingAs($quien->user)
            ->get(route('panel'))
            ->assertOk()
            ->getContent();
    }

    private function grupoConClase(
        string $promotoria,
        string $grupo,
        int $dia,
        string $hora,
        ?Perfil $profesor = null,
    ): Grupo {
        $p = Promotoria::create([
            'nombre' => $promotoria,
            'area_id' => $this->area->id,
            'profesor_id' => ($profesor ?? $this->profesor)->id,
        ]);

        $g = Grupo::create([
            'promotoria_id' => $p->id,
            'nombre' => $grupo,
            'nivel' => 'basico',
            'salon' => 'A1',
            'cupo_maximo' => 10,
        ]);

        SesionGrupo::create([
            'grupo_id' => $g->id,
            'dia' => $dia,
            'hora_inicio' => $hora,
            // Dos horas después. Con `substr(...) + 2 .':00'` PHP suma una
            // cadena a un entero y luego concatena, que es justo el tipo de
            // descuido que el analizador está para atrapar.
            'hora_fin' => sprintf('%02d:00', ((int) substr($hora, 0, 2)) + 2),
        ]);

        return $g;
    }

    private function perfil(string $username, string $rol): Perfil
    {
        $user = User::create(['username' => $username, 'password' => 'x', 'activo' => true]);

        return Perfil::create([
            'user_id' => $user->id,
            'rol' => $rol,
            'nombre_completo' => ucfirst($username),
            'fecha_nacimiento' => Carbon::today()->subYears(30)->toDateString(),
            'telefono' => '3000000000',
        ]);
    }
}
