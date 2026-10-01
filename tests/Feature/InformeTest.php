<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\DatosEstudiante;
use App\Models\DocumentoEstudiante;
use App\Models\DocumentoRequerido;
use App\Models\EncuestaDemografica;
use App\Models\Grupo;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Los dos informes descargables.
 *
 * Lo que se comprueba aqui no es solo que salga un archivo, sino las dos cosas
 * que lo hacen util o inutil: que ABRA bien en Excel —BOM y separador— y que
 * cada quien reciba solo lo que puede ver.
 */
class InformeTest extends TestCase
{
    use RefreshDatabase;

    private Periodo $periodo;

    private Promotoria $violin;

    private Promotoria $danza;

    private Perfil $profesor;

    private Perfil $director;

    private Perfil $admin;

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

        $this->profesor = $this->crearPerfil('profe', 'profesor');
        $this->director = $this->crearPerfil('dire', 'director');
        // Dirige todos los departamentos. Desde el 12/09/2026 un director
        // solo ve lo suyo, y estas pruebas no van del recorte: sin esto
        // fallarian por no tener ninguno asignado, que es otro fallo.
        $this->dirige($this->director);
        $this->admin = $this->crearPerfil('admin', 'administrador');

        $this->violin = Promotoria::create([
            'nombre' => 'Violin',
            'area_id' => $area->id,
            'profesor_id' => $this->profesor->id,
        ]);

