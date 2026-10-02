<?php

namespace App\Http\Controllers;

use App\Models\Actividad;
use App\Models\Acudiente;
use App\Models\DatosEstudiante;
use App\Models\DocumentoEstudiante;
use App\Models\DocumentoRequerido;
use App\Models\EncuestaDemografica;
use App\Models\Grupo;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Rules\ImagenProcesable;
use App\Support\Auditoria;
use App\Support\Companeros;
use App\Support\Documento;
use App\Support\GestionAsistida;
use App\Support\HorarioSemanal;
use App\Support\Imagen;
use App\Support\Reglas;
use App\Support\ResumenAsistencia;
use App\Support\Suplantacion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

/**
 * Lo que cada quien completa aqui, ya con sesion, es lo que los formularios
 * publicos de autorregistro NO piden por seguridad: foto de perfil, copia del
 * documento de identidad (solo estudiantes) y la encuesta demografica
 * (obligatoria para todos).
 *
 * Son formularios independientes en una sola pagina, distinguidos por el campo
 * oculto `accion`. Cada uno guarda por su cuenta: los papeles se consiguen de a
 * uno y en dias distintos, y un unico boton de "guardar todo" obligaria a
 * tenerlos todos a la mano el mismo dia.
 */
class MiPerfilController extends Controller
{
    /** Lo que se admite como foto o como papel. */
    private const IMAGENES = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    private const ARCHIVOS = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf'];

    public function mostrar(Request $request): View|RedirectResponse
    {
        $perfil = $request->user()->perfil;

        if ($perfil === null) {
            return redirect()->route('login')->with(
                'error',
                'Tu cuenta no tiene un perfil asociado. Contacta al administrador.'
            );
        }

        $datos = $perfil->datosEstudiante;
        $encuesta = $perfil->encuesta;

        // El panel de asistencia camina por los periodos donde esta persona
        // tiene algo. El resto de la pagina —foto, papeles, encuesta— no cambia
        // con el periodo, y por eso va como parametro de consulta y no en el
        // camino: lo que se mueve es una seccion, no la pantalla.
        $esEstudiante = $perfil->rol === 'estudiante';
        $navegacion = ResumenAsistencia::navegacionDePeriodos(
            $perfil,
            $request->query('periodo'),
            $esEstudiante
        );
        $periodo = $navegacion['periodo'];

        return view('perfil.mi-perfil', [
            'perfil' => $perfil,
            // Cual de los tres marcar en «Apariencia». Sale de la galleta del
            // aparato y no de la cuenta: ver `Support\Tema`.
            'datos' => $datos,
            'encuesta' => $encuesta,
            // Una ranura por papel pedido, con lo que ya subio si es que subio
            // algo.
            'papeles' => $datos === null ? [] : DocumentoRequerido::activos()->ordenados()->get()
                ->map(fn (DocumentoRequerido $requerido) => [
                    'requerido' => $requerido,
                    'entrega' => $datos->documentos
                        ->first(fn (DocumentoEstudiante $d) => $d->requerido_id === $requerido->id
                            && $d->archivo !== ''),
                ])
                ->all(),
            // Vacia cuando no hay nada que pedir, asi que la plantilla la usa
            // tambien como "¿esta pendiente?". Sin encuesta empezada no se
            // listan preguntas sueltas: ahi lo que falta es la encuesta entera.
            'faltanPreguntas' => $encuesta?->preguntas_faltantes ?? [],
            'estadisticas' => $this->estadisticas($perfil),
            // Cuantas matriculas vigentes hay AHORA que certificar. Cero
            // —o no ser estudiante— quita la seccion entera: un boton que baja
            // un papel en blanco es peor que no tener boton.
            'certificables' => $esEstudiante ? $this->certificables($perfil) : 0,
            // La rejilla semanal va SIEMPRE del periodo en curso, aunque las
            // flechas del panel de asistencia esten mirando otro: un horario
            // dice donde estar esta semana, y el de hace dos semestres no le
            // sirve a nadie para nada.
            'horario' => HorarioSemanal::de($perfil, Periodo::enCurso()),
            'periodo' => $periodo,
            // Las flechas del panel de asistencia. Conservan el resto de la URL
            // vacio a proposito: esta pantalla no tiene otros parametros.
            'periodoAtras' => $navegacion['atras']
                ? route('mi-perfil', ['periodo' => $navegacion['atras']->id])
                : null,
            'periodoAdelante' => $navegacion['adelante']
                ? route('mi-perfil', ['periodo' => $navegacion['adelante']->id])
                : null,
            'periodoEsElEnCurso' => $periodo !== null && $periodo->activo,
            // El mismo panel de asistencia que ve el personal en la ficha de una
            // persona, aqui puesto para que cada quien vea el SUYO: un estudiante
            // sus clases y sus faltas, quien dicta las que dio y cuantas le
            // verificaron. Es informacion sobre uno mismo y no habia ninguna
            // razon para que hubiera que pedirsela a otro.
            //
            // Sin acotar por promotoria: aqui no hay nada que esconderle a nadie
            // de lo suyo. El recorte de «solo mis promotorias» existe en la ficha
            // porque ahi mira un tercero.
            'asistencia' => $esEstudiante
                ? ResumenAsistencia::deEstudiante($perfil, $periodo)
                : ResumenAsistencia::deProfesor($perfil, $periodo),
        ]);
    }

