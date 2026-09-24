<?php

namespace App\Http\Controllers\Gestion;

use App\Http\Controllers\Controller;
use App\Support\Dependencias;
use App\Support\ErrorDeBaseDeDatos;
use App\Support\Reglas;
use App\Support\Regreso;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * El esqueleto compartido de los cuatro catalogos de Gestion: departamentos,
 * periodos, promotorias y grupos.
 *
 * Es el equivalente de las vistas genericas de Django (`ListView`,
 * `CreateView`, `UpdateView`, `DeleteView`) con su plantilla comun. Las cuatro
 * pantallas hacen exactamente lo mismo y solo cambian en el modelo, los campos y
 * a donde vuelven; escribirlas cuatro veces era garantizar que se separaran.
 *
 * Cada subclase declara su configuracion y, si le hace falta, redefine
 * `urlExito()` — las promotorias y los grupos vuelven a su padre en la jerarquia
 * y no al listado plano.
 */
abstract class RecursoController extends Controller
{
    /** @return class-string<Model> */
    abstract protected function modelo(): string;

    /**
     * ¿El formulario de este catalogo cabe en un modal?
     *
     * Casi todos son de uno a tres campos —un departamento es UN campo, y
     * gastaba una pantalla entera en pedir una palabra—, y ahi sacar a la
     * persona de su lista para eso es todo coste y ningun beneficio.
     *
     * El grupo dice que no, y es el motivo de que esto sea una pregunta y no
     * una regla: su horario es una rejilla semanal de siete filas con dos horas
     * cada una. Metida en un dialogo se aprieta, y en un celular pelea con el
     * teclado. La regla de que va en un modal y que no esta escrita en
     * DESIGN.md; aqui vive el interruptor.
     */
    protected function cabeEnModal(): bool
    {
        return true;
    }

    /**
     * Textos y rutas de esta pantalla.
     *
     * `ruta_eliminar` ES OPCIONAL desde el 23/09/2026, y su ausencia significa
     * QUE AQUI NO SE BORRA. La estreno la ficha de una institucion externa: lo
     * que se hace con una entidad que ya no recibe clases es apagarle la
     * cuenta, no quitarla —sus firmas son la evidencia de que un profesor fue a
     * dictar a una vereda—. Poner una ruta falsa «para que la clave exista» es
     * como se acaba con un modal que cierra sobre una pantalla distinta de la
     * que lo abrio, sin que nada falle.
     *
     * `confirmarBorrado()` y `eliminar()` cortan con 404 cuando falta, asi que
     * la ausencia cierra la puerta ademas de esconderla — aunque alguien
     * registre las rutas por descuido.
     *
     * @return array{
     *     titulo: string,
     *     titulo_nuevo: string,
     *     titulo_editar: string,
     *     ruta_lista: string,
     *     ruta_nuevo: string,
     *     ruta_editar: string,
     *     ruta_eliminar?: string,
     *     creado: string,
     *     actualizado: string,
     * }
     */
    abstract protected function textos(): array;

    /** @return array<string, mixed> lo que la vista de listado necesita ademas */
    abstract protected function listado(Request $request): array;

    /** @return array<string, array<int, mixed>> reglas de validacion */
    abstract protected function reglas(Request $request, ?Model $objeto): array;

    /**
     * Mensajes propios para esas reglas, cuando el de Laravel no basta.
     *
     * Vacio para casi todos: «Ya existe un registro con ese nombre» sirve
     * cuando el nombre es lo unico que hay. Deja de servir en cuanto la regla
     * tiene un porque —un grupo choca por nombre aunque el nivel sea otro— y
     * quien la lee necesita saber cual, no solo que.
     *
     * Lo que devuelva una subclase se SUMA a `Reglas::mensajes()`, que es donde
     * viven los de formato, y gana sobre ellos si repite una clave. Se junta en
     * la base y no en cada subclase a proposito: los seis catalogos comparten
     * estos campos, y el que se olvidara de sumarlos ensenaria «El formato de
     * nombre no es válido» sin decir que es lo que sobra.
     *
     * @return array<string, string>
     */
    protected function mensajes(Request $request, ?Model $objeto): array
    {
        return [];
    }

