{{--
  El cuerpo de una promotoría dentro del Panel. Va aparte de `index` porque se
  repite una vez por promotoría y porque es lo que se repinta sin recargar.

  Ojo con los `@include` aquí dentro: esto es un bucle caliente. El Panel de un
  director con trescientos estudiantes pinta ~500 filas, y cada include de Blade
  —que resuelve la vista, comprueba la caché contra disco y monta un ámbito
  nuevo— cuesta a esa escala unos 0,4 ms. Tres por fila eran más de un segundo
  de render. Por eso la foto y el acudiente van escritos en línea aquí aunque
  existan como parciales para las otras pantallas, donde se pintan una vez o dos
  y la claridad manda sobre el reloj.
--}}
@php($esAdministrador = $yo->rol === 'administrador')

<p class="campo-info" style="margin-top:-0.6rem;">Profesor:
  @if ($item['promotoria']->profesor)
    @if (\App\Support\Permisos::puedeVerFicha($yo, $item['promotoria']->profesor))
      <a href="{{ route('detalle-usuario', $item['promotoria']->profesor) }}">{{ $item['promotoria']->profesor->nombre_completo }}</a>
    @else
      {{ $item['promotoria']->profesor->nombre_completo }}
    @endif
  @else
    Sin asignar
  @endif
</p>

@if ($periodo)
<div class="cupo-fila">
  <span class="cupo-etiqueta">Cupo en {{ $periodo->nombre }}</span>
  @if ($item['cupo'] === null)
    <span class="cupo-cifra cupo-cifra-libre">Sin tope</span>
  @else
    <span class="cupo-cifra{{ $item['ocupados'] >= $item['cupo'] ? ' cupo-cifra-lleno' : '' }}">{{ $item['ocupados'] }} / {{ $item['cupo'] }}</span>
  @endif
  @if ($item['puede_gestionar'])
  <form action="{{ route('panel-cupo-promotoria', $item['promotoria']) }}" method="post" class="cupo-form">
    @csrf
    <label class="cupo-form-label" for="cupo-{{ $item['promotoria']->id }}">Cambiar a</label>
    <input type="number" id="cupo-{{ $item['promotoria']->id }}" name="cupo_maximo" min="0" step="1"
           value="{{ $item['cupo'] }}" placeholder="sin tope">
    <button type="submit" class="btn btn-sm">Guardar</button>
  </form>
  @endif
</div>
@endif

@if ($item['puede_gestionar'])
<p><a class="btn btn-sm" href="{{ route('panel-grupo-nuevo', $item['promotoria']) }}">+ Nuevo grupo</a></p>
@endif

{{--
  La lista de esta promotoría en hoja de cálculo, para llevarla impresa o para
  llamar a las familias. El informe se acota solo a lo que quien pide puede ver,
  así que el enlace no necesita comprobar nada.
--}}
<p style="margin:0.6rem 0 0;">
  <a href="{{ route('informe-estudiantes', ['promotoria' => $item['promotoria']->id]) }}">
    Descargar lista de estudiantes (Excel)
  </a>
  {{-- Los carnés de toda la promotoría, ordenados por grupo. Solo administración. --}}
  @if ($esAdministrador)
    · <a href="{{ route('carnes-promotoria', $item['promotoria']) }}" data-carnes-promotoria>Imprimir carnés (PDF)</a>
  @endif
</p>
{{--
  El enlace de inscripción de esta promotoría y su QR (29/09/2026). Aquí solo
  el estado y la puerta: la pantalla con el QR es aparte, porque la imagen pesa
  y este cuerpo se pide cada vez que alguien despliega la promotoría.
--}}
@if ($item['puede_gestionar'])
<p style="margin:0.4rem 0 0;">
  <a href="{{ route('panel-enlace-promotoria', $item['promotoria']) }}">Enlace de inscripción y QR</a>
  <span class="lista-nota">· {{ $item['promotoria']->enlace_abierto ? 'encendido' : 'apagado' }}</span>
</p>
@endif

@php($totalPendientes = count($item['pendientes']))
<h4>Pendientes de confirmación{{ $totalPendientes ? " ({$totalPendientes})" : '' }}</h4>
@if (! $item['pendientes'])
  <p class="vacio">No hay solicitudes pendientes.</p>
