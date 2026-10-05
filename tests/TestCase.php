<?php

namespace Tests;

use App\Http\Middleware\DatosDelPersonal;
use App\Models\Area;
use App\Models\Institucion;
use App\Models\Perfil;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * La institucion en la que corre cada prueba con base: la 2, NUNCA la 1
     * (paso 5, decision del usuario del 02/10/2026).
     *
     * La 1 existe siempre (la crea el esquema) y era la de toda la suite, asi
     * que un codigo que diera por hecho «la institucion es la 1» —un `find(1)`,
     * un `institucion_id = 1` escrito a mano— pasaba todas las pruebas. Con la
     * suite en otra, ese codigo se pone en rojo. La 1 se queda ahi, vacia,
     * como la casa de al lado.
     *
     * Se midio antes de decidirlo: la suite entera como la 2 dio seis fallos,
     * los seis en pruebas que tenian el 1 escrito, y ninguno en la aplicacion.
     *
     * Se fija como la institucion POR DEFECTO, que es la de una instalacion de
     * una sola casa: las pruebas corren sin dominio base (`phpunit.xml`).
     */
    protected ?Institucion $institucionDePrueba = null;

    /**
     * La barrera que pide documento y correo al profesor y al director
     * (`DatosDelPersonal`) va APAGADA en la suite, salvo donde se pone a true.
     *
     * Casi ninguna prueba va de eso, y cada una crea sus cuentas con su propio
     * ayudante, sin correo ni documento: con la barrera puesta, cada peticion
     * de un profesor acabaria en /completar-datos. Las pruebas de la barrera
     * (`DatosDelPersonalTest`) la encienden con esta propiedad.
     */
    protected bool $conBarreraDeDatosDelPersonal = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (in_array(RefreshDatabase::class, class_uses_recursive($this), true)) {
            $this->institucionDePrueba = Institucion::create(['nombre' => 'Institución de las pruebas']);
            config(['institucion.por_defecto' => $this->institucionDePrueba->id]);
        }

        if (! $this->conBarreraDeDatosDelPersonal) {
            $this->withoutMiddleware(DatosDelPersonal::class);
        }
    }

    /**
     * Las migraciones de `RefreshDatabase` corren como el DUEÑO de las tablas;
     * las pruebas, como la APLICACION.
     *
     * Es el reparto de produccion desde el paso 3 (RLS): la aplicacion no puede
     * crear ni alterar tablas, y RLS no le aplicaria si fuera la dueña. Si las
     * pruebas corrieran como el dueño, RLS no actuaria en ninguna y la suite
     * pasaria en verde sin probar el aislamiento que de verdad hay.
     *
     * Se engancha en `artisan()` y NO en `migrateFreshUsing()`, que es lo que
     * parece el sitio: ese metodo lo trae el trait `RefreshDatabase`, y un
     * trait usado en la clase de cada prueba TAPA el metodo de esta clase
     * madre. Se escribio primero ahi, se registro sin una queja y la suite
     * entera fallo con «must be owner of table».
     *
     * @param  string  $command
     * @param  array<string, mixed>  $parameters
     */
    public function artisan($command, $parameters = [])
    {
        if ($command === 'migrate:fresh' && ! isset($parameters['--database'])) {
            $parameters['--database'] = 'pgsql_dueno';
        }

        return parent::artisan($command, $parameters);
    }

    /**
     * Cada `actingAs` empieza con la sesion vacia: otra persona es otro
     * navegador.
     *
     * Existe por `AuthenticateSession`, que ata la sesion al hash de la
     * contrasena de quien la abrio. `actingAs` cambia de usuario pero CONSERVA
     * la sesion del anterior, asi que la segunda persona de un mismo test
     * llegaba con un hash que no era el suyo y el middleware la echaba. No es
     * un caso real: `/entrar` va detras del middleware `guest` —quien tiene
     * sesion abierta ni siquiera alcanza el controlador de login— y cerrar
     * sesion llama a `invalidate()`, que vacia la sesion entera.
     *
     * Va aqui y no repetido en cada prueba a proposito: son cinco pruebas hoy y
     * seria una trampa nueva para cada una que se escriba manana, con un
     * sintoma —un redirect a /entrar sin motivo— que no apunta a su causa.
     *
     * No rompe nada de lo que ya habia: ninguna prueba prepara la sesion antes
     * de `actingAs` (no hay un solo `withSession` ni un `from()` en la suite), y
     * lo que una peticion deje en sesion sobrevive, porque el vaciado ocurre
     * antes de la peticion y no despues.
     */
    public function actingAs(Authenticatable $user, $guard = null)
    {
        $this->flushSession();

        return parent::actingAs($user, $guard);
    }

    /**
     * Le da a un director los departamentos que dirige.
     *
     * Desde el 12/09/2026 un director solo ve lo de sus departamentos, asi que
     * uno recien creado no ve NADA. Casi todas las pruebas que usan un director
     * no van de eso —van del Panel, del informe, del pase de lista— y para ellas
     * lo realista es un director con su casa asignada.
     *
     * Sin argumentos le da TODAS, que es lo que hace la migracion con los que ya
     * estaban. Con argumentos, solo esas: es lo que usan las pruebas del recorte.
     */
    protected function dirige(Perfil $director, Area ...$areas): Perfil
    {
        $director->areasDirigidas()->sync(
            $areas === []
                ? Area::pluck('id')->all()
                : collect($areas)->pluck('id')->all()
        );

        // La relacion se cachea en la instancia: sin esto, una prueba que
        // pregunte por `areasDirigidas` justo despues sigue viendo lo de antes.
        return $director->load('areasDirigidas');
    }
}
