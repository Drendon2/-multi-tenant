# La institución la dice el dominio

Paso 4a de la versión multi-institución (ver
[`MULTI-INSTITUCION.md`](MULTI-INSTITUCION.md)). Desde aquí **la institución de
una petición web la dice el HOST**, antes de entrar y antes de cualquier
consulta. Con eso, `users` pasa a tener RLS como las demás tablas y el nombre
de usuario es único **por institución**.

Las decisiones de este paso las tomó el usuario el 02/10/2026, con la
recomendación delante.

---

## Cómo funciona

```
 GET https://guarne.matriculas.example/entrar
        │
        ▼
 InstitucionPorDominio  (el PRIMERO del grupo `web`, antes de la sesión)
        │   InstitucionActual::delHost('guarne.matriculas.example')
        │     1. ¿es el dominio propio de alguna?         → esa
        │     2. ¿es <subdominio>.<DOMINIO_BASE>?          → esa
        │     3. ninguna                                  → 404
        │
        ├── suspendida → «Servicio suspendido» (503)
        │
        ▼
 InstitucionActual::usar(id)  → la base recibe app.institucion_id
        │
        ▼
 sesión, login, pantallas: todo con RLS en esa institución
```

- **Dos formas de llegar a una institución:** su `subdominio` bajo el dominio
  base (`guarne.<DOMINIO_BASE>`) o su `dominio_propio`, para la entidad que trae
  el suyo (`matriculas.guarne.gov.co`). Se guardan en minúsculas, sin puerto y
  sin esquema, y la base lo comprueba con un CHECK.
- **Un solo nivel de subdominio.** `a.guarne.<base>` no es de nadie, y el
  dominio base a secas tampoco (lo ocupará el panel, paso 4b).
- **Un host que no es de nadie da 404**, sin caer en la institución por defecto:
  eso enseñaría la pantalla de entrar de una casa a quien escribió mal el nombre
  de otra.
- **Suspendida:** todas sus pantallas dicen «Servicio suspendido» (503), sin
  decir por qué. No se borra nada; reactivarla la devuelve tal cual. Solo se
  sirven el logo y los iconos, que pide la propia pantalla.

### Sin dominio base: una sola casa

Con `DOMINIO_BASE` vacío la instalación es de **una sola casa**: todo host es de
`INSTITUCION_POR_DEFECTO`. Es como funciona hoy El Santuario, y por eso es el
valor de fábrica. En ese modo, un enlace con token de otra institución da 404.

`INSTITUCION_POR_DEFECTO` vale solo ahí y en la consola, para el comando que no
dice con cuál trabaja.

### Lo que cambia para `users`

- **Tiene RLS**, con la misma política que las demás (`03-dominios.sql`). El
  login solo encuentra las cuentas de la casa por cuyo dominio se entra.
- **`username` es único por institución** (`users_institucion_username_unique`):
  dos casas pueden tener cada una su «admin».
- **`User` usa `DeLaInstitucion`** como los demás modelos, e
  `InstitucionActual::SIN_RLS` queda vacía. El mecanismo de `filtrar()` para
  tablas sin RLS se queda, por si algún día hace falta otra.
- **El tope de intentos del login cuenta por institución y cuenta**
  (`AppServiceProvider`, limitador `entrar`): sin la institución en la clave,
  equivocarse con el «admin» de una casa bloqueaba al «admin» de la otra.
- **Una sesión de otra institución no vale aquí:** la cuenta de la sesión se
  carga con RLS, en la casa del dominio, y no aparece. Las cookies, además, son
  de cada host (`SESSION_DOMAIN` vacío).

### Enlaces con token

Un enlace (`/unirse/…`, el de una actividad, el de «olvidé mi contraseña»)
abierto en el dominio de **otra** institución da 404. No se redirige al dominio
bueno: diría de qué institución es un token a quien lo prueba en otra. Los
enlaces se generan con la URL de la petición, así que salen ya con el dominio
correcto.

---

## Configurar

