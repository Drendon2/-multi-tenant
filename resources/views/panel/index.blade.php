{{--
  El envoltorio es variable porque esta portada se responde de dos formas: la
  página entera al abrir el Panel, y solo lo de dentro de <main> al responder a
  una acción sin recargar (ver `App\Support\Fragmento`). Por defecto, la de
  siempre.
--}}
@extends($disposicion ?? 'layouts.app')

@section('title', 'Panel')

@section('content')
<h2>Panel de promotorías</h2>

@include('partials.recordar-encuesta')

{{--
  CLASES POR REEMPLAZAR (03/10/2026, pedido del usuario): las clases que la
  bandeja de alertas marcó como FALTA y todavía no se han repuesto. Solo las de
  quien dicta (`PanelController::clasesPorReponer()`).

  Va ARRIBA de todo y ABIERTO: es trabajo pendiente, y como nada le avisa a
  nadie de nada en este sistema, esta lista es la única forma de que el
  profesor se entere. «Dictar reposición» inicia una clase ahora y lleva a
  pasar lista; al guardarla se va de aquí sola.
--}}
@if ($porReponer->isNotEmpty())
<details class="panel-departamento" id="bloque-por-reponer" open>
  <summary class="panel-departamento-resumen">
    Clases por reemplazar
    <span class="panel-departamento-cuenta">{{ $porReponer->count() }}</span>
    <svg aria-hidden="true" class="perfil-seccion-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>
  </summary>

  <p class="campo-ayuda" style="margin:0.5rem 0;">
    Clases que no se dieron y que dirección marcó como falta. Cuando des la
    reposición, iníciala desde aquí para que quede registrada como tal.
  </p>

  <ul class="sesiones-rapidas">
    @foreach ($porReponer as $omision)
    <li>
      <span class="sesiones-rapidas-fecha">{{ $omision->fecha->format('d/m/Y') }}</span>
      <span class="tag-dot {{ $omision->grupo->promotoria->area->tag_color }}"></span>
      <span>{{ $omision->grupo->promotoria->nombre }} · {{ $omision->grupo->nombre_con_nivel }}</span>
      <form method="post" action="{{ route('panel-reponer-clase', $omision) }}">
        @csrf
        <button type="submit" class="btn btn-sm"
                aria-label="Dictar reposición: {{ $omision->grupo->nombre_con_nivel }}, {{ $omision->fecha->format('d/m/Y') }}">Dictar reposición</button>
      </form>
    </li>
    @endforeach
  </ul>
</details>
@endif

{{--
  LAS CLASES DE LA SEMANA, con un selector de día que NO pide nada al servidor.

  Lo pidió el usuario el 06/09/2026: «un filtro por día y hora para los grupos,
  para que lleguen rápido al grupo que le van a dar clase».

  LA PRIMERA VERSIÓN FILTRABA POR `?dia=` Y LA RECHAZÓ, con la razón exacta: «no
  puede quedar recargando toda la página». Este Panel se apoya en `<details>`
  abiertos —departamentos, promotorías, actividades— y una navegación los cierra
  todos: quien estaba mirando una promotoría la perdía a cada cambio de día.

  Así que llega la SEMANA ENTERA y el día se elige en el navegador. Cabe de
  sobra: son los grupos de quien mira con su horario, sin un solo matriculado.

  EL SELECTOR LO CREA `clases-del-dia.js` Y NO ESTA PLANTILLA, igual que el ojo
  de la contraseña y por la misma razón: un selector que filtra sin JavaScript es
  un control que no hace nada. Sin él se ven las seis días seguidos, cada renglón
  con el suyo delante, que es una pantalla útil y no una rota.
--}}
@if ($clasesDeLaSemana !== [])
<details class="panel-departamento" id="bloque-clases-dia"
         data-clases-dia
         data-hoy="{{ $diaDeHoy }}">
  <summary class="panel-departamento-resumen">
    Clases de la semana
    <span class="panel-departamento-cuenta" data-clases-cuenta>
      {{ count($clasesDeLaSemana) }} {{ count($clasesDeLaSemana) == 1 ? 'clase' : 'clases' }}
    </span>
    <svg aria-hidden="true" class="perfil-seccion-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>
  </summary>

  <ul class="sesiones-rapidas" data-clases-lista>
    @foreach ($clasesDeLaSemana as $fila)
    <li data-dia="{{ $fila['dia'] }}">
      <span class="sesiones-rapidas-dia">{{ $diasDeClase[$fila['dia']] ?? '' }}</span>
      <span class="sesiones-rapidas-fecha">{{ $fila['sesion']->inicio_display }}</span>
      <span class="tag-dot {{ $fila['grupo']->promotoria->area->tag_color }}"></span>
      <span>{{ $fila['grupo']->promotoria->nombre }} · {{ $fila['grupo']->nombre_con_nivel }}</span>
      @if ($fila['grupo']->salon)
        <span class="campo-info" style="margin:0;">{{ $fila['grupo']->salon }}</span>
      @endif
      <a href="{{ route('grupo-clases', $fila['grupo']) }}">clases</a>
    </li>
    @endforeach
  </ul>

  {{-- Solo se ve cuando el filtro deja la lista vacía; lo enseña el guion. --}}
  <p class="vacio" data-clases-vacio hidden style="margin:0.5rem 0;">
    No hay ninguna clase ese día.
  </p>
