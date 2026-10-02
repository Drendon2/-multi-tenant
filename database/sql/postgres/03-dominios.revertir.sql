-- ============================================================================
-- 03 · La institucion la dice el DOMINIO · REVERTIR
-- ============================================================================
--
-- Saca `users` de RLS, devuelve el nombre de usuario unico en TODA la base y
-- borra `dominio_propio`. Idempotente.
--
-- Se niega si el mismo nombre de usuario existe en dos instituciones: la
-- restriccion de antes no se podria poner, y elegir cual de las dos cuentas se
-- renombra no lo puede decidir un guion.
--
-- OJO: como con 02, revertir esto sin devolver el codigo de antes deja `users`
-- sin ningun filtro (el codigo de 4a ya no lo filtra en PHP).
-- ============================================================================

DROP POLICY IF EXISTS por_institucion ON users;
ALTER TABLE users DISABLE ROW LEVEL SECURITY;

DO $$
BEGIN
  IF EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'users_username_unique') THEN
    RETURN;
  END IF;

  IF EXISTS (SELECT 1 FROM users GROUP BY username HAVING count(*) > 1) THEN
    RAISE EXCEPTION 'Hay nombres de usuario repetidos entre instituciones: no se puede volver a hacerlos unicos en toda la base.';
  END IF;

  ALTER TABLE users ADD CONSTRAINT users_username_unique UNIQUE (username);
END
$$;

ALTER TABLE users DROP CONSTRAINT IF EXISTS users_institucion_username_unique;

ALTER TABLE instituciones DROP CONSTRAINT IF EXISTS dominio_propio_valido;
ALTER TABLE instituciones DROP CONSTRAINT IF EXISTS subdominio_valido;
ALTER TABLE instituciones DROP CONSTRAINT IF EXISTS instituciones_dominio_propio_unique;
ALTER TABLE instituciones DROP COLUMN IF EXISTS dominio_propio;
