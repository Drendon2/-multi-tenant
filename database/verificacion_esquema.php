<?php

/**
 * Verificacion del esquema contra PostgreSQL.
 *
 * No comprueba que las migraciones CORRAN —eso ya lo dice artisan—, sino que
 * las garantias que el esquema promete se cumplan de verdad: sobre todo las
 * que se apoyan en columnas generadas con indice unico, en el trigger de cupo y
 * en el cotejo que no distingue mayusculas ni tildes.
 *
 * VACIA la base. Se ejecuta contra una desechable:
 *   DB_DATABASE=test_matriculas_mt php database/verificacion_esquema.php --borrar-datos
 */
// La conexion sale del .env, no de credenciales escritas aqui: ver
// `conexion_verificacion.php`.
$db = require __DIR__.'/conexion_verificacion.php';

$pasadas = 0;
$fallidas = 0;

/** Comprueba que una operacion sea RECHAZADA por la base de datos. */
function rechaza(PDO $db, string $titulo, callable $op, string $esperado = ''): void
{
    global $pasadas, $fallidas;
    try {
        $op($db);
        echo "  FALLO   $titulo\n          se esperaba un rechazo y la operacion paso.\n";
        $fallidas++;
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if ($esperado !== '' && stripos($msg, $esperado) === false) {
            echo "  FALLO   $titulo\n          rechazada, pero por otro motivo: $msg\n";
            $fallidas++;

            return;
        }
        echo "  ok      $titulo\n";
        $pasadas++;
    }
}

/** Comprueba que una operacion sea ACEPTADA. */
function acepta(PDO $db, string $titulo, callable $op): void
{
    global $pasadas, $fallidas;
    try {
        $op($db);
        echo "  ok      $titulo\n";
        $pasadas++;
    } catch (PDOException $e) {
        echo "  FALLO   $titulo\n          se esperaba que pasara: {$e->getMessage()}\n";
        $fallidas++;
    }
}

// ---------------------------------------------------------------------------
// Datos base
// ---------------------------------------------------------------------------
confirmarBorradoDeDatos($db);

vaciarTablasDeDatos($db);

// Dos instituciones: casi todo ocurre en la 1, y la 2 esta para comprobar que
// las restricciones unicas son POR institucion (ver el final).
$db->exec("INSERT INTO instituciones (id, nombre) VALUES (1,'Casa A'), (2,'Casa B')");

