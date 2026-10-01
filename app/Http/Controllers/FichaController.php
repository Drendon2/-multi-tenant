<?php

namespace App\Http\Controllers;

use App\Models\Actividad;
use App\Models\DocumentoEstudiante;
use App\Models\DocumentoRequerido;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Support\Permisos;
use App\Support\ResumenAsistencia;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Las fichas de una persona: el destino de su nombre en cualquier listado.
 *
 * Son tres pantallas con tres alcances distintos, y la diferencia no es
 * decorativa: la matriz de visibilidad del proyecto separa identidad, contacto y
 * datos sensibles, y cada una de estas vistas cubre exactamente un escalon.
 */
class FichaController extends Controller
{
    /**
     * Ficha de una persona, sea cual sea su rol.
     *
     * Reune identidad y contacto, resume lo que corresponde segun el rol
     * —promotorias que dicta un profesor; trayectoria de un estudiante— y enlaza
     * a la trayectoria completa y, para el administrador, a la ficha con
     * encuesta y documento.
     *
     * Quien ENTRA lo decide `Permisos::puedeVerFicha`. QUE se muestra sigue la
     * matriz de visibilidad: edad, telefono y acudiente de un estudiante son
     * para direccion, y para el profesor SOLO si ese estudiante cursa alguna de
     * sus promotorias. Que un profesor pueda abrir la ficha no le da acceso a
     * los datos de contacto de cualquiera.
     *
     * La edad tiene ademas un limite propio, y no viaja con el resto del
     * contacto: la del PERSONAL no se muestra, ni siquiera al administrador.
     * El porque esta en la matriz de `Perfil`. Por eso la esconde la plantilla y
     * no `$veContacto`: son dos puertas distintas y juntarlas en una bandera
     * dejaria sin telefono a quien solo hay que dejar sin edad.
     */
    public function usuario(Request $request, Perfil $usuario): View|RedirectResponse
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');

        if (! Permisos::puedeVerFicha($perfil, $usuario)) {
            return redirect()->route('panel')->with(
                'error',
                "No tienes acceso a la ficha de {$usuario->nombre_completo}: "
                .'un profesor solo puede consultar la de sus estudiantes.'
            );
        }

        $esEstudiante = $usuario->rol === 'estudiante';

        // El contacto es el dato acotado, no la ficha entera.
        $veContacto = in_array($perfil->rol, ['administrador', 'director'], true)
            || ($perfil->rol === 'profesor' && $esEstudiante && $this->loTieneEnClase($perfil, $usuario));

        $datos = $esEstudiante ? $usuario->datosEstudiante : null;

        // Solo los obligatorios: un papel opcional que falta no deja la
        // matricula incompleta. La etiqueta la ve todo el personal —es una
        // gestion pendiente, no un dato sensible—, pero los ARCHIVOS siguen
        // siendo del administrador.
        $papelesPendientes = $datos
            ? array_values(array_filter(
                $datos->documentosFaltantes(),
                fn (DocumentoRequerido $d) => $d->obligatorio
            ))
            : [];

        // El periodo del panel de asistencia lo eligen las flechas. Va como
        // parametro de consulta y no en el camino porque lo que se mueve es una
        // seccion: el contacto, la trayectoria y los papeles de esta ficha son
        // los mismos en cualquier periodo.
        $navegacion = ResumenAsistencia::navegacionDePeriodos(
            $usuario,
            $request->query('periodo'),
            $esEstudiante
        );
        $periodo = $navegacion['periodo'];

        // El panel de asistencia sigue la misma matriz que el resto de la ficha:
        // un profesor ve lo de SUS promotorias y no la asistencia del estudiante
        // en otras disciplinas. Direccion lo ve completo.
        if ($esEstudiante) {
            $acotarA = $perfil->rol === 'profesor'
                ? Promotoria::where('profesor_id', $perfil->id)->pluck('id')->all()
                : null;

            $asistencia = ResumenAsistencia::deEstudiante($usuario, $periodo, $acotarA);
        } else {
            $asistencia = ResumenAsistencia::deProfesor($usuario, $periodo);
        }

