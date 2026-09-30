-- ============================================================================
-- 01 · Reversion
-- ============================================================================
--
-- Devuelve el nombre viejo a la columna de programas externos y borra
-- `instituciones`. Corre DESPUES de revertir el 02.
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
  IF EXISTS (SELECT 1 FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'actividades'
                AND column_name = 'institucion_externa_id') THEN
    IF EXISTS (SELECT 1 FROM information_schema.columns
                WHERE table_schema = DATABASE() AND table_name = 'actividades'
                  AND column_name = 'institucion_id') THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'actividades todavia tiene institucion_id: revierte primero el paso 02.';
    END IF;
    ALTER TABLE actividades DROP CONSTRAINT IF EXISTS institucion_solo_en_programa_externo;
    ALTER TABLE actividades DROP FOREIGN KEY IF EXISTS actividades_institucion_externa_id_foreign;
    ALTER TABLE actividades DROP INDEX IF EXISTS actividades_institucion_externa_id_foreign;
    ALTER TABLE actividades CHANGE COLUMN institucion_externa_id institucion_id BIGINT UNSIGNED NULL;
  END IF;
END //
DELIMITER ;

ALTER TABLE actividades ADD INDEX IF NOT EXISTS actividades_institucion_id_foreign (institucion_id);
ALTER TABLE actividades ADD CONSTRAINT actividades_institucion_id_foreign
  FOREIGN KEY IF NOT EXISTS (institucion_id) REFERENCES instituciones_externas (id);
ALTER TABLE actividades ADD CONSTRAINT IF NOT EXISTS institucion_solo_en_programa_externo
  CHECK ((tipo = 'externo') = (institucion_id IS NOT NULL));

DROP TABLE IF EXISTS instituciones;
