@extends('layouts.app')

@section('title', $objetivo->nombre_completo)

@section('content')
<a href="{{ route('panel') }}" class="volver">&larr; Volver al panel</a>
<h2>Ficha de usuario</h2>

<div class="carne" style="margin-bottom:1.2rem;">
  @if ($objetivo->foto_perfil)
    <img class="carne-foto" style="width:64px;height:64px;" src="{{ route('ver-foto', $objetivo) }}" alt="">
  @else
    <div class="carne-foto-vacia" style="width:64px;height:64px;"></div>
  @endif
  <div class="carne-datos">
    <div class="carne-nombre" style="font-size:1.1rem;">
      {{ $objetivo->nombre_completo }}
      @if ($papelesPendientes)
      @php($faltan = count($papelesPendientes))
      <span class="estado estado-papeles"
            title="Le faltan: {{ implode(', ', array_map(fn ($d) => $d->nombre, $papelesPendientes)) }}">
        Faltan {{ $faltan }} {{ $faltan == 1 ? 'papel' : 'papeles' }}
      </span>
      @endif
    </div>
    <div class="carne-detalle">
      {{ $objetivo->user->username }} ·
      {{ $objetivo->rol ? $objetivo->rol_display : 'Pendiente de rol' }} ·
      {{ $objetivo->user->activo ? 'Activo' : 'Inactivo' }}
    </div>
  </div>
</div>

@if (! $objetivo->rol)
  <p class="aviso">
    Esta cuenta se creó por autorregistro y todavía no tiene rol asignado, así que su
    titular no puede entrar al sistema. Asígnaselo desde Gestión &rarr; Usuarios.
  </p>
@endif

<h3>Contacto</h3>
@if ($veContacto)
<table style="max-width:520px;">
  {{--
    La edad es un dato DEL ESTUDIANTE y solo de él: de ahí salen la minoría de
    edad, la exigencia de acudiente y el nivel que le corresponde a un grupo.
    En el personal no la usa nadie para nada y preguntarla, o exhibirla, se lee
    aquí como una falta de respeto. Su propia edad la sigue viendo cada quien en
    Mi perfil, que es el único sitio donde queda.

    Se aparta del Django a propósito: `detalle_usuario.html` la pinta para
    cualquier rol.
  --}}
  @if (! $objetivo->esPersonal())
  <tr><th>Edad</th><td>{{ $objetivo->edad }} años</td></tr>
  @endif
  <tr><th>Teléfono</th><td>{{ $objetivo->telefono }}</td></tr>
  {{--
    El correo va con el resto del contacto y bajo la misma puerta: es un dato de
    contacto, no de identidad, así que lo ve quien ve el teléfono. Un campo que
    se puede rellenar y no se ve en ninguna parte no sirve para nada.
  --}}
  <tr>
    <th>Correo</th>
    <td>@if ($objetivo->user->email){{ $objetivo->user->email }}@else<span class="vacio">—</span>@endif</td>
  </tr>
  @if ($esEstudiante)
  <tr>
    <th>Acudiente</th>
    <td>
      @if ($acudiente)
        {{ $acudiente->nombre }} ({{ $acudiente->telefono }})
      @elseif ($objetivo->es_menor)
        <span class="vacio">Sin acudiente registrado — es menor de edad</span>
      @else
        <span class="vacio">—</span>
      @endif
    </td>
  </tr>
  @endif
</table>
@else
  {{--
    Ver la matriz de visibilidad en `Perfil`: la edad, el teléfono y el acudiente
    de un estudiante son del administrador, del director y del profesor de SUS
    promotorías. Poder abrir la ficha no los desbloquea.
  --}}
  <p class="vacio">
    Edad, teléfono y acudiente solo los ve el personal a cargo de este estudiante.
  </p>
@endif

@if ($esEstudiante)
<h3>Trayectoria</h3>
@if ($resumen['periodos'])
<div class="dash-resumen historial-resumen">
  <div>
    <span class="dash-stat-num">{{ $resumen['periodos'] }}</span>
    <span class="dash-stat-label">
      {{ $resumen['periodos'] == 1 ? 'Periodo cursado' : 'Periodos cursados' }}
    </span>
  </div>
  <div>
    <span class="dash-stat-num">{{ $resumen['promotorias'] }}</span>
    <span class="dash-stat-label">{{ $resumen['promotorias'] == 1 ? 'Promotoría' : 'Promotorías' }}</span>
  </div>
  @if ($resumen['desde'])
  <div>
    <span class="dash-stat-num">{{ $resumen['desde']->nombre }}</span>
    <span class="dash-stat-label">Desde</span>
  </div>
  @endif
</div>
@else
  <p class="vacio">Todavía no ha cursado ningún periodo.</p>
@endif
<p>
  <a class="btn btn-secundario btn-sm" href="{{ route('historial-estudiante', $objetivo) }}">
    Ver trayectoria completa
  </a>
  {{--
    El certificado que reúne todas las promotorías vigentes. No se le ofrece al
    profesor —ni siquiera al que tiene a este estudiante en clase— porque lista
    también las promotorías que esta ficha le esconde. El de UNA matrícula, que
    es el que a él le pueden pedir, está en la trayectoria.
  --}}
  @if (\App\Support\Permisos::puedeCertificarTodo($yo, $objetivo))
  <a class="btn btn-secundario btn-sm" href="{{ route('certificado-todo', $objetivo) }}">
    Certificado de matrícula
  </a>
  @endif
  @if ($yo->rol === 'administrador')
  <a class="btn btn-secundario btn-sm" href="{{ route('detalle-estudiante', $objetivo) }}">
    Ver encuesta y documento
  </a>
  {{--
    El carné con el que le pasan lista con la cámara. SOLO al administrador, ni
    siquiera al profesor que lo tiene en su lista: es la llave con la que a esta
    persona se le marca asistencia, y quien pasa lista es a quien esas marcas
    vigilan. Está escrito entero en `CarneController`.
  --}}
  <a class="btn btn-secundario btn-sm" href="{{ route('carne-estudiante', $objetivo) }}">
    Carné con código
  </a>
  @endif
