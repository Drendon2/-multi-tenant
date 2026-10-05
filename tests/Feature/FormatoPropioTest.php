<?php

namespace Tests\Feature;

use App\Models\ConfiguracionInstitucion;
use App\Models\DatosEstudiante;
use App\Models\Perfil;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * El formato de autorizacion que sube LA ENTIDAD, en lugar del que imprime el
 * sistema.
 *
 * Pedido el 09/09/2026. Es un papel legal y una entidad puede tener el suyo ya
 * aprobado por su area juridica; hasta ese dia el sistema imprimia uno y no
 * habia otra opcion, asi que la unica salida era repartir el propio por fuera y
 * recogerlo por otra ranura — que es como se pierden los papeles.
 *
 * LO QUE VIGILA ESTE ARCHIVO:
 *
 * 1. QUE LAS DOS RANURAS SEAN INDEPENDIENTES. La version de mayor de edad y la
 *    de menor no son el mismo papel con otro titulo: un menor no otorga esta
 *    autorizacion por si mismo (Ley 1581 de 2012, art. 7), la da su acudiente,
 *    y ese formato identifica a DOS personas. Subir una tiene que dejar la otra
 *    donde estaba. Con un solo campo, esto pasaria en verde el dia que alguien
 *    las una «porque son lo mismo».
 *
 * 2. QUE NO SUBIR NADA SIGA FUNCIONANDO. Vacio significa «imprimelo tu», y esa
 *    es la situacion de todas las entidades hasta que suban algo. Es el camino
 *    que ya existia y el que no puede romperse.
 *
 * 3. QUE UNA COLUMNA QUE APUNTA A LA NADA NO TUMBE LA DESCARGA. Un respaldo
 *    restaurado a medias deja la fila y se lleva el archivo. Sin la caida al
 *    formato impreso, eso es un 500 en el unico papel que todo el mundo tiene
 *    que firmar.
 *
 * 4. QUE LO QUE LLEGA COMO FOTO SE GUARDE COMO PDF. La descarga lo entrega
 *    declarado `application/pdf`; un JPEG con ese encabezado no abre en un
 *    celular y no dice por que.
 */
class FormatoPropioTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Lo que distingue el papel de la entidad del que imprime el sistema.
     *
     * Va dentro de un PDF valido pero minimo: lo unico que estas pruebas
     * necesitan es reconocer cual de los dos llego.
     */
    private const MARCA = 'FORMATO-DE-LA-ENTIDAD';

    /** Los campos sin los que la pantalla de Institucion no guarda. */
    private const MINIMO = [
        'seccion' => 'entidad',
        'nombre_institucion' => 'Casa de la Cultura',
        'color_acento' => '#0a7a59',
        'limite_promotorias_por_periodo' => 2,
        'faltas_para_abandono' => 5,
    ];

    private Perfil $admin;

    private Perfil $mayor;

    private Perfil $menor;

    protected function setUp(): void
    {
        parent::setUp();

        // EL DISCO VA FALSO, como en las demas pruebas que suben archivos. Sin
        // esto los formatos de prueba se escriben en `storage/app/private` de
        // verdad y se quedan ahi: no rompen nada y por eso no se nota — se
        // acumulan hasta que alguien mira la carpeta.
        Storage::fake('local');

        $this->admin = $this->perfil('jefa', 'administrador');
        $this->mayor = $this->estudiante('ana', 30);
        $this->menor = $this->estudiante('tomas', 12);
    }

    // ------------------------------------------------------------------
    // Sin nada subido: lo imprime el sistema, como siempre
    // ------------------------------------------------------------------

    public function test_sin_formato_propio_lo_sigue_imprimiendo_el_sistema(): void
    {
        foreach ([$this->mayor, $this->menor] as $quien) {
            $contenido = $this->bajar($quien);

            $this->assertStringStartsWith('%PDF-', $contenido);
            $this->assertStringNotContainsString(self::MARCA, $contenido);
        }
    }

    // ------------------------------------------------------------------
    // Con formato propio
    // ------------------------------------------------------------------

    /**
     * La ranura del mayor NO alcanza al menor.
     *
     * Es la prueba central del archivo: afirma las dos mitades a la vez, y la
     * segunda —que el menor siga recibiendo el impreso— es la que se pondria
     * roja el dia que alguien junte las dos columnas en una.
     */
    public function test_cada_version_tiene_su_propia_ranura(): void
    {
        $this->subir('mayor');

        $this->assertStringContainsString(self::MARCA, $this->bajar($this->mayor));
        $this->assertStringNotContainsString(self::MARCA, $this->bajar($this->menor));
    }

    public function test_el_formato_en_blanco_de_direccion_tambien_entrega_el_propio(): void
    {
        $this->subir('menor');

        $propio = $this->actingAs($this->admin->user)
            ->get(route('consentimiento-formato', ['tipo' => 'menor']));

        $propio->assertOk();
        $this->assertStringContainsString(self::MARCA, $this->cuerpo($propio));

        $delSistema = $this->actingAs($this->admin->user)
            ->get(route('consentimiento-formato', ['tipo' => 'mayor']));

        $this->assertStringNotContainsString(self::MARCA, $this->cuerpo($delSistema));
    }

    /** Lo que se entrega va declarado como PDF, se llame como se llame. */
    public function test_lo_propio_se_entrega_declarado_como_pdf(): void
    {
        $this->subir('mayor');

        $respuesta = $this->actingAs($this->mayor->user)->get(route('consentimiento'));

        $respuesta->assertOk();
        $this->assertStringContainsString(
            'application/pdf',
            (string) $respuesta->headers->get('content-type')
        );
    }

    /** Una foto del papel se guarda convertida, no tal cual. */
    public function test_una_foto_del_papel_se_guarda_en_pdf(): void
    {
        $this->guardar(['consentimiento_mayor' => $this->foto()]);

        $ruta = ConfiguracionInstitucion::actual()->fresh()->formatoPropio('mayor');

        $this->assertNotSame('', $ruta);
        $this->assertStringEndsWith('.pdf', $ruta);
        $this->assertStringStartsWith('%PDF-', (string) Storage::disk('local')->get($ruta));
    }

    /** Y lo que ya es PDF se guarda tal cual, sin pasarlo por nada. */
    public function test_un_pdf_se_guarda_tal_cual(): void
    {
        $this->subir('mayor');

        $ruta = ConfiguracionInstitucion::actual()->fresh()->formatoPropio('mayor');

        $this->assertSame(self::pdf(), (string) Storage::disk('local')->get($ruta));
    }

    // ------------------------------------------------------------------
    // Quitarlo, y el archivo que se perdio
    // ------------------------------------------------------------------

    public function test_quitarlo_vuelve_al_que_imprime_el_sistema(): void
    {
        $this->subir('mayor');
        $ruta = ConfiguracionInstitucion::actual()->fresh()->formatoPropio('mayor');

        $this->guardar(['quitar_consentimiento_mayor' => '1']);

        $this->assertSame('', ConfiguracionInstitucion::actual()->fresh()->formatoPropio('mayor'));
        $this->assertStringNotContainsString(self::MARCA, $this->bajar($this->mayor));

        // Y el archivo no se queda ocupando disco en un hosting compartido.
        $this->assertFalse(Storage::disk('local')->exists($ruta));
    }

    /**
     * Una fila que apunta a un archivo que no esta cae al formato impreso.
     *
     * Sin esa caida es un 500, y justo en el papel que todo el mundo tiene que
     * firmar. No hace falta un respaldo a medias para llegar aqui: basta con
     * que alguien limpie la carpeta.
     */
    public function test_si_el_archivo_no_esta_se_imprime_el_del_sistema(): void
    {
        $this->subir('mayor');

        Storage::disk('local')->delete(
            ConfiguracionInstitucion::actual()->fresh()->formatoPropio('mayor')
        );

        $contenido = $this->bajar($this->mayor);

        $this->assertStringStartsWith('%PDF-', $contenido);
        $this->assertStringNotContainsString(self::MARCA, $contenido);
    }

    // ------------------------------------------------------------------
    // Lo que no entra
    // ------------------------------------------------------------------

    /**
     * Manda el CONTENIDO y no la extension, que es la regla que ya rige los
     * papeles del estudiante. Las dos las escribe quien sube el archivo.
     */
    public function test_un_archivo_que_no_es_papel_se_rechaza(): void
    {
        $this->actingAs($this->admin->user)
            ->post(route('gestion-configuracion'), self::MINIMO + [
                'consentimiento_mayor' => UploadedFile::fake()
                    ->createWithContent('formato.pdf', 'esto no es un pdf ni una foto'),
            ])
            ->assertSessionHasErrors('consentimiento_mayor');

        $this->assertSame('', ConfiguracionInstitucion::actual()->fresh()->formatoPropio('mayor'));
    }

    /**
     * Y el error ABRE el plegado donde vive el campo.
     *
     * Es la trampa escrita en CLAUDE.md: el aviso de arriba manda a buscar el
     * campo en rojo y el campo esta dentro de un `<details>` cerrado. Este vive
     * en «Datos de la entidad y política», que se abre solo si falla uno de SUS
     * campos — y los dos nuevos habia que anadirlos a esa lista a mano.
     */
    public function test_el_rechazo_se_ve(): void
    {
        $html = $this->actingAs($this->admin->user)
            ->from(route('gestion-configuracion'))
            ->followingRedirects()
            ->post(route('gestion-configuracion'), self::MINIMO + [
                'consentimiento_menor' => UploadedFile::fake()
                    ->createWithContent('formato.pdf', 'esto no es un pdf ni una foto'),
            ])
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/<details[^>]*id="bloque-datos-entidad"[^>]*\sopen/',
            (string) $html,
            'El plegado que contiene el campo llego cerrado, asi que el error queda escondido.'
        );
    }

    // ------------------------------------------------------------------
    // Andamiaje
    // ------------------------------------------------------------------

    private static function pdf(): string
    {
        return "%PDF-1.4\n% ".self::MARCA."\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
    }

    private function subir(string $version): void
    {
        $this->guardar([
            'consentimiento_'.$version => UploadedFile::fake()
                ->createWithContent('formato.pdf', self::pdf()),
        ]);
    }

    /** @param  array<string, mixed>  $campos */
    private function guardar(array $campos): void
    {
        $this->actingAs($this->admin->user)
            ->post(route('gestion-configuracion'), $campos + self::MINIMO)
            ->assertSessionHasNoErrors();
    }

    private function bajar(Perfil $estudiante): string
    {
        $respuesta = $this->actingAs($estudiante->user)->get(route('consentimiento'));

        $respuesta->assertOk();

        return $this->cuerpo($respuesta);
    }

    /**
     * El cuerpo de una descarga.
     *
     * Lo que sube del disco viaja en streaming y `getContent()` devolveria
     * false; lo que imprime dompdf es una respuesta normal. Se distinguen por
     * el tipo, no por adivinar.
     */
    private function cuerpo(TestResponse $respuesta): string
    {
        return $respuesta->baseResponse instanceof StreamedResponse
            ? $respuesta->streamedContent()
            : (string) $respuesta->getContent();
    }

    private function foto(): UploadedFile
    {
        $lienzo = imagecreatetruecolor(600, 800);
        imagefilledrectangle($lienzo, 0, 0, 599, 799, (int) imagecolorallocate($lienzo, 240, 240, 235));

        ob_start();
        imagejpeg($lienzo, null, 90);
        $jpeg = (string) ob_get_clean();
        imagedestroy($lienzo);

        return UploadedFile::fake()->createWithContent('formato.jpg', $jpeg);
    }

    private function estudiante(string $username, int $edad): Perfil
    {
        $perfil = $this->perfil($username, 'estudiante', $edad);

        DatosEstudiante::create([
            'perfil_id' => $perfil->id,
            'documento_identidad' => str_pad((string) $perfil->id, 8, '0', STR_PAD_LEFT),
        ]);

        return $perfil->fresh();
    }

    private function perfil(string $username, string $rol, int $edad = 30): Perfil
    {
        $user = User::create(['username' => $username, 'password' => 'x', 'activo' => true]);

        return Perfil::create([
            'user_id' => $user->id,
            'nombre_completo' => ucfirst($username).' Pérez',
            'rol' => $rol,
            'telefono' => '3001112233',
            'fecha_nacimiento' => Carbon::today()->subYears($edad),
        ]);
    }
}
