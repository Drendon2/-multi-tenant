<?php

namespace App\Support;

use App\Models\Institucion;
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

    /**
     * La URL de `$ruta` en el dominio de una institucion, o null si no tiene
     * ninguno. Con el esquema y el puerto de la peticion actual: en local el
     * panel y las instituciones comparten `:8001`.
     */
    public static function urlDe(Institucion $institucion, string $ruta): ?string
    {
        $base = strtolower(trim((string) config('institucion.dominio_base')));
        $host = $institucion->dominio_propio
            ?? ($institucion->subdominio !== null && $base !== '' ? $institucion->subdominio.'.'.$base : null);

        return $host === null ? null : self::url($host, $ruta);
    }

    /** La URL de `$ruta` en el host del panel. */
    public static function urlDelPanel(string $ruta): string
    {
        return self::url((string) self::host(), $ruta);
    }

    private static function url(string $host, string $ruta): string
    {
        $peticion = request();
        $puerto = $peticion->getPort();
        $estandar = ($peticion->getScheme() === 'https' && $puerto === 443) || ($peticion->getScheme() === 'http' && $puerto === 80);

        return $peticion->getScheme().'://'.$host.($estandar || $puerto === null ? '' : ':'.$puerto).'/'.ltrim($ruta, '/');
    }

    public static function esLaPeticion(Request $request): bool
    {
        $host = self::host();

        return $host !== null && strtolower($request->getHost()) === $host;
    }
}
