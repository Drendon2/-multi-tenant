<?php

namespace App\Http\Controllers\Gestion;

use App\Models\Area;
use App\Models\Grupo;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Promotoria;
use App\Support\HorarioDeGrupo;
use App\Support\Permisos;
use App\Support\Reglas;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Grupos vistos desde Gestion: el tercer nivel del catalogo.
 *
 * Es la misma tabla que administra quien dicta desde su Panel, pero por otro
 * camino y con otro alcance: aqui direccion recorre el catalogo entero —incluido
 * el de promotorias que no dicta nadie— mientras que en el Panel cada quien ve
 * lo suyo.
 */
class GrupoController extends RecursoController
{
    /**
     * El unico catalogo que NO cabe en un modal.
     *
     * Su formulario lleva el horario, que es una rejilla de los siete dias con
     * su hora de inicio y de fin. Eso no es un campo, es media pantalla; en un
     * dialogo se aprieta y en un celular queda debajo del teclado.
     */
    protected function cabeEnModal(): bool
    {
        return false;
    }

    protected function modelo(): string
    {
        return Grupo::class;
    }

    protected function textos(): array
    {
        return [
            'titulo' => 'Grupos',
            'titulo_nuevo' => 'Nuevo grupo',
            'titulo_editar' => 'Editar grupo',
            'ruta_lista' => 'grupo-lista',
            'ruta_nuevo' => 'grupo-nuevo',
            'ruta_editar' => 'grupo-editar',
            'ruta_eliminar' => 'grupo-eliminar',
            'creado' => 'Grupo creado.',
            'actualizado' => 'Grupo actualizado.',
        ];
    }

    /**
     * Valor del filtro de profesor que pide las promotorias SIN nadie asignado.
     *
     * Hace falta un centinela porque «sin profesor» es un null y la cadena vacia
     * ya significa «no filtres» en un formulario GET. Y hace falta la opcion:
     * una promotoria sin nadie asignado es aquella en la que NADIE puede
     * registrar clases, asi que poder listar sus grupos de un vistazo es lo que
     * convierte un hueco del catalogo en una tarea.
     */
    public const PROFESOR_SIN_ASIGNAR = '__sin__';

    protected function listado(Request $request): array
    {
        $seleccion = [
            'area' => $request->query('area') ?: null,
            'promotoria' => $request->query('promotoria') ?: null,
            'profesor' => $request->query('profesor') ?: null,
        ];

        // EL RECORTE DEL DIRECTOR VA PRIMERO y no como un filtro mas: los de
        // abajo los elige quien mira, este no se puede quitar. Un grupo cuelga
        // de una promotoria y esa de un departamento, asi que se pregunta por
        // la relacion.
        $perfil = request()->attributes->get('perfil');

        $consulta = Grupo::query()
            // La anotacion no sobra: dentro de `whereHas` el constructor llega
            // sin tipo y el analizador no sabe que `queVe()` existe.
            ->whereHas('promotoria', function ($q) use ($perfil) {
                /** @var Builder<Promotoria> $q */
                $q->queVe($perfil);
            })
            ->when($seleccion['promotoria'], fn ($q, $id) => $q->where('grupos.promotoria_id', $id))
            ->when($seleccion['area'], fn ($q, $id) => $q->whereHas(
                'promotoria',
                fn ($sub) => $sub->where('area_id', $id)
            ))
            ->when($seleccion['profesor'], fn ($q, $id) => $q->whereHas(
                'promotoria',
                fn ($sub) => $id === self::PROFESOR_SIN_ASIGNAR
                    ? $sub->whereNull('profesor_id')
                    : $sub->where('profesor_id', $id)
            ));

        $objetos = $this->filas($consulta);

        return [
            'objetos' => $objetos,
            ...$this->columnas(),
            'filtros' => $this->filtros($seleccion),
            'hay_filtros' => array_filter($seleccion) !== [],
            'nota_filtros' => count($objetos).' '.(count($objetos) === 1 ? 'grupo' : 'grupos'),
            // Con la promotoria ya elegida, «+ Nuevo» llega con ella puesta: es
            // el caso normal —se filtra para ver los de una y se crea otro ahi
            // mismo— y ahorra volver a buscarla en un desplegable de veintiuna.
            'preset_campo' => $seleccion['promotoria'] ? 'promotoria_id' : null,
            'preset_valor' => $seleccion['promotoria'],
        ];
    }

