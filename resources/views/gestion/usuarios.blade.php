@extends('layouts.app')

@section('title', 'Usuarios')

@section('content')
<a href="{{ route('gestion-inicio') }}" class="volver">&larr; Gestión</a>
<h2>Usuarios</h2>
{{-- En modal como «Editar»: es el mismo formulario, y que se abriera de dos
     formas distintas segun la puerta se leeria como un fallo. --}}
<p><a class="btn" href="{{ route('usuario-nuevo') }}" data-modal>+ Nuevo usuario</a></p>

{{--
  El enlace de registro de profesor, que ya NO está en la pantalla de inicio de
  sesión.

  Estuvo ahí, debajo del de estudiante, y la gente se equivocaba de puerta: quien
  entraba por la de profesor quedaba sin rol, sin documento y sin matrícula.
  Ahora es un enlace que dirección manda a quien va a dictar, igual que el de una
  actividad; la ruta sigue abierta, lo que se quitó es el letrero público.

  Plegado y no a la vista: se usa el día que entra un profesor nuevo, no todos
  los días, y esta pantalla ya llega cargada de filtros en un celular.
--}}
<details class="perfil-seccion" style="max-width:none;margin:0 0 1.2rem;">
  <summary class="perfil-seccion-cabecera" style="margin:0;">
    <span class="perfil-seccion-icono icono-documento" aria-hidden="true">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/>
        <path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/>
      </svg>
    </span>
    <h3 style="margin:0;">El enlace para registrar a un profesor</h3>
    <svg aria-hidden="true" class="perfil-seccion-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>
  </summary>

  {{--
    `.campo-ayuda` y no `.campo-info`: esa trae un margen superior NEGATIVO
    —está pensada para ir pegada bajo un título, que aporta el suyo inferior— y
    dentro de un `<details>` se sube encima del resumen. Era el margen escrito a
    mano que había aquí; ahora la solución tiene nombre y sirve para las cuatro
    pantallas donde hizo falta.
  --}}
  <p class="campo-ayuda" style="margin-bottom:0.9rem;">
    Mándaselo a quien va a dictar. Crea su cuenta y elige su propia contraseña;
    queda <strong>pendiente de rol</strong> hasta que se lo asignes aquí.
  </p>
  <label class="sr-solo" for="enlace_registro">Enlace de registro de profesor</label>
  <div class="enlace-fila">
    <input class="enlace-copiable" type="text" id="enlace_registro"
           readonly value="{{ route('registro') }}">
  </div>
</details>

