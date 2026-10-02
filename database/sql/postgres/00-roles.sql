-- ============================================================================
-- 00 · Los tres roles de la base
-- ============================================================================
--
-- Lo corre UN SUPERUSUARIO, una vez por servidor y antes de las migraciones:
-- los roles son del servidor, no de una base, y crearlos pide privilegios que
-- la aplicacion no tiene ni debe tener. Por eso NO lo corre ninguna migracion.
--
--   psql -U postgres -f database/sql/postgres/00-roles.sql
--
-- Las contraseñas NO van aqui (este archivo esta en un repositorio). Despues:
--   psql -U postgres -c "\password matriculas_dueno"     (y los otros dos)
--
-- Idempotente: crea lo que falta y vuelve a fijar los atributos de los que ya
-- estaban, que es lo que importa —un rol de aplicacion que alguien hizo
-- superusuario se saltaria RLS sin que nada fallara—.
--
-- Los tres, y por que no son uno:
--
-- * matriculas_dueno  Dueño de las tablas. Corre las migraciones y los guiones
--                     de `database/`. Por ser el dueño, RLS no le aplica.
-- * matriculas        La aplicacion. Solo lee y escribe filas, y RLS la ata a
--                     la institucion de cada peticion. No es dueña de nada: un
--                     dueño puede apagar RLS con un ALTER TABLE, y una
--                     inyeccion de SQL con ese rol se lo llevaria por delante.
-- * matriculas_global Solo lectura de TODAS las instituciones, para las
--                     estadisticas globales (paso 5 de la hoja de ruta). Se
--                     salta RLS por atributo del rol, no por un hueco en la
--                     politica. Nada lo usa todavia.
--
-- Si una instalacion usa otros nombres, se cambian aqui y en el `.env`
-- (DB_USERNAME, DB_DUENO_USERNAME, DB_ROL_GLOBAL).
-- ============================================================================

DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'matriculas_dueno') THEN
    CREATE ROLE matriculas_dueno LOGIN;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'matriculas') THEN
    CREATE ROLE matriculas LOGIN;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'matriculas_global') THEN
    CREATE ROLE matriculas_global LOGIN;
  END IF;
END
$$;

ALTER ROLE matriculas_dueno  NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS;
ALTER ROLE matriculas        NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS;
ALTER ROLE matriculas_global NOSUPERUSER NOCREATEDB NOCREATEROLE BYPASSRLS;
