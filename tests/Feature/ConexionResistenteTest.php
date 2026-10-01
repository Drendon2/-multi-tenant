<?php

namespace Tests\Feature;

use App\Support\ConexionQueReintenta;
use Illuminate\Database\LostConnectionDetector;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use RuntimeException;
use Tests\Support\ConectorDeGuion;
use Tests\TestCase;

/**
 * Que un hipo del motor no tumbe una pantalla (08/09/2026).
 *
 * Salio de un 504 real: el hosting compartido rechaza la conexion al socket de
 * la base en rafagas de un segundo con `Operation not permitted`, y la
 * peticion que lo pillaba se quedaba colgada hasta que el CDN cortaba a los 60
 * segundos con una pagina en blanco. Se midio con MariaDB; desde el paso a
 * PostgreSQL los mensajes son los de `pdo_pgsql`, donde TODO fallo al
 * conectar llega como `[08006] [7]` y lo que distingue un caso de otro es el
 * texto (ver `ConexionQueReintenta`).
 *
 * NUEVE de las diez se ponen ROJAS al deshacer el arreglo, y esto se comprobo
 * quitandolo por partes en vez de suponerlo: sin registrar el conector cae 1,
 * sin el tope de espera 2, sin espera entre intentos 1, sin reintento 2,
 * reintentando TODO —o sea sin la lista de textos— 1, sin el reconocimiento
 * por codigo 1, y metiendo los codigos de configuracion entre los
 * reintentables, 1.
 *
 * La octava, `test_laravel_no_reconoce_el_error_de_produccion`, es de otra
 * clase: no prueba el arreglo sino su PREMISA, y esta escrita para que el dia
 * que Laravel anada esa cadena a su lista alguien se entere y pueda tirar este
 * archivo entero.
 *
 * De esa ronda salio ademas una prueba inutil, ya corregida: la de la espera
 * medía el tiempo contra la propia constante, asi que con la espera en cero
 * seguia verde sin comprobar nada.
 */
class ConexionResistenteTest extends TestCase
{
    /**
     * El rechazo de produccion, con la forma en que lo envuelve `pdo_pgsql`.
     * El EPERM del sistema operativo es el mismo que se midio con MariaDB; el
     * envoltorio es el que da libpq (comprobado el 01/10/2026 con un servidor
     * parado: el mismo envoltorio, con «Connection refused» como causa).
     */
    private const ERROR_REAL = 'SQLSTATE[08006] [7] connection to server at "127.0.0.1", port 5432 failed: Operation not permitted';

    /**
     * La premisa: Laravel ya reintenta la conexion perdida, pero NO con este
     * error, porque no esta en su lista. Si esta prueba se pone roja, el
     * framework lo ha anadido y `ConexionQueReintenta` sobra.
     */
    public function test_laravel_no_reconoce_el_error_de_produccion(): void
    {
        $detector = new LostConnectionDetector;

        $this->assertFalse(
            $detector->causedByLostConnection(new PDOException(self::ERROR_REAL)),
            'Laravel ya reconoce este error: revisa si ConexionQueReintenta sigue haciendo falta.'
        );

        // Y el vecino que SI trae, para dejar claro que la lista existe y que lo
        // que falla es solo esta cadena.
        $this->assertTrue($detector->causedByLostConnection(
            new PDOException('SQLSTATE[08006] [7] server closed the connection unexpectedly')
        ));
    }

    public function test_reintenta_cuando_el_motor_rechaza_por_saturacion(): void
    {
        $conector = $this->conector([
            new PDOException(self::ERROR_REAL),
            new PDOException(self::ERROR_REAL),
            null, // al tercer intento el motor ya atiende
        ]);

        $conector->createConnection('dsn', [], []);

        $this->assertSame(3, $conector->intentos);
    }

    public function test_espera_entre_intentos_en_vez_de_insistir_de_golpe(): void
    {
        $conector = $this->conector([new PDOException(self::ERROR_REAL), null]);

        $antes = microtime(true);
        $conector->createConnection('dsn', [], []);
        $transcurrido = (microtime(true) - $antes) * 1000;

        // Las DOS afirmaciones hacen falta, y la primera no sobra: sin ella la
        // segunda se comprueba contra la propia constante, asi que poniendo la
        // espera a cero la prueba seguiria verde midiendo nada. Se vio al
        // quitar el arreglo para ver enrojecer estas pruebas.
        $this->assertGreaterThan(
            0,
            ConexionQueReintenta::ESPERAS_MS[0],
            'Sin espera, los tres intentos caen dentro de la misma rafaga.'
        );
        // El 90% y no el 120 exacto: `usleep` pide un minimo, no lo garantiza,
        // y el reloj de Windows lo redondea a su resolucion. Medido aqui: una
        // espera de 120 ms durmio 118,1 y puso roja la suite entera con el
        // codigo bueno. El margen no afloja la prueba porque quien la sostiene
        // es la afirmacion de arriba: con la espera en cero, sigue enrojeciendo.
        $this->assertGreaterThanOrEqual(
            ConexionQueReintenta::ESPERAS_MS[0] * 0.9,
            $transcurrido,
            'Reintento de golpe: una rafaga de saturacion no ha tenido tiempo de pasar.'
        );
    }