{{--
  EL BUSCADOR FUERA, LOS CINCO DESPLEGABLES PLEGADOS.

  Medido a 390px: la tarjeta de filtros medía 526px y la primera fila de datos
  empezaba en y=954 sobre una pantalla de 844 — una pantalla entera de controles
  antes de ver a una sola persona, en el dispositivo donde más se usa esto.

  El buscador se queda a la vista porque es el camino directo a una persona
  concreta y lo más frecuente; los cinco desplegables acotan un conjunto y se
  tocan de vez en cuando. El resumen dice cuántos hay puestos, para que plegado
  no acabe significando escondido.
--}}
<form method="get">
  {{--
    El buscador va PRIMERO: es el camino directo a una persona concreta, y los
    otros cinco son filtros que acotan un conjunto. Buscar por nombre o usuario
    y nada mas — el documento y el telefono no se buscan a proposito, ver el
    comentario del controlador.
  --}}
  <div class="filtros filtros-solo-buscar">
    <div class="filtro filtro-buscar">
      <label for="f-buscar">Buscar</label>
      <input type="search" name="buscar" id="f-buscar"
             value="{{ $seleccion['buscar'] }}"
             placeholder="Nombre, usuario o documento"
             autocomplete="off">
      {{--
        El documento se busca ENTERO y el nombre por partes, y eso hay que
        decirlo: si no, quien teclea «1017» y no encuentra nada concluye que la
        persona no está. El porqué de la asimetría está en el controlador —una
        búsqueda por partes dejaría confirmar la cédula de un menor probando
        cifras—.
      --}}
      <p class="campo-ayuda">
        El nombre y el usuario se buscan por partes. El documento, entero.
      </p>
    </div>
  </div>

  {{--
    Cuántos hay puestos, para el rótulo del pliegue. El periodo cuenta solo si
    NO es el que está en curso: siempre trae valor, así que contarlo tal cual
    diría «1 puesto» en una pantalla sin filtrar nada.
  --}}
  @php($cuantosFiltros = collect([$seleccion['rol'], $seleccion['area'], $seleccion['promotoria'], $seleccion['grupo'], $seleccion['papeles']])->filter()->count()
      + (($seleccion['periodo'] && ! $seleccion['periodo']->activo) ? 1 : 0))
  <details class="filtros-plegables">
    <summary class="filtros-resumen">
      Filtros
      @if ($cuantosFiltros)
        <span class="filtros-cuenta">{{ $cuantosFiltros }} {{ $cuantosFiltros == 1 ? 'puesto' : 'puestos' }}</span>
      @endif
      <svg class="perfil-seccion-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
    </summary>
    <div class="filtros">

  <div class="filtro">
    <label for="f-rol">Rol</label>
    <select name="rol" id="f-rol">
      <option value="">Todos</option>
      @foreach ($roles as $valor => $etiqueta)
        <option value="{{ $valor }}" @selected($seleccion['rol'] === $valor)>{{ $etiqueta }}</option>
      @endforeach
      <option value="{{ $rolPendiente }}" @selected($seleccion['rol'] === $rolPendiente)>
        Pendiente de rol
      </option>
    </select>
  </div>

  {{--
    A QUIÉN LE FALTA UN PAPEL. Nace el 06/09/2026 porque no había forma de
    saberlo: Institución dice cuántos han entregado, no quiénes, y la única
    alternativa era abrir la ficha de cada estudiante — entre 800, eso es
    adivinar. Se volvió urgente el mismo día, al nacer obligatorio el
    consentimiento de tratamiento de datos.

    Va aquí arriba, justo detrás del rol, porque es un filtro de PERSECUCIÓN: se
    usa para armar una lista de a quién llamar, no para mirar un catálogo.

    NO expone ningún dato restringido —dice quién no ha entregado, no qué
    entregó ni qué dice—, que es lo que separa esto del buscador de al lado: ahí
    el comentario del controlador explica por qué no se puede buscar por
    documento ni por teléfono.
  --}}
  @if ($papeles->isNotEmpty())
  <div class="filtro">
    <label for="f-papeles">Papeles</label>
    <select name="papeles" id="f-papeles">
      <option value="">Todos</option>
      <option value="{{ $papelesObligatorios }}" @selected($seleccion['papeles'] === $papelesObligatorios)>
        Le falta alguno obligatorio
      </option>
      @foreach ($papeles as $papel)
        <option value="{{ $papel->id }}" @selected($seleccion['papeles'] === (string) $papel->id)>
          Le falta: {{ $papel->nombre }}
        </option>
      @endforeach
    </select>
  </div>
  @endif

  <div class="filtro">
    <label for="f-area">Departamento</label>
    <select name="area" id="f-area">
      <option value="">Todos</option>
      @foreach ($areas as $area)
        <option value="{{ $area->id }}" @selected($seleccion['area']?->id === $area->id)>{{ $area->nombre }}</option>
      @endforeach
    </select>
  </div>

  <div class="filtro">
    <label for="f-promotoria">Promotoría</label>
    <select name="promotoria" id="f-promotoria">
      <option value="">Todas</option>
      @foreach ($promotorias->groupBy('area.nombre') as $areaNombre => $delArea)
      <optgroup label="{{ $areaNombre }}">
        @foreach ($delArea as $p)
          <option value="{{ $p->id }}" @selected($seleccion['promotoria']?->id === $p->id)>{{ $p->nombre }}</option>
        @endforeach
      </optgroup>
      @endforeach
    </select>
  </div>

  <div class="filtro">
    <label for="f-grupo">Grupo</label>
    <select name="grupo" id="f-grupo">
      <option value="">Todos</option>
      @foreach ($grupos->groupBy('promotoria.nombre') as $promotoriaNombre => $deLaPromotoria)
      <optgroup label="{{ $promotoriaNombre }}">
        @foreach ($deLaPromotoria as $g)
          <option value="{{ $g->id }}" @selected($seleccion['grupo']?->id === $g->id)>
            {{ $g->rotulo_breve }}
          </option>
        @endforeach
      </optgroup>
      @endforeach
    </select>
  </div>

  <div class="filtro">
    <label for="f-periodo">Periodo</label>
    <select name="periodo" id="f-periodo">
      @foreach ($periodos as $p)
        <option value="{{ $p->id }}" @selected($seleccion['periodo']?->id === $p->id)>
          {{ $p->nombre }}@if ($p->activo) (en curso) @endif
        </option>
      @endforeach
    </select>
  </div>

    </div>
  </details>

  {{--
    Los dos botones FUERA del pliegue, no dentro: «Limpiar» es la salida de un
    filtro puesto y esconderlo detrás del mismo panel que hay que abrir para
    quitarlo sería dejar la salida dentro de la trampa. Y «Filtrar» sirve
    también al buscador, que vive arriba.
  --}}
  <div class="filtro filtro-acciones filtros-botones">
    <button type="submit" class="btn btn-sm">Filtrar</button>
    @if ($hayFiltros)
      <a class="btn btn-blanco btn-sm" href="{{ route('usuario-lista') }}">Limpiar</a>
    @endif
  </div>
