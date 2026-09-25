@extends('layouts.app')

@section('title', 'Estadísticas')

@section('content')
<a href="{{ route('gestion-inicio') }}" class="volver">&larr; Gestión</a>
<h2>Estadísticas</h2>

{{--
  El paso entre periodos. Las flechas van a los lados del nombre porque es la
  forma en que se lee un calendario: atrás a la izquierda, adelante a la derecha.

  Cuando no hay a dónde ir la flecha se pinta igual, apagada, en vez de
  desaparecer: si se quitara, las dos flechas cambiarían de sitio al llegar al
  extremo y la que quedara caería justo donde estaba la otra — un clic de más
  saltaría dos periodos sin querer.

  En el móvil se puede además deslizar con el dedo sobre esta barra. Es un atajo,
  no el único camino: los enlaces siguen ahí y funcionan sin JavaScript.
--}}
@if ($periodoActual)
{{--
  La misma barra que los perfiles, desde `partials/periodo-nav`. Aquí el periodo
  va en el CAMINO y no como parámetro de consulta, porque el tablero entero es de
  ese periodo; en una ficha solo se mueve un panel. Por eso el parcial recibe
  URLs ya armadas en vez de una ruta.
--}}
@include('partials.periodo-nav', [
  'periodo' => $periodoActual,
  'urlAtras' => $haciaAtras ? route('gestion-estadisticas-periodo', $haciaAtras) : null,
  'urlAdelante' => $haciaAdelante ? route('gestion-estadisticas-periodo', $haciaAdelante) : null,
  'enCurso' => $esElEnCurso,
])

{{--
  Lo que cambia con el periodo y lo que no. Decirlo evita la lectura equivocada
  más probable de esta pantalla: que TODO el tablero se mueve con la flecha.
--}}
<p class="campo-info" style="margin:-0.6rem 0 1.4rem;">
  El periodo mueve las matrículas, el mapa de actividad, los cursos y actividades y los dos rankings. El
  catálogo y la encuesta demográfica son de toda la institución y no cambian.
</p>
@endif

