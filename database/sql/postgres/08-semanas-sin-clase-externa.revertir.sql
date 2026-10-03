-- ============================================================================
-- 08 · La alerta semanal de los programas externos · REVERTIR
-- ============================================================================
--
-- Borra las causas de las semanas sin clase y las fechas de clases de las
-- instituciones externas. Idempotente.
-- ============================================================================

DROP TABLE IF EXISTS omisiones_externas;
ALTER TABLE instituciones_externas DROP CONSTRAINT IF EXISTS clases_externas_en_orden;
ALTER TABLE instituciones_externas DROP COLUMN IF EXISTS clases_hasta;
ALTER TABLE instituciones_externas DROP COLUMN IF EXISTS clases_desde;
