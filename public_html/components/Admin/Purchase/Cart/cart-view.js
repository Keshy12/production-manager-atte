// Koszyk — client-side cart state (in-memory; lost on refresh).
// Two-step wizard:
//   Step 1: pick vendor + optional date/comment + "Dalej" →
//   Step 2: collapsed summary line with "Edytuj" / "Zmień dostawcę"
//            inline edit form (when "Edytuj" clicked)
//            + add-item form + items table + final action buttons.
//            also: "Dodaj nowy artykuł u dostawcy" modal for creating
//            a fresh list__vendor_part row inline.
//
// The sister endpoints (cart-action.php, search-vendor-parts.php,
// parts-search.php, vendor-part-add.php) run on the same origin so
// we just hit them by relative URL.

(function () {
    'use strict';

    var cart = {
        step        : 1,        // 1 = picking vendor, 2 = adding items
        vendorId    : null,
        vendorName  : '',
        date        : '',
        comment     : '',
        items       : []        // {vendor_part_id, vendor_part_no, producer_part_no, part_name, producer_name, unit_name, quantity, unit_price, currency}
    };

    // DOM refs
    var $vendor            = $('#cartVendor');
    var $date              = $('#cartDate');
    var $comment           = $('#cartComment');
    var $nextBtn           = $('#nextBtn');
    var $backBtn           = $('#backBtn');
    var $editParamsBtn     = $('#editParamsBtn');
    var $cancelEditBtn     = $('#cancelEditBtn');
    var $saveEditBtn       = $('#saveEditBtn');
    var $step1ParamsCard   = $('#step1ParamsCard');
    var $step2SummaryCard  = $('#step2SummaryCard');
    var $step2EditCard     = $('#step2EditCard');
    var $step2Content      = $('#step2Content');
    var $summaryVendor     = $('#summaryVendor');
    var $summaryDate       = $('#summaryDate');
    var $summaryComment    = $('#summaryComment');
    var $editVendorDisplay = $('#editVendorDisplay');
    var $cartDateEdit      = $('#cartDateEdit');
    var $cartCommentEdit   = $('#cartCommentEdit');
    var $addRow            = $('#addItemRow');
    var $addPart           = $('#addVendorPart');
    var $addQty            = $('#addQty');
    var $addPrice          = $('#addPrice');
    var $addCur            = $('#addCurrency');
    var $addBtn            = $('#addItemBtn');
    var $itemsRow          = $('#cartItemsRow');
    var $itemsBody         = $('#cartItemsBody');
    var $count             = $('#cartCount');
    var $actions           = $('#finalActionsRow');
    var $rfqBtn            = $('#createRfqBtn');
    var $poBtn             = $('#createPoBtn');
    var $clearBtn          = $('#clearCartBtn');

    // "Dodaj nowy artykuł u dostawcy" modal refs
    var $addVendorPartBtn   = $('#addVendorPartBtn');
    var $modalPartsPicker   = $('#modalPartsPicker');
    var $modalVendorPartNo  = $('#modalVendorPartNo');
    var $modalProducerPartNo = $('#modalProducerPartNo');
    var $modalVendorJm      = $('#modalVendorJm');
    var $modalFullPack      = $('#modalFullPack');
    var $saveVendorPartBtn  = $('#saveVendorPartBtn');

    // ---- helpers ----

    function refreshSelectpicker($el) {
        if (typeof $el.selectpicker === 'function') {
            try { $el.selectpicker('refresh'); } catch (e) { /* noop */ }
        }
    }

    function setAlert(msg, kind, container) {
        var $box = container ? $(container) : $('#alertContainer');
        if (!$box || !$box.length) { return; }
        if (!msg) { $box.empty(); return; }
        $box.html(
            '<div class="alert alert-' + (kind || 'info') + ' alert-dismissible fade show" role="alert">' +
            msg +
            '<button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>' +
            '</div>'
        );
    }

    function emptyDash(v) {
        return (v === null || v === undefined || v === '') ? '—' : v;
    }

    function refreshSummary() {
        $summaryVendor.text(emptyDash(cart.vendorName));
        $summaryDate.text(emptyDash(cart.date));
        $summaryComment.text(emptyDash(cart.comment));
    }

    function setStep(n) {
        cart.step = n;
        if (n === 1) {
            $step1ParamsCard.show();
            $step2SummaryCard.hide();
            $step2EditCard.hide();
            $step2Content.hide();
            setAlert('');
        } else {
            $step1ParamsCard.hide();
            $step2SummaryCard.show();
            $step2EditCard.hide();   // collapsed view by default
            $step2Content.show();
            refreshSummary();
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
                // Subtext = producer (falls back to vendor's unit if producer
                // missing) — bootstrap-select shows this as a smaller line
                // below the main label in the dropdown. vendor_part_no and
                // producer_part_no are intentionally NOT in the picker
                // label; they appear in the cart items table instead.
                var subtext = r.producer_name || r.unit_name || '';
                var $opt = $('<option></option>')
                    .attr('value', r.id)
                    .attr('data-vendor-part-no', r.vendor_part_no || '')
                    .attr('data-producer-part-no', r.producer_part_no || '')
                    .attr('data-part-name', r.part_name || '')
                    .attr('data-producer-name', r.producer_name || '')
                    .attr('data-unit-name', r.unit_name || '')
                    .attr('data-vendor-jm-id', r.vendor_jm_id)
                    .attr('data-subtext', subtext)
                    .text(r.label || r.vendor_part_no);
                $addPart.append($opt);
            });
            refreshSelectpicker($addPart);
        }).fail(function (xhr) {
            setAlert('Błąd ładowania artykułów: HTTP ' + xhr.status, 'danger');
        });
    }

    function loadModalParts(query) {
        $.ajax({
            url: PURCHASE_CART_BASE + '/parts-search.php',
            method: 'GET',
            data: { q: query || '' },
            dataType: 'json'
        }).done(function (rows) {
            $modalPartsPicker.find('option').not(':first').remove();
            (rows || []).forEach(function (r) {
                // Subtext = description (if different from name) with JM
                // appended; gives the admin more context when picking.
                var subParts = [];
                if (r.description && r.description !== r.name) {
                    subParts.push(r.description);
                }
                if (r.jm_name) {
                    subParts.push(r.jm_name);
                }
                var $opt = $('<option></option>')
                    .attr('value', r.id)
                    .attr('data-jm-id', r.jm_id)
                    .attr('data-name', r.name)
                    .attr('data-jm-name', r.jm_name)
                    .attr('data-subtext', subParts.join(' • '))
                    .text(r.label || r.name);
                $modalPartsPicker.append($opt);
            });
            refreshSelectpicker($modalPartsPicker);
        }).fail(function (xhr) {
            setModalAlert('Błąd wyszukiwania części: HTTP ' + xhr.status, 'danger');
        });
    }

    function setModalAlert(msg, kind) {
        setAlert(msg, kind, '#modalAlert');
    }

    function resetModalForm() {
        $modalPartsPicker.find('option').not(':first').remove();
        refreshSelectpicker($modalPartsPicker);
        $modalVendorPartNo.val('');
        $modalProducerPartNo.val('');
        $modalFullPack.val('1');
        setModalAlert('');
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
                '<td>' + escapeHtml(item.part_name || '') + '</td>' +
                '<td>' + escapeHtml(item.vendor_part_no) + '</td>' +
                '<td>' + (item.producer_part_no ? escapeHtml(item.producer_part_no) : '<span class="text-muted">—</span>') + '</td>' +
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

    // Step 1: vendor selection (selectpicker)
    $vendor.on('change', function () {
        cart.vendorId = parseInt($(this).val(), 10) || null;
        cart.vendorName = cart.vendorId ? $vendor.find('option:selected').text() : '';
        clearAddForm();
        loadVendorParts('');
    });

    // Step 1: live sync of date + comment inputs (also used in step 2 expand)
    $date.on('change',    function () { cart.date    = $(this).val(); });
    $comment.on('change', function () { cart.comment = $(this).val(); });

    // Step 1 → Step 2 transition
    $nextBtn.on('click', function () {
        if (!cart.vendorId) {
            setAlert('Wybierz dostawcę przed kontynuacją.', 'warning');
            return;
        }
        cart.date    = $date.val() || '';
        cart.comment = $comment.val() || '';
        setStep(2);
    });

    // Step 2 → Step 1 (clear all + go back)
    $backBtn.on('click', function (e) {
        e.preventDefault();
        cart.items    = [];
        cart.comment  = '';
        cart.date     = '';
        $comment.val('');
        $date.val('');
        renderCart();
        setStep(1);
    });

    // Step 2 collapsed → expanded edit form
    $editParamsBtn.on('click', function (e) {
        e.preventDefault();
        $editVendorDisplay.text(cart.vendorName);
        $cartDateEdit.val(cart.date);
        $cartCommentEdit.val(cart.comment);
        $step2SummaryCard.hide();
        $step2EditCard.show();
    });

    // Step 2 expanded → collapsed (cancel: discard pending changes)
    $cancelEditBtn.on('click', function (e) {
        e.preventDefault();
        $step2EditCard.hide();
        $step2SummaryCard.show();
    });

    // Step 2 expanded → collapsed (save: apply changes)
    $saveEditBtn.on('click', function () {
        cart.date    = $cartDateEdit.val() || '';
        cart.comment = $cartCommentEdit.val() || '';
        refreshSummary();
        $step2EditCard.hide();
        $step2SummaryCard.show();
        setAlert('Parametry zaktualizowane.', 'success');
    });

    // Live search on the VendorPart picker (step 2 add-item form)
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
            vendor_part_id    : vpId,
            vendor_part_no    : $sel.attr('data-vendor-part-no')    || '',
            producer_part_no  : $sel.attr('data-producer-part-no') || null,
            part_name         : $sel.attr('data-part-name')         || '',
            producer_name     : $sel.attr('data-producer-name')     || '',
            unit_name         : $sel.attr('data-unit-name')         || '',
            quantity          : qty,
            unit_price        : price,
            currency          : cur
        });

        renderCart();
        clearAddForm();
        setAlert('Dodano pozycję do koszyka.', 'success');
    });

    // ---- "Dodaj nowy artykuł u dostawcy" modal handlers ----

    $addVendorPartBtn.on('click', function (e) {
        e.preventDefault();
        if (!cart.vendorId) {
            setAlert('Najpierw wybierz dostawcę.', 'warning');
            return;
        }
        resetModalForm();
        $('#addVendorPartModal').modal('show');
    });

    // Live search inside the modal's Part picker
    $modalPartsPicker.on('keyup', function () {
        var $searchInput = $modalPartsPicker.parent().find('.bs-searchbox input');
        if ($searchInput.length === 0) { return; }
        var q = $searchInput.val();
        if (q.length < 2 && $searchInput.val() !== '') {
            // Re-trigger when user clears the search box
            if (q === '') { loadModalParts(''); }
            return;
        }
        if (q.length < 2) { return; }
        loadModalParts(q);
    });

    // When a Part is picked, auto-fill the JM dropdown with the part's JM
    $modalPartsPicker.on('change', function () {
        var $opt = $modalPartsPicker.find('option:selected');
        var jmId = parseInt($opt.attr('data-jm-id'), 10) || 0;
        if (jmId) {
            $modalVendorJm.val(jmId);
        }
    });

    // Save the new VendorPart
    $saveVendorPartBtn.on('click', function () {
        setModalAlert('');

        var partsId = parseInt($modalPartsPicker.val(), 10) || 0;
        var vendorPartNo = $modalVendorPartNo.val().trim();
        var producerPartNo = $modalProducerPartNo.val().trim();
        var vendorJmId = parseInt($modalVendorJm.val(), 10) || 0;
        var fullPack = parseFloat($modalFullPack.val()) || 1;

        if (!partsId) {
            setModalAlert('Wybierz część z naszego katalogu.', 'warning');
            return;
        }
        if (!vendorPartNo) {
            setModalAlert('Podaj numer katalogowy u dostawcy.', 'warning');
            return;
        }
        if (!vendorJmId) {
            setModalAlert('Wybierz JM u dostawcy.', 'warning');
            return;
        }

        var $btn = $saveVendorPartBtn;
        $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Zapisuję...');

        $.ajax({
            url: PURCHASE_CART_BASE + '/vendor-part-add.php',
            method: 'POST',
            data: {
                vendor_id        : cart.vendorId,
                parts_id         : partsId,
                vendor_part_no   : vendorPartNo,
                producer_part_no : producerPartNo,
                vendor_jm_id     : vendorJmId,
                full_pack_quantity: fullPack
            },
            dataType: 'json'
        }).done(function (response) {
            if (response && response.success) {
                $('#addVendorPartModal').modal('hide');
                // Reload the add-item VendorPart picker so the new entry shows up
                loadVendorParts('');
                setAlert('Dodano nowy artykuł u dostawcy: ' + response.vendor_part_no, 'success');
            } else {
                setModalAlert(response && response.error ? response.error : 'Nieznany błąd.', 'danger');
            }
        }).fail(function (xhr) {
            setModalAlert('Błąd HTTP ' + xhr.status, 'danger');
        }).always(function () {
            $btn.prop('disabled', false).html('<i class="bi bi-check-lg"></i> Zapisz');
        });
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

    // Initial render
    setStep(1);
})();
