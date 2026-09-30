-- ============================================================================
-- 02 · Reversion
-- ============================================================================
--
-- Quita la columna y su FK de las 28 tablas. Corre DESPUES de revertir el 03
-- y el 04.
--
-- Idempotente: se puede correr dos veces seguidas, o de nuevo tras un fallo a
-- medias, y termina en el mismo estado. MariaDB 10.5 o superior. Con el
-- cliente de consola: mysql -h ... nombre_de_la_base < este_archivo.sql
-- ============================================================================

-- Revertir con mas de una institucion MEZCLARIA sus datos (y chocaria con los
-- unicos viejos). Las FK impiden borrar una institucion que tenga filas, asi
-- que basta con mirar esta tabla.
DELIMITER //
BEGIN NOT ATOMIC
  IF EXISTS (SELECT 1 FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'instituciones') THEN
    IF EXISTS (SELECT 1 FROM instituciones WHERE id <> 1) THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Hay instituciones ademas de la 1: borralas (con sus datos) antes de revertir.';
    END IF;
  END IF;
END //
DELIMITER ;

DELIMITER //
BEGIN NOT ATOMIC
  IF EXISTS (SELECT 1 FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'instituciones')
     AND EXISTS (SELECT 1 FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'actividades' AND column_name = 'institucion_id')
     AND EXISTS (SELECT 1 FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'actividades' AND column_name = 'institucion_externa_id') THEN
    ALTER TABLE actividades DROP FOREIGN KEY IF EXISTS fk_actividades_institucion;
    ALTER TABLE actividades DROP INDEX IF EXISTS fk_actividades_institucion;
    ALTER TABLE actividades DROP COLUMN IF EXISTS institucion_id;
  END IF;
END //
DELIMITER ;

DELIMITER //
BEGIN NOT ATOMIC
  IF EXISTS (SELECT 1 FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'instituciones')
     AND EXISTS (SELECT 1 FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'acudientes' AND column_name = 'institucion_id') THEN
    ALTER TABLE acudientes DROP FOREIGN KEY IF EXISTS fk_acudientes_institucion;
    ALTER TABLE acudientes DROP INDEX IF EXISTS fk_acudientes_institucion;
    ALTER TABLE acudientes DROP COLUMN IF EXISTS institucion_id;
  END IF;
END //
DELIMITER ;

DELIMITER //
BEGIN NOT ATOMIC
  IF EXISTS (SELECT 1 FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'instituciones')
     AND EXISTS (SELECT 1 FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'areas' AND column_name = 'institucion_id') THEN
    ALTER TABLE areas DROP FOREIGN KEY IF EXISTS fk_areas_institucion;
    ALTER TABLE areas DROP INDEX IF EXISTS fk_areas_institucion;
    ALTER TABLE areas DROP COLUMN IF EXISTS institucion_id;
  END IF;
END //
DELIMITER ;

DELIMITER //
BEGIN NOT ATOMIC
  IF EXISTS (SELECT 1 FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'instituciones')
     AND EXISTS (SELECT 1 FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'areas_dirigidas' AND column_name = 'institucion_id') THEN
    ALTER TABLE areas_dirigidas DROP FOREIGN KEY IF EXISTS fk_areas_dirigidas_institucion;
    ALTER TABLE areas_dirigidas DROP INDEX IF EXISTS fk_areas_dirigidas_institucion;
    ALTER TABLE areas_dirigidas DROP COLUMN IF EXISTS institucion_id;
  END IF;
END //
DELIMITER ;

DELIMITER //
BEGIN NOT ATOMIC
  IF EXISTS (SELECT 1 FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'instituciones')
     AND EXISTS (SELECT 1 FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'asignaciones_grupo' AND column_name = 'institucion_id') THEN
    ALTER TABLE asignaciones_grupo DROP FOREIGN KEY IF EXISTS fk_asignaciones_grupo_institucion;
    ALTER TABLE asignaciones_grupo DROP INDEX IF EXISTS fk_asignaciones_grupo_institucion;
    ALTER TABLE asignaciones_grupo DROP COLUMN IF EXISTS institucion_id;
  END IF;
END //
DELIMITER ;

DELIMITER //
BEGIN NOT ATOMIC
  IF EXISTS (SELECT 1 FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'instituciones')
     AND EXISTS (SELECT 1 FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'asistencias' AND column_name = 'institucion_id') THEN
    ALTER TABLE asistencias DROP FOREIGN KEY IF EXISTS fk_asistencias_institucion;
    ALTER TABLE asistencias DROP INDEX IF EXISTS fk_asistencias_institucion;
    ALTER TABLE asistencias DROP COLUMN IF EXISTS institucion_id;
  END IF;
