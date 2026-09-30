<?php

namespace App\Http\Controllers\Gestion;

use App\Http\Controllers\Controller;
use App\Models\CupoPromotoria;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Support\InstitucionActual;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Fijar de una vez el cupo de todas las promotorias para un periodo.
 *
 * Es la pantalla de "abrir matriculas": se elige el periodo, se reparten los
 * cupos y se guarda todo junto. Dejar una casilla vacia deja esa promotoria sin
 * tope. Los periodos pasados se pueden consultar, pero no editar: su cupo es
 * parte del historico.
 */
class CuposController extends Controller
{
    public function mostrar(?Periodo $periodo = null): View
    {
        $periodos = Periodo::orderByDesc('activo')->orderByDesc('id')->get();
        $periodo ??= $periodos->firstWhere('activo', true) ?? $periodos->first();

        if ($periodo === null) {
            return view('gestion.cupos', ['periodo' => null, 'periodos' => $periodos, 'filas' => []]);
        }

        $promotorias = $this->promotorias();
        $ocupados = Promotoria::ocupadosEnLote($periodo, $promotorias);

        $filas = $promotorias
            ->map(fn (Promotoria $p) => [
                'promotoria' => $p,
                // `cupoEn` no consulta: aprovecha la relacion que trae el
                // `with('cupos')` de abajo. `ocupadosEn` si consultaba, una vez
                // por fila, y por eso sale del bucle.
                'cupo' => $p->cupoEn($periodo),
                'ocupados' => (int) ($ocupados[$p->id] ?? 0),
            ])
            ->all();

        return view('gestion.cupos', compact('periodo', 'periodos', 'filas'));
    }

    public function guardar(Request $request, Periodo $periodo): RedirectResponse
    {
        if (! $periodo->activo) {
            return $this->volver(
                $periodo,
                "{$periodo} no es el periodo activo: sus cupos son histórico y no se editan."
            );
        }

        $promotorias = $this->promotorias();
        $errores = [];
        $nuevos = [];

        // Se lee y se comprueba TODO antes de escribir nada. El original hacia
        // las dos cosas a la vez y deshacia la transaccion al encontrar el
        // primer valor malo; separarlo consigue lo mismo —nada a medias— sin
        // depender de un rollback a mitad de bucle, y ademas permite listar
        // todos los errores de una vez en lugar de solo el primero.
        foreach ($promotorias as $promotoria) {
            $bruto = trim((string) $request->input("cupo_{$promotoria->id}", ''));

            if ($bruto === '') {
                $nuevos[$promotoria->id] = null;

                continue;
            }

            if (! preg_match('/^-?\d+$/', $bruto)) {
                $errores[] = "{$promotoria}: «{$bruto}» no es un número entero.";

                continue;
            }

            if ((int) $bruto < 0) {
                $errores[] = "{$promotoria}: el cupo no puede ser negativo.";

                continue;
            }

            $nuevos[$promotoria->id] = (int) $bruto;
        }

        if ($errores !== []) {
            return $this->volver($periodo, 'No se guardó ningún cupo. '.implode(' ', $errores));
        }

        $avisos = [];
        $quitados = 0;

        // Las matriculas ya ocupando sitio se cuentan ANTES de abrir la
        // transaccion, y de una sola vez.
        //
        // Antes iba dentro del bucle, o sea un COUNT por promotoria con la
        // transaccion abierta. Solo servian para redactar un aviso de texto,
        // pero alargaban la transaccion y con ella el rato que las filas de
        // `cupos_promotoria` quedan bloqueadas — y esto es la pantalla de
        // «abrir matriculas», que se usa el dia que mas gente esta empujando
        // contra esas mismas filas y contra el trigger de cupos. Dentro de la
        // transaccion solo tienen que quedar escrituras.
        $ocupados = Promotoria::ocupadosEnLote($periodo, $promotorias);

        // El reparto se ARMA fuera y se escribe en dos sentencias, no en dos por
        // promotoria (C-05). Importa mas aqui que en otras pantallas: mientras
        // la transaccion esta abierta, las filas de `cupos_promotoria` que toca
        // quedan bloqueadas, y el trigger de cupos las lee en cada matricula que
        // alguien intente — o sea justo el dia que mas gente empuja contra
        // ellas. Es la continuacion de haber sacado los COUNT de aqui.
        $sinTope = [];
        $conTope = [];

        foreach ($promotorias as $promotoria) {
            $cupo = $nuevos[$promotoria->id];

            if ($cupo === null) {
                $sinTope[] = $promotoria->id;

                continue;
            }

            $conTope[] = [
                // `upsert()` no dispara el evento que pone la institucion.
                InstitucionActual::COLUMNA => InstitucionActual::id(),
                'promotoria_id' => $promotoria->id,
                'periodo_id' => $periodo->id,
                'cupo_maximo' => $cupo,
            ];
        }

        $guardados = count($conTope);

        DB::transaction(function () use ($periodo, $sinTope, $conTope, &$quitados) {
            if ($sinTope !== []) {
                // Cuenta filas borradas, no promotorias pedidas, que es lo que
                // contaba el bucle: `(promotoria, periodo)` es unico, asi que
                // una promotoria que ya estaba sin tope no suma.
                $quitados = CupoPromotoria::where('periodo_id', $periodo->id)
                    ->whereIn('promotoria_id', $sinTope)
                    ->delete();
            }

            if ($conTope !== []) {
                CupoPromotoria::upsert($conTope, ['promotoria_id', 'periodo_id'], ['cupo_maximo']);
            }
        });

        // El aviso se compone despues, con los numeros ya en memoria. Es texto
        // para quien acaba de guardar, no una condicion de la escritura: el
        // mensaje lo dice expresamente, «no se retiro a nadie».
        foreach ($promotorias as $promotoria) {
            $cupo = $nuevos[$promotoria->id];

            if ($cupo === null) {
                continue;
            }

            $yaOcupados = (int) ($ocupados[$promotoria->id] ?? 0);

            if ($cupo < $yaOcupados) {
                $avisos[] = "{$promotoria}: cupo {$cupo} por debajo de las {$yaOcupados} "
                    .'matrículas ya ocupando sitio.';
            }
        }

        $respuesta = redirect()->route('gestion-cupos-periodo', $periodo)->with(
            'success',
            "Cupos de {$periodo} guardados: {$guardados} con tope"
            .($quitados ? ", {$quitados} sin tope" : '').'.'
        );

        if ($avisos !== []) {
            $respuesta->with(
                'error',
                implode(' ', $avisos)
                .' No se retiró a nadie, pero no entrarán estudiantes nuevos.'
            );
        }

        return $respuesta;
    }

    /** @return Collection<int, Promotoria> */
    private function promotorias()
    {
        // ACOTADO al director: reparte los cupos de sus departamentos y no de
        // los ajenos. Ver `Promotoria::queVe()`.
        return Promotoria::queVe(request()->attributes->get('perfil'))
            ->with(['area', 'profesor', 'cupos'])
            ->join('areas', 'areas.id', '=', 'promotorias.area_id')
            ->orderBy('areas.nombre')
            ->orderBy('promotorias.nombre')
            ->select('promotorias.*')
            ->get();
    }

    private function volver(Periodo $periodo, string $mensaje): RedirectResponse
    {
        return redirect()->route('gestion-cupos-periodo', $periodo)->with('error', $mensaje);
    }
}
