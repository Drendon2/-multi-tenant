<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Donde vive el panel de todas las instituciones (paso 4b).
 *
 * En `panel.<dominio base>`, y solo si hay dominio base: una instalacion de
 * una sola casa no tiene panel. El subdominio `panel` esta reservado en la base
 * (`04-panel.sql`), asi que ninguna institucion puede quedarse con el.
 */
final class Panel
{
    /** Los subdominios que ninguna institucion puede tener. */
    public const RESERVADOS = ['panel', 'www'];

    /** El host del panel, o null si esta instalacion no tiene panel. */
    public static function host(): ?string
    {
        $base = strtolower(trim((string) config('institucion.dominio_base')));

        return $base === '' ? null : 'panel.'.$base;
    }

    public static function esLaPeticion(Request $request): bool
    {
        $host = self::host();

        return $host !== null && strtolower($request->getHost()) === $host;
    }
}