END //
DELIMITER ;

DELIMITER //
BEGIN NOT ATOMIC
  IF EXISTS (SELECT 1 FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'instituciones')
     AND EXISTS (SELECT 1 FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'asistencias_actividad' AND column_name = 'institucion_id') THEN
    ALTER TABLE asistencias_actividad DROP FOREIGN KEY IF EXISTS fk_asistencias_actividad_institucion;
    ALTER TABLE asistencias_actividad DROP INDEX IF EXISTS fk_asistencias_actividad_institucion;
    ALTER TABLE asistencias_actividad DROP COLUMN IF EXISTS institucion_id;
  END IF;
END //
DELIMITER ;

DELIMITER //
BEGIN NOT ATOMIC
  IF EXISTS (SELECT 1 FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'instituciones')
     AND EXISTS (SELECT 1 FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'clases' AND column_name = 'institucion_id') THEN
    ALTER TABLE clases DROP FOREIGN KEY IF EXISTS fk_clases_institucion;
    ALTER TABLE clases DROP INDEX IF EXISTS fk_clases_institucion;
    ALTER TABLE clases DROP COLUMN IF EXISTS institucion_id;
  END IF;
END //
DELIMITER ;

DELIMITER //
BEGIN NOT ATOMIC
  IF EXISTS (SELECT 1 FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'instituciones')
     AND EXISTS (SELECT 1 FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'configuracion_institucion' AND column_name = 'institucion_id') THEN
    ALTER TABLE configuracion_institucion DROP FOREIGN KEY IF EXISTS fk_configuracion_institucion_institucion;
    ALTER TABLE configuracion_institucion DROP INDEX IF EXISTS fk_configuracion_institucion_institucion;
    ALTER TABLE configuracion_institucion DROP COLUMN IF EXISTS institucion_id;
  END IF;
END //
DELIMITER ;

DELIMITER //
BEGIN NOT ATOMIC
  IF EXISTS (SELECT 1 FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'instituciones')
     AND EXISTS (SELECT 1 FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'confirmaciones_clase' AND column_name = 'institucion_id') THEN
    ALTER TABLE confirmaciones_clase DROP FOREIGN KEY IF EXISTS fk_confirmaciones_clase_institucion;
    ALTER TABLE confirmaciones_clase DROP INDEX IF EXISTS fk_confirmaciones_clase_institucion;
    ALTER TABLE confirmaciones_clase DROP COLUMN IF EXISTS institucion_id;
  END IF;
END //
DELIMITER ;

DELIMITER //
BEGIN NOT ATOMIC
  IF EXISTS (SELECT 1 FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'instituciones')
     AND EXISTS (SELECT 1 FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'cupos_promotoria' AND column_name = 'institucion_id') THEN
    ALTER TABLE cupos_promotoria DROP FOREIGN KEY IF EXISTS fk_cupos_promotoria_institucion;
    ALTER TABLE cupos_promotoria DROP INDEX IF EXISTS fk_cupos_promotoria_institucion;
    ALTER TABLE cupos_promotoria DROP COLUMN IF EXISTS institucion_id;
  END IF;
END //
DELIMITER ;

DELIMITER //
BEGIN NOT ATOMIC
  IF EXISTS (SELECT 1 FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'instituciones')
     AND EXISTS (SELECT 1 FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'datos_estudiante' AND column_name = 'institucion_id') THEN
    ALTER TABLE datos_estudiante DROP FOREIGN KEY IF EXISTS fk_datos_estudiante_institucion;
    ALTER TABLE datos_estudiante DROP INDEX IF EXISTS fk_datos_estudiante_institucion;
    ALTER TABLE datos_estudiante DROP COLUMN IF EXISTS institucion_id;
  END IF;
END //
DELIMITER ;

DELIMITER //
BEGIN NOT ATOMIC
  IF EXISTS (SELECT 1 FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'instituciones')
     AND EXISTS (SELECT 1 FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'documentos_estudiante' AND column_name = 'institucion_id') THEN
    ALTER TABLE documentos_estudiante DROP FOREIGN KEY IF EXISTS fk_documentos_estudiante_institucion;
    ALTER TABLE documentos_estudiante DROP INDEX IF EXISTS fk_documentos_estudiante_institucion;
    ALTER TABLE documentos_estudiante DROP COLUMN IF EXISTS institucion_id;
  END IF;
END //
DELIMITER ;

