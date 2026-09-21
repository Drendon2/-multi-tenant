<?php

/**
 * Verificacion del esquema contra MariaDB.
 *
 * No comprueba que las migraciones CORRAN —eso ya lo dice artisan—, sino que
 * las garantias que el esquema promete se cumplan de verdad: sobre todo las
 * que en el original daba PostgreSQL con indices parciales y un trigger, y que
 * aqui estan emuladas.
 *
 * Se ejecuta con:  php database/verificacion_esquema.php
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

$db->exec('SET FOREIGN_KEY_CHECKS = 0');
// La lista NO va escrita a mano: se pregunta al motor.
//
// Escrita a mano se quedo en agosto de 2025, y dejo fuera `sesiones_grupo`
// —que nacio despues— y las cuatro tablas de actividades. Como el TRUNCATE va
// con las claves foraneas apagadas, el resultado no era «quedan datos de mas»
// sino filas HUERFANAS: sesiones apuntando a grupos que ya no existen. Un
// escenario de prueba sucio es peor que ninguno, porque parece limpio.
//
// Se excluyen las de Laravel: `migrations` diria que no hay esquema, y las de
// sesion, cache y colas no son datos del dominio.
$deLaravel = ['migrations', 'sessions', 'cache', 'cache_locks', 'jobs',
    'job_batches', 'failed_jobs', 'password_reset_tokens'];

$tablas = array_diff(
    array_map(fn (array $f) => array_values($f)[0], $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_ASSOC)),
    $deLaravel
);

foreach ($tablas as $t) {
    $db->exec("TRUNCATE TABLE $t");
}
$db->exec('SET FOREIGN_KEY_CHECKS = 1');

$db->exec("INSERT INTO users (id, username, password, activo, created_at, updated_at)
           VALUES (1,'ana','x',1,NOW(),NOW()), (2,'beto','x',1,NOW(),NOW())");
$db->exec("INSERT INTO perfiles (id, user_id, rol, nombre_completo, fecha_nacimiento, telefono, created_at, updated_at)
           VALUES (1,1,'estudiante','Ana Ruiz','2000-05-01','3000000000',NOW(),NOW()),
                  (2,2,'profesor','Beto Diaz','1985-03-12','3000000001',NOW(),NOW())");
$db->exec("INSERT INTO areas (id, nombre, created_at, updated_at) VALUES (1,'Musica',NOW(),NOW())");
$db->exec("INSERT INTO periodos (id, nombre, fecha_inicio, fecha_fin, activo, matriculas_abiertas, created_at, updated_at)
           VALUES (1,'2026-1','2026-01-15','2026-06-30',1,1,NOW(),NOW())");
$db->exec("INSERT INTO promotorias (id, nombre, area_id, profesor_id, created_at, updated_at)
           VALUES (1,'Violin',1,2,NOW(),NOW()), (2,'Guitarra',1,2,NOW(),NOW()), (3,'Piano',1,2,NOW(),NOW()),
                  (4,'Flauta',1,2,NOW(),NOW()), (5,'Canto',1,2,NOW(),NOW())");

$nuevaMatricula = fn ($est, $promo, $ranura, $estado = 'pendiente') => "INSERT INTO matriculas (estudiante_id, promotoria_id, periodo_id, fecha, estado, ranura, created_at, updated_at)
     VALUES ($est, $promo, 1, NOW(), '$estado', $ranura, NOW(), NOW())";

echo "\n== Periodo: solo uno en curso (indice unico parcial emulado) ==\n";
acepta($db, 'un segundo periodo INACTIVO entra', fn ($d) => $d->exec(
    "INSERT INTO periodos (nombre, fecha_inicio, fecha_fin, activo, matriculas_abiertas, created_at, updated_at)
     VALUES ('2026-2','2026-07-01','2026-12-15',0,0,NOW(),NOW())"));
rechaza($db, 'un segundo periodo ACTIVO se rechaza', fn ($d) => $d->exec(
    "INSERT INTO periodos (nombre, fecha_inicio, fecha_fin, activo, matriculas_abiertas, created_at, updated_at)
     VALUES ('2027-1','2027-01-15','2027-06-30',1,0,NOW(),NOW())"), 'un_solo_periodo_activo');
rechaza($db, 'activar por UPDATE un segundo periodo se rechaza', fn ($d) => $d->exec(
    "UPDATE periodos SET activo = 1 WHERE nombre = '2026-2'"), 'un_solo_periodo_activo');

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
$db->exec('INSERT INTO cupos_promotoria (promotoria_id, periodo_id, cupo_maximo, created_at, updated_at)
           VALUES (5, 1, 2, NOW(), NOW())');
acepta($db, 'cupo 2: entra la primera', fn ($d) => $d->exec($nuevaMatricula(1, 5, 1)));
$db->exec("INSERT INTO users (id, username, password, activo, created_at, updated_at) VALUES (3,'caro','x',1,NOW(),NOW())");
$db->exec("INSERT INTO perfiles (id, user_id, rol, nombre_completo, fecha_nacimiento, telefono, created_at, updated_at)
           VALUES (3,3,'estudiante','Caro Paz','2001-02-02','3000000002',NOW(),NOW())");
acepta($db, 'cupo 2: entra la segunda', fn ($d) => $d->exec($nuevaMatricula(3, 5, 1)));
$db->exec("INSERT INTO users (id, username, password, activo, created_at, updated_at) VALUES (4,'dani','x',1,NOW(),NOW())");
$db->exec("INSERT INTO perfiles (id, user_id, rol, nombre_completo, fecha_nacimiento, telefono, created_at, updated_at)
           VALUES (4,4,'estudiante','Dani Gil','2002-03-03','3000000003',NOW(),NOW())");
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
$db->exec("INSERT INTO users (id, username, password, activo, created_at, updated_at) VALUES (5,'evi','x',1,NOW(),NOW())");
$db->exec("INSERT INTO perfiles (id, user_id, rol, nombre_completo, fecha_nacimiento, telefono, created_at, updated_at)
           VALUES (5,5,'estudiante','Evi Mora','2003-04-04','3000000004',NOW(),NOW())");
$db->exec('INSERT INTO cupos_promotoria (promotoria_id, periodo_id, cupo_maximo, created_at, updated_at)
           VALUES (3, 1, 1, NOW(), NOW())');
acepta($db, 'Piano cupo 1: Dani toma el unico sitio', fn ($d) => $d->exec($nuevaMatricula(4, 3, 1)));
$db->exec("UPDATE matriculas SET estado='retirada' WHERE estudiante_id=4 AND promotoria_id=3");
acepta($db, 'Dani se retira y Evi ocupa el sitio libre', fn ($d) => $d->exec($nuevaMatricula(5, 3, 1)));
rechaza($db, 'Dani ya no puede volver: el sitio esta tomado',
    fn ($d) => $d->exec("UPDATE matriculas SET estado='activa' WHERE estudiante_id=4 AND promotoria_id=3"),
    'no tiene cupos disponibles');

echo "\n== Sin cupo definido no hay tope ==\n";
for ($i = 6; $i <= 10; $i++) {
    $db->exec("INSERT INTO users (id, username, password, activo, created_at, updated_at) VALUES ($i,'u$i','x',1,NOW(),NOW())");
    $db->exec("INSERT INTO perfiles (id, user_id, rol, nombre_completo, fecha_nacimiento, telefono, created_at, updated_at)
               VALUES ($i,$i,'estudiante','Persona $i','2000-01-01','300000000$i',NOW(),NOW())");
}
acepta($db, 'Violin no tiene fila de cupo: admite a los cinco', function ($d) use ($nuevaMatricula) {
    for ($i = 6; $i <= 10; $i++) {
        $d->exec($nuevaMatricula($i, 1, 1));
    }
});

echo "\n== Otros CHECK del esquema ==\n";
rechaza($db, 'rol inventado en perfiles', fn ($d) => $d->exec(
    "INSERT INTO perfiles (user_id, rol, nombre_completo, fecha_nacimiento, telefono, created_at, updated_at)
     VALUES (1,'rector','X','2000-01-01','300',NOW(),NOW())"), 'rol_valido');
rechaza($db, 'limite de promotorias fuera de 1..6', fn ($d) => $d->exec(
    'INSERT INTO configuracion_institucion (limite_promotorias_por_periodo, created_at, updated_at)
     VALUES (9, NOW(), NOW())'), 'limite_promotorias_valido');
rechaza($db, 'color de acento que no es hex', fn ($d) => $d->exec(
    "INSERT INTO configuracion_institucion (color_acento, created_at, updated_at)
     VALUES ('verde', NOW(), NOW())"), 'color_acento_hex');
rechaza($db, 'estrato 8 en la encuesta', fn ($d) => $d->exec(
    "INSERT INTO encuestas_demograficas (perfil_id, genero, barrio, estrato, nivel_educativo, ocupacion, created_at, updated_at)
     VALUES (1,'f','Centro',8,'tecnico','estudiante',NOW(),NOW())"), 'estrato_valido');
rechaza($db, 'nivel de grupo inventado', fn ($d) => $d->exec(
    "INSERT INTO grupos (promotoria_id, nivel, nombre, salon, cupo_maximo, created_at, updated_at)
     VALUES (1,'experto','Grupo A','A1',10,NOW(),NOW())"), 'nivel_valido');
rechaza($db, 'estado de asistencia inventado', function ($d) {
    $d->exec("INSERT INTO grupos (id, promotoria_id, nivel, nombre, salon, cupo_maximo, created_at, updated_at)
              VALUES (1,1,'basico','Grupo A','A1',10,NOW(),NOW())");
    $d->exec('INSERT INTO clases (id, grupo_id, periodo_id, fecha_hora, registrada_por_id, confirmaciones_requeridas, created_at, updated_at)
              VALUES (1,1,1,NOW(),2,3,NOW(),NOW())');
    $mid = $d->query('SELECT id FROM matriculas LIMIT 1')->fetchColumn();
    $d->exec("INSERT INTO asistencias (clase_id, matricula_id, estado, fecha_registro, created_at, updated_at)
              VALUES (1,$mid,'tarde',NOW(),NOW(),NOW())");
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
}, 'Duplicate');
acepta($db, 'varios perfiles SIN codigo conviven (nulo se repite)', function ($d) {
    $d->exec('UPDATE perfiles SET codigo_qr = NULL WHERE id IN (1, 2)');
    $n = $d->query('SELECT COUNT(*) FROM perfiles WHERE codigo_qr IS NULL')->fetchColumn();
    if ($n < 2) {
        throw new PDOException("se esperaban al menos dos perfiles sin codigo, hay $n");
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

echo "\n".str_repeat('-', 60)."\n";
echo "Pasadas: $pasadas   Fallidas: $fallidas\n";
exit($fallidas > 0 ? 1 : 0);
