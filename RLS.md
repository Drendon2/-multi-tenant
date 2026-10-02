# Row Level Security

Paso 3 de la versión multi-institución (ver [`MULTI-INSTITUCION.md`](MULTI-INSTITUCION.md)).
Desde aquí **el aislamiento entre instituciones lo hace PostgreSQL**, no PHP.
Una consulta que se olvide del filtro, un `DB::table()` suelto o una
inyección de SQL ya no pueden ver ni escribir filas de otra institución.

---

## Contenido

1. [Cómo funciona](#cómo-funciona)
2. [Los tres roles](#los-tres-roles)
3. [Instalar y operar](#instalar-y-operar)
4. [Lo que queda fuera de RLS, y por qué](#lo-que-queda-fuera-de-rls-y-por-qué)
5. [Desarrollar con RLS](#desarrollar-con-rls)
6. [Cómo se comprobó](#cómo-se-comprobó)
7. [Archivos](#archivos)

---

## Cómo funciona

```
 petición ─► InstitucionActual::idSiSeSabe()      (token, cuenta o por defecto)
                   │
   antes de CADA consulta (DB::beforeExecuting), si cambió:
                   │
                   ▼
   set_config('app.institucion_id', '2')          ── la sesión de PostgreSQL lo sabe
                   │
                   ▼
   SELECT * FROM matriculas                        ── la consulta no dice nada
                   │
   política por_institucion:                       ── el motor añade
     institucion_id = institucion_de_la_sesion()
```

- **Cada tabla con `institucion_id` tiene RLS** (`users` desde el paso 4a,
  ver [`DOMINIOS.md`](DOMINIOS.md)) y la política
  `por_institucion`. `USING` filtra lo que se lee, actualiza y borra; `WITH
  CHECK` impide escribir una fila con la institución de otra.
- **La condición vive en una sola función SQL**, `institucion_de_la_sesion()`.
  Es la heredera de `InstitucionActual::filtrar()`, que queda vacía para estas
  tablas. Es `STABLE` y en SQL, así que el planificador la mete dentro de la
  consulta y sigue usando los índices que empiezan por `institucion_id`
  (medido: con y sin RLS, la ficha de una actividad entra por el mismo índice).
- **Ante la duda, nada.** Sin la variable puesta, la condición da `NULL` y no
  sale ninguna fila de ninguna tabla de datos.

### Cómo se entera la base

`InstitucionActual::alConsultar()`, registrado en `AppServiceProvider`, corre
**antes de cada consulta** y solo habla con la base cuando algo cambió: una
consulta más por petición. Va antes de cada consulta, y no una vez al empezar,
porque la institución cambia a mitad de camino: un enlace con token la adopta,
`instalar --nueva` trabaja como otra, una prueba cambia de cuenta.

Tres cosas que sostiene y que no se ven:

1. **No entra en bucle.** Averiguar la institución puede cargar la cuenta, y
   esa consulta vuelve a pasar por el gancho. Mientras se averigua no hace
   nada; esa consulta es a `users`, que no tiene RLS.
2. **No ensucia las cuentas de consultas.** La variable se pone directamente en
   el PDO, así que las pruebas que cuentan consultas siguen contando las de la
   aplicación.
3. **Una transacción deshecha vuelve atrás la variable** (en PostgreSQL los
   cambios de configuración son transaccionales). Lo recordado se olvida con el
   evento `TransactionRolledBack`. Sin eso, las consultas de después irían sin
   institución y no verían nada, sin fallar.

**Caso conocido:** si la conexión se cae y Laravel reconecta a mitad de una
consulta, la sesión nueva no tiene la variable y esa consulta no ve nada. Falla
cerrado: nunca enseña lo de otra institución.

---

## Los tres roles

| Rol | Qué hace | RLS |
|---|---|---|
| `matriculas_dueno` | Dueño de las tablas. Corre las migraciones y los guiones de `database/` (copiar, comparar, verificar), que trabajan con todas las instituciones a la vez. | No le aplica, por ser el dueño |
| `matriculas` | **La aplicación.** Solo `SELECT`, `INSERT`, `UPDATE` y `DELETE`: ni crea, ni altera, ni vacía tablas. | Le aplica |
| `matriculas_global` | Solo lectura de **todas** las instituciones, para las estadísticas globales (paso 5). Nada lo usa todavía. | `BYPASSRLS`, atributo del rol |

**Por qué la aplicación no es la dueña:** RLS no le aplica al dueño, y un dueño
puede apagarlo con un `ALTER TABLE`. Con la aplicación como dueña, una
inyección de SQL se llevaría el aislamiento por delante. Hay una prueba que
intenta apagarlo como la aplicación y comprueba que no puede.

**Por qué el global es un rol y no un hueco en la política:** una excepción
escrita en la política («si el rol es tal, ver todo») vive al lado de la regla
y se puede ampliar sin querer. Un atributo del rol solo lo da un superusuario.

---

## Instalar y operar

```bash
# 1. Los roles. UNA vez por servidor, como superusuario. Sin contraseñas:
psql -U postgres -f database/sql/postgres/00-roles.sql
psql -U postgres -c "\password matriculas_dueno"     # y los otros dos

# 2. La base, del dueño
psql -U postgres -c "CREATE DATABASE matriculas OWNER matriculas_dueno TEMPLATE template0 \
     LOCALE_PROVIDER icu ICU_LOCALE 'und' LOCALE 'C' ENCODING 'UTF8'"

# 3. .env: DB_USERNAME=matriculas (la aplicación) y DB_DUENO_* (el dueño)

# 4. El esquema, COMO EL DUEÑO
php artisan migrate --database=pgsql_dueno
php artisan instalar
```

**`php artisan migrate` a secas falla, y está bien que falle.** Corre como la
aplicación, que no puede crear tablas. El guion de RLS además se niega a correr
si el rol de la aplicación es el dueño, es superusuario o tiene `BYPASSRLS`:
cualquiera de las tres dejaría RLS escrito y sin efecto.

### Pasar una base existente (del paso 2) a RLS

```bash
# como superusuario
psql -U postgres -f database/sql/postgres/00-roles.sql
psql -U postgres -c "ALTER DATABASE matriculas OWNER TO matriculas_dueno"
psql -U postgres -d matriculas -c "REASSIGN OWNED BY matriculas TO matriculas_dueno"

php artisan migrate --database=pgsql_dueno
```

El guion `02-rls.sql` da los permisos, crea las políticas y las funciones. Es
idempotente, y su reversión también (subido y bajado dos veces, esquema
idéntico).

---

## Lo que queda fuera de RLS, y por qué

> **Desde el paso 4a `users` ya tiene RLS**: la institución la dice el dominio
> antes de entrar ([`DOMINIOS.md`](DOMINIOS.md)) y `SIN_RLS` quedó vacía. Lo
> que sigue sobre `users` explica cómo fue el paso 3.

**`users`** (decisión del usuario, 01/10/2026). Es la identidad con la que se
entra, y la institución de la petición sale de ella. Con RLS, el login solo
dejaría entrar a la institución por defecto hasta que llegue el enrutamiento por
dominio (paso 4). Mientras tanto:

- `InstitucionActual::SIN_RLS = ['users']`, y `filtrar()` **sigue filtrándola
  en PHP**, con el agrupado de los `orWhere`.
- Lo que la cuenta sin modelo va por `InstitucionActual::tabla('users')`. Sin
  ese filtro, `simular` borraría las cuentas de prueba de otras instituciones y
  Gestión → Institución contaría las cuentas sin correo de todas.
- La guardia comprueba que las tablas sin RLS son exactamente `SIN_RLS`.

**Las tablas del framework** (`sessions`, `cache`, `jobs`...) y
**`instituciones`**: no tienen `institucion_id`.

### Los enlaces con token

Tres páginas llegan sin sesión con un token y necesitan saber de qué
institución es: el enlace de una promotoría, el de una actividad y el de
restablecer la clave. Con RLS el token no se puede buscar en su tabla. Lo
resuelven tres funciones `SECURITY DEFINER`:

- `institucion_del_enlace_de_promotoria(token)`
- `institucion_del_enlace_de_actividad(token)`
- `institucion_del_restablecimiento(huella)`

Corren como el dueño y **devuelven solo el número de institución, nunca una
fila**. Llevan el `search_path` fijado y solo la aplicación puede llamarlas. Con
el número, `InstitucionActual::adoptar()`, y lo demás sigue con RLS como en
cualquier página. En PHP se llaman con `InstitucionActual::deEnlace()`.

`sinFiltroDeInstitucion()` ya no existe: RLS no se quita con un
`withoutGlobalScope`.

---

## Desarrollar con RLS

| Si añades… | Hace falta | Si no |
|---|---|---|
| Una tabla de datos | La columna `institucion_id` y volver a correr `02-rls.sql` (lo hace una migración nueva) | La guardia falla: tabla con institución y sin RLS |
| Una tabla de datos **sin** RLS | Añadirla a `InstitucionActual::SIN_RLS` y excluirla en `02-rls.sql`, sabiendo que solo la protege PHP | La guardia falla |
| Una migración | Correrla como el dueño: `--database=pgsql_dueno` | `permission denied` |
| Un enlace público con token | Una función `SECURITY DEFINER` en `02-rls.sql` que devuelva solo la institución, y `InstitucionActual::deEnlace()` + `adoptar()` (que da 404 si no es la del dominio) | La página no encuentra el token |
| Un guion que trabaje con todas las instituciones | En `database/`, como el dueño (`pgsql_dueno`) | Solo ve la institución por defecto |

**Lo que no se hace:**

- Usar la conexión `pgsql_dueno` desde `app/`. La guardia lo caza.

### En las pruebas

- **Las pruebas corren como la aplicación**, con RLS. Las migraciones de
  `RefreshDatabase` corren como el dueño (`Tests\TestCase::artisan()`).
- **Para mirar otra institución:** `InstitucionActual::mientras($id, fn () => …)`.
  La conexión del dueño no ve las filas de la transacción de la prueba.
- **`ANALYZE` como la aplicación no hace nada**: no es dueña de la tabla, y
  PostgreSQL se lo salta con un aviso. Una prueba que necesite estadísticas
  siembra y analiza como el dueño, con datos confirmados, y limpia al acabar
  (ver `IndicesActividadTest`).

---

## Cómo se comprobó

- **Suite completa, en verde, como el rol de la aplicación.** Las pruebas nuevas
  se vieron fallar:
  - sin políticas, 13 de las 19 de aislamiento;
  - sin el gancho, las 19;
  - con una tabla excluida del guion, o el rol de la aplicación con
    `BYPASSRLS`, la guardia (en el segundo caso, el propio guion se niega
    antes).
- **`verificacion_esquema.php`: 54 de 54.** Las seis garantías de RLS miran
  como la aplicación: sin institución no ve nada, desde una no ve la otra, no
  escribe en la otra y no puede apagar RLS.
- **La base con los datos reales**, ya con RLS:
  - la copia sigue coincidiendo con MariaDB en las 36 tablas;
  - como aplicación se ve solo la institución 1;
  - los tres informes de la institución 1 salen **idénticos byte a byte** a los
    de antes de RLS, y 14 de 16 pantallas también. Las otras dos tienen las
    mismas líneas en otro orden: empates en listas que no se recortan.
- **En el navegador:**
  - la institución 2 cuenta sus 2 cuentas sin correo, no las de toda la base;
  - el enlace de una actividad de la 1 abre sin sesión y da 404 con la sesión
    de la 2;
  - un token inventado da 404.

---

## Archivos

**Nuevos:** `database/sql/postgres/00-roles.sql`, `02-rls.sql`,
`02-rls.revertir.sql`, la migración `2026_10_03_100000_row_level_security.php`
y `RLS.md`.

**Modificados:**

- `App\Support\InstitucionActual`: el gancho, `SIN_RLS`, `deEnlace()`, y
  `filtrar()` vacía para las tablas con RLS.
- `AppServiceProvider`, que registra el gancho.
- `DeLaInstitucion`: sin `sinFiltroDeInstitucion()`.
- `Promotoria`, `InscripcionActividadController` y `RestablecerClave`, que
  resuelven sus tokens con `deEnlace()`. `RestablecerClave` escribe además con
  la institución de la cuenta.
- `config/database.php`, con la conexión `pgsql_dueno` y `rol_global`.
- `.env.example`, `.env.production.example`.
- Los guiones de `database/`, que trabajan como el dueño.
  `verificacion_esquema.php` tiene seis garantías nuevas.
- Pruebas: `TestCase` (migrar como el dueño), `AislamientoEntreInstitucionesTest`
  (seis pruebas nuevas), `FiltroDeInstitucionUnicoTest` (reescrita: vigila el
  esquema) e `IndicesActividadTest` (siembra como el dueño).
