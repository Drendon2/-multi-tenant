-- ============================================================================
-- 07 · El plazo para reponer una falta
-- ============================================================================
--
-- Traido de `main` (03/10/2026, pedido del usuario). Una falta que no se
-- repone en N dias se SEÑALA como vencida (Panel, bandeja, estadisticas); no
-- bloquea nada.
--
-- - `configuracion_institucion.dias_para_reponer`: el plazo. NULO es «sin
--   plazo», una decision de la entidad; nace en 15.
-- - `omisiones_archivadas.clasificada_en`: desde cuando corre. Es cuando se
--   dijo la causa y no el dia de la clase. Las que ya tienen causa se rellenan
--   con su `updated_at`, que es lo mas cercano que hay.
--
-- Las dos tablas ya llevan `institucion_id` y RLS. Lo corre el DUEÑO de las
-- tablas. Idempotente: el relleno solo toca filas aun vacias.
-- ============================================================================

ALTER TABLE configuracion_institucion ADD COLUMN IF NOT EXISTS dias_para_reponer smallint NULL DEFAULT 15;
ALTER TABLE omisiones_archivadas ADD COLUMN IF NOT EXISTS clasificada_en timestamp(0) NULL;

UPDATE omisiones_archivadas
   SET clasificada_en = updated_at
 WHERE causa IS NOT NULL AND clasificada_en IS NULL;
