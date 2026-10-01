<?php

/**
 * Que la institucion decida si el correo es obligatorio.
 *
 * Hasta hoy el correo era opcional SIEMPRE, y con razon: buena parte de quien
 * se inscribe aqui son menores que no tienen uno propio, y exigirlo obligaria a
 * inventarse uno por cada uno —que es justo lo que la tabla evita autenticando
 * por `username`—. Esa sigue siendo la decision por defecto.
 *
 * Lo que cambia es que deja de ser una decision del CODIGO para ser una de la
 * ENTIDAD. Este producto se vende a otras instituciones (ver PRODUCT.md), y una
 * escuela de adultos tiene todo el derecho a exigir un correo al que escribir.
 *
 * NACE APAGADO, y eso no es timidez: en produccion hay 32 correos guardados de
 * 885 personas. Encendido de fabrica, la primera vez que un administrador
 * abriera la ficha de cualquiera de las otras 853 no podria guardarla. Que lo
 * encienda quien quiera exigirlo, sabiendo lo que arrastra.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuracion_institucion', function (Blueprint $tabla) {
            $tabla->boolean('correo_obligatorio')
                ->default(false)
                ->after('recordar_encuesta');
        });
    }

    public function down(): void
    {
        Schema::table('configuracion_institucion', function (Blueprint $tabla) {
            $tabla->dropColumn('correo_obligatorio');
        });
    }
};
