/**
 * ============================================================================
 * KINGS OF PC - POS & DASHBOARD INTERFACE SCRIPT
 * ============================================================================
 * Este script maneja toda la lógica del frontend del punto de venta (POS):
 * búsqueda de productos, gestión del carrito, cálculo de subtotales, recargos,
 * descuentos, validación de pagos/vueltos y envío asíncrono (AJAX) para guardar
 * las ventas e imprimir comprobantes.
 */
(function ($) {
    'use strict';

    // Variables de configuración global y del estado de la venta
    var cfg = window.kopInv;
    var items = []; // Almacena los productos en el carrito: {id, name, sku, price, stock, manage, qty, discountPct}
    var timer = null; // Temporizador para el buscador con retraso (debounce)

    // Token anti-duplicado: se reutiliza si hay que reintentar la misma venta.
    function newToken() {
        if (window.crypto && window.crypto.randomUUID) { return window.crypto.randomUUID(); }
        return 'k' + Date.now().toString(36) + Math.random().toString(36).slice(2, 12) + Math.random().toString(36).slice(2, 12);
    }
    var saleToken = newToken();
    var lastSent = ''; // contenido de la última venta enviada (sin token)
    
    // Texto que explica qué falta para poder guardar
    $('<p id="kop-save-hint" class="kop-save-hint"></p>').insertAfter('#kop-save');

    // Referencias a elementos principales del DOM
    var $q = $('#kop-q');
    var $results = $('#kop-results');
    var $body = $('#kop-items tbody');

    /**
     * Formatea un número decimal a formato de moneda local (Pesos Argentinos).
     * @param {number} n - Valor numérico a formatear.
     * @returns {string} Cadena formateada ej: "$ 7.000,00"
     */
    function fmt(n) {
        return '$ ' + (Number(n) || 0).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    /**
     * Muestra notificaciones visuales en la parte superior del panel.
     * @param {string} msg - Mensaje de texto o HTML a mostrar.
     * @param {string} type - Tipo de aviso ('error', 'warning', 'success'). Por defecto 'error'.
     */
    function notice(msg, type) {
        $('#kop-notice')
            .attr('class', 'notice notice-' + (type || 'error'))
            .html('')
            .append($('<p>').html(msg))
            .show();
        window.scrollTo(0, 0);
    }

    /**
     * Calcula el subtotal de una línea de producto aplicando cantidad, precio y descuento porcentual.
     * @param {Object} it - Objeto que representa el ítem del carrito.
     * @returns {number} Subtotal redondeado a 2 decimales.
     */
    function lineTotal(it) {
        return Math.round(it.qty * it.price * (1 - it.discountPct / 100) * 100) / 100;
    }

    /* ========================================================================
     * SECCIÓN 1: BÚSQUEDA Y AUTOCOMPLETADO DE PRODUCTOS
     * ======================================================================== */

    /**
     * Realiza una petición AJAX al servidor para buscar productos por SKU o nombre.
     * @param {string} term - Texto de búsqueda ingresado por el cajero.
     * @param {auto} auto - Booleano que indica si se debe añadir automáticamente al carrito si hay coincidencia exacta.
     */
    function search(term, auto) {
        $.get(cfg.ajax, { action: 'kop_search', nonce: cfg.nonce, term: term }).done(function (r) {
            if (!r || !r.success) { return; }
            var list = r.data;
            console.log('KOP RESPONSE:', r);
            if (auto && list.length) {
                var exact = list[0].sku && list[0].sku.toLowerCase() === term.toLowerCase();
                if (list.length === 1 || exact) {
                    addItem(list[0]);
                    $q.val('');
                    $results.hide().empty();
                    return;
                }
            }
            showResults(list);
        });
    }

    /**
     * Renderiza la lista desplegable con los resultados de la búsqueda de productos.
     * @param {Array} list - Lista de productos encontrados.
     */
    function showResults(list) {
        $results.empty();
        if (!list.length) {
            $results.append($('<li class="kop-no-results">').text('Sin resultados')).show();
            return;
        }
        list.forEach(function (p) {
            var stock = p.manage ? ' · stock ' + p.stock : '';
            
            var $li = $('<li class="kop-result">').append(
                $('<img class="kop-result__img">')
                    .attr('src', p.img || '')
                    .attr('alt', p.name)
                    .attr('loading', 'lazy'),
                $('<div class="kop-result__body">').append(
                    $('<b class="kop-result__name">').text(p.name),
                    $('<small class="kop-result__meta">').text(
                        (p.sku ? p.sku + ' · ' : '') + fmt(p.price) + stock
                    )
                )
            );


            // Evento al hacer clic en un producto de la lista para agregarlo al carrito
            $li.on('mousedown', function (e) {
                e.preventDefault();
                addItem(p);
                $q.val('').focus();
                $results.hide().empty();
            });
            $results.append($li);
        });
        $results.show();
    }

    /**
     * Añade un producto seleccionado al carrito o incrementa su cantidad si ya existe.
     * @param {Object} p - Datos del producto.
     */
    function addItem(p) {
        var qty = 1
        
        var existing = items.filter(function (i) { return i.id === p.id; })[0];
        if (existing) {
            existing.qty += qty;
        } else {
            items.push({ id: p.id, img: p.img, name: p.name, sku: p.sku || '', price: p.price, stock: p.stock, manage: p.manage, qty: qty, discountPct: 0 });
        }

        render();
    }

    /* ========================================================================
     * SECCIÓN 2: RENDERIZADO DE LA TABLA DE ÍTEMS Y CÁLCULOS
     * ======================================================================== */

   /**
 * Actualiza y dibuja dinámicamente la tabla del carrito en la interfaz.
 */
function render() {
    $body.empty();
    items.forEach(function (it, i) {
        var $tr = $('<tr>').attr('data-i', i);

        // SKU
        $tr.append($('<td>').text(it.sku));

        // Descripción: imagen + nombre + advertencia de stock
        var $desc = $('<td>');
        var $descInner = $('<div class="kop-item-desc">');

        // Imagen del producto (tamaño grande para identificar visualmente)
        if (it.img) {
            $descInner.append(
                $('<img class="kop-item-img">')
                    .attr('src', it.img)
                    .attr('alt', it.name)
                    .attr('loading', 'lazy')
            );
        } else {
            $descInner.append($('<div class="kop-item-img kop-item-img--empty">'));
        }

        var $descText = $('<div class="kop-item-info">').append(
            $('<span class="kop-item-name">').text(it.name)
        );

        // Muestra advertencia visual si la cantidad supera el stock disponible de WooCommerce
        if (it.manage && it.qty > it.stock) {
            $descText.append($('<div class="kop-warn">').text('Stock disponible: ' + it.stock));
        }

        $descInner.append($descText);
        $desc.append($descInner);
        $tr.append($desc);

        // Cantidad
        $tr.append($('<td>').append($('<input type="number" min="1" step="1" data-f="qty">').val(it.qty)));
        // Precio
        $tr.append($('<td>').append($('<input type="number" min="0" step="0.01" data-f="price">').val(it.price)));
        // Descuento
        $tr.append($('<td>').append($('<input type="number" min="0" max="100" step="0.01" data-f="discountPct">').val(it.discountPct)));
        // Total línea
        $tr.append($('<td class="kop-line-total">').text(fmt(lineTotal(it))));
        // Quitar
        $tr.append($('<td>').append($('<button type="button" class="button-link kop-remove" title="Quitar">').text('✕')));

        $body.append($tr);
    });
    
    $('#kop-empty').toggle(!items.length);
    $('#kop-items').toggle(items.length > 0);
    
    // NUEVO: Si el carrito quedó vacío, reseteamos los pagos a 0
    if (!items.length) {
        $('.kop-pay-amount').val(0);
        $('#kop-discount').val(0);
    }
    
    updateTotals();
}

    var payDirty = false; // Bandera para saber si el cajero modificó manualmente los montos de pago

    /**
     * Redondea un número a exactamente 2 decimales.
     */
    function round2(n) { return Math.round(n * 100) / 100; }

    /**
     * Calcula la suma de todos los subtotales de los ítems del carrito.
     */
    function getSubtotal() {
        var subtotal = 0;
        items.forEach(function (it) { subtotal += lineTotal(it); });
        return subtotal;
    }

    /**
     * Calcula el monto base de la venta restando el descuento manual aplicado.
     */
    function getBase() {
        var subtotal = getSubtotal();
        var discount = Math.min(Math.max(parseFloat($('#kop-discount').val()) || 0, 0), subtotal);
        return round2(subtotal - discount);
    }

    /**
     * Calcula pagos, vuelto y recargos exactamente como los va a guardar el servidor.
     * Regla: lo tipeado en cada forma de pago es el valor imputado a la venta ("A cobrar" = valor + recargo).
     * Si se paga de más, el excedente se recorta del efectivo; el vuelto real es ese excedente más/menos
     * el % del efectivo.
     */
    function computePayments() {
        var base = getBase();
        var rows = [];
        var paid = 0;

        $('.kop-pay-amount').each(function () {
            var typed = Math.max(parseFloat($(this).val()) || 0, 0);
            var name = $(this).attr('data-name');
            rows.push({
                $el: $(this),
                name: name,
                pct: parseFloat($(this).attr('data-pct')) || 0,
                isCash: /efectivo/i.test(name),
                typed: typed,
                net: typed, // lo que queda imputado después de recortar el vuelto
                adj: 0
            });
            paid += typed;
        });
        paid = round2(paid);

        var excess = round2(Math.max(0, paid - base));
        var change = 0, handed = 0, changeOk = true, cashRow = null;

        if (excess > 0) {
            cashRow = rows.filter(function (r) { return r.isCash && r.typed >= excess; })[0] || null;
            if (cashRow) {
                cashRow.net = round2(cashRow.typed - excess);
                change = round2(excess * (1 + cashRow.pct / 100));
                handed = round2(cashRow.typed * (1 + cashRow.pct / 100)); // efectivo que entrega el cliente
            } else {
                changeOk = false; // sobra plata y no hay efectivo desde donde devolverla
            }
        }

        var adjustment = 0;
        rows.forEach(function (r) {
            r.adj = round2(r.net * r.pct / 100);
            adjustment += r.adj;
        });
        adjustment = round2(adjustment);

        return {
            base: base,
            paid: paid,
            excess: excess,
            missing: round2(Math.max(0, base - paid)),
            change: change,
            handed: handed,
            changeOk: changeOk,
            cashRow: cashRow,
            adjustment: adjustment,
            total: round2(base + adjustment),
            rows: rows
        };
    }

    /**
     * Devuelve la lista de cosas que faltan para poder guardar. Lista vacía = se puede guardar.
    */
    function getSaveProblems(c, hasItems) {
        var problems = [];

        if (!hasItems) { problems.push('un producto'); return problems; }
        if (c.paid < c.base) { problems.push('cobrar ' + fmt(c.missing)); }
        if (!c.changeOk) { problems.push('efectivo para dar el vuelto'); }

        if ($('#kop-type').val() === 'C') {
            var name = $.trim($('#kop-customer').val()).toLowerCase();
            var digits = String($('#kop-tax-id').val()).replace(/\D/g, '');
            if (!name || name === 'consumidor final') { problems.push('nombre del cliente'); }
            if (digits.length !== 11 && (digits.length < 7 || digits.length > 8)) { problems.push('DNI o CUIT válido'); }
        }

        return problems;
    }

    /**
     * Recalcula los totales generales, montos pagados, recargos por método de pago y faltantes/vueltos.
     */
    function updateTotals() {
        var units = 0;
        items.forEach(function (it) { units += it.qty; });

        var hasItems = items.length > 0;

        // NUEVO: Si no hay productos, ocultamos el aviso de error (ej. "Stock insuficiente")
        if (!hasItems) {
            $('#kop-notice').hide(); 
            payDirty = false; 
        }

        $('.kop-pay-amount, #kop-discount').prop('disabled', !hasItems);

        var c = computePayments();

        c.rows.forEach(function (r) {
            var txt = r.net > 0 ? 'A cobrar: ' + fmt(r.net + r.adj) : '';
            if (c.change > 0 && r === c.cashRow) {
                txt += (txt ? ' · ' : '') + 'recibe ' + fmt(c.handed);
            }
            r.$el.closest('.kop-pay-row').find('.kop-pay-due').text(txt);
        });

        
        var problems = getSaveProblems(c, hasItems);
        var canSave = problems.length === 0;
        // Se muestra solo si se cumplen todas las condiciones (o mientras está guardando, para ver "Guardando…").
        $('#kop-save').toggle(canSave || saving).prop('disabled', !canSave || saving);
        $('#kop-save-hint').text(canSave || saving ? '' : 'Falta: ' + problems.join(' · '));


        var label = 'Falta', value = c.missing;
        if (c.change > 0) { label = 'Vuelto'; value = c.change; }
        else if (!c.changeOk) { label = 'Sobra (el vuelto solo se da en efectivo)'; value = c.excess; }

        $('#kop-subtotal').text(fmt(getSubtotal()));
        $('#kop-adjustment').text(fmt(c.adjustment));
        $('#kop-total').text(fmt(c.total));
        $('#kop-paid').text(fmt(c.paid));
        $('#kop-missing-label').text(label);
        $('#kop-missing').text(fmt(value)).css('color', value === 0 ? '' : '#b32d2e');
        $('#kop-count-products').text(items.length);
        $('#kop-count-units').text(units);
    }

    /* ========================================================================
     * SECCIÓN 3: MANEJADORES DE EVENTOS (INPUTS, TABLA Y PAGOS)
     * ======================================================================== */

    // Evento dinámico al modificar cantidad, precio o descuento de un ítem en la tabla
    $body.on('input', 'input', function () {
        var $tr = $(this).closest('tr');
        var it = items[$tr.data('i')];
        var field = $(this).data('f');
        var value = parseFloat($(this).val()) || 0;
        if (field === 'qty') { value = Math.max(1, Math.floor(value)); }
        if (field === 'discountPct') { value = Math.min(100, Math.max(0, value)); }
        it[field] = value;
        $tr.find('.kop-line-total').text(fmt(lineTotal(it)));
        updateTotals();
    });

    $body.on('change', 'input[data-f="qty"]', render);

    // Eliminar un producto individual del carrito
    $body.on('click', '.kop-remove', function () {
        items.splice($(this).closest('tr').data('i'), 1);
        render();
    });

    // Vaciar todo el carrito de compras con confirmación
    $('#kop-clear').on('click', function () {
        if (items.length && confirm('¿Vaciar el carrito?')) { items = []; $('.kop-pay-amount').val(0); render(); }
    });

    $('#kop-discount').on('input', updateTotals);

    $(document).on('input', '.kop-pay-amount', function () {
        payDirty = true;
        updateTotals();
    });

        // --- Limpiar el '0' al enfocar los inputs de pago ---
    $(document).on('focus', '.kop-pay-amount', function () {
        if (this.value === '0' || this.value === '0.00') {
            $(this).val('');
        }
    });

    // Si se deja vacío al salir, restaurar el '0' para no romper los cálculos
    $(document).on('blur', '.kop-pay-amount', function () {
        if (this.value.trim() === '') {
            $(this).val('0');
            // Disparamos el evento input manualmente para que los totales se actualicen
            $(this).trigger('input'); 
        }
    });
    // -----------------------------------------------------

    /* ========================================================================
     * SECCIÓN 4: EVENTOS DEL BUSCADOR Y ATAJOS DE TECLADO
     * ======================================================================== */

    // Búsqueda con retraso (debounce) al escribir en el campo de código/nombre
    $q.on('input', function () {
        clearTimeout(timer);
        var term = $.trim($q.val());
        if (term.length < 2) { $results.hide().empty(); return; }
        timer = setTimeout(function () { search(term, false); }, 250);
    });

    // Añadir producto con la tecla Enter en el buscador
    $q.on('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            clearTimeout(timer);
            var term = $.trim($q.val());
            if (term.length >= 2) { search(term, true); }
        }
    });

    $q.on('blur', function () { setTimeout(function () { $results.hide(); }, 150); });

    /* ========================================================================
     * SECCIÓN 5: PROCESO DE GUARDADO Y ENVÍO DE LA VENTA (AJAX)
     * ======================================================================== */
    var saving = false;

    
    // Loader a pantalla completa (se usa al guardar una factura C)
    var $loader = $(
        '<div id="kop-loader" class="kop-loader" role="alert" aria-live="assertive" style="display:none">' +
            '<div class="kop-loader-box">' +
                '<div class="kop-spinner"></div>' +
                '<p class="kop-loader-title">Generando factura…</p>' +
                '<p class="kop-loader-sub">No cierres ni recargues esta página.</p>' +
            '</div>' +
        '</div>'
    ).appendTo('body');

    function showLoader() { $loader.css('display', 'flex'); }
    function hideLoader() { $loader.hide(); }

    /**
     * Valida los datos del cliente, pagos y envía la venta mediante AJAX al backend de WordPress.
     */
    function save() {
        if (saving) { return; }

        var pre = getSaveProblems(computePayments(), items.length > 0);
        if (pre.length) { notice('Falta: ' + pre.join(' · ') + '.'); return; }

        if (!items.length) { notice('Agregá al menos un producto.'); return; }
                var c = computePayments();
        var payments = c.rows
            .filter(function (r) { return r.net > 0; })
            .map(function (r) { return { name: r.name, amount: r.net }; });

        if (!payments.length) { notice('Cargá cuánto paga el cliente.'); return; }
        if (c.paid < c.base) { notice('El monto pagado es menor al total de la venta.'); return; }
        if (!c.changeOk) {
            notice('El vuelto solo se puede dar en efectivo: el pago en efectivo tiene que ser mayor o igual al vuelto.');
            return;
        }

        // Estructura de datos que se enviará al servidor
        var data = {
            customer: $('#kop-customer').val(),
            tax_id: $('#kop-tax-id').val(),
            phone: $('#kop-phone').val(),
            type: $('#kop-type').val(),
            discount: parseFloat($('#kop-discount').val()) || 0,
            payments: payments,
            notes: $('#kop-notes').val(),
            cash_received: c.change > 0 ? c.handed : 0,
            items: items.map(function (i) {
                return { id: i.id, qty: i.qty, price: i.price, discount_pct: i.discountPct };
            })
        };

        // Validación estricta para Factura tipo C (exigida por normativa fiscal)
        if (data.type === 'C') {
            var name = $.trim(data.customer).toLowerCase();
            var digits = String(data.tax_id).replace(/\D/g, '');
            var phone = String(data.phone).replace(/\D/g, '');

            if (!name || name === 'consumidor final') { notice('Para una factura C cargá el nombre del cliente.'); return; }

            if (digits.length !== 11 && (digits.length < 7 || digits.length > 8)) {
                notice('Para una factura C cargá un DNI (7 u 8 dígitos) o un CUIT/CUIL (11 dígitos).');
                return;
            }
        }

        if (data.type === 'C' && !confirm('Se va a generar una FACTURA C real. ¿Continuar?')) { return; }

        
        // Si el cajero cambió algo desde el último intento, es otra venta: token nuevo.
        var fingerprint = JSON.stringify(data);
        if (lastSent && fingerprint !== lastSent) { saleToken = newToken(); }
        lastSent = fingerprint;
        data.token = saleToken;

        var actionTab = window.open('about:blank', '_blank');

        saving = true;
        $('#kop-save').prop('disabled', true).text('Guardando…');

        if (data.type === 'C') { showLoader(); }
        
        // Petición AJAX POST para procesar y registrar la venta en la base de datos
        $.post(cfg.ajax, { action: 'kop_save', nonce: cfg.nonce, data: JSON.stringify(data) })
            .done(function (r) {

    if (r && r.success) {

        var msg = 'Venta <b>' + r.data.number + '</b> guardada por ' + r.data.total +
            '. <a href="' + r.data.url + '" target="_blank">Ver / imprimir comprobante</a>';

        // =====================================================
        // VENTAS X: descarga del recibo y apertura de WhatsApp
        // =====================================================

        if (r.data.type === 'X') {

            if (r.data.phone && r.data.whatsapp) {

            var waUrl    = r.data.whatsapp;
            var waOpened = false;
            var waTimer;

            // Iframe oculto que arma el PDF.
            var pdfFrame = document.createElement('iframe');
            pdfFrame.src = r.data.url + '&pdf=1';
            pdfFrame.style.cssText = 'position:fixed;left:-10000px;top:0;width:900px;height:1300px;border:0;opacity:0;pointer-events:none;';
            document.body.appendChild(pdfFrame);

            // Abre WhatsApp y limpia el iframe (se ejecuta una sola vez).
            var openWhatsApp = function () {
                if (waOpened) { return; }
                waOpened = true;
                window.removeEventListener('message', onPdfMsg);
                clearTimeout(waTimer);
                if (pdfFrame.parentNode) { pdfFrame.parentNode.removeChild(pdfFrame); }
                if (actionTab && !actionTab.closed) {
                    actionTab.location.href = waUrl;
                }
            };

            // Recibe el PDF del iframe y lo descarga desde esta página.
            var onPdfMsg = function (e) {
                if (e.origin !== window.location.origin || !e.data) { return; }

                if (e.data.type === 'kop-pdf' && e.data.blob) {
                    var blobUrl = URL.createObjectURL(e.data.blob);
                    var a = document.createElement('a');
                    a.href = blobUrl;
                    a.download = e.data.filename;
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    setTimeout(function () { URL.revokeObjectURL(blobUrl); }, 10000);
                    openWhatsApp();
                } else if (e.data.type === 'kop-pdf-error') {
                    console.error('KOP PDF:', e.data.message);
                    openWhatsApp();
                }
            };

            window.addEventListener('message', onPdfMsg);
            waTimer = setTimeout(openWhatsApp, 20000); // por si el PDF tarda o falla

            // Si no se pudo abrir la pestaña (bloqueador de popups), botón manual.
            if (!actionTab || actionTab.closed) {
                msg += '<br><a class="button button-primary" href="' +
                    waUrl + '" target="_blank" rel="noopener">Abrir WhatsApp</a>';
            }

            msg += '<br><em>Generando el PDF… se descargará solo y se abrirá WhatsApp.</em>';

            } else {

                // Sin teléfono: abrir directamente el recibo.
                if (actionTab && !actionTab.closed) {
                    actionTab.location.href = r.data.url;
                } else {
                    window.open(r.data.url, '_blank');
                }

                msg += '<br><em>No se cargó un teléfono válido: se abrió el comprobante.</em>';
            }

        }

                    // =====================================================
                    // VENTAS C: mantener comportamiento actual
                    // =====================================================

                    else {

                        if (r.data.order_id) {

                            if (r.data.invoice_status === 'invoiced') {
                                msg += '<br><b>Factura emitida</b>' + (r.data.cae ? ' · CAE ' + r.data.cae : '') + '.';
                            } else if (r.data.invoice_status === 'emitting') {
                                msg += '<br><b>Factura en proceso:</b> ARCA todavía no la confirmó. Revisá el Historial.';
                            }

                            msg += '<br>Pedido <b>#' + r.data.order_id +
                                '</b>. <a href="' + r.data.order_url +
                                '" target="_blank">Abrir pedido y descargar la factura</a>';

                            // Lleva directo a la orden en la pestaña que ya estaba abierta.
                            if (actionTab && !actionTab.closed) {
                                actionTab.location.href = r.data.order_url;
                            } else {
                                window.open(r.data.order_url, '_blank');
                            }

                        } else if (r.data.url) {

                            if (actionTab && !actionTab.closed) {
                                actionTab.location.href = r.data.url;
                            } else {
                                window.open(r.data.url, '_blank');
                            }

                        } else if (actionTab && !actionTab.closed) {
                            actionTab.close();
                        }
                    }

                    if (r.data.warning) {
                        msg += '<br>' + r.data.warning;
                    }

                    notice(msg, r.data.warning ? 'warning' : 'success');

                    // Restablecer formulario y carrito.
                    $('#kop-type').val('X').trigger('change');

                    items = [];

                    $('#kop-customer').val('Consumidor Final');
                    $('#kop-tax-id, #kop-phone, #kop-notes').val('');
                    $('#kop-discount').val(0);

                    $('.kop-pay-amount').val(0);

                    saleToken = newToken();
                    lastSent = '';
                    payDirty = false;

                    render();
                    $q.focus();

                } else {

                    if (actionTab && !actionTab.closed) {
                        actionTab.close();
                    }

                    notice((r && r.data) ? r.data : 'No se pudo guardar la venta.');
                }
            })

            .fail(function () {

                if (actionTab && !actionTab.closed) {
                    actionTab.close();
                }

                notice(
                    'No hubo respuesta del servidor. Antes de reintentar mirá el Historial: la venta pudo haberse guardado. Si volvés a tocar Guardar sin cambiar nada, no se duplica.',
                    'warning'
                );
            })


            .always(function () {
                hideLoader();
                saving = false;
                $('#kop-save').text('Guardar venta (F6)');
                updateTotals();
            });
    }

    // Control dinámico de campos obligatorios u opcionales según el tipo de comprobante (X o C)
    $('#kop-type').on('change', function () {
        var isC = $(this).val() === 'C';
        var $customer = $('#kop-customer');
        var name = $.trim($customer.val()).toLowerCase();
        $('#kop-tax-id').attr('placeholder', isC ? 'Obligatorio' : 'Opcional');
        $customer.attr('placeholder', isC ? 'Nombre y apellido (obligatorio)' : '');
        if (isC && name === 'consumidor final') { $customer.val('').focus(); } // el nombre se escribe a mano
        if (!isC && name === '') { $customer.val('Consumidor Final'); }
    });

    // Recalcula si se puede guardar cuando cambian el tipo de factura, el nombre o el DNI/CUIT
    $('#kop-customer, #kop-tax-id').on('input', updateTotals);
    $('#kop-type').on('change', updateTotals);

    // Asignación del evento de guardado al botón y atajos de teclado
    $('#kop-save').on('click', save);
    $(document).on('keydown', function (e) {
        if (e.key === 'F6') { e.preventDefault(); save(); }
    });

    // Inicialización del POS al cargar la página
    render();
})(jQuery);