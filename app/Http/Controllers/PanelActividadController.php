<?php

namespace App\Http\Controllers;

use App\Models\Actividad;
use App\Models\AsistenciaActividad;
use App\Models\InscritoActividad;
use App\Models\Perfil;
use App\Models\SesionActividad;
use App\Support\AsistenciaDeActividad;
use App\Support\CarneQr;
use App\Support\PaseDeLista;
use App\Support\Permisos;
use App\Support\Reglas;
use App\Support\VerificacionExterna;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * El lado de quien DA la actividad, no el de quien la administra.
 *
 * Gestion crea el curso, le pone fechas y comparte el enlace. Aqui se dirige lo
 * que ya existe: se ve quien se apunto y se oprime "Iniciar" cuando la clase
 * empieza de verdad.
 *
 * Esa division es la misma que ya hay entre Gestion y el Panel del lado de las
 * promotorias, y por el mismo motivo: crear el catalogo y dar la clase son
 * trabajos de dos personas distintas, aunque a veces sean la misma.
 */
class PanelActividadController extends Controller
{
    /**
     * Las que puede ver quien mira.
     *
     * EL ADMINISTRADOR TODAS; cualquier otro —incluido el DIRECTOR desde el
     * 12/09/2026— solo las que dirige. Una actividad no cuelga de un
     * departamento: lo que tiene es una PERSONA responsable, asi que el recorte
     * del director aqui no es por area. Es la misma regla que
     * `Permisos::puedeVerActividad()`, y si las dos se separan esta es la que
     * deja la puerta abierta, porque es la que alimenta las URL.
     */
    private function visiblesPara(Perfil $perfil): Builder
    {
        return Actividad::query()
            ->when(
                $perfil->rol !== 'administrador',
                fn (Builder $q) => $q->where('responsable_id', $perfil->id)
            );
    }

    public function index(Request $request): View
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');

