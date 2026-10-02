@extends('layouts.operador')

@section('title', 'Instituciones — Panel de instituciones')

@section('content')
  <h2>Instituciones</h2>
  <p class="campo-ayuda">
    Una institución nueva se da de alta con <code>php artisan instalar --nueva</code>.
    Aquí se le pone la dirección y se suspende o se reactiva.
  </p>

  {{-- `.tabla-personas`: bajo 640px cada fila pasa a ficha, con su acción a la vista. --}}
  <table class="tabla-personas tabla-catalogo">
    <thead>
      <tr>
        <th>Institución</th>
        <th>Dirección</th>
        <th>Estado</th>
        <th>Alta</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      @foreach ($instituciones as $institucion)
      <tr>
        <td data-celda="nombre">{{ $institucion->nombre }}</td>
        <td data-label="Dirección">
          @if ($institucion->dominio_propio)
            {{ $institucion->dominio_propio }}@if ($institucion->subdominio)<br>@endif
          @endif
          @if ($institucion->subdominio)
            {{ $institucion->subdominio }}.{{ $base }}
          @endif
          @if (! $institucion->dominio_propio && ! $institucion->subdominio)
            <span class="estado estado-pendiente">Sin dirección</span>
          @endif
        </td>
        <td data-label="Estado">
          @if ($institucion->estado === \App\Models\Institucion::SUSPENDIDA)
            <span class="estado estado-retirada">Suspendida</span>
          @else
            <span class="estado estado-activa">Activa</span>
          @endif
        </td>
        <td data-label="Alta">{{ $institucion->fecha_alta?->format('d/m/Y') }}</td>
        <td data-celda="accion">
          <a class="btn btn-secundario btn-sm" href="{{ route('operador.institucion', $institucion) }}"
             aria-label="Editar {{ $institucion->nombre }}">Editar</a>
        </td>
      </tr>
      @endforeach
    </tbody>
  </table>
@endsection
