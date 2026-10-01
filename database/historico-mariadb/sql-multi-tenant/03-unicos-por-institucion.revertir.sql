-- ============================================================================
-- 03 · Reversion
-- ============================================================================
--
-- Devuelve los unicos globales. Fallaria con datos repetidos entre
-- instituciones; la guardia lo impide antes.
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
              WHERE table_schema = DATABASE() AND table_name = 'areas' AND column_name = 'institucion_id') THEN
    ALTER TABLE areas ADD UNIQUE KEY IF NOT EXISTS areas_nombre_unique (nombre);
    ALTER TABLE areas ADD INDEX IF NOT EXISTS fk_areas_institucion (institucion_id);
    ALTER TABLE areas DROP INDEX IF EXISTS areas_nombre_por_institucion;
  END IF;
END //
DELIMITER ;

DELIMITER //
BEGIN NOT ATOMIC
  IF EXISTS (SELECT 1 FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'instituciones')
     AND EXISTS (SELECT 1 FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'periodos' AND column_name = 'institucion_id') THEN
    ALTER TABLE periodos ADD UNIQUE KEY IF NOT EXISTS periodos_nombre_unique (nombre);
    ALTER TABLE periodos ADD INDEX IF NOT EXISTS fk_periodos_institucion (institucion_id);
    ALTER TABLE periodos DROP INDEX IF EXISTS periodos_nombre_por_institucion;
  END IF;
END //
DELIMITER ;

DELIMITER //
BEGIN NOT ATOMIC
  IF EXISTS (SELECT 1 FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'instituciones')
     AND EXISTS (SELECT 1 FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'periodos' AND column_name = 'institucion_id') THEN
    ALTER TABLE periodos ADD UNIQUE KEY IF NOT EXISTS un_solo_periodo_activo (activo_marca);
    ALTER TABLE periodos ADD INDEX IF NOT EXISTS fk_periodos_institucion (institucion_id);
    ALTER TABLE periodos DROP INDEX IF EXISTS un_periodo_activo_por_institucion;
  END IF;
END //
DELIMITER ;

DELIMITER //
BEGIN NOT ATOMIC
  IF EXISTS (SELECT 1 FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'instituciones')
     AND EXISTS (SELECT 1 FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'documentos_requeridos' AND column_name = 'institucion_id') THEN
    ALTER TABLE documentos_requeridos ADD UNIQUE KEY IF NOT EXISTS un_documento_por_nombre (nombre);
    ALTER TABLE documentos_requeridos ADD INDEX IF NOT EXISTS fk_documentos_requeridos_institucion (institucion_id);
    ALTER TABLE documentos_requeridos DROP INDEX IF EXISTS un_documento_por_nombre_e_institucion;
  END IF;
END //
DELIMITER ;

DELIMITER //
BEGIN NOT ATOMIC
  IF EXISTS (SELECT 1 FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'instituciones')
     AND EXISTS (SELECT 1 FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'datos_estudiante' AND column_name = 'institucion_id') THEN
    ALTER TABLE datos_estudiante ADD UNIQUE KEY IF NOT EXISTS datos_estudiante_documento_identidad_unique (documento_identidad);
    ALTER TABLE datos_estudiante ADD INDEX IF NOT EXISTS fk_datos_estudiante_institucion (institucion_id);
    ALTER TABLE datos_estudiante DROP INDEX IF EXISTS un_documento_de_identidad_por_institucion;
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
    ALTER TABLE configuracion_institucion DROP INDEX IF EXISTS una_configuracion_por_institucion;
  END IF;
END //
DELIMITER ;

