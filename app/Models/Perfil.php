<?php

namespace App\Models;

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
 */
class Perfil extends Model
{
    public const ROLES = [
        'administrador' => 'Administrador',
        'director' => 'Director de escuela',
        'profesor' => 'Profesor',
        'estudiante' => 'Estudiante',
    ];

    /**
     * El personal de la institucion: todos los roles menos "estudiante".
     *
     * Es quien puede quedar a cargo de una promotoria —un director que tambien
     * dicta es un caso real— y quien entra al Panel. Las dos listas salen de
     * aqui para que no se separen con el tiempo.
     */
    public const ROLES_PERSONAL = ['administrador', 'director', 'profesor'];

    protected $table = 'perfiles';

    protected $fillable = [
        'user_id',
        'rol',
        'nombre_completo',
        'fecha_nacimiento',
        'telefono',
        'foto_perfil',
    ];

    protected function casts(): array
    {
        return [
            'fecha_nacimiento' => 'date',
        ];
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
     * @return BelongsToMany<Area, $this>
     */
    public function areasDirigidas(): BelongsToMany
    {
        return $this->belongsToMany(Area::class, 'areas_dirigidas', 'perfil_id', 'area_id')
            ->orderBy('nombre');
    }

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
     * Anos cumplidos. Se calcula, no se guarda: una edad almacenada estaria mal
     * todos los dias menos el del cumpleanos.
     */
    public function getEdadAttribute(): int
    {
        return self::edadDe($this->fecha_nacimiento);
    }

    public function getEsMenorAttribute(): bool
    {
        return $this->edad < 18;
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