@else
{{--
  Resolver en bloque. Al abrir matrículas llegan veinte solicitudes juntas y casi
  todas se responden igual; de a una son veinte idas y vueltas para una decisión
  ya tomada.

  Los dos botones van con el mismo peso visual que en la fila: confirmar en verde
  de acción y rechazar en rojo de retirada, sin que ninguno sea el «recomendado».

  El formulario va FUERA de la tabla y las casillas lo alcanzan con `form`, igual
  que en el reparto por grupo: cada fila ya tiene los suyos para responder a una
  sola, y anidar formularios es HTML inválido.
--}}
@php($lotePendientes = 'lote-pendientes-' . $item['promotoria']->id)
{{--
  El envoltorio NO es decorativo: acota hasta dónde se pega la barra de acciones
  en bloque. `position: sticky` se suelta al acabar su bloque contenedor, y sin
  esto la barra y la tabla son hermanas sueltas, así que el bloque es toda la
  sección: la barra se queda flotando encima de lo que venga después. En el
  Panel eso tapaba los encabezados de grupo —dos a la vez, medido en un ancho de
  teléfono— y con ellos sus botones. Con el envoltorio la barra no puede salir
  de su propia tabla, que es lo que se quería desde el principio.
--}}
<div class="lote-bloque">
@if ($item['puede_gestionar'] && count($item['pendientes']) > 1)
<form action="{{ route('panel-pendientes-lote', $item['promotoria']) }}" method="post"
      id="{{ $lotePendientes }}" class="lote-barra">
  @csrf
  <span class="lote-cuenta" data-lote-cuenta>Ninguno marcado</span>
  <button type="submit" name="decision" value="confirmar" class="btn btn-sm" data-lote-enviar disabled>
    Confirmar marcados
  </button>
  <button type="submit" name="decision" value="rechazar" class="btn btn-retirar btn-sm" data-lote-enviar disabled>
    Rechazar marcados
  </button>
</form>
@endif

{{--
  `data-col` en Telefono y Acudiente NO es decorativo y no cambia nada de lo que
  se ve en una pantalla ancha: es lo que permite esconder esas dos columnas
  entre 641 y 1000px, la banda donde esta tabla no cabe y lo que sobra queda al
  otro lado de un arrastre. La regla y el porque estan en app.css. Quien los
  quite creyendo que sobran no rompera nada que vaya a mirar.
  Las lleva esta tabla y la de «Sin grupo asignado», que son las dos que
  desbordan; la lista de un grupo cabe y las conserva.
--}}
<table class="tabla-personas" @if ($item['puede_gestionar'] && count($item['pendientes']) > 1) data-lote-tabla="{{ $lotePendientes }}" @endif>
  <thead>
    <tr>
      @if ($item['puede_gestionar'] && count($item['pendientes']) > 1)
      <th style="width:1%;">
        <input type="checkbox" data-lote-todos aria-label="Marcar todas las solicitudes pendientes">
      </th>
      @endif
      <th></th><th>Nombre</th><th>Trayectoria</th><th>Edad</th><th data-col="telefono">Teléfono</th><th data-col="acudiente">Acudiente</th>
      @if ($item['puede_gestionar'])<th></th>@endif
      @if ($esAdministrador)<th></th>@endif
    </tr>
  </thead>
  <tbody>
    @foreach ($item['pendientes'] as $e)
    <tr>
      @if ($item['puede_gestionar'] && count($item['pendientes']) > 1)
      <td data-celda="marca">
        <input type="checkbox" name="matricula_ids[]" value="{{ $e['matricula']->id }}"
               form="{{ $lotePendientes }}" data-lote-fila
               aria-label="Marcar la solicitud de {{ $e['perfil']->nombre_completo }}">
      </td>
      @endif
      <td data-celda="foto">@if ($e['perfil']->foto_perfil)<img class="foto-mini" src="{{ route('ver-foto', $e['perfil']) }}" alt="">@endif</td>
      <td data-celda="nombre">@include('panel.nombre', ['e' => $e])</td>
      <td data-label="Trayectoria">
        @if ($e['renovacion'])
          <span class="trayectoria trayectoria-renovacion">Renovación</span>
        @else
          <span class="trayectoria trayectoria-nuevo">Nuevo aquí</span>
        @endif
      </td>
      <td data-label="Edad">{{ $e['perfil']->edad }} años</td>
      <td data-col="telefono" data-label="Teléfono">{{ $e['perfil']->telefono }}</td>
      <td data-col="acudiente" data-label="Acudiente">@if ($e['acudiente']){{ $e['acudiente']->nombre }} ({{ $e['acudiente']->telefono }})@else<span class="vacio">—</span>@endif</td>
      @if ($item['puede_gestionar'])
      <td data-celda="accion">
        <span class="accion-fila">
        <form action="{{ route('panel-confirmar-matricula', $e['matricula']) }}" method="post" style="display:inline;">
          @csrf
          <button type="submit" class="btn btn-sm">Confirmar</button>
        </form>
        <form action="{{ route('panel-rechazar-matricula', $e['matricula']) }}" method="post" style="display:inline;">
          @csrf
          <button type="submit" class="btn btn-retirar btn-sm">Rechazar</button>
        </form>
        </span>
      </td>
      @endif
      @if ($esAdministrador)
      <td data-celda="accion"><a href="{{ route('detalle-estudiante', $e['perfil']) }}">Ver detalle</a></td>
      @endif
    </tr>
    @endforeach
  </tbody>
