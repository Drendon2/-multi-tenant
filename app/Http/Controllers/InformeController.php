<?php

namespace App\Http\Controllers;

use App\Models\Actividad;
use App\Models\Area;
use App\Models\AsistenciaActividad;
use App\Models\DocumentoEstudiante;
use App\Models\DocumentoRequerido;
use App\Models\EncuestaDemografica;
use App\Models\Grupo;
use App\Models\InscritoActividad;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Support\Csv;
use App\Support\InstitucionActual;
use App\Support\Permisos;
use Generator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Los informes descargables.
 *
 * Son dos y tienen alcances MUY distintos, asi que van con puertas distintas:
 *
 * - El de estudiantes por grupo es operativo: la lista que se lleva quien dicta
 *   para pasar asistencia en papel o para llamar a una familia. Lo baja el
 *   personal, y un profesor solo el de las promotorias que dicta.
 * - El de la institucion es el completo, con la encuesta demografica NOMINAL.
 *   Solo el administrador. Ver el aviso largo en `institucion()`.
 */
class InformeController extends Controller
{
    /**
     * Cuantas filas se traen por tanda.
     *
     * Es constante y no un numero suelto porque hay una prueba que necesita
     * superarla: el fallo que se arreglo aqui —tandas que se solapan y repiten
     * filas— solo aparece cuando el informe pasa de una tanda, y con cuatro
     * filas de prueba no se veia.
     */
    private const POR_TANDA = 100;

    /**
     * Estudiantes por promotoria y grupo, con su contacto.
     *
     * Sigue la misma matriz de visibilidad que la ficha: edad, telefono y
     * acudiente son para direccion, y para el profesor SOLO de sus promotorias.
     * Por eso el filtro no es un adorno del listado sino la propia puerta — un
     * profesor que pida el informe entero recibe unicamente lo suyo.
     *
     * Se acota al periodo en curso: la lista que se pide aqui es la de quien
     * esta yendo a clase ahora, no el historico. El historico de una persona
     * vive en su ficha.
     *
     * Se puede pedir entero, de una promotoria o de UN GRUPO. Lo de un grupo es
     * lo que de verdad se lleva impreso al salon: la lista de a quien le toca
     * ese horario. Con el informe entero encima, quien dicta tendria que buscar
     * sus veinte filas entre trescientas.
     */
    public function estudiantes(Request $request): StreamedResponse
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');
        $periodo = Periodo::enCurso();
        $grupo = $this->grupoPedido($request, $perfil);
        $promotoria = $this->promotoriaPedida($request, $perfil, $grupo);

        $consulta = Matricula::query()
            ->whereIn('estado', Matricula::ESTADOS_INSCRITO)
            ->when($periodo, fn ($q) => $q->where('periodo_id', $periodo->id))
            // Sin periodo en curso no hay lista que dar: se devuelve vacio en
            // vez de barrer el historico entero.
            ->when($periodo === null, fn ($q) => $q->whereRaw('1 = 0'))
            // EL RECORTE DE QUIEN PIDE EL INFORME, y desde el 12/09/2026
            // alcanza tambien al DIRECTOR: solo saca la informacion de los
            // departamentos que administra. Antes solo se acotaba al profesor y
            // direccion bajaba la casa entera.
            //
            // Una sola subconsulta para los dos casos, por `queVe()`, que es
            // donde vive la regla. Escrito como dos `when()` distintos se
            // separarian, y aqui separarse significa que un director se baja en
            // un CSV los datos —telefono, acudiente, documento— de estudiantes
            // de un departamento que no es suyo.
            ->when(
                $perfil->rol !== 'administrador',
                fn ($q) => $q->whereIn(
                    'promotoria_id',
                    Promotoria::queVe($perfil)->select('promotorias.id')
                )
            )
            ->when($promotoria, fn ($q) => $q->where('promotoria_id', $promotoria->id))
            // Por la puente: quien esta en un grupo vive en `asignaciones_grupo`
            // desde el 10/09/2026. `whereHas` y no un join, para que este filtro
            // no duplique filas — el informe se recorre con `lazy()` y puede ser
            // de cientos, y una matricula en dos grupos saldria dos veces.
            ->when($grupo, fn ($q) => $q->whereHas('grupos', fn ($g) => $g->where('grupos.id', $grupo->id)))
            ->with([
                'estudiante.datosEstudiante.acudiente',
                'promotoria.area',
                // Con las sesiones: el horario se deriva de ellas, y sin
                // traerlas aqui el informe pregunta una vez por fila. Este
                // informe se recorre con `lazy()` y puede ser de cientos.
                'grupos.sesiones',
            ])
            ->join('promotorias', 'promotorias.id', '=', 'matriculas.promotoria_id')
            ->join('areas', 'areas.id', '=', 'promotorias.area_id')
            ->join('perfiles', 'perfiles.id', '=', 'matriculas.estudiante_id')
            ->orderBy('areas.nombre')
            ->orderBy('promotorias.nombre')
            ->orderBy('perfiles.nombre_completo')
            ->select('matriculas.*');