{{--
  LA MISMA CINTA QUE LA PORTADA DE GESTIÓN, desde el 07/09/2026.

  Antes era `.card` + `.dash-resumen`, que es otro tratamiento del mismo dato:
  las dos pantallas se abren una detrás de otra —Estadísticas se entra desde
  Gestión— y la cifra cambiaba de forma por el camino.

  SE CAMBIA EL MARCADO Y NO EL CSS, y esa es la parte que importa: la clase
  `.dash-resumen` la comparten otras CINCO pantallas —satisfacción, dos
  historiales y la trayectoria— y restilarla para que se pareciera a la cinta se
  las habría llevado a todas por delante sin que nadie las mirara. Lo dice el
  comentario de la portada de Gestión, que ya avisó de lo mismo al revés.

  `.cifras-banda` trae su propia tarjeta —fondo, borde, sombra y margen— así que
  el `.card` que envolvía esto sobra: dejarlo pondría una tarjeta dentro de otra.
--}}
<div class="cifras-banda">
  <div class="cifras-celda">
    <span class="cifras-num">{{ $totalEstudiantesActivos }}</span>
    <span class="cifras-label">Estudiantes activos</span>
  </div>
  {{--
    POBLACIÓN IMPACTADA (25/09/2026, pedida por el usuario): estudiantes
    activos más la gente de actividades del periodo, sin contar dos veces a
    quien se puede reconocer. Es la ÚNICA cifra que suma las dos poblaciones;
    por qué, y dónde puede pasarse, en `ResumenInstitucion::poblacionImpactada()`.
  --}}
  <div class="cifras-celda" data-cifra="poblacion-impactada">
    <span class="cifras-num">{{ $cifras['poblacionImpactada'] }}</span>
    <span class="cifras-label">Población impactada</span>
  </div>
  <div class="cifras-celda">
    <span class="cifras-num">{{ $totalPromotorias }}</span>
    <span class="cifras-label">{{ $totalPromotorias == 1 ? 'Promotoría' : 'Promotorías' }}</span>
  </div>
  <div class="cifras-celda">
    <span class="cifras-num">{{ $totalGrupos }}</span>
    <span class="cifras-label">{{ $totalGrupos == 1 ? 'Grupo' : 'Grupos' }}</span>
  </div>
  {{--
    Cursos, proyección y cupos entran aquí el 07/09/2026: la portada de Gestión
    ya los pintaba y esta pantalla no, así que la misma cinta decía dos cosas
    distintas según por dónde se entrara. Las cifras salen de
    `ResumenInstitucion`, la misma fuente que la otra.
  --}}
  <div class="cifras-celda">
    <span class="cifras-num">{{ $cifras['cursosYTalleres'] }}</span>
    <span class="cifras-label">Cursos y talleres</span>
  </div>
  <div class="cifras-celda">
    <span class="cifras-num">{{ $cifras['proyeccion'] }}</span>
    <span class="cifras-label">Grupos de proyección</span>
  </div>
  <div class="cifras-celda" data-cifra="programas-externos">
    <span class="cifras-num">{{ $cifras['programasExternos'] }}</span>
    <span class="cifras-label">{{ $cifras['programasExternos'] == 1 ? 'Programa externo' : 'Programas externos' }}</span>
  </div>
  <div class="cifras-celda">
    <span class="cifras-num">{{ $cifras['cuposDisponibles'] }}</span>
    <span class="cifras-label">Cupos disponibles</span>
  </div>
  {{--
    LA CIFRA DE ENCUESTAS ES UN PORCENTAJE, y el par exacto vive en el rótulo.

    Elegido en vivo por el usuario el 07/09/2026 entre tres variantes. El
    problema no era el color ni el peso: era la MASA. «227/308» son siete glifos
    a 1.75rem contra los dos o tres de sus seis vecinas, así que esa celda
    gritaba dentro de una fila que está pensada para que todas pesen igual. Un
    solo número la devuelve al mismo tamaño óptico que las demás y no se pierde
    ningún dato — los dos siguen abajo.

    El guardia del cero no sobra: `$totalConRol` es la gente CON rol, y en una
    instalación recién montada son cero hasta que el instalador crea al primer
    administrador. Sin él, esta pantalla dividiría por cero el día del estreno.
  --}}
  <div class="cifras-celda">
    <span class="cifras-num">{{ $totalConRol > 0 ? round($totalEncuestas * 100 / $totalConRol) : 0 }}%</span>
    <span class="cifras-label">{{ $totalEncuestas }} de {{ $totalConRol }} encuestas</span>
  </div>
</div>

@include('gestion.partials.aviso-sin-tope', ['sinTope' => $cifras['promotoriasSinTope']])

@php($sufijoPeriodo = $periodoActual ? $periodoActual->nombre : '')
<h3 style="margin-top: 0.5rem;">
  Estudiantes por departamento y promotoría
  @if ($periodoActual)<span class="h4-nota">— {{ $sufijoPeriodo }}</span>@endif
</h3>
@if (! $periodoActual)
  <p class="vacio">No hay un periodo en curso, así que no hay nada que medir.</p>
@elseif (! $arbolDepartamentos)
  <p class="vacio">Todavía no hay matrículas en {{ $periodoActual->nombre }}.</p>
