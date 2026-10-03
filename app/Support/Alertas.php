<?php

namespace App\Support;

use App\Models\Actividad;
use App\Models\Asistencia;
use App\Models\ConfiguracionInstitucion;
use App\Models\Grupo;
use App\Models\InstitucionExterna;
use App\Models\Matricula;
use App\Models\OmisionArchivada;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Models\SesionGrupo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * LAS DOS ALERTAS DE LA BANDEJA, calculadas y no guardadas.
 *
 * Ninguna se almacena. Las dos salen de cruzar lo que DEBERIA haber pasado con
 * lo que hay registrado, cada vez que se abre la pantalla:
 *
 * - CLASE NO DICTADA: el grupo tiene el martes en su horario, el martes ya paso
 *   y no hay ninguna fila en `clases` de ese grupo con esa fecha. Quien dicta
 *   tiene todo el dia para oprimir «Iniciar clase» y pasar lista; el aviso sale
 *   cuando el dia termino y no lo hizo.
 *
 * - POSIBLE ABANDONO: un estudiante con matricula activa que acumula N faltas
 *   SEGUIDAS y sin excusa en las ultimas clases de su grupo. Se aviso; no se
 *   retira a nadie. Retirar libera el cupo y la ranura, que es una consecuencia
 *   sobre OTRA persona —la que espera ese cupo—, y en este sistema ninguna
 *   matricula cambia de estado sin que alguien lo decida.
 *
 * Guardarlas seria guardar algo que puede quedar viejo: el profesor registra la
 * clase tarde, el estudiante vuelve. Calculadas dicen siempre la verdad de
 * ahora, y ademas no hay que decidir quien las crea ni quien las limpia.
 *
 * EL COSTE NO CRECE CON LOS DATOS, que es la regla del proyecto y aqui no es
 * teorica: esto recorre el periodo entero. Son CUATRO consultas fijas —el
 * horario de todos los grupos, las clases del periodo, las omisiones archivadas
 * y las faltas— y todo el cruce se hace en memoria sobre esas filas. Ni una
 * consulta por grupo ni una por estudiante.
 */
class Alertas
{
    /**
     * Desde cuando cuentan las alertas.
     *
     * Un periodo academico INCLUYE su periodo de matriculas: semanas de
     * inscribir gente y armar grupos antes de que nadie de una clase. Contar
     * desde `fecha_inicio` llenaba la bandeja de dias en los que nadie tenia que
     * dar clase todavia — 596 avisos el primer dia en produccion, correctos e
     * inutiles. Asi que lo decide quien administra, en Configuracion.
     *
     * Nula significa el inicio del periodo, que es como se comportaba antes. Y
     * se toma siempre la MAS TARDIA de las dos: una fecha anterior al periodo no
     * puede hacer que se miren dias que ese periodo no tiene.
     *
     * PUBLICA desde el 04/09/2026 porque la portada de Gestion la ENSENA: cuando
     * no hay ninguna alerta, esta fecha es lo unico que explica el cero. Sin
     * ella, un cero recien configurado y un cero porque no ha pasado nada se
     * leen igual, y el primero es una pantalla que no esta mirando.
     */
    public static function desde(Periodo $periodo): Carbon
    {
        $inicio = Carbon::parse($periodo->fecha_inicio)->startOfDay();
        $configurada = ConfiguracionInstitucion::actual()->alertas_desde;

        if ($configurada === null) {
            return $inicio;
        }

        $configurada = Carbon::parse($configurada)->startOfDay();

        return $configurada->gt($inicio) ? $configurada : $inicio;
    }

