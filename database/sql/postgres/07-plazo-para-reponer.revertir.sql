-- ============================================================================
-- 07 · El plazo para reponer una falta · REVERTIR
-- ============================================================================
--
-- Borra el plazo y la fecha de clasificacion: las faltas dejan de vencer.
-- Idempotente.
-- ============================================================================

ALTER TABLE omisiones_archivadas DROP COLUMN IF EXISTS clasificada_en;
ALTER TABLE configuracion_institucion DROP COLUMN IF EXISTS dias_para_reponer;
