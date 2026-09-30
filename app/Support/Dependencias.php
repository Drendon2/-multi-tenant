<?php

namespace App\Support;

use App\Models\Actividad;
use App\Models\Area;
use App\Models\Grupo;
use App\Models\InstitucionExterna;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Que impediria borrar algo, y que se iria con ello.
 *
 * Es lo que permite que la pantalla de confirmacion diga la verdad ANTES de que
 * nadie pulse nada, en vez de preguntar «¿seguro?» para negarse despues.
 *
 * En el original esto lo resolvia el `Collector` de Django, que sabe recorrer el
 * grafo de relaciones solo. Aqui las dependencias van declaradas a mano en
 * `MAPA` porque Eloquent no las conoce: quien sabe si una clave foranea borra en
 * cascada o bloquea es el esquema, y esa informacion no sube al modelo.
 *
 * El precio de declararlo a mano es que hay que acordarse de tocar este mapa al
 * agregar una tabla. A cambio, el mapa es tambien la documentacion de que se
 * lleva por delante cada borrado, que es justo lo que la pantalla necesita
 * contar.
 */
class Dependencias
{
    /**
     * Por modelo: que relaciones BLOQUEAN el borrado y cuales se van CON el.
     *
     * `bloquean` son las claves foraneas declaradas RESTRICT; `arrastran`, las
     * CASCADE. Cada entrada es [relacion => [singular, plural]].
     *
     * Con UNA excepcion, la de `Perfil`, que esta explicada en su entrada: ahi
     * un bloqueo no describe al esquema sino que lo corrige. Si se lee esta
     * linea como una regla sin mirar aquella, se saca la conclusion contraria a
     * la verdadera.
     */
    private const MAPA = [
        Area::class => [
            'bloquean' => ['promotorias' => ['promotoría', 'promotorías']],
            'arrastran' => [],
        ],
        Periodo::class => [
            'bloquean' => [
                'matriculas' => ['matrícula', 'matrículas'],
                'clases' => ['clase', 'clases'],
            ],
            'arrastran' => [
                'cuposPromotoria' => ['cupo', 'cupos'],
            ],
        ],
        Promotoria::class => [
            'bloquean' => ['matriculas' => ['matrícula', 'matrículas']],
            'arrastran' => [
                'grupos' => ['grupo', 'grupos'],
                'cupos' => ['cupo', 'cupos'],
            ],
        ],
        Grupo::class => [
            'bloquean' => ['matriculas' => ['matrícula', 'matrículas']],
            'arrastran' => ['clases' => ['clase', 'clases']],
        ],
        /**
         * La cuenta de una persona, y la unica entrada cuyos `bloquean` NO son
         * todos claves foraneas RESTRICT. Hay que leerla con cuidado.
         *
         * `promotoriasDictadas` y `actividadesACargo` si son RESTRICT: la base
         * rechaza el borrado por su cuenta, y contarlas aqui solo sirve para
         * decirlo antes de preguntar.
         *
         * `matriculas` es la excepcion y va al reves: en el esquema es CASCADE,
         * asi que la base borra el historial academico de la persona SIN UNA
         * QUEJA —comprobado: un estudiante con una matricula se lleva la
         * matricula al borrar la cuenta—. Se declara como bloqueo porque es la
         * decision del proyecto: una cuenta con historial no se BORRA.
         *
         * Desde el 30/09/2026 tampoco se queda sin salida: si el titular pide
         * que se borren sus datos (Ley 1581), `UsuarioController` pregunta con
         * `salvo: ['matriculas']` y, si solo quedan matriculas, ANONIMIZA en
         * vez de borrar (`SupresionDeDatos`). La fila se queda y el historial
         * con ella; lo que se va es todo lo que dice quien era.
         *
         * Consecuencia que conviene tener presente: aqui el mapa no describe al
         * esquema, lo CORRIGE. Lo unico que separa un clic de perder anos de
         * historial es esta linea y la comprobacion que la lee, no la base. Si
         * algun dia se abre otro camino para borrar un perfil, ese camino tiene
         * que volver a pasar por aqui.
         */
        Perfil::class => [
            'bloquean' => [
                'matriculas' => ['matrícula', 'matrículas'],
                'promotoriasDictadas' => ['promotoría a su cargo', 'promotorías a su cargo'],
                'actividadesACargo' => ['actividad a su cargo', 'actividades a su cargo'],
                // RESTRICT de verdad, como las dos de arriba: borrar la cuenta
                // dejaria a esa escuela sin nadie que pueda dar fe de sus
                // clases. Se cuenta aqui solo para poder decirlo antes de
                // preguntar, en vez de negarse despues con un error del motor.
                'institucionExterna' => ['institución externa', 'instituciones externas'],
            ],
            'arrastran' => [
                'datosEstudiante' => ['ficha de estudiante', 'fichas de estudiante'],
                'encuesta' => ['encuesta demográfica', 'encuestas demográficas'],
                'encuestasSatisfaccion' => ['encuesta de satisfacción', 'encuestas de satisfacción'],
            ],
        ],
        // Una actividad no la bloquea nada: sus sesiones y sus inscritos son
        // suyos y de nadie mas, y no son historial academico de nadie —a un
        // taller se entra por un enlace, no con una matricula—. Se van con
        // ella, y la pantalla lo dice antes de preguntar.
        Actividad::class => [
            'bloquean' => [],
            'arrastran' => [
                'sesiones' => ['sesión', 'sesiones'],
                'inscritos' => ['inscrito', 'inscritos'],
            ],
        ],
        /*
         * La institucion externa. Sus programas la BLOQUEAN, al reves que las
         * sesiones de una actividad, y la diferencia no es de esquema sino de
         * lo que se pierde: con la ficha se va la cuenta que firmo las clases,
         * y esas firmas son la evidencia de que un profesor de esta casa fue a
         * dictar a una vereda. No se borra una institucion con historial; se
         * desactiva su cuenta, que es la misma politica de siempre.
         *
         * Su CUENTA no se arrastra: `perfil_id` apunta hacia `perfiles`, no al
         * reves, asi que borrar la ficha deja el perfil en pie. Es lo que hay
         * que saber al escribir el borrado — quien quiera irse del todo tiene
         * que llevarse las dos cosas, en ese orden.
         */
        InstitucionExterna::class => [
            'bloquean' => ['programas' => ['programa externo', 'programas externos']],
            'arrastran' => [],
        ],
    ];

