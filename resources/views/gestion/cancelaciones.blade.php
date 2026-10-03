@extends('layouts.app')

@section('title', 'Alertas y cancelaciones')

@section('content')
{{--
  LA BANDEJA DE LO QUE HAY QUE ATENDER.

  Eran solo las cancelaciones. Desde el 02/09/2026 lleva además dos alertas que
  no las pide nadie: el sistema las deduce cruzando lo que debería haber pasado
  con lo que hay registrado. Comparten pantalla porque comparten público y
  momento — es lo que dirección revisa cuando se sienta a ver qué falta.

  Las cancelaciones van PRIMERO y las alertas debajo, y ese orden no es
  alfabético: una cancelación es alguien esperando una respuesta que solo puede
  dar dirección, y mientras tanto ocupa un cupo. Una alerta es información.
--}}
<a href="{{ route('gestion-inicio') }}" class="volver">&larr; Gestión</a>
<h2>Alertas y cancelaciones</h2>

@if ($periodo)
  <p class="campo-ayuda" style="margin-bottom:1.4rem;">
    Las alertas se calculan sobre <strong>{{ $periodo->nombre }}</strong>, el periodo en
    curso. Se encienden y se apagan en Gestión → Institución.
  </p>
@endif

{{--
  LA TERCERA BANDEJA, y se entra por un BOTÓN.

  No es una sección desplegable como las dos de abajo, y eso salió de medir: en
  producción son ~810 personas de 839. Metidas aquí convertirían la pantalla en
  un muro — es lo que ya pasó con las 596 clases no dictadas.

  El desglose va debajo del botón porque un «810» a secas no dice si vale la pena
  entrar. Con él se ve de un vistazo que lo grande son dos cosas recién
  estrenadas que todavía no ha hecho nadie, y que lo accionable de verdad son las
  matrículas por aprobar y las que están sin grupo.
--}}
<div class="bandeja-entrada">
  <a href="{{ route('gestion-fichas-incompletas') }}" class="btn">
    Fichas por completar
    <span class="bandeja-cuenta">{{ $fichasIncompletas['total'] }}</span>
  </a>
  @if ($fichasIncompletas['total'])
    <p class="campo-ayuda bandeja-desglose">
      @foreach ($fichasIncompletas['porMotivo'] as $clave => $cuantas)
        @if ($cuantas)
          <a href="{{ route('gestion-fichas-incompletas', ['motivo' => $clave]) }}">{{ \App\Support\FichasIncompletas::MOTIVOS[$clave] }}: {{ $cuantas }}</a>@if (! $loop->last) · @endif
        @endif
      @endforeach
    </p>
  @else
    <p class="campo-ayuda bandeja-desglose">No falta nada por completar.</p>
  @endif
</div>

<h3>Cancelaciones por resolver</h3>
<p class="campo-ayuda" style="margin-bottom:1rem;">
  Mientras una cancelación esté aquí, el estudiante <strong>sigue matriculado</strong> y su
  cupo sigue ocupado. Aprobarla lo retira y libera el cupo.
</p>

@if (! count($pendientes))
  <p class="vacio">No hay cancelaciones pendientes.</p>
@else
{{--
  Resolver en bloque. Al cerrar un periodo esta cola se llena y casi todas se
  resuelven igual.

  «Rechazar marcadas» se pinta siempre, aunque en la selección haya mayores de
  edad: el servidor resuelve las que puede y dice por su nombre a quién no,
  porque esconder el botón según lo marcado obligaría a explicar en pantalla una
  regla que se entiende mucho mejor en la respuesta. Y sigue sin haber botón
  recomendado — en verde de acción, «Rechazar» empujaría a negar la salida de un
  niño, y esa decisión no la debe inclinar el sistema.
--}}
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
@if (count($pendientes) > 1)
<form action="{{ route('gestion-cancelaciones-lote') }}" method="post" id="lote-cancelaciones" class="lote-barra">
  @csrf
  <span class="lote-cuenta" data-lote-cuenta>Ninguno marcado</span>
  <button type="submit" name="decision" value="aprobar" class="btn btn-retirar btn-sm" data-lote-enviar disabled>
    Aprobar retiros marcados
  </button>
  <button type="submit" name="decision" value="rechazar" class="btn btn-secundario btn-sm" data-lote-enviar disabled>
    Rechazar marcadas
  </button>
