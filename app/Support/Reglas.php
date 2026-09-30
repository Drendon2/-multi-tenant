<?php

namespace App\Support;

use App\Models\ConfiguracionInstitucion;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;

/**
 * Las reglas de formato de todo lo que teclea una persona.
 *
 * Existe porque estas mismas reglas hacen falta en SIETE formularios repartidos
 * por seis controladores —inscripcion publica, registro, formulario de usuario,
 * mi perfil, inscripcion a una actividad, institucion— y escritas a mano en
 * cada sitio se separan sin que nada falle: basta con que alguien anada un
 * camino nuevo y copie la version vieja. Aqui la regla es un sitio y los
 * llamantes son referencias.
 *
 * Lo que estas reglas NO son es la defensa contra la inyeccion. Esa ya existe y
 * esta en otro sitio: Blade escapa todo lo que imprime, las consultas van
 * parametrizadas, y `Csv::celda()` neutraliza las formulas de Excel. Lo que
 * hace este archivo es cerrar la puerta ANTES, para que lo que no tiene forma
 * de dato no llegue siquiera a guardarse. Las dos capas hacen falta: la de
 * salida protege de lo que ya esta guardado, esta protege de lo que entra.
 */
class Reglas
{
    /**
     * Celular colombiano: diez digitos y NADA mas.
     *
     * Ni espacios, ni guiones, ni el prefijo +57, ni parentesis. Es la decision
     * del usuario del 07/09/2026 y es deliberadamente inflexible: un telefono
     * guardado como «300 111 2233» y otro como «3001112233» son la misma
     * persona para cualquiera que mire y dos filas distintas para cualquier
     * busqueda, y este sistema no tiene forma de avisar a nadie de nada — el
     * telefono ES el canal de contacto.
     *
     * NO se exige que empiece por 3. Un celular colombiano si empieza por 3,
     * pero desde 2022 un fijo tambien tiene diez digitos (60 + indicativo + 7),
     * y rechazarlo dejaria fuera a quien solo tiene fijo en casa.
     */
    public const CELULAR = '/^[0-9]{10}$/';

    /**
     * Documento de identidad: solo numeros, de 6 a 12 digitos.
     *
     * Cubre cedula (8-10), tarjeta de identidad y NUIP (10-11) y cedula de
     * extranjeria. Decidido asi el 07/09/2026 sabiendo el coste: un pasaporte
     * extranjero es alfanumerico y con esta regla no se puede inscribir. Si
     * algun dia aparece ese caso, la regla es esta constante y no una decena de
     * controladores.
     */
    public const DOCUMENTO = '/^[0-9]{6,12}$/';

    /**
     * Correo: arroba obligatoria, dominio con punto y extension de letras.
     *
     * Mas estricta que el `email` de Laravel a proposito. La regla `email:rfc`
     * da por bueno `pepe@localhost` y `a@b`, que no son correos a los que se
     * pueda escribir; aqui el correo se guarda justamente para poder escribir.
     *
     * Lo que esta lista blanca deja fuera y es legal segun el RFC: tildes en la
     * parte de antes de la arroba, dominios internacionalizados y las partes
     * locales entrecomilladas. Ninguna de las tres la usa nadie en la practica
     * y casi ningun proveedor las acepta.
     */
    public const CORREO = '/^[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}$/';

    /**
     * El telefono de la ENTIDAD, que es otra cosa y por eso tiene otra regla.
     *
     * No es el celular de una persona: es el contacto que se imprime en la
     * pagina publica de tratamiento de datos y en el consentimiento. Una casa
     * de la cultura pone ahi un fijo con extension, o dos numeros. Exigirle
     * diez digitos dejaria esa pantalla imposible de guardar.
     *
     * Sigue siendo una lista blanca cerrada: empieza por digito, parentesis o
     * el mas del prefijo de pais, admite digitos, espacios, parentesis, mas,
     * punto y guion, y opcionalmente una extension al final. Entre 7 y 15
     * digitos en total, que es lo que la mira de delante comprueba. Ni una
     * letra fuera de «ext».
     */
    public const TELEFONO_ENTIDAD = '/^(?=(?:\D*\d){7,15}\D*$)[0-9+(][0-9 ()+.\-]*(?: ?(?:ext|Ext|EXT)\.? ?[0-9]{1,6})?$/';

