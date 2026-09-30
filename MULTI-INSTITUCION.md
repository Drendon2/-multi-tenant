# Multi-institución · paso 1: `institucion_id` en MariaDB

Rama `multi-tenant`. Una sola base con varias instituciones, cada fila marcada
con la suya y el filtro en **un solo punto** del código, listo para
reemplazarlo por Row Level Security cuando se migre a PostgreSQL.

Fuera de este paso, a propósito: PostgreSQL, RLS, panel de administración de
instituciones, suplantación y enrutamiento por dominio.

## Cómo se aplica

Los guiones SQL de `database/sql/multi-tenant/` son la fuente de verdad. Cada
migración de Laravel (`database/migrations/2026_10_01_*`) corre **ese mismo
archivo**, así que el CI, las pruebas, `instalar` y el despliegue ejecutan
exactamente lo mismo que se corre a mano.

```
# Con Laravel (lo normal)
php artisan migrate
php artisan migrate:rollback --step=4

# A mano, en este orden (subir) y en el inverso (bajar)
mysql base < database/sql/multi-tenant/01-instituciones.sql
mysql base < database/sql/multi-tenant/02-columna-institucion.sql
mysql base < database/sql/multi-tenant/03-unicos-por-institucion.sql
mysql base < database/sql/multi-tenant/04-indices-por-institucion.sql
mysql base < database/sql/multi-tenant/04-indices-por-institucion.revertir.sql   # ... hasta 01
```

Todos son idempotentes. Las reversiones se niegan (SIGNAL) si hay más
instituciones que la 1: revertir con dos mezclaría sus datos.

**Ensayado sobre el volcado de producción del 11/09/2026** (1.038 perfiles,
1.351 matrículas), primero puesto al día con las migraciones de `main`:
subir dos veces, bajar dos veces, y el esquema y el contenido de las 32
tablas quedan idénticos a los de partida. Por los dos caminos: guiones a mano
y `migrate` / `migrate:rollback`, que además dejan el mismo esquema.

## Tablas

**Nueva:** `instituciones` (id, nombre, subdominio, estado, fecha_alta). La 1
existe siempre y toma el nombre de Gestión → Institución.

**Con `institucion_id`** (NOT NULL, FK a `instituciones`, **sin valor por
defecto**: una fila escrita sin decir de quién es falla, no cae en la 1). Son
28:

actividades, acudientes, areas, areas_dirigidas, asignaciones_grupo,
asistencias, asistencias_actividad, clases, configuracion_institucion,
confirmaciones_clase, cupos_promotoria, datos_estudiante,
documentos_estudiante, documentos_requeridos, encuestas_demograficas,
encuestas_satisfaccion, grupos, inscritos_actividad, instituciones_externas,
matriculas, omisiones_archivadas, perfiles, periodos, promotorias,
restablecimientos_clave, sesiones_actividad, sesiones_grupo, users.

**Sin ella** (del framework): migrations, cache, cache_locks, jobs,
job_batches, failed_jobs, sessions, password_reset_tokens. No hay catálogos
geográficos: los «departamentos» del sistema (`areas`) son los de cada casa de
la cultura y son datos de la institución.

**Renombrada:** `actividades.institucion_id` → `institucion_externa_id`. Era la
institución EXTERNA de un programa externo, y el nombre choca con la columna
nueva. Se rehicieron con el nombre nuevo su FK, su índice y el CHECK
`institucion_solo_en_programa_externo`.

## Restricciones únicas que cambiaron

| Tabla | Antes | Ahora |
|---|---|---|
| areas | `areas_nombre_unique` (nombre) | `areas_nombre_por_institucion` (institucion_id, nombre) |
| periodos | `periodos_nombre_unique` (nombre) | `periodos_nombre_por_institucion` (institucion_id, nombre) |
| periodos | `un_solo_periodo_activo` (activo_marca) | `un_periodo_activo_por_institucion` (institucion_id, activo_marca) |
| documentos_requeridos | `un_documento_por_nombre` (nombre) | `un_documento_por_nombre_e_institucion` (institucion_id, nombre) |
| datos_estudiante | `datos_estudiante_documento_identidad_unique` | `un_documento_de_identidad_por_institucion` (institucion_id, documento_identidad) |
| configuracion_institucion | (fila única con `id = 1` en el código) | `una_configuracion_por_institucion` (institucion_id) |

**No cambian, a propósito:**
- `users.username`: el login todavía no sabe de qué institución es quien entra.
  Pasa a ser único por institución cuando llegue el dominio.
- Los tokens (`perfiles.codigo_qr`, `promotorias.enlace_token`,
  `actividades.token`, `restablecimientos_clave.token`): son lo que le dice a
  un enlace público de qué institución es.
- Los que ya cuelgan de una fila de la institución (grupo por promotoría,
  matrícula por estudiante, etc.): los ids son globales.

## Índices creados

Compuestos que empiezan por `institucion_id`:

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

## El filtro: un solo punto

`App\Support\InstitucionActual::filtrar()` es el **único** `where
institucion_id` del código. Lo usan:

- el alcance global de los modelos (`App\Models\Concerns\DeLaInstitucion`, en
  los 24 modelos de datos y en los pivotes nuevos `AsignacionGrupo` y
  `AreaDirigida`), que además pone la institución al crear;
- `InstitucionActual::tabla()`, que sustituye a `DB::table()` en las pantallas
  que no hidratan modelos. Agrupa los `where` del que llama para que un
  `orWhere` no se salga del filtro;