    public function guardar(Request $request): RedirectResponse
    {
        $perfil = $request->user()->perfil;

        abort_if($perfil === null, 404);

        return match ($request->input('accion')) {
            'foto' => $this->guardarFoto($request, $perfil),
            'datos' => $this->guardarDatos($request, $perfil),
            'contacto' => $this->guardarContacto($request, $perfil),
            'correo' => $this->guardarCorreo($request, $perfil),
            'papel' => $this->guardarPapel($request, $perfil),
            'encuesta' => $this->guardarEncuesta($request, $perfil),
            'clave' => $this->guardarClave($request, $perfil),
            'usuario' => $this->guardarUsuario($request, $perfil),
            default => redirect()->route('mi-perfil'),
        };
    }

    private function guardarFoto(Request $request, Perfil $perfil): RedirectResponse
    {
        $request->validate([
            'foto_perfil' => ['required', 'image', 'mimes:'.implode(',', self::IMAGENES), 'max:8192', new ImagenProcesable],
        ], [
            // El de Laravel dice «no debe ser mayor que 8192 kilobytes», que en
            // un telefono no le dice nada a nadie. El tope por MEGAPIXELES —que
            // es otro y ataja lo que este no ve— lo explica `ImagenProcesable`.
            'foto_perfil.max' => 'La foto pesa demasiado. El máximo son 8 MB: '
                .'tómala con menos resolución desde los ajustes de la cámara, '
                .'o envía una copia más pequeña.',
        ], ['foto_perfil' => 'foto de perfil']);

        // Se convierte a WebP, se endereza y se acota antes de tocar el disco
        // (ver `Imagen`). Es una diferencia deliberada con el original, que
        // guardaba el archivo tal como llegaba del celular.
        $contenido = Imagen::aWebp($request->file('foto_perfil'));
        $ruta = "fotos_perfil/{$perfil->id}-".uniqid().'.webp';

        Storage::disk('local')->put($ruta, $contenido);
        $this->borrarAnterior($perfil->foto_perfil);

        $perfil->foto_perfil = $ruta;
        $perfil->save();

        return redirect()->route('mi-perfil')->with('success', 'Tu foto de perfil quedó guardada.');
    }

