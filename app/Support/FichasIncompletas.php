<?php

namespace App\Support;

use App\Models\ConfiguracionInstitucion;
use App\Models\DocumentoRequerido;
use App\Models\EncuestaDemografica;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A QUIEN LE FALTA ALGO: la tercera bandeja de Alertas.
 *
 * Las otras dos —clase no dictada y posible abandono— miran lo que PASO. Esta
 * mira lo que esta a medias: fichas sin terminar, papeles sin subir, matriculas
 * esperando el visto bueno de alguien. Ocho motivos, todos calculados al abrir
 * y ninguno guardado, por la misma razon que las otras dos: lo que se guarda
 * queda viejo en cuanto la persona sube el papel.
 *
 * ─── EL VOLUMEN ES EL PROBLEMA DE DISENO, y esta medido ────────────────────
 *
 * En produccion el 07/09/2026, sobre 839 personas con rol: 804 sin el
 * consentimiento firmado, 775 sin empezar la encuesta, 167 con matricula por
 * aprobar, 123 sin grupo. Todo lo demas en CERO — ni un menor sin acudiente, ni
 * un telefono vacio.
 *
 * O sea que esto no es una lista de excepciones: son ~810 personas, casi la
 * institucion entera. Y no es un fallo de los datos. El consentimiento nacio
 * obligatorio el 06/09 y la encuesta se empezo a pedir el 05/09, asi que los
 * dos numeros grandes dicen «esto es nuevo y todavia no lo ha hecho nadie», no
 * «aqui hay 800 problemas».
 *
 * De ahi salen las tres decisiones de forma, que no son cosmeticas:
 *
 * 1. Se entra por un BOTON a su propia pantalla, no desplegando una seccion.
 *    810 filas dentro de la bandeja la convertirian en un muro — es lo que ya
 *    paso con las 596 clases no dictadas.
 * 2. La lista se PAGINA y se FILTRA por motivo y por promotoria. Sin el filtro,
 *    quien busca al unico menor sin acudiente tendria que recorrer 804
 *    consentimientos.
 * 3. El contador del boton cuenta PERSONAS, no motivos. Una persona a la que le
 *    faltan tres cosas es una ficha que atender, no tres.
 *
 * ─── NO SE HIDRATA NI UN MODELO, y esto costo una vez ──────────────────────
 *
 * La primera version recorria `Perfil::with(["datosEstudiante.documentos",
 * "datosEstudiante.acudiente", "encuesta"])->get()`. Es la forma comoda y
 * REVENTO la memoria: 841 perfiles con tres relaciones cada uno, o sea unos
 * 1.650 modelos hidratados en cada visita a Alertas para leerles cuatro
 * columnas. Se vio porque la suite entera empezo a morir con «Allowed memory
 * size exhausted» en las pruebas de imagenes, que son las que corren detras.
 *
 * Y conviene decir lo que NO fue, porque la primera version de este comentario
 * lo dijo mal: no fue que produccion tenga la memoria contada. Produccion tiene
 * 2048M por peticion (leido del panel de Hostinger el 07/09/2026) y no se
 * habria caido. Lo que se cayo fue la suite en la maquina de desarrollo, que
 * tiene 128M. El arreglo sigue siendo el bueno igualmente: hidratar 1.650
 * modelos para pintar una tabla es caro en tiempo y en memoria aunque quepa.
 *
 * Asi que aqui NO hay modelos: seis consultas fijas que devuelven columnas
 * sueltas, y el cruce en memoria sobre filas planas. Cada fila lleva ya todo lo
 * que la pantalla pinta —nombre, telefono, rol, el acudiente— para que la vista
 * tampoco tenga que ir a buscar nada. Si alguien vuelve a meter un `with()`
 * aqui, la pantalla seguira funcionando en desarrollo y se caera con los datos
 * de verdad.
 *
 * @phpstan-type FichaIncompleta array{
 *     id: int,
 *     nombre: string,
 *     rol: string,
 *     rol_display: string,
 *     telefono: string,
 *     es_estudiante: bool,
 *     es_menor: bool,
 *     acudiente: array{nombre: string, telefono: string}|null,
 *     motivos: list<string>,
 *     detalles: list<string>,
 *     promotorias: list<array{id: int, nombre: string}>,
 * }
 */
