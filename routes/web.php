<?php

use App\Http\Controllers\ArchivoController;
use App\Http\Controllers\Auth\CompletarDatosController;
use App\Http\Controllers\Auth\InscripcionController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PostLoginController;
use App\Http\Controllers\Auth\RegistroController;
use App\Http\Controllers\Auth\RestablecerClaveController;
use App\Http\Controllers\CarneController;
use App\Http\Controllers\CatalogoController;
use App\Http\Controllers\CertificadoController;
use App\Http\Controllers\ClaseController;
use App\Http\Controllers\ConsentimientoController;
use App\Http\Controllers\EnlacePromotoriaController;
use App\Http\Controllers\ExternaController;
use App\Http\Controllers\FichaController;
use App\Http\Controllers\Gestion;
use App\Http\Controllers\IconoController;
use App\Http\Controllers\InformeController;
use App\Http\Controllers\InscripcionActividadController;
use App\Http\Controllers\MatricularController;
use App\Http\Controllers\MiPerfilController;
use App\Http\Controllers\MisClasesController;
use App\Http\Controllers\MisCompanerosController;
use App\Http\Controllers\MisEstadisticasController;
use App\Http\Controllers\MisMatriculasController;
use App\Http\Controllers\PanelActividadController;
use App\Http\Controllers\PanelController;
use App\Http\Controllers\PanelGrupoController;
use App\Http\Controllers\PoliticaDatosController;
use App\Http\Controllers\RenovarController;
use App\Http\Controllers\SuplantacionController;
use App\Http\Controllers\TemaController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas
|--------------------------------------------------------------------------
|
| Los nombres siguen a los del original (`matriculas/urls.py`) para que las dos
| versiones se puedan comparar de un vistazo, cambiando solo el guion bajo por
| guion medio, que es la convencion de Laravel.
|
| El orden sigue al del recorrido de la gente: primero lo publico, luego el
| estudiante, luego el personal y por ultimo Gestion. Los archivos subidos van
| aparte al final, porque no son una pantalla sino una entrega controlada.
|
*/

// ---------------------------------------------------------------------------
// Publico, sin sesion
// ---------------------------------------------------------------------------

// Los tres POST de aqui van limitados, y son los unicos del sistema que lo
// necesitan: son las tres puertas que se pueden empujar sin tener cuenta. Los
// GET quedan libres — abrir el formulario no cuesta nada y limitarlo solo
// molestaria a quien recarga.
//
// El del login cuenta por usuario+IP a traves del limitador con nombre `entrar`
// (ver `AppServiceProvider`); los otros dos van por IP, que es lo que
// corresponde cuando lo que se frena es crear cuentas en masa.
// La politica de tratamiento de datos. Publica y sin sesion a proposito: su
// enlace va en el pie de TODAS las pantallas, incluidas las tres de quien
// todavia no tiene cuenta. Una politica que solo se lee despues de entregar los
// datos no cumple para lo que existe.
//
// Fuera de `guest` por lo mismo que la de abajo: quien ya entro tiene que poder
// leerla sin cerrar su sesion primero.
Route::get('/tratamiento-de-datos', PoliticaDatosController::class)->name('politica-datos');

// El enlace de una actividad. Va FUERA de `guest`, al contrario que las tres de
// abajo: aquellas ofrecen crear una cuenta y no tienen sentido con la sesion ya
// abierta, pero esta no crea ninguna. Y quien comparte el enlace —el profesor,
// el director— tiene que poder abrirlo para comprobar que funciona sin cerrar
// su sesion primero.
Route::get('/inscribirse/{token}', [InscripcionActividadController::class, 'mostrar'])
    ->name('actividad-inscribirse');
Route::post('/inscribirse/{token}', [InscripcionActividadController::class, 'guardar'])
    ->middleware('throttle:10,1');

// El enlace de UNA promotoria (29/09/2026), que matricula aunque la ventana
// este cerrada si administracion o direccion lo encendieron. El GET va fuera
// de `guest` porque atiende a los tres: sin cuenta, estudiante y personal que
// comprueba que funciona. Los dos POST, cada uno con su puerta.
Route::get('/unirse/{token}', [EnlacePromotoriaController::class, 'mostrar'])
    ->name('promotoria-enlace');
Route::post('/unirse/{token}', [InscripcionController::class, 'guardarPorEnlace'])
    ->middleware(['guest', 'throttle:10,1'])
    ->name('promotoria-enlace.inscribir');
Route::post('/unirse/{token}/matricularme', [EnlacePromotoriaController::class, 'matricularme'])
    ->middleware(['auth', 'rol:estudiante'])
    ->name('promotoria-enlace.matricularme');

Route::middleware('guest')->group(function () {
    Route::get('/entrar', [LoginController::class, 'mostrar'])->name('login');
    Route::post('/entrar', [LoginController::class, 'entrar'])
        ->middleware('throttle:entrar')
        ->name('login.entrar');

    // ---------------------------------------------------------------------
    // «Olvide mi contrasena»
    // ---------------------------------------------------------------------
    //
    // Va dentro de `guest` como sus vecinas: quien ya entro no necesita
    // recuperar nada, y para cambiar su clave tiene «Mi perfil», que ademas le
    // pide la actual.
    //
    // LOS DOS POST VAN LIMITADOS, y no por el mismo motivo:
    //
    // - El de pedir el enlace va por el limitador con nombre `clave-olvidada`,
    //   que lleva DOS limites: por cuenta+IP y por IP. Sin freno, cualquiera
    //   puede usarlo para inundar el buzon de otra persona, y de paso agotar la
    //   cuota diaria del servidor de correo —que en el plan de hoy existe— y
    //   dejar sin enlace a quien lo necesite. Por que son dos y por que el de
    //   IP es generoso esta escrito en `AppServiceProvider`: hay un CDN delante
    //   y este proyecto no configura `TrustProxies`.
    //
    // - El de guardar la contrasena va por el limitador con NOMBRE
    //   `clave-nueva`, que cuenta por TOKEN: es lo unico que se puede adivinar
    //   aqui, y un contador por IP le regalaria intentos a quien cambie de red.
    //   Adivinar 64 caracteres al azar no es un ataque realista, pero el freno
    //   cuesta una linea.
    Route::get('/clave/olvidada', [RestablecerClaveController::class, 'pedir'])
        ->name('clave-olvidada');
    Route::post('/clave/olvidada', [RestablecerClaveController::class, 'enviar'])
        ->middleware('throttle:clave-olvidada')
        ->name('clave-olvidada.enviar');

    // El token va en la URL y NO se le pone restriccion de formato: uno mal
    // escrito tiene que llegar al controlador para que conteste «ese enlace ya
    // no sirve, pide otro», que es lo util. Con un `where` encima seria un 404,
    // o sea «esta pagina no existe» para quien abrio su correo tarde.
    Route::get('/clave/nueva/{token}', [RestablecerClaveController::class, 'formulario'])
        ->name('clave-nueva');
    Route::post('/clave/nueva/{token}', [RestablecerClaveController::class, 'guardar'])
        ->middleware('throttle:clave-nueva')
        ->name('clave-nueva.guardar');

    Route::get('/registro', [RegistroController::class, 'mostrar'])->name('registro');
    Route::post('/registro', [RegistroController::class, 'guardar'])
        ->middleware('throttle:5,1')
        ->name('registro.guardar');

    Route::get('/inscripcion', [InscripcionController::class, 'mostrar'])->name('inscripcion');
    Route::post('/inscripcion', [InscripcionController::class, 'guardar'])
        ->middleware('throttle:10,1')
        ->name('inscripcion.guardar');

    // El carne que se ensena nada mas terminar de inscribirse, cuando todavia no
    // hay sesion. QUIEN es sale de la SESION y no del camino: una URL con el
    // perfil dentro entregaria el carne de cualquiera a cualquiera. Por eso no
    // llevan parametro y por eso viven aqui, en `guest`, junto al formulario
    // del que salen.
    Route::get('/inscripcion/carne', [CarneController::class, 'trasInscribirse'])
        ->name('carne-recien-inscrito');
    Route::get('/inscripcion/carne/imagen', [CarneController::class, 'imagenTrasInscribirse'])
        ->name('carne-recien-inscrito-imagen');
});

