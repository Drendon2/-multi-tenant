/*
  PASAR LISTA LEYENDO EL CARNE QR DEL ESTUDIANTE.

  Lo que hace: enciende la camara, y cada vez que reconoce un carne de esta
  lista marca a esa persona como «Asistio» y guarda el codigo leido en un campo
  oculto. El guardado es el de siempre —el boton «Guardar asistencia»— y por ahi
  pasan las dos cosas: las marcas de la hoja y los codigos, que el servidor
  vuelve a resolver para dejar la clase verificada. Esto NO guarda nada por su
  cuenta.

  POR QUE EL COTEJO ES LOCAL Y AUN ASI EL CODIGO VIAJA. La pagina no trae los
  codigos de nadie: trae la HUELLA de cada uno (SHA-256, ver `CarneQr::huella`).
  Al leer un cuadrito se calcula su huella y se busca en la lista, asi que el
  profesor ve el nombre al instante, sin un viaje al servidor por escaneo —que
  en este hosting cuesta segundo y medio cada uno, y se escanea de pie en el
  salon—. Pero quien decide si una clase queda confirmada es el SERVIDOR, y para
  eso necesita el codigo entero; de lo contrario bastaria escribir numeros de
  matricula en el formulario.

  SIN JAVASCRIPT NO HAY BOTON. El panel nace con `hidden` puesto en la plantilla
  y es este guion el que lo destapa, y solo si el aparato tiene camara y
  `crypto.subtle`. Un boton de «leer el carne» que no puede leer nada es
  exactamente el error que ya costo el ojo de la contrasena.

  DOS LECTORES, EL BUENO PRIMERO. Donde el navegador trae `BarcodeDetector`
  —Chrome en Android, que es la mitad larga del personal— se usa ese: lo resuelve
  el sistema, gasta menos bateria y no descarga nada. Donde no —Safari en iPhone,
  sobre todo— se baja `jsqr.js`, que son 130 KB, Y SE BAJA AL ABRIR LA CAMARA y
  no al abrir la pantalla: quien pasa lista a mano no lo paga nunca.
*/
(function () {
  "use strict";

  var PREFIJO = "MTR:";

  // Cada cuanto se mira la imagen. A 10 por segundo un codigo se lee al vuelo y
  // el telefono no se calienta; el limite de verdad es la camara, no esto.
  var CADA_MS = 100;

  // Lo mismo leido dos veces seguidas no vuelve a anunciarse: con la camara
  // encendida, un carne quieto delante se lee decenas de veces.
  var REPETIR_MS = 2500;

  // AL REPINTARSE <main> ESTE GUION SE VUELVE A EJECUTAR (lo recrea
  // `acciones.js` al guardar sin recargar), y el <video> de antes ya no existe
  // pero SU CAMARA SIGUE ENCENDIDA: la luz del aparato queda puesta y nadie
  // entiende por que. Lo primero de todo es apagar la de la vuelta anterior.
  if (window.__lectorCarne) {
    window.__lectorCarne.apagar();
    window.__lectorCarne = null;
  }

  var panel = document.querySelector("[data-qr-lector]");
  var form = document.getElementById("form-asistencia");
  if (!panel || !form) { return; }

  var abrir = panel.querySelector("[data-qr-abrir]");
  var cerrar = panel.querySelector("[data-qr-cerrar]");
  var caja = panel.querySelector("[data-qr-camara]");
  var video = panel.querySelector("video");
  var aviso = panel.querySelector("[data-qr-aviso]");
  var leidos = panel.querySelector("[data-qr-leidos]");

  // `crypto.subtle` y la camara piden los dos un origen seguro (https, o
  // localhost). Si falta alguno no hay nada que ensenar: el panel se queda
  // oculto y la lista de abajo sigue funcionando a mano, como siempre.
  var hayCamara = !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
  var hayHuella = !!(window.crypto && window.crypto.subtle && window.TextEncoder);
  if (!hayCamara || !hayHuella) { return; }

  panel.hidden = false;

  // La lista de quien esta en esta hoja, por huella.
  var porHuella = {};
  form.querySelectorAll("[data-qr-huella]").forEach(function (fila) {
    porHuella[fila.getAttribute("data-qr-huella")] = fila;
  });

  var stream = null;
  var reloj = null;
  var detector = null;
  var lienzo = null;
  var ultimo = { texto: "", cuando: 0 };
  var yaLeidos = {};

  window.__lectorCarne = { apagar: apagar };

  abrir.addEventListener("click", encender);
  cerrar.addEventListener("click", function () {
    apagar();
    decir("");
  });

  // La camara no se queda encendida si la persona se va de la pantalla. El
  // `pagehide` cubre lo que `beforeunload` no cubre en un telefono, que es
  // justamente donde esto se usa.
  window.addEventListener("pagehide", apagar);

  function encender() {
    navigator.mediaDevices.getUserMedia({
      // La de ATRAS: se escanea un papel que sostiene otra persona.
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
      cerrar.hidden = false;
      decir("Apunta al código del carné.");
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
    var ahora = Date.now();
    if (texto === ultimo.texto && ahora - ultimo.cuando < REPETIR_MS) { return; }
    ultimo = { texto: texto, cuando: ahora };

    if (texto.indexOf(PREFIJO) !== 0) {
      decir("Ese código no es un carné del sistema.", "mal");
      return;
    }

    var codigo = texto.slice(PREFIJO.length);

    huellaDe(codigo).then(function (huella) {
      var fila = porHuella[huella];

      if (!fila) {
        // Es un carne de verdad, pero de alguien que no esta en esta lista.
        // Decirlo asi y no «no existe»: manda a mirar el grupo, que es donde
        // casi siempre esta el enredo.
        decir("Ese carné no es de ningún estudiante de esta lista.", "mal");
        return;
      }

      marcar(fila, codigo);
    });
  }

  function marcar(fila, codigo) {
    var nombre = (fila.querySelector(".asistencia-nombre") || fila).textContent.trim();
    var id = fila.getAttribute("data-matricula");

    var radio = fila.querySelector('input[type="radio"][value="asistio"]');
    if (radio) { radio.checked = true; }

    // El codigo viaja con el formulario para que el SERVIDOR confirme la clase.
    // Uno por persona: si el mismo carne se lee otra vez no se anade otro campo.
    if (!yaLeidos[id]) {
      yaLeidos[id] = true;
      var campo = document.createElement("input");
      campo.type = "hidden";
      campo.name = "qr[]";
      campo.value = PREFIJO + codigo;
      leidos.appendChild(campo);
    }

    fila.classList.add("asistencia-fila-leida");
    fila.scrollIntoView({ block: "center" });

    if (navigator.vibrate) { navigator.vibrate(60); }

    decir(nombre + " — marcado y verificado.", "bien");
  }

  /** SHA-256 en hexadecimal, que es como viene la huella en la plantilla. */
  function huellaDe(texto) {
    return window.crypto.subtle
      .digest("SHA-256", new TextEncoder().encode(texto))
      .then(function (buffer) {
        var bytes = new Uint8Array(buffer);
        var salida = "";
        for (var i = 0; i < bytes.length; i++) {
          salida += bytes[i].toString(16).padStart(2, "0");
        }
        return salida;
      });
  }

  function decir(texto, como) {
    // Se vacia ANTES de escribir: el mismo aviso dos veces seguidas no lo
    // anuncia un lector de pantalla si el nodo no cambia. Es la misma trampa de
    // las cajas `[data-voz]` del layout.
    aviso.textContent = "";
    aviso.className = "qr-lector-aviso" + (como ? " qr-lector-aviso-" + como : "");
    if (texto) { window.setTimeout(function () { aviso.textContent = texto; }, 30); }
  }

  function porQueNoAbrio(error) {
    var nombre = error && error.name;

    if (nombre === "NotAllowedError" || nombre === "SecurityError") {
      return "No diste permiso para usar la cámara. Búscalo en el candado de la barra de direcciones y vuelve a intentarlo.";
    }
    if (nombre === "NotFoundError" || nombre === "OverconstrainedError") {
      return "Este aparato no tiene una cámara que se pueda usar. Pasa lista a mano en la lista de abajo.";
    }
    if (nombre === "NotReadableError") {
      return "La cámara está ocupada por otra aplicación. Ciérrala y vuelve a intentarlo.";
    }

    return "No se pudo abrir la cámara. Pasa lista a mano en la lista de abajo.";
  }
})();
