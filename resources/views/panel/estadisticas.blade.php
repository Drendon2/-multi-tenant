@extends('layouts.app')

@section('title', 'Mis estadísticas')

@section('content')
{{--
  LAS ESTADÍSTICAS DEL PROFESOR (27/09/2026), desde su menú. Qué cuenta cada
  cifra —y qué deja fuera a propósito— está en `App\Support\EstadisticasDeProfesor`.

  Reutiliza piezas de Estadísticas del administrador y no inventa otras: la
  cinta de cifras, el paso entre periodos, las barras y las tortas con su rampa
  validada. La única pieza propia es la barra partida de «Clases por grupo»,
  porque ahí dos cantidades son partes de un mismo todo: las clases que tocaban.

  Solo cifras, nunca nombres de estudiantes.
--}}
<h2>Mis estadísticas</h2>

@if (! $datos)
  <p class="vacio">
    Todavía no hay matrículas ni clases en tus promotorías. Las cifras aparecen
    en cuanto registres la primera clase.
  </p>
@else
<p class="campo-ayuda">
  {{ count($datos['promotorias']) === 1 ? 'Tu promotoría' : 'Tus promotorías' }}:
  {{ implode(', ', $datos['promotorias']) }}.
</p>

@include('partials.periodo-nav', [
  'periodo' => $periodo,
  'enCurso' => $enCurso,
  'urlAtras' => $haciaAtras ? route('mis-estadisticas-periodo', $haciaAtras) : null,
  'urlAdelante' => $haciaAdelante ? route('mis-estadisticas-periodo', $haciaAdelante) : null,
])

@php($perdidas = $datos['perdidas'])
@php($ren = $datos['renovacion'])
<div class="cifras-banda" data-cifras-profesor>
  <div class="cifras-celda" data-cifra="dadas">
    <span class="cifras-num">{{ $datos['clasesDadas'] }}</span>
    <span class="cifras-label">{{ $datos['clasesDadas'] === 1 ? 'Clase dada' : 'Clases dadas' }}</span>
  </div>
  <div class="cifras-celda" data-cifra="perdidas">
    <span class="cifras-num">{{ $perdidas['total'] ?? '—' }}</span>
    <span class="cifras-label">{{ $perdidas['total'] === 1 ? 'Clase perdida' : 'Clases perdidas' }}</span>
  </div>
  <div class="cifras-celda" data-cifra="asistencia">
    <span class="cifras-num">{{ $datos['asistencia'] === null ? '—' : $datos['asistencia'].'%' }}</span>
    <span class="cifras-label">Asistencia</span>
  </div>
  <div class="cifras-celda" data-cifra="cancelaciones">
    <span class="cifras-num">{{ $datos['cancelaciones']['tramitadas'] }}</span>
    <span class="cifras-label">{{ $datos['cancelaciones']['tramitadas'] === 1 ? 'Cancelación' : 'Cancelaciones' }}</span>
  </div>
  <div class="cifras-celda" data-cifra="renovaron">
    <span class="cifras-num">{{ $ren['base'] ? $ren['renovaron'] : '—' }}</span>
    <span class="cifras-label">{{ $ren['base'] ? 'de '.$ren['base'].' renovaron' : 'Renovaron' }}</span>
  </div>
</div>

{{--
  LO QUE CADA CIFRA DEJA FUERA, dicho debajo y no escondido en un título: una
  cifra sin su regla se lee como otra cosa. Sobre todo las perdidas, que dependen
  de una fecha que el profesor no puso ni ve en ningún otro sitio.
--}}
<ul class="estadisticas-notas">
  <li>
    @if ($perdidas['motivo'] === 'apagadas')
      Las clases perdidas no se cuentan: la institución tiene apagada esa alerta.
    @elseif ($perdidas['motivo'] === 'fuera')
      Las clases perdidas empiezan a contar el {{ $perdidas['desde']->format('d/m/Y') }},
      cuando se encendieron las alertas, y este periodo terminó antes.
    @else
      Clases perdidas: días en que un grupo tenía horario y no se registró clase,
      contados desde el {{ $perdidas['desde']->format('d/m/Y') }}, cuando se
      encendieron las alertas.
      @if ($perdidas['archivadas'])
        {{ $perdidas['archivadas'] === $perdidas['total'] ? ($perdidas['total'] === 1 ? 'Está archivada' : 'Todas están archivadas') : $perdidas['archivadas'].' de ellas '.($perdidas['archivadas'] === 1 ? 'está archivada' : 'están archivadas') }}
        en la bandeja de alertas: ya se habló de ellas, pero cuentan porque la clase no se dio.
      @endif
    @endif
  </li>
  <li>
    Asistencia: de todas las marcas que pasaste, cuántas fueron «Asistió».
    Faltar con excusa cuenta como no asistir.
  </li>
  <li>
    Cancelaciones: las que pidió el estudiante y aprobó la dirección, de
    {{ $datos['cancelaciones']['matriculados'] }} {{ $datos['cancelaciones']['matriculados'] === 1 ? 'matriculado' : 'matriculados' }}.
    @if ($datos['cancelaciones']['enTramite'])
      {{ $datos['cancelaciones']['enTramite'] }} más {{ $datos['cancelaciones']['enTramite'] === 1 ? 'espera' : 'esperan' }} a que la dirección resuelva.
    @endif
  </li>
  <li>
    @if ($ren['anterior'])
      Renovaron: de quienes cursaron {{ $ren['anterior'] }} contigo, cuántos siguen en la misma promotoría.
    @else
      Renovaron: no hay un periodo anterior con el que comparar.
    @endif
  </li>
