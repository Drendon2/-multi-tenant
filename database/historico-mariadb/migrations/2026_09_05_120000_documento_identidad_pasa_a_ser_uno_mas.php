<?php

/**
 * El documento de identidad deja de ser un caso especial del codigo.
 *
 * Hasta el 05/09/2026 la copia de la cedula o el registro civil vivia en su
 * propia columna —`datos_estudiante.copia_documento`— y se pedia SIEMPRE, sin
 * que la entidad pudiera decidir nada: ni si se pide, ni como se llama, ni si
 * es obligatorio. La pantalla de Institucion lo decia con todas las letras
 * («se pide siempre y no se configura aqui»), y esa linea era el problema.
 *
 * Lo pidio el usuario el 05/09/2026 con una razon de producto: esto se vende a
 * otras instituciones, y cada una pide los papeles que le exige su propia
 * norma. Un documento quemado en el esquema es justo lo que no puede haber.
 *
 * QUE HACE ESTA MIGRACION:
 *
 * 1. Crea el requerido «Documento de identidad» si no existe, en primer lugar
 *    del orden — pero SOLO si la base esta en uso. Se crea aunque no haya
 *    ninguna copia subida: la entidad que ya lo estaba pidiendo tiene que seguir
 *    pidiendolo tras migrar, y apagarlo es una decision suya y no un efecto de
 *    actualizar el sistema. En una base vacia no se siembra nada, y el porque
 *    esta escrito en el propio `up()`: sembrar ahi rompia `php artisan instalar`.
 * 2. Copia cada `copia_documento` no vacia a `documentos_estudiante`, apuntando
 *    a ese requerido. NO mueve archivos: la ruta guardada sigue siendo valida y
 *    el disco no se toca, que es lo unico irreversible que habria aqui.
 * 3. Quita la columna.
 *
 * EL `down()` LA DEVUELVE CON SUS DATOS, y por eso el paso 2 conserva la ruta
 * tal cual en vez de reescribirla: revertir es copiar de vuelta y ya.
 *
 * OJO CON `insertOrIgnore` DEL PASO 2: la tabla destino tiene un unico por
 * (estudiante, requerido). Si esta migracion se corriera dos veces —o si
 * alguien hubiera creado a mano un requerido con ese nombre y le hubiera subido
 * algo—, un `insert` normal reventaria a mitad y dejaria la mitad de las filas
 * copiadas y la columna todavia puesta.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** El nombre es tambien la clave: `documentos_requeridos.nombre` es unico. */
    private const NOMBRE = 'Documento de identidad';

    public function up(): void
    {
        $ahora = now();

        // ¿HAY ALGUIEN USANDO ESTO? En una base recien creada NO se siembra
        // nada, y esa condicion no es un detalle: `php artisan instalar` se
        // niega a montar una institucion si la base «ya tiene datos», y una de
        // las cosas que cuenta son los documentos requeridos. Sembrando aqui,
        // cualquier instalacion nueva quedaba con un documento puesto y el
        // instalador se plantaba con «esto no parece una instalacion nueva»
        // ANTES de escribir una sola linea. O sea que esta migracion rompia el
        // montaje de cualquier entidad nueva, que es justo para lo que existe
        // este producto. Se vio corriendo la suite.
        //
        // Y conceptualmente es lo correcto: esta migracion esta para que quien
        // YA venia pidiendo la copia del documento la siga pidiendo. Donde no
        // hay nadie no hay costumbre que preservar, y el instalador ya trae
        // «Documento de identidad» en su propia lista.
        $enUso = DB::table('users')->exists();

        if (! $enUso) {
            Schema::table('datos_estudiante', function (Blueprint $table) {
                $table->dropColumn('copia_documento');
            });

            return;
        }

        $id = DB::table('documentos_requeridos')->where('nombre', self::NOMBRE)->value('id');

        if ($id === null) {
            $id = DB::table('documentos_requeridos')->insertGetId([
                'nombre' => self::NOMBRE,
                'descripcion' => 'Cédula, tarjeta de identidad o registro civil. Solo la ve la administración.',
                'obligatorio' => true,
                'activo' => true,
                // Delante de los demas: es el que ya se venia pidiendo.
                'orden' => 0,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);
        }

        // En tandas: hay instalaciones con miles de estudiantes y no hace falta
        // traerlos todos a memoria para copiar dos columnas.
        DB::table('datos_estudiante')
            ->select('id', 'copia_documento')
            ->where('copia_documento', '!=', '')
            ->orderBy('id')
            ->chunk(500, function ($filas) use ($id, $ahora) {
                DB::table('documentos_estudiante')->insertOrIgnore(
                    $filas->map(fn ($fila) => [
                        'datos_estudiante_id' => $fila->id,
                        'requerido_id' => $id,
                        'archivo' => $fila->copia_documento,
                        // No se sabe cuando se subio de verdad: la columna vieja
                        // no guardaba fecha. Se pone la de la migracion, que es
                        // lo unico honesto — no una inventada hacia atras.
                        'subido' => $ahora,
                        'created_at' => $ahora,
                        'updated_at' => $ahora,
                    ])->all()
                );
            });

        Schema::table('datos_estudiante', function (Blueprint $table) {
            $table->dropColumn('copia_documento');
        });
    }

    public function down(): void
    {
        Schema::table('datos_estudiante', function (Blueprint $table) {
            $table->string('copia_documento', 255)->default('');
        });

        $id = DB::table('documentos_requeridos')->where('nombre', self::NOMBRE)->value('id');

        if ($id === null) {
            return;
        }

        DB::table('documentos_estudiante')
            ->where('requerido_id', $id)
            ->orderBy('id')
            ->chunk(500, function ($filas) {
                foreach ($filas as $fila) {
                    DB::table('datos_estudiante')
                        ->where('id', $fila->datos_estudiante_id)
                        ->update(['copia_documento' => $fila->archivo]);
                }
            });

        DB::table('documentos_estudiante')->where('requerido_id', $id)->delete();
        DB::table('documentos_requeridos')->where('id', $id)->delete();
    }
};
