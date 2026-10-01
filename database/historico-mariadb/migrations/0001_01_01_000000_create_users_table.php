<?php

/**
 * Cuentas de acceso — el equivalente de `django.contrib.auth.models.User`.
 *
 * Se aparta de la tabla `users` que trae Laravel por defecto, y en tres puntos
 * concretos, porque el sistema original no autentica por correo:
 *
 * - `username` en vez de `email` como credencial. El original crea las cuentas
 *   con `User.objects.create_user(username=..., password=...)` y nunca toca el
 *   correo; pedirlo aquí obligaria a inventarse uno por cada estudiante que se
 *   inscribe, muchos de ellos menores que no tienen.
 * - `email` queda opcional. Se conserva la columna porque recuperar una clave
 *   algun dia lo va a necesitar, pero hoy nada la escribe.
 * - Sin `name`. El nombre de la persona vive en `perfiles.nombre_completo`,
 *   junto al resto de sus datos, y tenerlo en dos sitios solo daria pie a que
 *   se separen.
 *
 * `activo` es el `is_active` de Django: lo alterna Gestion → Usuarios para
 * cerrarle el paso a una cuenta sin borrarla (de ella cuelgan matriculas,
 * asistencias e historial).
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('username', 150)->unique();
            $table->string('email')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->boolean('activo')->default(true);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
