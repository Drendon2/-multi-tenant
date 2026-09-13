# Guía de instalación y despliegue

Sistema de Matrículas — versión PHP (Laravel 12 + MariaDB).

Esta guía va de cero a un sistema funcionando: primero en tu computador, después
en el servidor. Está pensada para seguirse en orden y sin saltarse pasos. Cada
paso dice **qué hacer**, **qué debe pasar** si salió bien y, cuando hace falta,
**qué hacer si sale mal**.

Hay cuatro partes:

1. **Instalar en tu computador** — para desarrollar y probar.
2. **Dejar el sistema listo para usarse** — el orden en que hay que cargar los
   datos para que el sistema funcione. Aplica igual en local y en el servidor.
3. **Desplegar en el hosting** — subirlo a internet.
4. **Problemas frecuentes** — qué mirar cuando algo no responde.

---

## Las cinco advertencias que hay que leer antes de nada

Ninguna de estas es teórica: las cinco han costado tiempo o datos.

| | |
|---|---|
| **Nunca `migrate:fresh`** | Ese comando **borra todas las tablas y todos los datos**, sin preguntar. En este proyecto ya vació la base local una vez. En el servidor no se usa jamás. Para aplicar cambios se usa `php artisan migrate`, a secas. |
| **La raíz del dominio apunta a `public/`** | Si apunta a la carpeta del proyecto, quedan expuestos a internet el archivo `.env` —con la contraseña de la base— y el código fuente entero. |
| **En el servidor se copia `.env.production.example`** | No `.env.example`, que es el de desarrollo y trae `APP_DEBUG=true`. Con eso, cualquiera que provoque un error 500 recibe la traza completa: credenciales y datos personales de estudiantes. |
| **Dos administradores, no uno** | La cuenta de un administrador solo la puede editar otro administrador. Si se pierde el acceso al único que hay, ni un director puede recuperarlo: toca entrar a la base de datos a mano. |
| **Sin cupo definido no hay tope** | El límite de una promotoría lo hace cumplir un *trigger* de la base de datos, y ese trigger se apoya en la fila de cupo del periodo. Si no defines cupo para una promotoría, esa promotoría admite matrículas sin límite. |

---

# Parte 1 — Instalar en tu computador

## 1.1 Requisitos

| Programa | Versión | Para qué |
|---|---|---|
| PHP | 8.4 o superior | Ejecuta la aplicación |
| Composer | 2.x | Instala las librerías de PHP |
| MariaDB | 10.5 o superior | La base de datos |
| Git | cualquiera | Descargar y actualizar el código |

PHP necesita estas extensiones activas: **`gd`**, **`exif`**, **`pdo_mysql`**,
**`mbstring`**, **`intl`** y **`zip`**. Sin `gd` y `exif` no se pueden subir
fotos de perfil ni el logo de la institución.

Para comprobar qué tienes:

```bash
php -v
php -m
composer --version
```

En la lista de `php -m` deben aparecer las seis extensiones. Si falta alguna, se
activa quitando el `;` de su línea en el `php.ini` y reiniciando.

> **No hace falta Node ni npm.** El proyecto no compila nada: el CSS es un
> archivo estático y el JavaScript son cuatro ficheros sueltos. Es deliberado,
> porque el destino es hosting compartido.

## 1.2 Descargar el proyecto

```bash
git clone https://github.com/Drendon2/sistema-matriculasPHP.git
cd sistema-matriculasPHP
```

## 1.3 Instalar las librerías

```bash
composer install
```

La carpeta `vendor/` no viaja en el repositorio: se construye aquí. Tarda uno o
dos minutos la primera vez.

## 1.4 Crear las bases de datos

Son **dos**: la de trabajo y la de pruebas. La segunda no es opcional si vas a
correr los tests, y conviene crearlas juntas.

Entra al cliente de MariaDB como administrador:

```bash
mysql -u root -p
```

Y ejecuta, cambiando `ELIGE_UNA_CLAVE` por la que quieras usar:

```sql
CREATE DATABASE matriculas       CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE test_matriculas  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER 'matriculas'@'localhost' IDENTIFIED BY 'ELIGE_UNA_CLAVE';

GRANT ALL PRIVILEGES ON matriculas.*      TO 'matriculas'@'localhost';
GRANT ALL PRIVILEGES ON test_matriculas.* TO 'matriculas'@'localhost';
FLUSH PRIVILEGES;
```

Dos detalles que importan:

- **`utf8mb4_unicode_ci`** es obligatorio. Con otra codificación, las tildes y
  las eñes de los nombres se guardan mal y ya no hay vuelta atrás cómoda.
- **`ALL PRIVILEGES` incluye el permiso `TRIGGER`**, y ese permiso hace falta:
  una de las migraciones crea dos triggers. Si le das permisos sueltos al
  usuario, asegúrate de incluir `TRIGGER`.

## 1.5 Configurar el archivo `.env`

```bash
cp .env.example .env
php artisan key:generate
```

Ahora abre `.env` y ajusta estas líneas:

```ini
DB_CONNECTION=mariadb
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=matriculas
DB_USERNAME=matriculas
DB_PASSWORD=ELIGE_UNA_CLAVE

APP_TIMEZONE=America/Bogota
```

> **La zona horaria no es cosmética.** De ella dependen dos reglas de negocio:
> si un grupo ya tiene clase registrada hoy, y el plazo de 48 horas para
> confirmarla. Ajústala antes de cargar datos si la institución está en otro
> huso.

## 1.6 Crear las tablas

```bash
php artisan migrate
```

