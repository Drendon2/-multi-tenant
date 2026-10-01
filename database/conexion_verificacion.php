<?php

/**
 * La conexion que usan los guiones de verificacion de `database/`, con las dos
 * barreras que impiden que se lleven por delante una base que importa.
 *
 * ─── Por que existe ────────────────────────────────────────────────────────
 *
 * Antes, cada guion traia escritas a mano las credenciales y el puerto de UNA
 * maquina: `127.0.0.1:3307`, usuario y contrasena `matriculas`. Eso publicaba
 * unas credenciales en el repositorio y obligaba a editar tres archivos para
 * correrlos en cualquier otro sitio.
 *
 * Pero hacia tambien algo bueno POR ACCIDENTE: en el servidor esa conexion
 * fallaba —alli la base va por socket y con otras credenciales—, asi que un
 * guion que empieza con `TRUNCATE` de once tablas no llegaba a hacer nada.
 * Leer el `.env` quita el problema de las credenciales y **destruye esa
 * proteccion accidental**: el mismo guion, en el servidor, apuntaria a
 * produccion y la vaciaria.
 *
 * De ahi que la barrera sea ahora explicita. Una proteccion que funciona por
 * accidente es una proteccion que se pierde en el primer arreglo que parezca
 * bueno.
 *
 * ─── Las dos barreras ──────────────────────────────────────────────────────
 *
 * 1. `APP_ENV` tiene que ser `local`. Cierra en FALSO: si la variable falta, o
 *    dice cualquier otra cosa, no se conecta. Es lo que protege produccion.
 * 2. Los guiones que vacian tablas exigen ademas `--borrar-datos` en la linea
 *    de ordenes (ver `confirmarBorradoDeDatos()`). Eso protege la base de
 *    desarrollo de quien —con toda la razon— cree que un guion llamado
 *    «verificacion» solo verifica.
 *
 * Se lee el `.env` a mano y NO se ejecuta, por la misma razon que lo escribe
 * `respaldar.sh`: un `source` haria correr cualquier cosa que alguien haya
 * dejado escrita ahi. Y no se levanta Laravel entero porque el sentido de estos
 * guiones es hablar con el motor sin el ORM de por medio.
 *
 * Se usa asi:
 *
 *     $db = require __DIR__.'/conexion_verificacion.php';
 *     confirmarBorradoDeDatos($db);   // solo los que vacian tablas
 */

/**
 * Segunda barrera: exige `--borrar-datos` y dice lo que se va a llevar.
 *
 * Va aqui y no en cada guion para que los dos digan lo mismo, y para que el
 * tercero que se escriba lo tenga sin acordarse.
 *
 * Las tres funciones de este archivo van tras un `function_exists`: la prueba
 * de concurrencia lo carga DOS veces (una conexion por cada lado de la
 * carrera), y PHP no deja declarar dos veces la misma funcion.
 */
if (! function_exists('confirmarBorradoDeDatos')) {
    function confirmarBorradoDeDatos(PDO $db): void
    {
        if (in_array('--borrar-datos', $_SERVER['argv'] ?? [], true)) {
            return;
        }

        $guion = basename($_SERVER['argv'][0] ?? 'el guion');

        fwrite(STDERR, "\n{$guion} NO es solo una comprobacion: VACIA la base antes de empezar.\n\n");

        // Se dice cuanto hay, no solo que se va a borrar: «11 tablas» no frena a
        // nadie, «589 matriculas» si.
        foreach (['perfiles', 'matriculas', 'promotorias', 'clases', 'asistencias'] as $tabla) {
            try {
                $cuantas = (int) $db->query("SELECT COUNT(*) FROM {$tabla}")->fetchColumn();
            } catch (PDOException) {
                continue;
            }

            if ($cuantas > 0) {
                fwrite(STDERR, sprintf("  se perderian %6d %s\n", $cuantas, $tabla));
            }
        }

        fwrite(STDERR, "\nSi la base es desechable, vuelve a lanzarlo asi:\n");
        fwrite(STDERR, "  php {$_SERVER['argv'][0]} --borrar-datos\n\n");

        exit(1);
    }
}

/**
 * Vacia las tablas de DATOS, todas en una sentencia, y reinicia sus ids.
 *
 * La lista NO va escrita a mano: se pregunta al motor. Escrita a mano se quedo
 * en agosto de 2025 y dejo fuera `sesiones_grupo` —que nacio despues— y las
 * cuatro tablas de actividades: un escenario de prueba sucio es peor que
 * ninguno, porque parece limpio.
 *
 * Se excluyen las de Laravel: `migrations` diria que no hay esquema, y las de
 * sesion, cache y colas no son datos del dominio.
 *
 * En MariaDB esto era un TRUNCATE por tabla con las claves foraneas apagadas;
 * PostgreSQL vacia el conjunto de una vez y no hace falta apagar nada.
 */
