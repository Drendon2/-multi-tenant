# El panel de todas las instituciones

Pasos 4b y 4c de la versión multi-institución (ver
[`MULTI-INSTITUCION.md`](MULTI-INSTITUCION.md)). Es la pantalla de quien
**presta el servicio**: desde aquí se le pone la dirección a cada institución,
se la suspende o reactiva, y se entra a ella como uno de sus administradores
para darle soporte. Las decisiones son del usuario, del 02/10/2026.

---

## Qué hace y qué no

| Hace | No hace (y dónde vive) |
|---|---|
| Lista todas las instituciones con su dirección, estado y fecha de alta | Crear una institución: `php artisan instalar --nueva`, que monta catálogo, periodo y dos administradores |
| Edita el **subdominio** y el **dominio propio** | Las cifras de cada una (personas, matrículas): paso 5, con el rol global |
| **Suspende** y **reactiva** | |
| **Entra como uno de sus administradores** (paso 4c, ver abajo) | |
| | Crear operadores: `php artisan operador:crear`, por consola |

---

## Dónde vive

- **En `panel.<DOMINIO_BASE>`** y en ningún otro host. Sin `DOMINIO_BASE` (una
  sola casa) no hay panel.
- **Sus rutas cuelgan de `/instituciones`** (`routes/operador.php`, nombres
  `operador.*`). No de `/panel`: esa es la pantalla del profesor, y dos rutas con
  la misma dirección se pisan aunque vivan en hosts distintos.
- **Su propio grupo de middleware, `operador`**: el de `web` sin
  `InstitucionPorDominio`, `AuthenticateSession` ni `CuentaActiva`, y con
  `SoloEnElPanel` el primero, que da 404 fuera de su host.
- En el host del panel, lo que no es del panel (`/`, `/entrar`) lleva a su
  portada. En el host de una institución, `/instituciones` es un 404.
- Los subdominios **`panel` y `www` están reservados** en la base
  (`subdominio_no_reservado`) y en el formulario.

### Una petición del panel no es de ninguna institución

`SoloEnElPanel` llama a `InstitucionActual::ninguna()`: ni la cuenta ni la
institución por defecto cuentan, `id()` lanza y la base no recibe institución,
así que **una tabla de datos, desde el panel, no devuelve ninguna fila**. Lo que
el panel lee (`instituciones`, `operadores`) no tiene RLS. Por lo mismo, el
compositor que pone `$configuracion` en todas las vistas no hace nada aquí, y
el panel tiene su propio envoltorio (`layouts.operador`) sin marca.

`ninguna()` y `usar()` se excluyen: una petición es de una institución o del
panel. Sin eso, en las pruebas —que reutilizan el contenedor entre peticiones—
la marca del panel sobrevivía a la petición siguiente.

---

## Los operadores

- **Tabla `operadores`, sin `institucion_id` y sin RLS**, con su guard
  (`operador`). No son un rol dentro de la institución 1: así un administrador
  de una casa no puede ascender nunca a operador de todas. Un administrador de
  una institución no entra al panel, ni siquiera con su clave.
- **Se crean por consola**, nunca desde una pantalla:

  ```bash
  php artisan operador:crear soporte --nombre="Soporte"   # crea, o cambia la clave
  php artisan operador:crear soporte --desactivar          # lo apaga y lo echa
  ```

  La contraseña pide **12 caracteres** como mínimo (las cuentas de una
  institución, 8): esta abre todas.
- Desactivar a un operador **echa también a quien ya está dentro**
  (`OperadorActivo`).
- El login tiene **los mismos dos topes** que el de las instituciones (por
  usuario e IP, y por cuenta venga de donde venga), con su propio contador
  (`operador-entrar`).
- La entrada y cada cambio de una institución quedan en el canal de
  **auditoría** (`operador.entrada`, `operador.institucion`, con el id del
  operador y lo que cambió).

---

## Editar una institución

- **Subdominio y dominio propio se guardan en minúsculas y sin espacios**, que
  es como llega el host. Los dos se validan con la misma expresión que los
  CHECK de la base (`03-dominios.sql`), así que un valor raro es un aviso en el
  campo y no un 500.
- **Hace falta uno de los dos**: sin ninguno, nadie podría llegar a la
  institución.
- **Un dominio propio no puede colgar del dominio base**: eso ya es un
  subdominio y chocaría con el de otra.
- **Suspendida**, todas sus pantallas dicen «Servicio suspendido», también a
  quien ya había entrado (ver [`DOMINIOS.md`](DOMINIOS.md)). No se borra nada.

---

## Resumen general y descargas consolidadas (paso 5)

`/instituciones/resumen`, en el menú del panel. Lo que entra lo decidió el
usuario el 02/10/2026: **población impactada, promotorías, datos demográficos y
profesores por promotoría, y nada de asistencia a clase**. Y, «muy importante»,
poder descargar todo consolidado.