class FichasIncompletas
{
    /**
     * Los ocho motivos, con lo que se le ensena a quien mira.
     *
     * El ORDEN es el de los filtros y el del desglose, y esta puesto por lo que
     * cuesta arreglarlo: primero lo que se resuelve desde el Panel en un clic
     * —aprobar, asignar grupo—, luego lo que hay que pedirle a la persona, y al
     * final lo que exige llamar por telefono.
     */
    public const MOTIVOS = [
        'aprobacion' => 'Matrícula por aprobar',
        'grupo' => 'Sin grupo asignado',
        'rol' => 'Sin rol asignado',
        'papel' => 'Falta un documento obligatorio',
        'encuesta' => 'Encuesta demográfica sin contestar',
        'acudiente' => 'Menor sin acudiente o sin su teléfono',
        'datos' => 'Faltan datos de la ficha',
        'formato' => 'Un dato guardado ya no es válido',
    ];

    /**
     * Todo lo que hay que atender, una entrada por persona.
     *
     * La forma va escrita entera y no como `array<string, mixed>` porque es el
     * contrato con la plantilla: la vista no tiene modelos de los que tirar, asi
     * que lo que no este en esta lista no lo puede pintar.
     *
     * @return list<FichaIncompleta>
     */
    public static function todas(?Perfil $quienMira = null): array
    {
        $config = ConfiguracionInstitucion::actual();
        $periodo = Periodo::enCurso();

        // ACOTADO A QUIEN MIRA desde el 12/09/2026: un director solo ve las
        // fichas de la gente de sus departamentos. Faltaba — las otras dos
        // bandejas de esta pantalla se acotaron ese dia y esta se quedo
        // enseñando la institucion entera, que lo reporto el usuario.
        //
        // «Su gente» es la misma definicion que ya usa el filtro de promotoria
        // de esta pantalla: los MATRICULADOS en sus promotorias y quien las
        // DICTA. Por eso el recorte va sobre el mapa de vinculos y no sobre una
        // consulta nueva: con dos definiciones, el filtro y la lista acabarian
        // diciendo cosas distintas.
        $vinculos = self::promotoriasDeCadaPersona($periodo, $quienMira);
        $acotado = $quienMira !== null && Permisos::areasVisiblesPara($quienMira) !== null;

        $personas = self::personas($acotado ? array_keys($vinculos) : null);
        $porAprobar = self::matriculasPorAprobar($periodo);
        $sinGrupo = self::matriculasSinGrupo($periodo);
        $obligatorios = DocumentoRequerido::activos()->where('obligatorio', true)->get();
        $entregados = self::entregasPorFicha($obligatorios->pluck('id')->all());
        $encuestas = $config->recordar_encuesta ? self::encuestas() : [];

        $filas = [];

        foreach ($personas as $p) {
            $id = (int) $p->id;
            $motivos = [];
            $detalles = [];

            // 1. SIN ROL. Va primero porque bloquea todo lo demas: quien no
            //    tiene rol no entra a ninguna pantalla, asi que no puede subir
            //    un papel ni contestar la encuesta aunque quisiera.
            if ((string) $p->rol === '') {
                $motivos[] = 'rol';
                $detalles[] = 'Se registró y nadie le ha asignado rol todavía';
            }

            if (isset($porAprobar[$id])) {
                $motivos[] = 'aprobacion';
                $detalles[] = self::frase('Matrícula por aprobar', $porAprobar[$id]);
            }

            if (isset($sinGrupo[$id])) {
                $motivos[] = 'grupo';
                $detalles[] = self::frase('Sin grupo asignado', $sinGrupo[$id]);
            }

            foreach (self::loQueLeFalta($p, $obligatorios, $entregados, $encuestas, $config) as [$motivo, $detalle]) {
                $motivos[] = $motivo;
                $detalles[] = $detalle;
            }

            if ($motivos === []) {
                continue;
            }

            $filas[] = [
                'id' => $id,
                'nombre' => (string) $p->nombre_completo,
                'rol' => (string) $p->rol,
                'rol_display' => Perfil::ROLES[$p->rol] ?? 'Sin rol asignado',
                'telefono' => (string) $p->telefono,
                'es_estudiante' => (string) $p->rol === 'estudiante',
                'es_menor' => self::esMenor($p->fecha_nacimiento),
                'acudiente' => $p->acudiente_nombre === null ? null : [
                    'nombre' => (string) $p->acudiente_nombre,
                    'telefono' => (string) $p->acudiente_telefono,
                ],
                'motivos' => $motivos,
                'detalles' => $detalles,
                'promotorias' => $vinculos[$id] ?? [],
            ];
        }

        return $filas;
    }

