@extends('layouts.app')

@section('title', 'Horarios')

@section('content')
{{--
  HORARIOS (27/09/2026). Un día a la vez y un filtro por promotoría: la forma
  la decidió el usuario al pedirla, y la semana entera de toda la casa quedó
  descartada de entrada. El porqué, en `App\Support\HorarioDeLaCasa`.

  La promotoría es el ENCABEZADO de su bloque y no una columna (pedido del
  usuario mientras se construía): repetida en cada fila era lo que más pesaba y
  lo que menos informaba. El profesor va en ese mismo encabezado porque también
  es de la promotoría, no del grupo.

  LLEGA LA SEMANA ENTERA y se esconde lo que no toca, con el atributo `hidden`
  puesto desde aquí. Así la pantalla sirve igual sin JavaScript —las pestañas
  son enlaces y el filtro un formulario GET— y con él `horarios.js` solo mueve
  ese atributo, sin pedir nada al servidor.
--}}
@php($diaNombre = \App\Models\SesionGrupo::DIAS[$dia])
@php($visiblesHoy = collect($bloques)->filter(fn ($b) => (! $promotoria || $b['id'] === $promotoria) && collect($b['filas'])->contains('dia', $dia)))
@php($crucesHoy = $visiblesHoy->sum(fn ($b) => collect($b['filas'])->where('dia', $dia)->filter(fn ($f) => $f['cruces'])->count()))
<a href="{{ route('gestion-inicio') }}" class="volver">&larr; Gestión</a>
<h2>Horarios</h2>
<p class="campo-ayuda horarios-intro">
  Qué se dicta cada día, promotoría por promotoría. Sale del horario que tiene
  puesto cada grupo.
</p>

@if (! $bloques && ! $sinHorario)
  <p class="vacio">
    Todavía no hay grupos. Se crean dentro de cada promotoría, en
    <a href="{{ route('gestion-programas') }}">Programas formativos</a>.
  </p>