</ul>

@if ($datos['porGrupo'])
<h3 style="margin-top:2rem;">Clases por grupo</h3>
{{--
  UNA BARRA PARTIDA POR GRUPO: dadas y perdidas son partes de las clases que
  tocaban, así que van en la misma barra y con la misma escala para todos los
  grupos. Los colores, y por qué no son el acento y el rojo, en el CSS de
  `.clases-grupo-pista`. Con leyenda y cifra al lado: el color no carga solo.
--}}
<div class="card estadisticas-barras">
  <p class="clases-grupo-leyenda" aria-hidden="true">
    <span><span class="clases-grupo-punto clases-grupo-punto-dadas"></span>Dadas</span>
    <span><span class="clases-grupo-punto clases-grupo-punto-perdidas"></span>Perdidas</span>
  </p>
  @foreach ($datos['porGrupo'] as $g)
  <div class="stat-bar-fila" data-grupo-clases>
    <span class="stat-bar-etiqueta" title="{{ $g['etiqueta'] }}">{{ $g['etiqueta'] }}</span>
    <div class="stat-bar-pista clases-grupo-pista">
      @if ($g['dadas'])
      <div class="stat-bar-relleno" style="width: {{ number_format($g['dadas'] / $datos['maxClases'] * 100, 2, '.', '') }}%;"
           title="{{ $g['dadas'] }} {{ $g['dadas'] === 1 ? 'clase dada' : 'clases dadas' }}"></div>
      @endif
      @if ($g['perdidas'])
      <div class="stat-bar-relleno clases-grupo-perdidas" style="width: {{ number_format($g['perdidas'] / $datos['maxClases'] * 100, 2, '.', '') }}%;"
           title="{{ $g['perdidas'] }} {{ $g['perdidas'] === 1 ? 'clase perdida' : 'clases perdidas' }}"></div>
      @endif
    </div>
    <span class="stat-bar-num clases-grupo-num">
      {{ $g['dadas'] }}@if ($perdidas['total'] !== null)<span class="clases-grupo-sep">·</span>{{ $g['perdidas'] }}@endif
    </span>
  </div>
  @endforeach
</div>
@endif

<div class="dash-grid-2" style="margin-top:2rem;">
  <div>
    <h3>Asistencia</h3>
    @if ($datos['tortaAsistencia']['total'])
      @include('gestion.torta', ['torta' => $datos['tortaAsistencia']])
    @else
      <p class="vacio">Todavía no hay listas pasadas en este periodo.</p>
    @endif
  </div>
  <div>
    <h3>Renovación</h3>
    @if ($ren['torta'])
      @include('gestion.torta', ['torta' => $ren['torta']])
    @elseif ($ren['anterior'])
      <p class="vacio">Nadie cursó {{ $ren['anterior'] }} en tus promotorías.</p>
    @else
      <p class="vacio">No hay un periodo anterior con el que comparar.</p>
    @endif
  </div>
</div>

@php($conAsistencia = array_values(array_filter($datos['porGrupo'], fn ($g) => $g['asistencia'] !== null)))
@if ($conAsistencia)
<h3 style="margin-top:2rem;">Asistencia por grupo</h3>
<div class="card estadisticas-barras">
  @include('gestion.barras', ['filas' => array_map(
    fn ($g) => ['etiqueta' => $g['etiqueta'], 'total' => $g['asistencia'].'%', 'porcentaje' => $g['asistencia']],
    $conAsistencia
  )])
</div>
@endif
@endif
@endsection