    /**
     * Una sola consulta con TODO lo que la pantalla pinta de cada persona.
     *
     * Los dos `leftJoin` son la razon por la que no hace falta ni un `with()`:
     * el documento y el acudiente cuelgan de `datos_estudiante`, y traerlos aqui
     * como columnas cuesta lo mismo que no traerlos. Con relaciones de Eloquent
     * serian 841 modelos y sus 807 hijos, cargados enteros.
     *
     * @return Collection<int, \stdClass>
     */
    /**
     * @param  list<int>|null  $soloEstos  Ids de perfil, o null para todos.
     * @return Collection<int, \stdClass>
     */
    private static function personas(?array $soloEstos = null): Collection
    {
        // OJO CON LA LISTA VACIA: un director sin nadie en sus departamentos da
        // `[]` y este `whereIn` no devuelve a nadie, que es lo correcto. La
        // tentacion al leerlo es «si esta vacia, no filtres»; eso le devolveria
        // la institucion entera.
        return InstitucionActual::tabla('perfiles')
            ->when($soloEstos !== null, fn ($q) => $q->whereIn('perfiles.id', $soloEstos ?? []))
            // A quien se le suprimieron los datos le falta TODO, a proposito:
            // pedirselos seria volver a recogerlos.
            ->whereNull('perfiles.suprimido_en')
            ->leftJoin('datos_estudiante', 'datos_estudiante.perfil_id', '=', 'perfiles.id')
            ->leftJoin('acudientes', 'acudientes.id', '=', 'datos_estudiante.acudiente_id')
            ->select(
                'perfiles.id',
                'perfiles.nombre_completo',
                'perfiles.rol',
                'perfiles.telefono',
                'perfiles.fecha_nacimiento',
                'datos_estudiante.id as ficha_id',
                'datos_estudiante.documento_identidad',
                'acudientes.nombre as acudiente_nombre',
                'acudientes.telefono as acudiente_telefono',
            )
            ->orderBy('perfiles.nombre_completo')
            ->orderBy('perfiles.id')
            ->get();
    }

