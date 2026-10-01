<?php

namespace App\Support;

use Illuminate\Database\Connectors\PostgresConnector;
use Throwable;

/**
 * Vuelve a intentar la conexion cuando el motor la rechaza por saturacion.
 *
 * Existe por un fallo medido en produccion el 08/09/2026, todavia con MariaDB:
 * el hosting compartido rechazaba la conexion al socket en rafagas de un
 * segundo, con `Operation not permitted`. Era el UNICO error de produccion que
 * aparecia en el registro —todos los dias, entre las 9 y las 11 de la manana,
 * que es cuando la gente usa el sistema— y no lo provocaba ninguna pantalla:
 * la base son 4,5 MB y las consultas contestan en 1-2 ms. Es la maquina
 * compartida, con una carga media de 26 a 35, frenando al contenedor. Con
 * PostgreSQL el rechazo del sistema operativo es el mismo; lo que cambia es
 * como llega envuelto.
 *
 * LA TRAMPA QUE JUSTIFICA ESTE ARCHIVO: Laravel YA reintenta la conexion
 * perdida, en `Connector::createConnection()`, y por eso parece que aqui no
 * hace falta nada. Pero decide con la lista de `LostConnectionDetector`, que
 * NO trae `Operation not permitted`. O sea que el reintento que ya existe no
 * se dispara justamente con el unico error que este sistema tiene. Antes de
 * borrar esta clase «porque el framework ya lo hace», busca esa cadena en esa
 * lista.
 *
 * Se reintenta y no se deja fallar porque el rechazo es INMEDIATO —es un EPERM,
 * no un tiempo de espera agotado— asi que un reintento cuesta lo que cueste la
 * espera y nada mas. Sin el, quien pulsa ve la pantalla en blanco del CDN.
 *
 * Y no reintenta cualquier cosa: una contrasena mala o una base que no existe
 * fallan a la primera, porque insistir no las va a arreglar y solo retrasaria
 * el mensaje que dice que hacer.
 */
class ConexionQueReintenta extends PostgresConnector
{
    /**
     * Lo que se espera entre intentos, en milisegundos.
     *
     * Son dos reintentos —tres intentos en total— y el peor caso son 480 ms
     * anadidos a una peticion que iba a fallar de todas formas. El numero sale
     * de que las rafagas medidas duran menos de un segundo; alargarlo mas
     * convertiria un fallo rapido en una espera, que es el problema que se
     * viene a arreglar.
     */
    public const ESPERAS_MS = [120, 360];

    /**
     * Los rechazos que SI vale la pena reintentar, por su texto.
     *
     * POR QUE NO POR CODIGO, que es lo que hacia la version de MariaDB: con
     * `pdo_pgsql` TODO fallo al conectar llega como `SQLSTATE[08006] [7]`,
     * tambien una contrasena mala o una base que no existe (medido el
     * 01/10/2026 con los tres casos). El codigo no distingue nada.
     *
     * Los textos vienen de dos sitios, y por eso hay dos clases de senal:
     *
     * - Del SISTEMA OPERATIVO, a traves de libpq: el rechazo de la red. En
     *   Windows llega traducido («No se puede establecer una conexion...»)
     *   pero lleva siempre el numero de Winsock, que no se traduce: 10061 es
     *   «rechazada», 10060 «tiempo agotado», 10013 «sin permiso» (el EPERM de
     *   produccion) y 10055 «sin bufer». En Linux llega el texto de
     *   `strerror`, que en un servidor es ingles.
     * - Del SERVIDOR: «too many clients», «starting up», «shutting down».
     *   Salen en el idioma de su `lc_messages`.
     *
     * OJO con lo que NO esta: `password authentication failed` y `database
     * ... does not exist` quedan fuera a proposito. Son errores de
     * configuracion, no de carga.
     */
    private const TRANSITORIOS = [
        'Operation not permitted',
        'Connection refused',
        'Connection timed out',
        'timeout expired',
        'Resource temporarily unavailable',
        'too many clients already',
        'remaining connection slots are reserved',
        'the database system is starting up',
        'the database system is shutting down',
        'could not fork new process',
        '/10013)',
        '/10055)',
        '/10060)',
        '/10061)',
    ];

    /** @param  array<string, mixed>  $config */
    public function createConnection($dsn, array $config, array $options)
    {
        $intentos = count(self::ESPERAS_MS) + 1;

        for ($i = 0; $i < $intentos; $i++) {
            try {
                return parent::createConnection($dsn, $config, $options);
            } catch (Throwable $e) {
                if ($i === $intentos - 1 || ! self::esTransitorio($e)) {
                    throw $e;
                }

                usleep(self::ESPERAS_MS[$i] * 1000);
            }
        }

        // Inalcanzable: el bucle o devuelve la conexion o relanza en la ultima
        // vuelta. Esta aqui para que el tipo de retorno sea cierto.
        throw new \RuntimeException('No se pudo conectar con la base de datos.');
    }

    /** ¿Este rechazo es de los que se arreglan solos volviendo a pedirlo? */
    public static function esTransitorio(Throwable $e): bool
    {
        foreach (self::TRANSITORIOS as $senal) {
            if (str_contains($e->getMessage(), $senal)) {
                return true;
            }
        }

        return false;
    }
}
