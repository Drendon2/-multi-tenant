@extends('layouts.app')

@section('title', 'Clases por verificar')

@section('content')
{{--
  LA UNICA PANTALLA de una institución externa, y hay que leerla sabiendo quién
  la mira: un funcionario de OTRA entidad —una escuela rural, un colegio— que
  entra a decir que el profesor sí vino. No es personal de la casa. Aquí no hay
  Panel, ni Gestión, ni fichas, ni buscador.

  QUÉ VE DE CADA CLASE, decidido con el usuario el 23/09/2026: la fecha, la hora
  real en que el profesor la inició, su nombre, y CUÁNTOS asistieron. No los
  nombres — sacar una lista nominal de menores hacia una cuenta ajena al sistema
  es un precio que esta firma no necesita pagar. La cifra sí, y no es adorno: es
  lo que permite notar que dice «asistieron 2» un día que el salón estaba lleno,
  que es justamente la clase de cosa por la que esto existe.

  Mismo renglón de hoja que «Mis clases» del estudiante —dato a la izquierda,
  acción a la derecha— y el mismo vocabulario de `.estado`, porque son las dos
  caras del mismo gesto: alguien que no dictó la clase da fe de que se dio.
--}}
<h2>Clases en {{ $institucion->nombre }}</h2>

<p class="campo-ayuda">
  Aquí das fe de que el profesor vino a dar la clase. No hay plazo: puedes
  hacerlo cuando entres, aunque hayan pasado semanas.
</p>

@if ($sesiones->isEmpty())
  {{--
    Todavía no hay nada, y eso tiene DOS causas que desde aquí no se distinguen
    —que no le hayan abierto ningún programa, o que el profesor no haya iniciado
    ninguna clase— así que se dicen las dos. Un «no hay nada» a secas deja
    preguntándose si el sistema falla.
  --}}
  <p class="vacio">
    Todavía no hay ninguna clase que verificar. Aparecerán aquí en cuanto el
    profesor inicie la primera en su institución.
  </p>
@else

  @if ($porFirmar)
    <p class="aviso">
      @if ($porFirmar == 1)
        Hay <strong>1 clase</strong> esperando que des fe de ella.
      @else
        Hay <strong>{{ $porFirmar }} clases</strong> esperando que des fe de ellas.
      @endif
    </p>
  @endif

  <div class="card clase-lista">
    @foreach ($sesiones as $sesion)
    <div class="clase-fila">
      <div class="clase-datos">
        <span class="clase-titulo">{{ $sesion->actividad->nombre }}</span>
        <span class="clase-cuando">
          {{ $sesion->iniciada_en->isoFormat('dddd D [de] MMMM [de] YYYY') }},
          a las {{ $sesion->iniciada_en->format('H:i') }}
          @if ($sesion->iniciadaPor)
            · {{ $sesion->iniciadaPor->nombre_completo }}
          @endif
        </span>
        <span class="clase-cuando">
          {{--
            Cero asistentes NO se pinta como «0 asistieron», que suena a que
            nadie fue. Lo que de verdad pasa casi siempre es que el profesor no
            alcanzó a pasar lista, y son dos cosas distintas: decir la primera
            sería poner en boca del sistema una acusación que nadie hizo.
          --}}
          @if ($sesion->asistentes)
            {{ $sesion->asistentes }} {{ $sesion->asistentes == 1 ? 'asistente' : 'asistentes' }}
          @else
            <span class="vacio">Sin lista tomada</span>
          @endif
        </span>
      </div>
      <div class="clase-accion">
        @if ($sesion->estaVerificada())
          <span class="estado estado-activa">Verificada</span>
          {{--
            POR DÓNDE ENTRÓ LA FIRMA, y esto es lo que hace que la retirada
            signifique algo. «Con tu QR» quiere decir que la dejó el profesor
            allá leyendo el cartón: si no recuerdas esa clase, este es el
            renglón que te lo dice y el botón de al lado lo deshace. Las dos
            cifras no se colapsan nunca — una media que mezcla las dos no se
            puede auditar.
          --}}
          <span class="clase-mia">
            {{ $sesion->verificacion_origen === 'qr' ? 'con su QR' : 'por usted' }}
          </span>
          <form method="post" action="{{ route('externa-retirar', $sesion) }}">
            @csrf
            <button type="submit" class="btn btn-blanco btn-sm"
                    aria-label="Retirar la verificación de {{ $sesion->actividad->nombre }} del {{ $sesion->iniciada_en->format('d/m/Y') }}">
              Retirar
            </button>
          </form>
        @else
          <span class="estado estado-pendiente">Sin verificar</span>
          <form method="post" action="{{ route('externa-verificar', $sesion) }}">
            @csrf
            {{--
              El nombre accesible nombra la clase. Sin él son tantos botones
              «Doy fe» idénticos como filas haya, y quien los recorre con un
              lector no sabe cuál firma cuál. El texto visible va entero y de
              primero dentro del accesible, que es lo que pide el criterio 2.5.3
              de WCAG para quien dicta por voz.
            --}}
            <button type="submit" class="btn"
                    aria-label="Doy fe: {{ $sesion->actividad->nombre }} del {{ $sesion->iniciada_en->format('d/m/Y') }}">
              Doy fe
            </button>
          </form>
        @endif
      </div>
    </div>
    @endforeach
  </div>
@endif
@endsection
