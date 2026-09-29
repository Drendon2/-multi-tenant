/*
 * ACCIONES SIN RECARGA
 * ====================
 * Puerto del script que trae `base.html` en el proyecto Django, sin cambios de
 * comportamiento. Es JavaScript plano y no depende de nada: funciona igual bajo
 * Laravel porque solo habla con <form> y <main>.
 *
 * Este sistema se usa de a tandas: se confirman quince matriculas seguidas, se
 * reparten treinta estudiantes en grupos, se vacia la bandeja de cancelaciones.
 * Con una recarga entera por accion, cada clic devolvia al usuario al principio
 * de la pagina y habia que volver a bajar hasta donde iba. Con listas largas eso
 * no es una molestia, es lo que hace el trabajo inviable.
 *
 * La estrategia NO es convertir cada ruta en una API. Los controladores siguen
 * haciendo lo que hacian —guardar, encolar su mensaje y redirigir—, y aqui se
 * intercepta el envio para pedir esa misma respuesta por `fetch` y cambiar solo
 * lo de dentro de <main>. El servidor sigue siendo la unica fuente de verdad y
 * sigue re-renderizando de cero: por eso los estudiantes que cambian de seccion,
 * los contadores de cupo y los avisos salen bien sin que nadie tenga que
 * reproducir esa logica en JavaScript.
 *
 * Lo que se conserva a mano es lo que el navegador perderia: la posicion del
 * scroll y que <details> estaban abiertos.
 *
 * Si no hay JavaScript, o si `fetch` falla, no pasa nada: el formulario se envia
 * como siempre. Para dejar una accion FUERA basta ponerle `data-recarga-completa`
 * al <form>.
 *
 * LA SEGUNDA MITAD: EL MODAL DE CONFIRMACION
 * ------------------------------------------
 * Vive aqui y no en un archivo aparte porque es lo mismo que lo de arriba —una
 * accion sin recargar— y porque necesita `pintar()`: cuando el borrado sale
 * bien hay que refrescar la lista de detras, que es exactamente lo que esa
 * funcion ya sabe hacer, con sus <details> abiertos y su scroll.
 *
 * La idea es la misma que la de los formularios: NO se convierte nada en una
 * API. La pagina de confirmacion sigue existiendo, sigue siendo una URL de
 * verdad y sigue renderizandose entera en el servidor; el modal se limita a
 * pedirla por `fetch` y ensenar su tarjeta dentro de un <dialog>. Por eso sin
 * JavaScript el enlace navega a esa pagina y todo funciona igual, y por eso el
 * servidor no tiene que saber si le estan preguntando desde un modal.
 */
