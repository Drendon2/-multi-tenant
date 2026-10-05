<?php

namespace App\Models;

use App\Models\Concerns\DeLaInstitucion;
use App\Support\Color;
use App\Support\InstitucionActual;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Throwable;

/**
 * Ajustes de la institucion, editables sin tocar codigo.
 *
 * Una fila por institucion (UNIQUE `institucion_id`). Nacio como fila unica
 * (id = 1), cuando el sistema servia a una sola institucion; la idea sigue:
 * ninguno de estos datos deberia estar quemado en el codigo si se quiere
 * reinstalar para otra entidad sin tocar plantillas.
 *
 * Cubre dos cosas distintas:
 *
 * - La MARCA (nombre, logo, color de acento), que solo afecta como se ve.
 * - Una REGLA OPERATIVA: cuantas promotorias puede cursar un estudiante en un
 *   mismo periodo. Esta si cambia el comportamiento, asi que lo que se guarde
 *   aqui gobierna las validaciones de Matricula.
 *
 * No incluye nada del catalogo academico (areas, promotorias, periodos, cupos):
 * eso son registros propios, no ajustes de una sola fila.
 *
 * Declarada porque viene de un guion SQL (07-plazo-para-reponer) y Larastan
 * solo deduce las columnas de las migraciones de Laravel.
 *
 * @property int|null $dias_para_reponer
 * @property string $nombre_corto Vacio = el nombre largo (guion 11).
 * @property string $color_fondo Vacio = el de fabrica (guion 12).
 * @property string $color_cabecera Vacio = el de fabrica (guion 12).
 */
class ConfiguracionInstitucion extends Model
{
    use DeLaInstitucion;

    /**
     * Techo de ranuras GRABADO en el esquema (ver el CHECK `ranura_valida` de
     * `matriculas`). NO es la regla de negocio: esa es
     * `limite_promotorias_por_periodo`, editable en caliente.
     *
     * Este numero existe solo porque un CHECK no puede consultar una fila de
     * configuracion: se fija al migrar y ahi se queda. Se eligio holgado para
     * que subir el limite operativo nunca exija una migracion; si algun dia
     * hicieran falta mas de 6, hay que migrar los dos constraints de Matricula.
     */
    public const RANURA_MAXIMA_ABSOLUTA = 6;

    /**
     * Las dos finalidades de fabrica: lo que se autoriza cuando la entidad no
     * ha escrito las suyas.
     *
     * NO NOMBRAN NI SECTOR NI NATURALEZA. Decian «politicas publicas del sector
     * cultura» y «procesos formativos y culturales», que es exacto para una
     * casa de la cultura publica y falso para un colegio privado, una escuela
     * deportiva o una fundacion — y este producto se vende a esas tambien. El
     * porque entero esta en `Support\PoliticaDatos`.
     *
     * LA FORMA GRAMATICAL IMPORTA, porque cada una se incrusta en DOS frases:
     *
     *   DATOS, sintagma nominal:
     *     politica  -> «Para el analisis estadistico y la planeacion…»
     *     formato   -> «…y para el analisis estadistico y la planeacion…»
     *
     *   IMAGEN, infinitivo:
     *     politica  -> «Para comunicar y promocionar…»
     *     formato   -> «…con el fin de comunicar y promocionar…»
     *
     * Quien las cambie desde Gestion ve la frase entera delante, para que se
     * note donde cae lo suyo.
     */
    public const FINALIDAD_DATOS = 'el análisis estadístico y la planeación de los procesos formativos';

    public const FINALIDAD_IMAGEN = 'comunicar y promocionar los procesos formativos de la institución';

    /** Clave con la que la peticion en curso guarda la fila ya resuelta. */
    private const MEMORIA = 'configuracion-institucion.actual';

    protected $table = 'configuracion_institucion';

    protected $fillable = [
        'nombre_institucion',
        'nombre_corto',
        'entidad_nit',
        'entidad_direccion',
        'entidad_correo',
        'entidad_telefono',
        'politica_datos',
        'finalidad_datos',
        'finalidad_imagen',
        'consentimiento_mayor',
        'consentimiento_menor',
        'correo_servidor',
        'correo_puerto',
        'correo_cifrado',
        'correo_usuario',
        'correo_clave',
        'logo',
        'firma',
        'firmante_nombre',
        'firmante_cargo',
        'color_acento',
        'color_fondo',
        'color_cabecera',
        'limite_promotorias_por_periodo',
        'promotorias_visibles_para_estudiantes',
        'alerta_clase_no_dictada',
        'alerta_abandono',
        'faltas_para_abandono',
        'dias_para_reponer',
        'alertas_desde',
        'recordar_encuesta',
        'correo_obligatorio',
    ];

