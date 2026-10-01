-- ============================================================================
-- 02 · Columna institucion_id en las tablas de datos
-- ============================================================================
--
-- Por tabla, en este orden: la columna nace NULL, se rellena con 1 (todo lo
-- que hay es de la institucion 1), pasa a NOT NULL y recibe su FK.
--
-- No lleva valor por defecto a proposito: una fila escrita sin decir de quien
-- es tiene que FALLAR, no caer en silencio en la institucion 1.
--
-- Rellenar `matriculas` no dispara el trigger de cupo: solo revisa cuando la
-- ocupacion SUBE (cambia estado, promotoria o periodo).
--
-- Se quedan SIN la columna las tablas del framework: migrations, cache,
-- cache_locks, jobs, job_batches, failed_jobs, sessions, password_reset_tokens.
--
-- Idempotente: se puede correr dos veces seguidas, o de nuevo tras un fallo a
-- medias, y termina en el mismo estado. MariaDB 10.5 o superior. Con el
-- cliente de consola: mysql -h ... nombre_de_la_base < este_archivo.sql
-- ============================================================================

-- actividades
ALTER TABLE actividades ADD COLUMN IF NOT EXISTS institucion_id BIGINT UNSIGNED NULL AFTER id;
UPDATE actividades SET institucion_id = 1 WHERE institucion_id IS NULL;
ALTER TABLE actividades MODIFY COLUMN institucion_id BIGINT UNSIGNED NOT NULL AFTER id;
ALTER TABLE actividades ADD CONSTRAINT fk_actividades_institucion
  FOREIGN KEY IF NOT EXISTS (institucion_id) REFERENCES instituciones (id);

-- acudientes
ALTER TABLE acudientes ADD COLUMN IF NOT EXISTS institucion_id BIGINT UNSIGNED NULL AFTER id;
UPDATE acudientes SET institucion_id = 1 WHERE institucion_id IS NULL;
ALTER TABLE acudientes MODIFY COLUMN institucion_id BIGINT UNSIGNED NOT NULL AFTER id;
ALTER TABLE acudientes ADD CONSTRAINT fk_acudientes_institucion
  FOREIGN KEY IF NOT EXISTS (institucion_id) REFERENCES instituciones (id);

-- areas
ALTER TABLE areas ADD COLUMN IF NOT EXISTS institucion_id BIGINT UNSIGNED NULL AFTER id;
UPDATE areas SET institucion_id = 1 WHERE institucion_id IS NULL;
ALTER TABLE areas MODIFY COLUMN institucion_id BIGINT UNSIGNED NOT NULL AFTER id;
ALTER TABLE areas ADD CONSTRAINT fk_areas_institucion
  FOREIGN KEY IF NOT EXISTS (institucion_id) REFERENCES instituciones (id);

-- areas_dirigidas
ALTER TABLE areas_dirigidas ADD COLUMN IF NOT EXISTS institucion_id BIGINT UNSIGNED NULL AFTER id;
UPDATE areas_dirigidas SET institucion_id = 1 WHERE institucion_id IS NULL;
ALTER TABLE areas_dirigidas MODIFY COLUMN institucion_id BIGINT UNSIGNED NOT NULL AFTER id;
ALTER TABLE areas_dirigidas ADD CONSTRAINT fk_areas_dirigidas_institucion
  FOREIGN KEY IF NOT EXISTS (institucion_id) REFERENCES instituciones (id);

-- asignaciones_grupo
ALTER TABLE asignaciones_grupo ADD COLUMN IF NOT EXISTS institucion_id BIGINT UNSIGNED NULL AFTER id;
UPDATE asignaciones_grupo SET institucion_id = 1 WHERE institucion_id IS NULL;
ALTER TABLE asignaciones_grupo MODIFY COLUMN institucion_id BIGINT UNSIGNED NOT NULL AFTER id;
ALTER TABLE asignaciones_grupo ADD CONSTRAINT fk_asignaciones_grupo_institucion
  FOREIGN KEY IF NOT EXISTS (institucion_id) REFERENCES instituciones (id);

-- asistencias
ALTER TABLE asistencias ADD COLUMN IF NOT EXISTS institucion_id BIGINT UNSIGNED NULL AFTER id;
UPDATE asistencias SET institucion_id = 1 WHERE institucion_id IS NULL;
ALTER TABLE asistencias MODIFY COLUMN institucion_id BIGINT UNSIGNED NOT NULL AFTER id;
ALTER TABLE asistencias ADD CONSTRAINT fk_asistencias_institucion
  FOREIGN KEY IF NOT EXISTS (institucion_id) REFERENCES instituciones (id);

