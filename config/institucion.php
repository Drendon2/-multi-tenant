<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Dominio base
    |--------------------------------------------------------------------------
    |
    | Con varias instituciones en la misma instalacion, la de cada peticion la
    | dice el HOST: `guarne.<dominio base>` es la institucion con subdominio
    | «guarne», y una entidad con dominio propio entra por el suyo. Un host que
    | no es de ninguna da 404 (ver `App\Support\InstitucionActual::delHost()`).
    |
    | Sin puerto ni esquema: `matriculas.example.com`, o `localhost` para
    | probar en local con `santuario.localhost:8001`.
    |
    | Vacio, la instalacion es de UNA sola casa y todas las peticiones son de la
    | institucion por defecto.
    |
    */

    'dominio_base' => env('DOMINIO_BASE'),

    /*
    |--------------------------------------------------------------------------
    | Institucion por defecto
    |--------------------------------------------------------------------------
    |
    | Solo vale en dos sitios (decision del usuario, 02/10/2026):
    |
    | - en una instalacion de UNA sola casa (sin `DOMINIO_BASE`), donde es la de
    |   todas las peticiones;
    | - en la consola, para el comando que no dice con cual trabaja.
    |
    | Con `DOMINIO_BASE`, una peticion web la saca del host y esta no se mira.
    |
    | Vacia, una peticion sin institucion no consulta nada (ver
    | `App\Support\InstitucionActual`).
    |
    */

    'por_defecto' => env('INSTITUCION_POR_DEFECTO', 1),

];
