@extends('layouts.app')

@section('title', $actividad->nombre)

@section('content')
{{--
  El lado de quien DA la actividad. Gestión la creó y le puso fechas; aquí se
  ve quién se apuntó y se oprime «Iniciar» cuando la clase empieza de verdad.

  Dirección abre esta pantalla en solo lectura: ver es cosa suya, iniciar es de
  quien estuvo en el salón. Por eso `$dirige` gobierna cada botón — pintar uno
  que al pulsarlo rebota es peor que no pintarlo.
--}}
<a href="{{ route('panel-actividades') }}" class="volver">&larr; Cursos y actividades</a>
<h2>{{ $actividad->nombre }} <span class="tipo-chip">{{ $actividad->etiquetaTipo() }}</span></h2>

@if (! $dirige)
  <p class="aviso">
    Esto lo dirige <strong>{{ $actividad->responsable->nombre_completo }}</strong>.
    Puedes verlo, pero iniciar las sesiones y pasar lista le toca a quien está a cargo.
  </p>
@endif

{{--
  `$apuntados` llega del controlador y NO se saca de `$inscritos`.

  Antes se calculaba aquí con `->count()`, correcto mientras la lista venía
  entera. Desde que la ficha pagina, `count()` son los de la página: de esta
  cifra cuelga si el enlace sigue admitiendo gente, así que una actividad con
  200 inscritos se habría leído como que tiene 50 y el enlace habría seguido
  abierto pasado el cupo.

  Se sigue pasando precalculado por lo de siempre: `admiteInscripciones()` sin
  argumento lanza un COUNT desde la plantilla, que es lo que cerró D-03.
--}}
{{--
  UN PROGRAMA EXTERNO NO TIENE ENLACE, así que en su lugar va lo que sí tiene:
  dónde se dicta y quién da fe. El `@if` envuelve la tarjeta entera y pregunta
  por `llevaEnlace()` y no por el tipo, que es la única casa de esa regla.
--}}
@if ($actividad->llevaEnlace())
<div class="card">
  <h3>El enlace para inscribirse</h3>
  @if ($actividad->admiteInscripciones($apuntados))
    <p class="campo-info" style="margin-top:0;">
      Compártelo con quien quieras inscribir. No necesitan cuenta.
    </p>
  @else
    <p class="campo-info" style="margin-top:0;">
      Ya no recibe gente: {{ $actividad->abierta ? 'se llenaron los cupos' : 'dirección cerró el enlace' }}.
    </p>
  @endif
  <label class="sr-solo" for="enlace">Enlace de {{ $actividad->nombre }}</label>
  {{-- El boton de copiar lo añade `copiar-enlace.js`: sin JavaScript no serviría. --}}
  <div class="enlace-fila">
    <input class="enlace-copiable" type="text" id="enlace" readonly value="{{ $actividad->enlace() }}">
  </div>
  {{--
    El QR del mismo enlace, para redes o para pegarlo en la pared (29/09/2026).
    Solo el botón y no la imagen en la ficha: esta pantalla se abre para pasar
    lista, y pintar el QR cada vez costaría sin servir.
  --}}
  <p style="margin-bottom:0;">
    <a class="btn btn-secundario btn-sm" href="{{ route('panel-actividad-qr', $actividad) }}" download>Descargar QR</a>
  </p>
</div>
@else
<div class="card">
  <h3>Dónde se dicta</h3>
  <p style="margin-top:0;">
    <strong>{{ $actividad->institucion->nombre }}</strong>
    @if ($actividad->institucion->direccion)
      <span class="campo-ayuda" style="display:block;">{{ $actividad->institucion->direccion }}</span>
    @endif
  </p>
  <p class="campo-ayuda">
    {{--
      DICE DÓNDE ESTÁ EL BOTÓN, y dice bien dónde. Antes decía «léelo desde la
      fila de esa clase» y el lector vive DEBAJO de la tabla de sesiones, no en
      una fila: se vio al abrir la pantalla. Una instrucción que manda a buscar
      por donde no es cuesta más que ninguna, porque quien no encuentra el botón
      concluye que el sistema no lo tiene.
    --}}
    Quien verifica allá es <strong>{{ $actividad->institucion->perfil->nombre_completo }}</strong>@if ($actividad->institucion->telefono),
    {{ $actividad->institucion->telefono }}@endif. Al terminar la clase, pídele
    su código QR y léelo con el botón que sale debajo de las sesiones: queda
    verificada en el acto. <strong>El QR solo sirve el mismo día</strong> —
    después, la verifica la institución desde su cuenta.
  </p>
