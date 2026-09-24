{{--
  LA TABLA DE PROGRAMAS EXTERNOS.

  ES SU PROPIA PLANTILLA Y NO UN PARÁMETRO MÁS DE `tabla-actividades`, y la
  razón no es que «tenga campos distintos» sino que las dos columnas que
  importan son OTRAS. Allí, las dos columnas que gobiernan una actividad son el
  ENLACE y el CUPO —lo que abre la puerta y lo que la cierra—, y un programa
  externo no tiene ninguna de las dos: no hay puerta. Lo que lo gobierna es
  DÓNDE se dicta y QUIÉN da fe. Estirar aquella plantilla para que quepan las
  cuatro cosas, con la mitad apagada en cada fila, es exactamente cómo se acaba
  con una plantilla que pregunta de qué pantalla se trata — que es lo que su
  propia cabecera advierte.

  Lo que SÍ se comparte de verdad está compartido: `.tabla-personas`, el
  `.tipo-chip`, `partials.menu-fila` y el nombre como puerta.

  Recibe `actividades`, `ruta_editar`, `ruta_eliminar` y `modal` tal como los
  trae `ProgramaExternoController`.

  Directivas PHP en la forma de UNA LINEA, como el resto del proyecto:
  mezclarla con la de bloque deja sin compilar todo lo que quede en medio.
--}}
@if ($actividades->isEmpty())
  <p class="vacio">{{ $vacio_texto ?? 'Todavía no hay programas externos.' }}</p>
@else
<table class="tabla-personas tabla-catalogo tabla-menu-fila">
  <thead>
    <tr>
      <th>Programa</th>
      <th>Profesor</th>
      <th class="num">En la lista</th>
      <th><span class="sr-solo">Acciones</span></th>
    </tr>
  </thead>
  <tbody>
    @foreach ($actividades as $actividad)
    @php($apuntados = $actividad->inscritos_count)
    @php($cuantas = $actividad->sesiones_count)
    <tr>
      <td data-celda="detalle">
        {{--
          El nombre es la puerta, igual que en las otras dos listas de esta
          pantalla: entra a la ficha del programa, donde viven la lista, las
          clases y la asistencia.
        --}}
        <span class="lista-nombre">
          <a href="{{ route('panel-actividad', $actividad) }}">{{ $actividad->nombre }}</a>
        </span>
        <span class="tipo-chip">{{ $actividad->etiquetaTipo() }}</span>
        {{--
          DÓNDE SE DICTA, en su propio renglón y no como una columna más. Es el
          dato que distingue un programa externo de todo lo demás del catálogo
          —lo que lo define es que ocurre fuera— y en el teléfono, donde la fila
          es una ficha, una cuarta columna lo habría enterrado.

          Si la cuenta de esa institución está apagada, se dice AQUÍ: es lo que
          explica por qué sus clases dejaron de verificarse, y ese es el tipo de
          cosa que sin avisar nadie relaciona nunca —este sistema no le avisa a
          nadie de nada—.
        --}}
        <span class="lista-nota lista-nota-bloque">
          En {{ $actividad->institucion->nombre }}
          @if (! $actividad->institucion->perfil->user->activo)
            — <strong>su cuenta está apagada</strong>, así que no puede verificar.
          @endif
        </span>
      </td>
      <td data-label="Profesor">
        @if (\App\Support\Permisos::puedeVerFicha($yo, $actividad->responsable))
          <a href="{{ route('detalle-usuario', $actividad->responsable) }}">{{ $actividad->responsable->nombre_completo }}</a>
        @else
          {{ $actividad->responsable->nombre_completo }}
        @endif
      </td>
      <td class="num" data-label="En la lista">
        {{--
          UNA CIFRA PELADA Y SIN «/ ∞», al contrario que en las otras
          actividades. Allí el denominador es el cupo y su ausencia significa
          «sin tope», que es una decisión que alguien tomó. Aquí no hay cupo que
          poner: la lista es la gente que hay en ese salón, y pintarle un
          infinito sería inventar una decisión que nadie tomó.
        --}}
        <span class="cupo-cifra">{{ $apuntados }}</span>
        <span class="lista-nota lista-nota-bloque">
          @if ($apuntados === 0)
            <strong>Sin lista todavía.</strong> La arma el profesor allá.
          @else
            {{ $cuantas }} {{ $cuantas == 1 ? 'clase dada' : 'clases dadas' }}
          @endif
        </span>
      </td>
      <td data-celda="accion" class="lista-acciones lista-acciones-menu">
        <span class="accion-fila">
        {{--
          NO HAY BOTÓN A LA VISTA, al revés que en las otras actividades. Allí
          el que se queda fuera del menú es el del enlace, porque es lo que se
          hace con una actividad en marcha; aquí no hay enlace, y editar o
          borrar son las dos de montarla. Un botón suelto solo por simetría
          sería el borrado a un dedo de distancia en un teléfono.
        --}}
        @include('partials.menu-fila', [
            'etiqueta' => $actividad->nombre,
            'opciones' => [
                ['texto' => 'Editar', 'url' => route($ruta_editar, $actividad), 'modal' => $modal ?? false],
                [
                    'texto' => 'Eliminar',
                    'url' => route($ruta_eliminar, $actividad),
                    'modal' => true,
                    'borrar' => true,
                ],
            ],
        ])
        </span>
      </td>
    </tr>
    @endforeach
  </tbody>
</table>
@endif