    /**
     * CAMBIARSE LA PROPIA CONTRASENA.
     *
     * Pedido por el usuario el 05/09/2026, y hasta ese dia NO EXISTIA por
     * ningun camino: la unica forma de cambiar una clave era que un
     * administrador la escribiera desde el formulario de usuario, donde el campo
     * se llama «Contrasena temporal» — un nombre que ya daba por hecho este otro
     * lado y llevaba meses sin el. Eso choca ademas con una decision que el
     * proyecto ya tenia tomada: el enlace de registro de profesor sigue abierto
     * justamente «para que el profesor elija su propia contrasena en vez de que
     * se la teclee un administrador y la sepa».
     *
     * TRES COSAS QUE NO SE TOCAN SIN VOLVER A PENSARLAS:
     *
     * 1. SE PIDE LA CONTRASENA ACTUAL. No es tramite: una sesion abierta en un
     *    celular prestado o sin bloquear basta para llegar hasta aqui, y sin
     *    este campo cualquiera que pase por delante del telefono de otro le
     *    quita la cuenta para siempre. Es el mismo razonamiento que ya sostiene
     *    la confirmacion de borrado, escrito en `UsuarioController`.
     *
     * 2. NO SE PUEDE DESDE UNA GESTION ASISTIDA. El administrador trabaja desde
     *    la cuenta de otra persona para ayudarla, no para quedarse con ella:
     *    cambiarle la clave desde dentro la deja fuera de su propia cuenta y sin
     *    forma de volver, porque en este sistema nada avisa a nadie de nada. Se
     *    suma a los otros dos cortes de `GestionAsistida` —escribir asistencia y
     *    confirmar una clase— y por la misma razon de fondo.
     *
     *    Ojo: la puerta va aqui y no solo en la plantilla. Esconder la seccion
     *    no cierra la peticion.
     *
     *    SE PLANTEO QUITARLA el 05/09/2026 y se decidio DEJARLA. El usuario
     *    pregunto por un caso real y bueno: esto son procesos formativos con
     *    poblaciones distintas, y una persona mayor que olvida su clave o un
     *    nino que la creo sin sus papas no pueden quedarse sin cuenta. No se
     *    quedan: **el administrador ya cambia la clave de cualquiera** desde
     *    Gestion → Usuarios → Editar, en el campo «Contrasena temporal», y
     *    `Permisos::puedeEditarUsuario()` le deja sobre todo el mundo. Este
     *    corte no cierra ese camino; cierra solo el de hacerlo DESDE DENTRO de
     *    la cuenta ajena, que ademas pedirla «contrasena actual» que el
     *    administrador no sabe.
     *
     *    Si alguien vuelve a proponerlo, lo que hay que mirar antes es que
     *    quitar el requisito de la clave actual —necesario para que funcione
     *    asistiendo— deja un formulario que cambia una contrasena sin pedir
     *    nada, alcanzable con una sesion abierta en un telefono sin bloquear.
     *
     * 3. SE GUARDA EN LA INSTANCIA DE `Auth` Y NO EN `$perfil->user`. Es lo
     *    unico de aqui que no se deduce leyendo, y costo una hora: son dos
     *    objetos distintos de la misma fila.
     *
     *    Este proyecto tiene `AuthenticateSession` en `bootstrap/app.php`, y ese
     *    middleware compara en cada peticion el hash que lleva la sesion con el
     *    de `$request->user()`. Es lo que hace que cambiar la clave EXPULSE a
     *    quien te la hubiera robado, que es justo lo que uno quiere. Pero
     *    guardando en `$perfil->user` se cambia la FILA y no la instancia que el
     *    middleware mira: la sesion se queda apuntando a un hash que ya no es de
     *    nadie y la peticion SIGUIENTE te manda al login. Sin fallar y sin
     *    aviso — se guarda bien, y lo que ves es la pantalla de entrar, o sea
     *    que parece que el sistema se rompio.
     *
     *    Con `$request->user()` no hay que tocar la sesion a mano. Se intento
     *    —un `session()->put('password_hash_web', ...)`— y se QUITO al medir que
     *    no cambiaba nada: con la instancia mala no bastaba, y con la buena
     *    sobra. Una linea que no hace nada acaba leyendose como si hiciera algo.
     *
     *    NINGUNA PRUEBA DE PHP VE ESTO, comprobado: `actingAs()` deja el usuario
     *    fijado en el guard durante todo el test, asi que la comparacion del
     *    middleware no llega a fallar y la prueba pasa en verde con el fallo
     *    puesto. Se vio con `curl`, en cuatro peticiones seguidas.
     */
    private function guardarClave(Request $request, Perfil $perfil): RedirectResponse
    {
        if (GestionAsistida::activa()) {
            return redirect()->route('mi-perfil')->with(
                'error',
                'No se puede cambiar la contraseña de alguien desde una gestión asistida. '
                .'Vuelve a tu cuenta para cambiar la tuya.'
            );
        }

        // Lo mismo entrando desde el panel de instituciones (paso 4c): la
        // contraseña es de la persona, no de quien le da soporte.
        if (Suplantacion::activa()) {
            return redirect()->route('mi-perfil')->with(
                'error',
                'No se puede cambiar la contraseña de esta cuenta entrando desde el panel de instituciones.'
            );
        }

        // LA INSTANCIA DE `Auth`, no `$perfil->user`. Son dos objetos distintos
        // de la misma fila, y `AuthenticateSession` compara contra la de Auth:
        // guardando en la otra, la sesion se queda mirando un hash que ya no es
        // el de nadie y la peticion SIGUIENTE te manda a la pantalla de entrar.
        $usuario = $request->user();

        $request->validate([
            'clave_actual' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', 'different:clave_actual', Password::defaults()],
        ], [
            'password.different' => 'La contraseña nueva tiene que ser distinta de la actual.',
        ], [
            'clave_actual' => 'contraseña actual',
            'password' => 'contraseña nueva',
        ]);

        // Se comprueba DESPUES de validar el formato para no decirle a quien
        // teclea mal que la actual estaba bien: los dos mensajes salen juntos.
        if (! Hash::check($request->input('clave_actual'), $usuario->password)) {
            return back()->withErrors([
                'clave_actual' => 'Esa no es tu contraseña actual.',
            ]);
        }

        // El modelo la castea a `hashed`, asi que se asigna en claro.
        $usuario->password = $request->input('password');
        $usuario->save();

        Auditoria::registrar('clave.cambiada', [], $perfil);

        return redirect()->route('mi-perfil')->with(
            'success',
            'Tu contraseña quedó cambiada. Si habías entrado en otro dispositivo, '
            .'ahí tendrás que volver a iniciar sesión.'
        );
    }