    /**
     * Los campos del formulario, tal como hay que pintarlos.
     *
     * Se declaran aqui y no en la plantilla —que es una sola para los cuatro
     * catalogos— por la misma razon que en Django los declaraba el ModelForm:
     * asi la pantalla no tiene que preguntar de que modelo se trata para saber
     * que dibujar.
     *
     * Cada entrada es `campo => [etiqueta, tipo, ...]`, donde `tipo` es uno de
     * text, date, number o select. Un select trae ademas `opciones` y, si admite
     * quedarse en blanco, `vacio`.
     *
     * @return array<string, array<string, mixed>>
     */
    abstract protected function campos(Request $request, ?Model $objeto): array;

    protected function urlExito(Model $objeto): string
    {
        return route($this->textos()['ruta_lista']);
    }

    /**
     * Columnas que fija la PANTALLA y no quien llena el formulario.
     *
     * Vacio para los catalogos de siempre. Lo usa la pantalla de grupos de
     * proyeccion, que crea actividades de un tipo concreto sin preguntarlo:
     * ofrecer un desplegable con una sola opcion es preguntar algo que ya se
     * sabe, y aceptarlo por el formulario dejaria que un POST a mano creara un
     * curso desde la pantalla de proyeccion.
     *
     * Solo se aplica al CREAR. Al editar no tiene sentido: lo que ya existe ya
     * tiene su valor, y un campo que el formulario no ofrece tampoco lo cambia.
     *
     * @return array<string, mixed>
     */
    protected function atributosFijos(Request $request): array
    {
        return [];
    }

    /**
     * A donde se va justo despues de CREAR, si no es al mismo sitio que tras
     * editar.
     *
     * Lo usa la pantalla de cursos: un curso recien creado todavia no tiene sus
     * fechas, y devolverlo al listado lo dejaria ahi, incompleto y sin decirlo.
     */
    protected function urlTrasCrear(Model $objeto, Request $request): string
    {
        return $this->urlExito($objeto);
    }

    /**
     * Resuelve el registro de la URL.
     *
     * Se hace a mano y no con el enlace implicito de Laravel porque estos cuatro
     * catalogos comparten los mismos metodos: el tipo del parametro tendria que
     * ser `Model`, que es abstracto, y de ahi Laravel no puede deducir que tabla
     * consultar. La alternativa era repetir los cuatro metodos en cada subclase
     * solo para cambiar una firma.
     */
    protected function buscar(string $id): Model
    {
        return $this->modelo()::findOrFail($id);
    }

    // -----------------------------------------------------------------------

    public function index(Request $request): View
    {
        return view('gestion.lista', $this->seccion($request));
    }

    /**
     * Todo lo que hace falta para pintar este catalogo, sin la pantalla.
     *
     * Existe porque desde el 01/09/2026 un catalogo se pinta en DOS sitios: su
     * propia pantalla y una seccion de «Programas formativos». Es publico —lo
     * llama otro controlador— y es lo mismo que `index()` le pasaba a la vista,
     * no una segunda version de la consulta: duplicarla habria sido garantizar
     * que las dos se separaran, que es justo lo que esta clase existe para
     * evitar.
     *
     * @return array<string, mixed>
     */
    public function seccion(Request $request): array
    {
        return [
            ...$this->textos(),
            'modal' => $this->cabeEnModal(),
            ...$this->listado($request),
        ];
    }

    public function crear(Request $request): View
    {
        $textos = $this->textos();

        return view('gestion.form', [
            'titulo' => $textos['titulo_nuevo'],
            'ruta_lista' => $textos['ruta_lista'],
            'accion' => route($textos['ruta_nuevo']),
            'objeto' => new ($this->modelo()),
            'campos' => $this->campos($request, null),
            'modal' => $this->cabeEnModal(),
            'volver' => Regreso::consulta($request, route($textos['ruta_lista'])),
        ]);
    }

    public function guardar(Request $request): RedirectResponse
    {
        $modelo = $this->modelo();
        $datos = $request->validate($this->reglas($request, null), $this->mensajes($request, null) + Reglas::mensajes());
        // Todo lo que pueda rechazar el formulario se comprueba ANTES de
        // escribir: si esto lanza, no queda un registro a medias en la base.
        $extra = $this->validarExtra($request, null);

        $objeto = new $modelo(array_merge($datos, $this->atributosFijos($request)));
        $objeto->save();
        $this->despuesDeGuardar($objeto, $extra);

        return redirect($this->urlTrasCrear($objeto, $request))->with('success', $this->textos()['creado']);
    }

