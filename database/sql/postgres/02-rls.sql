-- ============================================================================
-- 02 · Row Level Security: cada fila, solo para su institucion
-- ============================================================================
--
-- Hasta aqui el aislamiento entre instituciones lo hacia PHP, con un `where
-- institucion_id = ?` en cada consulta (`InstitucionActual::filtrar()`). Desde
-- este guion lo hace el MOTOR: una consulta que se olvide del filtro, o un
-- `DB::table()` suelto, ya no puede ver ni escribir filas de otra institucion.
--
-- Lo corre el DUEÑO de las tablas (`matriculas_dueno`), con dos variables
-- puestas antes, que son los nombres de los otros dos roles:
--
--   SET app.rol_aplicacion = 'matriculas';
--   SET app.rol_global = 'matriculas_global';     -- opcional
--   \i database/sql/postgres/02-rls.sql
--
-- La migracion de Laravel las pone sola, desde el `.env`.
--
-- Idempotente: se puede correr dos veces seguidas.
--
-- COMO SABE LA BASE DE QUE INSTITUCION ES LA PETICION: por la variable de
-- sesion `app.institucion_id`, que la aplicacion pone antes de consultar (ver
-- `App\Support\InstitucionActual::alConsultar()`). Sin ella puesta, la
-- comparacion da NULL y NO SALE NINGUNA FILA: ante la duda, nada. Es la misma
-- regla del paso 1, ahora sostenida por el motor.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- La institucion de la sesion: la UNICA definicion de la condicion. Es la
-- heredera de `InstitucionActual::filtrar()`, que queda vacia.
--
-- En SQL y STABLE para que el planificador la meta dentro de la consulta y
-- siga usando los indices que empiezan por `institucion_id`.
-- ----------------------------------------------------------------------------

CREATE OR REPLACE FUNCTION institucion_de_la_sesion() RETURNS bigint
LANGUAGE sql STABLE
AS $$
  SELECT nullif(current_setting('app.institucion_id', true), '')::bigint
$$;

-- ----------------------------------------------------------------------------
-- Permisos. La aplicacion solo lee y escribe filas: ni crea, ni altera, ni
-- vacia tablas. Y NO es la dueña: un dueño puede apagar RLS con un ALTER TABLE.
-- ----------------------------------------------------------------------------

DO $$
DECLARE
  v_app    text := nullif(current_setting('app.rol_aplicacion', true), '');
  v_global text := nullif(current_setting('app.rol_global', true), '');
  v_rol    record;
BEGIN
  IF v_app IS NULL THEN
    RAISE EXCEPTION 'Falta el rol de la aplicacion: SET app.rol_aplicacion = ''...'' antes de este guion.';
  END IF;

  SELECT rolsuper, rolbypassrls INTO v_rol FROM pg_roles WHERE rolname = v_app;

  IF NOT FOUND THEN
    RAISE EXCEPTION 'El rol de la aplicacion «%» no existe: corre antes 00-roles.sql.', v_app;
  END IF;

  -- Las tres formas de que RLS no le aplique. Cualquiera de ellas dejaria el
  -- aislamiento escrito y sin efecto, sin que nada fallara.
  IF v_app = current_user THEN
    RAISE EXCEPTION 'El rol de la aplicacion «%» es el dueño de las tablas: RLS no le aplicaria.', v_app;
  END IF;

  IF v_rol.rolsuper OR v_rol.rolbypassrls THEN
    RAISE EXCEPTION 'El rol de la aplicacion «%» es superusuario o tiene BYPASSRLS.', v_app;
  END IF;

  EXECUTE format('GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO %I', v_app);
  EXECUTE format('GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO %I', v_app);
  EXECUTE format('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO %I', v_app);
  EXECUTE format('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO %I', v_app);

  -- El rol global (estadisticas de todas las instituciones, paso 5): solo
  -- lectura. Si la instalacion no lo tiene, no pasa nada.
  IF v_global IS NOT NULL AND EXISTS (SELECT 1 FROM pg_roles WHERE rolname = v_global) THEN
    EXECUTE format('GRANT SELECT ON ALL TABLES IN SCHEMA public TO %I', v_global);
    EXECUTE format('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT ON TABLES TO %I', v_global);
  ELSE
    RAISE NOTICE 'Sin rol global: las estadisticas de todas las instituciones no tendran con que leer.';
  END IF;