    /**
     * Los tres desplegables del filtro.
     *
     * Las promotorias van agrupadas por departamento en `<optgroup>`: la
     * jerarquia del catalogo se ve en el propio desplegable sin tener que elegir
     * antes el departamento y recargar. Es la misma forma que ya usa el filtro
     * de usuarios.
     *
     * @param  array<string, string|null>  $seleccion
     * @return list<array<string, mixed>>
     */
    private function filtros(array $seleccion): array
    {
        $promotorias = Promotoria::queVe(request()->attributes->get('perfil'))
            ->with('area')
            ->join('areas', 'areas.id', '=', 'promotorias.area_id')
            ->orderBy('areas.nombre')
            ->orderBy('promotorias.nombre')
            ->select('promotorias.*')
            ->get();

        $porArea = [];

        foreach ($promotorias as $promotoria) {
            $porArea[$promotoria->area->nombre][$promotoria->id] = $promotoria->nombre;
        }

        // Solo quien DICTA algo. Un desplegable con las cien personas de la casa
        // para elegir entre los ocho que tienen grupos no es un filtro, es un
        // buscador de agujas.
        $profesores = Perfil::query()
            ->whereIn('id', $promotorias->pluck('profesor_id')->filter()->unique())
            ->orderBy('nombre_completo')
            ->pluck('nombre_completo', 'id')
            ->all();

        return [
            [
                'nombre' => 'area',
                'etiqueta' => 'Departamento',
                'vacio' => 'Todos',
                'opciones' => Area::orderBy('nombre')->pluck('nombre', 'id')->all(),
                'valor' => $seleccion['area'],
            ],
            [
                'nombre' => 'promotoria',
                'etiqueta' => 'Promotoría',
                'vacio' => 'Todas',
                'opciones' => $porArea,
                'agrupadas' => true,
                'valor' => $seleccion['promotoria'],
            ],
            [
                'nombre' => 'profesor',
                'etiqueta' => 'Profesor',
                'vacio' => 'Todos',
                'opciones' => $profesores + [self::PROFESOR_SIN_ASIGNAR => 'Sin asignar'],
                'valor' => $seleccion['profesor'],
            ],
        ];
    }

    /**
     * La misma puerta que en promotorias, y por lo mismo: sin ella un director
     * abria `/gestion/grupos/14/editar` de un grupo de otro departamento y le
     * respondia 200. `buscar()` es por donde pasan editar, actualizar y borrar.
     */
    protected function buscar(string $id): Model
    {
        /** @var Perfil $perfil */
        $perfil = request()->attributes->get('perfil');

        return Grupo::whereHas('promotoria', function ($q) use ($perfil) {
            /** @var Builder<Promotoria> $q */
            $q->queVe($perfil);
        })->findOrFail($id);
    }

    /** Los grupos de una sola promotoria, llegando desde su departamento. */
    public function porPromotoria(Promotoria $promotoria): View
    {
        // Una promotoria ajena no enseña ni sus grupos ni su nombre.
        abort_unless(
            Permisos::veLaPromotoria(request()->attributes->get('perfil'), $promotoria),
            404
        );

        $promotoria->load('area');

        return view('gestion.lista', [
            ...$this->textos(),
            'titulo' => "Grupos de {$promotoria->nombre}",
            'objetos' => $this->filas(Grupo::where('promotoria_id', $promotoria->id)),
            ...$this->columnas(),
            'preset_campo' => 'promotoria_id',
            'preset_valor' => $promotoria->id,
            // Los carnes de toda la promotoria, solo para administracion: la
            // misma puerta que el carne de otra persona (`CarneController`).
            'enlaces_extra' => request()->attributes->get('perfil')?->rol === 'administrador'
                ? [['texto' => 'Imprimir carnés (PDF)', 'url' => route('carnes-promotoria', $promotoria)]]
                : [],
            'migas' => [
                ['texto' => 'Programas formativos', 'url' => route('gestion-programas')],
                ['texto' => $promotoria->area->nombre, 'url' => route('promotorias-por-area', $promotoria->area_id)],
            ],
        ]);
    }