Debe terminar sin errores y listar 22 migraciones.

**Comprueba de inmediato que los dos triggers quedaron creados**, porque son la
única garantía real contra la sobreventa de cupos:

```bash
php artisan tinker --execute="echo count(DB::select('SHOW TRIGGERS')).PHP_EOL;"
```

Tiene que responder **`2`**. Si responde `0`, el usuario de la base no tiene
permiso `TRIGGER`: concédeselo y vuelve a correr la migración.

## 1.7 Arrancar el servidor

```bash
php artisan serve
```

Abre **http://127.0.0.1:8000**. Verás la pantalla de entrada. Todavía no puedes
entrar: la base está vacía y no existe ninguna cuenta. Eso es el paso siguiente.

## 1.8 Crear el primer administrador

Aquí hay un huevo y una gallina: los usuarios se crean desde Gestión, pero a
Gestión solo entra un administrador, y todavía no hay ninguno. Así que el
primero se crea por línea de comandos. **Es la única cuenta que nace así**; todas
las demás salen ya de la propia aplicación.

### El camino corto: `php artisan instalar`

Este comando hace este paso **y los cinco primeros de la parte 2** de una
sentada, preguntándote los datos por consola:

```bash
php artisan instalar
```

Pregunta la institución (nombre, color de acento, límite de promotorías,
visibilidad del catálogo), los documentos que se le piden al estudiante, los
departamentos, el periodo —y lo deja **en curso**—, y las **dos** cuentas de
administrador. Las dos no son opcionales: es la trampa de arriba, y el comando no
termina con una sola.

No escribe nada hasta el final, así que un dedazo a mitad no deja media
institución montada. Se niega a correr si la base ya tiene datos, y dice cuáles
encontró.

Si lo lanzas así, salta directo a **1.9** y luego a **2.7** (promotorías): lo de
en medio ya está hecho. Vuelve a 2.4 cuando toque dar de alta a directores y
profesores.

> Necesita una consola de verdad. Con `--no-interaction`, o dentro de un guion
> sin terminal, no instala: se planta y te lo dice.

### El camino largo, a mano

Abre la consola interactiva:

```bash
php artisan tinker
```

Y pega esto, cambiando los cuatro valores:

```php
$u = App\Models\User::create([
    'username' => 'tu.usuario',
    'password' => 'tu-contraseña-de-8-o-mas',
    'activo'   => true,
]);

App\Models\Perfil::create([
    'user_id'          => $u->id,
    'rol'              => 'administrador',
    'nombre_completo'  => 'Tu Nombre Completo',
    'fecha_nacimiento' => '1990-01-01',
    'telefono'         => '3000000000',
]);
```

La contraseña se cifra sola: se escribe en claro aquí y el modelo la guarda ya
convertida en hash. Sal con `exit`.

Los cuatro roles válidos son exactamente: `administrador`, `director`,
`profesor`, `estudiante`.

**Camino alternativo, si prefieres no escribir datos a mano:** regístrate en
`/registro` desde el navegador —el formulario público crea la cuenta **sin rol**,
en espera de que alguien se lo asigne— y después ascendéla desde tinker:

```php
$p = App\Models\Perfil::whereRelation('user', 'username', 'tu.usuario')->firstOrFail();
$p->rol = 'administrador';
$p->save();
```

Ya puedes entrar en http://127.0.0.1:8000 con ese usuario y esa contraseña.

## 1.9 Comprobar que la instalación está sana

```bash
php artisan test
```

Deben pasar **todas**. Corren contra la base `test_matriculas`, **no contra la
de trabajo**: no tocan tus datos.

(El número de pruebas no se escribe aquí a propósito: lo dice `artisan test` al
terminar, y una cifra en un documento se queda vieja sin que nadie se entere.
Llegó a haber tres distintas en este repositorio, y ninguna era la de verdad.)

Hay además dos guiones que comprueban el esquema contra SQL crudo, incluida la
carrera de dos transacciones por el último cupo:

```bash
php database/verificacion_esquema.php
php database/verificacion_concurrencia.php
```

> Estos dos guiones traen las credenciales de conexión escritas en sus primeras
> líneas, apuntando a la base local. Si los quieres correr contra otra base, hay
> que editar esa línea.

## 1.10 Arranque diario en esta máquina

En el computador donde se desarrolla el proyecto, **MariaDB no está registrada
como servicio de Windows**: hay que levantarla a mano en cada arranque. Y vive en
el **puerto 3307**, porque el 3306 lo ocupa un MySQL80 ajeno al proyecto.

Hay dos guiones en la carpeta padre que lo resuelven:

```powershell
.\arrancar-mariadb.ps1   # solo la base de datos
.\arrancar-app.ps1       # la base, el respaldo, el servidor y el navegador
```

Los dos son idempotentes: se pueden ejecutar siempre al empezar, sin comprobar
nada antes. Para parar el servidor, `Ctrl+C`.

**`arrancar-app.ps1` respalda la base en cada arranque**, antes de levantar el
servidor. El respaldo va al abrir y no al cerrar a propósito: así guarda el
estado con el que terminó la sesión anterior, antes de que la de hoy pueda
romperlo. Si el respaldo falla, avisa pero **no impide trabajar**.

Para sacar uno a mano en cualquier momento:

```powershell
.\respaldar.ps1                 # conserva los 10 más recientes
.\respaldar.ps1 -Conservar 30
```

Ese envoltorio existe porque en Windows `mysqldump` no está en el PATH y el
guion de verdad —`matriculas_php/respaldar.sh`, el mismo que corre en el
servidor— es bash. El envoltorio le indica dónde está el ejecutable y lo lanza
con el bash de Git.

