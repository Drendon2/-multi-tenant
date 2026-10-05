/*
 * SELECCIÓN EN BLOQUE
 * ===================
 * La casilla de «todos», la cuenta de marcados y el encendido de los botones.
 *
 * Vivía dentro de panel.js porque el reparto por lote era la única pantalla que
 * lo usaba. Ya no: lo usan también los pendientes de confirmación y las
 * cancelaciones por resolver, y esas dos están en secciones distintas. Copiarlo
 * tres veces era garantizar que se separaran.
 *
 * Aquí solo hay COMODIDAD, nunca reglas. Sin JavaScript las casillas se marcan a
 * mano y el formulario se envía igual; lo único que se pierde es la casilla de
 * «todos» y saber cuántos llevas marcados. Quien decide qué entra en el lote y
 * qué se puede hacer con él es siempre el servidor.
 *
 * El marcado que espera:
 *
 *     <form id="lote-x">                     el destino, FUERA de la tabla
 *       <span data-lote-cuenta>              donde se escribe «3 marcados»
 *       <button data-lote-enviar>            se apaga si no hay ninguno
 *     <table data-lote-tabla="lote-x">       la tabla que lo alimenta
 *       <input data-lote-todos>              la casilla de la cabecera
 *     <input data-lote-todos-de="lote-x">    otra «todas», en la barra: la del
 *                                            telefono, donde la cabecera se esconde
 *       <input data-lote-fila>               una por fila
 *
 * El formulario va FUERA de la tabla y las casillas lo alcanzan con el atributo
 * `form`. Es lo que evita anidar formularios —cada fila suele tener el suyo para
 * actuar sobre uno solo—, que es HTML inválido y el navegador lo desarma a su
 * gusto.
 */
(function () {
  "use strict";

  function tablaDe(casilla) {
    return casilla.closest("table[data-lote-tabla]");
  }

  function casillasDe(tabla) {
    return tabla ? tabla.querySelectorAll("[data-lote-fila]") : [];
  }

  function refrescar(tabla) {
    if (!tabla) { return; }
    var form = document.getElementById(tabla.getAttribute("data-lote-tabla"));
    if (!form) { return; }

    var todas = casillasDe(tabla);
    var marcadas = 0;
    todas.forEach(function (c) { if (c.checked) { marcadas++; } });

    var cuenta = form.querySelector("[data-lote-cuenta]");
    if (cuenta) {
      cuenta.textContent = marcadas === 0 ? "Ninguno marcado"
        : marcadas + (marcadas === 1 ? " marcado" : " marcados");
    }

    // TODOS los botones del formulario, no solo el primero: los pendientes
    // llevan dos —confirmar y rechazar— y con `querySelector` el segundo se
    // quedaba encendido sobre una selección vacía.
    form.querySelectorAll("[data-lote-enviar]").forEach(function (boton) {
      boton.disabled = marcadas === 0;
    });

    // Las dos casillas de «todas»: la de la cabecera y la de la barra (la del
    // telefono). Las dos dicen lo mismo, o una mentiria sobre la otra.
    todasLasDeTodos(tabla).forEach(function (todos) {
      todos.checked = marcadas > 0 && marcadas === todas.length;
      // Ni marcada ni vacía: con la mitad seleccionada, cualquiera de los dos
      // estados llanos diría una mentira sobre lo que va a pasar al pulsarla.
      todos.indeterminate = marcadas > 0 && marcadas < todas.length;
    });
  }

  function todasLasDeTodos(tabla) {
    var id = tabla.getAttribute("data-lote-tabla");
    var lista = Array.prototype.slice.call(tabla.querySelectorAll("[data-lote-todos]"));
    document.querySelectorAll('[data-lote-todos-de="' + id + '"]').forEach(function (c) { lista.push(c); });
    return lista;
  }

  function refrescarTodo(raiz) {
    (raiz || document).querySelectorAll("table[data-lote-tabla]").forEach(refrescar);
  }

  document.addEventListener("change", function (evento) {
    var origen = evento.target;
    if (!origen.matches) { return; }

    if (origen.matches("[data-lote-todos], [data-lote-todos-de]")) {
      var tabla = origen.hasAttribute("data-lote-todos-de")
        ? document.querySelector('table[data-lote-tabla="' + origen.getAttribute("data-lote-todos-de") + '"]')
        : tablaDe(origen);
      casillasDe(tabla).forEach(function (c) { c.checked = origen.checked; });
      refrescar(tabla);
    } else if (origen.matches("[data-lote-fila]")) {
      refrescar(tablaDe(origen));
    }
  });

  // Para quien inyecte tablas después de cargar la página —el Panel pide el
  // cuerpo de cada promotoría al desplegarla—. Se avisa por evento y no por una
  // variable global para que ninguno de los dos archivos tenga que saber si el
  // otro llegó a cargarse.
  document.addEventListener("lote:refrescar", function (evento) {
    refrescarTodo(evento.detail && evento.detail.raiz);
  });

  // Al cargar: el navegador conserva las casillas marcadas al volver atrás, y la
  // cuenta tiene que reflejarlo.
  refrescarTodo(document);
})();