    /**
     * Los cinco motivos que salen de la propia ficha de la persona.
     *
     * @param  Collection<int, DocumentoRequerido>  $obligatorios
     * @param  array<int, list<int>>  $entregados
     * @param  array<int, list<string>>  $encuestas
     * @return list<array{string, string}>
     */
    private static function loQueLeFalta(
        object $p,
        Collection $obligatorios,
        array $entregados,
        array $encuestas,
        ConfiguracionInstitucion $config,
    ): array {
        $salida = [];
        $esEstudiante = (string) $p->rol === 'estudiante';
        $ficha = $p->ficha_id === null ? null : (int) $p->ficha_id;

        // 2. PAPELES. Solo los OBLIGATORIOS cuentan como algo que atender, que
        //    es el mismo criterio que ya usa el filtro de Gestion → Usuarios.
        //    Un requerido no obligatorio es una ranura ofrecida, no una deuda;
        //    contarlo aqui pondria a media institucion en la bandeja por no
        //    haber subido algo que nadie le exigio.
        if ($esEstudiante && $obligatorios->isNotEmpty()) {
            // Sin ficha de estudiante no le faltan «algunos» papeles: le faltan
            // TODOS, y sin esta rama se quedaria invisible justo en la lista que
            // existe para no dejar a nadie fuera.
            $tiene = $ficha === null ? [] : ($entregados[$ficha] ?? []);
            $faltan = $obligatorios
                ->reject(fn (DocumentoRequerido $d) => in_array($d->id, $tiene, true))
                ->pluck('nombre')
                ->all();

            if ($faltan !== []) {
                $salida[] = ['papel', 'Falta subir: '.implode(', ', $faltan)];
            }
        }

        // 3. LA ENCUESTA, y solo si la institucion la esta pidiendo. El mismo
        //    interruptor que decide si se le recuerda al entrar decide si su
        //    ausencia es algo que atender: pedirsela por un lado y no contarla
        //    por el otro dejaria la bandeja diciendo que no falta nada mientras
        //    la pantalla de inicio le insiste a 775 personas.
        if ($config->recordar_encuesta) {
            if (! array_key_exists((int) $p->id, $encuestas)) {
                $salida[] = ['encuesta', 'No ha empezado la encuesta demográfica'];
            } elseif ($encuestas[(int) $p->id] !== []) {
                $salida[] = ['encuesta', 'Encuesta a medias, le falta: '
                    .implode(', ', $encuestas[(int) $p->id])];
            }
        }

        // 4. EL ACUDIENTE DE UN MENOR. Las dos mitades de la regla que ya vive
        //    en `DatosEstudiante::validar()`: tener acudiente, y que ese
        //    acudiente tenga telefono. La segunda importa tanto como la primera
        //    —el acudiente se registra para poder LLAMARLO— y sin ella la
        //    bandeja daria por buena una ficha que el formulario rechaza.
        if ($esEstudiante && self::esMenor($p->fecha_nacimiento)) {
            if ($p->acudiente_nombre === null) {
                $salida[] = ['acudiente', 'Es menor de edad y no tiene acudiente registrado'];
            } elseif (trim((string) $p->acudiente_telefono) === '') {
                $salida[] = ['acudiente', "El acudiente ({$p->acudiente_nombre}) no tiene teléfono"];
            }
        }

        // 5. CAMPOS EN BLANCO.
        $vacios = [];

        if (trim((string) $p->telefono) === '') {
            $vacios[] = 'teléfono';
        }

        if ($p->fecha_nacimiento === null) {
            $vacios[] = 'fecha de nacimiento';
        }

        if ($esEstudiante && $ficha === null) {
            $vacios[] = 'la ficha de estudiante entera';
        } elseif ($esEstudiante && trim((string) $p->documento_identidad) === '') {
            $vacios[] = 'documento de identidad';
        }

        if ($vacios !== []) {
            $salida[] = ['datos', 'Sin '.implode(', sin ', $vacios)];
        }

        // 6. LO QUE ESTA GUARDADO PERO YA NO PASA. Desde el 07/09 el telefono
        //    son diez digitos y el documento solo numeros (ver `Reglas`), y hay
        //    filas anteriores que no cumplen. No es una falta de la persona: es
        //    que su ficha NO SE PUEDE GUARDAR hasta corregirla, ni siquiera por
        //    un administrador que solo venia a cambiarle el rol. Por eso esta
        //    aqui y no solo en `datos:revisar`.
        $malos = [];

        if (self::noCumple((string) $p->telefono, Reglas::CELULAR)) {
            $malos[] = 'el teléfono';
        }

        if (self::noCumple((string) $p->documento_identidad, Reglas::DOCUMENTO)) {
            $malos[] = 'el documento';
        }

        if (self::noCumple((string) $p->acudiente_telefono, Reglas::CELULAR)) {
            $malos[] = 'el teléfono del acudiente';
        }

        if ($malos !== []) {
            $salida[] = ['formato', 'Hay que corregir '.implode(' y ', $malos)
                .': su ficha no se puede guardar así'];
        }

        return $salida;
    }

    /**
     * Vacio NO cuenta aqui: eso ya lo dice el motivo «faltan datos».
     *
     * Son dos trabajos distintos —uno se pide, el otro se corrige— y contarlos
     * juntos dejaria el filtro sin poder separarlos.
     */
    private static function noCumple(string $valor, string $regla): bool
    {
        return $valor !== '' && preg_match($regla, $valor) !== 1;
    }

    private static function esMenor(mixed $nacimiento): bool
    {
        if ($nacimiento === null) {
            return false;
        }

        return Perfil::edadDe(Carbon::parse((string) $nacimiento)) < 18;
    }

