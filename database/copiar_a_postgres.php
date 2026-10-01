<?php

/**
 * Copia los datos de la base MariaDB de antes a la PostgreSQL de ahora.
 *
 *   php database/copiar_a_postgres.php            (destino vacio)
 *   php database/copiar_a_postgres.php --vaciar   (vacia el destino antes)
 *
 * ORIGEN: la base de `ORIGEN_DB_*` del `.env`, abierta en SOLO LECTURA. Este
 * guion no puede escribir en MariaDB aunque se equivoque: la sesion se abre con
 * `SET SESSION TRANSACTION READ ONLY`.
 *
 * DESTINO: la conexion de la aplicacion (`DB_*`), que tiene que ser `pgsql` y
 * tener ya el esquema (`php artisan migrate`).
 *
 * Copia tabla a tabla en el orden de las claves foraneas, con los ids de
 * origen, y al final pone cada secuencia en el maximo de su tabla para que la
 * siguiente fila no choque. Todo en UNA transaccion: si algo falla, el destino
 * queda como estaba.
 *
 * Lo que hace por el camino y no se deduce:
 *
 * - Los `tinyint(1)` de MariaDB llegan como 0/1 y van a columnas `boolean`.
 * - Las columnas GENERADAS (`activo_marca`, `ranura_activa`) no se copian: las
 *   calcula PostgreSQL. `comparar_motores.php` comprueba que salen iguales.
 * - Los triggers propios se APAGAN durante la carga y se vuelven a encender
 *   dentro de la misma transaccion. El de cupo rechazaria filas que hoy son
 *   validas: un cupo que se bajo despues de llenarse.
 * - `migrations` no se copia: la de PostgreSQL registra su propia migracion,
 *   y las 55 de MariaDB ya no existen en `database/migrations/`.
 *
 * Vive en `database/` y no en `app/` por lo mismo que `verificacion_esquema.php`:
 * copia TODAS las instituciones a la vez, y eso es justo lo que el filtro por
 * institucion (y su guardia) existen para impedir dentro de la aplicacion.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

const LOTE = 500;
const SIN_COPIAR = ['migrations'];

$vaciar = in_array('--vaciar', $argv, true);

$destino = DB::connection();

if ($destino->getDriverName() !== 'pgsql') {
    fwrite(STDERR, "El destino no es PostgreSQL ({$destino->getDriverName()}). No se copia nada.\n");
    exit(1);
}

$origen = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', env('ORIGEN_DB_HOST', '127.0.0.1'), env('ORIGEN_DB_PORT', '3307'), env('ORIGEN_DB_DATABASE')),
    (string) env('ORIGEN_DB_USERNAME'),
    (string) env('ORIGEN_DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
);
$origen->exec('SET SESSION TRANSACTION READ ONLY');

echo 'Origen:  MariaDB '.env('ORIGEN_DB_DATABASE').' en el '.env('ORIGEN_DB_PORT')." (solo lectura)\n";
echo 'Destino: PostgreSQL '.$destino->getDatabaseName().' en el '.$destino->getConfig('port')."\n\n";

$tablas = tablasEnOrden();

// ---------------------------------------------------------------------------
// El destino tiene que estar vacio. La unica fila que se tolera es la
// institucion 1 que siembra el esquema: se sustituye por la del origen.
// ---------------------------------------------------------------------------

$conDatos = [];

foreach ($tablas as $tabla) {
    $filas = (int) $destino->table($tabla)->count();
    $sembrada = $tabla === 'instituciones' && $filas === 1
        && $destino->table('instituciones')->where('id', 1)->where('nombre', 'Institución')->exists();

    if ($filas > 0 && ! $sembrada) {
        $conDatos[] = "{$tabla} ({$filas})";
    }
}

if ($conDatos !== [] && ! $vaciar) {
    fwrite(STDERR, 'El destino ya tiene datos en: '.implode(', ', $conDatos).".\n");
    fwrite(STDERR, "Vuelve a lanzarlo con --vaciar para borrarlos antes de copiar.\n");
    exit(1);
}

if ($vaciar && ! app()->environment('local')) {
    fwrite(STDERR, "--vaciar solo se acepta con APP_ENV=local. No se toca nada.\n");
    exit(1);
}

$destino->transaction(function () use ($destino, $origen, $tablas, $vaciar) {
    if ($vaciar) {
        $destino->unprepared('TRUNCATE TABLE '.implode(', ', $tablas).' RESTART IDENTITY CASCADE');
    } else {
        $destino->table('instituciones')->delete();
    }

    $conTriggers = array_column($destino->select(
        'SELECT DISTINCT c.relname AS tabla
           FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid
           JOIN pg_namespace n ON n.oid = c.relnamespace
          WHERE n.nspname = current_schema() AND NOT t.tgisinternal'
    ), 'tabla');

    foreach ($conTriggers as $tabla) {
        $destino->unprepared("ALTER TABLE {$tabla} DISABLE TRIGGER USER");
    }

    foreach ($tablas as $tabla) {
        $columnas = columnasCopiables($tabla);
        $booleanas = array_keys(array_filter($columnas, fn ($tipo) => $tipo === 'boolean'));
        $lista = implode(', ', array_map(fn ($c) => "`{$c}`", array_keys($columnas)));
        $clave = clavePrimaria($tabla);

        $lectura = $origen->query("SELECT {$lista} FROM `{$tabla}` ORDER BY ".implode(', ', array_map(fn ($c) => "`{$c}`", $clave)));
        $lote = [];
        $copiadas = 0;

        while ($fila = $lectura->fetch()) {
            foreach ($booleanas as $columna) {
                if ($fila[$columna] !== null) {
                    $fila[$columna] = (bool) $fila[$columna];
                }
            }

            $lote[] = $fila;

            if (count($lote) === LOTE) {
                $destino->table($tabla)->insert($lote);
                $copiadas += count($lote);
                $lote = [];
            }
        }

        if ($lote !== []) {
            $destino->table($tabla)->insert($lote);
            $copiadas += count($lote);
        }

        printf("  %-28s %6d filas\n", $tabla, $copiadas);
    }

    foreach ($conTriggers as $tabla) {
        $destino->unprepared("ALTER TABLE {$tabla} ENABLE TRIGGER USER");
    }

    // Cada secuencia, en el maximo de su tabla. Con la tabla vacia, la
    // siguiente sera la 1.
    foreach ($destino->select(
        "SELECT table_name AS tabla, column_name AS columna
           FROM information_schema.columns
          WHERE table_schema = current_schema() AND is_identity = 'YES'"
    ) as $identidad) {
        $destino->select(
            "SELECT setval(pg_get_serial_sequence(?, ?), COALESCE((SELECT MAX({$identidad->columna}) FROM {$identidad->tabla}), 1), (SELECT COUNT(*) > 0 FROM {$identidad->tabla}))",
            [$identidad->tabla, $identidad->columna]
        );
    }
});

echo "\nCopia terminada. Compruebala con: php database/comparar_motores.php\n";

// ---------------------------------------------------------------------------

/**
 * Las tablas del destino, cada una DESPUES de las que referencia.
 *
 * @return list<string>
 */
