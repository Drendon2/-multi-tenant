@extends('layouts.app')

@section('title', 'Renovar matrícula')

@section('content')
<h2>Renovar mi matrícula — {{ $periodo->nombre }}</h2>

<p class="aviso">
  Ya cursaste <strong>{{ $periodoAnterior->nombre }}</strong>, así que no tienes que crear cuenta
  ni volver a llenar tus datos. Cuéntanos cómo te fue y confirma en qué promotorías sigues.
  Te quedan <span class="cupo-contador">{{ $cuposLibres }} de {{ $cuposLimite }}</span> cupos.
</p>

<form method="post" action="{{ route('renovar-matricula.guardar') }}">
  @csrf

  <div class="card">
    <h3>¿En qué promotorías sigues?</h3>
    <p class="campo-info">
      Desmarca la que ya no quieras cursar. Si no quieres seguir en ninguna, desmárcalas todas
      y escoge otra abajo. El profesor confirma después, igual que una matrícula nueva.
    </p>
    @foreach ($renovables as $m)
    <label class="renovar-opcion">
      <input type="checkbox" name="promotoria[]" value="{{ $m->promotoria_id }}"
             @checked(! old('promotoria') || in_array((string) $m->promotoria_id, (array) old('promotoria'), true))>
      <span>
        <span class="tag-dot {{ $m->promotoria->area->tag_color }}"></span>{{ $m->promotoria->nombre }}
        <span class="campo-info" style="margin:0;display:inline;">— {{ $m->promotoria->area->nombre }}</span>
      </span>
    </label>
    @endforeach
  </div>

  @if ($disponibles)
  <div class="card">
    <h3>¿Quieres entrar a otra?</h3>
    <p class="campo-info">
      Opcional. En una promotoría que no has cursado entras como estudiante nuevo, aunque no
      tengas que crear cuenta otra vez. Entre renovadas y nuevas puedes tener hasta
      {{ $cuposLimite }} en total.
    </p>

    {{--
      El sufijo «— sin cupo» se arma antes y aquí solo se imprime. Escrito como
      `cupo@endif` la directiva va pegada a una letra y Blade no la compila: la
      deja literal y el bloque revienta. Es la misma trampa de la cabecera del
      catálogo, y ya ha mordido dos veces.
    --}}
    <label for="id_promotoria_nueva">Otra promotoría</label>
    <select name="promotoria_nueva" id="id_promotoria_nueva">
      <option value="">-- ninguna --</option>
      @foreach ($disponibles as $d)
        @php($sinCupo = $d['llena'] ? ' — sin cupo' : '')
        <option value="{{ $d['promotoria']->id }}" @disabled($d['llena'])
                @selected(old('promotoria_nueva') == $d['promotoria']->id)>{{ $d['promotoria']->nombre }}{{ $sinCupo }}</option>
      @endforeach
    </select>

    <label for="id_promotoria_nueva_2">Y otra más</label>
    <select name="promotoria_nueva_2" id="id_promotoria_nueva_2">
      <option value="">-- ninguna --</option>
      @foreach ($disponibles as $d)
        @php($sinCupo = $d['llena'] ? ' — sin cupo' : '')
        <option value="{{ $d['promotoria']->id }}" @disabled($d['llena'])
                @selected(old('promotoria_nueva_2') == $d['promotoria']->id)>{{ $d['promotoria']->nombre }}{{ $sinCupo }}</option>
      @endforeach
    </select>
  </div>
  @endif

  @if ($porValorar->isEmpty())
    <p class="campo-info">Ya respondiste la encuesta de {{ $periodoAnterior->nombre }}. Gracias.</p>
  @else
  {{--
    Una tanda de preguntas por PROMOTORÍA cursada, no una sola para todas: la
    pregunta del acompañamiento no significa nada si se contesta una vez para
    dos disciplinas distintas. Los campos van sufijados con el id de la matrícula
    porque en la misma página puede haber dos tandas.
  --}}
  @foreach ($porValorar as $m)
  <div class="card">
    <h3>
      ¿Cómo te fue en {{ $m->promotoria->nombre }}?
      <span class="h4-nota">{{ $periodoAnterior->nombre }}</span>
    </h3>

    @include('estudiante.escala', [
      'campo' => "satisfaccion_general_{$m->id}",
      'enunciado' => '¿Qué tan satisfecho quedaste con el proceso?',
    ])

    @include('estudiante.escala', [
      'campo' => "calificacion_profesor_{$m->id}",
      'enunciado' => '¿Cómo calificas el acompañamiento del profesor?',
    ])

    @include('estudiante.escala', [
      'campo' => "horario_funciono_{$m->id}",
      'enunciado' => '¿El horario te funcionó?',
      'opciones' => [1 => 'Sí', 0 => 'No'],
    ])

    @include('estudiante.escala', [
      'campo' => "recomendaria_{$m->id}",
      'enunciado' => "¿Recomendarías {$m->promotoria->nombre} a alguien más?",
      'opciones' => [1 => 'Sí', 0 => 'No'],
    ])

    <div class="encuesta-pregunta">
      <label for="comentario_{{ $m->id }}">¿Algo que quieras contarnos? (opcional)</label>
      <textarea name="comentario_{{ $m->id }}" id="comentario_{{ $m->id }}" rows="3">{{ old("comentario_{$m->id}") }}</textarea>
      @error("comentario_{$m->id}")<div class="errorlist" style="color:var(--danger);font-size:0.82rem;">{{ $message }}</div>@enderror
    </div>
  </div>
  @endforeach
  @endif

  <p><button type="submit" class="btn">Renovar mi matrícula</button></p>
</form>
@endsection
