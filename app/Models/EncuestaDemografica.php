<?php

namespace App\Models;

use App\Models\Concerns\DeLaInstitucion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Encuesta demografica. Obligatoria para todos, con los campos SENSIBLES
 * opcionales.
 *
 * Salvo `barrio`, todo se responde eligiendo de una lista cerrada. Son dos
 * cosas a la vez: la persona escribe menos —muchas la llenan desde el celular—
 * y las cifras de Gestion → Estadisticas se pueden agrupar de verdad. Con texto
 * libre, «Bachillerato», «bachiller» y «Secundaria» eran tres filas distintas de
 * lo mismo, y no habia forma de sumarlas.
 *
 * Los codigos internos son cortos y estables; la etiqueta legible va aparte. Lo
 * que se guarda es el CODIGO, asi que renombrar un texto no obliga a migrar
 * datos. "ns" significa siempre "prefiero no responder", el mismo codigo en
 * todas las escalas que lo ofrecen.
 *
 * @property Carbon|null $fecha_autorizacion
 */
class EncuestaDemografica extends Model
{
    use DeLaInstitucion;

    /**
     * «No binario» y no «Otro», que es lo que decia el original.
     *
     * Es una divergencia DELIBERADA de la especificacion Django
     * (`models.py:441`), pedida por direccion, y la razon no es de estilo: en una
     * lista de identidades, «Otro» no nombra a nadie — define a esas personas por
     * no ser las dos primeras. Quien se reconoce ahi tiene un nombre, y la
     * encuesta que le pregunta quien es puede usarlo.
     *
     * La CLAVE guardada sigue siendo `o`. Cambiarla obligaria a migrar las filas
     * y no aporta nada: el codigo es opaco, nadie lo lee, y lo que se ensena es
     * la etiqueta. Se cambia ahora y no despues a proposito: el sistema todavia
     * no esta desplegado, asi que no hay ninguna respuesta real que reetiquetar
     * —las 44 que existen son de las cuentas sembradas por `simular`—. Una vez
     * en produccion, cambiar la etiqueta de una opcion ya contestada seria
     * reescribir lo que alguien respondio.
     *
     * `otro` SI se queda en OCUPACIONES: ahi no es una identidad sino una
     * categoria laboral, y «otro oficio» no define a nadie por descarte.
     */
    public const GENEROS = [
        'f' => 'Femenino',
        'm' => 'Masculino',
        'o' => 'No binario',
        'ns' => 'Prefiero no responder',
    ];

    public const ESTRATOS = [1 => '1', 2 => '2', 3 => '3', 4 => '4', 5 => '5', 6 => '6'];

    /**
     * De menos a mas estudios: el orden de la lista es el que usan los
     * desplegables y las graficas, asi que tiene que ser el orden natural de la
     * escala y no uno alfabetico.
     */
    public const NIVELES_EDUCATIVOS = [
        'ninguno' => 'Ninguno',
        'primaria_inc' => 'Primaria incompleta',
        'primaria_com' => 'Primaria completa',
        'secundaria_inc' => 'Secundaria incompleta',
        'secundaria_com' => 'Secundaria completa',
        'tecnico' => 'Técnico',
        'tecnologo' => 'Tecnólogo',
        'universitario' => 'Universitario',
        'posgrado' => 'Posgrado',
    ];

    public const OCUPACIONES = [
        'estudiante' => 'Estudiante',
        'empleado' => 'Empleado',
        'independiente' => 'Independiente',
        'desempleado' => 'Desempleado',
        'hogar' => 'Hogar',
        'pensionado' => 'Pensionado',
        'otro' => 'Otro',
    ];

    public const GRUPOS_ETNICOS = [
        'ninguno' => 'Ninguno',
        'indigena' => 'Indígena',
        'afro' => 'Negro/Afrocolombiano',
        'raizal' => 'Raizal',
        'palenquero' => 'Palenquero',
        'rrom' => 'Rrom/Gitano',
        'ns' => 'Prefiero no responder',
    ];

    public const DISCAPACIDADES = [
        'ninguna' => 'Ninguna',
        'fisica' => 'Física/motora',
        'visual' => 'Visual',
        'auditiva' => 'Auditiva',
        'intelectual' => 'Intelectual/cognitiva',
        'psicosocial' => 'Psicosocial',
        'multiple' => 'Múltiple',
        'ns' => 'Prefiero no responder',
    ];