        return view('panel.actividades', [
            'actividades' => $this->visiblesPara($perfil)
                ->with(['responsable'])
                ->withCount(['inscritos', 'sesiones'])
                ->orderBy('nombre')
                ->get(),
        ]);
    }

    /**
     * Cuantos inscritos por pagina en la ficha.
     *
     * La ficha es de consulta: se abre para ver como va la cosa, no para
     * recorrer a nadie de arriba abajo. Un grupo de proyeccion institucional
     * puede tener cientos y no hay cupo que lo ate. Pasar lista SI trae la
     * lista entera, y eso no es una excepcion olvidada: ahi hay que poder
     * marcar a todos de una vez, y una hoja partida en paginas se guardaria a
     * medias sin que nadie lo note.
     */
    public const INSCRITOS_POR_PAGINA = 50;

    /**
     * La edad de quien esta en la lista de un programa externo. La piden los
     * DOS caminos que anaden gente —armar la lista y «llego alguien» en plena
     * clase— y escrita en cada uno se separarian sin que nada fallara. El techo
     * es el del CHECK de la base, repetido para que el rechazo sea un mensaje
     * de campo y no un error del motor.
     */
    private const REGLA_EDAD = ['required', 'integer', 'min:1', 'max:119'];

    public function ver(Request $request, Actividad $actividad): View
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');

        // Esconder una actividad de la lista no cierra su URL.
        abort_unless(Permisos::puedeVerActividad($perfil, $actividad), 404);

        // La institucion de un programa externo, para la cabecera y para el
        // lector del QR. `null` en los otros tres tipos, que es lo que la
        // plantilla pregunta antes de pintar nada de esto.
        $actividad->loadMissing('institucion');

        return view('panel.actividad', [
            'actividad' => $actividad,
            // LA CLASE QUE EL QR PUEDE VERIFICAR, o null. Es UNA como mucho, y
            // eso no es una simplificacion: el QR solo vale el mismo dia y la
            // base admite una sesion por dia y actividad, asi que «la clase que
            // acabo de dar» es siempre una sola. De ahi que el lector sea uno
            // en la pantalla y no un boton por fila — un lector por fila seria
            // ofrecer veinte camaras para una sola respuesta posible.
            //
            // `null` tiene DOS causas que la pantalla distingue: que no haya
            // clase hoy, y que la de hoy ya este verificada.
            'sesionParaQr' => $actividad->esExterno() ? $this->sesionDeHoy($actividad) : null,
            // SI LA DE HOY YA EMPEZO, para que el botón no mienta. Solo importa
            // donde la sesion nace al oprimir —proyeccion y programa externo—;
            // un curso tiene sus fechas y su boton por fila.
            //
            // Sin esto, el boton seguia diciendo «Iniciar» en verde macizo
            // sobre una clase ya iniciada: el control mas visible de la
            // pantalla anunciando una accion agotada.
            'yaEmpezoHoy' => $actividad->llevaFechas()
                ? false
                : $actividad->sesiones()
                    ->whereDate('fecha', Carbon::today())
                    ->whereNotNull('iniciada_en')
                    ->exists(),
            // A quien ya se le marco algo, para que la lista no ofrezca
            // «Quitar» sobre alguien cuyas marcas se irian con el. UNA consulta
            // y no una por fila; el boton y la comprobacion del controlador
            // preguntan lo MISMO (ver `AsistenciaActividad::conMarcasEn`).
            'conMarcas' => $actividad->esExterno()
                ? AsistenciaActividad::conMarcasEn($actividad->id)
                : [],
            // `withCount` y no recorrer la relacion: la tabla pinta una fila
            // por sesion, y preguntar la asistencia dentro del bucle costaria
            // una consulta por fila.
            // `verificadaPor` viaja con las demas y no se pregunta en la
            // plantilla: en un programa externo la tabla pinta quien firmo cada
            // clase, y eso dentro del bucle es una consulta por sesion. En los
            // otros tres tipos la relacion viene vacia y no cuesta nada.
            'sesiones' => $actividad->sesiones()
                ->with(['iniciadaPor', 'verificadaPor'])
                ->withCount('asistencias')
                ->get(),
            // `with('perfil')` y no dejarlo a la plantilla: la tabla marca
            // «Estudiante de la institucion» fila a fila, y preguntarlo dentro
            // del bucle era una consulta por inscrito.
            //
            // Desempate por id: dos personas con el mismo nombre --que aqui es
            // corriente, porque se apuntan por un enlace y nadie normaliza--
            // quedarian en un orden que el motor no esta obligado a repetir, y
            // al paginar eso reparte filas repetidas o perdidas.
            'inscritos' => $actividad->inscritos()
                ->with('perfil')
                ->orderBy('nombre_completo')
                ->orderBy('id')
                ->paginate(self::INSCRITOS_POR_PAGINA),
            // El total va aparte: `$inscritos->count()` sobre un paginador son
            // los de la pagina, y de esta cifra cuelga si el enlace admite mas
            // gente. Con la de la pagina, una actividad de 200 inscritos se
            // leeria como que tiene 50 y el enlace seguiria abierto.
            'apuntados' => $actividad->inscritos()->count(),
            // Quien mira puede no ser quien dirige: direccion ve esta pantalla
            // en solo lectura. La plantilla necesita saberlo para no pintar un
            // boton que al pulsarlo rebota.
            'dirige' => Permisos::dirigeLaActividad($perfil, $actividad),
            // Cuanto asistio cada uno, para el certificado. En DOS consultas
            // fijas y no una por fila: esta tabla pagina de cincuenta.
            //
            // Se calcula tambien para un grupo de proyeccion, donde no hay
            // certificado: la cifra informa igual —«asistio a 12 de 15»— y
            // quien decide si hay papel es la plantilla mirando el tipo. Al
            // reves habria que acordarse de dos cosas en dos sitios.
            'asistencias' => AsistenciaDeActividad::deActividad($actividad),
            'minimoCertificado' => (int) (AsistenciaDeActividad::MINIMO * 100),
        ]);
    }

    /**
     * Oprime "Iniciar" en una sesion que ya existe: la de un curso o un taller.
     *
     * Lo que queda guardado es la hora REAL en que se oprimio, no la fecha
     * prevista. Son dos datos distintos y por eso hay dos columnas: la fecha
     * dice cuando tocaba y `iniciada_en`, cuando paso.
     */
    public function iniciar(Request $request, SesionActividad $sesion): RedirectResponse
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');
        $actividad = $sesion->actividad;

        abort_unless(Permisos::puedeVerActividad($perfil, $actividad), 404);

        if (! Permisos::dirigeLaActividad($perfil, $actividad)) {
            return $this->volver($actividad, 'Solo quien dirige la actividad puede iniciar sus sesiones.');
        }

        // Ya iniciada: no se dice nada y no se toca la hora. Volver a oprimir
        // por si acaso es lo que hace cualquiera, y reescribir la hora borraria
        // la de verdad.
        if ($sesion->yaEmpezo()) {
            return $this->volver($actividad, '');
        }

        $sesion->iniciada_en = now();
        $sesion->iniciada_por_id = $perfil->id;
        $sesion->save();

        return $this->volver(
            $actividad,
            "Empezó {$actividad->etiquetaSesionConArticulo()}. Ya puedes pasar lista.",
            exito: true
        );
    }

    /**
     * El boton de un grupo de proyeccion, que no tiene fechas puestas.
     *
     * Aqui la sesion NACE al oprimir, como `Clase` del lado de las promotorias.
     * Se busca la de hoy antes de crearla: dos toques seguidos —o dos personas
     * mirando la misma pantalla— tienen que dar un ensayo, no dos. Ademas el
     * unico de la base solo admite uno por dia, asi que crear a ciegas fallaria
     * con un error del motor.
     */
    public function iniciarHoy(Request $request, Actividad $actividad): RedirectResponse
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');

        abort_unless(Permisos::puedeVerActividad($perfil, $actividad), 404);

        if (! Permisos::dirigeLaActividad($perfil, $actividad)) {
            return $this->volver($actividad, 'Solo quien dirige la actividad puede iniciar sus sesiones.');
        }

        $hoy = Carbon::today()->toDateString();
        $sesion = $actividad->sesiones()->firstOrCreate(['fecha' => $hoy]);

        // YA EMPEZADA: no se toca la hora —reescribirla borraria la de verdad—
        // pero SI se lleva a la hoja, que es lo unico que quedaba por hacer.
        // Antes esto devolvia a la ficha EN SILENCIO, asi que el boton mas
        // visible de la pantalla era una accion agotada que no hacia nada ni lo
        // decia: el fallo que este proyecto ya pago con el ojo de la
        // contrasena, aqui en el sitio de mas trafico del Panel.
        if ($sesion->yaEmpezo()) {
            return redirect()->route('panel-actividad-lista', $sesion);
        }

        $sesion->iniciada_en = now();
        $sesion->iniciada_por_id = $perfil->id;
        $sesion->save();

        // SE ATERRIZA EN LA HOJA DE ASISTENCIA, no en la ficha (23/09/2026,
        // decision del usuario despues de probarlo en pantalla).
        //
        // El aviso decia «ya puedes pasar lista» y dejaba a la persona donde
        // estaba, con «Pasar lista» en un boton blanco pequeno dentro de la
        // tabla y DOS botones verdes al lado que no eran ese. El gesto real es
        // uno solo —llego, inicio, marco—, se hace de pie en un salon ajeno y
        // desde un celular, y eran tres pantallas y un boton que habia que
        // acertar entre dos senuelos.
        //
        // Vale igual para los grupos de proyeccion, que comparten este boton y
        // tenian el mismo problema. NO alcanza al «Iniciar» de una fila de
        // curso o taller (`iniciar()`): alli se inicia una clase CONCRETA de
        // una rejilla de fechas, que es otro gesto y no se pidio tocar.
        return redirect()
            ->route('panel-actividad-lista', $sesion)
            ->with('success', "Empezó {$actividad->etiquetaSesionConArticulo()} de hoy. Marca a quien esté.");
    }

    // -----------------------------------------------------------------------
    // Pasar lista
    // -----------------------------------------------------------------------

    public function lista(Request $request, SesionActividad $sesion): View|RedirectResponse
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');
        $actividad = $sesion->actividad;

        abort_unless(Permisos::puedeVerActividad($perfil, $actividad), 404);

        // No hay lista que pasar de algo que no ha empezado. El boton de la
        // pantalla anterior ya lo tiene en cuenta; esto cierra la URL.
        if (! $sesion->yaEmpezo()) {
            return $this->volver(
                $actividad,
                'Esa sesión todavía no ha empezado. Inicia primero y luego pasa lista.'
            );
        }

        $marcado = $sesion->asistencias()->pluck('estado', 'inscrito_id')->all();

        return view('panel.actividad-lista', [
            'actividad' => $actividad,
            'sesion' => $sesion,
            'estados' => AsistenciaActividad::ESTADOS,
            'inscritos' => $actividad->inscritos()
                ->orderBy('nombre_completo')
                ->get()
                ->map(fn (InscritoActividad $i) => [
                    'inscrito' => $i,
                    'estado' => $marcado[$i->id] ?? null,
                ]),
            'dirige' => Permisos::dirigeLaActividad($perfil, $actividad),
        ]);
    }

    public function guardarLista(Request $request, SesionActividad $sesion): RedirectResponse
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');
        $actividad = $sesion->actividad;

        abort_unless(Permisos::puedeVerActividad($perfil, $actividad), 404);

        // Se comprueba aqui y no solo escondiendo los controles: la peticion
        // llega igual si alguien la envia a mano.
        if (! Permisos::dirigeLaActividad($perfil, $actividad)) {
            return $this->volverALista($sesion, 'Solo quien dirige la actividad puede pasar lista.');
        }

        if (! $sesion->yaEmpezo()) {
            return $this->volver($actividad, 'Esa sesión todavía no ha empezado.');
        }

        $inscritos = $actividad->inscritos()->pluck('id');

        $marcados = PaseDeLista::guardar(
            request: $request,
            asistencias: AsistenciaActividad::class,
            sesion: ['sesion_id' => $sesion->id],
            quien: 'inscrito_id',
            ids: $inscritos,
        );

        $sinMarcar = $inscritos->count() - $marcados;

        return $this->volverALista(
            $sesion,
            $sinMarcar
                ? "Lista guardada. Quedaron {$sinMarcar} sin marcar: puedes volver y completarlos."
                : 'Lista guardada.',
            exito: true
        );
    }

    /**
     * Anade a quien llego sin haberse inscrito por el enlace.
     *
     * Solo el nombre: nadie le va a pedir el documento con la clase empezando,
     * y exigirselo seria dejarlo fuera de la lista por un tramite. Queda como
     * inscrito de la actividad —no solo de esta sesion— porque eso es lo que
     * ha pasado: se sumo al curso.
     *
     * NO respeta el cupo, a proposito. El cupo gobierna el ENLACE, que es el
     * que hay que cerrar cuando ya no caben mas; a quien esta de pie en el
     * salon no lo echa un numero.
     *
     * EN UN PROGRAMA EXTERNO PIDE TAMBIEN LA EDAD, y obligatoria (decision del
     * usuario, 25/09/2026). Alli la edad es el UNICO dato aparte del nombre, y
     * este camino la dejaba vacia: quien llegaba en la clase 3 entraba con un
     * «—» y el motivo de preguntarla se caia en cuanto faltara en media lista.
     * Es la misma regla que `anadirALista()`; el origen sigue siendo
     * `en_sesion`, porque dice COMO entro y no que datos trae.
     */
    public function anadirEnSesion(Request $request, SesionActividad $sesion): RedirectResponse
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');
        $actividad = $sesion->actividad;

        abort_unless(Permisos::puedeVerActividad($perfil, $actividad), 404);

        if (! Permisos::dirigeLaActividad($perfil, $actividad)) {
            return $this->volverALista($sesion, 'Solo quien dirige la actividad puede pasar lista.');
        }

        $reglas = ['nombre_completo' => Reglas::nombreDePersona(90)];

        if ($actividad->esExterno()) {
            $reglas['edad'] = self::REGLA_EDAD;
        }

        $datos = $request->validate($reglas, Reglas::mensajes(), ['nombre_completo' => 'nombre']);

        // Un nombre que ya esta en la lista casi siempre es la misma persona
        // apuntada dos veces: el boton se pulsa con la clase empezando y no hay
        // un documento con el que distinguirlas. NO se bloquea —dos hermanos
        // pueden llamarse casi igual, y quien esta delante sabe mejor que el
        // sistema quien hay en el salon—, pero se dice, que es lo que evita la
        // fila duplicada sin quitarle la decision a nadie.
        $repetido = $actividad->inscritos()
            ->where('nombre_completo', $datos['nombre_completo'])
            ->exists();

        if ($repetido) {
            return $this->volverALista(
                $sesion,
                "Ya hay alguien con el nombre «{$datos['nombre_completo']}» en la lista. "
                .'Si son dos personas distintas, escribe el nombre completo de cada una.'
            );
        }

        DB::transaction(function () use ($actividad, $sesion, $datos) {
            $inscrito = $actividad->inscritos()->create([
                'nombre_completo' => $datos['nombre_completo'],
                'edad' => $datos['edad'] ?? null,
                'origen' => InscritoActividad::EN_SESION,
            ]);

            // Se marca como asistio de una vez: se le anade PORQUE esta aqui, y
            // dejarlo sin marcar obligaria a buscarlo en la lista para decir lo
            // que ya se sabe.
            AsistenciaActividad::create([
                'sesion_id' => $sesion->id,
                'inscrito_id' => $inscrito->id,
                'estado' => AsistenciaActividad::ASISTIO,
            ]);
        });

        return $this->volverALista(
            $sesion,
            "{$datos['nombre_completo']} queda en la lista y marcado como asistió.",
            exito: true
        );
    }

    /**
     * La clase de HOY de un programa externo, si la hay y esta sin verificar.
     *
     * Es la unica que el QR puede firmar. Se busca por `iniciada_en` y no por
     * `fecha`, que es el mismo criterio que usa `VerificacionExterna` para
     * decidir el plazo: si las dos preguntas no se hicieran igual, la pantalla
     * pintaria el lector para una clase que el servidor va a rechazar —o lo
     * esconderia para una que si aceptaria—, y las dos cosas se leen como que
     * el sistema falla.
     */
    private function sesionDeHoy(Actividad $actividad): ?SesionActividad
    {
        return $actividad->sesiones()
            ->whereNotNull('iniciada_en')
            ->whereNull('verificada_en')
            ->whereDate('iniciada_en', Carbon::today())
            ->first();
    }

    // -----------------------------------------------------------------------
    // La lista de un programa externo
    // -----------------------------------------------------------------------

    /**
     * Anade a alguien a la lista de un programa externo: nombre y edad.
     *
     * ES LA UNICA FORMA DE POBLAR UN PROGRAMA EXTERNO. Los otros tres tipos se
     * llenan solos por su enlace; aqui no hay enlace, porque quienes estan en
     * ese salon son estudiantes de la OTRA institucion y no tienen por que
     * conocer este sistema. Los escribe el profesor, de pie y en el sitio.
     *
     * SOLO DOS CAMPOS, y es una decision de producto y no una simplificacion
     * por pereza: pedir documento o fecha de nacimiento a un nino de una
     * escuela rural, uno por uno y con la clase empezando, es como una lista se
     * queda a medias. La EDAD se pregunta y se contesta; la fecha exacta, no.
     *
     * Se anade a la ACTIVIDAD y no a una sesion —esta persona esta en el
     * programa, no en una clase suelta— y por eso esto vive aqui y no en
     * `anadirEnSesion()`, que significa otra cosa: «no estaba y aparecio hoy».
     */
    public function anadirALista(Request $request, Actividad $actividad): RedirectResponse
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');

        abort_unless(Permisos::puedeVerActividad($perfil, $actividad), 404);
        // 404 y no un aviso: en un curso o un taller esta ruta no significa
        // nada, y contestarle «aqui no» seria admitir que existe.
        abort_unless($actividad->esExterno(), 404);

        if (! Permisos::dirigeLaActividad($perfil, $actividad)) {
            return $this->volver($actividad, 'Solo quien dicta el programa puede armar su lista.');
        }

        $datos = $request->validate([
            'nombre_completo' => Reglas::nombreDePersona(90),
            'edad' => self::REGLA_EDAD,
        ], Reglas::mensajes(), ['nombre_completo' => 'nombre']);

        // Mismo criterio que al anadir en una sesion: se AVISA y no se bloquea.
        // Dos hermanos pueden llamarse casi igual, y quien esta delante sabe
        // mejor que el sistema quien hay en el salon.
        $repetido = $actividad->inscritos()
            ->where('nombre_completo', $datos['nombre_completo'])
            ->exists();

        if ($repetido) {
            return $this->volver(
                $actividad,
                "Ya hay alguien con el nombre «{$datos['nombre_completo']}» en la lista. "
                .'Si son dos personas distintas, escribe el nombre completo de cada una.'
            );
        }

        $actividad->inscritos()->create([
            'nombre_completo' => $datos['nombre_completo'],
            'edad' => $datos['edad'],
            'origen' => InscritoActividad::LISTA,
        ]);

        return $this->volver(
            $actividad,
            "{$datos['nombre_completo']} queda en la lista.",
            exito: true
        );
    }

    /**
     * Quita a alguien de la lista, SOLO si todavia no tiene ninguna marca.
     *
     * El corte no es un permiso, es lo que separa «me equivoque al escribir el
     * nombre» de «borrar asistencia». Una vez que esa persona tiene marcas, la
     * fila ya no es un apunte: es el registro de que estuvo —o no— en unas
     * clases concretas, y con ella se irian esas marcas sin que nada avisara,
     * porque la clave foranea es CASCADE.
     *
     * Quien tenga marcas y no debiera estar se deja en la lista: una fila de
     * mas no le quita nada a nadie, y la asistencia de los demas se cuenta por
     * sesion y no por el tamano de la lista.
     */
    public function quitarDeLista(Request $request, InscritoActividad $inscrito): RedirectResponse
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');
        $actividad = $inscrito->actividad;

        abort_unless(Permisos::puedeVerActividad($perfil, $actividad), 404);
        abort_unless($actividad->esExterno(), 404);

        if (! Permisos::dirigeLaActividad($perfil, $actividad)) {
            return $this->volver($actividad, 'Solo quien dicta el programa puede armar su lista.');
        }

        if (AsistenciaActividad::tieneMarcas($inscrito->id)) {
            return $this->volver(
                $actividad,
                "A {$inscrito->nombre_completo} ya se le pasó lista alguna vez, así que quitarlo "
                .'borraría esas marcas. Se queda en la lista.'
            );
        }

        $nombre = $inscrito->nombre_completo;
        $inscrito->delete();

        return $this->volver($actividad, "{$nombre} sale de la lista.", exito: true);
    }

    // -----------------------------------------------------------------------
    // Verificar con el QR de la institucion
    // -----------------------------------------------------------------------

    /**
     * El profesor lee el carton de la institucion al terminar la clase alli.
     *
     * EL SEGUNDO CAMINO de la verificacion, y el que hay que leer con la
     * objecion delante: quien escanea es el profesor, que es justo a quien esta
     * verificacion vigila. Se acepto el 23/09/2026 con esa objecion dicha,
     * porque el otro platillo era dejar la verificacion en manos de que un
     * funcionario de otra entidad entre a un sistema que no es suyo — y con el
     * plazo del mismo dia, que es lo que hace que la foto del carton no sirva
     * para las quince clases siguientes. Es el mismo trato que ya se hizo con
     * el carne del estudiante.
     *
     * LA REGLA DEL PLAZO NO ESTA AQUI: vive en `VerificacionExterna`, que es la
     * unica puerta de escritura de una firma. Aqui solo se resuelve QUIEN es el
     * codigo y se traduce el motivo a una frase.
     *
     * EL CODIGO SE RESUELVE CONTRA LA BASE Y NO CONTRA LA PANTALLA. Es la
     * leccion del 21/09/2026 en produccion: un mapa pintado al abrir la pagina
     * envejece —el codigo de la institucion nace al imprimir su QR por primera
     * vez— y darlo por autoridad hace que la pantalla acuse a quien tiene el
     * carton bueno en la mano.
     */
    public function verificarConQr(Request $request, SesionActividad $sesion): RedirectResponse
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');
        $actividad = $sesion->actividad;

        abort_unless(Permisos::puedeVerActividad($perfil, $actividad), 404);
        abort_unless($actividad->esExterno(), 404);

        if (! Permisos::dirigeLaActividad($perfil, $actividad)) {
            return $this->volver($actividad, 'Solo quien dicta el programa puede verificar sus clases.');
        }

        $codigo = CarneQr::codigoLeido((string) $request->input('codigo', ''));

        if ($codigo === null) {
            return $this->volver($actividad, 'Ese código no es un QR de este sistema.');
        }

        $institucion = Perfil::porCodigoQrDeInstitucion($codigo);

        if ($institucion === null) {
            // Un QR de verdad que ya no vale, casi siempre uno renovado. Se
            // distingue a proposito del de abajo —«es de otra institucion»—,
            // que se resuelve de otra manera.
            return $this->volver($actividad, 'Ese QR ya no sirve. Pídele a la institución el vigente.');
        }

        if (! Permisos::verificaLaActividad($institucion, $actividad)) {
            $suya = $institucion->institucionExterna?->nombre;

            return $this->volver(
                $actividad,
                $suya === null
                    ? 'Ese QR no es de la institución de este programa.'
                    : "Ese QR es de «{$suya}», que no es donde se dicta este programa."
            );
        }

        $motivo = VerificacionExterna::registrar($sesion, $institucion, VerificacionExterna::QR);

        // CADA RECHAZO DICE QUE HACER, que es la leccion del carne: un aviso
        // que solo habla en el camino feliz es un adorno. El del plazo es el
        // que mas importa, porque es el unico que no esta escrito en la
        // pantalla y no se puede deducir mirando la fila.
        return match ($motivo) {
            'sin_iniciar' => $this->volver($actividad, 'Inicia la clase antes de verificarla.'),
            'fuera_de_plazo' => $this->volver(
                $actividad,
                'El QR solo verifica la clase el mismo día en que se dio. Esta es de otro día: '
                .'la institución puede verificarla desde su cuenta, que no tiene plazo.'
            ),
            default => $this->volver(
                $actividad,
                $sesion->verificacion_origen === VerificacionExterna::QR
                    ? 'Clase verificada por la institución.'
                    : 'Esa clase ya estaba verificada por la institución.',
                exito: true
            ),
        };
    }

    private function volverALista(SesionActividad $sesion, string $mensaje, bool $exito = false): RedirectResponse
    {
        return redirect()
            ->route('panel-actividad-lista', $sesion)
            ->with($exito ? 'success' : 'error', $mensaje);
    }

    private function volver(Actividad $actividad, string $mensaje, bool $exito = false): RedirectResponse
    {
        $destino = redirect()->route('panel-actividad', $actividad);

        return $mensaje === ''
            ? $destino
            : $destino->with($exito ? 'success' : 'error', $mensaje);
    }
}
