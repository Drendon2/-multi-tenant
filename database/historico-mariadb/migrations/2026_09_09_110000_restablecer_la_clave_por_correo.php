<?php

/**
 * Los enlaces vivos para restablecer una contrasena.
 *
 * ─── POR QUE NO SE USA `password_reset_tokens` ─────────────────────────────
 *
 * Esa tabla la trae Laravel y esta ahi desde la primera migracion, sin que nada
 * la escriba. Su clave primaria es el CORREO, porque el resto del framework da
 * por hecho que el correo es la identidad de la cuenta. Aqui no lo es:
 *
 * - Se entra con `username`, y el correo es OPCIONAL (ver la migracion de
 *   `users`). En produccion lo tienen 32 personas de 885.
 * - Nada impide que dos cuentas compartan correo, y es el caso corriente: una
 *   madre con tres hijos inscritos pone el suyo en los tres. Con el correo de
 *   clave primaria, la segunda peticion pisa el enlace de la primera y una de
 *   esas tres cuentas no se puede recuperar nunca.
 *
 * Asi que la fila cuelga del USUARIO, que es la identidad de verdad de este
 * sistema. La tabla de Laravel se queda donde esta, vacia: quitarla es una
 * migracion que no arregla nada y que rompe a quien un dia use el broker.
 *
 * ─── UNA FILA POR CUENTA ───────────────────────────────────────────────────
 *
 * `user_id` es la clave primaria, asi que pedir un enlace nuevo INVALIDA el
 * anterior. Es lo que uno espera —el ultimo correo es el que sirve— y ademas
 * evita que quien pulse el boton veinte veces deje veinte puertas abiertas.
 *
 * ─── EL TOKEN SE GUARDA EN SHA-256, NO EN BCRYPT ───────────────────────────
 *
 * Y eso NO es una rebaja. Bcrypt existe para lo que las personas eligen, que
 * tiene poca entropia y hay que encarecer de adivinar. Este token son 32 bytes
 * del generador seguro del sistema: no se adivina por fuerza bruta ni con todo
 * el tiempo del mundo, asi que lo unico que hace falta es que lo guardado no
 * sirva de nada si alguien lee la tabla. Un digest sin sal ademas se puede
 * BUSCAR, que es justo lo que hace falta aqui: el enlace del correo no lleva
 * quien es —solo el token— y con bcrypt habria que recorrer todas las filas
 * comprobandolas una a una.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restablecimientos_clave', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();

            // 64 caracteres exactos: es lo que mide un SHA-256 en hexadecimal.
            // Unico porque es por donde se busca — el enlace del correo no
            // lleva nada mas.
            $table->char('token', 64)->unique();

            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restablecimientos_clave');
    }
};
