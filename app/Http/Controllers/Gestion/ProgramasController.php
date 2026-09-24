<?php

namespace App\Http\Controllers\Gestion;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * PROGRAMAS FORMATIVOS: todo lo que la institucion ofrece, en una pantalla.
 *
 * Nace el 01/09/2026 de un problema de portada, no de datos. Gestion tenia doce
 * fichas y cinco de ellas llevaban al mismo sitio por caminos distintos:
 * «Departamentos», «Promotorias» y «Grupos» son TRES puertas al mismo arbol
 * —el descenso departamento → promotorias → grupos ya existia, con sus migas—,
 * y las fichas planas entraban a media altura saltandoselo. Junto a ellas,
 * «Cursos y talleres» y «Grupos de proyeccion», que son la otra mitad de lo
 * mismo: lo que se ofrece.
 *
 * Aqui se ven las tres cosas seguidas y en su orden real. Los departamentos
 * encabezan porque SON la raiz del arbol: cada uno lleva a sus promotorias y
 * esas a sus grupos. Las dos actividades van debajo, separadas, porque no
 * cuelgan de un departamento y no pasan por matricula — a una promotoria se
 * entra con una matricula que alguien confirma, a una actividad por un enlace
 * que alguien comparte.
 *
 * No consulta nada por su cuenta: cada seccion se la da su propio controlador
 * por `seccion()`, que es lo mismo que ese controlador le pasa a su pantalla.
 * Una segunda version de esas consultas aqui seria la forma segura de que las
 * dos acabaran diciendo cosas distintas.
 */
class ProgramasController extends Controller
{
    public function __invoke(
        Request $request,
        AreaController $areas,
        CursoTallerController $cursos,
        ProyeccionController $proyeccion,
        ProgramaExternoController $externos,
        InstitucionExternaController $instituciones,
    ): View {
        return view('gestion.programas', [
            'departamentos' => $areas->seccion($request),
            'cursos' => $cursos->seccion($request),
            'proyeccion' => $proyeccion->seccion($request),
            // LOS PROGRAMAS EXTERNOS Y SUS INSTITUCIONES VAN JUNTOS Y AL FINAL,
            // en ese orden y por una razon de uso: no se puede abrir un
            // programa externo sin tener registrada la institucion donde se va
            // a dictar —su funcionario es quien verifica— asi que las dos
            // listas se leen seguidas y la segunda explica a la primera.
            //
            // Son DOS secciones y no una tabla anidada: una institucion
            // sobrevive a sus programas, se le cambia el funcionario y se le
            // apaga la cuenta sin tocar ninguno. Meterla dentro la haria
            // parecer una propiedad del programa, que es justo lo que no es.
            'externos' => $externos->seccion($request),
            'instituciones' => $instituciones->seccion($request),
        ]);
    }
}