</details>
@endif

{{--
  CURSOS, TALLERES Y GRUPOS DE PROYECCIÓN, con sus clases dentro.

  Hasta el 06/09/2026 esto era un botón suelto que llevaba a otra pantalla, y
  desde ahí había que entrar a la actividad y buscar la sesión: tres pantallas
  para llegar a pasar lista, cuando un grupo de promotoría tiene su enlace
  «clases» a un clic dentro de la promotoría desplegada. Lo pidió el usuario con
  esas palabras — que fuera «como los grupos de las promotorías».

  Se pinta solo si hay alguna a la vista: mientras la institución no use cursos
  ni grupos de proyección, esto no es más que ruido en la pantalla más usada.

  Misma forma que un departamento y por la misma razón: plegado no puede
  significar escondido, así que el resumen dice cuántas hay. Y el `id` no es
  decorativo — `acciones.js` reabre los `<details>` que lo llevan después de
  repintar, y esta portada se repinta entera con cada acción del Panel.
--}}
@if ($actividades->isNotEmpty())
<details class="panel-departamento" id="bloque-actividades">
  <summary class="panel-departamento-resumen">
    Cursos, talleres y grupos de proyección
    <span class="panel-departamento-cuenta">
      {{ $actividades->count() }} {{ $actividades->count() == 1 ? 'en total' : 'en total' }}
    </span>
    <svg aria-hidden="true" class="perfil-seccion-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>
  </summary>

  @foreach ($actividades as $actividad)
  {{--
    Cada una se despliega como una promotoría, y dentro están sus clases. El
    cuerpo viene YA PUESTO y no por `data-cuerpo` como el de una promotoría: lo
    que hay aquí son unas pocas sesiones con su fecha, no la lista de
    matriculados de nadie — o sea, nada que crezca con los estudiantes, que es
    la razón por la que aquel se pide aparte.
  --}}
  <details class="panel-item" id="actividad-{{ $actividad->id }}">
    <summary class="panel-item-resumen">
      {{ $actividad->nombre }}
      <span class="tipo-chip">{{ $actividad->etiquetaTipo() }}</span>
      <span class="panel-departamento-cuenta">
        {{ $actividad->inscritos_count }} {{ $actividad->inscritos_count == 1 ? 'inscrito' : 'inscritos' }}
      </span>
      <svg aria-hidden="true" class="perfil-seccion-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>
    </summary>

    <div class="panel-item-cuerpo">
      @if ($actividad->sesiones->isEmpty())
        <p class="vacio" style="margin:0.6rem 0;">
          @if ($actividad->llevaFechas())
            Todavía no tiene fechas. Las pone dirección, en Gestión → Cursos y talleres.
          @else
            Todavía no se ha hecho ningún {{ $actividad->etiquetaSesion() }}.
          @endif
        </p>
      @else
      {{--
        SOLO LAS OCHO MÁS RECIENTES. El bloque de una actividad va plegado, así
        que esto no se ve hasta que alguien lo abre — pero el HTML viaja igual,
        y una institución con veinte cursos de veinte sesiones metería
        cuatrocientos renglones en la pantalla más usada del sistema. Ocho es lo
        que se mira: a las clases viejas se va por la ficha, que está abajo.
      --}}
      @php($ultimas = $actividad->sesiones->take(8))
      <ul class="sesiones-rapidas">
        @foreach ($ultimas as $sesion)
        <li>
          <span class="sesiones-rapidas-fecha">{{ $sesion->fecha->format('d/m/Y') }}</span>
          @if ($sesion->yaEmpezo())
            <span class="estado estado-activa">Iniciada</span>
            {{--
              La lista se ofrece a todo el que ve la pantalla y no solo a quien
              dirige: dirección la abre en solo lectura, que es exactamente
              para lo que entra. Misma regla que en la ficha de la actividad.
            --}}
            <a href="{{ route('panel-actividad-lista', $sesion) }}">ver la lista</a>
          @else
            <span class="estado estado-pendiente">Sin iniciar</span>
          @endif
        </li>
        @endforeach
      </ul>
      @if ($actividad->sesiones->count() > $ultimas->count())
        <p class="campo-info" style="margin:0.4rem 0 0;">
          Y {{ $actividad->sesiones->count() - $ultimas->count() }} más. Están todas en la ficha.
        </p>
      @endif
      @endif

      <p class="accion-fila" style="margin:0.7rem 0 0.2rem;">
        <a class="btn btn-blanco btn-sm" href="{{ route('panel-actividad', $actividad) }}">
          Abrir {{ $actividad->nombre }}
        </a>
      </p>
    </div>
  </details>
  @endforeach

  <p style="margin:0.9rem 0 0.2rem;">
    <a href="{{ route('panel-actividades') }}">Ver la lista completa</a>
  </p>
