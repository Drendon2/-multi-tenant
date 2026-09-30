<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Institucion por defecto
    |--------------------------------------------------------------------------
    |
    | La que atiende a quien llega SIN sesion: el login, la inscripcion, la
    | politica de datos, el logo. Mientras no haya enrutamiento por dominio,
    | las paginas publicas son de esta. Un enlace con token (promotoria,
    | actividad, restablecer la clave) no la usa: trae la suya en la fila.
    |
    | Vacia, una peticion sin sesion ni token no tiene institucion y el
    | sistema se niega a consultar (ver `App\Support\InstitucionActual`).
    |
    */

    'por_defecto' => env('INSTITUCION_POR_DEFECTO', 1),

];
