$(document).ready(function() {
    const ajaxBase = COMPONENTS_PATH + "/Admin/Purchase/Orders/";

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

    function postAjax(endpoint, data) {
        return $.ajax({ url: ajaxBase + endpoint, type: 'POST', data: data, dataType: 'json' });
    }

    // Add order form
    $('#addOrderForm').on('submit', function(e) {
        e.preventDefault();
        const vendorId = $('#po_vendor_id').val();
        if (!vendorId) { showAlert('Wybierz dostawcę', 'warning'); return; }
        const data = {
            vendor_id:              vendorId,
            expected_delivery_date:  $('#po_expected_delivery_date').val() || '',
            comment:                $('#po_comment').val().trim(),
        };
        postAjax('order-add.php', data)
            .done(function(r) {
                if (r.success) {
                    showAlert('Zamówienie dodane: ' + r.po_number, 'success');
                    window.location.href = 'http://' + ROOT_DIR + '/admin/purchase/orders/edit?id=' + r.id;
                } else {
                    showAlert(r.error || 'Błąd', 'danger');
                }
            })
            .fail(function() { showAlert('Błąd komunikacji z serwerem', 'danger'); });
    });

    // Send
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
        postAjax('order-send.php', { id: id })
            .done(function(r) {
                if (r.success) {
                    $('#sendPoModal').modal('hide');
                    showAlert(r.message || 'Zamówienie wysłane', 'success');
                    location.reload();
                } else { showAlert(r.error || 'Błąd', 'danger'); }
            })
            .fail(function() { showAlert('Błąd komunikacji z serwerem', 'danger'); });
    });

    // Confirm (with vendor_po_number input)
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
        postAjax('order-confirm.php', { id: id, vendor_po_number: vendorPoNumber })
            .done(function(r) {
                if (r.success) {
                    $('#confirmPoModal').modal('hide');
                    showAlert(r.message || 'Zamówienie potwierdzone', 'success');
                    location.reload();
                } else { showAlert(r.error || 'Błąd', 'danger'); }
            })
            .fail(function() { showAlert('Błąd komunikacji z serwerem', 'danger'); });
    });

    // Cancel
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
        postAjax('order-cancel.php', { id: id })
            .done(function(r) {
                if (r.success) {
                    $('#cancelPoModal').modal('hide');
                    showAlert(r.message || 'Zamówienie anulowane', 'success');
                    location.reload();
                } else { showAlert(r.error || 'Błąd', 'danger'); }
            })
            .fail(function() { showAlert('Błąd komunikacji z serwerem', 'danger'); });
    });

    // Client-side row filter.
    $('#poFilter').on('keyup', function() {
        const q = $(this).val().toLowerCase();
        $('#poTable tbody tr').each(function() {
            const text = $(this).text().toLowerCase();
            $(this).toggle(text.indexOf(q) !== -1);
        });
    });
});
