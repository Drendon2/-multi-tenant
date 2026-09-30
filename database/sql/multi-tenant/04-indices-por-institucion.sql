-- ============================================================================
-- 04 · Indices que empiezan por institucion_id
-- ============================================================================
--
-- En las tablas que mas se consultan: cada consulta filtra por institucion y
-- despues por lo suyo.
--
-- Donde un indice compuesto (este o un unico del 03) ya empieza por
-- institucion_id, el indice suelto que creo la FK en el 02 sobra y se quita.
--
-- Idempotente: se puede correr dos veces seguidas, o de nuevo tras un fallo a
-- medias, y termina en el mismo estado. MariaDB 10.5 o superior. Con el
-- cliente de consola: mysql -h ... nombre_de_la_base < este_archivo.sql
-- ============================================================================

ALTER TABLE perfiles ADD INDEX IF NOT EXISTS perfiles_institucion_rol (institucion_id, rol);
ALTER TABLE perfiles ADD INDEX IF NOT EXISTS perfiles_institucion_nombre (institucion_id, nombre_completo);
ALTER TABLE matriculas ADD INDEX IF NOT EXISTS matriculas_institucion_periodo_estado (institucion_id, periodo_id, estado);
ALTER TABLE promotorias ADD INDEX IF NOT EXISTS promotorias_institucion_nombre (institucion_id, nombre);
ALTER TABLE grupos ADD INDEX IF NOT EXISTS grupos_institucion_nombre (institucion_id, nombre);
ALTER TABLE clases ADD INDEX IF NOT EXISTS clases_institucion_periodo_fecha (institucion_id, periodo_id, fecha_hora);
ALTER TABLE actividades ADD INDEX IF NOT EXISTS actividades_institucion_tipo_nombre (institucion_id, tipo, nombre);
ALTER TABLE encuestas_satisfaccion ADD INDEX IF NOT EXISTS encuestas_satisfaccion_institucion_periodo (institucion_id, periodo_id);
ALTER TABLE documentos_requeridos ADD INDEX IF NOT EXISTS documentos_requeridos_institucion_orden (institucion_id, orden, nombre);

-- Indices sueltos de la FK que ya cubre un compuesto.
ALTER TABLE actividades DROP INDEX IF EXISTS fk_actividades_institucion;
ALTER TABLE areas DROP INDEX IF EXISTS fk_areas_institucion;
ALTER TABLE clases DROP INDEX IF EXISTS fk_clases_institucion;
ALTER TABLE configuracion_institucion DROP INDEX IF EXISTS fk_configuracion_institucion_institucion;
ALTER TABLE datos_estudiante DROP INDEX IF EXISTS fk_datos_estudiante_institucion;
ALTER TABLE documentos_requeridos DROP INDEX IF EXISTS fk_documentos_requeridos_institucion;
ALTER TABLE encuestas_satisfaccion DROP INDEX IF EXISTS fk_encuestas_satisfaccion_institucion;
ALTER TABLE grupos DROP INDEX IF EXISTS fk_grupos_institucion;
ALTER TABLE matriculas DROP INDEX IF EXISTS fk_matriculas_institucion;
ALTER TABLE perfiles DROP INDEX IF EXISTS fk_perfiles_institucion;
ALTER TABLE periodos DROP INDEX IF EXISTS fk_periodos_institucion;
ALTER TABLE promotorias DROP INDEX IF EXISTS fk_promotorias_institucion;
