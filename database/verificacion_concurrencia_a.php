<?php

/**
 * Mitad A de la prueba de concurrencia (ver verificacion_concurrencia.php).
 *
 * Toma el ultimo cupo y RETIENE el cerrojo unos segundos antes de confirmar,
 * para que B se encuentre de verdad con la transaccion abierta. Vive en su
 * propio proceso porque una transaccion no se puede retener desde fuera.
 */
// La conexion sale del .env: ver `conexion_verificacion.php`.
$db = require __DIR__.'/conexion_verificacion.php';

$db->beginTransaction();
$db->exec("INSERT INTO matriculas (institucion_id, estudiante_id, promotoria_id, periodo_id, fecha, estado, ranura, created_at, updated_at)
           VALUES (1, 1, 1, 1, NOW(), 'pendiente', 1, NOW(), NOW())");

file_put_contents(__DIR__.'/.cerrojo_tomado', '1');
sleep(3);

$db->commit();
