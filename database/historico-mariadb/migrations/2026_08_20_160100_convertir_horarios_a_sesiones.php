<?php

/**
 * Convierte el `grupos.horario` de texto libre en filas de `sesiones_grupo`, y
 * despues retira la columna.
 *
 * Va en su propia migracion y no junto a la tabla porque son dos cosas
 * distintas: crear la estructura no puede fallar, y LEER prosa escrita a mano
 * si. Separadas, un texto que nadie sepa interpretar no impide que la tabla
 * quede creada.
 *
 * Lo que se entiende: un dia (con o sin tilde, con o sin mayuscula), un rango
 * de horas con o sin minutos, y un a. m./p. m. que puede aparecer una vez al
 * final o en cada extremo. Cubre las formas que hay escritas hoy:
 *
 *     Sábado 9:00-11:00 a. m.        Martes 4-6 p. m.
 *     Lunes 2:00-4:00 p. m.          Viernes 10:00 a. m.-12:00 m.
 *
 * Lo que NO se entiende se deja SIN sesion, a proposito, y el grupo se queda
 * sin horario hasta que alguien se lo ponga. La alternativa —inventar una hora
 * plausible— pondria a un estudiante a las cuatro en un salon donde no hay
 * nadie, y ese error no lo ve nadie hasta que alguien se planta alli.
 *
 * En produccion esto no convierte nada: cuando se escribio, la casa todavia no
 * habia creado ningun grupo. Se escribe igual porque en local hay 28 y porque
 * una migracion que solo funciona en una base concreta no es una migracion.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** 1 = lunes, como ISO-8601. Sin tildes: el texto se normaliza antes. */
    private const DIAS = [
        'lunes' => 1,
        'martes' => 2,
        'miercoles' => 3,
        'jueves' => 4,
        'viernes' => 5,
        'sabado' => 6,
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('grupos', 'horario')) {
            return;
        }

        $ahora = now();

        foreach (DB::table('grupos')->select('id', 'horario')->get() as $grupo) {
            $sesion = $this->interpretar((string) $grupo->horario);

            if ($sesion === null) {
                continue;
            }

            DB::table('sesiones_grupo')->insert([
                'grupo_id' => $grupo->id,
                'dia' => $sesion['dia'],
                'hora_inicio' => $sesion['inicio'],
                'hora_fin' => $sesion['fin'],
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);
        }

        Schema::table('grupos', function (Blueprint $table) {
            $table->dropColumn('horario');
        });
    }

    public function down(): void
    {
        Schema::table('grupos', function (Blueprint $table) {
            $table->string('horario', 60)->default('')->after('nivel');
        });

        // Se rehace el texto desde las sesiones. No sera identico al original
        // —«4-6 p. m.» vuelve como «4:00-6:00 p. m.»— y da igual: lo que
        // importa es que diga la misma hora.
        $dias = array_flip(self::DIAS);

        foreach (DB::table('sesiones_grupo')->orderBy('dia')->get() as $sesion) {
            DB::table('grupos')->where('id', $sesion->grupo_id)->update([
                'horario' => ucfirst($dias[$sesion->dia] ?? '').' '
                    .substr((string) $sesion->hora_inicio, 0, 5).'-'
                    .substr((string) $sesion->hora_fin, 0, 5),
            ]);
        }
    }

    /**
     * @return array{dia: int, inicio: string, fin: string}|null
     */
    private function interpretar(string $texto): ?array
    {
        $plano = $this->sinTildes(mb_strtolower(trim($texto)));

        $dia = null;

        foreach (self::DIAS as $nombre => $numero) {
            if (str_contains($plano, $nombre)) {
                $dia = $numero;
                break;
            }
        }

        if ($dia === null) {
            return null;
        }

        // Dos horas separadas por un guion, que puede ser el corto o el largo
        // que mete el procesador de textos. Los minutos son opcionales.
        if (! preg_match('/(\d{1,2})(?::(\d{2}))?\s*(a\.?\s*m\.?|p\.?\s*m\.?|m\.?)?\s*[-–—a]\s*(\d{1,2})(?::(\d{2}))?\s*(a\.?\s*m\.?|p\.?\s*m\.?|m\.?)?/u', $plano, $c)) {
            return null;
        }

        // El sufijo del final manda sobre el primero cuando este falta: en
        // «4-6 p. m.» las dos horas son de la tarde.
        $marcaInicio = $this->marca($c[3] ?? '') ?: $this->marca($c[6] ?? '');
        $marcaFin = $this->marca($c[6] ?? '') ?: $marcaInicio;

        $inicio = $this->aHora((int) $c[1], (int) ($c[2] ?? 0), $marcaInicio);
        $fin = $this->aHora((int) $c[4], (int) ($c[5] ?? 0), $marcaFin);

        // Sin sufijo util no se adivina si son las 4 de la tarde o las 4 de la
        // madrugada, y una clase a las 4 a. m. es justo el disparate que esta
        // migracion no debe inventar.
        if ($inicio === null || $fin === null || $fin <= $inicio) {
            return null;
        }

        return ['dia' => $dia, 'inicio' => $inicio, 'fin' => $fin];
    }

    private function marca(string $bruto): string
    {
        $limpio = str_replace([' ', '.'], '', $bruto);

        // «12 m.» es el mediodia en el uso colombiano; se trata como p. m.
        return match ($limpio) {
            'am' => 'am',
            'pm', 'm' => 'pm',
            default => '',
        };
    }

    private function aHora(int $hora, int $minuto, string $marca): ?string
    {
        if ($marca === '') {
            return null;
        }

        if ($marca === 'pm' && $hora < 12) {
            $hora += 12;
        }

        if ($marca === 'am' && $hora === 12) {
            $hora = 0;
        }

        if ($hora > 23 || $minuto > 59) {
            return null;
        }

        return sprintf('%02d:%02d:00', $hora, $minuto);
    }

    private function sinTildes(string $texto): string
    {
        return strtr($texto, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);
    }
};
