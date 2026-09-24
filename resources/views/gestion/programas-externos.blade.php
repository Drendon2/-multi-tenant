@extends('layouts.app')

@section('title', $titulo)

@section('content')
{{--
  La pantalla suelta de los programas externos.

  NO reusa `gestion.actividades`, que es la de cursos y proyección: aquella pinta
  la tabla del enlace y el cupo, y un programa externo no tiene ninguno de los
  dos. El porqué está escrito entero en `partials/tabla-programas-externos`.

  Como las demás pantallas planas, vive en su URL y no la enlaza la portada: a
  esto se llega desde «Programas formativos».
--}}
<a href="{{ route('gestion-programas') }}" class="volver">&larr; Programas formativos</a>
<h2>{{ $titulo }}</h2>

<p class="campo-ayuda">
  Lo que un profesor de la casa va a dictar en otra institución. La lista de
  asistentes la escribe él allá —nombre y edad— y cada clase la verifica un
  funcionario de esa institución.
</p>

{{--
  Mismo par de condiciones que en «Programas formativos»: solo el administrador
  crea, y solo si hay alguna institución registrada — sin ninguna, el
  desplegable obligatorio del formulario sale vacío y no se puede enviar.
--}}
@if ($yo->rol === 'administrador')
  @if ($hay_instituciones)
    <p><a class="btn" href="{{ route($ruta_nuevo) }}" @if ($modal ?? false) data-modal @endif>+ Nuevo</a></p>
  @else
    <p class="aviso">
      Antes de abrir un programa externo hay que registrar la institución donde
      se va a dictar: su funcionario es quien verifica cada clase.
      <a href="{{ route('institucion-externa-lista') }}">Registrar una institución</a>.
    </p>
  @endif
@endif

@include('partials.tabla-programas-externos')
@endsection
