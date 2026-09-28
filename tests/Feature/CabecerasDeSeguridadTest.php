<?php

namespace Tests\Feature;

use App\Models\Perfil;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Las cabeceras de seguridad (revision del 27/09/2026). Ver
 * `App\Http\Middleware\CabecerasDeSeguridad` para el porque de cada una.
 */
class CabecerasDeSeguridadTest extends TestCase
{
    use RefreshDatabase;

    private const ESPERADAS = [
        'X-Frame-Options' => 'SAMEORIGIN',
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
    ];

    public function test_una_pantalla_publica_las_lleva(): void
    {
        $respuesta = $this->get(route('login'))->assertOk();

        foreach (self::ESPERADAS as $cabecera => $valor) {
            $respuesta->assertHeader($cabecera, $valor);
        }
        // La camara, solo para este sitio: la usan los lectores QR.
        $this->assertStringContainsString('camera=(self)', (string) $respuesta->headers->get('Permissions-Policy'));
        $respuesta->assertHeaderMissing('X-Powered-By');
    }

    public function test_una_pantalla_con_sesion_tambien(): void
    {
        $user = User::create(['username' => 'jefa', 'password' => 'demo1234', 'activo' => true]);
        Perfil::create([
            'user_id' => $user->id, 'rol' => 'administrador', 'nombre_completo' => 'Jefa Ruiz',
            'fecha_nacimiento' => '1990-01-01', 'telefono' => '3000000000',
        ]);

        $respuesta = $this->actingAs($user)->get(route('mi-perfil'))->assertOk();

        foreach (self::ESPERADAS as $cabecera => $valor) {
            $respuesta->assertHeader($cabecera, $valor);
        }
    }

    /**
     * HSTS solo por HTTPS: sobre http no vale nada, y en desarrollo dejaria al
     * navegador empenado en https://localhost.
     */
    public function test_hsts_va_solo_por_https(): void
    {
        $this->get(route('login'))->assertHeaderMissing('Strict-Transport-Security');

        // La URL entera en https: con `route()` sale en http y el esquema de
        // la URL manda sobre cualquier variable de servidor.
        $this->get(str_replace('http://', 'https://', route('login')))
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }
}
