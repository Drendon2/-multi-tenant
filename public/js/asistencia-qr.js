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

  EL COTEJO LOCAL ES UN ATAJO, NO LA ULTIMA PALABRA. Se aprendio en produccion
  el 21/09/2026: las huellas son las de quien YA tenia codigo cuando se pinto la
  pagina, y el codigo se crea al sacar el carne por primera vez. Un carne
  impreso cinco minutos despues de abrir la lista no estaba en el mapa, y el
  lector contestaba «ese carne no es de ningun estudiante de esta lista» —o sea
  acusaba al estudiante de un desfase de la pantalla, sin que nada fallara—.
  Ahora, cuando el atajo no encuentra a nadie, se le pregunta al servidor
  (`data-qr-comprobar`), que es quien sabe; y lo que vuelve se guarda en el mapa
  para que el segundo escaneo del mismo carne no viaje.

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
  var pendientes = panel.querySelector("[data-qr-pendientes]");

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
    // El sonido se prepara AQUI, dentro del toque: Safari y Chrome no dejan
    // sonar a una pagina que no haya recibido un gesto, y los bips llegan
    // despues, desde el temporizador del lector, que no cuenta como gesto.
    prepararSonido();

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

      if (fila) {
        marcar(fila, codigo);
        return;
      }

      // NO SE DA POR PERDIDO AQUI, y esa es la leccion del 21/09/2026: las
      // huellas de esta pagina son las de quien YA tenia codigo cuando se
      // pinto, y el codigo se crea al sacar el carne. Un carne impreso despues
      // de abrir esta lista no esta en el mapa, y dar eso por «no es de esta
      // clase» es acusar al estudiante de un desfase de la pantalla. Se le
      // pregunta al servidor, que es quien sabe.
      preguntarAlServidor(codigo, huella);
    });
  }

  /** La segunda opinion: el servidor resuelve el carne contra la clase. */
  function preguntarAlServidor(codigo, huella) {
    decir("Comprobando el carné…");

    var testigo = form.querySelector('input[name="_token"]');

    fetch(panel.getAttribute("data-qr-comprobar"), {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "Accept": "application/json",
        "X-CSRF-TOKEN": testigo ? testigo.value : ""
      },
      body: JSON.stringify({ codigo: PREFIJO + codigo })
    }).then(function (respuesta) {
      return respuesta.json();
    }).then(function (dato) {
      if (dato.encontrado) {
        var fila = form.querySelector('[data-matricula="' + dato.matricula + '"]');

        if (!fila) {
          // La persona es de esta clase pero su renglon no esta en la pantalla:
          // la lista cambio desde que se abrio. Recargar es lo unico honesto.
          decir(dato.nombre + " entró a esta clase después de que abrieras la lista. Recarga la página.", "mal");
          return;
        }

        // Se guarda la huella: el segundo escaneo del mismo carne ya no viaja.
        if (dato.huella) { porHuella[dato.huella] = fila; }

        marcar(fila, codigo);
        return;
      }

      decir(porQueNoVale(dato), "mal");
    }).catch(function () {
      // Sin red no se puede afirmar nada, y decir «no está en la lista» seria
      // mentir con seguridad. Se dice lo que pasa y se ofrece la salida.
      decir("No se pudo comprobar el carné (sin conexión). Márcalo a mano en la lista.", "mal");
    });
  }

  function porQueNoVale(dato) {
    if (dato.motivo === "otra_lista") {
      return "Ese carné es de " + (dato.nombre || "otra persona") + ", que no está en esta clase.";
    }
    if (dato.motivo === "desconocido") {
      return "Ese carné ya no sirve: seguramente lo reemplazaron por uno nuevo.";
    }
    if (dato.motivo === "sin_permiso") {
      return "Pasar lista es de quien dicta la promotoría.";
    }

    return "Ese código no es un carné del sistema.";
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

    // «MARCADO», NO «VERIFICADO». Escanear no escribe nada: pone la marca en
    // esta pantalla y guarda el codigo para que viaje al guardar. La cifra de
    // verificacion la pinta el SERVIDOR, asi que no se mueve hasta entonces.
    // Decir «verificado» aqui prometia algo que todavia no habia pasado, y el
    // usuario lo leyo como un fallo el 21/09/2026: marcaba pero el conteo no
    // subia. Lo que falta hacer va aparte, en un renglon que se queda puesto.
    decir(nombre + " — marcado.", "bien");
    contar();
  }

  /**
   * Cuantos carnes llevas leidos y que falta para que cuenten.
   *
   * Va en su propio renglon y no dentro del aviso de cada escaneo: el aviso lo
   * pisa el siguiente carne a los dos segundos, y esto es justamente lo que
   * tiene que seguir ahi cuando se acabe de escanear a los veinte.
   */
  function contar() {
    var cuantos = Object.keys(yaLeidos).length;

    if (!pendientes || cuantos === 0) { return; }

    pendientes.hidden = false;
    pendientes.textContent = cuantos === 1
      ? "1 carné leído. Pulsa «Guardar asistencia» para que la verificación cuente."
      : cuantos + " carnés leídos. Pulsa «Guardar asistencia» para que las verificaciones cuenten.";
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

  /**
   * EL BIP (28/09/2026, pedido por el usuario): la lectura es tan rapida que
   * el profesor no se entera de si leyo. La vibracion no basta —el iPhone no
   * la tiene y en la mano apenas se nota— y mirar la pantalla es justo lo que
   * no hace quien sostiene el telefono frente a un carne.
   *
   * DOS SONIDOS Y NO UNO: un bip agudo si marco a alguien y dos tonos graves
   * si el carne no vale. Con un solo sonido para todo, un rechazo se oiria
   * como un acierto, y eso es peor que el silencio.
   *
   * Se genera con Web Audio y no con un archivo: no hay nada que bajar ni que
   * cachear, y suena al instante. El contexto se reutiliza entre repintados
   * (`window.__sonidoCarne`) porque el navegador limita cuantos se abren.
   *
   * Lo que NO se puede: el interruptor de silencio del iPhone lo calla, y eso
   * no lo decide la pagina.
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

      osc.type = bien ? "sine" : "square";
      osc.frequency.value = tono[0];

      // Rampa corta de entrada y salida (un corte en seco suena a chasquido) y
      // el volumen SOSTENIDO en medio. Con una caida exponencial de punta a
      // punta, medido el 28/09/2026, de 90 ms solo se oian 40.
      var nivel = bien ? 0.35 : 0.15;
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

  function decir(texto, como) {
    // El sonido acompana a los avisos que dicen un DESENLACE; «Comprobando…» y
    // «Apunta al codigo» no llevan ninguno.
    if (como === "bien") { sonar(true); }
    if (como === "mal") { sonar(false); }

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