Route::post('/salir', [LoginController::class, 'salir'])->middleware('auth')->name('logout');

// ---------------------------------------------------------------------------
// Con sesion
// ---------------------------------------------------------------------------

Route::middleware('auth')->group(function () {
    Route::get('/post-login', PostLoginController::class)->name('post-login');

    Route::get('/pendiente-aprobacion', [PostLoginController::class, 'pendienteAprobacion'])
        ->name('pendiente-aprobacion');

    // Documento y correo del personal que no los tiene. Sin rol, como Mi
    // perfil: la barrera (`DatosDelPersonal`) mira el rol por su cuenta.
    Route::get('/completar-datos', [CompletarDatosController::class, 'mostrar'])
        ->name('completar-datos');
    Route::post('/completar-datos', [CompletarDatosController::class, 'guardar'])
        ->name('completar-datos.guardar');
});

// ---------------------------------------------------------------------------
// Estudiante
// ---------------------------------------------------------------------------

Route::middleware(['auth', 'rol:estudiante'])->group(function () {
    Route::get('/', CatalogoController::class)->name('promotorias-disponibles');

    Route::post('/matricular/{promotoria}', MatricularController::class)->name('matricular');

    Route::get('/mis-matriculas', [MisMatriculasController::class, 'index'])->name('mis-matriculas');
    // El retiro pasa por una pantalla intermedia que pide la encuesta de salida.
    // Es el unico momento en que esa persona sigue estando: quien se va no
    // vuelve a entrar a contestar nada.
    Route::get('/mis-matriculas/{matricula}/retirar', [MisMatriculasController::class, 'confirmarRetiro'])
        ->name('mis-matriculas.confirmar-retiro');
    Route::post('/mis-matriculas/{matricula}/retirar', [MisMatriculasController::class, 'retirar'])
        ->name('mis-matriculas.retirar');
    // Echarse atras de una cancelacion todavia sin resolver.
    Route::post('/mis-matriculas/{matricula}/seguir', [MisMatriculasController::class, 'seguir'])
        ->name('mis-matriculas.seguir');

    Route::get('/renovar', [RenovarController::class, 'mostrar'])->name('renovar-matricula');
    Route::post('/renovar', [RenovarController::class, 'guardar'])->name('renovar-matricula.guardar');

    Route::get('/mis-clases', [MisClasesController::class, 'index'])->name('mis-clases');
    Route::post('/mis-clases/{clase}/confirmar', [MisClasesController::class, 'confirmar'])
        ->name('confirmar-clase');
    Route::post('/mis-clases/{clase}/quitar-confirmacion', [MisClasesController::class, 'retirar'])
        ->name('retirar-confirmacion-clase');

    Route::get('/mis-companeros', MisCompanerosController::class)->name('mis-companeros');
});

// «Mi perfil» es de TODOS los roles, incluidas las cuentas sin rol asignado
// todavia: es donde suben su foto y contestan la encuesta mientras esperan que
// direccion les asigne uno.
Route::middleware('auth')->group(function () {
    Route::get('/mi-perfil', [MiPerfilController::class, 'mostrar'])->name('mi-perfil');
    Route::post('/mi-perfil', [MiPerfilController::class, 'guardar'])->name('mi-perfil.guardar');

    // El carne QR propio. Va en el grupo de «Mi perfil» y no en el de
    // estudiante aunque hoy solo un estudiante tenga carne: el corte por rol lo
    // hace el controlador y contesta explicando —«el carne es de los
    // estudiantes»—, mientras que el middleware daria un rebote mudo a quien
    // llegue por un enlace viejo.
    Route::get('/mi-carne', [CarneController::class, 'mio'])->name('mi-carne');
    Route::get('/mi-carne/imagen', [CarneController::class, 'imagenMia'])->name('mi-carne-imagen');
    Route::post('/mi-carne/renovar', [CarneController::class, 'renovarElMio'])->name('mi-carne-renovar');
});

// El tema es del APARATO y no de la cuenta, asi que su ruta va FUERA de `auth`:
// la galleta sobrevive al cierre de sesion, y quien eligio oscuro no debe
// encontrarse la pantalla de entrar en blanco. Ver `Support\Tema`.
Route::post('/tema', TemaController::class)->name('tema');

// ---------------------------------------------------------------------------
// Panel: el personal (todos los roles menos estudiante)
// ---------------------------------------------------------------------------