@else
<div class="horarios" data-horarios data-dia="{{ $dia }}" data-promotoria="{{ $promotoria ?: '' }}">
  {{--
    EL FILTRO VA ANTES QUE LOS DÍAS en el documento: en el teléfono solo la
    fila de días se queda pegada arriba al bajar, y el desplegable se va con el
    scroll —fijos los dos eran 150px de pantalla quieta—. En escritorio la
    rejilla los vuelve a poner días a la izquierda y filtro a la derecha.
  --}}
  <div class="horarios-controles">
    <form method="get" action="{{ route('gestion-horarios') }}" class="horarios-filtro" data-horarios-filtro>
      <input type="hidden" name="dia" value="{{ $dia }}">
      <label for="horarios-promotoria">Promotoría</label>
      <div class="horarios-filtro-fila">
        <select id="horarios-promotoria" name="promotoria">
          <option value="">Todas las promotorías</option>
          @foreach ($promotorias as $area => $deArea)
            <optgroup label="{{ $area }}">
              @foreach ($deArea as $id => $nombre)
                <option value="{{ $id }}" @selected($id === $promotoria)>{{ $nombre }}</option>
              @endforeach
            </optgroup>
          @endforeach
        </select>
        {{-- Con JavaScript el desplegable filtra al cambiar y este botón se esconde. --}}
        <button type="submit" class="btn btn-blanco" data-horarios-ver>Ver</button>
      </div>
    </form>

    {{--
      LOS DÍAS SON ENLACES, no botones: sin JavaScript llevan a su día, y con él
      `horarios.js` los intercepta. La cifra de cada uno cuenta las clases de
      ese día con el filtro puesto, así que dice sin probarlos qué días tiene
      clase una promotoría. El día que se mira lleva `aria-current`, y el de
      hoy lo dice con una palabra y no con un color.
    --}}
    <nav class="horarios-dias" aria-label="Día de la semana">
      @foreach (\App\Models\SesionGrupo::DIAS as $n => $nombre)
        <a class="horarios-dia{{ $n === $dia ? ' horarios-dia-actual' : '' }}{{ $cuentas[$n] ? '' : ' horarios-dia-vacio' }}"
           href="{{ route('gestion-horarios', array_filter(['dia' => $n, 'promotoria' => $promotoria ?: null])) }}"
           data-dia="{{ $n }}" @if ($n === $dia) aria-current="true" @endif>
          <span class="horarios-dia-nombre" aria-hidden="true">{{ \App\Models\SesionGrupo::DIAS_CORTOS[$n] }}</span>
          <span class="sr-solo">{{ $nombre }}</span>
          <span class="horarios-dia-cuenta" data-cuenta>{{ $cuentas[$n] }}</span>
          @if ($n === $hoy)<span class="horarios-dia-hoy">hoy</span>@endif
        </a>
      @endforeach
    </nav>
  </div>

  {{--
    EL RESUMEN DEL DÍA. Va con `aria-live` porque es lo único que le cuenta a
    quien no ve la pantalla que el filtro cambió lo de abajo: al tocar un día no
    se navega y nada más lo anunciaría. No está dentro de lo que se esconde, así
    que no se lo lleva ningún repintado.
  --}}
  <p class="horarios-resumen" data-horarios-resumen aria-live="polite">
    @if ($visiblesHoy->isNotEmpty())
      <strong>{{ $diaNombre }}</strong>:
      {{ $cuentas[$dia] }} {{ $cuentas[$dia] === 1 ? 'clase' : 'clases' }}
      @if (! $promotoria)
        en {{ $visiblesHoy->count() }} {{ $visiblesHoy->count() === 1 ? 'promotoría' : 'promotorías' }}
      @endif
      @if ($crucesHoy)
        · <span class="horarios-resumen-cruce">{{ $crucesHoy }} {{ $crucesHoy === 1 ? 'clase' : 'clases' }} con cruce de salón</span>
      @endif
    @endif
  </p>

  {{--
    LA LISTA VACÍA dice por qué, que no es lo mismo en los tres casos: un día
    sin clases en toda la casa, una promotoría que ese día no tiene, o una que
    no tiene horario ninguno. `horarios.js` escribe la misma frase al filtrar.
  --}}
  <p class="vacio" data-horarios-vacio @if ($visiblesHoy->isNotEmpty()) hidden @endif>
    @if ($promotoria && ! collect($bloques)->contains('id', $promotoria))
      {{ $nombrePromotoria }} no tiene grupos con horario.
    @elseif ($promotoria)
      {{ $nombrePromotoria }} no tiene clases {{ $dia >= 6 ? 'los '.mb_strtolower($diaNombre).'s' : 'los '.mb_strtolower($diaNombre) }}.
    @else
      {{ $dia >= 6 ? 'Los '.mb_strtolower($diaNombre).'s' : 'Los '.mb_strtolower($diaNombre) }} no hay clases.
    @endif
  </p>

  @foreach ($bloques as $bloque)
    @php($suyo = (! $promotoria || $bloque['id'] === $promotoria) && collect($bloque['filas'])->contains('dia', $dia))
    <section class="horarios-bloque" data-promotoria="{{ $bloque['id'] }}" data-nombre="{{ $bloque['nombre'] }}"
             aria-labelledby="horarios-p{{ $bloque['id'] }}" @if (! $suyo) hidden @endif>
      <div class="horarios-cabecera">
        <h3 id="horarios-p{{ $bloque['id'] }}"><span class="tag-dot {{ $bloque['color'] }}"></span>{{ $bloque['nombre'] }}</h3>
        <p class="horarios-meta">
          {{ $bloque['area'] }}
          <span aria-hidden="true">·</span>
          {{ $bloque['profesor'] ?? 'Sin profesor asignado' }}
        </p>
      </div>
      <table class="horarios-tabla">
        <thead>
          <tr>
            <th scope="col">Hora</th>
            <th scope="col">Grupo</th>
            <th scope="col">Salón</th>
          </tr>
        </thead>
        <tbody>
          @foreach ($bloque['filas'] as $fila)
            <tr data-dia="{{ $fila['dia'] }}" @if ($fila['dia'] !== $dia) hidden @endif @if ($fila['cruces']) data-cruce @endif>
              <td class="horarios-hora">{{ $fila['rango'] }}</td>
              <td>
                {{--
                  EL GRUPO LLEVA A SUS CLASES Y SU ASISTENCIA (03/10/2026, pedido
                  del usuario): desde aquí es por donde se llega a revisar un
                  grupo. La puerta de destino es `puedeGestionarPromotoria()`,
                  que deja pasar al administrador y al director en lo suyo, o
                  sea a quien esta pantalla se lo enseña.
                --}}
                <a class="horarios-grupo" href="{{ route('grupo-clases', $fila['grupo_id']) }}" data-grupo-clases>{{ $fila['grupo'] }}</a>
                {{--
                  EL CRUCE se dice con una palabra en un marcador y con quién
                  choca: el color solo acompaña. No bloquea nada —decisión del
                  usuario—, lo deja a la vista.
                --}}
                @if ($fila['cruces'])
                  <div class="horarios-cruce">
                    <span class="estado estado-cruce">Cruce de salón</span>
                    <ul class="horarios-cruce-lista">
                      @foreach ($fila['cruces'] as $cruce)
                        <li>con {{ $cruce }}</li>
                      @endforeach
                    </ul>
                  </div>
                @endif
              </td>
              <td class="horarios-salon">{{ $fila['salon'] ?? '—' }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </section>
  @endforeach

  @if ($sinHorario)
    <p class="campo-ayuda horarios-sin-horario">
      {{ $sinHorario }} {{ $sinHorario === 1 ? 'grupo no tiene' : 'grupos no tienen' }}
      horario puesto y no {{ $sinHorario === 1 ? 'sale' : 'salen' }} aquí.
      Se pone en cada grupo, desde <a href="{{ route('grupo-lista') }}">la lista de grupos</a>.
    </p>
  @endif
</div>
@endif
@endsection

@push('scripts')
<script src="@recurso('js/horarios.js')" defer></script>
@endpush
