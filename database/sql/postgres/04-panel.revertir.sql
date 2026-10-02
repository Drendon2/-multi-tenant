-- ============================================================================
-- 04 · El panel de todas las instituciones · REVERTIR
-- ============================================================================
--
-- Borra `operadores` (con sus cuentas: se vuelven a crear con
-- `operador:crear`) y libera los subdominios reservados. Idempotente.
-- ============================================================================

ALTER TABLE instituciones DROP CONSTRAINT IF EXISTS subdominio_no_reservado;
DROP TABLE IF EXISTS operadores;