    /**
     * Los dias del periodo en los que un grupo tenia clase y no la hubo.
     *
     * `$conArchivadas` (27/09/2026) las devuelve TAMBIEN, marcadas. Archivar
     * saca un aviso de la BANDEJA —«ya lo hable con quien dicta»—, pero la
     * clase no se dio igual, y las estadisticas del profesor tienen que
     * contarla: en produccion, Percusion salia con 2 clases perdidas y le
     * faltaron 18, porque 16 estaban archivadas. La bandeja sigue sin ellas.
     *
     * Desde el 03/10/2026 cada una trae tambien su `causa` (nula si no se ha
     * clasificado o es de las archivadas de antes), si ya se `repuso` y si es
     * una falta que paso su plazo sin reponerse (`vencida`).
     *
     * @return Collection<int, array{grupo: Grupo, fecha: Carbon, dia: string, archivada: bool, causa: ?string, repuesta: bool, vencida: bool}>
     */
    public static function clasesNoDictadas(Periodo $periodo, ?Perfil $quienMira = null, bool $conArchivadas = false): Collection
    {
        // El horario de todos los grupos con matriculas en este periodo, de una
        // vez. Un grupo sin sesiones no tiene dia asignado y no puede faltar a
        // nada, asi que el join lo deja fuera solo.
        $sesiones = InstitucionActual::tabla('sesiones_grupo')
            ->join('grupos', 'grupos.id', '=', 'sesiones_grupo.grupo_id')
            ->select('sesiones_grupo.grupo_id', 'sesiones_grupo.dia')
            ->distinct()
            ->get()
            ->groupBy('grupo_id')
            ->map(fn ($filas) => $filas->pluck('dia')->all());

        if ($sesiones->isEmpty()) {
            return collect();
        }

        // Lo que SI se registro, y lo que ya se archivo. Dos consultas y no una
        // por fecha: lo que se busca es la AUSENCIA de una fila, y eso no se
        // pregunta, se deduce de tener la lista entera delante.
        $dictadas = InstitucionActual::tabla('clases')
            ->where('periodo_id', $periodo->id)
            // Una REPOSICION no es la clase de su dia (03/10/2026): si quien
            // dicta repone el martes pasado un martes en que no dio la suya,
            // contarla taparia la falta de hoy con la clase de la semana
            // pasada. Sigue en la misma consulta, con una subconsulta.
            ->whereNotIn('id', InstitucionActual::tabla('omisiones_archivadas')
                ->whereNotNull('repuesta_en_id')
                ->select('repuesta_en_id'))
            ->selectRaw('grupo_id, DATE(fecha_hora) as fecha')
            ->distinct()
            ->get()
            ->map(fn ($f) => $f->grupo_id.'|'.$f->fecha)
            ->flip();

        $archivadas = InstitucionActual::tabla('omisiones_archivadas')
            ->select('id', 'grupo_id', 'fecha', 'causa', 'clasificada_en', 'repuesta_en_id')
            ->get()
            ->keyBy(fn ($f) => $f->grupo_id.'|'.Carbon::parse($f->fecha)->toDateString());

        // ACOTADO A QUIEN MIRA desde el 12/09/2026: un director solo ve las
        // alertas de los departamentos que administra. El recorte va AQUI, en
        // el mapa de grupos, y no al final sobre la lista: el bucle de abajo ya
        // salta los grupos que no estan en el mapa, asi que la CIFRA que se
        // enseña sale bien sola. Filtrando despues habria que acordarse de
        // recontar, y esa es la clase de olvido que deja un contador mintiendo.
        $grupos = Grupo::with('promotoria.area', 'promotoria.profesor')
            ->whereIn('id', $sesiones->keys())
            ->when($quienMira !== null, function ($q) use ($quienMira) {
                // La anotacion no sobra: dentro de `whereHas` el constructor
                // llega sin tipo y el analizador no sabe que `queVe()` existe.
                $q->whereHas('promotoria', function ($sub) use ($quienMira) {
                    /** @var Builder<Promotoria> $sub */
                    $sub->queVe($quienMira);
                });
            })
            ->get()
            ->keyBy('id');

        $desde = self::desde($periodo);
        $diasParaReponer = ConfiguracionInstitucion::actual()->dias_para_reponer;
        // Hasta AYER: hoy no ha terminado, y quien dicta tiene todo el dia.
        $hasta = Carbon::today()->subDay();
        $fin = Carbon::parse($periodo->fecha_fin)->startOfDay();

        if ($fin->lt($hasta)) {
            $hasta = $fin;
        }

        $faltantes = collect();

        if ($desde->gt($hasta)) {
            return $faltantes;
        }

        foreach ($sesiones as $grupoId => $dias) {
            $grupo = $grupos->get($grupoId);

            if ($grupo === null) {
                continue;
            }

            $fecha = $desde->copy();

            while ($fecha->lte($hasta)) {
                if (in_array($fecha->dayOfWeekIso, $dias, true)) {
                    $clave = $grupoId.'|'.$fecha->toDateString();

                    $omision = $archivadas->get($clave);
                    $archivada = $omision !== null;

                    if (! $dictadas->has($clave) && ($conArchivadas || ! $archivada)) {
                        $faltantes->push([
                            'grupo' => $grupo,
                            'fecha' => $fecha->copy(),
                            'dia' => SesionGrupo::DIAS[$fecha->dayOfWeekIso] ?? '',
                            'archivada' => $archivada,
                            'causa' => $omision?->causa,
                            'repuesta' => $omision?->repuesta_en_id !== null,
                            'vencida' => $omision !== null && OmisionArchivada::vencio(
                                $omision->causa,
                                $omision->repuesta_en_id !== null,
                                $omision->clasificada_en ? Carbon::parse($omision->clasificada_en) : null,
                                $diasParaReponer,
                            ),
                        ]);
                    }
                }

                $fecha->addDay();
            }
        }

        // La mas reciente arriba: es la que todavia se puede recuperar hablando
        // con quien dicta. Entre las del mismo dia —un dia sin clases en toda
        // la casa las iguala a todas—, por promotoria y grupo: la portada
        // recorta a tres y sin desempate cada motor ensenaba otras (ver
        // `OrdenPorNombre`).
        return $faltantes->sort(fn (array $a, array $b) => $b['fecha']->timestamp <=> $a['fecha']->timestamp
            ?: OrdenPorNombre::comparar((string) $a['grupo']->promotoria?->nombre, (string) $b['grupo']->promotoria?->nombre)
            ?: OrdenPorNombre::comparar($a['grupo']->nombre, $b['grupo']->nombre)
        )->values();
    }

