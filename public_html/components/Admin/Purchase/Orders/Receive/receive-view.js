$(document).ready(function() {
    const ajaxBase = COMPONENTS_PATH + "/Admin/Purchase/Orders/Receive/";
    const ctx      = document.getElementById('receivePageContext');
    const poId     = parseInt(ctx.dataset.poId, 10);
    const canReceive = ctx.dataset.canReceive === '1';

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

    // Live validation: colour the qty input when it exceeds 110% of remaining.
    $(document).on('input', '.receive-qty', function() {
        const $inp = $(this);
        const raw  = parseFloat($inp.val());
        const rem  = parseFloat($inp.data('remaining'));
        const max  = parseFloat($inp.data('max'));
        const val  = isNaN(raw) ? 0 : raw;
        if (val > max + 1e-6) {
            $inp.addClass('is-invalid');
        } else {
            $inp.removeClass('is-invalid');
        }
    });

    if (!canReceive) {
        // Form isn't rendered in this case, but defensive: skip wiring.
        return;
    }

    $('#receiveForm').on('submit', function(e) {
        e.preventDefault();

        const subMagId = parseInt($('#receive_sub_magazine_id').val(), 10);
        if (!subMagId) { showAlert('Wybierz magazyn docelowy', 'warning'); return; }

        const items = [];
        let hasAny = false;
        $('.receive-qty').each(function() {
            const $inp = $(this);
            const raw = parseFloat($inp.val());
            if (!isNaN(raw) && raw > 0) {
                items.push({
                    po_item_id:        parseInt($inp.data('row'), 10),
                    quantity_received: raw,
                    comment:           '',
                });
                hasAny = true;
            }
        });
        if (!hasAny) { showAlert('Wpisz ilość przynajmniej w jednej pozycji', 'warning'); return; }

        // Block submission if any input is over the 110% cap.
        let over = null;
        $('.receive-qty').each(function() {
            const $inp = $(this);
            const raw  = parseFloat($inp.val());
            if (isNaN(raw) || raw <= 0) return;
            const max = parseFloat($inp.data('max'));
            if (raw > max + 1e-6) {
                over = $inp.data('row');
                return false;
            }
        });
        if (over !== null) {
            showAlert('Ilość w pozycji #' + over + ' przekracza limit 110% pozostałej ilości', 'warning');
            return;
        }

        const data = {
            po_id:           poId,
            sub_magazine_id: subMagId,
            document_number: $('#receive_document_number').val().trim(),
            comment:         $('#receive_comment').val().trim(),
            items:           items,
        };

        $.ajax({
            url: ajaxBase + 'receive-action.php',
            type: 'POST',
            data: data,
            dataType: 'json',
            traditional: true, // serialise `items` as items[]=...&items[]=...
        })
            .done(function(r) {
                if (r.success) {
                    showAlert(r.message || 'Przyjęcie zapisane', 'success');
                    if (r.redirect) {
                        window.location.href = r.redirect;
                    } else {
                        location.reload();
                    }
                } else {
                    showAlert(r.error || 'Błąd', 'danger');
                }
            })
            .fail(function(xhr) {
                showAlert('Błąd komunikacji z serwerem', 'danger');
                console.error('receive-action error:', xhr.responseText);
            });
    });
});
