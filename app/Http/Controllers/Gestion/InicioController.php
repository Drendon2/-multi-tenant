<?php

namespace App\Http\Controllers\Gestion;

use App\Http\Controllers\Controller;
use App\Models\ConfiguracionInstitucion;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Support\Alertas;
use App\Support\OrdenPorNombre;
use App\Support\ResumenInstitucion;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** La portada de Gestion: las fichas que llevan a cada pantalla. */
class InicioController extends Controller
{
    public function __invoke(Request $request): View
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');
        $config = ConfiguracionInstitucion::actual();
        $periodo = Periodo::enCurso();

        // La cifra de la ficha es lo unico que avisa de que hay algo esperando:
        // sin ella habria que entrar a mirar. Desde el 02/09/2026 cuenta las
        // TRES cosas de esa bandeja y no solo las cancelaciones, porque si
        // contara una sola la ficha diria «0» con veinte clases sin registrar.
        //
        // Acotada al director como las dos alertas (27/09/2026): hasta ese dia
        // contaba las cancelaciones de toda la casa, y la ficha invitaba a
        // entrar por solicitudes que no eran suyas. Las CIFRAS de la cinta de
        // arriba siguen siendo generales a proposito; esta no es una cifra de
        // la casa, es lo que ESTA PERSONA tiene por atender.
        $cancelaciones = Matricula::queVe($perfil)->where('estado', Matricula::CANCELACION_SOLICITADA)->count();

        $alertas = 0;
        $ultimas = [];

        if ($periodo !== null) {
            if ($config->alerta_clase_no_dictada) {
                $sinDictar = Alertas::clasesNoDictadas($periodo, $perfil);
                $alertas += $sinDictar->count();

                foreach ($sinDictar as $falta) {
                    // Anotada porque la relacion no lleva tipo y la promotoria
                    // llega como un `Model` sin nombre para el analizador.
                    /** @var Promotoria $promotoria */
                    $promotoria = $falta['grupo']->promotoria;

                    $ultimas[] = [
                        'tipo' => 'clase',
                        'fecha' => $falta['fecha'],
                        'texto' => "{$promotoria->nombre} · {$falta['grupo']->nombre}",
                        'detalle' => 'Clase del '.$falta['fecha']->format('d/m').' sin registrar',
                    ];
                }
            }

            if ($config->alerta_abandono) {
                $abandonos = Alertas::posiblesAbandonos($periodo, $perfil);
                $alertas += $abandonos->count();

                foreach ($abandonos as $caso) {
                    $ultimas[] = [
                        'tipo' => 'abandono',
                        'fecha' => $caso['desde'],
                        'texto' => $caso['matricula']->estudiante->nombre_completo
                            .' · '.$caso['matricula']->promotoria->nombre,
                        'detalle' => $caso['faltas'].' faltas seguidas sin excusa',
                    ];
                }
            }
        }

        // Las semanas sin clase de los programas externos (03/10/2026): solo
        // administracion, y fuera del `if` del periodo porque no dependen de
        // el —las gobiernan las fechas de cada institucion—.
        if ($config->alerta_clase_no_dictada && $perfil->rol === 'administrador') {
            foreach (Alertas::semanasSinClaseExterna() as $semana) {
                $alertas++;
                $ultimas[] = [
                    'tipo' => 'clase',
                    'fecha' => $semana['semana'],
                    'texto' => $semana['actividad']->nombre,
                    'detalle' => 'Semana del '.$semana['semana']->format('d/m').' sin clase',
                ];
            }
        }

        // LAS TRES MAS RECIENTES de las dos bandejas juntas, ordenadas por fecha.
        // Se mezclan a proposito: quien mira la portada quiere «que ha pasado
        // ultimamente», no «que ha pasado de este tipo». Y no se guarda ninguna
        // lista: las alertas se calculan al abrir, asi que en cuanto una se
        // resuelve deja de salir y suben las que venian detras, sin que nadie
        // tenga que refrescar nada ni mantener una cola.
        // Entre las de la misma fecha, por lo que se lee (ver `OrdenPorNombre`):
        // el corte a tres caia en medio de un empate y cada motor elegia otras.
        usort($ultimas, fn (array $a, array $b) => $b['fecha']->timestamp <=> $a['fecha']->timestamp
            ?: OrdenPorNombre::comparar($a['texto'], $b['texto']));

        return view('gestion.inicio', [
            'cancelacionesPendientes' => $cancelaciones,
            'alertasPendientes' => $alertas,
            'ultimasAlertas' => array_slice($ultimas, 0, 3),
            'alertasApagadas' => ! $config->alerta_clase_no_dictada && ! $config->alerta_abandono,
            // Desde cuando cuentan. Es lo unico que explica un cero: sin esta
            // fecha, «no ha pasado nada» y «se acaba de configurar y todavia no
            // mira casi nada» se leen igual.
            'alertasDesde' => $periodo === null ? null : Alertas::desde($periodo),
            'cifras' => ResumenInstitucion::cifras($periodo),
            'periodo' => $periodo,
        ]);
    }
}
