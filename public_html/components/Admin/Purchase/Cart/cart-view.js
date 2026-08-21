// Koszyk — part-first OR vendor-first discovery, both feed a shared
// cart grouped by vendor (client-side state).
//
// Flow:
//   A. Pick a Part from the picker → "Dostępni dostawcy" table
//      refreshes with every VendorPart for that part (last unit
//      price from PO history + active RFQ/PO counts). Set qty +
//      "Dodaj do koszyka" per row.
//   B. Pick a Vendor → their full catalog (every active VendorPart
//      for that vendor) loads the same way. Same add-to-cart
//      path.
//   Either flow lands the item in the same shared cart,
//   grouped by vendor_id. Each vendor group has its own
//   "Utwórz zapytanie" / "Utwórz zamówienie" buttons — submits
//   only that group's items to POST /cart-action.php.

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
    var $partPicker        = $('#partPicker');
    var $partSummary       = $('#partSummary');
    var $vendorListCard    = $('#vendorListCard');
    var $vendorListBody    = $('#vendorListBody');
    var $vendorListEmpty   = $('#vendorListEmpty');
    var $vendorPicker      = $('#vendorPicker');
    var $vendorSummary     = $('#vendorSummary');
    var $vendorCatalogCard = $('#vendorCatalogCard');
    var $vendorCatalogBody = $('#vendorCatalogBody');
    var $vendorCatalogEmpty= $('#vendorCatalogEmpty');
    var $cartCard          = $('#cartCard');
    var $cartBody          = $('#cartBody');
    var $cartCount         = $('#cartCount');
    var $clearBtn          = $('#clearCartBtn');

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

    function loadParts(query) {
        $.ajax({
            url: PURCHASE_CART_BASE + '/parts-search.php',
            method: 'GET',
            data: { q: query || '' },
            dataType: 'json'
        }).done(function (rows) {
            $partPicker.find('option').not(':first').remove();
            (rows || []).forEach(function (r) {
                $partPicker.append(
                    $('<option></option>')
                        .attr('value', r.id)
                        .attr('data-name', r.name)
                        .attr('data-jm-name', r.jm_name || '')
                        .attr('data-jm-id', r.jm_id || 0)
                        .text(r.label || r.name)
                );
            });
            refreshSelectpicker($partPicker);
        }).fail(function (xhr) {
            setAlert('Błąd wyszukiwania części: HTTP ' + xhr.status, 'danger');
        });
    }

    function loadVendorsForPart(partId) {
        $vendorListBody.empty();
        $vendorListEmpty.hide();
        $vendorListCard.show();
        $.ajax({
            url: PURCHASE_CART_BASE + '/find-vendors-for-part.php',
            method: 'GET',
            data: { parts_id: partId },
            dataType: 'json'
        }).done(function (rows) {
            renderVendorTable(rows || []);
        }).fail(function (xhr) {
            setAlert('Błąd ładowania dostawców: HTTP ' + xhr.status, 'danger');
        });
    }

    function renderVendorTable(rows) {
        if (!rows || rows.length === 0) {
            $vendorListBody.empty();
            $vendorListEmpty.show();
            return;
        }
        $vendorListEmpty.hide();
        var html = '';
        rows.forEach(function (v) {
            var inCart = cart.items.some(function (i) { return i.vendor_part_id === v.id; });
            html += '<tr data-vp-id="' + v.id + '"' +
                ' data-vendor-id="' + v.vendor_id + '"' +
                ' data-vendor-name="' + escapeHtml(v.vendor_name) + '"' +
                ' data-vendor-part-no="' + escapeHtml(v.vendor_part_no) + '"' +
                ' data-producer-part-no="' + escapeHtml(v.producer_part_no || '') + '"' +
                ' data-part-name="' + escapeHtml(v.part_name) + '"' +
                ' data-producer-name="' + escapeHtml(v.producer_name) + '"' +
                ' data-unit-name="' + escapeHtml(v.unit_name) + '"' +
                ' data-vendor-jm-id="' + v.vendor_jm_id + '"' +
                ' data-full-pack-quantity="' + v.full_pack_quantity + '"' +
                ' data-last-unit-price="' + (v.last_unit_price !== null ? v.last_unit_price : '') + '">' +
                '<td>' + escapeHtml(v.vendor_name) + (inCart ? ' <span class="badge badge-info">w koszyku</span>' : '') + '</td>' +
                '<td>' + escapeHtml(v.vendor_part_no) + '</td>' +
                '<td>' + escapeHtml(v.producer_part_no || '—') + '</td>' +
                '<td>' + escapeHtml(v.unit_name) + '</td>' +
                '<td>' + formatQty(v.full_pack_quantity) + '</td>' +
                '<td>' + formatPrice(v.last_unit_price) + '</td>' +
                '<td><input type="number" class="form-control form-control-sm vp-qty" value="1" min="0.0001" step="0.0001"></td>' +
                '<td><button type="button" class="btn btn-sm btn-success vp-add-btn"' + (inCart ? ' disabled' : '') + '>Dodaj</button></td>' +
                '</tr>';
        });
        $vendorListBody.html(html);
    }

    function loadVendorCatalog(vendorId) {
        $vendorCatalogBody.empty();
        $vendorCatalogEmpty.hide();
        $vendorCatalogCard.show();
        $.ajax({
            url: PURCHASE_CART_BASE + '/vendor-catalog.php',
            method: 'GET',
            data: { vendor_id: vendorId },
            dataType: 'json'
        }).done(function (rows) {
            renderVendorCatalog(rows || [], vendorId);
        }).fail(function (xhr) {
            setAlert('Błąd ładowania katalogu: HTTP ' + xhr.status, 'danger');
        });
    }

    function renderVendorCatalog(rows, vendorId) {
        // For the catalog view, vendor_id + vendor_name come from
        // the picker, not from each row (avoids duplicating).
        var vendorName = $vendorPicker.find('option:selected').data('name') || '';
        if (!rows || rows.length === 0) {
            $vendorCatalogBody.empty();
            $vendorCatalogEmpty.show();
            return;
        }
        $vendorCatalogEmpty.hide();
        var html = '';
        rows.forEach(function (v) {
            // Ensure vendor_id is set (server should already include it)
            v.vendor_id = vendorId;
            v.vendor_name = vendorName;
            var inCart = cart.items.some(function (i) { return i.vendor_part_id === v.id; });
            html += '<tr data-vp-id="' + v.id + '"' +
                ' data-vendor-id="' + vendorId + '"' +
                ' data-vendor-name="' + escapeHtml(vendorName) + '"' +
                ' data-vendor-part-no="' + escapeHtml(v.vendor_part_no) + '"' +
                ' data-producer-part-no="' + escapeHtml(v.producer_part_no || '') + '"' +
                ' data-part-name="' + escapeHtml(v.part_name) + '"' +
                ' data-producer-name="' + escapeHtml(v.producer_name) + '"' +
                ' data-unit-name="' + escapeHtml(v.unit_name) + '"' +
                ' data-vendor-jm-id="' + v.vendor_jm_id + '"' +
                ' data-full-pack-quantity="' + v.full_pack_quantity + '"' +
                ' data-last-unit-price="' + (v.last_unit_price !== null ? v.last_unit_price : '') + '">' +
                '<td>' + escapeHtml(v.part_name) + (inCart ? ' <span class="badge badge-info">w koszyku</span>' : '') + '</td>' +
                '<td>' + escapeHtml(v.vendor_part_no) + '</td>' +
                '<td>' + escapeHtml(v.producer_part_no || '—') + '</td>' +
                '<td>' + escapeHtml(v.unit_name) + '</td>' +
                '<td>' + formatQty(v.full_pack_quantity) + '</td>' +
                '<td>' + formatPrice(v.last_unit_price) + '</td>' +
                '<td><input type="number" class="form-control form-control-sm vp-qty" value="1" min="0.0001" step="0.0001"></td>' +
                '<td><button type="button" class="btn btn-sm btn-success vp-add-btn"' + (inCart ? ' disabled' : '') + '>Dodaj</button></td>' +
                '</tr>';
        });
        $vendorCatalogBody.html(html);
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
                        (d.doc_type === 'rfq' ? '⚠' : '📦') + ' ' + escapeHtml(d.number) + ' · ' + stateBadgeLabel(d.state) +
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

    function addVendorPartToCart($row) {
        var qty = parseFloat($row.find('.vp-qty').val());
        if (isNaN(qty) || qty <= 0) {
            setAlert('Podaj prawidłową ilość.', 'warning');
            return;
        }
        var data = $row.data();
        var existing = cart.items.find(function (i) { return i.vendor_part_id === data.vpId; });
        if (existing) {
            existing.quantity += qty;
        } else {
            cart.items.push({
                vendor_part_id    : data.vpId,
                vendor_id         : data.vendorId,
                vendor_name       : data.vendorName,
                vendor_part_no    : data.vendorPartNo,
                producer_part_no  : data.producerPartNo || null,
                part_name         : data.partName,
                producer_name     : data.producerName,
                unit_name         : data.unitName,
                vendor_jm_id      : data.vendorJmId,
                full_pack_quantity: data.fullPackQuantity,
                quantity          : qty,
                unit_price        : data.lastUnitPrice !== '' ? data.lastUnitPrice : null,
                currency          : 'PLN'
            });
        }
        // Mark the source row as "w koszyku" by replacing its Add button.
        $row.find('.vp-add-btn').prop('disabled', true).text('Dodano');
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

    // Part picker: change reloads vendor list
    $partPicker.on('change', function () {
        var partId = parseInt($(this).val(), 10) || null;
        if (!partId) {
            $vendorListCard.hide();
            $partSummary.text('');
            return;
        }
        var partLabel = $(this).find('option:selected').data('name') || '';
        $partSummary.text('Ładowanie dostawców dla: ' + partLabel + '…');
        loadVendorsForPart(partId);
    });

    // Part picker live-search: event delegation on a static ancestor
    // so selectpicker-internal DOM changes don't break the handler.
    $partPicker.closest('.card-body').on('input keyup', '.bs-searchbox input', function () {
        var q = $(this).val();
        if (q.length < 2) return;
        loadParts(q);
    });

    // Vendor-first picker: change loads that vendor's catalog
    $vendorPicker.on('change', function () {
        var vendorId = parseInt($(this).val(), 10) || null;
        if (!vendorId) {
            $vendorCatalogCard.hide();
            $vendorSummary.text('');
            return;
        }
        var vendorName = $(this).find('option:selected').data('name') || '';
        $vendorSummary.text('Ładowanie katalogu: ' + vendorName + '…');
        loadVendorCatalog(vendorId);
    });

    // Add row (works for both part-driven and vendor-driven tables)
    $vendorListBody.on('click', '.vp-add-btn', function () {
        addVendorPartToCart($(this).closest('tr'));
    });
    $vendorCatalogBody.on('click', '.vp-add-btn', function () {
        addVendorPartToCart($(this).closest('tr'));
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
        // Re-mark vendor rows (drop the disabled "Dodano" state) by
        // re-loading both views if they're visible.
        var partId = parseInt($partPicker.val(), 10);
        if (partId) loadVendorsForPart(partId);
        var vendorId = parseInt($vendorPicker.val(), 10);
        if (vendorId) loadVendorCatalog(vendorId);
        setAlert('Koszyk wyczyszczony.', 'info');
    });

    // Initial render
    renderCart();
})();