</form>
@endif

<table @if (count($pendientes) > 1) data-lote-tabla="lote-cancelaciones" @endif>
  <thead>
    <tr>
      @if (count($pendientes) > 1)
      <th style="width:1%;">
        <input type="checkbox" data-lote-todos aria-label="Marcar todas las cancelaciones">
      </th>
      @endif
      <th>Estudiante</th>
      <th>Promotoría</th>
      <th>Periodo</th>
      <th>Acudiente</th>
      <th></th>
    </tr>
  </thead>
  <tbody>
    @foreach ($pendientes as $m)
    @php($acudiente = $m->estudiante->datosEstudiante?->acudiente)
    <tr>
      @if (count($pendientes) > 1)
      <td>
        <input type="checkbox" name="matricula_ids[]" value="{{ $m->id }}"
               form="lote-cancelaciones" data-lote-fila
               aria-label="Marcar la cancelación de {{ $m->estudiante->nombre_completo }}">
      </td>
      @endif
      <td>
        @if (\App\Support\Permisos::puedeVerFicha($yo, $m->estudiante))
          <a href="{{ route('detalle-usuario', $m->estudiante) }}">{{ $m->estudiante->nombre_completo }}</a>
        @else
          {{ $m->estudiante->nombre_completo }}
        @endif
        @if ($m->cancelacion_es_rechazable)
          <span class="estado estado-pendiente" style="margin-left:0.4rem;">Menor de edad</span>
        @endif
      </td>
      <td>
        <span class="tag-dot {{ $m->promotoria->area->tag_color }}"></span>{{ $m->promotoria->nombre }}
        <span class="historial-area">{{ $m->promotoria->area->nombre }}</span>
      </td>
      <td>{{ $m->periodo->nombre }}</td>
      <td>@include('panel.acudiente', ['acudiente' => $acudiente])</td>
      <td style="text-align:right;white-space:nowrap;">
        <span class="accion-fila">
          <form action="{{ route('gestion-resolver-cancelacion', [$m, 'aprobar']) }}" method="post" style="display:inline;">
            @csrf
            <button type="submit" class="btn btn-retirar btn-sm">Aprobar retiro</button>
          </form>
          {{--
            Rechazar solo cabe con menores: la pausa existe para hablar con el
            acudiente antes de que un niño se salga por su cuenta. A un mayor de
            edad no se le discute la decisión, así que el botón ni aparece — y el
            controlador lo vuelve a comprobar, porque ocultarlo no impide enviar
            el formulario a mano.

            Secundario y no primario: en verde de acción, «Rechazar» empujaría al
            director a negar la salida de un niño, y esa decisión no la debe
            inclinar el sistema. Ninguno de los dos botones es el recomendado.
          --}}
          @if ($m->cancelacion_es_rechazable)
          <form action="{{ route('gestion-resolver-cancelacion', [$m, 'rechazar']) }}" method="post" style="display:inline;">
            @csrf
            <button type="submit" class="btn btn-secundario btn-sm">Rechazar</button>
          </form>
          @endif
        </span>
      </td>
    </tr>
    @endforeach
  </tbody>
</table>
</div>
{{ $pendientes->links() }}
@endif

