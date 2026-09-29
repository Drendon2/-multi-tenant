@extends('layouts.publico')

@section('title', $promotoria->nombre.' — '.$configuracion->nombre_institucion)
@section('ancho', '440px')

@section('caja')
  {{--
    Lo que abre el enlace de una promotoría cuando NO es la inscripción de
    alguien sin cuenta (esa es `auth.inscripcion` con la promotoría fija).

    Tres casos: el enlace está apagado, quien llega ya es estudiante, o quien
    llega es del personal comprobando que el enlace funciona. Se dice el nombre
    y el motivo, no un 404: quien llega lo recibió de alguien, o lo leyó de un
    cartel, y necesita saber si llegó tarde o si se equivocó de sitio.
  --}}
  <h1>{{ $promotoria->nombre }}</h1>
  <p class="info">{{ $promotoria->area->nombre }} · {{ $configuracion->nombre_institucion }}</p>

  @if ($estado === 'cerrado')
    <p class="aviso">
      <strong>Este enlace no está recibiendo inscripciones ahora.</strong>
      Acércate a {{ $configuracion->nombre_institucion }} si quieres entrar a esta promotoría.
    </p>
  @elseif ($estado === 'estudiante')
    @if ($yaEsta)
      <p class="aviso">Ya tienes una matrícula en esta promotoría este periodo.</p>
      <p class="enlace-pie"><a href="{{ route('mis-matriculas') }}">Ver mis matrículas</a></p>
    @else
      <p class="info">
        Tu inscripción queda pendiente hasta que el profesor la confirme.
      </p>
      <form method="post" action="{{ route('promotoria-enlace.matricularme', $promotoria->enlace_token) }}">
        @csrf
        <button type="submit">Matricularme en {{ $promotoria->nombre }}</button>
      </form>
    @endif
  @else
    <p class="aviso">
      Este enlace es para que los <strong>estudiantes</strong> se matriculen.
      Con tu cuenta no hay nada que hacer aquí; si quieres probarlo, ábrelo en una ventana
      privada.
    </p>
    @if ($puedeVerEnlace)
      <p class="enlace-pie"><a href="{{ route('panel-enlace-promotoria', $promotoria) }}">Ver el enlace y su QR</a></p>
    @endif
  @endif
@endsection