    /**
     * Los mismos valores por defecto que declara la migracion, repetidos aqui a
     * proposito y no por descuido.
     *
     * Sin esto, la fila que crea `actual()` la primera vez vuelve con solo el
     * `id`: los defaults los pone la base de datos al insertar, pero el modelo
     * en memoria no los ha leido, y `limite_promotorias_por_periodo` sale null
     * justo en la peticion que estrena el sistema.
     *
     * Sirve ademas para el otro caso que cubre `actual()`: cuando la tabla
     * todavia no existe y hay que devolver una instancia suelta que las
     * plantillas puedan pintar igual.
     */
    protected $attributes = [
        'nombre_institucion' => 'Casa de la Cultura',
        // Vacio = «usa el nombre largo». Va aqui por la trampa de siempre:
        // `actual()` crea la fila con `firstOrCreate` y no relee el defecto.
        'nombre_corto' => '',
        // Los cuatro datos de contacto de la entidad, por la misma razon que
        // sus vecinas: `actual()` crea la fila con `firstOrCreate` y esa
        // instancia NO relee lo que la base puso por defecto. Sin esta linea
        // salen null en la peticion que estrena el sistema, y la pagina de
        // tratamiento de datos —que es publica y la lee cualquiera— reventaria
        // justo ahi.
        //
        // `politica_datos` NO va aqui, y es deliberado: su ausencia es un valor
        // con significado —«usa el texto por defecto de `PoliticaDatos`»— y
        // nula es exactamente como tiene que llegar. Es el mismo caso de
        // `alertas_desde`, unas lineas mas abajo.
        'entidad_nit' => '',
        'entidad_direccion' => '',
        'entidad_correo' => '',
        'entidad_telefono' => '',
        // Las dos finalidades SI van aqui, al contrario que `politica_datos`:
        // aquella es nula y la ausencia es su valor; estas nacen '' en la base y
        // la instancia que crea `firstOrCreate` no lo releeria.
        'finalidad_datos' => '',
        'finalidad_imagen' => '',
        // Las dos ranuras del formato propio, por la misma razon que sus
        // vecinas. Su vacio significa «usa el que imprime el sistema», y en la
        // peticion que estrena la instalacion tienen que llegar '' y no null:
        // `formatoPropio()` compara con cadena vacia.
        'consentimiento_mayor' => '',
        'consentimiento_menor' => '',
        // Las cuatro del servidor de correo, por la misma razon que sus
        // vecinas. `correo_clave` NO esta aqui y es deliberado: lleva el cast
        // `encrypted`, que revienta al descifrar una cadena vacia, asi que «no
        // hay contrasena» tiene que llegar como NULL. Es el mismo caso de
        // `politica_datos`, unas lineas mas arriba.
        'correo_servidor' => '',
        'correo_puerto' => 465,
        'correo_cifrado' => 'smtps',
        'correo_usuario' => '',
        'logo' => '',
        'firma' => '',
        'firmante_nombre' => '',
        'firmante_cargo' => '',
        'color_acento' => '#0a7a59',
        // Vacios = «los de fabrica». Aqui por la trampa de `firstOrCreate`.
        'color_fondo' => '',
        'color_cabecera' => '',
        'limite_promotorias_por_periodo' => 2,
        'promotorias_visibles_para_estudiantes' => true,
        // Las tres de las alertas van AQUI y no solo en la migracion, como
        // todas sus vecinas: `actual()` crea la fila con `firstOrCreate` y esa
        // instancia NO relee lo que la base puso por defecto. Sin esta linea,
        // una instalacion recien migrada devolvia null en las tres — los dos
        // interruptores se leian como apagados y el umbral como cero, o sea
        // que las alertas salian apagadas y, si alguien las encendia, la de
        // abandono avisaba de todo el mundo.
        'alerta_clase_no_dictada' => true,
        'alerta_abandono' => true,
        'faltas_para_abandono' => 5,
        // El plazo para reponer una falta (03/10/2026). Va aqui por lo mismo
        // que sus vecinas; su NULO —«sin plazo»— lo pone quien lo vacia en
        // Configuracion, no una instalacion recien migrada.
        'dias_para_reponer' => 15,
        // Y esta por lo mismo. Sin ella, una instalacion recien migrada
        // devolvia null y el recordatorio nacia apagado sin que nadie lo
        // hubiera apagado.
        'recordar_encuesta' => true,
        // Y esta TAMBIEN, por lo de siempre: `actual()` crea la fila con
        // `firstOrCreate` y la instancia no relee el default de la base. Sin
        // ella, una instalacion recien migrada devuelve null y `correo()` lo
        // lee como «false» por casualidad, no por decision.
        'correo_obligatorio' => false,
        // `alertas_desde` NO va aqui, y es deliberado: sus vecinas estan porque
        // tienen un valor que la instancia no leeria de la base, y esa es nula.
        // Declararla no cambiaria nada — se comprobo quitandola y la prueba
        // siguio en verde — y una linea que no hace nada acaba leyendose como
        // si hiciera algo.
    ];

