<?php

namespace App\Support;

use App\Models\Perfil;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

/**
 * Gestion asistida: el administrador trabaja DESDE la cuenta de otra persona.
 *
 * Para que sirve, pedido por el usuario el 04/09/2026: ver el sistema como lo
 * ve un profesor o un director y poder resolver cosas por el. Un profesor que
 * no encuentra un boton, una promotoria que hay que desatascar, una pantalla
 * que se comporta raro y solo le pasa a una persona.
 *
 * NO SE LLAMA SUPLANTACION, tambien a peticion del usuario, y el nombre no es
 * cosmetico: describe lo que de verdad se hace, que es ayudar con el trabajo de
 * alguien y no hacerse pasar por el. La auditoria guarda siempre quien fue de
 * verdad.
 *
 * COMO FUNCIONA: se inicia sesion como la otra persona de verdad —`Auth::login`
 * — y se guarda en la sesion quien la empezo. Se hace asi y NO parcheando el
 * perfil que resuelve el middleware porque el sistema pregunta por la identidad
 * de tres formas distintas —`auth()->user()`, `$request->user()` y el atributo
 * `perfil`— y una identidad a medias es la clase de cosa que deja un hueco
 * exactamente donde nadie mira. Asi todas las puertas que ya existen funcionan
 * solas: el middleware de rol, `Permisos`, las consultas acotadas por profesor.
 *
 * LOS TRES LIMITES, y ninguno es negociable:
 *
 * 1. NO SE ASISTE A UN ADMINISTRADOR. No aporta nada —ya tiene los mismos
 *    permisos— y seria una via para que un administrador actuara como otro y
 *    dejara el rastro en su nombre. A los ESTUDIANTES si, desde el 04/09/2026 y
 *    a peticion del usuario: la mitad de las llamadas de soporte son de alguien
 *    que no logra matricularse.
 * 2. NO SE ANIDA. Estando asistido no se puede empezar otra asistencia; si se
 *    pudiera, la sesion tendria que recordar una pila y «salir» dejaria de ser
 *    una respuesta clara.
 * 3. NO SE ESCRIBE ASISTENCIA ni se CONFIRMA una clase, que son las dos caras
 *    de la misma garantia. La primera va en `Permisos::dictaLaPromotoria()`, la
 *    UNICA puerta de escritura de la lista, asi que alcanza a las cuatro
 *    pantallas de una vez.
 *
 *    La segunda va en `MisClasesController`: quien registra la clase es parte
 *    interesada, y por eso la verifican los estudiantes. Si el administrador
 *    puede dar fe desde la cuenta del estudiante, la verificacion deja de ser
 *    independiente y la clase queda avalada por la misma casa que la registro.
 *
 *    RENOVAR SI SE PUEDE, decidido por el usuario el 04/09/2026 sabiendo lo que
 *    arrastra: la renovacion exige la encuesta de satisfaccion, asi que quien
 *    renueva por alguien esta calificando a un profesor en su nombre y esa nota
 *    entra en las medias de Estadisticas sin distinguirse de las demas. Se
 *    acepta porque no lograr renovar es de lo que mas soporte genera. La razon esta escrita en ese mismo archivo desde
 *    antes de que esto existiera: un registro que puede reescribir alguien que
 *    no dio la clase deja de ser evidencia de lo que paso, y la evidencia es lo
 *    que la confirmacion de los estudiantes esta sosteniendo. Decision del
 *    usuario, tomada sabiendo lo que cuesta: si un profesor no puede entrar el
 *    dia de su clase, la asistencia sigue sin poder registrarse por el.
 */
class GestionAsistida
{
    /** Quien la empezo. Su presencia en la sesion ES el modo asistido. */
    private const CLAVE = 'gestion_asistida_admin';

    /** ¿Se puede asistir a esta persona? */
    public static function puedeAsistirA(Perfil $quien, Perfil $destino): bool
    {
        return $quien->rol === 'administrador'
            && in_array($destino->rol, ['profesor', 'director', 'estudiante'], true)
            && $destino->id !== $quien->id
            && ! $destino->estaSuprimido()
            && ! self::activa();
    }

    public static function activa(): bool
    {
        return Session::has(self::CLAVE);
    }

    /** El administrador de verdad, o null si nadie esta asistiendo. */
    public static function administrador(): ?Perfil
    {
        $id = Session::get(self::CLAVE);

        return $id === null ? null : Perfil::find($id);
    }

    /**
     * Empieza la asistencia. Devuelve false si no estaba permitida.
     *
     * La comprobacion se repite AQUI aunque el controlador ya la haya hecho: es
     * la funcion que cambia de identidad, y una puerta que solo vigila quien
     * llama es una puerta que se queda abierta en cuanto alguien llama de otro
     * sitio.
     */
    public static function iniciar(Perfil $quien, Perfil $destino): bool
    {
        if (! self::puedeAsistirA($quien, $destino)) {
            return false;
        }

        Auditoria::registrar('gestion_asistida.inicio', [
            'destino_id' => $destino->id,
            'destino_rol' => $destino->rol,
        ], $quien);

        Session::put(self::CLAVE, $quien->id);
        Auth::login($destino->user);

        return true;
    }

    /**
     * Devuelve al administrador a su cuenta.
     *
     * Si la sesion no trae marca no hace nada, y eso no es un caso raro: el
     * boton de salir vive en una barra que se pinta en todas las pantallas, y
     * una peticion repetida —dos toques, un reenvio— no puede echar a nadie de
     * su propia cuenta.
     */
    public static function terminar(): ?Perfil
    {
        $admin = self::administrador();

        if ($admin === null) {
            return null;
        }

        Auditoria::registrar('gestion_asistida.fin', [
            'destino_id' => Auth::user()?->perfil?->id,
        ], $admin);

        Session::forget(self::CLAVE);
        Auth::login($admin->user);

        return $admin;
    }
}
