$(document).ready(function() {
    const ajaxBase   = COMPONENTS_PATH + "/Admin/Purchase/Orders/Edit/";
    const parentAjax = COMPONENTS_PATH + "/Admin/Purchase/Orders/";
    const ctx        = document.getElementById('poPageContext');
    const poId       = parseInt(ctx.dataset.poId, 10);
    const vendorId   = parseInt(ctx.dataset.vendorId, 10);
    const canEdit    = ctx.dataset.canEdit === '1';

    function esc(str) {
        return $('<div>').text(str == null ? '' : String(str)).html();
    }

    function showAlert(message, type) {
        if(!type) type = 'success';
        const html = '<div class="alert alert-' + type + ' alert-dismissible fade show" role="alert">'
            + message
            + '<button type="button" class="close" data-dismiss="alert"><span aria-hidden="true">&times;</span></button>'
            + '</div>';
        $('#alertContainer').html(html);
        $('html, body').animate({ scrollTop: 0 }, 300);
    }

    function postAjax(endpoint, data, base) {
        if(!base) base = ajaxBase;
        return $.ajax({ url: base + endpoint, type: 'POST', data: data, dataType: 'json' });
    }
    function getAjax(endpoint, data, base) {
        if(!base) base = ajaxBase;
        return $.ajax({ url: base + endpoint, type: 'GET', data: data, dataType: 'json' });
    }

    // ---------------------------------------------------------------
    // Vendor-part picker on the "add" form (already server-rendered).
    // ---------------------------------------------------------------
    $('#po_item_vendor_part_select').on('changed.bs.select', function() {
        const $sel = $(this).find('option:selected');
        $('#po_item_vendor_part_id').val($(this).val());
        $('#po_item_quantity_unit_id').val($sel.data('vendor-jm-id') || '');
    });

    $('#addOrderItemForm').on('submit', function(e) {
        e.preventDefault();
        if (!canEdit) { showAlert('Zamówienie nie jest w stanie szkicu', 'warning'); return; }
        const data = {
            po_id:          poId,
            vendor_part_id: $('#po_item_vendor_part_id').val(),
            quantity:       $('#po_item_quantity').val(),
            unit_price:     $('#po_item_unit_price').val() || '0',
            currency:       $('#po_item_currency').val(),
            comment:        $('#po_item_comment').val().trim(),
        };
        if (!data.vendor_part_id) { showAlert('Wybierz artykuł', 'warning'); return; }
        if (!data.quantity || parseFloat(data.quantity) <= 0) {
            showAlert('Podaj ilość większą od zera', 'warning'); return;
        }
        postAjax('order-item-add.php', data)
            .done(function(r) {
                if (r.success) {
                    showAlert('Pozycja dodana', 'success');
                    location.reload();
                } else { showAlert(r.error || 'Błąd', 'danger'); }
            })
            .fail(function() { showAlert('Błąd komunikacji z serwerem', 'danger'); });
    });

    // ---------------------------------------------------------------
    // Edit line item modal.
    // ---------------------------------------------------------------
    function fillVendorPartSelect(selectId, currentVpId) {
        return getAjax('search-vendor-parts.php', { vendor_id: vendorId, q: '' }, ajaxBase)
            .done(function(vps) {
                let opts = '<option value="">Wybierz...</option>';
                vps.forEach(function(vp) {
                    const sel = String(vp.id) === String(currentVpId) ? ' selected' : '';
                    opts += '<option value="' + vp.id + '"'
                          + ' data-quantity-unit-id="' + vp.quantity_unit_id + '"'
                          + sel + '>'
                          + esc((vp.vendor_part_no || '') + ' — '
                                + (vp.part_name || '') + ' ('
                                + (vp.producer_name || '') + ')')
                          + '</option>';
                });
                $(selectId).html(opts);
                $(selectId).selectpicker('refresh');
            });
    }

    $(document).on('click', '.edit-item-btn', function() {
        const itemId = parseInt($(this).data('id'), 10);
        getAjax('order-item-get.php', { id: itemId })
            .done(function(r) {
                if (!r.success) { showAlert(r.error || 'Błąd ładowania', 'danger'); return; }
                const it = r.item;
                $('#edit_po_item_id').val(it.id);
                $('#edit_po_item_po_id').val(it.poId);
                $('#edit_po_item_quantity').val(it.quantity);
                $('#edit_po_item_unit_price').val(it.unitPrice);
                $('#edit_po_item_currency').val(it.currency || 'PLN');
                $('#edit_po_item_comment').val(it.comment || '');

                fillVendorPartSelect('#edit_po_item_vendor_part_select', it.vendorPartId)
                    .done(function() {
                        const $opt = $('#edit_po_item_vendor_part_select option[value="' + it.vendorPartId + '"]');
                        if ($opt.length) {
                            $('#edit_po_item_quantity_unit_id').val($opt.data('quantity-unit-id') || it.quantityUnitId);
                        } else {
                            $('#edit_po_item_quantity_unit_id').val(it.quantityUnitId);
                        }
                        $('#edit_po_item_vendor_part_id').val(it.vendorPartId);
                        $('#editOrderItemModal').modal('show');
                    });
            })
            .fail(function() { showAlert('Błąd komunikacji z serwerem', 'danger'); });
    });

    $('#edit_po_item_vendor_part_select').on('changed.bs.select', function() {
        const $sel = $(this).find('option:selected');
        $('#edit_po_item_vendor_part_id').val($(this).val());
        $('#edit_po_item_quantity_unit_id').val($sel.data('quantity-unit-id') || '');
    });

    $('#editOrderItemForm').on('submit', function(e) {
        e.preventDefault();
        const data = {
            id:               $('#edit_po_item_id').val(),
            po_id:            poId,
            vendor_part_id:   $('#edit_po_item_vendor_part_id').val(),
            quantity_unit_id: $('#edit_po_item_quantity_unit_id').val(),
            quantity:         $('#edit_po_item_quantity').val(),
            unit_price:       $('#edit_po_item_unit_price').val() || '0',
            currency:         $('#edit_po_item_currency').val(),
            comment:          $('#edit_po_item_comment').val().trim(),
        };
        if (!data.vendor_part_id) { showAlert('Wybierz artykuł', 'warning'); return; }
        if (!data.quantity || parseFloat(data.quantity) <= 0) {
            showAlert('Podaj ilość większą od zera', 'warning'); return;
        }
        postAjax('order-item-update.php', data)
            .done(function(r) {
                if (r.success) {
                    $('#editOrderItemModal').modal('hide');
                    showAlert('Pozycja zaktualizowana', 'success');
                    location.reload();
                } else { showAlert(r.error || 'Błąd zapisu', 'danger'); }
            })
            .fail(function() { showAlert('Błąd komunikacji z serwerem', 'danger'); });
    });

    // ---------------------------------------------------------------
    // Delete line item.
    // ---------------------------------------------------------------
    $(document).on('click', '.delete-item-btn', function() {
        const id = parseInt($(this).data('id'), 10);
        const vpNo = $(this).data('vendor-part-no');
        $('#delete_po_item_id').val(id);
        $('#delete_po_item_body').html('Czy na pewno usunąć pozycję <b>' + esc(vpNo || ('#' + id)) + '</b>?');
        $('#deleteOrderItemModal').modal('show');
    });
    $('#confirmDeleteOrderItem').on('click', function() {
        const id = parseInt($('#delete_po_item_id').val(), 10);
        postAjax('order-item-delete.php', { id: id, po_id: poId })
            .done(function(r) {
                if (r.success) {
                    $('#deleteOrderItemModal').modal('hide');
                    showAlert('Pozycja usunięta', 'success');
                    location.reload();
                } else { showAlert(r.error || 'Błąd', 'danger'); }
            })
            .fail(function() { showAlert('Błąd komunikacji z serwerem', 'danger'); });
    });

    // ---------------------------------------------------------------
    // Send / Confirm / Cancel (reuse parent endpoints).
    // ---------------------------------------------------------------
    $(document).on('click', '.send-po-btn', function() {
        const id     = parseInt($(this).data('id'), 10);
        const num    = $(this).data('number');
        const vendor = $(this).data('vendor');
        $('#send_po_id').val(id);
        $('#send_po_body').html(
            'Czy na pewno wysłać zamówienie <b>' + esc(num || ('#' + id)) + '</b> do <b>' + esc(vendor) + '</b>?'
        );
        $('#sendPoModal').modal('show');
    });
    $('#confirmSendPo').on('click', function() {
        const id = parseInt($('#send_po_id').val(), 10);
        postAjax('order-send.php', { id: id }, parentAjax)
            .done(function(r) {
                if (r.success) {
                    $('#sendPoModal').modal('hide');
                    showAlert(r.message || 'Zamówienie wysłane', 'success');
                    location.reload();
                } else { showAlert(r.error || 'Błąd', 'danger'); }
            })
            .fail(function() { showAlert('Błąd komunikacji z serwerem', 'danger'); });
    });

    $(document).on('click', '.confirm-po-btn', function() {
        const id     = parseInt($(this).data('id'), 10);
        const num    = $(this).data('number');
        const vendor = $(this).data('vendor');
        $('#confirm_po_id').val(id);
        $('#confirm_po_body').html(
            'Czy na pewno oznaczyć zamówienie <b>' + esc(num || ('#' + id)) + '</b> jako potwierdzone przez <b>' + esc(vendor) + '</b>?'
        );
        $('#confirm_vendor_po_number').val('');
        $('#confirmPoModal').modal('show');
    });
    $('#confirmConfirmPo').on('click', function() {
        const id             = parseInt($('#confirm_po_id').val(), 10);
        const vendorPoNumber = $('#confirm_vendor_po_number').val().trim();
        if (!vendorPoNumber) { showAlert('Numer potwierdzenia u dostawcy jest wymagany', 'warning'); return; }
        postAjax('order-confirm.php', { id: id, vendor_po_number: vendorPoNumber }, parentAjax)
            .done(function(r) {
                if (r.success) {
                    $('#confirmPoModal').modal('hide');
                    showAlert(r.message || 'Zamówienie potwierdzone', 'success');
                    location.reload();
                } else { showAlert(r.error || 'Błąd', 'danger'); }
            })
            .fail(function() { showAlert('Błąd komunikacji z serwerem', 'danger'); });
    });

    $(document).on('click', '.cancel-po-btn', function() {
        const id  = parseInt($(this).data('id'), 10);
        const num = $(this).data('number');
        $('#cancel_po_id').val(id);
        $('#cancel_po_body').html(
            'Czy na pewno anulować zamówienie <b>' + esc(num || ('#' + id)) + '</b>?'
            + '<div class="text-warning mt-2"><i class="bi bi-info-circle"></i> Jeśli są powiązane faktury lub dostawy, mogą wymagać ręcznego rozliczenia.</div>'
        );
        $('#cancelPoModal').modal('show');
    });
    $('#confirmCancelPo').on('click', function() {
        const id = parseInt($('#cancel_po_id').val(), 10);
        postAjax('order-cancel.php', { id: id }, parentAjax)
            .done(function(r) {
                if (r.success) {
                    $('#cancelPoModal').modal('hide');
                    showAlert(r.message || 'Zamówienie anulowane', 'success');
                    location.reload();
                } else { showAlert(r.error || 'Błąd', 'danger'); }
            })
            .fail(function() { showAlert('Błąd komunikacji z serwerem', 'danger'); });
    });
});
