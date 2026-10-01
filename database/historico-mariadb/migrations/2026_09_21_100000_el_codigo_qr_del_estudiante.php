<?php

/**
 * El codigo QR con el que a un estudiante se le pasa lista sin que teclee nada.
 *
 * NACE VACIO Y SE LLENA AL PEDIRLO POR PRIMERA VEZ, en vez de sembrarse aqui
 * para las 885 personas que ya existen. No es pereza: el token es el unico dato
 * de esta columna y sembrarlo en la migracion obliga a generar 885 valores
 * aleatorios dentro de una transaccion de esquema, cuando la mayoria no va a
 * pedir su carne nunca. `Perfil::codigoQr()` lo crea la primera vez que alguien
 * lo mira, y desde entonces es el mismo. Por eso la columna es NULLABLE y no
 * tiene valor por defecto: ahi "vacio" significa "todavia no lo ha pedido
 * nadie", que es un estado legitimo y no un dato a medias.
 *
 * NO ES UNA CREDENCIAL. Con el token no se entra al sistema, no se ve una
 * ficha y no se descarga un papel: lo unico que hace es decir QUIEN es quien se
 * presenta, y solo dentro de una lista de clase que ya tiene abierta el
 * profesor que la dicta. Es el equivalente de un carne de cartulina, con la
 * misma virtud —sirve aunque su dueno haya olvidado usuario y contrasena— y el
 * mismo riesgo: quien se lleve la cartulina puede hacerse pasar por su dueno
 * ante un profesor que no mire la foto.
 *
 * ES ANONIMO A PROPOSITO: 16 caracteres aleatorios y nada del documento, el
 * nombre ni el usuario. Un QR se fotografia de lejos y acaba pegado en una
 * pared; lo que lleve impreso encima deja de ser privado. Ademas se puede
 * cambiar sin tocar ningun otro dato el dia que alguien pierda su carne.
 *
 * `origen` EN LA CONFIRMACION es la otra mitad. Desde hoy una clase tambien
 * queda confirmada cuando el profesor lee el QR del estudiante en el salon, y
 * eso NO es lo mismo que el estudiante pulsando el boton desde su sesion: en el
 * segundo caso da fe quien no tiene nada que ganar, y en el primero el papel lo
 * sostiene quien registro la clase. Las dos cuentan igual —es la decision del
 * usuario del 21/09/2026, tomada con la objecion delante— pero tienen que poder
 * distinguirse despues, porque una cifra que mezcla las dos no se puede
 * auditar. NULO es legitimo: son las confirmaciones anteriores a hoy.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('perfiles', function (Blueprint $table) {
            // UNICO, que es lo que convierte "coincide con este token" en "es
            // esta persona". Sin el indice, dos perfiles con el mismo valor
            // dejarian el escaneo eligiendo al azar entre dos nombres.
            $table->char('codigo_qr', 16)->nullable()->unique()->after('foto_perfil');
        });

        Schema::table('confirmaciones_clase', function (Blueprint $table) {
            $table->string('origen', 12)->nullable()->after('fecha');
        });
    }

    public function down(): void
    {
        Schema::table('perfiles', function (Blueprint $table) {
            // El indice unico se va con la columna en MariaDB, pero se nombra
            // aqui igual: dejarlo implicito es lo que hace que un `down` falle
            // en el motor de al lado.
            $table->dropUnique(['codigo_qr']);
            $table->dropColumn('codigo_qr');
        });

        Schema::table('confirmaciones_clase', function (Blueprint $table) {
            $table->dropColumn('origen');
        });
    }
};
