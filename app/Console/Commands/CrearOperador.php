<?php

namespace App\Console\Commands;

use App\Models\Operador;
use Illuminate\Console\Command;

/**
 * Da de alta a un operador del panel de todas las instituciones, o le cambia
 * la contraseña si ya existe (paso 4b, 02/10/2026).
 *
 * Por consola y no desde una pantalla, a proposito: quien abre el panel ve y
 * suspende cualquier institucion, y una pantalla que crea operadores es una
 * puerta mas por la que se llega a eso. Quien tiene la consola ya tiene el
 * servidor.
 *
 *     php artisan operador:crear soporte --nombre="Soporte"
 *     php artisan operador:crear soporte --desactivar
 */
class CrearOperador extends Command
{
    /** Mas larga que la de una cuenta de institucion (8): esta abre todas. */
    public const LARGO_MINIMO = 12;

    protected $signature = 'operador:crear
        {usuario : Con el que entra al panel.}
        {--nombre= : Para reconocerlo en el registro. Por defecto, el usuario.}
        {--desactivar : No crea ni cambia la clave: lo apaga y lo echa del panel.}';

    protected $description = 'Crea un operador del panel de instituciones, le cambia la contraseña o lo desactiva.';

    public function handle(): int
    {
        $usuario = trim((string) $this->argument('usuario'));
        $operador = Operador::where('usuario', $usuario)->first();

        if ($this->option('desactivar')) {
            if ($operador === null) {
                $this->error("No hay ningún operador «{$usuario}».");

                return self::FAILURE;
            }

            $operador->update(['activo' => false]);
            $this->info("«{$operador->usuario}» queda desactivado.");

            return self::SUCCESS;
        }

        $clave = (string) $this->secret('Contraseña (mínimo '.self::LARGO_MINIMO.' caracteres)');

        if (mb_strlen($clave) < self::LARGO_MINIMO) {
            $this->error('Demasiado corta.');

            return self::FAILURE;
        }

        if ($clave !== (string) $this->secret('Repítela')) {
            $this->error('No coinciden.');

            return self::FAILURE;
        }

        if ($operador === null) {
            Operador::create([
                'usuario' => $usuario,
                'nombre' => $this->option('nombre') ?: $usuario,
                'password' => $clave,
                'activo' => true,
            ]);
            $this->info("Operador «{$usuario}» creado.");
        } else {
            $operador->update(['password' => $clave, 'activo' => true]);
            $this->info("Contraseña de «{$operador->usuario}» cambiada.");
        }

        return self::SUCCESS;
    }
}
