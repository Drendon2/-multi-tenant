-- ============================================================================
-- 11 · El nombre corto de la institucion · REVERTIR
-- ============================================================================
--
-- Borra el nombre corto: el icono vuelve a llevar el nombre largo.
-- Idempotente.
-- ============================================================================

ALTER TABLE configuracion_institucion DROP COLUMN IF EXISTS nombre_corto;
