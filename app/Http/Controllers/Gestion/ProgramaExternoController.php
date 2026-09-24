<?php

namespace App\Http\Controllers\Gestion;

use App\Models\Actividad;
use App\Models\InstitucionExterna;
use App\Models\Perfil;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Programas externos: lo que un profesor de la casa dicta en OTRA institucion.
 *
 * Hereda de `ActividadController` porque por debajo es una actividad mas —mismo
 * responsable, mismas sesiones, misma asistencia— y lo unico que cambia es el
 * tipo. Lo que SI cambia de cara a quien lo usa esta en tres sitios:
 *
 * 1. NO HAY CUPO NI ENLACE. El cupo gobierna una puerta publica que aqui no
 *    existe, y un campo de cupo sin puerta que cerrar es un numero que no hace
 *    nada. Por eso `campos()` tira el de la clase de arriba en vez de heredarlo.
 * 2. HAY INSTITUCION, y es obligatoria: sin ella nadie puede dar fe de que el
 *    profesor fue, que es la mitad de lo que esto es. El CHECK de la base lo
 *    exige tambien, en las dos direcciones.
 * 3. LA LISTA NO SE INSCRIBE, SE ESCRIBE. Quien la puebla es el profesor, desde
 *    el Panel, nombre y edad. Aqui no hay nada de eso.
 *
 * NO SE OFRECE CREAR SI NO HAY NINGUNA INSTITUCION REGISTRADA, y de eso se
 * encarga la pantalla: un formulario cuyo unico desplegable obligatorio sale
 * vacio es un formulario que no se puede enviar, y este proyecto ya pago una
 * vez el precio de pintar un boton que no hace nada.
 */
class ProgramaExternoController extends ActividadController
{
    protected function tipos(): array
    {
        return [Actividad::EXTERNO];
    }

    protected function textos(): array
    {
        return [
            'titulo' => 'Programas externos',
            'titulo_nuevo' => 'Nuevo programa externo',
            'titulo_editar' => 'Editar programa externo',
            // A «Programas formativos», como los otros dos catalogos de
            // actividades: es donde vive esta lista y donde estaba quien abrio
            // el modal. La pantalla suelta sigue existiendo en su URL.
            'ruta_lista' => 'gestion-programas',
            'ruta_nuevo' => 'programa-externo-nuevo',
            'ruta_editar' => 'programa-externo-editar',
            'ruta_eliminar' => 'programa-externo-eliminar',
            // `ruta_enlace` NO EXISTE aqui, y es la ausencia que importa: el
            // interruptor de «abierta» gobierna una puerta publica que un
            // programa externo no tiene. La plantilla compartida pregunta por
            // esta clave antes de pintar nada, asi que sin ella no hay boton.
            'creado' => 'Programa externo creado. El profesor ya puede armar la lista en el sitio.',
            'actualizado' => 'Programa actualizado.',
        ];
    }

    /** @return array<string, array<string, mixed>> */
    protected function campos(Request $request, ?Model $objeto): array
    {
        $campos = parent::campos($request, $objeto);

        return [
            'nombre' => [
                ...$campos['nombre'],
                'ayuda' => 'Cómo se llama lo que se va a dictar allá. Por ejemplo: «Guitarra — El Carmen».',
            ],
            'institucion_id' => [
                'etiqueta' => 'Institución donde se dicta',
                'tipo' => 'select',
                'opciones' => InstitucionExterna::orderBy('nombre')->pluck('nombre', 'id')->all(),
                'ayuda' => 'Su funcionario es quien va a verificar cada clase.',
            ],
            'responsable_id' => [
                ...$campos['responsable_id'],
                'etiqueta' => 'Profesor que va',
                'ayuda' => 'Quien inicia cada clase allá y arma la lista de asistentes.',
            ],
            // Y NO va `cupo_maximo`: ver la cabecera de la clase.
        ];
    }

    /** @return array<string, array<int, mixed>> */
    protected function reglas(Request $request, ?Model $objeto): array
    {
        $reglas = parent::reglas($request, $objeto);

        return [
            'nombre' => $reglas['nombre'],
            'responsable_id' => $reglas['responsable_id'],
            'institucion_id' => ['required', Rule::exists('instituciones_externas', 'id')],
        ];
    }

    /**
     * El tipo lo fija la pantalla, no el formulario.
     *
     * Mismo motivo que en los grupos de proyeccion: ofrecer un desplegable con
     * una sola opcion es preguntar algo que ya se sabe, y aceptarlo por el
     * formulario dejaria que un POST a mano creara un curso desde aqui — que
     * ademas rebotaria contra el CHECK, porque traeria institucion.
     *
     * `abierta` SE APAGA AL NACER, y es el segundo cerrojo de una puerta que ya
     * esta cerrada por tipo en `InscripcionActividadController`. Se pone igual
     * porque las dos cosas se leen en sitios distintos y la primera que alguien
     * mire tiene que decir la verdad: este programa no recibe inscripciones.
     */
    protected function atributosFijos(Request $request): array
    {
        return [
            ...parent::atributosFijos($request),
            'tipo' => Actividad::EXTERNO,
            'abierta' => false,
        ];
    }

    /**
     * Lo mismo que la clase de arriba, mas la institucion de cada fila.
     *
     * `with('institucion')` y no preguntarlo en la plantilla: la tabla pinta
     * una fila por programa con el nombre de su escuela, y eso dentro del bucle
     * es una consulta por fila.
     *
     * @return array<string, mixed>
     */
    public function seccion(Request $request): array
    {
        $datos = parent::seccion($request);
        $datos['actividades']->load('institucion');

        // Para que la pantalla sepa si puede ofrecer «+ Nuevo». Sin ninguna
        // institucion registrada el formulario no se puede enviar, asi que lo
        // que se ofrece en su lugar es registrar la primera.
        $datos['hay_instituciones'] = InstitucionExterna::exists();

        return $datos;
    }

    /**
     * Quien mira, acotado igual que las demas actividades.
     *
     * NO SE AMPLIA PARA LA INSTITUCION EXTERNA, y conviene dejarlo escrito: su
     * funcionario no entra a Gestion en absoluto —sus rutas piden
     * `rol:administrador,director`— y lo que ve es su propia bandeja. Si algun
     * dia se le abriera esta pantalla, el recorte tendria que pasar por
     * `Permisos::verificaLaActividad()` y no por el responsable.
     */
    /**
     * Su propia pantalla, y no la de cursos y proyeccion.
     *
     * `gestion.actividades` pinta la tabla del enlace y el cupo, que aqui no
     * existen. El porque de que sean dos plantillas esta en
     * `partials/tabla-programas-externos`.
     */
    public function index(Request $request): View
    {
        return view('gestion.programas-externos', $this->seccion($request));
    }

    protected function buscar(string $id): Model
    {
        /** @var Perfil $perfil */
        $perfil = request()->attributes->get('perfil');

        return Actividad::externos()
            ->when(
                $perfil->rol !== 'administrador',
                fn ($q) => $q->where('responsable_id', $perfil->id)
            )
            ->findOrFail($id);
    }
}
