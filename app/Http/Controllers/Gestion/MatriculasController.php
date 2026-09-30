<?php

namespace App\Http\Controllers\Gestion;

use App\Http\Controllers\Controller;
use App\Models\Matricula;
use App\Models\Periodo;
use App\Support\Reglas;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * MATRICULAS: todo lo que pasa con un periodo, en una pantalla.
 *
 * La institucion no recibe gente todo el ano: abre al principio y a mitad. El
 * interruptor de esta pantalla es lo que separa "el periodo esta en curso" de
 * "se admiten inscripciones y renovaciones ahora mismo".
 *
 * Desde el 01/09/2026 se llama solo «Matriculas» y se lleva ademas la lista de
 * PERIODOS, que era una ficha propia en la portada de Gestion. Crear un periodo
 * no es mantener un catalogo aparte: es el primer paso de abrir uno, y quien
 * llega aqui a abrir matriculas de 2026-2 y descubre que ese periodo todavia no
 * existe tenia que volver a la portada, entrar a otra pantalla, crearlo y
 * regresar. Los cupos siguen en su propia pantalla —son un formulario de una
 * fila por promotoria— pero se llega desde aqui, que es cuando se reparten.
 */
class MatriculasController extends Controller
{
    public function mostrar(): View
    {
        $periodo = Periodo::enCurso();

        return view('gestion.matriculas', [
            'periodo' => $periodo,
            'resumen' => $periodo === null ? null : $this->resumen($periodo),
            // Con lo que impide borrar cada uno, igual que en el catalogo: un
            // periodo con matriculas o clases sostiene historial. El conteo
            // viene con la consulta y no se pregunta fila a fila.
            'periodos' => Periodo::withCount(['matriculas', 'clases'])
                ->orderByDesc('fecha_inicio')
                ->get(),
        ]);
    }

    public function guardar(Request $request): RedirectResponse
    {
        $accion = $request->input('accion');

        if ($accion === 'poner_en_curso') {
            return $this->ponerEnCurso($request);
        }

        $periodo = Periodo::enCurso();

        if ($periodo === null) {
            return $this->volver('No hay un periodo en curso. Elige cuál es antes de abrir matrículas.');
        }

        $abrir = $accion === 'abrir';
        $periodo->matriculas_abiertas = $abrir;
        $periodo->save();

        return $this->volver(
            $abrir
                ? "Matrículas de {$periodo} ABIERTAS. Los estudiantes nuevos ya pueden "
                    .'inscribirse y los antiguos renovar.'
                : "Matrículas de {$periodo} CERRADAS. Las matrículas ya registradas no se "
                    .'tocan; solo deja de entrar gente nueva.',
            exito: true
        );
    }

    private function ponerEnCurso(Request $request): RedirectResponse
    {
        $request->validate(['periodo_id' => ['required', Reglas::existe('periodos')]]);

        $anterior = Periodo::enCurso();
        $elegido = Periodo::findOrFail($request->input('periodo_id'));

        Periodo::ponerEnCurso($elegido);

        return $this->volver(
            $anterior !== null && $anterior->id !== $elegido->id
                ? "{$elegido} es ahora el periodo en curso. {$anterior} dejó de estarlo y sus "
                    .'matrículas quedaron cerradas.'
                : "{$elegido} es ahora el periodo en curso.",
            exito: true
        );
    }

    /**
     * Las cifras de cabecera del periodo en curso.
     *
     * @return array<string, mixed>
     */
    private function resumen(Periodo $periodo): array
    {
        $matriculas = Matricula::where('periodo_id', $periodo->id);

        $anterior = Periodo::where('fecha_inicio', '<', $periodo->fecha_inicio)
            ->orderByDesc('fecha_inicio')
            ->first();

        $porRenovar = 0;

        if ($anterior !== null) {
            $yaEnCurso = (clone $matriculas)
                ->where('estado', '!=', Matricula::RETIRADA)
                ->pluck('estudiante_id');

            $porRenovar = Matricula::where('periodo_id', $anterior->id)
                ->where('estado', Matricula::ACTIVA)
                ->whereNotIn('estudiante_id', $yaEnCurso)
                ->distinct()
                ->count('estudiante_id');
        }

        return [
            'pendientes' => (clone $matriculas)->where('estado', Matricula::PENDIENTE)->count(),
            'activas' => (clone $matriculas)->where('estado', Matricula::ACTIVA)->count(),
            'estudiantes' => (clone $matriculas)
                ->where('estado', '!=', Matricula::RETIRADA)
                ->distinct()
                ->count('estudiante_id'),
            'periodo_anterior' => $anterior,
            'por_renovar' => $porRenovar,
        ];
    }

    private function volver(string $mensaje, bool $exito = false): RedirectResponse
    {
        return redirect()->route('gestion-matriculas')->with($exito ? 'success' : 'error', $mensaje);
    }
}