-- asistencias_actividad
ALTER TABLE asistencias_actividad ADD COLUMN IF NOT EXISTS institucion_id BIGINT UNSIGNED NULL AFTER id;
UPDATE asistencias_actividad SET institucion_id = 1 WHERE institucion_id IS NULL;
ALTER TABLE asistencias_actividad MODIFY COLUMN institucion_id BIGINT UNSIGNED NOT NULL AFTER id;
ALTER TABLE asistencias_actividad ADD CONSTRAINT fk_asistencias_actividad_institucion
  FOREIGN KEY IF NOT EXISTS (institucion_id) REFERENCES instituciones (id);

-- clases
ALTER TABLE clases ADD COLUMN IF NOT EXISTS institucion_id BIGINT UNSIGNED NULL AFTER id;
UPDATE clases SET institucion_id = 1 WHERE institucion_id IS NULL;
ALTER TABLE clases MODIFY COLUMN institucion_id BIGINT UNSIGNED NOT NULL AFTER id;
ALTER TABLE clases ADD CONSTRAINT fk_clases_institucion
  FOREIGN KEY IF NOT EXISTS (institucion_id) REFERENCES instituciones (id);

-- configuracion_institucion
ALTER TABLE configuracion_institucion ADD COLUMN IF NOT EXISTS institucion_id BIGINT UNSIGNED NULL AFTER id;
UPDATE configuracion_institucion SET institucion_id = 1 WHERE institucion_id IS NULL;
ALTER TABLE configuracion_institucion MODIFY COLUMN institucion_id BIGINT UNSIGNED NOT NULL AFTER id;
ALTER TABLE configuracion_institucion ADD CONSTRAINT fk_configuracion_institucion_institucion
  FOREIGN KEY IF NOT EXISTS (institucion_id) REFERENCES instituciones (id);

-- confirmaciones_clase
ALTER TABLE confirmaciones_clase ADD COLUMN IF NOT EXISTS institucion_id BIGINT UNSIGNED NULL AFTER id;
UPDATE confirmaciones_clase SET institucion_id = 1 WHERE institucion_id IS NULL;
ALTER TABLE confirmaciones_clase MODIFY COLUMN institucion_id BIGINT UNSIGNED NOT NULL AFTER id;
ALTER TABLE confirmaciones_clase ADD CONSTRAINT fk_confirmaciones_clase_institucion
  FOREIGN KEY IF NOT EXISTS (institucion_id) REFERENCES instituciones (id);

-- cupos_promotoria
ALTER TABLE cupos_promotoria ADD COLUMN IF NOT EXISTS institucion_id BIGINT UNSIGNED NULL AFTER id;
UPDATE cupos_promotoria SET institucion_id = 1 WHERE institucion_id IS NULL;
ALTER TABLE cupos_promotoria MODIFY COLUMN institucion_id BIGINT UNSIGNED NOT NULL AFTER id;
ALTER TABLE cupos_promotoria ADD CONSTRAINT fk_cupos_promotoria_institucion
  FOREIGN KEY IF NOT EXISTS (institucion_id) REFERENCES instituciones (id);

-- datos_estudiante
ALTER TABLE datos_estudiante ADD COLUMN IF NOT EXISTS institucion_id BIGINT UNSIGNED NULL AFTER id;
UPDATE datos_estudiante SET institucion_id = 1 WHERE institucion_id IS NULL;
ALTER TABLE datos_estudiante MODIFY COLUMN institucion_id BIGINT UNSIGNED NOT NULL AFTER id;
ALTER TABLE datos_estudiante ADD CONSTRAINT fk_datos_estudiante_institucion
  FOREIGN KEY IF NOT EXISTS (institucion_id) REFERENCES instituciones (id);

-- documentos_estudiante
ALTER TABLE documentos_estudiante ADD COLUMN IF NOT EXISTS institucion_id BIGINT UNSIGNED NULL AFTER id;
UPDATE documentos_estudiante SET institucion_id = 1 WHERE institucion_id IS NULL;
ALTER TABLE documentos_estudiante MODIFY COLUMN institucion_id BIGINT UNSIGNED NOT NULL AFTER id;
ALTER TABLE documentos_estudiante ADD CONSTRAINT fk_documentos_estudiante_institucion
  FOREIGN KEY IF NOT EXISTS (institucion_id) REFERENCES instituciones (id);

