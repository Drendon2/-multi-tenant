-- ============================================================================
-- 09 · El documento del personal
-- ============================================================================
--
-- Traido de `main` (05/10/2026, pedido del usuario). El registro de profesores
-- pide documento y correo, y al profesor o director que no los tenga no se le
-- deja seguir hasta escribirlos (`App\Http\Middleware\DatosDelPersonal`).
--
-- - `perfiles.documento_identidad`: el del PERSONAL. El del estudiante sigue
--   en `datos_estudiante`. NULO es legitimo (cuentas anteriores, estudiantes).
-- - Unico POR INSTITUCION, como el del estudiante: la misma persona puede
--   dictar en dos alcaldias. Un indice unico admite varios NULL.
--
-- `perfiles` ya lleva `institucion_id` y RLS. Lo corre el DUEÑO de las
-- tablas. Idempotente.
-- ============================================================================

ALTER TABLE perfiles ADD COLUMN IF NOT EXISTS documento_identidad varchar(15) COLLATE insensible NULL;

CREATE UNIQUE INDEX IF NOT EXISTS un_documento_del_personal_por_institucion
    ON perfiles (institucion_id, documento_identidad);