        return Csv::descargar($this->nombreDelArchivo($promotoria, $grupo), [
            'Departamento',
            'Promotoría',
            'Grupo',
            'Nivel',
            'Horario',
            'Salón',
            'Estudiante',
            'Edad',
            'Teléfono',
            'Acudiente',
            'Teléfono del acudiente',
            'Estado',
        ], $this->filasDeEstudiantes($consulta));
    }

    /**
     * El grupo que se pide, si se pide, y solo si esta persona puede verlo.
     *
     * Un 404 y no una lista vacia: pedir el grupo de una promotoria ajena tiene
     * que decir que no, no devolver un archivo con la cabecera y ninguna fila.
     * Lo segundo se lee como «ese grupo esta vacio», que es una respuesta falsa
     * a una pregunta que no correspondia hacer.
     *
     * La regla es la misma de siempre: direccion cualquiera, quien dicta solo
     * las suyas.
     */
    private function grupoPedido(Request $request, Perfil $perfil): ?Grupo
    {
        $id = $request->query('grupo');

        if (! $id) {
            return null;
        }

        $grupo = Grupo::with('promotoria')->find($id);

        abort_if($grupo === null, 404);
        abort_unless(Permisos::puedeGestionarPromotoria($perfil, $grupo->promotoria), 404);

        return $grupo;
    }

    /**
     * La promotoria que se pide, con la misma puerta.
     *
     * Si ya vino un grupo, manda el suyo: pedir a la vez el grupo A y la
     * promotoria B es una peticion incoherente, y quedarse con la promotoria del
     * grupo es la unica lectura que no devuelve algo vacio sin explicacion.
     */
    private function promotoriaPedida(Request $request, Perfil $perfil, ?Grupo $grupo): ?Promotoria
    {
        if ($grupo !== null) {
            return $grupo->promotoria;
        }

        $id = $request->query('promotoria');

        if (! $id) {
            return null;
        }

        $promotoria = Promotoria::find($id);

        abort_if($promotoria === null, 404);
        abort_unless(Permisos::puedeGestionarPromotoria($perfil, $promotoria), 404);

        return $promotoria;
    }

    /**
     * Como se llama el archivo.
     *
     * Lleva el nombre de la promotoria y del grupo porque quien dicta se baja
     * tres listas seguidas, una por horario, y con el mismo nombre las tres el
     * navegador las guarda como «(1)» y «(2)»: para saber cual es cual hay que
     * abrirlas. Con el nombre dentro se distinguen en la carpeta de descargas.
     */
    private function nombreDelArchivo(?Promotoria $promotoria, ?Grupo $grupo): string
    {
        // Sin acotar sigue siendo «por grupo», que es como esta organizado.
        if ($promotoria === null) {
            return 'estudiantes-por-grupo';
        }

        $partes = ['estudiantes', $promotoria->nombre];

        if ($grupo !== null) {
            $partes[] = $grupo->nombre;
        }

        return Str::slug(implode(' ', $partes)) ?: 'estudiantes-por-grupo';
    }

    /**
     * @return Generator<int, list<string>>
     */
    private function filasDeEstudiantes($consulta): Generator
    {
        // Por tandas y no de una: el informe puede ser de cientos de filas con
        // cuatro relaciones cargadas cada una, y en hosting compartido traerlas
        // todas a memoria a la vez es justo lo que no conviene.
        //
        // `lazy` y NO `lazyById`. Este informe va ordenado por departamento,
        // promotoria y nombre, y `lazyById` pagina preguntando por el id mayor
        // que el ultimo devuelto: con otro orden, «el ultimo» es un id
        // cualquiera, las tandas se solapan y las filas se repiten. Costo 4961
        // filas para 302 matriculas, y no se veia en las pruebas porque con
        // pocos datos todo cabe en la primera tanda.
        foreach ($consulta->lazy(self::POR_TANDA) as $matricula) {
            $datos = $matricula->estudiante->datosEstudiante;
            $acudiente = $datos?->acudiente;

            /*
             * UNA FILA POR GRUPO, no por matricula, desde el 10/09/2026.
             *
             * Lo decide para que es este informe, y esta escrito arriba: «la
             * lista que se lleva quien dicta para pasar asistencia en papel».
             * Quien va al Grupo A el lunes y al B el miercoles tiene que salir
             * en las DOS listas, o el profesor del miercoles va a clase con un
             * papel al que le falta gente. Es el mismo criterio que
             * `Clase::matriculasAPasar()`.
             *
             * Y la matricula SIN grupo sigue dando su fila —el `?: [null]`—
             * porque «Sin grupo» es informacion que direccion viene a buscar
             * aqui: es la lista de quien falta por repartir.
             */
            $grupos = $matricula->grupos->all() ?: [null];

            foreach ($grupos as $grupo) {
                yield array_map(Csv::celda(...), [
                    $matricula->promotoria->area->nombre,
                    $matricula->promotoria->nombre,
                    $grupo?->nombre ?? 'Sin grupo',
                    $grupo?->nivel_display,
                    $grupo?->horario,
                    $grupo?->salon,
                    $matricula->estudiante->nombre_completo,
                    $matricula->estudiante->edad,
                    $matricula->estudiante->telefono,
                    $acudiente?->nombre,
                    $acudiente?->telefono,
                    Matricula::ESTADOS[$matricula->estado] ?? $matricula->estado,
                ]);
            }
        }
    }

    /**
     * El informe completo de la institucion. SOLO ADMINISTRADOR.
     *
     * Lleva la encuesta demografica con NOMBRE Y APELLIDO, y eso invierte a
     * proposito lo que el sistema garantizaba hasta ahora: la encuesta se recogia
     * agregada y anonima, y `EstadisticasController` explica por que —una
     * encuesta con nombre en un tablero se convierte en un marcador, y la vez
     * siguiente la gente contesta pensando en quien va a leerla.
     *
     * Se hace porque la institucion lo necesita para reportar a quien la
     * financia, y se acota todo lo que se puede: la descarga es del
     * administrador y de nadie mas —la misma puerta que la copia del documento
     * de identidad—, y la pantalla avisa de lo que contiene antes de entregarlo.
     * Lo que el sistema NO puede controlar es que despues el archivo se reenvie,
     * y por eso la decision tenia que ser explicita y de quien manda, no de quien
     * programa.
     *
     * Una fila por persona y promotoria: quien cursa dos sale en dos filas, con
     * sus datos repetidos. Es la forma correcta para una hoja de calculo —permite
     * tablas dinamicas— y la unica que puede llevar el nivel y el tiempo, que son
     * datos DE la promotoria y no de la persona. Quien no tiene ninguna sale en
     * una sola fila con esas columnas vacias.
     */
    public function institucion(): StreamedResponse
    {
        // LOS PAPELES SE CONSULTAN UNA SOLA VEZ y se pasan a las dos partes.
        // La lista es dinamica —cada entidad pide los suyos y los cambia cuando
        // quiere—, asi que preguntarla dos veces abre la puerta a que la
        // cabecera y las filas salgan de listas distintas si alguien crea o
        // desactiva un papel entre las dos consultas. Cuadrar cabecera y filas
        // es lo que vigila `InformeCuadradoTest`, y ya se torcio una vez por
        // contar huecos a mano.
        //
        // Solo los ACTIVOS: un papel que se dejo de pedir no es una casilla que
        // nadie ha rellenado, es una pregunta que ya no se hace.
        $papeles = DocumentoRequerido::activos()->ordenados()->get();

        return Csv::descargar('institucion-completo', [
            'Rol',
            'Nombre completo',
            'Usuario',
            'Edad',
            'Teléfono',
            'Correo',
            'Departamento',
            'Promotoría',
            'Grupo',
            'Nivel',
            'Estado',
            'Periodos en la promotoría',
            'Desde',
            'Género',
            'Barrio',
            'Estrato',
            'Nivel educativo',
            'Ocupación',
            'Zona',
            'Afiliación en salud',
            'Grupo étnico',
            'Discapacidad',
            'Víctima del conflicto',
            'Autoriza tratamiento de datos',
            'Fecha de autorización',
            // Una columna por papel pedido, al final para no mover las que ya
            // existian: quien tenga una hoja hecha sobre este informe la
            // conserva. «Entregó:» delante porque un papel puede llamarse como
            // una columna de arriba —nada impide llamarlo «Teléfono»— y dos
            // cabeceras iguales rompen cualquier tabla dinamica.
            ...$papeles->map(fn (DocumentoRequerido $p) => 'Entregó: '.$p->nombre)->all(),
        ], $this->filasDeInstitucion($papeles));
    }

    /**
     * La gente SIN matricula: cursos, talleres, grupos de proyeccion y
     * programas externos (25/09/2026). Una fila por persona y actividad, con
     * TODO el historico.
     *
     * APARTE DE LOS OTROS DOS, por decision del usuario: hay hojas de calculo
     * armadas sobre aquellos, y meter aqui estas filas las llenaria de columnas
     * vacias que sus tablas dinamicas empezarian a contar.
     *
     * SOLO EL ADMINISTRADOR (la ruta lo dice): lleva nombres de menores, y los
     * de un programa externo son de OTRA institucion. Se ofrecio que cada
     * responsable bajara lo suyo y se eligio lo cerrado.
     *
     * LA EDAD NO SE CALCULA. Van la fecha de nacimiento (quien entro por el
     * enlace), la edad declarada (programa externo) y el dia del registro, cada
     * una en su columna: la edad escrita a mano ENVEJECE, y solo junto a
     * «Registrado el» se puede corregir. Deducir una de otra aqui seria
     * inventar un dato que nadie dio.
     *
     * El porcentaje sigue la regla del certificado (`AsistenciaDeActividad`):
     * sobre las sesiones CON LISTA TOMADA de la actividad, y truncado. Quien
     * entro a mitad de camino arrastra las sesiones de antes; es lo mismo que
     * pasa con el certificado y por la misma razon.
     *
     * Consultas fijas: dos agregados y la lista recorrida con `lazy()`.
     */
    public function actividades(): StreamedResponse
    {
        $sesionesConLista = InstitucionActual::tabla('asistencias_actividad as x')
            ->join('sesiones_actividad as s', 's.id', '=', 'x.sesion_id')
            ->groupBy('s.actividad_id')
            ->selectRaw('s.actividad_id, COUNT(DISTINCT s.id) as total')
            ->pluck('total', 'actividad_id');

        $asistio = InstitucionActual::tabla('asistencias_actividad')
            ->where('estado', AsistenciaActividad::ASISTIO)
            ->groupBy('inscrito_id')
            ->selectRaw('inscrito_id, COUNT(*) as total')
            ->pluck('total', 'inscrito_id');

        $filas = InstitucionActual::tabla('inscritos_actividad as i')
            ->join('actividades as a', 'a.id', '=', 'i.actividad_id')
            ->leftJoin('perfiles as r', 'r.id', '=', 'a.responsable_id')
            ->leftJoin('instituciones_externas as e', 'e.id', '=', 'a.institucion_externa_id')
            ->leftJoin('perfiles as fu', 'fu.id', '=', 'e.perfil_id')
            ->leftJoin('periodos as p', 'p.id', '=', 'a.periodo_id')
            ->select([
                'i.id', 'i.actividad_id', 'i.nombre_completo', 'i.documento', 'i.telefono',
                'i.correo', 'i.fecha_nacimiento', 'i.edad', 'i.perfil_id', 'i.origen', 'i.created_at',
                'a.tipo', 'a.nombre as actividad', 'r.nombre_completo as responsable',
                'e.nombre as institucion', 'e.telefono as telefono_institucion', 'fu.nombre_completo as funcionario', 'p.nombre as periodo',
            ])
            ->orderBy('a.tipo')
            ->orderBy('a.nombre')
            ->orderBy('i.nombre_completo')
            ->orderBy('i.id');

        $origen = [
            InscritoActividad::ENLACE => 'Por el enlace',
            InscritoActividad::EN_SESION => 'Añadido en clase',
            InscritoActividad::LISTA => 'Lista del programa',
        ];

        $recorrer = function () use ($filas, $sesionesConLista, $asistio, $origen): Generator {
            foreach ($filas->lazy(self::POR_TANDA) as $f) {
                $sesiones = (int) ($sesionesConLista[$f->actividad_id] ?? 0);
                $fue = (int) ($asistio[$f->id] ?? 0);

                yield [
                    Actividad::ETIQUETA_TIPO[$f->tipo] ?? $f->tipo,
                    $f->actividad,
                    $f->responsable,
                    $f->institucion,
                    // El de la ENTIDAD, no el del funcionario: es el contacto que
                    // sigue sirviendo cuando esa persona cambia de trabajo.
                    $f->telefono_institucion,
                    // Quien da fe de las clases desde la cuenta de la institucion.
                    $f->funcionario,
                    $f->periodo ?? 'Sin periodo',
                    $f->nombre_completo,
                    $origen[$f->origen] ?? $f->origen,
                    $f->documento,
                    $f->telefono,
                    $f->correo,
                    $f->fecha_nacimiento ? substr((string) $f->fecha_nacimiento, 0, 10) : null,
                    $f->edad,
                    substr((string) $f->created_at, 0, 10),
                    // Solo se reconoce por el documento: quien no lo dio sale
                    // «No» aunque este matriculado. Por eso la cabecera dice
                    // «reconocido» y no «matriculado».
                    $f->perfil_id ? 'Sí' : 'No',
                    $sesiones,
                    $fue,
                    $sesiones > 0 ? ((int) floor($fue * 100 / $sesiones)).'%' : null,
                ];
            }
        };

        return Csv::descargar('actividades-sin-matricula', [
            'Tipo',
            'Actividad',
            'Responsable',
            'Institución',
            'Teléfono de la institución',
            'Funcionario de la institución',
            'Periodo',
            'Nombre completo',
            'Cómo entró',
            'Documento',
            'Teléfono',
            'Correo',
            'Fecha de nacimiento',
            'Edad declarada',
            'Registrado el',
            'Reconocido como estudiante',
            'Sesiones con lista',
            'Asistió',
            'Asistencia',
        ], $recorrer());
    }

    /**
     * @param  Collection<int, DocumentoRequerido>  $papeles
     * @return Generator<int, list<string>>
     */
    private function filasDeInstitucion(Collection $papeles): Generator
    {
        $trayectorias = $this->trayectorias();
        $periodo = Periodo::enCurso();

        $consulta = Perfil::query()
            ->with([
                'user',
                'encuesta',
                // Solo las del periodo EN CURSO. Una matricula de un periodo que
                // ya termino sigue guardada como 'activa' —el estado no cambia,
                // lo que cambia es el calendario—, asi que sin este filtro quien
                // lleva tres semestres en Violin salia en tres filas identicas.
                // El pasado no se pierde: lo cuentan «Periodos en la promotoría»
                // y «Desde», que es justo para lo que estan.
                'matriculas' => fn ($q) => $q
                    ->whereIn('estado', Matricula::ESTADOS_INSCRITO)
                    ->when($periodo, fn ($sub) => $sub->where('periodo_id', $periodo->id))
                    ->when($periodo === null, fn ($sub) => $sub->whereRaw('1 = 0')),
                'matriculas.promotoria.area',
                'matriculas.grupos.sesiones',
                'matriculas.periodo',
                // Los papeles entregados. Se traen aqui y no se preguntan fila a
                // fila: son 800 personas y esto lo descarga alguien esperando
                // delante de la pantalla.
                'datosEstudiante.documentos',
            ])
            ->orderBy('rol')
            ->orderBy('nombre_completo');

        // `lazy` y no `lazyById`, por lo mismo que arriba: va ordenado por rol y
        // nombre, y paginar por id con otro orden repite filas.
        foreach ($consulta->lazy(self::POR_TANDA) as $perfil) {
            $comunes = $this->columnasDePersona($perfil);
            $encuesta = [
                ...$this->columnasDeEncuesta($perfil->encuesta),
                ...$this->columnasDeDocumentos($perfil, $papeles),
            ];

            if ($perfil->matriculas->isEmpty()) {
                yield array_map(Csv::celda(...), [
                    ...$comunes,
                    ...$this->columnasDeMatricula(null, $trayectorias),
                    ...$encuesta,
                ]);

                continue;
            }

            foreach ($perfil->matriculas as $matricula) {
                // Anotada porque la relacion no lleva tipo y PHPStan la ve como
                // un `Model` cualquiera. Antes esto se tapaba con cuatro
                // patrones en la linea base —uno por propiedad tocada—; al
                // pasar las columnas a su propio metodo hace falta el tipo de
                // verdad, y los cuatro patrones se van.
                /** @var Matricula $matricula */
                yield array_map(Csv::celda(...), [
                    ...$comunes,
                    ...$this->columnasDeMatricula($matricula, $trayectorias),
                    ...$encuesta,
                ]);
            }
        }
    }

    /**
     * Rol, identidad y contacto de una persona, sea cual sea su rol.
     *
     * La edad sale VACIA para el personal, y esa es la unica columna que
     * distingue una fila de otra. Es un dato del estudiante --de ahi salen la
     * minoria de edad, el acudiente obligatorio y el nivel del grupo-- y en un
     * profesor no lo usa nadie. Aqui pesa mas que en la ficha: esto es un
     * archivo que sale del sistema y ya no vuelve.
     *
     * La columna no se quita de la cabecera porque para el estudiante sigue
     * haciendo falta, y una hoja con las columnas cambiando segun la fila no la
     * abre ningun programa.
     *
     * @return list<string|int|null>
     */
    /**
     * Una columna por papel pedido: «Sí» si lo entrego, «No» si no.
     *
     * PARA EL PERSONAL VAN VACIAS, no «No». A un profesor no se le piden
     * papeles —cuelgan de `datos_estudiante`, que solo tienen los estudiantes—
     * asi que un «No» ahi seria una falta que no existe, y quien filtre la hoja
     * por «No» se encontraria a toda la plantilla dentro de la lista de a quien
     * llamar. Es el mismo trato que ya recibe la edad, y por la misma razon.
     *
     * Se mira `archivo !== ''` y no la existencia de la fila: una entrega puede
     * quedar con la ruta vacia y eso no es haber entregado.
     *
     * @param  Collection<int, DocumentoRequerido>  $papeles
     * @return list<string|null>
     */
    private function columnasDeDocumentos(Perfil $perfil, Collection $papeles): array
    {
        $datos = $perfil->datosEstudiante;

        if ($datos === null) {
            return array_fill(0, $papeles->count(), null);
        }

        $entregados = $datos->documentos
            ->filter(fn (DocumentoEstudiante $d) => $d->archivo !== '')
            ->pluck('requerido_id')
            ->all();

        return $papeles
            ->map(fn (DocumentoRequerido $p) => in_array($p->id, $entregados, true) ? 'Sí' : 'No')
            ->values()
            ->all();
    }

    /**
     * Las siete columnas que describen UNA matricula, o siete vacias si no hay.
     *
     * EL FALLO QUE ESTO CIERRA, del 04/09/2026: las dos ramas escribian su
     * propia lista, y la de «sin matricula» tenia SEIS huecos donde la otra
     * ponia siete columnas. Con eso, todo lo que va de «Departamento» en
     * adelante se corria una posicion a la izquierda y la encuesta caia bajo
     * cabeceras que no eran las suyas.
     *
     * No era un caso raro: le pasaba a TODO EL PERSONAL —un profesor no tiene
     * matriculas nunca— y a cualquier estudiante sin matricula activa en el
     * periodo en curso. O sea que el informe salia torcido siempre, y la parte
     * torcida era justo la que se reporta a quien financia.
     *
     * Se arregla con una sola lista y no contando huecos a mano: mientras las
     * dos ramas pasen por aqui no pueden descuadrarse entre si. Que ademas
     * cuadre con la CABECERA lo vigila `InformeCuadradoTest`, que compara los
     * anchos fila a fila.
     *
     * @param  array<string, array{periodos: int, desde: string}>  $trayectorias
     * @return list<string|int|null>
     */
    private function columnasDeMatricula(?Matricula $matricula, array $trayectorias): array
    {
        if ($matricula === null) {
            return array_fill(0, 7, '');
        }

        $clave = "{$matricula->estudiante_id}:{$matricula->promotoria_id}";
        $trayectoria = $trayectorias[$clave] ?? ['periodos' => 0, 'desde' => ''];

        // Por lo mismo que arriba: la relacion no lleva tipo, asi que el area
        // llega como un `Model` y su nombre no existe para el analizador.
        /** @var Promotoria $promotoria */
        $promotoria = $matricula->promotoria;
        /** @var Area $area */
        $area = $promotoria->area;

        return [
            $area->nombre,
            $promotoria->nombre,
            // Aqui la fila es una PERSONA y no una clase, asi que sus grupos se
            // juntan en la celda en vez de multiplicar filas. Es lo contrario
            // que en el informe operativo de arriba, y a proposito: alli la fila
            // ES la lista de un salon.
            $matricula->grupos->pluck('nombre')->implode(' · ') ?: 'Sin grupo',
            $matricula->grupos->pluck('nivel_display')->implode(' · '),
            Matricula::ESTADOS[$matricula->estado] ?? $matricula->estado,
            $trayectoria['periodos'],
            $trayectoria['desde'],
        ];
    }

    private function columnasDePersona(Perfil $perfil): array
    {
        return [
            $perfil->rol === '' ? 'Pendiente de rol' : (Perfil::ROLES[$perfil->rol] ?? $perfil->rol),
            $perfil->nombre_completo,
            $perfil->user->username,
            $perfil->esPersonal() ? null : $perfil->edad,
            $perfil->telefono,
            $perfil->user->email,
        ];
    }

    /**
     * Las respuestas de la encuesta, ya traducidas a su etiqueta.
     *
     * Se guardan como codigos (`f`, `primaria_inc`) porque asi los compara el
     * sistema, pero en una hoja de calculo eso no lo lee nadie: quien abre el
     * informe quiere «Femenino», no «f». Sin encuesta contestada, todas vacias.
     *
     * @return list<string|int|null>
     */
    private function columnasDeEncuesta(?EncuestaDemografica $encuesta): array
    {
        if ($encuesta === null) {
            return array_fill(0, 12, '');
        }

        $etiqueta = fn (array $opciones, $valor) => $valor === null || $valor === ''
            ? ''
            : ($opciones[$valor] ?? $valor);

        return [
            $etiqueta(EncuestaDemografica::GENEROS, $encuesta->genero),
            $encuesta->barrio,
            $etiqueta(EncuestaDemografica::ESTRATOS, $encuesta->estrato),
            $etiqueta(EncuestaDemografica::NIVELES_EDUCATIVOS, $encuesta->nivel_educativo),
            $etiqueta(EncuestaDemografica::OCUPACIONES, $encuesta->ocupacion),
            $etiqueta(EncuestaDemografica::ZONAS, $encuesta->zona),
            $etiqueta(EncuestaDemografica::AFILIACIONES_SALUD, $encuesta->afiliacion_salud),
            $etiqueta(EncuestaDemografica::GRUPOS_ETNICOS, $encuesta->grupo_etnico),
            $etiqueta(EncuestaDemografica::DISCAPACIDADES, $encuesta->discapacidad),
            $etiqueta(EncuestaDemografica::VICTIMAS_CONFLICTO, $encuesta->victima_conflicto_armado),
            $encuesta->autoriza_tratamiento_datos ? 'Sí' : 'No',
            $encuesta->fecha_autorizacion?->format('d/m/Y'),
        ];
    }

    /**
     * Cuanto lleva cada quien en cada promotoria, en UNA consulta.
     *
     * «Tiempo» se mide en PERIODOS cursados y no en meses, y esa es la unidad en
     * la que funciona la casa: alguien lleva «tres semestres en Guitarra», no
     * «catorce meses». Cuentan solo los periodos con matricula ACTIVA —lo que
     * de verdad curso—, asi que una solicitud que nadie confirmo no suma.
     *
     * Va agregado y no matricula por matricula porque si no serian dos consultas
     * por fila del informe, y el informe tiene una fila por persona y promotoria.
     *
     * @return array<string, array{periodos: int, desde: string}>
     */
    private function trayectorias(): array
    {
        $filas = Matricula::query()
            ->where('matriculas.estado', Matricula::ACTIVA)
            ->join('periodos', 'periodos.id', '=', 'matriculas.periodo_id')
            ->groupBy('matriculas.estudiante_id', 'matriculas.promotoria_id')
            ->selectRaw('
                matriculas.estudiante_id,
                matriculas.promotoria_id,
                COUNT(DISTINCT matriculas.periodo_id) as periodos,
                MIN(periodos.fecha_inicio) as primera
            ')
            ->get();

        $nombres = Periodo::pluck('nombre', 'fecha_inicio');
        $mapa = [];

        foreach ($filas as $fila) {
            $mapa["{$fila->estudiante_id}:{$fila->promotoria_id}"] = [
                'periodos' => (int) $fila->periodos,
                'desde' => $nombres[$fila->primera] ?? '',
            ];
        }

        return $mapa;
    }
}