-- documentos_requeridos
ALTER TABLE documentos_requeridos ADD COLUMN IF NOT EXISTS institucion_id BIGINT UNSIGNED NULL AFTER id;
UPDATE documentos_requeridos SET institucion_id = 1 WHERE institucion_id IS NULL;
ALTER TABLE documentos_requeridos MODIFY COLUMN institucion_id BIGINT UNSIGNED NOT NULL AFTER id;
ALTER TABLE documentos_requeridos ADD CONSTRAINT fk_documentos_requeridos_institucion
  FOREIGN KEY IF NOT EXISTS (institucion_id) REFERENCES instituciones (id);

-- encuestas_demograficas
ALTER TABLE encuestas_demograficas ADD COLUMN IF NOT EXISTS institucion_id BIGINT UNSIGNED NULL AFTER id;
UPDATE encuestas_demograficas SET institucion_id = 1 WHERE institucion_id IS NULL;
ALTER TABLE encuestas_demograficas MODIFY COLUMN institucion_id BIGINT UNSIGNED NOT NULL AFTER id;
ALTER TABLE encuestas_demograficas ADD CONSTRAINT fk_encuestas_demograficas_institucion
  FOREIGN KEY IF NOT EXISTS (institucion_id) REFERENCES instituciones (id);

-- encuestas_satisfaccion
ALTER TABLE encuestas_satisfaccion ADD COLUMN IF NOT EXISTS institucion_id BIGINT UNSIGNED NULL AFTER id;
UPDATE encuestas_satisfaccion SET institucion_id = 1 WHERE institucion_id IS NULL;
ALTER TABLE encuestas_satisfaccion MODIFY COLUMN institucion_id BIGINT UNSIGNED NOT NULL AFTER id;
ALTER TABLE encuestas_satisfaccion ADD CONSTRAINT fk_encuestas_satisfaccion_institucion
  FOREIGN KEY IF NOT EXISTS (institucion_id) REFERENCES instituciones (id);

-- grupos
ALTER TABLE grupos ADD COLUMN IF NOT EXISTS institucion_id BIGINT UNSIGNED NULL AFTER id;
UPDATE grupos SET institucion_id = 1 WHERE institucion_id IS NULL;
ALTER TABLE grupos MODIFY COLUMN institucion_id BIGINT UNSIGNED NOT NULL AFTER id;
ALTER TABLE grupos ADD CONSTRAINT fk_grupos_institucion
  FOREIGN KEY IF NOT EXISTS (institucion_id) REFERENCES instituciones (id);

-- inscritos_actividad
ALTER TABLE inscritos_actividad ADD COLUMN IF NOT EXISTS institucion_id BIGINT UNSIGNED NULL AFTER id;
UPDATE inscritos_actividad SET institucion_id = 1 WHERE institucion_id IS NULL;
ALTER TABLE inscritos_actividad MODIFY COLUMN institucion_id BIGINT UNSIGNED NOT NULL AFTER id;
ALTER TABLE inscritos_actividad ADD CONSTRAINT fk_inscritos_actividad_institucion
  FOREIGN KEY IF NOT EXISTS (institucion_id) REFERENCES instituciones (id);

-- instituciones_externas
ALTER TABLE instituciones_externas ADD COLUMN IF NOT EXISTS institucion_id BIGINT UNSIGNED NULL AFTER id;
UPDATE instituciones_externas SET institucion_id = 1 WHERE institucion_id IS NULL;
ALTER TABLE instituciones_externas MODIFY COLUMN institucion_id BIGINT UNSIGNED NOT NULL AFTER id;
ALTER TABLE instituciones_externas ADD CONSTRAINT fk_instituciones_externas_institucion
  FOREIGN KEY IF NOT EXISTS (institucion_id) REFERENCES instituciones (id);

-- matriculas
ALTER TABLE matriculas ADD COLUMN IF NOT EXISTS institucion_id BIGINT UNSIGNED NULL AFTER id;
UPDATE matriculas SET institucion_id = 1 WHERE institucion_id IS NULL;
ALTER TABLE matriculas MODIFY COLUMN institucion_id BIGINT UNSIGNED NOT NULL AFTER id;
ALTER TABLE matriculas ADD CONSTRAINT fk_matriculas_institucion
  FOREIGN KEY IF NOT EXISTS (institucion_id) REFERENCES instituciones (id);

