@extends('layouts.app')

@section('title', 'Institución')

@section('content')
<a href="{{ route('gestion-inicio') }}" class="volver">&larr; Gestión</a>
<h2>Institución</h2>

<p class="aviso">
  La <strong>marca</strong> solo cambia cómo se ve el sistema: el nombre de la cabecera y los
  títulos, el logo de las pantallas públicas, y el color de acento del que salen botones, enlaces,
  foco y mensajes de éxito. La <strong>firma</strong> es la que sella los certificados de matrícula.
  Los <strong>datos de la entidad</strong> son lo que se publica en la página de tratamiento de
  datos y lo que se imprime en el formato que firman los estudiantes. Las
  <strong>reglas de matrícula</strong> sí cambian lo que los estudiantes pueden hacer. Nada de esto
  toca el catálogo académico.
</p>

<div class="card">
  <form method="post" action="{{ route('gestion-configuracion') }}" enctype="multipart/form-data">
    @csrf

    <fieldset class="config-seccion">
    <legend class="config-seccion-titulo">Marca</legend>

    <div class="config-campo">
      <label class="config-etiqueta" for="nombre_institucion">Nombre de la institución</label>
      <input type="text" name="nombre_institucion" id="nombre_institucion" maxlength="80" required
             value="{{ old('nombre_institucion', $institucion->nombre_institucion) }}">
      @error('nombre_institucion')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
      <p class="config-ayuda">Aparece en la cabecera y en los títulos de página.</p>
    </div>

    <div class="config-campo">
      <label class="config-etiqueta" for="logo">Logo</label>
      <div class="config-logo">
        <img class="config-logo-vista"
             src="{{ route('logo-institucion') }}?v={{ \App\Support\LogoInstitucion::version() }}"
             alt="Logo actual" width="64" height="64">
        <p class="config-ayuda">
          {{ \App\Support\LogoInstitucion::esPropio() ? 'Logo propio cargado.' : 'Sin logo propio: se muestran las iniciales de la institución sobre su color. Sube el logo para reemplazarlas.' }}
        </p>
      </div>
      <input class="config-logo-file" type="file" name="logo" id="logo" accept="image/*">
      <label class="config-logo-boton" for="logo">
        + {{ $institucion->logo ? 'Cambiar el logo' : 'Subir un logo' }}
      </label>
      <p class="config-logo-nombre" data-nombre-archivo="logo">{{ basename($institucion->logo) }}</p>
      @if ($institucion->logo)
      <label class="config-logo-quitar">
        <input type="checkbox" name="quitar_logo" value="1"> Quitar y volver al logo por defecto
      </label>
      @endif
      @error('logo')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
    </div>

    <div class="config-campo">
      <label class="config-etiqueta" for="color_acento">Color de acento</label>
      <div class="config-color">
        <input type="color" name="color_acento" id="color_acento"
               value="{{ old('color_acento', $institucion->color_acento) }}">
        <span class="config-hex">{{ $institucion->color_acento }}</span>
      </div>
      @error('color_acento')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
      <p class="config-ayuda">
        Único color de marca del sistema: botones, enlaces, foco y mensajes de éxito.
        Los tonos hover y de fondo se derivan de este automáticamente.
      </p>
      <div class="config-muestra">
        <span class="config-muestra-chip" style="background:{{ $institucion->color_acento }};">Acento</span>
        <span class="config-muestra-chip" style="background:{{ $institucion->color_acento_oscuro }};">Hover</span>
        <span class="config-muestra-chip config-muestra-chip-claro"
              style="background:{{ $institucion->color_acento_suave }};color:{{ $institucion->color_acento_oscuro }};">Fondo suave</span>
      </div>
      <p class="config-ayuda">Los dos últimos se derivan del acento automáticamente; no se configuran por separado.</p>
    </div>

    </fieldset>

    <fieldset class="config-seccion">
    <legend class="config-seccion-titulo">Firma para certificados</legend>

    <p class="config-ayuda" style="margin:0 0 1.2rem;">
      Un estudiante puede descargar su <strong>certificado de matrícula</strong> en PDF desde
      «Mi perfil». Lo que se cargue aquí es lo que aparece al pie de ese documento. Sin firma
      cargada el certificado se sigue generando, pero sale con el espacio de la firma en blanco.
    </p>

    <div class="config-campo">
      <label class="config-etiqueta" for="firma">Firma escaneada</label>
      <div class="config-logo">
        @if ($institucion->firma)
        <img class="config-firma-vista" src="{{ route('firma-institucion') }}" alt="Firma actual">
        @endif
        <p class="config-ayuda">
          {{ $institucion->firma
              ? 'Firma cargada.'
              : 'Todavía no hay firma. Escanea la firma sobre papel blanco y súbela.' }}
        </p>
      </div>
      <input class="config-logo-file" type="file" name="firma" id="firma" accept="image/*">
      <label class="config-logo-boton" for="firma">
        + {{ $institucion->firma ? 'Cambiar la firma' : 'Subir una firma' }}
      </label>
      <p class="config-logo-nombre" data-nombre-archivo="firma">{{ basename($institucion->firma) }}</p>
      @if ($institucion->firma)
      <label class="config-logo-quitar">
        <input type="checkbox" name="quitar_firma" value="1"> Quitar la firma
      </label>
      @endif
      @error('firma')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
      <p class="config-ayuda">
        Un PNG recortado con fondo transparente es lo que mejor queda: se apoya sobre la línea
        en vez de taparla con un recuadro blanco.
      </p>
    </div>

    <div class="config-campo">
      <label class="config-etiqueta" for="firmante_nombre">Nombre de quien firma</label>
      <input type="text" name="firmante_nombre" id="firmante_nombre" maxlength="120"
             value="{{ old('firmante_nombre', $institucion->firmante_nombre) }}">
      @error('firmante_nombre')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
    </div>

    <div class="config-campo">
      <label class="config-etiqueta" for="firmante_cargo">Cargo</label>
      <input type="text" name="firmante_cargo" id="firmante_cargo" maxlength="80"
             value="{{ old('firmante_cargo', $institucion->firmante_cargo) }}"
             placeholder="Directora">
      @error('firmante_cargo')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
      <p class="config-ayuda">
        Se imprimen bajo la firma, en ese orden. Un garabato escaneado sin nombre ni cargo
        no identifica a nadie ante quien recibe el certificado.
      </p>
    </div>

    </fieldset>

    {{--
      LOS DATOS DE LA ENTIDAD Y LA POLÍTICA DE TRATAMIENTO DE DATOS.

      Van aquí y no en una pantalla aparte porque son lo mismo que el resto de
      esta: identidad de la institución, editable sin tocar código. La Ley 1581
      de 2012 exige que la política identifique al responsable —nombre, NIT,
      dirección, correo y teléfono— y diga a dónde se dirige quien quiere
      conocer, actualizar o suprimir lo suyo. Nada de eso puede estar quemado en
      una plantilla: esto se instala para otras entidades.

      Los cuatro campos son opcionales para no dejar el sistema plantado tras
      actualizar, pero la página pública ESCONDE el renglón que falta en vez de
      pintar «Teléfono:» y nada, así que una entidad que no los rellene publica
      una política sin forma de contactarla. De ahí el aviso.
    --}}
    {{--
      VA PLEGADA porque creció demasiado: cuatro datos de contacto, dos
      finalidades, el texto entero de la política y los dos formatos. Desplegada
      empujaba hacia abajo todo lo que sí se viene a tocar a diario —la marca,
      el límite de promotorías, las alertas—.

      ARRANCA ABIERTA SI ALGUNO DE **SUS** CAMPOS TRAE ERROR, y acotado a ellos:
      con `$errors->any()` se abriría porque falló cualquier otro campo de la
      pantalla. Es la trampa escrita en CLAUDE.md — un `<details>` plegado
      esconde los errores de su formulario y el aviso de arriba te manda a
      buscar algo rojo que no se ve.

      Lleva `id` a propósito: `acciones.js` conserva abiertos los `<details>`
      que lo tienen, así que al guardar no se cierra sobre quien estaba
      escribiendo aquí.
    --}}
    @php($camposDeDatos = ['entidad_nit', 'entidad_direccion', 'entidad_correo',
                           'entidad_telefono', 'politica_datos', 'finalidad_datos', 'finalidad_imagen',
                           'consentimiento_mayor', 'consentimiento_menor',
                           'correo_servidor', 'correo_puerto', 'correo_cifrado',
                           'correo_usuario', 'correo_clave', 'correo_prueba'])
    <details class="perfil-seccion" id="bloque-datos-entidad" style="max-width:none;"
             @if ($errors->hasAny($camposDeDatos)) open @endif>
    <summary class="perfil-seccion-cabecera">
      <span class="perfil-seccion-icono icono-documento" aria-hidden="true">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/>
          <path d="M14 3v5h5"/>
        </svg>
      </span>
      <h3 style="margin:0;">Datos de la entidad y textos legales</h3>
      @if ($errors->hasAny($camposDeDatos))<span class="estado estado-pendiente">Hay algo por corregir</span>@endif
      <svg aria-hidden="true" class="perfil-seccion-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>
    </summary>

    <p class="config-ayuda" style="margin-top:0;">
      Se publican en la página de <a href="{{ route('politica-datos') }}">tratamiento de datos</a>,
      que es pública y a la que lleva el pie de todas las pantallas. Son los datos por los que
      alguien puede pedir que se corrijan o se borren los suyos.
    </p>

    <div class="config-campo">
      <label class="config-etiqueta" for="entidad_nit">NIT</label>
      <input type="text" name="entidad_nit" id="entidad_nit" maxlength="40"
             value="{{ old('entidad_nit', $institucion->entidad_nit) }}">
      @error('entidad_nit')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
    </div>

    <div class="config-campo">
      <label class="config-etiqueta" for="entidad_direccion">Dirección</label>
      <input type="text" name="entidad_direccion" id="entidad_direccion" maxlength="160"
             autocomplete="street-address"
             value="{{ old('entidad_direccion', $institucion->entidad_direccion) }}">
      @error('entidad_direccion')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
    </div>

    <div class="config-campo">
      <label class="config-etiqueta" for="entidad_correo">Correo de contacto</label>
      <input type="email" name="entidad_correo" id="entidad_correo" maxlength="120" pattern="[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}" title="Un correo completo, con arroba y dominio. Ejemplo: nombre@correo.com"
             value="{{ old('entidad_correo', $institucion->entidad_correo) }}">
      @error('entidad_correo')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
      <p class="config-ayuda">
        Es la dirección a la que se pide conocer, actualizar o borrar los datos. Sin ella, la
        política sale diciendo «escribiendo a la dirección de contacto de la institución», que
        no le sirve a nadie.
      </p>
    </div>

    <div class="config-campo">
      <label class="config-etiqueta" for="entidad_telefono">Teléfono</label>
      <input type="text" name="entidad_telefono" id="entidad_telefono" maxlength="40" inputmode="tel" pattern="[0-9+(][0-9 ()+.-]*( ?([eE][xX][tT])[.]? ?[0-9]{1,6})?" title="Números, y si hace falta espacios, paréntesis o una extensión. Ejemplo: 604 555 1234 ext. 102"
             autocomplete="tel"
             value="{{ old('entidad_telefono', $institucion->entidad_telefono) }}">
      @error('entidad_telefono')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
    </div>

    {{--
      LAS DOS FINALIDADES. Es lo único de este bloque que sale también en el
      papel que se firma, y por eso la ayuda enseña la frase entera: quien
      escribe tiene que ver dónde cae lo suyo, porque cada una se incrusta en
      dos oraciones distintas y una forma gramatical equivocada las rompe.
    --}}
    <div class="config-campo">
      <label class="config-etiqueta" for="finalidad_datos">Para qué se tratan los datos</label>
      <input type="text" name="finalidad_datos" id="finalidad_datos" maxlength="255"
             placeholder="{{ \App\Models\ConfiguracionInstitucion::FINALIDAD_DATOS }}"
             value="{{ old('finalidad_datos', $institucion->finalidad_datos) }}">
      @error('finalidad_datos')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
      <p class="config-ayuda">
        Se lee en los dos sitios, así que <strong>escríbelo como un complemento</strong>
        («el análisis…», «la caracterización…»):<br>
        en la política — «Para <em>{{ $institucion->finalidadDeDatos() }}</em>. Los datos se usan agregados…»<br>
        en el formato firmado — «…y para <em>{{ $institucion->finalidadDeDatos() }}</em>, incluyendo su entrega
        a las autoridades competentes.»
      </p>
    </div>

    <div class="config-campo">
      <label class="config-etiqueta" for="finalidad_imagen">Para qué se usa la imagen</label>
      <input type="text" name="finalidad_imagen" id="finalidad_imagen" maxlength="255"
             placeholder="{{ \App\Models\ConfiguracionInstitucion::FINALIDAD_IMAGEN }}"
             value="{{ old('finalidad_imagen', $institucion->finalidad_imagen) }}">
      @error('finalidad_imagen')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
      <p class="config-ayuda">
        Esta va <strong>en infinitivo</strong> («comunicar…», «difundir…»):<br>
        en la política — «Para <em>{{ $institucion->finalidadDeImagen() }}</em>, usando tu imagen en piezas
        informativas…»<br>
        en el formato firmado — «…con el fin de <em>{{ $institucion->finalidadDeImagen() }}</em>.»
      </p>
    </div>

    <div class="config-campo">
      <label class="config-etiqueta" for="politica_datos">Texto de la política</label>
      <textarea name="politica_datos" id="politica_datos" rows="14" class="config-politica"
                >{{ old('politica_datos', $institucion->politica_datos) }}</textarea>
      @error('politica_datos')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
      <p class="config-ayuda">
        @if ($politicaEsLaDeFabrica)
          <strong>Vacío significa que se publica el texto de fábrica</strong>, escrito sobre la
          Ley 1581 de 2012 y el Decreto 1377 de 2013, con el nombre y el contacto de esta
          entidad ya dentro. Se pone al día solo cuando cambias esos datos.
          Escribe aquí únicamente si tu entidad tiene su propia política aprobada; para volver
          al texto de fábrica, vacía el campo.
        @else
          Estás publicando un texto propio. Vacía el campo para volver al de fábrica, que se
          escribe solo con el nombre y el contacto de esta entidad.
        @endif
        Se admite un formato mínimo: una línea que empiece por <code>##</code> es un título,
        una que empiece por <code>-</code> es una viñeta, y lo demás son párrafos separados por
        una línea en blanco.
      </p>
    </div>

    {{-- LAS DOS VERSIONES SE PINTAN CON EL MISMO BUCLE, y eso es lo que hace
         que no se separen. Son dos ranuras independientes —se puede subir la
         del menor y dejar que el sistema imprima la del mayor— y escritas dos
         veces a mano acabarían pidiendo cosas distintas sin que nada fallara.

         El enlace de descarga se pinta siempre y baja LO QUE DE VERDAD RECIBE
         el estudiante: el formato propio si lo hay y el del sistema si no. Es
         la única forma de comprobar que lo que se subió es lo que llega. --}}
    <div class="config-campo">
      <span class="config-etiqueta">Formato de autorización</span>
      <p class="config-ayuda" style="margin-top:0.2rem;">
        Es el papel que el estudiante descarga, firma y sube. Hay dos versiones y no son
        el mismo papel con otro título: un menor de edad no otorga esta autorización por
        sí mismo, la da su acudiente, así que ese formato identifica a dos personas.
      </p>
      <p class="config-ayuda">
        <strong>Si no subes nada, el sistema los imprime</strong> con el logo, el nombre
        y las finalidades de esta entidad, y con los datos de cada estudiante ya escritos.
        Sube el tuyo solo si tu entidad tiene su propio formato aprobado. El que subas
        <strong>va en blanco</strong> —el sistema no puede escribir dentro de un archivo
        ajeno— y de que quepa en una hoja respondes tú: el papel que se firma en la
        primera pierde la segunda. Se admite PDF o una foto del papel, que se convierte
        a PDF al guardarla.
      </p>
    </div>

    @foreach (['mayor' => 'Mayor de edad', 'menor' => 'Menor de edad'] as $version => $rotulo)
      @php($campo = 'consentimiento_'.$version)
      @php($propio = $institucion->formatoPropio($version))
      <div class="config-campo">
        <label class="config-etiqueta" for="{{ $campo }}">Formato de {{ mb_strtolower($rotulo) }}</label>
        <p class="config-ayuda" style="margin-top:0.2rem;">
          {{ $propio ? 'Se está entregando el formato que subió la entidad.' : 'Lo imprime el sistema.' }}
        </p>
        <p class="accion-fila">
          <a class="btn btn-blanco btn-sm" href="{{ route('consentimiento-formato', $version) }}">
            Ver el que se entrega
          </a>
        </p>
        <input class="config-logo-file" type="file" name="{{ $campo }}" id="{{ $campo }}"
               accept="application/pdf,image/*">
        <label class="config-logo-boton" for="{{ $campo }}">
          + {{ $propio ? 'Cambiar el formato' : 'Subir un formato propio' }}
        </label>
        <p class="config-logo-nombre" data-nombre-archivo="{{ $campo }}">{{ basename($propio) }}</p>
        @if ($propio)
        <label class="config-logo-quitar">
          <input type="checkbox" name="quitar_{{ $campo }}" value="1">
          Quitar y volver al que imprime el sistema
        </label>
        @endif
        @error($campo)<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
      </div>
    @endforeach

    </details>

    {{--
      EL SERVIDOR DE CORREO.

      Va en su propia sección y no dentro de «Datos de la entidad» porque no es
      un dato de la entidad: es la fontanería que hace que funcione una cosa
      concreta —el enlace de «olvidé mi contraseña»— y quien viene a esto viene
      a eso, no a cambiar el NIT.

      Y NO va plegada, al contrario que sus vecinas, porque el estado importa
      más que los campos: lo primero que hay que poder ver de un vistazo es si
      la recuperación de contraseña funciona o no.
    --}}
    <fieldset class="config-seccion">
    <legend class="config-seccion-titulo">Correo</legend>

    <div class="config-campo">
      <p class="config-ayuda" style="margin-top:0;">
        El sistema envía <strong>un solo correo</strong>: el enlace de «Olvidé mi contraseña»
        de la pantalla de entrar. No manda avisos de matrícula ni notificaciones de ningún tipo.
      </p>
      {{--
        DICE «HAY UN SERVIDOR CONFIGURADO» Y NO «FUNCIONA», y la diferencia no
        es de matiz: lo único que este renglón puede saber es que los campos
        están llenos, no que ese servidor conteste ni que la contraseña sea la
        buena. Decía «está funcionando» y se vio en el navegador diciéndolo
        justo debajo del aviso de que el envío de prueba acababa de fallar.
        Lo único que responde esa pregunta es mandarse una prueba, y por eso
        este renglón la pide.
      --}}
      @if ($correoActivo)
        <p class="config-ayuda">
          <strong>Hay un servidor de correo configurado</strong>, {{ $correoDeDonde }}.
          Que conteste de verdad solo lo dice una prueba: mándate una desde el campo de abajo.
        </p>
      @else
        <p class="config-ayuda" style="color:var(--danger);">
          <strong>La recuperación de contraseña NO funciona.</strong> Sin servidor de correo, quien
          la pida ve la misma pantalla de siempre y el enlace no le llega a nadie — no avisa de que
          está apagada. Llena estos campos con los datos del buzón de la institución.
        </p>
      @endif
    </div>

    <div class="config-campo">
      <label class="config-etiqueta" for="correo_servidor">Servidor</label>
      <input type="text" name="correo_servidor" id="correo_servidor" maxlength="160"
             inputmode="url" autocapitalize="none" spellcheck="false"
             placeholder="smtp.hostinger.com"
             value="{{ old('correo_servidor', $institucion->correo_servidor) }}">
      @error('correo_servidor')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
      <p class="config-ayuda">
        Solo el nombre, sin <code>https://</code>. Te lo da tu proveedor de correo.
      </p>
    </div>

    <div class="config-campo">
      <label class="config-etiqueta" for="correo_cifrado">Cifrado y puerto</label>
      <div class="config-color">
        <select name="correo_cifrado" id="correo_cifrado" style="width:auto;">
          <option value="smtps" @selected(old('correo_cifrado', $institucion->correo_cifrado) === 'smtps')>SSL (465)</option>
          <option value="smtp" @selected(old('correo_cifrado', $institucion->correo_cifrado) === 'smtp')>STARTTLS (587)</option>
        </select>
        <input type="number" name="correo_puerto" id="correo_puerto" min="1" max="65535" step="1" required
               style="width:7rem;" value="{{ old('correo_puerto', $institucion->correo_puerto) }}">
      </div>
      @error('correo_cifrado')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
      @error('correo_puerto')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
      <p class="config-ayuda">
        Casi siempre SSL con el puerto 465. Si tu proveedor pide STARTTLS, cambia también el
        puerto a 587.
      </p>
    </div>

    <div class="config-campo">
      <label class="config-etiqueta" for="correo_usuario">Dirección del buzón</label>
      <input type="email" name="correo_usuario" id="correo_usuario" maxlength="160"
             autocapitalize="none" spellcheck="false"
             placeholder="admin@tu-dominio.com"
             value="{{ old('correo_usuario', $institucion->correo_usuario) }}">
      @error('correo_usuario')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
      <p class="config-ayuda">
        Es a la vez el usuario con el que se entra al buzón y el remitente que verá quien reciba
        el correo. No hay un campo aparte para el remitente a propósito: casi todos los
        proveedores rechazan uno distinto del buzón, y son dos casillas para escribir lo mismo.
      </p>
    </div>

    {{--
      LA CONTRASEÑA NO SE PINTA NUNCA, ni siquiera con puntos: `value` vacío
      siempre. Si se pintara, la contraseña del buzón de la institución estaría
      en el código fuente de una página que abre cualquier administrador — el
      tipo `password` solo la esconde a la vista.

      Por eso vacío significa «deja la que hay», como el campo de archivo del
      logo, y para quitarla se vacía el servidor o la dirección (lo hace el
      controlador).
    --}}
    <div class="config-campo">
      <label class="config-etiqueta" for="correo_clave">Contraseña del buzón</label>
      <input type="password" name="correo_clave" id="correo_clave" maxlength="255"
             autocomplete="new-password" value=""
             placeholder="{{ $institucion->correo_clave ? 'Ya hay una guardada — escribe aquí solo si la vas a cambiar' : '' }}">
      @error('correo_clave')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
      <p class="config-ayuda">
        Es la contraseña <strong>del buzón</strong>, no la del panel de tu proveedor de hosting.
        Se guarda cifrada y no se puede volver a leer desde aquí; para reemplazarla, escribe la
        nueva. Vaciando el servidor o la dirección se borra.
      </p>
    </div>

    <div class="config-campo">
      <label class="config-etiqueta" for="correo_prueba">Enviar una prueba a</label>
      <input type="email" name="correo_prueba" id="correo_prueba" maxlength="160"
             autocapitalize="none" spellcheck="false" placeholder="tu-correo-personal@ejemplo.com"
             value="">
      @error('correo_prueba')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
      <p class="config-ayuda">
        Escribe aquí un correo tuyo y al guardar se te manda una prueba con lo que acabas de
        poner. <strong>Hazlo</strong>: si algo está mal, esta es la única forma de enterarte hoy
        y no el día que alguien pierda su contraseña. El campo se vacía solo; no se guarda.
      </p>
    </div>

    </fieldset>

    <fieldset class="config-seccion">
    <legend class="config-seccion-titulo">Reglas de matrícula</legend>

    <div class="config-campo">
      <label class="config-etiqueta" for="limite_promotorias_por_periodo">
        Promotorías por estudiante y periodo
      </label>
      <input type="number" name="limite_promotorias_por_periodo" id="limite_promotorias_por_periodo"
             min="1" max="{{ \App\Models\ConfiguracionInstitucion::RANURA_MAXIMA_ABSOLUTA }}" step="1" required
             value="{{ old('limite_promotorias_por_periodo', $institucion->limite_promotorias_por_periodo) }}">
      @error('limite_promotorias_por_periodo')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
      <p class="config-ayuda">
        Cuántas promotorías puede cursar un mismo estudiante en un periodo.
        Cuentan las matrículas pendientes y las activas; las retiradas liberan cupo.
        Bajarlo no retira ni rompe las matrículas que ya existen: solo impide pedir
        más a quien ya esté en el nuevo límite o por encima.
      </p>
    </div>

    <div class="config-campo">
      <label class="config-interruptor">
        <input type="checkbox" name="promotorias_visibles_para_estudiantes" value="1"
               @checked(old('promotorias_visibles_para_estudiantes', $institucion->promotorias_visibles_para_estudiantes))>
        <span class="config-etiqueta">Los estudiantes ven el catálogo de promotorías</span>
      </label>
      <p class="config-ayuda">
        Con esto apagado, el estudiante no ve la pantalla para matricularse por su
        cuenta y solo consulta lo que ya tiene. Sirve para las instituciones que
        inscriben en ventanilla. No afecta a las matrículas ya hechas.
      </p>
    </div>

    </fieldset>

    {{--
      Las alertas van en su propio grupo, y no sueltas con las de arriba: son
      avisos que el sistema DEDUCE, no reglas que cambien lo que la gente puede
      hacer. Apagarlas no cambia ninguna matrícula ni ningún cupo; solo deja de
      mirar. Por eso también se pueden apagar: hay instituciones que llevan la
      asistencia en papel y para las que estos avisos serían ruido.
    --}}
    <fieldset class="config-seccion">
      <legend class="config-seccion-titulo">Alertas</legend>

      <div class="config-campo">
        <label class="config-interruptor">
          <input type="checkbox" name="alerta_clase_no_dictada" value="1"
                 @checked(old('alerta_clase_no_dictada', $institucion->alerta_clase_no_dictada))>
          <span class="config-etiqueta">Avisar de las clases que no se dictaron</span>
        </label>
        <p class="config-ayuda">
          Si un grupo tenía clase el martes y nadie la registró, aparece en
          <strong>Alertas y cancelaciones</strong> al día siguiente. Quien dicta
          tiene todo el día para iniciarla y pasar lista.
        </p>
      </div>

      <div class="config-campo">
        <label class="config-interruptor">
          <input type="checkbox" name="alerta_abandono" value="1"
                 @checked(old('alerta_abandono', $institucion->alerta_abandono))>
          <span class="config-etiqueta">Avisar de posibles abandonos</span>
        </label>
        <p class="config-ayuda">
          Un estudiante que falta varias clases seguidas sin excusa aparece en la
          misma pantalla. <strong>No se retira a nadie solo</strong>: el aviso
          trae la acción al lado y la decide dirección.
        </p>
      </div>

      {{--
        EL RECORDATORIO DE LA ENCUESTA. Va aquí y no con las alertas de arriba
        aunque comparta la forma: aquellas avisan al PERSONAL de algo que pasó,
        y este le pide algo a la persona misma. Comparten pantalla porque los dos
        son «qué le enseña el sistema a quién», que es lo que se viene a decidir
        a Institución.
      --}}
      <div class="config-campo">
        <label class="config-interruptor">
          <input type="checkbox" name="recordar_encuesta" value="1"
                 @checked(old('recordar_encuesta', $institucion->recordar_encuesta))>
          <span class="config-etiqueta">Recordar la encuesta a quien no la ha contestado</span>
        </label>
        <p class="config-ayuda">
          A quien le falte alguna pregunta le sale un aviso al entrar, con el
          número de preguntas que le quedan y un botón para terminarla.
          <strong>No obliga ni bloquea nada</strong>, y quien ya la contestó no
          ve nada. Apágalo si prefieres no pedirlo.
        </p>
      </div>

      {{--
        EL CORREO, obligatorio o no. Va junto a la encuesta porque es la misma
        pregunta —qué le exige el sistema a la persona— y no junto a los datos
        de contacto de la entidad, que son un dato y no una regla.

        Nace apagado, y el aviso de abajo dice por qué importa: encenderlo no
        afecta solo a quien se inscriba mañana, sino a la ficha de todos los que
        ya están sin correo. Ese número se pinta con el dato real delante para
        que la decisión se tome sabiéndolo, y no se descubra al primer rechazo.
      --}}
      <div class="config-campo">
        <label class="config-interruptor">
          <input type="checkbox" name="correo_obligatorio" value="1"
                 @checked(old('correo_obligatorio', $institucion->correo_obligatorio))>
          <span class="config-etiqueta">Exigir el correo electrónico</span>
        </label>
        <p class="config-ayuda">
          Apagado, el correo es opcional en todos los formularios. Es lo normal
          aquí: buena parte de quien se inscribe son menores sin correo propio.
          @if ($sinCorreo > 0)
            <br><strong>Ojo si lo enciendes:</strong> hoy hay
            <strong>{{ $sinCorreo }}</strong> {{ $sinCorreo == 1 ? 'persona' : 'personas' }}
            sin correo guardado. Su ficha no se podrá guardar hasta ponérselo,
            y eso incluye a quien solo venga a cambiarle el rol.
          @endif
        </p>
      </div>

      {{--
        DESDE CUÁNDO cuentan las dos.

        Un periodo académico incluye su periodo de matrículas: semanas de
        inscribir gente y armar grupos antes de que nadie dé una clase. Contando
        desde el inicio del periodo, la bandeja se llenaba de días en los que
        nadie tenía que dar clase — 596 avisos el primer día en producción.

        Vive aquí, junto a los interruptores, porque quien enciende una alerta es
        quien decide desde cuándo cuenta. El riesgo de tenerla aquí y no en el
        periodo es que se quede vieja al cambiar de semestre; por eso el aviso de
        abajo, que es lo que impide que apague las alertas en silencio.
      --}}
      <div class="config-campo">
        <label class="config-etiqueta" for="alertas_desde">
          Las alertas empiezan el
        </label>
        <input type="date" name="alertas_desde" id="alertas_desde" style="max-width:14rem;"
               value="{{ old('alertas_desde', $institucion->alertas_desde?->format('Y-m-d')) }}">
        @error('alertas_desde')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
        <p class="config-ayuda">
          El día en que empiezan las clases. Antes de esa fecha no hay clase que
          registrar ni falta que acumular, así que no se avisa de nada.
          <strong>Déjala vacía</strong> para contar desde el inicio del periodo en
          curso.
          @if ($periodoEnCurso)
            @php($desde = $institucion->alertas_desde)
            @if ($desde && $desde->gt($periodoEnCurso->fecha_fin))
              <br><strong style="color:var(--danger);">Está después de que termine
              {{ $periodoEnCurso->nombre }}</strong> ({{ $periodoEnCurso->fecha_fin->format('d/m/Y') }}),
              así que ahora mismo no sale ninguna alerta. Muévela al empezar cada periodo.
            @elseif ($desde && $desde->lt($periodoEnCurso->fecha_inicio))
              <br>Es anterior a {{ $periodoEnCurso->nombre }}, que empezó el
              {{ $periodoEnCurso->fecha_inicio->format('d/m/Y') }}: se cuenta desde ese día.
            @endif
          @endif
        </p>
      </div>

      <div class="config-campo">
        <label class="config-etiqueta" for="faltas_para_abandono">
          Faltas seguidas para avisar
        </label>
        <input type="number" name="faltas_para_abandono" id="faltas_para_abandono"
               min="2" max="20" required style="max-width:8rem;"
               value="{{ old('faltas_para_abandono', $institucion->faltas_para_abandono) }}">
        @error('faltas_para_abandono')<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
        <p class="config-ayuda">
          Solo cuentan las faltas <strong>sin excusa</strong>: una excusa corta la
          racha, porque avisar de que no se puede ir es lo contrario de
          desaparecer. El número justo depende de cada cuánto se ve el grupo — uno
          que se reúne una vez por semana no aguanta lo mismo que uno de tres.
        </p>
      </div>
    </fieldset>

    <button type="submit" class="btn">Guardar</button>
  </form>
