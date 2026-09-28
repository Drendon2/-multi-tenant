{{--
  Historial de matrículas agrupado por periodo.

  Lo comparten «Mis matrículas» (el estudiante mirando lo suyo) y la trayectoria
  que consulta el personal, que son la misma información leída por dos públicos
  distintos.

  Contexto:
    $historial ......... salida de Matricula::historialPorPeriodo()
    $modo .............. "estudiante" o "personal"; cambia qué columnas se
                         muestran. El estudiante ya sabe quién le da clase pero
                         no cuándo se inscribió; el personal, al revés.
    $periodoActualId ... solo en modo estudiante, para ofrecer «Cancelar
                         matrícula» únicamente en el periodo en curso.
--}}
@php($modo = $modo ?? 'estudiante')
@php($periodoActualId = $periodoActualId ?? null)
{{--
  Corregir la promotoria solo lo ofrece la trayectoria que mira el personal, asi
  que las tres llegan con valor por defecto: «Mis matriculas» incluye este mismo
  parcial y no las pasa.
--}}
@php($puedeCorregir = $puedeCorregir ?? false)
@php($periodoEnCursoId = $periodoEnCursoId ?? null)
@php($promotoriasParaCorregir = $promotoriasParaCorregir ?? collect())
@php($motivoSinCorregir = $motivoSinCorregir ?? null)

