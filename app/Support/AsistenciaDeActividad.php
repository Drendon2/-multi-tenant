<?php

namespace App\Support;

use App\Models\Actividad;
use App\Models\AsistenciaActividad;
use App\Models\InscritoActividad;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Cuanto asistio cada inscrito a una actividad, y si eso le da certificado.
 *
 * Vive en un solo sitio porque la misma cifra hace dos cosas que no pueden
 * discrepar: la pantalla decide con ella si pinta el boton y el controlador del
 * PDF decide con ella si deja descargar. Escritas por separado, el dia que la
 * regla cambie una de las dos se queda vieja y nadie lo ve — el boton sale y al
 * pulsarlo rebota, o al reves.
 *
 * ─── LAS TRES REGLAS, Y DE DONDE SALE CADA UNA ─────────────────────────────
 *
 * Las decidio el usuario el 11/09/2026, y ninguna se deduce del esquema:
 *
 * 1. HACE FALTA EL 80%. Por debajo no hay papel.
 *
 * 2. EL DENOMINADOR SON LAS SESIONES CON LISTA TOMADA, no las programadas.
 *    «Si el profesor no toma lista no se le cuenta la falta a los estudiantes»:
 *    una sesion que nadie paso no es evidencia de que nadie fuera, es la falta
 *    de evidencia. Aqui eso se mide por la EXISTENCIA DE MARCAS y no por
 *    `sesiones_actividad.iniciada_en`, y la diferencia importa: una sesion
 *    iniciada a la que no se le paso lista a nadie tiene hora de inicio y cero
 *    marcas, o sea que con `iniciada_en` contaria en el denominador y bajaria
 *    el porcentaje de TODOS por algo que ninguno hizo.
 *
 * 3. LA EXCUSA NO SUMA. Cuenta solo «Asistio». Una excusa justifica la falta
 *    —por eso corta la racha de la alerta de abandono— pero no pone a nadie en
 *    el salon, y esto certifica haber estado.
 *
 * ─── Y EL CASO QUE NO SE PREGUNTA SOLO ─────────────────────────────────────
 *
 * A quien el profesor se salto en una sesion que SI tuvo lista, esa sesion le
 * cuenta como no asistida. Decidido el 11/09 con la alternativa delante, que
 * era contar solo las sesiones donde a esa persona la marcaron: mas coherente
 * con el resto del sistema —sin fila nadie afirma nada sobre ella— pero regala
 * el papel a quien fue UNA vez y justo ese dia lo marcaron, porque 1 de 1 es el
 * 100%. «El 80% de las clases» son las clases que se dieron.
 */
class AsistenciaDeActividad
{
    /** La proporcion de sesiones a la que hay que haber ido. */
    public const MINIMO = 0.8;

    /**
     * El resumen de UN inscrito.
     *
     * @return array{sesiones: int, asistidas: int, porcentaje: int, certificable: bool}
     */
    public static function deInscrito(InscritoActividad $inscrito): array
    {
        $sesiones = self::sesionesConLista($inscrito->actividad_id);

        $asistidas = AsistenciaActividad::query()
            ->where('inscrito_id', $inscrito->id)
            ->where('estado', AsistenciaActividad::ASISTIO)
            ->count();

        return self::resumen($sesiones, $asistidas);
    }

    /**
     * El resumen de TODOS los inscritos de una actividad, en dos consultas
     * fijas.
     *
     * Dos y no una por fila: esta cifra se pinta en la tabla del Panel, que
     * lista de cincuenta en cincuenta. Preguntarla dentro del bucle era
     * cincuenta consultas por visita.
     *
     * @return array<int, array{sesiones: int, asistidas: int, porcentaje: int, certificable: bool}>
     */
    public static function deActividad(Actividad $actividad): array
    {
        $sesiones = self::sesionesConLista($actividad->id);

        $asistidas = AsistenciaActividad::query()
            ->join('sesiones_actividad', 'sesiones_actividad.id', '=', 'asistencias_actividad.sesion_id')
            ->where('sesiones_actividad.actividad_id', $actividad->id)
            ->where('asistencias_actividad.estado', AsistenciaActividad::ASISTIO)
            ->groupBy('asistencias_actividad.inscrito_id')
            ->pluck(
                // Con alias: sin el, cada motor bautiza la columna a su manera
                // (MariaDB «COUNT(*)», PostgreSQL «count») y `pluck` la busca
                // por ese nombre.
                DB::raw('COUNT(*) as total'),
                'asistencias_actividad.inscrito_id'
            );

        $resumen = [];

        foreach ($actividad->inscritos()->pluck('id') as $id) {
            $resumen[$id] = self::resumen($sesiones, (int) ($asistidas[$id] ?? 0));
        }

        return $resumen;
    }