</div>
@endif

<div class="card">
  <h3>Sesiones</h3>

  {{--
    Un grupo de proyección no tiene fechas puestas: ensaya cuando toca y la
    sesión nace al oprimir el botón, igual que una clase de promotoría. Por eso
    aquí el botón va arriba y no en una fila.
  --}}
  {{--
    EL MISMO BOTÓN DICE DOS COSAS, según si la de hoy ya empezó. Va al mismo
    sitio por el mismo camino —el controlador no reescribe la hora de una
    sesión ya iniciada, solo lleva a la hoja— así que no hace falta una segunda
    ruta: lo único que cambia es que deje de anunciar una acción agotada.

    Antes decía «Iniciar» siempre, en verde macizo, aunque la clase llevara una
    hora dada; volver a oprimirlo no hacía nada y no lo decía. Se vio probándolo
    en pantalla el 23/09/2026 y era, además, el botón que competía con
    «Pasar lista» —que estaba en blanco y dentro de la tabla— justo cuando
    pasar lista era lo que tocaba.
  --}}
  @if (! $actividad->llevaFechas() && $dirige)
  <form method="post" action="{{ route('panel-actividad-iniciar-hoy', $actividad) }}">
    @csrf
    <button type="submit" class="btn">
      @if ($yaEmpezoHoy)
        Pasar lista de hoy
      @else
        Iniciar {{ $actividad->etiquetaSesion() }} de hoy
      @endif
    </button>
  </form>
  @endif

  @if ($sesiones->isEmpty())
    <p class="vacio">
      @if ($actividad->llevaFechas())
        Todavía no tiene fechas. Las pone dirección, en Gestión → Cursos y talleres.
      @else
        {{--
          CON EL ARTÍCULO DELANTE, no con «ningún» + la palabra pelada. Es la
          misma trampa que `Actividad::etiquetaSesionConArticulo()` documenta y
          que ya costó una vez un «Taller iniciada»: las tres palabras no
          concuerdan igual —«clase» es femenina, «taller» y «ensayo»
          masculinos—. Aquí salía «Todavía no se ha hecho ningún clase» en un
          programa externo, y se vio en el navegador con la suite en verde,
          porque ninguna prueba mira este texto.
        --}}
        Todavía no se ha iniciado {{ $actividad->etiquetaSesionConArticulo() }}.
      @endif
    </p>
  @else
  <table>
    <thead>
      <tr>
        <th>{{ $actividad->llevaFechas() ? 'Clase' : 'Fecha' }}</th>
        <th>Estado</th>
        @if ($actividad->esExterno())
        <th>Verificación</th>
        @endif
        <th></th>
      </tr>
    </thead>
    <tbody>
      @foreach ($sesiones as $i => $sesion)
      <tr>
        <td>
          @if ($actividad->llevaFechas())
            <strong>{{ $i + 1 }}.</strong>
          @endif
          {{ $sesion->fecha->format('d/m/Y') }}
        </td>
        <td>
          @if ($sesion->yaEmpezo())
            <span class="estado estado-activa">Iniciada</span>
            <span class="campo-info" style="margin:0;display:block;">
              {{ $sesion->iniciada_en->format('d/m/Y \a \l\a\s H:i') }}
              @if ($sesion->iniciadaPor)
                · {{ $sesion->iniciadaPor->nombre_completo }}
              @endif
            </span>
          @else
            <span class="estado estado-pendiente">Sin iniciar</span>
          @endif
        </td>
        @if ($actividad->esExterno())
        {{--
          LA FIRMA DE LA INSTITUCIÓN. Usa el vocabulario de `.estado` y no
          inventa marcador propio, igual que la verificación de una clase de
          promotoría: sólido cuando alguien la reclamó, punteado mientras no
          —«el punteado significa todavía no»—.

          Y DICE POR DÓNDE ENTRÓ. Nunca se colapsan las dos cifras: que la haya
          dado la institución desde su cuenta o que la haya dejado el propio
          profesor leyendo el cartón allá no es lo mismo, y una pantalla que las
          pinte iguales es la que hace que después no se puedan separar.
        --}}
        <td data-label="Verificación">
          @if ($sesion->estaVerificada())
            <span class="estado estado-activa">Verificada</span>
            <span class="campo-info" style="margin:0;display:block;">
              {{ $sesion->verificada_en->format('d/m/Y') }}
              · {{ $sesion->verificacion_origen === 'qr' ? 'leyendo su QR' : 'desde su cuenta' }}
            </span>
          @elseif ($sesion->yaEmpezo())
            <span class="estado estado-pendiente">Sin verificar</span>
          @else
            <span class="vacio">—</span>
          @endif
        </td>
        @endif
        <td style="text-align:right;">
          @if ($sesion->yaEmpezo())
            {{--
              La lista se ofrece a todos los que ven la pantalla, no solo a
              quien dirige: dirección la abre en solo lectura, que es
              exactamente para lo que necesita entrar.
            --}}
            <a class="btn btn-blanco btn-sm" href="{{ route('panel-actividad-lista', $sesion) }}">
              @if ($sesion->asistencias_count)
                Lista ({{ $sesion->asistencias_count }})
              @else
                Pasar lista
              @endif
            </a>
          @elseif ($dirige)
          <form method="post" action="{{ route('panel-actividad-iniciar', $sesion) }}">
            @csrf
            <button type="submit" class="btn btn-sm">Iniciar</button>
          </form>
          @endif
        </td>
      </tr>
      @endforeach
    </tbody>
  </table>
  @endif

  {{--
    EL LECTOR DEL QR DE LA INSTITUCIÓN. Uno solo y dentro de la tarjeta de las
    sesiones, no uno por fila: el QR solo verifica la clase del mismo día y la
    base admite una sesión por día, así que «la clase que acabo de dar» es
    siempre una. Veinte lectores para una sola respuesta posible es ruido.

    NACE OCULTO Y LO DESTAPA EL GUION (`verificacion-qr.js`), solo si el aparato
    tiene cámara. Sin JavaScript no aparece ningún botón — un control que no
    puede hacer su trabajo no se pinta, que es la regla de esta casa desde el
    ojo de la contraseña. Y no deja a nadie encerrado: la institución verifica
    desde su cuenta, sin plazo y sin cámara.

    EL AVISO DEL PLAZO SE DA ANTES DE ESCANEAR, no después. Es la lección del
    21/09/2026 en producción: el usuario pasó lista con el carné en una clase de
    días atrás, se marcó bien y no se verificó nadie, y la pantalla no dio ni una
    pista porque el aviso solo sabía hablar cuando algo se escribía. Aquí, si no
    hay clase de hoy sin verificar, no se ofrece cámara: se dice por qué y a
    dónde ir.
  --}}
  @if ($actividad->esExterno() && $dirige)
    @if ($sesionParaQr)
    <div class="qr-lector" data-qr-verificar hidden>
      <form method="post" action="{{ route('panel-externo-verificar-qr', $sesionParaQr) }}" data-qr-forma>
        @csrf
        <input type="hidden" name="codigo" data-qr-codigo value="">
        <p class="campo-ayuda" style="margin-top:0;">
          Terminaste la clase de hoy. Pídele el QR a
          {{ $actividad->institucion->perfil->nombre_completo }} y léelo aquí.
        </p>
        {{--
          LAS MISMAS CLASES que el lector del carné (`.qr-lector-*`), no unas
          paralelas: es el mismo componente —una cámara con una mira y un
          renglón que dice qué pasó— y dos juegos de clases para un solo
          componente es como acaba divergiendo lo que se ve. El guion es otro
          porque lo que hace después de leer es otra cosa; el aspecto es el
          mismo y está documentado en DESIGN.md bajo `.qr-lector`.
        --}}
        <button type="button" class="btn btn-secundario" data-qr-abrir>Leer el QR de la institución</button>
        <button type="button" class="btn btn-secundario btn-sm" data-qr-cerrar hidden>Apagar la cámara</button>
        <div class="qr-lector-camara" data-qr-camara hidden>
          <video playsinline muted></video>
          <div class="qr-lector-marco"></div>
        </div>
        {{-- `aria-live` para que el resultado se oiga: repintar no se lo cuenta
             a un lector de pantalla por sí solo. --}}
        <p class="qr-lector-aviso" data-qr-aviso role="status" aria-live="polite"></p>
      </form>
    </div>
    @else
    <p class="campo-ayuda">
      El QR de la institución solo verifica la clase <strong>el mismo día</strong>.
      @if ($sesiones->isEmpty())
        Todavía no has iniciado ninguna clase aquí.
      @else
        Hoy no hay ninguna clase iniciada sin verificar, así que no hay nada que leer.
      @endif
      Lo de otros días lo verifica la institución desde su cuenta, que no tiene plazo.
    </p>
    @endif
  @endif
