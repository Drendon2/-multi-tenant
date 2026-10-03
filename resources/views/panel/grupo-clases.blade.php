@extends('layouts.app')

@section('title', "Clases — {$grupo}")

@section('content')
<p class="migas">
  <a href="{{ route('panel') }}">Panel</a><span class="migas-sep">/</span>
  <span class="migas-actual">{{ $grupo->promotoria->nombre }} · {{ $grupo->nombre }}</span>
</p>

<h2>Clases dictadas</h2>
<p class="campo-info">
  <span class="tag-dot {{ $grupo->promotoria->area->tag_color }}"></span>{{ $grupo->promotoria->nombre }} ·
  {{ $grupo->rotulo }}@if ($periodo) · {{ $periodo->nombre }}@endif
</p>

{{--
  La lista de este grupo, aquí también: es la pantalla donde quien dicta pasa
  lista, y es justo donde se acuerda de que necesita la lista en papel.
--}}
<p>
  <a class="btn btn-secundario btn-sm" href="{{ route('informe-estudiantes', ['grupo' => $grupo->id]) }}">
    Descargar lista (Excel)
  </a>
  {{--
    Los carnés del grupo, nueve por hoja carta (03/10/2026, pedido del usuario:
    desde Horarios se llega aquí). Solo administración, como en la lista de
    estudiantes del grupo.
  --}}
  @if ($puedeImprimirCarnes)
  <a class="btn btn-secundario btn-sm" href="{{ route('carnes-grupo', $grupo) }}" data-carnes-grupo>
    Imprimir carnés (PDF)
  </a>
  @endif
</p>

@if (! $periodo)
  <p class="vacio">No hay un periodo en curso, así que todavía no hay clases que mostrar.</p>
@else
  @if ($puedeMarcar)
  <form action="{{ route('panel-clase-nueva', $grupo) }}" method="post" style="margin-bottom:1.4rem;">
    @csrf
    <button type="submit" class="btn">Iniciar clase</button>
  </form>
  @else
  <p class="aviso">
    Registrar clases y pasar lista es del profesor que dicta la promotoría. Aquí ves
    el registro, pero no lo modificas.
  </p>
  @endif

  @php($totalClases = count($clases))
  <h4>Sesiones registradas{{ $totalClases ? " ({$totalClases})" : '' }}</h4>
  @if (! $clases)
    <p class="vacio">Todavía no se ha registrado ninguna clase de este grupo en {{ $periodo->nombre }}.</p>
  @else
  <table>
    <thead>
      <tr>
        <th>Día</th><th class="num">Hora</th><th>Verificación</th><th class="num">Asistió</th>
        <th class="num">Faltó</th><th class="num">Con excusa</th><th class="num">Sin marcar</th><th></th>
      </tr>
    </thead>
    <tbody>
      @foreach ($clases as $c)
      <tr>
        <td>
          {{ $c['clase']->fecha_hora->isoFormat('ddd D [de] MMMM') }}
          @if ($reposiciones->has($c['clase']->id))
            <span class="lista-nota lista-nota-bloque">Reposición del {{ $reposiciones[$c['clase']->id]->format('d/m/Y') }}</span>
          @endif
        </td>
        <td class="num">{{ $c['clase']->fecha_hora->format('H:i') }}</td>
        <td>
          @if ($c['verificada'])
            <span class="estado estado-activa">Verificada</span>
          @elseif ($c['vencida'])
            {{-- El plazo se acabó sin reunir las confirmaciones: ya no va a cambiar. --}}
            <span class="estado estado-retirada">Sin verificar</span>
          @elseif ($c['requeridas'])
            <span class="estado estado-pendiente">{{ $c['confirmaciones'] }} de {{ $c['requeridas'] }}</span>
          @else
            {{-- Grupo sin nadie inscrito el día de la clase: no hay quién confirme. --}}
            <span class="vacio">Sin quién confirme</span>
          @endif
        </td>
        <td class="num">{{ $c['asistio'] }}</td>
        <td class="num">{{ $c['falto'] }}</td>
        <td class="num">{{ $c['excusa'] }}</td>
        <td class="num">
          @if ($c['sin_marcar']){{ $c['sin_marcar'] }}@else<span class="vacio">—</span>@endif
        </td>
        <td><a href="{{ route('clase-asistencia', $c['clase']) }}">Abrir lista</a></td>
      </tr>
      @endforeach
    </tbody>
  </table>
  @endif

  <h4>Por estudiante</h4>
  @if (! $filas)
    <p class="vacio">Este grupo no tiene estudiantes inscritos.</p>
  @else
  <table>
    <thead>
      <tr>
        <th></th><th>Nombre</th><th class="num">Asistió</th><th class="num">Faltó</th>
        <th class="num">Con excusa</th><th class="num">Asistencia</th>
      </tr>
    </thead>
    <tbody>
      @foreach ($filas as $f)
      @php($estudiante = $f['matricula']->estudiante)
      <tr>
        <td>@include('panel.foto', ['perfil' => $estudiante])</td>
        <td>
          @if (\App\Support\Permisos::puedeVerFicha($yo, $estudiante))
            <a href="{{ route('detalle-usuario', $estudiante) }}">{{ $estudiante->nombre_completo }}</a>
          @else
            {{ $estudiante->nombre_completo }}
          @endif
        </td>
        <td class="num">{{ $f['asistio'] }}</td>
        <td class="num">{{ $f['falto'] }}</td>
        <td class="num">{{ $f['excusa'] }}</td>
        {{--
          El porcentaje va sobre las clases DICTADAS, no sobre las veces que a
          esa persona la marcaron (ver ResumenAsistencia::deGrupo). Sin clases
          todavía no hay porcentaje que dar, y ahí va una raya, no un cero.
        --}}
        <td class="num">
          @if ($f['porcentaje'] === null)<span class="vacio">—</span>@else{{ $f['porcentaje'] }}%@endif
        </td>
      </tr>
      @endforeach
    </tbody>
  </table>
  @endif
@endif

<p style="margin-top:1.5rem;"><a class="volver" href="{{ route('panel') }}">← Volver al panel</a></p>
@endsection