DELIMITER //
BEGIN NOT ATOMIC
  IF EXISTS (SELECT 1 FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'instituciones')
     AND EXISTS (SELECT 1 FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'documentos_requeridos' AND column_name = 'institucion_id') THEN
    ALTER TABLE documentos_requeridos DROP FOREIGN KEY IF EXISTS fk_documentos_requeridos_institucion;
    ALTER TABLE documentos_requeridos DROP INDEX IF EXISTS fk_documentos_requeridos_institucion;
    ALTER TABLE documentos_requeridos DROP COLUMN IF EXISTS institucion_id;
  END IF;
END //
DELIMITER ;

DELIMITER //
BEGIN NOT ATOMIC
  IF EXISTS (SELECT 1 FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'instituciones')
     AND EXISTS (SELECT 1 FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'encuestas_demograficas' AND column_name = 'institucion_id') THEN
    ALTER TABLE encuestas_demograficas DROP FOREIGN KEY IF EXISTS fk_encuestas_demograficas_institucion;
    ALTER TABLE encuestas_demograficas DROP INDEX IF EXISTS fk_encuestas_demograficas_institucion;
    ALTER TABLE encuestas_demograficas DROP COLUMN IF EXISTS institucion_id;
  END IF;
END //
DELIMITER ;

DELIMITER //
BEGIN NOT ATOMIC
  IF EXISTS (SELECT 1 FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'instituciones')
     AND EXISTS (SELECT 1 FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'encuestas_satisfaccion' AND column_name = 'institucion_id') THEN
    ALTER TABLE encuestas_satisfaccion DROP FOREIGN KEY IF EXISTS fk_encuestas_satisfaccion_institucion;
    ALTER TABLE encuestas_satisfaccion DROP INDEX IF EXISTS fk_encuestas_satisfaccion_institucion;
    ALTER TABLE encuestas_satisfaccion DROP COLUMN IF EXISTS institucion_id;
  END IF;
END //
DELIMITER ;

DELIMITER //
BEGIN NOT ATOMIC
  IF EXISTS (SELECT 1 FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'instituciones')
     AND EXISTS (SELECT 1 FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'grupos' AND column_name = 'institucion_id') THEN
    ALTER TABLE grupos DROP FOREIGN KEY IF EXISTS fk_grupos_institucion;
    ALTER TABLE grupos DROP INDEX IF EXISTS fk_grupos_institucion;
    ALTER TABLE grupos DROP COLUMN IF EXISTS institucion_id;
  END IF;
END //
DELIMITER ;

DELIMITER //
BEGIN NOT ATOMIC
  IF EXISTS (SELECT 1 FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'instituciones')
     AND EXISTS (SELECT 1 FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'inscritos_actividad' AND column_name = 'institucion_id') THEN
    ALTER TABLE inscritos_actividad DROP FOREIGN KEY IF EXISTS fk_inscritos_actividad_institucion;
    ALTER TABLE inscritos_actividad DROP INDEX IF EXISTS fk_inscritos_actividad_institucion;
    ALTER TABLE inscritos_actividad DROP COLUMN IF EXISTS institucion_id;
  END IF;
END //
DELIMITER ;

DELIMITER //
BEGIN NOT ATOMIC
  IF EXISTS (SELECT 1 FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'instituciones')
     AND EXISTS (SELECT 1 FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'instituciones_externas' AND column_name = 'institucion_id') THEN
    ALTER TABLE instituciones_externas DROP FOREIGN KEY IF EXISTS fk_instituciones_externas_institucion;
    ALTER TABLE instituciones_externas DROP INDEX IF EXISTS fk_instituciones_externas_institucion;
    ALTER TABLE instituciones_externas DROP COLUMN IF EXISTS institucion_id;
  END IF;
END //
DELIMITER ;

DELIMITER //
BEGIN NOT ATOMIC
  IF EXISTS (SELECT 1 FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'instituciones')
     AND EXISTS (SELECT 1 FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'matriculas' AND column_name = 'institucion_id') THEN
    ALTER TABLE matriculas DROP FOREIGN KEY IF EXISTS fk_matriculas_institucion;
    ALTER TABLE matriculas DROP INDEX IF EXISTS fk_matriculas_institucion;
    ALTER TABLE matriculas DROP COLUMN IF EXISTS institucion_id;
  END IF;
END //
DELIMITER ;