</div>

{{--
  LA LISTA DE UN PROGRAMA EXTERNO SE ESCRIBE, no se inscribe. Es lo que lo
  separa de los otros tres tipos, y por eso este formulario existe solo aquí.

  DOS CAMPOS Y NADA MÁS —nombre y edad—, decidido con el usuario el 23/09/2026.
  Se llena de pie, en un salón ajeno y con la clase empezando: pedir documento o
  fecha de nacimiento a cada niño de una escuela rural es como una lista se
  queda a medias.

  Va ARRIBA de la tabla y no debajo: es lo que se viene a hacer a esta pantalla
  las primeras veces, y con la lista ya larga quedaría a un desplazamiento
  entero de distancia justo en el teléfono, que es donde se usa.
--}}
@if ($actividad->esExterno() && $dirige)
<div class="card">
  <h3>Añadir a la lista</h3>
  <form method="post" action="{{ route('panel-externo-anadir', $actividad) }}" class="lista-externa-forma">
    @csrf
    <div class="field">
      <label for="nombre_completo">Nombre</label>
      <input type="text" name="nombre_completo" id="nombre_completo" required maxlength="90"
             autocomplete="off" value="{{ old('nombre_completo') }}">
      @error('nombre_completo')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
    </div>
    <div class="field">
      <label for="edad">Edad</label>
      {{--
        `inputmode="numeric"` decide QUÉ TECLADO sale en un teléfono, que es
        donde esto se llena. Ninguna prueba de PHP puede medir un teclado, así
        que el atributo se pone aquí y se mira en el navegador.
      --}}
      <input type="number" name="edad" id="edad" required min="1" max="119" step="1"
             inputmode="numeric" value="{{ old('edad') }}">
      @error('edad')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
    </div>
    <button type="submit" class="btn">Añadir</button>
  </form>
