<?php

namespace App\Http\Controllers;

use App\Models\InstitucionExterna;
use App\Models\Perfil;
use App\Support\CarneQr;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * El carne con codigo QR: verlo, descargarlo y renovarlo.
 *
 * TRES PUERTAS Y NINGUNA MAS (decision del usuario, 21/09/2026):
 *
 * 1. El propio estudiante, desde Mi perfil.
 * 2. El administrador, desde la ficha de esa persona — que es como llega el
 *    carne a quien no sabe entrar al sistema, que es justo el publico para el
 *    que se construyo esto.
 * 3. La pantalla que sale al terminar de inscribirse, antes de tener sesion.
 *
 * EL PROFESOR NO ENTRA AQUI, aunque tenga al estudiante delante en su lista. Se
 * preguntó y se decidió asi: el carne es la llave con la que a alguien se le
 * marca asistencia, y quien pasa lista es a quien el sistema vigila con esas
 * mismas marcas. Que pueda sacar una copia del carne de cualquiera de sus
 * cuarenta estudiantes le daria la forma de marcarlos presentes sin que
 * estuvieran. Coste asumido: si a alguien se le pierde el carne en mitad de un
 * semestre, tiene que pedirselo a administracion.
 *
 * NO HAY RUTA PUBLICA POR CODIGO. Nada de `/carne/{codigo}`: seria una URL que
 * cualquiera que fotografie un carne ajeno puede abrir, y ahi el codigo dejaria
 * de ser un dato que solo vale dentro de una lista de clase.
 *
 * DESDE EL 23/09/2026 ESTA CLASE ENTREGA DOS CARTONES DISTINTOS. Las tres
 * puertas de arriba son las del CARNE DE UN ESTUDIANTE y siguen siendo esas
 * tres. El segundo es el QR DE UNA INSTITUCION EXTERNA, que no dice quien se
 * presenta sino donde se dio una clase, y tiene sus propias dos puertas
 * —administracion y la propia institucion— explicadas abajo, junto a sus
 * metodos. Comparten el trazado y la columna `codigo_qr`; no comparten ni el
 * significado ni quien los puede sacar, y mezclarlos es como se le acaba dando
 * a un profesor la llave con la que se verifica su propio trabajo.
 */
class CarneController extends Controller
{
    /** Lo que se le deja en la sesion a quien acaba de inscribirse. */
    public const RECIEN_INSCRITO = 'carne_recien_inscrito';

    /** El carne propio. Cualquiera con cuenta puede ver el suyo. */
    public function mio(Request $request): View|RedirectResponse
    {
        $perfil = $request->user()?->perfil;

        if ($perfil === null) {
            return redirect()->route('login')->with(
                'error',
                'Tu cuenta no tiene un perfil asociado. Contacta al administrador.'
            );
        }

        if ($perfil->rol !== 'estudiante') {
            // No es un permiso que falte, es que no existe: en una lista de
            // clase no hay profesores, asi que su carne no serviria para nada.
            return redirect()->route('mi-perfil')->with(
                'error',
                'El carné con código es de los estudiantes: sirve para que les marquen la asistencia en clase.'
            );
        }

        return view('perfil.carne-qr', [
            'estudiante' => $perfil,
            'propio' => true,
            'recienInscrito' => false,
            'descarga' => route('mi-carne-imagen'),
        ]);
    }

    public function imagenMia(Request $request): Response
    {
        $perfil = $request->user()?->perfil;

        if ($perfil === null || $perfil->rol !== 'estudiante') {
            abort(404);
        }

        return $this->entregar($perfil);
    }

    /**
     * El carne de un estudiante, para administracion.
     *
     * El rol se comprueba aqui ademas de en la ruta, que es la convencion de la
     * casa para lo que entrega datos de otra persona: una ruta se edita en un
     * renglon y el descuido no se ve; aqui, al lado de lo que entrega, si.
     */
    public function deEstudiante(Request $request, Perfil $usuario): View|RedirectResponse
    {
        if (! $this->puedeVerElDeOtro($request, $usuario)) {
            return redirect()->route('panel')->with(
                'error',
                'El carné de otra persona solo lo saca administración.'
            );
        }

        return view('perfil.carne-qr', [
            'estudiante' => $usuario,
            'propio' => false,
            'recienInscrito' => false,
            'descarga' => route('carne-estudiante-imagen', $usuario),
        ]);
    }