Route::middleware(['auth', 'rol:administrador,director,profesor'])->group(function () {
    Route::get('/panel', [PanelController::class, 'index'])->name('panel');

    // El cuerpo de una promotoria, que el indice pide al desplegarla. Es una
    // pagina de verdad y no un fragmento de una API: sin JavaScript se abre
    // igual y se lee, que es lo que la deja funcionando sin el.
    Route::get('/panel/promotoria/{promotoria}/cuerpo', [PanelController::class, 'cuerpo'])
        ->name('panel-promotoria-cuerpo');

    Route::post('/panel/matriculas/{matricula}/confirmar', [PanelController::class, 'confirmar'])
        ->name('panel-confirmar-matricula');
    Route::post('/panel/matriculas/{matricula}/rechazar', [PanelController::class, 'rechazar'])
        ->name('panel-rechazar-matricula');
    Route::post('/panel/matriculas/{matricula}/asignar-grupo', [PanelController::class, 'asignarGrupo'])
        ->name('panel-asignar-grupo');

    // Repartir a alguien en VARIOS grupos. Es una pagina de verdad con su URL
    // —el Panel la abre en un modal, y sin JavaScript se abre y funciona
    // igual—, como las confirmaciones de borrado y el formulario de usuario.
    Route::get('/panel/matriculas/{matricula}/grupos', [PanelController::class, 'grupos'])
        ->name('panel-grupos');
    Route::post('/panel/matriculas/{matricula}/grupos', [PanelController::class, 'guardarGrupos'])
        ->name('panel-guardar-grupos');

    Route::post('/panel/promotoria/{promotoria}/cupo', [PanelController::class, 'cupo'])
        ->name('panel-cupo-promotoria');

    // El enlace de inscripcion de la promotoria y su QR. Lo ven y lo encienden
    // quienes gestionan la promotoria, tambien su profesor.
    Route::get('/panel/promotoria/{promotoria}/enlace', [EnlacePromotoriaController::class, 'panel'])
        ->name('panel-enlace-promotoria');
    Route::get('/panel/promotoria/{promotoria}/enlace/qr', [EnlacePromotoriaController::class, 'qr'])
        ->name('panel-enlace-promotoria-qr');
    Route::post('/panel/promotoria/{promotoria}/enlace', [EnlacePromotoriaController::class, 'abrir'])
        ->name('panel-enlace-promotoria-abrir');
    Route::post('/panel/promotoria/{promotoria}/enlace/renovar', [EnlacePromotoriaController::class, 'renovar'])
        ->name('panel-enlace-promotoria-renovar');
    Route::post('/panel/promotoria/{promotoria}/asignar-grupo-lote', [PanelController::class, 'asignarGrupoLote'])
        ->name('panel-asignar-grupo-lote');
    Route::post('/panel/promotoria/{promotoria}/pendientes-lote', [PanelController::class, 'resolverPendientesLote'])
        ->name('panel-pendientes-lote');

    // Grupos
    // Cursos, talleres y grupos de proyeccion, del lado de quien los DA. Los
    // crea Gestion; aqui se dirige lo que ya existe.
    Route::get('/panel/actividades', [PanelActividadController::class, 'index'])
        ->name('panel-actividades');
    Route::get('/panel/actividades/{actividad}', [PanelActividadController::class, 'ver'])
        ->name('panel-actividad');
    Route::get('/panel/actividades/{actividad}/qr', [PanelActividadController::class, 'qr'])
        ->name('panel-actividad-qr');
    Route::post('/panel/actividades/{actividad}/iniciar-hoy', [PanelActividadController::class, 'iniciarHoy'])
        ->name('panel-actividad-iniciar-hoy');
    Route::post('/panel/sesiones/{sesion}/iniciar', [PanelActividadController::class, 'iniciar'])
        ->name('panel-actividad-iniciar');
    Route::get('/panel/sesiones/{sesion}/lista', [PanelActividadController::class, 'lista'])
        ->name('panel-actividad-lista');
    Route::post('/panel/sesiones/{sesion}/lista', [PanelActividadController::class, 'guardarLista']);
    Route::post('/panel/sesiones/{sesion}/anadir', [PanelActividadController::class, 'anadirEnSesion'])
        ->name('panel-actividad-anadir');

    // PROGRAMAS EXTERNOS, del lado del profesor que va.
    //
    // La lista se ESCRIBE aqui y no se inscribe por ningun enlace: es lo que
    // separa un programa externo de los otros tres tipos de actividad. Ver la
    // cabecera de `Actividad`.
    Route::post('/panel/actividades/{actividad}/lista', [PanelActividadController::class, 'anadirALista'])
        ->name('panel-externo-anadir');
    Route::post('/panel/inscritos/{inscrito}/quitar', [PanelActividadController::class, 'quitarDeLista'])
        ->name('panel-externo-quitar');
    // Verificar leyendo el QR de la institucion. Por POST y no por GET aunque
    // parezca una consulta, por lo mismo que `clase-comprobar-carne`: el codigo
    // iria en la URL, y una URL se queda escrita en los registros del servidor
    // y del CDN.
    Route::post('/panel/sesiones/{sesion}/verificar-qr', [PanelActividadController::class, 'verificarConQr'])
        ->name('panel-externo-verificar-qr');

    Route::get('/panel/promotoria/{promotoria}/grupos/nuevo', [PanelGrupoController::class, 'crear'])
        ->name('panel-grupo-nuevo');
    Route::post('/panel/promotoria/{promotoria}/grupos/nuevo', [PanelGrupoController::class, 'guardar']);
    Route::get('/panel/grupos/{grupo}/editar', [PanelGrupoController::class, 'editar'])
        ->name('panel-grupo-editar');
    Route::post('/panel/grupos/{grupo}/editar', [PanelGrupoController::class, 'actualizar']);
    Route::post('/panel/grupos/{grupo}/eliminar', [PanelGrupoController::class, 'eliminar'])
        ->name('panel-grupo-eliminar');

    // Clases y asistencia
    Route::post('/panel/grupos/{grupo}/clase-nueva', [ClaseController::class, 'nueva'])
        ->name('panel-clase-nueva');
    // Reponer una clase que la bandeja de alertas marco como falta.
    Route::post('/panel/reposiciones/{omision}', [ClaseController::class, 'reponer'])
        ->name('panel-reponer-clase');
    Route::get('/panel/grupos/{grupo}/clases', [ClaseController::class, 'delGrupo'])
        ->name('grupo-clases');
    Route::get('/panel/clases/{clase}/asistencia', [ClaseController::class, 'asistencia'])
        ->name('clase-asistencia');
    Route::post('/panel/clases/{clase}/asistencia', [ClaseController::class, 'guardarAsistencia']);

    // A quien pertenece un carne leido, cuando la pantalla no lo reconoce. Va
    // por POST y no por GET aunque solo lea: el codigo iria en la URL, y una URL
    // se queda escrita en los registros del servidor y del CDN.
    Route::post('/panel/clases/{clase}/carne', [ClaseController::class, 'comprobarCarne'])
        ->name('clase-comprobar-carne');

    // Fichas
    Route::get('/panel/usuario/{usuario}', [FichaController::class, 'usuario'])
        ->name('detalle-usuario');

    // El carne de un estudiante. SOLO ADMINISTRACION, y por eso el rol se
    // comprueba en el controlador ademas de aqui: este grupo deja entrar
    // tambien a profesores y directores, y el carne de otra persona no es de
    // ellos (ver la cabecera de `CarneController`).
    Route::get('/panel/usuario/{usuario}/carne', [CarneController::class, 'deEstudiante'])
        ->middleware('rol:administrador')
        ->name('carne-estudiante');
    Route::get('/panel/usuario/{usuario}/carne/imagen', [CarneController::class, 'imagenDeEstudiante'])
        ->middleware('rol:administrador')
        ->name('carne-estudiante-imagen');
    Route::post('/panel/usuario/{usuario}/carne/renovar', [CarneController::class, 'renovarDeEstudiante'])
        ->middleware('rol:administrador')
        ->name('carne-estudiante-renovar');
    // La hoja de carnes para imprimir, nueve por carta (25/09/2026). La misma
    // puerta que el carne de otra persona: SOLO administracion, comprobado
    // tambien en el controlador.
    Route::get('/panel/grupo/{grupo}/carnes', [CarneController::class, 'hojaDeGrupo'])
        ->middleware('rol:administrador')
        ->name('carnes-grupo');
    Route::get('/panel/promotoria/{promotoria}/carnes', [CarneController::class, 'hojaDePromotoria'])
        ->middleware('rol:administrador')
        ->name('carnes-promotoria');
    Route::get('/panel/estudiante/{usuario}/historial', [FichaController::class, 'historial'])
        ->name('historial-estudiante');
    // Corregir la promotoria de una matricula: el estudiante se inscribio en la
    // que no era. Vive aqui y no en el Panel porque se hace desde la
    // trayectoria, que es donde se ven todas sus matriculas a la vez; quien
    // puede usarla lo comprueba el controlador (direccion, no el profesor).
    Route::post('/panel/matriculas/{matricula}/corregir-promotoria',
        [FichaController::class, 'corregirPromotoria'])
        ->name('corregir-promotoria');

    // Deshacer un rechazo. Vive al lado de la correccion porque se hace desde la
    // misma pantalla, pero NO comparte su puerta: aqui puede tambien el profesor
    // de la promotoria, porque es quien rechaza y quien se equivoca al pulsar.
    Route::post('/panel/matriculas/{matricula}/deshacer-rechazo',
        [FichaController::class, 'deshacerRechazo'])
        ->name('deshacer-rechazo');

    // Readmitir en la MISMA promotoria a quien salio (por ejemplo, cancelo por
    // accidente). La puerta es la de la correccion, no la del rechazo.
    Route::post('/panel/matriculas/{matricula}/readmitir',
        [FichaController::class, 'readmitir'])
        ->name('readmitir-matricula');
});

