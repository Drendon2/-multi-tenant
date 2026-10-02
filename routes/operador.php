<?php

use App\Http\Controllers\Operador\InstitucionesController;
use App\Http\Controllers\Operador\SesionController;
use App\Http\Middleware\OperadorActivo;
use Illuminate\Support\Facades\Route;

/*
| El panel de TODAS las instituciones (paso 4b, 02/10/2026). Solo existe en
| `panel.<dominio base>` (lo vigila `SoloEnElPanel`, primero del grupo
| `operador`) y lo usan los operadores, con su propio guard.
|
| Todo cuelga de `/instituciones` y no de `/panel`: `/panel` es la pantalla del
| profesor, y dos rutas con la misma direccion se pisan aunque vivan en hosts
| distintos.
*/

Route::get('/instituciones/entrar', [SesionController::class, 'mostrar'])->name('operador.entrar');
Route::post('/instituciones/entrar', [SesionController::class, 'entrar'])
    ->middleware('throttle:operador-entrar')
    ->name('operador.entrar.enviar');

Route::middleware(['auth:operador', OperadorActivo::class])->group(function () {
    Route::post('/instituciones/salir', [SesionController::class, 'salir'])->name('operador.salir');
    Route::get('/instituciones', [InstitucionesController::class, 'index'])->name('operador.instituciones');
    Route::get('/instituciones/{institucion}', [InstitucionesController::class, 'editar'])
        ->whereNumber('institucion')
        ->name('operador.institucion');
    Route::post('/instituciones/{institucion}', [InstitucionesController::class, 'guardar'])
        ->whereNumber('institucion')
        ->name('operador.institucion.guardar');
});
