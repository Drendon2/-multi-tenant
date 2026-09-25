<?php

namespace Tests\Feature;

use App\Models\Acudiente;
use App\Models\DatosEstudiante;
use App\Models\Perfil;
use App\Models\User;
use App\Support\Imagen;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Lo que pesa un PDF de este sistema, que es lo que tarda en bajar.
 *
 * EL ORIGEN: el 09/09/2026 el usuario avisó de que el formato de consentimiento
 * «le esta costando mucho descargar a ciertos dispositivos». Medido, pesaba
 * 911,6 KB — de los cuales 860 eran la fuente DejaVu Sans incrustada ENTERA en
 * cada descarga, redonda y negrita, porque dompdf viene de fabrica sin recortar
 * la fuente. El papel que la lleva es una hoja de texto.
 *
 * Recortada la fuente y acotado el logo, el mismo papel pesa 60,6 KB. Eso son
 * 851 KB menos por descarga, y quien los paga es un celular con datos en una
 * conexion que ya arrastra un CDN de 1,3 a 1,9 s de TTFB (ver CLAUDE.md).
 *
 * LO QUE VIGILA ESTE ARCHIVO, y por que hacen falta las tres cosas:
 *
 * 1. QUE EL PAPEL SIGA SIENDO LIGERO. Es la afirmacion que de verdad importa, y
 *    la que enrojece si alguien vuelve a apagar el recorte de la fuente.
 *
 * 2. QUE LA CONFIGURACION DE DOMPDF SIGA SIENDO LA DE ESTE PROYECTO. Es la que
 *    no se ve venir: `config/dompdf.php` es una copia del archivo del paquete
 *    con UNA linea cambiada, y el paquete lo carga con `mergeConfigFrom`, que
 *    es una fusion superficial. Quien borre ese archivo —al actualizar el
 *    paquete, por ejemplo— no rompe nada visible: los PDF se siguen generando,
 *    solo que vuelven a pesar un megabyte. Sin esta prueba eso no lo nota
 *    nadie, porque en local un megabyte no se siente.
 *
 * 3. QUE EL LOGO SE INCRUSTE ACOTADO. Esta no la ve la primera: con el recorte
 *    de la fuente puesto, el logo sin acotar deja el papel en 77,8 KB, que
 *    sigue estando por debajo de cualquier tope razonable. Se mide sobre la
 *    imagen, que es donde el fallo existe.
 */
class PdfQueNoPesaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * El tope, en bytes.
     *
     * 150 KB con el papel en 60,6: dos veces y media de holgura, para que un
     * logo distinto —cada entidad sube el suyo— o un texto mas largo no pongan
     * roja la suite por una decima. Lo que este numero caza es el orden de
     * magnitud, que es el fallo que hubo: 911 KB.
     */
    private const TOPE = 150 * 1024;

    public function test_el_formato_del_estudiante_no_pesa_como_para_no_bajarlo(): void
    {
        foreach ([$this->estudiante('ana', 30), $this->estudiante('tomas', 12, 'Marta Restrepo')] as $quien) {
            $respuesta = $this->actingAs($quien->user)->get(route('consentimiento'));

            $respuesta->assertOk();
            $peso = strlen((string) $respuesta->getContent());

            $this->assertLessThan(
                self::TOPE,
                $peso,
                'El formato de '.$quien->nombre_completo.' pesa '.round($peso / 1024, 1).' KB. '
                .'Mira si sigue encendido `enable_font_subsetting` en config/dompdf.php.'
            );
        }
    }

    public function test_el_formato_en_blanco_tampoco(): void
    {
        $direccion = $this->perfil('jefa', 'administrador');

        foreach (['mayor', 'menor'] as $version) {
            $respuesta = $this->actingAs($direccion->user)
                ->get(route('consentimiento-formato', ['tipo' => $version]));

            $respuesta->assertOk();

            $this->assertLessThan(self::TOPE, strlen((string) $respuesta->getContent()));
        }
    }

    /**
     * La configuracion del proyecto sigue en su sitio.
     *
     * No es una tautologia aunque lo parezca: lo que afirma es que
     * `config/dompdf.php` existe y llega hasta dompdf. Si alguien lo borra, esta
     * es la unica linea del repositorio que se entera.
     */
    public function test_la_fuente_se_recorta(): void
    {
        $this->assertTrue(
            config('dompdf.options.enable_font_subsetting'),
            'config/dompdf.php dejo de recortar la fuente: cada PDF vuelve a llevar '
            .'DejaVu Sans entera, unos 860 KB por descarga.'
        );
    }

    /**
     * El logo se incrusta a la resolucion del papel, no a la del archivo.
     *
     * Se mide el ancho de la imagen que sale, no el peso: el peso depende de
     * como sea el logo de cada entidad, y el ancho es la regla.
     */
    public function test_el_logo_se_incrusta_acotado(): void
    {
        // Una imagen REAL (un logo de 240 px en WebP), no una generada: la que
        // traia el proyecto como logo por defecto hasta el 25/09/2026, que desde
        // entonces vive aqui y no en `public/`.
        $original = (string) file_get_contents(base_path('tests/fixtures/imagen-real.webp'));
        $medidasOriginales = getimagesizefromstring($original);

        // Sin esto la prueba pasaria sola el dia que alguien cambie el logo del
        // proyecto por uno pequeno: no habria nada que acotar.
        $this->assertGreaterThan(
            Imagen::LADO_LOGO_IMPRESO,
            max($medidasOriginales[0], $medidasOriginales[1]),
            'La imagen de prueba ya es mas pequena que el tope, asi que esta prueba no mide nada.'
        );

        $incrustado = Imagen::aDataUriPng($original, Imagen::LADO_LOGO_IMPRESO);

        $this->assertNotNull($incrustado);

        $medidas = getimagesizefromstring(
            (string) base64_decode(substr((string) $incrustado, strlen('data:image/png;base64,')))
        );

        $this->assertLessThanOrEqual(Imagen::LADO_LOGO_IMPRESO, max($medidas[0], $medidas[1]));
    }

    // ------------------------------------------------------------------

    private function estudiante(string $username, int $edad, ?string $acudiente = null): Perfil
    {
        $perfil = $this->perfil($username, 'estudiante', $edad);

        // El documento sale del id del perfil y no de una constante: es unico
        // en el esquema y aqui se crean dos estudiantes en la misma prueba.
        $datos = DatosEstudiante::create([
            'perfil_id' => $perfil->id,
            'documento_identidad' => str_pad((string) $perfil->id, 8, '0', STR_PAD_LEFT),
        ]);

        if ($acudiente !== null) {
            Acudiente::create([
                'datos_estudiante_id' => $datos->id,
                'nombre' => $acudiente,
                'telefono' => '3001112233',
                'parentesco' => 'Madre',
            ]);
        }

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