// Las estadisticas del profesor (27/09/2026): SOLO el rol profesor, que es
// quien las tiene en el menu. El director y el administrador ya tienen las de
// la casa en Gestion, y su barra esta en el tope de cuatro destinos.
Route::middleware(['auth', 'rol:profesor'])->group(function () {
    Route::get('/mis-estadisticas', MisEstadisticasController::class)->name('mis-estadisticas');
    Route::get('/mis-estadisticas/{periodo}', MisEstadisticasController::class)->name('mis-estadisticas-periodo');
});

// ---------------------------------------------------------------------------
// La institucion externa: todo lo que ve una cuenta de otra entidad
// ---------------------------------------------------------------------------
//
// SU PROPIO GRUPO Y SU PROPIO ROL, y no un rincon del Panel. Esta cuenta no es
// personal de la casa: es un funcionario de la escuela rural o del colegio
// donde un profesor va a dictar, y lo unico que hace aqui es dar fe de que
// fue. Dos pantallas y ninguna mas — sus clases y su QR—, que es exactamente
// lo que el menu le pinta.
//
// Que este grupo sea el UNICO con `rol:institucion_externa` es lo que hace que
// el resto del sistema le quede cerrado sin tener que acordarse de nada: los
// demas grupos nombran los roles que dejan entrar, y este no esta en ninguno.

Route::middleware(['auth', 'rol:institucion_externa'])->prefix('institucion')->group(function () {
    Route::get('/clases', [ExternaController::class, 'index'])->name('externa-clases');
    Route::post('/clases/{sesion}/verificar', [ExternaController::class, 'verificar'])
        ->name('externa-verificar');
    // Retirar una firma. Existe por lo mismo que se puede retirar una
    // confirmacion de clase, y aqui pesa mas: el otro camino —el QR— lo recorre
    // el profesor, asi que esto es lo unico que tiene la institucion para
    // desconocer una firma que ella no dio.
    Route::post('/clases/{sesion}/retirar', [ExternaController::class, 'retirar'])
        ->name('externa-retirar');

    // Su QR. Lo saca ella misma, como cualquiera saca su «Mi carné»; el
    // profesor NO, ni siquiera el responsable del programa (ver
    // `CarneController`).
    Route::get('/qr', [ExternaController::class, 'qr'])->name('externa-qr');
    Route::get('/qr/imagen', [CarneController::class, 'imagenPropia'])->name('externa-qr-imagen');
    Route::post('/qr/renovar', [CarneController::class, 'renovarPropio'])->name('externa-qr-renovar');
});

// La ficha con encuesta y documento es solo del administrador: va en su propio
// grupo porque es el unico sitio del panel con esa puerta.
Route::get('/panel/estudiante/{usuario}', [FichaController::class, 'estudiante'])
    ->middleware(['auth', 'rol:administrador'])
    ->name('detalle-estudiante');

// ---------------------------------------------------------------------------
// Informes descargables
// ---------------------------------------------------------------------------
//
// Dos alcances distintos, dos puertas distintas. El de estudiantes es operativo
// —la lista para pasar lista o llamar a una familia— y lo baja el personal, con
// el propio informe acotado a lo que cada quien puede ver. El de la institucion
// lleva la encuesta demografica con nombre, asi que es del administrador y de
// nadie mas: la misma puerta que la copia del documento de identidad.

Route::get('/informes/estudiantes', [InformeController::class, 'estudiantes'])
    ->middleware(['auth', 'rol:administrador,director,profesor'])
    ->name('informe-estudiantes');

