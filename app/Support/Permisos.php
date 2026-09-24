<?php

namespace App\Support;

use App\Models\Actividad;
use App\Models\Matricula;
use App\Models\Perfil;
use App\Models\Promotoria;

/**
 * Quien puede hacer que sobre una promotoria o una actividad.
 *
 * El middleware `rol:` solo cierra la puerta de la pantalla. Esto es la capa de
 * dentro: dos personas con el mismo rol entran al mismo Panel pero no pueden
 * tocar las mismas promotorias.
 *
 * La pareja de reglas se repite en los dos lados y conviene leerla junta:
 * `puedeGestionarPromotoria`/`puedeVerActividad` son las anchas —direccion
 * entra por el rol— y `dictaLaPromotoria`/`dirigeLaActividad` las estrechas,
 * que es lo que hace falta para escribir asistencia.
 *
 * Puerto de los ayudantes sueltos de `matriculas/views.py`. Aqui son una clase
 * porque tambien los necesitan las plantillas, y repetir la regla de roles en
 * Blade es como acaban separandose el enlace y la vista que protege: se pintan
 * botones que al pulsarlos rebotan.
 */
class Permisos
{
    /**
     * Las areas que esta persona puede ver, o `null` si puede ver TODAS.
     *
     * ─── LA REGLA DEL 12/09/2026, Y ESTA ES SU UNICA CASA ──────────────────
     *
     * Lo pidio el usuario: «el director solo deberia ver las areas a las que se
     * le asigne desde la administracion; si es director de musica solo veria las
     * promotorias de musica». Hasta ese dia el rol `director` era un
     * administrador con menos pantallas — en todas partes iba dentro del mismo
     * `in_array` que el administrador.
     *
     * `null` Y NO UN ARRAY CON TODAS LAS AREAS, y la diferencia importa: con un
     * array habria que consultar la tabla de areas en cada peticion de un
     * administrador para acabar sin filtrar nada, y una pantalla que olvidara
     * comprobarlo filtraria por una lista que se queda vieja en cuanto se crea
     * un area. `null` significa «no hay recorte» y se lee en el `when()` de
     * cada consulta sin coste.
     *
     * SIN AREAS ASIGNADAS UN DIRECTOR NO VE NADA —array vacio, no `null`—, y es
     * lo correcto aunque parezca duro: el recorte cierra en FALSO. Si se
     * devolviera `null` por no tener ninguna, el primer director que se cree sin
     * asignarle nada lo veria todo, que es exactamente lo que este cambio viene
     * a impedir. La migracion le dio todas las areas a los que ya estaban para
     * que el despliegue no le cambie el dia a nadie.
     *
     * @return list<int>|null
     */
    public static function areasVisiblesPara(Perfil $perfil): ?array
    {
        if ($perfil->rol !== 'director') {
            // El administrador lo ve todo. El profesor y el estudiante no se
            // acotan por AREA sino por su vinculo —la promotoria que dicta, su
            // matricula—, y esos recortes ya estaban y siguen donde estaban.
            return null;
        }

        return $perfil->areasDirigidas->pluck('id')->all();
    }

    /**
     * ¿Cae esta promotoria dentro de lo que esta persona dirige?
     *
     * Es la pregunta que acota las LISTAS. Para decidir si ademas puede tocarla
     * esta `puedeGestionarPromotoria()`, que la usa.
     */
    public static function veLaPromotoria(Perfil $perfil, Promotoria $promotoria): bool
    {
        $areas = self::areasVisiblesPara($perfil);

        return $areas === null || in_array($promotoria->area_id, $areas, true);
    }

    /**
     * ¿Puede administrar el catalogo de esta promotoria?
     *
     * Crear grupos, fijar el cupo, confirmar o rechazar matriculas. Direccion
     * sobre cualquiera; el profesor solo sobre las que dicta.
     *
     * Lo que se mira en el caso del profesor es el VINCULO
     * (`Promotoria::profesor`), no solo el rol. Un director que ademas dicta su
     * propia promotoria es un caso real, y por eso el rol de direccion abre la
     * puerta por su cuenta.
     */
    public static function puedeGestionarPromotoria(Perfil $perfil, Promotoria $promotoria): bool
    {
        // EL DIRECTOR YA NO ENTRA POR EL ROL SOLO: desde el 12/09/2026 tiene
        // que dirigir el area de esta promotoria. `veLaPromotoria()` devuelve
        // true para el administrador sin consultar nada.
        return ($perfil->rol === 'administrador')
            || ($perfil->rol === 'director' && self::veLaPromotoria($perfil, $promotoria))
            || ($perfil->rol === 'profesor' && $promotoria->profesor_id === $perfil->id);
    }

