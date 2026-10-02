<?php

namespace App\Http\Controllers;

use App\Models\Actividad;
use App\Models\Grupo;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Periodo;
use App\Models\Promotoria;
use App\Support\Csv;
use App\Support\InformeActividades;
use App\Support\InformeInstitucion;
use App\Support\Permisos;
use Generator;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Los informes descargables.
 *
 * Son dos y tienen alcances MUY distintos, asi que van con puertas distintas:
 *
 * - El de estudiantes por grupo es operativo: la lista que se lleva quien dicta
 *   para pasar asistencia en papel o para llamar a una familia. Lo baja el
 *   personal, y un profesor solo el de las promotorias que dicta.
 * - El de la institucion es el completo, con la encuesta demografica NOMINAL.
 *   Solo el administrador. Ver el aviso largo en `institucion()`.
 */
class InformeController extends Controller
{
    /**
     * Cuantas filas se traen por tanda.
     *
     * Es constante y no un numero suelto porque hay una prueba que necesita
     * superarla: el fallo que se arreglo aqui —tandas que se solapan y repiten
     * filas— solo aparece cuando el informe pasa de una tanda, y con cuatro
     * filas de prueba no se veia.
     */
    private const POR_TANDA = 100;

    /**
     * Estudiantes por promotoria y grupo, con su contacto.
     *
     * Sigue la misma matriz de visibilidad que la ficha: edad, telefono y
     * acudiente son para direccion, y para el profesor SOLO de sus promotorias.
     * Por eso el filtro no es un adorno del listado sino la propia puerta — un
     * profesor que pida el informe entero recibe unicamente lo suyo.
     *
     * Se acota al periodo en curso: la lista que se pide aqui es la de quien
     * esta yendo a clase ahora, no el historico. El historico de una persona
     * vive en su ficha.
     *
     * Se puede pedir entero, de una promotoria o de UN GRUPO. Lo de un grupo es
     * lo que de verdad se lleva impreso al salon: la lista de a quien le toca
     * ese horario. Con el informe entero encima, quien dicta tendria que buscar
     * sus veinte filas entre trescientas.
     */
    public function estudiantes(Request $request): StreamedResponse
    {
        /** @var Perfil $perfil */
        $perfil = $request->attributes->get('perfil');
        $periodo = Periodo::enCurso();
        $grupo = $this->grupoPedido($request, $perfil);
        $promotoria = $this->promotoriaPedida($request, $perfil, $grupo);

        $consulta = Matricula::query()
            ->whereIn('estado', Matricula::ESTADOS_INSCRITO)
            ->when($periodo, fn ($q) => $q->where('periodo_id', $periodo->id))
            // Sin periodo en curso no hay lista que dar: se devuelve vacio en
            // vez de barrer el historico entero.
            ->when($periodo === null, fn ($q) => $q->whereRaw('1 = 0'))
            // EL RECORTE DE QUIEN PIDE EL INFORME, y desde el 12/09/2026
            // alcanza tambien al DIRECTOR: solo saca la informacion de los
            // departamentos que administra. Antes solo se acotaba al profesor y
            // direccion bajaba la casa entera.
            //
            // Una sola subconsulta para los dos casos, por `queVe()`, que es
            // donde vive la regla. Escrito como dos `when()` distintos se
            // separarian, y aqui separarse significa que un director se baja en
            // un CSV los datos —telefono, acudiente, documento— de estudiantes
            // de un departamento que no es suyo.
            ->when(
                $perfil->rol !== 'administrador',
                fn ($q) => $q->whereIn(
                    'promotoria_id',
                    Promotoria::queVe($perfil)->select('promotorias.id')
                )
            )
            ->when($promotoria, fn ($q) => $q->where('promotoria_id', $promotoria->id))
            // Por la puente: quien esta en un grupo vive en `asignaciones_grupo`
            // desde el 10/09/2026. `whereHas` y no un join, para que este filtro
            // no duplique filas — el informe se recorre con `lazy()` y puede ser
            // de cientos, y una matricula en dos grupos saldria dos veces.
            ->when($grupo, fn ($q) => $q->whereHas('grupos', fn ($g) => $g->where('grupos.id', $grupo->id)))
            ->with([
                'estudiante.datosEstudiante.acudiente',
                'promotoria.area',
                // Con las sesiones: el horario se deriva de ellas, y sin
                // traerlas aqui el informe pregunta una vez por fila. Este
                // informe se recorre con `lazy()` y puede ser de cientos.
                'grupos.sesiones',
            ])
            ->join('promotorias', 'promotorias.id', '=', 'matriculas.promotoria_id')
            ->join('areas', 'areas.id', '=', 'promotorias.area_id')
            ->join('perfiles', 'perfiles.id', '=', 'matriculas.estudiante_id')
            ->orderBy('areas.nombre')
            ->orderBy('promotorias.nombre')
            ->orderBy('perfiles.nombre_completo')
            // Desempate por id, como sus gemelos de Cancelaciones y Usuarios.
            // `lazy()` pagina con LIMIT/OFFSET, y dos personas con el MISMO
            // nombre en la misma promotoria quedan en un orden que el motor no
            // esta obligado a repetir de una tanda a la siguiente: si el empate
            // cae en el borde, una sale dos veces y la otra ninguna. Se vio el
            // 01/10/2026 al pasar a PostgreSQL con dos «Mariangel» en Danza
            // Folclorica: la de 7 anos faltaba en la lista de su grupo. MariaDB
            // acertaba por suerte con esos datos.
            ->orderBy('matriculas.id')
            ->select('matriculas.*');

        return Csv::descargar($this->nombreDelArchivo($promotoria, $grupo), [
            'Departamento',
            'Promotoría',
            'Grupo',
            'Nivel',
            'Horario',
            'Salón',
            'Estudiante',
            'Edad',
            'Teléfono',
            'Acudiente',
            'Teléfono del acudiente',
            'Estado',
        ], $this->filasDeEstudiantes($consulta));
    }

