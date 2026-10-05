-- ============================================================================
-- 09 · El documento del personal · REVERTIR
-- ============================================================================
--
-- Borra el documento del personal: la barrera vuelve a pedirlo a todos.
-- Idempotente.
-- ============================================================================

DROP INDEX IF EXISTS un_documento_del_personal_por_institucion;
ALTER TABLE perfiles DROP COLUMN IF EXISTS documento_identidad;
