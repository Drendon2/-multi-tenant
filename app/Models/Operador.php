<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;

/**
 * Quien opera el panel de TODAS las instituciones (paso 4b, 02/10/2026).
 *
 * No es una cuenta de ninguna institucion y no lleva `institucion_id`: por eso
 * vive en su propia tabla, con su propio guard (`operador`), y no es un rol
 * dentro de la institucion 1. Decision del usuario: asi un administrador de
 * una casa no puede ascender nunca a operador de todas. Se crean por consola
 * (`php artisan operador:crear`), nunca desde una pantalla.
 *
 * @property int $id
 * @property string $usuario
 * @property string $nombre
 * @property bool $activo
 * @property Carbon|null $ultimo_acceso
 */
class Operador extends Authenticatable
{
    protected $table = 'operadores';

    protected $fillable = ['usuario', 'nombre', 'password', 'activo'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'activo' => 'boolean',
            'ultimo_acceso' => 'datetime',
        ];
    }
}