    public function imagenDeEstudiante(Request $request, Perfil $usuario): Response
    {
        abort_unless($this->puedeVerElDeOtro($request, $usuario), 404);

        return $this->entregar($usuario);
    }

    /**
     * Cambia el codigo: el carne viejo deja de servir en el acto.
     *
     * Lo puede hacer el dueno y el administrador. Es la unica salida cuando
     * alguien pierde el papel o le hacen una foto, y por eso existe desde el
     * primer dia y no "cuando haga falta": sin esto, un carne fotografiado no
     * se puede desactivar de ninguna manera.
     */
    public function renovarElMio(Request $request): RedirectResponse
    {
        $perfil = $request->user()?->perfil;

        if ($perfil === null || $perfil->rol !== 'estudiante') {
            abort(404);
        }

        $perfil->renovarCodigoQr();

        return redirect()->route('mi-carne')->with(
            'success',
            'Listo: tu carné anterior ya no sirve. Descarga el nuevo y guárdalo.'
        );
    }

    public function renovarDeEstudiante(Request $request, Perfil $usuario): RedirectResponse
    {
        abort_unless($this->puedeVerElDeOtro($request, $usuario), 404);

        $usuario->renovarCodigoQr();

        return redirect()->route('carne-estudiante', $usuario)->with(
            'success',
            "El carné anterior de {$usuario->nombre_completo} ya no sirve. Este es el nuevo."
        );
    }

    /**
     * El carne que se ensena una sola vez al terminar de inscribirse.
     *
     * NO LLEVA EL PERFIL EN LA URL, sino en la sesion, y por eso se pierde al
     * recargar. Es a proposito: quien acaba de inscribirse todavia no ha
     * iniciado sesion —la inscripcion deja en la pantalla de entrar— asi que
     * cualquier cosa en el camino seria una URL sin autenticar que entrega el
     * carne de alguien. Se ensena aqui, se descarga, y desde entonces se pide
     * desde Mi perfil como todo el mundo.
     */
    public function trasInscribirse(Request $request): View|RedirectResponse
    {
        $id = $request->session()->get(self::RECIEN_INSCRITO);
        $estudiante = $id === null ? null : Perfil::find($id);

        if ($estudiante === null) {
            return redirect()->route('login');
        }

        // Se REPONE en la sesion. La imagen de la pantalla va incrustada, asi
        // que verla no hace falta otra peticion; la DESCARGA si es una peticion
        // aparte, y con un flash de un solo uso llegaria ya sin permiso: el
        // boton de guardar el carne daria un 404 justo en la pantalla que
        // existe para que lo guarde. Se va sola en cuanto la persona navega.
        $request->session()->keep(self::RECIEN_INSCRITO);

        return view('perfil.carne-qr', [
            'estudiante' => $estudiante,
            'propio' => true,
            'recienInscrito' => true,
            'descarga' => route('carne-recien-inscrito-imagen'),
        ]);
    }

    public function imagenTrasInscribirse(Request $request): Response
    {
        $id = $request->session()->get(self::RECIEN_INSCRITO);
        $estudiante = $id === null ? null : Perfil::find($id);

        if ($estudiante === null) {
            abort(404);
        }

        $request->session()->keep(self::RECIEN_INSCRITO);

        return $this->entregar($estudiante);
    }

    private function puedeVerElDeOtro(Request $request, Perfil $usuario): bool
    {
        $solicitante = $request->user()?->perfil;

        return $solicitante !== null
            && $solicitante->rol === 'administrador'
            && $usuario->rol === 'estudiante';
    }

    // -----------------------------------------------------------------------
    // El QR de una institucion externa (23/09/2026)
    // -----------------------------------------------------------------------
    //
    // OTRO CARTON, NO OTRO CARNE, y conviene no confundirlos aunque compartan
    // el trazado y la columna: el de un estudiante dice QUIEN se presenta a una
    // lista, y este dice DONDE se dio una clase. De ahi que sus puertas no sean
    // las mismas.
    //
    // DOS PUERTAS, decididas con el usuario el 23/09/2026:
    //
    // 1. ADMINISTRACION, que es quien lo imprime y lo entrega la primera vez.
    // 2. LA PROPIA INSTITUCION desde su cuenta, igual que cualquiera saca su
    //    «Mi carné», para cuando se pierde el papel.
    //
    // Y EL PROFESOR NO, ni siquiera el responsable del programa. Es la misma
    // regla que ya rige el carne de un estudiante y aqui pesa todavia mas: este
    // carton es la llave con la que se verifica SU PROPIO TRABAJO. Quien puede
    // imprimirlo se verifica solo, y entonces la verificacion no verifica nada.
    // El director tampoco, por lo mismo: puede ser el responsable.

