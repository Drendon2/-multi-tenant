<?php

namespace App\Http\Controllers\Gestion;

use App\Models\Area;
use App\Models\Perfil;
use App\Models\Promotoria;
use App\Support\Permisos;
use App\Support\Reglas;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Promotorias: el segundo nivel del catalogo (Violin, dentro de Musica).
 *
 * Al crear o editar se vuelve a la lista del DEPARTAMENTO y no al listado
 * plano: quien esta armando el catalogo entra por Departamentos → un area → sus
 * promotorias, y devolverlo a la lista general lo saca de donde estaba
 * trabajando.
 */
class PromotoriaController extends RecursoController
{
    protected function modelo(): string
    {
        return Promotoria::class;
    }

    protected function textos(): array
    {
        return [
            'titulo' => 'Promotorías',
            'titulo_nuevo' => 'Nueva promotoría',
            'titulo_editar' => 'Editar promotoría',
            'ruta_lista' => 'promotoria-lista',
            'crear_solo_admin' => true,
            'ruta_nuevo' => 'promotoria-nueva',
            'ruta_editar' => 'promotoria-editar',
            'ruta_eliminar' => 'promotoria-eliminar',
            'creado' => 'Promotoría creada.',
            'actualizado' => 'Promotoría actualizada.',
        ];
    }

    /**
     * LA MISMA PUERTA PARA EDITAR, ACTUALIZAR Y BORRAR.
     *
     * `buscar()` es por donde pasan los cuatro metodos del recurso, asi que
     * acotarlo aqui los cierra todos de una vez. Sin esto el recorte del
     * 12/09/2026 era SOLO COSMETICO: los listados no enseñaban lo ajeno y un
     * director abria `/gestion/promotorias/12/editar` por URL y le respondia 200.
     * Se encontro abriendo la pagina, no leyendo el codigo.
     *
     * 404 y no 403: que exista o no una promotoria de otro departamento tampoco
     * es asunto suyo.
     */
    protected function buscar(string $id): Model
    {
        /** @var Perfil $perfil */
        $perfil = request()->attributes->get('perfil');

        return Promotoria::queVe($perfil)->findOrFail($id);
    }

    protected function listado(Request $request): array
    {
        return [
            // ACOTADO: un director solo ve las de los departamentos que
            // administra. Para el administrador `queVe()` no filtra nada.
            'objetos' => $this->filas(Promotoria::queVe($request->attributes->get('perfil'))),
            ...$this->columnas(),
        ];
    }

    /** Las promotorias de un solo departamento, llegando desde Departamentos. */
    public function porArea(Area $area): View
    {
        // Y el departamento tambien: llegar por `/gestion/areas/3/promotorias`
        // a uno ajeno enseñaba su nombre en el titulo aunque la lista saliera
        // vacia. 404, como todo lo demas de aqui.
        abort_unless(
            Permisos::areasVisiblesPara(request()->attributes->get('perfil')) === null
                || in_array($area->id, Permisos::areasVisiblesPara(request()->attributes->get('perfil')), true),
            404
        );

        return view('gestion.lista', [
            ...$this->textos(),
            'titulo' => "Promotorías de {$area->nombre}",
            'objetos' => $this->filas(Promotoria::where('area_id', $area->id)->queVe(request()->attributes->get('perfil'))),
            ...$this->columnas(),
            'preset_campo' => 'area_id',
            'preset_valor' => $area->id,
            'migas' => [['texto' => 'Programas formativos', 'url' => route('gestion-programas')]],
        ]);
    }

    /** @return array<string, mixed> */
    private function columnas(): array
    {
        return [
            'etiqueta_singular' => 'grupo',
            'etiqueta_plural' => 'grupos',
            'etiqueta_protegido' => 'matrículas',
            'ruta_fila' => 'grupos-por-promotoria',
            // Quien dicta cada una. `gestion.lista` sirve a cuatro catalogos y
            // solo las promotorias tienen profesor, asi que va por bandera.
            'mostrar_profesor' => true,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function filas($consulta): array
    {
        return $consulta
            ->with(['area', 'profesor'])
            ->withCount(['grupos', 'matriculas'])
            ->join('areas', 'areas.id', '=', 'promotorias.area_id')
            ->orderBy('areas.nombre')
            ->orderBy('promotorias.nombre')
            ->select('promotorias.*')
            ->get()
            ->map(fn (Promotoria $p) => [
                'objeto' => $p,
                'hijos' => $p->grupos_count,
                // Las matriculas son RESTRICT y ninguna se borra al terminar el
                // periodo, asi que aqui van TODAS —retiradas incluidas—: son
                // exactamente las que haran fallar el borrado.
                'protegido' => $p->matriculas_count,
            ])
            ->all();
    }

    protected function campos(Request $request, ?Model $objeto): array
    {
        return [
            'nombre' => ['etiqueta' => 'Nombre', 'tipo' => 'text', 'max' => 60],
            'area_id' => [
                'etiqueta' => 'Departamento',
                'tipo' => 'select',
                // SOLO LOS SUYOS. Un director ya no crea promotorias —eso es
                // del administrador desde el 12/09/2026— pero si EDITA las de
                // sus departamentos, y con la lista entera aqui podia moverse
                // una a un departamento ajeno: al guardar desaparecia de su
                // vista y no podia devolverla. Se pierde algo sin que nada
                // falle, que es la forma peor.
                'opciones' => Area::queVe($request->attributes->get('perfil'))
                    ->orderBy('nombre')->pluck('nombre', 'id')->all(),
                // Al llegar desde un departamento se preselecciona: quien esta
                // armando ese departamento no tiene por que volver a elegirlo.
                'valor' => $objeto?->area_id ?? $request->query('area_id'),
            ],
            'profesor_id' => [
                'etiqueta' => 'Profesor',
                'tipo' => 'select',
                // Quien puede quedar a cargo es el PERSONAL, no solo los
                // profesores: un director que ademas dicta su propia promotoria
                // es un caso real.
                'opciones' => Perfil::whereIn('rol', Perfil::ROLES_PERSONAL)
                    ->orderBy('nombre_completo')
                    ->pluck('nombre_completo', 'id')
                    ->all(),
                'vacio' => '-- sin asignar --',
            ],
        ];
    }

    protected function reglas(Request $request, ?Model $objeto): array
    {
        return [
            'nombre' => Reglas::texto(60),
            'area_id' => ['required', Reglas::existe('areas')],
            'profesor_id' => [
                'nullable',
                Reglas::existe('perfiles')->whereIn('rol', Perfil::ROLES_PERSONAL),
            ],
        ];
    }

    protected function urlExito(Model $objeto): string
    {
        return route('promotorias-por-area', $objeto->area_id);
    }
}
