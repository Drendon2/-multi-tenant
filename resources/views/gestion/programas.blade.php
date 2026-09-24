@extends('layouts.app')

@section('title', 'Programas formativos')

@section('content')
{{--
  Las tres cosas que la institución ofrece, seguidas y en su orden real. El
  porqué de que esta pantalla exista está en `ProgramasController`.

  Cada sección incluye el MISMO parcial que usa la pantalla de ese catálogo, con
  lo que le da su propio controlador. Aquí no se consulta ni se maqueta nada por
  segunda vez: lo único propio de esta pantalla es el orden y las cabeceras.
--}}
<a href="{{ route('gestion-inicio') }}" class="volver">&larr; Gestión</a>
<h2>Programas formativos</h2>

{{--
  CADA SECCIÓN SE NOMBRA A SÍ MISMA, y las tres cosas de aquí abajo son la misma
  decisión: en esta pantalla hay tres listas seguidas y quien no la ve necesita
  poder saltar entre ellas.

  - `aria-labelledby` colgando del `<h3>` convierte cada `<section>` en un punto
    de referencia con nombre. Un `<section>` sin nombre no es ninguno: se
    anuncia como un contenedor más y no aparece en la lista de regiones.
  - Los tres botones de crear decían «+ Nuevo» los tres. Quien los recorre por
    teclado o con un lector oye «+ Nuevo, + Nuevo, + Nuevo» sin manera de saber
    cuál abre un departamento y cuál un taller. El texto visible se queda corto
    —el botón vive al lado de su título y ahí «+ Nuevo» se entiende— y el nombre
    accesible lo completa. El visible sigue contenido en el accesible, que es lo
    que pide el criterio de etiqueta en el nombre.
--}}
<section class="programa-seccion" aria-labelledby="seccion-departamentos">
  <div class="programa-cabecera">
    <h3 id="seccion-departamentos">Departamentos</h3>
    {{--
      SOLO EL ADMINISTRADOR desde el 12/09/2026: crear un departamento es una
      decisión de toda la casa, y un director está acotado a los suyos. Sin este
      corte el botón seguía pintado y la ruta —ya cerrada— lo devolvía rebotado:
      un botón que no hace nada, que es un fallo que este proyecto ya pagó.
    --}}
    @if ($yo->rol === 'administrador')
      <a class="btn btn-blanco btn-sm" href="{{ route('area-nueva') }}" data-modal
         aria-label="Nuevo departamento">+ Nuevo</a>
    @endif
  </div>
  <p class="campo-ayuda">
    Cada departamento agrupa sus promotorías, y cada promotoría sus grupos con
    horario. En las tres listas, el nombre entra.
  </p>

  @include('partials.tabla-catalogo', $departamentos + ['vacio_texto' => 'Todavía no hay departamentos. Crea el primero para poder abrir promotorías.'])

  {{--
    Las dos listas planas, como enlaces y no como fichas: por el árbol se llega
    a los grupos de UNA promotoría, y la lista completa de grupos es el único
    sitio del sistema donde se filtra por profesor. Sin este renglón esa
    capacidad no tendría puerta.
  --}}
  <p class="programa-atajos">
    <a href="{{ route('promotoria-lista') }}">Ver todas las promotorías</a>
    <span class="programa-atajo-sep">·</span>
    <a href="{{ route('grupo-lista') }}">Ver todos los grupos</a>
  </p>
</section>

<section class="programa-seccion" aria-labelledby="seccion-cursos">
  <div class="programa-cabecera">
    <h3 id="seccion-cursos">Cursos y talleres</h3>
    {{-- Crear es del administrador desde el 12/09/2026; ver `routes/web.php`. --}}
    @if ($yo->rol === 'administrador')
      <a class="btn btn-blanco btn-sm" href="{{ route('actividad-curso-nueva') }}" data-modal
         aria-label="Nuevo curso o taller">+ Nuevo</a>
    @endif
  </div>
  <p class="campo-ayuda">
    No pasan por matrícula: se entra por un enlace que alguien comparte, sin
    cuenta.
  </p>

  @include('partials.tabla-actividades', $cursos + ['vacio_texto' => 'Todavía no hay cursos ni talleres.'])
</section>