@else
  <p class="campo-info" style="margin: -0.4rem 0 1rem;">
    <strong>Sigue</strong> es quién continúa matriculado hoy y <strong>deja</strong> quién se
    retiró, ambos dentro de {{ $periodoActual->nombre }}.
    @if ($periodoPrevio)
      <strong>No volvió</strong> es otra cosa: de quienes cursaron {{ $periodoPrevio->nombre }},
      qué parte no regresó a esa misma promotoría.
    @endif
  </p>
  {{--
    La leyenda va una sola vez y no por fila: es lo que hace decodificable la
    forma de cada columna, y repetirla 26 veces la volvería el ruido que estas
    micro-gráficas vinieron a quitar.
  --}}
  <div class="perm-leyenda">
    <span><i class="perm-col perm-sigue"></i>Sigue</span>
    <span><i class="perm-col perm-deja"></i>Deja</span>
    @if ($periodoPrevio)<span><i class="perm-col perm-novolvio"></i>No volvió</span>@endif
  </div>
  @foreach ($arbolDepartamentos as $d)
  {{--
    Arranca plegado salvo que el departamento esté perdiendo la mitad o más de su
    gente: acortar la página no puede costar esconder justo la fila que hay que
    mirar. Es `<details>` nativo, sin JavaScript, como la encuesta de más abajo.
  --}}
  <details class="dash-departamento" id="dep-{{ $d['id'] }}" @if ($d['pct_desercion'] >= 50) open @endif>
    <summary class="stat-bar-fila dep-cabecera">
      <svg class="dep-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
      <span class="stat-bar-etiqueta"><span class="tag-dot {{ $d['tag_class'] }}"></span>{{ $d['nombre'] }}</span>
      <div class="stat-bar-pista">
        <div class="stat-bar-relleno {{ $d['tag_class'] }}" style="width: {{ $d['porcentaje'] }}%;"></div>
      </div>
      <span class="stat-bar-num">{{ $d['total'] }}</span>
      @include('gestion.permanencia', ['fila' => $d, 'hayPrevio' => $periodoPrevio, 'tono' => $d['tag_class']])
    </summary>
    <div class="dash-promotorias">
      @foreach ($d['promotorias'] as $p)
      <div class="stat-bar-fila">
        <span class="stat-bar-etiqueta">{{ $p['etiqueta'] }}</span>
        <div class="stat-bar-pista">
          <div class="stat-bar-relleno" style="width: {{ $p['porcentaje'] }}%;"></div>
        </div>
        <span class="stat-bar-num">{{ $p['total'] }}</span>
        @include('gestion.permanencia', ['fila' => $p, 'hayPrevio' => $periodoPrevio, 'tono' => ''])
      </div>
      @endforeach
    </div>
  </details>
  @endforeach
@endif

{{--
  Actividad de la casa: qué días se dio clase y cuántas.

  Es el mismo calendario de las fichas, con la misma rejilla de siete filas y una
  columna por semana, pero contando todas las clases del periodo. La escala es
  relativa al día más cargado —ver `ResumenAsistencia::deInstitucion`—: con una
  escala fija, un martes normal de la institución entera ya saturaría el tono más
  oscuro y el mapa dejaría de decir nada.
--}}
@if ($mapaInstitucion)
<h3 style="margin-top:2rem;">
  Actividad de la institución
  @if ($periodoActual)<span class="h4-nota">— {{ $periodoActual->nombre }}</span>@endif
</h3>

<div class="card" style="margin-bottom:2rem;">
  @include('partials.mapa-calor', ['mapa' => $mapaInstitucion])

  @if ($mapaInstitucion['diasSemana'])
  <h4 style="margin:1.4rem 0 0.6rem;">Días de la semana con más clase</h4>
  <p class="campo-info" style="margin-top:-0.4rem;">
    Sirve para decidir horarios: si un día concentra la mitad de las clases,
    abrir ahí un grupo nuevo es pelearse por el salón.
  </p>
  @include('gestion.barras', ['filas' => array_map(
    fn ($d) => ['etiqueta' => $d['etiqueta'], 'total' => $d['total'], 'porcentaje' => $d['porcentaje']],
    $mapaInstitucion['diasSemana']
  )])
  @endif
</div>
@endif

{{--
  LA GENTE SIN MATRÍCULA: cursos, talleres, grupos de proyección y programas
  externos (25/09/2026). Va APARTE y no se suma a ninguna cifra de arriba, por
  decisión del usuario: un estudiante de semestre y quien fue a un taller de un
  día no responden a la misma pregunta. Lo único que cruza las dos mitades es
  la línea de «también matriculados». Las cuentas y a qué periodo pertenece
  una actividad sin periodo viven en `ResumenActividades`.
--}}
@if ($actividades)
<h3 style="margin-top:2rem;">
  Cursos y actividades sin matrícula
  <span class="h4-nota">— {{ $periodoActual->nombre }}</span>