    /**
     * CAMBIARSE EL PROPIO NOMBRE DE USUARIO.
     *
     * Lo pidio el usuario el 10/09/2026, y esto INVIERTE a medias lo que decia
     * «Mis datos» desde el 07/09 —«el usuario con el que entras no se cambia
     * desde aqui»—. Se invierte por la misma razon que abrio aquel formulario:
     * quien tiene algo mal escrito no podia arreglarlo solo, y aqui muerde mas
     * de lo que parece porque en este sistema el `username` NO es un apodo
     * tecnico. Medido en produccion: 179 personas usan su correo y 239 su
     * nombre con espacios («Ainhoa Davila»). O sea que un nombre mal tecleado
     * el dia de inscribirse se queda tambien en la credencial, y hasta hoy solo
     * un administrador podia corregirlo.
     *
     * LO QUE NO CAMBIA: sigue sin poderse desde el formulario de «Mis datos»,
     * ni colandolo alli a mano. Son dos formularios porque son dos cosas: aquel
     * corrige datos, este cambia una CREDENCIAL y por eso pide la contrasena.
     * La prueba que vigila aquella puerta sigue en `MisDatosTest` y sigue
     * valiendo.
     *
     * ─── SE PIDE LA CONTRASENA ACTUAL, y es la mitad que sostiene el resto ──
     *
     * Es el mismo razonamiento que ya sostiene `guardarClave()`, y aqui el dano
     * es distinto pero no menor: cambiar el usuario de otro NO le quita la
     * cuenta, le quita la forma de ENTRAR en ella. Una sesion abierta en un
     * celular prestado basta para llegar hasta aqui, y como en este sistema
     * nada le avisa a nadie de nada, el dueno se entera la proxima vez que
     * intente entrar y no le va a caber en la cabeza por que. Recuperarse solo
     * exige tener correo registrado, y lo tienen 32 de 885 personas.
     *
     * EL CAMPO SE LLAMA `clave_usuario` Y NO `clave_actual`, que es lo que
     * pediria el parecido. No se puede: la pantalla decide que `<details>`
     * abrir mirando SUS campos en `$errors`, asi que con el mismo nombre un
     * fallo aqui abriria tambien el bloque de la contrasena —y al reves—, y
     * quien baje a buscar lo rojo lo encontrara en el plegado equivocado. Es la
     * trampa del proyecto, escrita en CLAUDE.md.
     *
     * ─── DESDE UNA GESTION ASISTIDA SI SE PUEDE, y es deliberado ────────────
     *
     * Aqui NO va el corte de `GestionAsistida` que si tienen la contrasena, la
     * escritura de asistencia y la confirmacion de una clase. Decision del
     * usuario el 10/09/2026, tomada con la objecion delante: la barrera de esta
     * pantalla es saberse la contrasena de la persona, que el administrador no
     * sabe, asi que un corte aparte no anadiria nada. Si algun dia se quita el
     * requisito de la contrasena, ESTE parrafo deja de valer y hay que poner el
     * corte: sin los dos, un administrador podria cambiarle a alguien el nombre
     * con el que entra desde dentro de su propia cuenta.
     *
     * ─── CAMBIAR EL USUARIO NO TE EXPULSA ──────────────────────────────────
     *
     * No hay que tocar la sesion. `AuthenticateSession` compara HASHES DE
     * CONTRASENA, no nombres, asi que la sesion abierta sigue valiendo y el
     * nombre nuevo es el de la proxima vez que se entre. Se guarda igualmente
     * en la instancia de `Auth` y no en `$perfil->user` por lo mismo que la
     * clave: son dos objetos distintos de la misma fila y ya hay una escritura
     * que costo una hora por hacerlo en la otra.
     *
     * ─── EN LA AUDITORIA VA EL HECHO Y NO EL NOMBRE ────────────────────────
     *
     * Sin el valor viejo, igual que `clave.cambiada`. La tentacion es guardarlo
     * —sin el, el rastro no dice de que a que— y no se puede: la regla de
     * `Auditoria` es «identificadores, nunca nombres», y aqui el valor seria
     * justamente un correo o un nombre y apellido en 418 de las 841 cuentas.
     * Quien lo tenia se sabe por el id, que si va.
     */
    private function guardarUsuario(Request $request, Perfil $perfil): RedirectResponse
    {
        // La instancia de `Auth` y no `$perfil->user`: es contra la que hay que
        // comprobar el hash, y es la que el middleware mira.
        $usuario = $request->user();

        $datos = $request->validate([
            'username' => Reglas::usuario(
                Rule::unique('users', 'username')->ignore($usuario->id),
            ),
            'clave_usuario' => ['required', 'string'],
        ], Reglas::mensajes() + [
            'username.unique' => 'Ya existe una cuenta con ese nombre de usuario.',
        ], [
            'username' => 'nombre de usuario',
            'clave_usuario' => 'contraseña actual',
        ]);

        // Se comprueba DESPUES de validar el formato, por lo mismo que en la
        // contrasena: los dos mensajes salen juntos y no de a uno por intento.
        if (! Hash::check($datos['clave_usuario'], $usuario->password)) {
            // CON LA ENTRADA, Y SIN LA CLAVE DENTRO. Las dos mitades importan y
            // ninguna se ve leyendo la linea:
            //
            // 1. Sin `withInput()` se pierde el nombre que la persona acababa de
            //    teclear y el campo vuelve al que hay en la base. Ademas dejaba
            //    DOS rechazos distintos: el de formato o repetido lo conserva
            //    solo —`validate()` reenvia la entrada— y este lo borraba. Se
            //    vio en el navegador; ninguna prueba lo miraba.
            // 2. Y NO el `withInput()` pelado, que flashea todo lo que llego:
            //    la lista de campos que Laravel excluye trae `password` y
            //    `current_password`, y este se llama `clave_usuario`, asi que la
            //    contrasena en claro acabaria guardada en la sesion — que en
            //    produccion es una tabla de la base.
            return back()
                ->withInput($request->except('clave_usuario'))
                ->withErrors([
                    'clave_usuario' => 'Esa no es tu contraseña actual.',
                ]);
        }

        // Guardar el mismo que ya tenias no es un cambio: ni se escribe la fila
        // ni se deja una linea en la auditoria diciendo que algo paso.
        if ($datos['username'] === $usuario->username) {
            return redirect()->route('mi-perfil')->with(
                'success',
                'Tu nombre de usuario no cambió: ya era ese.'
            );
        }

        $usuario->username = $datos['username'];
        $usuario->save();

        Auditoria::registrar('usuario.cambiado', [], $perfil);

        return redirect()->route('mi-perfil')->with(
            'success',
            'Ahora entras con «'.$datos['username'].'». No tienes que volver a '
            .'iniciar sesión ahora; úsalo la próxima vez.'
        );
    }

