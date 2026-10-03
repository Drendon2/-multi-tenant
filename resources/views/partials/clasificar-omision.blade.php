{{--
  Los tres botones con los que se atiende una clase no dictada: por qué no se
  dio. Un formulario con tres `submit` y no un desplegable con su «Guardar»: es
  un toque y no dos, y desde el celular —donde vive casi todo el uso— el
  desplegable es el control más incómodo de la pantalla.

  Sirve igual para la primera vez y para CORREGIR una ya atendida: el
  controlador hace `updateOrCreate`. La causa que ya tiene se pinta apagada
  para que se vea cuál es y no se pulse en vano.

  Cada botón nombra su fila (ver DESIGN.md, «El nombre de un control repetido
  lleva su fila»): la palabra visible va entera y de primera.
--}}
<form method="post" action="{{ route('gestion-clasificar-omision') }}" class="clasificar-omision">
  @csrf
  <input type="hidden" name="grupo_id" value="{{ $falta['grupo']->id }}">
  <input type="hidden" name="fecha" value="{{ $falta['fecha']->toDateString() }}">
  @foreach ($causas as $valor => $rotulo)
    <button type="submit" name="causa" value="{{ $valor }}"
            class="btn btn-blanco btn-sm"
            aria-label="{{ $rotulo }}: {{ $falta['grupo']->nombre_con_nivel }}, {{ $falta['fecha']->format('d/m/Y') }}"
            @disabled($falta['causa'] === $valor)>{{ $rotulo }}</button>
  @endforeach
</form>
