-- ============================================================================
-- 02 · Row Level Security · REVERTIR
-- ============================================================================
--
-- Quita las politicas, apaga RLS y borra las funciones. Idempotente.
--
-- OJO: con esto el aislamiento vuelve a depender SOLO de PHP, y
-- `InstitucionActual::filtrar()` esta vacia desde el paso 3. Revertir este
-- guion sin devolver el codigo de antes deja a cada institucion viendo las
-- filas de todas. Solo tiene sentido para volver atras el codigo y la base a la
-- vez.
--
-- Los permisos del rol de la aplicacion se dejan: quitarlos no devuelve nada a
-- como estaba (antes la aplicacion era la dueña) y dejaria el sistema sin
-- poder leer.
-- ============================================================================

DO $$
DECLARE
  v_tabla text;
BEGIN
  FOR v_tabla IN
    SELECT tablename FROM pg_policies
     WHERE schemaname = current_schema() AND policyname = 'por_institucion'
  LOOP
    EXECUTE format('DROP POLICY IF EXISTS por_institucion ON %I', v_tabla);
    EXECUTE format('ALTER TABLE %I DISABLE ROW LEVEL SECURITY', v_tabla);
  END LOOP;
END
$$;

DROP FUNCTION IF EXISTS institucion_del_restablecimiento(text);
DROP FUNCTION IF EXISTS institucion_del_enlace_de_actividad(text);
DROP FUNCTION IF EXISTS institucion_del_enlace_de_promotoria(text);
DROP FUNCTION IF EXISTS institucion_de_la_sesion();