    public function test_agotados_los_intentos_relanza_el_error_de_verdad(): void
    {
        $conector = $this->conector(array_fill(0, 5, new PDOException(self::ERROR_REAL)));

        try {
            $conector->createConnection('dsn', [], []);
            $this->fail('Tenia que haber lanzado.');
        } catch (RuntimeException $e) {
            // OJO al escribir esto con dos `catch`: PDOException EXTIENDE
            // RuntimeException, asi que el generico va primero y se traga el
            // que se queria distinguir. Se comprueba el tipo a mano.
            $this->assertInstanceOf(
                PDOException::class,
                $e,
                'Se perdio el error original: quien lea el registro no sabra que paso.'
            );
            $this->assertSame(self::ERROR_REAL, $e->getMessage());
        }

        $this->assertSame(
            count(ConexionQueReintenta::ESPERAS_MS) + 1,
            $conector->intentos,
            'No se rinde: una base caida de verdad alargaria cada peticion.'
        );
    }

    /**
     * Una contrasena mala no se arregla insistiendo, y reintentarla solo
     * retrasaria el mensaje que dice cual es el problema.
     */
    public function test_no_reintenta_un_error_de_configuracion(): void
    {
        $conector = $this->conector([
            new PDOException('SQLSTATE[08006] [7] connection to server at "127.0.0.1", port 5432 failed: FATAL:  database "no_existe" does not exist'),
            null,
        ]);

        try {
            $conector->createConnection('dsn', [], []);
            $this->fail('Tenia que haber lanzado a la primera.');
        } catch (PDOException) {
            // esperado
        }

        $this->assertSame(1, $conector->intentos);
    }

    /**
     * El mismo fallo con el mensaje TRADUCIDO se sigue reconociendo, por el
     * numero de Winsock que libpq pega al final y que no se traduce.
     *
     * No es un caso inventado: es el texto que da un Windows en espanol. Este
     * producto se instala en casas ajenas y el idioma del sistema no lo
     * elegimos nosotros.
     */
    public function test_reconoce_el_fallo_aunque_el_mensaje_venga_traducido(): void
    {
        $traducido = new PDOException(
            'SQLSTATE[08006] [7] connection to server at "127.0.0.1", port 5432 failed: No se puede establecer una conexion ya que el equipo de destino denego expresamente dicha conexion. (0x0000274D/10061)'
        );
        $traducido->errorInfo = ['08006', 7, 'No se puede establecer una conexion (0x0000274D/10061)'];

        $this->assertTrue(
            ConexionQueReintenta::esTransitorio($traducido),
            'Solo se reconoce en ingles: en un servidor con otro idioma el reintento no se dispara.'
        );
    }

    /**
     * Una contrasena mala llega con el MISMO codigo que una saturacion
     * (`08006`): por eso no se decide por codigo, y esto vigila que no se
     * vuelva a intentar.
     */
    public function test_el_codigo_no_reintenta_una_contrasena_mala(): void
    {
        $denegado = new PDOException('SQLSTATE[08006] [7] connection to server at "127.0.0.1", port 5432 failed: FATAL:  password authentication failed for user "matriculas"');
        $denegado->errorInfo = ['08006', 7, 'password authentication failed for user'];

        $this->assertFalse(ConexionQueReintenta::esTransitorio($denegado));
    }

    public function test_el_conector_registrado_para_pgsql_es_el_que_reintenta(): void
    {
        $this->assertInstanceOf(
            ConexionQueReintenta::class,
            $this->app->make('db.connector.pgsql')
        );
    }

    public function test_la_conexion_lleva_tope_de_espera(): void
    {
        $opciones = config('database.connections.pgsql.options');

        $this->assertArrayHasKey(
            PDO::ATTR_TIMEOUT,
            $opciones,
            'Sin tope, una base que no contesta cuelga la peticion hasta el 504 del CDN.'
        );
        $this->assertGreaterThan(0, $opciones[PDO::ATTR_TIMEOUT]);
    }

    /**
     * El tope no se queda en el archivo: viaja en las opciones de la conexion
     * que se esta usando.
     *
     * No se comprueba preguntandoselo al PDO —`getAttribute(ATTR_TIMEOUT)`
     * no lo devuelve el driver— sino en la
     * configuracion resuelta de la conexion viva, que es lo que Laravel le pasa
     * al constructor.
     */
    public function test_el_tope_de_espera_viaja_en_la_conexion_viva(): void
    {
        $opciones = DB::connection('pgsql')->getConfig('options');

        $this->assertArrayHasKey(PDO::ATTR_TIMEOUT, $opciones);
        $this->assertGreaterThan(0, $opciones[PDO::ATTR_TIMEOUT]);
    }

    /**
     * @param  array<int, \Throwable|null>  $guion
     */
    private function conector(array $guion): ConectorDeGuion
    {
        return new ConectorDeGuion($guion);
    }
}
