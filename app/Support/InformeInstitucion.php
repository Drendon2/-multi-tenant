<?php

namespace App\Support;

use App\Models\Area;
use App\Models\DocumentoEstudiante;
use App\Models\DocumentoRequerido;
use App\Models\EncuestaDemografica;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use Generator;
use Illuminate\Database\Eloquent\Collection;

/**
 * El informe completo de una institucion: una fila por persona y promotoria,
 * con la encuesta demografica NOMINAL y los papeles entregados.
 *
 * Vivia dentro de `InformeController` y salio aqui en el paso 5 (02/10/2026),
 * porque desde entonces lo arman DOS descargas: la de cada institucion
 * (Gestion → Informes) y la consolidada del panel, que lo repite institucion
 * tras institucion con una columna «Institución» delante. Escrito dos veces,
 * las dos versiones se separarian sin que nada fallara.
 *
 * La UNICA diferencia entre las dos es como van los papeles. Cada institucion
 * pide los suyos, asi que en la suya va una columna por papel («Entregó: …»),
 * y en la consolidada —donde las columnas no casarian entre instituciones— van
 * juntos en una sola, «Papeles entregados».
 *
 * Por que el informe es como es (una fila por persona y promotoria, el periodo
 * en curso, la edad vacia para el personal, los anchos cuadrados) esta
 * explicado en cada metodo y en `InformeController::institucion()`.
 */
final class InformeInstitucion
{
    /** Cuantas filas se traen por tanda (ver `InformeController::POR_TANDA`). */
    private const POR_TANDA = 100;

    /** @var Collection<int, DocumentoRequerido> */
    private Collection $papeles;

    /**
     * Se construye DENTRO de la institucion que se informa: lee sus papeles.
     *
     * LOS PAPELES SE CONSULTAN UNA SOLA VEZ y se usan para la cabecera y para
     * las filas. La lista es dinamica —cada entidad pide los suyos y los cambia
     * cuando quiere—, asi que preguntarla dos veces abre la puerta a que la
     * cabecera y las filas salgan de listas distintas. Cuadrar cabecera y filas
     * es lo que vigila `InformeCuadradoTest`. Solo los ACTIVOS: un papel que se
     * dejo de pedir no es una casilla sin rellenar, es una pregunta que ya no se
     * hace.
     */
    public function __construct(private readonly bool $consolidado = false)
    {
        $this->papeles = DocumentoRequerido::activos()->ordenados()->get();
    }

    /** @return list<string> */
    public function cabecera(): array
    {
        return [
            ...self::columnasFijas(),
            // Una columna por papel pedido, al final para no mover las que ya
            // existian: quien tenga una hoja hecha sobre este informe la
            // conserva. «Entregó:» delante porque un papel puede llamarse como
            // una columna de arriba —nada impide llamarlo «Teléfono»— y dos
            // cabeceras iguales rompen cualquier tabla dinamica.
            ...($this->consolidado
                ? ['Papeles entregados']
                : $this->papeles->map(fn (DocumentoRequerido $p) => 'Entregó: '.$p->nombre)->all()),
        ];
    }

    /**
     * La cabecera del consolidado, que no depende de ninguna institucion: los
     * papeles van en una sola columna.
     *
     * @return list<string>
     */
    public static function cabeceraConsolidada(): array
    {
        return [...self::columnasFijas(), 'Papeles entregados'];
    }

    /** @return list<string> */
    private static function columnasFijas(): array
    {
        return [
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
        ];
    }

    /** @return Generator<int, list<string>> */
    public function filas(): Generator
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
            ->orderBy('nombre_completo')
            // Desempate por id: dos personas con el mismo nombre en el borde de
            // una tanda.
            ->orderBy('id');

