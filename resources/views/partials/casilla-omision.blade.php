{{--
  La casilla de una clase no dictada en el lote. El valor es «grupo|fecha»
  porque eso es lo que identifica una omisión: la pendiente no tiene fila en la
  base todavía, así que no hay id que mandar. El servidor parte el par y vuelve
  a comprobar el grupo (ver `CancelacionesController::clasificarLote`).
--}}
<input type="checkbox" name="omisiones[]" value="{{ $falta['grupo']->id }}|{{ $falta['fecha']->toDateString() }}"
       form="{{ $loteId }}" data-lote-fila
       aria-label="Marcar {{ $falta['grupo']->nombre_con_nivel }}, {{ $falta['fecha']->format('d/m/Y') }}">
