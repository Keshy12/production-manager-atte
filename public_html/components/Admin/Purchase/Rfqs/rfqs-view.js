$(document).ready(function() {
    const ajaxBase = COMPONENTS_PATH + "/Admin/Purchase/Rfqs/";

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

    // Add RFQ form
    $('#addRfqForm').on('submit', function(e) {
        e.preventDefault();
        const vendorId = $('#rfq_vendor_id').val();
        if (!vendorId) { showAlert('Wybierz dostawcę', 'warning'); return; }
        const data = {
            vendor_id:            vendorId,
            expected_reply_date:  $('#rfq_expected_reply_date').val() || '',
            comment:              $('#rfq_comment').val().trim(),
        };
        postAjax('rfq-add.php', data)
            .done(function(r) {
                if (r.success) {
                    showAlert('Zapytanie dodane: ' + r.rfq_number, 'success');
                    window.location.href = 'http://' + ROOT_DIR + '/admin/purchase/rfqs/edit?id=' + r.id;
                } else {
                    showAlert(r.error || 'Błąd', 'danger');
                }
            })
            .fail(function() { showAlert('Błąd komunikacji z serwerem', 'danger'); });
    });

    // Cancel RFQ
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
        postAjax('rfq-cancel.php', { id: id })
            .done(function(r) {
                if (r.success) {
                    $('#cancelRfqModal').modal('hide');
                    showAlert(r.message || 'Zapytanie anulowane', 'success');
                    location.reload();
                } else {
                    showAlert(r.error || 'Błąd', 'danger');
                }
            })
            .fail(function() { showAlert('Błąd komunikacji z serwerem', 'danger'); });
    });

    // Send RFQ
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
        postAjax('rfq-send.php', { id: id })
            .done(function(r) {
                if (r.success) {
                    $('#sendRfqModal').modal('hide');
                    showAlert(r.message || 'Zapytanie wysłane', 'success');
                    location.reload();
                } else {
                    showAlert(r.error || 'Błąd', 'danger');
                }
            })
            .fail(function() { showAlert('Błąd komunikacji z serwerem', 'danger'); });
    });

    // Client-side text filter (hides rows that don't contain the query).
    $('#rfqFilter').on('keyup', function() {
        const q = $(this).val().toLowerCase();
        $('#rfqsTable tbody tr').each(function() {
            const text = $(this).text().toLowerCase();
            $(this).toggle(text.indexOf(q) !== -1);
        });
    });
});
