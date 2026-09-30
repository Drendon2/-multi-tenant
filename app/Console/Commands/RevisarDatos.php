<?php

namespace App\Console\Commands;

use App\Support\Csv;
use App\Support\InstitucionActual;
use App\Support\Reglas;
use Illuminate\Console\Command;

/**
 * Quien tiene un dato que los formularios ya no aceptan.
 *
 * Desde el 07/09/2026 el telefono son diez digitos exactos y el documento solo
 * numeros (ver `App\Support\Reglas`). Las reglas corren al GUARDAR, no al leer,
 * asi que nadie se queda fuera del sistema por esto: se puede entrar, mirar el
 * horario, pasar lista y matricularse igual. Lo que pasa es que **la primera vez
 * que alguien vaya a guardar esa ficha, no le va a dejar** hasta corregir el
 * dato — y eso incluye a un administrador que solo venia a cambiarle el rol,
 * porque el formulario de usuario manda todos los campos a la vez.
 *
 * Este comando existe para que ese tropiezo no se descubra de uno en uno. Dice
 * quien es, que tiene guardado y por que no pasa.
 *
 * ─── Solo LEE ──────────────────────────────────────────────────────────────
 *
 * No escribe nada, ni con banderas: no las tiene. Es a proposito y no una
 * version a medias. Arreglar estos datos automaticamente es tentador —quitarle
 * los puntos a «1.036.261.209» da un documento valido— pero un documento de
 * identidad y un telefono de contacto son datos de una persona de carne y
 * hueso, y hay casos que NO se pueden deducir: un telefono de nueve digitos le
 * falta uno y no se sabe cual, y uno de once tiene uno de mas y no se sabe cual
 * sobra. Un comando que arregla la mitad y se calla la otra mitad es peor que
 * uno que solo mira.
 */
class RevisarDatos extends Command
{
    protected $signature = 'datos:revisar {--csv= : Escribe el listado en este archivo en vez de la pantalla}';

    protected $description = 'Lista las personas cuyo teléfono o documento ya no pasa la validación de los formularios';

    public function handle(): int
    {
        $filas = [
            ...$this->telefonosDePerfil(),
            ...$this->telefonosDeAcudiente(),
            ...$this->documentos(),
        ];

        if ($filas === []) {
            $this->info('Todo en orden: no hay ningún teléfono ni documento que los formularios rechacen.');

            return self::SUCCESS;
        }

        $ruta = $this->option('csv');

        if (is_string($ruta) && $ruta !== '') {
            return $this->aCsv($ruta, $filas);
        }

        $this->table(['Qué', 'Quién', 'Rol', 'Guardado', 'Por qué no pasa'], $filas);

        $this->newLine();
        $this->line("Son <options=bold>{$this->contar($filas)}</> datos en total.");
        $this->comment(
            'Ninguna de estas personas está bloqueada para entrar ni para usar el sistema. Lo que '
            .'no se puede es GUARDAR su ficha hasta corregir el dato, y eso incluye a quien solo '
            .'venía a cambiarle el rol.'
        );
        $this->comment('Con --csv=ruta.csv sale un archivo para repartir el trabajo.');

        return self::SUCCESS;
    }

