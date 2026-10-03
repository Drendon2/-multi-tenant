<?php

namespace App\Models;

use App\Models\Concerns\DeLaInstitucion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Una clase que no se dicto y que direccion ya dio por atendida.
 *
 * Lo UNICO que se guarda de las alertas. Las dos se calculan al abrir la
 * pantalla (ver `Support\Alertas`) porque una alerta guardada puede quedar
 * vieja; esta es la excepcion y por un motivo concreto: una clase que no se dio
 * el 12 de marzo no se arregla nunca, asi que su aviso no desaparece solo. Lo
 * que esta fila registra no es la omision —esa se deduce— sino que alguien ya
 * se ocupo de ella.
 *
 * La clave es (grupo, fecha) y no lleva clase_id: lo que se archiva es
 * justamente que NO hay clase.
 *
 * Desde el 03/10/2026 se dice ademas POR QUE (`causa`), y una falta se repone
 * con una clase (`repuesta_en_id`). Ver la migracion que las añadio. NULA es la
 * archivada de antes, sin clasificar, y sigue contando como perdida.
 *
 * @property Carbon $fecha
 * @property ?string $causa
 * @property ?int $repuesta_en_id
 * @property ?Carbon $clasificada_en
 */
class OmisionArchivada extends Model
{
    use DeLaInstitucion;

    protected $table = 'omisiones_archivadas';

    public const EXCUSA = 'excusa';

    public const FALTA = 'falta';

    public const INSTITUCION = 'institucion';

    /** Lo que se ofrece en la bandeja, en ese orden, con su rotulo. */
    public const CAUSAS = [
        self::EXCUSA => 'Excusa',
        self::FALTA => 'Falta',
        // Festivo, evento o cierre: ese dia la casa no tenia clase.
        self::INSTITUCION => 'Festivo o cierre',
    ];

    protected $fillable = ['grupo_id', 'fecha', 'causa', 'clasificada_en', 'archivada_por_id', 'repuesta_en_id'];

    /**
     * ¿Le cuenta al profesor como clase perdida?
     *
     * Las faltas y las archivadas de antes, sin clasificar. Una falta repuesta
     * TAMBIEN: la clase de ese dia no se dio igual, y la reposicion se dice
     * aparte.
     */
    public static function cuentaComoPerdida(?string $causa): bool
    {
        return $causa === null || $causa === self::FALTA;
    }

    /**
     * El ultimo dia para reponer una falta, o null si no hay plazo.
     *
     * Cuenta desde que se CLASIFICO y no desde el dia de la clase: es cuando el
     * profesor se entera, y una falta clasificada dos semanas tarde naceria
     * vencida. `$dias` nulo es «sin plazo» (Configuracion).
     */
    public static function plazoDe(?Carbon $clasificadaEn, ?int $dias): ?Carbon
    {
        if ($clasificadaEn === null || $dias === null) {
            return null;
        }

        return $clasificadaEn->copy()->startOfDay()->addDays($dias);
    }

    /**
     * ¿Es una falta sin reponer que ya paso su plazo?
     *
     * Solo SEÑALA: se puede seguir reponiendo. Una reposicion tardia vale mas
     * que ninguna, y nada le avisa a nadie de nada en este sistema.
     */
    public static function vencio(?string $causa, bool $repuesta, ?Carbon $clasificadaEn, ?int $dias): bool
    {
        $plazo = self::plazoDe($clasificadaEn, $dias);

        return $causa === self::FALTA && ! $repuesta && $plazo !== null && Carbon::today()->gt($plazo);
    }

    /** El plazo de ESTA falta, con el de la institucion. */
    public function plazo(): ?Carbon
    {
        return self::plazoDe($this->clasificada_en, ConfiguracionInstitucion::actual()->dias_para_reponer);
    }

    public function estaVencida(): bool
    {
        return self::vencio(
            $this->causa,
            $this->repuesta_en_id !== null,
            $this->clasificada_en,
            ConfiguracionInstitucion::actual()->dias_para_reponer,
        );
    }

    protected function casts(): array
    {
        return ['fecha' => 'date', 'clasificada_en' => 'datetime'];
    }

    public function grupo(): BelongsTo
    {
        return $this->belongsTo(Grupo::class);
    }

    /** La clase con la que se repuso, si ya se repuso. */
    public function repuestaEn(): BelongsTo
    {
        return $this->belongsTo(Clase::class, 'repuesta_en_id');
    }

    /** Quien la archivo, si esa cuenta sigue existiendo. */
    public function archivadaPor(): BelongsTo
    {
        return $this->belongsTo(Perfil::class, 'archivada_por_id');
    }
}
