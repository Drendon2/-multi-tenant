<?php

namespace App\Http\Controllers;

use App\Models\Actividad;
use App\Models\AsistenciaActividad;
use App\Models\InstitucionExterna;
use App\Models\Perfil;
use App\Models\SesionActividad;
use App\Support\Permisos;
use App\Support\VerificacionExterna;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * TODO lo que ve una institucion externa cuando entra, que es muy poco.
 *
 * Una pantalla: las clases que se han dictado en su sede, para dar fe de que el
 * profesor fue. No hay Panel, no hay Gestion, no hay fichas de nadie y no hay
 * buscador. Esta cuenta es de OTRA entidad y lo unico que se le pide aqui es
 * una firma.
 *
 * LO QUE VE DE CADA CLASE, decidido con el usuario el 23/09/2026: la fecha, la
 * hora REAL en que el profesor la inicio, su nombre, y CUANTOS asistieron. No
 * los nombres. La cifra no es un adorno: es lo que le permite notar que dice
 * «asistieron 2» un dia que el salon estaba lleno, que es justamente la clase
 * de cosa por la que esta verificacion existe. Los nombres se quedan fuera
 * porque sacar una lista nominal de menores hacia una cuenta ajena al sistema
 * es un precio que esta firma no necesita pagar.
 *
 * NO HAY PLAZO PARA FIRMAR, al contrario que la confirmacion del estudiante.
 * Un funcionario de otra entidad entra cuando puede —quiza una vez por semana—
 * y este sistema NO LE AVISA A NADIE DE NADA: un plazo corto dejaria media
 * lista sin verificar para siempre y sin que nadie se enterara. El plazo que SI
 * existe es el del QR, y vive en `VerificacionExterna` porque es del otro
 * camino.
 *
 * SE PUEDE RETIRAR UNA FIRMA, y aqui pesa mas que en «Mis clases»: el otro
 * camino —el QR— lo recorre el profesor, que es a quien esto vigila. Retirar es
 * lo unico que tiene la institucion para desconocer una firma que ella no dio.
 */
class ExternaController extends Controller
{
    /**
     * La institucion de quien mira.
     *
     * Falla con 403 y no con 404 si no la tiene: una cuenta con este rol y sin
     * ficha no deberia existir —solo se crean juntas— y si aparece una es que
     * alguien abrio un segundo camino para crearlas. Un 404 lo dejaria
     * pareciendo una pantalla que todavia no tiene datos.
     */
    private function suInstitucion(Request $request): InstitucionExterna
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');

        $institucion = $perfil->institucionExterna;

        abort_if(
            $institucion === null,
            403,
            'Esta cuenta no está asociada a ninguna institución. Avísale a la casa de la cultura.'
        );

        return $institucion;
    }

    /**
     * Las clases de sus programas, las que faltan por firmar primero.
     *
     * DOS CONSULTAS FIJAS y no una por fila: el conteo de asistentes va por
     * `withCount` y el profesor por `with`. Esta lista crece con los meses —un
     * programa de un semestre son veinte clases— y preguntarlo dentro del bucle
     * la volveria lenta justo cuando ya tiene historial.
     *
     * LO NO FIRMADO ARRIBA, y dentro de cada mitad lo mas reciente primero: lo
     * que esta persona viene a hacer es firmar lo que falta, y una lista
     * ordenada solo por fecha se lo entierra bajo lo que ya hizo.
     *
     * Solo las INICIADAS. Una sesion sin iniciar no es una clase que se dio: es
     * una fila que nadie ha estrenado, y ofrecerla para firmar seria pedirle a
     * alguien que de fe de algo que no ha pasado.
     */
    public function index(Request $request): View
    {
        $institucion = $this->suInstitucion($request);

        $sesiones = SesionActividad::query()
            ->whereIn('actividad_id', $institucion->programas()->select('id'))
            ->whereNotNull('iniciada_en')
            ->with(['actividad', 'iniciadaPor'])
            // Solo los que ASISTIERON: «cuantos hubo» es la cifra que sirve
            // para reconocer la clase, y contar las tres marcas la inflaria con
            // los que faltaron. La constante y no la cadena suelta, que es como
            // acaban separandose dos listas de estados.
            ->withCount([
                'asistencias as asistentes' => fn ($q) => $q->where('estado', AsistenciaActividad::ASISTIO),
            ])
            ->orderByRaw('verificada_en IS NOT NULL')
            ->orderByDesc('iniciada_en')
            ->get();

        return view('externa.clases', [
            'institucion' => $institucion,
            'sesiones' => $sesiones,
            'porFirmar' => $sesiones->filter(fn (SesionActividad $s) => ! $s->estaVerificada())->count(),
        ]);
    }

    /** Da fe: la clase se dio en esta sede. */
    public function verificar(Request $request, SesionActividad $sesion): RedirectResponse
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');

        $this->exigirQueSeaSuya($request, $sesion);

        $motivo = VerificacionExterna::registrar($sesion, $perfil, VerificacionExterna::PROPIA);

        if ($motivo === 'sin_iniciar') {
            return back()->with('error', 'Esa clase todavía no ha empezado, así que no hay nada de qué dar fe.');
        }

        return back()->with('success', 'Queda verificada. Gracias.');
    }

    /**
     * Retira la firma.
     *
     * El aviso nombra el caso que de verdad importa —una firma que entro por el
     * QR y que esta persona no reconoce— porque es el unico que no se explica
     * solo. Equivocarse de renglon se entiende sin que nadie lo diga.
     */
    public function retirar(Request $request, SesionActividad $sesion): RedirectResponse
    {
        $this->exigirQueSeaSuya($request, $sesion);

        VerificacionExterna::retirar($sesion);

        return back()->with('success', 'Retirada. Esa clase vuelve a constar como no verificada.');
    }

    /**
     * El QR con el que el profesor deja verificada la clase alli mismo.
     *
     * SE LO SACA LA PROPIA INSTITUCION, igual que cualquiera saca «Mi carné», y
     * ademas administracion cuando lo entrega la primera vez. El PROFESOR NO,
     * ni siquiera el responsable del programa: es la llave con la que se
     * verifica su propio trabajo, y darsela deja la verificacion sin sentido.
     * Por eso esta ruta vive en el grupo de esta cuenta y el otro camino —el
     * papel que imprime administracion— vive en Gestion.
     */
    public function qr(Request $request): View
    {
        $institucion = $this->suInstitucion($request);

        return view('externa.qr', [
            'institucion' => $institucion,
            'propio' => true,
        ]);
    }

    /**
     * Que la sesion sea de un programa de SU institucion.
     *
     * 404 y no 403: si no es suya, para esta cuenta esa clase no existe. Va en
     * un metodo y no repetido en los dos controladores de arriba porque es la
     * unica cosa que los dos tienen que comprobar, y la copia que se olvide es
     * la que deja firmar las clases de otra escuela.
     */
    private function exigirQueSeaSuya(Request $request, SesionActividad $sesion): void
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');

        /** @var Actividad $actividad */
        $actividad = $sesion->actividad;

        abort_unless(Permisos::verificaLaActividad($perfil, $actividad), 404);
    }
}
