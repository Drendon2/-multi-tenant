{{--
  EL ENLACE DE INSCRIPCIÓN DE UNA PROMOTORÍA Y SU QR (29/09/2026).

  Lo ven y lo encienden quienes gestionan la promotoría —administración, la
  dirección de su departamento y su profesor—. Decisión del usuario: existe
  para que el profesor registre gente nueva cuando quiera.

  La imagen va INCRUSTADA como datos y no como una segunda petición, igual que
  el QR de una institución: en este hosting cada petición cuesta ~1,5 s.
--}}
@extends('layouts.app')

@section('title', 'Enlace de '.$promotoria->nombre)

@section('content')
@php($enlace = $promotoria->enlace())
@php($abierto = $promotoria->enlace_abierto)

<p class="migas">
  <a href="{{ route('panel') }}">Panel</a><span class="migas-sep">/</span>
  <span class="migas-actual">Enlace de {{ $promotoria->nombre }}</span>
</p>

<h2>Enlace de inscripción de {{ $promotoria->nombre }}</h2>

<p class="campo-ayuda">
  Sirve para que alguien se matricule <strong>solo en esta promotoría</strong>, también con las
  matrículas cerradas. Quien no tiene cuenta la crea ahí mismo; quien ya es estudiante entra y
  pulsa «Matricularme». La matrícula queda pendiente, como todas, y cuenta contra el cupo.
</p>

<div class="card">
  <h3>Estado</h3>
  @if ($abierto)
    <p style="margin-top:0;"><span class="estado estado-activa">Encendido</span>
      Recibe inscripciones{{ $periodo && ! $periodo->matriculas_abiertas ? ', aunque las matrículas estén cerradas' : '' }}.
    </p>
  @else
    <p style="margin-top:0;"><span class="estado estado-pendiente">Apagado</span>
      Quien lo abra verá que no recibe inscripciones.
    </p>
  @endif
  @if (! $periodo)
    <p class="aviso">No hay un periodo en curso: aunque esté encendido, nadie puede matricularse.</p>
  @endif

  @if ($puedeAbrir)
    <form method="post" action="{{ route('panel-enlace-promotoria-abrir', $promotoria) }}">
      @csrf
      <input type="hidden" name="abierto" value="{{ $abierto ? '0' : '1' }}">
      <button type="submit" class="btn {{ $abierto ? 'btn-secundario' : '' }}">
        {{ $abierto ? 'Apagar el enlace' : 'Encender el enlace' }}
      </button>
    </form>
  @endif
</div>

@if ($enlace)
  <div class="card">
    <h3>El enlace</h3>
    <label class="sr-solo" for="enlace">Enlace de {{ $promotoria->nombre }}</label>
    {{-- El botón de copiar lo añade `copiar-enlace.js`: sin JavaScript no serviría. --}}
    <div class="enlace-fila">
      <input class="enlace-copiable" type="text" id="enlace" readonly value="{{ $enlace }}">
    </div>
  </div>

  <div class="card carne-qr">
    <img class="carne-qr-imagen"
         src="data:image/png;base64,{{ base64_encode(\App\Http\Controllers\EnlacePromotoriaController::cartel($promotoria)) }}"
         alt="Código QR para matricularse en {{ $promotoria->nombre }}">
    <div class="carne-qr-acciones">
      <a class="btn" href="{{ route('panel-enlace-promotoria-qr', $promotoria) }}" download>Descargar QR</a>
    </div>
    <p class="carne-qr-comollega">
      Es una imagen: sirve para publicarla en redes y para imprimirla y pegarla. Quien la
      escanee con la cámara del celular llega directo a la inscripción.
    </p>
  </div>

  @if ($puedeAbrir)
    <details class="carne-qr-renovar">
      <summary>El QR se publicó donde no debía<svg aria-hidden="true" class="carne-qr-renovar-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg></summary>
      <p class="campo-ayuda">
        Cambiar el enlace <strong>anula el anterior en el acto</strong>: el QR viejo y el enlace que
        ande por ahí dejan de servir. Después hay que volver a compartirlo.
      </p>
      <form method="post" action="{{ route('panel-enlace-promotoria-renovar', $promotoria) }}">
        @csrf
        <button type="submit" class="btn btn-secundario btn-sm">Cambiar el enlace</button>
      </form>
    </details>
  @endif
@else
  <p class="campo-ayuda">Todavía no se ha encendido nunca, así que no tiene enlace ni QR.</p>
@endif

<p style="margin-top:1.5rem;">
  <a class="volver" href="{{ route('panel') }}">← Panel</a>
</p>
@endsection

@push('scripts')
<script src="@recurso('js/copiar-enlace.js')" defer></script>
@endpush
