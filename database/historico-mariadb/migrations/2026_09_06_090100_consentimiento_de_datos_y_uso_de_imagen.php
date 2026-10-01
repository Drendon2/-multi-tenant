<?php

/**
 * El consentimiento firmado: un papel mas de los que se piden, pero el unico
 * que el sistema SABE IMPRIMIR.
 *
 * Los demas requeridos son ranuras vacias donde el estudiante sube lo que ya
 * tiene —la cedula, el certificado de EPS—. Este no existe hasta que alguien lo
 * imprime, lo firma y lo devuelve, asi que a su ranura hay que colgarle ademas
 * una DESCARGA. Eso es todo lo que significa `plantilla`: «este requerido tiene
 * un formato que genera el sistema».
 *
 * ES UNA COLUMNA Y NO UN NOMBRE RESERVADO. La tentacion era reconocerlo por
 * como se llama, y no sirve: el nombre de un requerido lo edita la entidad
 * —para eso se hizo configurable el 05/09— y el dia que alguien le ponga
 * «Autorizacion de datos (2027)» el boton de descargar desaparece sin que nada
 * falle. La columna sobrevive a que lo renombren.
 *
 * Vacia en todos los demas, que es lo corriente. `DocumentoRequerido::conFormato()`
 * es quien la lee.
 *
 * SE SIEMBRA SOLO SI LA BASE ESTA EN USO, y esa condicion es la trampa que ya
 * costo una vez: `php artisan instalar` se niega a montar una institucion si la
 * base «ya tiene datos», y entre lo que cuenta estan los documentos requeridos.
 * Sembrando sin mirar, cualquier instalacion nueva nacia con un documento
 * puesto y el instalador se plantaba con «esto no parece una instalacion nueva»
 * ANTES de escribir nada — o sea que rompia el montaje de cualquier entidad
 * nueva, que es justo para lo que existe este producto. Donde no hay nadie no
 * hay costumbre que preservar, y el instalador ya lo crea por su cuenta.
 */

use App\Models\DocumentoRequerido;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documentos_requeridos', function (Blueprint $table) {
            $table->string('plantilla', 30)->default('')->after('descripcion');
        });

        // Ver el cabecero: en una base recien creada NO se siembra nada.
        if (! DB::table('users')->exists()) {
            return;
        }

        $ahora = now();

        // `insertOrIgnore` y no `insert`: `nombre` es unico, y una entidad puede
        // haber creado ya a mano un requerido llamado igual. Si lo hizo, se le
        // marca la plantilla mas abajo en vez de reventar la migracion.
        DB::table('documentos_requeridos')->insertOrIgnore([
            'nombre' => DocumentoRequerido::CONSENTIMIENTO,
            'descripcion' => 'Descárgalo, fírmalo y súbelo. Si eres menor de edad lo firma tu acudiente.',
            'plantilla' => DocumentoRequerido::FORMATO_CONSENTIMIENTO,
            'obligatorio' => true,
            'activo' => true,
            // Detras del documento de identidad, que viene con orden 0.
            'orden' => 1,
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ]);

        DB::table('documentos_requeridos')
            ->where('nombre', DocumentoRequerido::CONSENTIMIENTO)
            ->update(['plantilla' => DocumentoRequerido::FORMATO_CONSENTIMIENTO]);
    }

    /**
     * NO borra el requerido ni lo entregado.
     *
     * Revertir el esquema es una operacion tecnica; tirar los consentimientos
     * que la gente ya firmo y subio es perder la prueba de que autorizaron. Lo
     * unico que se va es la columna, y con ella el boton de descargar el
     * formato: la ranura sigue ahi con sus archivos dentro.
     */
    public function down(): void
    {
        Schema::table('documentos_requeridos', function (Blueprint $table) {
            $table->dropColumn('plantilla');
        });
    }
};