        // Sin profesor asignado: es de otro, para probar el recorte.
        $this->danza = Promotoria::create(['nombre' => 'Danza', 'area_id' => $area->id]);
    }

    private function crearPerfil(string $username, string $rol, ?string $nacimiento = null): Perfil
    {
        $user = User::create(['username' => $username, 'password' => 'secreta123', 'activo' => true]);

        return Perfil::create([
            'user_id' => $user->id,
            'rol' => $rol,
            'nombre_completo' => ucfirst($username).' Apellido',
            'fecha_nacimiento' => $nacimiento ?? Carbon::today()->subYears(30)->toDateString(),
            'telefono' => '3001112233',
        ]);
    }

    private function crearEstudiante(string $username): Perfil
    {
        $perfil = $this->crearPerfil($username, 'estudiante');

        DatosEstudiante::create([
            'perfil_id' => $perfil->id,
            'documento_identidad' => (string) random_int(10000000, 99999999),
        ]);

        return $perfil;
    }

    private function matricular(Perfil $perfil, Promotoria $promotoria, string $estado = Matricula::ACTIVA): Matricula
    {
        return Matricula::create([
            'estudiante_id' => $perfil->id,
            'promotoria_id' => $promotoria->id,
            'periodo_id' => $this->periodo->id,
            'estado' => $estado,
            'fecha' => now(),
        ]);
    }

    /** El cuerpo del CSV, ya descargado. */
    private function contenido($respuesta): string
    {
        ob_start();
        $respuesta->baseResponse->sendContent();

        return ob_get_clean();
    }

    // -----------------------------------------------------------------------
    // Que el archivo ABRA bien
    // -----------------------------------------------------------------------

    /**
     * BOM y punto y coma. Sin BOM, Excel lee el archivo como Latin-1 y
     * «Promotoría» sale «PromotorÃ­a»; con comas en vez de punto y coma, la hoja
     * entera cae en una sola columna. Las dos cosas convierten un informe
     * correcto en uno que parece roto.
     */
    public function test_el_csv_abre_bien_en_excel(): void
    {
        $respuesta = $this->actingAs($this->admin->user)->get(route('informe-estudiantes'));
        $respuesta->assertOk();

        $csv = $this->contenido($respuesta);

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('Promotoría;', $csv);
        $this->assertStringContainsString('Teléfono del acudiente', $csv);
    }

    public function test_el_archivo_se_llama_con_la_fecha(): void
    {
        $this->actingAs($this->admin->user)
            ->get(route('informe-estudiantes'))
            ->assertDownload('estudiantes-por-grupo-'.now()->format('Y-m-d').'.csv');
    }

    // -----------------------------------------------------------------------
    // Estudiantes por grupo
    // -----------------------------------------------------------------------

    public function test_el_informe_trae_al_estudiante_con_su_contacto(): void
    {
        $ana = $this->crearEstudiante('ana');
        $grupo = Grupo::create([
            'promotoria_id' => $this->violin->id,
            'nombre' => 'Lunes 4-6 p. m.',
            'nivel' => 'basico',
            'salon' => 'Salon 3',
            'cupo_maximo' => 10,
        ]);

        $matricula = $this->matricular($ana, $this->violin);
        $matricula->repartirEn([$grupo->id]);

        $csv = $this->contenido(
            $this->actingAs($this->director->user)->get(route('informe-estudiantes'))
        );

        $this->assertStringContainsString('Ana Apellido', $csv);
        $this->assertStringContainsString('Lunes 4-6 p. m.', $csv);
        $this->assertStringContainsString('Salon 3', $csv);
        $this->assertStringContainsString('Básico', $csv);
    }

    /** Sin grupo asignado sigue saliendo: es justo a quien hay que repartir. */
    public function test_quien_no_tiene_grupo_sale_marcado(): void
    {
        $this->matricular($this->crearEstudiante('beto'), $this->violin);

        $csv = $this->contenido(
            $this->actingAs($this->director->user)->get(route('informe-estudiantes'))
        );

        $this->assertStringContainsString('Beto Apellido', $csv);
        $this->assertStringContainsString('Sin grupo', $csv);
    }

    /**
     * Un profesor recibe SOLO sus promotorias, aunque pida el informe entero.
     *
     * Es la misma matriz de visibilidad de la ficha —el telefono de un
     * estudiante ajeno no es suyo—, y aqui importa el doble porque un CSV se
     * guarda y se reenvia.
     */
    public function test_el_profesor_solo_recibe_sus_promotorias(): void
    {
        $this->matricular($this->crearEstudiante('mia'), $this->violin);
        $this->matricular($this->crearEstudiante('ajeno'), $this->danza);

        $csv = $this->contenido(
            $this->actingAs($this->profesor->user)->get(route('informe-estudiantes'))
        );

        $this->assertStringContainsString('Mia Apellido', $csv);
        $this->assertStringNotContainsString('Ajeno Apellido', $csv);
    }

    public function test_direccion_recibe_todas_las_promotorias(): void
    {
        $this->matricular($this->crearEstudiante('mia'), $this->violin);
        $this->matricular($this->crearEstudiante('otro'), $this->danza);

        $csv = $this->contenido(
            $this->actingAs($this->director->user)->get(route('informe-estudiantes'))
        );

        $this->assertStringContainsString('Mia Apellido', $csv);
        $this->assertStringContainsString('Otro Apellido', $csv);
    }

    /** Quien se retiro ya no esta en la lista de quien va a clase. */
    public function test_los_retirados_no_salen(): void
    {
        $this->matricular($this->crearEstudiante('fuera'), $this->violin, Matricula::RETIRADA);

        $csv = $this->contenido(
            $this->actingAs($this->director->user)->get(route('informe-estudiantes'))
        );

        $this->assertStringNotContainsString('Fuera Apellido', $csv);
    }

    public function test_un_estudiante_no_baja_el_informe(): void
    {
        $ana = $this->crearEstudiante('ana');

        $this->actingAs($ana->user)
            ->get(route('informe-estudiantes'))
            ->assertRedirect(route('post-login'));
    }

    // -----------------------------------------------------------------------
    // Informe completo de la institucion
    // -----------------------------------------------------------------------

    /**
     * Solo el administrador. Es la puerta mas estrecha del sistema junto con la
     * copia del documento, y por la misma razon: este archivo saca la encuesta
     * demografica con nombre fuera del sistema.
     */
    public function test_el_informe_completo_es_solo_del_administrador(): void
    {
        foreach ([$this->director, $this->profesor] as $perfil) {
            $this->actingAs($perfil->user)
                ->get(route('informe-institucion'))
                ->assertRedirect(route('post-login'));
        }

        $this->actingAs($this->admin->user)
            ->get(route('informe-institucion'))
            ->assertOk();
    }

    public function test_el_informe_completo_trae_correo_rol_y_encuesta(): void
    {
        $ana = $this->crearEstudiante('ana');
        $ana->user->update(['email' => 'ana@ejemplo.co']);
        $this->matricular($ana, $this->violin);

        EncuestaDemografica::create([
            'perfil_id' => $ana->id,
            'genero' => 'f',
            'barrio' => 'La Candelaria',
            'estrato' => 2,
            'nivel_educativo' => 'primaria_com',
            'ocupacion' => 'estudiante',
        ]);

        $csv = $this->contenido(
            $this->actingAs($this->admin->user)->get(route('informe-institucion'))
        );

        $this->assertStringContainsString('Ana Apellido', $csv);
        $this->assertStringContainsString('ana@ejemplo.co', $csv);
        $this->assertStringContainsString('Estudiante', $csv);
        $this->assertStringContainsString('La Candelaria', $csv);
        // Traducido a su etiqueta: en una hoja de calculo «f» no lo lee nadie.
        $this->assertStringContainsString('Femenino', $csv);
        $this->assertStringContainsString('Primaria completa', $csv);
    }

    /**
     * La columna «Edad» sale VACIA para el personal y llena para el estudiante.
     *
     * Misma regla que la ficha, y aqui pesa mas: esto es un archivo que sale del
     * sistema y ya no vuelve. La columna no se quita de la cabecera porque para
     * el estudiante hace falta, y una hoja con las columnas cambiando segun la
     * fila no la abre ningun programa.
     *
     * Se mira la CELDA y no el archivo entero: con «47» suelto la prueba pasaria
     * por cualquier telefono o documento que lo contenga.
     */
    public function test_el_informe_completo_no_lleva_la_edad_del_personal(): void
    {
        $this->profesor->fecha_nacimiento = Carbon::today()->subYears(47)->subDays(3);
        $this->profesor->save();

        $ana = $this->crearEstudiante('ana');
        $ana->fecha_nacimiento = Carbon::today()->subYears(14)->subDays(3);
        $ana->save();
        $this->matricular($ana, $this->violin);

        $csv = $this->contenido(
            $this->actingAs($this->admin->user)->get(route('informe-institucion'))
        );

        $this->assertSame('', $this->celdaDeEdad($csv, 'Profe Apellido'));
        $this->assertSame('14', $this->celdaDeEdad($csv, 'Ana Apellido'));
    }

    /**
     * QUIEN ENTREGO CADA PAPEL, que es para lo que se anadieron las columnas.
     *
     * Sin esto no habia forma de saberlo: Gestion → Institucion dice CUANTOS
     * han entregado pero no quienes, y la unica alternativa era abrir la ficha
     * de cada estudiante — con 800 usuarios eso es adivinar. Palabras del
     * usuario el 06/09/2026, y la peticion se volvio urgente ese mismo dia al
     * nacer obligatorio el consentimiento: 775 personas a las que perseguir.
     */
    public function test_el_informe_dice_quien_entrego_cada_papel(): void
    {
        $identidad = DocumentoRequerido::create(['nombre' => 'Documento de identidad', 'orden' => 1]);
        DocumentoRequerido::create(['nombre' => 'Certificado de EPS', 'orden' => 2]);

        $conPapel = $this->crearEstudiante('ana');
        $sinPapel = $this->crearEstudiante('caro');

        DocumentoEstudiante::create([
            'datos_estudiante_id' => $conPapel->datosEstudiante->id,
            'requerido_id' => $identidad->id,
            'archivo' => 'documentos/loquesea.pdf',
        ]);

        $csv = $this->contenido(
            $this->actingAs($this->admin->user)->get(route('informe-institucion'))
        );

        $this->assertStringContainsString('Entregó: Documento de identidad', $csv);
        $this->assertStringContainsString('Entregó: Certificado de EPS', $csv);

        // Entregó el de identidad y no el de EPS.
        $this->assertSame(['Sí', 'No'], $this->celdasDePapeles($csv, 'Ana Apellido'));
        $this->assertSame(['No', 'No'], $this->celdasDePapeles($csv, 'Caro Apellido'));

        // Y al PERSONAL no se le pide ninguno: van VACIAS, no «No». Con un «No»
        // ahi, quien filtre la hoja por «No» para saber a quien llamar se
        // encuentra a toda la plantilla dentro.
        $this->assertSame(['', ''], $this->celdasDePapeles($csv, 'Profe Apellido'));
    }

    /** Un papel que se dejo de pedir no deja columna: ya no es una pregunta. */
    public function test_un_papel_desactivado_no_sale_en_el_informe(): void
    {
        DocumentoRequerido::create(['nombre' => 'Recibo de servicios', 'orden' => 1, 'activo' => false]);

        $csv = $this->contenido(
            $this->actingAs($this->admin->user)->get(route('informe-institucion'))
        );

        $this->assertStringNotContainsString('Recibo de servicios', $csv);
    }

    /**
     * Las ultimas N celdas de la fila de alguien: una por papel pedido.
     *
     * Se cuentan desde el FINAL porque ahi es donde se anadieron, para no mover
     * las columnas que ya existian.
     *
     * @return list<string>
     */
    private function celdasDePapeles(string $csv, string $nombre): array
    {
        $cuantos = DocumentoRequerido::activos()->count();

        foreach (explode("\n", $csv) as $linea) {
            if (str_contains($linea, $nombre)) {
                return array_slice(explode(';', trim($linea)), -$cuantos);
            }
        }

        $this->fail("El informe no trae ninguna fila de {$nombre}.");
    }

    /**
     * La cuarta columna --«Edad»-- de la fila de alguien, buscada por su nombre.
     */
    private function celdaDeEdad(string $csv, string $nombre): string
    {
        foreach (explode("\n", $csv) as $linea) {
            if (str_contains($linea, $nombre)) {
                return explode(';', trim($linea))[3];
            }
        }

        $this->fail("El informe no trae ninguna fila de {$nombre}.");
    }

    /** Quien no cursa nada sale igual, con las columnas de promotoria vacias. */
    public function test_el_personal_sin_promotoria_tambien_sale(): void
    {
        $csv = $this->contenido(
            $this->actingAs($this->admin->user)->get(route('informe-institucion'))
        );

        $this->assertStringContainsString('Dire Apellido', $csv);
        $this->assertStringContainsString('Director de escuela', $csv);
    }

    /**
     * Una fila por persona y promotoria: quien cursa dos sale dos veces.
     *
     * Es la forma correcta para una hoja de calculo y la unica que puede llevar
     * el nivel y el tiempo, que son datos DE la promotoria y no de la persona.
     */
    public function test_quien_cursa_dos_promotorias_sale_en_dos_filas(): void
    {
        $ana = $this->crearEstudiante('ana');
        $this->matricular($ana, $this->violin);
        $this->matricular($ana, $this->danza);

        $csv = $this->contenido(
            $this->actingAs($this->admin->user)->get(route('informe-institucion'))
        );

        $this->assertSame(2, substr_count($csv, 'Ana Apellido'));
        $this->assertStringContainsString('Violin', $csv);
        $this->assertStringContainsString('Danza', $csv);
    }

    /**
     * El tiempo se mide en PERIODOS cursados, que es la unidad de la casa.
     *
     * Cuentan solo los que quedaron ACTIVOS: una solicitud que nadie confirmo no
     * es tiempo cursado.
     */
    public function test_el_tiempo_cuenta_los_periodos_cursados(): void
    {
        $anterior = Periodo::create([
            'nombre' => '2025-2',
            'fecha_inicio' => '2025-07-01',
            'fecha_fin' => '2025-12-15',
            'activo' => false,
            'matriculas_abiertas' => false,
        ]);

        $ana = $this->crearEstudiante('ana');

        Matricula::create([
            'estudiante_id' => $ana->id,
            'promotoria_id' => $this->violin->id,
            'periodo_id' => $anterior->id,
            'estado' => Matricula::ACTIVA,
            'fecha' => now(),
        ]);
        $this->matricular($ana, $this->violin);

        $csv = $this->contenido(
            $this->actingAs($this->admin->user)->get(route('informe-institucion'))
        );

        // Dos periodos, y el primero es el del semestre pasado.
        $this->assertMatchesRegularExpression('/Ana Apellido.*;2;2025-2;/s', $csv);

        // Y UNA sola fila: la del periodo en curso. Las de periodos cerrados
        // siguen guardadas como 'activa' —el estado no cambia, cambia el
        // calendario—, y sin filtrarlas quien lleva tres semestres salia tres
        // veces con las mismas columnas.
        $this->assertSame(1, substr_count($csv, 'Ana Apellido'));
    }

    // -----------------------------------------------------------------------
    // Acotar a una promotoria o a un grupo
    // -----------------------------------------------------------------------

    private function grupoDe(Promotoria $promotoria, string $nivel = 'basico'): Grupo
    {
        return Grupo::create([
            'promotoria_id' => $promotoria->id,
            'nombre' => 'Grupo '.(Grupo::count() + 1),
            'nivel' => $nivel,
            'salon' => 'Salon 1',
            'cupo_maximo' => 10,
        ]);
    }

    private function matricularEnGrupo(Perfil $perfil, Grupo $grupo): Matricula
    {
        $matricula = $this->matricular($perfil, $grupo->promotoria);
        $matricula->repartirEn([$grupo->id]);

        return $matricula;
    }

    /**
     * La lista de UN grupo: la que se lleva impresa al salon.
     *
     * Con el informe entero encima, quien dicta tendria que buscar sus veinte
     * filas entre trescientas.
     */
    public function test_se_puede_pedir_la_lista_de_un_solo_grupo(): void
    {
        $basico = $this->grupoDe($this->violin, 'basico');
        $avanzado = $this->grupoDe($this->violin, 'avanzado');

        $this->matricularEnGrupo($this->crearEstudiante('delbasico'), $basico);
        $this->matricularEnGrupo($this->crearEstudiante('delavanzado'), $avanzado);

        $csv = $this->contenido(
            $this->actingAs($this->profesor->user)
                ->get(route('informe-estudiantes', ['grupo' => $basico->id]))
        );

        $this->assertStringContainsString('Delbasico Apellido', $csv);
        $this->assertStringNotContainsString('Delavanzado Apellido', $csv);
    }

    public function test_se_puede_pedir_la_lista_de_una_sola_promotoria(): void
    {
        $this->matricular($this->crearEstudiante('mia'), $this->violin);
        $this->matricular($this->crearEstudiante('otra'), $this->danza);

        $csv = $this->contenido(
            $this->actingAs($this->director->user)
                ->get(route('informe-estudiantes', ['promotoria' => $this->violin->id]))
        );

        $this->assertStringContainsString('Mia Apellido', $csv);
        $this->assertStringNotContainsString('Otra Apellido', $csv);
    }

    /**
     * Pedir el grupo de una promotoria ajena da 404, no un archivo vacio.
     *
     * Un CSV con la cabecera y ninguna fila se lee como «ese grupo esta vacio»,
     * que es una respuesta falsa a una pregunta que no correspondia hacer.
     */
    public function test_un_profesor_no_baja_la_lista_de_un_grupo_ajeno(): void
    {
        $ajeno = $this->grupoDe($this->danza);

        $this->actingAs($this->profesor->user)
            ->get(route('informe-estudiantes', ['grupo' => $ajeno->id]))
            ->assertNotFound();
    }

    public function test_un_profesor_no_baja_la_lista_de_una_promotoria_ajena(): void
    {
        $this->actingAs($this->profesor->user)
            ->get(route('informe-estudiantes', ['promotoria' => $this->danza->id]))
            ->assertNotFound();
    }

    /** Direccion si baja la de cualquiera. */
    public function test_el_director_baja_la_lista_de_cualquier_grupo(): void
    {
        $grupo = $this->grupoDe($this->danza);
        $this->matricularEnGrupo($this->crearEstudiante('suyo'), $grupo);

        $csv = $this->contenido(
            $this->actingAs($this->director->user)
                ->get(route('informe-estudiantes', ['grupo' => $grupo->id]))
        );

        $this->assertStringContainsString('Suyo Apellido', $csv);
    }

    /**
     * El archivo lleva el nombre de la promotoria y del grupo.
     *
     * Quien dicta se baja tres listas seguidas, una por horario; con el mismo
     * nombre las tres, el navegador las guarda como «(1)» y «(2)» y para saber
     * cual es cual hay que abrirlas.
     */
    public function test_el_archivo_de_un_grupo_lleva_su_nombre(): void
    {
        $grupo = $this->grupoDe($this->violin);

        $this->actingAs($this->profesor->user)
            ->get(route('informe-estudiantes', ['grupo' => $grupo->id]))
            ->assertDownload('estudiantes-violin-grupo-1-'.now()->format('Y-m-d').'.csv');
    }

    /**
     * Ni una fila de mas ni una de menos cuando el informe pasa de UNA tanda.
     *
     * Esta prueba existe por un fallo concreto: el informe se traia por tandas
     * con `lazyById`, que pagina preguntando por el id mayor que el ultimo
     * devuelto, mientras el orden era por departamento, promotoria y nombre. Con
     * dos ordenes distintos las tandas se solapan y las filas se repiten — 4961
     * filas para 302 matriculas—, y no se veia con cuatro filas de prueba porque
     * todo cabia en la primera tanda.
     *
     * Por eso siembra por encima del tamano de tanda: por debajo, la prueba
     * pasaria igual con el codigo roto.
     */
    public function test_no_se_repiten_filas_cuando_hay_mas_de_una_tanda(): void
    {
        $cuantos = 130;

        for ($n = 1; $n <= $cuantos; $n++) {
            $this->matricular($this->crearEstudiante("est{$n}"), $this->violin);
        }

        $csv = $this->contenido(
            $this->actingAs($this->director->user)->get(route('informe-estudiantes'))
        );

        $lineas = array_filter(explode("\n", trim($csv)));

        // Cabecera + una fila por matricula, sin repetidas.
        $this->assertCount($cuantos + 1, $lineas);
        $this->assertCount($cuantos + 1, array_unique($lineas));
    }

    /**
     * La gemela de la de arriba, con todos los nombres IGUALES.
     *
     * `lazy()` pagina con LIMIT/OFFSET sobre un orden por departamento,
     * promotoria y nombre. Con nombres repetidos ese orden tiene empates, y el
     * motor no esta obligado a resolverlos igual en dos consultas: si el empate
     * cae en el borde de una tanda, una persona sale dos veces y otra ninguna.
     * Se vio el 01/10/2026 al pasar a PostgreSQL —dos «Mariangel» en Danza
     * Folclorica, y la de 7 anos faltaba en la lista de su grupo—.
     *
     * COMO se siembra decide si la prueba ve algo, y costo tres intentos verla
     * fallar sin el arreglo:
     *
     * - Con todos los nombres IGUALES PostgreSQL ve la entrada «ya ordenada» y
     *   devuelve el orden fisico en todas las tandas: no hay fallo que ver.
     * - Con PAREJAS de homonimos, ordenadas, una pareja ocupa las posiciones
     *   99-100 o 101-102: ninguna queda partida por el borde 100|101.
     * - Lo que lo destapa son TRIOS (cruzan los bordes 100, 200 y 400) y mas
     *   de 400 filas, para que las dos primeras tandas ordenen con un
     *   monticulo de los N primeros, de 100 y de 200, y cada uno deshaga los
     *   empates a su manera. Es lo que se vio en el caso real: `top-N
     *   heapsort` en las dos tandas, con N distinto.
     *
     * La semilla es fija para que no dependa de la suerte. Cada estudiante
     * lleva su propio telefono para que cada fila se distinga: con dos filas
     * iguales, `array_unique` no veria a quien falta.
     */
    public function test_no_se_pierde_nadie_cuando_hay_nombres_repetidos(): void
    {
        $cuantos = 450;

        $nombres = [];
        for ($n = 0; $n < $cuantos; $n++) {
            $nombres[] = 'Persona '.str_pad((string) intdiv($n, 3), 3, '0', STR_PAD_LEFT);
        }
        mt_srand(20261001);
        shuffle($nombres);
        mt_srand();

        foreach ($nombres as $n => $nombre) {
            $estudiante = $this->crearEstudiante("homonimo{$n}");
            $estudiante->update([
                'nombre_completo' => $nombre,
                'telefono' => '300'.str_pad((string) $n, 7, '0', STR_PAD_LEFT),
            ]);
            $this->matricular($estudiante, $this->violin);
        }

        $csv = $this->contenido(
            $this->actingAs($this->admin->user)->get(route('informe-estudiantes'))
        );

        $lineas = array_filter(explode("\n", trim($csv)));

        $this->assertCount($cuantos + 1, $lineas);
        $this->assertCount(
            $cuantos + 1,
            array_unique($lineas),
            'Hay filas repetidas, o sea que a alguien con el mismo nombre se lo comio el borde de una tanda.'
        );
    }

    /**
     * Una celda con una barra invertida pegada a una comilla vuelve tal cual.
     *
     * Es el caso que estropeaba el escape por defecto de `fputcsv` --la barra
     * invertida--, que no duplicaba esa comilla y se la mudaba al final: «La
     * Loma \" y ya» llegaba como «La Loma \ y ya"». Aqui los nombres los
     * escribe el publico, asi que la celda rara la manda alguien de fuera.
     *
     * El nombre se arma con `chr(92)` a proposito: escrito a mano, entre las
     * comillas de PHP y las del CSV, no hay quien lea cuantas barras hay.
     */
    public function test_una_celda_con_barra_y_comilla_vuelve_igual(): void
    {
        $raro = 'Ana '.chr(92).'" Ruiz';

        $ana = $this->crearEstudiante('ana');
        $ana->nombre_completo = $raro;
        $ana->save();
        $this->matricular($ana, $this->violin);

        $csv = $this->contenido(
            $this->actingAs($this->admin->user)->get(route('informe-estudiantes'))
        );

        // Se relee como lo relee cualquier programa: CSV del RFC 4180, sin
        // caracter de escape. Con el escape viejo esta vuelta no coincide.
        $filas = array_map(
            fn (string $linea) => str_getcsv($linea, ';', '"', ''),
            array_values(array_filter(explode("\n", trim($csv))))
        );

        // La columna «Estudiante» es la septima de la cabecera.
        $this->assertSame($raro, $filas[1][6]);
    }

    /**
     * Una celda que empieza por `=` la ejecuta Excel al abrir el archivo.
     *
     * Los nombres y los barrios los escribe el publico, asi que es una via real
     * de inyeccion de formulas. Se les antepone un apostrofo, que Excel entiende
     * como «esto es texto».
     */
    public function test_una_celda_con_formula_se_neutraliza(): void
    {
        $ana = $this->crearEstudiante('ana');
        $ana->update(['nombre_completo' => '=HYPERLINK("http://malo","clic")']);
        $this->matricular($ana, $this->violin);

        $csv = $this->contenido(
            $this->actingAs($this->admin->user)->get(route('informe-institucion'))
        );

        $this->assertStringContainsString("'=HYPERLINK", $csv);
    }
}