        // `lazy` y no `lazyById`: va ordenado por rol y nombre, y paginar por id
        // con otro orden repite filas.
        foreach ($consulta->lazy(self::POR_TANDA) as $perfil) {
            $comunes = $this->columnasDePersona($perfil);
            $encuesta = [
                ...$this->columnasDeEncuesta($perfil->encuesta),
                ...$this->columnasDeDocumentos($perfil),
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
                // un `Model` cualquiera.
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
     * La edad sale VACIA para el personal. Es un dato del estudiante --de ahi
     * salen la minoria de edad, el acudiente obligatorio y el nivel del
     * grupo-- y en un profesor no lo usa nadie. Aqui pesa mas que en la ficha:
     * esto es un archivo que sale del sistema y ya no vuelve. La columna no se
     * quita de la cabecera porque para el estudiante sigue haciendo falta, y
     * una hoja con las columnas cambiando segun la fila no la abre ningun
     * programa.
     *
     * @return list<string|int|null>
     */
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
     * Los papeles: «Sí» si lo entrego, «No» si no. Una columna por papel, o
     * todos en una celda en el consolidado («Cédula: Sí · Foto: No»).
     *
     * PARA EL PERSONAL VAN VACIAS, no «No». A un profesor no se le piden
     * papeles —cuelgan de `datos_estudiante`, que solo tienen los estudiantes—
     * asi que un «No» ahi seria una falta que no existe, y quien filtre la hoja
     * por «No» se encontraria a toda la plantilla dentro de la lista de a quien
     * llamar.
     *
     * Se mira `archivo !== ''` y no la existencia de la fila: una entrega puede
     * quedar con la ruta vacia y eso no es haber entregado.
     *
     * @return list<string|null>
     */
    private function columnasDeDocumentos(Perfil $perfil): array
    {
        $datos = $perfil->datosEstudiante;

        if ($datos === null) {
            return $this->consolidado ? [null] : array_fill(0, $this->papeles->count(), null);
        }

        $entregados = $datos->documentos
            ->filter(fn (DocumentoEstudiante $d) => $d->archivo !== '')
            ->pluck('requerido_id')
            ->all();

        $respuestas = $this->papeles
            ->map(fn (DocumentoRequerido $p) => in_array($p->id, $entregados, true) ? 'Sí' : 'No')
            ->values();

        if (! $this->consolidado) {
            return $respuestas->all();
        }

        return [$this->papeles->values()
            ->map(fn (DocumentoRequerido $p, int $i) => $p->nombre.': '.$respuestas[$i])
            ->implode(' · ')];
    }

    /**
     * Las siete columnas que describen UNA matricula, o siete vacias si no hay.
     *
     * EL FALLO QUE ESTO CIERRA, del 04/09/2026: las dos ramas escribian su
     * propia lista, y la de «sin matricula» tenia SEIS huecos donde la otra
     * ponia siete columnas. Todo lo que iba de «Departamento» en adelante se
     * corria una posicion, y le pasaba a TODO EL PERSONAL. Con una sola lista
     * no pueden descuadrarse; que ademas cuadre con la CABECERA lo vigila
     * `InformeCuadradoTest`.
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

        /** @var Promotoria $promotoria */
        $promotoria = $matricula->promotoria;
        /** @var Area $area */
        $area = $promotoria->area;

        return [
            $area->nombre,
            $promotoria->nombre,
            // Aqui la fila es una PERSONA y no una clase, asi que sus grupos se
            // juntan en la celda en vez de multiplicar filas.
            $matricula->grupos->pluck('nombre')->implode(' · ') ?: 'Sin grupo',
            $matricula->grupos->pluck('nivel_display')->implode(' · '),
            Matricula::ESTADOS[$matricula->estado] ?? $matricula->estado,
            $trayectoria['periodos'],
            $trayectoria['desde'],
        ];
    }

    /**
     * Las respuestas de la encuesta, ya traducidas a su etiqueta: quien abre
     * el informe quiere «Femenino», no «f». Sin encuesta, todas vacias.
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
     * «Tiempo» se mide en PERIODOS cursados y no en meses: alguien lleva «tres
     * semestres en Guitarra». Cuentan solo los periodos con matricula ACTIVA
     * —lo que de verdad curso—. Va agregado porque si no serian dos consultas
     * por fila del informe.
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
            ->toBase()
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
