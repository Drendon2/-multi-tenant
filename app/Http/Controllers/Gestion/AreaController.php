<?php

namespace App\Http\Controllers\Gestion;

use App\Models\Area;
use App\Support\Reglas;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Departamentos: el primer nivel del catalogo (Musica, Danza, Artes plasticas).
 */
class AreaController extends RecursoController
{
    protected function modelo(): string
    {
        return Area::class;
    }

    protected function textos(): array
    {
        return [
            'titulo' => 'Departamentos',
            'titulo_nuevo' => 'Nueva área',
            'titulo_editar' => 'Editar área',
            // Vuelve a «Programas formativos» y no al listado plano: ahi es
            // donde vive esta lista desde el 01/09/2026, y donde estaba quien
            // abrio el modal.
            'ruta_lista' => 'gestion-programas',
            'crear_solo_admin' => true,
            // Crear, editar y borrar esto es del ADMINISTRADOR desde el
            // 12/09/2026: es una decision de toda la casa y un director esta
            // acotado a sus departamentos. Lo lee `partials.tabla-catalogo`
            // para no pintar un menu de fila que rebotaria en la puerta.
            'solo_admin' => true,
            'ruta_nuevo' => 'area-nueva',
            'ruta_editar' => 'area-editar',
            'ruta_eliminar' => 'area-eliminar',
            'creado' => 'Área creada.',
            'actualizado' => 'Área actualizada.',
        ];
    }

    protected function listado(Request $request): array
    {
        return [
            // ACOTADO AL DIRECTOR desde el 12/09/2026: solo los departamentos
            // que le asignan. Para el administrador `queVe()` no filtra nada.
            'objetos' => Area::queVe($request->attributes->get('perfil'))
                ->withCount('promotorias')->orderBy('nombre')->get()
                ->map(fn (Area $area) => [
                    'objeto' => $area,
                    'hijos' => $area->promotorias_count,
                    // Un area sin promotorias se borra sin mas: lo que la
                    // bloquea son precisamente sus promotorias, que ya se
                    // cuentan arriba.
                    'protegido' => $area->promotorias_count,
                ])
                ->all(),
            'etiqueta_singular' => 'promotoría',
            'etiqueta_plural' => 'promotorías',
            'etiqueta_protegido' => 'promotorías',
            'ruta_fila' => 'promotorias-por-area',
            'mostrar_tag_area' => true,
        ];
    }

    protected function campos(Request $request, ?Model $objeto): array
    {
        return [
            'nombre' => ['etiqueta' => 'Nombre', 'tipo' => 'text', 'max' => 60],
        ];
    }

    protected function reglas(Request $request, ?Model $objeto): array
    {
        return [
            'nombre' => [...Reglas::texto(60), Reglas::unica('areas', 'nombre')->ignore($objeto?->id)],
        ];
    }
}
