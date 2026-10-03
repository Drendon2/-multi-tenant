-- ============================================================================
-- 06 · Por que no se dicto una clase · REVERTIR
-- ============================================================================
--
-- Borra la causa y el enlace con la reposicion: las omisiones vuelven a ser
-- solo «archivadas», y las clases de reposicion quedan como clases sueltas.
-- Idempotente.
-- ============================================================================

ALTER TABLE omisiones_archivadas DROP CONSTRAINT IF EXISTS causa_de_omision_valida;
ALTER TABLE omisiones_archivadas DROP COLUMN IF EXISTS repuesta_en_id;
ALTER TABLE omisiones_archivadas DROP COLUMN IF EXISTS causa;
