// Koszyk — cascading vendor/part pickers plus a search modal that land
// items in a shared cart grouped by vendor (client-side state).
//
// Flow:
//   1. Pick a vendor → parts list narrows to what that vendor carries.
//      Pick a part → vendors list narrows accordingly.
//   2. "Numer u dostawcy" scopes by whatever constraint exists: vendor
//      picked → that vendor's items; part picked → that part's items
//      across vendors; both → combo alternates; neither → empty.
//   3. The magnifier icon next to it opens a search modal (AJAX endpoint
//      vendor-part-search.php) for finding items by vendor/producer part
//      no or part/vendor name across the whole catalog; picking a result
//      auto-selects its vendor AND part.
//   4. Qty / price / currency row is always visible; "Dodaj" enables once
//      a valid VendorPart is selected. Same vendor_part_id merges in qty
//      instead of duplicating.
//   5. Each vendor group in the cart has its own "Utwórz zapytanie"
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
    var $vpSearchModal  = $('#vpSearchModal');
    var $vpSearchInput  = $('#vpSearchInput');
    var $vpSearchStatus = $('#vpSearchStatus');
    var $vpSearchResults = $('#vpSearchResults');
    var vpSearchRows = [];  // last endpoint response, indexed for row buttons
    var vpSearchTimer = null;
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

    // ---- three-way picker sync ----
    // Vendor/part filter each other's options. "Numer u dostawcy" starts
    // empty and scopes by whatever constraint exists: vendor picked →
    // that vendor's items; part picked → that part's items across
    // vendors; both → combo alternates. Catalog-wide lookup lives in
    // the search modal.

    function updateAddBtnState() {
        var $sel = $vendorPartNoSelect.find('option:selected');
        var ok = $sel.length > 0
            && !$sel.prop('hidden')
            && (parseInt($sel.attr('data-vp-id'), 10) || 0) > 0;
        $addToCartBtn.prop('disabled', !ok);
    }

    function getScopedVpOptions(vendorId, partId) {
        var out = [];
        Object.keys(VENDOR_PARTS_INDEX).forEach(function (key) {
            var parts = key.split(':');
            var vid = parseInt(parts[0], 10);
            var pid = parseInt(parts[1], 10);
            if (vendorId && vid !== vendorId) return;
            if (partId && pid !== partId) return;
            VENDOR_PARTS_INDEX[key].forEach(function (vp) { out.push(vp); });
        });
        return out;
    }

    function refreshVendorPartRow(preferredVpId) {
        var vendorId = parseInt($vendorSelect.val(), 10) || null;
        var partId = parseInt($partSelect.val(), 10) || null;
        // Nothing constrained → keep the picker empty.
        if (!vendorId && !partId) {
            $vendorPartNoSelect.empty();
            refreshSelectpicker($vendorPartNoSelect);
            $addToCartBtn.prop('disabled', true);
            return;
        }
        var options = getScopedVpOptions(vendorId, partId);
        if (options.length === 0) {
            $vendorPartNoSelect.empty();
            refreshSelectpicker($vendorPartNoSelect);
            $addToCartBtn.prop('disabled', true);
            return;
        }
        var html = '';
        options.forEach(function (vp) {
            // Producer part no rides as subtext — visible under the label
            // in the dropdown and matched by live-search typing.
            var subtext = vp.producer_part_no
                ? ' data-subtext="' + escapeHtml(vp.producer_part_no) + '"'
                : '';
            html += '<option value="' + vp.id + '"' +
                subtext +
                ' data-vp-id="' + vp.id + '"' +
                ' data-vendor-id="' + vp.vendor_id + '"' +
                ' data-part-id="' + vp.parts_id + '"' +
                ' data-vendor-part-no="' + escapeHtml(vp.vendor_part_no) + '"' +
                ' data-producer-part-no="' + escapeHtml(vp.producer_part_no || '') + '"' +
                ' data-vendor-jm-id="' + vp.vendor_jm_id + '"' +
                ' data-unit-name="' + escapeHtml(vp.unit_name) + '"' +
                ' data-full-pack-quantity="' + vp.full_pack_quantity + '">' +
                escapeHtml(vp.vendor_part_no) +
                '</option>';
        });
        $vendorPartNoSelect.html(html);
        // Pre-select ONLY on an explicit hand-off (search modal) or when
        // exactly one option exists — otherwise the user chooses.
        var preferred = null;
        if (preferredVpId) {
            options.forEach(function (vp) { if (vp.id === preferredVpId) preferred = vp.id; });
        }
        var targetId = null;
        if (preferred !== null) {
            targetId = String(preferred);
        } else if (options.length === 1) {
            targetId = String(options[0].id);
        }
        // Explicit selectpicker('val') + double refresh — plain .val()
        // alone proved unreliable on freshly-built option lists.
        refreshSelectpicker($vendorPartNoSelect);
        if (targetId !== null && typeof $vendorPartNoSelect.selectpicker === 'function') {
            try { $vendorPartNoSelect.selectpicker('val', targetId); } catch (e) { /* noop */ }
            refreshSelectpicker($vendorPartNoSelect);
        }
        updateAddBtnState();
    }

    // ---- modal search flow ----

    function renderVpSearchResults(rows) {
        vpSearchRows = rows || [];
        var html = '';
        vpSearchRows.forEach(function (r, i) {
            html += '<tr>' +
                '<td>' + escapeHtml(r.vendor_part_no) + '</td>' +
                '<td>' + (r.producer_part_no ? escapeHtml(r.producer_part_no) : '—') + '</td>' +
                '<td>' + escapeHtml(r.vendor_name) + '</td>' +
                '<td>' + escapeHtml(r.part_name) + '</td>' +
                '<td>' + escapeHtml(r.unit_name) + ' · opak. ' + formatQty(r.full_pack_quantity) + '</td>' +
                '<td><button type="button" class="btn btn-sm btn-primary vp-pick-btn" data-idx="' + i + '">Wybierz</button></td>' +
                '</tr>';
        });
        $vpSearchResults.html(html);
    }

    function runVpSearch() {
        var q = $.trim($vpSearchInput.val());
        if (q.length < 2) {
            $vpSearchStatus.removeClass('text-danger').text('Wpisz co najmniej 2 znaki…').show();
            renderVpSearchResults([]);
            return;
        }
        $vpSearchStatus.removeClass('text-danger').text('Szukam…').show();
        $.ajax({
            url: PURCHASE_CART_BASE + '/vendor-part-search.php?q=' + encodeURIComponent(q),
            method: 'GET',
            dataType: 'json'
        }).done(function (rows) {
            rows = rows || [];
            renderVpSearchResults(rows);
            $vpSearchStatus
                .removeClass('text-danger')
                .text(rows.length ? rows.length + ' wynik(ów).' : 'Brak wyników dla „' + q + '”.')
                .show();
        }).fail(function () {
            renderVpSearchResults([]);
            $vpSearchStatus.addClass('text-danger').text('Błąd pobierania danych.').show();
        });
    }

    // Picking a search result drives the other two pickers, then hands
    // the chosen id to refreshVendorPartRow so the populated alternates
    // list selects it (programmatic .val() does NOT fire change).
    function pickVp(r) {
        $vendorSelect.val(r.vendor_id);
        refreshSelectpicker($vendorSelect);
        $partSelect.val(r.parts_id);
        refreshSelectpicker($partSelect);
        applyVendorFilter();   // scope parts to this vendor
        applyPartFilter();     // scope vendors to this part
        refreshVendorPartRow(r.id);
        updateSelectionDocsBadge();
        loadSelectionDocs();
        $vpSearchModal.modal('hide');
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
        var vendorPartNo = $opt.attr('data-vendor-part-no') || '';
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
                producer_part_no  : producerPartNo,
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
        // Reset qty + price, clear the part picker (vendor stays selected
        // so the user can queue the next part from the same vendor).
        // Currency stays sticky — usually several items in a row share it.
        $cartQty.val('1');
        $cartPrice.val('');
        $partSelect.val('');
        refreshSelectpicker($partSelect);
        applyPartFilter();          // part cleared → restore full vendor list
        refreshVendorPartRow();     // combo incomplete → empties picker, add disabled
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

    // Picking a variant from a partially-scoped list completes the
    // combo: its vendor and/or part get auto-selected, the pickers
    // re-filter, and the variant list narrows to the combo's alternates
    // keeping the exact variant chosen.
    $vendorPartNoSelect.on('change', function () {
        var $opt = $(this).find('option:selected');
        var vid = parseInt($opt.attr('data-vendor-id'), 10) || null;
        var pid = parseInt($opt.attr('data-part-id'), 10) || null;
        var vpid = parseInt($opt.attr('data-vp-id'), 10) || null;
        var curVid = parseInt($vendorSelect.val(), 10) || null;
        var curPid = parseInt($partSelect.val(), 10) || null;
        var changed = false;
        if (vid && vid !== curVid) {
            $vendorSelect.val(vid);
            refreshSelectpicker($vendorSelect);
            applyVendorFilter();   // scope parts to this vendor
            changed = true;
        }
        if (pid && pid !== curPid) {
            $partSelect.val(pid);
            refreshSelectpicker($partSelect);
            applyPartFilter();     // scope vendors to this part
            changed = true;
        }
        if (changed) {
            // Defer the rebuild until bootstrap-select finishes its own
            // change dispatch — mutating the select mid-dispatch lets
            // bs-select's post-change render wipe the fresh selection.
            var keepVpId = vpid;
            setTimeout(function () { refreshVendorPartRow(keepVpId); }, 0);
        }
        updateSelectionDocsBadge();
        loadSelectionDocs();
    });

    // Magnifier next to the vp picker opens the global search modal.
    $('#vpSearchBtn').on('click', function (e) {
        e.preventDefault();
        $vpSearchModal.modal('show');
    });

    $vpSearchModal.on('shown.bs.modal', function () {
        $vpSearchInput.trigger('focus');
    });

    $vpSearchInput.on('input', function () {
        if (vpSearchTimer) { clearTimeout(vpSearchTimer); }
        vpSearchTimer = setTimeout(runVpSearch, 300);
    });

    $vpSearchResults.on('click', '.vp-pick-btn', function () {
        var idx = parseInt($(this).data('idx'), 10);
        var row = vpSearchRows[idx];
        if (!row) return;
        pickVp(row);
    });

    // Chevron flip driven by real collapse events (CSS aria-expanded
    // selector kept as backup).
    $selectionDocsBody.on('show.bs.collapse', function () {
        $selectionDocsHeader.addClass('is-open');
    }).on('hide.bs.collapse', function () {
        $selectionDocsHeader.removeClass('is-open');
    });

    // "Wyczysc" (next to Dodaj) clears the whole selection; the variant
    // picker empties and both pickers' full option lists are restored.
    $('#clearSelectionBtn').on('click', function () {
        $vendorSelect.val('');
        refreshSelectpicker($vendorSelect);
        $partSelect.val('');
        refreshSelectpicker($partSelect);
        applyVendorFilter();   // vendor cleared → restore full parts list
        applyPartFilter();     // part cleared → restore full vendors list
        refreshVendorPartRow();
        loadSelectionDocs();
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

    // Initial render — deferred to ready() so it runs AFTER header.js's
    // ready handler has initialized the selectpickers. A refresh() called
    // pre-init is swallowed, leaving the vp picker's menu stale.
    $(function () {
        renderCart();
        refreshVendorPartRow();
    });
})();
