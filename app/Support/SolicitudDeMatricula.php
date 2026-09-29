<?php

namespace App\Support;

use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Un estudiante que ya tiene cuenta pide una promotoria.
 *
 * Vivia dentro de `MatricularController` y salio el 29/09/2026, cuando nacio
 * el SEGUNDO camino: el enlace de una promotoria, que matricula con la ventana
 * cerrada. Los dos tienen que decir lo mismo —acudiente de un menor, rechazo
 * que no se vuelve a pedir, reactivar la fila retirada, cupo, limite— y dos
 * copias se separan sin que nada falle.
 *
 * LO QUE NO MIRA ES LA VENTANA DE MATRICULAS, y es a proposito: esa es la
 * unica diferencia entre los dos caminos, asi que la decide cada uno.
 */
class SolicitudDeMatricula
{
    /**
     * @return array{0: bool, 1: string} Si quedo registrada y lo que hay que decir.
     */
    public static function pedir(Perfil $perfil, Promotoria $promotoria, Periodo $periodo): array
    {
        $datosEstudiante = $perfil->datosEstudiante;

        if ($datosEstudiante === null) {
            return [false, 'Tu registro como estudiante no está completo (falta documento de identidad). '
                .'Contacta al administrador.'];
        }

        if ($perfil->es_menor && $datosEstudiante->acudiente_id === null) {
            return [false, 'Eres menor de edad y no tienes un acudiente registrado. '
                .'Pide al administrador que registre tu acudiente antes de matricularte.'];
        }

        // Si ya se retiro de esta promotoria en este periodo se REACTIVA su
        // matricula en vez de crear otra: `unica_matricula_por_periodo` no
        // admite una segunda fila para el mismo (estudiante, promotoria,
        // periodo), y sin esto el boton "Matricularme" de esa fila no llevaria a
        // ninguna parte. La fecha original se conserva; el estado vuelve a
        // pendiente y hay que confirmarla de nuevo.
        $matricula = Matricula::query()
            ->where('estudiante_id', $perfil->id)
            ->where('promotoria_id', $promotoria->id)
            ->where('periodo_id', $periodo->id)
            ->where('estado', Matricula::RETIRADA)
            ->first();

        // Una solicitud RECHAZADA no se vuelve a pedir por aqui. El corte va
        // aqui y no solo en el boton del catalogo: esconder el control no
        // cierra la URL, y una pagina vieja en el telefono seguiria mandando el
        // POST. Tampoco la reabre el enlace de la promotoria: quien fue
        // rechazado no entra por la puerta de al lado.
        //
        // La salida no es un callejon: direccion readmite moviendo la matricula
        // (ver `FichaController::corregirPromotoria`), que ademas es el camino
        // por el que alguien mira el caso antes de que vuelva a entrar.
        if ($matricula !== null && $matricula->motivo_retiro === Matricula::RETIRO_RECHAZO) {
            return [false, "Tu solicitud a {$promotoria} no fue aceptada, así que no puedes volver a "
                .'pedirla por tu cuenta este periodo. Habla con la institución si crees que '
                .'fue un error.'];
        }

        $reactivada = $matricula !== null;

        if ($reactivada) {
            $matricula->estado = Matricula::PENDIENTE;
            // La fila se reutiliza, asi que el motivo de la salida anterior
            // viajaria con ella y la nueva solicitud nacería contando que la
            // rechazaron. Se borra: vuelve a estar en juego.
            $matricula->motivo_retiro = null;
            $matricula->repartirEn([]);
        } else {
            $matricula = new Matricula([
                'estudiante_id' => $perfil->id,
                'promotoria_id' => $promotoria->id,
                'periodo_id' => $periodo->id,
            ]);
        }

        try {
            DB::transaction(function () use ($matricula) {
                $matricula->validar();
                $matricula->save();
            });
        } catch (ValidationException $e) {
            return [false, implode(' ', $e->validator->errors()->all())];
        } catch (QueryException $e) {
            return [false, self::mensajeDeConflicto($e, $promotoria, $periodo)];
        }

        return [true, $reactivada
            ? "Volviste a inscribirte en {$promotoria}. Tu matrícula quedó otra vez "
                .'pendiente de confirmación del profesor.'
            : "Tu inscripción a {$promotoria} quedó pendiente de confirmación del profesor."];
    }

    /**
     * Traduce el rechazo de la base de datos al mensaje que corresponde.
     *
     * Aqui solo se llega en una CARRERA real: la validacion del modelo ya
     * comprobo todo esto, asi que si el motor rechaza la escritura es porque
     * entre la comprobacion y el guardado entro otra peticion.
     */
    private static function mensajeDeConflicto(QueryException $e, Promotoria $promotoria, Periodo $periodo): string
    {
        if (ErrorDeBaseDeDatos::esCupoAgotado($e)) {
            return "{$promotoria} se llenó mientras enviabas la solicitud: alguien tomó el "
                ."último cupo de {$periodo}. No quedó registrada.";
        }

        // Matricula repetida en la misma promotoria, o el indice que limita las
        // promotorias por periodo si dos peticiones llegaron a la vez.
        $limite = Matricula::limitePromotorias();

        return 'No se pudo registrar la matrícula: o ya tienes una en esa promotoría este '
            ."periodo, o ya ocupas las {$limite} promotorías permitidas. "
            .'Revisa tus matrículas y vuelve a intentarlo.';
    }
}
