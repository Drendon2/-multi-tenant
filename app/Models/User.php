<?php

namespace App\Models;

use App\Models\Concerns\DeLaInstitucion;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Cuenta de acceso. El equivalente de `django.contrib.auth.models.User`.
 *
 * Se entra con `username`, no con correo (ver la migracion de la tabla).
 * `activo` es el `is_active` de Django: una cuenta desactivada no puede entrar,
 * pero no se borra — de ella cuelgan matriculas, asistencias e historial.
 *
 * Los datos de la PERSONA (nombre, edad, telefono, foto) no estan aqui sino en
 * `Perfil`, que es 1:1 con esta tabla y lo comparten todos los roles.
 *
 * El perfil se anota ANULABLE, al reves que `Perfil::$user`, y la asimetria es
 * correcta: `perfiles.user_id` es obligatorio --no hay perfil sin cuenta-- pero
 * nada obliga a que una cuenta tenga perfil, y de hecho existe la ventana entre
 * crear la una y el otro. Quien lea `$user->perfil` tiene que contar con null.
 *
 * Desde el paso 4a (02/10/2026) la cuenta es de una institucion como cualquier
 * otra fila: la institucion de la peticion la dice el DOMINIO, antes de
 * entrar, y `users` tiene RLS. Por eso `username` es unico POR institucion
 * (dos casas pueden tener cada una su «admin») y el login solo encuentra las
 * cuentas de la casa por cuyo dominio se entra. Hasta ese paso no llevaba el
 * filtro: la institucion salia de la cuenta, y con el filtro resolver la
 * una pedia la otra.
 *
 * @property int $institucion_id
 * @property-read Perfil|null $perfil
 */
class User extends Authenticatable
{
    use DeLaInstitucion, HasFactory, Notifiable;

    protected $fillable = [
        'username',
        'email',
        'password',
        'activo',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'activo' => 'boolean',
        ];
    }

    public function perfil(): HasOne
    {
        return $this->hasOne(Perfil::class);
    }
}
