<?php

/**
 * Prueba de concurrencia del cupo: dos peticiones peleando por el ULTIMO sitio.
 *
 * Es la unica razon por la que el trigger existe. La validacion del modelo no
 * basta: entre que comprueba el cupo y escribe la fila hay una ventana, y dos
 * matriculas simultaneas pasan las dos por ella. Sin el `FOR UPDATE` sobre la
 * fila de `cupos_promotoria`, esta prueba termina con dos matriculas en una
 * promotoria de cupo 1.
 *
 * VACIA la base. Se ejecuta contra una desechable:
 *   DB_DATABASE=test_matriculas_mt php database/verificacion_concurrencia.php --borrar-datos
 *
 * Estuvo roto del paso 1 de la multi-institucion (01/10/2026) hasta el paso a
 * PostgreSQL: sus INSERT no llevaban `institucion_id` y la base los rechazaba.
 * No lo corre el CI, asi que nadie lo vio.
 */
// La conexion sale del .env: ver `conexion_verificacion.php`.
$cerrojo = __DIR__.'/.cerrojo_tomado';

$db = require __DIR__.'/conexion_verificacion.php';

// Escenario limpio: Violin con cupo 1 y nadie inscrito.
confirmarBorradoDeDatos($db);

vaciarTablasDeDatos($db);

$db->exec("INSERT INTO instituciones (id, nombre) VALUES (1,'Casa A')");
$db->exec("INSERT INTO users (id, institucion_id, username, password, activo, created_at, updated_at)
           VALUES (1,1,'ana','x',true,NOW(),NOW()), (2,1,'beto','x',true,NOW(),NOW())");
$db->exec("INSERT INTO perfiles (id, institucion_id, user_id, rol, nombre_completo, fecha_nacimiento, telefono, created_at, updated_at)
           VALUES (1,1,1,'estudiante','Ana Ruiz','2000-05-01','3000000000',NOW(),NOW()),
                  (2,1,2,'estudiante','Beto Diaz','2000-06-01','3000000001',NOW(),NOW())");
$db->exec("INSERT INTO areas (id, institucion_id, nombre, created_at, updated_at) VALUES (1,1,'Musica',NOW(),NOW())");
$db->exec("INSERT INTO periodos (id, institucion_id, nombre, fecha_inicio, fecha_fin, activo, matriculas_abiertas, created_at, updated_at)
           VALUES (1,1,'2026-1','2026-01-15','2026-06-30',true,true,NOW(),NOW())");
$db->exec("INSERT INTO promotorias (id, institucion_id, nombre, area_id, created_at, updated_at)
           VALUES (1,1,'Violin',1,NOW(),NOW())");
$db->exec('INSERT INTO cupos_promotoria (institucion_id, promotoria_id, periodo_id, cupo_maximo, created_at, updated_at)
           VALUES (1,1,1,1,NOW(),NOW())');
ajustarSecuencias($db);
@unlink($cerrojo);

echo "Escenario: Violin, cupo 1, nadie inscrito. Ana y Beto lo piden a la vez.\n\n";

// A arranca en su propio proceso y retiene el cerrojo 3 segundos.
$cmd = 'start /B "" '.escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/verificacion_concurrencia_a.php');
pclose(popen($cmd, 'r'));

$t0 = microtime(true);
while (! file_exists($cerrojo) && microtime(true) - $t0 < 15) {
    usleep(50000);
}
if (! file_exists($cerrojo)) {
    exit("La transaccion A nunca llego a tomar el cerrojo.\n");
}
echo "A (Ana): matricula escrita, transaccion ABIERTA, cerrojo tomado.\n";

$b = require __DIR__.'/conexion_verificacion.php';
// Lo que espera B por el cerrojo antes de rendirse.
$b->exec("SET lock_timeout = '20s'");
$b->beginTransaction();

echo "B (Beto): pide el mismo ultimo sitio...\n";
$inicio = microtime(true);
$sobreventa = false;
try {
    $b->exec("INSERT INTO matriculas (institucion_id, estudiante_id, promotoria_id, periodo_id, fecha, estado, ranura, created_at, updated_at)
              VALUES (1, 2, 1, 1, NOW(), 'pendiente', 1, NOW(), NOW())");
    $b->commit();
    $sobreventa = true;
    printf("B: PASO tras %.2fs -> el cerrojo no sirvio.\n", microtime(true) - $inicio);
} catch (PDOException $e) {
    printf("B: rechazado tras esperar %.2fs (estuvo bloqueado en el FOR UPDATE).\n", microtime(true) - $inicio);
    echo "B: {$e->getMessage()}\n";
    $b->rollBack();
}

$total = $db->query("SELECT COUNT(*) FROM matriculas
                     WHERE promotoria_id=1 AND periodo_id=1 AND estado <> 'retirada'")->fetchColumn();

echo "\nMatriculas finales en una promotoria de cupo 1: {$total}\n";
@unlink($cerrojo);

if ($sobreventa || $total != 1) {
    echo "FALLO: hubo sobreventa.\n";
    exit(1);
}
echo "CORRECTO: la carrera quedo serializada, sin sobreventa.\n";