    /**
     * Texto libre: todo menos lo que solo sirve para colar codigo.
     *
     * Un nombre no se puede encerrar en una lista blanca de letras sin dejar
     * fuera algo real —y ademas seria falsa tranquilidad, porque el peligro de
     * un nombre no es su alfabeto—. Lo que se corta son los signos que no
     * aparecen en ningun dato de verdad de este sistema y si en toda carga:
     * los angulos que abren una etiqueta, y los caracteres de control.
     *
     * Se dejan pasar el tabulador, el salto de linea y el retorno, porque el
     * texto de la politica de datos es de varias lineas.
     */
    public const SIN_MARCAS = '/^[^<>\x00-\x08\x0B\x0C\x0E-\x1F\x7F]*$/u';

    /**
     * El nombre de una PERSONA: letras y la puntuacion que llevan los nombres.
     *
     * Esto INVIERTE lo que se decidio el 07/09 por la manana —que los nombres
     * no llevaran lista blanca— y lo invierte el usuario, que se encontro
     * documentos de identidad tecleados en la casilla del nombre. Se midio
     * antes de ponerla: de 885 nombres de perfil en produccion solo 3 quedan
     * fuera, y los tres estan mal escritos («1022142147», uno con parentesis y
     * otro con comas). De 666 acudientes, uno: «Adrian0», con un cero por una o
     * — que es justo el error que esta regla existe para cazar.
     *
     * EL PUNTO SI ENTRA, y no estaba en la lista que se pidio. Se comprobo
     * antes: en produccion hay «Elide del C. Puerta Rodriguez», que es una
     * inicial de segundo nombre y una forma normal de escribir un nombre.
     * Dejarlo fuera habria roto una ficha correcta por una regla escrita de
     * memoria.
     *
     * El apostrofo y el guion NO aparecen hoy en ninguna fila, y se admiten
     * igual: D'Angelo y Pérez-Reverte son apellidos reales, y el dia que llegue
     * uno nadie va a estar mirando esta constante.
     *
     * Tiene que EMPEZAR por letra. Eso descarta de paso lo que abre una formula
     * de Excel —`=`, `+`, `-`, `@`— aunque de eso ya se encargue `Csv::celda()`.
     *
     * NO se usa en los nombres de CATALOGO. Un grupo se llama «Nivel 2» y un
     * periodo «2026-1»: ahi el numero es el dato.
     */
    public const NOMBRE = '/^[\p{L}\p{M}][\p{L}\p{M} .\'\-]*$/u';

    /**
     * @return list<string>
     */
    public static function nombreDePersona(int $max = 90, bool $obligatorio = true): array
    {
        return [$obligatorio ? 'required' : 'nullable', 'string', 'max:'.$max, 'regex:'.self::NOMBRE];
    }

    /**
     * Nombre de usuario: lo mismo que cualquier texto, y NO una lista blanca.
     *
     * Esto se escribio primero como `/^[A-Za-z0-9._-]+$/` —sin espacios, sin
     * arroba— y se midio contra produccion antes de darlo por bueno: **435 de
     * las 841 cuentas no cumplian**. No porque fueran raras, sino porque es lo
     * que la gente hace: 179 usan su correo de nombre de usuario y 239 su
     * nombre con espacios («Ainhoa Davila»), y 17 llevan tilde o ñ.
     *
     * Con aquella regla puesta, un administrador no podia guardar la ficha de
     * ninguna de esas 435 personas: el `username` viaja en el mismo formulario
     * que el rol y el telefono, asi que cambiarle el rol a alguien exigia
     * cambiarle antes el nombre de usuario con el que entra.
     *
     * Y no ganaba nada. Ni el espacio ni la arroba son una via de inyeccion; lo
     * son los angulos, y esos los corta `SIN_MARCAS` igual. **Antes de estrechar
     * un campo, cuenta cuantas filas de produccion se quedan fuera.**
     */
    public static function usuario(mixed $unico = null, int $max = 150): array
    {
        $reglas = ['required', 'string', 'max:'.$max, 'regex:'.self::SIN_MARCAS];

        return $unico === null ? $reglas : [...$reglas, $unico];
    }

