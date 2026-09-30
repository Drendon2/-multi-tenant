<?php

namespace App\Models;

use App\Models\Concerns\DeLaInstitucion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
 */
class OmisionArchivada extends Model
{
    use DeLaInstitucion;

    protected $table = 'omisiones_archivadas';

    protected $fillable = ['grupo_id', 'fecha', 'archivada_por_id'];

    protected function casts(): array
    {
        return ['fecha' => 'date'];
    }

    public function grupo(): BelongsTo
    {
        return $this->belongsTo(Grupo::class);
    }

    /** Quien la archivo, si esa cuenta sigue existiendo. */
    public function archivadaPor(): BelongsTo
    {
        return $this->belongsTo(Perfil::class, 'archivada_por_id');
    }
}