-- omisiones_archivadas
ALTER TABLE omisiones_archivadas ADD COLUMN IF NOT EXISTS institucion_id BIGINT UNSIGNED NULL AFTER id;
UPDATE omisiones_archivadas SET institucion_id = 1 WHERE institucion_id IS NULL;
ALTER TABLE omisiones_archivadas MODIFY COLUMN institucion_id BIGINT UNSIGNED NOT NULL AFTER id;
ALTER TABLE omisiones_archivadas ADD CONSTRAINT fk_omisiones_archivadas_institucion
  FOREIGN KEY IF NOT EXISTS (institucion_id) REFERENCES instituciones (id);

-- perfiles
ALTER TABLE perfiles ADD COLUMN IF NOT EXISTS institucion_id BIGINT UNSIGNED NULL AFTER id;
UPDATE perfiles SET institucion_id = 1 WHERE institucion_id IS NULL;
ALTER TABLE perfiles MODIFY COLUMN institucion_id BIGINT UNSIGNED NOT NULL AFTER id;
ALTER TABLE perfiles ADD CONSTRAINT fk_perfiles_institucion
  FOREIGN KEY IF NOT EXISTS (institucion_id) REFERENCES instituciones (id);

-- periodos
ALTER TABLE periodos ADD COLUMN IF NOT EXISTS institucion_id BIGINT UNSIGNED NULL AFTER id;
UPDATE periodos SET institucion_id = 1 WHERE institucion_id IS NULL;
ALTER TABLE periodos MODIFY COLUMN institucion_id BIGINT UNSIGNED NOT NULL AFTER id;
ALTER TABLE periodos ADD CONSTRAINT fk_periodos_institucion
  FOREIGN KEY IF NOT EXISTS (institucion_id) REFERENCES instituciones (id);

-- promotorias
ALTER TABLE promotorias ADD COLUMN IF NOT EXISTS institucion_id BIGINT UNSIGNED NULL AFTER id;
UPDATE promotorias SET institucion_id = 1 WHERE institucion_id IS NULL;
ALTER TABLE promotorias MODIFY COLUMN institucion_id BIGINT UNSIGNED NOT NULL AFTER id;
ALTER TABLE promotorias ADD CONSTRAINT fk_promotorias_institucion
  FOREIGN KEY IF NOT EXISTS (institucion_id) REFERENCES instituciones (id);

-- restablecimientos_clave
ALTER TABLE restablecimientos_clave ADD COLUMN IF NOT EXISTS institucion_id BIGINT UNSIGNED NULL AFTER user_id;
UPDATE restablecimientos_clave SET institucion_id = 1 WHERE institucion_id IS NULL;
ALTER TABLE restablecimientos_clave MODIFY COLUMN institucion_id BIGINT UNSIGNED NOT NULL AFTER user_id;
ALTER TABLE restablecimientos_clave ADD CONSTRAINT fk_restablecimientos_clave_institucion
  FOREIGN KEY IF NOT EXISTS (institucion_id) REFERENCES instituciones (id);

-- sesiones_actividad
ALTER TABLE sesiones_actividad ADD COLUMN IF NOT EXISTS institucion_id BIGINT UNSIGNED NULL AFTER id;
UPDATE sesiones_actividad SET institucion_id = 1 WHERE institucion_id IS NULL;
ALTER TABLE sesiones_actividad MODIFY COLUMN institucion_id BIGINT UNSIGNED NOT NULL AFTER id;
ALTER TABLE sesiones_actividad ADD CONSTRAINT fk_sesiones_actividad_institucion
  FOREIGN KEY IF NOT EXISTS (institucion_id) REFERENCES instituciones (id);

-- sesiones_grupo
ALTER TABLE sesiones_grupo ADD COLUMN IF NOT EXISTS institucion_id BIGINT UNSIGNED NULL AFTER id;
UPDATE sesiones_grupo SET institucion_id = 1 WHERE institucion_id IS NULL;
ALTER TABLE sesiones_grupo MODIFY COLUMN institucion_id BIGINT UNSIGNED NOT NULL AFTER id;
ALTER TABLE sesiones_grupo ADD CONSTRAINT fk_sesiones_grupo_institucion
  FOREIGN KEY IF NOT EXISTS (institucion_id) REFERENCES instituciones (id);

-- users
ALTER TABLE users ADD COLUMN IF NOT EXISTS institucion_id BIGINT UNSIGNED NULL AFTER id;
UPDATE users SET institucion_id = 1 WHERE institucion_id IS NULL;
ALTER TABLE users MODIFY COLUMN institucion_id BIGINT UNSIGNED NOT NULL AFTER id;
ALTER TABLE users ADD CONSTRAINT fk_users_institucion
  FOREIGN KEY IF NOT EXISTS (institucion_id) REFERENCES instituciones (id);