</h3>
<p class="campo-ayuda" style="margin:-0.4rem 0 1rem;">
  Aquí no se suman a las matrículas; solo «Población impactada», arriba, junta
  a las dos sin contar dos veces a nadie. Quien va a dos actividades cuenta en
  las dos, y una sesión cuenta solo si se tomó lista.
</p>
@foreach ($actividades['tipos'] as $tipo => $t)
<h4 style="margin:1.2rem 0 0.5rem;">{{ $t['etiqueta'] }}</h4>
<div class="cifras-banda" data-tipo-actividad="{{ $tipo }}">
  <div class="cifras-celda">
    <span class="cifras-num">{{ $t['actividades'] }}</span>
    <span class="cifras-label">{{ $t['actividades'] === 1 ? 'Actividad' : 'Actividades' }}</span>
  </div>
  <div class="cifras-celda">
    <span class="cifras-num">{{ $t['inscritos'] }}</span>
    <span class="cifras-label">{{ $t['inscritos'] === 1 ? 'Inscrito' : 'Inscritos' }}</span>
  </div>
  <div class="cifras-celda">
    <span class="cifras-num">{{ $t['sesiones'] }}</span>
    <span class="cifras-label">{{ $t['sesiones'] === 1 ? 'Sesión con lista' : 'Sesiones con lista' }}</span>
  </div>
  <div class="cifras-celda">
    <span class="cifras-num">{{ $t['asistencias'] }}</span>
    <span class="cifras-label">{{ $t['asistencias'] === 1 ? 'Asistencia' : 'Asistencias' }}</span>
  </div>
</div>
@if ($tipo === \App\Models\Actividad::EXTERNO && $actividades['verificacion'] && $actividades['verificacion']['iniciadas'] > 0)
{{--
  Las dos vías de verificación van SEPARADAS a propósito: la firma desde la
  cuenta de la institución y el QR que escanea el propio profesor no dan la
  misma garantía, y una suma de las dos no se puede auditar.
--}}
<p class="campo-ayuda" data-verificacion-externa>
  De {{ $actividades['verificacion']['iniciadas'] }} {{ $actividades['verificacion']['iniciadas'] === 1 ? 'clase iniciada' : 'clases iniciadas' }},
  la institución verificó <strong>{{ $actividades['verificacion']['propia'] }}</strong> desde su cuenta
  y <strong>{{ $actividades['verificacion']['qr'] }}</strong> con el QR.
</p>
@endif
@endforeach
<p class="campo-ayuda" data-tambien-matriculados style="margin-bottom:2rem;">
  @if ($actividades['tambienMatriculados'] === 0)
    Ninguna de estas personas está matriculada en {{ $periodoActual->nombre }}.
  @else
    <strong>{{ $actividades['tambienMatriculados'] }}</strong>
    {{ $actividades['tambienMatriculados'] === 1 ? 'de estas personas también está matriculada' : 'de estas personas también están matriculadas' }}
    en {{ $periodoActual->nombre }}. Solo se reconoce a quien se inscribió con su documento.
  @endif
</p>
@endif

