<?php

namespace App\Support;

use App\Models\Actividad;
use App\Models\CupoPromotoria;
use App\Models\Grupo;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use Illuminate\Support\Facades\DB;

/**
 * Las cifras de «como va la escuela», en un solo sitio.
 *
 * POR QUE EXISTE: desde el 04/09/2026 las pinta la portada de Gestion —que es
 * donde aterriza el administrador— y las sigue pintando Estadisticas. Dos
 * pantallas con la misma cifra calculada en dos sitios es una cifra que acaba
 * diciendo dos cosas distintas, y de eso este proyecto ya tiene historia: en
 * agosto habia cuatro cuentas distintas de las pruebas repartidas por el
 * repositorio y ninguna cuadraba.
 *
 * ESTUDIANTES ACTIVOS SE ACOTA AL PERIODO, y esa es la unica de las cinco que
 * tiene truco. Una matricula NO se retira al cerrar un periodo —el dato no
 * cambio, cambio el calendario, y de ahi cuelgan la renovacion, los
 * certificados y la antiguedad—, asi que contar activas sin filtrar responde
 * «cuantos han cursado alguna vez» y no «cuantos hay ahora». Medido en la base
 * de desarrollo: 251 contra 231. La etiqueta dice «activos», asi que manda el
 * periodo. Se aparta del Django, que cuenta igual de mal.
 *
 * Las otras cuatro son totales del catalogo y no dependen del periodo: cuantas
 * promotorias, cuantos grupos, cuanto personal que ensena y cuantas actividades
 * hay montadas.
 */
class ResumenInstitucion
{
    /**
     * @return array{
     *     estudiantesActivos: int,
     *     profesores: int,
     *     promotorias: int,
     *     grupos: int,
     *     cursosYTalleres: int,
     *     proyeccion: int,
     *     programasExternos: int,
     *     poblacionImpactada: int,
     *     cuposDisponibles: int,
     *     promotoriasSinTope: int,
     * }
     */
    public static function cifras(?Periodo $periodo): array
    {
        $cupos = self::cupos($periodo);

        return [
            'estudiantesActivos' => $periodo === null ? 0 : Matricula::query()
                ->where('estado', Matricula::ACTIVA)
                ->where('periodo_id', $periodo->id)
                // DISTINCT sobre el estudiante: quien cursa dos promotorias es
                // una persona, no dos.
                ->distinct()
                ->count('estudiante_id'),
            'profesores' => Perfil::where('rol', 'profesor')->count(),
            'promotorias' => Promotoria::count(),
            'grupos' => Grupo::count(),
            'cursosYTalleres' => Actividad::whereIn('tipo', Actividad::TIPOS_CON_FECHAS)->count(),
            'proyeccion' => Actividad::where('tipo', Actividad::PROYECCION)->count(),
            'programasExternos' => Actividad::where('tipo', Actividad::EXTERNO)->count(),
            'poblacionImpactada' => self::poblacionImpactada($periodo),
            'cuposDisponibles' => $cupos['disponibles'],
            'promotoriasSinTope' => $cupos['sinTope'],
        ];
    }