```dotenv
DOMINIO_BASE=matriculas.example     # vacío: una sola casa
INSTITUCION_POR_DEFECTO=1           # solo sin DOMINIO_BASE, y en la consola
SESSION_DOMAIN=                     # vacío: la cookie es de cada host
```

El subdominio de cada institución se pone hoy con `instalar --nueva
--subdominio=guarne` o a mano en `instituciones`; el panel (paso 4b) lo hará
desde pantalla. **El DNS** necesita un comodín (`*.matriculas.example`) hacia el
servidor, y el certificado TLS también tiene que cubrirlo. Un dominio propio
necesita su registro DNS y su certificado.

### En local

Chrome y Firefox resuelven `*.localhost` a la máquina sin tocar nada, así que no
hacen falta puertos distintos (que además compartirían la cookie):

```dotenv
DOMINIO_BASE=localhost
APP_URL=http://santuario.localhost:8001
```

```
http://santuario.localhost:8001   → la institución con subdominio «santuario»
http://prueba.localhost:8001      → la de subdominio «prueba»
```

---

## Aplicar y revertir

```bash
php artisan migrate --database=pgsql_dueno       # 2026_10_04_100000
```

El guion `03-dominios.sql` es idempotente, y su reversión también. Revertir se
niega si el mismo nombre de usuario existe en dos instituciones (la restricción
de antes no se podría poner, y elegir qué cuenta se renombra no lo decide un
guion). Revertirlo sin devolver el código de antes deja `users` sin filtro.

---

## Cómo se comprobó

- **Las pruebas de aislamiento visitan cada casa por su dominio**
  (`AislamientoEntreInstitucionesTest`): la marca de cada dominio, el login solo
  por el de la propia casa, una sesión de la otra que no vale aquí (con una
  sesión de verdad, no con `actingAs`, que pone la cuenta sin pasar por la
  base), un host de nadie, el dominio propio, la suspendida y el modo de una
  sola casa. Todas miran las dos mitades.
- **Cada una se vio fallar** quitando su arreglo: el 404 del host desconocido,
  la pantalla de suspendida, `adoptar()` comparando con el dominio, la
  institución en la clave del tope de intentos y el RLS de `users` (sin él
  caen cuatro, la guardia incluida).
- **El guion, dos veces abajo y dos arriba** sobre la copia local con datos:
  el esquema (`pg_dump --schema-only`, sin la línea `\restrict` que cambia en
  cada volcado) vuelve idéntico al de partida, y las huellas md5 de todas las
  filas de `users` e `instituciones` no cambian.
- Un primer arreglo —olvidar en el middleware la cuenta de otra institución que
  `actingAs` deja puesta— **no se pudo ver fallar**: otra barrera ya echaba a
  esa sesión. Se quitó, y la prueba pasó a usar una sesión de verdad.

---

## Archivos

- `database/sql/postgres/03-dominios.sql` y `.revertir.sql`,
  `database/migrations/2026_10_04_100000_institucion_por_dominio.php`
- `app/Http/Middleware/InstitucionPorDominio.php`, registrado en
  `bootstrap/app.php`
- `app/Support/InstitucionActual.php`: `delHost()`, `adoptar()` compara con la
  institución del dominio, `SIN_RLS` vacía
- `app/Models/User.php` (`DeLaInstitucion`), `app/Models/Institucion.php`
  (`dominio_propio`)
- `resources/views/publico/suspendida.blade.php`
- `config/institucion.php` (`dominio_base`), `.env.example`,
  `.env.production.example`
- `app/Support/RestablecerClave.php` (comentarios),
  `app/Providers/AppServiceProvider.php` (clave del tope de intentos)
- `phpunit.xml`: fija `DOMINIO_BASE` vacío y `APP_URL`, para que la suite no
  herede los del `.env` de la máquina
- `tests/Feature/AislamientoEntreInstitucionesTest.php`,
  `tests/Feature/FiltroDeInstitucionUnicoTest.php`