    /**
     * @return list<string>
     */
    public static function celular(bool $obligatorio = true): array
    {
        return [$obligatorio ? 'required' : 'nullable', 'string', 'regex:'.self::CELULAR];
    }

    /**
     * @return list<string>
     */
    public static function documento(bool $obligatorio = true): array
    {
        return [$obligatorio ? 'required' : 'nullable', 'string', 'regex:'.self::DOCUMENTO];
    }

    /**
     * El telefono del ACUDIENTE: obligatorio en cuanto hay un acudiente.
     *
     * `required_with` y no `required` a secas porque el acudiente entero es
     * opcional para un mayor de edad. Pero en cuanto alguien escribe un nombre
     * ahi, el telefono deja de ser opcional: un acudiente sin numero no cumple
     * la funcion por la que se registra —que la institucion pueda LLAMARLO— y
     * lo unico que hace es ocupar sitio en la ficha.
     *
     * Lo pidio el usuario el 07/09/2026 despues de encontrarse 14 en
     * produccion, y ninguno de los 14 estaba suelto: los 14 cuelgan de un
     * estudiante. La regla de los MENORES es otra y vive aparte —a ellos se les
     * exige acudiente, no solo su telefono— en `DatosEstudiante::validar()` y
     * en `InscripcionController::comprobarAcudienteDeMenor()`.
     *
     * @return list<string>
     */
    public static function celularDeAcudiente(string $campoDelNombre = 'acudiente_nombre'): array
    {
        return ['required_with:'.$campoDelNombre, 'nullable', 'string', 'regex:'.self::CELULAR];
    }

    /**
     * El correo, obligatorio o no segun lo que haya decidido la ENTIDAD.
     *
     * Lee la configuracion aqui dentro y no en cada llamante a proposito. Son
     * tres formularios los que piden correo, y el que se olvidara de mirar el
     * interruptor lo dejaria opcional para siempre sin que nada fallara: el
     * administrador lo enciende en Institucion, ve que una pantalla lo exige y
     * otra no, y no tiene forma de saber cual es la que esta mal.
     *
     * Nace APAGADO. En produccion hay 32 correos de 885 personas: encendido de
     * fabrica, la primera vez que alguien abriera la ficha de cualquiera de las
     * otras 853 no podria guardarla.
     *
     * @return list<string>
     */
    public static function correoSegunLaInstitucion(int $max = 255): array
    {
        return self::correo($max, obligatorio: (bool) ConfiguracionInstitucion::actual()->correo_obligatorio);
    }

    /**
     * @return list<string>
     */
    public static function correo(int $max = 255, bool $obligatorio = false): array
    {
        return [
            $obligatorio ? 'required' : 'nullable',
            'string',
            'max:'.$max,
            'email:rfc,filter',
            'regex:'.self::CORREO,
        ];
    }

    /**
     * @return list<string>
     */
    public static function telefonoDeEntidad(int $max = 40): array
    {
        return ['nullable', 'string', 'max:'.$max, 'regex:'.self::TELEFONO_ENTIDAD];
    }

    /**
     * Texto que escribe una persona: nombre, barrio, salon, comentario.
     *
     * El `max` no es cosmetico. Sin el, un campo de texto es una via de escribir
     * megabytes en la base por peticion, y dos de los que habia —el comentario
     * de la encuesta y el texto de la politica— no tenian ninguno.
     *
     * @return list<string>
     */
    public static function texto(int $max, bool $obligatorio = true): array
    {
        return [$obligatorio ? 'required' : 'nullable', 'string', 'max:'.$max, 'regex:'.self::SIN_MARCAS];
    }

