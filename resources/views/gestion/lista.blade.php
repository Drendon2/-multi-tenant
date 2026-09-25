@extends('layouts.app')

@section('title', $titulo)

@section('content')
{{--
  La pantalla de listado que comparten los cuatro catálogos. Recibe:

    $objetos ............ [{objeto, hijos, protegido}]
    $ruta_nuevo/editar/eliminar
    $ruta_fila .......... a dónde lleva el nombre (el nivel de abajo), si aplica
    $etiqueta_singular/plural ... qué se cuenta al lado del nombre
    $etiqueta_protegido . qué impide borrarlo
    $preset_campo/valor . para que "+ Nuevo" llegue con el padre ya elegido
    $migas .............. la ruta de vuelta cuando se entra por la jerarquía
    $filtros ............ opcional: [{nombre, etiqueta, vacio, opciones, valor}]
                          Solo Grupos los usa hoy. Los otros tres catálogos no
                          pasan nada y la barra no se pinta, que es por lo que va
                          aquí y no en una plantilla aparte: la tabla, el estado
                          vacío y el botón de nuevo son los mismos.
--}}
@php($migas = $migas ?? [])
{{-- El grupo dice que no: su horario es media pantalla. Ver `RecursoController::cabeEnModal()`. --}}
@php($abreEnModal = $modal ?? false)
@php($preset = isset($preset_campo) ? '?'.$preset_campo.'='.$preset_valor : '')

@if ($migas)
<p class="migas">
  @foreach ($migas as $m)<a href="{{ $m['url'] }}">{{ $m['texto'] }}</a><span class="migas-sep">/</span>@endforeach<span class="migas-actual">{{ $titulo }}</span>
</p>
@else
<a href="{{ route('gestion-inicio') }}" class="volver">&larr; Gestión</a>
@endif
<h2>{{ $titulo }}</h2>
{{-- El espacio antes de la directiva no sobra: pegada a una letra, Blade lee
     «algo@if» como un correo y se come el @endif que le toca. --}}
{{--
  `crear_solo_admin` lo declaran los catalogos cuya creacion paso a ser del
  administrador el 12/09/2026 —departamentos, periodos y promotorias—. El boton
  se esconde Y la ruta esta cerrada: esconder el enlace no cierra la puerta, y
  dejarlo pintado sobre una ruta cerrada es un boton que no hace nada.

  Es una bandera aparte de `solo_admin` a proposito: un director NO crea una
  promotoria pero SI edita las suyas, asi que la que apaga el boton de crear no
  puede ser la misma que apaga el menu de fila.
--}}
@if (! ($crear_solo_admin ?? false) || $yo->rol === 'administrador')
<p><a class="btn" href="{{ route($ruta_nuevo).$preset }}" @if ($abreEnModal) data-modal @endif>+ Nuevo</a></p>
@endif
{{--
  Enlaces propios de UN listado, opcionales: `$enlaces_extra` = [{texto, url}].
  Hoy solo los usa la lista de grupos de una promotoría, para imprimir sus
  carnés; quien los pasa decide también quién los ve.
--}}
@if ($enlaces_extra ?? [])
<p>
  @foreach ($enlaces_extra as $enlace)
  <a class="btn btn-secundario btn-sm" href="{{ $enlace['url'] }}">{{ $enlace['texto'] }}</a>
  @endforeach
</p>
@endif

@php($losFiltros = $filtros ?? [])
@php($hayFiltros = $hay_filtros ?? false)

@if ($losFiltros)
{{--
  Plegada. Tres desplegables a 44px son 297px antes de que empiece la tabla, y
  esta pantalla se usa desde el teléfono. El resumen dice cuántos hay puestos
  para que plegado no acabe significando escondido. Ver `.filtros-plegables`.
--}}
@php($cuantosFiltros = collect($losFiltros)->filter(fn ($f) => (string) $f['valor'] !== '')->count())
<form method="get">
<details class="filtros-plegables">
  <summary class="filtros-resumen">
    Filtros
    @if ($cuantosFiltros)
      <span class="filtros-cuenta">{{ $cuantosFiltros }} {{ $cuantosFiltros == 1 ? 'puesto' : 'puestos' }}</span>
    @endif
    <svg class="perfil-seccion-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
  </summary>
  <div class="filtros">
  @foreach ($losFiltros as $f)
  <div class="filtro">
    <label for="f-{{ $f['nombre'] }}">{{ $f['etiqueta'] }}</label>
    <select name="{{ $f['nombre'] }}" id="f-{{ $f['nombre'] }}">
      <option value="">{{ $f['vacio'] }}</option>
      @if (! empty($f['agrupadas']))
        {{-- La jerarquía del catálogo se ve en el propio desplegable, sin tener
             que elegir antes el departamento y recargar. --}}
        @foreach ($f['opciones'] as $grupo => $opciones)
          <optgroup label="{{ $grupo }}">
            @foreach ($opciones as $valor => $etiqueta)
              <option value="{{ $valor }}" @selected((string) $f['valor'] === (string) $valor)>{{ $etiqueta }}</option>
            @endforeach
          </optgroup>
        @endforeach
      @else
        @foreach ($f['opciones'] as $valor => $etiqueta)
          <option value="{{ $valor }}" @selected((string) $f['valor'] === (string) $valor)>{{ $etiqueta }}</option>
        @endforeach
      @endif
    </select>
  </div>
  @endforeach

  </div>
</details>

  {{--
    Los dos botones FUERA del pliegue: «Limpiar» es la salida de un filtro
    puesto, y esconderla detrás del mismo panel que hay que abrir para quitarlo
    sería dejar la salida dentro de la trampa.
  --}}
  <div class="filtro filtro-acciones filtros-botones">
    <button type="submit" class="btn btn-sm">Filtrar</button>
    @if ($hayFiltros)
      <a class="btn btn-blanco btn-sm" href="{{ route($ruta_lista) }}">Limpiar</a>
    @endif
  </div>
</form>

@if ($hayFiltros && isset($nota_filtros))
  <p class="filtros-nota">{{ $nota_filtros }}</p>
@endif
@endif

@include('partials.tabla-catalogo')
@endsection