</form>

@if ($hayFiltros)
<p class="filtros-nota">
  {{ $perfiles->total() }} {{ $perfiles->total() == 1 ? 'usuario' : 'usuarios' }}
  @if ($seleccion['area'] || $seleccion['promotoria'] || $seleccion['grupo'])
    · matrículas de <strong>{{ $seleccion['periodo']?->nombre ?: 'ningún periodo' }}</strong>,
    sin contar las retiradas
  @endif
</p>
@endif

@if ($perfiles->isEmpty())
  @if ($hayFiltros)
    <p class="vacio">Ningún usuario coincide con estos filtros.</p>
  @else
    <p class="vacio">Todavía no hay usuarios.</p>
  @endif
@else
{{--
  `.tabla-personas` porque esto es una lista de REGISTROS de personas, que es
  justo su caso, y no una rejilla donde la posición de la celda sea el dato.

  Sin ella, a 390px la tabla mide 675 y se queda con `overflow-x`: había que
  arrastrarla 318px para llegar a «Editar» y «Desactivar», o sea que sus
  acciones estaban escondidas desde antes de que existiera «Eliminar». Se vio
  abriendo la página en un ancho de teléfono; ninguna prueba lo miraba.
--}}
<table class="tabla-personas tabla-menu-esquina">
  <thead>
    <tr>
      <th>Nombre</th>
      <th>Usuario</th>
      <th>Rol</th>
      <th>Promotorías</th>
      <th>Estado</th>
      <th></th>
    </tr>
  </thead>
  <tbody>
    {{-- Una vez y no una por fila: el rol de quien mira no cambia a mitad de la tabla. --}}
    @php($soyAdministrador = $yo?->rol === 'administrador')
    @foreach ($perfiles as $perfil)
    <tr>
      <td data-celda="nombre">
        @if (\App\Support\Permisos::puedeVerFicha($yo, $perfil))
          <a href="{{ route('detalle-usuario', $perfil) }}">{{ $perfil->nombre_completo }}</a>
        @else
          {{ $perfil->nombre_completo }}
        @endif
      </td>
      <td data-label="Usuario">{{ $perfil->user->username }}</td>
      <td data-label="Rol">
        @if ($perfil->rol)
          {{ $perfil->rol_display }}
        @else
          <span class="estado estado-pendiente">Pendiente de rol</span>
        @endif
      </td>
      {{--
        Una misma columna para dos vínculos distintos: quien DICTA la promotoría
        y quien está MATRICULADO en ella. Se lee igual en la lista —«a qué anda
        vinculada esta persona»— y separarlas en dos columnas dejaría cada una
        medio vacía, porque ninguna persona tiene las dos.
      --}}
      <td data-label="Promotorías">
        @php($dictadas = $perfil->promotoriasDictadas)
        @php($matriculas = $matriculasPorPerfil[$perfil->id] ?? collect())
        @if ($dictadas->isNotEmpty())
          @foreach ($dictadas as $p)
            <span class="tag-dot {{ $p->area->tag_color }}"></span>{{ $p->nombre }}@if (! $loop->last)<br>@endif
          @endforeach
        @elseif ($matriculas->isNotEmpty())
          @foreach ($matriculas as $m)
            <span class="tag-dot {{ $m->promotoria->area->tag_color }}"></span>{{ $m->promotoria->nombre }}@if (! $loop->last)<br>@endif
          @endforeach
        @else
          <span class="vacio">—</span>
        @endif
      </td>
      <td data-label="Estado">
        @if ($perfil->user->activo)
          <span class="estado estado-activa">Activo</span>
        @else
          <span class="estado estado-retirada">Inactivo</span>
        @endif
      </td>
      {{--
        La cuenta de un administrador solo la toca otro administrador. Se
        precalcula porque la usan las dos acciones de la fila.

        Va en la forma de UNA LÍNEA, con la asignación entre paréntesis, y no en
        la de bloque abierto y cerrado. Este archivo ya usa la de una línea más
        arriba, y Blade extrae los bloques de PHP crudo con una expresión regular
        perezosa ANTES de compilar ninguna directiva. Con las dos formas
        mezcladas en el mismo archivo, esa expresión abre en la primera de una
        línea y cierra en el cierre del bloque, se traga las veinticinco líneas
        que haya en medio y las deja sin compilar: el `if`, el `foreach` y el
        resto salen impresos como texto.

        Cuidado también con lo que se escribe AQUÍ DENTRO: un comentario que
        nombre esas dos directivas literalmente dispara la misma extracción y se
        acaba pintando el comentario entero en pantalla. Por eso este las
        describe en vez de escribirlas. Es prima hermana de la trampa de una
        directiva pegada a una letra.
      --}}
      @php($puedeTocarla = \App\Support\Permisos::puedeEditarUsuario($yo, $perfil))
      {{--
        Las tres acciones, en el menú de la fila.

        Antes iban sueltas, y en el teléfono eran el 45% del alto de cada ficha:
        dos botones a ancho completo y uno corto, con el borrado en rojo tirando
        del ojo. A esta pantalla se viene sobre todo a BUSCAR a alguien y a
        asignarle rol; desactivar y eliminar son de vez en cuando.

        Cada una puede no estar: a la cuenta de uno mismo no se le hace nada
        desde aquí, y la de un administrador solo la toca otro. Si no queda
        ninguna, el parcial no pinta ni el botón.
      --}}
      <td data-celda="accion" class="lista-acciones lista-acciones-menu">
        @include('partials.menu-fila', [
            'etiqueta' => $perfil->nombre_completo,
            'opciones' => [
                $puedeTocarla
                    ? ['texto' => 'Editar', 'url' => route('usuario-editar', $perfil), 'modal' => true]
                    : null,
                {{--
                  GESTIÓN ASISTIDA: entrar a trabajar desde la cuenta de esta
                  persona. Solo el administrador, solo sobre profesores y
                  directores, y no estando ya dentro de una — lo decide
                  `GestionAsistida::puedeAsistirA()`, que es la misma
                  comprobación que vuelve a hacer el controlador. Aquí decide si
                  se PINTA; allí decide si se PUEDE, que no es lo mismo.

                  Va con `post` porque cambia de sesión, no porque navegue.

                  Y con `recarga` porque cambia de IDENTIDAD: `acciones.js`
                  repinta solo `<main>`, y la barra de gestión asistida y el
                  menú viven fuera. Sin esto la sesión cambia de verdad pero la
                  pantalla sigue enseñando al administrador hasta la siguiente
                  navegación de verdad.
                --}}
                \App\Support\GestionAsistida::puedeAsistirA($yo, $perfil)
                    ? [
                        'texto' => 'Gestionar como '.$perfil->nombre_completo,
                        'url' => route('gestion-asistida-iniciar', $perfil),
                        'post' => true,
                        'recarga' => true,
                    ]
                    : null,
                {{-- Cambia datos: formulario y no enlace. Ver el parcial. --}}
                ($puedeTocarla && $perfil->user_id !== auth()->id())
                    ? [
                        'texto' => $perfil->user->activo ? 'Desactivar' : 'Activar',
                        'url' => route('usuario-alternar-activo', $perfil),
                        'post' => true,
                    ]
                    : null,
                {{--
                  «Eliminar» solo lo ve el administrador, y solo está VIVO
                  cuando de verdad se puede borrar: con la opción siempre
                  encendida, la única forma de enterarse de que una cuenta está
                  protegida era pulsarla y que te lo negaran. El conteo que
                  decide viene con la consulta de la página —`estaBloqueado` usa
                  lo que `withCount()` ya trajo—, no se pregunta aquí.

                  Es un enlace y no un formulario aunque borre: lleva a la
                  confirmación, que es la que pide la contraseña. Nada de este
                  listado borra nada por sí mismo.

                  Las matrículas NO lo apagan desde el 30/09/2026: con ellas se
                  anonimiza en vez de borrar (Ley 1581), y la confirmación lo
                  explica. Lo apaga tener algo A SU CARGO.
                --}}
                ($soyAdministrador && $puedeTocarla && $perfil->user_id !== auth()->id())
                    ? [
                        'texto' => 'Eliminar',
                        'url' => \App\Support\Dependencias::estaBloqueado($perfil, salvo: ['matriculas'])
                            ? null
                            : route('usuario-eliminar', $perfil),
                        'modal' => true,
                        'borrar' => true,
                        'porque' => 'Tiene promotorías, actividades o una institución a su cargo. Desactívala en su lugar.',
                    ]
                    : null,
            ],
        ])
      </td>
    </tr>
    @endforeach
  </tbody>
</table>
{{ $perfiles->links() }}
@endif
@endsection

@push('scripts')
<script src="@recurso('js/copiar-enlace.js')" defer></script>
@endpush
