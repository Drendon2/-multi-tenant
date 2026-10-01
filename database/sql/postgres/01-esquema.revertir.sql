-- ============================================================================
-- 01 · Esquema completo en PostgreSQL · REVERTIR
-- ============================================================================
--
-- Borra todo lo que crea `01-esquema.sql`, en el orden inverso de las claves.
-- Idempotente: todo es `IF EXISTS`. Se lleva los DATOS: solo tiene sentido en
-- una base de pruebas o para volver a montar el esquema desde cero.
--
-- Se niega si hay mas de una institucion, igual que las reversiones de
-- `database/sql/multi-tenant/`: con varias, deshacer el esquema es perder los
-- datos de alguien que no es quien lo lanza.
-- ============================================================================

-- Anidado y con EXECUTE a proposito: PL/pgSQL prepara la condicion ENTERA
-- aunque la primera mitad ya sea falsa, asi que un `AND (SELECT ... FROM
-- instituciones)` revienta en la segunda pasada, con la tabla ya borrada.
DO $$
DECLARE
  v_cuantas bigint;
BEGIN
  IF to_regclass('instituciones') IS NOT NULL THEN
    EXECUTE 'SELECT COUNT(*) FROM instituciones' INTO v_cuantas;
    IF v_cuantas > 1 THEN
      RAISE EXCEPTION 'Hay más de una institución: no se revierte el esquema.';
    END IF;
  END IF;
END
$$;

DROP TABLE IF EXISTS failed_jobs;
DROP TABLE IF EXISTS job_batches;
DROP TABLE IF EXISTS jobs;
DROP TABLE IF EXISTS cache_locks;
DROP TABLE IF EXISTS cache;
DROP TABLE IF EXISTS password_reset_tokens;
DROP TABLE IF EXISTS sessions;

DROP TABLE IF EXISTS asistencias_actividad;
DROP TABLE IF EXISTS inscritos_actividad;
DROP TABLE IF EXISTS sesiones_actividad;
DROP TABLE IF EXISTS actividades;
DROP TABLE IF EXISTS instituciones_externas;

DROP TABLE IF EXISTS omisiones_archivadas;
DROP TABLE IF EXISTS confirmaciones_clase;
DROP TABLE IF EXISTS asistencias;
DROP TABLE IF EXISTS clases;

DROP TABLE IF EXISTS sesiones_grupo;
DROP TABLE IF EXISTS asignaciones_grupo;
DROP TABLE IF EXISTS matriculas;
DROP FUNCTION IF EXISTS cupo_promotoria_disponible();
DROP TABLE IF EXISTS grupos;
DROP TABLE IF EXISTS cupos_promotoria;
DROP TABLE IF EXISTS encuestas_satisfaccion;
DROP TABLE IF EXISTS promotorias;

DROP TABLE IF EXISTS encuestas_demograficas;
DROP TABLE IF EXISTS documentos_estudiante;
DROP TABLE IF EXISTS documentos_requeridos;
DROP TABLE IF EXISTS datos_estudiante;
DROP TABLE IF EXISTS acudientes;

DROP TABLE IF EXISTS periodos;
DROP TABLE IF EXISTS areas_dirigidas;
DROP TABLE IF EXISTS areas;
DROP TABLE IF EXISTS configuracion_institucion;

DROP TABLE IF EXISTS restablecimientos_clave;
DROP FUNCTION IF EXISTS restablecimiento_al_dia();
DROP TABLE IF EXISTS perfiles;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS instituciones;

DROP COLLATION IF EXISTS insensible;