    /**
     * Que obligatorios tiene entregados cada ficha de estudiante.
     *
     * Una consulta para todas, no una por persona.
     *
     * @param  list<int>  $obligatorios
     * @return array<int, list<int>>
     */
    private static function entregasPorFicha(array $obligatorios): array
    {
        if ($obligatorios === []) {
            return [];
        }

        $mapa = [];

        $filas = InstitucionActual::tabla('documentos_estudiante')
            ->whereIn('requerido_id', $obligatorios)
            ->where('archivo', '!=', '')
            ->whereNotNull('archivo')
            ->select('datos_estudiante_id', 'requerido_id')
            ->get();

        foreach ($filas as $fila) {
            $mapa[(int) $fila->datos_estudiante_id][] = (int) $fila->requerido_id;
        }

        return $mapa;
    }

    /**
     * Que preguntas obligatorias tiene en blanco cada encuesta EMPEZADA.
     *
     * Estar en el mapa significa «la empezo»; el valor son las que le faltan, y
     * la lista vacia significa que la termino. La diferencia entre «no empezada»
     * y «a medias» no es teorica: en el original una migracion vacio dos
     * columnas y dejo a medias toda encuesta anterior a ese cambio.
     *
     * @return array<int, list<string>>
     */
    private static function encuestas(): array
    {
        $campos = array_keys(EncuestaDemografica::CAMPOS_OBLIGATORIOS);

        $filas = InstitucionActual::tabla('encuestas_demograficas')
            ->select(['perfil_id', ...$campos])
            ->get();

        $mapa = [];

        foreach ($filas as $fila) {
            $faltan = [];

            foreach (EncuestaDemografica::CAMPOS_OBLIGATORIOS as $campo => $etiqueta) {
                if ($fila->{$campo} === null || $fila->{$campo} === '') {
                    $faltan[] = $etiqueta;
                }
            }

            $mapa[(int) $fila->perfil_id] = $faltan;
        }

        return $mapa;
    }

    /**
     * Por que promotoria se puede pedir a cada persona.
     *
     * El filtro de promotoria existe para un uso concreto que pidio el usuario:
     * sacar la lista de una y pedirle a SU profesor que persiga esos datos. Asi
     * que «pertenecer a Violín» se resuelve por los DOS caminos que ya usa el
     * filtro de Gestion → Usuarios, y por la misma razon: un estudiante esta en
     * Violín porque tiene matricula, y el profesor porque la dicta. Pedir «los
     * de Violín» devuelve las dos cosas, que es lo que uno espera — y dos
     * lecturas distintas de la misma palabra en dos pantallas seria lo peor de
     * los dos mundos.
     *
     * Las matriculas RETIRADAS no cuentan —quien se salio ya no es de esa
     * promotoria— pero las PENDIENTES si: una matricula por aprobar es
     * justamente uno de los motivos de esta lista, y dejarla fuera del vinculo
     * escondería del filtro a la persona por la que se abrio la pantalla.
     *
     * @return array<int, list<array{id: int, nombre: string}>>
     */
    private static function promotoriasDeCadaPersona(?Periodo $periodo, ?Perfil $quienMira = null): array
    {
        $mapa = [];
        $areas = $quienMira === null ? null : Permisos::areasVisiblesPara($quienMira);

        if ($periodo !== null) {
            $matriculados = InstitucionActual::tabla('matriculas')
                ->join('promotorias', 'promotorias.id', '=', 'matriculas.promotoria_id')
                ->when($areas !== null, fn ($q) => $q->whereIn('promotorias.area_id', $areas ?? []))
                ->where('matriculas.periodo_id', $periodo->id)
                ->where('matriculas.estado', '!=', Matricula::RETIRADA)
                ->select('matriculas.estudiante_id as perfil_id', 'promotorias.id', 'promotorias.nombre')
                ->orderBy('promotorias.nombre')
                ->get();

            foreach ($matriculados as $fila) {
                $mapa[(int) $fila->perfil_id][(int) $fila->id] = [
                    'id' => (int) $fila->id,
                    'nombre' => (string) $fila->nombre,
                ];
            }
        }

        // El otro camino: quien la DICTA. No depende del periodo —el vinculo del
        // profesor con su promotoria no se acaba porque cambie el semestre— y
        // por eso va fuera del `if`.
        $dictadas = InstitucionActual::tabla('promotorias')
            ->when($areas !== null, fn ($q) => $q->whereIn('promotorias.area_id', $areas ?? []))
            ->whereNotNull('promotorias.profesor_id')
            ->select('promotorias.profesor_id as perfil_id', 'promotorias.id', 'promotorias.nombre')
            ->orderBy('promotorias.nombre')
            ->get();

        foreach ($dictadas as $fila) {
            $mapa[(int) $fila->perfil_id][(int) $fila->id] = [
                'id' => (int) $fila->id,
                'nombre' => (string) $fila->nombre,
            ];
        }

        // Se indexo por id de promotoria para que quien este matriculado en la
        // misma promotoria dos veces —o que ademas la dicte— no salga repetido.
        return array_map(array_values(...), $mapa);
    }

