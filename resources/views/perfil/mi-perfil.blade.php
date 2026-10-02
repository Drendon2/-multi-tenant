@extends('layouts.app')

@section('title', 'Mi perfil')

@section('content')
<h2>Mi perfil</h2>

<div class="perfil-intro">
  <form method="post" action="{{ route('mi-perfil.guardar') }}" enctype="multipart/form-data" id="form-foto">
    @csrf
    <input type="hidden" name="accion" value="foto">
    <label class="perfil-avatar-wrap" for="foto_perfil">
      <span class="perfil-avatar">
        @if ($perfil->foto_perfil)
          <img src="{{ route('ver-foto', $perfil) }}" alt="">
        @else
          <span class="perfil-avatar-inicial">{{ mb_strtoupper(mb_substr($perfil->nombre_completo, 0, 1)) }}</span>
        @endif
      </span>
      <span class="perfil-avatar-badge" aria-hidden="true">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.75" stroke-linecap="round">
          <path d="M12 5v14M5 12h14"/>
        </svg>
      </span>
      {{--
        `perfil-avatar-input` no es decorativa: es la que estira el input
        invisible sobre el avatar entero para que la tarjeta sea el control. Sin
        ella el navegador pinta su «Seleccionar archivo» encima de la foto.
      --}}
      <input type="file" name="foto_perfil" id="foto_perfil" accept="image/*" class="perfil-avatar-input">
    </label>
    @error('foto_perfil')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
  </form>
  <div class="perfil-nombre">{{ $perfil->nombre_completo }}</div>
  @if ($perfil->rol)<div class="perfil-rol-sub">{{ $perfil->rol_display }}</div>@endif
  {{--
    SOLO SI SE SABE. «Mi perfil» es de TODOS los roles, incluida la cuenta de
    una institución externa, a la que no se le pide la fecha de nacimiento —es
    un contacto de otra entidad y ese dato no lo usa nada aquí—. Sin este
    `@if`, ahí salía «Edad: años», que se lee como un fallo del sistema.
  --}}
  @if ($perfil->edad !== null)
    <p class="campo-info" style="margin:0.4rem 0 0;">Edad: {{ $perfil->edad }} años</p>
  @endif
  <div class="perfil-tel-fila perfil-tel-texto">
    <span class="campo-info" style="margin:0;">Teléfono: {{ $perfil->telefono }}</span>
    <button type="button" class="perfil-editar-btn perfil-tel-toggle" aria-label="Editar teléfono">
      <svg aria-hidden="true" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
    </button>
  </div>
  <form method="post" action="{{ route('mi-perfil.guardar') }}" class="perfil-contacto-form perfil-tel-form">
    @csrf
    <input type="hidden" name="accion" value="contacto">
    <input type="text" name="telefono" maxlength="10" inputmode="numeric" pattern="[0-9]{10}" title="10 dígitos, sin espacios ni guiones" value="{{ old('telefono', $perfil->telefono) }}">
    <button type="submit" class="perfil-editar-btn" aria-label="Guardar teléfono">
      <svg aria-hidden="true" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
    </button>
  </form>
  @error('telefono')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror

  {{--
    El correo es OPCIONAL y vive en la cuenta, no en el perfil. Sin él puesto se
    dice «Sin correo» en vez de dejar el renglón vacío: un hueco no distingue
    «no lo he puesto» de «esta pantalla no lo pide».

    Reutiliza las clases del teléfono porque el patrón es el mismo —texto con un
    botón que lo cambia por un campo— y son estructurales, no propias del
    teléfono.
  --}}
  <div class="perfil-tel-fila perfil-tel-texto">
    <span class="campo-info" style="margin:0;">
      Correo:
      @if ($perfil->user->email)
        {{ $perfil->user->email }}
      @else
        <span class="vacio">Sin correo</span>
      @endif
    </span>
    <button type="button" class="perfil-editar-btn perfil-tel-toggle" aria-label="Editar correo">
      <svg aria-hidden="true" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
    </button>
  </div>
  <form method="post" action="{{ route('mi-perfil.guardar') }}" class="perfil-contacto-form perfil-tel-form">
    @csrf
    <input type="hidden" name="accion" value="correo">
    <input type="email" name="correo" maxlength="255" pattern="[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}" title="Un correo completo, con arroba y dominio. Ejemplo: nombre@correo.com" placeholder="tu@correo.com"
           value="{{ old('correo', $perfil->user->email) }}">
    <button type="submit" class="perfil-editar-btn" aria-label="Guardar correo">
      <svg aria-hidden="true" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
    </button>
  </form>
  @error('correo')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror

  @if ($estadisticas)
  <div class="perfil-stats" style="margin-top:1rem;">
    @foreach ($estadisticas as $stat)
    <div>
      <span class="perfil-stat-num">{{ $stat['numero'] }}</span>
      <span class="perfil-stat-label">{{ $stat['etiqueta'] }}</span>
    </div>
    @endforeach
  </div>
  @endif