---

# Parte 2 — Dejar el sistema listo para usarse

El sistema recién instalado está vacío: no hay departamentos, ni periodos, ni
promotorías. **El orden de estos pasos importa**, porque cada uno necesita al
anterior. Todo se hace desde el navegador, con la cuenta de administrador.

> **Si usaste `php artisan instalar`**, del 2.1 al 2.6 ya están hechos —incluido
> poner el periodo en curso, que es el que más se olvida—. Empieza en **2.4** si
> tienes directores o profesores que dar de alta, y si no, directo a **2.7**.
> Del 2.7 en adelante no lo cubre el comando a propósito: un grupo lleva horario
> de rejilla semanal y un cupo depende del periodo, y esas pantallas lo resuelven
> mejor que una consola.

## 2.1 La institución

**Gestión → Institución** (solo administrador).

| Campo | Qué es |
|---|---|
| Nombre de la institución | Sale en la cabecera de todas las pantallas |
| Logo | Opcional. Se convierte a WebP automáticamente |
| Color de acento | En formato `#rrggbb`. Tiñe botones y enlaces |
| Límite de promotorías por periodo | Cuántas puede tomar una persona a la vez |
| Promotorías visibles para estudiantes | Si el catálogo se ve o no desde fuera |

## 2.2 Los documentos que se le piden al estudiante

En la misma pantalla de Institución. Cada documento que agregues aquí es un
archivo que el estudiante deberá entregar (copia del documento de identidad,
por ejemplo).

Los documentos que dejas de pedir **se desactivan, no se borran**: lo ya
entregado se conserva y se sigue viendo.

## 2.3 El segundo administrador

**Gestión → Usuarios → Nuevo.**

Hazlo ahora, no después. La cuenta de un administrador solo la edita otro
administrador; con uno solo, perder el acceso significa entrar a la base de
datos a mano.

## 2.4 Los directores y profesores

**Gestión → Usuarios → Nuevo**, eligiendo el rol.

| Rol | Qué puede hacer |
|---|---|
| **Administrador** | Todo: institución, estadísticas, copias de documentos de identidad |
| **Director** | El catálogo académico **de los departamentos que se le asignen**, pero no los usuarios, ni la institución, ni lo global (crear departamentos, periodos o promotorías) |
| **Profesor** | Su Panel: sus promotorías, sus grupos, sus clases y su asistencia |
| **Estudiante** | Su matrícula, sus clases y sus compañeros |

> **A UN DIRECTOR HAY QUE MARCARLE SUS DEPARTAMENTOS**, en las casillas
> «Departamentos que dirige» de ese mismo formulario. **Sin ninguno marcado no ve
> ninguna promotoría**: ni panel, ni alertas, ni informe. El recorte cierra en
> falso a propósito —un director recién creado no ve la casa entera mientras
> nadie decide qué le toca— así que el aviso no es que falle, es que no verá
> nada y parecerá roto.
>
> Y por eso **este paso va DESPUÉS del 2.5**: solo se pueden marcar
> departamentos que ya existan. Si estás montando una institución nueva, crea
> los departamentos primero y vuelve aquí.

Los profesores también pueden **registrarse solos** en `/registro`. La cuenta
queda sin rol y solo ve una pantalla de «cuenta pendiente» hasta que **un
administrador** se lo asigna desde Gestión → Usuarios. Un director no entra a esa
pantalla desde el 12/09/2026.

## 2.5 Los departamentos

**Gestión → Departamentos → Nuevo.** Son las áreas artísticas: música, danza,
teatro, artes plásticas. Cada una recibe un color de etiqueta estable.

## 2.6 El periodo, y ponerlo en curso

**Gestión → Periodos → Nuevo:** nombre, fecha de inicio y fecha de fin.

Después hay que **ponerlo en curso** desde **Gestión → Matrículas**. Esto es un
paso aparte y hay que darlo: sin periodo en curso, el catálogo no se ve y no se
puede matricular nadie.

Poner un periodo en curso **cierra el anterior** y deja sus matrículas cerradas.

## 2.7 Las promotorías

**Gestión → Promotorías → Nuevo:** nombre, departamento al que pertenece y
**quién la dicta**. Quien la dicta puede ser un profesor o un director.

## 2.8 Los cupos del periodo

**Gestión → Cupos.** Aquí se define, para cada promotoría y **para ese periodo**,
cuánta gente cabe.

> **Este paso es el que hace que el tope exista.** El límite lo impone un trigger
> de la base de datos que se apoya en la fila de cupo. Una promotoría sin cupo
> definido para el periodo **no tiene fila, no tiene cerrojo y no tiene tope**:
> admite matrículas sin límite.

## 2.9 Los grupos

**Gestión → Grupos → Nuevo**, o desde el Panel de cada promotoría: nombre,
nivel, horario, salón y cupo máximo del grupo.

Lo que identifica al grupo es su **nombre**, único dentro de la promotoría. Una
promotoría puede tener varios grupos del mismo nivel —ocho Básicos de Guitarra a
horas distintas son la semana normal de una casa que atiende a mucha gente—, y
ahí «Básico» no dice cuál es cuál.

El **horario** se pone marcando días en una rejilla de lunes a sábado, con hora
de inicio y de fin. Un grupo puede reunirse varios días; uno solo por día. De
ahí sale la rejilla semanal que cada quien ve en su perfil.

El estudiante **no elige grupo** al matricularse: se matricula en la promotoría y
quien la dicta le asigna un grupo después.

## 2.10 Abrir las matrículas

