$(document).ready(function() {
    const ajaxBase = COMPONENTS_PATH + "/purchases/receipts/";

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

    function getAjax(endpoint, data) {
        return $.ajax({ url: ajaxBase + endpoint, type: 'GET', data: data, dataType: 'json' });
    }

    // Open info modal on row click.
    $(document).on('click', '.receipt-row', function() {
        const id = parseInt($(this).data('id'), 10);
        openReceiptModal(id);
    });

    // Or via the explicit "Zobacz" button.
    $(document).on('click', '.view-receipt-btn', function(e) {
        e.stopPropagation();
        const id = parseInt($(this).data('id'), 10);
        openReceiptModal(id);
    });

    function openReceiptModal(id) {
        getAjax('receipt-get.php', { id: id })
            .done(function(r) {
                if (!r.success) { showAlert(r.error || 'Błąd ładowania', 'danger'); return; }
                const rc = r.receipt;
                const items = r.items || [];

                const headerHtml =
                    '<div class="row">'
                  + '<div class="col-md-3"><strong>Numer dokumentu:</strong><br>' + esc(rc.documentNumber || '—') + '</div>'
                  + '<div class="col-md-3"><strong>Zamówienie:</strong><br>'
                  +   (rc.poNumber
                          ? '<a href="http://' + ROOT_DIR + '/admin/purchase/orders/edit?id=' + rc.poId + '">'
                            + esc(rc.poNumber) + '</a>'
                          : '#' + rc.poId)
                  + '</div>'
                  + '<div class="col-md-3"><strong>Dostawca:</strong><br>' + esc(rc.vendorName || '—') + '</div>'
                  + '<div class="col-md-3"><strong>Przyjął:</strong><br>'
                  +   esc(rc.receivedByName || ('#' + rc.receivedBy))
                  +   '<br><small class="text-muted">' + esc(rc.receivedAt || '') + '</small>'
                  + '</div>'
                  + '</div>'
                  + (rc.comment ? '<hr><strong>Komentarz:</strong><br>' + esc(rc.comment) : '');

                $('#receiptInfoHeader').html(headerHtml);

                let rows = '';
                if (items.length === 0) {
                    rows = '<tr><td colspan="8" class="text-center text-muted">Brak pozycji.</td></tr>';
                } else {
                    items.forEach(function(i) {
                        rows += '<tr>'
                              + '<td>' + esc(i.vendorPartNo || '—') + '</td>'
                              + '<td>' + esc(i.partName || '—') + '</td>'
                              + '<td>' + esc(i.producerName || '—') + '</td>'
                              + '<td>' + esc(i.unitName || '—') + '</td>'
                              + '<td class="text-center">' + esc(String(i.quantityReceived)) + '</td>'
                              + '<td>' + esc(i.magazineName || ('#' + i.subMagazineId)) + '</td>'
                              + '<td>' + esc(i.comment || '') + '</td>'
                              + '</tr>';
                    });
                }
                $('#receiptInfoItems').html(rows);
                $('#receiptInfoModal').modal('show');
            })
            .fail(function() { showAlert('Błąd komunikacji z serwerem', 'danger'); });
    }

    // Client-side row filter.
    $('#receiptFilter').on('keyup', function() {
        const q = $(this).val().toLowerCase();
        $('#receiptsTable tbody tr.receipt-row').each(function() {
            const text = $(this).text().toLowerCase();
            $(this).toggle(text.indexOf(q) !== -1);
        });
    });
});