    /**
     * Las SEMANAS (lunes a domingo) en que un programa externo no tuvo ninguna
     * clase (03/10/2026, pedido del usuario).
     *
     * Un programa externo no tiene horario: la clase existe cuando el profesor
     * la inicia alla. Asi que lo unico que se puede deducir es una semana entera
     * sin ninguna clase iniciada. Cuentan las semanas COMPLETAS entre las fechas
     * de la institucion (`clases_desde` / `clases_hasta`) y desde que existe el
     * programa; sin fecha de inicio la institucion no avisa. La ultima que se
     * mira es la que termino el domingo pasado: la de hoy todavia no acaba.
     *
     * NO lleva periodo ni recorte por persona: la ve solo administracion
     * (decision del usuario), igual que el QR y el informe de estos programas.
     *
     * Tres consultas fijas —los programas, sus clases iniciadas y las semanas
     * ya atendidas— y el cruce en memoria.
     *
     * @return Collection<int, array{actividad: Actividad, semana: Carbon, atendida: bool, causa: ?string}>
     */
    public static function semanasSinClaseExterna(bool $conAtendidas = false): Collection
    {
        $programas = Actividad::externos()
            ->whereHas('institucion', fn ($q) => $q->whereNotNull('clases_desde'))
            ->with(['institucion', 'responsable'])
            ->get();

        if ($programas->isEmpty()) {
            return collect();
        }

        $conClase = InstitucionActual::tabla('sesiones_actividad')
            ->whereIn('actividad_id', $programas->modelKeys())
            ->whereNotNull('iniciada_en')
            ->select('actividad_id', 'fecha')
            ->get()
            ->map(fn ($s) => $s->actividad_id.'|'.Carbon::parse($s->fecha)->startOfWeek()->toDateString())
            ->flip();

        $atendidas = InstitucionActual::tabla('omisiones_externas')
            ->whereIn('actividad_id', $programas->modelKeys())
            ->select('actividad_id', 'semana', 'causa')
            ->get()
            ->keyBy(fn ($o) => $o->actividad_id.'|'.Carbon::parse($o->semana)->toDateString());

        // El lunes de la ultima semana que ya termino.
        $ultima = Carbon::today()->startOfWeek()->subWeek();
        $semanas = collect();

        foreach ($programas as $programa) {
            /** @var InstitucionExterna $institucion */
            $institucion = $programa->institucion;

            // La MAS TARDIA entre el inicio de las clases alla y el dia en que
            // se creo el programa: antes de existir no podia faltar a nada.
            $inicio = $institucion->clases_desde->copy()->startOfDay();
            $creado = Carbon::parse($programa->created_at)->startOfDay();
            if ($creado->gt($inicio)) {
                $inicio = $creado;
            }

            // La primera semana COMPLETA: un programa que empieza un viernes no
            // ha faltado a la semana de ese viernes.
            $lunes = $inicio->copy()->startOfWeek();
            if ($lunes->lt($inicio)) {
                $lunes->addWeek();
            }

            while ($lunes->lte($ultima)) {
                // Y la ultima, tambien completa: la semana entera antes del fin.
                if ($institucion->clases_hasta !== null && $lunes->copy()->addDays(6)->gt($institucion->clases_hasta)) {
                    break;
                }

                $clave = $programa->id.'|'.$lunes->toDateString();
                $atendida = $atendidas->get($clave);

                if (! $conClase->has($clave) && ($conAtendidas || $atendida === null)) {
                    $semanas->push([
                        'actividad' => $programa,
                        'semana' => $lunes->copy(),
                        'atendida' => $atendida !== null,
                        'causa' => $atendida?->causa,
                    ]);
                }

                $lunes->addWeek();
            }
        }

        return $semanas->sortByDesc(fn ($s) => $s['semana']->timestamp)->values();
    }