END
$$;

-- ----------------------------------------------------------------------------
-- La politica, en cada tabla con `institucion_id`.
--
-- Se recorre el catalogo en vez de escribir la lista: una tabla de datos nueva
-- queda cubierta al volver a correr el guion, y si no se vuelve a correr,
-- `FiltroDeInstitucionUnicoTest` la caza.
--
-- `users` queda FUERA a proposito (decision del usuario, 01/10/2026): es la
-- identidad con la que se entra, y la institucion de la peticion sale de ella.
-- Con RLS, el login solo dejaria entrar a la institucion por defecto hasta que
-- llegue el enrutamiento por dominio. Entonces se le pone.
--
-- USING filtra lo que se lee, actualiza y borra; WITH CHECK impide escribir una
-- fila con la institucion de otra.
-- ----------------------------------------------------------------------------

DO $$
DECLARE
  v_tabla text;
BEGIN
  FOR v_tabla IN
    SELECT c.table_name
      FROM information_schema.columns c
      JOIN information_schema.tables t
        ON t.table_schema = c.table_schema AND t.table_name = c.table_name
     WHERE c.table_schema = current_schema()
       AND c.column_name = 'institucion_id'
       AND t.table_type = 'BASE TABLE'
       AND c.table_name <> 'users'
  LOOP
    EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', v_tabla);
    EXECUTE format('DROP POLICY IF EXISTS por_institucion ON %I', v_tabla);
    EXECUTE format(
      'CREATE POLICY por_institucion ON %I
         USING (institucion_id = institucion_de_la_sesion())
         WITH CHECK (institucion_id = institucion_de_la_sesion())',
      v_tabla
    );
  END LOOP;
END
$$;

-- ----------------------------------------------------------------------------
-- Los tres enlaces con token. Llegan sin sesion, y lo unico que necesitan es
-- saber DE QUE INSTITUCION es el token: con ese numero la aplicacion la adopta
-- (`InstitucionActual::adoptar()`) y sigue con RLS como cualquier otra pagina.
--
-- SECURITY DEFINER: corren como el dueño, asi que ven todas las filas. Por eso
-- devuelven SOLO el numero de institucion, nunca una fila, y fijan el
-- `search_path` (una funcion que corre con privilegios ajenos no puede fiarse
-- del de quien la llama). Solo puede llamarlas la aplicacion.
-- ----------------------------------------------------------------------------

CREATE OR REPLACE FUNCTION institucion_del_enlace_de_promotoria(p_token text) RETURNS bigint
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public
AS $$
  SELECT institucion_id FROM promotorias WHERE enlace_token = p_token
$$;

CREATE OR REPLACE FUNCTION institucion_del_enlace_de_actividad(p_token text) RETURNS bigint
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public
AS $$
  SELECT institucion_id FROM actividades WHERE token = p_token
$$;

-- Recibe la HUELLA del token (SHA-256), que es lo que se guarda: el token en
-- claro no llega nunca a la base (ver `App\Support\RestablecerClave`).
CREATE OR REPLACE FUNCTION institucion_del_restablecimiento(p_huella text) RETURNS bigint
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public
AS $$
  SELECT institucion_id FROM restablecimientos_clave WHERE token = p_huella
$$;

DO $$
DECLARE
  v_app  text := nullif(current_setting('app.rol_aplicacion', true), '');
  v_func text;
BEGIN
  FOREACH v_func IN ARRAY ARRAY[
    'institucion_del_enlace_de_promotoria(text)',
    'institucion_del_enlace_de_actividad(text)',
    'institucion_del_restablecimiento(text)'
  ] LOOP
    EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC', v_func);
    EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO %I', v_func, v_app);
  END LOOP;
END
$$;
