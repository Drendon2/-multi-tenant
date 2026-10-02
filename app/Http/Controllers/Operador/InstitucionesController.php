<?php

namespace App\Http\Controllers\Operador;

use App\Http\Controllers\Controller;
use App\Models\Institucion;
use App\Support\Auditoria;
use App\Support\Panel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Las instituciones, vistas desde el panel de todas (paso 4b, 02/10/2026).
 *
 * Lo que el panel hace con una institucion, por decision del usuario: su
 * DIRECCION (subdominio y dominio propio) y su ESTADO. Crearla sigue siendo
 * `php artisan instalar --nueva`, porque monta catalogo, periodo y dos
 * administradores, y eso se teclea mejor en una consola.
 *
 * Solo lee y escribe `instituciones`, que no tiene RLS. Las cifras de cada
 * una (personas, matriculas) son del paso 5, con el rol global.
 */
class InstitucionesController extends Controller
{
    /** Lo que la base admite en `subdominio` (CHECK `subdominio_valido`). */
    private const SUBDOMINIO = '/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/';

    /** Lo que la base admite en `dominio_propio` (CHECK `dominio_propio_valido`). */
    private const DOMINIO = '/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]*[a-z0-9]$/';

    public function index(): View
    {
        return view('operador.instituciones', [
            'instituciones' => Institucion::orderBy('nombre')->orderBy('id')->get(),
            'base' => config('institucion.dominio_base'),
        ]);
    }

    public function editar(Institucion $institucion): View
    {
        return view('operador.institucion', [
            'institucion' => $institucion,
            'base' => config('institucion.dominio_base'),
        ]);
    }

    public function guardar(Request $request, Institucion $institucion): RedirectResponse
    {
        // En minusculas y sin espacios antes de validar: es lo que el navegador
        // manda como host, y lo que la base exige.
        $request->merge([
            'subdominio' => self::limpiar($request->input('subdominio')),
            'dominio_propio' => self::limpiar($request->input('dominio_propio')),
        ]);

        $base = strtolower((string) config('institucion.dominio_base'));

        $datos = $request->validate([
            // Sin ninguna de las dos, nadie podria llegar a la institucion.
            'subdominio' => ['nullable', 'required_without:dominio_propio', 'string', 'max:63',
                'regex:'.self::SUBDOMINIO, Rule::notIn(Panel::RESERVADOS),
                Rule::unique('instituciones', 'subdominio')->ignore($institucion->id)],
            'dominio_propio' => ['nullable', 'string', 'max:253', 'regex:'.self::DOMINIO,
                Rule::unique('instituciones', 'dominio_propio')->ignore($institucion->id),
                function (string $campo, mixed $valor, \Closure $falla) use ($base) {
                    // Un nombre bajo el dominio base ya es un subdominio: se
                    // pone en el otro campo, o chocaria con el de otra.
                    if ($valor === $base || str_ends_with((string) $valor, '.'.$base)) {
                        $falla('Ese dominio cuelga del dominio del sistema: ponlo como subdominio.');
                    }
                }],
            'estado' => ['required', Rule::in([Institucion::ACTIVA, Institucion::SUSPENDIDA])],
        ], [
            'subdominio.required_without' => 'Pon un subdominio o un dominio propio: sin ninguno, nadie puede entrar a esta institución.',
            'subdominio.regex' => 'Solo letras minúsculas sin tildes, números y guiones, sin puntos ni guion al principio o al final.',
            'subdominio.not_in' => 'Ese subdominio está reservado para el sistema.',
            'subdominio.unique' => 'Ese subdominio ya es de otra institución.',
            'dominio_propio.regex' => 'Escribe solo el dominio, sin https:// ni barras ni puerto. Por ejemplo: matriculas.alcaldia.gov.co',
            'dominio_propio.unique' => 'Ese dominio ya es de otra institución.',
        ], [
            'subdominio' => 'subdominio',
            'dominio_propio' => 'dominio propio',
        ]);

        $institucion->fill($datos);
        $cambios = $institucion->getDirty();
        $institucion->save();

        if ($cambios !== []) {
            Auditoria::registrar('operador.institucion', [
                'operador_id' => Auth::guard('operador')->id(),
                'institucion_id' => $institucion->id,
                'cambios' => $cambios,
            ]);
        }

        return redirect()->route('operador.institucion', $institucion)
            ->with('success', 'Guardado.');
    }

    private static function limpiar(mixed $valor): ?string
    {
        $valor = strtolower(trim((string) $valor));

        return $valor === '' ? null : $valor;
    }
}