    public function editar(Request $request, string $id): View
    {
        $objeto = $this->buscar($id);

        $textos = $this->textos();

        return view('gestion.form', [
            'titulo' => $textos['titulo_editar'],
            'ruta_lista' => $textos['ruta_lista'],
            'accion' => route($textos['ruta_editar'], $objeto),
            'objeto' => $objeto,
            'campos' => $this->campos($request, $objeto),
            'modal' => $this->cabeEnModal(),
            // Los filtros y la pagina de la lista de la que se viene, para
            // devolverlos puestos. Sin esto, editar algo de la pagina 3
            // aterrizaba en la 1.
            'volver' => Regreso::consulta($request, $this->urlExito($objeto)),
        ]);
    }

    public function actualizar(Request $request, string $id): RedirectResponse
    {
        $objeto = $this->buscar($id);
        $datos = $request->validate($this->reglas($request, $objeto), $this->mensajes($request, $objeto) + Reglas::mensajes());
        $extra = $this->validarExtra($request, $objeto);

        $objeto->fill($datos);
        $objeto->save();
        $this->despuesDeGuardar($objeto, $extra);

        return redirect(Regreso::url($this->urlExito($objeto), $request->input('volver')))
            ->with('success', $this->textos()['actualizado']);
    }

    /**
     * Comprueba lo que no cabe en `reglas()` y devuelve lo que haga falta para
     * guardarlo despues.
     *
     * Corre ANTES de escribir nada y puede lanzar `ValidationException`: es lo
     * que permite que un horario mal puesto rebote el formulario sin dejar
     * creado un grupo sin horas.
     */
    protected function validarExtra(Request $request, ?Model $objeto): mixed
    {
        return null;
    }

    /**
     * Guarda lo que va ADEMAS de las columnas del propio registro.
     *
     * Vacio para tres de los cuatro catalogos, que son columnas y nada mas. El
     * grupo lo usa para su horario, que vive en filas aparte. Corre con el
     * objeto ya guardado, que es cuando tiene id al que colgar esas filas, y
     * recibe lo que `validarExtra()` ya dio por bueno.
     */
    protected function despuesDeGuardar(Model $objeto, mixed $extra): void
    {
        //
    }

    /**
     * La pantalla de confirmacion, que dice la verdad antes de preguntar.
     *
     * Si algo bloquea el borrado no se ofrece boton: preguntar «¿seguro?» para
     * negarse despues es hacer perder el viaje, y la respuesta ya se sabe.
     */
    public function confirmarBorrado(Request $request, string $id): View
    {
        $textos = $this->textos();
        // Sin `ruta_eliminar`, este catalogo NO SE BORRA y esta pantalla no
        // existe. Se corta aqui y en `eliminar()` porque son dos puertas: la
        // que pregunta y la que ejecuta, y cerrar solo la primera deja el POST
        // abierto a quien lo componga a mano.
        abort_unless(isset($textos['ruta_eliminar']), 404);

        $objeto = $this->buscar($id);

        return view('gestion.confirma-borrado', [
            'objeto' => $objeto,
            'ruta_lista' => $textos['ruta_lista'],
            'accion' => route($textos['ruta_eliminar'], $objeto),
            // Los filtros con los que se estaba mirando la lista, para
            // devolverlos puestos. Se leen AQUI porque este es el ultimo momento
            // en que la pagina anterior sigue siendo esa lista.
            'volver' => Regreso::consulta($request, $this->urlExito($objeto)),
            ...Dependencias::de($objeto),
        ]);
    }

    public function eliminar(Request $request, string $id): RedirectResponse
    {
        // La otra mitad del corte de `confirmarBorrado()`: sin `ruta_eliminar`,
        // aqui no se borra nada por ningun camino.
        abort_unless(isset($this->textos()['ruta_eliminar']), 404);

        $objeto = $this->buscar($id);

        // El destino se calcula ANTES de borrar: despues, `$objeto->area_id` y
        // compania siguen en memoria pero la fila ya no esta, y las subclases que
        // vuelven al padre necesitan ese dato intacto.
        $destino = Regreso::url($this->urlExito($objeto), $request->input('volver'));

        try {
            $objeto->delete();
        } catch (QueryException $e) {
            if (! ErrorDeBaseDeDatos::esFilaEnUso($e)) {
                throw $e;
            }

            // La pantalla ya avisa antes de preguntar, asi que aqui no deberia
            // llegar nadie. Se queda igual porque el boton no es la unica forma
            // de enviar este POST, y porque entre que se pinta la pagina y se
            // pulsa puede entrar una matricula nueva.
            return redirect($destino)->with('error', Dependencias::avisoDeProtegido($objeto));
        }

        return redirect($destino)->with('success', 'Eliminado.');
    }
}
