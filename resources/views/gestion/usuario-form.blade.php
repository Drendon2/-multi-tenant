@extends('layouts.app')

@section('title', $titulo)

@section('content')
<a href="{{ route('usuario-lista') }}" class="volver">&larr; Volver al listado</a>

{{--
  `data-modal-cuerpo` es lo que el modal se lleva dentro cuando se abre desde el
  listado, y por eso el título vive AQUÍ y ya no encima: suelto quedaría flotando
  sobre el fondo oscurecido, fuera de la tarjeta. Sigue siendo una página con su
  URL —sin JavaScript se abre y se rellena igual—, y es la misma tarjeta en los
  dos casos.

  Este formulario es el caso que DESIGN.md daba como ejemplo de lo que NO va en
  un modal, por sus once campos. Se cambió el 02/09/2026 a petición del usuario:
  es el que más se abre —asignar rol a quien se acaba de registrar— y sacarlo de
  la lista para eso costaba el viaje de ida y vuelta. El diálogo se ancla arriba
  y la tarjeta se desplaza por dentro, que es lo que hace que quepa.
--}}
<form method="post" action="{{ $accion }}" enctype="multipart/form-data" class="form-card"
      @if ($modal ?? false) data-modal-cuerpo @endif>
  @csrf
  <input type="hidden" name="volver" value="{{ $volver ?? '' }}">
  <h2 style="margin-top:0;">{{ $titulo }}</h2>

  <div class="field">
    <label for="username">Usuario</label>
    <input type="text" name="username" id="username" maxlength="150" required
           value="{{ old('username', $perfil->user?->username) }}">
    @error('username')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
  </div>

  <div class="field">
    <label for="password">Contraseña{{ $esCreacion ? '' : ' temporal' }}</label>
    <input type="password" name="password" id="password" @required($esCreacion) autocomplete="new-password">
    @if (! $esCreacion)
      <div class="campo-ayuda">Déjalo en blanco para no cambiar la contraseña.</div>
    @endif
    @error('password')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
  </div>

  <div class="field">
    <label for="rol">Rol</label>
    {{--
      Solo los roles que QUIEN MIRA puede repartir: un director no ve
      «Administrador» en la lista. El corte de verdad esta en el controlador
      —esconder una opcion no cierra el POST—, pero ofrecer algo que va a
      rebotar es peor que no ofrecerlo.
    --}}
    <select name="rol" id="rol" required>
      {{--
        La opción vacía, y NO es un adorno: es la que impide un ascenso por
        descuido.

        Quien se autorregistra queda con el rol en cadena vacía, que no está en
        esta lista. Sin esta opción ninguna quedaba marcada, así que el navegador
        preseleccionaba la PRIMERA — «Administrador» para un administrador,
        «Director de escuela» para un director—, y abrir la ficha de un profesor
        que espera su rol y guardar sin bajar el desplegable lo convertía en
        administrador sin que nadie lo hubiera pedido ni visto.

        Va deshabilitada, así que con `required` el formulario no se envía hasta
        que alguien elija a propósito. Solo se pinta cuando hace falta: en una
        cuenta que ya tiene rol sería una opción muerta en mitad de la lista.
      --}}
      @if (old('rol', $perfil->rol) === '')
        <option value="" selected disabled>Sin rol asignado — elige uno</option>
      @endif
      @foreach ($roles as $valor)
        <option value="{{ $valor }}" @selected(old('rol', $perfil->rol) === $valor)>
          {{ \App\Models\Perfil::ROLES[$valor] }}
        </option>
      @endforeach
    </select>
    @error('rol')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
  </div>

  {{--
    Opcional a propósito: muchos de los matriculados son menores sin correo
    propio. Se avisa en el campo para que quien da el alta no se invente uno.
  --}}
  <div class="field">
    <label for="correo">Correo electrónico</label>
    <input type="email" name="correo" id="correo" maxlength="255" pattern="[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}" title="Un correo completo, con arroba y dominio. Ejemplo: nombre@correo.com"
           value="{{ old('correo', $perfil->user?->email) }}">
    <p class="campo-ayuda">Opcional. Puede repetirse: los hermanos suelen compartir el del acudiente.</p>
    @error('correo')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
  </div>

  <div class="field">
    <label for="nombre_completo">Nombre completo</label>
    <input type="text" name="nombre_completo" id="nombre_completo" maxlength="90" pattern="[\p{L}\p{M}][\p{L}\p{M} .'-]*" title="Solo letras, espacios, apóstrofo y guion. Sin números" required
           value="{{ old('nombre_completo', $perfil->nombre_completo) }}">
    @error('nombre_completo')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
  </div>

  <div class="field">
    <label for="fecha_nacimiento">Fecha de nacimiento</label>
    <input type="date" name="fecha_nacimiento" id="fecha_nacimiento" required
           value="{{ old('fecha_nacimiento', $perfil->fecha_nacimiento?->toDateString()) }}">
    @error('fecha_nacimiento')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
  </div>

  <div class="field">
    <label for="telefono">Teléfono</label>
    <input type="text" name="telefono" id="telefono" maxlength="10" inputmode="numeric" pattern="[0-9]{10}" title="10 dígitos, sin espacios ni guiones" required
           value="{{ old('telefono', $perfil->telefono) }}">
    @error('telefono')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
  </div>

  {{--
    Fuera del bloque de estudiante desde el 05/10/2026: el personal también
    tiene documento, y vive en su perfil. El del estudiante sigue en su ficha;
    el controlador decide dónde se guarda según el rol.
  --}}
  <div class="field">
    <label for="documento_identidad">Documento de identidad</label>
    <input type="text" name="documento_identidad" id="documento_identidad" maxlength="12" inputmode="numeric" pattern="[0-9]{6,12}" title="Solo números, entre 6 y 12 dígitos"
           value="{{ old('documento_identidad', $datos?->documento_identidad ?? $perfil->documento_identidad) }}">
    @error('documento_identidad')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
  </div>

  <div class="field">
    <label for="foto_perfil">Foto de perfil</label>
    @if ($perfil->exists && $perfil->foto_perfil)
      <div style="display:flex;align-items:center;gap:0.6rem;margin-bottom:0.4rem;">
        <img src="{{ route('ver-foto', $perfil) }}" alt="" class="foto-mini" style="width:40px;height:40px;">
        <span style="font-size:0.8rem;color:var(--ink-soft);">Foto actual — deja este campo vacío para conservarla.</span>
      </div>
    @endif
    <input type="file" name="foto_perfil" id="foto_perfil" accept="image/*">
    @error('foto_perfil')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
  </div>

  {{--
    Los datos de estudiante solo aplican a ese rol. Se pintan siempre y se
    ocultan con JavaScript en vez de recargar al cambiar el desplegable: sin
    sesión de red de por medio, el formulario responde al instante y sigue
    funcionando entero si el script no llega a ejecutarse.
  --}}
  <div id="campos-estudiante">
    <h4 style="margin-top:0;">Datos de estudiante</h4>

    <div class="field">
      <label for="acudiente_nombre">Nombre del acudiente</label>
      <input type="text" name="acudiente_nombre" id="acudiente_nombre" maxlength="90" pattern="[\p{L}\p{M}][\p{L}\p{M} .'-]*" title="Solo letras, espacios, apóstrofo y guion. Sin números"
             value="{{ old('acudiente_nombre', $acudiente?->nombre) }}">
      <div class="campo-ayuda">Obligatorio si el estudiante es menor de edad.</div>
      @error('acudiente_nombre')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
      @error('acudiente')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
    </div>

    <div class="field">
      <label for="acudiente_telefono">Teléfono del acudiente</label>
      <input type="text" name="acudiente_telefono" id="acudiente_telefono" maxlength="10" inputmode="numeric" pattern="[0-9]{10}" title="10 dígitos, sin espacios ni guiones"
             value="{{ old('acudiente_telefono', $acudiente?->telefono) }}">
      @error('acudiente_telefono')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
    </div>
  </div>

  {{--
    LOS DEPARTAMENTOS QUE DIRIGE. Desde el 12/09/2026 un director no ve la casa
    entera: ve lo de los departamentos que se le asignen, y este es el único
    sitio donde se asignan.

    Se pinta siempre y lo esconde el mismo guion que esconde los datos de
    estudiante, por la misma razón escrita ahí: sin viaje de red el formulario
    responde al instante y sigue funcionando entero si el script no llega.

    SON CASILLAS Y NO UN `<select multiple>`: un desplegable múltiple se maneja
    con ctrl+clic en escritorio —y un clic normal borra la selección anterior—,
    que en esta pantalla significa quitarle departamentos a alguien sin decirlo.
    Ya se descartó por lo mismo al repartir grupos, el 10/09.

    El `hidden` vacío de delante no sobra: sin él, desmarcarlas todas no manda
    nada y el servidor no puede distinguir «quítale todos» de «no llegó el
    campo». Es la misma trampa del modal de grupos.
  --}}
  <div id="campos-director">
    <h4>Departamentos que dirige</h4>
    <p class="campo-ayuda">
      Solo verá las promotorías de estos departamentos. Sin ninguno marcado no
      verá ninguna.
    </p>

    <input type="hidden" name="areas_dirigidas[]" value="">

    @foreach ($areas as $area)
      <label class="tema-opcion">
        <input type="checkbox" name="areas_dirigidas[]" value="{{ $area->id }}"
               @checked(in_array($area->id, old('areas_dirigidas', $dirigidas), false))>
        <span>{{ $area->nombre }}</span>
      </label>
    @endforeach

    @error('areas_dirigidas.*')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
  </div>

  {{--
    «Cancelar» sirve en los dos sitios: dentro del modal es lo que lo cierra, y
    en la página suelta es la salida que no obliga a subir hasta el «Volver».
  --}}
  <div class="modal-botones" style="margin-top:1.3rem;">
    <button type="submit" class="btn">Guardar</button>
    <a href="{{ route('usuario-lista') }}" class="btn btn-secundario" data-modal-cerrar>Cancelar</a>
  </div>


  {{--
    El guion va DENTRO del formulario y no después, y no es cosmético: el modal
    se lleva lo que esté marcado con `data-modal-cuerpo`, así que ahí fuera se
    quedaría en la página y dentro del diálogo el desplegable de rol no haría
    nada. Que además se ejecute al insertarlo lo resuelve `acciones.js`, porque
    un <script> insertado en el DOM no corre por sí solo.
  --}}
  <script>
  (function () {
    var rol = document.getElementById("rol");
    var camposEstudiante = document.getElementById("campos-estudiante");
    var camposDirector = document.getElementById("campos-director");
    if (!rol || !camposEstudiante) { return; }

    function actualizar() {
      camposEstudiante.style.display = (rol.value === "estudiante") ? "" : "none";

      // Los departamentos solo aplican al director. Sin este guion se ven
      // siempre, que es feo pero no rompe nada: el servidor los ignora para
      // cualquier otro rol.
      if (camposDirector) {
        camposDirector.style.display = (rol.value === "director") ? "" : "none";
      }
    }

    rol.addEventListener("change", actualizar);
    actualizar();
  })();
  </script>
</form>
@endsection