{{--
  ALERTA 1: las clases que un grupo tenía en su horario y no se dictaron.

  No se pueden arreglar —el martes 12 ya pasó— así que la acción es decir POR
  QUÉ (03/10/2026): excusa, falta o festivo. Es lo único de esta pantalla que se
  guarda. La falta le cuenta a quien dicta y le aparece en su Panel para
  reponerla; las otras dos no. Ver `OmisionArchivada::CAUSAS`.

  `.tabla-personas` porque bajo 640px esto tiene que dejar de ser tabla: la
  acción quedaría al otro lado de un arrastre.
--}}
<h3 style="margin-top:2.4rem;">Clases que no se dictaron</h3>
<p class="campo-ayuda" style="margin-bottom:1rem;">
  El grupo tenía clase ese día y nadie la registró. Quien dicta tiene todo el día
  para iniciarla y pasar lista; esto aparece al día siguiente. Di por qué no se dio:
  <strong>Excusa</strong> no se repone ni le cuenta como perdida;
  <strong>Falta</strong> le cuenta y le aparece en su Panel para reponerla;
  <strong>Festivo o cierre</strong> es un día en que la institución no tenía clase.
  @if ($faltasVencidas)
    <br><strong style="color:var(--danger);">{{ $faltasVencidas === 1 ? 'Una falta pasó' : $faltasVencidas.' faltas pasaron' }}
    el plazo de {{ $diasParaReponer }} {{ $diasParaReponer === 1 ? 'día' : 'días' }} sin reponerse.</strong>
    Están en «Ya atendidas», abajo.
  @endif
  @if ($omisionesTotales > $clasesNoDictadas->count())
    <br><strong>Hay {{ $omisionesTotales }} en total</strong> y se muestran las
    {{ $clasesNoDictadas->count() }} más recientes; las demás van apareciendo a
    medida que atiendas estas.
  @endif
</p>

{{--
  FESTIVO PARA TODOS (03/10/2026, pedido del usuario): un lunes festivo dejaba
  una alerta por cada grupo de los lunes. Una fecha y un botón marcan a todos
  los que tenían clase ese día de la semana —también una fecha futura, para que
  la alerta no llegue a salir—. No pisa lo que ya tenga causa ni toca a quien
  sí dio clase (ver `CancelacionesController::marcarFestivo`).

  Un formulario suelto y NO dentro de un `<details>`: plegado escondería su
  error, y este proyecto sostiene que sus pantallas funcionan sin JavaScript.
--}}
@if ($periodo && $alertaClases)
<form method="post" action="{{ route('gestion-marcar-festivo') }}" class="festivo-para-todos">
  @csrf
  <label class="config-etiqueta" for="fecha_festivo">Marcar un día como festivo o cierre para todos los grupos</label>
  <div class="festivo-para-todos-fila">
    <input type="date" name="fecha_festivo" id="fecha_festivo" required
           min="{{ $periodo->fecha_inicio->toDateString() }}" max="{{ $periodo->fecha_fin->toDateString() }}"
           value="{{ old('fecha_festivo') }}">
    <button type="submit" class="btn btn-secundario btn-sm">Marcar festivo o cierre</button>
  </div>
  @error('fecha_festivo')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
  <p class="campo-ayuda">
    Sirve también para un día que todavía no llega. Lo que ya tenga causa no se cambia.
  </p>
</form>
@endif

@if ($clasesNoDictadas->isEmpty())
  <p class="vacio">
    @if ($periodo)
      Ninguna clase quedó sin registrar en {{ $periodo->nombre }}.
    @else
      Ningún periodo está en curso, así que no hay horario que revisar.
    @endif
  </p>
@else
{{-- Con una sola no hay lote: los botones de la fila bastan. --}}
@php($loteOmisiones = $clasesNoDictadas->count() > 1)
<div class="lote-bloque">
@if ($loteOmisiones)
  @include('partials.lote-omisiones', ['loteId' => 'lote-omisiones'])
