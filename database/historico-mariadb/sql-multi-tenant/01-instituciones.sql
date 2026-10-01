-- ============================================================================
-- 01 · Tabla de instituciones
-- ============================================================================
--
-- Crea `instituciones` y registra la que ya usa esta base como la numero 1,
-- con el nombre que tenga en Gestion -> Institucion.
--
-- Antes, `actividades.institucion_id` (la institucion EXTERNA de un programa
-- externo) pasa a llamarse `institucion_externa_id`: el nombre `institucion_id`
-- queda para la columna de la institucion duena de cada fila.
--
-- Idempotente: se puede correr dos veces seguidas, o de nuevo tras un fallo a
-- medias, y termina en el mismo estado. MariaDB 10.5 o superior. Con el
-- cliente de consola: mysql -h ... nombre_de_la_base < este_archivo.sql
-- ============================================================================

CREATE TABLE IF NOT EXISTS instituciones (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre      VARCHAR(80)     NOT NULL,
  subdominio  VARCHAR(63)     NULL,
  estado      VARCHAR(20)     NOT NULL DEFAULT 'activa',
  fecha_alta  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY instituciones_subdominio_unique (subdominio),
  CONSTRAINT nombre_de_institucion_no_vacio CHECK (nombre <> ''),
  CONSTRAINT estado_de_institucion_valido CHECK (estado IN ('activa', 'suspendida'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La 1 existe SIEMPRE, tambien en una base recien creada: es la institucion
-- por defecto y la que rellena el paso 02. `php artisan instalar` le pone el
-- nombre de verdad. La fecha de alta es la de la cuenta mas antigua.
INSERT INTO instituciones (id, nombre, estado, fecha_alta)
SELECT 1,
       COALESCE(NULLIF((SELECT nombre_institucion FROM configuracion_institucion ORDER BY id LIMIT 1), ''), 'Institución'),
       'activa',
       COALESCE((SELECT MIN(created_at) FROM users), CURRENT_TIMESTAMP)
  FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM instituciones WHERE id = 1);

-- Renombrar solo si todavia no se hizo: tras el paso 02 `actividades` vuelve a
-- tener una `institucion_id`, y renombrar ESA seria el desastre.
DELIMITER //
BEGIN NOT ATOMIC
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                  WHERE table_schema = DATABASE() AND table_name = 'actividades'
                    AND column_name = 'institucion_externa_id') THEN
    ALTER TABLE actividades DROP CONSTRAINT IF EXISTS institucion_solo_en_programa_externo;
    ALTER TABLE actividades DROP FOREIGN KEY IF EXISTS actividades_institucion_id_foreign;
    ALTER TABLE actividades DROP INDEX IF EXISTS actividades_institucion_id_foreign;
    ALTER TABLE actividades CHANGE COLUMN institucion_id institucion_externa_id BIGINT UNSIGNED NULL;
  END IF;
END //
DELIMITER ;

ALTER TABLE actividades ADD INDEX IF NOT EXISTS actividades_institucion_externa_id_foreign (institucion_externa_id);
ALTER TABLE actividades ADD CONSTRAINT actividades_institucion_externa_id_foreign
  FOREIGN KEY IF NOT EXISTS (institucion_externa_id) REFERENCES instituciones_externas (id);
ALTER TABLE actividades ADD CONSTRAINT IF NOT EXISTS institucion_solo_en_programa_externo
  CHECK ((tipo = 'externo') = (institucion_externa_id IS NOT NULL));