$db->exec("INSERT INTO users (id, institucion_id, username, password, activo, created_at, updated_at)
           VALUES (1,1,'ana','x',true,NOW(),NOW()), (2,1,'beto','x',true,NOW(),NOW())");
$db->exec("INSERT INTO perfiles (id, institucion_id, user_id, rol, nombre_completo, fecha_nacimiento, telefono, created_at, updated_at)
           VALUES (1,1,1,'estudiante','Ana Ruiz','2000-05-01','3000000000',NOW(),NOW()),
                  (2,1,2,'profesor','Beto Diaz','1985-03-12','3000000001',NOW(),NOW())");
$db->exec("INSERT INTO areas (id, institucion_id, nombre, created_at, updated_at) VALUES (1,1,'Musica',NOW(),NOW())");
$db->exec("INSERT INTO periodos (id, institucion_id, nombre, fecha_inicio, fecha_fin, activo, matriculas_abiertas, created_at, updated_at)
           VALUES (1,1,'2026-1','2026-01-15','2026-06-30',true,true,NOW(),NOW())");
$db->exec("INSERT INTO promotorias (id, institucion_id, nombre, area_id, profesor_id, created_at, updated_at)
           VALUES (1,1,'Violin',1,2,NOW(),NOW()), (2,1,'Guitarra',1,2,NOW(),NOW()), (3,1,'Piano',1,2,NOW(),NOW()),
                  (4,1,'Flauta',1,2,NOW(),NOW()), (5,1,'Canto',1,2,NOW(),NOW())");
ajustarSecuencias($db);

$nuevaMatricula = fn ($est, $promo, $ranura, $estado = 'pendiente') => "INSERT INTO matriculas (institucion_id, estudiante_id, promotoria_id, periodo_id, fecha, estado, ranura, created_at, updated_at)
     VALUES (1, $est, $promo, 1, NOW(), '$estado', $ranura, NOW(), NOW())";

echo "\n== Periodo: solo uno en curso (indice unico parcial emulado) ==\n";
acepta($db, 'un segundo periodo INACTIVO entra', fn ($d) => $d->exec(
    "INSERT INTO periodos (institucion_id, nombre, fecha_inicio, fecha_fin, activo, matriculas_abiertas, created_at, updated_at)
     VALUES (1,'2026-2','2026-07-01','2026-12-15',false,false,NOW(),NOW())"));
rechaza($db, 'un segundo periodo ACTIVO se rechaza', fn ($d) => $d->exec(
    "INSERT INTO periodos (institucion_id, nombre, fecha_inicio, fecha_fin, activo, matriculas_abiertas, created_at, updated_at)
     VALUES (1,'2027-1','2027-01-15','2027-06-30',true,false,NOW(),NOW())"), 'un_periodo_activo_por_institucion');
rechaza($db, 'activar por UPDATE un segundo periodo se rechaza', fn ($d) => $d->exec(
    "UPDATE periodos SET activo = true WHERE nombre = '2026-2'"), 'un_periodo_activo_por_institucion');

echo "\n== Matricula: unicidad y ranuras ==\n";
acepta($db, 'primera matricula de Ana (Violin, ranura 1)', fn ($d) => $d->exec($nuevaMatricula(1, 1, 1)));
rechaza($db, 'la misma promotoria y periodo se rechaza', fn ($d) => $d->exec($nuevaMatricula(1, 1, 2)),
    'unica_matricula_por_periodo');
acepta($db, 'segunda promotoria en ranura 2', fn ($d) => $d->exec($nuevaMatricula(1, 2, 2)));
rechaza($db, 'tercera promotoria reusando la ranura 1', fn ($d) => $d->exec($nuevaMatricula(1, 3, 1)),
    'una_matricula_por_ranura_y_periodo');
rechaza($db, 'ranura 7 rompe el techo del esquema', fn ($d) => $d->exec($nuevaMatricula(1, 3, 7)),
    'ranura_valida');
rechaza($db, 'un estado inventado se rechaza', fn ($d) => $d->exec($nuevaMatricula(1, 3, 3, 'aprobada')),
    'estado_valido');

echo "\n== La retirada LIBERA la ranura, la cancelacion en tramite NO ==\n";
$db->exec("UPDATE matriculas SET estado='cancelacion_solicitada' WHERE estudiante_id=1 AND promotoria_id=1");
rechaza($db, 'con cancelacion en tramite la ranura 1 sigue ocupada', fn ($d) => $d->exec($nuevaMatricula(1, 3, 1)),
    'una_matricula_por_ranura_y_periodo');
$db->exec("UPDATE matriculas SET estado='retirada' WHERE estudiante_id=1 AND promotoria_id=1");
acepta($db, 'tras retirarla, la ranura 1 queda libre', fn ($d) => $d->exec($nuevaMatricula(1, 3, 1)));
$db->exec("UPDATE matriculas SET estado='retirada' WHERE estudiante_id=1 AND promotoria_id=3");
acepta($db, 'dos retiradas comparten ranura (NULL != NULL)', fn ($d) => $d->exec($nuevaMatricula(1, 4, 1)));

echo "\n== Trigger de cupo ==\n";
$db->exec('DELETE FROM matriculas');
$db->exec('INSERT INTO cupos_promotoria (institucion_id, promotoria_id, periodo_id, cupo_maximo, created_at, updated_at)
           VALUES (1, 5, 1, 2, NOW(), NOW())');
acepta($db, 'cupo 2: entra la primera', fn ($d) => $d->exec($nuevaMatricula(1, 5, 1)));
$db->exec("INSERT INTO users (id, institucion_id, username, password, activo, created_at, updated_at) VALUES (3,1,'caro','x',true,NOW(),NOW())");
$db->exec("INSERT INTO perfiles (id, institucion_id, user_id, rol, nombre_completo, fecha_nacimiento, telefono, created_at, updated_at)
           VALUES (3,1,3,'estudiante','Caro Paz','2001-02-02','3000000002',NOW(),NOW())");
acepta($db, 'cupo 2: entra la segunda', fn ($d) => $d->exec($nuevaMatricula(3, 5, 1)));
$db->exec("INSERT INTO users (id, institucion_id, username, password, activo, created_at, updated_at) VALUES (4,1,'dani','x',true,NOW(),NOW())");
$db->exec("INSERT INTO perfiles (id, institucion_id, user_id, rol, nombre_completo, fecha_nacimiento, telefono, created_at, updated_at)
           VALUES (4,1,4,'estudiante','Dani Gil','2002-03-03','3000000003',NOW(),NOW())");
rechaza($db, 'cupo 2: la tercera se rechaza', fn ($d) => $d->exec($nuevaMatricula(4, 5, 1)),
    'no tiene cupos disponibles');

echo "\n== Trigger de cupo: lo que NO debe bloquear (la parte delicada) ==\n";
// Con la promotoria LLENA, el personal tiene que poder seguir operando sobre
// las matriculas que ya existen. Si el trigger las bloqueara, bajar un cupo
// dejaria al profesor sin poder confirmar a nadie.
acepta($db, 'confirmar una matricula ya existente en promotoria LLENA',
    fn ($d) => $d->exec("UPDATE matriculas SET estado='activa' WHERE estudiante_id=1 AND promotoria_id=5"));
acepta($db, 'pedir cancelacion en promotoria LLENA',
    fn ($d) => $d->exec("UPDATE matriculas SET estado='cancelacion_solicitada' WHERE estudiante_id=3 AND promotoria_id=5"));
acepta($db, 'retirar siempre pasa',
    fn ($d) => $d->exec("UPDATE matriculas SET estado='retirada' WHERE estudiante_id=3 AND promotoria_id=5"));
// Y ahora que una se retiro, queda un sitio: reactivarla debe poder.
acepta($db, 'reactivar una retirada cuando volvio a haber sitio',
    fn ($d) => $d->exec("UPDATE matriculas SET estado='activa' WHERE estudiante_id=3 AND promotoria_id=5"));
// Reactivar una retirada cuando el sitio YA lo tomo otro si debe bloquearse.
// Se monta en Piano (cupo 1) para que el escenario quede a la vista:
//   Dani entra y ocupa el unico sitio -> se retira -> Evi ocupa el sitio libre
//   -> Dani ya no puede volver, y esa es exactamente la carrera que el trigger
//   tiene que atajar tambien en el UPDATE, no solo en el INSERT.
$db->exec("INSERT INTO users (id, institucion_id, username, password, activo, created_at, updated_at) VALUES (5,1,'evi','x',true,NOW(),NOW())");
$db->exec("INSERT INTO perfiles (id, institucion_id, user_id, rol, nombre_completo, fecha_nacimiento, telefono, created_at, updated_at)
           VALUES (5,1,5,'estudiante','Evi Mora','2003-04-04','3000000004',NOW(),NOW())");
ajustarSecuencias($db);
$db->exec('INSERT INTO cupos_promotoria (institucion_id, promotoria_id, periodo_id, cupo_maximo, created_at, updated_at)
           VALUES (1, 3, 1, 1, NOW(), NOW())');
acepta($db, 'Piano cupo 1: Dani toma el unico sitio', fn ($d) => $d->exec($nuevaMatricula(4, 3, 1)));
$db->exec("UPDATE matriculas SET estado='retirada' WHERE estudiante_id=4 AND promotoria_id=3");
acepta($db, 'Dani se retira y Evi ocupa el sitio libre', fn ($d) => $d->exec($nuevaMatricula(5, 3, 1)));
rechaza($db, 'Dani ya no puede volver: el sitio esta tomado',
    fn ($d) => $d->exec("UPDATE matriculas SET estado='activa' WHERE estudiante_id=4 AND promotoria_id=3"),
    'no tiene cupos disponibles');

echo "\n== Sin cupo definido no hay tope ==\n";
for ($i = 6; $i <= 10; $i++) {
    $db->exec("INSERT INTO users (id, institucion_id, username, password, activo, created_at, updated_at) VALUES ($i,1,'u$i','x',true,NOW(),NOW())");
    $db->exec("INSERT INTO perfiles (id, institucion_id, user_id, rol, nombre_completo, fecha_nacimiento, telefono, created_at, updated_at)
               VALUES ($i,1,$i,'estudiante','Persona $i','2000-01-01','300000000$i',NOW(),NOW())");
}
ajustarSecuencias($db);
acepta($db, 'Violin no tiene fila de cupo: admite a los cinco', function ($d) use ($nuevaMatricula) {
    for ($i = 6; $i <= 10; $i++) {
        $d->exec($nuevaMatricula($i, 1, 1));
    }
});

echo "\n== Otros CHECK del esquema ==\n";
rechaza($db, 'rol inventado en perfiles', fn ($d) => $d->exec(
    "INSERT INTO perfiles (institucion_id, user_id, rol, nombre_completo, fecha_nacimiento, telefono, created_at, updated_at)
     VALUES (1,1,'rector','X','2000-01-01','300',NOW(),NOW())"), 'rol_valido');
rechaza($db, 'limite de promotorias fuera de 1..6', fn ($d) => $d->exec(
    'INSERT INTO configuracion_institucion (institucion_id, limite_promotorias_por_periodo, created_at, updated_at)
     VALUES (1, 9, NOW(), NOW())'), 'limite_promotorias_valido');
rechaza($db, 'color de acento que no es hex', fn ($d) => $d->exec(
    "INSERT INTO configuracion_institucion (institucion_id, color_acento, created_at, updated_at)
     VALUES (1, 'verde', NOW(), NOW())"), 'color_acento_hex');
rechaza($db, 'estrato 8 en la encuesta', fn ($d) => $d->exec(
    "INSERT INTO encuestas_demograficas (institucion_id, perfil_id, genero, barrio, estrato, nivel_educativo, ocupacion, created_at, updated_at)
     VALUES (1,1,'f','Centro',8,'tecnico','estudiante',NOW(),NOW())"), 'estrato_valido');
rechaza($db, 'nivel de grupo inventado', fn ($d) => $d->exec(
    "INSERT INTO grupos (institucion_id, promotoria_id, nivel, nombre, salon, cupo_maximo, created_at, updated_at)
     VALUES (1,1,'experto','Grupo A','A1',10,NOW(),NOW())"), 'nivel_valido');
rechaza($db, 'estado de asistencia inventado', function ($d) {
    $d->exec("INSERT INTO grupos (id, institucion_id, promotoria_id, nivel, nombre, salon, cupo_maximo, created_at, updated_at)
              VALUES (1,1,1,'basico','Grupo A','A1',10,NOW(),NOW())");
    $d->exec('INSERT INTO clases (id, institucion_id, grupo_id, periodo_id, fecha_hora, registrada_por_id, confirmaciones_requeridas, created_at, updated_at)
              VALUES (1,1,1,1,NOW(),2,3,NOW(),NOW())');
    $mid = $d->query('SELECT id FROM matriculas LIMIT 1')->fetchColumn();
    $d->exec("INSERT INTO asistencias (institucion_id, clase_id, matricula_id, estado, fecha_registro, created_at, updated_at)
              VALUES (1,1,$mid,'tarde',NOW(),NOW(),NOW())");
}, 'estado_asistencia_valido');

/*
 * EL CODIGO DEL CARNE QR TIENE QUE SER UNICO, y esa unicidad no es un adorno:
 * es lo que convierte «coincide con este codigo» en «es esta persona». Con dos
 * perfiles compartiendolo, leer un carne en clase marcaria a uno de los dos al
 * azar, sin que nada fallara.
 *
 * Y NULO TIENE QUE PODER REPETIRSE, que es la otra mitad y la que se romperia
 * sin darse cuenta: la columna nace vacia para todo el mundo y solo se llena al
 * pedir el carne. Un indice unico que no admitiera varios nulos dejaria el
 * sistema con UNA sola persona sin carne, y el error saldria al crear la
 * segunda cuenta, lejos de aqui.
 */
echo "\n== Carne QR: el codigo identifica a una sola persona ==\n";
rechaza($db, 'dos perfiles con el mismo codigo de carne', function ($d) {
    $d->exec("UPDATE perfiles SET codigo_qr = 'abcdefghij012345' WHERE id = 1");
    $d->exec("UPDATE perfiles SET codigo_qr = 'abcdefghij012345' WHERE id = 2");
}, 'duplicate key');
acepta($db, 'varios perfiles SIN codigo conviven (nulo se repite)', function ($d) {
    $d->exec('UPDATE perfiles SET codigo_qr = NULL WHERE id IN (1, 2)');
    $n = $d->query('SELECT COUNT(*) FROM perfiles WHERE codigo_qr IS NULL')->fetchColumn();
    if ($n < 2) {
        throw new PDOException("se esperaban al menos dos perfiles sin codigo, hay $n");
    }
});

/*
 * EL ENLACE DE UNA PROMOTORIA (29/09/2026), por lo mismo que el carne: el
 * token es lo que dice EN QUE promotoria se matricula quien lo abre, y dos
 * con el mismo mandarian a la gente a una de las dos al azar. Y nulo se
 * repite: nace vacio para todas y solo se llena al encenderlo.
 */
echo "\n== Enlace de promotoria: el token apunta a una sola ==\n";
rechaza($db, 'dos promotorias con el mismo enlace', function ($d) {
    $d->exec("UPDATE promotorias SET enlace_token = 'abcdefghij012345' WHERE id = 1");
    $d->exec("UPDATE promotorias SET enlace_token = 'abcdefghij012345' WHERE id = 2");
}, 'duplicate key');
acepta($db, 'varias promotorias SIN enlace conviven (nulo se repite)', function ($d) {
    $d->exec('UPDATE promotorias SET enlace_token = NULL WHERE id IN (1, 2)');
    $n = $d->query('SELECT COUNT(*) FROM promotorias WHERE enlace_token IS NULL')->fetchColumn();
    if ($n < 2) {
        throw new PDOException("se esperaban al menos dos promotorias sin enlace, hay $n");
    }
});

echo "\n== Integridad referencial ==\n";
rechaza($db, 'borrar una promotoria con matriculas (RESTRICT)',
    fn ($d) => $d->exec('DELETE FROM promotorias WHERE id = 1'), 'foreign key');
rechaza($db, 'borrar un periodo con matriculas (RESTRICT)',
    fn ($d) => $d->exec('DELETE FROM periodos WHERE id = 1'), 'foreign key');
acepta($db, 'borrar una cuenta arrastra su perfil (CASCADE)', function ($d) {
    $d->exec('DELETE FROM users WHERE id = 10');
    $n = $d->query('SELECT COUNT(*) FROM perfiles WHERE user_id = 10')->fetchColumn();
    if ($n != 0) {
        throw new PDOException('el perfil sobrevivio');
    }
});

/*
 * VARIAS INSTITUCIONES EN LA MISMA BASE (multi-institucion, paso 1). Cada fila
 * dice de quien es, y lo que era unico en toda la base pasa a serlo dentro de
 * cada una: dos casas de la cultura tienen su «Musica» y su «2026-1», y cada
 * una su periodo en curso.
 *
 * La columna NO tiene valor por defecto a proposito: una fila escrita sin
 * decir de quien es tiene que FALLAR, no caer en silencio en la institucion 1.
 */
echo "\n== Varias instituciones: cada fila dice de quien es ==\n";
rechaza($db, 'una fila sin institucion se rechaza (no cae en la 1)', fn ($d) => $d->exec(
    "INSERT INTO areas (nombre, created_at, updated_at) VALUES ('Danza',NOW(),NOW())"), 'institucion_id');
rechaza($db, 'una institucion que no existe se rechaza', fn ($d) => $d->exec(
    "INSERT INTO areas (institucion_id, nombre, created_at, updated_at) VALUES (99,'Danza',NOW(),NOW())"), 'foreign key');
acepta($db, 'otra institucion tiene SU departamento «Musica»', fn ($d) => $d->exec(
    "INSERT INTO areas (institucion_id, nombre, created_at, updated_at) VALUES (2,'Musica',NOW(),NOW())"));
rechaza($db, 'dos «Musica» en la MISMA institucion se rechaza', fn ($d) => $d->exec(
    "INSERT INTO areas (institucion_id, nombre, created_at, updated_at) VALUES (2,'Musica',NOW(),NOW())"),
    'areas_nombre_por_institucion');
acepta($db, 'otra institucion tiene SU periodo «2026-1» en curso', fn ($d) => $d->exec(
    "INSERT INTO periodos (institucion_id, nombre, fecha_inicio, fecha_fin, activo, matriculas_abiertas, created_at, updated_at)
     VALUES (2,'2026-1','2026-01-15','2026-06-30',true,false,NOW(),NOW())"));
rechaza($db, 'dos periodos en curso en la MISMA institucion se rechaza', fn ($d) => $d->exec(
    "INSERT INTO periodos (institucion_id, nombre, fecha_inicio, fecha_fin, activo, matriculas_abiertas, created_at, updated_at)
     VALUES (2,'2026-2','2026-07-01','2026-12-15',true,false,NOW(),NOW())"), 'un_periodo_activo_por_institucion');
rechaza($db, 'dos configuraciones para la misma institucion se rechaza', function ($d) {
    $d->exec('INSERT INTO configuracion_institucion (institucion_id, created_at, updated_at) VALUES (2, NOW(), NOW())');
    $d->exec('INSERT INTO configuracion_institucion (institucion_id, created_at, updated_at) VALUES (2, NOW(), NOW())');
}, 'una_configuracion_por_institucion');
rechaza($db, 'borrar una institucion con datos (RESTRICT)',
    fn ($d) => $d->exec('DELETE FROM instituciones WHERE id = 2'), 'foreign key');

/*
 * EL COTEJO (paso a PostgreSQL, 01/10/2026). En MariaDB `utf8mb4_unicode_ci`
 * no distinguia mayusculas ni tildes, y de eso dependian cosas que no se ven:
 * que `Ana` entre como `ana` y que no puedan existir dos departamentos
 * «Música» y «musica». PostgreSQL distingue las dos cosas por defecto; aqui lo
 * hace el cotejo `insensible`, puesto columna a columna. Si una columna nueva
 * se crea sin el, esto no lo ve: lo ve el usuario que no puede entrar.
 */
echo "\n== Cotejo: ni mayusculas ni tildes distinguen ==\n";
rechaza($db, '«música» choca con «Musica» en la misma institucion', fn ($d) => $d->exec(
    "INSERT INTO areas (institucion_id, nombre, created_at, updated_at) VALUES (2,'música',NOW(),NOW())"),
    'areas_nombre_por_institucion');
rechaza($db, '«ANA» choca con el usuario «ana»', fn ($d) => $d->exec(
    "INSERT INTO users (institucion_id, username, password, activo, created_at, updated_at) VALUES (1,'ANA','x',true,NOW(),NOW())"),
    'users_username_unique');
acepta($db, 'la busqueda con LIKE encuentra «Ana Ruiz» escribiendo «ana ruíz»', function ($d) {
    $n = $d->query("SELECT COUNT(*) FROM perfiles WHERE nombre_completo LIKE '%ana ruíz%'")->fetchColumn();
    if ($n != 1) {
        throw new PDOException("se esperaba encontrar 1 perfil, salieron $n");
    }
});

/*
 * `restablecimientos_clave.created_at` llevaba en MariaDB `ON UPDATE
 * CURRENT_TIMESTAMP`: un enlace renovado empieza a contar de nuevo. PostgreSQL
 * no tiene esa clausula y lo hace un trigger.
 */
echo "\n== Enlace de restablecer la clave: renovarlo reinicia su hora ==\n";
acepta($db, 'cambiar el token pone la hora de ahora', function ($d) {
    $d->exec("INSERT INTO restablecimientos_clave (user_id, institucion_id, token, created_at)
              VALUES (1, 1, repeat('a', 64), '2020-01-01 00:00:00')");
    $d->exec("UPDATE restablecimientos_clave SET token = repeat('b', 64) WHERE user_id = 1");
    $hora = $d->query('SELECT created_at FROM restablecimientos_clave WHERE user_id = 1')->fetchColumn();
    if (str_starts_with((string) $hora, '2020')) {
        throw new PDOException("la hora no se movio: $hora");
    }
});

/*
 * ROW LEVEL SECURITY (paso 3, 01/10/2026). El aislamiento entre instituciones
 * lo hace el motor, y se mira como la APLICACION, que es a quien le aplica: el
 * dueño de las tablas —este guion— se lo salta por serlo. A estas alturas hay
 * filas de las dos instituciones (las dos «Musica» de arriba).
 */
echo "\n== RLS: la aplicacion solo ve y escribe su institucion ==\n";
$app = conectarComoAplicacion();
$fijar = fn (?int $id) => $app->prepare('SELECT set_config(\'app.institucion_id\', ?, false)')
    ->execute([$id === null ? '' : (string) $id]);

acepta($db, 'cada tabla con institucion_id, menos users, tiene RLS y su politica', function ($d) {
    $sin = $d->query("SELECT c.relname FROM pg_class c
                       JOIN information_schema.columns k ON k.table_name = c.relname AND k.column_name = 'institucion_id'
                      WHERE k.table_schema = current_schema() AND c.relkind = 'r' AND c.relname <> 'users'
                        AND (NOT c.relrowsecurity OR NOT EXISTS (
                             SELECT 1 FROM pg_policies p WHERE p.tablename = c.relname AND p.policyname = 'por_institucion'))")
        ->fetchAll(PDO::FETCH_COLUMN);
    if ($sin !== []) {
        throw new PDOException('sin RLS: '.implode(', ', $sin));
    }
});
acepta($db, 'el rol de la aplicacion no es superusuario ni se salta RLS', function () use ($app) {
    $r = $app->query('SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user')->fetch(PDO::FETCH_NUM);
    if ($r[0] || $r[1]) {
        throw new PDOException('el rol de la aplicacion se salta RLS');
    }
});
acepta($db, 'sin institucion puesta no ve ninguna fila', function () use ($app, $fijar) {
    $fijar(null);
    $n = $app->query('SELECT COUNT(*) FROM areas')->fetchColumn();
    if ($n != 0) {
        throw new PDOException("vio $n departamentos sin saber de que institucion es");
    }
});
acepta($db, 'desde la 1 no ve las filas de la 2', function () use ($app, $fijar) {
    $fijar(1);
    $ajenas = $app->query('SELECT COUNT(*) FROM areas WHERE institucion_id = 2')->fetchColumn();
    $suyas = $app->query('SELECT COUNT(*) FROM areas WHERE institucion_id = 1')->fetchColumn();
    if ($ajenas != 0 || $suyas == 0) {
        throw new PDOException("ajenas: $ajenas, suyas: $suyas");
    }
});
rechaza($db, 'desde la 2 no escribe una fila de la 1', function () use ($app, $fijar) {
    $fijar(2);
    $app->exec("INSERT INTO areas (institucion_id, nombre, created_at, updated_at) VALUES (1,'Colada',NOW(),NOW())");
}, 'row-level security');
rechaza($db, 'la aplicacion no puede apagar RLS', fn () => $app->exec('ALTER TABLE areas DISABLE ROW LEVEL SECURITY'),
    'must be owner');

echo "\n".str_repeat('-', 60)."\n";
echo "Pasadas: $pasadas   Fallidas: $fallidas\n";
exit($fallidas > 0 ? 1 : 0);
