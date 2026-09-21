<?php

namespace Tests\Feature;

use App\Http\Controllers\CarneController;
use App\Models\Area;
use App\Models\Asistencia;
use App\Models\Clase;
use App\Models\ConfirmacionClase;
use App\Models\Grupo;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Models\User;
use App\Support\CarneQr;
use App\Support\PaseDeLista;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * El carne con codigo QR: sacarlo, y pasar lista con el.
 *
 * ─── DE DONDE SALE ─────────────────────────────────────────────────────────
 *
 * Lo pidio el usuario el 21/09/2026 para el publico que no se maneja con el
 * telefono: quien olvida su usuario y su contrasena no puede entrar, y hasta
 * entonces eso significaba que su clase no la confirmaba nadie. Con el carne, el
 * profesor lee el cuadrito en el salon y esa persona queda marcada Y su clase
 * verificada, sin teclear nada.
 *
 * ─── LO QUE NO PUEDE COMPROBAR UNA PRUEBA DE PHP ───────────────────────────
 *
 * La camara, el permiso del navegador y que `jsqr.js` decodifique. Eso se mira
 * en el navegador y en un telefono de verdad. Aqui se comprueba lo que decide
 * el SERVIDOR, que es todo lo que sostiene la funcion: quien puede sacar un
 * carne de quien, que el codigo no viaje a la pantalla del profesor, y que un
 * codigo leido solo confirme cuando de verdad corresponde.
 */
class CarneQrTest extends TestCase
{
    use RefreshDatabase;

    private Periodo $periodo;

    private Promotoria $piano;

    private Grupo $grupo;

    private Perfil $profesor;

    private Perfil $ana;

    private Perfil $administrador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->periodo = Periodo::create([
            'nombre' => '2026-2',
            'fecha_inicio' => Carbon::today()->subMonth()->toDateString(),
            'fecha_fin' => Carbon::today()->addMonths(3)->toDateString(),
            'activo' => true,
            'matriculas_abiertas' => true,
        ]);

        $area = Area::create(['nombre' => 'Música']);
        $this->profesor = $this->perfil('profe', 'profesor');
        $this->administrador = $this->perfil('admin', 'administrador');

        $this->piano = Promotoria::create([
            'nombre' => 'Piano',
            'area_id' => $area->id,
            'profesor_id' => $this->profesor->id,
        ]);
        $this->grupo = Grupo::create([
            'promotoria_id' => $this->piano->id,
            'nombre' => 'Mañana',
            'nivel' => 'basico',
            'salon' => 'A1',
            'cupo_maximo' => 20,
        ]);