- **Una cinta** con los totales (instituciones, población impactada,
  estudiantes activos, promotorías, profesores) y **una fila por institución**
  con su periodo en curso y la fecha de su última matrícula, que dice si esa
  casa se está usando.
- **La encuesta demográfica de todas, sumada** opción a opción, con las mismas
  gráficas que Estadísticas. Solo cifras: ningún nombre.
- **Los profesores por promotoría** de cada institución, plegados por casa.

**Cómo se calcula (decisión del usuario):** institución por institución, con
`InstitucionActual::mientras()`, bajo RLS y con las MISMAS clases que pintan la
cinta de Gestión (`ResumenInstitucion`) y la encuesta de Estadísticas
(`ResumenDemografico`, que salió del controlador para esto). No con el rol
`matriculas_global`: obligaba a reescribir esas cuentas en SQL propio, y una
cifra calculada en dos sitios acaba diciendo dos cosas. Ese rol se queda
creado, de reserva. Medido: 13 consultas y ~60 ms por institución.

**Cada institución cuenta con su propio periodo en curso**, y los totales
suman: una persona inscrita en dos municipios cuenta dos veces, porque el
documento es único dentro de cada institución y no entre ellas. La pantalla lo
dice.

### Las cinco descargas

Todas con el nombre de la institución en la primera columna. Cada descarga
queda en la auditoría (`operador.descarga`) con el operador.

| Archivo | Qué trae |
|---|---|
| Resumen por institución | Las cifras de cada una, con dirección y estado, y una fila «Todas». |
| Promotorías y profesores | Cada promotoría con su departamento, profesor, teléfono y correo, inscritos del periodo y cupo. |
| Datos demográficos, contados | Formato largo: institución, pregunta, respuesta, personas. Con «Todas». Sin nombres. |
| Informe completo de personas | **Confidencial.** El informe de la institución de Gestión, de todas. |
| Cursos y actividades sin matrícula | **Confidencial.** El de Gestión, de todas. |

- **Los dos confidenciales son el MISMO informe que baja cada institución**:
  sus filas salieron del controlador a `App\Support\InformeInstitucion` e
  `InformeActividades`, que usan las dos descargas. Escrito dos veces, se
  separarían sin que nada fallara.
- **La única diferencia son los papeles.** Cada institución pide los suyos, así
  que en su informe va una columna por papel («Entregó: …») y en el
  consolidado van juntos en una sola, «Papeles entregados» («Cédula: Sí ·
  Foto: No»).
- **En el de actividades, «Institución» era la externa** donde se dicta un
  programa. En el consolidado esas columnas pasan a «Institución externa», para
  no repetir la cabecera de la primera.
- **Las descargas van saliendo fila a fila**, y la institución se fija con
  `InstitucionActual::recorriendo()`, que es `mientras()` para un generador: con
  `mientras()` se soltaría antes de que corriera la primera consulta.
- Medido sobre la copia local: el informe completo de personas, 1280 filas y
  421 KB, en 1,7 s.

## Entrar como administrador (paso 4c)

En la ficha de cada institución, el panel lista sus administradores activos
con un botón **«Entrar como»**. Decisión del usuario: solo administradores;
desde esa cuenta, si hace falta, la gestión asistida lleva a un profesor,
director o estudiante, con sus propios cortes.

```
 panel.<base>                                   guarne.<base>
 «Entrar como Ana» ──► token de un solo uso ──► /suplantacion/{token}
                       (60 s, en SHA-256,        canjea: Auth::login(Ana)
                        en `suplantaciones`)     + marca en la sesión
```

- **Por qué un token y no la sesión del panel:** la cookie es de su host, y el
  panel no puede abrir una sesión en el de la institución. El panel deja el
  token y redirige; la institución lo canjea y abre una sesión de verdad con
  `Auth::login`, como la gestión asistida.
- **La fila tiene `institucion_id` y RLS**: el token solo se encuentra en el
  dominio de SU institución. En el de otra no existe, y no se gasta.
- **Un solo uso y 60 segundos.** Gastarlo es un `UPDATE … WHERE usado_en IS
  NULL … RETURNING`, así que dos pestañas que lo abren a la vez no entran las
  dos. Si la cuenta dejó de ser administradora o se desactivó en ese minuto, no
  se entra.
- **La comprobación vive en `App\Support\Suplantacion`**, no en el
  controlador: es la función que abre la puerta.
- **Mientras dura, una barra lo dice en todas las pantallas** («Desde el panel
  de instituciones»), con el nombre del operador y un botón **«Volver al
  panel»** que cierra la sesión de la institución y lleva al panel. Si además
  se abre una gestión asistida, salen las dos barras.
- **La contraseña de la cuenta no se cambia** desde aquí, igual que en la
  gestión asistida.
- **Funciona aunque la institución esté suspendida**: es para dar soporte. Por
  eso la comprobación de «suspendida» pasó a su propio middleware,
  `InstitucionSuspendida`, que corre DESPUÉS de la sesión (que es donde se sabe
  quién viene del panel); la barra avisa de que nadie más la ve.