**Gestión → Matrículas**, interruptor de matrículas abiertas.

Mientras estén cerradas, el formulario público de inscripción muestra un aviso y
rechaza los envíos, aunque alguien llegue a la URL directamente.

## 2.11 Cursos, talleres y grupos de proyección (opcional)

Esto es **independiente de todo lo anterior**: no necesita periodo, ni cupos, ni
matrículas abiertas, y de hecho puede montarse antes que el catálogo entero. Si
la institución solo da promotorías, sáltatelo.

**Gestión → Cursos y talleres**, o **Gestión → Grupos de proyección**.

Al crear uno se pide el nombre, **cuántas clases**, quién queda a cargo y el cupo
(en blanco, sin tope). Una sola clase es un taller; dos o más, un curso. Después
se ponen las fechas, una casilla por clase. Los grupos de proyección no llevan
fechas: la sesión nace cuando se oprime «Iniciar ensayo».

Cada uno genera un **enlace** que se copia y se comparte. Quien lo abra se
inscribe con cinco campos y **sin crear cuenta**; si su documento coincide con el
de un estudiante de la casa, queda vinculado a su ficha. El enlace deja de
admitir gente al llenarse el cupo, o cuando dirección lo cierra a mano — que es
lo único que puede parar uno sin tope.

Quien quedó a cargo lo ve en su **Panel**: inicia cada sesión y pasa lista. Puede
añadir a quien llegó sin inscribirse, solo con el nombre.

> **Ojo al asignar el responsable.** Dirección ve todas las actividades, pero
> **solo quien está a cargo puede iniciar sesiones y pasar lista**. Si esa
> persona falta, hay que cambiar el responsable desde Gestión; nadie más puede
> escribir en su lista.

## 2.12 Cómo se usa a partir de aquí

1. **El público se inscribe** en `/inscripcion`: crea su cuenta y elige sus
   promotorías en un solo formulario. Un menor de edad debe registrar acudiente.
2. **Quien dicta confirma o rechaza** desde su **Panel**. Puede hacerlo en bloque.
3. **Se asigna grupo** a cada matrícula confirmada, también en bloque.
4. **Se registran las clases** y se pasa lista desde el Panel del grupo.
5. **El estudiante confirma su asistencia** desde «Mis clases», dentro del plazo
   de 48 horas.
6. **Los informes** se descargan en CSV desde las pantallas de grupo y desde
   Gestión.

---

# Parte 3 — Desplegar en el hosting

Escrito para Hostinger (PHP 8.4, LiteSpeed, MariaDB), pero sirve para cualquier
hosting compartido equivalente.

## 3.0 Antes de tocar nada, comprueba tres cosas

1. **La versión de PHP del plan es 8.4 o superior**, y tiene activas `gd`,
   `exif`, `pdo_mysql`, `mbstring`, `intl` y `zip`. En hPanel se cambia y se
   revisa desde la configuración de PHP.
2. **Hay acceso SSH**. Sin SSH no se pueden correr `composer`, `artisan` ni las
   migraciones, y el despliegue se complica bastante: habría que subir `vendor/`
   por FTP y ejecutar las migraciones por otros medios.
3. **La raíz del dominio se puede cambiar** para que apunte a una subcarpeta.

## 3.1 Crear la base de datos

Desde hPanel, en la sección de bases de datos MySQL: crea la base y un usuario, y
asígnale todos los permisos.

Anota los tres datos: **nombre de la base, usuario y contraseña**. En hosting
compartido suelen llevar un prefijo de tu cuenta, del estilo `u123456_`.

El servidor de base de datos es casi siempre `localhost` y el puerto `3306`.

## 3.2 Subir el código

Con SSH y Git, que es lo más cómodo para actualizar después:

```bash
cd ~/dominios/tu-dominio.com
git clone https://github.com/Drendon2/sistema-matriculasPHP.git app
cd app
```

Sin Git: comprime el proyecto **sin** `vendor/`, `node_modules/`, `.env` ni
`storage/logs/`, súbelo por el gestor de archivos y descomprímelo allí.

## 3.3 Apuntar el dominio a `public/`

La raíz del dominio (*document root*) tiene que apuntar a la carpeta **`public/`**
del proyecto, no a la carpeta del proyecto.

> Si apunta a la carpeta del proyecto, quedan accesibles por URL el `.env` —con
> la contraseña de la base de datos— y todo el código fuente.

Si el plan no permite cambiar la raíz del dominio, la salida es dejar el proyecto
fuera de `public_html` y mover el contenido de `public/` dentro de `public_html`,
corrigiendo después las dos rutas del archivo `index.php` que apuntan a
`vendor/autoload.php` y a `bootstrap/app.php`.

## 3.4 Instalar las librerías, sin las de desarrollo

```bash
composer install --no-dev --optimize-autoloader
```

`--no-dev` deja fuera PHPUnit y las herramientas de desarrollo.
`--optimize-autoloader` acelera cada petición.

## 3.5 El archivo `.env` de producción

```bash
cp .env.production.example .env
```

> **`.env.production.example`, no `.env.example`.** El segundo es el de
> desarrollo y trae `APP_DEBUG=true`.

Edita el `.env` y rellena todo lo que diga `CAMBIAR`:

```ini
APP_URL=https://tu-dominio.com

DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=u123456_matriculas
DB_USERNAME=u123456_matriculas
DB_PASSWORD=la-contraseña-que-anotaste

MAIL_USERNAME=admin@tu-dominio.com
MAIL_PASSWORD=la-contraseña-del-buzón
MAIL_FROM_ADDRESS="admin@tu-dominio.com"
```