    public const ZONAS = [
        'urbana' => 'Urbana',
        'rural' => 'Rural',
        'centro_poblado' => 'Centro poblado',
    ];

    public const VICTIMAS_CONFLICTO = [
        'si' => 'Sí',
        'no' => 'No',
        'ns' => 'Prefiero no responder',
    ];

    public const AFILIACIONES_SALUD = [
        'contributivo' => 'Contributivo',
        'subsidiado' => 'Subsidiado',
        'no_afiliado' => 'No afiliado',
        'no_sabe' => 'No sabe',
    ];

    /**
     * Los que no admiten vacio. `autoriza_tratamiento_datos` NO esta aqui a
     * proposito: es un booleano y "no autorizo" es una respuesta legitima, no
     * una pregunta sin contestar.
     */
    public const CAMPOS_OBLIGATORIOS = [
        'genero' => 'género',
        'barrio' => 'barrio',
        'estrato' => 'estrato',
        'nivel_educativo' => 'nivel educativo',
        'ocupacion' => 'ocupación',
    ];

    protected $table = 'encuestas_demograficas';

    protected $fillable = [
        'perfil_id',
        'genero',
        'barrio',
        'estrato',
        'nivel_educativo',
        'ocupacion',
        'zona',
        'afiliacion_salud',
        'grupo_etnico',
        'discapacidad',
        'victima_conflicto_armado',
        'autoriza_tratamiento_datos',
        'fecha_autorizacion',
    ];

    protected function casts(): array
    {
        return [
            'estrato' => 'integer',
            'autoriza_tratamiento_datos' => 'boolean',
            'fecha_autorizacion' => 'datetime',
        ];
    }

    /**
     * Que lista de opciones le corresponde a cada campo.
     *
     * Es lo que hace posible traducir un codigo guardado a su texto legible sin
     * repetir el nombre de la constante en cada plantilla y en cada formulario.
     */
    public const OPCIONES = [
        'genero' => self::GENEROS,
        'estrato' => self::ESTRATOS,
        'nivel_educativo' => self::NIVELES_EDUCATIVOS,
        'ocupacion' => self::OCUPACIONES,
        'zona' => self::ZONAS,
        'afiliacion_salud' => self::AFILIACIONES_SALUD,
        'grupo_etnico' => self::GRUPOS_ETNICOS,
        'discapacidad' => self::DISCAPACIDADES,
        'victima_conflicto_armado' => self::VICTIMAS_CONFLICTO,
    ];

    public function perfil(): BelongsTo
    {
        return $this->belongsTo(Perfil::class);
    }

    /**
     * El texto legible de un campo de lista cerrada.
     *
     * Equivale al `get_FOO_display` de Django: sin esto la ficha ensenaria
     * "secundaria_com" en vez de "Secundaria completa". Los campos opcionales
     * sin responder devuelven una raya, que es lo que corresponde a un hueco.
     */
    public function etiqueta(string $campo, string $vacio = '—'): string
    {
        $valor = $this->{$campo};

        if ($valor === null || $valor === '') {
            return $vacio;
        }

        return self::OPCIONES[$campo][$valor] ?? (string) $valor;
    }

    /**
     * Preguntas obligatorias que esta encuesta tiene en blanco.
     *
     * Existir una encuesta no significa estar contestada, y esa diferencia no es
     * teorica: en el original, una migracion vacio el texto libre de
     * `nivel_educativo` y `ocupacion` para poder estrechar la columna a listas
     * cerradas, asi que toda encuesta anterior a ese cambio quedo a medias. El
     * formulario de hoy no deja guardarlas vacias, pero las de entonces siguen
     * ahi y nada las senalaba: contaban como diligenciadas en la cifra de la
     * portada.
     *
     * Devuelve los nombres legibles, que es lo que hay que ensenarle a la
     * persona; para decidir usa `esta_completa`.
     *
     * @return list<string>
     */
    public function getPreguntasFaltantesAttribute(): array
    {
        $faltantes = [];

        foreach (self::CAMPOS_OBLIGATORIOS as $campo => $etiqueta) {
            $valor = $this->{$campo};

            if ($valor === null || $valor === '') {
                $faltantes[] = $etiqueta;
            }
        }

        return $faltantes;
    }

    public function getEstaCompletaAttribute(): bool
    {
        return $this->preguntas_faltantes === [];
    }
}
