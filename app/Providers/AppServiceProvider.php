<?php

namespace App\Providers;

use App\Models\ConfiguracionInstitucion;
use App\Models\Periodo;
use App\Support\ClasesPendientes;
use App\Support\ConexionQueReintenta;
use App\Support\InstitucionActual;
use App\Support\Recurso;
use App\Support\Tema;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /**
         * La conexion a MariaDB vuelve a intentarlo si el motor la rechaza por
         * saturacion. El porque y la trampa estan en la propia clase.
         *
         * Va en `register()` y no en `boot()` porque la primera consulta puede
         * ocurrir antes de arrancar: la sesion se lee en el middleware, o sea
         * antes de que ningun `boot()` haya corrido.
         *
         * Solo `pgsql`: desde el paso a PostgreSQL es el unico motor que hay
         * en `config/database.php`.
         */
        $this->app->bind('db.connector.pgsql', ConexionQueReintenta::class);
    }

    public function boot(): void
    {
        /**
         * Row Level Security: antes de cada consulta, la base se entera de que
         * institucion es la peticion. Sin esto las politicas no reciben
         * institucion y NO SALE NINGUNA FILA de ninguna tabla de datos: el
         * fallo es ruidoso, que es lo que se quiere. Ver
         * `InstitucionActual::alConsultar()`.
         *
         * Solo en la conexion de la aplicacion: la del dueño no pasa por RLS.
         */
        DB::connection('pgsql')->beforeExecuting(
            fn (string $sql, array $enlaces, Connection $conexion) => InstitucionActual::alConsultar($conexion)
        );
        Event::listen(
            TransactionRolledBack::class,
            fn (TransactionRolledBack $evento) => InstitucionActual::olvidarLoDicho($evento->connection)
        );

        /**
         * La marca de la institucion, disponible en TODAS las plantillas.
         *
         * Equivale al context processor del original. Va con `View::composer('*')`
         * en vez de `View::share()` para que no se consulte la base de datos en
         * las peticiones que no pintan ninguna vista (los POST que solo
         * redirigen, por ejemplo).
         *
         * OJO: este comentario decia «se resuelve una vez por peticion» y era
         * FALSO. El compositor corre una vez por VISTA, y una pagina son el
         * layout mas sus parciales: eran cuatro consultas iguales por carga.
         * Quien lo lea de aqui en adelante, que sepa que lo que garantiza la
         * unica consulta es la memoria de `ConfiguracionInstitucion::actual()`,
         * no esta linea.
         *
         * `ConfiguracionInstitucion::actual()` devuelve valores por defecto en
         * memoria si la tabla todavia no existe, asi que un proyecto recien
         * clonado y sin migrar no se cae aqui.
         */
        View::composer('*', function ($view) {
            // El panel de todas las instituciones no es de ninguna (paso 4b):
            // sus vistas no pintan marca, y pedirla lanzaria.
            if (InstitucionActual::idSiSeSabe() === null) {
                return;
            }

            $view->with('configuracion', ConfiguracionInstitucion::actual());
        });

        /**
         * El tema del aparato, para que los dos envoltorios puedan estampar
         * `data-tema` en el `<html>`.
         *
         * Va por compositor y no por middleware porque lo unico que hace falta
         * es una CADENA en la plantilla, no tocar la peticion ni la respuesta.
         * Es cadena vacia cuando no hay preferencia guardada, que significa
         * «sigue al sistema» — y entonces el atributo ni siquiera se pinta.
         *
         * `request()` y no una inyeccion: el compositor corre dentro de la
         * peticion, y en una consola o una prueba sin peticion HTTP devuelve una
         * peticion vacia, o sea cadena vacia, que es el valor correcto.
         */
        View::composer(['layouts.app', 'layouts.publico'], function ($view) {
            $view->with('tema', Tema::delAparato(request()));
        });

        /**
         * El Perfil de quien mira, disponible en TODAS las plantillas como
         * `$yo`.
         *
         * Media docena de vistas necesitan saber quien esta mirando para decidir
         * si un nombre va como enlace o como texto, y pasarlo desde cada
         * controlador significaba acordarse en cada uno. Se llama `$yo` y no
         * `$perfil` para no chocar con la variable local de las vistas que
         * hablan del perfil de OTRA persona, que es justo donde mas se usa.
         *
         * Es null sin sesion, y las plantillas publicas no lo tocan.
         */
        View::composer('*', function ($view) {
            $view->with('yo', auth()->user()?->perfil);
        });

        /**
         * CUANTAS CLASES ESPERAN QUE ESTE ESTUDIANTE LAS CONFIRME.
         *
         * Va al envoltorio para que el aviso viva en el MENU, que es lo unico
         * que el estudiante ve en todas las pantallas. Antes el unico aviso
         * estaba en «Promotorias disponibles» —donde aterriza al entrar— y esa
         * pantalla la puede APAGAR la institucion: con el interruptor en
         * ventanilla, nadie le decia que tenia 48 horas para confirmar. El
         * porque entero esta en `Support\ClasesPendientes`.
         *
         * SOLO PARA ESTUDIANTES. Para cualquier otro rol es cero y no se
         * consulta nada: quien dicta no confirma sus propias clases.
         */
        View::composer('layouts.app', function ($view) {
            $perfil = auth()->user()?->perfil;

            $view->with(
                'clasesPorConfirmar',
                $perfil?->rol === 'estudiante'
                    ? ClasesPendientes::cuantas($perfil, Periodo::enCurso())
                    : 0
            );
        });

        $this->limitarIntentos();
        $this->exigirContrasenaMinima();
        $this->declararRecursoVersionado();

        // Las vistas que trae Laravel son de Tailwind y aqui no hay Tailwind:
        // el CSS es de mano. Sin esto, cada `links()` saldria sin estilos.
        Paginator::defaultView('partials.paginacion');

        // En Hostinger el SSL termina antes de PHP, asi que la aplicacion ve
        // http y generaria enlaces y formularios en http dentro de una pagina
        // https. Forzarlo aqui evita el aviso de contenido mixto.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }

    /**
     * Cuantos intentos de inicio de sesion se admiten, y contados contra que.
     *
     * La clave es la pareja USUARIO + IP, no la IP sola, y esa es toda la
     * decision. Una escuela entera sale a internet por una sola direccion: si el
     * contador fuera por IP, treinta estudiantes entrando a la vez desde la sala
     * de computo se bloquearian entre si, y el sistema castigaria justamente el
     * uso normal que existe para atender. Contando por pareja, cada cuenta lleva
     * su propio marcador desde cada sitio: quien machaca una cuenta ajena se
     * queda sin intentos en un minuto, y el de al lado no se entera.
     *
     * Contrapartida asumida y consciente: esto NO frena el barrido —probar una
     * misma contrasena contra mil usuarios distintos—, porque cada usuario nuevo
     * estrena contador. Cerrar esa puerta pide un tope adicional por IP, y ese
     * tope es exactamente el que volveria a bloquear la sala de computo. Se deja
     * fuera a proposito; si algun dia hace falta, el sitio es aqui y el numero
     * tiene que salir de cuanta gente entra de verdad a la vez.
     *
     * Y hay una razon MAS para no ponerlo, vista en la revision del 27/09/2026:
     * en produccion hay un CDN delante y no se configura `TrustProxies`, asi que
     * `$request->ip()` puede ser la del borde del CDN, compartida por mucha
     * gente. Y leer la IP real de `X-Forwarded-For` tampoco vale: el origen se
     * alcanza directo, asi que esa cabecera la puede inventar cualquiera y con
     * ella saltarse el tope. Contra el barrido la defensa no es un contador, es
     * que no haya contrasenas debiles.
     *
     * El registro y la inscripcion no pasan por aqui: se limitan por IP con el
     * `throttle:` de sus rutas, porque ahi lo que se frena es la creacion masiva
     * de cuentas y no hay ninguna cuenta previa contra la cual contar.
     */
    private function limitarIntentos(): void
    {
        // El login del panel de todas las instituciones (paso 4b), con los
        // mismos dos topes que el de las instituciones y por la misma razon.
        // Su propio nombre: un operador y una cuenta de una casa pueden
        // llamarse igual y no comparten contador.
        RateLimiter::for('operador-entrar', function (Request $request) {
            $usuario = Str::lower(trim((string) $request->input('usuario')));

            return [
                Limit::perMinute(5)->by('operador|'.$usuario.'|'.$request->ip()),
                Limit::perMinutes(15, 10)->by('operador-cuenta|'.$usuario),
            ];
        });

        RateLimiter::for('entrar', function (Request $request) {
            // En minusculas para que `Ana` y `ana` compartan contador: el login
            // no distingue mayusculas y dos contadores separados darian el doble
            // de intentos por escribirlo distinto.
            //
            // Con la institucion delante desde el paso 4a: el mismo usuario
            // puede existir en dos casas, y sin ella equivocarse en una
            // bloquearia al «admin» de la otra.
            $usuario = InstitucionActual::idSiSeSabe().'|'.Str::lower(trim((string) $request->input('username')));

            return [
                Limit::perMinute(5)->by($usuario.'|'.$request->ip()),
                // POR CUENTA, VENGA DE DONDE VENGA (27/09/2026, revision de
                // seguridad pedida por el usuario): «que pasa si el atacante
                // cambia constantemente de IP». Con el limite de arriba, cada IP
                // nueva estrenaba cinco intentos contra la MISMA cuenta, sin
                // fin. Este cuenta solo la cuenta: diez cada quince minutos,
                // cambie de IP cuanto quiera.
                //
                // No choca con la decision de fondo —no hay tope por IP—: en la
                // sala de computo cada quien entra con SU cuenta, y nadie se
                // equivoca diez veces en quince minutos. Lo que si permite es
                // que un extrano deje sin entrar a alguien concreto durante un
                // cuarto de hora tecleando mal su usuario; es temporal y se
                // asumio frente a la alternativa.
                Limit::perMinutes(15, 10)->by('cuenta|'.$usuario),
            ];
        });

        /**
         * Pedir el enlace de «olvide mi contrasena».
         *
         * DOS LIMITES A LA VEZ, porque son dos abusos distintos y uno solo no
         * tapa los dos:
         *
         * - Por CUENTA+IP, 3 por minuto: frena a quien pulsa el boton veinte
         *   veces sobre la cuenta de otro para llenarle el buzon. Es el abuso
         *   realista, porque el correo lo manda el sistema y lo recibe alguien
         *   que no lo pidio.
         *
         * - Por IP a secas, 20 cada diez minutos: frena a quien rocia cuentas
         *   distintas, que el limite de arriba no ve.
         *
         * Y EL SEGUNDO ES GENEROSO A PROPOSITO, por una razon que no se ve
         * leyendo esta linea: este proyecto NO configura `TrustProxies`, y en
         * produccion hay un CDN delante. O sea que `$request->ip()` puede ser
         * la del borde del CDN y no la de quien pulsa — con lo cual un limite
         * por IP apretado no limita a una persona, limita a la institucion
         * entera. Ese reparto lo hace el primer limite, que separa por cuenta.
         *
         * En minusculas por lo mismo que el de entrar: `Ana` y `ana` son la
         * misma cuenta y dos contadores darian el doble de intentos.
         */
        RateLimiter::for('clave-olvidada', function (Request $request) {
            $cuenta = Str::lower(trim((string) $request->input('cuenta')));

            return [
                Limit::perMinute(3)->by($cuenta.'|'.$request->ip()),
                Limit::perMinutes(10, 20)->by($request->ip()),
            ];
        });

        /**
         * Guardar la contrasena nueva de un enlace de recuperacion.
         *
         * CUENTA POR TOKEN Y NO POR IP, y esa es la diferencia con el de
         * arriba. Lo unico que se puede adivinar en esa pantalla es el token —
         * la cuenta no se escribe— asi que el contador tiene que colgar de el;
         * por IP, quien cambie de red se lleva intentos nuevos sobre el mismo
         * enlace, y quien comparta una red con otros veinte se queda sin los
         * suyos por culpa ajena.
         *
         * Diez por minuto: de sobra para quien teclea mal la confirmacion dos o
         * tres veces, y nada para lo otro.
         */
        RateLimiter::for('clave-nueva', function (Request $request) {
            return Limit::perMinute(10)->by((string) $request->route('token'));
        });
    }

    /**
     * El minimo de la contrasena, definido en UN solo sitio.
     *
     * Hay tres formularios que crean credenciales —el autorregistro de profesor,
     * la inscripcion de estudiante y el alta desde Gestion— y hasta ahora
     * ninguno pedia longitud: se aceptaba una contrasena de un solo caracter en
     * un formulario publico, en un sistema que guarda copias de documentos de
     * identidad de menores.
     *
     * Va como `Password::defaults()` y no como un `min:8` repetido tres veces
     * porque tres copias de una regla es la forma seria de que acaben
     * discrepando: el dia que este numero suba, tiene que subir en los tres a la
     * vez o no ha subido en ninguno.
     *
     * OJO, no es un puerto: el original NO valida la contrasena por ninguna
     * parte. Su `settings.py` lista los cuatro AUTH_PASSWORD_VALIDATORS de
     * Django, pero son la plantilla que deja `startproject` y ahi nadie los
     * invoca — solo corren desde `UserCreationForm` o `SetPasswordForm`, y el
     * original registra con un `forms.Form` plano que llama directamente a
     * `create_user()`. Configuracion muerta. Esta regla es un anadido
     * deliberado, no una equivalencia.
     *
     * Se queda en la longitud y no se copian los otros tres validadores (lista
     * de contrasenas comunes, no-solo-digitos, parecido al usuario) porque eso
     * ya seria decidir politica de contrasenas, y esa decision no esta escrita
     * en ninguna parte. Si algun dia se toma, se anade aqui encadenando y los
     * tres formularios la reciben solos.
     */
    private function exigirContrasenaMinima(): void
    {
        Password::defaults(fn () => Password::min(8));
    }

    /**
     * `@recurso('js/acciones.js')`: el activo con su fecha pegada detras.
     *
     * Es la PRIMERA directiva propia del proyecto y se anade con reparo, porque
     * cada directiva es vocabulario nuevo que hay que aprender para leer una
     * plantilla. Se justifica por el numero: son diez referencias en ocho
     * plantillas, y la alternativa —escribir la llamada estatica completa en
     * cada una— es la forma segura de que la proxima se escriba con `asset()`
     * por costumbre y se quede sin version sin que nadie lo note.
     *
     * Compila a una LLAMADA, no al resultado. Importa porque el despliegue corre
     * `view:cache`: si la fecha se calculara al compilar, se congelaria la del
     * momento del despliegue y no volveria a moverse.
     *
     * Va con `e()` por el contexto —siempre dentro de un atributo HTML— aunque
     * hoy ninguna ruta de `public/` tenga nada que escapar.
     */
    private function declararRecursoVersionado(): void
    {
        // La barra inicial no es decorativa: la plantilla compilada vive en el
        // espacio de nombres global, y sin ella el nombre seria relativo.
        Blade::directive('recurso', fn (string $ruta) => '<?php echo e(\\'.Recurso::class."::versionado({$ruta})); ?>");
    }
}
