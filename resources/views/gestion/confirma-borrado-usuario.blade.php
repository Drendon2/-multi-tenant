@extends('layouts.app')

@section('title', 'Eliminar cuenta')

@section('content')
{{--
  `data-modal-cuerpo` marca lo que el modal se lleva dentro. La página sigue
  existiendo entera y con su URL: sin JavaScript se abre y se lee igual, y es la
  MISMA tarjeta en los dos casos, así que no hay dos versiones que se puedan
  desincronizar. Ver el modal en `acciones.js`.
--}}
<div class="card" data-modal-cuerpo style="max-width:480px;">
@if ($impedimento)
  {{--
    Misma regla que en la confirmacion de los catalogos: preguntar «¿seguro?»
    para negarse despues es hacer perder el viaje, y aqui la respuesta ya se
    sabe. Asi que no hay pregunta ni campo de contraseña, solo el porque y la
    salida.
  --}}
  <h2>No se puede eliminar esta cuenta</h2>
  <p>{{ $impedimento }}</p>
  <p class="campo-ayuda" style="margin-bottom:1.3rem;">
    Desactivarla le cierra la puerta. Si la persona pidió que se borren sus
    datos, primero hay que pasar a otra persona lo que tiene a su cargo.
  </p>
  {{-- En `.modal-botones` aunque sea una sola: es lo que la pone a ancho
       completo en el teléfono, igual que el par de la otra rama. --}}
  <div class="modal-botones">
    <a href="{{ route('usuario-lista') }}" class="btn btn-secundario" data-modal-cerrar>Volver</a>
  </div>
@elseif ($matriculas > 0)
  {{--
    Con matriculas NO se borra: se anonimiza (Ley 1581, decision del usuario
    del 30/09/2026, ver `SupresionDeDatos`). Tiene rama propia porque lo que
    pasa es distinto y quien pulsa tiene que saberlo ANTES: la fila se queda
    y las cifras no cambian.
  --}}
  <h2>¿Suprimir los datos de «{{ $usuario->nombre_completo }}»?</h2>

  <p class="campo-info" style="margin-top:-0.6rem;">
    Usuario <strong>{{ $usuario->user->username }}</strong> · {{ $usuario->rol_display }}
  </p>

  <p class="campo-ayuda">
    Tiene {{ $matriculas }} {{ $matriculas === 1 ? 'matrícula' : 'matrículas' }}, así que la
    cuenta no se borra: se <strong>anonimiza</strong>. Se borran su nombre, documento,
    teléfono, correo, fecha de nacimiento, foto, papeles, encuestas y acudiente, y la
    cuenta deja de poder entrar.
  </p>
  <p class="campo-ayuda">
    Sus matrículas y asistencias se quedan como «{{ \App\Support\SupresionDeDatos::NOMBRE }}»,
    para que las cifras de los periodos pasados no cambien. Si está matriculada en el
    periodo en curso, se retira y su cupo queda libre.
  </p>
  <p class="campo-ayuda">
    <strong>No se puede deshacer.</strong> Úsalo solo cuando la persona haya pedido que
    se borren sus datos; para cerrarle el acceso basta con desactivarla.
  </p>

  <form method="post" action="{{ $accion }}">
    @csrf
    <input type="hidden" name="volver" value="{{ $volver }}">

    <label for="password">Escribe tu contraseña para confirmar</label>
    <input type="password" name="password" id="password"
           autocomplete="current-password" required autofocus>
    @error('password')
      <ul class="errorlist"><li>{{ $message }}</li></ul>
    @enderror

    <div class="modal-botones" style="margin-top:0.9rem;">
      <button type="submit" class="btn btn-retirar">Sí, suprimir sus datos</button>
      <a href="{{ route('usuario-lista') }}" class="btn btn-secundario" data-modal-cerrar>Cancelar</a>
    </div>
  </form>
@else
  <h2>¿Eliminar la cuenta de «{{ $usuario->nombre_completo }}»?</h2>

  {{--
    Quien es, dicho con los dos datos por los que se le distingue en la lista.
    Dos personas del mismo nombre son un caso corriente en una casa de la
    cultura, y el usuario es lo unico que no se repite.
  --}}
  <p class="campo-info" style="margin-top:-0.6rem;">
    Usuario <strong>{{ $usuario->user->username }}</strong> · {{ $usuario->rol_display }}
  </p>

  @if ($arrastre)
  <p class="campo-info">
    Se llevará también <strong>{{ $arrastre }}</strong>.
  </p>
  @endif

  <p class="campo-info">
    No se puede deshacer. Si solo quieres cerrarle el acceso,
    <strong>desactívala</strong> desde el listado en vez de eliminarla.
  </p>

  <form method="post" action="{{ $accion }}">
    @csrf
    <input type="hidden" name="volver" value="{{ $volver }}">

    {{--
      La contraseña de QUIEN BORRA, no la de la cuenta que se va. Una sesion
      abierta en un celular prestado basta para llegar hasta aqui, y este campo
      es lo que comprueba que quien pulsa es la persona y no el aparato.
    --}}
    <label for="password">Escribe tu contraseña para confirmar</label>
    <input type="password" name="password" id="password"
           autocomplete="current-password" required autofocus>
    @error('password')
      <ul class="errorlist"><li>{{ $message }}</li></ul>
    @enderror

    <div class="modal-botones" style="margin-top:0.9rem;">
      <button type="submit" class="btn btn-retirar">Sí, eliminar</button>
      <a href="{{ route('usuario-lista') }}" class="btn btn-secundario" data-modal-cerrar>Cancelar</a>
    </div>
  </form>
@endif
</div>
@endsection
