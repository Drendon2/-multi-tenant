{{--
  Envoltorio de las pantallas CON sesion.

  Puerto de `matriculas/templates/matriculas/base.html`. El sistema de diseno
  vive en public/css/app.css, extraido tal cual del <style> del original; en
  linea solo queda el color de marca, que es lo unico que cambia por
  institucion y por eso no se puede cachear.
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
<title>@hasSection('title')@yield('title') — @endif{{ $configuracion->nombre_institucion }}</title>
@include('partials.marca-cabecera', ['versionMarca' => \App\Support\IconoInstitucion::version()])
<link rel="stylesheet" href="@recurso('css/app.css')">
{{--
  Marca configurable: sobreescribe SOLO el acento y sus dos tonos derivados.
  El resto del sistema de diseño (neutros, estados, colores de Área) no se toca.
--}}
@php($oscuro = $configuracion->acento_oscuro_trio)
<style>
  /*
    La marca, en sus DOS versiones. El acento de una institucion no sobrevive a
    un fondo oscuro —el verde de fabrica da 2,98:1— asi que la version oscura no
    es la misma con otro nombre: se aclara conservando el tono, y los otros dos
    invierten su papel. El como esta en `Support\Color`.

    El respaldo de la primera linea de cada par es lo que salva a un navegador
    que no conoce `light-dark()`: descarta la segunda por invalida y se queda
    con el color claro, en vez de dejar el token sin valor.
  */
  :root {
    --accent: {{ $configuracion->color_acento }};
    --accent: light-dark({{ $configuracion->color_acento }}, {{ $oscuro['claro'] }});
    --accent-dark: {{ $configuracion->color_acento_oscuro }};
    --accent-dark: light-dark({{ $configuracion->color_acento_oscuro }}, {{ $oscuro['hover'] }});
    --accent-soft: {{ $configuracion->color_acento_suave }};
    --accent-soft: light-dark({{ $configuracion->color_acento_suave }}, {{ $oscuro['suave'] }});
    /* Fondo y cabecera propios (05/10/2026), SOLO en modo claro: la segunda
       mitad de cada `light-dark()` es la paleta oscura de siempre. */
@if ($configuracion->color_fondo !== '')
    --bg: {{ $configuracion->color_fondo }};
    --bg: light-dark({{ $configuracion->color_fondo }}, #121715);
@endif
@if ($configuracion->color_cabecera !== '')
    --cabecera: {{ $configuracion->color_cabecera }};
    --cabecera: light-dark({{ $configuracion->color_cabecera }}, #1c2421);
    --cabecera-ink: {{ $configuracion->color_texto_cabecera }};
    --cabecera-ink: light-dark({{ $configuracion->color_texto_cabecera }}, #e7ede9);
@endif
  }
</style>
</head>
<body>
<header @if ($configuracion->color_cabecera !== '') class="cabecera-propia" @endif>
  <div class="marca-header">
    <img src="{{ route('logo-institucion') }}?v={{ \App\Support\LogoInstitucion::version() }}"
         alt="" width="30" height="30">
    <h1>{{ $configuracion->nombre_institucion }}</h1>
  </div>
  <nav>
    @auth
      <form action="{{ route('tema') }}" method="post" class="tema-forma nav-tema" data-tema-forma>
        @csrf
        <button type="submit" name="tema" value="oscuro" class="tema-boton tema-a-oscuro" aria-label="Cambiar a modo oscuro"><svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg></button>
        <button type="submit" name="tema" value="claro" class="tema-boton tema-a-claro" aria-label="Cambiar a modo claro"><svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg></button>
      </form>

      <div class="nav-barra">
        @if ($yo?->rol === 'estudiante')
          <a href="{{ route('mis-matriculas') }}">
            <svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h11l5 5v11H4z"/><path d="M15 4v5h5"/></svg>
            <span class="nav-texto">Matrículas</span>
          </a>
          {{--
            EL PUNTO ROJO. Confirmar una clase es la acción más importante que
            tiene un estudiante aquí y CADUCA a las 48 horas, así que el aviso no
            puede vivir solo en «Promotorías disponibles» —que es donde aterriza
            al entrar, pero que la institución puede APAGAR—. En el menú está en
            todas las pantallas y no se apaga.

            Va dentro del enlace y posicionado sobre el icono, así que no ocupa
            sitio: la celda mide lo mismo con punto y sin punto, y la barra no se
            recoloca al aparecer.

            Y NO ES SOLO UN PUNTO: lleva su texto para quien no ve el color, que
            es la regla de este proyecto —nada depende únicamente del color—. El
            número entero va en el `aria-label` del enlace.
          --}}
          <a href="{{ route('mis-clases') }}"
             @if ($clasesPorConfirmar)
               class="nav-con-aviso"
               aria-label="Clases — {{ $clasesPorConfirmar }} {{ $clasesPorConfirmar == 1 ? 'clase sin confirmar' : 'clases sin confirmar' }}"
             @endif>
            <svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 11h18"/></svg>
            <span class="nav-texto">Clases</span>
          </a>
          <a href="{{ route('mis-companeros') }}">
            <svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/></svg>
            <span class="nav-texto">Compañeros</span>
          </a>
        @elseif ($yo?->rol === 'institucion_externa')
          {{--
            LA CUENTA DE OTRA ENTIDAD, y va en su propia rama ANTES del
            `@elseif ($yo?->rol)` de abajo. Ese comodín significaba «cualquiera
            que no sea estudiante es personal de la casa», que dejó de ser cierto
            el 23/09/2026: por ahí, esta cuenta recibía el botón de Panel, y el
            Panel pide `rol:administrador,director,profesor`. Un botón que
            rebota, en la única barra que esta persona ve.

            Dos destinos y nada más. Aquí no hay Panel, ni Gestión, ni fichas:
            esta cuenta entra a firmar que el profesor vino, y a imprimir el
            cartón con el que el profesor lo deja firmado allá mismo.
          --}}
          <a href="{{ route('externa-clases') }}">
            <svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
            <span class="nav-texto">Clases</span>
          </a>
          <a href="{{ route('externa-qr') }}">
            <svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3h-3zM18 18h3v3h-3z"/></svg>
            <span class="nav-texto">Su QR</span>
          </a>
        @elseif ($yo?->rol)
          <a href="{{ route('panel') }}">
            <svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/></svg>
            <span class="nav-texto">Panel</span>
          </a>
        @endif

        @if (in_array($yo?->rol, ['director', 'administrador'], true))
          <a href="{{ route('gestion-inicio') }}">
            <svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M2 12h3M19 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1"/></svg>
            <span class="nav-texto">Gestión</span>
          </a>
          {{--
            HORARIOS EN LA BARRA (27/09/2026, pedido del usuario): es lo que más
            se consulta cuando alguien llega a la oficina a preguntar, y dentro
            de Gestión quedaba a dos toques. Es el CUARTO destino de este rol
            —Panel, Gestión, Horarios, Mi perfil—, o sea el tope: no cabe un
            quinto. Ver «El menú es una barra» en DESIGN.md.
          --}}
          <a href="{{ route('gestion-horarios') }}">
            <svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
            <span class="nav-texto">Horarios</span>
          </a>
        @endif

        {{--
          LAS ESTADÍSTICAS DEL PROFESOR (27/09/2026, pedido del usuario). Solo
          el rol profesor: la ruta no deja entrar a nadie más, y la dirección ya
          tiene las de la casa en Gestión y la barra en el tope de cuatro.
        --}}
        @if ($yo?->rol === 'profesor')
          <a href="{{ route('mis-estadisticas') }}">
            <svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20h16"/><path d="M7 16v-5M12 16V6M17 16v-8"/></svg>
            <span class="nav-texto">Estadísticas</span>
          </a>
        @endif

        <a href="{{ route('mi-perfil') }}">
          <svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          <span class="nav-texto">Mi perfil</span>
        </a>

        <div class="nav-raya"></div>

        <form action="{{ route('logout') }}" method="post" style="display:contents">
          @csrf
          <button type="submit" class="nav-salir">
            <svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>
            <span class="nav-texto">Salir</span>
          </button>
        </form>
      </div>
    @endauth
  </nav>
</header>
{{--
  EL AVISO AL ENTRAR. Lo pidió el usuario el 12/09/2026: «la verdad es la acción
  más importante de ese perfil». Y lo es, con una diferencia que lo separa del
  aviso de la encuesta —que este proyecto decidió a propósito que pidiera sin
  obligar—: la encuesta puede esperar siempre y esto CADUCA a las 48 horas.

  Aun así NO ES UN MURO: se cierra, y detrás está la pantalla entera. Quien entra
  con prisa a mirar su horario tiene que poder hacerlo, que es la razón escrita
  del aviso de la encuesta y vale igual aquí.

  SE ENSEÑA UNA VEZ POR SESIÓN, y la marca la pone el SERVIDOR al pintarlo. Sin
  eso reaparecería en cada pantalla, que es exactamente cómo se enseña a la gente
  a cerrar avisos sin leerlos.

  VA FUERA DE `<main>` por la regla de la casa: dentro, el repintado de
  `acciones.js` se lo llevaría, y `layouts.fragmento` no lo incluye.

  Y ES UN `<dialog>` CERRADO que abre un guion. Sin JavaScript no se ve nada —y
  está bien— porque la garantía es el PUNTO ROJO del menú, que no necesita
  guion. Esto lo amplifica; no lo sostiene.
--}}
@if ($clasesPorConfirmar && ! session('aviso_clases_visto'))
  @php(session(['aviso_clases_visto' => true]))
  <dialog class="aviso-clases" data-aviso-clases>
    <h2>{{ $clasesPorConfirmar == 1 ? 'Tienes una clase sin confirmar' : 'Tienes '.$clasesPorConfirmar.' clases sin confirmar' }}</h2>
    <p>
      Confirmar es decir que la clase sí se dio: es lo que le permite a
      {{ $configuracion->nombre_institucion }} verificar que se dictó.
    </p>
    <p class="aviso-clases-plazo">El plazo es de 48 horas desde que empezó cada clase.</p>
    <div class="aviso-clases-acciones">
      <a class="btn" href="{{ route('mis-clases') }}">Confirmar mis clases</a>
      <button type="button" class="btn btn-blanco" data-cerrar-aviso>Ahora no</button>
    </div>
  </dialog>
@endif
{{--
  LA BARRA DE GESTIÓN ASISTIDA. Va FUERA de <main> y encima de todo, porque
  mientras dure hay que verla en todas las pantallas: quien la olvida está
  actuando desde la cuenta de otra persona sin saberlo, y eso es exactamente lo
  que no puede pasar.

  Dice a nombre de quién se está trabajando, y su botón de salir manda a una
  ruta que NO pide rol de administrador — ver `routes/web.php`. En cuanto la
  asistencia empieza, para el middleware quien navega es el profesor, así que
  una puerta de administrador en el botón de salir dejaría a quien entra
  encerrado hasta cerrar sesión.
--}}
{{--
  LA BARRA DE «ENTRAR DESDE EL PANEL» (paso 4c). La misma idea que la de
  gestión asistida de abajo, y por la misma razón va fuera de <main> y en
  todas las pantallas: un operador del panel está dentro de la cuenta de un
  administrador de esta institución. Si además abrió una gestión asistida,
  salen las dos barras, y cada una deshace lo suyo. Una institución
  suspendida solo se ve así, y la barra lo dice.
--}}
@php($operadorDelPanel = \App\Support\Suplantacion::operador())
@if ($operadorDelPanel)
@php($institucionSuspendida = request()->attributes->get(\App\Models\Institucion::class)?->estado === \App\Models\Institucion::SUSPENDIDA)
<div class="barra-asistida" data-barra-suplantacion>
  <span class="barra-asistida-texto">
    <strong>Desde el panel de instituciones</strong>
    {{ $operadorDelPanel }} está dentro de la cuenta de un administrador de esta institución.
    @if ($institucionSuspendida)
      <strong>La institución está suspendida:</strong> nadie más la ve.
    @endif
  </span>
  <form action="{{ route('suplantacion.salir') }}" method="post" data-recarga-completa>
    @csrf
    <button type="submit" class="btn btn-blanco btn-sm">Volver al panel</button>
  </form>
</div>
@endif

@php($asistiendo = \App\Support\GestionAsistida::administrador())
@if ($asistiendo)
<div class="barra-asistida">
  <span class="barra-asistida-texto">
    <strong>Gestión asistida</strong>
    Estás trabajando como <strong>{{ $yo?->nombre_completo }}</strong>.
    Todo queda registrado a nombre de {{ $asistiendo->nombre_completo }}.
  </span>
  {{--
    `data-recarga-completa` aunque hoy no haga falta: esta barra vive FUERA de
    `<main>`, así que `acciones.js` ni lo mira. Eso es una casualidad de
    colocación y no una decisión escrita — mover la barra dentro por cualquier
    razón de diseño rompería el botón sin que nada fallara, y este es el caso
    peligroso: creer que saliste sin haber salido.
  --}}
  <form action="{{ route('gestion-asistida-salir') }}" method="post" data-recarga-completa>
    @csrf
    <button type="submit" class="btn btn-blanco btn-sm">Volver a mi cuenta</button>
  </form>
</div>
@endif

<main>
  @include('partials.mensajes')
  @yield('content')
</main>

{{--
  LO QUE SE LE DICE A UN LECTOR DE PANTALLA CUANDO ALGO PASA.

  Estas dos cajas van VACIAS y viven FUERA de <main> a proposito, y las dos
  cosas son el arreglo entero. `acciones.js` responde a una accion cambiando el
  contenido de <main> sin navegar; un lector de pantalla no anuncia eso, asi que
  quien no ve la pantalla no se entera ni de que se guardo ni de que NO se
  guardo. Es el mismo fallo que ya costo un profesor en produccion —creyo que
  habia un tope de grupos porque no vio el aviso— pero total, no parcial.

  Y tienen que PREEXISTIR: una region viva que se inserta ya con texto dentro no
  se anuncia de forma fiable en todos los lectores. Por eso estan aqui vacias
  desde la primera carga y `pintar()` solo les escribe el texto. Fuera de <main>
  porque dentro las borraria el propio repintado.

  Son DOS y no una porque el tono no es el mismo: lo que salio bien espera turno
  (`status`, cortes) y un rechazo interrumpe (`alert`), que es lo que se quiere
  de algo que dice que el trabajo no se guardo. Cambiar `aria-live` sobre la
  marcha en una sola caja no es fiable; tener dos, si.

  No se ven: `.sr-solo` es la misma clase que ya usan las etiquetas de Cupos y
  de los enlaces copiables. En pantalla el aviso sigue siendo el de `.messages`,
  que no cambia.
--}}
<div class="sr-solo" role="status" aria-live="polite" data-voz="bien"></div>
<div class="sr-solo" role="alert" aria-live="assertive" data-voz="mal"></div>

{{--
  El pie. FUERA de <main> por la misma razón que las dos cajas de arriba: lo que
  vive dentro se lo lleva el repintado de `acciones.js`, y `layouts.fragmento`
  —que es lo que va dentro de <main>— no lo incluye. Metido ahí, el enlace a la
  política de tratamiento de datos desaparecería tras la primera acción hecha
  sin recargar, sin que nada fallara ni avisara.
--}}
@include('partials.pie')

<script src="@recurso('js/acciones.js')" defer></script>
{{--
  El ojo para ver la contraseña. Con la sesión iniciada lo necesitan dos
  pantallas y las dos son MODALES —el formulario de usuario y la confirmación
  de borrado—, así que el guion no se conforma con barrer al arrancar: observa
  lo que se inserta después. Por eso da igual que vaya detrás de `acciones.js`.
--}}
<script src="@recurso('js/ver-clave.js')" defer></script>
{{--
  El selector de día de «Clases de la semana», en la portada del Panel. Lo monta
  este guion y NO la plantilla, igual que el ojo de la contraseña: un selector
  que filtra sin JavaScript es un control que no hace nada. Sin él se ven los
  seis días seguidos, que es una pantalla útil.

  Observa el DOM porque la portada del Panel se repinta sin recargar, así que
  da igual que vaya detrás de `acciones.js`.
--}}
<script src="@recurso('js/clases-del-dia.js')" defer></script>
{{--
  El cambio de tema sin recargar. El botón del menú funciona sin este guion
  —manda su formulario y la página vuelve del otro color—; lo que añade es que
  el color cambie en el acto.
--}}
<script src="@recurso('js/tema.js')" defer></script>
{{--
  El aviso de clases sin confirmar. Solo lo ABRE: el diálogo ya viene en el HTML
  y sin este guion no se ve, porque la garantía es el punto rojo del menú.
--}}
<script src="@recurso('js/aviso-clases.js')" defer></script>
@stack('scripts')
</body>
</html>
