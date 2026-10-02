<?php

use App\Http\Middleware\CabecerasDeSeguridad;
use App\Http\Middleware\CuentaActiva;
use App\Http\Middleware\InstitucionPorDominio;
use App\Http\Middleware\RequiereRol;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\TokenMismatchException;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // El equivalente del decorador `@requiere_rol(...)` del original.
        // Se usa como 'rol:estudiante' o 'rol:administrador,director'.
        $middleware->alias([
            'rol' => RequiereRol::class,
        ]);

        // Cambiar una contrasena tiene que cerrar las demas sesiones de esa
        // cuenta. Sin esto, un administrador puede resetear la clave de alguien
        // desde Gestion -> Usuarios y esa persona sigue dentro donde ya
        // estuviera: es el unico camino por el que se cambia una contrasena en
        // este sistema, asi que era justo el caso que importaba.
        //
        // Guarda el hash en la sesion y compara en cada peticion. Dos cosas que
        // suelen frenar este cambio y que aqui NO aplican:
        //
        // - No cierra las sesiones ya abiertas al desplegarlo: si el hash falta
        //   en la sesion lo GUARDA, y solo echa a quien lo tenga y no cuadre.
        // - No toca «recuerdame»: el formulario de entrada no ofrece la
        //   casilla, asi que `viaRemember()` nunca es cierto.
        //
        // Va antes que CuentaActiva por orden de coste: si la sesion ya no vale,
        // no hace falta consultar si la cuenta sigue activa.
        //
        // Desactivar una cuenta tiene que echar tambien a quien ya esta dentro,
        // que es lo que hace el original. Va en el grupo entero y no pegado a
        // 'rol' porque /post-login y /mi-perfil no llevan rol.
        $middleware->web(append: [
            AuthenticateSession::class,
            CuentaActiva::class,
        ]);

        // Las cabeceras de seguridad de cada respuesta (revision del
        // 27/09/2026). En el grupo `web` y no global porque lo que protegen son
        // las pantallas: la ruta de salud `/up` no lleva nada que enmarcar.
        $middleware->web(append: [CabecerasDeSeguridad::class]);

        // Sin sesion, todo lleva al login (Laravel apunta por defecto a una
        // ruta 'login' que aqui si existe, pero se deja explicito).
        $middleware->redirectGuestsTo(fn () => route('login'));

        // De que institucion es la peticion: la del host (paso 4a). El
        // PRIMERO del grupo, antes de la sesion: con `users` bajo RLS, cargar
        // la cuenta de la sesion ya necesita saberlo.
        $middleware->web(prepend: [InstitucionPorDominio::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /**
         * Quedarse sin intentos devuelve al formulario, no a la pagina 429.
         *
         * La pagina que trae Laravel dice "Too Many Attempts." en ingles y sin
         * ninguna salida: quien la ve no sabe si se rompio el sistema, si el
         * usuario estaba mal o cuanto tiene que esperar. Aqui se vuelve al mismo
         * formulario con el aviso puesto, que es lo que hace el resto de la
         * aplicacion con cualquier otro error.
         *
         * El mensaje va como `error` de sesion y no pegado al campo `username`:
         * quedarse sin intentos no es un problema del valor que se escribio —la
         * regla de `partials/mensajes` es justamente esa—. Y no dice si el
         * usuario existe, por la misma razon que no lo dice el mensaje de clave
         * incorrecta.
         *
         * Solo aplica a peticiones de formulario. Una peticion que pida JSON
         * sigue recibiendo su 429 con la cabecera `Retry-After`, que es lo
         * correcto para algo que no es un navegador.
         */
        /**
         * La sesion caducada devuelve al formulario, no a la pagina 419.
         *
         * Es el mismo trato que el 429 de aqui abajo y por la misma razon: la
         * pagina de Laravel dice "Page Expired" en ingles, sin explicacion y sin
         * salida, y quien la ve no sabe si se rompio el sistema. Pasa a diario
         * en el celular: se deja el login abierto en una pestana --y ahi se
         * quedan abiertas semanas--, la cookie de sesion muere a las dos horas
         * (`SESSION_LIFETIME`), y al escribir la contrasena al dia siguiente el
         * formulario manda un testigo que el servidor ya olvido.
         *
         * NO se debilita el CSRF: el rechazo sigue siendo el mismo y ocurre
         * antes de mirar credenciales. Lo unico que cambia es lo que ve quien lo
         * sufre. El remedio que la gente encontraba sola --darle a Atras-- ya
         * funcionaba por accidente: la aplicacion manda `Cache-Control:
         * no-cache`, asi que volver atras vuelve a pedir la pagina y con ella un
         * testigo nuevo.
         *
         * La contrasena NO se devuelve, igual que en el 429: se reescribe. El
         * usuario si, que es lo tedioso de teclear en un telefono.
         *
         * `fallback` hace falta de verdad y no es defensivo: si la cookie murio,
         * la sesion que atiende esta peticion es NUEVA y no recuerda ninguna
         * pagina anterior, asi que `back()` depende del `Referer` --que algunos
         * navegadores no mandan--. Sin el, ese caso aterriza en la raiz.
         *
         * Sirve tambien al camino de `acciones.js`, que sigue redirecciones y
         * repinta: hoy recibe la pagina de error, no encuentra `<main>` y navega
         * a ella. Con el redirect, pinta el formulario con el aviso.
         */
        $exceptions->render(function (HttpException $e, Request $request) {
            // Se engancha al 419 y NO a `TokenMismatchException`, aunque sea esa
            // la que se lanza: el manejador de Laravel llama a
            // `prepareException()` --que la convierte en un `HttpException` de
            // 419-- ANTES de consultar estos renderizadores
            // (`Handler::render()`, lineas 616 y 618). Un gancho sobre la clase
            // original se registra sin protestar y no se ejecuta nunca.
            //
            // El `getPrevious()` no es adorno: en esa conversion Laravel pasa la
            // excepcion original como anterior, asi que preguntar por ella
            // acota esto al CSRF y deja en paz a cualquier otro 419.
            if (! $e->getPrevious() instanceof TokenMismatchException) {
                return null;
            }

            if ($request->expectsJson()) {
                return null;
            }

            return back(fallback: route('login'))
                ->withInput($request->except(['password', 'password_confirmation']))
                ->with('error', 'Tu sesión caducó por inactividad. Vuelve a intentarlo.');
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if ($request->expectsJson()) {
                return null;
            }

            $segundos = (int) ($e->getHeaders()['Retry-After'] ?? 60);
            $minutos = (int) ceil($segundos / 60);

            $espera = $segundos <= 60
                ? "{$segundos} segundos"
                : $minutos.($minutos === 1 ? ' minuto' : ' minutos');

            return back()
                ->withInput($request->except(['password', 'password_confirmation']))
                ->with(
                    'error',
                    "Demasiados intentos seguidos. Espera {$espera} y vuelve a intentarlo."
                );
        });
    })->create();
