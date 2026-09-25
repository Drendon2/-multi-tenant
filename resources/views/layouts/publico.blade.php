{{--
  Envoltorio de las pantallas SIN sesion: login, registro, inscripcion y la
  pagina de cuenta pendiente de rol.

  Puerto de `matriculas/templates/matriculas/base_publico.html`. El sistema de
  diseno vive en public/css/publico.css, extraido tal cual del <style> del
  original; lo unico que sigue yendo en linea es el color de marca, que es lo
  unico que cambia por institucion.
--}}
<!DOCTYPE html>
{{--
  `data-tema` lo estampa el SERVIDOR leyendo la galleta, no un guion al
  cargar: con JavaScript la pagina pinta en claro y salta a oscuro un
  instante despues, que es un fogonazo blanco en la cara de quien encendio
  el modo oscuro justo para no tener uno. Vacio significa «sigue al
  sistema», que es lo que hace el CSS cuando el atributo no esta.
--}}
<html lang="es"@if ($tema !== '') data-tema="{{ $tema }}"@endif>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>@yield('title', $configuracion->nombre_institucion)</title>
@include('partials.marca-cabecera', ['versionMarca' => \App\Support\IconoInstitucion::version()])
<link rel="stylesheet" href="@recurso('css/publico.css')">
@php($oscuro = $configuracion->acento_oscuro_trio)
<style>
  /* La marca en sus dos versiones; el porque esta en `layouts.app`. */
  :root {
    --accent: {{ $configuracion->color_acento }};
    --accent: light-dark({{ $configuracion->color_acento }}, {{ $oscuro['claro'] }});
    --accent-dark: {{ $configuracion->color_acento_oscuro }};
    --accent-dark: light-dark({{ $configuracion->color_acento_oscuro }}, {{ $oscuro['hover'] }});
    --accent-soft: {{ $configuracion->color_acento_suave }};
    --accent-soft: light-dark({{ $configuracion->color_acento_suave }}, {{ $oscuro['suave'] }});
    --caja-ancho: @yield('ancho', '400px');
  }
</style>
</head>
<body>
  <div class="envoltorio">
    <div class="marca">
      <img class="escudo"
           src="{{ $configuracion->logo ? route('logo-institucion') : asset('img/logo.webp') }}"
           alt="{{ $configuracion->nombre_institucion }}" width="60" height="60">
      <span>{{ $configuracion->nombre_institucion }}</span>
    </div>
    <div class="caja">
      @include('partials.mensajes')
      @yield('caja')
    </div>
    {{--
      El pie va DENTRO del envoltorio y no suelto en el <body>: aquí el body es
      un contenedor flex centrado, y un hijo más se colocaría al LADO de la caja
      en vez de debajo.
    --}}
    @include('partials.pie')
  </div>
  {{--
    El ojo para ver la contraseña. Va aquí y no en cada formulario porque las
    tres pantallas con campo de clave —entrar, inscripción y registro— cuelgan
    de este envoltorio, y el guion no hace nada donde no hay ninguno.
  --}}
  <script src="@recurso('js/ver-clave.js')" defer></script>
  @stack('scripts')
</body>
</html>
