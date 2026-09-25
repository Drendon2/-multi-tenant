<?php

namespace App\Http\Controllers;

use App\Models\Actividad;
use App\Models\ConfiguracionInstitucion;
use App\Models\InscritoActividad;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Support\AsistenciaDeActividad;
use App\Support\Imagen;
use App\Support\LogoInstitucion;
use App\Support\Permisos;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * El certificado de matricula en PDF.
 *
 * Dos formas del mismo documento, y la diferencia es lo que certifica:
 *
 * - El de UNA matricula. Es el que suele pedir un tercero —un colegio que pide
 *   la constancia de la actividad extracurricular, una empresa que da permiso de
 *   horario—: dice que esta persona esta en Guitarra, en tal periodo, con tal
 *   horario.
 * - El REUNIDO, con todas las matriculas vigentes del periodo en curso. Sirve
 *   cuando lo que hay que acreditar es la dedicacion completa en la casa.
 *
 * QUE se puede certificar es una sola regla y esta en un sitio: solo la
 * matricula ACTIVA —confirmada por quien dicta— y la FINALIZADA, que es la
 * activa de un periodo ya cerrado y acredita haber cursado. Una pendiente no se
 * certifica: nadie ha confirmado todavia que esa persona este en el curso, y un
 * papel sellado diciendo lo contrario es un problema que se descubre fuera de
 * casa. Una retirada tampoco: cuenta lo contrario de lo que el papel afirma.
 */
class CertificadoController extends Controller
{
    /** El certificado de una matricula concreta. */
    public function matricula(Request $request, Matricula $matricula): Response
    {
        $solicitante = $request->user()?->perfil;

        // 404 y no 403: que exista o no la matricula de otra persona tampoco es
        // asunto de quien pregunta. Misma linea que las fotos.
        abort_unless(Permisos::puedeCertificarMatricula($solicitante, $matricula), 404);

        $matricula->load(['estudiante.datosEstudiante', 'promotoria.area', 'promotoria.profesor', 'grupos.sesiones', 'periodo']);

        abort_unless($this->esCertificable($matricula), 404, 'Esta matrícula no se puede certificar.');

        return $this->generar(
            'Certificado de matrícula',
            $matricula->estudiante,
            collect([$matricula]),
            $matricula->periodo,
            $matricula->estado_visible === Matricula::FINALIZADA
        );
    }

    /**
     * El certificado reunido: todas las matriculas vigentes del periodo EN
     * CURSO.
     *
     * Solo el periodo en curso y no el historial entero: lo que este documento
     * acredita es una situacion presente —«esta cursando»—, y una lista con
     * cinco anos de promotorias no es una constancia sino una trayectoria, que
     * es otra cosa y ya tiene su pantalla.
     */
    public function todo(Request $request, Perfil $estudiante): Response
    {
        $solicitante = $request->user()?->perfil;

        abort_unless(Permisos::puedeCertificarTodo($solicitante, $estudiante), 404);
        abort_unless($estudiante->rol === 'estudiante', 404);

        $periodo = Periodo::enCurso();

        // Sin nada que certificar se VUELVE con un aviso, no se aborta: el
        // enlace se pinta en la ficha sin consultar antes cuantas matriculas
        // vigentes tiene esa persona, y un 404 seco dejaria a quien lo pulsa
        // creyendo que el sistema se rompio en vez de que no hay nada que
        // certificar. Es el mismo trato que da la ficha a la que no se puede
        // abrir.
        $sinNada = fn (string $motivo) => redirect()
            ->back(fallback: route('panel'))
            ->with('error', $motivo);

        if ($periodo === null) {
            return $sinNada('No hay ningún periodo en curso, así que no hay matrícula vigente que certificar.');
        }

        $matriculas = Matricula::with(['promotoria.area', 'promotoria.profesor', 'grupos.sesiones'])
            ->where('estudiante_id', $estudiante->id)
            ->where('periodo_id', $periodo->id)
            ->where('estado', Matricula::ACTIVA)
            ->get()
            ->sortBy(fn (Matricula $m) => $m->promotoria->nombre)
            ->values();

        if ($matriculas->isEmpty()) {
            return $sinNada(
                "{$estudiante->nombre_completo} no tiene matrículas activas en {$periodo->nombre}: "
                .'una solicitud pendiente de confirmar no se puede certificar.'
            );
        }

        return $this->generar(
            'Certificado de matrícula',
            $estudiante,
            $matriculas,
            $periodo,
            false
        );
    }

