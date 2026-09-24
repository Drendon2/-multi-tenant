<?php

namespace App\Support;

use App\Models\Perfil;
use App\Models\SesionActividad;
use Illuminate\Support\Carbon;

/**
 * LA UNICA PUERTA DE ESCRITURA de la firma de una institucion externa.
 *
 * Es la hermana de `ConfirmacionClase::registrar()` y existe por lo mismo: hay
 * DOS caminos que llegan a escribir lo mismo —el funcionario entrando a su
 * cuenta, y el profesor leyendo el QR de la institucion al terminar la clase
 * alli— y escritas a mano en cada controlador las dos copias se separan sin que
 * nada falle. El dia que alguien mueva la regla del plazo, la arreglaria en la
 * pantalla que estuviera mirando y el otro camino seguiria con la vieja. Ya
 * paso en esta casa con el contador de clases pendientes.
 *
 * LOS DOS CAMINOS NO TIENEN LA MISMA REGLA, y esa asimetria es el fondo del
 * asunto, no un descuido:
 *
 * - `propia` — el funcionario desde su cuenta: SIN PLAZO. Entra cuando puede,
 *   quiza una vez por semana, y este sistema NO LE AVISA A NADIE DE NADA: un
 *   plazo de dos dias dejaria media lista sin verificar para siempre y sin que
 *   nadie se entere. Da fe con su nombre desde su sesion, que es la forma mas
 *   fuerte que hay aqui.
 *
 * - `qr` — el profesor escaneando el carton de la institucion: SOLO EL MISMO
 *   DIA de la clase. Es literalmente lo que se pidio, «cuando termine alla en
 *   la institucion», y es tambien el unico freno que tiene este camino. Quien
 *   escanea es el profesor, que es a QUIEN LA VERIFICACION VIGILA; el QR es un
 *   carton y un carton se fotografia. Con el corte del dia, la foto sirve para
 *   verificar la clase que se esta dando —estando alli, que es el punto— y no
 *   las quince siguientes desde la casa. Es el mismo riesgo asumido del carne
 *   del estudiante (21/09/2026) y el mismo contrapeso: se puede renovar, y
 *   quien firma de verdad puede retirar lo que no reconozca.
 *
 * NO SE COLAPSAN LAS DOS CIFRAS. `verificacion_origen` las separa y toda
 * pantalla que las cuente tiene que seguir separandolas: una media que mezcla
 * «lo firmo la escuela» con «lo escaneo el profesor» no se puede auditar, que
 * es lo unico para lo que esto existe.
 */
class VerificacionExterna
{
    /** La dio el funcionario, entrando a su cuenta. */
    public const PROPIA = 'propia';

    /** La dio el profesor leyendo el QR de la institucion, alli y ese dia. */
    public const QR = 'qr';

    /**
     * Firma la sesion, o devuelve el motivo por el que no se pudo.
     *
     * Devuelve `null` cuando quedo firmada —incluido el caso de que YA lo
     * estuviera, que no es un fallo: dos toques del mismo boton, o el mismo
     * carton leido dos veces con la camara encendida, es lo normal— y una
     * palabra cuando no. Quien llama traduce esa palabra a una frase, porque la
     * pantalla es la que sabe decir POR QUE con las palabras de quien mira.
     *
     * NO REESCRIBE UNA FIRMA QUE YA ESTA. Una clase que el funcionario ya
     * verifico desde su cuenta y que despues se escanea con el QR conserva
     * `propia`, que es lo cierto: dio fe el, y el carton solo repitio lo que ya
     * estaba. Es la misma regla que `ConfirmacionClase::registrar()`.
     */
    public static function registrar(
        SesionActividad $sesion,
        Perfil $quien,
        string $origen,
        ?Carbon $ahora = null,
    ): ?string {
        $ahora ??= Carbon::now();

        // Una clase que no empezo no se puede haber dado. El boton de la
        // pantalla ya lo tiene en cuenta; esto cierra la peticion enviada a
        // mano y la pestana que quedo abierta desde antes.
        if (! $sesion->yaEmpezo()) {
            return 'sin_iniciar';
        }

        if ($sesion->estaVerificada()) {
            return null;
        }

        if ($origen === self::QR && ! self::esDeHoy($sesion, $ahora)) {
            return 'fuera_de_plazo';
        }

        $sesion->verificada_en = $ahora;
        $sesion->verificada_por_id = $quien->id;
        $sesion->verificacion_origen = $origen;
        $sesion->save();

        return null;
    }

    /**
     * Retira la firma.
     *
     * EXISTE POR LA MISMA RAZON QUE SE PUEDE RETIRAR UNA CONFIRMACION DE CLASE:
     * una firma es una afirmacion de alguien sobre lo que vio, y quien se
     * equivoco de renglon tiene que poder deshacerlo. Dejarla fija por miedo a
     * que alguien juegue con ella tendria el precio de volver permanente justo
     * el error que este registro existe para evitar.
     *
     * Y AQUI PESA MAS QUE ALLI: el camino del QR lo recorre el profesor, asi
     * que esto es lo unico que tiene la institucion para desconocer una firma
     * que ella no dio. Sin retirada, el QR seria la ultima palabra de quien
     * esta siendo verificado.
     *
     * Las tres columnas se vacian juntas porque el CHECK de la base las exige
     * juntas, y porque media firma no significa nada.
     */
    public static function retirar(SesionActividad $sesion): void
    {
        $sesion->verificada_en = null;
        $sesion->verificada_por_id = null;
        $sesion->verificacion_origen = null;
        $sesion->save();
    }

    /**
     * Si la clase es de hoy, mirando la hora REAL en que se inicio.
     *
     * Se compara contra `iniciada_en` y NO contra `fecha`. Son dos datos
     * distintos —la fecha dice cuando tocaba y `iniciada_en` cuando paso— y en
     * un programa externo la sesion nace al oprimir el boton, asi que la unica
     * que cuenta lo que de verdad ocurrio es la segunda. Con `fecha` bastaria
     * un servidor en otra zona horaria, o una sesion creada a las 23:58, para
     * que el profesor no pudiera escanear el carton que tiene en la mano.
     */
    private static function esDeHoy(SesionActividad $sesion, Carbon $ahora): bool
    {
        // `iniciada_en` es anulable —su vacio significa «nadie ha oprimido
        // Iniciar»— y aqui no puede serlo porque `yaEmpezo()` ya corto arriba.
        // Se pregunta igual en vez de darlo por hecho: quien llame a esto desde
        // otro sitio manana no tiene por que haber pasado por aquel corte, y
        // una clase sin iniciar no es «de hoy» por mucho que hoy sea su fecha.
        return $sesion->iniciada_en?->isSameDay($ahora) ?? false;
    }
}
