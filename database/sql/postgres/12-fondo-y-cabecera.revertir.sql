-- ============================================================================
-- 12 · El fondo de pagina y la cabecera de cada institucion · REVERTIR
-- ============================================================================
--
-- Borra los dos colores: todas vuelven a los de fabrica. Idempotente.
-- ============================================================================

ALTER TABLE configuracion_institucion DROP COLUMN IF EXISTS color_cabecera;
ALTER TABLE configuracion_institucion DROP COLUMN IF EXISTS color_fondo;