    /**
     * Los mensajes de rechazo, que son la mitad del trabajo.
     *
     * El mensaje que trae Laravel para `regex` es «El formato de teléfono no es
     * válido», que no le dice a nadie que hacer. Quien tecleo «300 111 2233»
     * tiene que leer que sobran los espacios, no que el formato es invalido.
     *
     * Van todos los nombres de campo que usa el sistema porque el mismo texto
     * vale para el campo de una persona y para el de su acudiente.
     *
     * @return array<string, string>
     */
    /**
     * `exists` acotado a la institucion actual.
     *
     * Un `Rule::exists('promotorias', 'id')` suelto va a la base sin pasar por
     * los modelos, asi que aceptaria el id de una promotoria de OTRA
     * institucion. El filtro no se escribe aqui: se le pide a
     * `InstitucionActual::filtrar()`, que es el unico sitio donde existe.
     */
    public static function existe(string $tabla, string $columna = 'id'): Exists
    {
        return Rule::exists($tabla, $columna)
            ->where(fn (Builder $consulta) => InstitucionActual::filtrar($consulta, $tabla));
    }

    /**
     * `unique` acotado a la institucion actual: el nombre de un departamento
     * o de un periodo se repite entre instituciones y no dentro de una. Casa
     * con los unicos compuestos del guion `03-unicos-por-institucion.sql`.
     *
     * NO se usa para `users.username`, que sigue siendo unico en toda la base
     * (ver `App\Models\User`).
     */
    public static function unica(string $tabla, string $columna): Unique
    {
        return Rule::unique($tabla, $columna)
            ->where(fn (Builder $consulta) => InstitucionActual::filtrar($consulta, $tabla));
    }

    public static function mensajes(): array
    {
        $celular = 'Escribe el número de celular con 10 dígitos, sin espacios ni guiones. Ejemplo: 3001112233.';
        $documento = 'El documento de identidad son solo números, entre 6 y 12 dígitos, sin puntos ni espacios.';
        $correo = 'Escribe un correo electrónico completo, con arroba y dominio. Ejemplo: nombre@correo.com.';
        $marcas = 'No se pueden usar los signos < y > en este campo.';
        $nombre = 'El nombre solo admite letras, espacios, apóstrofo y guion. Sin números ni otros signos.';

        return [
            'username.regex' => 'No se pueden usar los signos < y > en el nombre de usuario.',
            'telefono.regex' => $celular,
            'acudiente_telefono.regex' => $celular,
            'documento.regex' => $documento,
            'documento_identidad.regex' => $documento,
            'correo.regex' => $correo,
            'correo.email' => $correo,
            'entidad_correo.regex' => $correo,
            'entidad_correo.email' => $correo,
            'entidad_telefono.regex' => 'Escribe un teléfono de contacto: números, y si hace falta espacios, paréntesis o una extensión. Ejemplo: 604 555 1234 ext. 102.',
            'nombre_completo.regex' => $nombre,
            'acudiente_nombre.regex' => $nombre,
            'acudiente_telefono.required_with' => 'Si registras un acudiente, su teléfono es obligatorio: es el número al que llamaría la institución.',
            'correo.required' => 'El correo electrónico es obligatorio.',
            'entidad_correo.required' => 'El correo electrónico es obligatorio.',
            'nombre.regex' => $marcas,
            'nombre_institucion.regex' => $marcas,
            'barrio.regex' => $marcas,
            'salon.regex' => $marcas,
            'descripcion.regex' => $marcas,
            'comentario.regex' => $marcas,
            'politica_datos.regex' => $marcas,
            'entidad_nit.regex' => $marcas,
            'entidad_direccion.regex' => $marcas,
            'finalidad_datos.regex' => $marcas,
            'finalidad_imagen.regex' => $marcas,
            'firmante_nombre.regex' => $marcas,
            'firmante_cargo.regex' => $marcas,
        ];
    }
}
