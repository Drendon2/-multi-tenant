/**
 * El selector de dia de «Clases de la semana», en la portada del Panel.
 *
 * ─── Por que el selector lo crea ESTE ARCHIVO y no la plantilla ────────────
 *
 * Es la misma decision que el ojo de la contrasena (`ver-clave.js`) y por la
 * misma razon: un selector que filtra sin JavaScript es un control que no hace
 * nada. Puesto en el Blade «para que se vea en el HTML», quien navegue sin
 * JavaScript se encuentra seis botones muertos.
 *
 * Sin este archivo la pantalla sigue sirviendo: se ven los seis dias seguidos,
 * cada renglon con el suyo delante, ordenados por dia y hora. Es una pantalla
 * util, solo que mas larga.
 *
 * ─── Por que no se pide nada al servidor ───────────────────────────────────
 *
 * La primera version filtraba por `?dia=` y cada cambio era una navegacion. El
 * usuario la rechazo el 06/09/2026 con la razon exacta: «no puede quedar
 * recargando toda la pagina». El Panel se apoya en `<details>` abiertos
 * —departamentos, promotorias, actividades— y una recarga los cierra todos, asi
 * que cambiar de dia costaba perder lo que estabas mirando.
 *
 * La semana entera viene ya en el HTML. Aqui solo se esconden renglones.
 *
 * ─── Por que un MutationObserver ───────────────────────────────────────────
 *
 * La portada del Panel se REPINTA sin recargar: `acciones.js` reemplaza el
 * contenido de `<main>` cada vez que se confirma una matricula o se asigna un
 * grupo. Un barrido de arranque solo veria el primer pintado, y a partir de la
 * primera accion el selector desapareceria. Se vigila desde aqui y no desde
 * `acciones.js` porque asi no hay que acordarse en los dos sitios.
 */
(function () {
    'use strict';

    var DIAS = { 1: 'Lunes', 2: 'Martes', 3: 'Miércoles', 4: 'Jueves', 5: 'Viernes', 6: 'Sábado', 7: 'Domingo' };

    /** Monta el selector de un bloque, si no lo tiene ya. */
    function montar(bloque) {
        if (bloque.dataset.selectorPuesto === 'si') {
            return;
        }

        var lista = bloque.querySelector('[data-clases-lista]');
        var vacio = bloque.querySelector('[data-clases-vacio]');
        var cuenta = bloque.querySelector('[data-clases-cuenta]');

        if (!lista) {
            return;
        }

        var filas = Array.prototype.slice.call(lista.querySelectorAll('li[data-dia]'));

        // Los dias que ESTA persona tiene clase. Ofrecer los seis siempre
        // obligaria a probar uno por uno para descubrir que el martes no dicta.
        var conClase = [];
        filas.forEach(function (fila) {
            var dia = fila.getAttribute('data-dia');
            if (conClase.indexOf(dia) === -1) {
                conClase.push(dia);
            }
        });
        conClase.sort();

        // Con un solo dia no hay nada que elegir, y un selector de un boton solo
        // ocupa sitio.
        if (conClase.length < 2) {
            bloque.dataset.selectorPuesto = 'si';
            return;
        }

        var barra = document.createElement('p');
        barra.className = 'dias-selector';

        var botones = [];

        /** Deja a la vista solo el dia pedido; '' son todos. */
        function elegir(dia) {
            var visibles = 0;

            filas.forEach(function (fila) {
                var suyo = dia === '' || fila.getAttribute('data-dia') === dia;
                fila.hidden = !suyo;
                if (suyo) {
                    visibles++;
                }
            });

            botones.forEach(function (boton) {
                var activo = boton.dataset.dia === dia;
                boton.classList.toggle('dias-selector-actual', activo);
                // `aria-pressed` y no solo la clase: para quien no ve la
                // pantalla, el color no dice cual esta puesto.
                boton.setAttribute('aria-pressed', activo ? 'true' : 'false');
            });

            if (vacio) {
                vacio.hidden = visibles > 0;
            }

            if (cuenta) {
                cuenta.textContent = visibles + (visibles === 1 ? ' clase' : ' clases');
            }
        }

        function boton(dia, texto) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'dias-selector-boton';
            b.dataset.dia = dia;
            b.textContent = texto;
            b.addEventListener('click', function () {
                elegir(dia);
            });
            barra.appendChild(b);
            botones.push(b);

            return b;
        }

        boton('', 'Toda la semana');
        conClase.forEach(function (dia) {
            boton(dia, DIAS[dia] || dia);
        });

        lista.parentNode.insertBefore(barra, lista);

        // Arranca en HOY si esta persona tiene clase hoy; si no, en toda la
        // semana.
        var hoy = bloque.dataset.hoy || '';
        elegir(conClase.indexOf(hoy) === -1 ? '' : hoy);

        bloque.dataset.selectorPuesto = 'si';
    }

    function barrer() {
        var bloques = document.querySelectorAll('[data-clases-dia]');
        Array.prototype.forEach.call(bloques, montar);
    }

    barrer();

    // Ver el cabecero: `<main>` se repinta sin recargar y el bloque llega nuevo,
    // sin selector y sin la marca de montado.
    if (window.MutationObserver) {
        new MutationObserver(barrer).observe(document.body, { childList: true, subtree: true });
    }
})();