Route::get('/informes/institucion', [InformeController::class, 'institucion'])
    ->middleware(['auth', 'rol:administrador'])
    ->name('informe-institucion');

// La gente SIN matricula (25/09/2026). Tambien solo del administrador: lleva
// nombres de menores, y los de un programa externo son de otra institucion.
Route::get('/informes/actividades', [InformeController::class, 'actividades'])
    ->middleware(['auth', 'rol:administrador'])
    ->name('informe-actividades');

// ---------------------------------------------------------------------------
// Certificados de matricula
// ---------------------------------------------------------------------------
//
// Sin `rol:` a proposito, y no es un descuido: quien puede bajar cada
// certificado NO se decide por el rol sino por el vinculo con la matricula —el
// propio estudiante, direccion, o el profesor que dicta esa promotoria—, y esa
// regla vive en `Permisos`. Un middleware de rol aqui dejaria fuera al
// estudiante, que es quien mas lo usa.

Route::get('/certificado/matricula/{matricula}', [CertificadoController::class, 'matricula'])
    ->middleware('auth')
    ->name('certificado-matricula');

Route::get('/certificado/estudiante/{estudiante}', [CertificadoController::class, 'todo'])
    ->middleware('auth')
    ->name('certificado-todo');

// El de asistencia a un curso o taller. Va aqui y no con las rutas del Panel
// porque es un certificado y comparte controlador —y con el, el membrete y la
// firma—, no porque se parezca a pasar lista.
//
// `scopeBindings()` es lo que ata el inscrito a SU actividad: sin el, el id de
// un inscrito de otro curso se resolveria igual y saldria un papel con el
// nombre de una persona que no estuvo en el que se esta certificando. El
// permiso no lo veria, porque el permiso mira la ACTIVIDAD y esa si seria suya.
Route::get('/certificado/actividad/{actividad}/{inscrito}', [CertificadoController::class, 'actividad'])
    ->middleware('auth')
    ->scopeBindings()
    ->name('certificado-actividad');

// ---------------------------------------------------------------------------
// La autorizacion de tratamiento de datos y uso de imagen
// ---------------------------------------------------------------------------
//
// El unico papel de los que pide la institucion que el sistema IMPRIME: los
// demas son ranuras donde se sube algo que ya existe.
//
// La de abajo, la del formato en blanco, va DESPUES: con `{tipo}` colgando de
// `/consentimiento` no habria choque —son dos segmentos distintos— pero el
// orden deja a la vista que la primera es la que usa todo el mundo y la
// segunda el caso de ventanilla.

// Sin `rol:` a proposito, igual que los certificados: quien puede bajarlo no lo
// decide el rol sino que sea un estudiante, y eso lo mira el controlador. Un
// `rol:estudiante` aqui daria 403 en vez del 404 que corresponde.
Route::get('/consentimiento', [ConsentimientoController::class, 'mio'])
    ->middleware('auth')
    ->name('consentimiento');

Route::get('/consentimiento/formato/{tipo}', [ConsentimientoController::class, 'formato'])
    ->middleware(['auth', 'rol:administrador,director'])
    ->name('consentimiento-formato');

// ---------------------------------------------------------------------------
// Archivos servidos de forma controlada (nunca como carpeta publica)
// ---------------------------------------------------------------------------

Route::get('/logo', [ArchivoController::class, 'logo'])->name('logo-institucion');

// El icono del acceso directo, la imagen de compartir y el manifiesto, todos
// sacados del logo (25/09/2026). Abiertos como el logo: los piden el telefono y
// el robot de WhatsApp, que no tienen sesion. `whereNumber` para que un lado
// que no es numero sea un 404 de la ruta y no un error de tipo.
Route::get('/icono-{lado}.png', [IconoController::class, 'icono'])
    ->whereNumber('lado')
    ->name('icono-institucion');
Route::get('/compartir.png', [IconoController::class, 'compartir'])->name('imagen-compartir');
Route::get('/manifest.webmanifest', [IconoController::class, 'manifiesto'])->name('manifiesto');

// La firma NO va abierta como el logo, y esa es toda la diferencia entre las dos
// imagenes de la institucion. Una firma escaneada colgada en una URL publica se
// la lleva cualquiera para estampar el papel que quiera; solo la ve el personal,
// que es quien la configura y quien la reconoce.
Route::get('/firma', [ArchivoController::class, 'firma'])
    ->middleware(['auth', 'rol:administrador,director'])
    ->name('firma-institucion');

Route::get('/foto/{perfil}', [ArchivoController::class, 'foto'])
    ->middleware('auth')
    ->name('ver-foto');

// Cualquier papel entregado, no solo el de identidad: desde el 05/09/2026 ese
// dejo de ser un caso especial del esquema. Sigue pidiendo rol de administrador,
// que es la barrera que ya tenia — un papel que pide una institucion puede ser
// una historia clinica, y no es menos delicado que una cedula.
Route::get('/documento/{entrega}', [ArchivoController::class, 'documento'])
    ->middleware(['auth', 'rol:administrador'])
    ->name('descargar-documento');

// ---------------------------------------------------------------------------
// Gestion: el catalogo academico y la institucion (direccion)
// ---------------------------------------------------------------------------