    /**
     * ¿Es esta persona quien DICTA la promotoria?
     *
     * Regla mas estrecha que la anterior, y la diferencia esta donde tiene que
     * estar: gestionar el catalogo —crear grupos, fijar cupos, confirmar
     * matriculas— es tarea de direccion, pero registrar una clase y pasar lista
     * son actos de quien estuvo en el salon.
     *
     * Direccion sigue VIENDO toda la asistencia; lo que no hace es escribirla en
     * promotorias ajenas. Un registro que puede reescribir alguien que no dio la
     * clase deja de ser evidencia de lo que paso, y es justamente la evidencia
     * lo que la confirmacion de los estudiantes esta sosteniendo.
     *
     * Lo que se mira es el VINCULO, no el rol. Un director que ademas dicta su
     * propia promotoria es un caso real, y exigiendole el rol "profesor" quedaba
     * en el peor sitio posible: veia su propio grupo en solo lectura, sin poder
     * registrar su asistencia y sin que nadie pudiera hacerlo por el.
     *
     * Contrapartida asumida: quien edita el catalogo puede asignarse a si mismo
     * una promotoria y con eso escribir su asistencia. No es un agujero
     * silencioso —el panel ensena quien es el profesor de cada promotoria— y la
     * clase la siguen verificando los estudiantes, que es donde vive la garantia
     * de verdad.
     *
     * Consecuencia asumida: una promotoria SIN nadie asignado no puede registrar
     * clases hasta que se le asigne alguien. Es correcto —sin profesor no hay
     * quien de la clase— y el mensaje de error lo dice.
     */
    public static function dictaLaPromotoria(Perfil $perfil, Promotoria $promotoria): bool
    {
        // EN GESTION ASISTIDA NO, y va aqui a proposito: esta es la UNICA
        // puerta por la que se escribe una lista de asistencia, asi que un
        // solo corte alcanza a las cuatro pantallas —iniciar clase, pasar
        // lista, corregirla y el boton del Panel— sin que haya que acordarse de
        // ninguna. Puesto en cada controlador, la que se olvide no falla: deja
        // pasar.
        //
        // El porque es lo de arriba, leido al reves: si un administrador puede
        // escribir la lista desde la cuenta del profesor, el registro deja de
        // significar «esto lo escribio quien dio la clase» y con eso deja de
        // ser la evidencia que la confirmacion de los estudiantes sostiene.
        // Decidido por el usuario el 04/09/2026 sabiendo el coste: si el
        // profesor no puede entrar el dia de su clase, nadie la registra por el.
        //
        // VER y CORREGIR lo que ya hay no pasa por aqui, asi que sigue
        // disponible: lo que se cierra es escribir.
        if (GestionAsistida::activa()) {
            return false;
        }

        return $promotoria->profesor_id !== null && $promotoria->profesor_id === $perfil->id;
    }

    /**
     * ¿Puede VER esta actividad en el Panel?
     *
     * Direccion ve todas; el responsable, la suya. Es la misma forma que
     * `puedeGestionarPromotoria()`, y por la misma razon: un director tiene que
     * poder mirar que se esta dando en su casa sin ser el que lo da.
     */
    public static function puedeVerActividad(Perfil $perfil, Actividad $actividad): bool
    {
        // EL DIRECTOR YA NO VE TODAS. Desde el 12/09/2026 solo las que dirige,
        // y una actividad no cuelga de un area —vive en su propia tabla y lo
        // que tiene es una PERSONA responsable—, asi que «asignarle» una a un
        // director es ponerlo de responsable. Decision del usuario ese dia, con
        // la alternativa delante: darle area a la actividad seria mas coherente
        // de cara a quien mira, pero contradice la decision escrita de que una
        // actividad no cuelga de un departamento.
        return $perfil->rol === 'administrador'
            || $actividad->responsable_id === $perfil->id;
    }