</div>
{{--
  El horario de la semana. Solo aparece si esta persona tiene dónde estar: al
  estudiante sin grupo asignado todavía, y a quien no dicta nada, una rejilla
  vacía no le dice nada que no sepa.
--}}
@if ($horario)
  @include('partials.horario-semanal', [
    'horario' => $horario,
    'titulo' => $perfil->rol === 'estudiante' ? 'Mi horario' : 'Mi horario de clases',
    'periodo' => \App\Models\Periodo::enCurso(),
  ])
@endif

{{--
  Solo si hay algo que contar. Un panel de ceros no informa de nada y ademas
  miente por omisión: sin clases todavía no se distingue «no he faltado nunca» de
  «no ha empezado el periodo».
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

<script>
  (function () {
    var toggles = document.querySelectorAll(".perfil-tel-toggle");
    toggles.forEach(function (toggle) {
      toggle.addEventListener("click", function () {
        var fila = toggle.closest(".perfil-tel-texto");
        if (!fila) { return; }
        // El formulario que sigue a ESTA fila, no el primero de la tarjeta: hay
        // dos campos en línea —teléfono y correo— y buscar por nombre de campo
        // o por el primero abría siempre el mismo.
        var form = fila.nextElementSibling;
        if (!form || !form.classList.contains("perfil-tel-form")) { return; }
        fila.style.display = "none";
        form.style.display = "inline-flex";
        var input = form.querySelector("input:not([type=hidden])");
        if (input) { input.focus(); }
      });
    });
  })();
</script>

{{--
  MIS DATOS. Nace el 07/09/2026: hasta ese día el nombre y la fecha se escribían
  una vez al inscribirse y después solo los tocaba un administrador. Se abrió
  porque hacía falta —la regla del nombre que entró esa misma tarde dejó a
  cuatro personas con un nombre que el sistema ya no acepta, y ninguna podía
  arreglarlo sola— y porque son SUS datos.

  Va en un `<details>` cerrado y arriba del todo de las secciones: se toca poco,
  pero cuando se toca es lo primero que se viene a buscar.

  SE ABRE SOLA SI SU FORMULARIO FUE RECHAZADO, acotado a SUS campos y no con
  `$errors->any()`: con eso se abriría porque falló la encuesta, que no tiene
  nada que ver. Es la misma regla que el bloque de la contraseña, y existe por
  el mismo fallo — un aviso que manda a buscar algo rojo dentro de un plegado.
--}}
{{--
  EN UNA SOLA LÍNEA, y no por gusto: la forma en línea de la directiva PHP no
  cruza saltos de línea. Partida en tres, Blade no la compila, `$erroresDeDatos`
  nunca se asigna y el `<details>` de abajo se queda cerrado sobre su propio
  error — sin fallar y sin avisar. Y la de bloque no se puede usar aquí: este
  archivo ya usa la de una línea, y mezclarlas se traga todo lo que quede en
  medio (está escrito en CLAUDE.md).
--}}
@php($erroresDeDatos = $errors->hasAny(['nombre_completo', 'fecha_nacimiento', 'documento_identidad', 'acudiente_nombre', 'acudiente_telefono', 'acudiente']))
<details class="perfil-seccion" id="bloque-datos" @if ($erroresDeDatos) open @endif>
  <summary class="perfil-seccion-cabecera">
    <span class="perfil-seccion-icono icono-documento" aria-hidden="true">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
        <circle cx="12" cy="7" r="4"/>
      </svg>
    </span>
    <h3 style="margin:0;">Mis datos</h3>
    <svg aria-hidden="true" class="perfil-seccion-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>
  </summary>

  <p class="campo-ayuda">
    Corrige aquí tu información si algo quedó mal escrito al inscribirte.
    El usuario con el que entras se cambia más abajo, en «Nombre de usuario».
  </p>

  <form method="post" action="{{ route('mi-perfil.guardar') }}">
    @csrf
    <input type="hidden" name="accion" value="datos">

    <div class="field">
      <label for="mis-nombre">Nombre completo</label>
      <input type="text" name="nombre_completo" id="mis-nombre" maxlength="90" required
             pattern="[\p{L}\p{M}][\p{L}\p{M} .'-]*" title="Solo letras, espacios, apóstrofo y guion. Sin números"
             value="{{ old('nombre_completo', $perfil->nombre_completo) }}">
      @error('nombre_completo')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
    </div>

    <div class="field">
      <label for="mis-nacimiento">Fecha de nacimiento</label>
      <input type="date" name="fecha_nacimiento" id="mis-nacimiento" required
             value="{{ old('fecha_nacimiento', $perfil->fecha_nacimiento?->toDateString()) }}">
      {{--
        Dice lo que arrastra, porque no se deduce del campo: de la fecha sale si
        eres menor, y de ahí que se te pida acudiente y qué versión del
        consentimiento se imprime.
      --}}
      <p class="campo-ayuda">
        De esta fecha depende si el sistema te pide acudiente y qué formato de
        autorización te corresponde firmar.
      </p>
      @error('fecha_nacimiento')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
    </div>

    @if ($perfil->rol === 'estudiante')
    <div class="field">
      {{--
        «Número de documento» y no «Documento de identidad», que es como se
        llama en los demás formularios. Lo destapó una prueba que ya existía: en
        ESTA pantalla conviven dos cosas distintas con ese nombre —el número que
        se teclea aquí, y el PAPEL escaneado que la institución puede pedir más
        abajo—, así que el rótulo repetido las confundía. Fuera de aquí no hay
        ambigüedad y el nombre largo se queda.
      --}}
      <label for="mis-documento">Número de documento</label>
      <input type="text" name="documento_identidad" id="mis-documento" required
             maxlength="12" inputmode="numeric" pattern="[0-9]{6,12}" title="Solo números, entre 6 y 12 dígitos"
             value="{{ old('documento_identidad', $datos?->documento_identidad) }}">
      @error('documento_identidad')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
    </div>

    <div class="field">
      <label for="mis-acudiente">Nombre del acudiente</label>
      <input type="text" name="acudiente_nombre" id="mis-acudiente" maxlength="90"
             pattern="[\p{L}\p{M}][\p{L}\p{M} .'-]*" title="Solo letras, espacios, apóstrofo y guion. Sin números"
             value="{{ old('acudiente_nombre', $datos?->acudiente?->nombre) }}">
      <p class="campo-ayuda">Obligatorio si eres menor de edad.</p>
      @error('acudiente_nombre')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
      @error('acudiente')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
    </div>

    <div class="field">
      <label for="mis-acudiente-tel">Teléfono del acudiente</label>
      <input type="text" name="acudiente_telefono" id="mis-acudiente-tel"
             maxlength="10" inputmode="numeric" pattern="[0-9]{10}" title="10 dígitos, sin espacios ni guiones"
             value="{{ old('acudiente_telefono', $datos?->acudiente?->telefono) }}">
      @error('acudiente_telefono')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
    </div>
    @endif

    <button type="submit" class="btn">Guardar mis datos</button>
  </form>
</details>

{{--
  EL CARNÉ CON CÓDIGO QR. Solo estudiantes: es con lo que el profesor les marca
  la asistencia en clase, y en una lista de clase no hay profesores.

  Va ANTES del certificado y de los papeles a propósito: es lo único de esta
  pantalla que hay que llevar encima, y quien entra buscándolo lo hace de pie en
  la puerta del salón.
--}}
@if ($perfil->rol === 'estudiante')
<div class="perfil-seccion">
  <div class="perfil-seccion-cabecera">
    <span class="perfil-seccion-icono icono-documento" aria-hidden="true">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <rect x="3" y="3" width="7" height="7" rx="1"/>
        <rect x="14" y="3" width="7" height="7" rx="1"/>
        <rect x="3" y="14" width="7" height="7" rx="1"/>
        <path d="M14 14h3v3h-3zM19 19h2M14 19v2"/>
      </svg>
    </span>
    <h3>Mi carné</h3>
  </div>
  <p class="campo-info" style="margin-top:0;">
    Un código para que tu profesor te marque la asistencia con la cámara.
    Guárdalo en las fotos de tu celular o imprímelo: <strong>sirve aunque no
    puedas entrar al sistema</strong>.
  </p>
  <a class="btn" href="{{ route('mi-carne') }}">Ver mi carné</a>
</div>
@endif

{{--
  El certificado de matrícula. Solo para quien tiene algo que certificar ahora
  mismo: sin matrículas activas en el periodo en curso la sección no aparece,
  porque el documento saldría afirmando que esta persona cursa algo que no cursa.
--}}
@if ($certificables)
<div class="perfil-seccion">
  <div class="perfil-seccion-cabecera">
    <span class="perfil-seccion-icono icono-documento" aria-hidden="true">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/>
        <path d="M14 3v5h5"/>
        <path d="M9 13h6M9 17h4"/>
      </svg>
    </span>
    <h3>Certificado de matrícula</h3>
  </div>
  <p class="campo-info" style="margin-top:0;">
    Documento en PDF que acredita
    {{ $certificables === 1
        ? 'la promotoría que cursas'
        : 'las '.$certificables.' promotorías que cursas' }}
    en el periodo en curso, firmado por la dirección. Para certificar una sola
    promotoría, usa el botón de cada fila en <strong>Mis matrículas</strong>.
  </p>
  <a class="btn" href="{{ route('certificado-todo', $perfil) }}">Descargar el certificado</a>
</div>
@endif

@if ($papeles)
{{--
  Los papeles que pide ESTA institución. Cada ranura es su propio envío: se suben
  a medida que se consiguen, que es como se hace en la vida real. Un solo botón
  de "guardar todo" obligaría a tenerlos todos a la mano el mismo día.

  DESDE EL 05/09/2026 EL DOCUMENTO DE IDENTIDAD ES UNO MÁS de esta lista. Tenía
  su propia sección encima, con su propia columna en la base y sin que la entidad
  pudiera decidir nada sobre él — ni si se pide, ni cómo se llama, ni si es
  obligatorio. Ahora se configura en Institución como los demás.

  Y VA PLEGADA, a petición del usuario: son papeles que se suben una vez y luego
  no se vuelven a mirar, así que desplegados empujaban hacia abajo todo lo que sí
  se viene a ver. Arranca ABIERTA si falta alguno obligatorio, por lo mismo que
  la encuesta: plegada, una entrega a medias no se distingue de una completa.
--}}
@php($faltaAlgunPapel = collect($papeles)->contains(fn ($p) => $p['requerido']->obligatorio && ! $p['entrega']))
<details class="perfil-seccion" id="bloque-papeles" @if ($faltaAlgunPapel) open @endif>
  <summary class="perfil-seccion-cabecera">
    <span class="perfil-seccion-icono icono-documento" aria-hidden="true">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/>
        <path d="M14 3v5h5"/>
      </svg>
    </span>
    <h3 style="margin:0;">Documentos para la matrícula</h3>
    @if ($faltaAlgunPapel)<span class="estado estado-pendiente">Falta alguno</span>@endif
    <svg aria-hidden="true" class="perfil-seccion-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>
  </summary>
  <p class="campo-ayuda">
    Lo que pide {{ $configuracion->nombre_institucion }} para dar la matrícula por completa.
    Puedes subirlos de a uno, según los vayas consiguiendo.
  </p>

  @foreach ($papeles as $p)
  <form method="post" action="{{ route('mi-perfil.guardar') }}" enctype="multipart/form-data" class="papel-fila">
    @csrf
    <input type="hidden" name="accion" value="papel">
    <input type="hidden" name="documento_id" value="{{ $p['requerido']->id }}">
    <div class="papel-datos">
      <span class="papel-nombre">
        {{ $p['requerido']->nombre }}
        @if (! $p['requerido']->obligatorio)<span class="campo-info" style="margin:0;">(opcional)</span>@endif
      </span>
      @if ($p['requerido']->descripcion)
        <span class="campo-info" style="margin:0;">{{ $p['requerido']->descripcion }}</span>
      @endif
      @if ($p['entrega'])
        <span class="campo-info archivo-guardado" style="margin:0;">Entregado — súbelo otra vez para reemplazarlo.</span>
      @elseif ($p['requerido']->obligatorio)
        <span class="estado estado-pendiente">Falta</span>
      @endif
      {{--
        LA DESCARGA DEL FORMATO. Solo la tiene el papel que el sistema sabe
        imprimir, y quién es lo dice la columna `plantilla` — no su nombre, que
        la entidad puede cambiar desde Institución.

        Va DENTRO de la ficha del papel y no en una sección aparte: bajar el
        formato y devolverlo firmado son dos mitades del mismo trámite, y
        separarlas deja a quien lo abre buscando dónde estaba el papel que
        acaba de firmar.

        El enlace se pinta también cuando ya lo entregó: un consentimiento se
        vuelve a bajar para releer qué se autorizó, y para volver a firmarlo si
        se quiere cambiar una de las dos casillas.
      --}}
      @if ($p['requerido']->plantilla === \App\Models\DocumentoRequerido::FORMATO_CONSENTIMIENTO)
        <a class="papel-formato" href="{{ route('consentimiento') }}">
          <svg aria-hidden="true" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
            <path d="M7 10l5 5 5-5"/>
            <path d="M12 15V3"/>
          </svg>
          Descargar el formato para firmar
        </a>
      @endif
    </div>
    <input type="file" name="archivo" aria-label="Archivo de {{ $p['requerido']->nombre }}">
    <button type="submit" class="btn btn-sm">{{ $p['entrega'] ? 'Reemplazar' : 'Subir' }}</button>
    {{--
      EL RECHAZO, EN LA FILA QUE SE INTENTO.

      Hasta el 06/09/2026 esta pantalla no pintaba NINGUN error de esta sección:
      el aviso de arriba decía «No se guardó. Hay un campo por corregir, marcado
      en rojo más abajo» y no había nada rojo en ninguna parte. O sea el fallo
      que ya costó un profesor en producción, otra vez, y en la pantalla desde
      la que 740 personas tienen que subir su consentimiento.

      Y va acotado con `old('documento_id')` a propósito. Aquí hay un formulario
      POR PAPEL y todos mandan un campo que se llama `archivo`, así que un
      `@error('archivo')` a secas pinta el mismo error en los cinco: quien
      falló al subir la cédula vería «demasiado grande» también bajo el
      consentimiento, que no ha tocado. Es la misma regla que abre un <details>
      solo si el error es SUYO.
    --}}
    @error('archivo')
      @if ((int) old('documento_id') === $p['requerido']->id)
        <ul class="errorlist"><li>{{ $message }}</li></ul>
      @endif
    @enderror
  </form>
  @endforeach
</details>
@endif

{{--
  La sección arranca abierta cuando falta algo por contestar. Plegada, una
  encuesta a medias no se distingue de una terminada, y quien la dejó así no
  tiene por qué sospechar que le falta nada.

  Y ABIERTA TAMBIÉN CUANDO NO HAY ENCUESTA NINGUNA, que es el caso que faltaba y
  el que reportaron los usuarios el 12/09/2026: «me sigue apareciendo el aviso
  después de llenarla». No la habían llenado. `$faltanPreguntas` vale `[]`
  cuando la encuesta NO EXISTE —ahí lo que falta es entera, no unas preguntas—
  así que esta sección, el chip y el aviso de dentro colgaban todos de esa misma
  condición y NINGUNO se pintaba. Quien pulsaba «Contestar la encuesta» en el
  aviso aterrizaba en `#bloque-encuesta` y encontraba un título plegado con nada
  debajo: ni formulario, ni señal de que hubiera algo pendiente. Medido en
  producción ese día, es el caso de ~924 de 1.048 personas con rol.

  Y ABIERTA SI SUS CAMPOS TRAEN ERROR, que es la regla de la casa y aquí faltaba.
  Sin JavaScript un rechazo vuelve a pintar la página desde cero, y como seguía
  sin haber encuesta la sección volvía plegada CON LOS ERRORES DENTRO — el aviso
  de arriba mandando a buscar lo rojo más abajo y nada rojo a la vista. Con
  JavaScript no se ve, porque `acciones.js` conserva abiertos los `<details>`
  que tienen `id`.

  `hasAny` acotado a SUS campos y no `$errors->any()`: con eso se abriría porque
  falló el formulario de la contraseña, que no tiene nada que ver.
--}}
@php($encuestaSinEmpezar = $encuesta === null)
@php($erroresDeEncuesta = $errors->hasAny(['genero', 'barrio', 'estrato', 'nivel_educativo', 'ocupacion', 'zona', 'afiliacion_salud', 'grupo_etnico', 'discapacidad', 'victima_conflicto_armado', 'autoriza_tratamiento_datos']))
<details class="perfil-seccion" id="bloque-encuesta" @if ($faltanPreguntas || $encuestaSinEmpezar || $erroresDeEncuesta) open @endif>
  <summary class="perfil-seccion-cabecera">
    <span class="perfil-seccion-icono icono-encuesta" aria-hidden="true">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <rect x="6" y="4" width="12" height="17" rx="2"/>
        <path d="M9 4V3a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v1"/>
        <path d="M9 11h6M9 15h6M9 19h3"/>
      </svg>
    </span>
    <h3 style="margin:0;">Encuesta demográfica</h3>
    @if ($encuestaSinEmpezar)<span class="estado estado-pendiente">Sin contestar</span>@elseif ($faltanPreguntas)<span class="estado estado-pendiente">Incompleta</span>@endif
    <svg aria-hidden="true" class="perfil-seccion-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>
  </summary>
  <p class="campo-info" style="margin-top:0.8rem;">Esta información solo la puedes ver tú y el administrador.</p>
  @if ($encuestaSinEmpezar)
  <p class="aviso">
    Todavía no has contestado esta encuesta. Son unas pocas preguntas y toma
    menos de un minuto.
  </p>
  @elseif ($faltanPreguntas)
  <p class="aviso">
    Falta contestar
    {{ count($faltanPreguntas) === 1 ? 'una pregunta' : count($faltanPreguntas).' preguntas' }}:
    <strong>{{ implode(', ', $faltanPreguntas) }}</strong>.
  </p>
  @endif
  @if ($perfil->es_menor)
  <p class="aviso">
    Eres menor de edad: la autorización de tratamiento de datos debe darla tu acudiente.
    Pide al administrador que la registre.
  </p>
  @endif
  <form method="post" action="{{ route('mi-perfil.guardar') }}" class="form-card">
    @csrf
    <input type="hidden" name="accion" value="encuesta">

    @include('perfil.campo-lista', ['campo' => 'genero', 'etiqueta' => 'Género', 'obligatorio' => true])

    <div class="field">
      <label for="barrio">Barrio</label>
      <input type="text" name="barrio" id="barrio" maxlength="60" required
             value="{{ old('barrio', $encuesta?->barrio) }}">
      @error('barrio')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
    </div>

    @include('perfil.campo-lista', ['campo' => 'estrato', 'etiqueta' => 'Estrato', 'obligatorio' => true])
    @include('perfil.campo-lista', ['campo' => 'nivel_educativo', 'etiqueta' => 'Nivel educativo', 'obligatorio' => true])
    @include('perfil.campo-lista', ['campo' => 'ocupacion', 'etiqueta' => 'Ocupación', 'obligatorio' => true])
    @include('perfil.campo-lista', ['campo' => 'zona', 'etiqueta' => 'Zona'])
    @include('perfil.campo-lista', ['campo' => 'afiliacion_salud', 'etiqueta' => 'Afiliación a salud'])
    @include('perfil.campo-lista', ['campo' => 'grupo_etnico', 'etiqueta' => 'Grupo étnico'])
    @include('perfil.campo-lista', ['campo' => 'discapacidad', 'etiqueta' => 'Discapacidad'])
    @include('perfil.campo-lista', [
      'campo' => 'victima_conflicto_armado',
      'etiqueta' => 'Víctima del conflicto armado',
    ])

    @if (! $perfil->es_menor)
    <div class="field">
      <label>
        <input type="checkbox" name="autoriza_tratamiento_datos" value="1"
               @checked(old('autoriza_tratamiento_datos', $encuesta?->autoriza_tratamiento_datos))>
        Autorizo el tratamiento de mis datos personales
      </label>
    </div>
    @endif

    <button type="submit" class="btn">Guardar encuesta</button>
  </form>
</details>

{{--
  EL NOMBRE DE USUARIO. Pedido el 10/09/2026, y hasta ese día no se cambiaba por
  ningún camino que no fuera un administrador: «Mis datos» decía literalmente
  «el usuario con el que entras no se cambia desde aquí».

  ES SU PROPIA SECCIÓN Y NO UN CAMPO MÁS DE «Mis datos», y esa es la decisión:
  pide la contraseña actual, y metido allí obligaría a teclearla para corregir
  una tilde del nombre. Va aquí abajo, junto a la contraseña, porque las dos son
  la CREDENCIAL con la que se entra y no un dato de la persona.

  SE PINTA EL QUE HAY. No se puede cambiar lo que no se ve, y esta pantalla no
  lo enseñaba en ninguna parte.

  SE ABRE SOLA SI SU FORMULARIO FUE RECHAZADO, acotado a SUS dos campos. Y por
  eso su campo de clave se llama `clave_usuario` y no `clave_actual`: con el
  mismo nombre que el de abajo, un fallo aquí abriría los dos plegados y quien
  bajase a buscar lo rojo lo encontraría en el equivocado.

  El ojo del campo de clave lo pone `ver-clave.js` solo; aquí no hay que hacer
  nada.
--}}
<details class="perfil-seccion" id="bloque-usuario"
         @if ($errors->has('username') || $errors->has('clave_usuario')) open @endif>
  <summary class="perfil-seccion-cabecera">
    <span class="perfil-seccion-icono icono-documento" aria-hidden="true">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
        <circle cx="12" cy="7" r="4"/>
      </svg>
    </span>
    <h3 style="margin:0;">Nombre de usuario</h3>
    <svg aria-hidden="true" class="perfil-seccion-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>
  </summary>

  <form method="post" action="{{ route('mi-perfil.guardar') }}" class="form-card">
    @csrf
    <input type="hidden" name="accion" value="usuario">

    <label for="mi-username">Entras con</label>
    <input type="text" name="username" id="mi-username" maxlength="150" required
           autocomplete="username" autocapitalize="none" spellcheck="false"
           value="{{ old('username', $perfil->user->username) }}">
    @error('username')<ul class="errorlist"><li>{{ $message }}</li></ul>@enderror

    <label for="clave_usuario">Contraseña actual</label>
    <input type="password" name="clave_usuario" id="clave_usuario"
           autocomplete="current-password" required>
    {{--
      Dice POR QUÉ se pide, porque no se deduce del campo: cambiarle el usuario
      a alguien no le quita la cuenta, le quita la forma de entrar en ella, y
      aquí se llega con una sesión abierta en un celular prestado.
    --}}
    <p class="campo-ayuda">
      Se pide para que nadie pueda cambiarte el usuario con el que entras
      aprovechando que dejaste la sesión abierta.
    </p>
    @error('clave_usuario')<ul class="errorlist"><li>{{ $message }}</li></ul>@enderror

    <p class="campo-ayuda">
      No tendrás que volver a iniciar sesión ahora: el usuario nuevo es el de la
      próxima vez que entres.
    </p>

    <button type="submit" class="btn">Cambiar el nombre de usuario</button>
  </form>
</details>

{{--
  CAMBIAR LA CONTRASEÑA. Va en un `<details>` cerrado y al final a propósito: es
  lo que menos se hace de esta pantalla, y abierto empujaría hacia abajo todo lo
  que sí se viene a mirar.

  La sección se PINTA siempre, incluso durante una gestión asistida, y entonces
  dice por qué no se puede en vez de desaparecer. Es el criterio del menú de
  fila: si desapareciera, enterarse de que algo está protegido exigiría
  intentarlo y que te lo nieguen.

  El ojo de los tres campos lo pone `ver-clave.js` solo; aquí no hay que hacer
  nada.
--}}
{{--
  SE ABRE SOLA SI SU FORMULARIO FUE RECHAZADO. Sin esto, el aviso de arriba dice
  «hay un campo por corregir, marcado en rojo más abajo» y al bajar no hay nada
  rojo: el error está dentro de un `<details>` plegado. Es el mismo fallo que ya
  costó un profesor en producción, y aquí solo asoma SIN JavaScript — con él,
  `acciones.js` conserva abiertos los `<details>` que tienen `id`, y este lo
  tiene. Se vio pulsando el botón con la clave actual mal escrita.

  Acotado a SUS dos campos: `$errors` es de toda la página, y con `$errors->any()`
  esta sección se abriría porque falló la encuesta, que no tiene nada que ver.
--}}
<details class="perfil-seccion" id="bloque-clave"
         @if ($errors->has('clave_actual') || $errors->has('password')) open @endif>
  <summary class="perfil-seccion-cabecera">
    <span class="perfil-seccion-icono icono-clave" aria-hidden="true">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <rect x="3" y="11" width="18" height="11" rx="2"/>
        <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
      </svg>
    </span>
    <h3 style="margin:0;">Contraseña</h3>
    <svg aria-hidden="true" class="perfil-seccion-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>
  </summary>

  @if (\App\Support\GestionAsistida::activa())
    <p class="campo-ayuda">
      No se puede cambiar la contraseña de alguien desde una gestión asistida.
      Vuelve a tu cuenta para cambiar la tuya.
    </p>
  @elseif (\App\Support\Suplantacion::activa())
    <p class="campo-ayuda">
      No se puede cambiar la contraseña de esta cuenta entrando desde el panel
      de instituciones.
    </p>
  @else
    <form method="post" action="{{ route('mi-perfil.guardar') }}" class="form-card">
      @csrf
      <input type="hidden" name="accion" value="clave">

      <label for="clave_actual">Contraseña actual</label>
      <input type="password" name="clave_actual" id="clave_actual"
             autocomplete="current-password" required>
      @error('clave_actual')<ul class="errorlist"><li>{{ $message }}</li></ul>@enderror

      <label for="password">Contraseña nueva</label>
      <input type="password" name="password" id="password"
             autocomplete="new-password" required>
      @error('password')<ul class="errorlist"><li>{{ $message }}</li></ul>@enderror

      <label for="password_confirmation">Repite la contraseña nueva</label>
      <input type="password" name="password_confirmation" id="password_confirmation"
             autocomplete="new-password" required>

      <p class="campo-ayuda">
        Si habías entrado en otro celular o computador, ahí tendrás que volver a
        iniciar sesión.
      </p>

      <button type="submit" class="btn">Cambiar la contraseña</button>
    </form>
  @endif
</details>

<script>
  (function () {
    var input = document.getElementById("foto_perfil");
    var form = document.getElementById("form-foto");
    if (!input || !form) { return; }
    input.addEventListener("change", function () {
      if (input.files && input.files.length) { form.submit(); }
    });
  })();
</script>
@endsection

@push('scripts')
{{-- Solo el gesto de deslizar entre periodos. Las flechas funcionan sin esto. --}}
<script src="@recurso('js/periodo.js')" defer></script>
@endpush
