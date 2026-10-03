{{--
  EL QR DE UNA INSTITUCION EXTERNA. Es otro cartón, no otro carné.

  El de un estudiante dice QUIÉN se presenta a una lista; este dice DÓNDE se dio
  una clase. Comparten el trazado y la columna `codigo_qr`, y no comparten ni el
  significado ni quién lo puede sacar, así que tampoco comparten pantalla: la de
  `perfil/carne-qr` está escrita entera en segunda persona y para el dueño del
  carné —«muéstraselo a tu profesor»—, y aquí el dueño del cartón no es quien lo
  muestra sino quien lo guarda para que se lo lean.

  LA MISMA PANTALLA EN SUS DOS PUERTAS —administración imprimiéndolo para
  entregarlo, y la institución sacándolo de nuevo desde su cuenta— y no dos
  parecidas. Lo único que cambia es el título, el aviso de arriba y a dónde
  apuntan los botones. Dos copias se habrían separado el día que alguien mejore
  las instrucciones, que es la parte que decide si esto funciona.

  EL PROFESOR NO LLEGA AQUÍ por ninguno de los dos caminos, ni siquiera el
  responsable del programa: este cartón es la llave con la que se verifica su
  propio trabajo.

  La imagen va INCRUSTADA como datos y no como una segunda petición: así la
  pantalla se pinta entera de una vez, y en este hosting cada petición cuesta
  ~1,5 s por el CDN.
--}}
@extends('layouts.app')

@section('title', $propio ? 'Nuestro QR' : "QR — {$institucion->nombre}")

@section('content')
@php($imagen = 'data:image/png;base64,'.base64_encode(\App\Support\CarneQr::carneDeInstitucion($institucion->perfil, $institucion->nombre)))

@if ($propio)
  <h2>El QR de {{ $institucion->nombre }}</h2>
  <p class="aviso">
    Imprímalo y téngalo donde se dan las clases. Cuando el profesor termine,
    muéstreselo: con eso la clase queda verificada en el acto, sin que usted
    tenga que entrar aquí. <strong>Solo funciona el mismo día de la clase</strong>
    — las de otros días se verifican desde <a href="{{ route('externa-clases') }}">Clases</a>.
  </p>
@else
  <p class="migas">
    <a href="{{ route('gestion-programas') }}">Programas formativos</a><span class="migas-sep">/</span>
    <span class="migas-actual">QR de {{ $institucion->nombre }}</span>
  </p>
  <h2>QR de {{ $institucion->nombre }}</h2>
  <p class="aviso">
    Imprímelo y entrégaselo a <strong>{{ $institucion->perfil->nombre_completo }}</strong>.
    Con él, el profesor deja la clase verificada allá mismo el día que la da.
    <strong>No se lo des al profesor</strong>: es la llave con la que se verifica
    su propio trabajo.
  </p>
@endif

<div class="card carne-qr">
  <img class="carne-qr-imagen" src="{{ $imagen }}" alt="Código QR de {{ $institucion->nombre }}">

  {{--
    Solo «Descargar», sin botón de imprimir, por lo mismo que en el carné: uno
    de imprimir necesita `window.print()` y sin JavaScript sería un botón que no
    hace nada. La hoja impresa la cuidan las reglas de impresión de `app.css`.
  --}}
  <div class="carne-qr-acciones">
    <a class="btn" href="{{ $propio ? route('externa-qr-imagen') : route('institucion-externa-qr-imagen', $institucion) }}" download>Descargar</a>
  </div>

  <p class="carne-qr-comollega">
    En Android la descarga queda en <strong>Archivos</strong> y suele salir también en
    <strong>Galería</strong>. En iPhone mantenga presionada la imagen de arriba y elija
    <strong>«Guardar en Fotos»</strong>.
  </p>
</div>

<details class="carne-qr-renovar">
  <summary>{{ $propio ? 'Perdimos el cartón o alguien le tomó una foto' : 'Perdieron el cartón o alguien le tomó una foto' }}<svg aria-hidden="true" class="carne-qr-renovar-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg></summary>
  <p class="campo-ayuda">
    Sacar un QR nuevo <strong>anula el anterior en el acto</strong>: el cartón viejo y las
    fotos que anden por ahí dejan de servir para verificar nada. Después hay que volver a
    imprimirlo y a entregarlo.
    {{--
      POR QUÉ ESTO IMPORTA MÁS AQUÍ QUE EN UN CARNÉ, dicho donde se decide: quien
      escanea este cartón es el profesor, o sea la persona a la que la
      verificación vigila. Si se quedó con una foto del código, esto es lo único
      que la inutiliza. Por eso el botón lo tiene también la institución, sin
      tener que pedirle nada a nadie.
    --}}
    Es lo que hay que hacer si se sospecha que alguien fotografió el código.
  </p>
  <form method="post" action="{{ $propio ? route('externa-qr-renovar') : route('institucion-externa-qr-renovar', $institucion) }}">
    @csrf
    <button type="submit" class="btn btn-secundario btn-sm">Sacar un QR nuevo</button>
  </form>
</details>

<p style="margin-top:1.5rem;">
  @if ($propio)
    <a class="volver" href="{{ route('externa-clases') }}">← Clases</a>
  @else
    <a class="volver" href="{{ route('gestion-programas') }}">← Programas formativos</a>
  @endif
</p>
@endsection