DELIMITER //
BEGIN NOT ATOMIC
  IF EXISTS (SELECT 1 FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'instituciones')
     AND EXISTS (SELECT 1 FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'omisiones_archivadas' AND column_name = 'institucion_id') THEN
    ALTER TABLE omisiones_archivadas DROP FOREIGN KEY IF EXISTS fk_omisiones_archivadas_institucion;
    ALTER TABLE omisiones_archivadas DROP INDEX IF EXISTS fk_omisiones_archivadas_institucion;
    ALTER TABLE omisiones_archivadas DROP COLUMN IF EXISTS institucion_id;
  END IF;
END //
DELIMITER ;

DELIMITER //
BEGIN NOT ATOMIC
  IF EXISTS (SELECT 1 FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'instituciones')
     AND EXISTS (SELECT 1 FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'perfiles' AND column_name = 'institucion_id') THEN
    ALTER TABLE perfiles DROP FOREIGN KEY IF EXISTS fk_perfiles_institucion;
    ALTER TABLE perfiles DROP INDEX IF EXISTS fk_perfiles_institucion;
    ALTER TABLE perfiles DROP COLUMN IF EXISTS institucion_id;
  END IF;
END //
DELIMITER ;

DELIMITER //
BEGIN NOT ATOMIC
  IF EXISTS (SELECT 1 FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'instituciones')
     AND EXISTS (SELECT 1 FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'periodos' AND column_name = 'institucion_id') THEN
    ALTER TABLE periodos DROP FOREIGN KEY IF EXISTS fk_periodos_institucion;
    ALTER TABLE periodos DROP INDEX IF EXISTS fk_periodos_institucion;
    ALTER TABLE periodos DROP COLUMN IF EXISTS institucion_id;
  END IF;
END //
DELIMITER ;

DELIMITER //
BEGIN NOT ATOMIC
  IF EXISTS (SELECT 1 FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'instituciones')
     AND EXISTS (SELECT 1 FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'promotorias' AND column_name = 'institucion_id') THEN
    ALTER TABLE promotorias DROP FOREIGN KEY IF EXISTS fk_promotorias_institucion;
    ALTER TABLE promotorias DROP INDEX IF EXISTS fk_promotorias_institucion;
    ALTER TABLE promotorias DROP COLUMN IF EXISTS institucion_id;
  END IF;
END //
DELIMITER ;

DELIMITER //
BEGIN NOT ATOMIC
  IF EXISTS (SELECT 1 FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'instituciones')
     AND EXISTS (SELECT 1 FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'restablecimientos_clave' AND column_name = 'institucion_id') THEN
    ALTER TABLE restablecimientos_clave DROP FOREIGN KEY IF EXISTS fk_restablecimientos_clave_institucion;
    ALTER TABLE restablecimientos_clave DROP INDEX IF EXISTS fk_restablecimientos_clave_institucion;
    ALTER TABLE restablecimientos_clave DROP COLUMN IF EXISTS institucion_id;
  END IF;
END //
DELIMITER ;

DELIMITER //
BEGIN NOT ATOMIC
  IF EXISTS (SELECT 1 FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'instituciones')
     AND EXISTS (SELECT 1 FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'sesiones_actividad' AND column_name = 'institucion_id') THEN
    ALTER TABLE sesiones_actividad DROP FOREIGN KEY IF EXISTS fk_sesiones_actividad_institucion;
    ALTER TABLE sesiones_actividad DROP INDEX IF EXISTS fk_sesiones_actividad_institucion;
    ALTER TABLE sesiones_actividad DROP COLUMN IF EXISTS institucion_id;
  END IF;
END //
DELIMITER ;

DELIMITER //
BEGIN NOT ATOMIC
  IF EXISTS (SELECT 1 FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'instituciones')
     AND EXISTS (SELECT 1 FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'sesiones_grupo' AND column_name = 'institucion_id') THEN
    ALTER TABLE sesiones_grupo DROP FOREIGN KEY IF EXISTS fk_sesiones_grupo_institucion;
    ALTER TABLE sesiones_grupo DROP INDEX IF EXISTS fk_sesiones_grupo_institucion;
    ALTER TABLE sesiones_grupo DROP COLUMN IF EXISTS institucion_id;
  END IF;
END //
DELIMITER ;

DELIMITER //
BEGIN NOT ATOMIC
  IF EXISTS (SELECT 1 FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'instituciones')
     AND EXISTS (SELECT 1 FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'institucion_id') THEN
    ALTER TABLE users DROP FOREIGN KEY IF EXISTS fk_users_institucion;
    ALTER TABLE users DROP INDEX IF EXISTS fk_users_institucion;
    ALTER TABLE users DROP COLUMN IF EXISTS institucion_id;
  END IF;
END //
DELIMITER ;