    protected function casts(): array
    {
        return [
            'limite_promotorias_por_periodo' => 'integer',
            'promotorias_visibles_para_estudiantes' => 'boolean',
            'alerta_clase_no_dictada' => 'boolean',
            'alerta_abandono' => 'boolean',
            'faltas_para_abandono' => 'integer',
            'dias_para_reponer' => 'integer',
            'alertas_desde' => 'date',
            'recordar_encuesta' => 'boolean',
            'correo_obligatorio' => 'boolean',
            'correo_puerto' => 'integer',
            // CIFRADA EN LA BASE, con `APP_KEY`. Una copia robada de la base no
            // entrega la contrasena del buzon de la entidad; hace falta ademas
            // el `.env` del servidor. Ojo: este cast lanza al leer una cadena
            // vacia, asi que la columna se escribe null cuando no hay nada —
            // ver `$attributes` y la migracion.
            'correo_clave' => 'encrypted',
        ];
    }

    /**
     * La configuracion vigente, creandola con los valores por defecto si falta.
     *
     * Se resuelve en caliente y no por una migracion de datos, para que un
     * proyecto recien clonado funcione sin pasos extra. Si la tabla todavia no
     * existe (antes de migrar) devuelve una instancia en memoria con los
     * defaults: de esto cuelga el compositor de vistas que corre en CADA
     * pagina, y no debe tumbar el sitio.
     *
     * SE RESUELVE UNA SOLA VEZ POR PETICION. El compositor de vistas de
     * `AppServiceProvider` dice eso en su comentario desde el principio, pero
     * no era verdad: `View::composer('*')` corre una vez por VISTA pintada, y
     * una pagina son el layout mas sus parciales. Eran cuatro `SELECT` iguales
     * en cada carga de cada pantalla del sistema.
     *
     * La copia vive en el contenedor y no en una propiedad estatica de la
     * clase. Es a proposito: el contenedor se construye de nuevo en cada
     * peticion y en cada prueba, asi que la copia muere sola. Una estatica
     * sobreviviria a toda la suite y devolveria la fila de la prueba anterior,
     * que es la clase de error que se tarda un dia en encontrar.
     */
    public static function actual(): self
    {
        // La memoria va atada a la institucion: una peticion que adopta la de
        // un enlace (o un comando que trabaja para otra) no puede quedarse con
        // la marca de la que se resolvio primero.
        $memoria = self::MEMORIA.'.'.InstitucionActual::id();

        if (app()->bound($memoria)) {
            return app()->make($memoria);
        }

        try {
            // Una fila por institucion (UNIQUE institucion_id); el filtro de
            // `DeLaInstitucion` la acota y el alta la crea en la actual.
            $configuracion = static::firstOrCreate([]);
        } catch (Throwable) {
            // Tabla sin migrar. NO se memoriza: es un estado que se arregla
            // solo en cuanto alguien migre, y guardarlo obligaria a que la
            // instancia suelta sobreviviera a la peticion que ya tiene tabla.
            return new static;
        }

        app()->instance($memoria, $configuracion);

        return $configuracion;
    }