    /** El QR de una institucion, para administracion. */
    public function deInstitucion(Request $request, InstitucionExterna $institucion): View
    {
        $this->exigirAdministrador($request);

        return view('externa.qr', [
            'institucion' => $institucion,
            'propio' => false,
        ]);
    }

    public function imagenDeInstitucion(Request $request, InstitucionExterna $institucion): Response
    {
        $this->exigirAdministrador($request);

        return $this->entregarDeInstitucion($institucion);
    }

    /**
     * Cambia el codigo: el carton anterior deja de servir en el acto.
     *
     * Existe por lo mismo que el del estudiante —un carton se pierde y se
     * fotografia— y aqui ademas es EL CONTRAPESO del camino del QR: si un
     * profesor se quedo con una foto del codigo, esto es lo que la inutiliza.
     * Por eso lo puede hacer tambien la institucion desde su cuenta, sin tener
     * que pedirle nada a nadie.
     */
    public function renovarDeInstitucion(Request $request, InstitucionExterna $institucion): RedirectResponse
    {
        $this->exigirAdministrador($request);

        $institucion->perfil->renovarCodigoQr();

        return redirect()->route('institucion-externa-qr', $institucion)->with(
            'success',
            "El QR anterior de «{$institucion->nombre}» ya no sirve. Este es el nuevo: imprímelo y entrégalo."
        );
    }

    /** La imagen del QR propio, para la cuenta de la institucion. */
    public function imagenPropia(Request $request): Response
    {
        return $this->entregarDeInstitucion($this->laSuya($request));
    }

    public function renovarPropio(Request $request): RedirectResponse
    {
        $institucion = $this->laSuya($request);
        $institucion->perfil->renovarCodigoQr();

        return redirect()->route('externa-qr')->with(
            'success',
            'Listo: el QR anterior ya no sirve. Imprime este y ponlo donde se dan las clases.'
        );
    }

    /**
     * La institucion de quien mira, si es una cuenta de institucion externa.
     *
     * 404 y no un aviso: para cualquier otro rol estas rutas no existen. El
     * grupo de rutas ya lo comprueba; esto es la convencion de la casa para lo
     * que ENTREGA algo —una ruta se edita en un renglon y el descuido no se ve,
     * y aqui, al lado de lo que entrega, si.
     */
    private function laSuya(Request $request): InstitucionExterna
    {
        $perfil = $request->user()?->perfil;

        abort_if($perfil === null || $perfil->rol !== Perfil::INSTITUCION_EXTERNA, 404);

        $institucion = $perfil->institucionExterna;

        abort_if($institucion === null, 404);

        return $institucion;
    }

    private function exigirAdministrador(Request $request): void
    {
        abort_unless($request->user()?->perfil?->rol === 'administrador', 404);
    }

    /**
     * El PNG del carton de una institucion.
     *
     * Mismas cabeceras que el del estudiante y por lo mismo: `private,
     * no-store` para que no se quede en la cache de un aparato prestado ni en
     * la del CDN, que es compartida.
     */
    private function entregarDeInstitucion(InstitucionExterna $institucion): Response
    {
        $png = CarneQr::carneDeInstitucion($institucion->perfil, $institucion->nombre);

        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'attachment; filename="'
                .CarneQr::nombreDeArchivo($institucion->perfil, $institucion->nombre).'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * El PNG.
     *
     * Va como DESCARGA y no incrustado: el <img> de la pantalla se pinta con
     * una imagen de datos, asi que lo unico que pide esta ruta es alguien que
     * quiere el archivo. `Cache-Control: private, no-store` porque un carne no
     * tiene que quedarse en la cache de un aparato prestado ni en la del CDN,
     * que es compartida.
     */
    private function entregar(Perfil $estudiante): Response
    {
        return response(CarneQr::carne($estudiante), 200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'attachment; filename="'.CarneQr::nombreDeArchivo($estudiante).'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