Route::middleware(['auth', 'rol:administrador,director'])->prefix('gestion')->group(function () {
    Route::get('/', Gestion\InicioController::class)->name('gestion-inicio');

    // Ventana de matriculas y cupos

    Route::get('/cupos', [Gestion\CuposController::class, 'mostrar'])->name('gestion-cupos');
    Route::get('/cupos/{periodo}', [Gestion\CuposController::class, 'mostrar'])->name('gestion-cupos-periodo');
    Route::post('/cupos/{periodo}', [Gestion\CuposController::class, 'guardar']);

    // Cancelaciones
    Route::get('/cancelaciones', [Gestion\CancelacionesController::class, 'index'])
        ->name('gestion-cancelaciones');
    // La tercera bandeja, en su propia pantalla porque son ~810 personas y
    // dentro de Alertas serian un muro. Se entra por el boton de alli.
    Route::get('/cancelaciones/fichas', [Gestion\FichasIncompletasController::class, 'index'])
        ->name('gestion-fichas-incompletas');
    // El lote va ANTES que la de abajo: con `{matricula}` por delante, la palabra
    // «lote» se leeria como el id de una matricula y el enlace implicito
    // devolveria un 404 en vez de llegar aqui.
    Route::post('/cancelaciones/lote', [Gestion\CancelacionesController::class, 'resolverLote'])
        ->name('gestion-cancelaciones-lote');
    // Las acciones de las alertas. Van ANTES de la de `{matricula}` por lo
    // mismo que «lote»: con el comodin por delante, «omision» se leeria como el
    // id de una matricula.
    Route::post('/cancelaciones/omision', [Gestion\CancelacionesController::class, 'clasificarOmision'])
        ->name('gestion-clasificar-omision');
    Route::post('/cancelaciones/omisiones-lote', [Gestion\CancelacionesController::class, 'clasificarLote'])
        ->name('gestion-clasificar-omisiones-lote');
    Route::post('/cancelaciones/festivo', [Gestion\CancelacionesController::class, 'marcarFestivo'])
        ->name('gestion-marcar-festivo');
    // Solo administracion: el controlador da 404 a cualquier otro rol.
    Route::post('/cancelaciones/semana-externa', [Gestion\CancelacionesController::class, 'clasificarSemanaExterna'])
        ->name('gestion-clasificar-semana-externa');
    Route::post('/cancelaciones/{matricula}/abandono', [Gestion\CancelacionesController::class, 'retirarPorAbandono'])
        ->name('gestion-retirar-abandono');
    Route::post('/cancelaciones/{matricula}/{decision}', [Gestion\CancelacionesController::class, 'resolver'])
        ->name('gestion-resolver-cancelacion');

    // Programas formativos: el arbol academico y las dos actividades, juntos.
    // Es la casa de los departamentos, los cursos y los grupos de proyeccion;
    // las pantallas de cada uno por separado siguen vivas debajo.
    Route::get('/programas', Gestion\ProgramasController::class)->name('gestion-programas');

    // Horarios: que se dicta cada dia, por promotoria. El director ve sus
    // departamentos; el recorte vive en `HorarioDeLaCasa`.
    Route::get('/horarios', Gestion\HorariosController::class)->name('gestion-horarios');

    // Departamentos
    Route::get('/areas', [Gestion\AreaController::class, 'index'])->name('area-lista');
    Route::get('/areas/{area}/promotorias', [Gestion\PromotoriaController::class, 'porArea'])
        ->name('promotorias-por-area');

    // Periodos
    Route::get('/periodos', [Gestion\PeriodoController::class, 'index'])->name('periodo-lista');

    // Promotorias
    Route::get('/promotorias', [Gestion\PromotoriaController::class, 'index'])->name('promotoria-lista');
    Route::get('/promotorias/{objeto}/editar', [Gestion\PromotoriaController::class, 'editar'])
        ->name('promotoria-editar');
    Route::post('/promotorias/{objeto}/editar', [Gestion\PromotoriaController::class, 'actualizar']);
    Route::get('/promotorias/{objeto}/eliminar', [Gestion\PromotoriaController::class, 'confirmarBorrado'])
        ->name('promotoria-eliminar');
    Route::post('/promotorias/{objeto}/eliminar', [Gestion\PromotoriaController::class, 'eliminar']);
    Route::get('/promotorias/{promotoria}/grupos', [Gestion\GrupoController::class, 'porPromotoria'])
        ->name('grupos-por-promotoria');

    // Grupos
    Route::get('/grupos', [Gestion\GrupoController::class, 'index'])->name('grupo-lista');
    Route::get('/grupos/nuevo', [Gestion\GrupoController::class, 'crear'])->name('grupo-nuevo');
    Route::post('/grupos/nuevo', [Gestion\GrupoController::class, 'guardar']);
    Route::get('/grupos/{objeto}/editar', [Gestion\GrupoController::class, 'editar'])->name('grupo-editar');
    Route::post('/grupos/{objeto}/editar', [Gestion\GrupoController::class, 'actualizar']);
    Route::get('/grupos/{objeto}/eliminar', [Gestion\GrupoController::class, 'confirmarBorrado'])
        ->name('grupo-eliminar');
    Route::post('/grupos/{objeto}/eliminar', [Gestion\GrupoController::class, 'eliminar']);
    Route::get('/grupos/{grupo}/estudiantes', [Gestion\GrupoController::class, 'estudiantes'])
        ->name('grupo-estudiantes');

    // Cursos y talleres
    Route::get('/cursos', [Gestion\CursoTallerController::class, 'index'])->name('actividad-curso-lista');
    Route::get('/cursos/{objeto}/editar', [Gestion\CursoTallerController::class, 'editar'])
        ->name('actividad-curso-editar');
    Route::post('/cursos/{objeto}/editar', [Gestion\CursoTallerController::class, 'actualizar']);
    Route::get('/cursos/{objeto}/eliminar', [Gestion\CursoTallerController::class, 'confirmarBorrado'])
        ->name('actividad-curso-eliminar');
    Route::post('/cursos/{objeto}/eliminar', [Gestion\CursoTallerController::class, 'eliminar']);
    Route::post('/cursos/{objeto}/enlace', [Gestion\CursoTallerController::class, 'alternarEnlace'])
        ->name('actividad-curso-enlace');
    // Las fechas son el segundo paso de crear un curso, y donde se le cambian
    // despues. Van con {objeto} como las de arriba: `buscar()` de esa pantalla
    // ya se encarga de que no se llegue a un grupo de proyeccion.
    Route::get('/cursos/{objeto}/fechas', [Gestion\CursoTallerController::class, 'fechas'])
        ->name('actividad-curso-fechas');
    Route::post('/cursos/{objeto}/fechas', [Gestion\CursoTallerController::class, 'guardarFechas']);

    // Programas externos
    //
    // Un DIRECTOR gestiona los que le asignen —los edita y los borra— igual que
    // hace con los cursos y los grupos de proyeccion; lo que no hace es
    // crearlos, por la misma razon escrita mas abajo. Las instituciones son
    // otra cosa y viven enteras en el grupo del administrador: registrar una
    // entidad con la que la casa tiene convenio no es acotable a un
    // departamento.
    Route::get('/externos', [Gestion\ProgramaExternoController::class, 'index'])
        ->name('programa-externo-lista');
    Route::get('/externos/{objeto}/editar', [Gestion\ProgramaExternoController::class, 'editar'])
        ->name('programa-externo-editar');
    Route::post('/externos/{objeto}/editar', [Gestion\ProgramaExternoController::class, 'actualizar']);
    Route::get('/externos/{objeto}/eliminar', [Gestion\ProgramaExternoController::class, 'confirmarBorrado'])
        ->name('programa-externo-eliminar');
    Route::post('/externos/{objeto}/eliminar', [Gestion\ProgramaExternoController::class, 'eliminar']);

    // Grupos de proyeccion
    Route::get('/proyeccion', [Gestion\ProyeccionController::class, 'index'])
        ->name('actividad-proyeccion-lista');
    Route::get('/proyeccion/{objeto}/editar', [Gestion\ProyeccionController::class, 'editar'])
        ->name('actividad-proyeccion-editar');
    Route::post('/proyeccion/{objeto}/editar', [Gestion\ProyeccionController::class, 'actualizar']);
    Route::get('/proyeccion/{objeto}/eliminar', [Gestion\ProyeccionController::class, 'confirmarBorrado'])
        ->name('actividad-proyeccion-eliminar');
    Route::post('/proyeccion/{objeto}/eliminar', [Gestion\ProyeccionController::class, 'eliminar']);
    Route::post('/proyeccion/{objeto}/enlace', [Gestion\ProyeccionController::class, 'alternarEnlace'])
        ->name('actividad-proyeccion-enlace');

    // Usuarios
});

