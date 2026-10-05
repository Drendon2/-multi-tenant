{{--
  La rejilla semanal: dónde tiene que estar esta persona cada día.

  Una fila por franja horaria y una columna por día, de lunes a domingo. Las
  franjas son las que de verdad se usan y no las horas del reloj: una casa que
  solo da clase a las 4 y a las 6 no tiene por qué enseñar catorce filas vacías.

  La tabla se desborda en horizontal dentro de su propio contenedor en pantallas
  estrechas. Es a propósito: un horario semanal no cabe en un móvil sin
  encogerlo hasta que no se lee, y prefiero que se arrastre a que se apelmace.

  Contexto:
    $horario ... salida de HorarioSemanal::de()
    $titulo .... encabezado de la sección
    $periodo ... el periodo en curso, para decir de cuándo es
--}}
<div class="perfil-seccion">
  <div class="perfil-seccion-cabecera">
    <span class="perfil-seccion-icono icono-horario" aria-hidden="true">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <rect x="3" y="4" width="18" height="17" rx="2"/>
        <path d="M3 9h18M8 2v4M16 2v4"/>
      </svg>
    </span>
    <h3>{{ $titulo }}</h3>
    @if ($periodo)<span class="historial-marca">{{ $periodo->nombre }}</span>@endif
  </div>

  <div class="horario-scroll">
    <table class="horario-rejilla">
      <thead>
        <tr>
          <th class="horario-hora">Hora</th>
          @foreach ($horario['dias'] as $numero => $corto)
            <th>{{ $corto }}</th>
          @endforeach
        </tr>
      </thead>
      <tbody>
        @foreach ($horario['franjas'] as $franja)
        <tr>
          <th class="horario-hora">{{ $franja['etiqueta'] }}</th>
          @foreach ($horario['dias'] as $numero => $corto)
          <td>
            @foreach ($franja['celdas'][$numero] as $clase)
            <div class="horario-clase">
              <span class="tag-dot {{ $clase['color'] }}"></span>{{ $clase['titulo'] }}
              <span class="horario-detalle">{{ $clase['detalle'] }}</span>
            </div>
            @endforeach
          </td>
          @endforeach
        </tr>
        @endforeach
      </tbody>
    </table>
  </div>
</div>
