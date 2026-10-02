-- ============================================================================
-- 05 · Entrar a una institucion desde el panel · REVERTIR
-- ============================================================================
--
-- Borra `suplantaciones`, y con ella el registro de quien entro desde el panel.
-- Idempotente.
-- ============================================================================

DROP TABLE IF EXISTS suplantaciones;