if (! function_exists('vaciarTablasDeDatos')) {
    function vaciarTablasDeDatos(PDO $db): void
    {
        $deLaravel = ['migrations', 'sessions', 'cache', 'cache_locks', 'jobs',
            'job_batches', 'failed_jobs', 'password_reset_tokens'];

        $tablas = array_diff(
            $db->query('SELECT tablename FROM pg_tables WHERE schemaname = current_schema()')->fetchAll(PDO::FETCH_COLUMN),
            $deLaravel
        );

        if ($tablas === []) {
            fwrite(STDERR, "La base no tiene tablas: corre antes las migraciones.\n");
            exit(1);
        }

        $db->exec('TRUNCATE TABLE '.implode(', ', $tablas).' RESTART IDENTITY CASCADE');
    }
}

/**
 * Pone cada secuencia en el maximo de su tabla.
 *
 * Hace falta tras insertar filas con el id escrito a mano: MariaDB movia su
 * AUTO_INCREMENT solo, PostgreSQL no, y la siguiente fila SIN id chocaria con
 * una de las escritas.
 */
if (! function_exists('ajustarSecuencias')) {
    function ajustarSecuencias(PDO $db): void
    {
        $identidades = $db->query(
            "SELECT table_name, column_name FROM information_schema.columns
              WHERE table_schema = current_schema() AND is_identity = 'YES'"
        )->fetchAll(PDO::FETCH_NUM);

        foreach ($identidades as [$tabla, $columna]) {
            $db->query(
                "SELECT setval(pg_get_serial_sequence('{$tabla}', '{$columna}'),
                        COALESCE((SELECT MAX({$columna}) FROM {$tabla}), 1),
                        (SELECT COUNT(*) > 0 FROM {$tabla}))"
            );
        }
    }
}

$env = dirname(__DIR__).'/.env';

if (! is_file($env)) {
    fwrite(STDERR, "No encuentro el .env: sin el no se a que base conectarme.\n");
    exit(1);
}

/** Lee una variable del .env sin interpretarlo. */
$leer = static function (string $clave) use ($env): string {
    foreach (file($env, FILE_IGNORE_NEW_LINES) as $linea) {
        if (str_starts_with($linea, $clave.'=')) {
            return trim(substr($linea, strlen($clave) + 1), " \t\"'");
        }
    }

    return '';
};

// ─── Primera barrera ───────────────────────────────────────────────────────
// Cierra en falso a proposito: se compara contra 'local' y no contra
// 'production'. Un `.env` sin APP_ENV, o con 'staging', o con una errata, no
// entra. Al reves —negar solo 'production'— cualquier valor inesperado abriria
// la puerta, que es justo lo contrario de lo que hace falta aqui.
$entorno = $leer('APP_ENV');

if ($entorno !== 'local') {
    fwrite(STDERR, "\nEste guion solo corre con APP_ENV=local.\n");
    fwrite(STDERR, 'El .env de aqui dice: '.($entorno === '' ? '(vacio o ausente)' : $entorno)."\n\n");
    fwrite(STDERR, "Vacia tablas antes de empezar. En un servidor eso es la base de la institucion:\n");
    fwrite(STDERR, "las matriculas, las asistencias y los documentos de todo el mundo.\n\n");
    exit(1);
}

$servidor = $leer('DB_HOST') ?: '127.0.0.1';
$puerto = $leer('DB_PORT') ?: '5432';
// La BASE se puede dar por el entorno, como hace `phpunit`:
//   DB_DATABASE=test_matriculas_mt php database/verificacion_esquema.php --borrar-datos
// En esta version el `.env` apunta a una copia con datos reales, y sin esto la
// unica forma de apuntar a una base desechable era editarlo. Las dos barreras
// de arriba siguen igual: APP_ENV sale SIEMPRE del `.env`.
$base = getenv('DB_DATABASE') ?: $leer('DB_DATABASE');
$usuario = $leer('DB_USERNAME');
$clave = $leer('DB_PASSWORD');

if ($base === '' || $usuario === '') {
    fwrite(STDERR, "El .env no trae DB_DATABASE o DB_USERNAME.\n");
    exit(1);
}

try {
    return new PDO(
        "pgsql:host={$servidor};port={$puerto};dbname={$base}",
        $usuario,
        $clave,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException) {
    // El mensaje del motor NO se imprime tal cual: lleva el usuario y a veces el
    // servidor. Lo que hace falta saber aqui es a donde se intento entrar.
    fwrite(STDERR, "No pude conectar a {$base} en {$servidor}:{$puerto}.\n");
    fwrite(STDERR, "Revisa las credenciales del .env y que la base este levantada.\n");
    exit(1);
}
