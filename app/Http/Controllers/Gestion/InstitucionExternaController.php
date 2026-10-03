<?php

namespace App\Http\Controllers\Gestion;

use App\Models\InstitucionExterna;
use App\Models\Perfil;
use App\Models\User;
use App\Support\Reglas;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Las instituciones que reciben programas: la escuela rural, el colegio.
 *
 * UN FORMULARIO QUE CREA TRES COSAS —la ficha, la cuenta y el perfil— y por eso
 * no le basta el camino corto de `RecursoController`: `guardar()` va sobreescrito
 * para que las tres entren en UNA transaccion. A medias esto deja lo peor de los
 * dos mundos: una institucion sin cuenta no puede verificar nada, y una cuenta
 * sin institucion es un usuario que entra, no ve nada y nadie sabe de donde
 * salio (ver por que su rol no se reparte desde Usuarios, en
 * `Permisos::rolesAsignablesPor`).
 *
 * DOS NOMBRES EN UN MISMO FORMULARIO Y NO SON EL MISMO DATO. Arriba va el de la
 * ENTIDAD —«I. E. Rural El Carmen»— y abajo el de la PERSONA que va a firmar.
 * La escuela sobrevive al funcionario: el dia que esa persona cambie de trabajo
 * se le edita el nombre y la clave a la cuenta, y la institucion sigue siendo la
 * misma con sus clases verificadas intactas. Por eso la ficha y el perfil son
 * dos filas y no una.
 *
 * EL TELEFONO VA CON LA REGLA DE ENTIDAD y no con la de celular, igual que el de
 * la propia casa en Configuracion: aqui lo corriente es un fijo de vereda con
 * extension, y los diez digitos exactos dejarian fuera justo a las
 * instituciones para las que esto existe. Ver `Reglas::telefonoDeEntidad()`.
 *
 * NO HAY BORRADO. Una institucion con programas la bloquea `Dependencias`, y
 * una sin ellos tampoco se ofrece borrar: lo que se hace con una entidad que ya
 * no recibe clases es DESACTIVARLE la cuenta, que es la politica de toda la
 * casa para lo que tiene historial. Si algun dia hace falta, el borrado tendria
 * que llevarse ficha y perfil en ese orden, y `Dependencias` ya lo dice.
 */
class InstitucionExternaController extends RecursoController
{
    protected function modelo(): string
    {
        return InstitucionExterna::class;
    }

    /**
     * En modal SI: son cinco campos cortos y nada que se estreche mal.
     *
     * Es el criterio de `RecursoController::cabeEnModal()` —no cuantos campos
     * hay, sino si el contenido pierde algo al angostarse— y el formulario de
     * usuario, con once, lo tiene encendido.
     */
    protected function cabeEnModal(): bool
    {
        return true;
    }

    protected function textos(): array
    {
        return [
            'titulo' => 'Instituciones externas',
            'titulo_nuevo' => 'Registrar una institución externa',
            'titulo_editar' => 'Editar la institución externa',
            // Vuelve a «Programas formativos», que es de donde se sale: la
            // institucion se registra desde la seccion de programas externos y
            // el modal tiene que cerrar sobre la misma pantalla que lo abrio.
            'ruta_lista' => 'gestion-programas',
            'ruta_nuevo' => 'institucion-externa-nueva',
            'ruta_editar' => 'institucion-externa-editar',
            // NO HAY `ruta_eliminar` a proposito, y su ausencia es la que
            // sostiene la decision: aqui no se borra, se desactiva la cuenta.
            // Poner una que apuntara a otro sitio «para que la clave exista»
            // es como se acaba con un modal que cierra sobre una pantalla
            // distinta de la que lo abrio, sin que nada falle.
            'creado' => 'Institución registrada. Ya puedes abrirle un programa externo y entregarle su QR.',
            'actualizado' => 'Institución actualizada.',
        ];
    }

