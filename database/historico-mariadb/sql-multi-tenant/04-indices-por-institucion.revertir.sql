-- ============================================================================
-- 04 · Reversion
-- ============================================================================
--
-- Devuelve a la FK su indice suelto y quita los compuestos.
--
-- Idempotente: se puede correr dos veces seguidas, o de nuevo tras un fallo a
-- medias, y termina en el mismo estado. MariaDB 10.5 o superior. Con el
-- cliente de consola: mysql -h ... nombre_de_la_base < este_archivo.sql
-- ============================================================================

DELIMITER //
BEGIN NOT ATOMIC
  IF EXISTS (SELECT 1 FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'instituciones')
     AND EXISTS (SELECT 1 FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'actividades' AND column_name = 'institucion_id')
     AND EXISTS (SELECT 1 FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'actividades' AND column_name = 'institucion_externa_id') THEN
    ALTER TABLE actividades ADD INDEX IF NOT EXISTS fk_actividades_institucion (institucion_id);
    ALTER TABLE actividades DROP INDEX IF EXISTS actividades_institucion_tipo_nombre;
  END IF;
END //
DELIMITER ;

DELIMITER //
BEGIN NOT ATOMIC
  IF EXISTS (SELECT 1 FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'instituciones')
     AND EXISTS (SELECT 1 FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'areas' AND column_name = 'institucion_id') THEN
    ALTER TABLE areas ADD INDEX IF NOT EXISTS fk_areas_institucion (institucion_id);
  END IF;
END //
DELIMITER ;

DELIMITER //
BEGIN NOT ATOMIC
  IF EXISTS (SELECT 1 FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'instituciones')
     AND EXISTS (SELECT 1 FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'clases' AND column_name = 'institucion_id') THEN
    ALTER TABLE clases ADD INDEX IF NOT EXISTS fk_clases_institucion (institucion_id);
    ALTER TABLE clases DROP INDEX IF EXISTS clases_institucion_periodo_fecha;
  END IF;
END //
DELIMITER ;

DELIMITER //
BEGIN NOT ATOMIC
  IF EXISTS (SELECT 1 FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'instituciones')
     AND EXISTS (SELECT 1 FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'configuracion_institucion' AND column_name = 'institucion_id') THEN
    ALTER TABLE configuracion_institucion ADD INDEX IF NOT EXISTS fk_configuracion_institucion_institucion (institucion_id);
  END IF;
END //
DELIMITER ;

DELIMITER //
BEGIN NOT ATOMIC
  IF EXISTS (SELECT 1 FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'instituciones')
     AND EXISTS (SELECT 1 FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'datos_estudiante' AND column_name = 'institucion_id') THEN
    ALTER TABLE datos_estudiante ADD INDEX IF NOT EXISTS fk_datos_estudiante_institucion (institucion_id);
  END IF;
END //
DELIMITER ;

DELIMITER //
BEGIN NOT ATOMIC
  IF EXISTS (SELECT 1 FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'instituciones')
     AND EXISTS (SELECT 1 FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'documentos_requeridos' AND column_name = 'institucion_id') THEN
    ALTER TABLE documentos_requeridos ADD INDEX IF NOT EXISTS fk_documentos_requeridos_institucion (institucion_id);
    ALTER TABLE documentos_requeridos DROP INDEX IF EXISTS documentos_requeridos_institucion_orden;
  END IF;
END //
DELIMITER ;

DELIMITER //
BEGIN NOT ATOMIC
  IF EXISTS (SELECT 1 FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'instituciones')
     AND EXISTS (SELECT 1 FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'encuestas_satisfaccion' AND column_name = 'institucion_id') THEN
    ALTER TABLE encuestas_satisfaccion ADD INDEX IF NOT EXISTS fk_encuestas_satisfaccion_institucion (institucion_id);
    ALTER TABLE encuestas_satisfaccion DROP INDEX IF EXISTS encuestas_satisfaccion_institucion_periodo;
  END IF;
END //
DELIMITER ;

DELIMITER //
BEGIN NOT ATOMIC
  IF EXISTS (SELECT 1 FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'instituciones')
     AND EXISTS (SELECT 1 FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'grupos' AND column_name = 'institucion_id') THEN
    ALTER TABLE grupos ADD INDEX IF NOT EXISTS fk_grupos_institucion (institucion_id);
    ALTER TABLE grupos DROP INDEX IF EXISTS grupos_institucion_nombre;
  END IF;
END //
DELIMITER ;

DELIMITER //
BEGIN NOT ATOMIC
  IF EXISTS (SELECT 1 FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'instituciones')
     AND EXISTS (SELECT 1 FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'matriculas' AND column_name = 'institucion_id') THEN
    ALTER TABLE matriculas ADD INDEX IF NOT EXISTS fk_matriculas_institucion (institucion_id);
    ALTER TABLE matriculas DROP INDEX IF EXISTS matriculas_institucion_periodo_estado;
  END IF;
END //
DELIMITER ;

DELIMITER //
BEGIN NOT ATOMIC
  IF EXISTS (SELECT 1 FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'instituciones')
     AND EXISTS (SELECT 1 FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'perfiles' AND column_name = 'institucion_id') THEN
    ALTER TABLE perfiles ADD INDEX IF NOT EXISTS fk_perfiles_institucion (institucion_id);
    ALTER TABLE perfiles DROP INDEX IF EXISTS perfiles_institucion_rol;
    ALTER TABLE perfiles DROP INDEX IF EXISTS perfiles_institucion_nombre;
  END IF;
END //
DELIMITER ;

DELIMITER //
BEGIN NOT ATOMIC
  IF EXISTS (SELECT 1 FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'instituciones')
     AND EXISTS (SELECT 1 FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'periodos' AND column_name = 'institucion_id') THEN
    ALTER TABLE periodos ADD INDEX IF NOT EXISTS fk_periodos_institucion (institucion_id);
  END IF;
END //
DELIMITER ;

DELIMITER //
BEGIN NOT ATOMIC
  IF EXISTS (SELECT 1 FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'instituciones')
     AND EXISTS (SELECT 1 FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'promotorias' AND column_name = 'institucion_id') THEN
    ALTER TABLE promotorias ADD INDEX IF NOT EXISTS fk_promotorias_institucion (institucion_id);
    ALTER TABLE promotorias DROP INDEX IF EXISTS promotorias_institucion_nombre;
  END IF;
END //
DELIMITER ;

