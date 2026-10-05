<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

/**
 * Que la contraseña nueva no sea de las que se adivinan primero (05/10/2026,
 * decision del usuario).
 *
 * Es la unica defensa contra el BARRIDO: probar una contraseña comun contra
 * muchas cuentas. El tope de intentos va por cuenta (ver la revision de
 * seguridad en CLAUDE.md) y ese ataque usa un intento por cuenta, asi que
 * ningun contador lo frena; lo frena que esa contraseña no exista.
 *
 * Rechaza tres cosas:
 *
 * - Las de la lista (`resources/claves-comunes.txt`), comparadas sin
 *   mayusculas ni tildes: «Colombia123» y «colombia123» son la misma para
 *   quien las prueba.
 * - Un solo caracter repetido («aaaaaaaa», «11111111»).
 * - La que es igual al nombre de usuario, que es lo primero que se prueba.
 *
 * NO pregunta a ningun servicio de fuera (Laravel trae `uncompromised()`, que
 * consulta una API por internet): en el hosting compartido seria una espera y
 * un punto de fallo mas en cada formulario con contraseña.
 *
 * Corre al CREAR o CAMBIAR una contraseña, nunca al entrar: quien ya tiene una
 * comun sigue entrando.
 */
class ClaveComun implements ValidationRule
{
    /** @var array<string, true>|null */
    private static ?array $lista = null;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        $clave = self::normalizar($value);

        if (isset(self::lista()[$clave]) || preg_match('/^(.)\1+$/u', $clave) === 1) {
            $fail('Esa contraseña es de las más usadas y se adivina en segundos. Elige otra.');

            return;
        }

        // El usuario de la cuenta: el del formulario si lo trae (registro,
        // inscripcion, Gestion → Usuarios) o el de quien la cambia (Mi perfil).
        $usuario = request()->input('username') ?? request()->user()?->username;

        if (is_string($usuario) && $usuario !== '' && $clave === self::normalizar($usuario)) {
            $fail('La contraseña no puede ser igual al nombre de usuario.');
        }
    }

    private static function normalizar(string $texto): string
    {
        return Str::lower(Str::ascii(trim($texto)));
    }

    /** @return array<string, true> */
    private static function lista(): array
    {
        if (self::$lista === null) {
            $lineas = file(resource_path('claves-comunes.txt'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            $claves = array_filter($lineas, fn (string $l) => ! str_starts_with($l, '#'));
            self::$lista = array_fill_keys(array_map(self::normalizar(...), $claves), true);
        }

        return self::$lista;
    }
}
