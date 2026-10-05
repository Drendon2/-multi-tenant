<?php

namespace App\Models;

use App\Models\Concerns\DeLaInstitucion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Perfil comun a TODOS los roles. Uno por cada cuenta, sin importar el rol.
 *
 * Las reglas de VISIBILIDAD por campo (quien ve que) NO van aqui: se aplican en
 * la capa de permisos. Este modelo solo define los DATOS.
 *
 * Recordatorio de visibilidad (se implementa en los controladores, no aqui):
 *     nombre, foto ...... admin, director, profesor, companeros del MISMO GRUPO
 *     telefono,
 *     acudiente ......... admin, director, profesor (profesor solo de SUS promotorias)
 *     edad .............. como el telefono, PERO solo la de un ESTUDIANTE
 *     encuesta .......... solo el dueno de la cuenta y el administrador
 *     copia_documento ... solo el administrador
 *
 * El renglon de la edad es el unico que separa al estudiante del personal, y no
 * es una omision: del `esPersonal()` de mas abajo no se muestra la edad a nadie,
 * ni en su ficha ni en el informe de la institucion. En un estudiante el dato
 * TRABAJA --de el salen la minoria de edad, el acudiente obligatorio y el nivel
 * que le toca-- y en un profesor no lo usa nadie para nada; en Colombia,
 * ademas, exhibir la edad de un adulto se lee como una falta de respeto. La suya
 * la sigue viendo cada quien en Mi perfil, que es donde se queda.
 *
 * La fecha de nacimiento SI se sigue pidiendo en Gestion -> Usuarios: es un
 * campo obligatorio del alta y de ahi sale la cuenta. Lo que se cierra es donde
 * se EXHIBE, no donde se captura.
 *
 * Se aparta del Django a proposito, que en `detalle_usuario.html` la pinta para
 * cualquier rol.
 *
 * El primer renglon dice GRUPO desde el 27/08 y antes decia promotoria: quien va
 * a Guitarra los martes no comparte clase con quien va los jueves. Quien es
 * companero --y con ello quien ve el nombre y la cara de quien-- lo decide
 * `App\Support\Companeros`, que es el unico sitio donde esta escrito; de ahi
 * cuelgan las dos pantallas y la puerta de la foto.
 *
 * La linea de la encuesta sigue siendo cierta, pero desde que existe el informe
 * completo (`InformeController::institucion`) conviene leerla entera: el
 * administrador no solo la VE, tambien puede SACARLA en bloque y con nombre en
 * una hoja de calculo. La puerta no cambio —sigue siendo solo el—, lo que cambio
 * es que a partir de ahi el dato ya no vive dentro del sistema. Fue una decision
 * de direccion, tomada a sabiendas, y la pantalla lo avisa antes de descargar.
 *
 * La cuenta NO es opcional y por eso se anota como `User` y no como `?User`:
 * `perfiles.user_id` es obligatorio, con clave foranea y CASCADE (ver la
 * migracion). No hay ni puede haber un perfil huerfano. La anotacion no es un
 * parche para callar al analisis estatico: es que el tipo por defecto de la
 * relacion --anulable-- describe peor la realidad que esta linea, y sin ella
 * cada `actingAs($perfil->user)` de la suite se leia como un posible null.
 *
 * @property-read User $user
 * @property string|null $documento_identidad El del PERSONAL (guion 09); el del
 *     estudiante vive en `datos_estudiante`.
 */
class Perfil extends Model
{
    use DeLaInstitucion;

