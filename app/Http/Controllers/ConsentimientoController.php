<?php

namespace App\Http\Controllers;

use App\Models\ConfiguracionInstitucion;
use App\Models\Perfil;
use App\Support\Imagen;
use App\Support\LogoInstitucion;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * El formato de autorizacion de tratamiento de datos y uso de imagen, en PDF.
 *
 * Es el unico papel de los que pide la institucion que el sistema IMPRIME. Los
 * demas requeridos son ranuras donde el estudiante sube algo que ya tiene —la
 * cedula, el certificado de EPS—; este no existe hasta que alguien lo baja, lo
 * firma y lo devuelve por la misma ranura.
 *
 * DOS VERSIONES, Y LA DIFERENCIA NO ES COSMETICA. Un menor de edad no puede
 * otorgar por si mismo esta autorizacion: la da su acudiente, en representacion
 * suya (Ley 1581 de 2012, art. 7). Asi que el formato del menor identifica a
 * DOS personas —quien participa y quien autoriza— y lo firma la segunda. El de
 * mayor de edad identifica y firma una sola.
 *
 * LA VERSION SE ELIGE POR LA EDAD DEL DIA EN QUE SE DESCARGA, y eso es lo
 * correcto aunque parezca fragil: lo que el papel documenta es un
 * consentimiento dado en una fecha, y quien lo dio entonces siendo menor lo dio
 * validamente. Cumplir 18 despues no invalida lo firmado ni obliga a repetirlo;
 * si la entidad quiere una autorizacion propia del ya mayor, la pide y esa
 * persona baja el formato que le toca ahora.
 *
 * LAS DOS AUTORIZACIONES VAN SEPARADAS y cada una con su par de casillas. No es
 * un adorno del diseno: son finalidades distintas y una de ellas —el uso de la
 * imagen— no puede condicionar la matricula, asi que tiene que poder negarse
 * sin negar la otra. Un solo «acepto todo» al pie convertiria la negativa en
 * imposible y la autorizacion en invalida.
 *
 * Y DESDE EL 09/09/2026 LA ENTIDAD PUEDE SUBIR EL SUYO, una version por edad,
 * desde Gestion → Institucion. Si lo hay, se entrega ESE y aqui no se imprime
 * nada. Es un papel legal y una entidad puede tener el suyo ya aprobado por su
 * area juridica; con uno solo posible, la unica salida era repartirlo por fuera
 * del sistema y pedirlo por otra ranura, que es como se pierden los papeles.
 *
 * Lo que se pierde al subirlo esta escrito en la pantalla que lo sube, porque
 * no se deduce: el papel de la entidad va EN BLANCO —el sistema no sabe donde
 * escribir el nombre dentro de un PDF ajeno— y no lleva la garantia de que
 * quepa en una hoja, que aqui se mide y alli no se puede.
 */
class ConsentimientoController extends Controller
{
    /** Las dos versiones. La clave es la que viaja por la URL. */
    private const VERSIONES = ['mayor', 'menor'];

    /**
     * El formato de quien lo pide, con sus datos ya escritos.
     *
     * Solo estudiantes: son los unicos que tienen ranura donde devolverlo. El
     * personal no sube papeles en este sistema.
     */
    public function mio(Request $request): Response
    {
        $perfil = $request->user()?->perfil;

        abort_if($perfil === null || $perfil->rol !== 'estudiante', 404);

        return $this->generar($perfil->es_menor ? 'menor' : 'mayor', $perfil);
    }

    /**
     * El formato EN BLANCO, para imprimir y repartir.
     *
     * Existe porque no todo el mundo llega por el sistema: en la practica hay
     * quien se matricula en ventanilla y firma el papel ahi mismo, y hasta que
     * exista la inscripcion manual —que hoy no existe— la administracion
     * necesita poder imprimir el formato sin entrar a la cuenta de nadie.
     *
     * Sin datos precargados a proposito: un formato en blanco con el nombre de
     * otra persona impresa es un problema, no una comodidad.
     */
    public function formato(string $tipo): Response
    {
        abort_unless(in_array($tipo, self::VERSIONES, true), 404);

        return $this->generar($tipo, null);
    }