    /**
     * @return array<int, list<string>>
     */
    private static function matriculasPorAprobar(?Periodo $periodo): array
    {
        return self::nombresPorEstudiante(
            $periodo,
            fn ($q) => $q->where('matriculas.estado', Matricula::PENDIENTE)
        );
    }

    /**
     * @return array<int, list<string>>
     */
    private static function matriculasSinGrupo(?Periodo $periodo): array
    {
        return self::nombresPorEstudiante(
            $periodo,
            // «Sin grupo» es no estar en NINGUNO, y por eso se pregunta por la
            // ausencia de filas en la puente y no por la columna en nulo: quien
            // va a dos horarios tiene la columna puesta igual que quien va a
            // uno. Es el mismo cambio de significado que en el Panel.
            // «Sin grupo» es no estar en NINGUNO, y por eso se pregunta por la
            // ausencia de filas en la puente y no por la columna en nulo: quien
            // va a dos horarios tiene la columna puesta igual que quien va a
            // uno. Es el mismo cambio de significado que en el Panel.
            //
            // `whereNotExists` a mano y NO `whereDoesntHave`: esta consulta la
            // arma el CONSTRUCTOR de consultas y no Eloquent —esta pantalla no
            // hidrata ni un modelo a proposito, ver la cabecera del archivo— asi
            // que el metodo de relaciones no existe aqui. Escrito por descuido,
            // no falla al compilar: se interpreta como una columna llamada
            // `doesnt_have` y revienta al correr.
            fn ($q) => $q->where('matriculas.estado', Matricula::ACTIVA)
                ->whereNotExists(fn ($sub) => InstitucionActual::filtrar($sub->from('asignaciones_grupo'), 'asignaciones_grupo')
                    ->whereColumn('asignaciones_grupo.matricula_id', 'matriculas.id')
                    ->selectRaw('1'))
        );
    }

    /**
     * @param  callable(Builder): mixed  $filtro
     * @return array<int, list<string>>
     */
    private static function nombresPorEstudiante(?Periodo $periodo, callable $filtro): array
    {
        if ($periodo === null) {
            return [];
        }

        $consulta = InstitucionActual::tabla('matriculas')
            ->join('promotorias', 'promotorias.id', '=', 'matriculas.promotoria_id')
            ->where('matriculas.periodo_id', $periodo->id)
            ->select('matriculas.estudiante_id', 'promotorias.nombre')
            ->orderBy('promotorias.nombre');

        $filtro($consulta);

        $mapa = [];

        foreach ($consulta->get() as $fila) {
            $mapa[(int) $fila->estudiante_id][] = (string) $fila->nombre;
        }

        return $mapa;
    }

    /**
     * «Sin grupo asignado en Violín y Danza».
     *
     * @param  list<string>  $promotorias
     */
    private static function frase(string $encabezado, array $promotorias): string
    {
        if ($promotorias === []) {
            return $encabezado;
        }

        if (count($promotorias) === 1) {
            return "{$encabezado} en {$promotorias[0]}";
        }

        $ultima = array_pop($promotorias);

        return "{$encabezado} en ".implode(', ', $promotorias)." y {$ultima}";
    }

    /**
     * Cuantas personas hay por motivo, para el desglose del boton.
     *
     * @param  list<FichaIncompleta>  $filas
     * @return array<string, int>
     */
    public static function porMotivo(array $filas): array
    {
        $cuenta = array_fill_keys(array_keys(self::MOTIVOS), 0);

        foreach ($filas as $fila) {
            foreach ($fila['motivos'] as $motivo) {
                $cuenta[$motivo]++;
            }
        }

        return $cuenta;
    }
}
