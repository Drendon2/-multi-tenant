<?php

namespace Tests\Feature;

use App\Models\ConfiguracionInstitucion;
use App\Models\Perfil;
use App\Models\User;
use App\Support\PoliticaDatos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * La pagina publica de tratamiento de datos y el pie que lleva a ella.
 *
 * LO QUE VIGILA ESTE ARCHIVO, y por que cada cosa:
 *
 * 1. QUE LA PAGINA SE LEA SIN SESION. Es el requisito entero: el enlace va en
 *    el pie de las tres pantallas de quien todavia no tiene cuenta, que son
 *    justo donde alguien entrega sus datos por primera vez. Una politica que
 *    solo se puede leer despues de entregarlos no cumple para lo que existe.
 *
 * 2. QUE EL PIE ESTE EN LAS DOS FAMILIAS DE PANTALLAS. Son dos envoltorios
 *    distintos —`layouts.app` y `layouts.publico`— y anadirlo a uno y olvidar
 *    el otro no rompe nada visible: la mitad del sistema se queda sin enlace.
 *
 * 3. QUE EL PIE VIVA FUERA DE `<main>`. Esta es la que parece tonta y no lo es.
 *    `acciones.js` responde a una accion reemplazando el contenido de `<main>`
 *    sin navegar, y `layouts.fragmento` —que es literalmente lo que va dentro
 *    de `<main>`— no incluye el pie. Metido dentro, el enlace desapareceria tras
 *    la primera accion hecha sin recargar, sin que nada fallara ni avisara.
 *
 * 4. QUE EL TEXTO PROPIO MANDE, y que vacio signifique «el de fabrica». Esa
 *    distincion es la funcion entera del campo, y se ve igual —el textarea
 *    vacio— en los dos casos.
 *
 * 5. QUE LO ESCRITO EN EL TEXTAREA SE ESCAPE. Es el unico sitio del proyecto
 *    que imprime HTML sin escapar en la plantilla, y lo que hay en esa columna
 *    lo tecleo una persona.
 */
class TratamientoDeDatosTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------------
    // La pagina
    // ------------------------------------------------------------------

    /** Sin sesion, que es el caso que la justifica. */
    public function test_la_pagina_se_lee_sin_haber_entrado(): void
    {
        $configuracion = ConfiguracionInstitucion::actual();
        $configuracion->nombre_institucion = 'Casa de la Cultura de El Santuario';
        $configuracion->save();

        $html = $this->get(route('politica-datos'))->assertOk()->getContent();

        $this->assertStringContainsString('Tratamiento de datos personales', $html);
        $this->assertStringContainsString('Casa de la Cultura de El Santuario', $html);
        // Las dos finalidades del encargo. Si alguna se cae del texto de
        // fabrica, el consentimiento pasa a autorizar algo que la politica no
        // anuncia, y eso no vale.
        $this->assertStringContainsString('análisis estadístico y la planeación', $html);
        $this->assertStringContainsString('uso de tu imagen', $html);

        // Y NADA SOBRE QUIEN ES LA ENTIDAD. Decia «politicas publicas del sector
        // cultura» y «procesos formativos y culturales»: exacto para una casa de
        // la cultura publica, falso para un colegio privado o una fundacion.
        // Esto se vende a esas tambien. Es una prueba de PRODUCTO, no de
        // redaccion — vigila a quien se le puede vender el sistema.
        $this->assertStringNotContainsString('sector cultura', $html);
        $this->assertStringNotContainsString('culturales', $html);
        $this->assertStringNotContainsString('políticas públicas', $html);
        $this->assertStringNotContainsString('entidades públicas', $html);
        $this->assertStringContainsString('Ley 1581 de 2012', $html);
    }

    /** Y con la sesion abierta tambien: es la misma pantalla para todos. */
    public function test_la_pagina_se_lee_con_la_sesion_abierta(): void
    {
        $this->actingAs($this->perfil('ana', 'estudiante')->user)
            ->get(route('politica-datos'))
            ->assertOk();
    }

    /**
     * Los datos de contacto que la entidad no ha rellenado NO se pintan vacios.
     *
     * Es preferible una politica sin telefono a una que diga «Teléfono:» y
     * nada detras: lo segundo se lee como un dato que se perdio.
     */
    public function test_el_contacto_que_falta_no_deja_un_renglon_vacio(): void
    {
        $configuracion = ConfiguracionInstitucion::actual();
        $configuracion->entidad_correo = 'datos@ejemplo.gov.co';
        $configuracion->save();

        $html = $this->get(route('politica-datos'))->assertOk()->getContent();

        $this->assertStringContainsString('datos@ejemplo.gov.co', $html);
        $this->assertStringNotContainsString('Teléfono:', $html, 'se pintó el renglón de un dato que no hay.');
        $this->assertStringNotContainsString('NIT:', $html, 'se pintó el renglón de un dato que no hay.');
    }

    /**
     * Sin correo, la politica no promete un canal que no existe.
     *
     * Dice «la dirección de contacto de la institución» en vez de dejar la
     * frase colgando, que es lo que pasaria interpolando una cadena vacia.
     */
    public function test_sin_correo_no_se_promete_un_canal_que_no_existe(): void
    {
        $html = $this->get(route('politica-datos'))->assertOk()->getContent();

        $this->assertStringContainsString('dirección de contacto de la institución', $html);
    }

    // ------------------------------------------------------------------
    // El texto: de fabrica contra el propio
    // ------------------------------------------------------------------

    /** Escrito por la entidad, manda el suyo y desaparece el de fabrica. */
    public function test_el_texto_propio_sustituye_al_de_fabrica(): void
    {
        $configuracion = ConfiguracionInstitucion::actual();
        $configuracion->politica_datos = "## Nuestra política\n\nEsto lo escribió la entidad.";
        $configuracion->save();

        $html = $this->get(route('politica-datos'))->assertOk()->getContent();

        $this->assertStringContainsString('Esto lo escribió la entidad.', $html);
        $this->assertStringNotContainsString('Ley 1581 de 2012', $html, 'siguió saliendo el texto de fábrica.');
    }

    /**
     * Y vaciarlo devuelve el de fabrica.
     *
     * Es el camino de vuelta, y sin el la entidad que escribe algo una vez se
     * queda sin forma de volver desde la pantalla. Se guarda NULL y no cadena
     * vacia: con '' la pagina publicaria una politica en blanco.
     */
    public function test_vaciarlo_devuelve_el_texto_de_fabrica(): void
    {
        $admin = $this->perfil('jefa', 'administrador');

        $configuracion = ConfiguracionInstitucion::actual();
        $configuracion->politica_datos = 'Algo propio.';
        $configuracion->save();

        $this->actingAs($admin->user)
            ->post(route('gestion-configuracion'), $this->formularioDeInstitucion(['politica_datos' => '   ']))
            ->assertRedirect();

        $this->assertNull(
            ConfiguracionInstitucion::actual()->fresh()->politica_datos,
            'un textarea con solo espacios tenía que volver al texto de fábrica.'
        );

        $this->assertStringContainsString(
            'Ley 1581 de 2012',
            $this->get(route('politica-datos'))->getContent()
        );
    }

    /** Los cuatro datos de la entidad se guardan desde Institucion. */
    public function test_los_datos_de_la_entidad_se_guardan(): void
    {
        $admin = $this->perfil('jefa', 'administrador');

        $this->actingAs($admin->user)
            ->post(route('gestion-configuracion'), $this->formularioDeInstitucion([
                'entidad_nit' => '890.900.111-1',
                'entidad_direccion' => 'Calle 1 # 2-3',
                'entidad_correo' => 'datos@ejemplo.gov.co',
                'entidad_telefono' => '604 555 0000',
            ]))
            ->assertRedirect();

        $guardada = ConfiguracionInstitucion::actual()->fresh();

        $this->assertSame('890.900.111-1', $guardada->entidad_nit);
        $this->assertSame('Calle 1 # 2-3', $guardada->entidad_direccion);
        $this->assertSame('datos@ejemplo.gov.co', $guardada->entidad_correo);
        $this->assertSame('604 555 0000', $guardada->entidad_telefono);

        // Y salen en la pagina publica, que es para lo que se piden.
        $html = $this->get(route('politica-datos'))->getContent();

        $this->assertStringContainsString('890.900.111-1', $html);
        $this->assertStringContainsString('Calle 1 # 2-3', $html);
    }

    // ------------------------------------------------------------------
    // Las dos finalidades editables
    // ------------------------------------------------------------------

    /**
     * CAMBIAR UNA FINALIDAD LA MUEVE EN LOS DOS SITIOS A LA VEZ.
     *
     * Esta es la prueba que sostiene el diseño entero. La política tiene que
     * ANUNCIAR lo que el consentimiento autoriza: si no coinciden, lo firmado no
     * vale. Con dos textos independientes eso dependía de que alguien se
     * acordara de tocar los dos —había un aviso escrito en `PoliticaDatos`
     * pidiéndolo—. Leyendo los dos de la misma columna, no hay forma de que se
     * separen, y si alguien la rompe esta prueba se pone roja.
     */
    public function test_la_finalidad_cambia_la_politica_y_el_formato_a_la_vez(): void
    {
        $configuracion = ConfiguracionInstitucion::actual();
        $configuracion->finalidad_datos = 'la caracterización de la población deportiva del municipio';
        $configuracion->finalidad_imagen = 'difundir los torneos y escuelas de formación';
        $configuracion->save();

        $politica = $this->get(route('politica-datos'))->assertOk()->getContent();

        $formato = view('certificados.consentimiento', [
            'institucion' => ConfiguracionInstitucion::actual()->fresh(),
            'esMenor' => false,
            'estudiante' => null,
            'documento' => null,
            'acudiente' => null,
            'politica' => route('politica-datos'),
            'expedido' => Carbon::now(),
            'logo' => null,
        ])->render();

        foreach ([$politica, $formato] as $texto) {
            $this->assertStringContainsString('caracterización de la población deportiva', $texto);
            $this->assertStringContainsString('difundir los torneos', $texto);
            // Y la de fábrica desaparece de los dos, no solo de uno.
            $this->assertStringNotContainsString('análisis estadístico y la planeación', $texto);
        }
    }

    /** Vacías, mandan las de fábrica. Es el camino de vuelta. */
    public function test_sin_finalidad_propia_se_usa_la_de_fabrica(): void
    {
        $html = $this->get(route('politica-datos'))->assertOk()->getContent();

        $this->assertStringContainsString(ConfiguracionInstitucion::FINALIDAD_DATOS, $html);
        $this->assertStringContainsString(ConfiguracionInstitucion::FINALIDAD_IMAGEN, $html);
    }

    /** Y se guardan desde Institución, recortadas. */
    public function test_las_finalidades_se_guardan_desde_institucion(): void
    {
        $this->actingAs($this->perfil('jefa', 'administrador')->user)
            ->post(route('gestion-configuracion'), $this->formularioDeInstitucion([
                'finalidad_datos' => '  la planeación de la oferta académica  ',
                'finalidad_imagen' => '  difundir la vida escolar  ',
            ]))
            ->assertRedirect();

        $guardada = ConfiguracionInstitucion::actual()->fresh();

        $this->assertSame('la planeación de la oferta académica', $guardada->finalidad_datos);
        $this->assertSame('difundir la vida escolar', $guardada->finalidad_imagen);
    }

    /**
     * LA SECCIÓN PLEGADA NO ESCONDE SUS ERRORES.
     *
     * Es la trampa de CLAUDE.md: un `<details>` cerrado deja el campo en rojo
     * fuera de la vista, y el aviso de arriba manda a buscar algo que no se ve.
     * Se abre solo cuando el error es de SUS campos — no con `$errors->any()`,
     * que la abriría porque falló cualquier otro campo de la pantalla.
     */
    public function test_la_seccion_plegable_se_abre_si_su_campo_falla(): void
    {
        $admin = $this->perfil('jefa', 'administrador');

        // Un correo inválido: el error es de esta sección.
        $rechazo = $this->actingAs($admin->user)
            ->from(route('gestion-configuracion'))
            ->post(route('gestion-configuracion'), $this->formularioDeInstitucion([
                'entidad_correo' => 'esto-no-es-un-correo',
            ]));

        $rechazo->assertRedirect(route('gestion-configuracion').'#entidad');

        // Se SIGUE la redirección: el error vive en la sesión y la sección se
        // abre al pintar la pantalla siguiente, no en la respuesta del POST.
        $html = (string) $this->followRedirects($rechazo)->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '#<details[^>]*id="bloque-datos-entidad"[^>]*\sopen#',
            $html,
            'la sección quedó plegada con un error dentro: nadie lo va a encontrar.'
        );

        // Y con la pantalla limpia se queda plegada, que es para lo que existe.
        $limpia = $this->actingAs($admin->user)->get(route('gestion-configuracion'))->getContent();

        $this->assertDoesNotMatchRegularExpression(
            '#<details[^>]*id="bloque-datos-entidad"[^>]*\sopen#',
            $limpia,
            'la sección sale desplegada sin motivo.'
        );
    }

    // ------------------------------------------------------------------
    // El conversor de texto a HTML
    // ------------------------------------------------------------------

    /**
     * LO QUE TECLEA UNA PERSONA SE ESCAPA.
     *
     * La plantilla imprime este HTML sin volver a escaparlo —es el unico sitio
     * del proyecto que lo hace— asi que el escape tiene que estar aqui. Sin
     * esto, quien pueda editar la configuracion puede meter un guion en una
     * pagina PUBLICA.
     */
    public function test_lo_que_se_escribe_en_la_politica_se_escapa(): void
    {
        $configuracion = ConfiguracionInstitucion::actual();
        $configuracion->politica_datos = '<script>alert(1)</script> y <b>negrita</b>';
        $configuracion->save();

        $html = $this->get(route('politica-datos'))->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html, 'salió un guion sin escapar.');
        $this->assertStringNotContainsString('<b>negrita</b>', $html, 'salió HTML sin escapar.');
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    /** El formato minimo: titulos, vinnetas y parrafos. */
    public function test_el_formato_minimo_del_texto(): void
    {
        $html = PoliticaDatos::aHtml("## Un título\n\nUn párrafo.\n\n- uno\n- dos\n\nOtro párrafo.");

        $this->assertStringContainsString('<h3>Un título</h3>', $html);
        $this->assertStringContainsString('<p>Un párrafo.</p>', $html);
        $this->assertStringContainsString('<ul><li>uno</li><li>dos</li></ul>', $html);
        $this->assertStringContainsString('<p>Otro párrafo.</p>', $html);
    }

    /**
     * Vinnetas seguidas son UNA lista, no una por linea.
     *
     * Se comprueba porque ya salio mal: al escribir el texto de fabrica cada
     * vinneta iba en su propio bloque y el conversor las envolvia en siete
     * `<ul>` de un elemento. Se pinta parecido y no es lo mismo — ni para un
     * lector de pantalla, que anuncia «lista de 1» siete veces.
     */
    public function test_las_vinnetas_seguidas_son_una_sola_lista(): void
    {
        $html = PoliticaDatos::aHtml("- uno\n- dos\n- tres");

        $this->assertSame(1, substr_count($html, '<ul>'), 'las viñetas salieron en listas sueltas.');
        $this->assertSame(3, substr_count($html, '<li>'));
    }

    /** Un titulo detras de una vinneta cierra la lista. */
    public function test_un_titulo_cierra_la_lista_que_venia(): void
    {
        $html = PoliticaDatos::aHtml("- uno\n## Título");

        $this->assertStringContainsString('</ul>', $html);
        $this->assertLessThan(
            strpos($html, '<h3>'),
            strpos($html, '</ul>'),
            'el <ul> se quedó abierto y el título cayó dentro.'
        );
    }

    // ------------------------------------------------------------------
    // El pie
    // ------------------------------------------------------------------

    /** En las pantallas SIN sesion, que son las que lo justifican. */
    public function test_el_pie_lleva_a_la_politica_sin_haber_entrado(): void
    {
        foreach ([route('login'), route('inscripcion'), route('registro')] as $url) {
            $html = $this->get($url)->assertOk()->getContent();

            $this->assertStringContainsString(route('politica-datos'), $html, "sin enlace en {$url}");
            $this->assertStringContainsString('Tratamiento de datos personales', $html, "sin enlace en {$url}");
        }
    }

    /** Y en las pantallas CON sesion, que son el otro envoltorio. */
    public function test_el_pie_lleva_a_la_politica_con_la_sesion_abierta(): void
    {
        $html = $this->actingAs($this->perfil('jefa', 'administrador')->user)
            ->get(route('panel'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(route('politica-datos'), $html);
    }

    /**
     * LA MARCA DEL AUTOR va en el pie de los DOS envoltorios (27/09/2026,
     * pedida por el). Atada a la clase y a las siglas, que son lo que se pidio;
     * no al rotulo de delante, que puede cambiar.
     */
    public function test_el_pie_lleva_la_marca_del_autor_en_los_dos_envoltorios(): void
    {
        $publico = $this->get(route('login'))->assertOk()->getContent();
        $conSesion = $this->actingAs($this->perfil('jefa', 'administrador')->user)
            ->get(route('panel'))->assertOk()->getContent();

        foreach (['sin sesion' => $publico, 'con sesion' => $conSesion] as $cual => $html) {
            $this->assertMatchesRegularExpression(
                '/class="pie-autor">[^<]*<abbr title="Daniel Fernando Rendón Ramírez">DFRR<\/abbr>/u',
                $html,
                "sin la marca del autor en el pie {$cual}"
            );
        }
    }

    /**
     * EL PIE VA FUERA DE `<main>`.
     *
     * Dentro, se lo llevaria el repintado de `acciones.js` en cuanto alguien
     * hiciera una accion sin recargar —`layouts.fragmento` no incluye el pie—
     * y el enlace desapareceria sin que nada fallara. No se ve mirando la
     * pantalla: hay que hacer una accion primero.
     */
    public function test_el_pie_no_vive_dentro_de_main(): void
    {
        $html = $this->actingAs($this->perfil('jefa', 'administrador')->user)
            ->get(route('panel'))
            ->assertOk()
            ->getContent();

        $cierreDeMain = strrpos($html, '</main>');
        $pie = strpos($html, '<footer class="pie">');

        $this->assertNotFalse($cierreDeMain, 'la pantalla no tiene <main>.');
        $this->assertNotFalse($pie, 'la pantalla no tiene pie.');
        $this->assertGreaterThan(
            $cierreDeMain,
            $pie,
            'el pie quedó DENTRO de <main>: el primer repintado sin recargar se lo lleva.'
        );
    }

    /**
     * Lo que va en el fragmento y lo que va en el envoltorio.
     *
     * `layouts.fragmento` es literalmente lo que va dentro de `<main>`, asi que
     * el pie NO puede estar ahi. Si algun dia alguien lo mete «para que se vea
     * igual», el fragmento pintaria un segundo pie dentro del `<main>` de la
     * pagina que ya tiene el suyo.
     */
    public function test_el_fragmento_no_trae_pie(): void
    {
        $fragmento = file_get_contents(resource_path('views/layouts/fragmento.blade.php'));

        $this->assertStringNotContainsString(
            'partials.pie',
            (string) $fragmento,
            'el fragmento incluye el pie: se pintaría un segundo pie dentro de <main>.'
        );
    }

    // ------------------------------------------------------------------
    // Andamiaje
    // ------------------------------------------------------------------

    /**
     * El formulario de Institucion entero.
     *
     * Va completo y no solo con lo que cada prueba cambia: `guardar()` valida
     * varios campos como obligatorios, y mandar solo uno devolveria un rechazo
     * en vez de guardar — con la prueba pasando en verde por la razon
     * equivocada.
     *
     * @param  array<string, mixed>  $cambios
     * @return array<string, mixed>
     */
    private function formularioDeInstitucion(array $cambios = []): array
    {
        return $cambios + [
            'seccion' => 'entidad',
            'nombre_institucion' => 'Casa de la Cultura',
            'color_acento' => '#0a7a59',
            'limite_promotorias_por_periodo' => 2,
            'faltas_para_abandono' => 5,
        ];
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
