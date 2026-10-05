/**
 * El filtro de Gestion → Horarios: cambiar de dia o de promotoria sin recargar.
 *
 * La pantalla funciona entera sin este archivo: los dias son enlaces, el
 * desplegable va en un formulario GET y el servidor pone `hidden` a lo que no
 * toca. Aqui solo se mueve ese atributo, porque la semana entera ya vino en el
 * HTML, y se reescribe la URL con `replaceState` para que recargar o compartir
 * el enlace devuelva la misma vista.
 *
 * Las frases del resumen y de la lista vacia son las mismas que escribe la
 * plantilla. Estan dos veces a proposito —una para quien no tiene JavaScript y
 * otra para quien si—; si se cambia una, hay que cambiar la otra.
 *
 * `[hidden]` basta aqui porque ninguna regla de estas filas pone `display`: es
 * la trampa que ya costo en «Clases de la semana», donde el `flex` de la lista
 * le ganaba al atributo y el filtro no escondia nada. La hoja de estilos lo
 * refuerza de todos modos.
 */
(function () {
    'use strict';

    var DIAS = { 1: 'Lunes', 2: 'Martes', 3: 'Miércoles', 4: 'Jueves', 5: 'Viernes', 6: 'Sábado', 7: 'Domingo' };

    /** «los lunes», «los sábados»: los cinco primeros no cambian en plural. */
    function enPlural(dia) {
        var nombre = DIAS[dia].toLowerCase();
        return dia === '6' || dia === '7' ? nombre + 's' : nombre;
    }

    function montar(raiz) {
        if (raiz.dataset.montado === 'si') {
            return;
        }
        raiz.dataset.montado = 'si';

        var estado = { dia: raiz.dataset.dia, promotoria: raiz.dataset.promotoria || '' };
        var dias = Array.prototype.slice.call(raiz.querySelectorAll('.horarios-dia'));
        var bloques = Array.prototype.slice.call(raiz.querySelectorAll('.horarios-bloque'));
        var select = raiz.querySelector('#horarios-promotoria');
        var campoDia = raiz.querySelector('[data-horarios-filtro] input[name="dia"]');
        var ver = raiz.querySelector('[data-horarios-ver]');
        var resumen = raiz.querySelector('[data-horarios-resumen]');
        var vacio = raiz.querySelector('[data-horarios-vacio]');

        // El desplegable filtra al cambiar, asi que el boton ya no hace falta.
        if (ver) {
            ver.hidden = true;
        }

        function nombrePromotoria() {
            var opcion = select && select.options[select.selectedIndex];
            return opcion ? opcion.textContent : '';
        }

        function aplicar() {
            var cuentas = { 1: 0, 2: 0, 3: 0, 4: 0, 5: 0, 6: 0 };
            var clases = 0;
            var promotorias = 0;
            var cruces = 0;
            var tieneHorario = estado.promotoria === '';

            bloques.forEach(function (bloque) {
                var suya = estado.promotoria === '' || bloque.dataset.promotoria === estado.promotoria;
                var visiblesAqui = 0;

                if (suya && estado.promotoria !== '') {
                    tieneHorario = true;
                }

                Array.prototype.forEach.call(bloque.querySelectorAll('tr[data-dia]'), function (fila) {
                    var dia = fila.dataset.dia;
                    if (suya) {
                        cuentas[dia]++;
                    }
                    var mostrar = suya && dia === estado.dia;
                    fila.hidden = !mostrar;
                    if (mostrar) {
                        visiblesAqui++;
                        if (fila.hasAttribute('data-cruce')) {
                            cruces++;
                        }
                    }
                });

                bloque.hidden = visiblesAqui === 0;
                if (visiblesAqui) {
                    clases += visiblesAqui;
                    promotorias++;
                }
            });

            dias.forEach(function (enlace) {
                var dia = enlace.dataset.dia;
                var actual = dia === estado.dia;
                enlace.classList.toggle('horarios-dia-actual', actual);
                enlace.classList.toggle('horarios-dia-vacio', cuentas[dia] === 0);
                if (actual) {
                    enlace.setAttribute('aria-current', 'true');
                } else {
                    enlace.removeAttribute('aria-current');
                }
                enlace.querySelector('[data-cuenta]').textContent = cuentas[dia];
                enlace.href = url(dia, estado.promotoria);
            });

            if (campoDia) {
                campoDia.value = estado.dia;
            }

            if (clases) {
                var texto = '<strong>' + DIAS[estado.dia] + '</strong>: ' + clases + (clases === 1 ? ' clase' : ' clases');
                if (estado.promotoria === '') {
                    texto += ' en ' + promotorias + (promotorias === 1 ? ' promotoría' : ' promotorías');
                }
                if (cruces) {
                    texto += ' · <span class="horarios-resumen-cruce">' + cruces + (cruces === 1 ? ' clase' : ' clases') + ' con cruce de salón</span>';
                }
                resumen.innerHTML = texto;
            } else {
                resumen.textContent = '';
            }

            vacio.hidden = clases > 0;
            if (!clases) {
                if (estado.promotoria !== '' && !tieneHorario) {
                    vacio.textContent = nombrePromotoria() + ' no tiene grupos con horario.';
                } else if (estado.promotoria !== '') {
                    vacio.textContent = nombrePromotoria() + ' no tiene clases los ' + enPlural(estado.dia) + '.';
                } else {
                    vacio.textContent = 'Los ' + enPlural(estado.dia) + ' no hay clases.';
                }
            }

            if (window.history && window.history.replaceState) {
                window.history.replaceState(null, '', url(estado.dia, estado.promotoria));
            }
        }

        function url(dia, promotoria) {
            var base = window.location.pathname + '?dia=' + dia;
            return promotoria ? base + '&promotoria=' + promotoria : base;
        }

        dias.forEach(function (enlace) {
            enlace.addEventListener('click', function (evento) {
                // Con Ctrl o la rueda se abre en otra pestana, como un enlace.
                if (evento.ctrlKey || evento.metaKey || evento.shiftKey || evento.button !== 0) {
                    return;
                }
                evento.preventDefault();
                estado.dia = enlace.dataset.dia;
                aplicar();
            });
        });

        if (select) {
            select.addEventListener('change', function () {
                estado.promotoria = select.value;
                aplicar();
            });
        }
    }

    function barrer() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-horarios]'), montar);
    }

    barrer();

    // `acciones.js` puede traer esta pantalla repintando `<main>` sin recargar,
    // y entonces llega nueva y sin montar. Se vigila desde aqui por lo mismo
    // que en `clases-del-dia.js`: asi no hay que acordarse en los dos sitios.
    if (window.MutationObserver) {
        new MutationObserver(barrer).observe(document.body, { childList: true, subtree: true });
    }
})();
