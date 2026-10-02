@extends('layouts.operador')

@section('title', $institucion->nombre . ' — Panel de instituciones')

@section('content')
  <p><a href="{{ route('operador.instituciones') }}">← Instituciones</a></p>
  <h2>{{ $institucion->nombre }}</h2>

  <form method="post" action="{{ route('operador.institucion.guardar', $institucion) }}" class="card">
    @csrf

    <label for="subdominio">Subdominio</label>
    <input type="text" name="subdominio" id="subdominio" maxlength="63"
           value="{{ old('subdominio', $institucion->subdominio) }}"
           autocomplete="off" autocapitalize="none" spellcheck="false">
    <p class="campo-ayuda">Se entra por <strong>&lt;subdominio&gt;.{{ $base }}</strong>. Solo minúsculas sin tildes, números y guiones.</p>
    @error('subdominio')
      <ul class="errorlist"><li>{{ $message }}</li></ul>
    @enderror

    <label for="dominio_propio">Dominio propio <span class="campo-ayuda">(opcional)</span></label>
    <input type="text" name="dominio_propio" id="dominio_propio" maxlength="253"
           value="{{ old('dominio_propio', $institucion->dominio_propio) }}"
           autocomplete="off" autocapitalize="none" spellcheck="false" inputmode="url">
    <p class="campo-ayuda">Para la entidad que trae el suyo, como <strong>matriculas.alcaldia.gov.co</strong>. Necesita su registro DNS y su certificado apuntando a este servidor.</p>
    @error('dominio_propio')
      <ul class="errorlist"><li>{{ $message }}</li></ul>
    @enderror

    <label for="estado">Estado</label>
    @php($estado = old('estado', $institucion->estado))
    <select name="estado" id="estado">
      <option value="{{ \App\Models\Institucion::ACTIVA }}" @selected($estado === \App\Models\Institucion::ACTIVA)>Activa</option>
      <option value="{{ \App\Models\Institucion::SUSPENDIDA }}" @selected($estado === \App\Models\Institucion::SUSPENDIDA)>Suspendida</option>
    </select>
    <p class="campo-ayuda">Suspendida, todas sus pantallas dicen «Servicio suspendido», también a quien ya había entrado. No se borra nada: reactivarla la devuelve tal cual.</p>
    @error('estado')
      <ul class="errorlist"><li>{{ $message }}</li></ul>
    @enderror

    <button type="submit" class="btn">Guardar</button>
  </form>
@endsection

@section('despues')
  <h3>Entrar como administrador</h3>
  <p class="campo-ayuda">
    Abre la institución con la cuenta de uno de sus administradores, para darle
    soporte. Una barra lo dice en todas las pantallas mientras dura, la
    contraseña de esa cuenta no se puede cambiar, y queda registrado quién
    entró y cuándo. Funciona aunque la institución esté suspendida.
  </p>

  @if (! $conDireccion)
    <p class="vacio">Esta institución no tiene dirección todavía: ponle un subdominio arriba.</p>
  @elseif ($administradores->isEmpty())
    <p class="vacio">No tiene ningún administrador activo.</p>
  @else
    <table class="tabla-personas tabla-catalogo">
      <thead>
        <tr><th>Administrador</th><th>Usuario</th><th></th></tr>
      </thead>
      <tbody>
        @foreach ($administradores as $administrador)
        <tr>
          <td data-celda="nombre">{{ $administrador->nombre_completo }}</td>
          <td data-label="Usuario">{{ $administrador->user->username }}</td>
          <td data-celda="accion">
            <form method="post" action="{{ route('operador.suplantar', [$institucion, $administrador->id]) }}">
              @csrf
              <button type="submit" class="btn btn-sm"
                      aria-label="Entrar como {{ $administrador->nombre_completo }}">Entrar como</button>
            </form>
          </td>
        </tr>
        @endforeach
      </tbody>
    </table>
  @endif
@endsection