    /**
     * El grupo que se pide, si se pide, y solo si esta persona puede verlo.
     *
     * Un 404 y no una lista vacia: pedir el grupo de una promotoria ajena tiene
     * que decir que no, no devolver un archivo con la cabecera y ninguna fila.
     * Lo segundo se lee como «ese grupo esta vacio», que es una respuesta falsa
     * a una pregunta que no correspondia hacer.
     *
     * La regla es la misma de siempre: direccion cualquiera, quien dicta solo
     * las suyas.
     */
    private function grupoPedido(Request $request, Perfil $perfil): ?Grupo
    {
        $id = $request->query('grupo');

        if (! $id) {
            return null;
        }

        $grupo = Grupo::with('promotoria')->find($id);

        abort_if($grupo === null, 404);
        abort_unless(Permisos::puedeGestionarPromotoria($perfil, $grupo->promotoria), 404);

        return $grupo;
    }

    /**
     * La promotoria que se pide, con la misma puerta.
     *
     * Si ya vino un grupo, manda el suyo: pedir a la vez el grupo A y la
     * promotoria B es una peticion incoherente, y quedarse con la promotoria del
     * grupo es la unica lectura que no devuelve algo vacio sin explicacion.
     */
    private function promotoriaPedida(Request $request, Perfil $perfil, ?Grupo $grupo): ?Promotoria
    {
        if ($grupo !== null) {
            return $grupo->promotoria;
        }

        $id = $request->query('promotoria');

        if (! $id) {
            return null;
        }

        $promotoria = Promotoria::find($id);

        abort_if($promotoria === null, 404);
        abort_unless(Permisos::puedeGestionarPromotoria($perfil, $promotoria), 404);

        return $promotoria;
    }

    /**
     * Como se llama el archivo.
     *
     * Lleva el nombre de la promotoria y del grupo porque quien dicta se baja
     * tres listas seguidas, una por horario, y con el mismo nombre las tres el
     * navegador las guarda como «(1)» y «(2)»: para saber cual es cual hay que
     * abrirlas. Con el nombre dentro se distinguen en la carpeta de descargas.
     */
    private function nombreDelArchivo(?Promotoria $promotoria, ?Grupo $grupo): string
    {
        // Sin acotar sigue siendo «por grupo», que es como esta organizado.
        if ($promotoria === null) {
            return 'estudiantes-por-grupo';
        }

        $partes = ['estudiantes', $promotoria->nombre];

        if ($grupo !== null) {
            $partes[] = $grupo->nombre;
        }

        return Str::slug(implode(' ', $partes)) ?: 'estudiantes-por-grupo';
    }

