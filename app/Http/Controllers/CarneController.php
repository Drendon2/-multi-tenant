<?php

namespace App\Http\Controllers;

use App\Models\Grupo;
use App\Models\InstitucionExterna;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Support\CarneQr;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * El carne con codigo QR: verlo, descargarlo y renovarlo.
 *
 * TRES PUERTAS Y NINGUNA MAS (decision del usuario, 21/09/2026):
 *
 * 1. El propio estudiante, desde Mi perfil.
 * 2. El administrador, desde la ficha de esa persona — que es como llega el
 *    carne a quien no sabe entrar al sistema, que es justo el publico para el
 *    que se construyo esto. Desde el 25/09/2026 tambien en HOJA, nueve por
 *    carta, de un grupo o de una promotoria: es la misma puerta.
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
    // La hoja de carnes para imprimir (25/09/2026)
    // -----------------------------------------------------------------------
    //
    // NUEVE CARNES POR HOJA CARTA, de un grupo o de una promotoria entera.
    // Pedido por el usuario: el carne se construyo para quien no sabe entrar al
    // sistema, y a esa persona no le llega bajandolo uno a uno desde su ficha;
    // le llega impreso, repartido en clase.
    //
    // ES LA MISMA PUERTA 2 DE ARRIBA —administracion— y ninguna mas. El
    // profesor no entra ni a la hoja de su propio grupo: son los carnes de sus
    // cuarenta estudiantes de una vez, que es justo lo que la cabecera de esta
    // clase le niega de uno en uno.
    //
    // SOLO QUIEN ESTA INSCRITO EN EL PERIODO EN CURSO. Un grupo no pertenece a
    // un periodo —las matriculas si— y sin ese corte la hoja traeria a quien
    // curso el grupo hace dos semestres. Es el mismo corte que el informe de
    // estudiantes, con los mismos estados (la cancelacion en tramite sigue
    // yendo a clase, asi que sigue necesitando su carne).
    //
    // Sacar la hoja CREA el codigo de quien no tenia (`Perfil::codigoQr`), igual
    // que verlo en la ficha. No renueva el de nadie: imprimir dos veces la misma
    // hoja da los mismos carnes.

    /** Nueve por hoja: 3 x 3. */
    private const POR_FILA = 3;

    private const FILAS_POR_HOJA = 3;

    /** Carta es 612 x 792 pt. El margen deja sitio a la pinza de la impresora. */
    private const MARGEN_HOJA = 22;

    /** El aire entre la linea de corte y el carne, para que la tijera no lo muerda. */
    private const AIRE_CELDA = 6;

    /**
     * Lo que se le quita al alto de cada fila para que las tres quepan.
     *
     * MEDIDO POR BISECCION el 25/09/2026, no estimado: con las filas al alto
     * exacto de la hoja, los bordes y el redondeo de dompdf empujaban la
     * tercera fila medio punto y saltaba sola a otra pagina (9 carnes en dos
     * hojas). Con 0,5 pt sigue saltando; con 1 pt cabe. Se deja 4, cuatro veces
     * el umbral, que en papel es menos de milimetro y medio por fila. Lo vigila
     * `CarneImpresoTest::test_nueve_carnes_caben_en_una_hoja_carta`.
     */
    private const HOLGURA_FILA = 4;

    public function hojaDeGrupo(Request $request, Grupo $grupo): Response
    {
        $this->exigirAdministrador($request);

        $grupo->load('promotoria');

        return $this->hoja(
            $this->inscritosEnCurso()
                ->whereHas('grupos', fn ($g) => $g->where('grupos.id', $grupo->id)),
            "Carnés — {$grupo->promotoria->nombre} — {$grupo->nombre}",
            "carnes-{$grupo->promotoria->nombre}-{$grupo->nombre}"
        );
    }

    /**
     * Todos los de una promotoria, ORDENADOS POR GRUPO: la hoja se reparte
     * salon por salon, y un orden alfabetico corrido obligaria a separar los
     * carnes de cada horario a mano. Quien esta en dos grupos de la misma
     * promotoria sale UNA vez, en el primero: es UNA matricula repartida por la
     * tabla puente, y por eso se filtra con `whereHas` y no con un join, que la
     * duplicaria. Quien aun no tiene grupo sale al final.
     */
    public function hojaDePromotoria(Request $request, Promotoria $promotoria): Response
    {
        $this->exigirAdministrador($request);

        return $this->hoja(
            $this->inscritosEnCurso()->where('matriculas.promotoria_id', $promotoria->id),
            "Carnés — {$promotoria->nombre}",
            "carnes-{$promotoria->nombre}"
        );
    }

    /** @return Builder<Matricula> */
    private function inscritosEnCurso(): Builder
    {
        $periodo = Periodo::enCurso();

        return Matricula::query()
            ->whereIn('matriculas.estado', Matricula::ESTADOS_INSCRITO)
            // Sin periodo en curso no hay a quien repartir carnes: una hoja
            // vacia y no el historico entero.
            ->when($periodo === null, fn ($q) => $q->whereRaw('1 = 0'))
            ->when($periodo, fn ($q) => $q->where('matriculas.periodo_id', $periodo->id));
    }

    /** @param  Builder<Matricula>  $consulta */
    private function hoja(Builder $consulta, string $titulo, string $archivo): Response
    {
        $matriculas = $consulta
            ->with(['estudiante', 'grupos'])
            ->get()
            ->sortBy(fn (Matricula $m) => [
                // Sin grupo, al final: «~» va detras de cualquier letra.
                $m->grupos->sortBy('nombre')->first()->nombre ?? '~',
                Str::lower($m->estudiante->nombre_completo),
            ])
            ->values();

        $anchoCelda = (612 - 2 * self::MARGEN_HOJA) / self::POR_FILA;
        $altoCelda = (792 - 2 * self::MARGEN_HOJA) / self::FILAS_POR_HOJA - self::HOLGURA_FILA;

        $carnes = $matriculas->map(function (Matricula $m) use ($anchoCelda, $altoCelda) {
            $png = CarneQr::carne($m->estudiante);
            [$ancho, $alto] = getimagesizefromstring($png) ?: [1, 1];

            // Encajado SIN deformar: el carne crece con los renglones del
            // nombre, asi que unos llegan al alto de la celda y otros al ancho.
            // Un QR estirado en un eje deja de leerse.
            $escala = min(
                ($anchoCelda - 2 * self::AIRE_CELDA) / $ancho,
                ($altoCelda - 2 * self::AIRE_CELDA) / $alto
            );

            return [
                // JPEG y no PNG: ver `CarneQr::comoJpeg`, es lo que hace que
                // una promotoria grande quepa en el tiempo del CDN.
                'jpeg' => base64_encode(CarneQr::comoJpeg($png)),
                'ancho' => round($ancho * $escala, 2),
                'alto' => round($alto * $escala, 2),
            ];
        })->all();

        // Se rellena la ultima hoja con celdas vacias: sin ellas la ultima fila
        // tendria menos columnas y dompdf ensancharia las que quedan, con lo
        // que las lineas de corte de esa hoja no casarian con las de las demas.
        $porHoja = self::POR_FILA * self::FILAS_POR_HOJA;
        $hojas = [];

        foreach (array_chunk($carnes, $porHoja) as $deUnaHoja) {
            $deUnaHoja = array_pad($deUnaHoja, (int) (ceil(count($deUnaHoja) / self::POR_FILA) * self::POR_FILA), null);
            $hojas[] = array_chunk($deUnaHoja, self::POR_FILA);
        }

        $pdf = Pdf::loadView('carnes.hoja', [
            'titulo' => $titulo,
            'hojas' => $hojas === [] ? [[array_fill(0, self::POR_FILA, null)]] : $hojas,
            'margen' => self::MARGEN_HOJA,
            'anchoCelda' => round($anchoCelda, 2),
            'altoCelda' => round($altoCelda, 2),
        ])->setPaper('letter');

        return $pdf->download(Str::slug($archivo).'.pdf');
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
