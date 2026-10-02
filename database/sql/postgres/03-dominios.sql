-- ============================================================================
-- 03 · La institucion la dice el DOMINIO
-- ============================================================================
--
-- Paso 4a de la version multi-institucion. Hasta aqui la institucion de una
-- peticion salia de la cuenta con sesion, y quien llegaba sin sesion caia en la
-- institucion por defecto. Desde aqui la dice el HOST de la peticion (ver
-- `App\Support\InstitucionActual::delHost()`), y con ella sabida ANTES de
-- entrar:
--
-- 1. `instituciones.dominio_propio`: el dominio de una entidad que trae el
--    suyo (`matriculas.guarne.gov.co`). El `subdominio` ya existia y cuelga del
--    dominio base del `.env` (`guarne.<dominio base>`). Ninguno lleva puerto.
-- 2. `users.username` pasa a ser unico POR institucion: dos casas pueden tener
--    cada una su «admin».
-- 3. `users` entra en RLS, como las demas tablas de datos. Se habia dejado fuera
--    en el paso 3 porque la institucion salia de ella; ahora sale del dominio.
--
-- Lo corre el DUEÑO de las tablas (`php artisan migrate --database=pgsql_dueno`).
-- Idempotente: se puede correr dos veces seguidas.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. Los dos nombres de una institucion en la red.
--
-- En minusculas, sin puerto y sin esquema: se comparan con el host de la
-- peticion, que el navegador manda asi. Las expresiones llevan `COLLATE "C"`
-- porque la columna es `insensible` y con ese cotejo una expresion regular no
-- distingue mayusculas.
-- ----------------------------------------------------------------------------

ALTER TABLE instituciones ADD COLUMN IF NOT EXISTS dominio_propio varchar(253) COLLATE insensible NULL;

DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'instituciones_dominio_propio_unique') THEN
    ALTER TABLE instituciones ADD CONSTRAINT instituciones_dominio_propio_unique UNIQUE (dominio_propio);
  END IF;

  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'subdominio_valido') THEN
    ALTER TABLE instituciones ADD CONSTRAINT subdominio_valido
      CHECK ((subdominio COLLATE "C") ~ '^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$');
  END IF;

  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'dominio_propio_valido') THEN
    ALTER TABLE instituciones ADD CONSTRAINT dominio_propio_valido
      CHECK ((dominio_propio COLLATE "C") ~ '^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]*[a-z0-9]$');
  END IF;
END
$$;

-- ----------------------------------------------------------------------------
-- 2. El nombre de usuario, unico dentro de cada institucion.
--
-- El indice nuevo empieza por `institucion_id`, que es como lo busca el login
-- (con RLS, la condicion de la institucion va en cada consulta).
-- ----------------------------------------------------------------------------

DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'users_institucion_username_unique') THEN
    ALTER TABLE users ADD CONSTRAINT users_institucion_username_unique UNIQUE (institucion_id, username);
  END IF;
END
$$;

ALTER TABLE users DROP CONSTRAINT IF EXISTS users_username_unique;

-- ----------------------------------------------------------------------------
-- 3. `users` con RLS, con la misma politica que las demas (ver 02-rls.sql).
-- ----------------------------------------------------------------------------

ALTER TABLE users ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS por_institucion ON users;
CREATE POLICY por_institucion ON users
  USING (institucion_id = institucion_de_la_sesion())
  WITH CHECK (institucion_id = institucion_de_la_sesion());
