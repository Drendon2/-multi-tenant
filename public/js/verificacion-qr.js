/*
  LEER EL QR DE LA INSTITUCION PARA DEJAR LA CLASE VERIFICADA.

  Lo que hace: enciende la camara, y en cuanto reconoce un codigo del sistema lo
  mete en el campo oculto y ENVIA el formulario. Quien decide todo lo demas
  —que el codigo sea de ESTA institucion, que la clase sea de hoy, que no este
  ya firmada— es el SERVIDOR, en `VerificacionExterna`. Este guion no sabe
  ninguna de esas reglas y no debe aprenderselas.

  POR QUE NO COMPARTE CODIGO CON `asistencia-qr.js`, que hace algo parecido.
  Aquel resuelve un problema que aqui no existe: cuarenta carnes distintos, uno
  por persona, cotejados CONTRA UN MAPA DE HUELLAS para no pagar un viaje al
  servidor por escaneo —que en este hosting cuesta segundo y medio, y se escanea
  de pie en el salon—. Aqui hay UN codigo y UNA respuesta posible, asi que no
  hay mapa, ni huellas, ni segunda opinion, ni marcas que poner en filas: se lee
  y se envia. Compartir aquel archivo habria significado arrastrar el 70% que
  aqui sobra y dejar los dos atados; lo que SI se comparte de verdad es lo unico
  que los dos necesitan y ya esta fuera: el prefijo `MTR:`, el `jsqr.js` de
  `vendor/` y la hoja de estilos `.qr-lector`.

  UN SOLO ESCANEO Y SE ACABA. En cuanto un codigo bueno entra, la camara se
  apaga y el formulario se envia: no hay nada mas que leer. Es la diferencia de
  fondo con pasar lista, donde la camara se queda encendida para los cuarenta
  siguientes.

  SIN JAVASCRIPT NO HAY BOTON. El panel nace con `hidden` puesto en la plantilla
  y es este guion quien lo destapa, y solo si el aparato tiene camara. Un boton
  de «leer el QR» que no puede leer nada es el error que este proyecto ya pago
  con el ojo de la contrasena. Y no deja a nadie encerrado: la institucion
  verifica desde su cuenta, sin camara y sin plazo.

  DOS LECTORES, EL BUENO PRIMERO. Donde el navegador trae `BarcodeDetector`
  —Chrome en Android, que es la mitad larga del personal— se usa ese: lo resuelve
  el sistema y no descarga nada. Donde no, se baja `jsqr.js`, y SE BAJA AL ABRIR
  LA CAMARA y no al abrir la pantalla.
*/
(function () {
  "use strict";

  var PREFIJO = "MTR:";

  // Cada cuanto se mira la imagen. A 10 por segundo un codigo se lee al vuelo y
  // el telefono no se calienta; el limite de verdad es la camara, no esto.
  var CADA_MS = 100;

  // AL REPINTARSE <main> ESTE GUION SE VUELVE A EJECUTAR (lo recrea
  // `acciones.js` al guardar sin recargar), y el <video> de antes ya no existe
  // pero SU CAMARA SIGUE ENCENDIDA: la luz del aparato queda puesta y nadie
  // entiende por que. Lo primero de todo es apagar la de la vuelta anterior.
  if (window.__lectorInstitucion) {
    window.__lectorInstitucion.apagar();
    window.__lectorInstitucion = null;
  }

  var panel = document.querySelector("[data-qr-verificar]");
  if (!panel) { return; }

  var forma = panel.querySelector("[data-qr-forma]");
  var campo = panel.querySelector("[data-qr-codigo]");
  var abrir = panel.querySelector("[data-qr-abrir]");
  var cerrar = panel.querySelector("[data-qr-cerrar]");
  var caja = panel.querySelector("[data-qr-camara]");
  var video = panel.querySelector("video");
  var aviso = panel.querySelector("[data-qr-aviso]");

  if (!forma || !campo || !abrir || !caja || !video) { return; }

  // La camara pide un origen seguro (https, o localhost). Si no lo hay no queda
  // nada que ensenar: el panel se queda oculto y la institucion verifica desde
  // su cuenta, como siempre.
  if (!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia)) { return; }

  panel.hidden = false;

  var stream = null;
  var reloj = null;
  var detector = null;
  var lienzo = null;
  var enviando = false;
  var ajeno = { texto: "", cuando: 0 };

  window.__lectorInstitucion = { apagar: apagar };

  abrir.addEventListener("click", encender);

  if (cerrar) {
    cerrar.addEventListener("click", function () {
      apagar();
      decir("");
    });
  }

  // La camara no se queda encendida si la persona se va de la pantalla. El
  // `pagehide` cubre lo que `beforeunload` no cubre en un telefono, que es
  // justamente donde esto se usa.
  window.addEventListener("pagehide", apagar);

  function encender() {
    // Dentro del toque: el navegador no deja sonar sin un gesto, y el bip
    // llega despues, desde el temporizador del lector.
    prepararSonido();

    navigator.mediaDevices.getUserMedia({
      // La de ATRAS: se escanea un carton que sostiene otra persona.
      video: { facingMode: { ideal: "environment" } },
      audio: false
    }).then(function (medio) {
      stream = medio;
      video.srcObject = medio;
      video.setAttribute("playsinline", "");
      return video.play();
    }).then(function () {
      caja.hidden = false;
      abrir.hidden = true;
      if (cerrar) { cerrar.hidden = false; }
      decir("Apunta al código de la institución.");
      return prepararLector();
    }).then(function () {
      reloj = window.setInterval(mirar, CADA_MS);
    }).catch(function (error) {
      apagar();
      decir(porQueNoAbrio(error), "mal");
    });
  }

  function apagar() {
    if (reloj) { window.clearInterval(reloj); reloj = null; }
    if (stream) {
      stream.getTracks().forEach(function (pista) { pista.stop(); });
      stream = null;
    }
    if (video) { video.srcObject = null; }
    if (caja) { caja.hidden = true; }
    if (abrir) { abrir.hidden = false; }
    if (cerrar) { cerrar.hidden = true; }
  }

  /** El lector del sistema si lo hay; si no, se baja jsQR. */
  function prepararLector() {
    if (detector || window.jsQR) { return Promise.resolve(); }

    if (window.BarcodeDetector) {
      return window.BarcodeDetector.getSupportedFormats().then(function (formatos) {
        if (formatos.indexOf("qr_code") >= 0) {
          detector = new window.BarcodeDetector({ formats: ["qr_code"] });
          return;
        }
        return bajarJsQr();
      }).catch(bajarJsQr);
    }

    return bajarJsQr();
  }

  function bajarJsQr() {
    if (window.jsQR) { return Promise.resolve(); }

    return new Promise(function (listo, falla) {
      var script = document.createElement("script");
      // La ruta va sin version a proposito: es una copia congelada de una
      // libreria de fuera, no un archivo de este proyecto que cambie.
      script.src = "/js/vendor/jsqr.js";
      script.onload = function () { listo(); };
      script.onerror = function () { falla(new Error("no se pudo cargar el lector")); };
      document.head.appendChild(script);
    });
  }

  function mirar() {
    if (!video || video.readyState < 2) { return; }

    if (detector) {
      detector.detect(video).then(function (codigos) {
        if (codigos && codigos.length) { alLeer(codigos[0].rawValue); }
      }).catch(function () { /* un cuadro ilegible no es un error que contar */ });
      return;
    }

    if (!window.jsQR) { return; }

    if (!lienzo) { lienzo = document.createElement("canvas"); }

    var ancho = video.videoWidth;
    var alto = video.videoHeight;
    if (!ancho || !alto) { return; }

    lienzo.width = ancho;
    lienzo.height = alto;

    var pincel = lienzo.getContext("2d", { willReadFrequently: true });
    pincel.drawImage(video, 0, 0, ancho, alto);

    var codigo = window.jsQR(pincel.getImageData(0, 0, ancho, alto).data, ancho, alto, {
      inversionAttempts: "dontInvert"
    });

    if (codigo && codigo.data) { alLeer(codigo.data); }
  }

  function alLeer(texto) {
    // Con la camara encendida, un carton quieto delante se lee decenas de veces
    // por segundo. Aqui no hace falta el anti-repeticion por tiempo que lleva el
    // lector de carnes: el primero bueno cierra la tienda.
    if (enviando) { return; }

    if (texto.indexOf(PREFIJO) !== 0) {
      // El mismo codigo ajeno quieto en el encuadre se lee diez veces por
      // segundo: sin este freno, desde que suena (28/09/2026) seria un zumbido
      // continuo. Es el `REPETIR_MS` del lector de carnes.
      var ahora = Date.now();
      if (texto === ajeno.texto && ahora - ajeno.cuando < 2500) { return; }
      ajeno = { texto: texto, cuando: ahora };

      // NO se apaga la camara: lo mas probable es que se haya colado el codigo
      // de un producto en el encuadre, y quien esta sosteniendo el carton tiene
      // que poder seguir intentandolo sin volver a pulsar nada.
      decir("Ese código no es del sistema. Apunta al de la institución.", "mal");
      return;
    }

    enviando = true;
    campo.value = texto;
    apagar();
    // El bip dice LEIDO, no verificado: eso lo decide el servidor y lo escribe
    // la pantalla al volver. Es la misma promesa que el «marcado» del carne.
    sonar(true);
    decir("Comprobando…");

    // Se envia el FORMULARIO y no un `fetch`: asi pasa por `acciones.js` como
    // cualquier otra accion de la pantalla —que es quien repinta <main> y lleva
    // el aviso a la vista— y, sin `acciones.js`, navega. Por los dos caminos el
    // resultado lo escribe el servidor, que es quien sabe.
    if (typeof forma.requestSubmit === "function") {
      forma.requestSubmit();
    } else {
      forma.submit();
    }
  }

  // Las clases son las del lector del carne (`.qr-lector-aviso`), no unas
  // paralelas: es el mismo componente y dos juegos de clases para uno solo es
  // como acaba divergiendo lo que se ve.
  /**
   * EL BIP (28/09/2026, pedido por el usuario tras ponerlo en el lector de
   * carnes). Es una COPIA de `prepararSonido()`/`sonar()` de
   * `asistencia-qr.js`, y lo es por lo mismo que dice la cabecera: atar los dos
   * archivos por cuarenta lineas pesa mas que repetirlas. El contexto de audio
   * SI se comparte (`window.__sonidoCarne`), porque el navegador limita cuantos
   * se abren. Si cambias los tonos en uno, cambialos en el otro: el profesor
   * usa los dos y un mismo sonido tiene que significar lo mismo.
   *
   * Bip agudo = leido; dos tonos graves = ese codigo no vale.
   */
  function prepararSonido() {
    var Contexto = window.AudioContext || window.webkitAudioContext;
    if (!Contexto) { return; }

    try {
      if (!window.__sonidoCarne) { window.__sonidoCarne = new Contexto(); }
      if (window.__sonidoCarne.state === "suspended") { window.__sonidoCarne.resume(); }
    } catch (e) {
      window.__sonidoCarne = null;
    }
  }

  function sonar(bien) {
    var ctx = window.__sonidoCarne;
    if (!ctx || ctx.state !== "running") { return; }

    // [frecuencia, empieza, dura] en segundos.
    var tonos = bien ? [[1760, 0, 0.09]] : [[330, 0, 0.13], [330, 0.19, 0.13]];
    var t0 = ctx.currentTime;

    tonos.forEach(function (tono) {
      var osc = ctx.createOscillator();
      var vol = ctx.createGain();
      var inicio = t0 + tono[1];
      var fin = inicio + tono[2];
      var nivel = bien ? 0.35 : 0.15;

      osc.type = bien ? "sine" : "square";
      osc.frequency.value = tono[0];

      // Rampas cortas (sin ellas, chasquido) y el volumen sostenido en medio.
      vol.gain.setValueAtTime(0.0001, inicio);
      vol.gain.exponentialRampToValueAtTime(nivel, inicio + 0.01);
      vol.gain.setValueAtTime(nivel, fin - 0.02);
      vol.gain.exponentialRampToValueAtTime(0.0001, fin);

      osc.connect(vol);
      vol.connect(ctx.destination);
      osc.start(inicio);
      osc.stop(fin + 0.02);
    });
  }

  function decir(texto, tono) {
    if (tono === "mal") { sonar(false); }

    if (!aviso) { return; }
    aviso.textContent = texto;
    aviso.className = "qr-lector-aviso" + (tono === "mal" ? " qr-lector-aviso-mal" : "");
  }

  /**
   * Por que no abrio la camara, dicho de forma que se pueda hacer algo.
   *
   * «No se pudo acceder a la camara» manda a todo el mundo al mismo sitio
   * equivocado: quien la nego tiene que ir a los permisos del navegador, y
   * quien no tiene camara tiene que dejar de intentarlo.
   */
  function porQueNoAbrio(error) {
    var nombre = error && error.name ? error.name : "";

    if (nombre === "NotAllowedError" || nombre === "SecurityError") {
      return "El navegador no dio permiso para la cámara. Actívalo y vuelve a intentarlo.";
    }

    if (nombre === "NotFoundError" || nombre === "OverconstrainedError") {
      return "Este aparato no tiene cámara. La institución puede verificar la clase desde su cuenta.";
    }

    return "No se pudo abrir la cámara. La institución puede verificar la clase desde su cuenta.";
  }
})();