        return view('panel.detalle-usuario', [
            'objetivo' => $usuario,
            'esEstudiante' => $esEstudiante,
            'veContacto' => $veContacto,
            'papelesPendientes' => $papelesPendientes,
            'asistencia' => $asistencia,
            'periodo' => $periodo,
            'periodoAtras' => $navegacion['atras']
                ? route('detalle-usuario', [$usuario, 'periodo' => $navegacion['atras']->id])
                : null,
            'periodoAdelante' => $navegacion['adelante']
                ? route('detalle-usuario', [$usuario, 'periodo' => $navegacion['adelante']->id])
                : null,
            'periodoEsElEnCurso' => $periodo !== null && $periodo->activo,
            'acudiente' => $datos && $veContacto ? $datos->acudiente : null,
            'resumen' => $esEstudiante ? Matricula::resumenTrayectoria($usuario) : null,
            // Las promotorias salen del VINCULO y no del rol: un director que
            // ademas dicta tiene que ver las suyas en su ficha. Para quien no
            // dicta nada la lista queda vacia sola, sin preguntarle el rol.
            'promotorias' => $esEstudiante
                ? collect()
                : Promotoria::where('profesor_id', $usuario->id)
                    ->with(['area', 'grupos'])
                    ->join('areas', 'areas.id', '=', 'promotorias.area_id')
                    ->orderBy('areas.nombre')
                    ->orderBy('promotorias.nombre')
                    ->select('promotorias.*')
                    ->get(),
            // LOS CURSOS, TALLERES Y GRUPOS DE PROYECCION A CARGO.
            //
            // Faltaban del todo hasta el 06/09/2026: la ficha decia
            // «Promotorias a cargo» y una actividad asignada no aparecia por
            // ningun lado. Asignabas un taller a un director y su ficha seguia
            // diciendo lo mismo que antes. Lo reporto el usuario: «si le asigno
            // un curso, taller o grupo de proyeccion no se diferencia de las
            // demas cosas y lo hace un poco confuso».
            //
            // Cuelgan de `responsable_id` y NO de `promotorias.profesor_id`, que
            // es exactamente la razon por la que se cayeron de esta pantalla: la
            // consulta de al lado nunca las iba a encontrar.
            'actividades' => $esEstudiante
                ? collect()
                : Actividad::where('responsable_id', $usuario->id)
                    ->withCount(['inscritos', 'sesiones'])
                    ->orderBy('nombre')
                    ->get(),
            'puedeGestionarUsuarios' => in_array($perfil->rol, ['director', 'administrador'], true),
        ]);
    }

    /**
     * Trayectoria de un estudiante: en que promotorias ha estado y en cuales
     * sigue.
     *
     * La ve el personal completo, y muestra el historial ENTERO: todas las
     * promotorias del estudiante, no solo las de quien consulta.
     *
     * Eso ultimo es una excepcion deliberada al criterio acotado que sigue el
     * resto del sistema, y se decidio asi porque el dato es justamente el que
     * hace falta para ubicar a alguien en un nivel: saber que lleva tres
     * periodos en Danza le sirve al profesor de Teatro que lo recibe por primera
     * vez. No abre nada mas: la encuesta demografica y la copia del documento
     * siguen siendo solo del administrador.
     */
    public function historial(Request $request, Perfil $usuario): View
    {
        abort_unless($usuario->rol === 'estudiante', 404);

        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');

        $enCurso = Periodo::enCurso();
        $puedeCorregir = $this->puedeCorregirPromotoria($perfil, $enCurso);

        return view('panel.historial-estudiante', [
            'estudiante' => $usuario,
            'historial' => Matricula::historialPorPeriodo($usuario),
            'resumen' => Matricula::resumenTrayectoria($usuario),
            'puedeCorregir' => $puedeCorregir,
            // Por que NO puede, cuando el motivo es temporal. Al director con la
            // ventana cerrada le desaparecia la accion y quedaba un hueco, y un
            // hueco donde antes habia un boton se lee como un fallo, no como una
            // regla — la misma leccion que el «Plazo cerrado» de la hoja de
            // asistencia. A un profesor no se le dice nada: nunca la tuvo.
            'motivoSinCorregir' => ! $puedeCorregir && $perfil->rol === 'director'
                ? 'Matrículas cerradas'
                : null,
            'periodoEnCursoId' => $enCurso?->id,
            // Solo hace falta la lista si va a pintarse el desplegable. Agrupada
            // por area para el <optgroup>, como el resto de los selectores de
            // promotoria del proyecto.
            //
            // Al director, SOLO las de sus departamentos (28/09/2026): hasta ese
            // dia el desplegable ofrecia todas y bastaba elegir una ajena.
            'promotoriasParaCorregir' => $puedeCorregir
                ? Promotoria::queVe($perfil)->with('area')
                    ->join('areas', 'areas.id', '=', 'promotorias.area_id')
                    ->orderBy('areas.nombre')
                    ->orderBy('promotorias.nombre')
                    ->select('promotorias.*')
                    ->get()
                : collect(),
        ]);
    }

    /**
     * Corrige la promotoria de una matricula: el estudiante se inscribio en la
     * que no era.
     *
     * MUEVE la fila en vez de retirar una y crear otra, y eso es deliberado (se
     * decidio el 28/08/2026). No fue una salida, fue un dato mal puesto: la
     * matricula conserva su fecha de inscripcion y el estudiante figura como si
     * siempre hubiera estado en la correcta. Retirar y crear le dejaria una
     * `retirada` en la equivocada, que en pantalla se lee como que se salio.
     *
     * Vuelve a `pendiente` aunque estuviera activa, por la misma razon por la
     * que solo quien dicta pasa lista: confirmar es un acto de quien tiene a esa
     * persona en su salon, y el profesor de la promotoria nueva no la ha visto
     * nunca. Le aparece en su Panel por el circuito normal.
     *
     * Tambien se mueve una RETIRADA, y al moverla revive como pendiente (se
     * decidio el 29/08/2026). Es el mismo trato que ya le da el boton
     * «Matricularme» del catalogo cuando el estudiante vuelve por su cuenta:
     * quien se salio y quiere entrar a otra no esta corrigiendo un dato viejo,
     * esta entrando. El limite de promotorias vuelve a contarla, y de eso se
     * encarga `Matricula::validar()`, que ve que la ocupacion aumenta.
     *
     * Solo el periodo EN CURSO. Mover una matricula de un periodo cerrado
     * cambiaria certificados ya emitidos y la antiguedad que cuenta
     * `InformeController`, y eso no es corregir un error de captura.
     */
    public function corregirPromotoria(Request $request, Matricula $matricula): RedirectResponse
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');

        $volver = redirect()->route('historial-estudiante', $matricula->estudiante_id);
        $enCurso = Periodo::enCurso();

        if ($matricula->periodo_id !== $enCurso?->id) {
            return $volver->with('error', 'Solo se corrige una matrícula del periodo en curso.');
        }

        if (! $this->puedeCorregirPromotoria($perfil, $enCurso)) {
            // El motivo importa: a un director con la ventana cerrada no le
            // falta permiso siempre, le falta AHORA, y un «no tienes acceso»
            // seco le haria pensar que el sistema esta mal.
            return $volver->with('error', $perfil->rol === 'director'
                ? "Las matrículas de {$enCurso} están cerradas. Con la ventana cerrada, "
                    .'solo el administrador puede mover una matrícula.'
                : 'No tienes acceso a esta corrección.');
        }

        // El id llega de un formulario: que exista se exige en la consulta, no
        // se da por hecho porque se haya pintado en el desplegable.
        $destino = Promotoria::find($request->input('promotoria_id'));

        if ($destino === null) {
            return $volver->with('error', 'Esa promotoría no existe.');
        }

        // El director, solo DENTRO de sus departamentos, por los dos lados:
        // sacar a alguien de una promotoria ajena y meterlo en una ajena son la
        // misma puerta abierta. Hasta el 28/09/2026 solo se miraba el rol y la
        // ventana; esconder el desplegable no la cerraba.
        if (! Permisos::veLaPromotoria($perfil, $matricula->promotoria)
            || ! Permisos::veLaPromotoria($perfil, $destino)) {
            return $volver->with('error', 'No tienes acceso a esa promotoría.');
        }

        if ($destino->id === $matricula->promotoria_id) {
            return $volver->with('error', 'La matrícula ya está en esa promotoría.');
        }

        // Anotada porque `Matricula` no lleva `@property` y PHPStan no adivina
        // el tipo detras de la relacion. No es un cast para callarlo: el nombre
        // se lee ANTES de mover, que es cuando todavia es el de la promotoria
        // equivocada, y es lo que se le cuenta a quien corrige.
        /** @var Promotoria $anterior */
        $anterior = $matricula->promotoria;
        $origen = $anterior->nombre;
        $revivida = $matricula->estado === Matricula::RETIRADA;
        /** @var Perfil $quien */
        $quien = $matricula->estudiante;

        $matricula->promotoria_id = $destino->id;
        // El grupo cuelga de la promotoria vieja: dejarlo puesto meteria al
        // estudiante en un horario de otra promotoria. Se pierde siempre, y por
        // eso la pantalla lo avisa antes de preguntar.
        $matricula->repartirEn([]);
        $matricula->estado = Matricula::PENDIENTE;
        // Vuelve a estar en juego, asi que el porque de la salida deja de valer:
        // una readmitida no puede seguir contando que la rechazaron.
        $matricula->motivo_retiro = null;

        try {
            $matricula->validar();
            // En su propia transaccion para que, si la base la rechaza (cupo,
            // unico), se deshaga SOLO esto: en PostgreSQL una sentencia fallida
            // deja inservible la transaccion que la envuelva.
            DB::transaction(fn () => $matricula->save());
        } catch (ValidationException $e) {
            return $volver->with('error', implode(' ', Arr::flatten($e->errors())));
        } catch (QueryException $e) {
            return $volver->with('error', $this->porQueNoSePudoCorregir($e, $destino));
        }

        // Se dicen distinto porque pasaron cosas distintas: una activa cambió de
        // sitio, una retirada volvió a entrar. Un mensaje único dejaría a quien
        // corrige sin saber que acaba de readmitir a alguien.
        return $volver->with('success', $revivida
            ? "{$quien->nombre_completo} estaba retirado de {$origen} y vuelve a entrar, "
                ."ahora en {$destino->nombre}. Queda pendiente de que la confirme quien la dicta."
            : "La matrícula pasó de {$origen} a {$destino->nombre}, "
                .'y queda pendiente de que la confirme quien la dicta.');
    }

    /**
     * Quien puede mover una matricula de promotoria, y cuando.
     *
     * La regla vive AQUI y la usan las dos: la pantalla, para decidir si pinta
     * el panel, y la accion, para decidir si lo acepta. Separadas se
     * desincronizan, y la forma en que se nota es la peor —el boton se ve, se
     * pulsa y contesta que no—, que es justo lo que este proyecto ya aprendio
     * con el rastro de las retiradas: la regla en un sitio, no una copia por
     * pantalla.
     *
     * El administrador puede en cualquier momento. El director, solo mientras
     * la ventana de matriculas este abierta: con ella cerrada el periodo ya esta
     * repartido —hay grupos armados y listas pasadas— y mover a alguien deja de
     * ser una correccion de captura para ser un cambio de plan. Esa excepcion se
     * queda en una sola persona (decidido el 29/08/2026).
     */
    private function puedeCorregirPromotoria(Perfil $perfil, ?Periodo $enCurso): bool
    {
        if ($perfil->rol === 'administrador') {
            return true;
        }

        if ($perfil->rol === 'director') {
            return (bool) $enCurso?->matriculas_abiertas;
        }

        return false;
    }

    /**
     * Traduce el rechazo de la base a algo que se pueda leer en pantalla.
     *
     * Las dos garantias que pueden saltar aqui viven en el motor y no en la
     * aplicacion, asi que no hay forma de anticiparlas sin una carrera: el
     * trigger de cupo —que tambien corre en UPDATE— y el unico
     * `(estudiante, promotoria, periodo)`, que cuenta TAMBIEN las retiradas. Ese
     * segundo caso es el que sorprende: un estudiante que entro y se salio de la
     * promotoria destino este mismo periodo no puede volver a ella moviendo otra
     * matricula, y el mensaje crudo de MariaDB no lo explicaria.
     */
    private function porQueNoSePudoCorregir(QueryException $e, Promotoria $destino): string
    {
        $mensaje = $e->getMessage();

        if (str_contains($mensaje, 'unica_matricula_por_periodo')) {
            return "El estudiante ya tiene una matrícula en {$destino->nombre} este periodo, "
                .'aunque esté retirada. Reactívala desde ahí en vez de mover esta.';
        }

        if (str_contains($mensaje, '45000') || str_contains($mensaje, 'cupo')) {
            return "{$destino->nombre} no tiene cupo libre en este periodo.";
        }

        throw $e;
    }

    /**
     * Ficha completa de un estudiante: encuesta demografica y documento.
     *
     * Solo el administrador. La trayectoria por promotorias no se repite aqui:
     * vive en `historial`, que si ve todo el personal.
     */
    public function estudiante(Perfil $usuario): View
    {
        abort_unless($usuario->rol === 'estudiante', 404);

        $datos = $usuario->datosEstudiante;

        return view('panel.detalle-estudiante', [
            'estudiante' => $usuario,
            'datos' => $datos,
            'encuesta' => $usuario->encuesta,
            // UNA RANURA POR PAPEL PEDIDO, con lo que haya entregado. Es la
            // misma forma que arma «Mi perfil» y a proposito: son la misma
            // lista mirada desde los dos lados.
            //
            // Antes aqui solo salia la copia del documento de identidad —lo
            // unico que tenia enlace— y los demas papeles no se veian por
            // ningun sitio: se subian y ahi se quedaban. Al dejar de ser aquel
            // un caso especial (05/09/2026) el agujero se cerro solo.
            'papeles' => $datos === null ? [] : DocumentoRequerido::activos()->ordenados()->get()
                ->map(fn (DocumentoRequerido $requerido) => [
                    'requerido' => $requerido,
                    'entrega' => $datos->documentos
                        ->first(fn (DocumentoEstudiante $d) => $d->requerido_id === $requerido->id
                            && $d->archivo !== ''),
                ])
                ->all(),
        ]);
    }

    /** ¿El estudiante cursa alguna promotoria de este profesor, sin retirar? */
    private function loTieneEnClase(Perfil $profesor, Perfil $estudiante): bool
    {
        return Matricula::query()
            ->where('estudiante_id', $estudiante->id)
            ->where('estado', '!=', Matricula::RETIRADA)
            ->whereHas('promotoria', fn ($q) => $q->where('profesor_id', $profesor->id))
            ->exists();
    }

    /**
     * Deshacer un rechazo: la solicitud vuelve a estar sobre la mesa.
     *
     * POR QUE EXISTE. Al empezar a distinguir el rechazo (04/09/2026) se cerro
     * la unica salida que habia: el catalogo dejo de ofrecerle «Matricularme» a
     * quien habian rechazado, y el POST tambien. Hasta entonces la salida era
     * mala pero existia — volver a pedirlo a ciegas —, y cerrarla dejo un
     * callejon completo, porque «Corregir promotoria» se niega a mover una
     * matricula a la promotoria en la que ya esta. Una solicitud rechazada en
     * Piano no podia volver a Piano ese periodo NI por el estudiante ni por
     * direccion.
     *
     * QUIEN PUEDE es `puedeGestionarPromotoria`, o sea la MISMA puerta que
     * rechaza en el Panel, y no la de «Corregir promotoria» que tiene al lado.
     * Es a proposito: quien pudo rechazar puede deshacerlo, y es quien se
     * equivoca al pulsar y quien lo nota primero. Obligarle a pedirselo a
     * direccion es un viaje de ida y vuelta por un clic mal dado, en un producto
     * que se usa desde el telefono.
     *
     * VUELVE A PENDIENTE, no a activa. Se deshace la decision, no se toma la
     * contraria: la solicitud regresa a la cola de quien dicta, que es donde
     * estaba antes del clic. Es ademas lo mismo que hace la readmision de
     * `corregirPromotoria`.
     *
     * Se valida como cualquier alta porque la ocupacion AUMENTA: entre el
     * rechazo y el arrepentimiento el estudiante pudo llenar su limite de
     * promotorias por otro lado, y el cupo de esta pudo agotarse.
     */
    public function deshacerRechazo(Request $request, Matricula $matricula): RedirectResponse
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');

        $volver = redirect()->route('historial-estudiante', $matricula->estudiante_id);
        $enCurso = Periodo::enCurso();

        if ($matricula->periodo_id !== $enCurso?->id) {
            // Igual que la correccion: un periodo cerrado es historial, y
            // reabrir una solicitud de entonces cambiaria lo que ya se conto.
            return $volver->with('error', 'Solo se deshace un rechazo del periodo en curso.');
        }

        if (! Permisos::puedeGestionarPromotoria($perfil, $matricula->promotoria)) {
            return $volver->with('error', 'No tienes acceso a esta promotoría.');
        }

        // Que siga siendo un rechazo se comprueba aqui y no se da por hecho
        // porque se haya pintado el boton: entre que se pinto y se pulso, otro
        // pudo deshacerlo, o el estudiante pudo volver a entrar por otro camino.
        if ($matricula->estado !== Matricula::RETIRADA
            || $matricula->motivo_retiro !== Matricula::RETIRO_RECHAZO) {
            return $volver->with('error', 'Esa matrícula ya no está rechazada.');
        }

        return $this->devolverAPendiente(
            $matricula,
            $volver,
            "La solicitud de {$matricula->estudiante->nombre_completo} a {$matricula->promotoria} "
            .'vuelve a estar pendiente, y quien la dicta la verá otra vez en su Panel.'
        );
    }

    /**
     * Readmitir a alguien en la MISMA promotoria de la que salio.
     *
     * POR QUE EXISTE (28/09/2026, pedido por el usuario): quien cancela por
     * accidente no tenia vuelta. «Corregir promotoria» se niega a mover una
     * matricula a donde ya esta, «Deshacer el rechazo» solo vale para rechazos,
     * y lo unico que quedaba era que el propio estudiante pulsara
     * «Matricularme» en el catalogo — con la ventana de matriculas ABIERTA.
     * Cerrada, no habia ningun camino.
     *
     * Vale para cualquier retirada del periodo en curso: cancelacion aprobada,
     * retiro propio, abandono y tambien un rechazo (el boton de la pantalla se
     * esconde en ese caso porque ya esta «Deshacer el rechazo», que tambien
     * puede pulsar el profesor).
     *
     * LA PUERTA ES LA DE «CORREGIR PROMOTORIA», decision del usuario: el
     * administrador siempre, el director solo con la ventana abierta. No la
     * del rechazo: aqui se revierte una salida que decidio el estudiante o la
     * direccion, no un clic del profesor.
     *
     * VUELVE A PENDIENTE, como las otras dos readmisiones: el retiro le quito
     * los grupos y quien la dicta tiene que volver a confirmarla y repartirla.
     */
    public function readmitir(Request $request, Matricula $matricula): RedirectResponse
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');

        $volver = redirect()->route('historial-estudiante', $matricula->estudiante_id);
        $enCurso = Periodo::enCurso();

        if ($matricula->periodo_id !== $enCurso?->id) {
            return $volver->with('error', 'Solo se readmite en una matrícula del periodo en curso.');
        }

        if (! $this->puedeCorregirPromotoria($perfil, $enCurso)) {
            return $volver->with('error', $perfil->rol === 'director'
                ? "Las matrículas de {$enCurso} están cerradas. Con la ventana cerrada, "
                    .'solo el administrador puede readmitir.'
                : 'No tienes acceso a esta corrección.');
        }

        // El director, solo en las promotorias de sus departamentos: esconder
        // el boton no cierra la puerta.
        if (! Permisos::veLaPromotoria($perfil, $matricula->promotoria)) {
            return $volver->with('error', 'No tienes acceso a esta promotoría.');
        }

        // Entre que se pinto el boton y se pulso, otro pudo readmitirla o el
        // estudiante pudo volver por el catalogo.
        if ($matricula->estado !== Matricula::RETIRADA) {
            return $volver->with('error', 'Esa matrícula ya no está retirada.');
        }

        return $this->devolverAPendiente(
            $matricula,
            $volver,
            "{$matricula->estudiante->nombre_completo} vuelve a {$matricula->promotoria}. "
            .'Queda pendiente de que la confirme quien la dicta, que además tendrá que '
            .'asignarle grupo de nuevo.'
        );
    }

    /**
     * Lo comun de las dos readmisiones en la misma promotoria.
     *
     * Se valida como cualquier alta porque la ocupacion AUMENTA: entre la
     * salida y la vuelta el estudiante pudo llenar su limite de promotorias por
     * otro lado, y el cupo pudo agotarse (eso lo dice el trigger).
     */
    private function devolverAPendiente(Matricula $matricula, RedirectResponse $volver, string $exito): RedirectResponse
    {
        $matricula->estado = Matricula::PENDIENTE;
        $matricula->motivo_retiro = null;

        try {
            $matricula->validar();
            // Ver `corregir()`: aislada, por si la base la rechaza.
            DB::transaction(fn () => $matricula->save());
        } catch (ValidationException $e) {
            return $volver->with('error', implode(' ', Arr::flatten($e->errors())));
        } catch (QueryException $e) {
            return $volver->with('error', $this->porQueNoSePudoCorregir($e, $matricula->promotoria));
        }

        return $volver->with('success', $exito);
    }
}
