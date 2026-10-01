<?php

namespace App\Http\Controllers;

use App\Models\Grupo;
use App\Models\Perfil;
use App\Models\Promotoria;
use App\Support\ErrorDeBaseDeDatos;
use App\Support\HorarioDeGrupo;
use App\Support\Permisos;
use App\Support\Reglas;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Alta, edicion y baja de los grupos de una promotoria.
 *
 * Un grupo es un horario concreto. Lo crea quien dicta segun su disponibilidad,
 * y solo despues reparte ahi a los que ya se matricularon: el estudiante nunca
 * elige horario.
 */
class PanelGrupoController extends Controller
{
    public function crear(Request $request, Promotoria $promotoria): View|RedirectResponse
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');

        if (! Permisos::puedeGestionarPromotoria($perfil, $promotoria)) {
            return $this->alPanel('No tienes acceso a esta promotoría.');
        }

        return view('panel.grupo-form', [
            'titulo' => "Nuevo grupo — {$promotoria->nombre}",
            'promotoria' => $promotoria,
            'grupo' => new Grupo,
            'sesiones' => HorarioDeGrupo::paraElFormulario(null),
            'accion' => route('panel-grupo-nuevo', $promotoria),
        ]);
    }

    public function guardar(Request $request, Promotoria $promotoria): RedirectResponse
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');

        if (! Permisos::puedeGestionarPromotoria($perfil, $promotoria)) {
            return $this->alPanel('No tienes acceso a esta promotoría.');
        }

        $datos = $this->validar($request, $promotoria);
        // El horario se lee ANTES de crear nada: si esta mal, el formulario
        // rebota sin haber dejado un grupo a medias en la base.
        $sesiones = HorarioDeGrupo::leer($request);

        $grupo = new Grupo($datos);
        $grupo->promotoria_id = $promotoria->id;
        $grupo->save();

        HorarioDeGrupo::guardar($grupo, $sesiones);

        return $this->alPanel('Grupo creado.', exito: true, promotoria: $promotoria);
    }

    public function editar(Request $request, Grupo $grupo): View|RedirectResponse
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');

        if (! Permisos::puedeGestionarPromotoria($perfil, $grupo->promotoria)) {
            return $this->alPanel('No tienes acceso a esta promotoría.');
        }

        return view('panel.grupo-form', [
            'titulo' => "Editar grupo — {$grupo}",
            'promotoria' => $grupo->promotoria,
            'grupo' => $grupo,
            'sesiones' => HorarioDeGrupo::paraElFormulario($grupo),
            'accion' => route('panel-grupo-editar', $grupo),
        ]);
    }

    public function actualizar(Request $request, Grupo $grupo): RedirectResponse
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');

        if (! Permisos::puedeGestionarPromotoria($perfil, $grupo->promotoria)) {
            return $this->alPanel('No tienes acceso a esta promotoría.');
        }

        $datos = $this->validar($request, $grupo->promotoria, $grupo);
        $sesiones = HorarioDeGrupo::leer($request);

        $grupo->fill($datos);
        $grupo->save();

        HorarioDeGrupo::guardar($grupo, $sesiones);

        return $this->alPanel('Grupo actualizado.', exito: true, promotoria: $grupo->promotoria);
    }

    /**
     * Elimina el grupo, si es que no queda nadie apuntado a el.
     *
     * La matricula apunta al grupo con RESTRICT, no con CASCADE: borrar el
     * horario no puede llevarse por delante la inscripcion de nadie. Quien
     * quiera deshacerlo tiene que sacar antes a los estudiantes, que es una
     * decision que se toma persona a persona.
     */
    public function eliminar(Request $request, Grupo $grupo): RedirectResponse
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');

        if (! Permisos::puedeGestionarPromotoria($perfil, $grupo->promotoria)) {
            return $this->alPanel('No tienes acceso a esta promotoría.');
        }

        // Se guarda ANTES de borrar: despues, `$grupo->promotoria` es una
        // consulta a una fila que ya no tiene de quien colgar.
        $promotoria = $grupo->promotoria;

        try {
            // En su propia transaccion: si la base lo rechaza, en PostgreSQL
            // la sentencia fallida dejaria inservible cualquier transaccion
            // que la envolviera.
            DB::transaction(fn () => $grupo->delete());
        } catch (QueryException $e) {
            if (! ErrorDeBaseDeDatos::esFilaEnUso($e)) {
                throw $e;
            }

            return $this->alPanel(
                'No se puede eliminar: hay estudiantes con matrícula asignada a este grupo.',
                promotoria: $promotoria,
            );
        }

        return $this->alPanel('Grupo eliminado.', exito: true, promotoria: $promotoria);
    }

    /**
     * @return array<string, mixed>
     */
    private function validar(Request $request, Promotoria $promotoria, ?Grupo $grupo = null): array
    {
        return $request->validate([
            // Lo unico que no puede repetirse dentro de una promotoria. Se
            // comprueba aqui ademas de en el indice unico para que el mensaje
            // llegue al campo y no como un error del motor.
            'nombre' => [
                ...Reglas::texto(60),
                Reglas::unica('grupos', 'nombre')
                    ->where('promotoria_id', $promotoria->id)
                    ->ignore($grupo?->id),
            ],
            // El nivel SI se repite: una promotoria con mucha gente tiene varios
            // grupos de Basico, y eso es lo normal, no un error.
            'nivel' => ['required', Rule::in(array_keys(Grupo::NIVELES))],
            'salon' => Reglas::texto(40),
            'cupo_maximo' => ['required', 'integer', 'min:0'],
        ], [
            'nombre.unique' => "{$promotoria->nombre} ya tiene un grupo con ese nombre, aunque sea "
                .'de otro nivel: el nombre es lo que los distingue en las listas, así que no puede '
                .'repetirse. Prueba con algo como «Martes tarde» o «Grupo A avanzado».',
        ], [
            'nombre' => 'nombre',
            'nivel' => 'nivel',
        ]);
    }

    /**
     * De vuelta al Panel, ABIERTO por la promotoria en la que se estaba.
     *
     * Sin `abrir`, crear un grupo devolvia el Panel plegado del todo y lo
     * primero que habia que hacer era buscar otra vez donde se estaba. Lo
     * reporto el usuario el 10/09/2026 probando: «me devuelve al panel con todo
     * cerrado».
     *
     * `null` cuando no hay promotoria a la que volver —al eliminar un grupo, la
     * pantalla de destino ya no es la suya— y entonces se va al Panel de
     * siempre.
     */
    private function alPanel(string $mensaje, bool $exito = false, ?Promotoria $promotoria = null): RedirectResponse
    {
        $respuesta = redirect()->route(
            'panel',
            $promotoria === null ? [] : ['abrir' => $promotoria->id]
        );

        if ($mensaje !== '') {
            $respuesta->with($exito ? 'success' : 'error', $mensaje);
        }

        return $respuesta;
    }
}