    /** El listado de quien esta en un grupo. Solo lectura. */
    public function estudiantes(Grupo $grupo): View
    {
        $grupo->load(['promotoria.area', 'sesiones']);

        $matriculas = $grupo->matriculas()
            ->whereIn('matriculas.estado', Matricula::ESTADOS_INSCRITO)
            ->with(['estudiante.datosEstudiante.acudiente'])
            ->get();

        return view('gestion.grupo-estudiantes', [
            'grupo' => $grupo,
            'estudiantes' => $matriculas->map(fn (Matricula $m) => [
                'matricula' => $m,
                'perfil' => $m->estudiante,
                'acudiente' => $m->estudiante->datosEstudiante?->acudiente,
            ])->all(),
            'migas' => [
                ['texto' => 'Programas formativos', 'url' => route('gestion-programas')],
                ['texto' => $grupo->promotoria->area->nombre, 'url' => route('promotorias-por-area', $grupo->promotoria->area_id)],
                ['texto' => $grupo->promotoria->nombre, 'url' => route('grupos-por-promotoria', $grupo->promotoria_id)],
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function columnas(): array
    {
        return [
            'etiqueta_singular' => 'estudiante',
            'etiqueta_plural' => 'estudiantes',
            'etiqueta_protegido' => 'matrículas',
            'ruta_fila' => 'grupo-estudiantes',
        ];
    }

    /** @return list<array<string, mixed>> */
    private function filas($consulta): array
    {
        return $consulta
            ->with('promotoria.area')
            ->withCount([
                'matriculas as activas_count' => fn ($q) => $q->where('estado', Matricula::ACTIVA),
                'matriculas',
            ])
            ->join('promotorias', 'promotorias.id', '=', 'grupos.promotoria_id')
            ->join('areas', 'areas.id', '=', 'promotorias.area_id')
            ->orderBy('areas.nombre')
            ->orderBy('promotorias.nombre')
            ->orderBy('grupos.nivel')
            ->select('grupos.*')
            ->get()
            ->map(fn (Grupo $g) => [
                'objeto' => $g,
                'hijos' => $g->activas_count,
                // Ojo a la diferencia con la de al lado: la del nombre cuenta
                // estudiantes ACTIVOS, y un grupo del que todos se retiraron
                // muestra cero y aun asi no se deja borrar. Esta cuenta todas.
                'protegido' => $g->matriculas_count,
            ])
            ->all();
    }

    protected function campos(Request $request, ?Model $objeto): array
    {
        // SOLO LAS SUYAS. Crear grupos SI es trabajo de un director —armar los
        // horarios de sus promotorias lo es— pero con la lista entera podia
        // colgar un grupo de una promotoria ajena, y entonces ni lo veia
        // despues. Ver `Promotoria::queVe()`.
        $promotorias = Promotoria::queVe($request->attributes->get('perfil'))
            ->with('area')
            ->join('areas', 'areas.id', '=', 'promotorias.area_id')
            ->orderBy('areas.nombre')
            ->orderBy('promotorias.nombre')
            ->select('promotorias.*')
            ->get()
            ->mapWithKeys(fn (Promotoria $p) => [$p->id => (string) $p])
            ->all();

        return [
            'promotoria_id' => [
                'etiqueta' => 'Promotoría',
                'tipo' => 'select',
                'opciones' => $promotorias,
                'valor' => $objeto?->promotoria_id ?? $request->query('promotoria_id'),
            ],
            'nombre' => [
                'etiqueta' => 'Nombre del grupo', 'tipo' => 'text', 'max' => 60,
                'ayuda' => 'Lo que distingue este grupo de los demás de la promotoría. '
                    .'Por ejemplo, Martes tarde.',
            ],
            'nivel' => ['etiqueta' => 'Nivel', 'tipo' => 'select', 'opciones' => Grupo::NIVELES],
            // El horario no es una columna sino filas aparte, asi que se pinta
            // con su propio parcial en vez de con el formulario declarativo.
            'sesiones' => ['etiqueta' => 'Horario', 'tipo' => 'sesiones'],
            'salon' => ['etiqueta' => 'Salón', 'tipo' => 'text', 'max' => 40],
            'cupo_maximo' => ['etiqueta' => 'Cupo máximo', 'tipo' => 'number', 'min' => 0],
        ];
    }

    /**
     * El mismo mensaje que da el Panel, y por el mismo motivo.
     *
     * Aqui llegaba el de Laravel —«Ya existe un registro con ese nombre»—, que
     * no dice en que promotoria choca ni por que choca si el nivel es otro. Es
     * exactamente la confusion que se llevo por delante a un profesor en
     * produccion: creo dos grupos con el mismo nombre y distinto nivel, y leyo
     * el rechazo como un tope de grupos.
     *
     * @return array<string, string>
     */
    protected function mensajes(Request $request, ?Model $objeto): array
    {
        $promotoria = Promotoria::find($request->input('promotoria_id'))?->nombre ?? 'Esta promotoría';

        return [
            'nombre.unique' => "{$promotoria} ya tiene un grupo con ese nombre, aunque sea de otro "
                .'nivel: el nombre es lo que los distingue en las listas, así que no puede '
                .'repetirse. Prueba con algo como «Martes tarde» o «Grupo A avanzado».',
        ];
    }

    protected function reglas(Request $request, ?Model $objeto): array
    {
        return [
            'promotoria_id' => ['required', 'exists:promotorias,id'],
            // El NOMBRE es lo unico que no puede repetirse dentro de una
            // promotoria. El nivel si se repite: una promotoria con mucha gente
            // tiene varios grupos de Basico, y eso es lo normal.
            'nombre' => [
                ...Reglas::texto(60),
                Rule::unique('grupos', 'nombre')
                    ->where('promotoria_id', $request->input('promotoria_id'))
                    ->ignore($objeto?->id),
            ],
            'nivel' => ['required', Rule::in(array_keys(Grupo::NIVELES))],
            'salon' => Reglas::texto(40),
            'cupo_maximo' => ['required', 'integer', 'min:0'],
        ];
    }

    /**
     * El horario no es una columna del grupo sino filas de `sesiones_grupo`, y
     * por eso va por estos dos ganchos en vez de por `reglas()`.
     *
     * Se comprueba antes de escribir y se guarda despues, que es el orden que
     * evita dejar un grupo creado sin horas cuando el horario esta mal.
     */
    protected function validarExtra(Request $request, ?Model $objeto): mixed
    {
        return HorarioDeGrupo::leer($request);
    }

    protected function despuesDeGuardar(Model $objeto, mixed $extra): void
    {
        HorarioDeGrupo::guardar($objeto, $extra);
    }

    protected function urlExito(Model $objeto): string
    {
        return route('grupos-por-promotoria', $objeto->promotoria_id);
    }
}
