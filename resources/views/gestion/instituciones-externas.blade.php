@extends('layouts.app')

@section('title', $titulo)

@section('content')
{{--
  La pantalla suelta de las instituciones externas.

  Vive en su URL como las de los demás catálogos, y como ellas ya no la enlaza
  la portada: a esto se llega desde «Programas formativos», que es donde la
  lista significa algo —al lado de los programas que estas instituciones
  reciben—. Se conserva porque una URL que alguien guardó tiene que seguir
  abriendo algo, y porque la misma tabla se pinta en los dos sitios sin
  copiarse.
--}}
<a href="{{ route('gestion-programas') }}" class="volver">&larr; Programas formativos</a>
<h2>{{ $titulo }}</h2>

<p class="campo-ayuda">
  Entidades ajenas a la casa —escuelas, colegios, fundaciones— donde un profesor
  va a dictar. Cada una tiene la cuenta de quien verifica allá que la clase se
  dio.
</p>

<p><a class="btn" href="{{ route($ruta_nuevo) }}" @if ($modal ?? false) data-modal @endif>+ Nueva</a></p>

@include('partials.tabla-instituciones-externas')
@endsection
