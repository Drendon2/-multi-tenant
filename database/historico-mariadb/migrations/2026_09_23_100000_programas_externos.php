<?php

/**
 * PROGRAMAS EXTERNOS: lo que un profesor de la casa va a dictar A OTRA
 * INSTITUCION —una escuela rural, un colegio, una fundacion—.
 *
 * POR QUE NO ES UNA TABLA NUEVA. Un programa externo tiene lista de asistentes,
 * sesiones y asistencia, que es exactamente lo que ya tiene una actividad. Una
 * tercera vertical de asistencia habria sido la TERCERA copia de las mismas
 * tres marcas y del mismo «no hay fila» —ver la cabecera de
 * `App\Support\PaseDeLista`, que ya cuenta lo caro que sale mantener dos—. Asi
 * que esto entra como un CUARTO TIPO de `actividades`, y lo que se anade son
 * unas columnas y una tabla, no un modulo paralelo.
 *
 * LO QUE SI LO SEPARA DE LOS OTROS TRES TIPOS, y hay que tenerlo delante al
 * leer `Actividad`: a un curso, un taller o un grupo de proyeccion SE ENTRA POR
 * UN ENLACE que alguien comparte. A un programa externo NO se entra: la lista
 * la escribe el profesor alli mismo, con el nombre y la edad, porque quienes
 * estan en ese salon son los estudiantes de la OTRA institucion y no tienen por
 * que conocer este sistema. Por eso `InscripcionActividadController` cierra la
 * puerta publica por TIPO, y no basta con que el enlace este cerrado.
 *
 * QUIEN DA FE. La verificacion de una clase de promotoria la dan los
 * estudiantes (`confirmaciones_clase`); aqui no la pueden dar, asi que la da un
 * FUNCIONARIO de la institucion que recibe la clase. Esa persona es una cuenta
 * del sistema con el rol `institucion_externa` y no ve nada mas que sus clases.
 * De ahi que la verificacion viva en `sesiones_actividad` y no en una tabla
 * aparte: es UNA firma por sesion —la de la institucion— y no muchas como en
 * una clase de promotoria, donde lo que hace fuerza es el numero.
 *
 * `verificacion_origen` ES LA MISMA DECISION QUE `confirmaciones_clase.origen`,
 * y por la misma razon. Hay dos caminos: el funcionario entrando a su cuenta
 * (`propia`) y el profesor leyendo el QR de la institucion al terminar la clase
 * alli (`qr`). Los dos cuentan, pero NUNCA se colapsan las dos cifras: en el
 * segundo quien sostiene el papel es el profesor, que es justo a quien la
 * verificacion vigila, y una media que los mezcla no se puede auditar.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * La otra institucion, con su cuenta.
         *
         * `perfil_id` es UNICO: una institucion, una cuenta. No es una
         * comodidad, es lo que hace que «quien dio fe» signifique algo — con
         * dos cuentas para la misma escuela, retirar una verificacion desde una
         * y volver a ponerla desde la otra seria indistinguible de dos personas
         * que no se ponen de acuerdo.
         *
         * RESTRICT hacia `perfiles`: borrar la cuenta dejaria la ficha de la
         * institucion sin nadie que pueda verificar nada. Se desactiva, como
         * cualquier otra cuenta con historial.
         */
        Schema::create('instituciones_externas', function (Blueprint $table) {
            $table->id();
            // El nombre de la ENTIDAD. El de la persona vive en su perfil: son
            // dos datos distintos y la escuela sobrevive al funcionario.
            $table->string('nombre', 120);
            $table->string('direccion', 160)->nullable();
            // Con la regla de entidad y no la de celular: aqui lo corriente es
            // un fijo de vereda con extension, no un movil de diez digitos. Ver
            // `Reglas::telefonoDeEntidad()`, que ya existe por lo mismo en la
            // configuracion de la casa.
            $table->string('telefono', 40)->nullable();
            $table->foreignId('perfil_id')->unique()->constrained('perfiles')->restrictOnDelete();
            $table->timestamps();
        });

        DB::statement("
            ALTER TABLE instituciones_externas
            ADD CONSTRAINT nombre_de_institucion_externa_no_vacio
            CHECK (nombre <> '')
        ");

        /*
         * EL QUINTO ROL. Dos cosas, y las dos hacen falta:
         *
         * 1. LA COLUMNA NO DABA. Era `varchar(15)` y el mas largo de los cuatro
         *    que habia era «administrador», de 13; `institucion_externa` son 19.
         *    MariaDB no trunca en silencio con el modo estricto de Laravel —da
         *    un 1406— pero el fallo aparece lejos de aqui, al crear la cuenta.
         *    Se sube a 25, que deja sitio para el siguiente sin volver a tocar
         *    una columna con indice.
         * 2. EL CHECK `rol_valido` NO LO CONOCIA. Se rehace entero porque
         *    MariaDB no sabe ampliar uno.
         *
         * El orden importa: primero se afloja el CHECK, luego se cambia el tipo
         * de la columna y al final se vuelve a poner. Con el CHECK viejo puesto,
         * un `MODIFY` sobre esa columna lo arrastra y lo revalida.
         */
        DB::statement('ALTER TABLE perfiles DROP CONSTRAINT rol_valido');

        Schema::table('perfiles', function (Blueprint $table) {
            $table->string('rol', 25)->default('')->change();

            /*
             * Y LA FECHA DE NACIMIENTO PASA A ADMITIR NULL, que es el cambio de
             * esta migracion que mas lejos llega y conviene leer entero.
             *
             * POR QUE. El funcionario de una institucion externa es un contacto
             * de OTRA entidad, y su fecha de nacimiento no la usa nada aqui: de
             * ese dato cuelgan la minoria de edad, el acudiente obligatorio y el
             * nivel que le toca, y las tres son cosas de un estudiante. Pedirsela
             * seria recoger un dato personal sin finalidad, que es justo lo que
             * la Ley 1581 llama no hacer; inventarsela —un 1990-01-01— seria
             * escribir algo falso en la base para callar a una columna.
             *
             * LO QUE ESTO OBLIGA A SOSTENER, y va escrito porque no se deduce:
             * `Perfil::edad` devuelve ahora `?int` y `es_menor` contesta `false`
             * cuando no se sabe. Sin las dos cosas, «Mi perfil» —que es de TODOS
             * los roles— reventaba al pintar la edad de esta cuenta.
             *
             * LO QUE NO CAMBIA: los formularios siguen exigiendola para todo el
             * mundo. Aqui se afloja la BASE, no la regla; el unico camino que
             * crea un perfil sin ella es el de registrar una institucion externa.
             */
            $table->date('fecha_nacimiento')->nullable()->change();

            /*
             * Y EL TELEFONO, por la misma razon exacta.
             *
             * El de la institucion existe y se pide —vive en
             * `instituciones_externas.telefono`, con la regla de ENTIDAD, que
             * admite un fijo de vereda con extension—. Lo que no se pide es el
             * CELULAR PERSONAL del funcionario, que es lo que guarda esta
             * columna con `Reglas::celular()`: diez digitos exactos del movil de
             * una persona concreta. Son dos datos distintos y solo uno de los
             * dos le hace falta a este sistema.
             *
             * Esta es mas mansa que la de arriba: `telefono` se imprime, no se
             * calcula, asi que un null sale como celda vacia y no revienta nada.
             */
            $table->string('telefono', 15)->nullable()->change();
        });

        DB::statement("
            ALTER TABLE perfiles
            ADD CONSTRAINT rol_valido
            CHECK (rol IN ('administrador', 'director', 'profesor', 'estudiante', 'institucion_externa', ''))
        ");

        Schema::table('actividades', function (Blueprint $table) {
            // NULL para los otros tres tipos, y el CHECK de abajo lo exige al
            // reves para el externo: un programa externo sin institucion no
            // tiene quien le de fe, que es la mitad de lo que esto es.
            $table->foreignId('institucion_id')->nullable()->after('periodo_id')
                ->constrained('instituciones_externas')->restrictOnDelete();
        });

        // El cuarto tipo. Se rehace el CHECK entero porque MariaDB no sabe
        // ampliar uno: se tira y se vuelve a poner con la lista completa.
        DB::statement('ALTER TABLE actividades DROP CONSTRAINT tipo_de_actividad_valido');
        DB::statement("
            ALTER TABLE actividades
            ADD CONSTRAINT tipo_de_actividad_valido
            CHECK (tipo IN ('curso', 'taller', 'proyeccion', 'externo'))
        ");

        // LAS DOS DIRECCIONES A LA VEZ, y hacen falta las dos. Sin la primera
        // mitad se puede crear un programa externo sin institucion —y entonces
        // nadie puede verificarlo, sin que nada falle—; sin la segunda, un
        // taller corriente puede quedar colgado de una escuela que no tiene
        // nada que ver con el, y esa fila luego aparece en la bandeja del
        // funcionario.
        DB::statement("
            ALTER TABLE actividades
            ADD CONSTRAINT institucion_solo_en_programa_externo
            CHECK ((tipo = 'externo') = (institucion_id IS NOT NULL))
        ");

        Schema::table('inscritos_actividad', function (Blueprint $table) {
            // LA EDAD, Y NO LA FECHA DE NACIMIENTO, y es lo unico que se
            // pregunta ademas del nombre. La lista se escribe de pie en un
            // salon ajeno: se pregunta «cuantos anos tienes» y se escribe un
            // numero. Pedir la fecha exacta es pedirle a un nino de ocho anos
            // un dato que no se sabe, y con el la lista se queda a medias.
            //
            // El precio, escrito aqui para que nadie lo descubra tarde: la edad
            // ENVEJECE y la fecha no. A los dos anos este numero miente. Se
            // asume porque lo que se hace con el es decir «hay doce menores de
            // catorce en este grupo» el dia que se escribe, no calcular nada
            // despues; si algun dia hay que calcular, el dato que sirve es
            // `created_at` de la fila junto a este numero.
            $table->unsignedTinyInteger('edad')->nullable()->after('fecha_nacimiento');
        });

        DB::statement('
            ALTER TABLE inscritos_actividad
            ADD CONSTRAINT edad_de_inscrito_creible
            CHECK (edad IS NULL OR (edad > 0 AND edad < 120))
        ');

        // El tercer origen. `enlace` es quien llego por la URL compartida,
        // `en_sesion` quien aparecio el dia de la clase, y `lista` quien entro
        // porque el profesor escribio la lista del sitio —que es como se puebla
        // un programa externo entero—.
        DB::statement('ALTER TABLE inscritos_actividad DROP CONSTRAINT origen_de_inscrito_valido');
        DB::statement("
            ALTER TABLE inscritos_actividad
            ADD CONSTRAINT origen_de_inscrito_valido
            CHECK (origen IN ('enlace', 'en_sesion', 'lista'))
        ");

        Schema::table('sesiones_actividad', function (Blueprint $table) {
            // Las tres NULL mientras nadie firme, que es el estado normal de
            // una clase recien dada. `verificada_en` es la que manda: las otras
            // dos cuentan QUIEN y POR DONDE.
            $table->timestamp('verificada_en')->nullable()->after('iniciada_por_id');
            // NULL al borrar y no RESTRICT, al reves que `iniciada_por_id`: si
            // algun dia se va la cuenta del funcionario, que la clase siga
            // constando como verificada es mas cierto que perder el hecho. Lo
            // que se pierde es a quien preguntarle, no que ocurrio.
            $table->foreignId('verificada_por_id')->nullable()->after('verificada_en')
                ->constrained('perfiles')->nullOnDelete();
            $table->string('verificacion_origen', 10)->nullable()->after('verificada_por_id');
        });

        DB::statement("
            ALTER TABLE sesiones_actividad
            ADD CONSTRAINT origen_de_verificacion_valido
            CHECK (verificacion_origen IS NULL OR verificacion_origen IN ('propia', 'qr'))
        ");

        // Las tres columnas viajan juntas o no viajan: una sesion con fecha de
        // verificacion y sin origen es una firma sin decir de donde salio, que
        // es justo lo que no se puede volver a averiguar despues.
        DB::statement('
            ALTER TABLE sesiones_actividad
            ADD CONSTRAINT verificacion_completa_o_ninguna
            CHECK ((verificada_en IS NULL) = (verificacion_origen IS NULL))
        ');

        // La bandeja del funcionario pregunta siempre lo mismo: las sesiones de
        // sus programas que todavia no ha firmado. Sin este indice eso es un
        // barrido de la tabla entera de sesiones, que crece con toda la casa y
        // no con su escuela.
        Schema::table('sesiones_actividad', function (Blueprint $table) {
            $table->index(['actividad_id', 'verificada_en'], 'sesiones_por_verificar');
        });
    }

    public function down(): void
    {
        Schema::table('sesiones_actividad', function (Blueprint $table) {
            $table->dropIndex('sesiones_por_verificar');
            $table->dropForeign(['verificada_por_id']);
            $table->dropColumn(['verificada_en', 'verificada_por_id', 'verificacion_origen']);
        });

        DB::statement('ALTER TABLE inscritos_actividad DROP CONSTRAINT origen_de_inscrito_valido');
        DB::statement("
            ALTER TABLE inscritos_actividad
            ADD CONSTRAINT origen_de_inscrito_valido
            CHECK (origen IN ('enlace', 'en_sesion'))
        ");

        Schema::table('inscritos_actividad', function (Blueprint $table) {
            $table->dropColumn('edad');
        });

        DB::statement('ALTER TABLE actividades DROP CONSTRAINT institucion_solo_en_programa_externo');
        DB::statement('ALTER TABLE actividades DROP CONSTRAINT tipo_de_actividad_valido');
        DB::statement("
            ALTER TABLE actividades
            ADD CONSTRAINT tipo_de_actividad_valido
            CHECK (tipo IN ('curso', 'taller', 'proyeccion'))
        ");

        Schema::table('actividades', function (Blueprint $table) {
            $table->dropForeign(['institucion_id']);
            $table->dropColumn('institucion_id');
        });

        Schema::dropIfExists('instituciones_externas');

        // El rol, al reves que arriba. Las cuentas de instituciones externas ya
        // no existen a estas alturas —se fueron con `instituciones_externas`,
        // que las bloqueaba— asi que el CHECK estrecho vuelve a poderse poner.
        DB::statement('ALTER TABLE perfiles DROP CONSTRAINT rol_valido');

        Schema::table('perfiles', function (Blueprint $table) {
            $table->string('rol', 15)->default('')->change();
            // Vuelve a ser obligatoria. A estas alturas ya no queda ningun
            // perfil sin ella: los unicos que podian tenerla vacia eran los de
            // las instituciones externas, y esas filas se fueron arriba.
            $table->date('fecha_nacimiento')->nullable(false)->change();
            $table->string('telefono', 15)->nullable(false)->change();
        });

        DB::statement("
            ALTER TABLE perfiles
            ADD CONSTRAINT rol_valido
            CHECK (rol IN ('administrador', 'director', 'profesor', 'estudiante', ''))
        ");
    }
};
