{{--
  Envoltorio del panel de TODAS las instituciones (paso 4b, 02/10/2026).

  Aparte de `layouts.app` y `layouts.publico` porque esos dos pintan la marca
  de una institucion (nombre, logo, color), y el panel no es de ninguna: su
  host es `panel.<dominio base>`. Usa el mismo sistema de diseño (`app.css`)
  con el acento de fabrica, y sin menu: tiene una sola pantalla de fondo.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>@yield('title', 'Panel de instituciones')</title>
<link rel="stylesheet" href="@recurso('css/app.css')">
</head>
<body>
<header>
  <div class="marca-header">
    <h1>Panel de instituciones</h1>
  </div>
  @auth('operador')
    <nav>
      <form method="post" action="{{ route('operador.salir') }}">
        @csrf
        <button type="submit" class="btn btn-secundario btn-sm">Salir</button>
      </form>
    </nav>
  @endauth
</header>
<main>
  @include('partials.mensajes')
  @yield('content')
  @yield('despues')
</main>
</body>
</html>
