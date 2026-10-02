{{--
  Lo que ve cualquiera que llegue a una institucion suspendida desde el panel
  (paso 4a, 02/10/2026). No dice por que: eso es entre la entidad y quien le
  presta el servicio, y la pantalla la ve tambien el publico. Tampoco ofrece
  entrar: suspendida no tiene nada abierto.
--}}
@extends('layouts.publico')

@section('title', 'Servicio suspendido — ' . $configuracion->nombre_institucion)

@section('caja')
  <h1>Servicio suspendido</h1>
  <p>El sistema de matrículas de {{ $configuracion->nombre_institucion }} no está disponible en este momento.</p>
  <p>Si necesitas hacer un trámite, comunícate directamente con la institución.</p>
@endsection