</div>

{{--
  Los documentos van FUERA del formulario de arriba y con los suyos propios:
  agregar un papel y desactivarlo son acciones sueltas que se resuelven solas, no
  un campo que se guarde junto con el color de la marca. Anidar formularios,
  además, es HTML inválido.
--}}
<div class="card" style="margin-top:1.4rem;">
  <h3 style="margin-top:0;">Documentos para matricularse</h3>
  <p class="config-ayuda" style="margin-top:-0.4rem;">
    Los papeles que esta institución exige. El estudiante los sube desde <strong>Mi perfil</strong>,
    y a quien le falte alguno obligatorio le sale una etiqueta en el panel del profesor y en su
    ficha. Todos se configuran aquí, incluida la copia del documento de identidad.
  </p>

  @if (count($documentos))
  <table>
    <thead>
      <tr><th>Documento</th><th>Obligatorio</th><th>Entregados</th><th></th></tr>
    </thead>
    <tbody>
      @foreach ($documentos as $d)
      <tr>
        <td>
          {{ $d->nombre }}
          @if (! $d->activo)<span class="estado estado-pendiente">Ya no se pide</span>@endif
          @if ($d->descripcion)<span class="campo-info" style="margin:0;display:block;">{{ $d->descripcion }}</span>@endif
        </td>
        <td>@if ($d->obligatorio)Sí @else<span class="vacio">Opcional</span>@endif</td>
        <td>{{ $d->entregados }}</td>
        <td style="text-align:right;white-space:nowrap;">
          <form action="{{ route('documento-requerido-alternar', $d) }}" method="post" style="display:inline;">
            @csrf
            {{--
              «Dejar de pedir» y no «Eliminar»: los archivos que ya subieron los
              estudiantes cuelgan de esta fila, y borrarla se llevaría la prueba
              de que en su momento cumplieron.
            --}}
            <button type="submit" class="btn btn-secundario btn-sm">
              {{ $d->activo ? 'Dejar de pedir' : 'Volver a pedir' }}
            </button>
          </form>
        </td>
      </tr>
      @endforeach
    </tbody>
  </table>
  @else
    <p class="vacio">No se pide ningún documento.</p>
  @endif

  <form action="{{ route('documento-requerido-nuevo') }}" method="post" class="doc-alta">
    @csrf
    <div class="doc-alta-campo doc-alta-ancho">
      <label class="config-etiqueta" for="doc-nombre">Nombre</label>
      <input type="text" name="nombre" id="doc-nombre" maxlength="60" required placeholder="Certificado de EPS">
    </div>
    <div class="doc-alta-campo doc-alta-ancho">
      <label class="config-etiqueta" for="doc-descripcion">Aclaración</label>
      <input type="text" name="descripcion" id="doc-descripcion" maxlength="120"
             placeholder="Vigencia no mayor a 30 días (opcional)">
    </div>
    <div class="doc-alta-campo">
      <label class="config-etiqueta" for="doc-orden">Orden</label>
      <input type="number" name="orden" id="doc-orden" min="0" step="1" value="0" style="width:5rem;" required>
    </div>
    <label class="config-interruptor doc-alta-campo">
      <input type="checkbox" name="obligatorio" value="1" checked>
      <span class="config-etiqueta">Obligatorio</span>
    </label>
    <button type="submit" class="btn btn-sm">+ Agregar documento</button>
  </form>
</div>

<script>
  // El input de archivo va oculto y la etiqueta hace de control (ver
  // .config-logo-file en app.css), así que el navegador ya no escribe por su
  // cuenta el nombre del archivo elegido. Sin esta línea, tras escoger un logo
  // la pantalla se quedaría exactamente igual que antes de escogerlo.
  (function () {
    // Cada destino dice de qué input escucha, porque ya son dos —el logo y la
    // firma— y un querySelector suelto emparejaría los dos con el primero.
    document.querySelectorAll("[data-nombre-archivo]").forEach(function (destino) {
      var entrada = document.getElementById(destino.dataset.nombreArchivo);
      if (!entrada) { return; }
      var original = destino.textContent.trim();
      entrada.addEventListener("change", function () {
        destino.textContent = entrada.files.length ? entrada.files[0].name : original;
      });
    });
  })();
</script>
@endsection