    /**
     * Cada quien corrige SUS datos: nombre, fecha, documento y acudiente.
     *
     * Hasta el 07/09/2026 no se podia. El nombre y la fecha se escribian una vez
     * al inscribirse y despues solo los tocaba un administrador desde Gestion →
     * Usuarios. Lo abrio el usuario ese dia, y hacia falta: la regla del nombre
     * que entro esa misma tarde dejo a cuatro personas de produccion con un
     * nombre que el sistema ya no acepta, y ninguna podia arreglarlo sola.
     *
     * ─── LO QUE ESTO ARRASTRA, que no se ve mirando el formulario ───────────
     *
     * De `fecha_nacimiento` sale `es_menor`, y de ahi cuelgan tres cosas: que se
     * exija acudiente, quien firma el consentimiento, y cual de las dos
     * versiones del formato se imprime. O sea que cambiar la fecha puede dejar
     * una ficha en un estado que el sistema no admite —menor sin acudiente— y
     * por eso NO basta con validar los campos: se llama a
     * `DatosEstudiante::validar()`, que es la regla de verdad y la misma que
     * usan la inscripcion publica y Gestion. Sin esa llamada, un menor se
     * quitaria el acudiente cambiandose el año de nacimiento.
     *
     * ─── QUEDA EN LA AUDITORIA, y esa es la contrapartida ───────────────────
     *
     * Nombre, documento y fecha son datos de IDENTIDAD: salen en el certificado
     * y en el papel que se firma. Que cada quien pueda corregirlos es lo que se
     * pidio; que nadie pueda hacerlo sin dejar rastro es lo que lo hace
     * sostenible. Se registra QUE cambio, no los valores.
     */
    private function guardarDatos(Request $request, Perfil $perfil): RedirectResponse
    {
        $esEstudiante = $perfil->rol === 'estudiante';
        $datos = $perfil->datosEstudiante;

        $reglas = [
            'nombre_completo' => Reglas::nombreDePersona(90),
            'fecha_nacimiento' => ['required', 'date', 'before:today'],
        ];

        // El documento y el acudiente solo existen para un estudiante: el
        // personal no tiene fila en `datos_estudiante`.
        if ($esEstudiante) {
            $reglas['documento_identidad'] = [
                ...Reglas::documento(),
                Reglas::unica('datos_estudiante', 'documento_identidad')->ignore($datos?->id),
            ];
            $reglas['acudiente_nombre'] = Reglas::nombreDePersona(90, obligatorio: false);
            $reglas['acudiente_telefono'] = Reglas::celularDeAcudiente();
        }

        $valores = $request->validate($reglas, Reglas::mensajes() + [
            'documento_identidad.unique' => 'Ya hay un estudiante registrado con ese documento.',
        ], [
            'nombre_completo' => 'nombre completo',
            'fecha_nacimiento' => 'fecha de nacimiento',
            'documento_identidad' => 'documento de identidad',
        ]);

        $cambios = $this->queCambia($perfil, $datos, $valores);

        try {
            DB::transaction(function () use ($perfil, $datos, $valores, $esEstudiante) {
                $perfil->nombre_completo = $valores['nombre_completo'];
                $perfil->fecha_nacimiento = $valores['fecha_nacimiento'];
                $perfil->save();

                if (! $esEstudiante) {
                    return;
                }

                $acudiente = $datos?->acudiente;

                if (! empty($valores['acudiente_nombre'])) {
                    $acudiente ??= new Acudiente;
                    $acudiente->nombre = $valores['acudiente_nombre'];
                    $acudiente->telefono = $valores['acudiente_telefono'] ?? '';
                    $acudiente->save();
                }

                $ficha = $datos ?? new DatosEstudiante(['perfil_id' => $perfil->id]);
                $ficha->perfil_id = $perfil->id;
                $ficha->documento_identidad = $valores['documento_identidad'];
                $ficha->acudiente_id = $acudiente?->id;
                // `setRelation` y no dejar que lo cargue solo: `validar()` mira
                // el perfil para saber si es menor, y el que acaba de guardarse
                // en memoria es el que trae la fecha NUEVA. Sin esto validaria
                // contra la vieja y dejaria pasar justo el caso que importa.
                $ficha->setRelation('perfil', $perfil);
                $ficha->validar();
                $ficha->save();
            });
        } catch (ValidationException $e) {
            return back()->withInput()->withErrors($e->validator->errors());
        }

        if ($cambios !== []) {
            Auditoria::registrar('perfil.datos_cambiados', ['campos' => $cambios], $perfil);
        }

        return redirect()->route('mi-perfil')->with('success', 'Tus datos quedaron actualizados.');
    }

    /**
     * Que campos de identidad cambian, por su nombre y sin los valores.
     *
     * Sin valores a proposito: el registro de auditoria dice que alguien se
     * cambio el documento, no cual era ni cual es. Para saber eso esta la ficha;
     * duplicarlo aqui seria repartir el dato de identidad de un menor por los
     * archivos de registro.
     *
     * @param  array<string, mixed>  $valores
     * @return list<string>
     */
    private function queCambia(Perfil $perfil, ?DatosEstudiante $datos, array $valores): array
    {
        $cambios = [];

        if ($perfil->nombre_completo !== $valores['nombre_completo']) {
            $cambios[] = 'nombre';
        }

        // `getRawOriginal` y no el accesor: el cast devuelve un Carbon y aqui
        // solo hace falta comparar dos cadenas «YYYY-MM-DD» —la guardada y la
        // que llega del formulario—, que es lo que son las dos.
        if ((string) $perfil->getRawOriginal('fecha_nacimiento') !== $valores['fecha_nacimiento']) {
            $cambios[] = 'fecha_nacimiento';
        }

        if (isset($valores['documento_identidad'])
            && $datos?->documento_identidad !== $valores['documento_identidad']) {
            $cambios[] = 'documento';
        }

        return $cambios;
    }