    /**
     * @return list<array{string, string, string, string, string}>
     */
    private function telefonosDePerfil(): array
    {
        return InstitucionActual::tabla('perfiles')
            ->select('nombre_completo', 'rol', 'telefono')
            ->orderBy('nombre_completo')
            ->get()
            ->reject(fn ($p) => preg_match(Reglas::CELULAR, (string) $p->telefono) === 1)
            ->map(fn ($p) => [
                'Teléfono',
                (string) $p->nombre_completo,
                $p->rol === '' ? 'sin rol' : (string) $p->rol,
                $this->visible((string) $p->telefono),
                $this->porQueElTelefono((string) $p->telefono),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{string, string, string, string, string}>
     */
    private function telefonosDeAcudiente(): array
    {
        return InstitucionActual::tabla('acudientes')
            ->select('nombre', 'telefono')
            ->orderBy('nombre')
            ->get()
            // VACIO NO ES UN PROBLEMA, y esto es lo que la primera version de
            // este comando conto mal: el telefono del acudiente es `nullable`,
            // asi que en blanco pasa la validacion y esa ficha se guarda sin
            // tocar nada. En produccion son 13 de los 24 que parecian estar mal.
            // Contarlos aqui mandaria a alguien a llamar a trece familias para
            // arreglar algo que no esta roto.
            ->reject(fn ($a) => trim((string) $a->telefono) === '')
            ->reject(fn ($a) => preg_match(Reglas::CELULAR, (string) $a->telefono) === 1)
            ->map(fn ($a) => [
                'Tel. acudiente',
                (string) $a->nombre,
                'acudiente',
                $this->visible((string) $a->telefono),
                $this->porQueElTelefono((string) $a->telefono),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{string, string, string, string, string}>
     */
    private function documentos(): array
    {
        return InstitucionActual::tabla('datos_estudiante')
            ->join('perfiles', 'perfiles.id', '=', 'datos_estudiante.perfil_id')
            ->select('perfiles.nombre_completo', 'datos_estudiante.documento_identidad as doc')
            ->orderBy('perfiles.nombre_completo')
            ->get()
            ->reject(fn ($d) => preg_match(Reglas::DOCUMENTO, (string) $d->doc) === 1)
            ->map(fn ($d) => [
                'Documento',
                (string) $d->nombre_completo,
                'estudiante',
                $this->visible((string) $d->doc),
                $this->porQueElDocumento((string) $d->doc),
            ])
            ->values()
            ->all();
    }

    /**
     * Por que falla ESTE telefono, no «el formato no es válido».
     *
     * Se distingue lo mecanico de lo que hace falta preguntar, porque son dos
     * trabajos distintos: a quien tiene «313 741 1910» se le quitan los
     * espacios; a quien tiene nueve digitos hay que llamarlo.
     */
    private function porQueElTelefono(string $valor): string
    {
        if (trim($valor) === '') {
            return 'está vacío y es obligatorio';
        }

        $digitos = preg_replace('/\D/', '', $valor) ?? '';
        $largo = strlen($digitos);

        if ($largo === 10) {
            // El caso mecanico: los digitos son los correctos y solo sobran
            // signos. Aqui entran los espacios, los puntos del documento y el
            // espacio duro que pega un copiado de WhatsApp.
            return 'sobran signos, los 10 dígitos están: quedaría '.$digitos;
        }

        if ($largo < 10) {
            return "solo tiene {$largo} dígitos: falta preguntarle el número";
        }

        return "tiene {$largo} dígitos: hay que preguntarle cuál sobra";
    }

    private function porQueElDocumento(string $valor): string
    {
        if (trim($valor) === '') {
            return 'está vacío';
        }

        $digitos = preg_replace('/\D/', '', $valor) ?? '';
        $largo = strlen($digitos);

        if ($largo >= 6 && $largo <= 12 && $digitos !== $valor) {
            return 'sobran signos: quedaría '.$digitos;
        }

        if ($largo < 6) {
            return "solo tiene {$largo} dígitos: hay que pedir el documento";
        }

        if ($largo > 12) {
            return "tiene {$largo} dígitos: hay que revisarlo a mano";
        }

        return 'tiene letras: hay que revisarlo a mano';
    }

    /**
     * Deja ver lo que no se ve.
     *
     * Un espacio duro (U+00A0) —que es lo que pega un copiado de WhatsApp— se
     * imprime igual que un espacio normal, asi que en la pantalla el dato
     * pareceria correcto y quien lo corrija volveria a escribir lo mismo. Aqui
     * se nombra.
     */
    private function visible(string $valor): string
    {
        return str_replace(
            ["\u{00A0}", "\u{200B}", "\t"],
            ['[espacio duro]', '[espacio invisible]', '[tabulador]'],
            $valor
        );
    }

    /**
     * @param  list<array{string, string, string, string, string}>  $filas
     */
    private function contar(array $filas): int
    {
        return count($filas);
    }

    /**
     * @param  list<array{string, string, string, string, string}>  $filas
     */
    private function aCsv(string $ruta, array $filas): int
    {
        $salida = @fopen($ruta, 'w');

        if ($salida === false) {
            $this->error("No se pudo escribir en {$ruta}.");

            return self::FAILURE;
        }

        // El BOM y el punto y coma, por lo mismo que en `App\Support\Csv`: sin
        // ellos Excel abre el archivo en Latin-1 y en una sola columna.
        fwrite($salida, "\xEF\xBB\xBF");
        fputcsv($salida, ['Qué', 'Quién', 'Rol', 'Guardado', 'Por qué no pasa'], ';', '"', '');

        foreach ($filas as $fila) {
            fputcsv($salida, array_map(Csv::celda(...), $fila), ';', '"', '');
        }

        fclose($salida);

        $this->info(count($filas)." filas escritas en {$ruta}.");

        return self::SUCCESS;
    }
}
