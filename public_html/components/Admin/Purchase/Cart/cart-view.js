// Koszyk — client-side cart state (in-memory; lost on refresh).
// Two-step wizard:
//   Step 1: pick vendor + optional date/comment + "Dalej" →
//   Step 2: vendor locked, edit date/comment, add items, "Utwórz" → creates RFQ or PO.
//
// The two sister endpoints (cart-action.php, search-vendor-parts.php)
// run on the same origin so we just hit them by relative URL.

(function () {
    'use strict';

    var cart = {
        step        : 1,        // 1 = picking vendor, 2 = adding items
        vendorId    : null,
        vendorName  : '',
        date        : '',
        comment     : '',
        items       : []        // {vendor_part_id, vendor_part_no, part_name, producer_name, unit_name, quantity, unit_price, currency}
    };

    var $vendor         = $('#cartVendor');
    var $date           = $('#cartDate');
    var $comment        = $('#cartComment');
    var $nextBtn        = $('#nextBtn');
    var $backBtn        = $('#backBtn');
    var $vendorDisplay  = $('#vendorNameDisplay');
    var $step1VendorRow = $('#step1VendorRow');
    var $step2VendorRow = $('#step2VendorRow');
    var $step1NextRow   = $('#step1NextRow');
    var $step2Content   = $('#step2Content');
    var $stepHint       = $('#stepHint');
    var $addRow         = $('#addItemRow');
    var $addPart        = $('#addVendorPart');
    var $addQty         = $('#addQty');
    var $addPrice       = $('#addPrice');
    var $addCur         = $('#addCurrency');
    var $addBtn         = $('#addItemBtn');
    var $itemsRow       = $('#cartItemsRow');
    var $itemsBody      = $('#cartItemsBody');
    var $count          = $('#cartCount');
    var $actions        = $('#finalActionsRow');
    var $rfqBtn         = $('#createRfqBtn');
    var $poBtn          = $('#createPoBtn');
    var $clearBtn       = $('#clearCartBtn');

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

    function setStep(n) {
        cart.step = n;
        if (n === 1) {
            $step1VendorRow.show();
            $step2VendorRow.hide();
            $step1NextRow.show();
            $step2Content.hide();
            $stepHint.text('');
            setAlert('');
        } else {
            $step1VendorRow.hide();
            $step2VendorRow.show();
            $step1NextRow.hide();
            $step2Content.show();
            $stepHint.text('(edytowalne)');
            $vendorDisplay.text(cart.vendorName);
            showAddRowIfReady();
        }
    }

    function showAddRowIfReady() {
        if (cart.step === 2 && cart.vendorId) {
            $addRow.show();
        } else {
            $addRow.hide();
            $itemsRow.hide();
            $actions.hide();
        }
    }

    function loadVendorParts(query) {
        if (!cart.vendorId) {
            $addPart.find('option').not(':first').remove();
            refreshSelectpicker($addPart);
            return;
        }
        $.ajax({
            url: PURCHASE_CART_BASE + '/search-vendor-parts.php',
            method: 'GET',
            data: { vendor_id: cart.vendorId, q: query || '' },
            dataType: 'json'
        }).done(function (rows) {
            $addPart.find('option').not(':first').remove();
            (rows || []).forEach(function (r) {
                var $opt = $('<option></option>')
                    .attr('value', r.id)
                    .attr('data-vendor-part-no', r.vendor_part_no)
                    .attr('data-part-name', r.part_name || '')
                    .attr('data-producer-name', r.producer_name || '')
                    .attr('data-unit-name', r.unit_name || '')
                    .attr('data-vendor-jm-id', r.vendor_jm_id)
                    .text(r.label || r.vendor_part_no);
                $addPart.append($opt);
            });
            refreshSelectpicker($addPart);
        }).fail(function (xhr) {
            setAlert('Błąd ładowania artykułów: HTTP ' + xhr.status, 'danger');
        });
    }

    function clearAddForm() {
        $addPart.val('');
        $addQty.val('');
        $addPrice.val('');
        $addCur.val('PLN');
        refreshSelectpicker($addPart);
    }

    function renderCart() {
        if (cart.items.length === 0) {
            $itemsRow.hide();
            $actions.hide();
            return;
        }
        $itemsRow.show();
        $actions.show();
        $count.text(cart.items.length);

        var html = '';
        cart.items.forEach(function (item, idx) {
            html += '<tr>' +
                '<td>' + escapeHtml(item.vendor_part_no) + '</td>' +
                '<td>' + escapeHtml(item.part_name || '') + '</td>' +
                '<td>' + escapeHtml(item.producer_name || '') + '</td>' +
                '<td>' + escapeHtml(item.unit_name || '') + '</td>' +
                '<td>' + formatQty(item.quantity) + '</td>' +
                '<td>' + (item.unit_price !== null && item.unit_price !== '' ? formatPrice(item.unit_price) : '<span class="text-muted">—</span>') + '</td>' +
                '<td>' + escapeHtml(item.currency || 'PLN') + '</td>' +
                '<td><button type="button" class="btn btn-sm btn-danger remove-item-btn" data-idx="' + idx + '">' +
                    '<i class="bi bi-trash"></i></button></td>' +
                '</tr>';
        });
        $itemsBody.html(html);

        $itemsBody.find('.remove-item-btn').on('click', function () {
            var idx = parseInt($(this).attr('data-idx'), 10);
            cart.items.splice(idx, 1);
            renderCart();
        });
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
        var v = parseFloat(n);
        if (isNaN(v)) return '';
        return v.toFixed(4).replace(/\.?0+$/, '');
    }

    function validateBeforeCreate() {
        if (!cart.vendorId) {
            setAlert('Wybierz dostawcę.', 'warning');
            return false;
        }
        if (cart.items.length === 0) {
            setAlert('Dodaj co najmniej jedną pozycję.', 'warning');
            return false;
        }
        setAlert('');
        return true;
    }

    // ---- event handlers ----

    // Vendor selection (step 1 only — step 2 vendor is locked)
    $vendor.on('change', function () {
        cart.vendorId = parseInt($(this).val(), 10) || null;
        cart.vendorName = cart.vendorId ? $vendor.find('option:selected').text() : '';
        clearAddForm();
        loadVendorParts('');
    });

    // Date + comment sync (works in both steps since inputs are shared)
    $date.on('change',    function () { cart.date    = $(this).val(); });
    $comment.on('change', function () { cart.comment = $(this).val(); });

    // Step 1 → Step 2 transition
    $nextBtn.on('click', function () {
        if (!cart.vendorId) {
            setAlert('Wybierz dostawcę przed kontynuacją.', 'warning');
            return;
        }
        setStep(2);
    });

    // Step 2 → Step 1 (clear items + comment + date)
    $backBtn.on('click', function (e) {
        e.preventDefault();
        cart.items    = [];
        cart.comment  = '';
        cart.date     = '';
        $comment.val('');
        $date.val('');
        renderCart();   // hides items + actions rows
        setStep(1);
    });

    // Live search on the VendorPart picker (only in step 2)
    $addPart.on('keyup', function (e) {
        var $searchInput = $addPart.parent().find('.bs-searchbox input');
        if ($searchInput.length === 0) { return; }
        var q = $searchInput.val();
        if (q.length < 2 && e.which !== 13) { return; }
        loadVendorParts(q);
    });

    $addBtn.on('click', function () {
        if (cart.step !== 2) { return; }
        var $sel = $addPart.find('option:selected');
        var vpId = parseInt($sel.attr('value'), 10) || 0;
        var qty  = parseFloat($addQty.val());
        var price = $addPrice.val() === '' ? null : parseFloat($addPrice.val());
        var cur  = $addCur.val();

        if (!vpId) {
            setAlert('Wybierz artykuł.', 'warning');
            return;
        }
        if (isNaN(qty) || qty <= 0) {
            setAlert('Podaj prawidłową ilość.', 'warning');
            return;
        }
        if (price !== null && (isNaN(price) || price < 0)) {
            setAlert('Cena musi być liczbą nieujemną.', 'warning');
            return;
        }

        cart.items.push({
            vendor_part_id : vpId,
            vendor_part_no : $sel.attr('data-vendor-part-no') || '',
            part_name      : $sel.attr('data-part-name')     || '',
            producer_name  : $sel.attr('data-producer-name') || '',
            unit_name      : $sel.attr('data-unit-name')     || '',
            quantity       : qty,
            unit_price     : price,
            currency       : cur
        });

        renderCart();
        clearAddForm();
        setAlert('Dodano pozycję do koszyka.', 'success');
    });

    function buildPayload(forcedType) {
        return {
            vendor_id : cart.vendorId,
            doc_type  : forcedType,
            date      : cart.date || '',
            comment   : cart.comment || '',
            items     : JSON.stringify(cart.items.map(function (i) {
                return {
                    vendor_part_id : i.vendor_part_id,
                    quantity       : i.quantity,
                    unit_price     : i.unit_price === null ? '' : i.unit_price,
                    currency       : i.currency
                };
            }))
        };
    }

    function submit(forcedType, btn) {
        if (!validateBeforeCreate()) { return; }
        cart.date = $date.val() || '';
        cart.comment = $comment.val() || '';
        var $btn = $(btn);
        $btn.prop('disabled', true).text('Tworzę...');
        $.ajax({
            url: PURCHASE_CART_BASE + '/cart-action.php',
            method: 'POST',
            data: buildPayload(forcedType),
            dataType: 'json'
        }).done(function (response) {
            if (response && response.success) {
                setAlert('Utworzono dokument. Przekierowuję...', 'success');
                window.location.href = response.redirect;
            } else {
                setAlert('Błąd: ' + (response && response.error ? response.error : 'nieznany'), 'danger');
                $btn.prop('disabled', false).html(forcedType === 'po'
                    ? '<i class="bi bi-bag-check"></i> Utwórz zamówienie'
                    : '<i class="bi bi-file-earmark-text"></i> Utwórz zapytanie');
            }
        }).fail(function (xhr) {
            setAlert('Błąd HTTP ' + xhr.status, 'danger');
            $btn.prop('disabled', false).html(forcedType === 'po'
                ? '<i class="bi bi-bag-check"></i> Utwórz zamówienie'
                : '<i class="bi bi-file-earmark-text"></i> Utwórz zapytanie');
        });
    }

    $rfqBtn.on('click', function () { submit('rfq', this); });
    $poBtn.on('click',  function () { submit('po',  this); });

    $clearBtn.on('click', function () {
        if (cart.items.length === 0) { return; }
        if (!window.confirm('Wyczyścić koszyk? ' + cart.items.length + ' pozycji zostanie usuniętych.')) { return; }
        cart.items = [];
        renderCart();
        setAlert('Koszyk wyczyszczony.', 'info');
    });

    // Initial render — show step 1 by default
    setStep(1);
})();
