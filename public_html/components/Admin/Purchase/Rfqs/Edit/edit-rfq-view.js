$(document).ready(function() {
    const ajaxBase      = COMPONENTS_PATH + "/Admin/Purchase/Rfqs/Edit/";
    const parentAjax    = COMPONENTS_PATH + "/Admin/Purchase/Rfqs/";
    const ctx           = document.getElementById('rfqPageContext');
    const rfqId         = parseInt(ctx.dataset.rfqId, 10);
    const vendorId      = parseInt(ctx.dataset.vendorId, 10);
    const canEdit       = ctx.dataset.canEdit === '1';

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
    // VendorPart picker on the "add" form (already server-rendered).
    // ---------------------------------------------------------------
    $('#rfq_item_vendor_part_select').on('changed.bs.select', function() {
        const $sel = $(this).find('option:selected');
        $('#rfq_item_vendor_part_id').val($(this).val());
        $('#rfq_item_quantity_unit_id').val($sel.data('vendor-jm-id') || '');
    });

    $('#addRfqItemForm').on('submit', function(e) {
        e.preventDefault();
        if (!canEdit) { showAlert('Zapytanie nie jest w stanie szkicu', 'warning'); return; }
        const data = {
            rfq_id:         rfqId,
            vendor_part_id: $('#rfq_item_vendor_part_id').val(),
            quantity:       $('#rfq_item_quantity').val(),
            unit_price:     $('#rfq_item_unit_price').val() || '',
            currency:       $('#rfq_item_currency').val(),
            comment:        $('#rfq_item_comment').val().trim(),
        };
        if (!data.vendor_part_id) { showAlert('Wybierz artykuł', 'warning'); return; }
        if (!data.quantity || parseFloat(data.quantity) <= 0) {
            showAlert('Podaj ilość większą od zera', 'warning'); return;
        }
        postAjax('rfq-item-add.php', data)
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
        getAjax('rfq-item-get.php', { id: itemId })
            .done(function(r) {
                if (!r.success) { showAlert(r.error || 'Błąd ładowania', 'danger'); return; }
                const it = r.item;
                $('#edit_rfq_item_id').val(it.id);
                $('#edit_rfq_item_rfq_id').val(it.rfqId);
                $('#edit_rfq_item_quantity').val(it.quantity);
                $('#edit_rfq_item_unit_price').val(it.unitPrice == null ? '' : it.unitPrice);
                $('#edit_rfq_item_currency').val(it.currency || 'PLN');
                $('#edit_rfq_item_comment').val(it.comment || '');

                fillVendorPartSelect('#edit_rfq_item_vendor_part_select', it.vendorPartId)
                    .done(function() {
                        const $opt = $('#edit_rfq_item_vendor_part_select option[value="' + it.vendorPartId + '"]');
                        if ($opt.length) {
                            $('#edit_rfq_item_quantity_unit_id').val($opt.data('quantity-unit-id') || it.quantityUnitId);
                        } else {
                            $('#edit_rfq_item_quantity_unit_id').val(it.quantityUnitId);
                        }
                        $('#edit_rfq_item_vendor_part_id').val(it.vendorPartId);
                        $('#editRfqItemModal').modal('show');
                    });
            })
            .fail(function() { showAlert('Błąd komunikacji z serwerem', 'danger'); });
    });

    $('#edit_rfq_item_vendor_part_select').on('changed.bs.select', function() {
        const $sel = $(this).find('option:selected');
        $('#edit_rfq_item_vendor_part_id').val($(this).val());
        $('#edit_rfq_item_quantity_unit_id').val($sel.data('quantity-unit-id') || '');
    });

    $('#editRfqItemForm').on('submit', function(e) {
        e.preventDefault();
        const data = {
            id:              $('#edit_rfq_item_id').val(),
            rfq_id:          rfqId,
            vendor_part_id:  $('#edit_rfq_item_vendor_part_id').val(),
            quantity_unit_id:$('#edit_rfq_item_quantity_unit_id').val(),
            quantity:        $('#edit_rfq_item_quantity').val(),
            unit_price:      $('#edit_rfq_item_unit_price').val() || '',
            currency:        $('#edit_rfq_item_currency').val(),
            comment:         $('#edit_rfq_item_comment').val().trim(),
        };
        if (!data.vendor_part_id) { showAlert('Wybierz artykuł', 'warning'); return; }
        if (!data.quantity || parseFloat(data.quantity) <= 0) {
            showAlert('Podaj ilość większą od zera', 'warning'); return;
        }
        postAjax('rfq-item-update.php', data)
            .done(function(r) {
                if (r.success) {
                    $('#editRfqItemModal').modal('hide');
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
        $('#delete_rfq_item_id').val(id);
        $('#delete_rfq_item_body').html('Czy na pewno usunąć pozycję <b>' + esc(vpNo || ('#' + id)) + '</b>?');
        $('#deleteRfqItemModal').modal('show');
    });
    $('#confirmDeleteRfqItem').on('click', function() {
        const id = parseInt($('#delete_rfq_item_id').val(), 10);
        postAjax('rfq-item-delete.php', { id: id, rfq_id: rfqId })
            .done(function(r) {
                if (r.success) {
                    $('#deleteRfqItemModal').modal('hide');
                    showAlert('Pozycja usunięta', 'success');
                    location.reload();
                } else { showAlert(r.error || 'Błąd', 'danger'); }
            })
            .fail(function() { showAlert('Błąd komunikacji z serwerem', 'danger'); });
    });

    // ---------------------------------------------------------------
    // Cancel / Send RFQ (reuse parent AJAX endpoints).
    // ---------------------------------------------------------------
    $(document).on('click', '.cancel-rfq-btn', function() {
        const id  = parseInt($(this).data('id'), 10);
        const num = $(this).data('number');
        $('#cancel_rfq_id').val(id);
        $('#cancel_rfq_body').html(
            'Czy na pewno anulować zapytanie <b>' + esc(num || ('#' + id)) + '</b>?'
        );
        $('#cancelRfqModal').modal('show');
    });
    $('#confirmCancelRfq').on('click', function() {
        const id = parseInt($('#cancel_rfq_id').val(), 10);
        postAjax('rfq-cancel.php', { id: id }, parentAjax)
            .done(function(r) {
                if (r.success) {
                    $('#cancelRfqModal').modal('hide');
                    showAlert(r.message || 'Zapytanie anulowane', 'success');
                    location.reload();
                } else { showAlert(r.error || 'Błąd', 'danger'); }
            })
            .fail(function() { showAlert('Błąd komunikacji z serwerem', 'danger'); });
    });

    $(document).on('click', '.send-rfq-btn', function() {
        const id     = parseInt($(this).data('id'), 10);
        const num    = $(this).data('number');
        const vendor = $(this).data('vendor');
        $('#send_rfq_id').val(id);
        $('#send_rfq_body').html(
            'Czy na pewno wysłać zapytanie <b>' + esc(num || ('#' + id)) + '</b> do <b>' + esc(vendor) + '</b>?'
        );
        $('#sendRfqModal').modal('show');
    });
    $('#confirmSendRfq').on('click', function() {
        const id = parseInt($('#send_rfq_id').val(), 10);
        postAjax('rfq-send.php', { id: id }, parentAjax)
            .done(function(r) {
                if (r.success) {
                    $('#sendRfqModal').modal('hide');
                    showAlert(r.message || 'Zapytanie wysłane', 'success');
                    location.reload();
                } else { showAlert(r.error || 'Błąd', 'danger'); }
            })
            .fail(function() { showAlert('Błąd komunikacji z serwerem', 'danger'); });
    });
});
