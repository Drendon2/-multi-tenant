<?php

namespace Tests\Feature;

use App\Models\ConfiguracionInstitucion;
use App\Models\Perfil;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * EL MENU ES UNA BARRA, y en el telefono vive abajo — 12/09/2026.
 *
 * Lo pidio el usuario: «este menu no es muy funcional para movil, y el boton de
 * cerrar sesion es el mas visible y queda muy cerca de los otros, puede causar
 * que se cierre sesion por error al querer cambiar al modo oscuro; y dentro de
 * cerrar sesion no es necesario que este el nombre del usuario».
 *
 * ─── LO QUE ESTAS PRUEBAS PUEDEN Y NO PUEDEN ───────────────────────────────
 *
 * PHPUnit NO TIENE NAVEGADOR, asi que aqui no se mide ni un pixel. Lo medido
 * esta escrito en `app.css` y se comprobo abriendo la pagina a 390px: la
 * cabecera bajo de 114px a 70, la separacion entre el boton del tema y salir
 * paso de 10px a 588, y las celdas del estudiante quedaron en 75px con su
 * rotulo entero.
 *
 * Lo que SI se vigila aqui es que las marcas de las que cuelga todo eso sigan
 * puestas —la misma idea que `ToqueEnElTelefonoTest`— y que la logica de rol no
 * se haya perdido al cambiar el marcado, que es lo que un rediseno del menu
 * rompe sin que nada falle.
 */
class MenuComoBarraTest extends TestCase
{
    use RefreshDatabase;

    private function crearAdministrador(): User
    {
        $user = User::create(['username' => 'jefa', 'password' => 'demo1234', 'activo' => true]);

        Perfil::create([
            'user_id' => $user->id,
            'rol' => 'administrador',
            'nombre_completo' => 'Jefa Ruiz',
            'fecha_nacimiento' => '1990-04-04',
            'telefono' => '3001112233',
        ]);

        return $user;
    }

    private function crearEstudiante(): User
    {
        $user = User::create(['username' => 'ana', 'password' => 'demo1234', 'activo' => true]);

        Perfil::create([
            'user_id' => $user->id,
            'rol' => 'estudiante',
            'nombre_completo' => 'Ana Gomez',
            'fecha_nacimiento' => '2000-05-05',
            'telefono' => '3002223344',
        ]);

        return $user;
    }

    /** El marcado de la barra, que es de lo que cuelga todo el CSS. */
    public function test_el_menu_trae_la_barra_con_sus_rotulos(): void
    {
        $html = (string) $this->actingAs($this->crearAdministrador())
            ->get(route('gestion-inicio'))->assertOk()->getContent();

        $this->assertStringContainsString('class="nav-barra"', $html);
        $this->assertStringContainsString('class="nav-raya"', $html);
        $this->assertStringContainsString('class="nav-salir"', $html);
        $this->assertStringContainsString('class="nav-texto"', $html);

        // El boton del tema queda FUERA de la barra, y eso es lo que los separa:
        // en el telefono la barra baja al borde inferior y el tema se queda en la
        // cabecera.
        $this->assertStringContainsString('tema-forma nav-tema', $html);
    }

    /** «Salir» ya no lleva el nombre del usuario, que es lo tercero que se pidio. */
    public function test_salir_ya_no_lleva_el_nombre_del_usuario(): void
    {
        $admin = $this->crearAdministrador();

        $html = (string) $this->actingAs($admin)
            ->get(route('gestion-inicio'))->assertOk()->getContent();

        $this->assertStringContainsString('>Salir<', $html);
        $this->assertStringNotContainsString('Cerrar sesión ('.$admin->username, $html);
    }

    /**
     * LA HOJA LE RESERVA EL ALTO A LA BARRA, y sin eso los ultimos pixeles del
     * contenido quedan DEBAJO de ella.
     *
     * La barra es `fixed`, asi que no ocupa sitio en el flujo: `main` acaba con
     * 40px de margen y la barra mide 59. En una pantalla larga eso es la ultima
     * accion escondida, que es un fallo que este proyecto ya pago dos veces, y
     * NO se ve en escritorio, donde la barra vive en la cabecera.
     */
    public function test_la_hoja_le_reserva_el_alto_a_la_barra(): void
    {
        $css = (string) file_get_contents(public_path('css/app.css'));

        $movil = strpos($css, '@media (max-width: 640px)');
        $fija = strpos($css, 'position: fixed; inset: auto 0 0 0');
        $reserva = strpos($css, 'padding-bottom: 66px');

        $this->assertNotFalse($movil);
        $this->assertNotFalse($fija, 'la barra ya no se fija al borde inferior');
        $this->assertNotFalse($reserva, 'nadie le reserva el alto a la barra fija');

        // Las dos viven DENTRO de la consulta del telefono: fuera de ella, la
        // barra se fijaria tambien en escritorio y la reserva dejaria un hueco
        // blanco al pie de todas las pantallas anchas.
        $this->assertGreaterThan($movil, $fija, 'la barra fija se salio de la consulta del telefono');
        $this->assertGreaterThan($movil, $reserva, 'la reserva se salio de la consulta del telefono');
    }