    /**
     * El listado, para la seccion de «Programas formativos».
     *
     * `with('perfil.user')` y no preguntarlo en la plantilla: la tabla pinta
     * una fila por institucion con el nombre de su funcionario y si la cuenta
     * esta activa, y eso dentro del bucle es dos consultas por fila.
     */
    protected function listado(Request $request): array
    {
        return [
            'instituciones' => InstitucionExterna::with('perfil.user')
                ->withCount('programas')
                ->orderBy('nombre')
                ->get(),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    protected function campos(Request $request, ?Model $objeto): array
    {
        /** @var InstitucionExterna|null $objeto */
        $campos = [
            'nombre' => [
                'etiqueta' => 'Nombre de la institución',
                'tipo' => 'text',
                'max' => 120,
                'ayuda' => 'La escuela, el colegio o la fundación donde se va a dictar.',
            ],
            'direccion' => [
                'etiqueta' => 'Dirección',
                'tipo' => 'text',
                'max' => 160,
                'opcional' => true,
                'ayuda' => 'La vereda o la dirección, para saber a dónde se va.',
            ],
            'telefono' => [
                'etiqueta' => 'Teléfono',
                'tipo' => 'text',
                'max' => 40,
                'opcional' => true,
                'ayuda' => 'El de la institución. Admite fijo con extensión.',
            ],
            // Entre que fechas se dicta alla (03/10/2026): gobiernan la alerta
            // de las semanas sin clase. Opcionales las dos, y la ayuda dice lo
            // que pasa cuando faltan, porque no se deduce.
            'clases_desde' => [
                'etiqueta' => 'Las clases empiezan el',
                'tipo' => 'date',
                'opcional' => true,
                'ayuda' => 'Desde la primera semana completa (de lunes a domingo) a partir de esta fecha, cada semana sin ninguna clase allá sale en Alertas. Vacío, no se avisa.',
            ],
            'clases_hasta' => [
                'etiqueta' => 'Las clases terminan el',
                'tipo' => 'date',
                'opcional' => true,
                'ayuda' => 'Después de esta fecha deja de avisar. Vacío, sigue avisando hasta que la pongas.',
            ],
            'funcionario' => [
                'etiqueta' => 'Persona que verifica',
                'tipo' => 'text',
                'max' => 90,
                'valor' => $objeto?->perfil?->nombre_completo,
                'ayuda' => 'Quien da fe, desde la institución, de que el profesor fue a dar la clase.',
            ],
            'username' => [
                'etiqueta' => 'Usuario',
                'tipo' => 'text',
                'max' => 150,
                'valor' => $objeto?->perfil?->user?->username,
                'ayuda' => 'Con esto entra esa persona. Se lo entregas junto al QR.',
            ],
        ];

        // La contrasena solo se PIDE al crear. Al editar se ofrece en blanco y
        // en blanco significa «dejala como esta»: obligar a reescribirla para
        // corregir un telefono es como se acaban poniendo claves de tres letras.
        $campos['password'] = [
            'etiqueta' => $objeto === null ? 'Contraseña' : 'Contraseña nueva',
            'tipo' => 'password',
            'opcional' => $objeto !== null,
            'ayuda' => $objeto === null
                ? 'Mínimo 8 caracteres.'
                : 'Déjala en blanco para no cambiarla.',
        ];

        return $campos;
    }

    /** @return array<string, array<int, mixed>> */
    protected function reglas(Request $request, ?Model $objeto): array
    {
        /** @var InstitucionExterna|null $objeto */
        return [
            'nombre' => Reglas::texto(120),
            // `Reglas::texto()` y NO `nombreDePersona()`: el de una entidad
            // lleva puntos, numeros y siglas —«I. E. R. San José N.º 2»— y la
            // lista blanca de nombres de persona lo dejaria fuera. Es la misma
            // razon por la que esa lista no alcanza a los catalogos.
            'direccion' => Reglas::texto(160, obligatorio: false),
            'telefono' => Reglas::telefonoDeEntidad(40),
            'clases_desde' => ['nullable', 'date'],
            'clases_hasta' => ['nullable', 'date', 'after_or_equal:clases_desde'],
            // El de la PERSONA si lleva la lista blanca: es un nombre de
            // persona y nada mas.
            'funcionario' => Reglas::nombreDePersona(90),
            'username' => Reglas::usuario(
                Rule::unique('users', 'username')->ignore($objeto?->perfil?->user_id)
            ),
            'password' => $objeto === null
                ? ['required', Password::min(8)]
                : ['nullable', Password::min(8)],
        ];
    }

    protected function mensajes(Request $request, ?Model $objeto): array
    {
        return [
            'funcionario.required' => 'Escribe el nombre de quien va a verificar las clases.',
            'clases_hasta.after_or_equal' => 'Las clases no pueden terminar antes de empezar.',
        ];
    }

    /**
     * Crear: la ficha, la cuenta y el perfil, o ninguna de las tres.
     *
     * Va sobreescrito entero y no por los ganchos de la clase de arriba porque
     * aqui el orden importa y hay que envolverlo: el perfil necesita el usuario,
     * la ficha necesita el perfil, y `RecursoController::guardar()` escribe el
     * objeto ANTES de llamar a `despuesDeGuardar()`. Con esa forma, un fallo al
     * crear la cuenta dejaria una institucion sin nadie que la verifique.
     */
    public function guardar(Request $request): RedirectResponse
    {
        $datos = $request->validate($this->reglas($request, null), $this->mensajes($request, null) + Reglas::mensajes());

        $institucion = DB::transaction(function () use ($datos) {
            $user = User::create([
                'username' => $datos['username'],
                'password' => Hash::make($datos['password']),
                'activo' => true,
            ]);

            $perfil = Perfil::create([
                'user_id' => $user->id,
                'rol' => Perfil::INSTITUCION_EXTERNA,
                'nombre_completo' => $datos['funcionario'],
            ]);

            return InstitucionExterna::create([
                'nombre' => $datos['nombre'],
                'direccion' => $datos['direccion'] ?: null,
                'telefono' => $datos['telefono'] ?: null,
                'clases_desde' => $datos['clases_desde'] ?? null,
                'clases_hasta' => $datos['clases_hasta'] ?? null,
                'perfil_id' => $perfil->id,
            ]);
        });

        return redirect()
            ->route($this->textos()['ruta_lista'])
            ->with('success', $this->textos()['creado'].' ('.$institucion->nombre.')');
    }

    /**
     * Editar: los datos de la entidad y los de su cuenta, juntos.
     *
     * La contrasena en blanco NO se toca, y eso es lo que permite corregir un
     * telefono sin inventarse una clave. Cuando SI viene, se escribe sobre
     * `$perfil->user` y no sobre una segunda instancia del mismo usuario: son
     * dos objetos de la misma fila y guardar por el camino equivocado es lo que
     * echa a la gente del sistema (ver `MiPerfilController`). Aqui no echa a
     * nadie —quien edita es el administrador, no el dueno de la cuenta— pero el
     * camino se mantiene igual para no tener dos formas de hacer lo mismo.
     */
    public function actualizar(Request $request, string $id): RedirectResponse
    {
        /** @var InstitucionExterna $institucion */
        $institucion = $this->buscar($id);
        $datos = $request->validate($this->reglas($request, $institucion), $this->mensajes($request, $institucion) + Reglas::mensajes());

        DB::transaction(function () use ($institucion, $datos) {
            $institucion->fill([
                'nombre' => $datos['nombre'],
                'direccion' => $datos['direccion'] ?: null,
                'telefono' => $datos['telefono'] ?: null,
                'clases_desde' => $datos['clases_desde'] ?? null,
                'clases_hasta' => $datos['clases_hasta'] ?? null,
            ])->save();

            $perfil = $institucion->perfil;
            $perfil->nombre_completo = $datos['funcionario'];
            $perfil->save();

            $user = $perfil->user;
            $user->username = $datos['username'];

            if (($datos['password'] ?? '') !== '') {
                $user->password = Hash::make($datos['password']);
            }

            $user->save();
        });

        return redirect()
            ->route($this->textos()['ruta_lista'])
            ->with('success', $this->textos()['actualizado']);
    }

    /**
     * Enciende o apaga la cuenta de la institucion.
     *
     * Es lo que sustituye al borrado, y vive aqui y no en Gestion → Usuarios
     * porque esas cuentas no se listan alli: su ficha no se puede abrir con el
     * formulario de usuarios sin arriesgarse a cambiarle el rol sin querer (ver
     * `Permisos::puedeEditarUsuario`). Apagada, esa persona no entra y sus
     * clases se quedan como estan: lo ya firmado sigue firmado.
     */
    public function alternarActivo(Request $request, string $id): RedirectResponse
    {
        /** @var InstitucionExterna $institucion */
        $institucion = $this->buscar($id);
        $user = $institucion->perfil->user;
        $user->activo = ! $user->activo;
        $user->save();

        return redirect()
            ->route($this->textos()['ruta_lista'])
            ->with('success', $user->activo
                ? "«{$institucion->nombre}» vuelve a poder entrar a verificar."
                : "«{$institucion->nombre}» ya no puede entrar. Lo que ya verificó se queda como está.");
    }

    public function index(Request $request): View
    {
        return view('gestion.instituciones-externas', $this->seccion($request));
    }
}
