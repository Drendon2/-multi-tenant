@extends('layouts.operador')

@section('title', 'Entrar — Panel de instituciones')

@section('content')
  <div class="card" style="max-width: 360px; margin: 2rem auto;">
    <h2>Entrar</h2>
    <p class="campo-ayuda">Solo para quien presta el servicio. Las cuentas de una institución entran por la dirección de su institución.</p>

    <form method="post" action="{{ route('operador.entrar.enviar') }}">
      @csrf

      <label for="usuario">Usuario</label>
      <input type="text" name="usuario" id="usuario" value="{{ old('usuario') }}"
             autocomplete="username" autofocus required>
      @error('usuario')
        <ul class="errorlist"><li>{{ $message }}</li></ul>
      @enderror

      <label for="password">Contraseña</label>
      <input type="password" name="password" id="password" autocomplete="current-password" required>
      @error('password')
        <ul class="errorlist"><li>{{ $message }}</li></ul>
      @enderror

      <button type="submit" class="btn">Entrar</button>
    </form>
  </div>
@endsection