        $this->ana = $this->perfil('ana', 'estudiante');
    }

    // ------------------------------------------------------------------
    // Sacar el carne
    // ------------------------------------------------------------------

    /**
     * El codigo NO existe hasta que alguien pide el carne.
     *
     * Es la decision de la migracion: nace vacio y se llena al pedirlo, para no
     * sembrar 885 codigos que casi nadie va a mirar. Si algun dia se cambia por
     * un hook de `creating`, esta prueba se pone roja y hay que decidirlo
     * aposta.
     */
    public function test_el_codigo_nace_vacio_y_se_crea_al_pedir_el_carne(): void
    {
        $this->assertNull($this->ana->codigo_qr);

        $this->actingAs($this->ana->user)->get(route('mi-carne'))->assertOk();

        $this->assertNotNull($this->ana->fresh()->codigo_qr);
    }

    /** Y no cambia solo: un carne impreso tiene que seguir sirviendo manana. */
    public function test_el_codigo_no_cambia_al_volver_a_mirarlo(): void
    {
        $this->actingAs($this->ana->user)->get(route('mi-carne'));
        $primero = $this->ana->fresh()->codigo_qr;

        $this->actingAs($this->ana->user)->get(route('mi-carne'));

        $this->assertSame($primero, $this->ana->fresh()->codigo_qr);
    }

    public function test_la_descarga_es_un_png_de_verdad(): void
    {
        $respuesta = $this->actingAs($this->ana->user)->get(route('mi-carne-imagen'));

        $respuesta->assertOk();
        $respuesta->assertHeader('content-type', 'image/png');

        // Los ocho primeros bytes de todo PNG. Comprobar solo la cabecera HTTP
        // daria verde con una respuesta vacia, que es justo lo que pasaria si
        // GD o la fuente faltaran en el servidor.
        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", (string) $respuesta->getContent());
    }

    /**
     * Un profesor no tiene carne, y se le DICE.
     *
     * No es un permiso que le falte: en una lista de clase no hay profesores.
     * Un 404 mudo mandaria a pensar que la pantalla se rompio.
     */
    public function test_el_personal_no_tiene_carne_y_la_pantalla_lo_explica(): void
    {
        $this->actingAs($this->profesor->user)
            ->get(route('mi-carne'))
            ->assertRedirect(route('mi-perfil'));

        $this->assertStringContainsString('estudiantes', (string) session('error'));
    }

    // ------------------------------------------------------------------
    // El carne de OTRA persona
    // ------------------------------------------------------------------

    /**
     * EL PROFESOR NO SACA EL CARNE DE NADIE, ni del estudiante que tiene en su
     * lista. Es la decision del usuario del 21/09/2026.
     *
     * El carne es la llave con la que a esa persona se le marca asistencia, y
     * quien pasa lista es a quien esas marcas vigilan: con una copia del carne
     * de sus cuarenta estudiantes podria confirmarse sus propias clases.
     */
    public function test_el_profesor_no_puede_sacar_el_carne_de_su_estudiante(): void
    {
        $this->inscribir();

        $respuesta = $this->actingAs($this->profesor->user)
            ->get(route('carne-estudiante-imagen', $this->ana));

        // Lo para el `rol:administrador` de la ruta, que devuelve al profesor a
        // su sitio. Lo que se afirma no es el codigo de estado sino que POR AHI
        // NO SALE UN PNG: es lo unico que importa, y sigue valiendo el dia que
        // alguien cambie el rebote por otra cosa.
        $respuesta->assertRedirect();
        $this->assertStringNotContainsString("\x89PNG", (string) $respuesta->getContent());
    }

    /**
     * Y la puerta del CONTROLADOR cierra sola, sin el middleware de la ruta.
     *
     * Es la convencion de la casa para lo que entrega datos de otra persona: una
     * ruta se edita en un renglon y el descuido no se ve. Sin esta prueba, la
     * comprobacion de dentro se podria borrar «porque ya la hace la ruta» y
     * nada se pondria rojo.
     */
    public function test_la_puerta_de_dentro_cierra_aunque_la_ruta_deje_pasar(): void
    {
        $peticion = Request::create(route('carne-estudiante-imagen', $this->ana));
        $peticion->setUserResolver(fn () => $this->profesor->user);

        $this->expectException(NotFoundHttpException::class);

        (new CarneController)->imagenDeEstudiante($peticion, $this->ana);
    }

    /** Y el enlace tampoco se le pinta, que es la otra mitad. */
    public function test_la_ficha_solo_ofrece_el_carne_al_administrador(): void
    {
        $this->inscribir();

        $this->actingAs($this->profesor->user)
            ->get(route('detalle-usuario', $this->ana))
            ->assertDontSee('Carné con código');

        $this->actingAs($this->administrador->user)
            ->get(route('detalle-usuario', $this->ana))
            ->assertSee('Carné con código');
    }

    public function test_el_administrador_si_lo_saca(): void
    {
        $this->actingAs($this->administrador->user)
            ->get(route('carne-estudiante', $this->ana))
            ->assertOk();

        $this->assertNotNull($this->ana->fresh()->codigo_qr);
    }

    /**
     * Renovar invalida el anterior EN EL ACTO.
     *
     * Es lo unico que puede hacer quien perdio el papel o a quien le tomaron una
     * foto. Sin esto, un carne fotografiado no se apaga de ninguna manera.
     */
    public function test_renovar_cambia_el_codigo_y_el_viejo_deja_de_servir(): void
    {
        $viejo = $this->ana->codigoQr();

        $this->actingAs($this->ana->user)
            ->post(route('mi-carne-renovar'))
            ->assertRedirect(route('mi-carne'));

        $nuevo = $this->ana->fresh()->codigo_qr;

        $this->assertNotSame($viejo, $nuevo);
        $this->assertNull(Perfil::porCodigoQr($viejo));
        $this->assertTrue(Perfil::porCodigoQr($nuevo)->is($this->ana));
    }

    // ------------------------------------------------------------------
    // La hoja de asistencia no lleva los codigos
    // ------------------------------------------------------------------

    /**
     * LA PANTALLA DEL PROFESOR LLEVA LA HUELLA, NUNCA EL CODIGO.
     *
     * Es la mitad silenciosa de todo esto. Si la hoja trajera los codigos, quien
     * abra el inspector en su telefono se lleva el carne de sus cuarenta
     * estudiantes —y esos codigos sirven tambien en las clases de los demas
     * profesores—. Nada fallaria: la pantalla se veria igual.
     */
    public function test_la_hoja_lleva_la_huella_y_no_el_codigo(): void
    {
        $this->inscribir();
        $clase = $this->clase();
        $codigo = $this->ana->codigoQr();

        $respuesta = $this->actingAs($this->profesor->user)
            ->get(route('clase-asistencia', $clase));

        $respuesta->assertOk();
        $respuesta->assertSee(CarneQr::huella($codigo), false);
        $respuesta->assertDontSee($codigo, false);
    }

    /**
     * Y abrir la lista NO le crea el codigo a nadie.
     *
     * Crearlo ahi escribiria en cuarenta perfiles cada vez que un profesor abre
     * una lista, y en hosting compartido eso se nota. Quien no tiene codigo
     * sencillamente no se puede escanear todavia.
     */
    public function test_abrir_la_lista_no_le_crea_el_codigo_a_los_estudiantes(): void
    {
        $this->inscribir();
        $clase = $this->clase();

        $this->actingAs($this->profesor->user)->get(route('clase-asistencia', $clase));

        $this->assertNull($this->ana->fresh()->codigo_qr);
    }

    // ------------------------------------------------------------------
    // Pasar lista con el carne
    // ------------------------------------------------------------------

    /**
     * EL CAMINO BUENO: el profesor escanea, guarda, y la clase queda verificada.
     *
     * Se manda lo mismo que manda el navegador: la marca que el lector puso en
     * el radio, y el codigo leido. Las dos cosas, porque el servidor cruza una
     * con otra.
     */
    public function test_el_carne_leido_marca_asistencia_y_confirma_la_clase(): void
    {
        $matricula = $this->inscribir();
        $clase = $this->clase();

        $this->pasarLista($clase, [
            PaseDeLista::PREFIJO.$matricula->id => Asistencia::ASISTIO,
            'qr' => [CarneQr::PREFIJO.$this->ana->codigoQr()],
        ])->assertRedirect(route('clase-asistencia', $clase));

        $this->assertSame(Asistencia::ASISTIO, $clase->asistencias()->first()->estado);
        $this->assertSame(1, $clase->confirmaciones()->count());
        $this->assertSame(ConfirmacionClase::CARNE, $clase->confirmaciones()->first()->origen);
    }

    /**
     * MANDA LO QUE QUEDO ESCRITO, no lo que se escaneo.
     *
     * El profesor escanea a alguien y despues le cambia la marca a «Faltó» —se
     * equivoco de persona, o la vio irse—. La confirmacion NO se escribe: quien
     * consta ausente no da fe de una clase, y esa regla es la misma que rige el
     * boton del estudiante. Por eso el cruce va DESPUES de guardar la hoja.
     */
    public function test_si_la_marca_acaba_en_falta_el_carne_no_confirma(): void
    {
        $matricula = $this->inscribir();
        $clase = $this->clase();

        $this->pasarLista($clase, [
            PaseDeLista::PREFIJO.$matricula->id => Asistencia::FALTO,
            'qr' => [CarneQr::PREFIJO.$this->ana->codigoQr()],
        ]);

        $this->assertSame(0, $clase->confirmaciones()->count());
    }

    /**
     * Un carne de alguien que no esta en esta lista no confirma nada.
     *
     * AL AJENO HAY QUE MATRICULARLO EN OTRA PARTE, y no es relleno: con una
     * persona sin ninguna matricula esta prueba pasaba en verde con el cruce
     * quitado —no encontraba matricula por la que confirmar, y la barrera que
     * la paraba no era la que dice el nombre—. Comprobado quitando el cruce el
     * 21/09/2026: verde. Con la matricula en otra promotoria, roja.
     */
    public function test_el_carne_de_otro_grupo_no_confirma_esta_clase(): void
    {
        $matricula = $this->inscribir();
        $clase = $this->clase();
        $ajeno = $this->perfil('otro', 'estudiante');
        $this->inscribirEnOtraPromotoria($ajeno);

        $this->pasarLista($clase, [
            PaseDeLista::PREFIJO.$matricula->id => Asistencia::ASISTIO,
            'qr' => [CarneQr::PREFIJO.$ajeno->codigoQr()],
        ]);

        $this->assertSame(0, $clase->confirmaciones()->count());
    }

    /**
     * Basura en el campo `qr[]` no revienta el guardado de la hoja.
     *
     * LO QUE VIGILA ES EL `assertRedirect`, y conviene decirlo: la cifra de
     * confirmaciones se quedaria en cero igual aunque el filtro de forma no
     * existiera, porque ninguna de esas cadenas casa con un codigo de la base.
     * Lo que si cambia sin el filtro es que una cadena cualquiera —larguisima,
     * con comillas— llegue a ser una consulta en mitad de un guardado que el
     * profesor esta esperando de pie en el salon.
     */
    public function test_un_codigo_inventado_no_hace_nada(): void
    {
        $matricula = $this->inscribir();
        $clase = $this->clase();

        $this->pasarLista($clase, [
            PaseDeLista::PREFIJO.$matricula->id => Asistencia::ASISTIO,
            'qr' => ['MTR:noesuncodigo', 'cualquier cosa', CarneQr::PREFIJO.'0123456789abcdef'],
        ])->assertRedirect(route('clase-asistencia', $clase));

        $this->assertSame(0, $clase->confirmaciones()->count());
    }

    /**
     * El mismo carne leido dos veces no confirma dos veces.
     *
     * Con la camara encendida, un papel quieto delante se lee decenas de veces.
     * El guion ya manda uno solo por persona, pero la garantia esta aqui: contra
     * el indice unico, una segunda insercion seria un error de integridad en
     * mitad del guardado de la hoja.
     */
    public function test_el_mismo_carne_dos_veces_deja_una_sola_confirmacion(): void
    {
        $matricula = $this->inscribir();
        $clase = $this->clase();
        $codigo = CarneQr::PREFIJO.$this->ana->codigoQr();

        $this->pasarLista($clase, [
            PaseDeLista::PREFIJO.$matricula->id => Asistencia::ASISTIO,
            'qr' => [$codigo, $codigo],
        ])->assertRedirect(route('clase-asistencia', $clase));

        $this->assertSame(1, $clase->confirmaciones()->count());
    }

    /**
     * Fuera del plazo de 48 horas no confirma, igual que el boton del
     * estudiante.
     *
     * La regla vive en un solo sitio (`ConfirmacionClase::registrar`) y por eso
     * alcanza a los dos caminos. Escrita a mano en cada uno, esta prueba seria
     * la que se olvida.
     */
    public function test_pasado_el_plazo_el_carne_ya_no_confirma(): void
    {
        $matricula = $this->inscribir();
        $clase = $this->clase();
        $clase->update(['fecha_hora' => Carbon::now()->subHours(Clase::VENTANA_CONFIRMACION_HORAS + 1)]);

        $this->pasarLista($clase, [
            PaseDeLista::PREFIJO.$matricula->id => Asistencia::ASISTIO,
            'qr' => [CarneQr::PREFIJO.$this->ana->codigoQr()],
        ]);

        $this->assertSame(0, $clase->confirmaciones()->count());
    }

    /**
     * Quien NO dicta la promotoria no escribe nada, tampoco por este camino.
     *
     * La puerta es la de siempre (`Permisos::dictaLaPromotoria`) y el rechazo
     * ocurre antes de mirar los codigos. Sin esta prueba, el campo `qr[]` seria
     * una segunda entrada a la misma escritura.
     */
    public function test_quien_no_dicta_no_confirma_aunque_mande_codigos(): void
    {
        $matricula = $this->inscribir();
        $clase = $this->clase();
        $otro = $this->perfil('otraprofe', 'profesor');

        $this->actingAs($otro->user)->post(route('clase-asistencia', $clase), [
            PaseDeLista::PREFIJO.$matricula->id => Asistencia::ASISTIO,
            'qr' => [CarneQr::PREFIJO.$this->ana->codigoQr()],
        ]);

        $this->assertSame(0, $clase->confirmaciones()->count());
        $this->assertSame(0, $clase->asistencias()->count());
    }

    /**
     * La que confirma el estudiante por su cuenta queda marcada como SUYA.
     *
     * `origen` existe para poder distinguirlas despues: una clase avalada por
     * quien no tiene nada que ganar no es lo mismo que una avalada por el papel
     * que sostiene el profesor, aunque las dos cuenten igual.
     */
    public function test_la_confirmacion_del_estudiante_queda_marcada_como_propia(): void
    {
        $matricula = $this->inscribir();
        $clase = $this->clase();

        Asistencia::create([
            'clase_id' => $clase->id,
            'matricula_id' => $matricula->id,
            'estado' => Asistencia::ASISTIO,
        ]);

        $this->actingAs($this->ana->user)->post(route('confirmar-clase', $clase));

        $this->assertSame(ConfirmacionClase::PROPIA, $clase->confirmaciones()->first()->origen);
    }

    // ------------------------------------------------------------------
    // Cuando el carne NO verifica, la pantalla dice por que
    // ------------------------------------------------------------------

    /**
     * EL SEGUNDO FALLO DE PRODUCCION DEL 21/09/2026.
     *
     * El usuario paso lista con el carne y no se le verifico nadie. La causa
     * mas probable era el plazo —una clase de dias atras— pero lo que convirtio
     * eso en un misterio fue que la pantalla CALLABA: el aviso solo sabia hablar
     * cuando algo se escribia.
     *
     * Estas pruebas no comprueban que se confirme, que ya esta cubierto arriba.
     * Comprueban que cuando NO se confirma se DIGA, que es lo que costo el rato.
     */
    public function test_pasado_el_plazo_el_aviso_explica_que_fue_el_plazo(): void
    {
        $matricula = $this->inscribir();
        $clase = $this->clase();
        $clase->update(['fecha_hora' => Carbon::now()->subHours(Clase::VENTANA_CONFIRMACION_HORAS + 1)]);

        $this->pasarLista($clase, [
            PaseDeLista::PREFIJO.$matricula->id => Asistencia::ASISTIO,
            'qr' => [CarneQr::PREFIJO.$this->ana->codigoQr()],
        ]);

        $aviso = (string) session('success');

        $this->assertStringContainsString('el plazo', $aviso);
        $this->assertStringContainsString('venció', $aviso);
        // Y la asistencia SI se guardo: el carne sigue sirviendo para pasar
        // lista aunque ya no verifique.
        $this->assertSame(Asistencia::ASISTIO, $clase->asistencias()->first()->estado);
    }

    /**
     * EL VERBO CONCUERDA CON EL SUJETO, en singular y en plural.
     *
     * Esta prueba SI esta atada al texto, en contra de la regla de la casa de
     * atarlas a clases o rutas — y es a proposito: aqui el texto ES lo que se
     * comprueba. «El carné leído marcaron la asistencia» es lo que salio la
     * primera vez, y es el TERCER caso del mismo descuido en la asistencia. Si
     * alguien reescribe la frase, esta prueba tiene que obligarle a mirar la
     * otra forma tambien.
     */
    public function test_el_aviso_del_plazo_concuerda_en_singular_y_en_plural(): void
    {
        $matricula = $this->inscribir();
        $otro = $this->perfil('dos', 'estudiante');
        $suya = $this->inscribirA($otro);
        $clase = $this->clase();
        $clase->update(['fecha_hora' => Carbon::now()->subHours(Clase::VENTANA_CONFIRMACION_HORAS + 1)]);

        $this->pasarLista($clase, [
            PaseDeLista::PREFIJO.$matricula->id => Asistencia::ASISTIO,
            'qr' => [CarneQr::PREFIJO.$this->ana->codigoQr()],
        ]);

        $this->assertStringContainsString('El carné leído marcó la asistencia', (string) session('success'));

        $this->pasarLista($clase, [
            PaseDeLista::PREFIJO.$matricula->id => Asistencia::ASISTIO,
            PaseDeLista::PREFIJO.$suya->id => Asistencia::ASISTIO,
            'qr' => [
                CarneQr::PREFIJO.$this->ana->codigoQr(),
                CarneQr::PREFIJO.$otro->codigoQr(),
            ],
        ]);

        $this->assertStringContainsString('Los 2 carnés leídos marcaron la asistencia', (string) session('success'));
    }

    /** Y el lector lo advierte ANTES, no despues de escanear a veinte personas. */
    public function test_el_lector_avisa_del_plazo_vencido_antes_de_escanear(): void
    {
        $this->inscribir();
        $clase = $this->clase();
        $clase->update(['fecha_hora' => Carbon::now()->subHours(Clase::VENTANA_CONFIRMACION_HORAS + 1)]);

        $this->actingAs($this->profesor->user)
            ->get(route('clase-asistencia', $clase))
            ->assertSee('marcará la asistencia, pero ya no la verifica', false);
    }

    /** Dentro del plazo no hay tal aviso: seria ruido en el camino normal. */
    public function test_dentro_del_plazo_el_lector_no_avisa_de_nada(): void
    {
        $this->inscribir();
        $clase = $this->clase();

        $this->actingAs($this->profesor->user)
            ->get(route('clase-asistencia', $clase))
            ->assertDontSee('ya no la verifica', false);
    }

    /** A quien acabo marcado ausente se le dice que por eso no verifica. */
    public function test_si_acabo_marcado_ausente_el_aviso_lo_explica(): void
    {
        $matricula = $this->inscribir();
        $clase = $this->clase();

        $this->pasarLista($clase, [
            PaseDeLista::PREFIJO.$matricula->id => Asistencia::FALTO,
            'qr' => [CarneQr::PREFIJO.$this->ana->codigoQr()],
        ]);

        $this->assertStringContainsString('no asistió', (string) session('success'));
    }

    /** Un carne que no es de esta lista tampoco se calla. */
    public function test_un_carne_ajeno_lo_dice_en_el_aviso(): void
    {
        $matricula = $this->inscribir();
        $clase = $this->clase();
        $ajeno = $this->perfil('otro', 'estudiante');
        $this->inscribirEnOtraPromotoria($ajeno);

        $this->pasarLista($clase, [
            PaseDeLista::PREFIJO.$matricula->id => Asistencia::ASISTIO,
            'qr' => [CarneQr::PREFIJO.$ajeno->codigoQr()],
        ]);

        $this->assertStringContainsString('no es de ningún estudiante de esta lista', (string) session('success'));
    }

    /**
     * SIN CARNES NO SE DICE NADA DE CARNES.
     *
     * Es la mitad que impide que el arreglo del silencio se convierta en ruido:
     * quien pasa lista a mano —que sigue siendo la mayoria— no tiene por que
     * leer una frase sobre una funcion que no uso.
     */
    public function test_pasar_lista_a_mano_no_menciona_el_carne(): void
    {
        $matricula = $this->inscribir();
        $clase = $this->clase();

        $this->pasarLista($clase, [
            PaseDeLista::PREFIJO.$matricula->id => Asistencia::ASISTIO,
        ]);

        $this->assertStringNotContainsString('carné', (string) session('success'));
    }

    // ------------------------------------------------------------------
    // La red de seguridad: preguntarle al servidor de quien es el carne
    // ------------------------------------------------------------------

    /**
     * EL FALLO DE PRODUCCION DEL 21/09/2026, y por eso existe esta seccion.
     *
     * El profesor escaneo el carne de alguien que SI estaba en su lista y le
     * salio «ese carne no es de ningun estudiante de esta lista». La pantalla
     * lleva las huellas de quien YA tenia codigo cuando se pinto, y el codigo se
     * crea al sacar el carne: abrir la hoja y sacar el carne despues dejaba una
     * pagina que no sabia de ese codigo.
     *
     * Esta prueba reproduce ese orden EXACTO —hoja primero, carne despues— y es
     * la que se queda roja si alguien vuelve a hacer del cotejo local la ultima
     * palabra.
     */
    public function test_un_carne_sacado_despues_de_abrir_la_hoja_se_reconoce_igual(): void
    {
        $matricula = $this->inscribir();
        $clase = $this->clase();

        // 1. El profesor abre la lista. Ana todavia no tiene codigo, asi que su
        //    renglon sale SIN huella: es el estado que causaba el fallo.
        $hoja = $this->actingAs($this->profesor->user)->get(route('clase-asistencia', $clase));
        $hoja->assertOk();
        $hoja->assertDontSee('data-qr-huella', false);

        // 2. Administracion le imprime el carne. AHORA nace el codigo.
        $codigo = $this->ana->codigoQr();

        // 3. Se escanea contra la pagina de antes. El servidor lo resuelve.
        $respuesta = $this->actingAs($this->profesor->user)->postJson(
            route('clase-comprobar-carne', $clase),
            ['codigo' => CarneQr::PREFIJO.$codigo]
        );

        $respuesta->assertOk();
        $respuesta->assertJson([
            'encontrado' => true,
            'matricula' => $matricula->id,
            'nombre' => $this->ana->nombre_completo,
        ]);
        $respuesta->assertJsonPath('huella', CarneQr::huella($codigo));
    }

    /**
     * Un carne de otra clase dice DE QUIEN es.
     *
     * El nombre viaja a proposito: quien pregunta tiene el carne en la mano y el
     * nombre va impreso en el. «Ese carné es de Ana Ruiz, que no está en esta
     * clase» se resuelve ahi mismo; «no está en la lista» manda a buscar a
     * ciegas, que es lo que paso en produccion.
     */
    public function test_el_carne_de_otra_clase_dice_de_quien_es(): void
    {
        $this->inscribir();
        $clase = $this->clase();
        $ajeno = $this->perfil('otro', 'estudiante');
        $this->inscribirEnOtraPromotoria($ajeno);

        $this->actingAs($this->profesor->user)->postJson(
            route('clase-comprobar-carne', $clase),
            ['codigo' => CarneQr::PREFIJO.$ajeno->codigoQr()]
        )->assertOk()->assertJson([
            'encontrado' => false,
            'motivo' => 'otra_lista',
            'nombre' => $ajeno->nombre_completo,
        ]);
    }

    /**
     * Un carne renovado se distingue de uno que no es de esta clase.
     *
     * Son dos arreglos distintos —uno se resuelve imprimiendo el carne nuevo y
     * el otro mirando el grupo— y decir lo mismo en los dos casos manda a la
     * mitad de la gente por donde no es.
     */
    public function test_un_carne_que_ya_no_vale_se_distingue_del_de_otra_clase(): void
    {
        $this->inscribir();
        $clase = $this->clase();
        $viejo = $this->ana->codigoQr();
        $this->ana->renovarCodigoQr();

        $this->actingAs($this->profesor->user)->postJson(
            route('clase-comprobar-carne', $clase),
            ['codigo' => CarneQr::PREFIJO.$viejo]
        )->assertOk()->assertJson(['encontrado' => false, 'motivo' => 'desconocido']);
    }

    /** Y basura no llega a ser una consulta. */
    public function test_lo_que_no_tiene_forma_de_carne_no_se_consulta(): void
    {
        $this->inscribir();
        $clase = $this->clase();

        $this->actingAs($this->profesor->user)->postJson(
            route('clase-comprobar-carne', $clase),
            ['codigo' => 'https://ejemplo.com/loquesea']
        )->assertOk()->assertJson(['encontrado' => false, 'motivo' => 'no_es_carne']);
    }

    /**
     * QUIEN NO DICTA NO CONVIERTE UN CODIGO EN UN NOMBRE.
     *
     * Es la puerta que importa de esta ruta: sin ella seria el resquicio por el
     * que cualquiera del panel —o un director mirando la hoja— resuelve carnes
     * ajenos. Es la misma barrera que la de escribir la lista, no una mas suelta.
     */
    public function test_quien_no_dicta_no_puede_resolver_un_carne(): void
    {
        $this->inscribir();
        $clase = $this->clase();
        $otro = $this->perfil('otraprofe', 'profesor');

        $this->actingAs($otro->user)->postJson(
            route('clase-comprobar-carne', $clase),
            ['codigo' => CarneQr::PREFIJO.$this->ana->codigoQr()]
        )->assertForbidden();
    }

    /** Y el administrador tampoco, que es quien mas cerca esta de poder. */
    public function test_el_administrador_tampoco_resuelve_carnes_de_una_clase(): void
    {
        $this->inscribir();
        $clase = $this->clase();

        $this->actingAs($this->administrador->user)->postJson(
            route('clase-comprobar-carne', $clase),
            ['codigo' => CarneQr::PREFIJO.$this->ana->codigoQr()]
        )->assertForbidden();
    }

    // ------------------------------------------------------------------
    // Lo que se lee del cuadrito
    // ------------------------------------------------------------------

    public function test_solo_se_acepta_lo_que_tiene_forma_de_carne(): void
    {
        $bueno = CarneQr::PREFIJO.'0123456789abcdef';

        $this->assertSame('0123456789abcdef', CarneQr::codigoLeido($bueno));
        $this->assertSame('0123456789abcdef', CarneQr::codigoLeido("  {$bueno}  "));

        // Un codigo de otro sistema, uno corto, uno largo y uno con simbolos.
        $this->assertNull(CarneQr::codigoLeido('https://ejemplo.com/algo'));
        $this->assertNull(CarneQr::codigoLeido(CarneQr::PREFIJO.'corto'));
        $this->assertNull(CarneQr::codigoLeido(CarneQr::PREFIJO.str_repeat('a', 40)));
        $this->assertNull(CarneQr::codigoLeido(CarneQr::PREFIJO.'0123456789abcde/'));
        $this->assertNull(CarneQr::codigoLeido(''));
    }

    /**
     * Un codigo vacio NO encuentra a nadie.
     *
     * Sin ese corte, `where('codigo_qr', '')` devolveria a cualquiera de los que
     * todavia no han pedido su carne — y un cuadrito ilegible se lee a veces
     * como una cadena vacia.
     */
    public function test_un_codigo_vacio_no_encuentra_a_nadie(): void
    {
        $this->assertNull(Perfil::porCodigoQr(''));
        $this->assertNull(Perfil::porCodigoQr('   '));
    }

    /** El carne es de estudiantes: el codigo de un profesor no resuelve. */
    public function test_el_codigo_de_un_profesor_no_identifica_a_nadie(): void
    {
        $codigo = $this->profesor->codigoQr();

        $this->assertNull(Perfil::porCodigoQr($codigo));
    }

    // ------------------------------------------------------------------
    // Andamiaje
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $datos
     */
    private function pasarLista(Clase $clase, array $datos): TestResponse
    {
        return $this->actingAs($this->profesor->user)
            ->post(route('clase-asistencia', $clase), $datos);
    }

    private function clase(): Clase
    {
        return Clase::create([
            'grupo_id' => $this->grupo->id,
            'periodo_id' => $this->periodo->id,
            'fecha_hora' => Carbon::now()->subHours(2),
            'registrada_por_id' => $this->profesor->id,
            'confirmaciones_requeridas' => 1,
        ]);
    }

    private function inscribir(): Matricula
    {
        $matricula = new Matricula([
            'estudiante_id' => $this->ana->id,
            'promotoria_id' => $this->piano->id,
            'periodo_id' => $this->periodo->id,
            'estado' => Matricula::ACTIVA,
        ]);
        $matricula->save();
        $matricula->repartirEn([$this->grupo->id]);

        return $matricula;
    }

    /** Otra persona en el MISMO grupo, para los casos de plural. */
    private function inscribirA(Perfil $estudiante): Matricula
    {
        $matricula = new Matricula([
            'estudiante_id' => $estudiante->id,
            'promotoria_id' => $this->piano->id,
            'periodo_id' => $this->periodo->id,
            'estado' => Matricula::ACTIVA,
        ]);
        $matricula->save();
        $matricula->repartirEn([$this->grupo->id]);

        return $matricula;
    }

    /** Una matricula viva, pero en otra promotoria: no esta en esta hoja. */
    private function inscribirEnOtraPromotoria(Perfil $estudiante): Matricula
    {
        $otra = Promotoria::create([
            'nombre' => 'Guitarra',
            'area_id' => $this->piano->area_id,
            'profesor_id' => $this->profesor->id,
        ]);

        $matricula = new Matricula([
            'estudiante_id' => $estudiante->id,
            'promotoria_id' => $otra->id,
            'periodo_id' => $this->periodo->id,
            'estado' => Matricula::ACTIVA,
        ]);
        $matricula->save();

        return $matricula;
    }

    private function perfil(string $username, string $rol): Perfil
    {
        $user = User::create(['username' => $username, 'password' => 'x', 'activo' => true]);

        return Perfil::create([
            'user_id' => $user->id,
            'nombre_completo' => ucfirst($username).' Pérez',
            'rol' => $rol,
            'telefono' => '3001112233',
            'fecha_nacimiento' => Carbon::today()->subYears(20),
        ]);
    }
}