@endif
<table class="tabla-personas tabla-catalogo" @if ($loteOmisiones) data-lote-tabla="lote-omisiones" @endif>
  <thead>
    <tr>
      @if ($loteOmisiones)
      <th style="width:1%;">
        <input type="checkbox" data-lote-todos aria-label="Marcar todas las clases que no se dictaron">
      </th>
      @endif
      <th>Grupo</th>
      <th>Día</th>
      <th></th>
    </tr>
  </thead>
  <tbody>
    @foreach ($clasesNoDictadas as $falta)
    <tr>
      @if ($loteOmisiones)
      <td data-celda="marca">
        @include('partials.casilla-omision', ['loteId' => 'lote-omisiones'])
      </td>
      @endif
      <td data-celda="detalle">
        {{-- `nombre_con_nivel` y no `rotulo_breve`: ese trae ademas el horario
             semanal, y aqui al lado va la fecha concreta. «Martes 4:00 p.m. ·
             Martes 30/06» dice dos veces lo mismo. --}}
        <span class="tag-dot {{ $falta['grupo']->promotoria->area->tag_color }}"></span><span class="lista-nombre">{{ $falta['grupo']->nombre_con_nivel }}</span>
        <span class="lista-nota lista-nota-bloque">
          {{ $falta['grupo']->promotoria->nombre }} ·
          @if ($falta['grupo']->promotoria->profesor)
            {{ $falta['grupo']->promotoria->profesor->nombre_completo }}
          @else
            Sin profesor asignado
          @endif
        </span>
      </td>
      <td data-label="Día">
        {{ $falta['dia'] }} {{ $falta['fecha']->format('d/m/Y') }}
      </td>
      <td data-celda="accion" class="lista-acciones">
        <span class="accion-fila">
          @include('partials.clasificar-omision')
        </span>
      </td>
    </tr>
    @endforeach
  </tbody>
</table>
</div>
@endif

{{--
  LAS YA ATENDIDAS, para poder CORREGIR una causa equivocada. Sin esto, quien
  pulsa «Falta» en vez de «Excusa» deja al profesor con una perdida y una
  reposición que no le tocan, y no habría ninguna puerta para deshacerlo.

  Plegadas: no son trabajo pendiente. Llevan `id` para que `acciones.js` las
  deje abiertas tras corregir una — se corrige casi siempre más de una seguida.
  Las archivadas antes del 03/10/2026 salen «Sin clasificar» y siguen contando
  como perdidas hasta que alguien les ponga causa.
--}}
@if ($atendidasTotales > 0)
<details class="panel-departamento" id="omisiones-atendidas" style="margin-top:1rem;">
  <summary class="panel-departamento-resumen">
    Ya atendidas
    <span class="panel-departamento-cuenta">{{ $atendidasTotales }}</span>
    <svg aria-hidden="true" class="perfil-seccion-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>
  </summary>
  @if ($atendidasTotales > $omisionesAtendidas->count())
    <p class="campo-ayuda">Se muestran las {{ $omisionesAtendidas->count() }} más recientes.</p>
  @endif
  @php($loteAtendidas = $omisionesAtendidas->count() > 1)
  <div class="lote-bloque">
  @if ($loteAtendidas)
    @include('partials.lote-omisiones', ['loteId' => 'lote-atendidas'])
  @endif
  <table class="tabla-personas tabla-catalogo" @if ($loteAtendidas) data-lote-tabla="lote-atendidas" @endif>
    <thead>
      <tr>
        @if ($loteAtendidas)
        <th style="width:1%;">
          <input type="checkbox" data-lote-todos aria-label="Marcar todas las ya atendidas">
        </th>
        @endif
        <th>Grupo</th>
        <th>Día</th>
        <th>Causa</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      @foreach ($omisionesAtendidas as $falta)
      <tr>
        @if ($loteAtendidas)
        <td data-celda="marca">
          @include('partials.casilla-omision', ['loteId' => 'lote-atendidas'])
        </td>
        @endif
        <td data-celda="detalle">
          <span class="tag-dot {{ $falta['grupo']->promotoria->area->tag_color }}"></span><span class="lista-nombre">{{ $falta['grupo']->nombre_con_nivel }}</span>
          <span class="lista-nota lista-nota-bloque">
            {{ $falta['grupo']->promotoria->nombre }} ·
            {{ $falta['grupo']->promotoria->profesor?->nombre_completo ?? 'Sin profesor asignado' }}
          </span>
        </td>
        <td data-label="Día">{{ $falta['dia'] }} {{ $falta['fecha']->format('d/m/Y') }}</td>
        <td data-label="Causa">
          @include('partials.causa-omision')
        </td>
        <td data-celda="accion" class="lista-acciones">
          <span class="accion-fila">
            @include('partials.clasificar-omision')
          </span>
        </td>
      </tr>
      @endforeach
    </tbody>
  </table>
  </div>
