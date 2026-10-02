<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>{{ $titulo }}</title>
{{--
  La hoja de carnés para imprimir: NUEVE por hoja carta, 3×3 (decisión del
  usuario, 25/09/2026). Cada carné es el MISMO dibujo que se descarga uno a uno
  (`CarneQr::carne`), pasado a JPEG porque dompdf lo incrusta sin reprocesarlo
  (`CarneQr::comoJpeg`), y encajado en su celda sin deformarlo: dos trazados del
  mismo carné se separarían en cuanto uno creciera un renglón.

  Una tabla por hoja y no una sola tabla larga: dompdf parte mal una fila de
  tabla entre dos páginas, y una fila partida es un carné cortado por la mitad
  que nadie puede usar. El alto de fila va fijo por lo mismo.

  Las medidas del carné van en CSS y EN PUNTOS, no en los atributos
  `width`/`height`: dompdf lee esos atributos como píxeles a 96 ppp, o sea tres
  cuartos de punto, y el carné salía a tres cuartos de la celda sin fallar.

  La línea discontinua es la guía de corte, y va en el borde de la CELDA y no
  del carné: así todas las líneas de una hoja quedan alineadas, que es lo que
  deja cortar una fila entera de un tijeretazo.

  El pie va DETRÁS de la tabla, dentro de la hoja, y no con `position: fixed`:
  dompdf repite un fijo idéntico en todas las páginas, y aquí cada hoja dice su
  grupo. Su alto ya se le restó a las filas en el controlador.
--}}
<style>
  @page { margin: {{ $margen }}pt; }
  body { margin: 0; font-family: sans-serif; }
  table.hoja { width: 100%; border-collapse: collapse; table-layout: fixed; }
  div.pie {
    height: {{ $altoPie }}pt;
    line-height: {{ $altoPie }}pt;
    font-size: 9pt;
    color: #333333;
    text-align: center;
  }
  div.pie.sigue { page-break-after: always; }
  td.celda {
    width: {{ $anchoCelda }}pt;
    height: {{ $altoCelda }}pt;
    border: 0.6pt dashed #9a9a9a;
    text-align: center;
    vertical-align: middle;
    padding: 0;
  }
  td.celda img { display: inline-block; }
</style>
</head>
<body>
@foreach ($hojas as $hoja)
<table class="hoja">
  @foreach ($hoja['filas'] as $fila)
  <tr>
    @foreach ($fila as $carne)
    <td class="celda">
      @if ($carne)
      <img src="data:image/jpeg;base64,{{ $carne['jpeg'] }}" style="width: {{ $carne['ancho'] }}pt; height: {{ $carne['alto'] }}pt;" alt="">
      @endif
    </td>
    @endforeach
  </tr>
  @endforeach
</table>
<div class="pie {{ $loop->last ? '' : 'sigue' }}">{{ $hoja['pie'] }}</div>
@endforeach
</body>
</html>
