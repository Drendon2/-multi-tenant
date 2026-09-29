/*
 * PANEL: CARGA AL DESPLEGAR
 * =========================
 * Una sola cosa, y solo hace falta en el Panel: el cuerpo de cada promotoría se
 * pide cuando alguien la abre, no antes. El índice manda solo los títulos; con
 * trescientos estudiantes, mandarlo todo eran cientos de KB para enseñar una
 * lista de veintiún renglones plegados.
 *
 * La casilla de «todos» y la cuenta de marcados vivían aquí y se fueron a
 * lote.js: dejaron de ser cosa del Panel en cuanto los pendientes y las
 * cancelaciones empezaron a usarlas.
 *
 * Va como ARCHIVO y fuera de <main>. Los scripts que viven dentro se vuelven a
 * ejecutar en cada repintado sin recarga (ver acciones.js), y como estos delegan
 * en `document`, cada repintado dejaría un oyente más escuchando lo mismo.
 * Cargado desde el <head> del layout se registra una vez y sobrevive a los
 * cambios de <main>, que es exactamente lo que necesita.
 *
 * Sin JavaScript el Panel sigue sirviendo: cada promotoría lleva dentro un
 * <noscript> con el enlace a su propia página.
 */
(function () {
  "use strict";

  /* ---------------------------------------------------------------------
     1. El cuerpo de una promotoría, al desplegarla
     --------------------------------------------------------------------- */

  /*
   * La forma de lo que va a llegar: el renglon del profesor, el cupo, el boton
   * de «Nuevo grupo» y unas filas de estudiante con su foto. No tiene que
   * coincidir al pixel; tiene que ocupar mas o menos lo mismo para que la
   * pagina no pegue un salto cuando llega el cuerpo de verdad.
   */
  var FILA =
    '<div class="esqueleto-fila"><span class="esqueleto-foto"></span>' +
    '<span class="esqueleto-texto"><span class="esqueleto-linea media"></span>' +
    '<span class="esqueleto-linea corta"></span></span></div>';
  var ESQUELETO =
    '<div class="esqueleto">' +
    '<span class="esqueleto-voz">Cargando…</span>' +
    '<span class="esqueleto-linea corta"></span>' +
    '<span class="esqueleto-linea media"></span>' +
    '<span class="esqueleto-boton"></span>' +
    '<span class="esqueleto-linea titulo"></span>' +
    FILA + FILA + FILA +
    '</div>';

  function cargar(detalle) {
    var destino = detalle.querySelector("[data-cuerpo-destino]");
    var url = detalle.getAttribute("data-cuerpo");
    if (!destino || !url || detalle.dataset.cargando === "si") { return; }

    // `data-cargado` se marca sobre el DESTINO y no sobre el <details>: tras un
    // repintado sin recarga el <details> es un elemento nuevo con el mismo id,
    // así que una marca en él viajaría con datos viejos. En el destino no: el
    // destino llega nuevo en cada repintado y dice la verdad sobre SU contenido.
    //
    // Hay dos maneras de que ya esté puesto, y las dos acaban aquí: porque lo
    // trajo este archivo hace un rato, o porque venía dentro de la respuesta de
    // una acción, que el servidor pinta y marca (ver `App\Support\Fragmento`).
    // No hay nada que pedir, pero sí que aplicar lo que depende de tener el
    // cuerpo delante — sin esto, una promotoría repintada sale con sus grupos
    // plegados y con la cuenta de marcados en cero.
    if (destino.dataset.cargado === "si") {
      colocado(destino);
      return;
    }

    detalle.dataset.cargando = "si";

    // El esqueleto lo pone ESTE archivo y no la plantilla: sin JavaScript nadie
    // lo quitaria y seria una promesa de contenido que no llega nunca. Ahi el
    // servidor deja su «Cargando…» y el enlace del <noscript>.
    destino.innerHTML = ESQUELETO;
    destino.setAttribute("aria-busy", "true");

    fetch(url, { headers: { "X-Requested-With": "XMLHttpRequest" } })
      .then(function (r) {
        if (!r.ok) { throw new Error(r.status); }
        return r.text();
      })
      .then(function (html) {
        destino.innerHTML = html;
        destino.dataset.cargado = "si";
        colocado(destino);
      })
      .catch(function () {
        // Un fallo de red no puede dejar la promotoría en "Cargando…" para
        // siempre: se dice qué pasó y se deja una salida que no depende de que
        // el JavaScript vuelva a funcionar.
        destino.innerHTML =
          '<p class="aviso">No se pudo cargar esta promotoría. ' +
          '<a href="' + url + '">Ábrela en su propia página</a> o vuelve a intentarlo.</p>';
      })
      .finally(function () {
        destino.removeAttribute("aria-busy");
        delete detalle.dataset.cargando;
      });
  }

  // `toggle` no burbujea, así que se escucha en fase de captura. Es lo que
  // permite un solo oyente para todas las promotorías, incluidas las que
  // aparezcan después de un repintado.
  document.addEventListener(
    "toggle",
    function (evento) {
      var detalle = evento.target;
      if (detalle.matches && detalle.matches("details[data-cuerpo]") && detalle.open) {
        cargar(detalle);
      }
    },
    true
  );

  // Lo que hay que hacer cuando el cuerpo de una promotoria esta delante, venga
  // de donde venga. En un solo sitio a proposito: son dos caminos —pedirlo al
  // desplegar y recibirlo dentro de una accion— y separarlos garantizaba que uno
  // de los dos se quedara sin la mitad de esto la proxima vez que se anada algo.
  function colocado(destino) {
    reabrirGrupos(destino);
    refrescarLotes(destino);
  }

  // Las tablas recien inyectadas traen sus casillas: se avisa a lote.js para que
  // ponga al dia la cuenta y los botones de esa promotoria.
  function refrescarLotes(raiz) {
    document.dispatchEvent(new CustomEvent("lote:refrescar", { detail: { raiz: raiz } }));
  }

  /* ---------------------------------------------------------------------
     2. Las listas de grupo abiertas, que sobreviven al repintado
     --------------------------------------------------------------------- */

  /*
   * Las listas de estudiantes de cada grupo van plegadas y se abren a mano. Al
   * quitar a alguien de un grupo, `acciones.js` repinta <main> sin recargar y
   * la lista que estabas mirando se cerraria sola.
   *
   * Y NO lo arregla `acciones.js`, aunque ya guarde y reponga los <details> con
   * id: los repone JUSTO despues de reemplazar <main>, y en ese momento el
   * cuerpo de la promotoria todavia dice «Cargando…» — estos <details> no
   * existen aun, asi que no hay nada que reponer. Se piden despues, y para
   * entonces `acciones.js` ya termino.
   *
   * De ahi que el recuerdo viva AQUI, que es quien inyecta ese cuerpo y sabe
   * cuando esta puesto. En memoria y no en `sessionStorage` a proposito: es una
   * comodidad de un rato, no un ajuste; al recargar la pagina se vuelve al
   * estado de partida, que es plegado.
   *
   * Sobrevive a los repintados porque este archivo se carga UNA vez y fuera de
   * <main> (ver la cabecera): la variable sigue ahi cuando el HTML se rehace.
   */
  var gruposAbiertos = {};

  document.addEventListener(
    "toggle",
    function (evento) {
      var detalle = evento.target;
      if (detalle.matches && detalle.matches("details[data-grupo][id]")) {
        gruposAbiertos[detalle.id] = detalle.open;
      }
    },
    true
  );

  function reabrirGrupos(raiz) {
    raiz.querySelectorAll("details[data-grupo][id]").forEach(function (detalle) {
      if (gruposAbiertos[detalle.id]) { detalle.open = true; }
    });
  }

  /* ---------------------------------------------------------------------
     3. Las que ya llegan abiertas
     --------------------------------------------------------------------- */

  /*
   * UNA PROMOTORIA QUE VIENE ABIERTA DEL SERVIDOR NO DISPARA `toggle`.
   *
   * Todo lo de arriba cuelga de ese evento, y eso bastaba mientras el Panel
   * llegara siempre plegado. Desde el 10/09/2026 no: volver de crear o editar
   * un grupo trae `?abrir=N` y esa promotoria se pinta ya desplegada, asi que
   * nadie la toca y nadie pide su cuerpo. Se queda en «Cargando…» para siempre
   * — que es exactamente lo que el usuario reporto.
   *
   * El barrido va al final del archivo y no dentro de un `DOMContentLoaded`: el
   * script se carga con `defer`, asi que cuando corre el documento ya esta
   * puesto. Y hace falta que sea asi, porque `acciones.js` puede inyectar este
   * archivo DESPUES de un repintado, cuando ese evento ya paso hace rato y no
   * va a volver.
   */
  document.querySelectorAll("details[data-cuerpo][open]").forEach(cargar);
})();
