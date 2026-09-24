{{--
  LAS INSTITUCIONES EXTERNAS REGISTRADAS.

  Cada fila es una entidad ajena a la casa —una escuela rural, un colegio, una
  fundación— y la cuenta de la persona que, desde allí, da fe de que el profesor
  fue. Son dos datos y no uno: la escuela sobrevive al funcionario.

  Recibe `instituciones` tal como las trae `InstitucionExternaController`.

  Directivas PHP en la forma de UNA LINEA, como el resto del proyecto.
--}}
@if ($instituciones->isEmpty())
  <p class="vacio">
    {{ $vacio_texto ?? 'Todavía no hay ninguna institución registrada.' }}
  </p>
@else
<table class="tabla-personas tabla-catalogo tabla-menu-fila">
  <thead>
    <tr>
      <th>Institución</th>
      <th>Quien verifica</th>
      <th class="num">Programas</th>
      <th><span class="sr-solo">Acciones</span></th>
    </tr>
  </thead>
  <tbody>
    @foreach ($instituciones as $institucion)
    @php($cuenta = $institucion->perfil->user)
    <tr>
      <td data-celda="detalle">
        {{--
          EL NOMBRE NO ES UNA PUERTA AQUÍ, y es la excepción de esta pantalla.
          En las otras tres listas el nombre baja a algo —las promotorías de un
          departamento, la ficha de una actividad—; una institución no tiene
          nada debajo a donde bajar: sus programas ya están listados arriba, con
          su nombre en cada fila. Un enlace que llevara a una pantalla con lo
          mismo que ya se está viendo es un viaje perdido.
        --}}
        <span class="lista-nombre">{{ $institucion->nombre }}</span>
        @if (! $cuenta->activo)
          {{--
            Sólido y en rojo, como «retirada»: la cuenta apagada es un desenlace
            y no un trámite a medias. Y lleva su palabra, que es lo que de
            verdad lo dice — nada de esto depende sólo del color.
          --}}
          <span class="estado estado-retirada">Apagada</span>
        @endif
        @if ($institucion->direccion || $institucion->telefono)
          <span class="lista-nota lista-nota-bloque">
            {{ $institucion->direccion }}@if ($institucion->direccion && $institucion->telefono) · @endif{{ $institucion->telefono }}
          </span>
        @endif
      </td>
      <td data-label="Quien verifica">
        {{ $institucion->perfil->nombre_completo }}
        {{--
          EL USUARIO A LA VISTA, en mono porque es un dato que se teclea. Esta
          persona no está en Gestión → Usuarios —su ficha no se puede abrir
          desde allí sin arriesgarse a cambiarle el rol— así que si este renglón
          no lo dice, no lo dice nadie: quien entregue las credenciales tendría
          que adivinarlas. La contraseña no, por razones que no hace falta
          explicar; para eso está «Editar».

          `.dato-usuario` Y NO `.clase-mia`, que es la que se usa en «Mis
          clases» y parecía servir: aquella lleva versalitas además de mono, y
          se vio en el navegador que pintaba `elcarmen` como «ELCARMEN». Esto es
          una credencial que alguien copia de la pantalla para entregarla, así
          que enseñarla en otra caja de la que tiene es dictarla mal.
        --}}
        <span class="dato-usuario">{{ $cuenta->username }}</span>
      </td>
      <td class="num" data-label="Programas">{{ $institucion->programas_count }}</td>
      <td data-celda="accion" class="lista-acciones lista-acciones-menu">
        <span class="accion-fila">
        {{--
          EL QR SE QUEDA A LA VISTA y el resto va al menú, y la línea entre los
          dos es la misma que en las actividades: la frecuencia. Imprimir y
          entregar el cartón es lo que se hace con una institución en marcha
          —se pierde, se moja, se renueva— mientras que editarla o apagarle la
          cuenta son de montarla o de cerrarla.
        --}}
        <a class="btn btn-blanco btn-sm" href="{{ route('institucion-externa-qr', $institucion) }}"
           aria-label="Ver el QR de {{ $institucion->nombre }}">QR</a>
        @include('partials.menu-fila', [
            'etiqueta' => $institucion->nombre,
            'opciones' => [
                ['texto' => 'Editar', 'url' => route('institucion-externa-editar', $institucion), 'modal' => true],
                [
                    'texto' => $cuenta->activo ? 'Apagar la cuenta' : 'Encender la cuenta',
                    'url' => route('institucion-externa-alternar-activo', $institucion),
                    'post' => true,
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