    /**
     * ¿Es esta persona quien DIRIGE la actividad?
     *
     * La regla estrecha, y la diferencia esta donde tiene que estar: la misma
     * que separa `puedeGestionarPromotoria()` de `dictaLaPromotoria()`. Ver una
     * actividad es cosa de direccion; iniciar una sesion y pasar lista son
     * actos de quien estuvo en el salon, y un registro que puede escribir quien
     * no estuvo deja de ser evidencia de lo que paso.
     *
     * Aqui pesa mas que en las promotorias, no menos: alli la clase la
     * confirman despues los propios estudiantes, y esa es la garantia de
     * verdad. Los inscritos de un taller no tienen cuenta con la que confirmar
     * nada, asi que la unica firma que queda es la de quien oprimio el boton.
     *
     * A diferencia de una promotoria, una actividad SIEMPRE tiene responsable:
     * el formulario lo exige. Asi que esto nunca deja una actividad sin nadie
     * que pueda pasarle lista. Y si el responsable falta, direccion tiene la
     * misma salida que en las promotorias: cambiarlo desde Gestion.
     */
    public static function dirigeLaActividad(Perfil $perfil, Actividad $actividad): bool
    {
        return $actividad->responsable_id === $perfil->id;
    }

    /**
     * ¿Es esta cuenta la institucion que recibe este programa externo?
     *
     * La tercera relacion con una actividad, y NO se parece a las otras dos: no
     * mira el rol de direccion ni quien la dirige, sino a quien se le esta
     * dictando. Quien pasa por aqui puede firmar una clase y no puede tocar
     * nada mas — ni iniciarla, ni pasar lista, ni ver a nadie.
     *
     * LAS TRES CONDICIONES IMPORTAN y ninguna sobra:
     *
     * - EL ROL, porque el resto de la casa no da fe de nada aqui; sin el, el
     *   administrador —que es `institucion_id` de nadie pero pasa por todas
     *   partes— acabaria firmando el trabajo de sus propios profesores.
     * - EL TIPO, porque `institucion_id` es NULL en los otros tres y en SQL
     *   dos NULL no son iguales pero en PHP `null === null` SI: sin esta
     *   linea, un funcionario cuya ficha se borrara mal pasaria a poder firmar
     *   cualquier taller de la casa.
     * - LA FICHA, que es la unica que dice cual es SU institucion.
     */
    public static function verificaLaActividad(Perfil $perfil, Actividad $actividad): bool
    {
        if ($perfil->rol !== Perfil::INSTITUCION_EXTERNA || ! $actividad->esExterno()) {
            return false;
        }

        $suya = $perfil->institucionExterna;

        return $suya !== null && $suya->id === $actividad->institucion_id;
    }

    /**
     * ¿Puede descargar el certificado de ESTA matricula?
     *
     * El propio estudiante siempre; direccion sobre cualquiera; quien dicta la
     * promotoria sobre las suyas. El profesor entra por el VINCULO y no por el
     * rol, igual que en el resto del proyecto.
     *
     * Que el personal pueda bajarlo no es comodidad: media matricula de una casa
     * de la cultura son menores y adultos mayores que piden la constancia en
     * ventanilla y no van a entrar al sistema a buscarla.
     */
    public static function puedeCertificarMatricula(?Perfil $solicitante, Matricula $matricula): bool
    {
        if ($solicitante === null) {
            return false;
        }

        if ($solicitante->id === $matricula->estudiante_id) {
            return true;
        }

        if (in_array($solicitante->rol, ['administrador', 'director'], true)) {
            return true;
        }

        return $solicitante->rol === 'profesor'
            && $matricula->promotoria !== null
            && self::dictaLaPromotoria($solicitante, $matricula->promotoria);
    }

    /**
     * ¿Puede descargar el certificado que reune TODAS las matriculas vigentes de
     * un estudiante?
     *
     * Regla mas estrecha que la anterior a proposito, y la diferencia es el
     * profesor: el certificado reunido lista todas las promotorias que esa
     * persona cursa, y la ficha le esconde deliberadamente las que no dicta
     * —lo mismo hace el panel de asistencia—. Dejarselo bajar entregaria en un
     * PDF justo lo que la matriz de visibilidad le niega en pantalla.
     *
     * Le queda el certificado de la matricula suya, que es el que le pueden
     * pedir a el.
     */
    public static function puedeCertificarTodo(?Perfil $solicitante, Perfil $estudiante): bool
    {
        if ($solicitante === null) {
            return false;
        }

        return $solicitante->id === $estudiante->id
            || in_array($solicitante->rol, ['administrador', 'director'], true);
    }