{{--
  Los dos rankings. Van juntos porque se leen juntos: uno dice quién sostiene las
  clases y el otro quién las aprovecha.
--}}
<div class="dash-grid-2">
  <div>
    <h3>Profesores con más clases</h3>
    {{--
      La columna de verificadas no es un adorno. Registrar una clase es apretar
      un botón, y quien lo aprieta es parte interesada; un ranking a secas
      premiaría a quien más veces lo pulsa. Con las dos cifras al lado, la lista
      dice lo que se puede afirmar de verdad.
    --}}
    <p class="campo-info" style="margin-top:-0.4rem;">
      «Verificadas» son las que confirmaron sus propios estudiantes.
    </p>
    @if (! $profesoresActivos)
      <p class="vacio">No hay clases registradas en este periodo.</p>
    @else
    <table>
      <thead>
        <tr><th>Profesor</th><th>Clases</th><th>Verificadas</th><th>Grupos</th></tr>
      </thead>
      <tbody>
        @foreach ($profesoresActivos as $p)
        <tr>
          <td>
            @if (\App\Support\Permisos::puedeVerFicha($yo, $p['perfil']))
              <a href="{{ route('detalle-usuario', $p['perfil']) }}">{{ $p['perfil']->nombre_completo }}</a>
            @else
              {{ $p['perfil']->nombre_completo }}
            @endif
          </td>
          <td>{{ $p['clases'] }}</td>
          <td>{{ $p['verificadas'] }} <span class="campo-info">({{ $p['pct'] }}%)</span></td>
          <td>{{ $p['grupos'] }}</td>
        </tr>
        @endforeach
      </tbody>
    </table>
    @endif
  </div>

  <div>
    <h3>Estudiantes más constantes</h3>
    {{--
      Ordenado por PORCENTAJE y no por número de asistencias: a quien cursa dos
      promotorías le caben el doble de sesiones y por el número absoluto
      encabezaría siempre sin ser más constante. El mínimo se dice aquí, no se
      esconde en el código: sin él, un 1 de 1 sería un 100 %.
    --}}
    <p class="campo-info" style="margin-top:-0.4rem;">
      Desde {{ $minimoConstancia }} clases con lista pasada. Las clases sin
      marcar no cuentan como falta.
    </p>
    @if (! $estudiantesConstantes)
      <p class="vacio">Todavía no hay suficientes clases con lista pasada.</p>
    @else
    <table>
      <thead>
        <tr><th>Estudiante</th><th>Asistió</th><th>Constancia</th></tr>
      </thead>
      <tbody>
        @foreach ($estudiantesConstantes as $e)
        <tr>
          <td>
            @if (\App\Support\Permisos::puedeVerFicha($yo, $e['perfil']))
              <a href="{{ route('detalle-usuario', $e['perfil']) }}">{{ $e['perfil']->nombre_completo }}</a>
            @else
              {{ $e['perfil']->nombre_completo }}
            @endif
          </td>
          <td>{{ $e['asistio'] }} de {{ $e['marcadas'] }}</td>
          <td>{{ $e['pct'] }}%</td>
        </tr>
        @endforeach
      </tbody>
    </table>
    @endif
  </div>
</div>

<div class="dash-grid-2" style="margin-top:1.7rem;">
  <div>
    <h3>Grupos por nivel</h3>
    @include('gestion.barras', ['filas' => $gruposPorCurso])
  </div>
  <div>
    <h3>Estudiantes por periodo</h3>
    @include('gestion.barras', ['filas' => $estudiantesPorPeriodo])
  </div>
</div>

{{--
  La satisfacción va en su propio desplegable y antes que la demográfica: son dos
  encuestas distintas y responden a preguntas distintas. Esta dice cómo nos fue
  el semestre pasado —que es lo que se viene a mirar—; la otra describe a quién
  atiende la casa, y se consulta mucho menos a menudo.
--}}
<details class="perfil-seccion" id="bloque-satisfaccion" style="max-width: none; margin-top: 2.6rem;">
  <summary class="perfil-seccion-cabecera">
    <span class="perfil-seccion-icono icono-encuesta" aria-hidden="true">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
      </svg>
    </span>
    <h3 style="margin:0;">Satisfacción</h3>
    <svg aria-hidden="true" class="perfil-seccion-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>
  </summary>

  @include('gestion.satisfaccion')
</details>