// GESTION ASISTIDA: el administrador trabaja desde la cuenta de un profesor o un
// director. Ver `Support\GestionAsistida`.
//
// SALIR va en OTRO grupo y con otra puerta, y no es descuido: en cuanto la
// asistencia empieza, para el middleware quien navega ya no es administrador
// sino la persona a la que se asiste. Con `rol:administrador` en el boton de
// salir, quien entra se queda dentro y solo sale cerrando sesion.
// SALIR va PRIMERO, y el orden importa: Laravel casa por orden de registro, asi
// que con `{usuario}` delante la palabra «salir» entraria como si fuera el
// nombre de alguien y la ruta de salida no existiria.
Route::post('/gestion/asistida/salir', [Gestion\AsistidaController::class, 'terminar'])
    ->middleware('auth')
    ->name('gestion-asistida-salir');

Route::post('/gestion/asistida/{usuario}', [Gestion\AsistidaController::class, 'iniciar'])
    ->middleware(['auth', 'rol:administrador'])
    ->name('gestion-asistida-iniciar');

// ENTRAR DESDE EL PANEL de todas las instituciones (paso 4c): el operador llega
// con un token de un solo uso que dejo el panel. Ver `Support\Suplantacion`.
// Las dos van fuera de `guest` y sin rol: la primera atiende a quien tenga
// sesion abierta en este navegador (la cierra), y la segunda la usa quien sea
// que el operador este mirando, tambien un profesor si abrio una gestion
// asistida desde la cuenta del administrador.
Route::get('/suplantacion/{token}', [SuplantacionController::class, 'entrar'])
    ->middleware('throttle:10,1')
    ->name('suplantacion.entrar');
Route::post('/suplantacion/salir', [SuplantacionController::class, 'salir'])
    ->middleware('auth')
    ->name('suplantacion.salir');