    /**
     * @return Generator<int, list<string>>
     */
    private function filasDeEstudiantes($consulta): Generator
    {
        // Por tandas y no de una: el informe puede ser de cientos de filas con
        // cuatro relaciones cargadas cada una, y en hosting compartido traerlas
        // todas a memoria a la vez es justo lo que no conviene.
        //
        // `lazy` y NO `lazyById`. Este informe va ordenado por departamento,
        // promotoria y nombre, y `lazyById` pagina preguntando por el id mayor
        // que el ultimo devuelto: con otro orden, «el ultimo» es un id
        // cualquiera, las tandas se solapan y las filas se repiten. Costo 4961
        // filas para 302 matriculas, y no se veia en las pruebas porque con
        // pocos datos todo cabe en la primera tanda.
        foreach ($consulta->lazy(self::POR_TANDA) as $matricula) {
            $datos = $matricula->estudiante->datosEstudiante;
            $acudiente = $datos?->acudiente;

            /*
             * UNA FILA POR GRUPO, no por matricula, desde el 10/09/2026.
             *
             * Lo decide para que es este informe, y esta escrito arriba: «la
             * lista que se lleva quien dicta para pasar asistencia en papel».
             * Quien va al Grupo A el lunes y al B el miercoles tiene que salir
             * en las DOS listas, o el profesor del miercoles va a clase con un
             * papel al que le falta gente. Es el mismo criterio que
             * `Clase::matriculasAPasar()`.
             *
             * Y la matricula SIN grupo sigue dando su fila —el `?: [null]`—
             * porque «Sin grupo» es informacion que direccion viene a buscar
             * aqui: es la lista de quien falta por repartir.
             */
            $grupos = $matricula->grupos->all() ?: [null];

            foreach ($grupos as $grupo) {
                yield array_map(Csv::celda(...), [
                    $matricula->promotoria->area->nombre,
                    $matricula->promotoria->nombre,
                    $grupo?->nombre ?? 'Sin grupo',
                    $grupo?->nivel_display,
                    $grupo?->horario,
                    $grupo?->salon,
                    $matricula->estudiante->nombre_completo,
                    $matricula->estudiante->edad,
                    $matricula->estudiante->telefono,
                    $acudiente?->nombre,
                    $acudiente?->telefono,
                    Matricula::ESTADOS[$matricula->estado] ?? $matricula->estado,
                ]);
            }
        }
    }

    /**
     * El informe completo de la institucion. SOLO ADMINISTRADOR.
     *
     * Lleva la encuesta demografica con NOMBRE Y APELLIDO, y eso invierte a
     * proposito lo que el sistema garantizaba hasta ahora: la encuesta se recogia
     * agregada y anonima, y `EstadisticasController` explica por que —una
     * encuesta con nombre en un tablero se convierte en un marcador, y la vez
     * siguiente la gente contesta pensando en quien va a leerla.
     *
     * Se hace porque la institucion lo necesita para reportar a quien la
     * financia, y se acota todo lo que se puede: la descarga es del
     * administrador y de nadie mas —la misma puerta que la copia del documento
     * de identidad—, y la pantalla avisa de lo que contiene antes de entregarlo.
     * Lo que el sistema NO puede controlar es que despues el archivo se reenvie,
     * y por eso la decision tenia que ser explicita y de quien manda, no de quien
     * programa.
     *
     * Una fila por persona y promotoria: quien cursa dos sale en dos filas, con
     * sus datos repetidos. Es la forma correcta para una hoja de calculo —permite
     * tablas dinamicas— y la unica que puede llevar el nivel y el tiempo, que son
     * datos DE la promotoria y no de la persona. Quien no tiene ninguna sale en
     * una sola fila con esas columnas vacias.
     */
    public function institucion(): StreamedResponse
    {
        // Las filas las arma `InformeInstitucion` desde el paso 5: la descarga
        // consolidada del panel repite este mismo informe en cada institucion,
        // y escrito dos veces se separaria sin que nada fallara. Alli estan
        // tambien la lista de papeles (consultada UNA vez para cabecera y
        // filas) y el porque de cada columna.
        $informe = new InformeInstitucion;

        return Csv::descargar('institucion-completo', $informe->cabecera(), $informe->filas());
    }

    /**
     * La gente SIN matricula: cursos, talleres, grupos de proyeccion y
     * programas externos (25/09/2026). Una fila por persona y actividad, con
     * TODO el historico.
     *
     * APARTE DE LOS OTROS DOS, por decision del usuario: hay hojas de calculo
     * armadas sobre aquellos, y meter aqui estas filas las llenaria de columnas
     * vacias que sus tablas dinamicas empezarian a contar.
     *
     * SOLO EL ADMINISTRADOR (la ruta lo dice): lleva nombres de menores, y los
     * de un programa externo son de OTRA institucion. Se ofrecio que cada
     * responsable bajara lo suyo y se eligio lo cerrado.
     *
     * LA EDAD NO SE CALCULA. Van la fecha de nacimiento (quien entro por el
     * enlace), la edad declarada (programa externo) y el dia del registro, cada
     * una en su columna: la edad escrita a mano ENVEJECE, y solo junto a
     * «Registrado el» se puede corregir. Deducir una de otra aqui seria
     * inventar un dato que nadie dio.
     *
     * El porcentaje sigue la regla del certificado (`AsistenciaDeActividad`):
     * sobre las sesiones CON LISTA TOMADA de la actividad, y truncado. Quien
     * entro a mitad de camino arrastra las sesiones de antes; es lo mismo que
     * pasa con el certificado y por la misma razon.
     *
     * Consultas fijas: dos agregados y la lista recorrida con `lazy()`.
     */
    public function actividades(): StreamedResponse
    {
        // Las filas las arma `InformeActividades` desde el paso 5, por lo mismo
        // que el de la institucion: tambien lo repite la descarga del panel.
        return Csv::descargar('actividades-sin-matricula', InformeActividades::cabecera(), InformeActividades::filas());
    }
}