</details>
@endif

@if ($promotorias->isEmpty())
  <p class="vacio">No hay promotorías para mostrar todavía.</p>
@else
  {{--
    PLEGADO POR DEPARTAMENTO desde el 04/09/2026, a petición del usuario: un
    director tiene veintiuna promotorías y verlas todas seguidas es demasiada
    pantalla para encontrar una.

    Va CERRADO salvo que solo haya un departamento, porque entonces plegar no
    esconde nada y solo añade un clic — que es justo lo que le pasaría a un
    profesor que dicta en uno solo.

    El resumen dice cuántas promotorías tiene y cuántas solicitudes esperan
    dentro, y esa cifra es la mitad del asunto: plegado no puede significar
    escondido, así que desde fuera ya se ve dónde hay algo que hacer.

    El `id` no es decorativo: `acciones.js` reabre los <details> que lo llevan
    después de repintar. Sin él, confirmar una matrícula cerraría el
    departamento entero y devolvería a quien está resolviendo veinte seguidas al
    principio de todo.
  --}}
  {{--
    LA PROMOTORÍA QUE SE PIDE ABIERTA, y su departamento con ella.

    Sirve para volver de una pantalla que se abrió desde aquí —crear o editar un
    grupo— sin que el Panel reaparezca cerrado y haya que buscar otra vez dónde
    se estaba. Antes volvía plegado del todo y lo primero que tocaba hacer era
    deshacer el camino.

    El departamento se abre TAMBIÉN: sin eso la promotoría queda abierta dentro
    de un plegado cerrado, o sea invisible, que es peor que dejarla cerrada
    porque parece que la orden no se obedeció.
  --}}
  @php($abrir = (int) request('abrir'))
  @php($unSoloDepartamento = $porDepartamento->count() === 1)
  @foreach ($porDepartamento as $departamento => $delDepartamento)
  @php($pendientesDelDepartamento = $delDepartamento->sum(fn ($p) => $pendientes[$p->id] ?? 0))
  <details class="panel-departamento" id="departamento-{{ \Illuminate\Support\Str::slug($departamento) }}"
           @if ($unSoloDepartamento || $delDepartamento->contains('id', $abrir)) open @endif>
    <summary class="panel-departamento-resumen">
      <span class="tag-dot {{ $delDepartamento->first()->area->tag_color }}"></span>{{ $departamento }}
      <span class="panel-departamento-cuenta">
        {{ $delDepartamento->count() }} {{ $delDepartamento->count() == 1 ? 'promotoría' : 'promotorías' }}
      </span>
      @if ($pendientesDelDepartamento)
        <span class="estado estado-pendiente">
          {{ $pendientesDelDepartamento }} {{ $pendientesDelDepartamento == 1 ? 'pendiente' : 'pendientes' }}
        </span>
      @endif
      <svg aria-hidden="true" class="perfil-seccion-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>
    </summary>
  @foreach ($delDepartamento as $promotoria)
  @php($cuantasPendientes = $pendientes[$promotoria->id] ?? 0)
  {{--
    El id no es decorativo: es lo que permite que la promotoría siga abierta
    después de confirmar una matrícula. Ver public/js/acciones.js.

    El cuerpo NO viene aquí: llega al desplegar, desde `data-cuerpo`. Antes iba
    dentro y el resultado era que un director descargaba el catálogo entero
    —cientos de KB con trescientos estudiantes— para ver una lista de títulos
    plegados. Sin JavaScript el enlace del final sigue llevando a la promotoría,
    así que la pantalla no deja de funcionar, solo deja de ser cómoda.
  --}}
  <details class="panel-item" id="promotoria-{{ $promotoria->id }}"
           data-cuerpo="{{ route('panel-promotoria-cuerpo', $promotoria) }}"
           @if ($promotoria->id === $abrir) open @endif>
    <summary class="panel-item-resumen">
      {{--
        El departamento YA NO se repite aquí: lo dice el <summary> del grupo que
        contiene a esta promotoría, y repetirlo en cada una de las veintiuna era
        la misma palabra veintiuna veces. El punto de color se queda, que es lo
        que permite reconocerlo de un vistazo sin leer.
      --}}
      <span class="tag-dot {{ $promotoria->area->tag_color }}"></span>{{ $promotoria->nombre }}
      @if ($cuantasPendientes)
        <span class="estado estado-pendiente">
          {{ $cuantasPendientes }} {{ $cuantasPendientes == 1 ? 'pendiente' : 'pendientes' }}
        </span>
      @endif
      <svg aria-hidden="true" class="perfil-seccion-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>
    </summary>
    {{--
      El cuerpo llega YA PUESTO en una sola promotoría: aquella sobre la que se
      acaba de actuar. Sin esto, repintar `<main>` deja su destino sin marcar,
      `panel.js` lo vuelve a pedir y cambiar una fila cuesta un tercer viaje.

      `data-cargado` es lo que se lo dice a `panel.js` — la MISMA marca que él
      pone al traerlo por su cuenta, para que no haya dos maneras de saber que un
      cuerpo ya está.
    --}}
    @php($cuerpoPuesto = ($cuerpoDe ?? null) === $promotoria->id)
    <div data-cuerpo-destino @if ($cuerpoPuesto) data-cargado="si" @endif>
      @if ($cuerpoPuesto)
        @include('panel.item', $cuerpo)
      @else
        <p class="vacio" data-cuerpo-cargando>Cargando…</p>
        <noscript>
          <p><a class="btn btn-sm" href="{{ route('panel-promotoria-cuerpo', $promotoria) }}">Ver {{ $promotoria->nombre }}</a></p>
        </noscript>
      @endif
    </div>
  </details>
  @endforeach
  </details>
  @endforeach
@endif
@endsection

@push('scripts')
{{--
  Va como archivo y fuera de <main> a propósito. Los scripts que viven dentro se
  vuelven a ejecutar en cada repintado sin recarga (ver acciones.js), y los que
  delegan en `document` acumularían un oyente por repintado. Aquí se carga una
  vez y sobrevive a los cambios de <main>, que es justo lo que necesita.
--}}
<script src="@recurso('js/lote.js')" defer></script>
<script src="@recurso('js/panel.js')" defer></script>
@endpush
