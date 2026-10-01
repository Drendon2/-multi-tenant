-- ============================================================================
-- 03 · Restricciones unicas por institucion
-- ============================================================================
--
-- Lo que era unico en toda la base pasa a serlo dentro de cada institucion.
-- Primero se crea el nuevo y despues se borra el viejo: en ningun momento la
-- tabla se queda sin la garantia.
--
-- NO cambian, a proposito:
-- - users.username: el login todavia no sabe de que institucion es quien
--   entra (llega con el enrutamiento por dominio).
-- - Los tokens (perfiles.codigo_qr, promotorias.enlace_token,
--   actividades.token, restablecimientos_clave.token): son la forma en que un
--   enlace publico dice de que institucion es.
-- - Los que ya cuelgan de una fila de la institucion (grupos por promotoria,
--   matriculas por estudiante, etc.): los ids son globales.
--
-- Idempotente: se puede correr dos veces seguidas, o de nuevo tras un fallo a
-- medias, y termina en el mismo estado. MariaDB 10.5 o superior. Con el
-- cliente de consola: mysql -h ... nombre_de_la_base < este_archivo.sql
-- ============================================================================

ALTER TABLE areas ADD UNIQUE KEY IF NOT EXISTS areas_nombre_por_institucion (institucion_id, nombre);
ALTER TABLE areas DROP INDEX IF EXISTS areas_nombre_unique;

ALTER TABLE periodos ADD UNIQUE KEY IF NOT EXISTS periodos_nombre_por_institucion (institucion_id, nombre);
ALTER TABLE periodos DROP INDEX IF EXISTS periodos_nombre_unique;

ALTER TABLE periodos ADD UNIQUE KEY IF NOT EXISTS un_periodo_activo_por_institucion (institucion_id, activo_marca);
ALTER TABLE periodos DROP INDEX IF EXISTS un_solo_periodo_activo;

ALTER TABLE documentos_requeridos ADD UNIQUE KEY IF NOT EXISTS un_documento_por_nombre_e_institucion (institucion_id, nombre);
ALTER TABLE documentos_requeridos DROP INDEX IF EXISTS un_documento_por_nombre;

ALTER TABLE datos_estudiante ADD UNIQUE KEY IF NOT EXISTS un_documento_de_identidad_por_institucion (institucion_id, documento_identidad);
ALTER TABLE datos_estudiante DROP INDEX IF EXISTS datos_estudiante_documento_identidad_unique;

ALTER TABLE configuracion_institucion ADD UNIQUE KEY IF NOT EXISTS una_configuracion_por_institucion (institucion_id);

