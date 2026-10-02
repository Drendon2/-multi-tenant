# El panel de todas las instituciones

Paso 4b de la versión multi-institución (ver
[`MULTI-INSTITUCION.md`](MULTI-INSTITUCION.md)). Es la pantalla de quien
**presta el servicio**: desde aquí se le pone la dirección a cada institución y
se la suspende o reactiva. Las decisiones son del usuario, del 02/10/2026.

---

## Qué hace y qué no

| Hace | No hace (y dónde vive) |
|---|---|
| Lista todas las instituciones con su dirección, estado y fecha de alta | Crear una institución: `php artisan instalar --nueva`, que monta catálogo, periodo y dos administradores |
| Edita el **subdominio** y el **dominio propio** | Las cifras de cada una (personas, matrículas): paso 5, con el rol global |
| **Suspende** y **reactiva** | Entrar como un administrador: suplantación, paso 4c |
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

## Aplicar y revertir

```bash
php artisan migrate --database=pgsql_dueno       # 2026_10_05_100000
php artisan operador:crear <usuario>
```

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
