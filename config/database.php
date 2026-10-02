<?php

use Illuminate\Support\Str;

/*
 * La conexion de la APLICACION. Entra con el rol `matriculas` (DB_USERNAME):
 * solo lee y escribe filas, y Row Level Security la ata a la institucion de
 * cada peticion (ver `App\Support\InstitucionActual`).
 */
$pgsql = [
    'driver' => 'pgsql',
    'url' => env('DB_URL'),
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => env('DB_PORT', '5432'),
    'database' => env('DB_DATABASE', 'laravel'),
    'username' => env('DB_USERNAME', 'root'),
    'password' => env('DB_PASSWORD', ''),
    'charset' => env('DB_CHARSET', 'utf8'),
    'prefix' => '',
    'prefix_indexes' => true,
    'search_path' => 'public',
    'sslmode' => env('DB_SSLMODE', 'prefer'),
    // La hora de la sesion de la base, que es la que ponen los
    // valores por defecto del esquema (`LOCALTIMESTAMP`). La misma de
    // la aplicacion: las columnas son `timestamp` sin zona y guardan
    // la hora local tal cual.
    'timezone' => env('APP_TIMEZONE', 'America/Bogota'),
    'options' => [
        // CUANTO se espera a que la base conteste al conectar. Sin esto
        // PDO espera lo que diga el sistema, que en la practica es «para
        // siempre»: el 08/09/2026 una peticion se quedo colgada y quien
        // la lanzo vio el 504 en blanco del CDN a los 60 segundos, sin
        // ninguna pista de que habia pasado. Con el tope, una base que
        // no contesta da un error legible y deja rastro en el registro.
        //
        // `pdo_pgsql` lo traduce al `connect_timeout` de PostgreSQL.
        // Un cero es «sin espera», un valor legitimo: por eso no pasa
        // por ningun filtro de vacios.
        PDO::ATTR_TIMEOUT => (int) env('DB_ESPERA_CONEXION', 5),
    ],
];

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for database operations. This is
    | the connection which will be utilized unless another connection
    | is explicitly specified when you execute a query / statement.
    |
    */

    'default' => env('DB_CONNECTION', 'pgsql'),

    /*
     * El rol que lee TODAS las instituciones (BYPASSRLS), para las estadisticas
     * globales del paso 5. El guion de RLS le da permiso de solo lectura si
     * existe. Nada lo usa todavia.
     */
    'rol_global' => env('DB_ROL_GLOBAL', 'matriculas_global'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Below are all of the database connections defined for your application.
    | An example configuration is provided for each database system which
    | is supported by Laravel. You're free to add / remove connections.
    |
    */

    'connections' => [

        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            'busy_timeout' => null,
            'journal_mode' => null,
            'synchronous' => null,
            'transaction_mode' => 'DEFERRED',
        ],

        'pgsql' => $pgsql,

        /*
         * La del DUEÑO de las tablas (DB_DUENO_*). Corre las migraciones
         * (`php artisan migrate --database=pgsql_dueno`) y los guiones de
         * `database/`, que copian y comparan TODAS las instituciones: por ser
         * el dueño, RLS no le aplica. La aplicacion NO la usa nunca, y
         * `FiltroDeInstitucionUnicoTest` vigila que siga asi.
         */
        'pgsql_dueno' => array_merge($pgsql, [
            'username' => env('DB_DUENO_USERNAME', 'matriculas_dueno'),
            'password' => env('DB_DUENO_PASSWORD', ''),
        ]),

        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', '1433'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            // 'encrypt' => env('DB_ENCRYPT', 'yes'),
            // 'trust_server_certificate' => env('DB_TRUST_SERVER_CERTIFICATE', 'false'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run on the database.
    |
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is an open source, fast, and advanced key-value store that also
    | provides a richer body of commands than a typical key-value system
    | such as Memcached. You may define your connection settings here.
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-database-'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

    ],

];