    /**
     * `$salvo` deja fuera relaciones que bloquean en el mapa pero que quien
     * pregunta sabe atender por otro camino: las matriculas de una persona, que
     * desde el 30/09/2026 no impiden la supresion sino que la convierten en
     * anonimizacion (ver `SupresionDeDatos`).
     *
     * @param  list<string>  $salvo
     * @return array{bloqueos: string, arrastre: string} frases ya armadas, vacias si no hay nada
     */
    public static function de(Model $objeto, array $salvo = []): array
    {
        $config = self::MAPA[$objeto::class] ?? ['bloquean' => [], 'arrastran' => []];
        $config['bloquean'] = array_diff_key($config['bloquean'], array_flip($salvo));

        return [
            'bloqueos' => self::enumerar(self::contar($objeto, $config['bloquean'])),
            'arrastre' => self::enumerar(self::contar($objeto, $config['arrastran'])),
        ];
    }

    /**
     * Las relaciones que bloquean el borrado de este modelo, por su nombre.
     *
     * Existe para que un listado pueda pedirlas con `withCount()` en la MISMA
     * consulta que ya hace, en vez de preguntarlas fila a fila. Sale de aqui y
     * no escrita a mano en el controlador para que no se separe del mapa: una
     * lista que se olvide de una relacion pinta como borrable algo que luego se
     * niega, que es justo el viaje perdido que todo esto evita.
     *
     * @return list<string>
     */
    public static function nombresDeBloqueos(string $clase): array
    {
        return array_keys(self::MAPA[$clase]['bloquean'] ?? []);
    }

    /**
     * ¿Hay algo que impida borrarlo? La version barata, para pintar una lista.
     *
     * Usa el conteo que ya venga cargado —el que deja `withCount()`— y solo
     * pregunta a la base cuando no lo hay. Sin esa preferencia, pintar cincuenta
     * usuarios costaria ciento cincuenta consultas.
     */
    /** @param  list<string>  $salvo  como en `de()` */
    public static function estaBloqueado(Model $objeto, array $salvo = []): bool
    {
        foreach (array_diff(self::nombresDeBloqueos($objeto::class), $salvo) as $relacion) {
            $yaContado = $objeto->getAttribute(Str::snake($relacion).'_count');
            $cuantos = $yaContado ?? $objeto->{$relacion}()->count();

            if ($cuantos > 0) {
                return true;
            }
        }

        return false;
    }

    /** El aviso que se da cuando el borrado se rechaza de verdad. */
    public static function avisoDeProtegido(Model $objeto): string
    {
        $config = self::MAPA[$objeto::class] ?? ['bloquean' => []];
        $piezas = self::enumerar(self::contar($objeto, $config['bloquean']));

        // "todavía tiene …" en vez de "… depende de él": el sujeto puede ser una
        // promotoria, un area o un periodo, y asi el aviso no tiene que
        // concordar en genero con nada.
        return $piezas === ''
            ? "No se puede eliminar «{$objeto}»: todavía hay registros que dependen de él."
            : "No se puede eliminar «{$objeto}»: todavía tiene {$piezas}.";
    }

    /**
     * @param  array<string, array{0: string, 1: string}>  $relaciones
     * @return list<string> ["1 grupo", "19 matrículas"]
     */
    private static function contar(Model $objeto, array $relaciones): array
    {
        $piezas = [];

        foreach ($relaciones as $relacion => [$singular, $plural]) {
            $cuantos = $objeto->{$relacion}()->count();

            if ($cuantos > 0) {
                $piezas[] = $cuantos.' '.($cuantos === 1 ? $singular : $plural);
            }
        }

        return $piezas;
    }

    /** ["3 grupos", "41 matrículas"] -> "3 grupos y 41 matrículas". */
    private static function enumerar(array $piezas): string
    {
        if (count($piezas) <= 1) {
            return implode('', $piezas);
        }

        $ultima = array_pop($piezas);

        return implode(', ', $piezas).' y '.$ultima;
    }
}
