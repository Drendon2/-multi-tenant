# Sistema de Matrículas · versión multi-institución

El sistema de matrículas de una casa de la cultura, preparado para servir a
**varias instituciones desde una sola instalación y una sola base de datos**.
Cada fila sabe de qué institución es, y el código filtra por ella en **un
único punto**, pensado para reemplazarlo por Row Level Security cuando la base
pase a PostgreSQL.

Esta versión vive en la rama `multi-tenant`. El sistema de una sola institución
que está en producción es la rama `main`, y **esta rama no se fusiona en ella**.

> **Estado: pasos 1, 2 y 3 de 5, terminados.** `institucion_id` en todas las
> tablas de datos (paso 1), el sistema entero sobre **PostgreSQL 18** (paso 2,
> [`POSTGRES.md`](POSTGRES.md)) y el aislamiento hecho por el motor con **Row
> Level Security** (paso 3, [`RLS.md`](RLS.md)). Ensayados sobre un volcado de
> producción. Ver [Hoja de ruta](#hoja-de-ruta).

Para lo que no cambia (qué resuelve el sistema, sus pantallas, el stack), el
[`README.md`](README.md) sigue valiendo. Este documento cuenta solo lo que
añade esta versión.

---

## Contenido

1. [La idea en una página](#la-idea-en-una-página)
2. [Instalar y operar](#instalar-y-operar)
3. [El esquema](#el-esquema)
4. [El filtro: un solo punto](#el-filtro-un-solo-punto)
5. [Desarrollar sobre esta versión](#desarrollar-sobre-esta-versión)
6. [Cómo se comprobó](#cómo-se-comprobó)
7. [Hoja de ruta](#hoja-de-ruta)
8. [Archivos de esta versión](#archivos-de-esta-versión)

---

## La idea en una página

```
                 petición
                    │
        ¿de qué institución es?  ──  InstitucionActual::id()
                    │                  1. la fijada: en la web, la del HOST
                    │                     (paso 4a, DOMINIOS.md); un comando,
                    │                     usar() o mientras()
                    │                  2. la de la cuenta (solo pruebas)
                    │                  3. INSTITUCION_POR_DEFECTO (consola)
                    │                  4. ninguna → error (nunca «todas»)
                    ▼
     ┌──────────── InstitucionActual::filtrar() ─────────────┐
     │  el ÚNICO  where institucion_id = ?  de todo el código  │
     └────────────────────────────────────────────────────────┘
        ▲                  ▲                       ▲
   modelos Eloquent   consultas sin modelo    validación
   (DeLaInstitucion)  (InstitucionActual::    (Reglas::existe()
                       tabla())                 Reglas::unica())
```

- **Una base, una columna.** Las 28 tablas de datos llevan `institucion_id`:
  NOT NULL, con clave foránea a `instituciones` y **sin valor por defecto**.
  Una fila escrita sin decir de quién es falla; no cae en silencio en ninguna
  institución.
- **Un solo punto de filtrado.** Todo camino hacia la base pasa por
  `filtrar()`. Cuando llegue RLS, el motor hará ese trabajo y `filtrar()` se
  vacía. Una prueba vigila que nadie lo rodee.
- **Ante la duda, nada.** Si no se sabe de qué institución es una petición, el
  sistema se niega a consultar en vez de devolverlo todo.

---

## Instalar y operar

### Una instalación nueva

```bash
composer install
cp .env.example .env          # completar la base de datos
php artisan key:generate
php artisan migrate
php artisan instalar          # monta la institución 1
```

La institución 1 existe siempre, desde la primera migración. `instalar` le pone
el nombre y monta su catálogo mínimo, igual que en la versión de una sola
institución.

### Dar de alta otra institución

```bash
php artisan instalar --nueva
php artisan instalar --nueva --subdominio=guarne
```

Pregunta lo mismo que la instalación de siempre (institución, documentos,
departamentos, periodo en curso y dos administradores) y lo crea todo dentro de
la institución nueva, **en una sola transacción**: si algo falla, no queda una
institución vacía. Para desarrollo, `--nueva --ejemplo` monta una de juguete
sin preguntar (se niega en producción).

Los administradores de la institución nueva entran por el mismo `/entrar`, y
desde ese momento solo ven lo suyo.

### Pasar una instalación existente a esta versión

```bash
php artisan migrate
```

Las cuatro migraciones `2026_10_01_*` crean `instituciones`, registran la
existente como la número 1 con el nombre que tenga en Gestión → Institución, y
marcan todas sus filas con ella. **Ensáyalo antes sobre un volcado** de esa
base (ver [Cómo se comprobó](#cómo-se-comprobó)).

Para volver atrás:

```bash
php artisan migrate:rollback --step=4
```

Se niega si ya hay más de una institución, porque revertir mezclaría sus datos.

### Configuración

| Variable | Qué hace | Si falta |
|---|---|---|
| `DOMINIO_BASE` | Con varias instituciones, la de cada petición la dice el host: `<subdominio>.<DOMINIO_BASE>` o el dominio propio. Ver [`DOMINIOS.md`](DOMINIOS.md). | Una sola casa: todo es de `INSTITUCION_POR_DEFECTO` |
| `INSTITUCION_POR_DEFECTO` | Solo sin `DOMINIO_BASE` (la institución de todas las peticiones) y en la consola. | `1` |

Vacías las dos, una petición no tiene institución y el sistema se niega a
consultar.

### Lo que cada institución ve y lo que comparte

| Por institución | Compartido por todas |
|---|---|
| Todo el catálogo, las personas, las matrículas, la asistencia, las encuestas, los informes y las estadísticas | La instalación, el código y la base |
| La marca: nombre, logo, color, política de datos, correo SMTP | Los archivos subidos, en la misma carpeta con nombres únicos |
| Su periodo en curso y su ventana de matrículas | |
| Sus cuentas: desde el paso 4a, `username` es único **por institución** | |

**Desde el paso 4a** ([`DOMINIOS.md`](DOMINIOS.md)) cada institución se visita
por su dominio: sus páginas públicas, su login y sus enlaces con token. Un
token abierto en el dominio de otra da 404.

---

## El esquema

### Cómo se aplica

> **Desde el paso a PostgreSQL**, el esquema entero, con todo lo de esta
> sección, está en `database/sql/postgres/01-esquema.sql` (ver
> [`POSTGRES.md`](POSTGRES.md)). Los cuatro guiones de abajo son los de
> MariaDB: viven en `database/historico-mariadb/sql-multi-tenant/` y ya no se
> ejecutan. Se dejan descritos porque cuentan cómo se llegó a este esquema
> desde el de una sola institución.

Los guiones SQL de `database/sql/multi-tenant/` eran la fuente de verdad. Cada
migración de Laravel corría **ese mismo archivo** (con
`App\Support\GuionSql`), así que el CI, las pruebas, `instalar` y cualquier
despliegue ejecutaban exactamente lo mismo que se corría a mano:

```bash
# Subir, en este orden
mysql base < database/sql/multi-tenant/01-instituciones.sql
mysql base < database/sql/multi-tenant/02-columna-institucion.sql
mysql base < database/sql/multi-tenant/03-unicos-por-institucion.sql
mysql base < database/sql/multi-tenant/04-indices-por-institucion.sql

# Bajar, en el orden inverso
mysql base < database/sql/multi-tenant/04-indices-por-institucion.revertir.sql
mysql base < database/sql/multi-tenant/03-unicos-por-institucion.revertir.sql
mysql base < database/sql/multi-tenant/02-columna-institucion.revertir.sql
mysql base < database/sql/multi-tenant/01-instituciones.revertir.sql
```

**Los ocho son idempotentes**: se pueden correr dos veces seguidas, o de nuevo
tras un fallo a medias, y terminan en el mismo estado. Piden MariaDB 10.5 o
superior.

### Tablas

**Nueva:** `instituciones`.

| Columna | |
|---|---|
| `id` | La 1 existe siempre. |
| `nombre` | Toma el de Gestión → Institución al migrar y al instalar. |
| `subdominio` | Único. Desde el paso 4a, `<subdominio>.<DOMINIO_BASE>` es esta institución. |
| `dominio_propio` | Paso 4a. Único y opcional: el dominio de la entidad que trae el suyo. |
| `estado` | `activa` o `suspendida`. Desde el paso 4a, suspendida no atiende. |
| `fecha_alta` | Para la 1, la de la cuenta más antigua. |

**Con `institucion_id`** (28 tablas):

actividades, acudientes, areas, areas_dirigidas, asignaciones_grupo,
asistencias, asistencias_actividad, clases, configuracion_institucion,
confirmaciones_clase, cupos_promotoria, datos_estudiante,
documentos_estudiante, documentos_requeridos, encuestas_demograficas,
encuestas_satisfaccion, grupos, inscritos_actividad, instituciones_externas,
matriculas, omisiones_archivadas, perfiles, periodos, promotorias,
restablecimientos_clave, sesiones_actividad, sesiones_grupo, users.

- También la llevan las tablas hijas, que ya quedarían aisladas por su padre:
  RLS la necesitará en cada una.
- **Sin ella**, las 8 del framework: migrations, cache, cache_locks, jobs,
  job_batches, failed_jobs, sessions, password_reset_tokens.
- No hay catálogos geográficos compartidos: los «departamentos» del sistema
  (`areas`) son los de cada casa de la cultura.

**Renombrada:** `actividades.institucion_id` → `institucion_externa_id`. Es la
institución EXTERNA donde se dicta un programa externo, y el nombre chocaba con
la columna nueva. Con ella cambiaron su FK, su índice y el CHECK
`institucion_solo_en_programa_externo`.

### Restricciones únicas

| Tabla | Antes | Ahora |
|---|---|---|
| areas | `areas_nombre_unique` (nombre) | `areas_nombre_por_institucion` (institucion_id, nombre) |
| periodos | `periodos_nombre_unique` (nombre) | `periodos_nombre_por_institucion` (institucion_id, nombre) |
| periodos | `un_solo_periodo_activo` (activo_marca) | `un_periodo_activo_por_institucion` (institucion_id, activo_marca) |
| documentos_requeridos | `un_documento_por_nombre` (nombre) | `un_documento_por_nombre_e_institucion` (institucion_id, nombre) |
| datos_estudiante | `datos_estudiante_documento_identidad_unique` | `un_documento_de_identidad_por_institucion` (institucion_id, documento_identidad) |
| configuracion_institucion | fila única con `id = 1` en el código | `una_configuracion_por_institucion` (institucion_id) |

En cada una se crea la nueva antes de borrar la vieja: en ningún momento la
tabla se queda sin la garantía.

**No cambian, a propósito:**

- **`users.username`**: el login todavía no sabía de qué institución era quien
  entraba. Pasó a ser único por institución en el paso 4a
  (`users_institucion_username_unique`, ver [`DOMINIOS.md`](DOMINIOS.md)).
- **Los tokens** (`perfiles.codigo_qr`, `promotorias.enlace_token`,
  `actividades.token`, `restablecimientos_clave.token`): son lo que le dice a
  un enlace público de qué institución es.
- **Las que ya cuelgan de una fila de la institución** (grupo por promotoría,
  matrícula por estudiante…): los ids son globales.
- **El trigger de cupo de `matriculas`**: cuenta por `promotoria_id` y
  `periodo_id`, que ya son de una sola institución.

### Índices

Compuestos que empiezan por `institucion_id`, en las tablas que más se
consultan:

| Tabla | Índice |
|---|---|
| perfiles | `perfiles_institucion_rol` (institucion_id, rol) |
| perfiles | `perfiles_institucion_nombre` (institucion_id, nombre_completo) |
| matriculas | `matriculas_institucion_periodo_estado` (institucion_id, periodo_id, estado) |
| promotorias | `promotorias_institucion_nombre` (institucion_id, nombre) |
| grupos | `grupos_institucion_nombre` (institucion_id, nombre) |
| clases | `clases_institucion_periodo_fecha` (institucion_id, periodo_id, fecha_hora) |
| actividades | `actividades_institucion_tipo_nombre` (institucion_id, tipo, nombre) |
| encuestas_satisfaccion | `encuestas_satisfaccion_institucion_periodo` (institucion_id, periodo_id) |
| documentos_requeridos | `documentos_requeridos_institucion_orden` (institucion_id, orden, nombre) |

Las demás tablas tienen el índice que crea su FK (`fk_<tabla>_institucion`).
Donde un compuesto ya empieza por `institucion_id`, ese índice suelto sobra y
se quita.

---

## El filtro: un solo punto

> **Desde el paso 3 esto lo hace el motor** (ver [`RLS.md`](RLS.md)):
> `filtrar()` queda vacía para las tablas con RLS y solo sigue filtrando en PHP
> las de `InstitucionActual::SIN_RLS` (vacía desde el paso 4a, en que `users`
> recibió RLS). La guardia ya no lee el
> código buscando consultas que rodeen el filtro: lee el esquema. Lo que sigue
> describe el paso 1.

`App\Support\InstitucionActual::filtrar()` es el **único** `where
institucion_id` del código. Lo llaman tres caminos:

| Camino | Para qué | Dónde |
|---|---|---|
| Alcance global | Todos los modelos de datos. También pone la institución al crear. | `App\Models\Concerns\DeLaInstitucion` |
| `InstitucionActual::tabla('x')` | Consultas sin modelo, en lugar de `DB::table('x')`. Agrupa los `where` del que llama para que un `orWhere` no se salga del filtro. | `App\Support\InstitucionActual` |
| `Reglas::existe()` / `Reglas::unica()` | Validación que va a la base. Un `exists:` suelto aceptaba ids de otra institución. | `App\Support\Reglas` |

### De qué institución es una petición

1. **La fijada.** Un enlace con token la toma de su fila (`adoptar()`), y da
   404 si hay una sesión abierta de otra institución. Un comando la fija con
   `usar()` o `mientras()`.
2. **La de la cuenta con sesión.**
3. **`INSTITUCION_POR_DEFECTO`.**
4. **Ninguna: error.** Nunca «todas».

### `users` no lleva el filtro

> **Ya no es así desde el paso 4a:** la institución la dice el dominio, `users`
> tiene RLS y `User` usa `DeLaInstitucion`. Ver [`DOMINIOS.md`](DOMINIOS.md).

Es la identidad con la que se entra, y la institución de la sesión sale de
ella. Con el filtro, resolver la cuenta pediría la institución y la institución
pediría la cuenta. Por eso:

- se llega a las personas por `Perfil`, que sí se filtra;
- lo que cuenta cuentas va por `InstitucionActual::tabla('users')`;
- `username` sigue siendo único en toda la base.

### La prueba que lo vigila

`tests/Feature/FiltroDeInstitucionUnicoTest.php` lee el código y el esquema
**reales**, no una lista escrita a mano, y falla si:

- una tabla de datos no tiene `institucion_id`, o no tiene un modelo filtrado;
- aparece un `DB::table`/`DB::select` en crudo, un `exists:`/`unique:` suelto,
  un `->from()` sin filtrar, un `where institucion_id` escrito a mano, un
  `withoutGlobalScopes()` o un `User::where()`.

Las tres excepciones están escritas en la prueba con su porqué. Además se
prueba contra trampas, para que un patrón mal escrito no la deje en verde para
siempre.

---

## Desarrollar sobre esta versión

### Al añadir algo, sin romper el aislamiento

| Si añades… | Hace falta | Si no |
|---|---|---|
| Una tabla de datos | La columna `institucion_id` en un guion SQL nuevo y un modelo con `use DeLaInstitucion` | La prueba-guardia falla |
| Un `upsert()`, un `insert()` en crudo | `InstitucionActual::COLUMNA => InstitucionActual::id()` en cada fila: no disparan eventos | La base rechaza la fila |
| Un `belongsToMany` | `->using(Pivote::class)` con el pivote usando el trait | `sync()` inserta sin institución y la base lo rechaza |
| Una regla `exists`/`unique` | `Reglas::existe()` / `Reglas::unica()` | Acepta ids de otra institución (la guardia lo caza) |
| Una subconsulta `->from('tabla')` | Envolverla en `InstitucionActual::filtrar(...)` | La guardia lo caza |
| Un enlace público con token | Buscar con `sinFiltroDeInstitucion()` y llamar a `InstitucionActual::adoptar()` | La página sale con la marca de otra institución |

**Lo que no se hace:**

- `withoutGlobalScopes()` sin argumentos: se lleva también el filtro.
- `User::where(...)` suelto: devuelve cuentas de todas.
- Escribir tu propio `where('institucion_id', …)`.

### Cambios de esquema

Van como guiones SQL en `database/sql/<tema>/NN-*.sql`, con su
`NN-*.revertir.sql`. La migración de Laravel solo llama a `GuionSql::correr()`.

- **Idempotentes los dos lados.** Con `IF [NOT] EXISTS`, `CREATE OR REPLACE`
  y, donde no alcanza, bloques `DO $$ … $$`, que `GuionSql` corta igual que
  `psql`.
- **Toda columna de texto nueva lleva `COLLATE insensible`.** Sin él, la
  columna distingue mayúsculas y tildes, que es lo contrario de lo que el
  sistema espera (ver [`POSTGRES.md`](POSTGRES.md#el-cotejo)).
- **Ensayados sobre un volcado limpio**, dos veces arriba y dos abajo,
  comparando esquema y contenido.
- **Una columna nueva pide su `@property` en el modelo.** Larastan deduce las
  columnas de las migraciones de Laravel y no lee el SQL.

### Pruebas

Contra PostgreSQL, con una base de pruebas propia que `phpunit.xml` ya fija:

```bash
php vendor/bin/phpunit
```

La verificación del esquema tiene ocho garantías nuevas: fila sin institución
rechazada, nombre repetido entre instituciones pero no dentro de una, un
periodo en curso por institución, una configuración por institución, y no se
borra una institución con datos. Vacía la base a la que apunte el `.env`: se
corre contra una desechable.

```bash
DB_DATABASE=test_matriculas_mt php database/verificacion_esquema.php --borrar-datos
```

---

## Cómo se comprobó

- **Guiones sobre un volcado de producción** (1.038 perfiles, 1.351
  matrículas), primero puesto al día con las migraciones de `main`. Se subió
  dos veces y se bajó dos veces, y el esquema y el contenido de las 32 tablas
  quedaron idénticos a los de partida. Por los dos caminos (guiones a mano y
  `migrate` / `migrate:rollback`), que además dejan el mismo esquema. El
  contenido se comparó con `SELECT *` y md5, no con `CHECKSUM TABLE`, que en
  tablas con columnas virtuales cambia al reconstruirse con las mismas filas.
- **La suite completa, en verde**, con las pruebas de aislamiento y la
  guardia. Cada una se vio fallar quitando su arreglo: el filtro, el agrupado
  del `orWhere`, la adopción por token y los únicos del guion 03.
- **La institución que ya existía funciona igual.** Se generaron los tres
  informes CSV como su administrador, con el código de `main` sobre el volcado
  sin migrar y con esta versión sobre el volcado migrado y con una segunda
  institución dada de alta.
  - El de la institución y el de actividades salen **idénticos byte a byte**.
  - El de estudiantes trae **las mismas filas**, con dos pares en otro orden.
    Ordena por departamento, promotoría y nombre; ante un empate (la misma
    persona en dos grupos) SQL no fija el orden, y los índices nuevos
    cambiaron el plan.
- **En el navegador**, el administrador de la institución de prueba solo ve lo
  suyo: sus cuentas, las cifras a cero y 404 al abrir por URL una fila de la
  otra. Sin sesión se ve la institución por defecto.

---

## Hoja de ruta

| Paso | Qué | Estado |
|---|---|---|
| 1 | `institucion_id` en MariaDB y el filtro en un solo punto | **Hecho** |
| 2 | PostgreSQL ([`POSTGRES.md`](POSTGRES.md)) | **Hecho** |
| 3 | Row Level Security ([`RLS.md`](RLS.md)), con el rol global ya previsto para las estadísticas del paso 5 | **Hecho** |
| 4a | La institución la dice el dominio: `username` único por institución, `users` con RLS, las páginas públicas dejan de caer en la institución por defecto ([`DOMINIOS.md`](DOMINIOS.md)) | **Hecho** |
| 4b | Panel de administración de todas las instituciones: dominios y estado ([`PANEL.md`](PANEL.md)) | **Hecho** |
| 4c | Entrar como un administrador desde el panel ([`PANEL.md`](PANEL.md#entrar-como-administrador-paso-4c)) | **Hecho** |
| 5 | Pruebas con varias instituciones y estadísticas globales de todas | Pendiente |

**Abierto y sin decidir:**

- Una consulta de `tabla()` usada como SUBconsulta se compila sin pasar por
  el agrupado de los `where`, así que un `orWhere` de primer nivel ahí se
  saldría del filtro. Hoy no hay ninguna; está advertido en `tabla()`.
- `instituciones.nombre` se pone al instalar y no sigue a un cambio de nombre
  hecho luego en Gestión → Institución, que es el que se pinta en pantalla.
- Separar esta versión en un repositorio propio y privado, revisando el CI
  heredado, que despliega a producción.

---

## Archivos de esta versión

**Nuevos**

- `database/sql/multi-tenant/*.sql` (4 de subida y 4 de reversión)
- `database/migrations/2026_10_01_100000_crear_la_tabla_de_instituciones.php`,
  `…110000_institucion_id_en_las_tablas_de_datos.php`,
  `…120000_restricciones_unicas_por_institucion.php`,
  `…130000_indices_por_institucion.php`
- `app/Support/InstitucionActual.php`, `app/Support/GuionSql.php`
- `app/Models/Concerns/DeLaInstitucion.php`, `app/Models/Institucion.php`,
  `app/Models/AsignacionGrupo.php`, `app/Models/AreaDirigida.php`
- `config/institucion.php`
- `tests/Feature/AislamientoEntreInstitucionesTest.php`,
  `tests/Feature/FiltroDeInstitucionUnicoTest.php`

**Modificados**

- **Modelos:**
  - los 24 de datos (`use DeLaInstitucion`);
  - `User` (nace en la institución actual);
  - `ConfiguracionInstitucion` (una fila por institución, memoria por
    institución);
  - `Actividad` e `InstitucionExterna` (columna renombrada);
  - `Grupo`, `Matricula` y `Perfil` (pivotes con modelo);
  - `Promotoria` (enlace por token);
  - `Matricula` (`withoutGlobalScopes` → `query`).
- **Consultas sin modelo pasadas a `tabla()`:** `Alertas`,
  `AsistenciaDeActividad`, `Companeros`, `EstadisticasDeProfesor`,
  `FichasIncompletas`, `HorarioDeLaCasa`, `HorarioSemanal`,
  `ResumenActividades`, `ResumenInstitucion`, `SupresionDeDatos`,
  `InformeController`, `RevisarDatos`, `Simular` y `ConfiguracionController`.
- **Escrituras con `upsert()`:** `PaseDeLista` y `CuposController`.
- **Validación por `Reglas::existe()`/`unica()`:** `Reglas` y los
  controladores `Inscripcion`, `Actividad`, `Area`, `Cancelaciones`, `Grupo`,
  `Matriculas`, `Periodo`, `ProgramaExterno`, `Promotoria`, `Usuario`,
  `MiPerfil` y `PanelGrupo`.
- **Enlaces con token:** `InscripcionActividadController` y `RestablecerClave`.
- **Otros:** `Permisos` (columna renombrada), `Instalar` (`--nueva`),
  `database/verificacion_esquema.php` (institución en cada `INSERT` y ocho
  garantías nuevas), `.env.example` y `.env.production.example`
  (`INSTITUCION_POR_DEFECTO`).
- **Pruebas adaptadas:** `EnlaceDePromotoriaTest`, `ProgramaExternoTest` y
  `ResumenActividadesTest` (columna renombrada); `ConfiguracionMemorizadaTest`
  (la configuración ya no es la fila `id = 1`); `IndicesActividadTest`
  (inserción en crudo con su institución).