- **Queda registrado dos veces**: la fila de `suplantaciones` (quién, como
  quién, cuándo emitido y cuándo usado), que no se borra, y el canal de
  auditoría (`suplantacion.inicio` y `suplantacion.fin`).
- `InstitucionActual::mientras()` deja al panel otra vez en «ninguna
  institución» al terminar: el panel la usa para leer los administradores.

**Lo que esto permite y se asumió:** dentro de la cuenta del administrador, el
operador puede todo lo que puede ese administrador (también restablecer la
contraseña de otras cuentas desde Gestión → Usuarios). Es lo que se necesita
para dar soporte, y queda escrito quién entró.

## Aplicar y revertir

```bash
php artisan migrate --database=pgsql_dueno       # 2026_10_05 y 2026_10_06
php artisan operador:crear <usuario>
```

`05-suplantaciones.sql` crea la tabla con su RLS; revertirlo borra el registro
de quién entró desde el panel.

`04-panel.sql` es idempotente, y su reversión también. Revertir **borra los
operadores**. Los permisos de la aplicación sobre la tabla nueva los da el
`ALTER DEFAULT PRIVILEGES` de `02-rls.sql`.

En local: `http://panel.localhost:8001`.

---

## Cómo se comprobó

- **`PanelDeInstitucionesTest`**: el panel solo en su host y nunca sin dominio
  base, la redirección de lo demás, el login (clave mala, desactivado, el
  administrador de una institución), desactivar echa, subdominio y dominio
  propio que después sirven, cada dirección que no vale, suspender y
  reactivar, que desde el panel no se ve ninguna fila de datos, y el comando.
- **Cada pieza se vio fallar quitándola**, diez en total: el 404 fuera del
  host, `ninguna()`, `OperadorActivo`, la redirección, los reservados, el
  dominio bajo la base, las minúsculas, «hace falta uno», el compositor y la
  exclusión entre `usar()` y `ninguna()`.
- **El guion, dos veces abajo y dos arriba** sobre la copia local: el esquema
  vuelve idéntico y `instituciones` no cambia.
- **En el navegador**: el login, la lista y la edición, con el rechazo de
  `panel` visible bajo su campo, y a 390 px sin desbordar, con las filas como
  fichas y los controles de 44 px.

---

## Archivos

- `database/sql/postgres/04-panel.sql` y `.revertir.sql`,
  `database/migrations/2026_10_05_100000_panel_de_instituciones.php`
- `app/Models/Operador.php`, `app/Support/Panel.php`
- `app/Http/Controllers/Operador/SesionController.php`,
  `app/Http/Controllers/Operador/InstitucionesController.php`
- `app/Http/Middleware/SoloEnElPanel.php`, `app/Http/Middleware/OperadorActivo.php`
- `app/Console/Commands/CrearOperador.php`
- `routes/operador.php`, `bootstrap/app.php` (grupo `operador`, rutas, a dónde
  van los que no tienen sesión), `config/auth.php` (guard y proveedor)
- `app/Http/Middleware/InstitucionPorDominio.php` (el host del panel lleva al
  panel), `app/Support/InstitucionActual.php` (`ninguna()`),
  `app/Providers/AppServiceProvider.php` (compositor y tope de intentos)
- `resources/views/layouts/operador.blade.php`, `resources/views/operador/*`
- `tests/Feature/PanelDeInstitucionesTest.php`,
  `tests/Feature/FiltroDeInstitucionUnicoTest.php` (`operadores` entre las
  tablas sin institución)

**Paso 4c**

- `database/sql/postgres/05-suplantaciones.sql` y `.revertir.sql`,
  `database/migrations/2026_10_06_100000_suplantaciones.php`
- `app/Support/Suplantacion.php`, `app/Models/Suplantacion.php`
- `app/Http/Controllers/Operador/SuplantacionController.php` (el panel deja el
  token), `app/Http/Controllers/SuplantacionController.php` (la institución lo
  canjea y devuelve al panel), rutas en `routes/operador.php` y
  `routes/web.php`
- `app/Http/Middleware/InstitucionSuspendida.php` (la comprobación de
  suspendida, ahora después de la sesión)
- `app/Support/Panel.php` (`urlDe()`, `urlDelPanel()`),
  `app/Support/InstitucionActual.php` (`mientras()` respeta «ninguna»)
- `resources/views/layouts/app.blade.php` (la barra),
  `resources/views/operador/institucion.blade.php` (los administradores),
  `app/Http/Controllers/MiPerfilController.php` y
  `resources/views/perfil/mi-perfil.blade.php` (la contraseña no se cambia)
- `tests/Feature/SuplantacionTest.php`: entrar y verlo dicho, un solo uso, solo
  en su dominio, caduca, solo administradores, cuenta desactivada en medio,
  institución suspendida, contraseña, volver al panel, la barra solo cuando
  toca, la lista del panel y `mientras()`. Las once piezas se vieron fallar
  quitándolas, el RLS de la tabla incluido.
