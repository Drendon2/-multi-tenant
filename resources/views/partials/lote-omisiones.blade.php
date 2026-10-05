{{--
  La barra para clasificar VARIAS clases no dictadas de una vez (03/10/2026,
  pedido del usuario: en producción la bandeja puede traer cientos, y las
  archivadas antes de las causas quedan «Sin clasificar»).

  Es la misma forma que el lote de cancelaciones —`lote.js` cuenta, enciende los
  botones y maneja la casilla de «todas»— y los mismos tres botones que cada
  fila, en el mismo orden. Recibe `$loteId`, porque hay dos tablas en la página
  (las pendientes y las ya atendidas) y cada una alimenta su propio formulario.
--}}
<form action="{{ route('gestion-clasificar-omisiones-lote') }}" method="post" id="{{ $loteId }}" class="lote-barra">
  @csrf
  @include('partials.lote-marcar-todas', ['loteId' => $loteId])
  <span class="lote-cuenta" data-lote-cuenta>Ninguno marcado</span>
  @foreach ($causas as $valor => $rotulo)
    <button type="submit" name="causa" value="{{ $valor }}" class="btn btn-blanco btn-sm" data-lote-enviar disabled>
      {{ $rotulo }}
    </button>
  @endforeach
</form>
