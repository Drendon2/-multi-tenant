<?php

/**
 * El servidor de correo de la entidad, editable desde Gestion → Institucion.
 *
 * ─── POR QUE NO SE QUEDA SOLO EN EL `.env` ─────────────────────────────────
 *
 * Desde el 09/09/2026 el sistema manda un correo: el enlace de «olvide mi
 * contrasena». Y el `.env` es el peor sitio posible para eso EN ESTE PRODUCTO,
 * que se instala en casas ajenas: para encenderlo hay que entrar por SSH al
 * hosting, que es justo lo que no se hace. Y cuando no se hace, la funcion no
 * falla, MIENTE — con `MAIL_MAILER=log` el enlace se escribe en un archivo de
 * registro y la persona ve la misma pantalla tranquilizadora, porque esa
 * pantalla contesta lo mismo pase lo que pase para no delatar quien tiene
 * cuenta.
 *
 * Aqui las escribe el administrador de la entidad, sin consola, y la pantalla
 * le dice si funcionan.
 *
 * ─── EL `.env` SIGUE VALIENDO, Y ESO NO ES INDECISION ──────────────────────
 *
 * Manda lo que haya aqui; si no hay nada, lo del `.env`. Las dos razones son
 * concretas: la produccion de hoy ya se configura por ahi y un despliegue no
 * puede apagarle el correo, y quien monte esto en un servidor propio con un
 * relay interno prefiere el archivo. La pantalla dice cual de los dos esta en
 * uso, que es lo que evita que esto se vuelva un misterio.
 *
 * ─── LA CONTRASENA ─────────────────────────────────────────────────────────
 *
 * Va CIFRADA (cast `encrypted` del modelo, con `APP_KEY`), asi que una copia de
 * la base robada no la entrega. Y es NULABLE y no '' a proposito: el cast
 * revienta al intentar descifrar una cadena vacia, asi que «no hay clave» tiene
 * que ser null. Por eso —y solo por eso— es la unica de estas columnas que NO
 * va en el `$attributes` del modelo. Es el mismo caso de `politica_datos`.
 *
 * ─── NO HAY COLUMNA PARA EL REMITENTE, y es deliberado ─────────────────────
 *
 * El «De:» es el propio `correo_usuario`. Casi todos los proveedores rechazan
 * un remitente distinto del buzon autenticado, asi que un campo aparte solo
 * sirve para que alguien escriba dos cosas distintas y se pase la tarde
 * buscando por que no llegan los correos. El NOMBRE que acompana a esa
 * direccion es `nombre_institucion`, que ya existe.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuracion_institucion', function (Blueprint $table) {
            $table->string('correo_servidor', 160)->default('')->after('consentimiento_menor');
            $table->unsignedSmallInteger('correo_puerto')->default(465)->after('correo_servidor');

            // `smtps` es SSL directo (puerto 465) y `smtp` es STARTTLS (587).
            // Son los dos nombres que entiende Laravel en `MAIL_SCHEME`; se
            // guarda el mismo vocabulario para no traducir en dos sitios.
            $table->string('correo_cifrado', 10)->default('smtps')->after('correo_puerto');

            $table->string('correo_usuario', 160)->default('')->after('correo_cifrado');

            // TEXT y NULABLE: lo que se guarda es el cifrado de la contrasena,
            // que es bastante mas largo que ella, y «no hay» tiene que ser null
            // porque descifrar '' lanza.
            $table->text('correo_clave')->nullable()->after('correo_usuario');
        });
    }

    public function down(): void
    {
        Schema::table('configuracion_institucion', function (Blueprint $table) {
            $table->dropColumn([
                'correo_servidor',
                'correo_puerto',
                'correo_cifrado',
                'correo_usuario',
                'correo_clave',
            ]);
        });
    }
};