</table>
</div>
@endif

@foreach ($item['grupos'] as $g)
  <div class="grupo-cabecera">
    <h4 style="margin:1.2rem 0 0.4rem;">
      {{ $g['grupo']->rotulo }}
      ({{ count($g['estudiantes']) }}/{{ $g['grupo']->cupo_maximo }})
    </h4>
    @if ($item['puede_gestionar'])
    <span class="acciones">
      @if ($item['puede_marcar'])
      <form action="{{ route('panel-clase-nueva', $g['grupo']) }}" method="post" style="display:inline;">
        @csrf
        @if ($g['clase_hoy'])
          <button type="submit" class="btn btn-secundario btn-sm">Seguir la lista de hoy</button>
        @else
          <button type="submit" class="btn btn-sm">Iniciar clase</button>
        @endif
      </form>
      @endif
      <a href="{{ route('grupo-clases', $g['grupo']) }}">clases</a>
      {{--
        La lista de ESTE horario, que es la que se lleva impresa al salón. La de
        la promotoría entera está arriba; aquí abajo, con veinte filas en vez de
        doscientas, es la que se usa de verdad.
      --}}
      <a href="{{ route('informe-estudiantes', ['grupo' => $g['grupo']->id]) }}">lista (Excel)</a>
      @if ($esAdministrador)
      <a href="{{ route('carnes-grupo', $g['grupo']) }}">carnés (PDF)</a>
      @endif
      <a href="{{ route('panel-grupo-editar', $g['grupo']) }}">editar</a>
      <form action="{{ route('panel-grupo-eliminar', $g['grupo']) }}" method="post" style="display:inline;">
        @csrf
        <button type="submit" class="btn-texto">eliminar</button>
      </form>
    </span>
    @endif
  </div>
  @if (! $g['estudiantes'])
    <p class="vacio">Sin estudiantes en este grupo.</p>
  @else
  {{--
    La lista va PLEGADA. Con tres grupos de veinte, una promotoría abierta eran
    sesenta filas antes de llegar a «Sin grupo asignado», que es la sección donde
    de verdad se trabaja al empezar un periodo.

    Lo que NO se pliega es el encabezado de arriba con sus acciones: «Iniciar
    clase» es lo que se pulsa a diario, y esconderlo detrás de un clic habría
    cambiado un problema de sitio por uno de pasos.

    El id no es decorativo, igual que en la promotoría: es lo que permite que el
    grupo siga abierto después de quitar a alguien. Ver public/js/panel.js — y no
    `acciones.js`, que restaura los suyos ANTES de que este cuerpo se haya
    pedido, cuando estos <details> todavía no existen.
  --}}
  @php($cuantos = count($g['estudiantes']))
  <details class="grupo-lista" id="grupo-{{ $g['grupo']->id }}" data-grupo>
    <summary class="grupo-lista-resumen">
      {{ $cuantos }} {{ $cuantos == 1 ? 'estudiante' : 'estudiantes' }}
      <svg aria-hidden="true" class="perfil-seccion-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>
    </summary>
  <table class="tabla-personas">
    <thead>
      <tr>
        <th></th><th>Nombre</th><th>Edad</th><th>Teléfono</th><th>Acudiente</th>
        @if ($item['puede_gestionar'])<th></th>@endif
        @if ($esAdministrador)<th></th>@endif
      </tr>
    </thead>
    <tbody>
      @foreach ($g['estudiantes'] as $e)
      <tr>
        <td data-celda="foto">@if ($e['perfil']->foto_perfil)<img class="foto-mini" src="{{ route('ver-foto', $e['perfil']) }}" alt="">@endif</td>
        <td data-celda="nombre">@include('panel.nombre', ['e' => $e])</td>
        <td data-label="Edad">{{ $e['perfil']->edad }} años</td>
        <td data-label="Teléfono">{{ $e['perfil']->telefono }}</td>
        <td data-label="Acudiente">@if ($e['acudiente']){{ $e['acudiente']->nombre }} ({{ $e['acudiente']->telefono }})@else<span class="vacio">—</span>@endif</td>
        @if ($item['puede_gestionar'])
        <td data-celda="accion">
          <span class="accion-fila">
          {{--
            QUITAR DE ESTE GRUPO Y NO DE TODOS.

            Manda la lista de los OTROS grupos de esta matrícula, no un
            «grupo_id» vacío. Desde que alguien puede ir a dos horarios, vaciar
            la lista significa «sácalo de todos»: quitar del lunes se llevaría
            también el miércoles, sin fallar y sin avisar.

            El campo vacío de delante no sobra: si esta persona solo está en
            este grupo, no hay ningún otro que mandar y sin él no llegaría el
            campo — el servidor no podría distinguir «sácalo de todos» de «no
            llegó nada».
          --}}
          <form action="{{ route('panel-guardar-grupos', $e['matricula']) }}" method="post" style="display:inline;">
            @csrf
            <input type="hidden" name="grupo_id[]" value="">
            @foreach ($e['matricula']->grupos as $otro)
              @if ($otro->id !== $g['grupo']->id)
                <input type="hidden" name="grupo_id[]" value="{{ $otro->id }}">
              @endif
            @endforeach
            <button type="submit" class="btn btn-retirar btn-sm">Quitar de este grupo</button>
          </form>
          {{--
            Y el modal, que es donde se reparte en VARIOS. Va como enlace y no
            como control dentro de la celda por lo medido el 10/09: un
            `<select multiple>` sube la fila de 68 a 136 px en escritorio, y en
            escritorio se maneja con ctrl+clic — un clic normal borra la
            selección anterior.

            SOLO SI HAY MÁS DE UN GRUPO, y eso no es un detalle: con uno solo el
            modal enseñaría una casilla que hace lo mismo que el botón de al
            lado. Medido el 10/09: de las catorce promotorías con grupos, SIETE
            tienen uno solo — o sea que sin esta condición el enlace sería ruido
            en la mitad de las pantallas.

            El rótulo lleva VERBO, como su vecino, y en plural: decía «Grupos», y
            un sustantivo nombra una cosa en vez de decir qué pasa al pulsarlo.
          --}}
          @if (count($item['grupos']) > 1)
            <a href="{{ route('panel-grupos', $e['matricula']) }}" data-modal>Agregar a más grupos</a>
          @endif
          </span>
        </td>
        @endif
        @if ($esAdministrador)
        <td data-celda="accion"><a href="{{ route('detalle-estudiante', $e['perfil']) }}">Ver detalle</a></td>
        @endif
      </tr>
      @endforeach
    </tbody>
  </table>
  </details>
  @endif