</div>
@endif

<div class="card">
  <h3>{{ $actividad->esExterno() ? 'La lista' : 'Inscritos' }} <span class="cupo-cifra">{{ $apuntados }}</span></h3>

  @if ($inscritos->isEmpty())
    <p class="vacio">
      @if ($actividad->esExterno())
        Todavía no hay nadie en la lista. La escribes tú, allá, con el formulario de arriba.
      @else
        Todavía no se ha inscrito nadie por el enlace.
      @endif
    </p>
  @else
  {{--
    `.tabla-personas` desde el 11/09/2026, cuando esta tabla ganó una acción:
    bajo 640px cada fila se vuelve ficha. Es una lista de registros y no una
    rejilla —la posición de la celda no es el dato— y sin esto el botón de
    «Certificado» quedaba al otro lado de un arrastre horizontal en el teléfono,
    que es donde se usa casi todo esto. Es la trampa que este proyecto ya pagó
    dos veces.
  --}}
  <table class="tabla-personas tabla-catalogo">
    <thead>
      <tr>
        <th>Nombre</th>
        <th class="num">Edad</th>
        {{--
          TELÉFONO Y CORREO NO EXISTEN EN UN PROGRAMA EXTERNO: no se piden, así
          que las dos columnas saldrían con un guion en cada fila. Un guion dice
          «este dato está vacío», y lo cierto es que ese dato no se pregunta
          aquí — son dos cosas distintas y la columna entera es la que sobra.
        --}}
        @if (! $actividad->esExterno())
        <th>Teléfono</th>
        <th>Correo</th>
        @endif
        <th>Asistencia</th>
        @if ($actividad->llevaFechas() || ($actividad->esExterno() && $dirige))
        <th><span class="sr-solo">Acciones</span></th>
        @endif
      </tr>
    </thead>
    <tbody>
      @foreach ($inscritos as $inscrito)
      @php($suya = $asistencias[$inscrito->id] ?? ['sesiones' => 0, 'asistidas' => 0, 'porcentaje' => 0, 'certificable' => false])
      <tr>
        <td data-celda="detalle">
          {{ $inscrito->nombre_completo }}
          {{--
            Quien además es estudiante de la casa. Se sabe porque el documento
            coincidió, y decirlo aquí es lo que permite saber cuántos de los
            propios están en el coro.
          --}}
          @if ($inscrito->perfil)
            <span class="campo-info" style="margin:0;display:block;">Estudiante de la institución</span>
          @endif
        </td>
        {{--
          DOS FUENTES PARA LA MISMA COLUMNA, y no es un remiendo: quien llegó
          por un enlace escribió su fecha de nacimiento y su edad se CALCULA —y
          sigue siendo cierta dentro de dos años—; a quien escribió el profesor
          en un salón ajeno se le preguntó la edad y punto. La calculada va
          primero porque es la que no envejece.
        --}}
        <td class="num" data-label="Edad">
          @if ($inscrito->fecha_nacimiento)
            {{ \App\Models\Perfil::edadDe($inscrito->fecha_nacimiento) }}
          @elseif ($inscrito->edad)
            {{ $inscrito->edad }}
          @else
            <span class="vacio">—</span>
          @endif
        </td>
        @if (! $actividad->esExterno())
        <td data-label="Teléfono">{{ $inscrito->telefono ?: '—' }}</td>
        <td data-label="Correo">{{ $inscrito->correo ?: '—' }}</td>
        @endif
        <td data-label="Asistencia">
          @if ($suya['sesiones'] === 0)
            {{-- Sin lista tomada no hay cifra que dar, y un «0%» diría de cada
                 inscrito algo falso: que no fue, cuando lo que pasa es que
                 todavía nadie ha pasado lista. --}}
            <span class="vacio">Sin lista tomada</span>
          @else
            {{ $suya['asistidas'] }} de {{ $suya['sesiones'] }}
            <span class="lista-nota">({{ $suya['porcentaje'] }}%)</span>
          @endif
        </td>
        @if ($actividad->llevaFechas())
        {{--
          `data-celda="accion"` y no `data-label`: en la ficha del teléfono esto
          no es un dato con rótulo, es lo que se pulsa. Y cuando no se puede, el
          renglón dice POR QUÉ en vez de quedarse vacío — si no, quien mira una
          fila sin botón no sabe si le falta asistencia o si el sistema falló.
        --}}
        <td data-celda="accion" class="lista-acciones">
          @if ($suya['certificable'])
            <a class="btn btn-sm" href="{{ route('certificado-actividad', [$actividad, $inscrito]) }}">Certificado</a>
          @elseif ($suya['sesiones'] > 0)
            <span class="lista-nota">Menos del {{ $minimoCertificado }}%</span>
          @endif
        </td>
        @elseif ($actividad->esExterno() && $dirige)
        {{--
          QUITAR, solo mientras esa persona no tenga ninguna marca. El corte no
          es un permiso: es lo que separa «me equivoqué al escribir el nombre»
          de «borrar asistencia» — la clave foránea es CASCADE y con la fila se
          irían sus marcas sin que nada avisara. Cuando ya no se puede, el
          renglón dice POR QUÉ en vez de quedarse vacío, que es la regla de esta
          misma tabla tres líneas más arriba.
        --}}
        <td data-celda="accion" class="lista-acciones">
          @if (! isset($conMarcas[$inscrito->id]))
            <form method="post" action="{{ route('panel-externo-quitar', $inscrito) }}">
              @csrf
              <button type="submit" class="btn btn-blanco btn-sm"
                      aria-label="Quitar a {{ $inscrito->nombre_completo }} de la lista">Quitar</button>
            </form>
          @else
            <span class="lista-nota">Ya tiene asistencia</span>
          @endif
        </td>
        @endif
      </tr>
      @endforeach
    </tbody>
  </table>
  {{ $inscritos->links() }}
  @endif
</div>
@endsection

@push('scripts')
{{--
  Solo añade el botón de copiar al enlace. Se comparte por WhatsApp y casi
  siempre desde el celular, donde seleccionar un texto largo a dedo es lo peor
  de la pantalla.
--}}
<script src="@recurso('js/copiar-enlace.js')" defer></script>
{{--
  El lector del QR de la institución, solo en un programa externo: en los otros
  tres tipos no hay panel que destapar y serían 4 KB por nada.

  Se compara por la RUTA del `src` y no por la URL con `?v=`, que es como
  `Fragmento::pintar()` decide qué guiones le faltan a la página de destino: sin
  eso, al cambiar de pantalla por ese camino la nueva se quedaba con los guiones
  de la anterior.
--}}
@if ($actividad->esExterno())
<script src="@recurso('js/verificacion-qr.js')" defer></script>
@endif
@endpush
