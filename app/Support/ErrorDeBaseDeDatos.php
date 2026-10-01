<?php

namespace App\Support;

use Illuminate\Database\QueryException;

/**
 * Lee QUE restriccion rechazo una escritura.
 *
 * Es el equivalente de `_constraint_violada` del original, que en PostgreSQL
 * sacaba el nombre del diagnostico de psycopg. PDO no expone ese campo: el
 * nombre viaja dentro del texto del error, entre comillas, asi que aqui se
 * extrae de ahi.
 *
 * A esto solo se llega en una CARRERA real. La validacion del modelo ya
 * comprobo cupo y limite antes de escribir; si el motor rechaza la operacion es
 * porque entre la comprobacion y el guardado entro otra peticion. Por eso vale
 * la pena distinguir el motivo: "se llenó mientras enviabas" y "ya tienes una
 * en esa promotoría" son cosas muy distintas para quien lo lee.
 *
 * Todo empieza por el SQLSTATE, que no depende del idioma del servidor; el
 * texto solo se lee despues, para sacar el nombre. Un producto que se instala
 * en casas ajenas no elige el `lc_messages` de su PostgreSQL.
 */
class ErrorDeBaseDeDatos
{
    /**
     * SQLSTATE que usa el trigger de cupo (`RAISE ... USING ERRCODE = 45000`).
     *
     * Es el mismo que lanzaba el `SIGNAL` de MariaDB, conservado a proposito.
     * Como es un estado propio y generico, no basta con el codigo y hay que
     * mirar tambien el mensaje, que escribe el propio trigger.
     */
    private const SQLSTATE_CUPO = '45000';

    /** Clave unica repetida. */
    private const SQLSTATE_UNICO = '23505';

    /** Clave foranea: tanto «sigue en uso» como «apunta a algo que no existe». */
    private const SQLSTATE_FORANEA = '23503';

    /** ¿La escritura la rechazo el trigger de cupo de promotoria? */
    public static function esCupoAgotado(QueryException $e): bool
    {
        return ($e->getCode() === self::SQLSTATE_CUPO)
            && str_contains($e->getMessage(), 'no tiene cupos disponibles');
    }

    /**
     * Nombre del indice unico que rechazo la escritura, o null.
     *
     * PostgreSQL lo escribe como:
     *   duplicate key value violates unique constraint "nombre_del_indice"
     * y con el servidor en espanol, entre comillas angulares («...»). Es el
     * PRIMER nombre entre comillas del mensaje en los dos idiomas.
     */
    public static function indiceViolado(QueryException $e): ?string
    {
        if ($e->getCode() !== self::SQLSTATE_UNICO) {
            return null;
        }

        if (preg_match('/["«]([A-Za-z0-9_]+)["»]/u', $e->getMessage(), $coincidencias) === 1) {
            return $coincidencias[1];
        }

        return null;
    }

    /** ¿El estudiante ya ocupaba todas sus ranuras del periodo? */
    public static function esRanuraOcupada(QueryException $e): bool
    {
        return self::indiceViolado($e) === 'una_matricula_por_ranura_y_periodo';
    }

    /** ¿Ya existia una matricula suya en esa promotoria y periodo? */
    public static function esMatriculaRepetida(QueryException $e): bool
    {
        return self::indiceViolado($e) === 'unica_matricula_por_periodo';
    }

    /** ¿Esa persona ya estaba inscrita en la actividad, por su documento? */
    public static function esInscripcionRepetida(QueryException $e): bool
    {
        return self::indiceViolado($e) === 'una_inscripcion_por_documento';
    }

    /**
     * ¿El borrado se rechazo porque otra tabla apunta a la fila?
     *
     * Es el equivalente del `ProtectedError` de Django. PostgreSQL da el MISMO
     * estado (23503) a dos cosas distintas: borrar algo que sigue en uso, e
     * insertar apuntando a algo que no existe. Solo la primera significa «esto
     * todavia esta en uso», y lo que las separa sin depender del idioma es la
     * SENTENCIA que fallo: un borrado.
     */
    public static function esFilaEnUso(QueryException $e): bool
    {
        return $e->getCode() === self::SQLSTATE_FORANEA
            && preg_match('/^\s*delete\b/i', $e->getSql()) === 1;
    }
}
