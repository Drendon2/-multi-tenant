<?php

/**
 * Trigger que hace cumplir el cupo de promotoria dentro de la propia base de datos.
 *
 * ---------------------------------------------------------------------------
 * TRAMPA (b) DE LA MIGRACION
 * ---------------------------------------------------------------------------
 * La validacion del modelo `Matricula` ya comprueba el cupo y da el mensaje
 * legible, pero entre esa comprobacion y el INSERT hay una ventana: dos
 * solicitudes simultaneas para el ultimo sitio pasan las dos. Un CHECK no sirve
 * (solo ve su propia fila) y un indice unico tampoco (el maximo es un dato
 * variable, no una forma fija de la tabla), asi que la unica garantia real es
 * un trigger.
 *
 * La clave para que sea a prueba de carreras es el `FOR UPDATE` sobre la fila
 * de `cupos_promotoria`: esa fila es el cerrojo natural de la promotoria en ese
 * periodo. Dos transacciones que intenten matricular a la vez se serializan
 * ahi, y la segunda ya cuenta a la primera. Sin cupo definido no hay fila, no
 * hay cerrojo y no hay tope: el camino sin limite no paga nada.
 *
 * Diferencias con PostgreSQL, todas de sintaxis:
 *   RAISE EXCEPTION            -> SIGNAL SQLSTATE '45000'
 *   IF NOT FOUND               -> variable inicializada a NULL + IS NOT NULL
 *   id IS DISTINCT FROM NEW.id -> NOT (id <=> NEW.id)
 *   un solo trigger INSERT OR UPDATE -> dos triggers, MariaDB no los combina
 *
 * Por que DOS triggers y no uno: MariaDB no admite `BEFORE INSERT OR UPDATE` en
 * una sola definicion. El cuerpo es casi el mismo, pero la rama de UPDATE
 * ademas tiene que dejar pasar las operaciones que no suman ocupacion.
 *
 * Por que el UPDATE se salta la comprobacion cuando no cambia promotoria ni
 * periodo: confirmar, rechazar, asignar grupo o mover de grupo NO cambian
 * cuantos sitios se ocupan. Aplicarles el tope dejaria al personal sin poder
 * tocar las matriculas que YA existen en cuanto alguien bajara el cupo. Solo
 * suman: crear una matricula, reactivar una retirada, o moverla a otra
 * promotoria/periodo.
 *
 * Por que el conteo excluye la propia fila: en un UPDATE la matricula que se
 * esta moviendo ya esta en la tabla, y sin excluirla se contaria a si misma.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared("
            CREATE TRIGGER cupo_promotoria_disponible_insert
            BEFORE INSERT ON matriculas
            FOR EACH ROW
            BEGIN
                DECLARE v_maximo   INT DEFAULT NULL;
                DECLARE v_ocupados INT DEFAULT 0;
                DECLARE v_promotoria VARCHAR(60);
                DECLARE v_periodo    VARCHAR(20);
                DECLARE v_mensaje    VARCHAR(255);

                -- Una matricula retirada no ocupa sitio: nada que comprobar.
                IF NEW.estado <> 'retirada' THEN
                    SELECT cupo_maximo INTO v_maximo
                      FROM cupos_promotoria
                     WHERE promotoria_id = NEW.promotoria_id
                       AND periodo_id = NEW.periodo_id
                     FOR UPDATE;

                    -- Sin cupo definido para ese periodo, no hay tope.
                    IF v_maximo IS NOT NULL THEN
                        SELECT COUNT(*) INTO v_ocupados
                          FROM matriculas
                         WHERE promotoria_id = NEW.promotoria_id
                           AND periodo_id = NEW.periodo_id
                           AND estado <> 'retirada';

                        IF v_ocupados >= v_maximo THEN
                            SELECT nombre INTO v_promotoria FROM promotorias WHERE id = NEW.promotoria_id;
                            SELECT nombre INTO v_periodo    FROM periodos    WHERE id = NEW.periodo_id;
                            SET v_mensaje = CONCAT(
                                v_promotoria, ' no tiene cupos disponibles para ', v_periodo, ': ',
                                v_ocupados, ' de ', v_maximo,
                                ' ocupados, contando las solicitudes pendientes.'
                            );
                            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_mensaje;
                        END IF;
                    END IF;
                END IF;
            END
        ");

        DB::unprepared("
            CREATE TRIGGER cupo_promotoria_disponible_update
            BEFORE UPDATE ON matriculas
            FOR EACH ROW
            BEGIN
                DECLARE v_maximo   INT DEFAULT NULL;
                DECLARE v_ocupados INT DEFAULT 0;
                DECLARE v_promotoria VARCHAR(60);
                DECLARE v_periodo    VARCHAR(20);
                DECLARE v_mensaje    VARCHAR(255);

                IF NEW.estado <> 'retirada'
                   AND NOT (OLD.estado <> 'retirada'
                            AND OLD.promotoria_id = NEW.promotoria_id
                            AND OLD.periodo_id = NEW.periodo_id) THEN

                    SELECT cupo_maximo INTO v_maximo
                      FROM cupos_promotoria
                     WHERE promotoria_id = NEW.promotoria_id
                       AND periodo_id = NEW.periodo_id
                     FOR UPDATE;

                    IF v_maximo IS NOT NULL THEN
                        SELECT COUNT(*) INTO v_ocupados
                          FROM matriculas
                         WHERE promotoria_id = NEW.promotoria_id
                           AND periodo_id = NEW.periodo_id
                           AND estado <> 'retirada'
                           AND NOT (id <=> NEW.id);

                        IF v_ocupados >= v_maximo THEN
                            SELECT nombre INTO v_promotoria FROM promotorias WHERE id = NEW.promotoria_id;
                            SELECT nombre INTO v_periodo    FROM periodos    WHERE id = NEW.periodo_id;
                            SET v_mensaje = CONCAT(
                                v_promotoria, ' no tiene cupos disponibles para ', v_periodo, ': ',
                                v_ocupados, ' de ', v_maximo,
                                ' ocupados, contando las solicitudes pendientes.'
                            );
                            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_mensaje;
                        END IF;
                    END IF;
                END IF;
            END
        ");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS cupo_promotoria_disponible_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS cupo_promotoria_disponible_update');
    }
};