@foreach ($historial as $bloque)
<section class="historial-bloque">
  <div class="historial-cabecera">
    <h3 class="historial-periodo">{{ $bloque['periodo']->nombre }}</h3>
    @if ($bloque['en_curso'])
      <span class="historial-marca">En curso</span>
    @else
      <span class="historial-fechas">
        {{ $bloque['periodo']->fecha_inicio->translatedFormat('M Y') }} –
        {{ $bloque['periodo']->fecha_fin->translatedFormat('M Y') }}
      </span>
    @endif
  </div>

  {{--
    `tabla-personas` no es decorativo: bajo 640px convierte cada fila en una
    ficha y saca las acciones a ancho completo. Sin eso, en un teléfono esta
    tabla se arrastra en horizontal y «Certificado» y «Corregir» quedan FUERA de
    la pantalla — la misma trampa que ya costó una vez en el Panel: una tabla
    que hay que arrastrar para pulsar el botón no es una tabla estrecha, es un
    formulario escondido. Aquí se puede aplicar porque esto es una lista de
    matrículas, no una rejilla donde la posición de la celda sea el dato.
  --}}
  <table class="tabla-personas">
    <thead>
      <tr>
        <th>Promotoría</th>
        @if ($modo === 'personal')<th>Profesor</th>@endif
        <th>Grupo</th>
        @if ($modo === 'estudiante')<th>Inscripción</th>@endif
        <th>Estado</th>
        <th></th>
        @if ($modo === 'estudiante')<th></th>@endif
        @if ($modo === 'personal')<th></th>@endif
      </tr>
    </thead>
    <tbody>
      @foreach ($bloque['matriculas'] as $m)
      <tr>
        <td data-celda="nombre">
          <span class="tag-dot {{ $m->promotoria->area->tag_color }}"></span>{{ $m->promotoria->nombre }}
          <span class="historial-area">{{ $m->promotoria->area->nombre }}</span>
        </td>

        @if ($modo === 'personal')
        <td data-label="Profesor">{{ $m->promotoria->profesor?->nombre_completo ?: '—' }}</td>
        @endif

        <td data-label="Grupo">
          @if ($m->grupos->isNotEmpty())
            @foreach ($m->grupos as $g)
              {{ $g->rotulo_breve }}@if (! $loop->last)<br>@endif
            @endforeach
          @elseif ($m->estado === \App\Models\Matricula::ACTIVA)
            <span class="vacio">Por asignar</span>
          @else
            <span class="vacio">—</span>
          @endif
        </td>

        @if ($modo === 'estudiante')
        <td class="num" data-label="Inscripción">{{ $m->fecha->format('d/m/Y') }}</td>
        @endif

        {{--
          `estado_visible` y no `estado`: una activa de un periodo cerrado se lee
          como «Finalizada». No es un valor guardado, se deduce del calendario.
        --}}
        <td data-label="Estado">
          <span class="estado estado-{{ $m->estado_visible }}">{{ $m->estado_visible_display }}</span>
        </td>

        {{--
          El certificado, en los dos modos: el estudiante baja el suyo y el
          personal se lo baja a quien lo pide en ventanilla. Solo aparece cuando
          hay algo que certificar —matrícula activa o finalizada— y quien mira
          tiene derecho a ella; una pendiente o una retirada no lo enseñan,
          porque no hay nada que un papel pueda afirmar de ellas.
        --}}
        <td class="historial-certificado" data-celda="accion">
          @php($certificable = in_array($m->estado_visible, [\App\Models\Matricula::ACTIVA, \App\Models\Matricula::FINALIZADA], true))
          @if ($certificable && \App\Support\Permisos::puedeCertificarMatricula($yo, $m))
            <a class="btn btn-secundario btn-sm" href="{{ route('certificado-matricula', $m) }}">
              Certificado
            </a>
          @endif
        </td>

        {{--
          Corregir la promotoría: el estudiante se inscribió en la que no era.
          Va plegado en un <details> nativo porque es una corrección rara y no
          puede pesar tanto como el certificado, que sí se pide a diario.

          El <details> lleva `id` a propósito: `acciones.js` reemplaza <main>
          sin recargar y solo sabe volver a abrir los que lo tienen. Sin él, al
          fallar la corrección el panel se cerraría y el aviso quedaría sin el
          formulario que lo explica.
        --}}
        @if ($modo === 'personal')
        <td class="historial-corregir" data-celda="accion">
          {{--
            DESHACER UN RECHAZO, y va antes que «Corregir» porque cuando existe
            es lo que se viene a hacer.

            Tiene su propia puerta y no la de la corrección de al lado: aquí
            puede también el profesor de la promotoría, que es quien rechaza y
            quien se equivoca al pulsar. Se comprueba por fila porque depende de
            QUÉ promotoría es; `promotoria` viene precargada, así que no cuesta
            una consulta por matrícula.

            Sin esto la pantalla es un callejón. Desde el 04/09/2026 al
            rechazado no se le vuelve a ofrecer «Matricularme» —ni el botón ni la
            URL—, y «Corregir promotoría» se niega a mover una matrícula a donde
            ya está: una solicitud rechazada en Piano no podría volver a Piano
            ese periodo por ningún camino.
          --}}
          @php($esRechazo = $m->estado === \App\Models\Matricula::RETIRADA && $m->motivo_retiro === \App\Models\Matricula::RETIRO_RECHAZO)
          @if ($esRechazo && $m->periodo_id === $periodoEnCursoId && \App\Support\Permisos::puedeGestionarPromotoria($yo, $m->promotoria))
          <form action="{{ route('deshacer-rechazo', $m) }}" method="post" class="deshacer-rechazo">
            @csrf
            <button type="submit" class="btn btn-secundario btn-sm">Deshacer el rechazo</button>
          </form>
          @endif
          {{--
            READMITIR en la misma promotoría (28/09/2026): quien canceló por
            accidente no tenía vuelta, porque «Cambiar» no ofrece la promotoría
            en la que ya está. Con la puerta de la corrección. En un rechazo no
            se pinta: ahí ya está «Deshacer el rechazo», que hace lo mismo.
          --}}
          @if ($puedeCorregir && $m->periodo_id === $periodoEnCursoId && $m->estado === \App\Models\Matricula::RETIRADA && ! $esRechazo && \App\Support\Permisos::veLaPromotoria($yo, $m->promotoria))
          <form action="{{ route('readmitir-matricula', $m) }}" method="post" class="deshacer-rechazo">
            @csrf
            <button type="submit" class="btn btn-secundario btn-sm">Readmitir aquí</button>
          </form>
          @endif
          @if ($puedeCorregir && $m->periodo_id === $periodoEnCursoId && \App\Support\Permisos::veLaPromotoria($yo, $m->promotoria))
          {{--
            Una retirada TAMBIÉN se mueve: quien se salió y quiere entrar a otra
            no está corrigiendo un dato viejo, está entrando. Por eso el rótulo
            cambia — «Corregir» no describe readmitir a alguien.
          --}}
          @php($esRetirada = $m->estado === \App\Models\Matricula::RETIRADA)
          <details class="corregir" id="corregir-{{ $m->id }}">
            <summary class="corregir-abrir">{{ $esRetirada ? 'Cambiar' : 'Corregir' }}</summary>
            <form action="{{ route('corregir-promotoria', $m) }}" method="post" class="corregir-form">
              @csrf
              <label class="sr-solo" for="corregir-destino-{{ $m->id }}">Promotoría correcta</label>
              {{--
                La promotoría actual NO se ofrece: no se mueve una matrícula a
                donde ya está, y dejarla en la lista —además preseleccionada—
                hacía que el botón por defecto no hiciera nada más que sacar un
                aviso. Se filtra ANTES de agrupar, así ningún <optgroup> queda
                vacío. El controlador conserva su comprobación igualmente: la
                lista se pinta aquí, pero el id lo manda el navegador.
              --}}
              @php($destinos = $promotoriasParaCorregir->where('id', '!=', $m->promotoria_id))
              <select name="promotoria_id" id="corregir-destino-{{ $m->id }}" required>
                <option value="">Elegir promotoría…</option>
                @foreach ($destinos->groupBy('area.nombre') as $areaNombre => $delArea)
                <optgroup label="{{ $areaNombre }}">
                  @foreach ($delArea as $p)
                    <option value="{{ $p->id }}">{{ $p->nombre }}</option>
                  @endforeach
                </optgroup>
                @endforeach
              </select>
              <p class="corregir-aviso">
                @if ($esRetirada)
                  Vuelve a entrar y queda pendiente de que la confirme quien la dicta.
                  Ocupa de nuevo un cupo del estudiante.
                @else
                  Pierde el grupo asignado y vuelve a quedar pendiente de que la confirme quien la dicta.
                @endif
              </p>
              <button type="submit" class="btn btn-sm">
                {{ $esRetirada ? 'Mover y readmitir' : 'Mover matrícula' }}
              </button>
            </form>
          </details>
          @elseif ($motivoSinCorregir && $m->periodo_id === $periodoEnCursoId)
            {{-- Dice por que no hay boton, en vez de dejar el hueco. --}}
            <span class="clase-mia">{{ $motivoSinCorregir }}</span>
          @endif
        </td>
        @endif

        @if ($modo === 'estudiante')
        <td data-celda="accion">
          @if ($m->periodo_id !== $periodoActualId)
            <span class="periodo-terminado">Periodo terminado</span>
          @elseif ($m->cancelacion_pendiente)
            {{--
              Mientras la dirección no resuelva, la decisión sigue siendo suya:
              quien canceló por accidente se echa atrás aquí (28/09/2026). Siendo
              mayor, la dirección solo puede aprobar, así que sin este botón el
              accidente acababa en retiro sí o sí.
            --}}
            <span class="periodo-terminado">Cancelación en trámite</span>
            <form action="{{ route('mis-matriculas.seguir', $m) }}" method="post">
              @csrf
              <button type="submit" class="btn btn-secundario btn-sm">Seguir matriculado</button>
            </form>
          @elseif (in_array($m->estado, [\App\Models\Matricula::ACTIVA, \App\Models\Matricula::PENDIENTE], true))
            {{--
              Una matricula ACTIVA pasa por la pantalla de salida, que pide la
              encuesta; una PENDIENTE no, porque nunca tuvo clase y no hay nada
              que valorar — ahi el boton sigue siendo inmediato, como era.
            --}}
            @if ($m->estado === \App\Models\Matricula::ACTIVA)
              <a class="btn btn-retirar btn-sm" href="{{ route('mis-matriculas.confirmar-retiro', $m) }}">
                Cancelar matrícula
              </a>
            @else
              <form action="{{ route('mis-matriculas.retirar', $m) }}" method="post">
                @csrf
                <button type="submit" class="btn btn-retirar btn-sm">Retirar solicitud</button>
              </form>
            @endif
          @endif
        </td>
        @endif
      </tr>
      @endforeach
    </tbody>
  </table>
</section>
@endforeach
