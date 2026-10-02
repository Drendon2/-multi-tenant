@extends('layouts.operador')

@section('title', 'Resumen general — Panel de instituciones')

{{--
  El resumen de TODAS las instituciones (paso 5, 02/10/2026). Lo que entra lo
  decidio el usuario: poblacion impactada, promotorias, datos demograficos y
  profesores por promotoria, y nada de asistencia a clase. Las cifras salen de
  `ResumenGlobal`, institucion por institucion, con las mismas clases que la
  cinta de Gestion y la encuesta de Estadisticas: aqui no se calcula nada.
--}}
@section('content')
  <h2>Resumen general</h2>
  {{-- Con margen abajo: `.campo-ayuda` no lo trae, y pegada a la cinta se leía como parte de ella. --}}
  <p class="campo-ayuda" style="margin-bottom: 1.3rem;">
    Cada institución cuenta con su propio periodo en curso. Los totales suman
    las de todas, así que una persona inscrita en dos instituciones cuenta dos
    veces.
  </p>

  <div class="cifras-banda">
    <div class="cifras-celda">
      <span class="cifras-num">{{ count($filas) }}</span>
      <span class="cifras-label">Instituciones</span>
    </div>
    <div class="cifras-celda" data-cifra="poblacion-impactada">
      <span class="cifras-num">{{ $totales['poblacionImpactada'] }}</span>
      <span class="cifras-label">Población impactada</span>
    </div>
    <div class="cifras-celda">
      <span class="cifras-num">{{ $totales['estudiantesActivos'] }}</span>
      <span class="cifras-label">Estudiantes activos</span>
    </div>
    <div class="cifras-celda">
      <span class="cifras-num">{{ $totales['promotorias'] }}</span>
      <span class="cifras-label">Promotorías</span>
    </div>
    <div class="cifras-celda">
      <span class="cifras-num">{{ $totales['profesores'] }}</span>
      <span class="cifras-label">Profesores</span>
    </div>
  </div>

  <h3>Por institución</h3>
  <table class="tabla-personas tabla-catalogo" data-tabla="por-institucion">
    <thead>
      <tr>
        <th>Institución</th>
        <th>Periodo en curso</th>
        <th>Población impactada</th>
        <th>Estudiantes activos</th>
        <th>Promotorías</th>
        <th>Profesores</th>
        <th>Última matrícula</th>
      </tr>
    </thead>
    <tbody>
      @foreach ($filas as $fila)
      <tr>
        <td data-celda="nombre">
          {{ $fila['institucion']->nombre }}
          @if ($fila['institucion']->estado === \App\Models\Institucion::SUSPENDIDA)
            <span class="estado estado-retirada">Suspendida</span>
          @endif
        </td>
        <td data-label="Periodo">{{ $fila['periodo'] ?? 'Sin periodo en curso' }}</td>
        <td data-label="Población impactada">{{ $fila['cifras']['poblacionImpactada'] }}</td>
        <td data-label="Estudiantes activos">{{ $fila['cifras']['estudiantesActivos'] }}</td>
        <td data-label="Promotorías">{{ $fila['cifras']['promotorias'] }}</td>
        <td data-label="Profesores">{{ $fila['cifras']['profesores'] }}</td>
        <td data-label="Última matrícula">{{ $fila['ultimaMatricula'] ? \Illuminate\Support\Carbon::parse($fila['ultimaMatricula'])->format('d/m/Y') : '—' }}</td>
      </tr>
      @endforeach
    </tbody>
  </table>

  <h3>Descargas consolidadas</h3>
  <p class="campo-ayuda">
    Cada archivo trae todas las instituciones, con su nombre en la primera
    columna. Se abren con Excel o con Hojas de cálculo de Google.
  </p>
  {{--
    Las tarjetas de «Informes descargables» de Gestión, con el mismo criterio:
    el aviso de los confidenciales va en la tarjeta, ANTES de pulsar.
  --}}
  <div class="tarjetas">
    <a class="tarjeta-enlace" href="{{ route('operador.descarga.resumen') }}">
      Resumen por institución
      <span class="tarjeta-nota">Las cifras de cada una, con su dirección y estado, y los totales.</span>
    </a>
    <a class="tarjeta-enlace" href="{{ route('operador.descarga.promotorias') }}">
      Promotorías y profesores
      <span class="tarjeta-nota">Cada promotoría con su departamento, su profesor y su contacto, inscritos y cupo.</span>
    </a>
    <a class="tarjeta-enlace" href="{{ route('operador.descarga.demografia') }}">
      Datos demográficos, contados
      <span class="tarjeta-nota">Cuántas personas por respuesta en cada pregunta, por institución y de todas. Sin nombres.</span>
    </a>
    <a class="tarjeta-enlace" href="{{ route('operador.descarga.personas') }}">
      Informe completo de personas
      <span class="tarjeta-nota">
        Todas las cuentas con su matrícula, la <strong>encuesta demográfica con
        nombre</strong>, datos de menores y los papeles entregados. Trátalo como
        confidencial.
      </span>
    </a>
    <a class="tarjeta-enlace" href="{{ route('operador.descarga.actividades') }}">
      Cursos y actividades sin matrícula
      <span class="tarjeta-nota">
        Cursos, talleres, grupos de proyección y programas externos de todas.
        Lleva <strong>datos de menores</strong>. Trátalo como confidencial.
      </span>
    </a>
  </div>
  <p class="campo-ayuda">Cada descarga queda registrada con tu nombre.</p>

  <h3>Datos demográficos de todas las instituciones</h3>
  <p class="campo-ayuda">
    {{ $demografia['total'] }} personas han diligenciado la encuesta en todas las
    instituciones. Son cifras agregadas: ninguna respuesta individual se muestra aquí.
  </p>

  <div class="dash-grid-2">
    <div>
      <h4>Género</h4>
      @include('gestion.torta', ['torta' => $graficas['generoTorta']])
    </div>
    <div>
      <h4>Estrato</h4>
      @include('gestion.barras', ['filas' => $graficas['estratoStats']])
    </div>
  </div>
  <div class="dash-grid-2">
    <div>
      <h4>Nivel educativo</h4>
      @include('gestion.barras', ['filas' => $graficas['nivelEducativoStats']])
    </div>
    <div>
      <h4>Ocupación</h4>
      @include('gestion.barras', ['filas' => $graficas['ocupacionStats']])
    </div>
  </div>
  <div class="dash-grid-2">
    <div>
      <h4>Zona</h4>
      @include('gestion.torta', ['torta' => $graficas['zonaTorta']])
    </div>
    <div>
      <h4>Afiliación a salud</h4>
      @include('gestion.barras', ['filas' => $graficas['afiliacionSaludStats']])
    </div>
  </div>
  <div class="dash-grid-2">
    <div>
      <h4>Grupo étnico</h4>
      @include('gestion.barras', ['filas' => $graficas['grupoEtnicoStats']])
    </div>
    <div>
      <h4>Discapacidad</h4>
      @include('gestion.barras', ['filas' => $graficas['discapacidadStats']])
    </div>
  </div>
  <h4>Víctima del conflicto armado</h4>
  @include('gestion.barras', ['filas' => $graficas['victimaConflictoStats']])

  <h3>Profesores por promotoría</h3>
  {{--
    Un `<details>` por institución, plegado: con varias casas la lista entera
    sería una pared. Sin `id`, por la regla de los menús de fila (ver
    `acciones.js`), aunque esta pantalla no lo carga.
  --}}
  @foreach ($promotorias as $grupo)
    <details class="card" data-promotorias-de="{{ $grupo['institucion']->id }}">
      <summary>
        <strong>{{ $grupo['institucion']->nombre }}</strong>
        · {{ count($grupo['promotorias']) }} {{ count($grupo['promotorias']) === 1 ? 'promotoría' : 'promotorías' }}
      </summary>
      @if ($grupo['promotorias'] === [])
        <p class="vacio">Sin promotorías.</p>
      @else
        <table class="tabla-personas tabla-catalogo">
          <thead>
            <tr><th>Promotoría</th><th>Departamento</th><th>Profesor</th><th>Inscritos</th><th>Cupo</th></tr>
          </thead>
          <tbody>
            @foreach ($grupo['promotorias'] as $p)
            <tr>
              <td data-celda="nombre">{{ $p['promotoria'] }}</td>
              <td data-label="Departamento">{{ $p['departamento'] }}</td>
              <td data-label="Profesor">{{ $p['profesor'] ?? 'Sin profesor' }}</td>
              <td data-label="Inscritos">{{ $p['inscritos'] }}</td>
              <td data-label="Cupo">{{ $p['cupo'] ?? 'Sin tope' }}</td>
            </tr>
            @endforeach
          </tbody>
        </table>
      @endif
    </details>
  @endforeach
@endsection