    /** Una fila por institucion: la unicidad la garantiza la base. */
    protected static function booted(): void
    {
        // Cualquier guardado tira la copia de la peticion. Hoy todo el codigo
        // llega por `actual()` y guarda sobre ESA instancia, asi que la copia
        // ya saldria al dia; esto es para el dia que alguien cargue la fila por
        // su cuenta y la guarde, que entonces la copia memorizada quedaria
        // vieja y la pantalla seguiria pintando la marca anterior.
        static::saved(function (self $configuracion) {
            app()->forgetInstance(self::MEMORIA.'.'.$configuracion->institucionId());
        });

        // Sin configuracion el sistema se quedaria sin marca.
        static::deleting(function () {
            throw new RuntimeException('La configuracion de la institucion no se puede eliminar.');
        });
    }

    /**
     * QUE se autoriza tratar, tal como se lee en la politica y en el formato.
     *
     * Los dos sitios llaman aqui, y eso es lo que impide que se separen: la
     * politica tiene que ANUNCIAR lo que el consentimiento autoriza, y si no
     * coinciden lo firmado no vale. Antes dependia de que alguien se acordara de
     * tocar los dos textos.
     */
    public function finalidadDeDatos(): string
    {
        return trim((string) $this->finalidad_datos) ?: self::FINALIDAD_DATOS;
    }

    /** Lo mismo para el uso de la imagen. Va en infinitivo — ver la constante. */
    public function finalidadDeImagen(): string
    {
        return trim((string) $this->finalidad_imagen) ?: self::FINALIDAD_IMAGEN;
    }

    /**
     * La ruta del formato de autorizacion que subio la entidad, o '' si no
     * subio ninguno para esa version.
     *
     * Existe aqui y no en el controlador porque lo preguntan tres sitios —el
     * generador del PDF, la pantalla de Institucion y la de Mi perfil— y
     * escrito a mano en cada uno se separa sin que nada falle.
     *
     * `$version` es la misma palabra que viaja por la URL del formato en
     * blanco: `mayor` o `menor`. Cualquier otra cosa devuelve '', que es lo
     * correcto — quien pregunte por una version que no existe recibe «no hay»,
     * no un error.
     */
    public function formatoPropio(string $version): string
    {
        return match ($version) {
            'mayor' => (string) $this->consentimiento_mayor,
            'menor' => (string) $this->consentimiento_menor,
            default => '',
        };
    }

    /**
     * El nombre que va bajo el icono del celular: el corto si la entidad lo
     * puso, y si no el de siempre.
     */
    public function getNombreParaIconoAttribute(): string
    {
        $corto = trim((string) $this->nombre_corto);

        return $corto !== '' ? $corto : (string) $this->nombre_institucion;
    }

    /** El texto de la cabecera propia, o '' si la cabecera es la de fabrica. */
    public function getColorTextoCabeceraAttribute(): string
    {
        $cabecera = (string) $this->color_cabecera;

        return $cabecera === '' ? '' : Color::textoSobre($cabecera);
    }

    public function getColorAcentoOscuroAttribute(): string
    {
        return Color::acentoOscuro($this->color_acento ?? '#0a7a59');
    }

    public function getColorAcentoSuaveAttribute(): string
    {
        return Color::acentoSuave($this->color_acento ?? '#0a7a59');
    }

    /**
     * Los tres tonos del acento para el modo OSCURO.
     *
     * Existen porque el acento de marca no sobrevive a un fondo oscuro: el
     * verde de fabrica da 2,98:1 sobre la superficie oscura, y el de otra
     * institucion puede ser peor. Se derivan igual que sus gemelos claros, o
     * sea de UN solo color elegido, para que cambiar de marca siga sin obligar
     * a nadie a inventarse dos paletas. El como esta en `Support\Color`.
     *
     * @return array{claro: string, hover: string, suave: string}
     */
    public function getAcentoOscuroTrioAttribute(): array
    {
        return Color::acentoParaFondoOscuro($this->color_acento ?? '#0a7a59');
    }

    /** Contraste del texto blanco sobre el acento (los botones primarios). */
    public function getContrasteTextoBotonAttribute(): float
    {
        return Color::contraste('#ffffff', $this->color_acento ?? '#0a7a59');
    }
}