- `Reglas::existe()` y `Reglas::unica()`, que sustituyen a los `exists`/`unique`
  de validación. Sueltos, aceptaban ids de otra institución.

Al pasar a RLS se vacía `filtrar()` y nada más.

**De qué institución es la petición**, por orden: la fijada (un enlace con
token la adopta de su fila; un comando la fija con `usar()`/`mientras()`), la
de la cuenta con sesión, y la de `INSTITUCION_POR_DEFECTO` (1 si no se dice)
para quien llega sin sesión. Sin ninguna, **lanza**: ante la duda no se
devuelve todo.

**`users` no lleva el filtro.** Es la identidad con la que se entra y de ella
sale la institución de la sesión; con el filtro, cada una pediría la otra. Se
llega a las personas por `Perfil`, que sí se filtra, y lo que cuenta cuentas
va por `tabla('users')`.

**La prueba `FiltroDeInstitucionUnicoTest` lo vigila.** Lee el código y el
esquema reales y falla si:
- una tabla de datos no tiene `institucion_id`, o no tiene un modelo filtrado;
- aparece un `DB::table`/`DB::select` en crudo, un `exists:`/`unique:` suelto,
  un `->from()` sin filtrar, un `where institucion_id` escrito a mano, un
  `withoutGlobalScopes()` o un `User::where()`.

Las tres excepciones están escritas en la prueba con su porqué. Además se
prueba contra trampas, para que un patrón mal escrito no la deje en verde para
siempre.

## Crear una segunda institución

```
php artisan instalar --nueva --subdominio=guarne
```

Pregunta lo mismo que la instalación de siempre (institución, documentos,
departamentos, periodo y dos administradores) y lo crea todo dentro de la
institución nueva, en una transacción. Sus administradores entran por el mismo
`/entrar`. Mientras no haya dominio, las páginas públicas sin token (login,
`/inscripcion`, política de datos) enseñan la institución por defecto.

## Archivos tocados

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
- Los 24 modelos de datos (`use DeLaInstitucion`); `User` (nace en la
  institución actual); `ConfiguracionInstitucion` (una fila por institución,
  memoria por institución); `Actividad`, `InstitucionExterna` (columna
  renombrada); `Grupo`, `Matricula`, `Perfil` (pivotes con modelo);
  `Promotoria` (enlace por token); `Matricula` (`withoutGlobalScopes` →
  `query`).
- Consultas sin modelo pasadas a `tabla()`: `Alertas`, `AsistenciaDeActividad`,
  `Companeros`, `EstadisticasDeProfesor`, `FichasIncompletas`,
  `HorarioDeLaCasa`, `HorarioSemanal`, `ResumenActividades`,
  `ResumenInstitucion`, `SupresionDeDatos`, `InformeController`,
  `RevisarDatos`, `Simular`, `ConfiguracionController`.
- Validación por `Reglas::existe()`/`unica()`: `Reglas` y los controladores
  `Inscripcion`, `Actividad`, `Area`, `Cancelaciones`, `Grupo`, `Matriculas`,
  `Periodo`, `ProgramaExterno`, `Promotoria`, `Usuario`, `MiPerfil` y
  `PanelGrupo`.
- Enlaces con token: `InscripcionActividadController`, `RestablecerClave`.
- `Permisos` (columna renombrada), `Instalar` (`--nueva`),
  `database/verificacion_esquema.php` (institución en cada `INSERT`, y ocho
  garantías nuevas).
- Pruebas que usaban la columna renombrada: `EnlaceDePromotoriaTest`,
  `ProgramaExternoTest`, `ResumenActividadesTest`.

## Cómo se comprobó que El Santuario funciona igual

- La suite completa, contra MariaDB, en verde. Incluye las pruebas nuevas de
  aislamiento y de la guardia, que se vieron fallar quitando cada arreglo (el
  filtro, el agrupado del `orWhere` y la adopción por token).
- `database/verificacion_esquema.php`: todas las garantías de antes más ocho
  nuevas. Se vieron fallar revirtiendo el guion 03.
- **Los tres informes CSV, generados como el administrador de El Santuario**:
  con el código de `main` sobre el volcado sin migrar, y con esta rama sobre
  el volcado migrado y con una segunda institución dada de alta.
  - El de la institución y el de actividades salen **idénticos byte a byte**.
  - El de estudiantes trae **las mismas filas**, con dos pares intercambiados.
    Ordena por departamento, promotoría y nombre, y ante un empate (la misma
    persona en dos grupos) SQL no fija el orden; los índices nuevos cambiaron
    el plan. Ya pasaba con cualquier índice. Si importa, se arregla añadiendo
    un desempate al `orderBy`, que no se tocó porque es lógica de la pantalla.
- En el navegador, el administrador de la institución de prueba solo ve lo
  suyo: sus dos cuentas, cifras a cero y 404 al abrir una promotoría de El
  Santuario por URL. Sin sesión, `/entrar` enseña El Santuario.

## Lo que queda abierto

- Una consulta de `tabla()` usada como SUBconsulta se compila sin pasar por
  el agrupado de los `where`, así que un `orWhere` de primer nivel ahí
  seguiría saliéndose del filtro. Hoy no hay ninguna; está escrito en
  `tabla()`.
- `instituciones.nombre` se pone al instalar y no sigue a un cambio de nombre
  hecho luego en Gestión → Institución, que es lo que se pinta en pantalla.
- Los archivos en disco (logo, firma, fotos, papeles) llevan nombres únicos y
  su ruta vive en la fila, así que no chocan entre instituciones. Pero siguen
  en la misma carpeta.
