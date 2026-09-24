@extends('layouts.app')

@section('title', $titulo)

@section('content')
<a href="{{ route($ruta_lista) }}" class="volver">&larr; Volver al listado</a>

{{--
  El formulario que comparten los cuatro catálogos. Los campos llegan
  declarados desde el controlador (ver `RecursoController::campos()`), que es lo
  que permite que una sola plantilla sirva para departamentos, periodos,
  promotorías y grupos sin preguntar de cuál se trata.

  `data-modal-cuerpo` es lo que el modal se lleva dentro cuando se abre desde el
  listado, y por eso el título vive AQUÍ y ya no encima: suelto quedaría flotando
  sobre el fondo oscurecido, fuera de la tarjeta. Sigue siendo una página de
  verdad con su URL — sin JavaScript se abre y se rellena igual —, y es la misma
  tarjeta en los dos casos. El enlace de «Volver al listado» se queda fuera a
  propósito: dentro del modal el que cierra es «Cancelar».
--}}
<form method="post" action="{{ $accion }}" class="form-card" @if ($modal ?? false) data-modal-cuerpo @endif>
  @csrf
  <input type="hidden" name="volver" value="{{ $volver ?? '' }}">
  <h2 style="margin-top:0;">{{ $titulo }}</h2>

  @foreach ($campos as $campo => $spec)
  {{--
    El horario del grupo no es una columna sino filas aparte, así que trae su
    propio parcial —el mismo que usa el Panel— en vez de pasar por los tipos de
    campo de aquí abajo.
  --}}
  @if ($spec['tipo'] === 'sesiones')
    @include('partials.sesiones-form', [
      'sesiones' => \App\Support\HorarioDeGrupo::paraElFormulario($objeto),
    ])
    @continue
  @endif

  @php($valor = old($campo, $spec['valor'] ?? $objeto->{$campo}))
  {{--
    Casi todos los campos son obligatorios, así que lo obligatorio es el
    defecto y lo que se declara es la excepción. Un campo opcional lo es de
    verdad: el cupo de una actividad en blanco significa «sin tope», y esa
    ausencia tiene que poder escribirse.

    Se precalcula con la directiva PHP en línea, como el resto del archivo.
  --}}
  @php($obligatorio = ! ($spec['opcional'] ?? false))
  <div class="field">
    <label for="{{ $campo }}">{{ $spec['etiqueta'] }}</label>

    @if ($spec['tipo'] === 'select')
      <select name="{{ $campo }}" id="{{ $campo }}" @required($obligatorio && ! isset($spec['vacio']))>
        @isset($spec['vacio'])
          <option value="">{{ $spec['vacio'] }}</option>
        @endisset
        @foreach ($spec['opciones'] as $clave => $etiqueta)
          <option value="{{ $clave }}" @selected((string) $valor === (string) $clave)>{{ $etiqueta }}</option>
        @endforeach
      </select>
    @elseif ($spec['tipo'] === 'date')
      {{-- El <input type="date"> del navegador solo entiende aaaa-mm-dd. --}}
      <input type="date" name="{{ $campo }}" id="{{ $campo }}" @required($obligatorio)
             value="{{ $valor instanceof \Illuminate\Support\Carbon ? $valor->toDateString() : $valor }}">
    @elseif ($spec['tipo'] === 'number')
      <input type="number" name="{{ $campo }}" id="{{ $campo }}" @required($obligatorio)
             min="{{ $spec['min'] ?? 0 }}" step="1" value="{{ $valor }}">
    @elseif ($spec['tipo'] === 'password')
      {{--
        NUNCA SE DEVUELVE EL VALOR, ni con `old()`. Los demás campos vuelven
        rellenos cuando el formulario rebota, y aquí eso significaría escribir
        una contraseña en el HTML de una página — que se queda en la caché del
        aparato y en el historial. Se vuelve a teclear, que es lo que hace
        cualquier formulario de este tipo.

        `autocomplete="new-password"` y no `current-password`: esto crea o
        cambia una clave ajena, así que el gestor del navegador no tiene que
        ofrecer la del administrador que está escribiendo.

        EL OJO PARA VERLA NO SE PINTA AQUÍ: lo crea `ver-clave.js`, que carga en
        `layouts.app` y alcanza a todo `input[type=password]` se llame como se
        llame. Ponerlo en la plantilla sería un botón que no hace nada para
        quien no tenga JavaScript, y ese error este proyecto ya lo pagó.
      --}}
      <input type="password" name="{{ $campo }}" id="{{ $campo }}" @required($obligatorio)
             autocomplete="new-password">
    @else
      <input type="text" name="{{ $campo }}" id="{{ $campo }}" @required($obligatorio)
             maxlength="{{ $spec['max'] ?? 255 }}" value="{{ $valor }}">
    @endif

    @isset($spec['ayuda'])
      <span class="campo-info" style="margin:0;">{{ $spec['ayuda'] }}</span>
    @endisset

    @error($campo)<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
  </div>
  @endforeach

  {{--
    «Cancelar» no existía y hace falta en los dos sitios: dentro del modal es lo
    que lo cierra, y en la página suelta es la salida que no obliga a buscar el
    «Volver» de arriba del todo.
  --}}
  <div class="modal-botones">
    <button type="submit" class="btn">Guardar</button>
    <a href="{{ route($ruta_lista) }}" class="btn btn-secundario" data-modal-cerrar>Cancelar</a>
  </div>
</form>
@endsection