    /**
     * El certificado de asistencia a un curso o un taller.
     *
     * ES OTRO DOCUMENTO, no el de matricula con otro titulo, y la diferencia no
     * es de forma sino de QUE ACREDITA. El de matricula dice que alguien esta
     * inscrito: se apoya en la matricula, que alguien confirmo. Aqui no hay
     * matricula —a una actividad se entra por un enlace, sin cuenta— asi que lo
     * unico que puede sostener un papel es la lista que paso quien la dicto. Por
     * eso este certifica ASISTENCIA y exige el 80%, y el otro no exige ninguna.
     *
     * SOLO CURSOS Y TALLERES, decidido el 11/09/2026 con la alternativa
     * delante. Un grupo de proyeccion tiene ensayos y lista igual que un curso,
     * pero no es lo que se pidio. El corte va aqui y por TIPO, no por si tiene
     * fechas: `TIPOS_CON_FECHAS` es la misma pareja y ya significa «los que se
     * administran juntos en Cursos y talleres».
     *
     * QUIEN LO SACA es el responsable, un administrador o el director —
     * `puedeVerActividad`, la misma puerta que abre esta pantalla—. El propio
     * inscrito no: no tiene cuenta con la que entrar, que es justamente lo que
     * distingue una actividad de una matricula.
     */
    public function actividad(Request $request, Actividad $actividad, InscritoActividad $inscrito): Response
    {
        // Sin `@var` a proposito, al contrario que los otros dos metodos: aqui
        // el nulo se comprueba de verdad justo debajo, porque
        // `puedeVerActividad()` exige un Perfil y no acepta null.
        $solicitante = $request->user()?->perfil;

        // 404 y no 403, igual que los otros dos: que exista o no esta actividad
        // tampoco es asunto de quien pregunta.
        abort_unless($solicitante !== null && Permisos::puedeVerActividad($solicitante, $actividad), 404);
        abort_unless($actividad->llevaFechas(), 404, 'Solo los cursos y talleres dan certificado.');

        // El enlace no se pinta para quien no llega al minimo, y aun asi se
        // comprueba aqui: esconder el boton no cierra la URL, y este papel
        // afirma algo que tiene que ser verdad fuera de casa.
        $asistencia = AsistenciaDeActividad::deInscrito($inscrito);

        if (! $asistencia['certificable']) {
            return redirect()
                ->back(fallback: route('panel-actividad', $actividad))
                ->with('error', $this->porQueNoSeCertifica($inscrito, $actividad, $asistencia));
        }

        $institucion = ConfiguracionInstitucion::actual();

        $pdf = Pdf::loadView('certificados.actividad', [
            'institucion' => $institucion,
            'actividad' => $actividad,
            'inscrito' => $inscrito,
            'asistencia' => $asistencia,
            // Las fechas solo salen si hubo MAS DE UNA sesion, que es lo que se
            // pidio: en un taller de un dia, «del 3 de marzo al 3 de marzo» es
            // ruido que ademas ya dice la linea de al lado.
            'fechas' => $asistencia['sesiones'] > 1
                ? AsistenciaDeActividad::fechasDictadas($actividad->id)
                : null,
            'expedido' => now(),
            'logo' => $this->logo($institucion),
            'firma' => $this->incrustar($institucion->firma),
        ])->setPaper('letter', 'landscape');

        // HORIZONTAL y carta, pedido asi el 11/09. Carta por lo mismo que el de
        // matricula —es el papel de oficina en Colombia— y horizontal porque es
        // la forma en que se enmarca un diploma. Lo que cuesta esa vuelta esta
        // medido en `CertificadoDeActividadTest`: el ancho pasa de 792 a 1008 pt
        // y el ALTO disponible baja de 792 a 612, que es la mitad que importa.

        return $pdf->download($this->nombreDeCertificadoDeActividad($actividad, $inscrito));
    }

    /**
     * Por que esta persona no tiene papel, dicho con los numeros delante.
     *
     * Los dos casos se leen distinto y mandan a sitios distintos: sin ninguna
     * lista tomada el trabajo es de quien dicta, y con listas tomadas no hay
     * nada que hacer. Un «no se puede» a secas deja a quien lo lee sin saber
     * cual de los dos es.
     *
     * @param  array{sesiones: int, asistidas: int, porcentaje: int, certificable: bool}  $asistencia
     */
    private function porQueNoSeCertifica(InscritoActividad $inscrito, Actividad $actividad, array $asistencia): string
    {
        if ($asistencia['sesiones'] === 0) {
            return 'Todavía no se ha pasado lista en ninguna '.$actividad->etiquetaSesion()
                .' de «'.$actividad->nombre.'», así que no hay asistencia que certificar.';
        }

        $minimo = (int) (AsistenciaDeActividad::MINIMO * 100);

        return $inscrito->nombre_completo.' asistió a '.$asistencia['asistidas'].' de '
            .$asistencia['sesiones'].' ('.$asistencia['porcentaje'].'%), y el certificado pide al menos el '
            .$minimo.'%.';
    }