    /**
     * Que roles puede REPARTIR esta persona.
     *
     * El administrador reparte cualquiera, incluido el suyo. El director reparte
     * todo MENOS administrador, y ahi esta el fondo del asunto: las rutas de
     * usuarios estan abiertas a los dos, pero el enrutado reserva al
     * administrador tres pantallas —la configuracion de la institucion, las
     * estadisticas con la encuesta demografica y la descarga de copias de
     * documentos de identidad—. Si un director pudiera repartir el rol de
     * administrador, se lo daria a si mismo y esas tres puertas dejarian de
     * significar nada: la restriccion se saltaria sola, sin forzar nada.
     *
     * @return list<string>
     */
    public static function rolesAsignablesPor(Perfil $solicitante): array
    {
        // LA LISTA SALE DE `ROLES_REPARTIBLES` Y NO DE `ROLES`, y la diferencia
        // es `institucion_externa`: NO LO REPARTE NADIE, ni siquiera el
        // administrador. No es una restriccion de confianza sino de forma —esa
        // cuenta solo significa algo colgada de una ficha de institucion, y este
        // formulario no sabe crear una—. Se crean, se editan y se desactivan
        // desde «Programas formativos», junto a la institucion de la que son.
        $todos = Perfil::ROLES_REPARTIBLES;

        if ($solicitante->rol === 'administrador') {
            return $todos;
        }

        return array_values(array_filter($todos, fn (string $rol) => $rol !== 'administrador'));
    }

    /**
     * ¿Puede tocar la cuenta de esta persona?
     *
     * La otra mitad de lo anterior, y hace falta las dos. Limitar solo los roles
     * que se reparten deja abierto el camino corto: un director no se asciende,
     * pero le cambia la contrasena al administrador y entra como el. Editar la
     * cuenta de alguien es poder suplantarlo, asi que la cuenta de un
     * administrador solo la toca otro administrador.
     *
     * Cubre las tres formas de tocarla —editar, cambiar el rol y activar o
     * desactivar—, porque las tres pasan por el mismo formulario o por el mismo
     * listado y dejar una fuera es dejarla entera fuera.
     *
     * Nadie queda encerrado: un administrador siempre puede editar a otro, y a
     * si mismo.
     */
    public static function puedeEditarUsuario(Perfil $solicitante, Perfil $objetivo): bool
    {
        // LA CUENTA DE UNA INSTITUCION EXTERNA NO SE TOCA DESDE AQUI, ni el
        // administrador, y el motivo no es de permisos sino de que ESTE
        // formulario le hace dano. Su rol no esta entre los repartibles (ver
        // `rolesAsignablesPor`), asi que el desplegable de rol se pinta SIN el
        // suyo y ninguna opcion sale marcada: guardar la ficha sin bajar ese
        // desplegable le cambia el rol a otra cosa —y con el se va la unica
        // cuenta que podia dar fe de las clases de esa escuela—, sin fallar y
        // sin avisar. Es exactamente el fallo que el propio formulario ya
        // documenta para las cuentas sin rol.
        //
        // Se edita, se le cambia la clave y se desactiva desde su institucion,
        // en «Programas formativos». Esta linea cierra de una vez los CINCO
        // caminos del controlador de usuarios —editar, actualizar, activar,
        // confirmar el borrado y borrar—, porque los cinco pasan por
        // `exigirAccesoA()`, que es quien la lee.
        if ($objetivo->rol === Perfil::INSTITUCION_EXTERNA) {
            return false;
        }

        if ($solicitante->rol === 'administrador') {
            return true;
        }

        return $objetivo->rol !== 'administrador';
    }

    /**
     * ¿Puede abrir la ficha de otra persona? Se mira hacia abajo, no hacia los
     * lados.
     *
     * Administrador y director abren la de cualquiera. El profesor solo la de
     * estudiantes — ni la de otro profesor, ni la de un director. Un estudiante
     * no abre ninguna: sus pantallas ensenan los nombres como texto.
     */
    public static function puedeVerFicha(?Perfil $solicitante, ?Perfil $objetivo): bool
    {
        if ($solicitante === null || $objetivo === null) {
            return false;
        }

        if (in_array($solicitante->rol, ['administrador', 'director'], true)) {
            return true;
        }

        if ($solicitante->rol === 'profesor') {
            return $objetivo->rol === 'estudiante';
        }

        return false;
    }
}
