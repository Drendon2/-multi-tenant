# Sistema de Matrículas — versión PHP

> **Rama `multi-tenant`: versión multi-institución.** Varias instituciones en
> una sola instalación y una sola base. Lo que añade esta versión, cómo se
> instala, cómo se opera y cómo se extiende está en
> [`MULTI-INSTITUCION.md`](MULTI-INSTITUCION.md). Lo de abajo sigue valiendo
> para todo lo demás, salvo lo que dice del despliegue: **esta rama no se
> despliega ni se fusiona en `main`**.

Plataforma de autoservicio para inscripción, confirmación y asignación de grupos
en una escuela de artes (casas de cultura).

Es la **reconstrucción en Laravel + MariaDB** del proyecto original en Django +
PostgreSQL ([`Drendon2/sistema-matriculas`](https://github.com/Drendon2/sistema-matriculas)),
para poder desplegarlo en hosting compartido tradicional — que ejecuta PHP bajo
demanda y no admite un proceso Python persistente.

No es una traducción automática de código: son las mismas reglas de negocio y las
mismas pantallas, reimplementadas sobre otra pila. El proyecto Django sigue siendo
la especificación funcional de referencia.

> **Estado: en producción.** Las tres áreas del sistema —autoservicio del
> estudiante, panel de quien dicta y gestión de dirección— están terminadas,
> probadas y auditadas, con la suite entera en verde. Desplegado en
> https://escuelas.culturaelsantuario.com, con despliegue automático desde
> GitHub: cada `push` a `main` corre las pruebas y solo sube si pasan.

## Stack

| Capa | |
|---|---|
| Lenguaje | PHP 8.4 |
| Framework | Laravel 12 |
| Base de datos | MariaDB 10.5 |
| Plantillas | Blade |
| Autenticación | Sesiones nativas de Laravel, por `username` |
| Interacción | JavaScript plano, sin build step |

Sin Node ni compilación de assets: el CSS va como archivo estático y el JS son
cuatro ficheros sueltos, ninguno imprescindible. Es deliberado — el destino es
hosting compartido. Todo lo que hace el JavaScript es comodidad: las casillas de
selección múltiple, la carga diferida del Panel, el paso entre periodos con el
dedo. Sin él, las pantallas se marcan a mano y los enlaces siguen ahí.

## Qué resuelve

El público se inscribe desde su casa o su celular en una **promotoría** artística
(música, danza, teatro, pintura…), sin elegir horario. Quien la dicta confirma la
matrícula y luego crea **grupos** según su disponibilidad, repartiendo ahí a los ya
matriculados.

Algunas reglas que no se ven a simple vista:

- **Cupos garantizados por la base de datos**, no solo por la aplicación: un
  trigger con bloqueo de fila impide que dos solicitudes simultáneas se lleven el
  mismo último sitio.
- **Pedir la baja no es lo mismo que darse de baja.** Retirar una solicitud sin
  confirmar es inmediato; salirse de una matrícula activa es una solicitud que
  resuelve dirección, y hasta entonces el estudiante sigue inscrito y su sitio
  sigue ocupado.
- **La clase la verifican los estudiantes, no quien la dictó.** Quien registra la
  clase es parte interesada, así que queda «sin verificar» hasta que varios de
  los que estuvieron den fe, dentro de un plazo de 48 horas. En un grupo de uno o
  dos basta una confirmación: un requisito inalcanzable no verifica nada. Y da
  fe **quien consta que estuvo**: si el profesor le marcó asistencia, la clase le
  sale para confirmar aunque después la hayan movido de grupo o la matrícula se
  creara ese mismo día, más tarde que la clase.
- **Privacidad diferenciada por rol**: quién ve nombre, edad, teléfono, acudiente,
  encuesta o documento de identidad está definido de forma estricta (pensado para
  la Ley 1581 de Colombia). Ningún archivo subido se sirve por URL directa.
- **Un director dirige DEPARTAMENTOS, no la casa entera.** Se le asignan desde
  administración, y solo ve las promotorías de esos: su panel, sus alertas, su
  catálogo y su informe descargable van acotados. Sin ninguno asignado no ve
  ninguna — el recorte cierra en falso a propósito, para que un director recién
  creado no vea la institución completa mientras nadie decide qué le toca.
- **El rol de administrador no se reparte hacia arriba.** Un director no entra a
  la gestión de usuarios; a las personas llega por los grupos de sus
  departamentos. Y donde sí puede editar, no puede nombrar administradores ni
  tocar la cuenta de uno: si pudiera, se daría el rol a sí mismo —o le cambiaría
  la contraseña al administrador— y el recorte de arriba dejaría de significar
  nada.
- **A un mayor de edad no se le discute la salida.** Rechazar una cancelación
  solo cabe con menores, y existe para dar tiempo a hablar con el acudiente antes
  de que un niño se salga por su cuenta.
- **Configurable sin tocar código**: nombre, logo, color de acento, cuántas
  promotorías puede cursar alguien, qué papeles se exigen, el texto de la
  política de tratamiento de datos y **el servidor de correo** se editan desde la
  propia interfaz. Del formato de autorización que se firma, la entidad puede
  subir el suyo —una versión para mayores de edad y otra para menores— o dejar
  que lo imprima el sistema con sus datos dentro.

## Las tres áreas

**Estudiante** — catálogo de promotorías, inscripción, renovación con encuesta de
satisfacción, sus matrículas y el retiro, confirmación de clases, sus compañeros
y su perfil (foto, documento, papeles y encuesta demográfica).

**Panel** (quien dicta, dirección y administración) — confirmar y rechazar
solicitudes (una a una o por lote), fijar el cupo del periodo, crear grupos y
repartir en ellos a los matriculados (uno a uno o por lote), registrar clases,
pasar lista y consultar la ficha y la trayectoria de cada persona. También los
cursos, talleres y grupos de proyección que tenga a su cargo (ver abajo).

Desde la trayectoria de un estudiante, dirección puede además **mover una
matrícula de promotoría** cuando se inscribió en la que no era (ver abajo).

**Gestión** (dirección y administración) — el catálogo en cuatro niveles
(departamento → promotoría → grupo → estudiantes) con filtros de grupos por
departamento, promotoría y profesor; la ventana de matrículas, los cupos de todo
el catálogo de una vez, las cancelaciones por resolver (también por lote), los
usuarios con **buscador por nombre o cuenta** y filtros por catálogo y periodo,
los cursos, talleres y grupos de proyección, los ajustes de la institución y las
estadísticas agregadas.

El buscador del listado de usuarios busca por nombre y por nombre de cuenta, y
**no por documento ni por teléfono a propósito**: son datos de visibilidad
restringida, y un buscador que los aceptara dejaría a un director confirmar el
documento de alguien probando cifras hasta acertar.

## Mover una matrícula de promotoría

El estudiante se inscribió en la que no era y hay que arreglarlo. Se hace desde
su trayectoria (Gestión → Usuarios → su nombre → «Ver trayectoria»), en la fila
de la matrícula.

**Mueve la fila, no retira una y crea otra.** No fue una salida, fue un dato mal
puesto: la matrícula conserva su fecha de inscripción y el estudiante figura como
si siempre hubiera estado en la correcta. Retirar y crear le dejaría una
«retirada» en la equivocada, que en pantalla se lee como que se salió.

Al moverla **vuelve a pendiente** aunque estuviera activa, por la misma razón por
la que solo quien dicta pasa lista: confirmar es un acto de quien tiene a esa
persona en su salón, y el profesor de la promotoría nueva no la ha visto nunca.
Y **suelta el grupo**, que colgaba de la promotoría vieja.

Una matrícula **retirada también se mueve, y al moverla revive** como pendiente:
quien se salió y quiere entrar a otra no está corrigiendo un dato viejo, está
entrando. Vuelve a ocupar un cupo del estudiante, y el límite de promotorías por
periodo vuelve a contarla.

Quién y cuándo:

| | Ventana de matrículas abierta | Ventana cerrada |
|---|---|---|
| Administrador | sí | **sí** |
| Director | sí | no |
| Profesor | no | no |

Siempre dentro del **periodo en curso**. Mover una matrícula de un periodo
cerrado cambiaría certificados ya emitidos y la antigüedad que cuenta el informe,
y eso no es corregir un error de captura.

Con la ventana cerrada el periodo ya está repartido —hay grupos armados y listas
pasadas— y mover a alguien deja de ser una corrección para ser un cambio de plan;
por eso esa excepción se queda en una sola persona. Al director, en el sitio del
botón, la pantalla le dice por qué no está en vez de dejar un hueco.

Dos rechazos vienen de la base y se traducen a algo legible: la promotoría de
destino **sin cupo** (el trigger corre también en `UPDATE`) y el estudiante que
**ya tuvo matrícula en la de destino** ese periodo, porque el único
`(estudiante, promotoría, periodo)` cuenta también las retiradas.

**El estudiante no necesita nada de esto** para cambiarse por su cuenta: al
retirarse con la ventana abierta su cupo y su ranura quedan libres y se matricula
en otra desde el catálogo.

## Cursos, talleres y grupos de proyección

Es la otra mitad del sistema, y funciona **al revés que las promotorías**. A una
promotoría se entra con una matrícula que alguien confirma; a esto se entra por
un **enlace que alguien comparte**, sin cuenta y sin matrícula. Nada de lo que
hay aquí toca el límite de promotorías del estudiante ni pasa por el trigger de
cupos: son tablas propias que no conocen a `Matricula`.

Se crean en **Gestión del catálogo académico**, en dos botones:

- **Cursos y talleres.** Se pregunta el nombre, **cuántas clases**, el
  responsable y el cupo. El tipo no se elige: una sola clase es un taller y dos
  o más son un curso, porque un taller es exactamente eso. Al guardar se pasa a
  una segunda pantalla con una casilla de fecha por clase.
- **Grupos de proyección.** Igual, pero sin fechas: una banda o un coro ensayan
  cuando toca, y la sesión nace al oprimir el botón.

Cada uno genera su **enlace**. Quien lo abre llena cinco campos —nombre,
documento, fecha de nacimiento, teléfono y, opcional, correo— y queda inscrito.
Si el documento coincide con el de un estudiante de la casa, queda vinculado a
su ficha; para la mayoría no habrá coincidencia y eso es lo normal. El enlace
deja de admitir gente por dos motivos distintos, y la pantalla dice cuál: el
**cupo lleno**, que se cierra solo, o el **interruptor** de dirección, que es lo
único capaz de parar una actividad sin tope.

En el **Panel**, quien esté a cargo ve los inscritos, oprime «Iniciar» cuando la
clase empieza de verdad y pasa lista. Puede además añadir a quien llegó sin
inscribirse, solo con el nombre: nadie le va a pedir el documento con la clase
empezando. Dirección **ve** todo esto pero no lo escribe — es la misma regla que
separa gestionar una promotoría de dictarla, y aquí pesa más, porque estos
inscritos no tienen cuenta con la que confirmar después que la sesión se dio.

## Informes descargables

Dos, en CSV, que abren con doble clic en Excel y al importar en Hojas de cálculo:

| | |
|---|---|
| **Estudiantes por grupo** | La lista que se lleva impresa al salón: nombre, edad, teléfono y acudiente. Se puede pedir entera, de una promotoría o de un solo grupo, y hay enlace en las tres pantallas donde se entra a un grupo. |
| **Informe completo de la institución** | Todo el padrón con rol, contacto, promotoría, nivel, tiempo cursado y la encuesta demográfica. **Solo administrador**, y con aviso en pantalla antes de descargar. |

El segundo es el único sitio donde la encuesta demográfica sale con nombre y
apellido: en pantalla siempre va agregada. Fue una decisión de dirección, tomada
a sabiendas, para poder reportar a quien financia la institución.

CSV y no `.xlsx` a propósito: un `.xlsx` real pediría `phpoffice/phpspreadsheet`
—unos 10 MB en `vendor/` y bastante memoria por descarga— y el destino es hosting
compartido. Llevan BOM UTF‑8 y separador `;`, que es lo que hace que Excel en
español los abra con las tildes bien y en columnas.

## Horario de los grupos

El horario de un grupo son **datos y no texto**: una fila por sesión en
`sesiones_grupo`, con día (1 = lunes … 6 = sábado), hora de inicio y hora de
fin. Un grupo puede reunirse varios días —«Martes y jueves 4:00 p. m. a 6:00
p. m.»— con un máximo de una sesión por día.

Se crea marcando días en una rejilla, la misma en el Panel y en Gestión. El
motor no admite una sesión que termine antes de empezar, ni dos el mismo día,
ni el domingo.

El texto del horario se **deriva** de las sesiones y no se guarda: cuando
existían las dos cosas acababan discrepando, y entonces no hay forma de saber
cuál miente. Los días que comparten hora se enuncian juntos, que es como lo dice
la gente.

De ahí sale la **rejilla semanal del perfil**: al estudiante los grupos donde
está repartido, a quien dicta los grupos que da, siempre del periodo en curso.
Las filas son las franjas que de verdad se usan y no las horas del reloj.

## Certificado de matrícula

Un PDF que acredita que alguien está matriculado, para llevar a un colegio o a
una empresa. En dos formas: el de **una matrícula** —promotoría, área, grupo y
horario, docente, fecha— con botón en cada fila de «Mis matrículas» y de la
trayectoria; y el **reunido**, con todas las promotorías vigentes del periodo en
curso, desde «Mi perfil» y desde la ficha.

Solo se certifican las matrículas **activas** y las **finalizadas** (activas de
un periodo ya cerrado, que acreditan haber cursado). Una pendiente no: nadie ha
confirmado todavía que esa persona esté en el curso.

Quién puede bajarlo va por el vínculo, no por el rol: el propio estudiante,
dirección, y el profesor **solo el de la promotoría que dicta**. El reunido no se
le ofrece al profesor porque lista promotorías que la ficha le esconde.

La firma que lo sella se carga en **Gestión → Institución**: imagen, nombre y
cargo de quien firma. Se guarda en PNG y no en WebP como el resto —el generador
de PDF no entiende WebP— y, a diferencia del logo, **no se sirve en abierto**:
una firma escaneada en una URL pública se la lleva cualquiera. Sin firma cargada
el certificado se genera igual, con el espacio en blanco para firmarlo a mano.

Lo genera `dompdf` (`barryvdh/laravel-dompdf`). Necesita `dom`, `mbstring` y
`gd`, las tres ya habilitadas en el hosting.

## Estadísticas

Matrícula por departamento y promotoría con permanencia y deserción, mapa de
calor de la actividad de la casa, ranking de profesores por clases dictadas —con
cuántas les verificaron sus estudiantes al lado— y ranking de estudiantes por
constancia, más la encuesta demográfica y la de satisfacción agregadas.

Se camina entre periodos con flechas (o deslizando el dedo en el móvil), lo mismo
que el panel de asistencia de cada perfil. Solo se ofrecen los periodos donde esa
persona tiene algo: una flecha que lleva a un panel vacío no informa de nada.

**Qué se mueve con la flecha y qué no**, porque es la lectura equivocada más
probable de esa pantalla: el periodo mueve las matrículas —«Estudiantes activos»
incluido—, el mapa de actividad y los dos rankings. El catálogo (promotorías y
grupos) y la encuesta demográfica son de toda la institución y no cambian. La
pantalla lo dice en prosa encima de las cifras.

## Seguridad

Lo que hay puesto, por si alguien tiene que auditarlo o extenderlo:

- **Límite de intentos** en todas las puertas que se pueden empujar sin cuenta:
  entrar, registro, inscripción, el enlace de una actividad y la recuperación de
  contraseña. El del login cuenta por **usuario + IP** y no por IP sola: una
  escuela entera sale a internet por una sola dirección, y con el contador por IP
  treinta estudiantes entrando desde la sala de cómputo se bloquearían entre sí.
  El de recuperación lleva **dos contadores a la vez** —uno por cuenta y otro por
  IP— porque son dos abusos distintos: llenarle el buzón a una persona concreta,
  y rociar cuentas distintas.
- **Recuperar la contraseña por correo**, con enlace de un solo uso que caduca en
  una hora. La pantalla **contesta lo mismo pase lo que pase** —exista la cuenta
  o no, tenga correo o no, salga el envío o falle—: distinguir los casos la
  convertiría en una forma de averiguar quién tiene cuenta. El correo es
  opcional en este sistema, así que esa pantalla dice también, a todo el mundo,
  que sin uno registrado hay que pedírselo a la institución.
- **Ocho caracteres mínimo** en las contraseñas, declarado una sola vez con
  `Password::defaults()`. No es un puerto: el original no valida la contraseña
  por ninguna parte.
- **Archivos fuera del webroot.** Fotos y documentos viven en
  `storage/app/private` y se entregan por rutas que comprueban antes quién los
  pide. Las fotos se re-codifican a WebP con GD, lo que destruye cualquier carga
  útil incrustada.
- **CSRF** en todos los formularios que cambian estado, y consultas siempre por
  el ORM: no hay una sola concatenación de SQL con datos del usuario. Cuando el
  testigo caduca —la cookie de sesión vive dos horas y en un celular la pestaña
  del login se queda abierta días— **se vuelve al formulario con el aviso
  puesto**, no a la página «419 Page Expired» de Laravel, que está en inglés y no
  ofrece salida. El rechazo es el mismo; lo que cambia es lo que ve quien lo
  sufre. Mismo trato que el límite de intentos.
- **Celdas de CSV neutralizadas**: un valor que empieza por `=`, `+`, `-` o `@`
  lo ejecutaría Excel al abrir el archivo, y aquí los nombres y los barrios los
  escribe el público.
- **Desactivar una cuenta expulsa en el acto**, y cambiar una contraseña cierra
  las demás sesiones de esa cuenta. Lo primero lo hace el middleware
  `CuentaActiva`, que mira `activo` en cada petición y no solo al entrar; lo
  segundo, `AuthenticateSession`. Los dos van en el grupo `web` entero y no
  pegados al rol, porque `/post-login` y `/mi-perfil` no llevan rol y por ahí se
  colaría.
- **El enlace de una actividad** es la única ruta que escribe sin autenticar. Lo
  que autoriza es el token de la URL —32 caracteres de `Str::random`, que va por
  `random_bytes`— y nada más. No crea cuentas, no sube archivos y no toca ninguna
  tabla del sistema de matrículas.

## Puesta en marcha local

Requisitos: PHP 8.4 (con `gd`, `exif`, `pdo_mysql`, `mbstring`, `intl`, `zip`),
Composer y MariaDB 10.5.

```bash
git clone https://github.com/Drendon2/sistema-matriculasPHP.git
cd sistema-matriculasPHP
composer install
cp .env.example .env
php artisan key:generate
```

Completa las credenciales de tu base de datos en `.env` y luego:

```bash
php artisan migrate
php artisan instalar --ejemplo
php artisan serve
```

El repositorio no incluye datos de demostración: la base arranca vacía a
propósito, porque sembrar datos de ejemplo significa sembrar contraseñas
conocidas. Y una base vacía no deja abrir casi ninguna pantalla, así que
`php artisan instalar --ejemplo` monta de un tirón lo mínimo para recorrerla:
institución, documentos, departamentos, un periodo **en curso** con las
matrículas abiertas y dos administradores. Las cuentas son `admin` y `admin.dos`,
las dos con la contraseña `administrador`. Es una instalación de juguete: el
comando se niega a correr si `APP_ENV=production`, sin bandera que lo salte.

Para llenarla además de gente, clases y asistencia:

```bash
php artisan simular
```

**Para instalar de verdad, para una institución**, el mismo comando sin bandera
pregunta los datos por consola —incluidas las dos cuentas de administrador, que
no son opcionales— y no escribe nada hasta el final:

```bash
php artisan instalar
```

Cubre hasta dejar el periodo en curso; las promotorías, los cupos y los grupos se
montan después desde Gestión. Se niega a correr si la base ya tiene datos.

La zona horaria está en `America/Bogota` y de ella dependen dos reglas —si un
grupo ya tiene clase registrada hoy, y el plazo de 48 horas para confirmarla—,
así que conviene ajustarla en `.env` antes que nada si la institución está en
otro huso.

## Pruebas

```bash
php artisan test
```

Corren contra **MariaDB, no contra SQLite**, y no es una preferencia: buena parte
de lo que hay que probar son garantías del motor —columnas generadas con índice
único, triggers con `SIGNAL`, `SELECT … FOR UPDATE`— y SQLite no tiene ninguna de
las tres. Necesitan una base `test_matriculas` con los mismos permisos.

Hay además dos guiones que comprueban el esquema contra SQL crudo, incluida la
carrera de dos transacciones por el último cupo:

```bash
php database/verificacion_esquema.php --borrar-datos
php database/verificacion_concurrencia.php --borrar-datos
```

**Estos dos VACÍAN la base antes de empezar**, porque necesitan un escenario
conocido. La bandera es obligatoria y no es una formalidad: sin ella el guion se
niega y te dice cuántas filas ibas a perder. Además solo corren con
`APP_ENV=local` — en un servidor no se conectan siquiera, y esa barrera cierra
en falso: un `.env` sin `APP_ENV`, o con cualquier otro valor, tampoco pasa.

### Las mismas puertas, antes de empujar

Cada `push` a `main` dispara el CI, y si algo no pasa hay que esperar, mirar el
log, arreglar y volver a empujar — con `main` en rojo mientras tanto. Hay dos
hooks versionados en `.githooks/` que corren eso mismo en local. **Son
opcionales y se activan a mano**, con una línea:

```bash
git config core.hooksPath .githooks
```

| Hook | Qué corre | Cuánto tarda |
|---|---|---|
| `pre-commit` | Pint | ~2 s |
| `pre-push` | Base de datos, pruebas, Pint y PHPStan | ~2 min 15 s |

El `pre-push` no es rápido y conviene saberlo antes de activarlo: casi todo son
las pruebas. Los dos se saltan con `--no-verify` cuando haga falta.

**Lo que el `pre-push` NO corre es la verificación del esquema**, y es a
propósito: ese guion vacía la base con la que se encuentre. Tiene su barrera
(`APP_ENV=local` y `--borrar-datos`), pero un guion destructivo disparándose solo
en cada push es justo la clase de automatismo que ya costó un susto aquí. Se
queda a mano y en el CI, que corre contra una base de usar y tirar.

Para desactivarlos: `git config --unset core.hooksPath`.

## Despliegue

El *document root* del dominio tiene que apuntar a `public/`. Dejarlo en la raíz
del proyecto expone el `.env` y el código fuente entero a internet.

### Pasos

```bash
composer install --no-dev --optimize-autoloader

cp .env.production.example .env     # NO el .env.example, que es el de desarrollo
# edita el .env: dominio, base de datos y las líneas marcadas «CAMBIAR»

php artisan key:generate            # se genera aquí, no se copia la de local
php artisan migrate --force         # --force: en producción pide confirmación

php artisan config:cache
php artisan route:cache
php artisan view:cache
```

`vendor/` no está versionado, de ahí el primer paso. Las tres cachés del final
son las que hacen que cada petición no vuelva a leer y compilar la configuración,
las rutas y las plantillas; **hay que repetirlas después de cada despliegue**, y
`config:cache` además de cada cambio en el `.env` — con la configuración cacheada,
editar el `.env` deja de tener efecto hasta que se regenera.

### Las cuatro líneas que importan del `.env`

`.env.production.example` ya viene con ellas puestas, pero conviene saber por qué
están:

| | |
|---|---|
| `APP_DEBUG=false` | Con `true`, cualquiera que provoque un error 500 recibe la traza completa: credenciales de la base y el contenido de las consultas, que aquí incluye nombres, teléfonos y documentos de identidad de menores. |
| `APP_ENV=production` | Además de la configuración, es lo único que impide que `php artisan simular` siembre 300 cuentas de prueba con contraseña conocida, y lo mismo con las dos que crea `php artisan instalar --ejemplo`. `simular` admite `--forzar` para saltárselo; `instalar --ejemplo` no admite nada. |
| `SESSION_SECURE_COOKIE=true` | Sin ella la cookie de sesión viaja también por http plano. |
| `LOG_LEVEL=error` | En `debug` se escribe cada consulta: llena la cuota de disco del plan compartido y deja datos personales en un archivo que nadie vigila. |

### Lo demás

Las fotos y las copias de documentos se guardan en `storage/app/private`, **fuera
de `public/`**, y solo se entregan por una ruta que comprueba antes quién las
pide. Hay que dejarle permiso de escritura a `storage/` y a `bootstrap/cache/`.

Conviene crear **un segundo administrador** antes de abrir el sistema. La cuenta
de un administrador solo la edita otro administrador, así que si se pierde el
acceso al único que hay, ni un director puede recuperarlo: habría que tocar la
base de datos.

Los respaldos se sacan con `./respaldar.sh`, que guarda las dos cosas que no se
pueden reconstruir desde el repositorio —la base **con sus triggers** y los
archivos subidos—. Su carpeta de destino no puede quedar bajo `public/`.

El proyecto **no necesita** el scheduler de Laravel: los plazos se calculan al
leer, no con tareas programadas.

## Licencia

Proyecto privado.
