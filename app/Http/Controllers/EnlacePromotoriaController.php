<?php

namespace App\Http\Controllers;

use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Support\CarneQr;
use App\Support\Permisos;
use App\Support\SolicitudDeMatricula;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * El enlace de inscripcion de UNA promotoria (29/09/2026).
 *
 * Sirve para matricular en una promotoria concreta con la ventana CERRADA sin
 * abrirla para todas. Las decisiones —quien entra, quien lo enciende, que
 * nace apagado— estan en la migracion `el_enlace_de_la_promotoria`.
 *
 * Dos lados:
 * - El PUBLICO (`/unirse/{token}`): quien no tiene cuenta ve la inscripcion
 *   con la promotoria ya puesta; quien es estudiante, un boton; el personal,
 *   que es un enlace para estudiantes.
 * - El del PERSONAL, en el Panel: el enlace, su QR y el interruptor.
 */
class EnlacePromotoriaController extends Controller
{
    /** Lo que abre el enlace o el QR. Sin sesion, con sesion de estudiante o de personal. */
    public function mostrar(Request $request, string $token): View
    {
        $promotoria = Promotoria::porEnlace($token);
        abort_if($promotoria === null, 404);

        $periodo = Periodo::enCurso();
        $perfil = $request->user()?->perfil;

        if ($periodo === null || ! $promotoria->enlace_abierto) {
            return $this->pagina($promotoria, 'cerrado', $perfil);
        }

        if ($perfil === null) {
            // Quien ya tiene cuenta y pulsa «entrar» tiene que volver AQUI y
            // no a su portada: si no, el enlace le deja en un sitio donde con
            // la ventana cerrada no hay ningun boton para matricularse.
            redirect()->setIntendedUrl($request->fullUrl());

            return view('auth.inscripcion', [
                'periodo' => $periodo,
                'matriculasAbiertas' => true,
                'limite' => 1,
                'catalogo' => collect([$promotoria]),
                'fija' => $promotoria,
            ]);
        }

        return $this->pagina($promotoria, $perfil->rol === 'estudiante' ? 'estudiante' : 'personal', $perfil);
    }

    /** El boton de quien ya tiene cuenta de estudiante. */
    public function matricularme(Request $request, string $token): RedirectResponse
    {
        $promotoria = Promotoria::porEnlace($token);
        abort_if($promotoria === null, 404);

        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');
        $periodo = Periodo::enCurso();

        // Otra vez en el POST: una pagina abierta en el telefono sigue mandando
        // aunque alguien haya apagado el enlace mientras tanto.
        if ($periodo === null || ! $promotoria->enlace_abierto) {
            return redirect()->route('promotoria-enlace', $token)
                ->with('error', 'Este enlace ya no recibe inscripciones.');
        }

        [$exito, $mensaje] = SolicitudDeMatricula::pedir($perfil, $promotoria, $periodo);

        return $exito
            ? redirect()->route('mis-matriculas')->with('success', $mensaje)
            : redirect()->route('promotoria-enlace', $token)->with('error', $mensaje);
    }

    /** La pantalla del Panel: el enlace, su QR y el interruptor. */
    public function panel(Request $request, Promotoria $promotoria): View
    {
        $perfil = $this->quien($request);
        abort_unless(Permisos::puedeGestionarPromotoria($perfil, $promotoria), 404);

        $promotoria->loadMissing('area');

        return view('panel.enlace-promotoria', [
            'promotoria' => $promotoria,
            'periodo' => Periodo::enCurso(),
            'puedeAbrir' => Permisos::puedeAbrirEnlace($perfil, $promotoria),
        ]);
    }

    /** El PNG del cartel, para descargarlo. */
    public function qr(Request $request, Promotoria $promotoria): Response
    {
        $perfil = $this->quien($request);
        abort_unless(Permisos::puedeGestionarPromotoria($perfil, $promotoria), 404);
        abort_if($promotoria->enlace_token === null, 404);

        return self::entregarCartel(self::cartel($promotoria), $promotoria->nombre);
    }

    /** Encender o apagar. */
    public function abrir(Request $request, Promotoria $promotoria): RedirectResponse
    {
        $perfil = $this->quien($request);
        abort_unless(Permisos::puedeAbrirEnlace($perfil, $promotoria), 404);

        $abrir = $request->boolean('abierto');
        $promotoria->abrirEnlace($abrir);
        $cerradas = ! (Periodo::enCurso()->matriculas_abiertas ?? false);

        return redirect()->route('panel-enlace-promotoria', $promotoria)->with(
            'success',
            $abrir
                ? "El enlace de {$promotoria->nombre} ya recibe inscripciones"
                    .($cerradas ? ', aunque las matrículas estén cerradas.' : '.')
                : "El enlace de {$promotoria->nombre} quedó apagado: quien lo abra verá que no recibe inscripciones."
        );
    }

    /** Cambiar el enlace: el anterior y su QR dejan de servir en el acto. */
    public function renovar(Request $request, Promotoria $promotoria): RedirectResponse
    {
        $perfil = $this->quien($request);
        abort_unless(Permisos::puedeAbrirEnlace($perfil, $promotoria), 404);
        abort_if($promotoria->enlace_token === null, 404);

        $promotoria->renovarEnlace();

        return redirect()->route('panel-enlace-promotoria', $promotoria)->with(
            'success',
            'El enlace anterior y su QR ya no sirven. Este es el nuevo: vuelve a compartirlo.'
        );
    }

    /** El cartel de una promotoria como PNG. */
    public static function cartel(Promotoria $promotoria): string
    {
        return CarneQr::cartel(
            (string) $promotoria->enlace(),
            $promotoria->nombre,
            'Escanéalo para matricularte'
        );
    }

    /**
     * La descarga de un cartel. Tambien la usa el de las actividades.
     *
     * `no-store` por lo mismo que el carne: el CDN es compartido, y un cartel
     * viejo servido desde la cache despues de renovar el enlace llevaria a
     * una puerta que ya no abre.
     */
    public static function entregarCartel(string $png, string $nombre): Response
    {
        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'attachment; filename="'.CarneQr::nombreDeCartel($nombre).'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function pagina(Promotoria $promotoria, string $estado, ?Perfil $perfil): View
    {
        $yaEsta = false;

        if ($estado === 'estudiante' && ($periodo = Periodo::enCurso()) !== null) {
            $yaEsta = Matricula::query()
                ->where('estudiante_id', $perfil?->id)
                ->where('promotoria_id', $promotoria->id)
                ->where('periodo_id', $periodo->id)
                ->where('estado', '!=', Matricula::RETIRADA)
                ->exists();
        }

        return view('publico.enlace-promotoria', [
            'promotoria' => $promotoria,
            'estado' => $estado,
            'yaEsta' => $yaEsta,
            'puedeVerEnlace' => $perfil !== null && $estado === 'personal'
                && Permisos::puedeGestionarPromotoria($perfil, $promotoria),
        ]);
    }

    private function quien(Request $request): Perfil
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');

        return $perfil;
    }
}