</details>
@endif

{{--
  ALERTA 2: quien lleva demasiadas faltas seguidas sin excusa.

  Desaparece sola en cuanto el estudiante vuelve o alguien retira su matrícula,
  así que no hay nada que archivar. La acción retira de verdad: libera el cupo y
  la ranura, y por eso la aprieta una persona y no el sistema.
--}}
<h3 style="margin-top:2.4rem;">Posibles abandonos</h3>
<p class="campo-ayuda" style="margin-bottom:1rem;">
  Faltas seguidas <strong>sin excusa</strong> a las últimas clases de su grupo. Una
  excusa corta la racha. Nadie queda retirado hasta que alguien lo decida aquí.
</p>

@if ($abandonos->isEmpty())
  <p class="vacio">Nadie acumula faltas suficientes ahora mismo.</p>
@else
<table class="tabla-personas tabla-catalogo">
  <thead>
    <tr>
      <th>Estudiante</th>
      <th>Promotoría</th>
      <th class="num">Faltas</th>
      <th></th>
    </tr>
  </thead>
  <tbody>
    @foreach ($abandonos as $caso)
    @php($m = $caso['matricula'])
    <tr>
      <td data-celda="detalle">
        <span class="lista-nombre"><a href="{{ route('detalle-estudiante', $m->estudiante) }}">{{ $m->estudiante->nombre_completo }}</a></span>
        @if ($m->estudiante->datosEstudiante?->acudiente)
          <span class="lista-nota lista-nota-bloque">
            Acudiente: {{ $m->estudiante->datosEstudiante->acudiente->nombre_completo }}
            · {{ $m->estudiante->datosEstudiante->acudiente->telefono }}
          </span>
        @elseif ($m->estudiante->telefono)
          <span class="lista-nota lista-nota-bloque">Teléfono: {{ $m->estudiante->telefono }}</span>
        @endif
      </td>
      <td data-label="Promotoría">
        <span class="tag-dot {{ $m->promotoria->area->tag_color }}"></span>{{ $m->promotoria->nombre }}
        @foreach ($m->grupos as $g)<span class="lista-nota"> · {{ $g->rotulo_breve }}</span>@endforeach
      </td>
      <td class="num" data-label="Faltas">
        {{ $caso['faltas'] }}
        <span class="lista-nota lista-nota-bloque">desde el {{ $caso['desde']->format('d/m/Y') }}</span>
      </td>
      <td data-celda="accion" class="lista-acciones">
        <span class="accion-fila">
          <form method="post" action="{{ route('gestion-retirar-abandono', $m) }}">
            @csrf
            <button type="submit" class="btn btn-retirar btn-sm">Retirar la matrícula</button>
          </form>
        </span>
      </td>
    </tr>
    @endforeach
  </tbody>
</table>
@endif
@endsection

@push('scripts')
{{--
  Solo la casilla de «todos» y la cuenta de marcados. Sin JavaScript la pantalla
  funciona igual: se marcan las casillas a mano y se envía.
--}}
<script src="@recurso('js/lote.js')" defer></script>
@endpush
