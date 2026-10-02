<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Los enlaces de un solo uso para volver a entrar cuando se olvido la clave.
 *
 * ─── Por que existe esto y no `Password::broker()` ─────────────────────────
 *
 * El broker de Laravel identifica la cuenta por el CORREO: lo pide en el
 * formulario, lo guarda como clave primaria del token y lo vuelve a pedir al
 * escribir la contrasena nueva. Aqui el correo no es la identidad —se entra con
 * `username`, y el correo lo tienen 32 de 885 personas— y ademas se repite: una
 * madre con tres hijos inscritos pone el suyo en las tres cuentas. El porque
 * entero esta en la migracion de `restablecimientos_clave`.
 *
 * ─── SE BUSCA POR USUARIO **O** POR CORREO ─────────────────────────────────
 *
 * Un solo campo, y acepta las dos cosas. No es por comodidad: en produccion 179
 * personas usan su correo COMO nombre de usuario y otras 239 su nombre con
 * espacios, asi que ni siquiera esta claro que es lo que cada quien recuerda
 * haber escrito. Pedir «tu usuario» dejaria fuera a quien solo se acuerda del
 * correo, y al reves. Y no se filtra nada extra: el sistema contesta lo mismo
 * en los tres casos —encontrado, no encontrado, encontrado pero sin correo—,
 * asi que aceptar los dos no dice nada que no dijera uno.
 *
 * ─── LO QUE ESTA CLASE NO HACE ─────────────────────────────────────────────
 *
 * No manda el correo. Eso es del controlador, y va aparte porque el envio puede
 * fallar por cosas que no son asunto de un token —SMTP caido, buzon lleno— y
 * ahi hay que decidir que ve la persona, que es una decision de pantalla.
 */
final class RestablecerClave
{
    /**
     * Cuanto vive un enlace, en minutos.
     *
     * Una hora, que es el valor con el que viene Laravel. Corto de sobra para
     * lo que protege y largo de sobra para el caso real de aqui: alguien que
     * pide el enlace desde el celular, se va a buscar la clave del correo y
     * vuelve.
     */
    public const VALIDEZ_MINUTOS = 60;

    private const TABLA = 'restablecimientos_clave';

    /**
     * La cuenta a la que se le puede mandar un enlace, o null.
     *
     * Null cubre TRES situaciones distintas —no hay cuenta, la cuenta esta
     * desactivada, la cuenta no tiene correo— y quien llama no puede
     * distinguirlas, a proposito: contestar distinto en cada caso convierte
     * este formulario en una forma de averiguar quien tiene cuenta aqui.
     */
    public static function cuentaDe(string $usuarioOCorreo): ?User
    {
        $escrito = trim($usuarioOCorreo);

        if ($escrito === '') {
            return null;
        }

        // Solo en la institucion del dominio: `users` tiene RLS desde el paso
        // 4a, y el mismo usuario puede existir en otra.
        $usuario = User::query()
            ->where('activo', true)
            // El cotejo de estas columnas no distingue mayusculas, asi que
            // `Ana` encuentra a `ana` — igual que en la pantalla de entrar.
            ->where(fn ($q) => $q->where('username', $escrito)->orWhere('email', $escrito))
            ->first();

        // Sin correo no hay a donde mandar nada. Es el caso de 853 de las 885
        // cuentas de produccion, asi que no es el raro: es el corriente.
        return ($usuario?->email ?: null) === null ? null : $usuario;
    }

    /**
     * Un enlace nuevo para esa cuenta. Devuelve el token EN CLARO, que es lo
     * unico que viaja al correo y lo unico que no se guarda.
     *
     * Reemplaza el anterior si lo habia: `user_id` es la clave primaria de la
     * tabla, asi que pedir otro invalida el de antes. Es lo que uno espera —el
     * ultimo correo es el que sirve— y de paso impide que pulsar el boton
     * veinte veces deje veinte puertas abiertas a la vez.
     */
    public static function crear(User $usuario): string
    {
        $token = Str::random(64);

        // Como la institucion de la CUENTA. Desde el paso 4a es la misma que la
        // de la pagina (la cuenta se busco con RLS, en la del dominio), pero
        // esto no depende de quien llame: con otra, la fila ni se veria
        // (`updateOrInsert` chocaria con la clave) ni se podria escribir.
        InstitucionActual::mientras($usuario->institucion_id, fn () => DB::table(self::TABLA)->updateOrInsert(
            ['user_id' => $usuario->id],
            [
                'token' => self::digerir($token),
                'created_at' => now(),
                InstitucionActual::COLUMNA => $usuario->institucion_id,
            ],
        ));

        return $token;
    }

    /**
     * La cuenta de un enlace, o null si no vale.
     *
     * Null si el token no existe, si ya se uso, si caduco o si la cuenta se
     * desactivo entre medias. Lo ultimo importa: entre pedir el enlace y
     * abrirlo puede pasar una hora, y en ese rato un administrador puede haber
     * cerrado esa cuenta. Sin esta comprobacion el enlace le devolveria el paso.
     */
    public static function cuentaDelEnlace(string $token): ?User
    {
        // El enlace llega por correo, sin sesion, y apunta al dominio de la
        // institucion de esa cuenta. La base dice de cual es la huella (ver
        // `InstitucionActual::deEnlace()`) y `adoptar()` da 404 si no es la del
        // dominio por el que se abrio.
        $institucion = InstitucionActual::deEnlace('restablecimiento', self::digerir($token));

        if ($institucion === null) {
            return null;
        }

        InstitucionActual::adoptar($institucion);

        $fila = DB::table(self::TABLA)->where('token', self::digerir($token))->first();

        if ($fila === null) {
            return null;
        }

        if (self::caducado($fila->created_at)) {
            // Se borra al encontrarlo caducado y no solo se ignora: si no, la
            // fila se queda para siempre —nada mas la visita— y la tabla crece
            // con enlaces muertos.
            DB::table(self::TABLA)->where('user_id', $fila->user_id)->delete();

            return null;
        }

        return User::where('id', $fila->user_id)->where('activo', true)->first();
    }

    /** Gasta el enlace. Se llama DESPUES de guardar la contrasena nueva. */
    public static function consumir(User $usuario): void
    {
        InstitucionActual::mientras(
            $usuario->institucion_id,
            fn () => DB::table(self::TABLA)->where('user_id', $usuario->id)->delete()
        );
    }

    /**
     * El token tal como se guarda.
     *
     * SHA-256 sin sal, y no es una rebaja frente a bcrypt: el porque esta
     * escrito en la migracion. En dos palabras, esto no es una contrasena
     * elegida por una persona sino 64 caracteres del generador seguro, y hace
     * falta poder BUSCARLO — el enlace del correo no lleva quien es.
     */
    private static function digerir(string $token): string
    {
        return hash('sha256', $token);
    }

    private static function caducado(mixed $creadoEn): bool
    {
        return Carbon::parse((string) $creadoEn)
            ->addMinutes(self::VALIDEZ_MINUTOS)
            ->isPast();
    }
}
