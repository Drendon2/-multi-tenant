<?php

/**
 * Compara, tabla por tabla, la base MariaDB de antes con la PostgreSQL de
 * ahora.
 *
 *   php database/comparar_motores.php
 *
 * Dos comprobaciones por tabla, y la segunda es la que importa:
 *
 * 1. CONTEO de filas.
 * 2. CONTENIDO: la huella de cada fila, con los valores normalizados, y la
 *    huella del conjunto. Un conteo que cuadra no dice que las filas sean las
 *    mismas: una fecha corrida una hora o un booleano invertido pasan
 *    cualquier conteo.
 *
 * La huella del conjunto se hace con las huellas de fila ORDENADAS, no con las
 * filas en el orden en que llegan: cada motor ordena el texto con su cotejo y
 * el mismo `ORDER BY` puede devolver otro orden sin que nada este mal.
 *
 * Normalizacion: booleanos a 1/0 (MariaDB los guarda como `tinyint(1)`), nulos
 * a una marca propia, todo lo demas como texto. Se comparan TAMBIEN las
 * columnas generadas (`activo_marca`, `ranura_activa`): en MariaDB las calcula
 * su expresion y en PostgreSQL la traducida, y que coincidan es la prueba de
 * que la traduccion es fiel.
 *
 * Solo LEE: MariaDB se abre en solo lectura y en PostgreSQL no se escribe.
 * Sale con codigo 1 si hay alguna diferencia.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

/** No se comparan: cada motor tiene la suya (ver `copiar_a_postgres.php`). */
const SIN_COMPARAR = ['migrations'];

const NULO = "\u{2400}";

$destino = DB::connection();

if ($destino->getDriverName() !== 'pgsql') {
    fwrite(STDERR, "La conexion de la aplicacion no es PostgreSQL.\n");
    exit(1);
}

$origen = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', env('ORIGEN_DB_HOST', '127.0.0.1'), env('ORIGEN_DB_PORT', '3307'), env('ORIGEN_DB_DATABASE')),
    (string) env('ORIGEN_DB_USERNAME'),
    (string) env('ORIGEN_DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
);
$origen->exec('SET SESSION TRANSACTION READ ONLY');

$enOrigen = array_map(fn ($f) => array_values($f)[0], $origen->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll());
$enDestino = array_column($destino->select('SELECT tablename FROM pg_tables WHERE schemaname = current_schema()'), 'tablename');

$todas = array_values(array_diff(array_unique([...$enOrigen, ...$enDestino]), SIN_COMPARAR));
sort($todas);

if ($todas === []) {
    // Una comparacion sin tablas diria «identicas» sin haber mirado nada.
    fwrite(STDERR, "No hay tablas que comparar: revisa las dos conexiones.\n");
    exit(1);
}

echo 'MariaDB    '.env('ORIGEN_DB_DATABASE').' (puerto '.env('ORIGEN_DB_PORT').")\n";
echo 'PostgreSQL '.$destino->getDatabaseName().' (puerto '.$destino->getConfig('port').")\n\n";
printf("%-28s %9s %9s  %s\n", 'TABLA', 'MARIADB', 'POSTGRES', 'CONTENIDO');
echo str_repeat('-', 64)."\n";

$diferencias = 0;
$filasTotales = 0;

foreach ($todas as $tabla) {
    if (! in_array($tabla, $enOrigen, true) || ! in_array($tabla, $enDestino, true)) {
        printf("%-28s %9s %9s  %s\n", $tabla,
            in_array($tabla, $enOrigen, true) ? 'si' : 'FALTA',
            in_array($tabla, $enDestino, true) ? 'si' : 'FALTA',
            'la tabla no esta en los dos');
        $diferencias++;

        continue;
    }

    $columnas = columnas($tabla);
    $booleanas = array_keys(array_filter($columnas, fn ($tipo) => $tipo === 'boolean'));
    $nombres = array_keys($columnas);

    $huellasOrigen = huellas(
        $origen->query('SELECT '.implode(', ', array_map(fn ($c) => "`{$c}`", $nombres))." FROM `{$tabla}`"),
        $nombres,
        $booleanas,
    );
    $huellasDestino = huellas(
        $destino->getPdo()->query('SELECT '.implode(', ', $nombres)." FROM {$tabla}", PDO::FETCH_ASSOC),
        $nombres,
        $booleanas,
    );

    $cuentaOrigen = count($huellasOrigen);
    $cuentaDestino = count($huellasDestino);
    $filasTotales += $cuentaOrigen;

    $iguales = $huellasOrigen === $huellasDestino;
    $detalle = 'identico';

    if (! $iguales) {
        $soloOrigen = count(array_diff($huellasOrigen, $huellasDestino));
        $soloDestino = count(array_diff($huellasDestino, $huellasOrigen));
        $detalle = "DISTINTO: {$soloOrigen} filas solo en MariaDB, {$soloDestino} solo en PostgreSQL";
        $diferencias++;
    }

    printf("%-28s %9d %9d  %s\n", $tabla, $cuentaOrigen, $cuentaDestino, $detalle);
}

echo str_repeat('-', 64)."\n";
echo 'Sin comparar: '.implode(', ', SIN_COMPARAR)." (cada motor registra sus propias migraciones).\n";

if ($diferencias > 0) {
    echo "\n{$diferencias} tablas con diferencias.\n";
    exit(1);
}

echo "\nLas ".count($todas)." tablas coinciden en filas y contenido ({$filasTotales} filas).\n";

// ---------------------------------------------------------------------------

/** @return array<string, string> columna => tipo en PostgreSQL */
function columnas(string $tabla): array
{
    $columnas = [];

    foreach (DB::select(
        'SELECT column_name, data_type FROM information_schema.columns
          WHERE table_schema = current_schema() AND table_name = ?
          ORDER BY ordinal_position',
        [$tabla]
    ) as $c) {
        $columnas[$c->column_name] = $c->data_type;
    }

    return $columnas;
}

/**
 * Las huellas de todas las filas, ordenadas.
 *
 * @param  list<string>  $nombres
 * @param  list<string>  $booleanas
 * @return list<string>
 */
function huellas(PDOStatement $lectura, array $nombres, array $booleanas): array
{
    $huellas = [];

    while ($fila = $lectura->fetch(PDO::FETCH_ASSOC)) {
        $valores = [];

        foreach ($nombres as $columna) {
            $valor = $fila[$columna];

            if ($valor === null) {
                $valores[] = NULO;
            } elseif (in_array($columna, $booleanas, true)) {
                $valores[] = $valor ? '1' : '0';
            } else {
                $valores[] = (string) $valor;
            }
        }

        $huellas[] = md5(implode("\x1F", $valores));
    }

    sort($huellas);

    return $huellas;
}