> **Esto también se puede hacer sin tocar el `.env`.** Desde el 09/09/2026 las
> credenciales del correo se escriben en Gestión → Institución → Correo, con un
> botón para mandarse una prueba. Es el camino recomendado para una entidad
> nueva: no necesita consola y dice si funciona. Lo de abajo sigue valiendo, y
> lo que se escriba en la pantalla manda sobre el archivo.
>
> **El correo hace falta para una sola cosa, y sin él esa cosa no avisa de que
> no funciona.** El sistema manda un único correo: el enlace de «Olvidé mi
> contraseña». Con `MAIL_MAILER=log` el enlace se escribe en `storage/logs` en
> vez de enviarse, y la persona ve exactamente la misma pantalla —esa pantalla
> contesta lo mismo pase lo que pase, para no delatar quién tiene cuenta—. O
> sea: parece que funciona y no funciona.
>
> El buzón se crea en el panel de Hostinger (Correos → Cuentas de correo). La
> contraseña que va aquí es **la del buzón**, no la del panel. Si el buzón está
> en Hostinger, el resto de líneas (`MAIL_HOST`, `MAIL_PORT`, `MAIL_SCHEME`) ya
> vienen bien en la plantilla.
>
> Para comprobar que sale de verdad, después del despliegue:
> `php artisan tinker --execute="Mail::raw('prueba', fn(\$m) => \$m->to('tu-correo-personal@example.com')->subject('prueba'));"`

Y **verifica** que estas cuatro líneas están tal cual. La plantilla ya las trae,
pero es lo que hay que mirar dos veces:

| | |
|---|---|
| `APP_DEBUG=false` | Con `true`, un error 500 muestra traza completa, credenciales y datos personales de menores |
| `APP_ENV=production` | Es lo único que impide que `php artisan simular` siembre 300 cuentas de prueba con contraseña conocida |
| `SESSION_SECURE_COOKIE=true` | Sin ella la cookie de sesión también viaja por HTTP plano |
| `LOG_LEVEL=error` | En `debug` se escribe cada consulta: llena la cuota de disco y deja datos personales en un archivo que nadie vigila |

## 3.6 Generar la clave de cifrado

```bash
php artisan key:generate
```

**Se genera en el servidor. No se copia la de desarrollo**: es la que cifra las
cookies y las sesiones de la gente real.

## 3.7 Crear las tablas

```bash
php artisan migrate --force
```

`--force` es necesario porque en producción Laravel pide confirmación
interactiva.

> **Nunca `migrate:fresh` ni `migrate:refresh` aquí.** Borran todas las tablas y
> todos los datos.

## 3.8 Comprobar los triggers — no te saltes este paso

```bash
php artisan tinker --execute="echo count(DB::select('SHOW TRIGGERS')).PHP_EOL;"
```

Tiene que responder **`2`**.

Si responde `0`, el usuario de la base **no tiene el permiso `TRIGGER`** y las
migraciones lo pasaron por alto. El sistema arrancará y parecerá funcionar, pero
**los cupos dejarán de tener tope**: dos personas que se matriculen a la vez para
el último sitio entrarán las dos. Concede el permiso desde hPanel y vuelve a
correr la migración de los triggers.

## 3.9 Permisos de escritura

```bash
chmod -R ug+w storage bootstrap/cache
```

Son las dos únicas carpetas donde la aplicación escribe: los logs, las sesiones,
las plantillas compiladas y los archivos que sube la gente.

> **No hace falta `php artisan storage:link`.** Las fotos y las copias de
> documentos se guardan en `storage/app/private`, deliberadamente fuera de
> `public/`, y se entregan por una ruta que comprueba antes quién las pide. Crear
> el enlace las publicaría.

## 3.10 Las tres cachés

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Hacen que cada petición no vuelva a leer y compilar la configuración, las rutas
y las plantillas.

> **Hay que repetirlas después de cada despliegue**, y `config:cache` además
> después de cada cambio en el `.env`. Con la configuración cacheada, editar el
> `.env` no tiene ningún efecto hasta que se regenera la caché. Es la causa más
> común de «cambié la contraseña de la base y sigue fallando».

## 3.11 Crear los dos administradores

Igual que en el paso 1.8. Lo más corto es `php artisan instalar` por SSH, que
pide las dos cuentas y de paso monta institución, documentos, departamentos y el
periodo en curso. Si prefieres hacerlo a mano, `php artisan tinker` y **dos
veces**: el tuyo y el de respaldo.

Después de esto, todas las demás cuentas se crean desde Gestión → Usuarios.

## 3.12 Lista de comprobación final

Antes de darle la dirección a nadie:

- [ ] `https://tu-dominio.com` abre la pantalla de entrada, **con candado**.
- [ ] `https://tu-dominio.com/.env` da **404 o 403**, nunca el contenido del archivo.
- [ ] Entras con el administrador y llegas a Gestión.
- [ ] `SHOW TRIGGERS` devuelve **2**.
- [ ] Subes una foto de perfil y se ve — confirma que `gd` está activa y que
      `storage/` tiene permiso de escritura.
- [ ] Haces una matrícula de prueba de punta a punta y después la borras.
- [ ] Existen **dos** administradores.
- [ ] `php artisan about` no reporta nada raro.

## 3.13 Actualizar el sistema más adelante

Todo lo de esta sección lo hace `desplegar.sh`, que va en el repositorio:

```bash
cd ~/dominios/tu-dominio.com/app
./desplegar.sh
```