    /**
     * Cuantas sesiones de esta actividad tienen lista tomada.
     *
     * Una sesion con marcas es una sesion que alguien paso. No se mira
     * `iniciada_en` a proposito — ver la regla 2 de la cabecera.
     */
    public static function sesionesConLista(int $actividadId): int
    {
        return AsistenciaActividad::query()
            ->join('sesiones_actividad', 'sesiones_actividad.id', '=', 'asistencias_actividad.sesion_id')
            ->where('sesiones_actividad.actividad_id', $actividadId)
            ->distinct()
            ->count('asistencias_actividad.sesion_id');
    }

    /**
     * Entre que dos fechas ocurrio lo que se certifica.
     *
     * Son las fechas de la primera y la ultima sesion CON LISTA, no las que se
     * programaron al crear el curso: el papel dice lo que paso, y un curso
     * programado hasta diciembre que se dejo de dictar en octubre no se
     * certifica hasta diciembre. Devuelve null cuando no hay ninguna.
     *
     * @return array{0: Carbon, 1: Carbon}|null
     */
    public static function fechasDictadas(int $actividadId): ?array
    {
        // `DB::table` y no `AsistenciaActividad::query()`: lo que vuelve son dos
        // columnas calculadas y no una fila de esa tabla, asi que hidratar un
        // modelo seria mentir sobre lo que es —y cobrarlo—.
        $fechas = InstitucionActual::tabla('asistencias_actividad')
            ->join('sesiones_actividad', 'sesiones_actividad.id', '=', 'asistencias_actividad.sesion_id')
            ->where('sesiones_actividad.actividad_id', $actividadId)
            ->selectRaw('MIN(sesiones_actividad.fecha) AS primera, MAX(sesiones_actividad.fecha) AS ultima')
            ->first();

        if ($fechas === null || $fechas->primera === null) {
            return null;
        }

        return [Carbon::parse($fechas->primera), Carbon::parse($fechas->ultima)];
    }

    /**
     * @return array{sesiones: int, asistidas: int, porcentaje: int, certificable: bool}
     */
    private static function resumen(int $sesiones, int $asistidas): array
    {
        // SIN NINGUNA SESION CON LISTA no se certifica, y el cero de aqui no es
        // solo por no dividir entre cero: no hay nada que acreditar. Un curso
        // recien creado tiene inscritos y ninguna evidencia de que se haya
        // dado, y un papel firmado diciendo que asistieron seria falso.
        if ($sesiones <= 0) {
            return ['sesiones' => 0, 'asistidas' => 0, 'porcentaje' => 0, 'certificable' => false];
        }

        $proporcion = $asistidas / $sesiones;

        return [
            'sesiones' => $sesiones,
            'asistidas' => $asistidas,
            // FLOOR Y NO ROUND, y es la diferencia entre una pantalla
            // coherente y una que miente. Con `round`, un 79,6% se pinta «80%»
            // y el boton no sale: quien lo lee ve el numero que pide el papel y
            // ningun sitio donde pulsar, que es el «no hizo nada» que este
            // proyecto ya pago una vez. Truncando, «80%» en pantalla y
            // `certificable` son la MISMA condicion — floor(p*100) >= 80
            // equivale a p >= 0,8— asi que no pueden contradecirse.
            'porcentaje' => (int) floor($proporcion * 100),
            'certificable' => $proporcion >= self::MINIMO,
        ];
    }
}
