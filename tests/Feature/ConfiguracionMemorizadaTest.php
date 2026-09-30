<?php

namespace Tests\Feature;

use App\Models\ConfiguracionInstitucion;
use App\Models\Perfil;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * La configuracion de la institucion se pregunta UNA vez por peticion.
 *
 * No estaba en la auditoria del 24/08: salio de volcar las consultas de
 * `Gestion -> Usuarios` mientras se paginaba. `View::composer('*')` corre una
 * vez por VISTA pintada —el layout y cada parcial—, asi que cada pantalla del
 * sistema lanzaba cuatro `SELECT` identicos a `configuracion_institucion`. El
 * comentario del compositor afirmaba desde el principio que se resolvia una vez
 * por peticion; no era verdad, y ahora ademas lo dice.
 *
 * La segunda prueba es la que hace que memorizar sea seguro y no un error
 * latente: comprueba que guardar la fila tira la copia. Sin eso, una copia
 * memorizada al principio de la peticion seguiria pintando la marca vieja.
 */
class ConfiguracionMemorizadaTest extends TestCase
{
    use RefreshDatabase;

    public function test_una_pantalla_solo_pregunta_una_vez_por_la_configuracion(): void
    {
        $admin = $this->crearAdmin();

        // La fila se crea con `create()` y NO con `actual()`, y no hay peticion
        // de calentamiento. Las dos cosas por lo mismo: en una prueba, todas las
        // peticiones comparten el contenedor —en produccion cada una estrena el
        // suyo—, asi que cualquier `actual()` previo dejaria la copia puesta y
        // la pantalla mediria CERO consultas, que es medirse a si misma.
        ConfiguracionInstitucion::create([]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($admin->user)->get('/gestion/usuarios')->assertOk();

        $consultas = collect(DB::getQueryLog())
            ->filter(fn ($q) => str_contains($q['query'], 'configuracion_institucion'))
            ->count();

        DB::disableQueryLog();

        $this->assertSame(1, $consultas, 'La configuracion se consulto mas de una vez en la misma peticion.');
    }

    /**
     * Guardar la fila tira la copia de la peticion.
     *
     * Se guarda desde OTRA instancia a proposito. Hoy todo el codigo llega por
     * `actual()` y guarda sobre esa misma instancia, asi que la copia saldria al
     * dia por casualidad; lo que se fija aqui es que siga estando bien el dia
     * que alguien cargue la fila por su cuenta, que es cuando una memoria mal
     * puesta empieza a servir datos viejos sin avisar.
     */
    public function test_guardar_la_configuracion_tira_la_copia_memorizada(): void
    {
        ConfiguracionInstitucion::actual();

        // La fila de la institucion actual, cargada por su cuenta (ya no es
        // `id = 1`: hay una por institucion).
        $otra = ConfiguracionInstitucion::query()->sole();
        $otra->nombre_institucion = 'Escuela Nueva';
        $otra->save();

        $this->assertSame('Escuela Nueva', ConfiguracionInstitucion::actual()->nombre_institucion);
    }

    /** Lo que ve quien mira: el nombre nuevo, no el que habia al abrir. */
    public function test_el_encabezado_pinta_el_nombre_guardado_y_no_el_anterior(): void
    {
        $admin = $this->crearAdmin();

        $this->actingAs($admin->user)->get('/gestion/usuarios')->assertOk();

        // La fila de la institucion actual, cargada por su cuenta (ya no es
        // `id = 1`: hay una por institucion).
        $otra = ConfiguracionInstitucion::query()->sole();
        $otra->nombre_institucion = 'Escuela Nueva';
        $otra->save();

        $this->actingAs($admin->user)
            ->get('/gestion/usuarios')
            ->assertOk()
            ->assertSee('Escuela Nueva');
    }

    private function crearAdmin(): Perfil
    {
        $user = User::create(['username' => 'jefa', 'password' => 'x', 'activo' => true]);

        return Perfil::create([
            'user_id' => $user->id,
            'rol' => 'administrador',
            'nombre_completo' => 'Jefa',
            'fecha_nacimiento' => Carbon::today()->subYears(35)->toDateString(),
            'telefono' => '3000000000',
        ]);
    }
}
