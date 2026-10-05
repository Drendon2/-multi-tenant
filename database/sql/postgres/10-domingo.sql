-- ============================================================================
-- 10 · El domingo tambien es dia de clase
-- ============================================================================
--
-- Traido de `main` (05/10/2026, pedido del usuario: «el dia domingo en
-- todo»). La semana de un grupo iba de lunes a sabado (1-6); ahora llega al
-- domingo (7), el `dayOfWeekIso` de ISO-8601.
--
-- Solo ENSANCHA el CHECK: ninguna fila queda fuera. Lo corre el DUEÑO de las
-- tablas. Idempotente: se borra y se vuelve a poner.
-- ============================================================================

ALTER TABLE sesiones_grupo DROP CONSTRAINT IF EXISTS dia_valido;
ALTER TABLE sesiones_grupo ADD CONSTRAINT dia_valido CHECK (dia BETWEEN 1 AND 7);
