{{--
  El icono y la vista previa de la institución (25/09/2026). Va en las DOS
  plantillas: la pública es la que se comparte (entrar, inscripción) y la de
  sesión es donde alguien pone el acceso directo estando dentro.

  Todo sale del logo de Gestión → Institución (`IconoInstitucion`). El `?v=`
  cambia con el logo: los teléfonos y el CDN guardan un icono días enteros, y
  sin él quien cambia el logo seguiría viendo el viejo.

  Las etiquetas `og:` las lee el robot de WhatsApp o Facebook al pegar el
  enlace; la imagen tiene que ir con URL ABSOLUTA, que es lo que da `route()`.
--}}
<link rel="icon" type="image/png" sizes="192x192" href="{{ route('icono-institucion', 192) }}?v={{ $versionMarca }}">
<link rel="apple-touch-icon" sizes="180x180" href="{{ route('icono-institucion', 180) }}?v={{ $versionMarca }}">
<link rel="manifest" href="{{ route('manifiesto') }}?v={{ $versionMarca }}">
<meta name="theme-color" content="{{ $configuracion->color_acento }}">
<meta name="apple-mobile-web-app-title" content="{{ $configuracion->nombre_institucion }}">
<meta property="og:type" content="website">
<meta property="og:site_name" content="{{ $configuracion->nombre_institucion }}">
<meta property="og:title" content="{{ $configuracion->nombre_institucion }}">
<meta property="og:description" content="Inscripciones, clases y asistencia de {{ $configuracion->nombre_institucion }}.">
<meta property="og:url" content="{{ url()->current() }}">
<meta property="og:image" content="{{ route('imagen-compartir') }}?v={{ $versionMarca }}">
<meta property="og:image:type" content="image/png">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta name="twitter:card" content="summary_large_image">
