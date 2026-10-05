-- ============================================================================
-- 10 · El domingo tambien es dia de clase · REVERTIR
-- ============================================================================
--
-- Vuelve a lunes-sabado. Se NIEGA si algun grupo ya tiene clase el domingo:
-- esa fila no tendria donde ir, y borrarla seria borrar un horario de verdad.
-- Idempotente.
-- ============================================================================

DO $$
BEGIN
  IF EXISTS (SELECT 1 FROM sesiones_grupo WHERE dia = 7) THEN
    RAISE EXCEPTION 'Hay grupos con clase el domingo: quitalos antes de revertir.';
  END IF;
END
$$;

ALTER TABLE sesiones_grupo DROP CONSTRAINT IF EXISTS dia_valido;
ALTER TABLE sesiones_grupo ADD CONSTRAINT dia_valido CHECK (dia BETWEEN 1 AND 6);