<details class="perfil-seccion" id="bloque-encuesta" style="max-width: none; margin-top: 1.4rem;">
  <summary class="perfil-seccion-cabecera">
    <span class="perfil-seccion-icono icono-encuesta" aria-hidden="true">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <rect x="6" y="4" width="12" height="17" rx="2"/>
        <path d="M9 4V3a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v1"/>
        <path d="M9 11h6M9 15h6M9 19h3"/>
      </svg>
    </span>
    <h3 style="margin:0;">Encuesta demográfica</h3>
    <svg aria-hidden="true" class="perfil-seccion-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>
  </summary>

  <p class="campo-ayuda" style="margin: 0.9rem 0 1.3rem;">
    {{ $totalEncuestas }} de {{ $totalConRol }} personas con rol asignado han diligenciado la
    encuesta. Estas cifras son agregadas — ninguna respuesta individual se muestra aquí.
  </p>

  {{--
    Una encuesta a medias contaba como diligenciada y no lo estaba. Sin este
    aviso, la única señal era que las barras de esas preguntas sumaban menos que
    el total, y ni siquiera eso se veía.
  --}}
  @if ($encuestasIncompletas)
  <p class="aviso" style="margin-bottom: 1.3rem;">
    <strong>{{ $encuestasIncompletas }} de esas {{ $totalEncuestas }}
    {{ $encuestasIncompletas == 1 ? 'está incompleta' : 'están incompletas' }}</strong>
    y {{ $encuestasIncompletas == 1 ? 'aparece' : 'aparecen' }} como «Sin responder» en las
    preguntas que le faltan. Cada persona ve lo que le falta en «Mi perfil» y puede
    completarlo desde ahí. No se rellenan solas ni desde aquí — es información de la
    persona, no del sistema.
  </p>
  @endif

  <div class="dash-grid-2">
    <div>
      <h4>Género</h4>
      @include('gestion.torta', ['torta' => $generoTorta])
    </div>
    <div>
      <h4>Estrato</h4>
      @include('gestion.barras', ['filas' => $estratoStats])
    </div>
  </div>

  <h4>Autorización de tratamiento de datos</h4>
  @if ($totalEncuestas)
    <div class="dash-split">
      <div class="dash-split-si" style="width: {{ $pctAutorizaSi }}%;"></div>
      <div class="dash-split-no" style="width: {{ $pctAutorizaNo }}%;"></div>
    </div>
    <div class="dash-split-leyenda">
      <span><span class="punto" style="background: var(--accent);"></span>Autorizan ({{ $autorizaSi }})</span>
      <span><span class="punto" style="background: var(--border-strong);"></span>No autorizan ({{ $autorizaNo }})</span>
    </div>
  @else
    <p class="vacio">Sin respuestas todavía.</p>
  @endif

  {{--
    Las cifras cuadran con el total de encuestas porque quien no cae en ninguna
    opción entra como «Sin responder» al final. Eso no siempre significa que la
    persona se saltara la pregunta: también cae ahí quien tiene la encuesta a
    medias.
  --}}
  <div class="dash-grid-2" style="margin-top: 1.7rem;">
    <div>
      <h4>Nivel educativo</h4>
      @include('gestion.barras', ['filas' => $nivelEducativoStats])
    </div>
    <div>
      <h4>Ocupación</h4>
      @include('gestion.barras', ['filas' => $ocupacionStats])
    </div>
  </div>

  <div class="dash-grid-2" style="margin-top: 1.3rem;">
    <div>
      <h4>Zona <span class="h4-nota">(opcional)</span></h4>
      @include('gestion.torta', ['torta' => $zonaTorta])
    </div>
    <div>
      <h4>Afiliación a salud <span class="h4-nota">(opcional)</span></h4>
      @include('gestion.barras', ['filas' => $afiliacionSaludStats])
    </div>
  </div>

  <div class="dash-grid-2" style="margin-top: 1.3rem;">
    <div>
      <h4>Grupo étnico <span class="h4-nota">(dato sensible y opcional)</span></h4>
      @include('gestion.barras', ['filas' => $grupoEtnicoStats])
    </div>
    <div>
      <h4>Discapacidad <span class="h4-nota">(dato sensible y opcional)</span></h4>
      @include('gestion.barras', ['filas' => $discapacidadStats])
    </div>
  </div>

  <h4 style="margin-top: 1.3rem;">
    Víctima del conflicto armado <span class="h4-nota">(dato sensible y opcional)</span>
  </h4>
  @include('gestion.barras', ['filas' => $victimaConflictoStats])
</details>
@endsection

@push('scripts')
{{-- Solo el gesto de deslizar. Las flechas funcionan sin esto. --}}
<script src="@recurso('js/periodo.js')" defer></script>
@endpush