Hace, en este orden: respaldo, cerrar al público, `git pull`, dependencias,
migraciones, las tres cachés, comprobar los triggers y volver a abrir. Si algo
falla a mitad, **el sitio vuelve a estar en pie igualmente** — sin eso, una
migración fallida deja la página en mantenimiento hasta que alguien llama por
teléfono a preguntar.

Y si prefieres los pasos sueltos:

```bash
./respaldar.sh                                  # primero el respaldo, siempre
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

## 3.14 Despliegue automático desde GitHub

El repositorio trae un flujo de trabajo, `.github/workflows/desplegar.yml`, que
en cada `push` a `main`:

1. Levanta MariaDB y corre **la suite entera**.
2. **Solo si pasan todas**, entra por SSH al servidor y ejecuta `desplegar.sh`.

Esa segunda condición es el motivo de que exista. Un `git pull` automático sin
pruebas publica el error en internet treinta segundos después de haberlo
escrito; con la puerta, la suite que ya tienes se convierte en el guardián del
despliegue.

**Mientras no configures los secretos, el despliegue se salta solo** y la
ejecución queda en verde: las pruebas siguen corriendo en cada push, que ya es
útil por sí solo.

### Lo que hay que configurar una vez

En GitHub, en *Settings → Secrets and variables → Actions*, cinco secretos:

| Secreto | Qué es |
|---|---|
| `HOSTINGER_HOST` | Dominio o IP del servidor |
| `HOSTINGER_PUERTO` | Puerto SSH. En Hostinger **no es el 22** |
| `HOSTINGER_USUARIO` | Usuario SSH |
| `HOSTINGER_CLAVE_SSH` | La clave **privada** completa, con sus líneas `BEGIN` y `END` |
| `HOSTINGER_RUTA` | Ruta al proyecto en el servidor |

Y en el servidor, una sola vez:

```bash
ssh-keygen -t ed25519 -C "despliegue github"   # si no tienes ya un par de claves
cat ~/.ssh/id_ed25519.pub >> ~/.ssh/authorized_keys
chmod 600 ~/.ssh/authorized_keys
```

La clave **pública** se queda en el servidor; la **privada** es la que se pega
en `HOSTINGER_CLAVE_SSH`. Nunca al revés, y la privada no se guarda en ningún
archivo del repositorio.

> **Los datos no viajan por aquí, y es deliberado.** Este circuito sube
> **código**, nada más. Los cambios de estructura de la base viajan como
> migraciones, que son archivos del repositorio y las aplica `migrate --force`.
> Los **datos** del servidor son los buenos: no se sobrescriben nunca desde
> local. Si alguna vez quieres trabajar con datos parecidos a los reales, el
> movimiento es el contrario — sacar un respaldo del servidor y restaurarlo en
> tu máquina.

> **Para cambiar el esquema se añade una migración nueva, jamás se edita una
> existente.** Editar una vieja obliga a `migrate:fresh` para que surta efecto, y
> eso borra la base entera. Es exactamente lo que vació la base local una vez.

## 3.15 Respaldos

```bash
./respaldar.sh
```

Guarda las dos cosas que **no** se pueden reconstruir desde el repositorio: la
base de datos **con sus triggers** y los archivos que ha subido la gente.

> La carpeta de destino de los respaldos **no puede quedar bajo `public/`**. Un
> volcado ahí es la base entera —con documentos de identidad— descargable por
> URL.

Súbelos a otro sitio de vez en cuando. Un respaldo que vive solo en el mismo
servidor no es un respaldo.

---

# Parte 4 — Problemas frecuentes

## No puedo entrar con ninguna cuenta

Mira primero si hay usuarios:

```bash
php artisan tinker --execute="echo App\Models\User::count().PHP_EOL;"
```

Si responde `0`, la base está vacía. Casi siempre es porque alguien corrió
`php artisan migrate:fresh`, que borra y recrea todas las tablas. Restaura el
último respaldo:

```bash
mysql -u USUARIO -p NOMBRE_BASE < respaldos/matriculas-AAAAMMDD-HHMMSS.sql
```

El archivo de respaldo ya trae los `DROP TABLE` y los triggers: la restauración
es completa.

Si hay usuarios pero el tuyo no entra, comprueba que la cuenta esté activa: una
cuenta desactivada da el mismo mensaje que una contraseña equivocada, a
propósito, para no confirmarle a un extraño que ese usuario existe.

## «Demasiados intentos»

El login admite **5 intentos por minuto** por usuario y por IP. Se despeja solo
en un minuto, o de inmediato con:

```bash
php artisan cache:clear
```

## Cambié el `.env` y no pasa nada

La configuración está cacheada. Regenérala:

```bash
php artisan config:cache
```

## Error 500 sin ningún detalle

Es el comportamiento correcto en producción. El detalle está en el log:

```bash
tail -n 50 storage/logs/laravel.log
```

Nunca pongas `APP_DEBUG=true` en el servidor para diagnosticar: esa página de
error muestra credenciales y datos personales a cualquiera que la provoque.

## Una promotoría admitió más gente que su cupo

Los triggers no están:

```bash
php artisan tinker --execute="echo count(DB::select('SHOW TRIGGERS')).PHP_EOL;"
```

Si devuelve menos de 2, ver el paso 3.8. Si devuelve 2, revisa que esa promotoría
**tenga cupo definido para ese periodo** en Gestión → Cupos: sin fila de cupo no
hay tope.

## Las fotos no se ven

Tres causas posibles, en este orden: la extensión `gd` no está activa,
`storage/` no tiene permiso de escritura, o el archivo se subió antes de que
existiera la carpeta. El log lo dice.

## Un profesor se registró y no ve nada

Correcto: `/registro` crea la cuenta **sin rol**, y hasta que un director o
administrador se lo asigne desde Gestión → Usuarios, esa persona solo ve la
pantalla de «cuenta pendiente».

## El despliegue automático falla en «Desplegar por SSH»

Pasó el 22/08/2026. Las pruebas en verde, y el paso de SSH en rojo a los **31
segundos**. No es el `command_timeout` de 15 minutos agotándose: a esa velocidad
lo que falla es la conexión, no el guion.

**Cómo distinguir «no conectó» de «se rompió a mitad»**, que es lo primero que
hay que saber: mira si hay un respaldo nuevo en `~/matriculas-app/respaldos/`. El
respaldo es lo PRIMERO que hace `desplegar.sh`, antes incluso de cerrar el sitio.
Sin respaldo nuevo, el guion no llegó a arrancar y el servidor sigue intacto en
el commit anterior.

En aquel caso el sitio siguió **abierto y sirviendo** el commit previo. No hubo
caída: lo único que pasó es que los commits nuevos no llegaron al aire.

### El otro fallo: cuando SÍ conecta (05/09/2026)

No todo lo que aparece en rojo en «Desplegar por SSH» es lo de arriba, y dar por
hecho que sí es el error que hay que evitar. El 05/09 el paso conectó, **hizo el
respaldo**, cerró el sitio al público, y murió al traer el código:

```
== Trayendo el código
fatal: could not read Username for 'https://github.com': No such device or address
```

**La causa:** el repositorio había pasado a **privado**, y el servidor tira de
`https://github.com/...` sin credenciales — `git config --get credential.helper`
devuelve vacío. Mientras el repositorio fue público, `git pull` funcionaba
anónimamente; en cuanto dejó de serlo, GitHub pide usuario y el guion se queda
sin nadie a quien preguntárselo.

