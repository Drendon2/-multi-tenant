{{--
  «Marcar todas» en la BARRA del lote, solo en el teléfono (05/10/2026).

  La casilla de siempre vive en la cabecera de la tabla, y bajo 640px las
  tablas `.tabla-personas` se vuelven fichas y esconden la cabecera: en el
  celular no había forma de marcarlas todas, justo donde se usa el sistema.
  `lote.js` la trata como otra casilla de «todas» de la misma tabla y mantiene
  las dos de acuerdo. En escritorio se esconde: ahí está la de la cabecera.

  Sin `name`: no viaja con el formulario.

  Recibe `$loteId`, el `id` del formulario y el `data-lote-tabla` de la tabla.
--}}
<label class="lote-todas">
  <input type="checkbox" data-lote-todos-de="{{ $loteId }}">
  <span>Marcar todas</span>
</label>