@endforeach

<h4>Sin grupo asignado</h4>
@if (! $item['sin_grupo'])
  <p class="vacio">Todos los matriculados ya tienen grupo.</p>
@else

@php($conLote = $item['puede_gestionar'] && $item['grupos'])
{{--
  El envoltorio NO es decorativo: acota hasta dónde se pega la barra de acciones
  en bloque. `position: sticky` se suelta al acabar su bloque contenedor, y sin
  esto la barra y la tabla son hermanas sueltas, así que el bloque es toda la
  sección: la barra se queda flotando encima de lo que venga después. En el
  Panel eso tapaba los encabezados de grupo —dos a la vez, medido en un ancho de
  teléfono— y con ellos sus botones. Con el envoltorio la barra no puede salir
  de su propia tabla, que es lo que se quería desde el principio.
--}}
<div class="lote-bloque">
@if ($conLote)
{{--
  Repartir es la tarea del principio de periodo y casi todos van al mismo
  horario: de a uno son veinte idas y vueltas para una decisión ya tomada.

  El formulario va FUERA de la tabla y las casillas lo alcanzan con el atributo
  `form`. Es lo que evita anidar formularios —cada fila ya tiene el suyo para
  mandar a uno solo—, que es HTML inválido y el navegador lo desarma a su gusto.
--}}
<form action="{{ route('panel-asignar-grupo-lote', $item['promotoria']) }}" method="post"
      id="lote-{{ $item['promotoria']->id }}" class="lote-barra" data-lote>
  @csrf
  <span class="lote-cuenta" data-lote-cuenta>Ninguno marcado</span>
  <select name="grupo_id" required>
    <option value="">-- elegir grupo --</option>
    @foreach ($item['grupos'] as $g)
      <option value="{{ $g['grupo']->id }}">{{ $g['grupo']->rotulo_breve }} ({{ count($g['estudiantes']) }}/{{ $g['grupo']->cupo_maximo }})</option>
    @endforeach
  </select>
  <button type="submit" class="btn btn-sm" data-lote-enviar disabled>Asignar marcados</button>