    /**
     * Los roles que existen, con su nombre de pantalla.
     *
     * `institucion_externa` NO ES PERSONAL DE LA CASA y no se parece a los
     * otros cuatro: es la cuenta de un funcionario de OTRA entidad —una escuela
     * rural, un colegio— cuyo unico trabajo aqui es dar fe de que el profesor
     * fue a dictar alla. No entra al Panel, no entra a Gestion y no ve a nadie.
     *
     * ESTA EN ESTA LISTA SOLO PARA PODER PINTAR SU NOMBRE, y no para poder
     * repartirlo: `Permisos::rolesAsignablesPor()` lo saca del desplegable de
     * Gestion → Usuarios a proposito. Una cuenta con este rol solo tiene
     * sentido colgada de una `InstitucionExterna`, y el formulario de usuarios
     * no sabe crear esa ficha: por ahi solo saldrian cuentas sueltas que no
     * pueden verificar nada y que nadie entiende de donde salieron. Se crean
     * desde «Programas formativos», que es donde vive la institucion.
     */
    public const ROLES = [
        'administrador' => 'Administrador',
        'director' => 'Director de escuela',
        'profesor' => 'Profesor',
        'estudiante' => 'Estudiante',
        'institucion_externa' => 'Institución externa',
    ];

    /** El rol de la cuenta que da fe desde otra entidad. */
    public const INSTITUCION_EXTERNA = 'institucion_externa';

    /**
     * Los roles que el formulario de Gestion → Usuarios puede repartir.
     *
     * Son los de `ROLES` menos `institucion_externa`, y SE ESCRIBEN ENTEROS en
     * vez de restarse, por lo mismo que `ROLES_PERSONAL` de aqui abajo: una
     * resta es una regla que cambia sola el dia que alguien anada un sexto rol,
     * y lo que cambiaria es a quien se le puede dar. Quien anada uno tiene que
     * decidir aqui, a proposito, si se reparte desde esa pantalla.
     *
     * Por que `institucion_externa` no esta: esa cuenta solo significa algo
     * colgada de una ficha de institucion, y ese formulario no sabe crear una.
     * Suelta seria un usuario que entra, no ve nada y no puede verificar nada.
     * Ver `Permisos::rolesAsignablesPor()`.
     */
    public const ROLES_REPARTIBLES = ['administrador', 'director', 'profesor', 'estudiante'];

    /**
     * El personal de la casa de la cultura: administrador, director y profesor.
     *
     * Es quien puede quedar a cargo de una promotoria —un director que tambien
     * dicta es un caso real— y quien entra al Panel. Las dos listas salen de
     * aqui para que no se separen con el tiempo.
     *
     * SE ESCRIBE ENTERA Y NO SE DEDUCE DE `ROLES`. Hasta el 23/09/2026 esto
     * era «todos los roles menos estudiante» y se podia leer como una resta;
     * ya no lo es, porque `institucion_externa` tampoco esta. Quien la
     * reconstruya con un `array_diff` contra «estudiante» le da a una cuenta de
     * otra entidad el Panel entero y la posibilidad de quedar a cargo de una
     * promotoria de esta casa — y no fallaria nada al hacerlo.
     */
    public const ROLES_PERSONAL = ['administrador', 'director', 'profesor'];

    /**
     * Quien tiene que tener documento y correo para poder usar el sistema
     * (05/10/2026, decision del usuario). Se piden en el registro de profesores
     * y, a quien ya tenia cuenta sin ellos, en una pantalla que no deja seguir
     * hasta llenarlos (`App\Http\Middleware\DatosDelPersonal`).
     *
     * El administrador NO esta, a proposito: es quien arregla lo de los demas
     * desde Gestion, y una puerta que lo dejara fuera de Gestion por un dato
     * suyo dejaria a la institucion sin nadie que pudiera abrirla.
     */
    public const ROLES_CON_DOCUMENTO_Y_CORREO = ['director', 'profesor'];

    protected $table = 'perfiles';

    protected $fillable = [
        'user_id',
        'rol',
        'nombre_completo',
        'fecha_nacimiento',
        'telefono',
        'documento_identidad',
        'foto_perfil',
    ];

    protected function casts(): array
    {
        return [
            'fecha_nacimiento' => 'date',
            'suprimido_en' => 'datetime',
        ];
    }