function tablasEnOrden(): array
{
    $todas = array_column(DB::select(
        'SELECT tablename FROM pg_tables WHERE schemaname = current_schema() ORDER BY tablename'
    ), 'tablename');
    $todas = array_values(array_diff($todas, SIN_COPIAR));

    $depende = array_fill_keys($todas, []);

    foreach (DB::select(
        "SELECT c.relname AS hija, p.relname AS padre
           FROM pg_constraint k
           JOIN pg_class c ON c.oid = k.conrelid
           JOIN pg_class p ON p.oid = k.confrelid
           JOIN pg_namespace n ON n.oid = c.relnamespace
          WHERE k.contype = 'f' AND n.nspname = current_schema()"
    ) as $fk) {
        if ($fk->hija !== $fk->padre) {
            $depende[$fk->hija][] = $fk->padre;
        }
    }

    $orden = [];

    while (count($orden) < count($todas)) {
        $avance = false;

        foreach ($todas as $tabla) {
            if (! in_array($tabla, $orden, true) && array_diff($depende[$tabla], $orden) === []) {
                $orden[] = $tabla;
                $avance = true;
            }
        }

        if (! $avance) {
            throw new RuntimeException('Hay un ciclo de claves foraneas: no hay orden de copia.');
        }
    }

    return $orden;
}

/**
 * Las columnas que se escriben, con su tipo. Fuera las generadas.
 *
 * @return array<string, string>
 */
function columnasCopiables(string $tabla): array
{
    $columnas = [];

    foreach (DB::select(
        "SELECT column_name, data_type FROM information_schema.columns
          WHERE table_schema = current_schema() AND table_name = ? AND is_generated = 'NEVER'
          ORDER BY ordinal_position",
        [$tabla]
    ) as $c) {
        $columnas[$c->column_name] = $c->data_type;
    }

    return $columnas;
}

/** @return list<string> */
function clavePrimaria(string $tabla): array
{
    return array_column(DB::select(
        'SELECT a.attname
           FROM pg_index i
           JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY (i.indkey)
          WHERE i.indrelid = ?::regclass AND i.indisprimary
          ORDER BY array_position(i.indkey, a.attnum)',
        [$tabla]
    ), 'attname');
}
