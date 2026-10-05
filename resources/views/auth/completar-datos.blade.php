@extends('layouts.publico')

@section('title', 'Completa tus datos — ' . $configuracion->nombre_institucion)
@section('ancho', '460px')

{{--
  La pantalla que no deja seguir al profesor o director sin documento o sin
  correo. La pinta `CompletarDatosController` y a ella manda el middleware
  `DatosDelPersonal`. Es una página entera y no un diálogo de JavaScript: una
  barrera que vive en el navegador se salta quitando el guion.
--}}
@section('caja')
  <h1>Completa tus datos</h1>
  <p class="info">
    Hola, {{ $perfil->nombre_completo }}. Para seguir usando el sistema, la
    institución necesita tu número de documento y tu correo electrónico.
  </p>

  <form method="post" action="{{ route('completar-datos.guardar') }}">
    @csrf

    <label for="documento_identidad">Número de documento</label>
    <input type="text" name="documento_identidad" id="documento_identidad"
           value="{{ old('documento_identidad', $perfil->documento_identidad) }}"
           maxlength="12" inputmode="numeric" pattern="[0-9]{6,12}" title="Solo números, entre 6 y 12 dígitos, sin puntos ni espacios" required>
    @error('documento_identidad')<ul class="errorlist"><li>{{ $message }}</li></ul>@enderror

    <label for="correo">Correo electrónico</label>
    <input type="email" name="correo" id="correo" value="{{ old('correo', $perfil->user->email) }}"
           maxlength="255" autocomplete="email"
           pattern="[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}" title="Un correo completo, con arroba y dominio. Ejemplo: nombre@correo.com" required>
    @error('correo')<ul class="errorlist"><li>{{ $message }}</li></ul>@enderror

    <p class="campo-info">
      Los dos se pueden corregir después desde «Mi perfil».
    </p>

    <button type="submit">Guardar y continuar</button>
  </form>

  <form method="post" action="{{ route('logout') }}" class="enlace-pie">
    @csrf
    <button type="submit" class="boton-enlace">Cerrar sesión</button>
  </form>
@endsection