    /**
     * ¿Se suprimieron sus datos a peticion suya? Ver `SupresionDeDatos`.
     *
     * Se pregunta por la COLUMNA y no por el nombre: «Persona suprimida» es un
     * rotulo que cualquiera puede teclear en su propio perfil.
     */
    public function estaSuprimido(): bool
    {
        return $this->suprimido_en !== null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Las areas que este director dirige.
     *
     * VACIA PARA CUALQUIER OTRO ROL, y por eso no se pregunta sola: se lee
     * siempre junto al rol, en `Permisos::areasVisiblesPara()`. Las areas de
     * quien dejo de ser director siguen en la tabla y no dicen nada, que es lo
     * correcto — si vuelve a serlo, recupera lo que tenia.
     *
     * @return BelongsToMany<Area, $this, AreaDirigida>
     */
    public function areasDirigidas(): BelongsToMany
    {
        return $this->belongsToMany(Area::class, 'areas_dirigidas', 'perfil_id', 'area_id')
            ->using(AreaDirigida::class)
            ->orderBy('nombre');
    }

    /** @return HasOne<EncuestaDemografica, $this> */
    public function encuesta(): HasOne
    {
        return $this->hasOne(EncuestaDemografica::class, 'perfil_id');
    }

    public function encuestasSatisfaccion(): HasMany
    {
        return $this->hasMany(EncuestaSatisfaccion::class, 'perfil_id');
    }

    /**
     * Lo que solo tiene un estudiante: documento, encuesta y acudiente.
     *
     * El tipo va ANOTADO por lo mismo que en `Matricula::estudiante()`: sin el,
     * el analizador ve un `Model` generico y toda la cadena
     * `estudiante->datosEstudiante->acudiente` sale como propiedad inexistente
     * en las pantallas que la recorren.
     *
     * @return HasOne<DatosEstudiante, $this>
     */
    public function datosEstudiante(): HasOne
    {
        return $this->hasOne(DatosEstudiante::class, 'perfil_id');
    }

    public function matriculas(): HasMany
    {
        return $this->hasMany(Matricula::class, 'estudiante_id');
    }

    public function promotoriasDictadas(): HasMany
    {
        return $this->hasMany(Promotoria::class, 'profesor_id');
    }

    public function clasesRegistradas(): HasMany
    {
        return $this->hasMany(Clase::class, 'registrada_por_id');
    }

    /**
     * Las actividades que esta persona tiene a su cargo.
     *
     * Existe para que el borrado de una cuenta pueda contarlas antes de
     * ofrecerse: `actividades.responsable_id` es RESTRICT, o sea que la base
     * rechaza el borrado, y sin esta relacion la pantalla no podria decirlo
     * hasta despues de que alguien pulsara.
     */
    public function actividadesACargo(): HasMany
    {
        return $this->hasMany(Actividad::class, 'responsable_id');
    }

    /** Etiqueta legible del rol, o el aviso de que no tiene ninguno. */
    public function getRolDisplayAttribute(): string
    {
        return self::ROLES[$this->rol] ?? 'sin rol asignado';
    }

    public function esPersonal(): bool
    {
        return in_array($this->rol, self::ROLES_PERSONAL, true);
    }

    /** ¿Su rol le obliga a tener documento y correo? */
    public function debeTenerDocumentoYCorreo(): bool
    {
        return in_array($this->rol, self::ROLES_CON_DOCUMENTO_Y_CORREO, true);
    }

    /**
     * ¿Le falta el documento o el correo y su rol los exige?
     *
     * Una cuenta suprimida no pide nada: no tiene datos ni puede entrar.
     */
    public function faltanDocumentoOCorreo(): bool
    {
        return $this->debeTenerDocumentoYCorreo()
            && ! $this->estaSuprimido()
            && (($this->documento_identidad ?? '') === '' || ($this->user->email ?? '') === '');
    }

    /**
     * El token del carne QR, creandolo la primera vez que alguien lo pide.
     *
     * SE GENERA AL PEDIRLO y no al crear el perfil, a diferencia del token de
     * `Actividad`: aquella columna es NOT NULL y nace con la fila, mientras que
     * aqui habia 885 perfiles anteriores a esta funcion. Un hook de `creating`
     * habria dejado con codigo solo a quien se inscribiera desde hoy, y
     * justamente los que mas lo necesitan son los que ya estaban. Asi el camino
     * es uno solo para todos: se pide, y si no hay, se crea.
     *
     * `Str::random` usa el generador seguro del sistema. SON 16 CARACTERES Y NO
     * 32 porque el largo del codigo decide lo tupida que sale la rejilla del QR
     * —medido: 25 modulos con 16, 33 con 32— y esa rejilla la lee la camara de
     * un telefono barato sobre una fotocopia arrugada. 16 caracteres de este
     * alfabeto son 95 bits: adivinarlo no es un camino, y el camino de verdad
     * para hacerse con un codigo ajeno es fotografiarlo, no adivinarlo.
     *
     * Guarda con `saveQuietly` —nada escucha a este modelo hoy, pero mirar un
     * carne no es editar un perfil— y `codigo_qr` NO esta en `$fillable`: no
     * hay ningun formulario que deba poder escribirlo.
     */
    public function codigoQr(): string
    {
        if ($this->codigo_qr === null || $this->codigo_qr === '') {
            $this->codigo_qr = Str::random(16);
            $this->saveQuietly();
        }

        return $this->codigo_qr;
    }

    /**
     * Cambia el codigo: el carne anterior deja de servir en el acto.
     *
     * Existe porque un carne se pierde y se fotografia, y sin esto la unica
     * salida seria borrar la cuenta. Devuelve el nuevo.
     */
    public function renovarCodigoQr(): string
    {
        $this->codigo_qr = Str::random(16);
        $this->saveQuietly();

        return $this->codigo_qr;
    }

    /**
     * Quien lleva ese codigo, o null.
     *
     * Solo ESTUDIANTES. El carne existe para pasar lista, y en una lista de
     * clase no hay profesores; acotarlo aqui es lo que impide que el codigo de
     * un profesor —que hoy no se imprime en ningun sitio, pero la columna es de
     * todos los perfiles— sirva de nada si algun dia se filtrara.
     *
     * Un codigo vacio NO busca: sin este corte, `where('codigo_qr', '')`
     * encontraria a cualquiera de los que todavia no han pedido su carne.
     */
    public static function porCodigoQr(string $codigo): ?self
    {
        if (trim($codigo) === '') {
            return null;
        }

        return static::query()
            ->where('codigo_qr', $codigo)
            ->where('rol', 'estudiante')
            ->first();
    }

    /**
     * La institucion externa que lleva ese codigo, o null.
     *
     * ES UN METODO APARTE Y NO UN PARAMETRO DE `porCodigoQr()`, a proposito.
     * Los dos QR del sistema viven en la misma columna y empiezan por el mismo
     * `MTR:`, asi que lo unico que los separa es el ROL de quien lo lleva. Si
     * una sola funcion resolviera los dos, el lector de una hoja de asistencia
     * de promotoria aceptaria el QR de una escuela —y el de un programa externo
     * aceptaria el carne de un estudiante— sin que nada fallara: cada uno
     * seguiria su camino con el perfil equivocado en la mano.
     *
     * Por eso cada lector llama al suyo, y por eso ninguno de los dos admite
     * «cualquier rol». El dia que haya un tercer QR, sera un tercer metodo.
     *
     * Devuelve el PERFIL y no la institucion: quien pregunta ya sabe que hacer
     * con el, y `perfil->institucionExterna` esta a un paso.
     */
    public static function porCodigoQrDeInstitucion(string $codigo): ?self
    {
        if (trim($codigo) === '') {
            return null;
        }

        return static::query()
            ->where('codigo_qr', $codigo)
            ->where('rol', self::INSTITUCION_EXTERNA)
            ->first();
    }

    /**
     * La institucion externa de la que esta cuenta es el funcionario.
     *
     * NULL para los otros cuatro roles, que es lo normal. Un perfil con rol
     * `institucion_externa` y sin esto es una cuenta que no puede verificar
     * nada: no deberia existir —solo se crean junto a su ficha— y si aparece
     * una, es que alguien abrio un segundo camino.
     */
    /** @return HasOne<InstitucionExterna, $this> */
    public function institucionExterna(): HasOne
    {
        return $this->hasOne(InstitucionExterna::class, 'perfil_id');
    }

    /**
     * Anos cumplidos a partir de una fecha de nacimiento.
     *
     * Se escribe a mano en vez de usar `diffInYears` porque en Carbon 3 ese
     * metodo devuelve un float con signo, y aqui hace falta exactamente la
     * cuenta del original: el ano de diferencia, menos uno si todavia no ha
     * llegado el cumpleanos de este ano.
     *
     * Es publica y estatica porque el formulario de inscripcion necesita la
     * misma cuenta ANTES de que exista ningun perfil, para saber si hay que
     * exigir acudiente. Dos implementaciones de "es menor de edad" acabarian
     * discrepando justo el dia del cumpleanos.
     */
    public static function edadDe(Carbon $nacimiento): int
    {
        $hoy = Carbon::today();
        $edad = $hoy->year - $nacimiento->year;

        if ([$hoy->month, $hoy->day] < [$nacimiento->month, $nacimiento->day]) {
            $edad--;
        }

        return $edad;
    }

    /**
     * Anos cumplidos, o `null` si no se sabe.
     *
     * Se calcula, no se guarda: una edad almacenada estaria mal todos los dias
     * menos el del cumpleanos.
     *
     * `null` ES UN ESTADO REAL DESDE EL 23/09/2026 y no un hueco a medias: la
     * cuenta de una institucion externa es un contacto de OTRA entidad, y su
     * fecha de nacimiento no se pide porque no la usa nada —de ese dato cuelgan
     * la minoria de edad, el acudiente y el nivel, que son cosas de un
     * estudiante—. Para todos los demas roles sigue siendo obligatoria en el
     * formulario, asi que en la practica esto solo es null ahi.
     *
     * Quien pinte esto tiene que decidir QUE ENSENA cuando no hay dato. No vale
     * imprimirlo a secas: saldria «años» a secas, que se lee como un fallo.
     */
    public function getEdadAttribute(): ?int
    {
        return $this->fecha_nacimiento === null
            ? null
            : self::edadDe($this->fecha_nacimiento);
    }

    /**
     * Si es menor de edad. SIN FECHA, `false`.
     *
     * No es «se asume que es mayor»: es que de `es_menor` cuelgan cosas que
     * solo le pasan a un estudiante —exigirle acudiente, elegirle el formato de
     * consentimiento— y un estudiante SIEMPRE tiene fecha. Contestar `true`
     * ante la duda le exigiria acudiente a la cuenta de una escuela rural, que
     * no tiene ningun sentido y bloquearia su ficha.
     */
    public function getEsMenorAttribute(): bool
    {
        return $this->edad !== null && $this->edad < 18;
    }

    /**
     * ¿A esta persona le falta contestar la encuesta demografica?
     *
     * Cubre los dos casos, que para quien la tiene que llenar son el mismo: no
     * haberla empezado nunca, y tenerla a medias.
     */
    public function getEncuestaPendienteAttribute(): bool
    {
        $encuesta = $this->encuesta;

        return $encuesta === null || ! $encuesta->esta_completa;
    }

    public function __toString(): string
    {
        return "{$this->nombre_completo} ({$this->rol_display})";
    }
}