    private function guardarContacto(Request $request, Perfil $perfil): RedirectResponse
    {
        $datos = $request->validate([
            'telefono' => Reglas::celular(),
        ], Reglas::mensajes());

        $perfil->telefono = $datos['telefono'];
        $perfil->save();

        return redirect()->route('mi-perfil')->with('success', 'Tu teléfono quedó actualizado.');
    }

    /**
     * El correo, que es opcional y vive en la CUENTA y no en el perfil.
     *
     * La asimetria con el telefono no es un descuido: el telefono es un dato de
     * la persona y el correo es de la credencial —esta en `users` desde el
     * principio, reservado para poder recuperar una clave algun dia—, y hasta
     * hoy nada lo escribia.
     *
     * Opcional, y esa es la decision de fondo: buena parte de quien se inscribe
     * aqui son menores que no tienen correo propio. Exigirlo obligaria a
     * inventarse uno por cada uno, que es justamente lo que la tabla evita
     * autenticando por `username`.
     *
     * Y NO se exige unico, igual que en el esquema. Dos hermanos matriculados
     * comparten el correo de su acudiente, y ese caso es corriente en una casa
     * de la cultura; un indice unico lo convertiria en un error que la familia
     * no sabria como resolver. La contrapartida es que el correo no sirve por si
     * solo para identificar una cuenta: el dia que haya recuperacion de clave
     * tendra que pedir tambien el usuario.
     */
    private function guardarCorreo(Request $request, Perfil $perfil): RedirectResponse
    {
        $datos = $request->validate([
            'correo' => Reglas::correoSegunLaInstitucion(),
        ], Reglas::mensajes(), ['correo' => 'correo electrónico']);

        $user = $perfil->user;
        // Vacio se guarda como null y no como cadena: «sin correo» es la
        // ausencia del dato, y dejar '' obligaria a comprobar las dos cosas en
        // cada sitio que lo lea.
        $user->email = $datos['correo'] ?: null;
        $user->save();

        return redirect()->route('mi-perfil')->with(
            'success',
            $user->email === null ? 'Tu correo quedó vacío.' : 'Tu correo quedó actualizado.'
        );
    }

    /**
     * Guarda uno de los papeles que pide la institucion.
     *
     * DESDE EL 05/09/2026 ESTO INCLUYE EL DOCUMENTO DE IDENTIDAD, que hasta ese
     * dia tenia su propio metodo y su propia columna. Ya no: es un requerido
     * mas, y por eso aqui no hay ningun caso especial que mirar.
     *
     * DESDE EL 06/09/2026 UNA IMAGEN SE CONVIERTE A PDF Y SE ALIGERA, y esto
     * INVIERTE lo que decia aqui —«se guarda tal cual llega... reescribirla la
     * convierte en otra cosa»—. La linea vieja no era un capricho, asi que
     * conviene saber por que se cambio y que se puso en su lugar.
     *
     * EL DATO QUE LO DECIDIO, medido en produccion ese dia: 70 documentos, 100
     * MB. De ellos 56 eran fotos de celular de 4000x3000 —88,5 MB, hasta 4,9 MB
     * cada una— y 14 PDF, que sumaban 10,9. El 89% del peso eran fotos sin
     * reducir, en un hosting compartido con disco y ancho de banda contados.
     *
     * LO QUE SE CONSERVA de la decision anterior: se conserva la LEGIBILIDAD,
     * que es lo que esa linea protegia de verdad. Se reduce a 2000 px de lado
     * mayor y calidad 78, que sobre un documento enfocado deja el numero y la
     * firma indistinguibles del original a la misma escala —comparado a 1:1 en
     * el navegador, no supuesto—. Y no se toca nada mas: la orientacion se
     * endereza, el color se mantiene, no se recorta ni se pasa a gris.
     *
     * LOS PDF SIGUEN GUARDANDOSE TAL CUAL, y eso tambien esta medido:
     * recomprimirlos los deja MAS GRANDES —uno de 480 KB salia en 5.832— porque
     * ya vienen comprimidos, y solo encogen bajando a una resolucion donde un
     * numero de cedula deja de leerse. El porque entero esta en `Documento`.
     *
     * SI LA CONVERSION FALLA SE GUARDA EL ORIGINAL. Un papel que llega raro
     * —un formato que GD no entiende, una imagen corrupta a medias— vale mas
     * entregado que perdido: quien lo sube no tiene forma de saber que paso y
     * probablemente no lo vuelva a intentar.
     */
    private function guardarPapel(Request $request, Perfil $perfil): RedirectResponse
    {
        $datos = $perfil->datosEstudiante;

        abort_if($datos === null, 404);

        $request->validate([
            'documento_id' => ['required', Reglas::existe('documentos_requeridos')->where('activo', true)],
            // `ImagenProcesable` con `puedeNoSerImagen`, porque aqui vale
            // tambien un PDF. Sobre una imagen mide los lados leyendo la
            // cabecera y rechaza la que no quepa en la memoria de la maquina
            // ANTES de que nadie la descomprima; un JPEG puede declarar 200
            // megapixeles y pesar poco, asi que el `max:8192` de al lado no
            // para eso. El porque entero esta en la regla.
            'archivo' => [
                'required', 'file', 'mimes:'.implode(',', self::ARCHIVOS), 'max:8192',
                new ImagenProcesable(puedeNoSerImagen: true),
            ],
        ], [
            'archivo.required' => 'Elige un archivo antes de subirlo.',
            // El mensaje de Laravel dice «no debe ser mayor que 8192
            // kilobytes», que en un telefono no le dice nada a nadie.
            'archivo.max' => 'El archivo pesa demasiado. El máximo son 8 MB: '
                .'vuelve a tomar la foto con menos resolución desde los ajustes '
                .'de la cámara, o envía una copia más pequeña.',
        ], ['archivo' => 'archivo']);

        $requerido = DocumentoRequerido::findOrFail($request->input('documento_id'));

        $entrega = DocumentoEstudiante::firstOrNew([
            'datos_estudiante_id' => $datos->id,
            'requerido_id' => $requerido->id,
        ]);

        $this->borrarAnterior($entrega->archivo);
        $entrega->archivo = $this->guardarAligerado($request->file('archivo'));
        $entrega->save();

        return redirect()->route('mi-perfil')->with('success', "«{$requerido->nombre}» quedó guardado.");
    }

