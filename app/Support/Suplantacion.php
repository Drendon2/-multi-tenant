<?php

namespace App\Support;

use App\Models\Institucion;
use App\Models\Operador;
use App\Models\Perfil;
use App\Models\Suplantacion as Fila;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

/**
 * Un operador del panel entra a una institucion como uno de sus
 * administradores (paso 4c, decision del usuario del 02/10/2026).
 *
 * El panel y la institucion viven en hosts distintos y una cookie es de su
 * host, asi que el panel no puede abrir la sesion en el otro. Lo que hace es
 * dejar un token de un solo uso (`emitir()`) y mandar al navegador al dominio
 * de la institucion, que lo canjea (`canjear()`) y abre una sesion de verdad
 * con `Auth::login`, como la gestion asistida: una identidad a medias deja un
 * hueco donde nadie mira.
 *
 * - Solo a ADMINISTRADORES. Desde ahi, si hace falta, la gestion asistida
 *   lleva a un profesor, director o estudiante, con sus propios cortes.
 * - Mientras dura, una barra lo dice en todas las pantallas, y lo que la
 *   gestion asistida no deja hacer tampoco se hace aqui: cambiar la
 *   contraseña de la cuenta.
 * - Funciona aunque la institucion este SUSPENDIDA: es para dar soporte.
 * - Todo queda en el canal de auditoria y la fila se queda en
 *   `suplantaciones`: quien entro, como quien y cuando.
 */
final class Suplantacion
{
    /** Cuanto vive el token: es un salto entre dos hosts, no una espera. */
    public const VALIDEZ_SEGUNDOS = 60;

    /** El operador de verdad. Su presencia en la sesion ES la suplantacion. */
    private const CLAVE = 'suplantacion_operador';

    /** ¿Se puede entrar como esta persona? */
    public static function puedeSuplantarA(Perfil $perfil): bool
    {
        return $perfil->rol === 'administrador'
            && $perfil->user->activo === true
            && ! $perfil->estaSuprimido();
    }

    /**
     * Los administradores de una institucion por los que se puede entrar.
     * Lo llama el panel, que no es de ninguna: se mira desde ella.
     *
     * @return Collection<int, Perfil>
     */
    public static function administradoresDe(Institucion $institucion)
    {
        return InstitucionActual::mientras($institucion->id, fn () => Perfil::with('user')
            ->where('rol', 'administrador')
            ->whereNull('suprimido_en')
            ->orderBy('nombre_completo')->orderBy('id')
            ->get()
            ->filter(fn (Perfil $p) => self::puedeSuplantarA($p))
            ->values());
    }

    /**
     * Deja el token para entrar a `$institucion` como el perfil `$perfilId`.
     * Devuelve el token EN CLARO, que solo viaja en la redireccion, o null si
     * esa persona no se puede suplantar.
     *
     * La comprobacion vive AQUI y no en el controlador, por lo mismo que en
     * `GestionAsistida::iniciar()`: es la funcion que abre la puerta.
     */
    public static function emitir(Operador $operador, Institucion $institucion, int $perfilId): ?string
    {
        return InstitucionActual::mientras($institucion->id, function () use ($operador, $perfilId) {
            $perfil = Perfil::with('user')->find($perfilId);

            if ($perfil === null || ! self::puedeSuplantarA($perfil)) {
                return null;
            }

            $token = Str::random(64);

            Fila::create([
                'operador_id' => $operador->id,
                'user_id' => $perfil->user_id,
                'token' => self::digerir($token),
            ]);

            return $token;
        });
    }

    /**
     * Gasta el token y abre la sesion. Devuelve false si no vale: no existe en
     * ESTA institucion (RLS), ya se uso, caduco, o esa persona dejo de ser
     * administradora en el minuto que paso.
     *
     * Gastarlo es un UPDATE condicionado y no leer y luego escribir: dos
     * pestañas que lo abren a la vez no entran las dos.
     */
    public static function canjear(string $token): bool
    {
        $fila = DB::selectOne(
            'UPDATE suplantaciones SET usado_en = LOCALTIMESTAMP(0)
              WHERE token = ? AND usado_en IS NULL
                AND created_at >= LOCALTIMESTAMP(0) - make_interval(secs => ?)
          RETURNING id, operador_id, user_id',
            [self::digerir($token), self::VALIDEZ_SEGUNDOS]
        );

        if ($fila === null) {
            return false;
        }

        $user = User::with('perfil')->find($fila->user_id);
        $operador = Operador::where('activo', true)->find($fila->operador_id);

        if ($user === null || $user->perfil === null || $operador === null || ! self::puedeSuplantarA($user->perfil)) {
            return false;
        }

        Auth::login($user);
        Session::put(self::CLAVE, ['id' => $operador->id, 'nombre' => $operador->nombre]);

        Auditoria::registrar('suplantacion.inicio', [
            'operador_id' => $operador->id,
            'suplantacion_id' => $fila->id,
            'perfil_id' => $user->perfil->id,
        ]);

        return true;
    }

    public static function activa(): bool
    {
        return Session::has(self::CLAVE);
    }

    /** El nombre del operador que entro, o null si nadie esta suplantando. */
    public static function operador(): ?string
    {
        return Session::get(self::CLAVE)['nombre'] ?? null;
    }

    /** Lo deja en el registro. La sesion la cierra quien llama. */
    public static function terminar(): void
    {
        $datos = Session::get(self::CLAVE);

        if ($datos === null) {
            return;
        }

        Auditoria::registrar('suplantacion.fin', [
            'operador_id' => $datos['id'],
            'perfil_id' => Auth::user()?->perfil?->id,
        ]);

        Session::forget(self::CLAVE);
    }

    private static function digerir(string $token): string
    {
        return hash('sha256', $token);
    }
}
