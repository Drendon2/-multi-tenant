{{-- El programa externo de una fila de alertas: qué, dónde y quién va. --}}
<span class="lista-nombre">{{ $semana['actividad']->nombre }}</span>
<span class="lista-nota lista-nota-bloque">
  {{ $semana['actividad']->institucion?->nombre }} ·
  {{ $semana['actividad']->responsable?->nombre_completo ?? 'Sin profesor asignado' }}
</span>