    /**
     * Guarda el papel y devuelve su ruta, convirtiendo la imagen a PDF.
     *
     * TRES COSAS QUE NO SE VEN LEYENDO LA LLAMADA:
     *
     * 1. Se pregunta por el CONTENIDO y no por la extension ni por el tipo que
     *    declara el navegador: los dos los escribe quien sube el archivo. Un
     *    «.pdf» que en realidad es una foto se convierte igual, y un «.jpg» que
     *    en realidad es un PDF se guarda tal cual, que es lo correcto en los dos
     *    casos.
     *
     * 2. LA RUTA ORIGINAL SE LE PASA A `Documento` para que lea el EXIF. Ahi
     *    vive la orientacion con la que el celular tomo la foto, y sin ella un
     *    documento vertical se guarda tumbado — sin fallar y sin avisar. Solo se
     *    nota al abrirlo.
     *
     * 3. SI LA CONVERSION FALLA SE GUARDA EL ORIGINAL. Un papel raro vale mas
     *    entregado que perdido: quien lo sube no tiene forma de enterarse de que
     *    algo salio mal, y este sistema no avisa a nadie de nada.
     */
    private function guardarAligerado(UploadedFile $archivo): string
    {
        $binario = (string) file_get_contents($archivo->getRealPath());

        if (! Documento::esImagen($binario)) {
            return $archivo->store('documentos', 'local');
        }

        try {
            $pdf = Documento::aPdf($binario, $archivo->getRealPath());
        } catch (Throwable) {
            return $archivo->store('documentos', 'local');
        }

        $ruta = 'documentos/'.Str::random(40).'.pdf';
        Storage::disk('local')->put($ruta, $pdf);

        return $ruta;
    }

    private function guardarEncuesta(Request $request, Perfil $perfil): RedirectResponse
    {
        $reglas = [
            'genero' => ['required', Rule::in(array_keys(EncuestaDemografica::GENEROS))],
            'barrio' => Reglas::texto(60),
            'estrato' => ['required', Rule::in(array_keys(EncuestaDemografica::ESTRATOS))],
            'nivel_educativo' => ['required', Rule::in(array_keys(EncuestaDemografica::NIVELES_EDUCATIVOS))],
            'ocupacion' => ['required', Rule::in(array_keys(EncuestaDemografica::OCUPACIONES))],
            'zona' => ['nullable', Rule::in(array_keys(EncuestaDemografica::ZONAS))],
            'afiliacion_salud' => ['nullable', Rule::in(array_keys(EncuestaDemografica::AFILIACIONES_SALUD))],
            'grupo_etnico' => ['nullable', Rule::in(array_keys(EncuestaDemografica::GRUPOS_ETNICOS))],
            'discapacidad' => ['nullable', Rule::in(array_keys(EncuestaDemografica::DISCAPACIDADES))],
            'victima_conflicto_armado' => ['nullable', Rule::in(array_keys(EncuestaDemografica::VICTIMAS_CONFLICTO))],
        ];

        // A un menor de edad ni siquiera se le pinta la casilla: la autorizacion
        // de tratamiento de datos la da su acudiente, y admitirla aqui seria
        // recoger un consentimiento que la ley no reconoce.
        if (! $perfil->es_menor) {
            $reglas['autoriza_tratamiento_datos'] = ['nullable', 'boolean'];
        }

        $datos = $request->validate($reglas, Reglas::mensajes());

        $encuesta = $perfil->encuesta ?? new EncuestaDemografica(['perfil_id' => $perfil->id]);
        $encuesta->fill(array_map(fn ($v) => $v ?? '', $datos));

        if (! $perfil->es_menor) {
            $autoriza = $request->boolean('autoriza_tratamiento_datos');
            $encuesta->autoriza_tratamiento_datos = $autoriza;

            // La fecha marca CUANDO se dio el consentimiento y no se refresca en
            // cada guardado: si se pisara, la constancia diria la fecha de la
            // ultima vez que alguien toco el formulario.
            if ($autoriza && $encuesta->fecha_autorizacion === null) {
                $encuesta->fecha_autorizacion = now();
            } elseif (! $autoriza) {
                $encuesta->fecha_autorizacion = null;
            }
        }

        $encuesta->perfil_id = $perfil->id;
        $encuesta->save();

        return redirect()->route('mi-perfil')->with('success', 'Tu encuesta quedó guardada.');
    }