    /**
     * EL ESTUDIANTE TIENE TRES DESTINOS Y «PROMOTORIAS» NO ESTA ENTRE ELLOS.
     *
     * Esta es la prueba que sostiene la decision del 12/09: con cinco destinos
     * mas «Salir» eran SEIS celdas en 375px —62px cada una— y tres rotulos se
     * recortaban, «Promotorias» el primero porque pide 65. Se saco de la barra a
     * «Mis matriculas», que es donde se entra a matricularse.
     *
     * Se afirman las DOS cosas: que estan los tres que quedan y que el que se
     * fue NO esta. Sin la segunda mitad, devolverlo al menu daria verde igual y
     * los rotulos volverian a recortarse sin que nada fallara.
     */
    public function test_el_estudiante_no_tiene_promotorias_en_el_menu(): void
    {
        $html = (string) $this->actingAs($this->crearEstudiante())
            ->get(route('mis-matriculas'))->assertOk()->getContent();

        // SE COMPARA LA LISTA EXACTA, y no que «Promotorias» no aparezca: asi
        // enrojece tambien el dia que alguien anada un destino CUALQUIERA, que
        // es lo que de verdad rompe el reparto. Cinco celdas ya no caben.
        //
        // Y no se compara por URL: `promotorias-disponibles` es la ruta RAIZ,
        // asi que su direccion es el prefijo de todas las demas y una asercion
        // de subcadena da falso positivo. Costo una prueba roja al escribirla.
        $this->assertSame(
            ['Matrículas', 'Clases', 'Compañeros', 'Mi perfil', 'Salir'],
            $this->rotulosDeLaBarra($html),
            'cambio la lista de la barra: a 375px solo caben cuatro destinos con su rotulo entero'
        );
    }

    /**
     * HORARIOS ES EL CUARTO DESTINO DE DIRECCION (27/09/2026, pedido del
     * usuario: es lo que mas se consulta cuando alguien llega a preguntar).
     *
     * Lista EXACTA por lo mismo que la del estudiante: con este, administrador
     * y director llegan al tope de cuatro, y un quinto tiene que enrojecer. El
     * profesor no lo lleva porque la ruta no le deja entrar: un boton que rebota
     * es el fallo que ya costo con la cuenta de institucion externa.
     */
    public function test_direccion_tiene_horarios_en_la_barra_y_el_profesor_no(): void
    {
        $admin = $this->crearAdministrador();

        $this->assertSame(
            ['Panel', 'Gestión', 'Horarios', 'Mi perfil', 'Salir'],
            $this->rotulosDeLaBarra((string) $this->actingAs($admin)->get(route('mi-perfil'))->getContent()),
            'cambio la barra de direccion: a 375px solo caben cuatro destinos con su rotulo entero'
        );

        // El director, igual; el profesor, sin Horarios ni Gestion.
        foreach (['director' => ['Panel', 'Gestión', 'Horarios', 'Mi perfil', 'Salir'], 'profesor' => ['Panel', 'Mi perfil', 'Salir']] as $rol => $esperados) {
            $admin->perfil->update(['rol' => $rol]);

            $this->assertSame(
                $esperados,
                $this->rotulosDeLaBarra((string) $this->actingAs($admin->refresh())->get(route('mi-perfil'))->getContent()),
                "la barra del {$rol}"
            );
        }
    }

    /** Y su puerta esta en «Mis matriculas», que es donde se fue. */
    public function test_la_puerta_a_promotorias_vive_en_mis_matriculas(): void
    {
        $html = (string) $this->actingAs($this->crearEstudiante())
            ->get(route('mis-matriculas'))->assertOk()->getContent();

        $this->assertStringContainsString(route('promotorias-disponibles'), $html);
        $this->assertStringContainsString('Ver promotorías disponibles', $html);
    }

    /**
     * Y respeta el interruptor de la institucion, igual que cuando vivia en el
     * menu: una entidad que matricula en ventanilla lo apaga, y entonces este
     * enlace no puede quedar apuntando a una pantalla cerrada.
     */
    public function test_apagar_el_catalogo_se_lleva_tambien_ese_enlace(): void
    {
        $config = ConfiguracionInstitucion::actual();
        $config->promotorias_visibles_para_estudiantes = false;
        $config->save();

        $html = (string) $this->actingAs($this->crearEstudiante())
            ->get(route('mis-matriculas'))->assertOk()->getContent();

        $this->assertStringNotContainsString('Ver promotorías disponibles', $html);
    }

    /**
     * Los rotulos de la barra, en orden.
     *
     * Se acota al `<nav>` a proposito: fuera de el hay enlaces con los mismos
     * nombres —el de «Ver promotorias disponibles» que ahora vive en el cuerpo,
     * por ejemplo— y contarlos aqui haria pasar esta prueba por el motivo
     * equivocado.
     *
     * @return list<string>
     */
    private function rotulosDeLaBarra(string $html): array
    {
        $i = strpos($html, '<nav>');
        $this->assertNotFalse($i, 'la pagina no trae <nav>');
        $j = strpos($html, '</nav>', $i);
        $this->assertNotFalse($j);

        preg_match_all(
            '/<span class="nav-texto">([^<]+)<\/span>/',
            substr($html, $i, $j - $i),
            $coincidencias
        );

        return array_map('trim', $coincidencias[1]);
    }
}