    /**
     * Matriculas activas con demasiadas faltas seguidas.
     *
     * @return Collection<int, array{matricula: Matricula, faltas: int, desde: Carbon}>
     */
    public static function posiblesAbandonos(Periodo $periodo, ?Perfil $quienMira = null): Collection
    {
        $umbral = ConfiguracionInstitucion::actual()->faltas_para_abandono;

        // Todas las marcas del periodo, con la fecha de su clase, en una sola
        // consulta. Se ordena por fecha DESCENDENTE porque la racha que importa
        // es la del final: se cuenta hacia atras desde la ultima clase y se para
        // en cuanto aparece algo que no sea una falta.
        $marcas = InstitucionActual::tabla('asistencias')
            ->join('clases', 'clases.id', '=', 'asistencias.clase_id')
            ->where('clases.periodo_id', $periodo->id)
            // La misma frontera que la otra alerta: lo anterior al arranque de
            // las clases no cuenta. Sin esto, mover la fecha limpiaria una
            // bandeja y no la otra.
            ->whereDate('clases.fecha_hora', '>=', self::desde($periodo))
            ->select('asistencias.matricula_id', 'asistencias.estado', 'clases.fecha_hora')
            ->orderByDesc('clases.fecha_hora')
            ->get()
            ->groupBy('matricula_id');

        $rachas = [];

        foreach ($marcas as $matriculaId => $suyas) {
            $seguidas = 0;
            $desde = null;

            foreach ($suyas as $marca) {
                // La EXCUSA rompe la racha, y es la decision de producto que
                // hace util a esta alerta: quien avisa de que no puede ir es lo
                // contrario de quien desaparece sin decir nada.
                if ($marca->estado !== Asistencia::FALTO) {
                    break;
                }

                $seguidas++;
                $desde = $marca->fecha_hora;
            }

            if ($seguidas >= $umbral) {
                $rachas[$matriculaId] = ['faltas' => $seguidas, 'desde' => $desde];
            }
        }

        if ($rachas === []) {
            return collect();
        }

        // Solo las ACTIVAS: una retirada ya no abandona nada, y una con la
        // cancelacion pedida ya esta en la bandeja de al lado.
        /** @var Collection<int, array{matricula: Matricula, faltas: int, desde: Carbon}> $casos */
        // ACOTADO A QUIEN MIRA, igual que la alerta de al lado: un director no
        // ve los abandonos de un departamento que no administra. Va en la
        // consulta y no sobre la coleccion, para que la cifra no dependa de un
        // segundo recuento.
        $casos = Matricula::query()
            ->whereIn('id', array_keys($rachas))
            ->where('estado', Matricula::ACTIVA)
            ->when($quienMira !== null, function ($q) use ($quienMira) {
                $q->whereHas('promotoria', function ($sub) use ($quienMira) {
                    /** @var Builder<Promotoria> $sub */
                    $sub->queVe($quienMira);
                });
            })
            ->with(['estudiante.datosEstudiante.acudiente', 'promotoria.area', 'grupos'])
            ->get()
            ->map(fn (Matricula $m) => [
                'matricula' => $m,
                'faltas' => $rachas[$m->id]['faltas'],
                'desde' => Carbon::parse($rachas[$m->id]['desde']),
            ])
            // Mas faltas arriba y, entre iguales, por nombre (ver
            // `OrdenPorNombre`).
            ->sort(fn (array $a, array $b) => $b['faltas'] <=> $a['faltas']
                ?: OrdenPorNombre::comparar(
                    (string) $a['matricula']->estudiante?->nombre_completo,
                    (string) $b['matricula']->estudiante?->nombre_completo
                ))
            ->values();

        return $casos;
    }
}