    /**
     * Borra el archivo que se reemplaza.
     *
     * El original dejaba el anterior en disco —Django tampoco lo borra solo— y
     * en hosting compartido eso se acumula: cada foto nueva dejaba la vieja
     * ocupando sitio para siempre.
     */
    private function borrarAnterior(?string $ruta): void
    {
        if ($ruta !== null && $ruta !== '') {
            Storage::disk('local')->delete($ruta);
        }
    }

    /**
     * Matriculas del periodo en curso que se pueden certificar.
     *
     * Solo las ACTIVAS: una pendiente todavia no la ha confirmado quien dicta, y
     * el certificado no puede afirmar que esa persona esta en el curso. La
     * misma regla que aplica el controlador que genera el PDF, aqui solo para
     * decidir si se pinta el boton.
     */
    private function certificables(Perfil $perfil): int
    {
        $periodo = Periodo::enCurso();

        if ($periodo === null) {
            return 0;
        }

        return Matricula::where('estudiante_id', $perfil->id)
            ->where('periodo_id', $periodo->id)
            ->where('estado', Matricula::ACTIVA)
            ->count();
    }

    /**
     * Los cursos, talleres y grupos de proyeccion que dirige, si dirige alguno.
     *
     * SOLO SI HAY, igual que «Promotorias a cargo» del director y por la misma
     * razon escrita alli: a quien no dirige ninguno no se le ensena un cero que
     * no significa nada.
     *
     * Existe desde el 06/09/2026, y hasta ese dia una actividad asignada no
     * aparecia en ningun sitio de «Mi perfil»: alguien cuyo unico encargo era un
     * taller leia «0 promotorias a cargo, 0 grupos», que es lo mismo que ve
     * quien no tiene nada. Cuelgan de `responsable_id` y no de
     * `promotorias.profesor_id`, que es por lo que se cayeron de aqui.
     *
     * @return list<array{numero: int, etiqueta: string}>
     */
    private function actividadesACargo(Perfil $perfil): array
    {
        $cuantas = Actividad::where('responsable_id', $perfil->id)->count();

        if ($cuantas === 0) {
            return [];
        }

        return [[
            'numero' => $cuantas,
            'etiqueta' => $cuantas === 1 ? 'Curso o taller a cargo' : 'Cursos y talleres a cargo',
        ]];
    }

    /**
     * Las cifras de la tarjeta, segun el rol.
     *
     * @return list<array{numero: int, etiqueta: string}>
     */
    private function estadisticas(Perfil $perfil): array
    {
        if ($perfil->rol === 'estudiante') {
            // `grupo_id` en la seleccion, y no sobra: es la columna con la
            // que `Companeros` empareja, y una que no se pide llega NULA sin
            // que nada se queje. Con ella fuera, la cifra sale 0 para todo el
            // mundo --que es un numero creible, y por eso el descuido se
            // quedaria puesto.
            // El `id` no sobra en la seleccion y `grupos` tampoco: `Companeros`
            // empareja por los grupos de cada matricula, y una fila traida sin
            // su id no puede cargar ninguna relacion. Sin las dos cosas esto
            // devolveria cero companeros sin fallar.
            //
            // Y `periodo_id` porque la clave del emparejamiento es el par
            // (grupo, periodo). Nada mas: la columna `grupo_id` ya no existe.
            $activas = Matricula::where('estudiante_id', $perfil->id)
                ->where('estado', Matricula::ACTIVA)
                ->with('grupos')
                ->get(['id', 'periodo_id']);

            // "Companero" = mismo GRUPO y periodo, con matricula activa. La
            // regla vive en `Companeros` y no aqui: la escribian este metodo y
            // «Mis companeros» por separado, cada uno preguntando una vez por
            // matricula dentro de un bucle (C-04).
            return [
                ['numero' => $activas->count(), 'etiqueta' => 'Matrículas activas'],
                ['numero' => Companeros::cuantos($perfil, $activas), 'etiqueta' => 'Compañeros'],
            ];
        }

        if ($perfil->rol === 'profesor') {
            $cifras = [
                [
                    'numero' => Promotoria::where('profesor_id', $perfil->id)->count(),
                    'etiqueta' => 'Promotorías a cargo',
                ],
                [
                    'numero' => Grupo::whereHas('promotoria', fn ($q) => $q->where('profesor_id', $perfil->id))->count(),
                    'etiqueta' => 'Grupos',
                ],
            ];

            return [...$cifras, ...$this->actividadesACargo($perfil)];
        }

        if (in_array($perfil->rol, ['director', 'administrador'], true)) {
            $cifras = [
                ['numero' => Promotoria::count(), 'etiqueta' => 'Promotorías'],
                ['numero' => Perfil::count(), 'etiqueta' => 'Usuarios'],
            ];

            // Un director puede ademas dictar. Cuando lo hace, sus dos cifras de
            // direccion no cuentan lo suyo por ninguna parte: se le suma la que
            // si, y solo entonces — a quien no dicta no se le ensena un cero que
            // no significa nada.
            $aCargo = Promotoria::where('profesor_id', $perfil->id)->count();

            if ($aCargo) {
                $cifras[] = ['numero' => $aCargo, 'etiqueta' => 'Promotorías a cargo'];
            }

            return [...$cifras, ...$this->actividadesACargo($perfil)];
        }

        return [];
    }
}
