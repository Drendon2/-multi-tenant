{{--
  El marcador de por qué no se dio una clase. Reusa las formas de `.estado` en
  vez de inventar otras (DESIGN.md, Status Marker): la palabra es lo que separa
  una causa de otra, y la forma acompaña. La falta por reponer va PUNTEADA
  —todavía falta algo, como «pendiente»— y la repuesta con punto, como una
  activa: ya se dio.
--}}
@if ($falta['causa'] === 'falta')
  @if ($falta['repuesta'])
    <span class="estado estado-activa">Falta · repuesta</span>
  @else
    <span class="estado estado-cancelacion">Falta · por reponer</span>
  @endif
@elseif ($falta['causa'] === 'excusa')
  <span class="estado estado-finalizada">Excusa</span>
@elseif ($falta['causa'] === 'institucion')
  <span class="estado estado-finalizada">Festivo o cierre</span>
@else
  <span class="estado estado-pendiente">Sin clasificar</span>
@endif