(function () {
  var main = document.querySelector("main");
  if (!main || !window.fetch || !window.DOMParser || !window.history.pushState) { return; }

  /*
   * Las cabeceras de todas las peticiones de este archivo. Las TRES importan.
   *
   * `X-Fragmento` es la ultima, del 03/09. Dice que quien pregunta sabe colocar
   * medio documento, asi que el servidor puede contestar con lo de dentro de
   * <main> en vez de guardar-y-redirigir. Donde nadie la mire —que es casi
   * todo— no cambia nada. Su mitad de servidor es `App\Support\Fragmento`.
   *
   * `X-Requested-With` es la de siempre. `Accept` se anadio el 01/09 y arregla
   * algo que llevaba roto sin que se viera:
   *
   * `fetch` manda un Accept comodin —el que acepta cualquier tipo— cuando nadie
   * dice otra cosa, y con eso `Request::expectsJson()` de Laravel devuelve true. O sea que un formulario
   * rechazado por la validacion no contestaba con el 302 y su HTML, sino con un
   * 422 y un JSON — y entonces `pintar()` no encuentra <main>, se rinde y
   * navega a la URL del POST. El aviso de por que se rechazo no llegaba a
   * verse: exactamente el fallo que se creia arreglado.
   *
   * Se llevaba por delante tambien a los dos manejadores de `bootstrap/app.php`
   * — el del CSRF caducado y el del limite de intentos —, que preguntan por
   * `expectsJson()` y se apartaban. El primero dice en su propio comentario que
   * existe para servir a este archivo, y no llegaba a correr nunca.
   *
   * NINGUNA PRUEBA DE PHP PUEDE VIGILAR ESTA LINEA, y conviene saberlo: el
   * cliente de PHPUnit no manda ese comodin, asi que por ahi el servidor
   * siempre contesto HTML y las pruebas de rechazo pasaban en verde con el
   * fallo puesto. Lo que si esta probado es la otra mitad —que con ESTAS
   * cabeceras el servidor devuelve HTML—, en `RechazoDeFormularioTest`. Si
   * alguien quita este `Accept`, esa prueba seguira verde y el fallo volvera.
   * Se ve abriendo la pagina y guardando algo mal escrito.
   */
  var CABECERAS = {
    "X-Requested-With": "XMLHttpRequest",
    Accept: "text/html",
    "X-Fragmento": "1",
  };

  function abiertos() {
    // Solo los que tienen id: el id es la promesa de que ese <details> es el
    // mismo antes y despues. Sin el, la promotoria que cambio de posicion
    // heredaria el estado de la vecina.
    var estado = {};
    main.querySelectorAll("details[id]").forEach(function (d) { estado[d.id] = d.open; });
    return estado;
  }

  /*
   * `fragmento` dice que la respuesta trae SOLO lo de dentro de <main>, y eso lo
   * decide la cabecera que devuelve el servidor — nunca el HTML que llego.
   *
   * La tentacion es deducirlo: "no trae <main>, luego es un fragmento". Falla en
   * el caso que mas duele. Si la sesion caduco, lo que contesta el servidor es
   * la pagina de entrar, y esa deduccion la pegaria dentro de <main>: el panel
   * se quedaria con dos cabeceras y un formulario de contrasena incrustado en
   * medio, en vez de llevar a la persona al login. Por eso se pregunta a quien
   * lo sabe.
   */
  /*
   * LOS SCRIPTS QUE LA PAGINA NUEVA NECESITA Y ESTA NO TIENE.
   *
   * ─── EL FALLO QUE VINO A ARREGLAR ──────────────────────────────────────
   *
   * Reportado el 10/09/2026 probando el Panel: crear un grupo devolvia al Panel
   * y ahi, al desplegar una promotoria, se quedaba en «Cargando…» PARA SIEMPRE.
   * Habia que recargar la pagina a mano para poder verla.
   *
   * La causa es de este archivo. El formulario del grupo vive dentro de <main>,
   * asi que el envio se intercepta aqui, se sigue la redireccion al Panel y se
   * repinta <main>... pero `panel.js` y `lote.js` NO viven dentro de <main>:
   * van en la pila de scripts, al final del <body>, precisamente para
   * registrarse una vez y sobrevivir a los repintados. Al cambiar de PAGINA por
   * este camino, la de destino se queda sin ellos. La URL es la del Panel, el
   * contenido es el del Panel, y los scripts son los de la pagina anterior.
   *
   * No falla y no avisa: la pantalla se pinta entera y lo unico que pasa es que
   * una funcion deja de responder. Diez vistas de este proyecto traen scripts
   * propios, asi que esto alcanza a cualquiera a la que se llegue enviando un
   * formulario desde otra.
   *
   * ─── POR QUE SE AÑADEN Y NO SE REEMPLAZAN ──────────────────────────────
   *
   * Solo se trae lo que FALTA, comparando por la ruta del `src` y no por la URL
   * entera: `@recurso` le cuelga un `?v=` que no tiene por que coincidir entre
   * dos renderizados. Volver a cargar uno que ya esta registraria sus oyentes
   * por segunda vez, que es justo lo que el comentario de `panel.js` explica que
   * hay que evitar.
   *
   * Lo que SOBRA se deja: un `panel.js` cargado de mas en el formulario de un
   * grupo no hace nada, porque no encuentra ningun `details[data-cuerpo]`. Lo
   * que rompe es lo que falta, no lo que sobra.
   */
  function traerLosScriptsQueFaltan(doc) {
    var puestos = {};

    document.querySelectorAll("script[src]").forEach(function (s) {
      puestos[new URL(s.src, location.href).pathname] = true;
    });

    doc.querySelectorAll("script[src]").forEach(function (s) {
      var ruta = new URL(s.getAttribute("src"), location.href).pathname;
      if (puestos[ruta]) { return; }
      puestos[ruta] = true;

      var script = document.createElement("script");
      script.src = s.getAttribute("src");
      document.body.appendChild(script);
    });
  }

  /**
   * Copia el menu de la respuesta al que ya esta, si vino en el HTML.
   *
   * Solo cuando llega la pagina ENTERA: un fragmento —`layouts.fragmento`— no
   * trae <nav>, y con `querySelector` devolviendo null aqui se borraria el menu
   * de la pantalla. Por eso se comprueba antes de tocar nada.
   */
  function sincronizarMenu(doc) {
    var nueva = doc.querySelector("header nav");
    var actual = document.querySelector("header nav");

    if (!nueva || !actual) { return; }

    actual.innerHTML = nueva.innerHTML;
  }

  function pintar(html, estado, scroll, fragmento) {
    var doc = new DOMParser().parseFromString(html, "text/html");
    var nuevo = fragmento ? doc.body : doc.querySelector("main");
    if (!nuevo) { return false; }

    main.innerHTML = nuevo.innerHTML;
    if (doc.title) { document.title = doc.title; }

    // LA INSIGNIA DEL MENU, que vive FUERA de <main> y por eso no la trae el
    // repintado.
    //
    // Se vio recorriendo el flujo entero el 12/09/2026: un estudiante confirma
    // su ultima clase, la fila cambia a VERIFICADA... y el punto rojo de
    // «Clases» se queda diciendo «1 clase sin confirmar» hasta la siguiente
    // navegacion de verdad. Un contador que miente una vez no lo vuelve a mirar
    // nadie, y este existe justamente para que no se pase el plazo de 48 horas.
    //
    // No es un caso del punto: es de TODO lo que vive fuera de <main> y cambia
    // con la accion. Aqui solo el menu, que es lo unico que hay hoy.
    sincronizarMenu(doc);

    main.querySelectorAll("details[id]").forEach(function (d) {
      if (d.id in estado) { d.open = estado[d.id]; }
    });

    // innerHTML no ejecuta <script>. Las pantallas que traen el suyo (pasar
    // lista, la encuesta del perfil) quedarian muertas tras un cambio, asi que
    // se reponen creandolos de nuevo.
    main.querySelectorAll("script").forEach(function (viejo) {
      var script = document.createElement("script");
      if (viejo.src) { script.src = viejo.src; } else { script.textContent = viejo.textContent; }
      viejo.parentNode.replaceChild(script, viejo);
    });

    traerLosScriptsQueFaltan(doc);

    // Un RECHAZO no restaura el scroll de antes.
    //
    // Restaurarlo es lo correcto cuando la accion salio bien —confirmar una
    // matricula no debe saltar al principio de la lista— y es lo peor posible
    // cuando el servidor devuelve el formulario con un error: el aviso queda
    // arriba, fuera de pantalla, y la persona sigue mirando el mismo sitio con
    // la impresion de que el boton no hizo nada.
    //
    // Eso se llevo por delante a un profesor en produccion: creo dos grupos con
    // el mismo nombre y distinto nivel, no vio el mensaje que lo explicaba, y
    // concluyo que el sistema tenia un tope de grupos.
    var fallo = main.querySelector(".errorlist");

    // Y un ACIERTO no necesita nada de esto, aunque lo parezca. Al guardar la
    // lista de asistencia desde el final de veinte nombres, el aviso se pinta
    // arriba del todo y la tentacion es traerlo a la vista igual que el fallo.
    // No hace falta: `.messages` es `position: sticky`, asi que ya se ve sin
    // que nadie la mueva. Medido en Chrome a 390px el 03/09 —el aviso quedaba a
    // 10px del borde con el scroll en 2220—, y traerlo ademas solo conseguia
    // subir al profesor 390px sin darle nada. Si vuelve a apetecer, mirar
    // primero si esa regla del CSS sigue puesta.
    if (fallo) {
      fallo.scrollIntoView({ block: "center" });
    } else {
      window.scrollTo(0, scroll);
    }

    anunciar();

    return true;
  }

  /*
   * Decir en voz alta lo que la pantalla acaba de decir por escrito.
   *
   * Todo lo de arriba —traer el aviso a la vista, dejarlo pegado, no restaurar
   * el scroll tras un rechazo— resuelve el problema para quien MIRA la pantalla.
   * Quien no la mira no se entera de nada: cambiar el contenido de <main> no lo
   * anuncia ningun lector de pantalla, asi que la accion se queda en silencio,
   * y eso vale igual para «se guardo» que para «no se guardo».
   *
   * Se copia el texto a la caja que corresponde en vez de marcar `.messages`
   * como region viva, y no es lo mismo: `.messages` se destruye y se vuelve a
   * crear en cada repintado, y una region viva que aparece ya con texto dentro
   * no se anuncia de forma fiable. Las cajas de `layouts.app` no se destruyen
   * nunca — viven fuera de <main> — y aqui solo se les cambia el texto.
   *
   * SE VACIA ANTES Y SE ESCRIBE DESPUES, en dos vueltas del bucle de eventos.
   * Un lector anuncia el CAMBIO, no el contenido: escribir dos veces el mismo
   * texto —dos rechazos seguidos por el mismo motivo, que es justo lo que pasa
   * cuando alguien no entiende que le piden— no seria ningun cambio y el
   * segundo no se diria. Vaciar primero garantiza que siempre lo haya.
   */
  function anunciar() {
    var errores = main.querySelectorAll(".messages .error");
    var exito = main.querySelector(".messages .success");
    var caja = document.querySelector(errores.length ? "[data-voz='mal']" : "[data-voz='bien']");
    if (!caja) { return; }

    var texto = "";
    if (errores.length) {
      // El aviso de rechazo va primero y resume: «No se guardo. Hay N campos
      // por corregir». Los de campo no se leen aqui —estan pegados a su campo,
      // que es donde hay que corregirlos— y recitarlos sueltos, sin decir de
      // cual es cada uno, seria una lista de quejas sin sitio.
      texto = errores[0].textContent;
    } else if (exito) {
      texto = exito.textContent;
    }

    texto = texto.replace(/\s+/g, " ").trim();

    document.querySelectorAll("[data-voz]").forEach(function (c) { c.textContent = ""; });
    if (!texto) { return; }

    window.setTimeout(function () { caja.textContent = texto; }, 0);
  }

  document.addEventListener("submit", function (evento) {
    var form = evento.target;
    if (!form.matches("form") || !main.contains(form)) { return; }
    if ((form.method || "").toLowerCase() !== "post") { return; }
    if (form.hasAttribute("data-recarga-completa")) { return; }

    evento.preventDefault();

    // El boton pulsado importa: hay formularios con mas de uno y su name/value
    // viaja en el envio. FormData(form, submitter) lo hace solo donde existe;
    // donde no, se anade a mano.
    var enviador = evento.submitter;
    var datos;
    try {
      datos = new FormData(form, enviador);
    } catch (e) {
      datos = new FormData(form);
      if (enviador && enviador.name) { datos.append(enviador.name, enviador.value); }
    }

    var estado = abiertos();
    var scroll = window.scrollY;
    var destino = form.action || window.location.href;
    main.setAttribute("aria-busy", "true");

    fetch(destino, {
      method: "POST",
      body: datos,
      credentials: "same-origin",
      redirect: "follow",
      headers: CABECERAS,
    }).then(function (respuesta) {
      return respuesta.text().then(function (html) {
        return {
          html: html,
          url: respuesta.url,
          ok: respuesta.ok,
          // Solo si el servidor lo dice. Ver `pintar()`.
          fragmento: respuesta.headers.get("X-Fragmento") === "1",
        };
      });
    }).then(function (r) {
      // La accion pudo llevar a OTRA pagina (crear algo y volver a su lista, o
      // caerse la sesion y aterrizar en el login). La respuesta que ya tenemos
      // es la buena: si se navegara de verdad, el mensaje encolado ya se habria
      // consumido en esta peticion y se perderia. Asi que se pinta igual y se
      // corrige la barra de direcciones.
      //
      // Un fragmento nunca es un cambio de pagina: se responde en el sitio, y
      // `respuesta.url` es la misma del formulario.
      var cambioDePagina = !r.fragmento && r.url && r.url !== window.location.href;
      if (!pintar(r.html, estado, cambioDePagina ? 0 : scroll, r.fragmento)) {
        window.location.href = r.url || window.location.href;
        return;
      }
      if (cambioDePagina) { window.history.pushState({ pintado: true }, "", r.url); }
    }).catch(function () {
      // Red caida o respuesta ilegible. Reenviar por las bravas duplicaria la
      // accion si el POST si habia llegado, asi que se recarga: la pagina vuelve
      // a contar el estado real y el usuario ve si se guardo o no.
      window.location.reload();
    }).then(function () {
      main.removeAttribute("aria-busy");
    });
  });

  // -----------------------------------------------------------------------
  // MODAL DE CONFIRMACION
  // -----------------------------------------------------------------------

  var dialogo = null;

  /*
   * El <dialog>, creado una vez y colgado del <body>.
   *
   * FUERA de <main>, y no es un detalle de colocacion: el manejador de envios
   * de arriba actua sobre los formularios que main CONTIENE, y si el dialogo
   * estuviera dentro, enviar la confirmacion repintaria main y se llevaria por
   * delante el propio dialogo a media peticion. Al quedar fuera, aquel lo
   * ignora y este se ocupa.
   */
  function caja() {
    if (dialogo) { return dialogo; }

    dialogo = document.createElement("dialog");
    dialogo.className = "modal";
    document.body.appendChild(dialogo);

    // Tocar el fondo cierra. El propio <dialog> ocupa toda la ventana cuando
    // esta abierto, asi que un clic cuyo destino sea EL —y no algo de dentro—
    // es un clic en el fondo.
    dialogo.addEventListener("click", function (evento) {
      if (evento.target === dialogo) { dialogo.close(); }
    });

    // Cerrar deja sin efecto el modal que se estuviera pidiendo: quien cierra
    // el esqueleto con Escape o tocando el fondo no quiere que la tarjeta le
    // salte encima un segundo despues. SOLO si lo cerrado era el esqueleto:
    // `close` llega en una tarea aparte, y cerrar una tarjeta de verdad y tocar
    // otro enlace enseguida anularia el pedido nuevo.
    dialogo.addEventListener("close", function () {
      if (dialogo.querySelector(".esqueleto")) { pedidoModal++; }
    });

    return dialogo;
  }

  /*
   * EL ESQUELETO DEL MODAL, mientras llega la tarjeta de verdad.
   *
   * Por el CDN, pedir la tarjeta tarda de 1,3 a 1,9 s, y durante ese rato el
   * toque no daba ninguna senal: la persona volvia a tocar, o pensaba que el
   * enlace no hacia nada. Ahora el dialogo se abre enseguida con la forma de
   * una tarjeta y se rellena al llegar.
   *
   * Solo si tarda mas de ESPERA_ESQUELETO: con una respuesta rapida, sin ese
   * umbral cada modal abriria con un parpadeo gris antes de la tarjeta.
   *
   * `pedidoModal` numera los pedidos: una respuesta que llega cuando ya hay
   * otro pedido detras —o cuando el esqueleto se cerro— se tira.
   */
  var ESPERA_ESQUELETO = 200;
  var pedidoModal = 0;

  function abrirEsqueleto() {
    var d = caja();
    d.innerHTML =
      '<div class="card">' +
      '<div class="esqueleto" aria-busy="true">' +
      '<span class="esqueleto-voz">Cargando…</span>' +
      '<span class="esqueleto-linea titulo"></span>' +
      '<span class="esqueleto-linea"></span>' +
      '<span class="esqueleto-linea media"></span>' +
      '<span class="esqueleto-boton"></span>' +
      '</div></div>';
    if (!d.open) { d.showModal(); }
  }

  /*
   * Mete en el dialogo la tarjeta que venga en ese HTML. Devuelve false si el
   * HTML no trae ninguna, que es como se distingue un rechazo de un exito.
   */
  function abrirModal(html) {
    var doc = new DOMParser().parseFromString(html, "text/html");
    var cuerpo = doc.querySelector("[data-modal-cuerpo]");
    if (!cuerpo) { return false; }

    var d = caja();
    d.innerHTML = "";
    d.appendChild(document.importNode(cuerpo, true));
    d.removeAttribute("aria-busy");

    /*
     * Los <script> que vengan DENTRO de la tarjeta se vuelven a crear para que
     * corran.
     *
     * Un <script> insertado por `importNode` o por `innerHTML` no se ejecuta
     * NUNCA: el navegador lo deja ahi como texto. Es una regla del DOM y no un
     * fallo, pero aqui muerde de una forma silenciosa — el formulario de usuario
     * lleva uno que muestra u oculta los campos de estudiante segun el rol, y
     * sin esto el modal se abria con esos campos en el estado en que el servidor
     * los dejo y el desplegable no hacia nada. No falla, no avisa: simplemente
     * deja de responder.
     *
     * Se copia el contenido y los atributos a un <script> nuevo, que es la unica
     * forma de que el navegador lo trate como codigo.
     */
    d.querySelectorAll("script").forEach(function (viejo) {
      var nuevo = document.createElement("script");
      for (var i = 0; i < viejo.attributes.length; i++) {
        nuevo.setAttribute(viejo.attributes[i].name, viejo.attributes[i].value);
      }
      nuevo.textContent = viejo.textContent;
      viejo.parentNode.replaceChild(nuevo, viejo);
    });

    if (!d.open) { d.showModal(); }

    // El foco al primer campo que haya, y si no al primer boton. `showModal()`
    // ya lo atrapa dentro; esto solo elige por donde empieza.
    var foco = d.querySelector("input:not([type=hidden]), textarea, select, button");
    if (foco) { foco.focus(); }

    return true;
  }

  document.addEventListener("click", function (evento) {
    // Cancelar dentro del modal cierra y no navega.
    var cerrar = evento.target.closest("[data-modal-cerrar]");
    if (cerrar && dialogo && dialogo.contains(cerrar)) {
      evento.preventDefault();
      dialogo.close();
      return;
    }

    var enlace = evento.target.closest("a[data-modal]");
    if (!enlace) { return; }

    // Abrir en otra pestana, o con el boton de en medio, sigue siendo abrir en
    // otra pestana: quien lo pide a proposito no quiere un modal.
    if (evento.button !== 0 || evento.metaKey || evento.ctrlKey || evento.shiftKey || evento.altKey) {
      return;
    }

    evento.preventDefault();

    var turno = ++pedidoModal;
    var espera = window.setTimeout(function () {
      if (turno === pedidoModal) { abrirEsqueleto(); }
    }, ESPERA_ESQUELETO);

    fetch(enlace.href, {
      credentials: "same-origin",
      headers: CABECERAS,
    }).then(function (respuesta) {
      return respuesta.text();
    }).then(function (html) {
      window.clearTimeout(espera);
      // Cerraron el esqueleto mientras llegaba: ya no lo quieren.
      if (turno !== pedidoModal) { return; }
      // Si la respuesta no trae tarjeta —la sesion caduco y llego el login, por
      // ejemplo— se navega de verdad, que es lo que la persona esperaba.
      if (!abrirModal(html)) { window.location.href = enlace.href; }
    }).catch(function () {
      window.clearTimeout(espera);
      if (turno !== pedidoModal) { return; }
      window.location.href = enlace.href;
    });
  });

  document.addEventListener("submit", function (evento) {
    var form = evento.target;
    // Solo los de DENTRO del dialogo. Los de main ya los lleva el manejador de
    // arriba, que sale antes por su propia comprobacion.
    if (!dialogo || !dialogo.contains(form)) { return; }

    evento.preventDefault();

    var datos;
    try {
      datos = new FormData(form, evento.submitter);
    } catch (e) {
      datos = new FormData(form);
    }

    var estado = abiertos();
    var scroll = window.scrollY;
    dialogo.setAttribute("aria-busy", "true");

    fetch(form.action || window.location.href, {
      method: "POST",
      body: datos,
      credentials: "same-origin",
      redirect: "follow",
      headers: CABECERAS,
    }).then(function (respuesta) {
      return respuesta.text().then(function (html) {
        return { html: html, url: respuesta.url };
      });
    }).then(function (r) {
      // Si vuelve la tarjeta de confirmacion es que NO se hizo: la contrasena
      // estaba mal, o algo lo impide. Se queda abierto con el aviso dentro, que
      // es justo lo que una pantalla entera hacia perder de vista.
      if (abrirModal(r.html)) { return; }

      dialogo.close();

      // Salio bien: lo que llega es la lista, y se pinta detras sin recargar.
      var cambioDePagina = r.url && r.url !== window.location.href;
      if (!pintar(r.html, estado, cambioDePagina ? 0 : scroll)) {
        window.location.href = r.url || window.location.href;
        return;
      }
      if (cambioDePagina) { window.history.pushState({ pintado: true }, "", r.url); }
    }).catch(function () {
      window.location.reload();
    }).then(function () {
      if (dialogo) { dialogo.removeAttribute("aria-busy"); }
    });
  });

  /*
   * LOS MENUS DE FILA, que son `<details>` normales.
   *
   * Sin esto funcionan: se abren, se cierran y llegan con el teclado, porque el
   * elemento es nativo. Lo que falta es lo que un <details> no hace y un menu
   * si: que abrir uno cierre el anterior, y que Escape cierre el que este
   * abierto. Con veinte filas, sin lo primero se acaba con media pantalla de
   * menus abiertos.
   *
   * Va en `document` y no en `main` porque los menus se repintan con la lista y
   * un manejador colgado de una fila concreta se iria con ella.
   */
  document.addEventListener("toggle", function (evento) {
    var menu = evento.target;
    if (!menu.open || !menu.classList || !menu.classList.contains("menu-fila")) { return; }
    document.querySelectorAll("details.menu-fila[open]").forEach(function (otro) {
      if (otro !== menu) { otro.open = false; }
    });
  }, true);

  document.addEventListener("keydown", function (evento) {
    if (evento.key !== "Escape") { return; }
    var abierto = document.querySelector("details.menu-fila[open]");
    if (!abierto) { return; }
    abierto.open = false;
    // El foco vuelve al boton que lo abrio: si se quedara en el aire, el
    // siguiente tabulador empezaria desde el principio del documento.
    var boton = abierto.querySelector("summary");
    if (boton) { boton.focus(); }
  });

  // Tocar fuera cierra, como cualquier menu. No `blur`: el foco pasa por dentro
  // del propio panel al elegir una opcion.
  document.addEventListener("click", function (evento) {
    document.querySelectorAll("details.menu-fila[open]").forEach(function (menu) {
      if (!menu.contains(evento.target)) { menu.open = false; }
    });
  });

  // Atras/adelante entre entradas que comparten este documento (las que pusimos
  // con pushState). El navegador cambia la URL pero no vuelve a cargar nada, y
  // el <main> que se ve es el que dejamos pintado: hay que pedir la pagina de
  // verdad.
  window.addEventListener("popstate", function () { window.location.reload(); });
})();
