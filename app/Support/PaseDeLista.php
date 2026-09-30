<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Guardar una hoja de asistencia, sea de una clase o de una sesion de
 * actividad.
 *
 * Existe por B-01 de la auditoria del 24/08. El sistema tiene dos verticales de
 * asistencia completas y paralelas —`clases`/`asistencias` frente a
 * `sesiones_actividad`/`asistencias_actividad`— porque la original cuelga de una
 * matricula y el modulo de actividades era explicitamente para gente SIN cuenta
 * y sin matricula. Separarlas fue lo prudente para entregar; mantenerlas
 * separadas es lo que sale caro, porque toda regla nueva de asistencia hay que
 * escribirla dos veces y el dia que se olvide una, divergen en silencio.
 *
 * Ya hay evidencia de que pasa: el mismo error de concordancia de genero se
 * corrigio en el aviso de una pantalla y se repitio dos horas despues en la otra
 * (`5f6e1bd` y `54b2226`). Si eso pasa con una frase, pasa con una regla.
 *
 * Esto NO unifica el esquema —eso es cirugia mayor sobre produccion y no esta
 * decidido— sino la unica pieza que las dos comparten de verdad: que estados se
 * aceptan, que significa no marcar a alguien, y que todo entre en una sola
 * transaccion. Es el paso 1 de los tres que propone el informe.
 */
class PaseDeLista
{
    /**
     * Prefijo del campo del formulario. Los dos parciales de Blade lo pintan y
     * esta clase lo lee: si cambia, cambia en un solo sitio.
     */
    public const PREFIJO = 'estado_';

    /**
     * Marca a quien venga en la peticion y devuelve a cuantos se marco.
     *
     * `$sesion` es la parte fija de la clave unica —`['clase_id' => 7]` o
     * `['sesion_id' => 7]`— y `$quien` la columna que cambia en cada vuelta
     * (`matricula_id` o `inscrito_id`). Se piden por separado y no como una
     * clave ya montada porque asi el bucle no tiene que saber de que vertical
     * es: solo une las dos mitades.
     *
     * Los estados validos salen del propio modelo (`$asistencias::ESTADOS`), que
     * en actividades ya toma sus valores de `Asistencia::ESTADOS` en vez de
     * copiarlos. Esa era la unica pieza compartida que habia; ahora son dos.
     *
     * @param  class-string  $asistencias  el modelo que guarda la marca
     * @param  array<string, int>  $sesion  la mitad fija de la clave unica
     * @param  string  $quien  la columna de quien asiste
     * @param  iterable<int>  $ids  a quien se puede marcar en esta hoja
     */
    public static function guardar(
        Request $request,
        string $asistencias,
        array $sesion,
        string $quien,
        iterable $ids,
    ): int {
        // Una sola marca de tiempo para la hoja entera, y no un `now()` por
        // fila: pasar lista es UN acto: que la primera y la ultima marca
        // difieran en milisegundos no significaba nada y solo hacia el dato mas
        // dificil de leer.
        $ahora = now();
        $filas = [];

        foreach ($ids as $id) {
            $estado = $request->input(self::PREFIJO.$id);

            // Sin marcar es valido y se representa por la AUSENCIA de fila:
            // saltarselo aqui es exactamente lo que hace falta. Tambien
            // descarta lo que llegue inventado, porque la peticion entra
            // igual si alguien la envia a mano.
            if (! array_key_exists($estado, $asistencias::ESTADOS)) {
                continue;
            }

            $filas[] = $sesion + [
                $quien => $id,
                'estado' => $estado,
                // Por lo mismo que `fecha_registro` aqui abajo: `upsert()`
                // no dispara el evento que pone la institucion, y sin ella la
                // base rechaza la fila (no tiene valor por defecto).
                InstitucionActual::COLUMNA => InstitucionActual::id(),
                // Va explicito, y ES la trampa de este metodo. La ponia el
                // `saving` del modelo --«se refresca en cada guardado: es la
                // marca de la ultima correccion»-- y `upsert()` NO dispara
                // eventos de Eloquent. Sin esta linea, una hoja nueva chocaria
                // contra el NOT NULL de la columna, y una correccion se
                // guardaria conservando la fecha de la primera vez: el dato
                // seguiria ahi, mintiendo, sin que nada fallara.
                //
                // El `saving` del modelo SE QUEDA: hay otros dos caminos que
                // crean asistencias de una en una (`Simular` y el «anadir a
                // quien llego sin inscribirse» del panel de actividades) y esos
                // si dependen de el.
                'fecha_registro' => $ahora,
            ];
        }

        if ($filas === []) {
            return 0;
        }

        // La transaccion se queda aunque hoy envuelva una sola sentencia --que
        // ya es atomica por si misma--. Es una de las tres cosas que esta clase
        // promete unificar, dice el docblock, y quitarla ahorraria dos viajes a
        // cambio de que la promesa dejara de ser verdad el dia que aqui haya
        // una segunda escritura.
        DB::transaction(function () use ($asistencias, $filas, $sesion, $quien) {
            $asistencias::upsert(
                $filas,
                array_merge(array_keys($sesion), [$quien]),
                ['estado', 'fecha_registro']
            );
        });

        return count($filas);
    }
}
