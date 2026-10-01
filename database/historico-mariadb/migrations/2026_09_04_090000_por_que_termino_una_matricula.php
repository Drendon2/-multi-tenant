<?php

/**
 * Por que una matricula quedo retirada.
 *
 * EL PROBLEMA, del 04/09/2026: 'retirada' es el desenlace de cuatro cosas muy
 * distintas y no se distinguian entre si. Al profesor que RECHAZA una solicitud
 * y al estudiante que se echa atras les queda exactamente la misma fila, y el
 * codigo ya lo tenia anotado —«queda 'retirada', el mismo estado que si el
 * estudiante se hubiera echado atras», en `PanelController::rechazarLote()`—.
 *
 * Para quien pidio entrar eso significa que abre «Mis matriculas», lee
 * «Retirada» y no sabe si le dijeron que no, si se cayo su solicitud o si hubo
 * un error. Y como el catalogo excluye las retiradas, la promotoria le volvia a
 * aparecer con el boton «Matricularme»: reintentar a ciegas era el camino que le
 * ofrecia la aplicacion.
 *
 * NO ES UN ESTADO NUEVO, y eso es deliberado. Un sexto valor en `estado`
 * obligaria a revisar las casi cincuenta consultas que filtran por los que ya
 * hay. Esta columna EXPLICA un estado que ya existe: nada que hoy pregunte por
 * 'retirada' cambia de respuesta.
 *
 * NULA significa «no se registro», que es lo que les toca a las filas que ya
 * existen: de una retirada vieja no hay forma de saber por cual de los cuatro
 * caminos llego, y ponerle un motivo inventado seria peor que dejarla muda. Se
 * muestran como hasta hoy.
 *
 * Y se BORRA al revivir una matricula (ver `FichaController`): una fila que
 * vuelve a estar activa no arrastra el motivo por el que un dia dejo de estarlo.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matriculas', function (Blueprint $table) {
            $table->string('motivo_retiro', 20)->nullable()->after('estado');
        });
    }

    public function down(): void
    {
        Schema::table('matriculas', function (Blueprint $table) {
            $table->dropColumn('motivo_retiro');
        });
    }
};
