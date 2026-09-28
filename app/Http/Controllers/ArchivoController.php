<?php

namespace App\Http\Controllers;

use App\Models\ConfiguracionInstitucion;
use App\Models\DocumentoEstudiante;
use App\Models\Perfil;
use App\Support\LogoInstitucion;
use App\Support\Permisos;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sirve los archivos subidos, cada uno con su propia regla.
 *
 * NINGUNO se expone como carpeta publica, y esa es la decision de fondo: en el
 * mismo sitio conviven el logo —marca publica— con fotos de perfil y copias de
 * documentos de identidad, que tienen reglas de visibilidad estrictas (Ley 1581).
 * Abrir el directorio entero para servir el logo entregaria tambien lo demas a
 * cualquiera que adivinara un nombre de archivo.
 *
 * En el despliegue esto se traduce en algo concreto: el disco de estos archivos
 * es `storage/app/private`, que queda FUERA de `public/`. Ni siquiera hace falta
 * confiar en una regla del servidor web.
 */
class ArchivoController extends Controller
{
    /**
     * El logo de la institucion.
     *
     * Es lo contrario del resto: marca publica, la ve hasta quien no ha iniciado
     * sesion. Se sirve sin restriccion, pero por su propia ruta en vez de abrir
     * la carpeta.
     */
    public function logo(): Response
    {
        // Siempre hay logo: el propio o las iniciales (`LogoInstitucion`). Antes
        // era un 404 sin logo propio y cada plantilla se buscaba el respaldo por
        // su cuenta, que era el logo de El Santuario para cualquier entidad.
        $binario = LogoInstitucion::binario();
        $tipo = (new \finfo(FILEINFO_MIME_TYPE))->buffer($binario) ?: 'image/png';

        return response($binario, 200, [
            'Content-Type' => $tipo,
            // Publica: es la misma imagen para todo el mundo, y las plantillas
            // la piden con `?v=`, que cambia con el logo, el nombre y el color.
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    /**
     * La firma que sella los certificados.
     *
     * Es lo contrario del logo, aunque las dos sean imagenes de la institucion y
     * vivan en la misma fila: el logo se ensena a todo el mundo y la firma solo
     * a quien la configura. Una firma escaneada en una URL abierta se la lleva
     * cualquiera para estampar el papel que quiera, y el certificado dejaria de
     * valer nada precisamente por lo que se supone que lo hace valer.
     *
     * La puerta de rol la pone la ruta; aqui solo queda el caso de que no haya
     * ninguna cargada.
     */
    public function firma(): StreamedResponse
    {
        $configuracion = ConfiguracionInstitucion::actual();

        abort_if($configuracion->firma === '', 404, 'La institución no tiene una firma cargada.');

        return $this->entregar($configuracion->firma);
    }

    /**
     * La foto de perfil de alguien, con la regla de visibilidad del modelo:
     *
     *     nombre, foto ... admin, director, profesor, companeros del MISMO
     *                      grupo
     *
     * Sumado a que cualquiera puede ver la suya.
     *
     * Quien es companero lo dice `Companeros`, y no una copia de aqui. Lo era
     * hasta el 27/08, cuando la regla se estrecho de la promotoria al grupo: una
     * puerta con su propia version de la regla habria seguido entregando la foto
     * de quien la pantalla ya no ensena, sin que fallara nada.
     */
    public function foto(Request $request, Perfil $perfil): StreamedResponse
    {
        $solicitante = $request->user()?->perfil;

        abort_if($solicitante === null, 404);

        // Quien ve que foto vive en UN sitio (`Permisos::puedeVerFoto`), acotado
        // el 27/09/2026: hasta entonces cualquier profesor veia la de cualquiera.
        //
        // Un 404 y no un 403 en los dos casos: que exista o no la foto de otra
        // persona tampoco es asunto de quien pregunta.
        abort_unless(Permisos::puedeVerFoto($solicitante, $perfil) && $perfil->foto_perfil !== '', 404);

        return $this->entregar($perfil->foto_perfil);
    }

    /**
     * La copia del documento de identidad. Solo el administrador.
     *
     * Va como descarga y no incrustada: es un documento de identidad, no una
     * imagen para mirar de paso.
     *
     * El rol se comprueba DOS veces, aqui y en la ruta (`rol:administrador`).
     * No sobra: es la convencion que el resto del proyecto declara en media
     * docena de sitios —no fiarse de que el enlace no se pintara— y este es el
     * dato mas protegido que guarda el sistema, la cedula o el registro civil
     * de un menor. Una ruta se edita en un renglon y el descuido no se ve;
     * aqui, al lado de lo que entrega, si.
     *
     * DESDE EL 05/09/2026 SIRVE CUALQUIER PAPEL, no solo el de identidad, que
     * dejo de ser un caso especial del esquema. Y eso ARREGLA UN AGUJERO que
     * habia al lado: los papeles variables se subian y NO habia forma de verlos
     * — ni ruta, ni enlace, ni pantalla—. La gente los entregaba y se quedaban
     * en el disco sin que nadie pudiera abrirlos.
     *
     * TODOS pasan por la puerta del administrador, tambien los variables. Es la
     * barrera que ya protegia al de identidad y no se baja al generalizar: un
     * papel que pide una institucion puede ser una historia clinica o un
     * certificado de discapacidad, y esos no son menos delicados que una cedula.
     * Si algun dia hace falta que un profesor vea alguno, sera una columna
     * `reservado` en `documentos_requeridos` y una decision tomada a proposito.
     */
    public function documento(Request $request, DocumentoEstudiante $entrega): StreamedResponse
    {
        // Un 404 y no un 403, igual que en `foto()`: que exista o no el
        // documento de otra persona no es asunto de quien pregunta.
        abort_unless($request->user()?->perfil?->rol === 'administrador', 404);

        abort_if($entrega->archivo === '', 404);

        return Storage::disk('local')->download(
            $entrega->archivo,
            basename($entrega->archivo)
        );
    }

    private function entregar(string $ruta): StreamedResponse
    {
        $disco = Storage::disk('local');

        abort_unless($disco->exists($ruta), 404);

        return $disco->response($ruta);
    }
}
