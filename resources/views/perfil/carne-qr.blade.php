{{--
  El carné con código QR de un estudiante.

  La MISMA pantalla en los tres sitios donde aparece —el propio estudiante,
  administración mirando una ficha, y quien acaba de inscribirse— y no tres
  parecidas: lo único que cambia es el título, a dónde apunta el botón de
  descargar y el aviso de arriba. Tres copias se habrían separado el día que
  alguien mejorara las instrucciones de guardarlo en el teléfono, que es la
  parte que de verdad decide si esto funciona.

  La imagen va INCRUSTADA como datos y no como una segunda petición: así la
  pantalla se pinta entera de una vez —importa, porque el CDN cobra ~1,5 s por
  petición— y funciona igual en la pantalla de recién inscrito, donde todavía no
  hay sesión que autorice una segunda llamada.
--}}
@extends('layouts.app')

@section('title', $propio ? 'Mi carné' : "Carné — {$estudiante->nombre_completo}")

@section('content')
@php($imagen = 'data:image/png;base64,'.base64_encode(\App\Support\CarneQr::carne($estudiante)))

@if ($recienInscrito)
  <h2>Ya quedaste inscrito</h2>
  <p class="aviso">
    <strong>Guarda este carné en tu teléfono antes de salir de esta pantalla.</strong>
    Con él tu profesor te marca la asistencia en clase sin que tengas que recordar tu
    usuario ni tu contraseña. Si lo pierdes, lo vuelves a sacar desde <em>Mi perfil</em>
    o te lo dan en la oficina.
  </p>
@elseif ($propio)
  <h2>Mi carné</h2>
  <p class="aviso">
    Muéstraselo a tu profesor al llegar a clase y te marca la asistencia. Guárdalo en las
    fotos de tu teléfono o imprímelo: <strong>sirve aunque no puedas entrar al sistema</strong>.
  </p>
@else
  <p class="migas">
    <a href="{{ route('detalle-usuario', $estudiante) }}">{{ $estudiante->nombre_completo }}</a><span class="migas-sep">/</span>
    <span class="migas-actual">Carné</span>
  </p>
  <h2>Carné de {{ $estudiante->nombre_completo }}</h2>
  <p class="aviso">
    Descárgalo o imprímelo para entregárselo. Es la forma de que le pasen lista a quien
    no se maneja con el sistema o no recuerda su contraseña.
  </p>
@endif

<div class="card carne-qr">
  <img class="carne-qr-imagen" src="{{ $imagen }}" alt="Código QR del carné de {{ $estudiante->nombre_completo }}">

  {{--
    Solo «Descargar», sin botón de imprimir: uno de imprimir tiene que llamar a
    `window.print()`, y sin JavaScript sería un botón que no hace nada —el mismo
    error que ya costó el ojo de la contraseña—. Quien imprime lo hace desde el
    menú de su navegador, y la hoja impresa la cuidan las reglas de impresión de
    `app.css` (busca `carne-qr` ahí).
  --}}
  <div class="carne-qr-acciones">
    <a class="btn" href="{{ $descarga }}" download>Descargar</a>
  </div>

  <p class="carne-qr-comollega">
    En Android la descarga queda en <strong>Archivos</strong> y suele salir también en
    <strong>Galería</strong>. En iPhone mantén presionada la imagen de arriba y elige
    <strong>«Guardar en Fotos»</strong>: ahí la descarga va a <em>Archivos</em>, no a tus fotos.
  </p>
</div>

{{--
  Sin renovar para quien acaba de inscribirse: todavía no ha iniciado sesión, y
  su carné acaba de nacer. El botón apuntaría a una ruta que le va a rebotar.
--}}
@if (! $recienInscrito)
<details class="carne-qr-renovar">
  <summary>{{ $propio ? 'Perdí mi carné o alguien le tomó una foto' : 'Perdió el carné o alguien le tomó una foto' }}<svg aria-hidden="true" class="carne-qr-renovar-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg></summary>
  <p class="campo-ayuda">
    Sacar un carné nuevo <strong>anula el anterior en el acto</strong>: el papel viejo y las
    fotos que anden por ahí dejan de servir para marcar asistencia. Después hay que volver a
    descargarlo y a imprimirlo.
  </p>
  <form method="post" action="{{ $propio ? route('mi-carne-renovar') : route('carne-estudiante-renovar', $estudiante) }}">
    @csrf
    <button type="submit" class="btn btn-secundario btn-sm">Sacar un carné nuevo</button>
  </form>
</details>
@endif

<p style="margin-top:1.5rem;">
  @if ($recienInscrito)
    <a class="btn" href="{{ route('login') }}">Entrar al sistema</a>
  @elseif ($propio)
    <a class="volver" href="{{ route('mi-perfil') }}">← Mi perfil</a>
  @else
    <a class="volver" href="{{ route('detalle-usuario', $estudiante) }}">← Volver a la ficha</a>
  @endif
</p>
@endsection
