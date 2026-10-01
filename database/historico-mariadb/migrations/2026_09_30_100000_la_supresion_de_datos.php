<?php

/**
 * Cuando se suprimieron los datos personales de alguien (Ley 1581, art. 8 e).
 *
 * Hasta hoy una cuenta con matriculas no se podia borrar: solo desactivar. Eso
 * dejaba sin respuesta a quien pide que se borren sus datos, que es un derecho
 * del titular. Borrar la fila no servia —`matriculas.estudiante_id` es CASCADE y
 * se llevaria las cifras de todos los periodos pasados—, asi que se ANONIMIZA:
 * el perfil se queda como «Persona suprimida», sin nada que la identifique, y
 * sus matriculas y asistencias siguen contando (decision del usuario del
 * 30/09/2026). Ver `App\Support\SupresionDeDatos`.
 *
 * La columna existe porque despues de anonimizar ya no queda NINGUN dato que
 * diga que esa fila no es una persona corriente, y hay pantallas que tienen que
 * saberlo: la lista de Usuarios no la enseña, sus botones se apagan y las
 * fichas incompletas no la piden. Adivinarlo por el nombre dejaria la regla
 * colgando de un rotulo que alguien puede teclear en su propio perfil.
 *
 * NULO es lo corriente: nadie suprimido.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('perfiles', function (Blueprint $table) {
            $table->dateTime('suprimido_en')->nullable()->after('codigo_qr');
        });
    }

    public function down(): void
    {
        Schema::table('perfiles', function (Blueprint $table) {
            $table->dropColumn('suprimido_en');
        });
    }
};