</form>
@endif

<table class="tabla-personas" data-lote-tabla="lote-{{ $item['promotoria']->id }}">
  <thead>
    <tr>
      @if ($conLote)
      <th style="width:1%;">
        <input type="checkbox" data-lote-todos aria-label="Marcar a todos los que no tienen grupo">
      </th>
      @endif
      <th></th><th>Nombre</th><th>Edad</th><th data-col="telefono">Teléfono</th><th data-col="acudiente">Acudiente</th>
      @if ($item['puede_gestionar'])<th>Asignar a</th>@endif
      @if ($esAdministrador)<th></th>@endif
    </tr>
  </thead>
  <tbody>
    @foreach ($item['sin_grupo'] as $e)
    <tr>
      @if ($conLote)
      <td data-celda="marca">
        <input type="checkbox" name="matricula_ids[]" value="{{ $e['matricula']->id }}"
               form="lote-{{ $item['promotoria']->id }}" data-lote-fila
               aria-label="Marcar a {{ $e['perfil']->nombre_completo }}">
      </td>
      @endif
      <td data-celda="foto">@if ($e['perfil']->foto_perfil)<img class="foto-mini" src="{{ route('ver-foto', $e['perfil']) }}" alt="">@endif</td>
      <td data-celda="nombre">@include('panel.nombre', ['e' => $e])</td>
      <td data-label="Edad">{{ $e['perfil']->edad }} años</td>
      <td data-col="telefono" data-label="Teléfono">{{ $e['perfil']->telefono }}</td>
      <td data-col="acudiente" data-label="Acudiente">@if ($e['acudiente']){{ $e['acudiente']->nombre }} ({{ $e['acudiente']->telefono }})@else<span class="vacio">—</span>@endif</td>
      @if ($item['puede_gestionar'])
      <td data-celda="accion">
        @if ($item['grupos'])
        <form action="{{ route('panel-asignar-grupo', $e['matricula']) }}" method="post" style="display:flex;gap:0.4rem;">
          @csrf
          <select name="grupo_id">
            <option value="">-- elegir grupo --</option>
            @foreach ($item['grupos'] as $g)
              <option value="{{ $g['grupo']->id }}">{{ $g['grupo']->rotulo_breve }} ({{ count($g['estudiantes']) }}/{{ $g['grupo']->cupo_maximo }})</option>
            @endforeach
          </select>
          <button type="submit" class="btn btn-sm">Asignar</button>
        </form>
        @else
          <span class="vacio">Crea un grupo primero</span>
        @endif
      </td>
      @endif
      @if ($esAdministrador)
      <td data-celda="accion"><a href="{{ route('detalle-estudiante', $e['perfil']) }}">Ver detalle</a></td>
      @endif
    </tr>
    @endforeach
  </tbody>
</table>
</div>
@endif