    /**
     * A cuantas PERSONAS llego la casa en el periodo: estudiantes activos mas
     * la gente de cursos, talleres, proyeccion y programas externos, sin
     * contar dos veces a nadie que se pueda reconocer.
     *
     * ─── POR QUE AQUI SI SE SUMA (usuario, 25/09/2026) ─────────────────────
     *
     * Esa misma manana se decidio que las dos poblaciones no se mezclan, y
     * sigue valiendo para todo lo que es una MEDIA o un reparto: una media que
     * junte a un estudiante de semestre con quien fue a un taller de un dia no
     * dice nada de ninguno. Esta cifra es otra pregunta —ALCANCE, «a cuantas
     * personas llegamos»— y el usuario la pidio con ese nombre y con la
     * objecion delante. Solo esta cifra suma; nada mas en la pantalla.
     *
     * ─── COMO SE EVITA CONTAR DOS VECES ────────────────────────────────────
     *
     * 1. Quien esta matriculado y ademas va a una actividad cuenta UNA vez: se
     *    reconoce por `perfil_id`, que se rellena cuando el documento coincide
     *    con el de un estudiante.
     * 2. Quien va a dos actividades con el mismo documento cuenta una vez.
     * 3. Quien NO dio documento —toda la lista de un programa externo y quien
     *    se anade en plena clase— cuenta una vez POR FILA: no hay con que
     *    reconocerlo. Si esa persona va a dos programas, cuenta dos. Es el
     *    limite del dato, no un descuido, y es la unica forma en que la cifra
     *    puede pasarse; nunca se queda corta.
     *
     * El `perfil_id IS NULL OR NOT IN` no es redundante: un NOT IN contra una
     * fila con NULL da NULL y no TRUE, y sin la primera mitad desaparecerian
     * de la cuenta justo los que no tienen cuenta, que son casi todos.
     *
     * Las actividades del periodo salen de `ResumenActividades`, la misma
     * regla que la seccion de Estadisticas: escrita dos veces, las dos cifras
     * de la pantalla contarian actividades distintas.
     */
    private static function poblacionImpactada(?Periodo $periodo): int
    {
        if ($periodo === null) {
            return 0;
        }

        $activos = Matricula::query()
            ->where('estado', Matricula::ACTIVA)
            ->where('periodo_id', $periodo->id)
            ->select('estudiante_id');

        $estudiantes = (clone $activos)->distinct()->count('estudiante_id');
        $actividades = array_keys(ResumenActividades::actividadesDelPeriodo($periodo));

        if ($actividades === []) {
            return $estudiantes;
        }

        $fila = DB::table('inscritos_actividad')
            ->whereIn('actividad_id', $actividades)
            ->where(fn ($q) => $q
                ->whereNull('perfil_id')
                ->orWhereNotIn('perfil_id', $activos))
            ->selectRaw('COUNT(DISTINCT documento) as con_documento, SUM(documento IS NULL) as sin_documento')
            ->first();

        return $estudiantes + (int) ($fila->con_documento ?? 0) + (int) ($fila->sin_documento ?? 0);
    }

    /**
     * Cuantos sitios quedan libres en toda la institucion, y cuantas
     * promotorias NO entran en esa cuenta.
     *
     * ─── LO SEGUNDO NO ES UN EXTRA ─────────────────────────────────────────
     *
     * Una promotoria SIN fila en `cupos_promotoria` no tiene tope: admite a
     * quien llegue. O sea que no aporta un numero a esta suma, y decir
     * «1.196 cupos disponibles» a secas seria una cifra CORRECTA con una
     * etiqueta que miente por omision — que es exactamente el fallo que ya tuvo
     * esta misma cinta con «1 · Cursos y talleres». Por eso salen las dos y la
     * pantalla avisa cuando la segunda no es cero. En produccion el 07/09/2026:
     * 25 promotorias, 24 con tope y UNA sin el.
     *
     * ─── SE SUMA SOLO LO POSITIVO ──────────────────────────────────────────
     *
     * Una promotoria puede acabar pasada de cupo —se baja el tope despues de
     * matricular, y el trigger no retira a nadie—. Ese exceso NO puede restar
     * de las demas: un sitio de menos en Violin no llena uno de Danza, y
     * sumando en crudo la cifra diria que hay menos libres de los que se pueden
     * ocupar de verdad. Hoy no hay ninguna asi; el dia que la haya, esta linea
     * es la diferencia entre una cifra util y una que nadie sabe leer.
     *
     * ─── DOS CONSULTAS FIJAS ───────────────────────────────────────────────
     *
     * Los topes por un lado y los ocupados agrupados por el otro, cruzados en
     * memoria. `Promotoria::cuposDisponibles()` consulta por promotoria, asi que
     * llamarla en un bucle costaria dos consultas por fila.
     *
     * Las condiciones de lo OCUPADO son las mismas que en
     * `Promotoria::ocupadosEn()` —todo lo que no esta retirado, incluida una
     * cancelacion en tramite— y tienen que seguir siendolo: dos definiciones de
     * «ocupa un cupo» acaban dando dos cifras.
     *
     * @return array{disponibles: int, sinTope: int}
     */
    private static function cupos(?Periodo $periodo): array
    {
        if ($periodo === null) {
            return ['disponibles' => 0, 'sinTope' => Promotoria::count()];
        }

        $topes = CupoPromotoria::where('periodo_id', $periodo->id)
            ->pluck('cupo_maximo', 'promotoria_id');

        $ocupados = Matricula::query()
            ->where('periodo_id', $periodo->id)
            ->where('estado', '!=', Matricula::RETIRADA)
            ->groupBy('promotoria_id')
            ->selectRaw('promotoria_id, COUNT(*) as total')
            ->pluck('total', 'promotoria_id');

        $disponibles = 0;

        foreach ($topes as $promotoriaId => $tope) {
            $disponibles += max(0, ((int) $tope) - ((int) ($ocupados[$promotoriaId] ?? 0)));
        }

        return [
            'disponibles' => $disponibles,
            'sinTope' => max(0, Promotoria::count() - $topes->count()),
        ];
    }
}
