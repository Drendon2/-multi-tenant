<?php

namespace App\Support;

use App\Models\Acudiente;
use App\Models\DatosEstudiante;
use App\Models\Matricula;
use App\Models\Perfil;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Borra los datos personales de alguien que lo pidio (Ley 1581, art. 8 e).
 *
 * ANONIMIZA, NO BORRA LA FILA, y es la decision del usuario del 30/09/2026 con
 * las dos opciones delante. Borrar el perfil se lleva sus matriculas por el
 * CASCADE del esquema, y con ellas cambian las cifras de todos los periodos
 * pasados: Estadisticas, Poblacion impactada, el porcentaje de asistencia de un
 * grupo y las estadisticas de un profesor. Anonimizado, el hecho de que hubo una
 * matricula sigue en pie y nada de lo que queda dice quien era: un dato
 * anonimizado deja de ser un dato personal, que es lo que la supresion exige.
 *
 * LO QUE SE VA: nombre, fecha de nacimiento, telefono, documento del personal,
 * foto, codigo del carne, usuario y correo de la cuenta, la ficha de
 * estudiante con su documento, su
 * acudiente y TODOS sus papeles (los archivos del disco tambien), las dos
 * encuestas, y lo que dejo en las listas de talleres y cursos.
 *
 * LO QUE SE QUEDA: sus matriculas, asistencias y confirmaciones, colgando de un
 * perfil que se llama «Persona suprimida» y que no puede entrar.
 *
 * LAS MATRICULAS VIVAS SE RETIRAN, con motivo propio: una silla ocupada por
 * nadie es un cupo que le falta a otro (decision del usuario, el mismo dia).
 * «Viva» es la que no esta retirada y cuyo periodo no termino. Una ACTIVA de un
 * periodo pasado NO se toca: es lo que la pantalla enseña como «finalizada», y
 * retirarla reescribiria la historia que justo se quiere conservar.
 *
 * LOS ARCHIVOS SE BORRAN DESPUES DEL COMMIT. Al reves, un fallo a mitad de la
 * transaccion devolveria las filas y dejaria papeles apuntando a archivos que ya
 * no existen, que es un 500 en la ficha de esa persona.
 *
 * Lo que esto NO alcanza, y hay que decirselo al titular si pregunta: las copias
 * de seguridad (las del despliegue y `respaldos/` de `documentos:aligerar`,
 * cuyos archivos ya no se pueden relacionar con nadie) y el registro de
 * auditoria, que guarda ids y nunca nombres.
 */
class SupresionDeDatos
{
    public const NOMBRE = 'Persona suprimida';

    /**
     * @return int cuantas matriculas vivas quedaron retiradas
     */
    public static function suprimir(Perfil $perfil, ?Perfil $quien = null): int
    {
        $archivos = [];

        $retiradas = DB::transaction(function () use ($perfil, &$archivos) {
            $retiradas = 0;

            $vivas = Matricula::query()
                ->where('estudiante_id', $perfil->id)
                ->with('periodo')
                ->where('estado', '!=', Matricula::RETIRADA)
                ->get()
                ->reject(fn (Matricula $m) => $m->periodo->termino);

            foreach ($vivas as $matricula) {
                $matricula->estado = Matricula::RETIRADA;
                $matricula->motivo_retiro = Matricula::RETIRO_SUPRESION;
                $matricula->repartirEn([]);
                $matricula->save();
                $retiradas++;
            }

            if ($perfil->foto_perfil !== '') {
                $archivos[] = $perfil->foto_perfil;
            }

            $ficha = DatosEstudiante::with('documentos')->where('perfil_id', $perfil->id)->first();

            if ($ficha !== null) {
                foreach ($ficha->documentos as $documento) {
                    if ($documento->archivo !== '') {
                        $archivos[] = $documento->archivo;
                    }
                }

                $acudienteId = $ficha->acudiente_id;
                // Los documentos se van con la ficha (CASCADE). El acudiente
                // NO: la clave va de la ficha hacia el, asi que borrar la ficha
                // lo dejaria huerfano con su nombre y su telefono.
                $ficha->delete();

                if ($acudienteId !== null
                    && ! DatosEstudiante::where('acudiente_id', $acudienteId)->exists()) {
                    Acudiente::whereKey($acudienteId)->delete();
                }
            }

            $perfil->encuesta()->delete();
            $perfil->encuestasSatisfaccion()->delete();
            $perfil->areasDirigidas()->sync([]);

            // La fila del taller se queda —cuenta en la asistencia de ese
            // taller— pero sin nada de la persona. Esta tabla guarda sus
            // PROPIOS datos, copiados al inscribirse, y la clave hacia el perfil
            // no se los lleva ni al borrar la cuenta (es nullOnDelete).
            InstitucionActual::tabla('inscritos_actividad')->where('perfil_id', $perfil->id)->update([
                'nombre_completo' => self::NOMBRE,
                'documento' => null,
                'telefono' => null,
                'correo' => null,
                'fecha_nacimiento' => null,
                'edad' => null,
            ]);

            $perfil->nombre_completo = self::NOMBRE;
            $perfil->fecha_nacimiento = null;
            $perfil->telefono = null;
            // El documento del personal (05/10/2026); el del estudiante se va con su ficha.
            $perfil->documento_identidad = null;
            $perfil->foto_perfil = '';
            $perfil->codigo_qr = null;
            $perfil->suprimido_en = now();
            $perfil->save();

            // La cuenta se queda porque el perfil cuelga de ella (CASCADE), pero
            // sin nada que la identifique y sin forma de entrar: el usuario era
            // muchas veces el correo o el nombre de la persona.
            $user = $perfil->user;
            $user->username = 'suprimido-'.$perfil->id;
            $user->email = null;
            $user->password = Hash::make(Str::random(64));
            $user->activo = false;
            // Testigo nuevo: una galleta de «recordarme» ya emitida trae el
            // viejo y deja de valer.
            $user->remember_token = Str::random(60);
            $user->save();

            DB::table('sessions')->where('user_id', $user->id)->delete();
            InstitucionActual::tabla('restablecimientos_clave')->where('user_id', $user->id)->delete();

            return $retiradas;
        });

        Storage::disk('local')->delete($archivos);

        Auditoria::registrar('perfil.suprimido', [
            'perfil_id' => $perfil->id,
            'matriculas_retiradas' => $retiradas,
        ], $quien);

        return $retiradas;
    }
}
