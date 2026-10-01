<?php

namespace Tests\Unit;

use App\Support\ErrorDeBaseDeDatos;
use Exception;
use Illuminate\Database\QueryException;
use PDOException;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * El traductor de errores del motor.
 *
 * Existe porque de el depende una decision que no es cosmetica: que error se le
 * cuenta al usuario como "esto ya estaba hecho" y cual tiene que propagarse.
 * Confundirlos es como el formulario publico de actividades llego a responder
 * "ya estabas inscrito" ante una base caida.
 *
 * Es una prueba unitaria de verdad —sin base de datos— porque lo unico que hace
 * esta clase es leer el SQLSTATE, el mensaje y la sentencia. Las excepciones se
 * construyen a mano con los mensajes que PostgreSQL escribe de verdad, en ingles
 * y con el servidor en espanol: un producto que se instala en casas ajenas no
 * elige el `lc_messages` de su base.
 */
class ErrorDeBaseDeDatosTest extends TestCase
{
    /**
     * Como las construye `pdo_pgsql`: el codigo de la excepcion es el SQLSTATE,
     * un TEXTO. `new PDOException()` solo admite un entero, asi que se pone a
     * mano; sin eso la prueba no se pareceria a lo que llega en produccion.
     */
    private function excepcion(string $sqlstate, string $mensaje, string $sql = 'insert into "x" values (?)'): QueryException
    {
        $previa = new PDOException("SQLSTATE[{$sqlstate}]: {$mensaje}");
        (new ReflectionProperty(Exception::class, 'code'))->setValue($previa, $sqlstate);
        $previa->errorInfo = [$sqlstate, 7, $mensaje];

        return new QueryException('pgsql', $sql, [], $previa);
    }

    public function test_reconoce_el_unico_de_inscripcion_por_documento(): void
    {
        $e = $this->excepcion('23505', 'Unique violation: 7 ERROR:  duplicate key value violates unique constraint "una_inscripcion_por_documento"'
            ."\nDETAIL:  Key (actividad_id, documento)=(3, 99887766) already exists.");

        $this->assertTrue(ErrorDeBaseDeDatos::esInscripcionRepetida($e));
    }

    public function test_reconoce_el_nombre_con_el_servidor_en_espanol(): void
    {
        $e = $this->excepcion('23505', 'Unique violation: 7 ERROR:  llave duplicada viola restricción de unicidad «una_inscripcion_por_documento»'
            ."\nDETAIL:  Ya existe la llave (actividad_id, documento)=(3, 99887766).");

        $this->assertTrue(
            ErrorDeBaseDeDatos::esInscripcionRepetida($e),
            'Solo se reconoce en ingles: con otro idioma, una carrera se contaria como un fallo.'
        );
    }

    public function test_no_confunde_otro_unico_con_ese(): void
    {
        // Dos indices distintos de la misma familia de error. Sin mirar el
        // NOMBRE, cualquier clave repetida pasaria por una inscripcion
        // repetida.
        $e = $this->excepcion('23505', 'Unique violation: 7 ERROR:  duplicate key value violates unique constraint "unica_matricula_por_periodo"');

        $this->assertFalse(ErrorDeBaseDeDatos::esInscripcionRepetida($e));
        $this->assertTrue(ErrorDeBaseDeDatos::esMatriculaRepetida($e));
    }

    public function test_un_fallo_que_no_es_de_indice_no_es_una_repeticion(): void
    {
        // Este es el caso que importa: la base caida, la tabla que falta, el
        // CHECK violado. Nada de eso significa "ya estaba hecho", y contarlo
        // como tal deja a alguien fuera creyendo que entro. El CHECK es el que
        // mas se parece: tambien trae un nombre entre comillas.
        foreach ([
            ['08006', 'server closed the connection unexpectedly'],
            ['42P01', 'Undefined table: 7 ERROR:  relation "inscritos_actividad" does not exist'],
            ['23514', 'Check violation: 7 ERROR:  new row for relation "inscritos_actividad" violates check constraint "nombre_de_inscrito_no_vacio"'],
            ['55P03', 'Lock not available: 7 ERROR:  canceling statement due to lock timeout'],
            // El que obliga a mirar el SQLSTATE antes que el texto: un
            // interbloqueo al escribir en ESE indice lo nombra entre comillas,
            // y la persona no quedo inscrita.
            ['40P01', 'Deadlock detected: 7 ERROR:  deadlock detected'
                ."\nCONTEXT:  while inserting index tuple (0,7) in relation \"una_inscripcion_por_documento\""],
        ] as [$sqlstate, $mensaje]) {
            $this->assertFalse(
                ErrorDeBaseDeDatos::esInscripcionRepetida($this->excepcion($sqlstate, $mensaje)),
                "Se conto como inscripcion repetida: {$mensaje}"
            );
        }
    }

    public function test_el_cupo_se_reconoce_por_su_estado_y_su_mensaje(): void
    {
        $agotado = $this->excepcion('45000', '7 ERROR:  Guitarra no tiene cupos disponibles para 2026-2: 20 de 20 ocupados, contando las solicitudes pendientes.');

        $this->assertTrue(ErrorDeBaseDeDatos::esCupoAgotado($agotado));
        // El mismo texto con otro estado no es el trigger.
        $this->assertFalse(ErrorDeBaseDeDatos::esCupoAgotado(
            $this->excepcion('P0001', '7 ERROR:  Guitarra no tiene cupos disponibles para 2026-2')
        ));
    }

    public function test_la_fila_en_uso_se_distingue_por_la_sentencia_y_no_por_el_mensaje(): void
    {
        // PostgreSQL da el MISMO estado a «sigue en uso» (al borrar) y a
        // «apunta a algo que no existe» (al insertar). Solo el primero
        // significa que el borrado hay que negarlo.
        $alBorrar = $this->excepcion(
            '23503',
            'Foreign key violation: 7 ERROR:  update or delete on table "grupos" violates foreign key constraint "asignaciones_grupo_grupo_id_foreign" on table "asignaciones_grupo"',
            'delete from "grupos" where "id" = ?',
        );
        $alInsertar = $this->excepcion(
            '23503',
            'Foreign key violation: 7 ERROR:  insert or update on table "asignaciones_grupo" violates foreign key constraint "asignaciones_grupo_grupo_id_foreign"',
        );

        $this->assertTrue(ErrorDeBaseDeDatos::esFilaEnUso($alBorrar));
        $this->assertFalse(ErrorDeBaseDeDatos::esFilaEnUso($alInsertar));
    }
}