    private function generar(string $version, ?Perfil $estudiante): Response
    {
        $institucion = ConfiguracionInstitucion::actual();

        $propio = $this->formatoDeLaEntidad($institucion, $version, $estudiante);

        if ($propio !== null) {
            return $propio;
        }

        $acudiente = $version === 'menor'
            ? $estudiante?->datosEstudiante?->acudiente
            : null;

        $pdf = Pdf::loadView('certificados.consentimiento', [
            'institucion' => $institucion,
            'esMenor' => $version === 'menor',
            'estudiante' => $estudiante,
            'documento' => $estudiante?->datosEstudiante?->documento_identidad ?: null,
            'acudiente' => $acudiente,
            // La direccion de la politica, escrita en el papel. Quien firma
            // tiene derecho a leer antes que autoriza, y el papel viaja
            // impreso: sin la URL dentro, ese derecho se queda en la pantalla
            // desde la que se descargo.
            'politica' => route('politica-datos'),
            'expedido' => now(),
            'logo' => $this->logo($institucion),
        ])->setPaper('letter');

        return $pdf->download($this->nombreDeArchivo($version, $estudiante));
    }

    /**
     * El formato que subio la entidad, listo para entregar, o null si no hay.
     *
     * TRES COSAS QUE NO SE VEN LEYENDO LA LLAMADA:
     *
     * 1. SE COMPRUEBA QUE EL ARCHIVO ESTE EN DISCO, no solo que la columna
     *    tenga algo escrito. Una fila que apunta a un archivo que no esta —un
     *    respaldo restaurado a medias, un borrado a mano— dejaria la descarga
     *    en un 500 justo para el papel que todo el mundo tiene que firmar. Sin
     *    archivo se cae al que imprime el sistema, que es la respuesta util.
     *
     * 2. VA COMO `application/pdf` DECLARADO, y no como lo que diga el disco:
     *    lo que se guarda ya pasa por la conversion de `Support\Documento`, asi
     *    que en esa carpeta solo hay PDF. Es la misma garantia que sostiene la
     *    ranura donde el estudiante lo devuelve.
     *
     * 3. EL NOMBRE DEL ARCHIVO ES EL MISMO que el del generado. El papel va en
     *    blanco, pero quien lo baja sigue siendo una persona concreta y en la
     *    carpeta de descargas de un celular tres «formato.pdf» seguidos no se
     *    distinguen — que es justo por lo que ese nombre existe.
     */
    private function formatoDeLaEntidad(
        ConfiguracionInstitucion $institucion,
        string $version,
        ?Perfil $estudiante
    ): ?Response {
        $ruta = $institucion->formatoPropio($version);

        if ($ruta === '') {
            return null;
        }

        $disco = Storage::disk('local');

        if (! $disco->exists($ruta)) {
            return null;
        }

        return $disco->download(
            $ruta,
            $this->nombreDeArchivo($version, $estudiante),
            ['Content-Type' => 'application/pdf']
        );
    }

    /**
     * El nombre del archivo que se descarga.
     *
     * Con el nombre de quien lo va a firmar cuando se sabe: en la carpeta de
     * descargas del celular, tres «consentimiento.pdf» seguidos no se
     * distinguen, y este es un papel que una familia baja para varios hijos.
     */
    private function nombreDeArchivo(string $version, ?Perfil $estudiante): string
    {
        if ($estudiante === null) {
            return "autorizacion-datos-{$version}-de-edad.pdf";
        }

        return 'autorizacion-datos-'.Str::slug($estudiante->nombre_completo).'.pdf';
    }

    /**
     * El logo que encabeza el formato.
     *
     * Misma regla que el certificado y por la misma razon: el propio de la
     * institucion si lo cargaron, y si no sus iniciales (`LogoInstitucion`). Mirar solo
     * la fila de configuracion dejaba sin logo justo a la entidad que acaba de
     * instalar el sistema, que es la que mas lo necesita — este papel sale de
     * casa firmado.
     *
     * Se lee en el servidor y no por su URL: dompdf no sale a la red a buscar
     * nada.
     *
     * Va ACOTADO a `LADO_LOGO_IMPRESO`, y eso es la mitad de por que este papel
     * dejo de pesar un megabyte. La otra mitad es el recorte de la fuente, que
     * vive en `config/dompdf.php`.
     */
    private function logo(ConfiguracionInstitucion $institucion): ?string
    {
        // El propio o las iniciales generadas: la regla vive en `LogoInstitucion`.
        return Imagen::aDataUriPng(LogoInstitucion::binario(), Imagen::LADO_LOGO_IMPRESO);
    }
}