    private function nombreDeCertificadoDeActividad(Actividad $actividad, InscritoActividad $inscrito): string
    {
        return implode('-', [
            'certificado',
            Str::slug($actividad->nombre),
            Str::slug($inscrito->nombre_completo),
        ]).'.pdf';
    }

    /**
     * ¿Es esta matricula de las que se pueden certificar?
     *
     * Se mira `estado_visible` y no `estado` porque ahi vive la distincion entre
     * la activa de un periodo abierto y la de uno ya cerrado, que es justo la
     * que cambia el verbo del documento.
     */
    private function esCertificable(Matricula $matricula): bool
    {
        return in_array(
            $matricula->estado_visible,
            [Matricula::ACTIVA, Matricula::FINALIZADA],
            true
        );
    }

    /**
     * @param  Collection<int, Matricula>  $matriculas
     */
    private function generar(
        string $titulo,
        Perfil $estudiante,
        $matriculas,
        Periodo $periodo,
        bool $finalizado
    ): Response {
        $institucion = ConfiguracionInstitucion::actual();

        $pdf = Pdf::loadView('certificados.matricula', [
            'titulo' => $titulo,
            'institucion' => $institucion,
            'estudiante' => $estudiante,
            'documento' => $estudiante->datosEstudiante?->documento_identidad ?: null,
            'matriculas' => $matriculas,
            'periodo' => $periodo,
            // Cambia el verbo: «tiene matricula vigente» mientras el periodo
            // corre, «cursó» cuando ya termino. Certificar en presente algo que
            // acabo hace un ano es afirmar lo que no es.
            'finalizado' => $finalizado,
            'expedido' => now(),
            'logo' => $this->logo($institucion),
            'firma' => $this->incrustar($institucion->firma),
        ])->setPaper('letter');

        // Carta y no A4: es el tamano de papel de oficina en Colombia, y un
        // certificado se imprime.

        return $pdf->download($this->nombreDeArchivo($estudiante, $matriculas));
    }

    /**
     * El logo que encabeza el certificado.
     *
     * Con dos origenes y ese es el punto: el propio de la institucion si lo
     * cargaron, y si no sus iniciales generadas (`LogoInstitucion`) —lo mismo
     * que ya se ve en la cabecera y en las pantallas publicas—. Mirar solo la fila de
     * configuracion dejaba sin logo justo a la institucion que todavia no ha
     * subido el suyo, que es la que acaba de instalar el sistema.
     *
     * Se lee en el servidor y no por su URL: dompdf no sale a la red a buscar
     * nada, y pedirse una pagina a si mismo es una forma cara de leer un
     * archivo.
     */
    private function logo(ConfiguracionInstitucion $institucion): ?string
    {
        // El propio o las iniciales generadas: la regla vive en
        // `LogoInstitucion`. WebP incluido, que dompdf no entiende y GD si.
        return Imagen::aDataUriPng(LogoInstitucion::binario(), Imagen::LADO_LOGO_IMPRESO);
    }

    /**
     * Una imagen del disco privado, lista para incrustar en el PDF.
     *
     * Devuelve null en cuanto algo no cuadra —no hay archivo, no esta en disco,
     * no se puede decodificar—, y el certificado se genera igual sin ella. Un
     * logo que falta no es razon para negarle a nadie su constancia.
     */
    private function incrustar(string $ruta): ?string
    {
        if ($ruta === '') {
            return null;
        }

        $disco = Storage::disk('local');

        if (! $disco->exists($ruta)) {
            return null;
        }

        return Imagen::aDataUriPng((string) $disco->get($ruta));
    }

    /**
     * @param  Collection<int, Matricula>  $matriculas
     */
    private function nombreDeArchivo(Perfil $estudiante, $matriculas): string
    {
        $partes = ['certificado-matricula', Str::slug($estudiante->nombre_completo)];

        // Con una sola matricula el nombre dice cual, que es lo que distingue
        // dos certificados de la misma persona en la carpeta de descargas.
        if ($matriculas->count() === 1) {
            $partes[] = Str::slug($matriculas->first()->promotoria->nombre);
        }

        return implode('-', $partes).'.pdf';
    }
}
