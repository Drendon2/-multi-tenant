{{--
  Los tres botones de una semana sin clase de un programa externo. La misma
  forma que `clasificar-omision` (tres `submit`, la causa puesta apagada) con
  otro destino: la semana se guarda por su lunes y no lleva reposición.
--}}
<form method="post" action="{{ route('gestion-clasificar-semana-externa') }}" class="clasificar-omision">
  @csrf
  <input type="hidden" name="actividad_id" value="{{ $semana['actividad']->id }}">
  <input type="hidden" name="semana" value="{{ $semana['semana']->toDateString() }}">
  @foreach ($causas as $valor => $rotulo)
    <button type="submit" name="causa" value="{{ $valor }}" class="btn btn-blanco btn-sm"
            aria-label="{{ $rotulo }}: {{ $semana['actividad']->nombre }}, semana del {{ $semana['semana']->format('d/m/Y') }}"
            @disabled($semana['causa'] === $valor)>{{ $rotulo }}</button>
  @endforeach
</form>