<section class="programa-seccion" aria-labelledby="seccion-proyeccion">
  <div class="programa-cabecera">
    <h3 id="seccion-proyeccion">Grupos de proyección</h3>
    {{-- Crear es del administrador desde el 12/09/2026; ver `routes/web.php`. --}}
    @if ($yo->rol === 'administrador')
      <a class="btn btn-blanco btn-sm" href="{{ route('actividad-proyeccion-nueva') }}" data-modal
         aria-label="Nuevo grupo de proyección">+ Nuevo</a>
    @endif
  </div>

  @include('partials.tabla-actividades', $proyeccion + ['vacio_texto' => 'Todavía no hay grupos de proyección.'])
</section>

{{--
  PROGRAMAS EXTERNOS Y SUS INSTITUCIONES, en ese orden y al final. Lo de arriba
  es lo que la casa ofrece EN la casa; esto es lo que la casa lleva AFUERA, y
  por eso va después y no intercalado entre los cursos y la proyección.

  Las dos secciones se leen seguidas porque una explica a la otra: no se puede
  abrir un programa externo sin tener registrada la institución donde se dicta,
  ya que su funcionario es quien verifica cada clase.
--}}
<section class="programa-seccion" aria-labelledby="seccion-externos">
  <div class="programa-cabecera">
    <h3 id="seccion-externos">Programas externos</h3>
    {{--
      EL BOTÓN SOLO SI HAY DÓNDE DICTAR. El formulario de un programa externo
      tiene un desplegable obligatorio —la institución— y sin ninguna registrada
      sale vacío: un formulario que no se puede enviar. Este proyecto ya pagó
      una vez el precio de pintar un botón que no hace nada, así que aquí lo que
      se ofrece en su lugar es el paso que de verdad toca, abajo.

      Y solo el administrador, como en las otras dos secciones: un director
      gestiona los que le asignen, pero crearlos poniendo de responsable a otro
      lo haría perderlos de vista en el mismo gesto.
    --}}
    @if ($yo->rol === 'administrador' && $externos['hay_instituciones'])
      <a class="btn btn-blanco btn-sm" href="{{ route('programa-externo-nuevo') }}" data-modal
         aria-label="Nuevo programa externo">+ Nuevo</a>
    @endif
  </div>
  <p class="campo-ayuda">
    Lo que un profesor de la casa va a dictar en otra institución. La lista de
    asistentes la escribe él allá —nombre y edad— y cada clase la verifica un
    funcionario de esa institución.
  </p>

  @include('partials.tabla-programas-externos', $externos + [
      'vacio_texto' => $externos['hay_instituciones']
          ? 'Todavía no hay programas externos.'
          : 'Todavía no hay programas externos. Registra abajo la institución donde se va a dictar y luego ábrele el programa.',
  ])
</section>

{{--
  Registrar una institución es del ADMINISTRADOR y de nadie más: es una entidad
  con la que la casa tiene convenio, no algo acotable a un departamento. Un
  director no ve esta sección — y no se la esconde nada más: sus rutas están
  todas en el grupo del administrador.
--}}
@if ($yo->rol === 'administrador')
<section class="programa-seccion" aria-labelledby="seccion-instituciones">
  <div class="programa-cabecera">
    <h3 id="seccion-instituciones">Instituciones externas</h3>
    {{--
      El nombre accesible CONTIENE el texto visible y empieza por él —«Nueva
      institución externa», no «Registrar institución externa»—. Es el criterio
      2.5.3 de WCAG: quien dicta por voz dice lo que LEE, y con la palabra fuera
      del nombre el control deja de responderle. Lo cazó `MenuDeFilaTest`, que
      recorre todos los controles de la pantalla comprobando justo esto.
    --}}
    <a class="btn btn-blanco btn-sm" href="{{ route('institucion-externa-nueva') }}" data-modal
       aria-label="Nueva institución externa">+ Nueva</a>
  </div>
  <p class="campo-ayuda">
    La escuela, el colegio o la fundación donde se dicta, con la cuenta de quien
    da fe allá de que el profesor fue. Su QR se imprime y se le entrega: con él,
    el profesor deja la clase verificada el mismo día, allá mismo.
  </p>

  @include('partials.tabla-instituciones-externas', $instituciones)
</section>
@endif
@endsection

@push('scripts')
<script src="@recurso('js/copiar-enlace.js')" defer></script>
@endpush
