-- ============================================================================
-- 06 · Por que no se dicto una clase, y con que clase se repuso
-- ============================================================================
--
-- Traido de `main` (03/10/2026, decision del usuario). La bandeja de alertas
-- ya no solo ARCHIVA una clase no dictada: dice POR QUE.
--
-- - `excusa`: no se repone y no le cuenta al profesor como perdida.
-- - `falta`: le cuenta —aunque la reponga— y le aparece en el Panel, en
--   «Clases por reemplazar».
-- - `institucion`: festivo, evento o cierre. Ni cuenta ni se repone.
-- - NULO: archivada antes de esto, sin clasificar. Sigue contando.
--
-- `repuesta_en_id` es la clase que la repuso, UNICA: una clase repone una
-- falta y no dos. `ON DELETE SET NULL`: si esa clase desaparece, la falta
-- vuelve a estar por reponer.
--
-- La tabla ya lleva `institucion_id` y RLS desde 01/02, y las dos columnas
-- nuevas no cambian eso. Lo corre el DUEÑO de las tablas. Idempotente.
-- ============================================================================

ALTER TABLE omisiones_archivadas ADD COLUMN IF NOT EXISTS causa varchar(12) COLLATE insensible NULL;
ALTER TABLE omisiones_archivadas ADD COLUMN IF NOT EXISTS repuesta_en_id bigint NULL;

DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'causa_de_omision_valida') THEN
    ALTER TABLE omisiones_archivadas ADD CONSTRAINT causa_de_omision_valida
      CHECK (causa IS NULL OR causa IN ('excusa', 'falta', 'institucion'));
  END IF;

  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'omisiones_archivadas_repuesta_en_id_unique') THEN
    ALTER TABLE omisiones_archivadas ADD CONSTRAINT omisiones_archivadas_repuesta_en_id_unique UNIQUE (repuesta_en_id);
  END IF;

  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'omisiones_archivadas_repuesta_en_id_foreign') THEN
    ALTER TABLE omisiones_archivadas ADD CONSTRAINT omisiones_archivadas_repuesta_en_id_foreign
      FOREIGN KEY (repuesta_en_id) REFERENCES clases (id) ON DELETE SET NULL;
  END IF;
END
$$;
