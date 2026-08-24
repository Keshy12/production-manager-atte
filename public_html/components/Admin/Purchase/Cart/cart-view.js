// Koszyk — cascading vendor↔part picker that lands items in a
// shared cart grouped by vendor (client-side state).
//
// Flow:
//   1. User picks a vendor (or a part). The other picker disables
//      every option that wouldn't match the picked side.
//   2. Once both are picked, the "Numer u dostawcy" dropdown
//      populates with every VendorPart for that combo (same part
//      can be listed under several vendor_part_no by one vendor —
//      e.g. alternates). The dropdown defaults to the first one.
//   3. User picks qty and clicks "Dodaj do koszyka". Same item
//      (same vendor_part_id) merges in qty instead of duplicating.
//   4. Each vendor group in the cart has its own "Utwórz zapytanie"
//      / "Utwórz zamówienie" buttons — submits only that group's
//      items to POST /cart-action.php.

(function () {
    'use strict';

    var cart = {
        items:       [],     // {vendor_part_id, vendor_id, vendor_name, vendor_part_no,
                             //  producer_part_no, part_name, producer_name, unit_name,
                             //  vendor_jm_id, full_pack_quantity, quantity, unit_price,
                             //  currency}
        activeDocs:  {},     // vendor_part_id (string) → [doc, …]
    };

    // DOM refs
    var $vendorSelect        = $('#vendorSelect');
    var $partSelect          = $('#partSelect');
    var $vendorPartRow       = $('#vendorPartRow');
    var $vendorPartNoSelect  = $('#vendorPartNoSelect');
    var $cartQty             = $('#cartQty');
    var $cartPrice           = $('#cartPrice');
    var $cartCurrency        = $('#cartCurrency');
    var $addToCartBtn        = $('#addToCartBtn');
    var $cartCard            = $('#cartCard');
    var $cartBody            = $('#cartBody');
    var $cartCount           = $('#cartCount');
    var $clearBtn            = $('#clearCartBtn');
    var $selectionDocsCard   = $('#selectionDocsCard');
    var $selectionDocsHeader = $('#selectionDocsHeader');
    var $selectionDocsBody   = $('#selectionDocsBody');
    var $selectionDocsContent = $('#selectionDocsContent');
    var $selectionDocsCount  = $('#selectionDocsCount');
    // Last fetched strip payload: { options: [vp,…], docs: {vpId: [doc,…]} }
    var selectionDocsCache   = { options: [], docs: {} };

    // ---- helpers ----

    function refreshSelectpicker($el) {
        if (typeof $el.selectpicker === 'function') {
            try { $el.selectpicker('refresh'); } catch (e) { /* noop */ }
        }
    }

    function setAlert(msg, kind) {
        var $box = $('#alertContainer');
        if (!msg) { $box.empty(); return; }
        $box.html(
            '<div class="alert alert-' + (kind || 'info') + ' alert-dismissible fade show" role="alert">' +
            msg +
            '<button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>' +
            '</div>'
        );
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function formatQty(n) {
        var v = parseFloat(n);
        if (isNaN(v)) return '';
        return v.toString();
    }

    function formatPrice(n) {
        if (n === null || n === undefined || n === '') return '—';
        var v = parseFloat(n);
        if (isNaN(v)) return '—';
        return v.toFixed(4).replace(/\.?0+$/, '');
    }

    function stateBadgeClass(state) {
        switch (state) {
            case 'draft':              return 'badge-secondary';
            case 'sent':               return 'badge-primary';
            case 'responded':          return 'badge-info';
            case 'confirmed':          return 'badge-info';
            case 'partially_received': return 'badge-warning';
            case 'received':           return 'badge-success';
            case 'cancelled':          return 'badge-danger';
            default:                   return 'badge-secondary';
        }
    }

    function stateBadgeLabel(state) {
        return ({
            draft: 'szkic', sent: 'wysłane', responded: 'odpowiedź',
            confirmed: 'potwierdzone', partially_received: 'częściowo odebrane',
            received: 'odebrane', cancelled: 'anulowane'
        })[state] || state;
    }

    function docTypeIcon(docType) {
        return docType === 'rfq' ? '⚠' : '📦';
    }

    function safeJsonArray(raw) {
        if (!raw) return [];
        try {
            var v = JSON.parse(raw);
            // Defensive: GROUP_CONCAT output double-encoded as a JSON string
            // ("1,2,3") parses to a string, not an array — split it.
            if (typeof v === 'string') {
                return v.split(',').map(function (n) { return parseInt(n, 10); }).filter(function (n) { return !isNaN(n); });
            }
            return Array.isArray(v) ? v : [];
        } catch (e) { return []; }
    }

    // ---- cascading-filter logic ----

    function applyVendorFilter() {
        var vendorId = parseInt($vendorSelect.val(), 10) || null;
        var partsIds = [];
        if (vendorId) {
            var $opt = $vendorSelect.find('option:selected');
            partsIds = safeJsonArray($opt.attr('data-parts'));
        }
        var partId = parseInt($partSelect.val(), 10) || null;

        $partSelect.find('option').each(function () {
            var id = parseInt($(this).val(), 10) || 0;
            if (id === 0) return; // skip the placeholder
            var hide = !!(vendorId && partsIds.indexOf(id) === -1);
            $(this).prop('hidden', hide);
        });
        refreshSelectpicker($partSelect);
        // If currently selected part is now hidden, clear it
        if (partId && $partSelect.find('option[value="' + partId + '"]').prop('hidden')) {
            $partSelect.val('');
            refreshSelectpicker($partSelect);
        }
    }

    function applyPartFilter() {
        var partId = parseInt($partSelect.val(), 10) || null;
        var vendorsIds = [];
        if (partId) {
            var $opt = $partSelect.find('option:selected');
            vendorsIds = safeJsonArray($opt.attr('data-vendors'));
        }
        var vendorId = parseInt($vendorSelect.val(), 10) || null;

        $vendorSelect.find('option').each(function () {
            var id = parseInt($(this).val(), 10) || 0;
            if (id === 0) return;
            var hide = !!(partId && vendorsIds.indexOf(id) === -1);
            $(this).prop('hidden', hide);
        });
        refreshSelectpicker($vendorSelect);
        if (vendorId && $vendorSelect.find('option[value="' + vendorId + '"]').prop('hidden')) {
            $vendorSelect.val('');
            refreshSelectpicker($vendorSelect);
        }
    }

    function refreshVendorPartRow() {
        var vendorId = parseInt($vendorSelect.val(), 10) || null;
        var partId = parseInt($partSelect.val(), 10) || null;
        if (!vendorId || !partId) {
            $vendorPartRow.hide();
            $addToCartBtn.prop('disabled', true);
            $vendorPartNoSelect.empty();
            refreshSelectpicker($vendorPartNoSelect);
            return;
        }
        var key = vendorId + ':' + partId;
        var options = VENDOR_PARTS_INDEX[key] || [];
        if (options.length === 0) {
            $vendorPartRow.show();
            $addToCartBtn.prop('disabled', true);
            $vendorPartNoSelect.html('<option value="" disabled>Brak katalogu u tego dostawcy dla tej części</option>');
            refreshSelectpicker($vendorPartNoSelect);
            return;
        }
        var html = '';
        options.forEach(function (vp) {
            var prod = vp.producer_part_no
                ? ' <small class="text-muted">(prod: ' + escapeHtml(vp.producer_part_no) + ')</small>'
                : '';
            html += '<option value="' + escapeHtml(vp.vendor_part_no) + '"' +
                ' data-vp-id="' + vp.id + '"' +
                ' data-producer-part-no="' + escapeHtml(vp.producer_part_no || '') + '"' +
                ' data-vendor-jm-id="' + vp.vendor_jm_id + '"' +
                ' data-unit-name="' + escapeHtml(vp.unit_name) + '"' +
                ' data-full-pack-quantity="' + vp.full_pack_quantity + '">' +
                escapeHtml(vp.vendor_part_no) + prod +
                ' <small class="text-muted">— ' + escapeHtml(vp.unit_name) + ' · opak. ' + vp.full_pack_quantity + '</small>' +
                '</option>';
        });
        $vendorPartNoSelect.html(html);
        refreshSelectpicker($vendorPartNoSelect);
        $vendorPartNoSelect.selectpicker('val', options[0].vendor_part_no);
        refreshSelectpicker($vendorPartNoSelect);
        $vendorPartRow.show();
        $addToCartBtn.prop('disabled', false);
    }

    // ---- active-docs / cart rendering ----

    // Active docs for the *currently picked* vendor+part combo — shown
    // in the strip card above the cart as soon as both pickers hold a
    // value (no need to add anything to the cart first).
    function loadSelectionDocs() {
        var vendorId = parseInt($vendorSelect.val(), 10) || null;
        var partId = parseInt($partSelect.val(), 10) || null;
        if (!vendorId || !partId) {
            $selectionDocsCard.hide();
            $selectionDocsBody.collapse('hide');
            $selectionDocsContent.empty();
            selectionDocsCache = { options: [], docs: {} };
            return;
        }
        var options = VENDOR_PARTS_INDEX[vendorId + ':' + partId] || [];
        var vpIds = options.map(function (vp) { return vp.id; });
        if (vpIds.length === 0) {
            $selectionDocsCard.hide();
            return;
        }
        $.ajax({
            url: PURCHASE_CART_BASE + '/cart-active-docs.php',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(vpIds),
            dataType: 'json'
        }).done(function (docs) {
            renderSelectionDocs(options, docs || {});
        }).fail(function () {
            renderSelectionDocs(options, {});
        });
    }

    function renderSelectionDocs(options, docs) {
        selectionDocsCache = { options: options, docs: docs };
        var html = '';
        var total = 0;
        options.forEach(function (vp) {
            var list = docs[vp.id] || [];
            if (list.length === 0) return;
            total += list.length;
            html += '<div class="mb-1"><strong>' + escapeHtml(vp.vendor_part_no) + '</strong>' +
                ' <span class="text-muted">(' + list.length + ')</span>: ';
            list.forEach(function (d) {
                html += '<span class="badge ' + stateBadgeClass(d.state) + ' mr-1" title="' +
                    stateBadgeLabel(d.state) + ' · ' + formatQty(d.quantity) + ' szt. · ' + formatPrice(d.unit_price) + '">' +
                    docTypeIcon(d.doc_type) + ' ' + escapeHtml(d.number) + ' · ' + stateBadgeLabel(d.state) +
                    '</span>';
            });
            html += '</div>';
        });
        if (total === 0) {
            html = '<span class="text-muted">Brak aktywnych dokumentów dla tej pozycji.</span>';
        }
        $selectionDocsContent.html(html);
        $selectionDocsCard.show();
        updateSelectionDocsBadge();
    }

    // Header badge reflects the *specific* VendorPart currently picked
    // in "Numer u dostawcy" — not the combo-wide total.
    function updateSelectionDocsBadge() {
        var $opt = $vendorPartNoSelect.find('option:selected');
        var vpId = parseInt($opt.attr('data-vp-id'), 10) || null;
        var list = (vpId !== null && selectionDocsCache.docs[vpId]) ? selectionDocsCache.docs[vpId] : [];
        if (list.length > 0) {
            $selectionDocsCount.text(list.length).show();
        } else {
            $selectionDocsCount.hide();
        }
    }

    function loadActiveDocs() {
        if (cart.items.length === 0) {
            cart.activeDocs = {};
            renderCart();
            return;
        }
        var vpIds = cart.items.map(function (i) { return i.vendor_part_id; });
        $.ajax({
            url: PURCHASE_CART_BASE + '/cart-active-docs.php',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(vpIds),
            dataType: 'json'
        }).done(function (docs) {
            cart.activeDocs = docs || {};
            renderCart();
        }).fail(function () {
            cart.activeDocs = {};
            renderCart();
        });
    }

    function renderCart() {
        if (cart.items.length === 0) {
            $cartCard.hide();
            return;
        }
        $cartCard.show();
        $cartCount.text(cart.items.length);

        var byVendor = {};
        cart.items.forEach(function (item, idx) {
            if (!byVendor[item.vendor_id]) {
                byVendor[item.vendor_id] = { name: item.vendor_name, items: [] };
            }
            byVendor[item.vendor_id].items.push({ item: item, idx: idx });
        });

        var html = '';
        Object.keys(byVendor).forEach(function (vid) {
            var group = byVendor[vid];
            var groupValue = group.items.reduce(function (sum, x) {
                return sum + (x.item.unit_price ? x.item.unit_price * x.item.quantity : 0);
            }, 0);

            html += '<div class="vendor-group mb-4" data-vendor-id="' + vid + '">' +
                '<div class="d-flex justify-content-between align-items-center mb-2">' +
                '<h6 class="mb-0">' + escapeHtml(group.name) +
                    ' <small class="text-muted">(' + group.items.length + ' poz. · łącznie ' + formatPrice(groupValue) + ')</small>' +
                    '</h6>' +
                '</div>' +
                '<div class="table-responsive">' +
                '<table class="table table-sm table-striped">' +
                '<thead class="thead-light">' +
                '<tr>' +
                    '<th>Część</th>' +
                    '<th>Numer u dostawcy</th>' +
                    '<th>Numer u producenta</th>' +
                    '<th>JM</th>' +
                    '<th>Ilość</th>' +
                    '<th>Cena</th>' +
                    '<th>Waluta</th>' +
                    '<th>Wartość</th>' +
                    '<th>Aktywne dok.</th>' +
                    '<th>Akcje</th>' +
                '</tr>' +
                '</thead><tbody>';

            group.items.forEach(function (x) {
                var item = x.item;
                var lineTotal = (item.unit_price ? item.unit_price * item.quantity : 0);
                var docs = cart.activeDocs[item.vendor_part_id] || [];
                var docBadges = '';
                docs.forEach(function (d) {
                    docBadges += '<span class="badge ' + stateBadgeClass(d.state) + ' mr-1" title="' +
                        stateBadgeLabel(d.state) + ' · ' + formatQty(d.quantity) + ' · ' + formatPrice(d.unit_price) + '">' +
                        docTypeIcon(d.doc_type) + ' ' + escapeHtml(d.number) + ' · ' + stateBadgeLabel(d.state) +
                        '</span>';
                });
                if (!docBadges) {
                    docBadges = '<span class="text-muted">—</span>';
                }

                html += '<tr>' +
                    '<td>' + escapeHtml(item.part_name) + '</td>' +
                    '<td>' + escapeHtml(item.vendor_part_no) + '</td>' +
                    '<td>' + (item.producer_part_no ? escapeHtml(item.producer_part_no) : '<span class="text-muted">—</span>') + '</td>' +
                    '<td>' + escapeHtml(item.unit_name) + '</td>' +
                    '<td>' + formatQty(item.quantity) + '</td>' +
                    '<td>' + formatPrice(item.unit_price) + '</td>' +
                    '<td>' + escapeHtml(item.currency) + '</td>' +
                    '<td>' + formatPrice(lineTotal) + '</td>' +
                    '<td style="white-space: nowrap;">' + docBadges + '</td>' +
                    '<td><button type="button" class="btn btn-sm btn-danger remove-item-btn" data-idx="' + x.idx + '">' +
                        '<i class="bi bi-trash"></i></button></td>' +
                    '</tr>';
            });

            html += '</tbody></table></div>' +
                '<div class="d-flex justify-content-end">' +
                    '<button type="button" class="btn btn-primary mr-2 create-rfq-btn" data-vendor-id="' + vid + '">' +
                    '<i class="bi bi-file-earmark-text"></i> Utwórz zapytanie</button>' +
                    '<button type="button" class="btn btn-success create-po-btn" data-vendor-id="' + vid + '">' +
                    '<i class="bi bi-bag-check"></i> Utwórz zamówienie</button>' +
                '</div>' +
                '</div>';
        });
        $cartBody.html(html);
    }

    function addCurrentSelectionToCart() {
        if ($addToCartBtn.prop('disabled')) return;
        var vendorId = parseInt($vendorSelect.val(), 10) || null;
        var partId = parseInt($partSelect.val(), 10) || null;
        var $opt = $vendorPartNoSelect.find('option:selected');
        var vendorPartId = parseInt($opt.attr('data-vp-id'), 10) || null;
        var vendorPartNo = $vendorPartNoSelect.val();
        var producerPartNo = $opt.attr('data-producer-part-no') || null;
        var qty = parseFloat($cartQty.val());
        var priceRaw = $cartPrice.val() === '' ? NaN : parseFloat($cartPrice.val());
        var unitPrice = (!isNaN(priceRaw) && priceRaw >= 0) ? priceRaw : null;
        var currency = $cartCurrency.val() || 'PLN';
        if (!vendorId || !partId || !vendorPartId || isNaN(qty) || qty <= 0) {
            setAlert('Podaj prawidłową ilość.', 'warning');
            return;
        }
        if (!isNaN(priceRaw) && priceRaw < 0) {
            setAlert('Cena nie może być ujemna.', 'warning');
            return;
        }
        var $vendorOpt = $vendorSelect.find('option:selected');
        var $partOpt = $partSelect.find('option:selected');
        var existing = cart.items.find(function (i) {
            return i.vendor_part_id === vendorPartId;
        });
        if (existing) {
            existing.quantity += qty;
            // A price entered on the repeat add refreshes the item's
            // price/currency; empty price keeps the previous one.
            if (unitPrice !== null) {
                existing.unit_price = unitPrice;
                existing.currency = currency;
            }
        } else {
            cart.items.push({
                vendor_part_id    : vendorPartId,
                vendor_id         : vendorId,
                vendor_name       : $vendorOpt.attr('data-name') || '',
                vendor_part_no    : vendorPartNo,
                producer_part_no  : producerPartNo || null,
                part_name         : $partOpt.attr('data-name') || '',
                producer_name     : '',
                unit_name         : $opt.attr('data-unit-name') || '',
                vendor_jm_id      : parseInt($opt.attr('data-vendor-jm-id'), 10) || null,
                full_pack_quantity: parseFloat($opt.attr('data-full-pack-quantity')) || null,
                quantity          : qty,
                unit_price        : unitPrice,
                currency          : currency
            });
        }
        // Reset qty + price, clear the part picker (vendor stays selected so the
        // user can queue the next part from the same vendor), hide the
        // picker row and the docs strip. Currency stays sticky — usually
        // several items in a row share it.
        $cartQty.val('1');
        $cartPrice.val('');
        $partSelect.val('');
        refreshSelectpicker($partSelect);
        applyPartFilter();          // part cleared → restore full vendor list
        refreshVendorPartRow();
        loadSelectionDocs();
        renderCart();
        loadActiveDocs();
        setAlert('Dodano pozycję do koszyka.', 'success');
    }

    function buildPayload(docType, vendorId, items) {
        return {
            doc_type  : docType,
            vendor_id : vendorId,
            date      : '',
            comment   : '',
            items     : JSON.stringify(items.map(function (i) {
                return {
                    vendor_part_id : i.vendor_part_id,
                    quantity       : i.quantity,
                    unit_price     : i.unit_price === null ? '' : i.unit_price,
                    currency       : i.currency
                };
            }))
        };
    }

    function submit(docType, btn) {
        var $btn = $(btn);
        var vendorId = parseInt($btn.data('vendor-id'), 10);
        if (!vendorId) { setAlert('Brak identyfikatora dostawcy.', 'danger'); return; }
        var items = cart.items.filter(function (i) { return i.vendor_id === vendorId; });
        if (items.length === 0) { setAlert('Brak pozycji dla tego dostawcy.', 'warning'); return; }
        $btn.prop('disabled', true).text('Tworzę...');
        $.ajax({
            url: PURCHASE_CART_BASE + '/cart-action.php',
            method: 'POST',
            data: buildPayload(docType, vendorId, items),
            dataType: 'json'
        }).done(function (response) {
            if (response && response.success) {
                setAlert('Utworzono dokument. Przekierowuję...', 'success');
                window.location.href = response.redirect;
            } else {
                setAlert('Błąd: ' + (response && response.error ? response.error : 'nieznany'), 'danger');
                $btn.prop('disabled', false).html(docType === 'po'
                    ? '<i class="bi bi-bag-check"></i> Utwórz zamówienie'
                    : '<i class="bi bi-file-earmark-text"></i> Utwórz zapytanie');
            }
        }).fail(function (xhr) {
            setAlert('Błąd HTTP ' + xhr.status, 'danger');
            $btn.prop('disabled', false).html(docType === 'po'
                ? '<i class="bi bi-bag-check"></i> Utwórz zamówienie'
                : '<i class="bi bi-file-earmark-text"></i> Utwórz zapytanie');
        });
    }

    // ---- event handlers ----

    $vendorSelect.on('change', function () {
        applyVendorFilter();
        refreshVendorPartRow();
        loadSelectionDocs();
    });

    $partSelect.on('change', function () {
        applyPartFilter();
        refreshVendorPartRow();
        loadSelectionDocs();
    });

    // Switching between alternates re-scopes the header badge instantly
    // (no refetch needed — docs for the whole combo are cached).
    $vendorPartNoSelect.on('change', function () {
        updateSelectionDocsBadge();
    });

    // Chevron flip driven by real collapse events (CSS aria-expanded
    // selector kept as backup).
    $selectionDocsBody.on('show.bs.collapse', function () {
        $selectionDocsHeader.addClass('is-open');
    }).on('hide.bs.collapse', function () {
        $selectionDocsHeader.removeClass('is-open');
    });

    $addToCartBtn.on('click', function () {
        addCurrentSelectionToCart();
    });

    // Remove cart item
    $cartBody.on('click', '.remove-item-btn', function () {
        var idx = parseInt($(this).data('idx'), 10);
        cart.items.splice(idx, 1);
        renderCart();
        loadActiveDocs();
        setAlert('Usunięto pozycję.', 'info');
    });

    // Per-vendor-group create buttons
    $cartBody.on('click', '.create-rfq-btn, .create-po-btn', function () {
        var docType = $(this).hasClass('create-rfq-btn') ? 'rfq' : 'po';
        submit(docType, this);
    });

    // Clear cart
    $clearBtn.on('click', function () {
        if (cart.items.length === 0) { return; }
        if (!window.confirm('Wyczyścić koszyk? ' + cart.items.length + ' pozycji zostanie usuniętych.')) { return; }
        cart.items = [];
        cart.activeDocs = {};
        renderCart();
        setAlert('Koszyk wyczyszczony.', 'info');
    });

    // Initial render
    renderCart();
})();