**Producción NO se cayó, y eso no fue suerte.** `desplegar.sh` tiene:

```bash
trap levantar EXIT      # levantar() { php artisan up || true; }
```

Ese trap corre en CUALQUIER salida, también en una que aborta por `set -e`. Sin
él, el sitio se habría quedado en modo mantenimiento desde el momento en que
falló el `git pull` hasta que alguien lo mirara. **No lo quites**, y si algún día
reescribes el guion, escribe el trap ANTES de la primera acción que cierre el
sitio.

**Cómo se arregla**, por orden de limpieza:

1. **Clave de despliegue por SSH**: generar el par en el servidor, registrar la
   pública como *deploy key* de solo lectura en GitHub y cambiar el remoto a
   `git@github.com:...`. No caduca, no deja ningún token en disco y no puede
   escribir en el repositorio. Es lo que hay que hacer si el repositorio vuelve
   a cerrarse.
2. **Volver el repositorio a público**, que es lo que se hizo ese día. Arregla el
   despliegue sin tocar el servidor, pero **publica la historia entera**: antes
   de hacerlo hay que comprobar que ningún commit subió nunca el `.env`, la
   auditoría, `MIGRACION.md`, `SIGUIENTE-SESION.md` ni el `DemoSeeder`.
3. **Un token en el servidor.** Funciona, caduca, y queda escrito en disco. Es
   lo que la clave de despliegue evita.

**Y una consecuencia de haberlo abierto:** este mismo archivo lleva escritos la
IP, el puerto y el usuario SSH de producción, así que ahora son públicos. No
abren nada por sí solos, pero son la mitad del trabajo para quien pruebe fuerza
bruta contra ese puerto. Si algún día se quiere cerrar eso, hay que sacarlos a un
archivo fuera del repositorio — y para que desaparezcan de los commits
anteriores, reescribir la historia.

Antes de culpar a la red, descarta lo de siempre desde el propio servidor:

```bash
ssh -p 65002 u821315052@46.202.183.237
cd ~/matriculas-app
git log --oneline -1          # en qué commit está de verdad
git status --short            # ¿algo a medias?
git fetch origin && git rev-list --count HEAD..origin/main   # ¿cuántos faltan?
df -h ~                       # ¿queda disco?
ls -lt respaldos/ | head -3   # ¿hasta dónde llegó?
```

**El arreglo**: reintentar. En GitHub, *Actions → la ejecución fallida →
«Re-run failed jobs»*. Si vuelve a fallar, o si hay prisa, se despliega a mano
—es exactamente lo mismo que hace la acción—:

```bash
ssh -p 65002 u821315052@46.202.183.237
cd ~/matriculas-app && chmod +x desplegar.sh respaldar.sh && ./desplegar.sh
```

Termina diciendo `Desplegado: <commit> — <mensaje>`. Si esa línea sale, entró.

**Si falla varias veces seguidas**, la sospecha pasa a ser que Hostinger le cerró
la puerta a la IP del ejecutor de GitHub: es el mismo veto automático por IP que
tiene bloqueada la oficina desde la que se desarrolla (ver más abajo). Ahí toca
soporte de Hostinger, o desplegar a mano desde una red que sí pase.

---

# Parte 5 — La instalación real (19/08/2026)

Esta parte no es teoría: describe el despliegue que está en producción, con sus
rutas concretas y las tres trampas que aparecieron al hacerlo. Sirve para
entender el montaje sin tener que abrirlo, y para repetirlo si algún día hay que
rehacerlo.

## Dónde vive

```
https://escuelas.culturaelsantuario.com
    │
    └── public_html/escuelas   ─── enlace simbólico ──►  matriculas-app/public
                                                              (lo único público)

/home/u821315052/matriculas-app/     la aplicación entera, fuera del docroot
```

