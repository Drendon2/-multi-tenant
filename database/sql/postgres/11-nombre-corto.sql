-- ============================================================================
-- 11 · El nombre corto de la institucion
-- ============================================================================
--
-- Traido de `main` (05/10/2026, pedido del usuario). Va bajo el icono del
-- celular, donde caben unos doce caracteres. VACIO = «usa el nombre largo».
--
-- `configuracion_institucion` ya lleva `institucion_id` y RLS: cada
-- institucion tiene el suyo. Lo corre el DUEÑO de las tablas. Idempotente.
-- ============================================================================

ALTER TABLE configuracion_institucion ADD COLUMN IF NOT EXISTS nombre_corto varchar(20) COLLATE insensible NOT NULL DEFAULT '';
