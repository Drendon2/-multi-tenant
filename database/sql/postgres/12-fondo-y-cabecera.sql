-- ============================================================================
-- 12 · El fondo de pagina y la cabecera de cada institucion
-- ============================================================================
--
-- Traido de `main` (05/10/2026, pedido del usuario). VACIOS = los de fabrica.
-- Las reglas de contraste viven en `ConfiguracionController` y en DESIGN.md:
-- el fondo tiene que ser claro, el texto de la cabecera se elige solo, y en
-- modo oscuro no se usan.
--
-- `configuracion_institucion` ya lleva `institucion_id` y RLS: cada
-- institucion tiene los suyos. Lo corre el DUEÑO de las tablas. Idempotente.
-- ============================================================================

ALTER TABLE configuracion_institucion ADD COLUMN IF NOT EXISTS color_fondo varchar(7) COLLATE insensible NOT NULL DEFAULT '';
ALTER TABLE configuracion_institucion ADD COLUMN IF NOT EXISTS color_cabecera varchar(7) COLLATE insensible NOT NULL DEFAULT '';