**La carpeta raíz del subdominio es un enlace simbólico a `public/`.** Es lo que
hace que un `git pull` actualice el sitio sin copiar nada: no hay dos copias que
sincronizar. Y deja el `.env`, el código y `storage/` fuera del alcance de una
URL. Comprobado: `/.env` responde 403.

El subdominio `escuelas` ya existía —ahí vivía un Moodle que se retiró— y su
carpeta estaba vacía. El enlace solo se creó porque estaba vacía.

> **En `culturaelsantuario.com` hay un WordPress vivo y los datos del Moodle
> retirado.** El despliegue no los toca. Cuidado con cualquier instrucción
> genérica que diga «vacía `public_html`»: aquí eso tumbaría el sitio de la
> institución.

## El servidor

| | |
|---|---|
| Hostinger, `us-bos-web1849.main-hosting.eu` | usuario `u821315052` |
| SSH | puerto **65002**, con llave |
| PHP | 8.2.30, con las seis extensiones |
| Base de datos | MariaDB **11.8.8** (en local es 10.5; sin diferencias hasta ahora) |
| Base | `u821315052_escuelas` |

## Las tres trampas del hosting compartido

Ninguna la podían ver las pruebas: la suite corre contra MariaDB en local y en
un PHP sin restricciones.

**`localhost` significa socket, no una dirección de red.** El cliente de MariaDB
no lo respeta: resuelve el nombre, sale por TCP a `::1`, y el permiso del
usuario —concedido para `localhost`— ya no aplica. `mysqldump` moría con un
`Access denied ... (using password: YES)` que parece un problema de contraseña y
no lo es: Laravel conectaba perfectamente por PDO al mismo tiempo. Resuelto en
`respaldar.sh` con `--protocol=socket` cuando el host es `localhost`.

**`proc_open` viene deshabilitado.** Composer lo necesita para su script de
post-instalación (`@php artisan package:discover`), que lanza php como proceso
hijo. Sin la bandera `--no-scripts`, composer aborta y —con `set -e`— se lleva
por delante el despliegue entero, con el sitio ya cerrado al público. Resuelto
en `desplegar.sh`: `--no-scripts` y `package:discover` ejecutado aparte, en el
mismo proceso.

**Un espacio en un secreto de GitHub.** `HOSTINGER_PUERTO` se guardó como
`" 65002"` y la acción de SSH no pudo convertirlo en número: falló al leer sus
propios parámetros, sin llegar a conectar. Los secretos se escriben a mano, no
se pegan.

**Hostinger veta IP enteras, y el síntoma imita a un sitio caído.** (22/08/2026.)
La IP de la oficina desde la que se desarrolla está bloqueada en los servidores
de origen: se cuelga **todo** —80, 443, 21 y el 65002 del SSH—. Como el dominio
raíz va por CDN y el subdominio por un registro A directo, el WordPress carga y
el sistema de matrículas parece muerto.

Ese día casi cuesta un cambio de DNS innecesario en producción. Se leyó un
`ECONNREFUSED` como «ahí no hay servidor», y no lo es: un cortafuegos puede
**rechazar** (RST, error instantáneo) o **descartar** (te deja esperando hasta
agotar el tiempo), y Hostinger usa las dos cosas según el rango. Los dos síntomas
que parecían distintos eran lo mismo: veto, en las dos redes desde las que se
probó.

**Antes de concluir que el sitio está caído, pregunta si se ve desde un celular
con datos móviles.** Ninguna prueba hecha desde la red vetada lo puede decidir.

Lo que el veto **no** bloquea: la API de Hostinger (`api.hostinger.com`), hPanel,
y el despliegue automático, que sale de los servidores de GitHub y no de la
oficina.

## El huevo y la gallina de los arreglos del despliegue

Un fallo en `respaldar.sh` o en `desplegar.sh` **no se puede arreglar
desplegando**: el guion que corre es el viejo, y muere antes de traer su propio
reemplazo. Hay que entrar por SSH y hacer el `git pull` a mano una vez:

```bash
ssh -p 65002 u821315052@46.202.183.237
cd ~/matriculas-app && git pull --ff-only origin main
```

Pasó dos veces el día del despliegue. Conviene recordarlo antes de perder media
hora mirando registros.

## Lo que sí funcionó a la primera

- Las **22 migraciones** y, sobre todo, los **dos triggers**: el usuario de MySQL
  de Hostinger tiene permiso `TRIGGER` sobre su propia base.
- La **trampa de salida** de `desplegar.sh`. En los dos despliegues fallidos el
  sitio ya estaba cerrado al público y volvió a abrirse solo. No es un detalle:
  sin ella, cada intento fallido habría dejado la página en mantenimiento hasta
  que alguien llamara a preguntar.
- El **respaldo previo**, que dejó su volcado con los dos triggers dentro antes
  de tocar nada.

## Trabajar a partir de ahora

Escribir, probar en local, `git push origin main`. GitHub corre la suite
y solo despliega si pasan todas.

Dos reglas para que siga funcionando:

- **No editar archivos directamente en el servidor.** El despliegue hace
  `git pull --ff-only`, que se niega a fusionar si encuentra cambios locales —y
  hace bien—, pero deja el despliegue bloqueado hasta deshacerlos a mano.
- **Para cambiar el esquema, migración nueva; nunca editar una existente.**

Y la vía manual, siempre disponible:

```bash
ssh -p 65002 u821315052@46.202.183.237
cd ~/matriculas-app && ./desplegar.sh
```