</p>
@endif

{{--
  Sale del vínculo, no del rol: un director que además dicta tiene las suyas.
  Al profesor se le enseña la sección aunque esté vacía, porque ahí el hueco es
  el dato — a nadie más le dice nada.
--}}
@if ($objetivo->rol === 'profesor' || count($promotorias))
<h3>Promotorías a cargo</h3>
@if (! count($promotorias))
  <p class="vacio">No tiene promotorías asignadas.</p>
@else
<table>
  <thead>
    <tr><th>Promotoría</th><th>Departamento</th><th>Grupos</th></tr>
  </thead>
  <tbody>
    @foreach ($promotorias as $p)
    <tr>
      <td><span class="tag-dot {{ $p->area->tag_color }}"></span>{{ $p->nombre }}</td>
      <td>{{ $p->area->nombre }}</td>
      <td>
        @forelse ($p->grupos as $g)
          {{ $g->rotulo_breve }}@if (! $loop->last)<br>@endif
        @empty
          <span class="vacio">Sin grupos creados</span>
        @endforelse
      </td>
    </tr>
    @endforeach
  </tbody>
</table>
@endif
@endif

{{--
  CURSOS, TALLERES Y GRUPOS DE PROYECCIÓN A CARGO.

  Faltaban del todo hasta el 06/09/2026: la ficha decía «Promotorías a cargo» y
  una actividad asignada no salía por ningún lado, así que asignarle un taller a
  alguien no cambiaba nada de lo que se ve aquí. Cuelgan de `responsable_id` y
  no de `promotorias.profesor_id`, que es justo por lo que se cayeron.

  A DIFERENCIA de la sección de arriba, esta NO se enseña vacía: allí el hueco
  es el dato —un profesor sin promotorías es algo que hay que ver— y aquí no
  dice nada, porque una institución puede no usar cursos ni grupos de proyección
  en absoluto.

  Cada una lleva su `.tipo-chip`, que es lo que las distingue entre sí y de una
  promotoría sin depender de dónde esté puesta la tabla.
--}}
@if (count($actividades))
<h3>Cursos, talleres y grupos de proyección a cargo</h3>
<table>
  <thead>
    <tr><th>Nombre</th><th>Tipo</th><th class="num">Inscritos</th><th class="num">Sesiones</th></tr>
  </thead>
  <tbody>
    @foreach ($actividades as $a)
    <tr>
      <td><a href="{{ route('panel-actividad', $a) }}">{{ $a->nombre }}</a></td>
      <td><span class="tipo-chip">{{ $a->etiquetaTipo() }}</span></td>
      <td class="num"><span class="cupo-cifra">{{ $a->inscritos_count }}</span></td>
      <td class="num"><span class="cupo-cifra">{{ $a->sesiones_count }}</span></td>
    </tr>
    @endforeach
  </tbody>
</table>
@endif

{{--
  Solo si hay algo que contar. Un panel de ceros no informa de nada y además
  miente por omisión: en una ficha sin clases todavía no se distingue "no ha
  faltado nunca" de "no ha empezado el periodo".
--}}
@if ($asistencia)
  @include('partials.panel-asistencia', [
    'asistencia' => $asistencia,
    'periodo' => $periodo,
    'periodoAtras' => $periodoAtras,
    'periodoAdelante' => $periodoAdelante,
    'periodoEsElEnCurso' => $periodoEsElEnCurso,
  ])
@endif

@if ($puedeGestionarUsuarios)
<p style="margin-top:1.5rem;">
  <a class="btn btn-sm" href="{{ route('usuario-editar', $objetivo) }}">Editar usuario</a>
</p>
@endif

{{--
  GESTIÓN ASISTIDA desde la ficha, añadido el 04/09/2026 a petición del usuario.

  Es el camino natural: cuando alguien escribe por WhatsApp diciendo que no
  encuentra un botón, se llega a su ficha por su nombre desde cualquier listado.
  La otra puerta —Gestión → Usuarios— obliga a filtrar por rol primero, porque
  ese listado ordena por rol y pagina de 50: los profesores caen en la página
  cinco y parecía que la opción solo existía para directores.

  Va con `post` porque cambia de sesión, no porque navegue. Y la comprobación es
  la misma función que vuelve a mirar el controlador: aquí decide si se PINTA,
  allí si se PUEDE.
--}}
@if (\App\Support\GestionAsistida::puedeAsistirA($yo, $objetivo))
{{--
  `data-recarga-completa` porque esto cambia la IDENTIDAD de la sesión.
  `acciones.js` intercepta los formularios de dentro de `<main>` y repinta solo
  `<main>`; la barra de gestión asistida y el menú viven FUERA, así que sin este
  atributo la sesión cambia y la pantalla no se entera hasta que el navegador
  navegue de verdad. Se notó pulsando «Mi perfil», que era lo primero que lo
  provocaba.
--}}
<form action="{{ route('gestion-asistida-iniciar', $objetivo) }}" method="post" style="margin-top:1.5rem;"
      data-recarga-completa>
  @csrf
  <button type="submit" class="btn btn-secundario btn-sm">
    Gestionar como {{ $objetivo->nombre_completo }}
  </button>
</form>
@endif
@endsection

@push('scripts')
{{-- Solo el gesto de deslizar entre periodos. Las flechas funcionan sin esto. --}}
<script src="@recurso('js/periodo.js')" defer></script>
@endpush
