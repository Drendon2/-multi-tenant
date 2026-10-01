<?php

namespace Tests\Unit;

use App\Support\OrdenPorNombre;
use PHPUnit\Framework\TestCase;

/**
 * El desempate por nombre compara como la base: sin mayusculas ni tildes.
 *
 * Si comparara byte a byte, «Óscar» iria detras de «Zulma» y «ana» detras de
 * «Beto»: el desempate de una lista y el orden de la base no coincidirian.
 */
class OrdenPorNombreTest extends TestCase
{
    public function test_ordena_como_una_persona_lo_busca(): void
    {
        $nombres = ['Zulma', 'Óscar', 'paula', 'Nicolás', 'ana', 'Beto'];

        usort($nombres, OrdenPorNombre::comparar(...));

        $this->assertSame(['ana', 'Beto', 'Nicolás', 'Óscar', 'paula', 'Zulma'], $nombres);
    }

    public function test_mayusculas_y_tildes_no_desempatan(): void
    {
        $this->assertSame(0, OrdenPorNombre::comparar('María José', 'MARIA JOSE'));
    }
}
