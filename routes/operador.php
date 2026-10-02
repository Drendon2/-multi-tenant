<?php

use App\Http\Controllers\Operador\InstitucionesController;
use App\Http\Controllers\Operador\ResumenController;
use App\Http\Controllers\Operador\SesionController;
use App\Http\Controllers\Operador\SuplantacionController;
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

    // El resumen de todas y sus descargas consolidadas (paso 5). Antes que
    // `{institucion}`, aunque esa solo case con numeros.
    Route::get('/instituciones/resumen', [ResumenController::class, 'index'])->name('operador.resumen');
    Route::get('/instituciones/resumen/resumen.csv', [ResumenController::class, 'descargarResumen'])->name('operador.descarga.resumen');
    Route::get('/instituciones/resumen/promotorias.csv', [ResumenController::class, 'descargarPromotorias'])->name('operador.descarga.promotorias');
    Route::get('/instituciones/resumen/demografia.csv', [ResumenController::class, 'descargarDemografia'])->name('operador.descarga.demografia');
    Route::get('/instituciones/resumen/personas.csv', [ResumenController::class, 'descargarPersonas'])->name('operador.descarga.personas');
    Route::get('/instituciones/resumen/actividades.csv', [ResumenController::class, 'descargarActividades'])->name('operador.descarga.actividades');
    Route::get('/instituciones/{institucion}', [InstitucionesController::class, 'editar'])
        ->whereNumber('institucion')
        ->name('operador.institucion');
    Route::post('/instituciones/{institucion}', [InstitucionesController::class, 'guardar'])
        ->whereNumber('institucion')
        ->name('operador.institucion.guardar');
    Route::post('/instituciones/{institucion}/entrar-como/{perfil}', [SuplantacionController::class, 'emitir'])
        ->whereNumber(['institucion', 'perfil'])
        ->name('operador.suplantar');
});