// La institucion y las estadisticas son SOLO del administrador: la primera es la
// identidad de la entidad y una regla que gobierna las matriculas de todo el
// mundo; las segundas agregan la encuesta demografica.
Route::middleware(['auth', 'rol:administrador'])->prefix('gestion')->group(function () {
    // CREAR una promotoria es del ADMINISTRADOR desde el 12/09/2026, y esta
    // linea llego despues de las demas: se vio abriendo Programas como
    // director, donde el formulario le ofrecia TODOS los departamentos en el
    // desplegable —incluidos los que no administra—.
    //
    // Un director SIGUE editando las suyas y armando sus grupos, que es su
    // trabajo. Abrir una promotoria nueva es decidir lo que la casa ofrece,
    // y eso no se acota a un departamento. Decision del usuario ese dia.
    Route::get('/promotorias/nueva', [Gestion\PromotoriaController::class, 'crear'])->name('promotoria-nueva');
    Route::post('/promotorias/nueva', [Gestion\PromotoriaController::class, 'guardar']);

    // CREAR una actividad es del ADMINISTRADOR desde el 12/09/2026.
    //
    // Un director SI gestiona las que le asignen: las edita, les pone fechas,
    // les pasa lista y saca sus certificados. Lo que no hace es crearlas, y la
    // razon salio de probarlo con un director de verdad: el formulario le
    // ofrecia poner de responsable a cualquiera de las 29 personas, y en
    // cuanto ponia a otro la actividad DESAPARECIA de su vista —no la ve, no
    // la edita, no la borra— porque una actividad no cuelga de un
    // departamento y su recorte va por responsable.
    //
    // Crear algo y perderlo en el mismo gesto es peor que no poder crearlo.
    // Decision del usuario ese dia, con la alternativa delante: dejar que
    // solo se las creara A SI MISMO.
    Route::get('/cursos/nuevo', [Gestion\CursoTallerController::class, 'crear'])->name('actividad-curso-nueva');
    Route::post('/cursos/nuevo', [Gestion\CursoTallerController::class, 'guardar']);
    Route::get('/proyeccion/nuevo', [Gestion\ProyeccionController::class, 'crear'])
        ->name('actividad-proyeccion-nueva');
    Route::post('/proyeccion/nuevo', [Gestion\ProyeccionController::class, 'guardar']);

    // Crear un programa externo es del ADMINISTRADOR, por lo mismo que las
    // otras actividades: un director que lo creara poniendo de responsable a
    // otro lo perderia de vista en el mismo gesto, porque el recorte de una
    // actividad va por responsable y no por departamento.
    Route::get('/externos/nuevo', [Gestion\ProgramaExternoController::class, 'crear'])
        ->name('programa-externo-nuevo');
    Route::post('/externos/nuevo', [Gestion\ProgramaExternoController::class, 'guardar']);

    // ── LAS INSTITUCIONES EXTERNAS, ENTERAS ─────────────────────────────
    //
    // Registrar una entidad con la que la casa tiene convenio —y crearle la
    // cuenta con la que va a dar fe del trabajo de sus profesores— es una
    // decision de toda la casa, como abrir un departamento o una promotoria.
    // No se acota a un departamento, asi que no es de un director.
    //
    // NO HAY RUTA DE BORRADO, y es la decision y no un olvido: una institucion
    // con programas la bloquea `Dependencias`, y lo que se hace con una que ya
    // no recibe clases es apagarle la cuenta. Ver `InstitucionExternaController`.
    Route::get('/instituciones', [Gestion\InstitucionExternaController::class, 'index'])
        ->name('institucion-externa-lista');
    Route::get('/instituciones/nueva', [Gestion\InstitucionExternaController::class, 'crear'])
        ->name('institucion-externa-nueva');
    Route::post('/instituciones/nueva', [Gestion\InstitucionExternaController::class, 'guardar']);
    Route::get('/instituciones/{objeto}/editar', [Gestion\InstitucionExternaController::class, 'editar'])
        ->name('institucion-externa-editar');
    Route::post('/instituciones/{objeto}/editar', [Gestion\InstitucionExternaController::class, 'actualizar']);
    Route::post('/instituciones/{objeto}/alternar-activo', [Gestion\InstitucionExternaController::class, 'alternarActivo'])
        ->name('institucion-externa-alternar-activo');

    // El QR que administracion imprime y entrega. Va con `{institucion}` —enlace
    // implicito— y no con `{objeto}`: aqui no manda `RecursoController` sino
    // `CarneController`, que resuelve el modelo por su tipo.
    Route::get('/instituciones/{institucion}/qr', [CarneController::class, 'deInstitucion'])
        ->name('institucion-externa-qr');
    Route::get('/instituciones/{institucion}/qr/imagen', [CarneController::class, 'imagenDeInstitucion'])
        ->name('institucion-externa-qr-imagen');
    Route::post('/instituciones/{institucion}/qr/renovar', [CarneController::class, 'renovarDeInstitucion'])
        ->name('institucion-externa-qr-renovar');

    // ── SOLO EL ADMINISTRADOR, desde el 12/09/2026 ──────────────────────
    //
    // Un director esta acotado a los departamentos que administra, asi que
    // lo que es GLOBAL deja de ser suyo: crear o borrar un departamento, y
    // abrir o cerrar la ventana de matriculas de toda la casa. Un director
    // de Musica que puede borrar el departamento de Danzas no esta acotado
    // a nada.
    //
    // Y USUARIOS entero, decidido ese dia: a las personas llega por los
    // grupos de sus departamentos —su ficha se abre desde el Panel— y no
    // por un listado de toda la institucion. El coste asumido es que deja
    // de poder asignarle el rol a quien se registra.
    Route::get('/usuarios', [Gestion\UsuarioController::class, 'index'])->name('usuario-lista');
    Route::get('/usuarios/nuevo', [Gestion\UsuarioController::class, 'crear'])->name('usuario-nuevo');
    Route::post('/usuarios/nuevo', [Gestion\UsuarioController::class, 'guardar']);
    Route::get('/usuarios/{usuario}/editar', [Gestion\UsuarioController::class, 'editar'])
        ->name('usuario-editar');
    Route::post('/usuarios/{usuario}/editar', [Gestion\UsuarioController::class, 'actualizar']);
    Route::post('/usuarios/{usuario}/alternar-activo', [Gestion\UsuarioController::class, 'alternarActivo'])
        ->name('usuario-alternar-activo');
    Route::get('/areas/nueva', [Gestion\AreaController::class, 'crear'])->name('area-nueva');
    Route::post('/areas/nueva', [Gestion\AreaController::class, 'guardar']);
    Route::get('/areas/{objeto}/editar', [Gestion\AreaController::class, 'editar'])->name('area-editar');
    Route::post('/areas/{objeto}/editar', [Gestion\AreaController::class, 'actualizar']);
    Route::get('/areas/{objeto}/eliminar', [Gestion\AreaController::class, 'confirmarBorrado'])
        ->name('area-eliminar');
    Route::post('/areas/{objeto}/eliminar', [Gestion\AreaController::class, 'eliminar']);
    Route::get('/periodos/nuevo', [Gestion\PeriodoController::class, 'crear'])->name('periodo-nuevo');
    Route::post('/periodos/nuevo', [Gestion\PeriodoController::class, 'guardar']);
    Route::get('/periodos/{objeto}/editar', [Gestion\PeriodoController::class, 'editar'])->name('periodo-editar');
    Route::post('/periodos/{objeto}/editar', [Gestion\PeriodoController::class, 'actualizar']);
    Route::get('/periodos/{objeto}/eliminar', [Gestion\PeriodoController::class, 'confirmarBorrado'])
        ->name('periodo-eliminar');
    Route::post('/periodos/{objeto}/eliminar', [Gestion\PeriodoController::class, 'eliminar']);
    Route::get('/matriculas', [Gestion\MatriculasController::class, 'mostrar'])->name('gestion-matriculas');
    Route::post('/matriculas', [Gestion\MatriculasController::class, 'guardar']);
    Route::get('/institucion', [Gestion\ConfiguracionController::class, 'mostrar'])
        ->name('gestion-configuracion');
    Route::post('/institucion', [Gestion\ConfiguracionController::class, 'guardar']);
    Route::post('/institucion/documentos/nuevo', [Gestion\ConfiguracionController::class, 'documentoNuevo'])
        ->name('documento-requerido-nuevo');
    Route::post('/institucion/documentos/{documento}/alternar', [Gestion\ConfiguracionController::class, 'documentoAlternar'])
        ->name('documento-requerido-alternar');

    // Sin periodo se ensena el en curso; con periodo, ese. Dos rutas y no una
    // con parametro opcional porque el nombre sin parametro es el que usan el
    // menu y la portada, y asi no tienen que saber en que periodo estamos.
    Route::get('/estadisticas', Gestion\EstadisticasController::class)->name('gestion-estadisticas');
    Route::get('/estadisticas/{periodo}', Gestion\EstadisticasController::class)
        ->name('gestion-estadisticas-periodo');

    // Eliminar una cuenta, y SOLO el administrador. Es la unica accion del
    // sistema que quita datos en lugar de cambiarlos: todo lo demas se corrige,
    // se retira o se desactiva. Un director gobierna su escuela entera sin
    // borrar a nadie, asi que no gana nada teniendolo y puede perder mucho.
    //
    // Vive aqui abajo, en el grupo del administrador, y no arriba con el resto
    // de /usuarios: la puerta es el grupo de rutas, no una comprobacion dentro
    // del controlador que haya que acordarse de repetir.
    Route::get('/usuarios/{usuario}/eliminar', [Gestion\UsuarioController::class, 'confirmarBorrado'])
        ->name('usuario-eliminar');
    // Con limite de intentos, como el login y por lo mismo. Este POST acepta una
    // contrasena, y esa contrasena es lo UNICO que separa una sesion prestada de
    // un borrado permanente: sin limite se puede tantear a placer desde dentro.
    // Cinco por minuto es lo que ya se le da al propio inicio de sesion.
    Route::post('/usuarios/{usuario}/eliminar', [Gestion\UsuarioController::class, 'eliminar'])
        ->middleware('throttle:5,1');
});
