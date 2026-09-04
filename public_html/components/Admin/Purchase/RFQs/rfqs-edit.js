/**
 * Inline-edit for /admin/purchase/rfqs/edit items table.
 *
 * Click pencil on a row → qty, price, AND comment cells swap to
 * <input>/<textarea>; actions cell swaps to Save / Cancel. Save →
 * AJAX POST to rfq-item-update.php, refresh the editable cells in
 * place. Cancel → restore originals.
 *
 * Trash button → Bootstrap modal confirm → AJAX POST to
 * rfq-item-delete.php → fade out + remove <tr>.
 *
 * Only qty, unit_price and comment are meant to be edited from the UI;
 * the other fields (vendor_part_id, quantity_unit_id, currency,
 * picked_pack_size) are echoed back from data-* attrs unchanged.
 *
 * Follows the project AJAX conventions (jQuery $.ajax with form-encoded
 * payload → $_POST on the server).
 */

$(function () {

    const $tbody = $('#rfq-items-tbody');
    if ($tbody.length === 0) return;

    // Mirror the PHP helpers in rfqs-edit.php so success-time cell refresh
    // produces the same display as the initial server render.
    function formatQty(n) {
        if (n === null || n === undefined || n === '') return '—';
        let v = parseFloat(n);
        if (isNaN(v)) return '—';
        return String(+parseFloat(parseFloat(v).toFixed(4)).toString());
    }
    function formatPrice(n) {
        if (n === null || n === undefined || n === '') return '—';
        let v = parseFloat(n);
        if (isNaN(v)) return '—';
        return String(+parseFloat(parseFloat(v).toFixed(4)).toString());
    }
    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    // Enter saves, Esc cancels — for the qty / price inputs.
    $tbody.on('keydown', '.rfq-edit-input', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            $(this).closest('tr').find('.rfq-row-save').trigger('click');
        } else if (e.key === 'Escape') {
            e.preventDefault();
            $(this).closest('tr').find('.rfq-row-cancel').trigger('click');
        }
    });
    // Same shortcuts in the comment textarea (Enter alone inserts a newline
    // — only Ctrl/Cmd+Enter saves; Esc still cancels).
    $tbody.on('keydown', '.rfq-edit-comment', function (e) {
        if (e.key === 'Escape') {
            e.preventDefault();
            $(this).closest('tr').find('.rfq-row-cancel').trigger('click');
        } else if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
            e.preventDefault();
            $(this).closest('tr').find('.rfq-row-save').trigger('click');
        }
    });

    // ---- Edit mode ----

    $tbody.on('click', '.rfq-row-edit', function () {
        const $tr = $(this).closest('tr');
        if ($tr.data('rfq-editing')) return;
        $tr.data('rfq-editing', true);

        const qty     = $tr.data('quantity');
        const price   = $tr.data('unit-price');
        const comment = $tr.data('comment') || '';
        const currency = $tr.data('currency') || 'PLN';
        const unitName = $tr.data('unit-name') || '';

        // Save original cell HTML so Cancel can restore verbatim.
        $tr.data('orig-qty-html',     $tr.find('td.rfq-cell-qty').html());
        $tr.data('orig-price-html',   $tr.find('td.rfq-cell-price').html());
        $tr.data('orig-comment-html', $tr.find('td.rfq-cell-comment').html());
        $tr.data('orig-akcje-html',   $tr.find('td.rfq-cell-akcje').html());

        // Swap qty cell. With-pack lines show qty input + packages input +
        // pack badge, two-way synced — mirrors the cart's inline
        // edit (and the picker). No-pack lines fall back to a bare
        // qty input.
        const pickedPack = parseFloat($tr.data('picked-pack-size'));
        if (!isNaN(pickedPack) && pickedPack > 0) {
            const currentQty  = parseFloat(qty) || 0;
            const currentPkgs = currentQty / pickedPack;
            $tr.data('rfq-edit-pack', pickedPack);
            const $qtyCell = $tr.find('td.rfq-cell-qty').empty();
            $qtyCell.append(
                '<input type="number" step="any" min="0" class="form-control form-control-sm text-right rfq-edit-input rfq-edit-qty" value="' + escapeHtml(currentQty) + '">' +
                '<div class="d-flex align-items-center flex-wrap mt-1" style="gap:.25rem">' +
                    '<input type="number" step="1" min="0" class="form-control form-control-sm text-right rfq-edit-input rfq-edit-packages" ' +
                    'style="width:6em" value="' + escapeHtml(parseFloat(currentPkgs.toFixed(2))) + '" ' +
                    'title="Opak. — qty jest wyliczane jako opak. × wielkość">' +
                    '<small class="text-muted">opak.</small>' +
                    '<span class="badge badge-light border text-monospace" title="Wielkość opakowania (stała)">× ' + escapeHtml(formatQty(pickedPack)) + '</span>' +
                '</div>'
            );
            if (unitName) {
                $qtyCell.append('<div><small class="text-muted">' + escapeHtml(unitName) + '</small></div>');
            }
        } else {
            const $qtyCell = $tr.find('td.rfq-cell-qty').empty();
            $qtyCell.append(
                '<input type="number" step="any" min="0" class="form-control form-control-sm text-right rfq-edit-input rfq-edit-qty" value="' + escapeHtml(qty) + '">'
            );
            if (unitName) {
                $qtyCell.append('<div><small class="text-muted">' + escapeHtml(unitName) + '</small></div>');
            }
        }

        // Swap price cell (keep currency/JM sub-line as a hint).
        const priceInputVal = price === null || price === undefined ? '' : price;
        $tr.find('td.rfq-cell-price').empty().append(
            '<input type="number" step="any" min="0" class="form-control form-control-sm text-right rfq-edit-input rfq-edit-price" value="' + escapeHtml(priceInputVal) + '">' +
            '<div><small class="text-muted">' + escapeHtml(currency) + '/' + escapeHtml(unitName) + '</small></div>'
        );

        // Swap comment cell with a textarea.
        $tr.find('td.rfq-cell-comment').empty().append(
            '<textarea rows="2" class="form-control form-control-sm rfq-edit-comment" placeholder="Komentarz do pozycji...">' + escapeHtml(comment) + '</textarea>'
        );

        // Swap actions cell — show only Save + Cancel while editing.
        $tr.find('td.rfq-cell-akcje').html(
            '<button type="button" class="btn btn-sm btn-success rfq-row-save mr-1" title="Zapisz (Enter / Ctrl+Enter)"><i class="bi bi-check-lg"></i></button>' +
            '<button type="button" class="btn btn-sm btn-outline-secondary rfq-row-cancel" title="Anuluj (Esc)"><i class="bi bi-x-lg"></i></button>'
        );

        $tr.find('.rfq-edit-qty').trigger('focus').trigger('select');
    });

    // Two-way sync between qty input and packages input — mirrors the
    // cart's inline edit + picker's Opak.↔Ilość pair. Whichever input
    // was edited gets the uneven-pack yellow flag; the other clears.
    function applyPackSync($source, isPackagesSource) {
        const $tr = $source.closest('tr');
        const pack = parseFloat($tr.data('rfq-edit-pack'));
        if (!pack || pack <= 0) return;
        const raw = ($source.val() || '').toString().replace(',', '.');
        const val = parseFloat(raw);
        const $qtyInput     = $tr.find('.rfq-edit-qty');
        const $packagesInput = $tr.find('.rfq-edit-packages');
        if (isNaN(val) || val < 0) {
            $source.removeClass('packages-uneven').removeAttr('title');
            return;
        }
        const newQty  = isPackagesSource ? val * pack : val;
        const newPkgs = isPackagesSource ? val       : val / pack;
        // Write to the OTHER input only — never bounce back into the
        // currently focused one (would steal caret + duplicate digits).
        if (isPackagesSource) {
            if (document.activeElement !== $qtyInput[0]) {
                $qtyInput.val(parseFloat(newQty.toFixed(6)));
            }
        } else {
            if (document.activeElement !== $packagesInput[0]) {
                $packagesInput.val(parseFloat(newPkgs.toFixed(2)));
            }
        }
        const qtyForCheck = isPackagesSource ? newQty : val;
        const even = Math.abs(qtyForCheck - Math.round(qtyForCheck / pack) * pack) < 1e-9 * pack;
        if (even) {
            $source.removeClass('packages-uneven').removeAttr('title');
        } else {
            $source.addClass('packages-uneven').attr(
                'title',
                'Uwaga: ilość nie odpowiada pełnej liczbie opakowań (wielkość: ' + formatQty(pack) + ')'
            );
        }
        const $other = isPackagesSource ? $qtyInput : $packagesInput;
        $other.removeClass('packages-uneven').removeAttr('title');
        // applyPackSync wrote to the OTHER input via .val() (which
        // doesn't fire 'input'), so update the value cell directly.
        updateRfqValueCell($tr);
    }

    // Live-update the Wartość cell (qty × price) as the user edits qty,
    // price, or packages during inline edit. Shows "—" when price is
    // null/invalid or qty is missing.
    function updateRfqValueCell($tr) {
        const qty = parseFloat(($tr.find('.rfq-edit-qty').val() || '').toString().replace(',', '.'));
        const priceRaw = ($tr.find('.rfq-edit-price').val() || '').toString().trim();
        const price = (priceRaw === '') ? null : parseFloat(priceRaw.replace(',', '.'));
        if (isNaN(qty) || qty <= 0 || price === null || isNaN(price)) {
            $tr.find('td.rfq-cell-value').html('<span class="text-muted">—</span>');
        } else {
            $tr.find('td.rfq-cell-value').html(
                escapeHtml(formatPrice(qty * price)) +
                '<div><small class="text-muted">' + escapeHtml($tr.data('currency') || 'PLN') + '</small></div>'
            );
        }
    }

    $tbody.on('input', '.rfq-edit-qty',      function () { applyPackSync($(this), false); updateRfqValueCell($(this).closest('tr')); });
    $tbody.on('input', '.rfq-edit-price',    function () { updateRfqValueCell($(this).closest('tr')); });
    $tbody.on('input', '.rfq-edit-packages', function () { applyPackSync($(this), true);  updateRfqValueCell($(this).closest('tr')); });

    // Cancel → restore original cell HTML, leave edit mode.
    $tbody.on('click', '.rfq-row-cancel', function () {
        const $tr = $(this).closest('tr');
        if (!$tr.data('rfq-editing')) return;
        $tr.find('td.rfq-cell-qty').html($tr.data('orig-qty-html'));
        $tr.find('td.rfq-cell-price').html($tr.data('orig-price-html'));
        $tr.find('td.rfq-cell-comment').html($tr.data('orig-comment-html'));
        $tr.find('td.rfq-cell-akcje').html($tr.data('orig-akcje-html'));
        $tr.removeData('rfq-editing');
        $tr.removeData(['orig-qty-html', 'orig-price-html', 'orig-comment-html', 'orig-akcje-html', 'rfq-edit-pack']);
    });

    // Save → AJAX POST, refresh editable cells on success.
    $tbody.on('click', '.rfq-row-save', function () {
        const $tr = $(this).closest('tr');
        const id = $tr.data('rfq-item-id');
        const $qtyInput     = $tr.find('.rfq-edit-qty');
        const $priceInput   = $tr.find('.rfq-edit-price');
        const $commentInput = $tr.find('.rfq-edit-comment');

        const qtyRaw   = ($qtyInput.val() || '').toString().replace(',', '.');
        const qty      = parseFloat(qtyRaw);
        const priceRaw = ($priceInput.val() || '').toString().trim();
        const unitPrice = (priceRaw === '') ? null : parseFloat(priceRaw.replace(',', '.'));
        const comment = ($commentInput.val() || '').toString();

        if (isNaN(qty) || qty <= 0) {
            if (typeof setAlert === 'function') { setAlert('Ilość musi być > 0.', 'warning'); }
            else { alert('Ilość musi być > 0.'); }
            $qtyInput.trigger('focus');
            return;
        }
        if (unitPrice !== null && (isNaN(unitPrice) || unitPrice < 0)) {
            if (typeof setAlert === 'function') { setAlert('Cena nie może być ujemna.', 'warning'); }
            else { alert('Cena nie może być ujemna.'); }
            $priceInput.trigger('focus');
            return;
        }

        // Disable buttons so a double-click can't fire two POSTs.
        $tr.find('.rfq-row-save, .rfq-row-cancel').prop('disabled', true);

        $.ajax({
            url: COMPONENTS_PATH + '/Admin/Purchase/RFQs/rfq-item-update.php',
            method: 'POST',
            dataType: 'json',
            data: {
                    id:               id,
                    quantity:         qty,
                    unit_price:       unitPrice === null ? '' : unitPrice,
                    vendor_part_id:   $tr.data('vendor-part-id'),
                    quantity_unit_id: $tr.data('quantity-unit-id'),
                    currency:         $tr.data('currency'),
                    picked_pack_size: $tr.data('picked-pack-size'),
                    comment:          comment
                }
        })
        .done(function (r) {
            if (!r || !r.success) {
                if (typeof setAlert === 'function') { setAlert((r && r.error) || 'Błąd zapisu.', 'danger'); }
                else { alert((r && r.error) || 'Błąd zapisu.'); }
                $tr.find('.rfq-row-save, .rfq-row-cancel').prop('disabled', false);
                return;
            }
            // Update data-* so a subsequent re-edit of the same row starts from the new values.
            $tr.data('quantity', r.quantity);
            $tr.data('unit-price', r.unit_price);
            $tr.data('comment', comment);

            // Re-render qty cell. Includes the packages sub-line + pack badge
            // when pickedPackSize is set, mirroring the PHP read-mode
            // render in rfqs-edit.php so post-save rows match the
            // initial display.
            const unitName = $tr.data('unit-name') || '';
            const pack     = parseFloat($tr.data('picked-pack-size'));
            let qtyCellHtml = escapeHtml(formatQty(r.quantity));
            if (!isNaN(pack) && pack > 0) {
                const qtyVal   = parseFloat(r.quantity) || 0;
                const pkgs     = qtyVal / pack;
                const evenPkgs = Math.abs(pkgs - Math.round(pkgs)) < 1e-9;
                qtyCellHtml += '<div>' +
                    '<small class="' + (evenPkgs ? 'text-muted' : 'text-warning') + '">' +
                        escapeHtml(String(parseFloat(pkgs.toFixed(2)))) + ' opak.' +
                    '</small>' +
                    ' <span class="badge badge-light border text-monospace ml-1" title="Wybrana wielkość opakowania">' +
                        'opak. ' + escapeHtml(formatQty(pack)) +
                    '</span>' +
                    '</div>';
            }
            if (unitName) {
                qtyCellHtml += '<div><small class="text-muted">' + escapeHtml(unitName) + '</small></div>';
            }
            $tr.find('td.rfq-cell-qty').html(qtyCellHtml);

            // Re-render price cell.
            const currency = r.currency || $tr.data('currency') || 'PLN';
            if (r.unit_price === null) {
                $tr.find('td.rfq-cell-price').html('<span class="text-muted">—</span>');
            } else {
                $tr.find('td.rfq-cell-price').html(
                    escapeHtml(formatPrice(r.unit_price)) +
                    '<div><small class="text-muted">' + escapeHtml(currency) + '/' + escapeHtml(unitName) + '</small></div>'
                );
            }

            // Re-render wartość cell (qty × price).
            if (r.unit_price === null) {
                $tr.find('td.rfq-cell-value').html('<span class="text-muted">—</span>');
            } else {
                $tr.find('td.rfq-cell-value').html(
                    escapeHtml(formatPrice(r.line_total)) +
                    '<div><small class="text-muted">' + escapeHtml(currency) + '</small></div>'
                );
            }

            // Re-render comment cell (preserve newlines via <br>).
            $tr.find('td.rfq-cell-comment').html(
                comment === '' ? '' : escapeHtml(comment).replace(/\n/g, '<br>')
            );

            // Restore the actions cell (edit + trash buttons) and exit edit mode.
            $tr.find('td.rfq-cell-akcje').html(
                '<button type="button" class="btn btn-sm btn-outline-primary rfq-row-edit mr-1" title="Edytuj ilość / cenę / komentarz">' +
                '<i class="bi bi-pencil"></i></button>' +
                '<button type="button" class="btn btn-sm btn-outline-danger rfq-row-delete" title="Usuń pozycję">' +
                '<i class="bi bi-trash"></i></button>'
            );
            $tr.removeData('rfq-editing');
            $tr.removeData(['orig-qty-html', 'orig-price-html', 'orig-comment-html', 'orig-akcje-html', 'rfq-edit-pack']);

            if (typeof setAlert === 'function') { setAlert(r.message || 'Zapisano.', 'success'); }
        })
        .fail(function (xhr, status) {
            const msg = (xhr && xhr.responseJSON && xhr.responseJSON.error) || status || 'Błąd sieci.';
            if (typeof setAlert === 'function') { setAlert(msg, 'danger'); }
            else { alert(msg); }
            $tr.find('.rfq-row-save, .rfq-row-cancel').prop('disabled', false);
        });
    });

    // ---- Delete with modal confirmation ----

    let $pendingDeleteRow = null;

    $tbody.on('click', '.rfq-row-delete', function () {
        const $tr = $(this).closest('tr');
        if ($tr.data('rfq-editing')) {
            if (typeof setAlert === 'function') { setAlert('Najpierw zakończ edycję pozycji.', 'warning'); }
            return;
        }
        // Pull each piece of the row's identity from dedicated data-*
        // attrs (added on the row in rfqs-edit.php). Rendering them into
        // separate containers matches the row's part-cell layout — no
        // more "everything bold" string-blob modal.
        const partName      = $tr.data('part-name')      || '—';
        const vendorName    = $tr.data('vendor-name')    || '';
        const vendorPartNo  = $tr.data('vendor-part-no') || '';
        const producerNo    = $tr.data('producer-part-no') || '';
        const description   = $tr.data('part-description') || '';
        const vpComment     = $tr.data('vp-comment')     || '';
        const qty           = $tr.data('quantity');
        const unitName      = $tr.data('unit-name') || '';

        // Helper: show-or-hide a line based on whether there's content,
        // and set its text. Keeps the modal compact when fields are empty.
        function setLine($el, text) {
            if (text && text.length > 0) {
                $el.text(text).show();
            } else {
                $el.hide();
            }
        }

        $('#rfqItemDeletePartName').text(partName);

        // Vendor line: "Dostawca: <name>  ·  Nr dost.: <vendorPartNo>".
        // Producer number is rendered on its own line because the table
        // splits these onto separate rows when they differ.
        let vendorText = '';
        if (vendorName)    { vendorText += 'Dostawca: ' + vendorName; }
        if (vendorPartNo)  { vendorText += (vendorText ? '  ·  ' : '') + 'Nr dost.: ' + vendorPartNo; }
        setLine($('#rfqItemDeleteVendorLine'), vendorText);

        setLine($('#rfqItemDeleteProducerLine'), producerNo ? 'Nr prod.: ' + producerNo : '');

        setLine($('#rfqItemDeleteDescLine'), description);

        // Variant "Komentarz:" line — render Brak when empty, matching
        // the table's muted-text empty state.
        if (vpComment) {
            $('#rfqItemDeleteCommentText').text(vpComment);
            $('#rfqItemDeleteCommentLine').show();
        } else {
            $('#rfqItemDeleteCommentText').text('Brak');
            $('#rfqItemDeleteCommentLine').show();
        }

        $('#rfqItemDeleteMeta').text('Ilość: ' + formatQty(qty) + (unitName ? ' ' + unitName : ''));
        $pendingDeleteRow = $tr;
        $('#rfqItemDeleteModal').modal('show');
    });

    $('#rfqItemDeleteConfirm').on('click', function () {
        const $tr = $pendingDeleteRow;
        if (!$tr || $tr.length === 0) return;
        const id = $tr.data('rfq-item-id');
        const $btn = $(this).prop('disabled', true);

        $.ajax({
            url: COMPONENTS_PATH + '/Admin/Purchase/RFQs/rfq-item-delete.php',
            method: 'POST',
            dataType: 'json',
            data: { id: id }
        })
        .done(function (r) {
            $btn.prop('disabled', false);
            if (!r || !r.success) {
                if (typeof setAlert === 'function') { setAlert((r && r.error) || 'Błąd usuwania.', 'danger'); }
                else { alert((r && r.error) || 'Błąd usuwania.'); }
                return;
            }
            $tr.fadeOut(200, function () {
                $(this).remove();
                // If the table just went empty, swap in the empty-state row.
                if ($tbody.find('tr').length === 0) {
                    $tbody.append('<tr><td colspan="7" class="text-center text-muted">Brak pozycji.</td></tr>');
                }
            });
            $('#rfqItemDeleteModal').modal('hide');
            $pendingDeleteRow = null;
            if (typeof setAlert === 'function') { setAlert(r.message || 'Pozycja usunięta.', 'success'); }
        })
        .fail(function (xhr, status) {
            $btn.prop('disabled', false);
            const msg = (xhr && xhr.responseJSON && xhr.responseJSON.error) || status || 'Błąd sieci.';
            if (typeof setAlert === 'function') { setAlert(msg, 'danger'); }
            else { alert(msg); }
        });
    });

    // Clear pending row if modal is dismissed without confirm (Escape / X / Anuluj).
    $('#rfqItemDeleteModal').on('hidden.bs.modal', function () {
        $pendingDeleteRow = null;
        $('#rfqItemDeleteConfirm').prop('disabled', false);
    });

    // ---- Vendor-part comment inline edit (mirrors /purchase/cart) ----
    // Pen in the Komentarz sub-line swaps it with a textarea + Save /
    // Cancel; Save posts to vendor-part-comment.php (the same endpoint
    // the cart uses), then refreshes the sub-line in place.
    //
    // The current value is read from `data-vp-comment` on the <tr>
    // (stamped by rfqs-edit.php from $i->vendorPartComment). We never
    // scrape the rendered HTML — PHP indentation inside the <small>
    // leaves leading whitespace that fooled a regex-based strip in a
    // previous version. Cart reads from its JS cache instead; we mirror
    // that pattern with the row attribute.

    function renderVpCommentLine(vpId, value) {
        const inner = value !== ''
            ? '<i class="bi bi-journal-text"></i> Komentarz: ' + escapeHtml(value)
            : '<i class="bi bi-journal-text"></i> Komentarz: <em>Brak</em>';
        const title = value !== '' ? 'Edytuj komentarz' : 'Dodaj komentarz';
        return '<div class="rfq-row-vp-comment-line">' +
                  '<small class="text-muted">' + inner + '</small> ' +
                  '<button type="button" class="btn btn-link btn-sm p-0 ml-1 rfq-row-vp-comment-edit" ' +
                          'data-vp-id="' + vpId + '" title="' + title + '">' +
                      '<i class="bi bi-pencil"></i>' +
                  '</button>' +
               '</div>';
    }

    $tbody.on('click', '.rfq-row-vp-comment-edit', function (e) {
        e.preventDefault();
        const $btn = $(this);
        const vpId = parseInt($btn.attr('data-vp-id'), 10);
        if (!vpId) return;
        const $tr = $btn.closest('tr');
        const cur = $tr.attr('data-vp-comment') || '';
        const $line = $btn.closest('.rfq-row-vp-comment-line');
        $line.html(
            '<textarea rows="2" class="form-control form-control-sm rfq-row-vp-comment-input" placeholder="Komentarz do pozycji...">' +
            escapeHtml(cur) +
            '</textarea>' +
            '<div class="mt-1">' +
                '<button type="button" class="btn btn-sm btn-success rfq-row-vp-comment-save mr-1" data-vp-id="' + vpId + '" title="Zapisz (Ctrl+Enter)"><i class="bi bi-check-lg"></i> Zapisz</button>' +
                '<button type="button" class="btn btn-sm btn-outline-secondary rfq-row-vp-comment-cancel" title="Anuluj (Esc)">Anuluj</button>' +
            '</div>'
        );
        const $ta = $line.find('textarea').trigger('focus');
        const v = $ta.val();
        if (v) { $ta[0].selectionStart = $ta[0].selectionEnd = v.length; }
    });

    $tbody.on('click', '.rfq-row-vp-comment-cancel', function () {
        const $line = $(this).closest('.rfq-row-vp-comment-line');
        const $tr = $line.closest('tr');
        const vpId = parseInt($tr.attr('data-vendor-part-id'), 10);
        // Restore from the row's stamped value (last-saved wins; never
        // scrape the displayed HTML).
        const original = $tr.attr('data-vp-comment') || '';
        $line.replaceWith(renderVpCommentLine(vpId, original));
    });

    $tbody.on('click', '.rfq-row-vp-comment-save', function () {
        const $btn = $(this);
        const vpId = parseInt($btn.attr('data-vp-id'), 10);
        if (!vpId) return;
        const $line = $btn.closest('.rfq-row-vp-comment-line');
        const $tr = $line.closest('tr');
        const val = $.trim($line.find('textarea').val() || '');
        $btn.prop('disabled', true);
        $line.find('.rfq-row-vp-comment-cancel').prop('disabled', true);

        $.ajax({
            url: COMPONENTS_PATH + '/purchases/cart/vendor-part-comment.php',
            method: 'POST',
            dataType: 'json',
            data: { vp_id: vpId, comment: val }
        })
        .done(function (resp) {
            if (resp && resp.success) {
                // Update the row's stamped value so a subsequent edit
                // sees the same starting text.
                $tr.attr('data-vp-comment', val);
                $line.replaceWith(renderVpCommentLine(vpId, val));
                if (typeof setAlert === 'function') { setAlert('Komentarz zapisany.', 'success'); }
            } else {
                $btn.prop('disabled', false);
                $line.find('.rfq-row-vp-comment-cancel').prop('disabled', false);
                if (typeof setAlert === 'function') {
                    setAlert('Błąd zapisu komentarza: ' + ((resp && resp.error) || 'nieznany'), 'danger');
                }
            }
        })
        .fail(function (xhr, status) {
            $btn.prop('disabled', false);
            $line.find('.rfq-row-vp-comment-cancel').prop('disabled', false);
            const msg = (xhr && xhr.responseJSON && xhr.responseJSON.error) || status || 'Błąd sieci.';
            if (typeof setAlert === 'function') { setAlert(msg, 'danger'); }
            else { alert(msg); }
        });
    });

    $tbody.on('keydown', '.rfq-row-vp-comment-input', function (e) {
        if (e.key === 'Escape') {
            e.preventDefault();
            $(this).closest('.rfq-row-vp-comment-line').find('.rfq-row-vp-comment-cancel').trigger('click');
        } else if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
            e.preventDefault();
            $(this).closest('.rfq-row-vp-comment-line').find('.rfq-row-vp-comment-save').trigger('click');
        }
    });

    // ---- Add new item (inline form below the table) ----
    // Pattern-matched after cart's "Wybierz dostawcę i część" card.
    // Vendor is fixed for an RFQ so the cascade simplifies to
    // Part → VendorPart. The card starts hidden behind the toggle
    // button; opening it reveals the vendor line + part picker.
    //   - Part picked  → Row 2 (VP picker + packages + qty) reveals.
    //   - VP picked    → packages/qty inputs become visible inside
    //                    Row 2; Row 3 (price/currency/comment) and
    //                    Row 4 (actions) reveal.
    // Bidirectional packages ↔ qty binding (mirrors cart's
    // state.lastEdited / state.lastDerived pattern from cart-view.js
    // lines 1700–1770). Neither input is ever disabled. Editing one
    // re-derives the other; pack-stepper − / + cycles pickedPackSize
    // and re-derives the field marked lastEdited.

    const $addWrapper = $('.rfq-add-wrapper');
    if ($addWrapper.length === 0) return;

    const $addCard   = $addWrapper.find('.rfq-add-card');
    const $addToggle = $addWrapper.find('.rfq-add-toggle');

    const addState = {
        vendorId:       parseInt($addWrapper.attr('data-vendor-id'), 10),
        vendorName:     $addWrapper.attr('data-vendor-name') || '',
        rfqId:          parseInt($addWrapper.attr('data-rfq-id'), 10),
        excludeIds:     JSON.parse($addWrapper.attr('data-existing-vp-ids') || '[]'),
        vpsByPart:      JSON.parse($addWrapper.attr('data-vps-by-part') || '{}'),
        selectedVp:     null,
        pickedPackSize: null,
        // 'qty' | 'packages' | null — which input the user typed in
        // last. Drives the pack-stepper recompute: changing the picked
        // tier re-derives whichever field was NOT lastEdited.
        lastEdited:     null,
        // 'qty' | 'packages' | null — which input is currently
        // auto-derived from the other. null after the user types into
        // a field directly. (Reserved for symmetry with cart; not
        // actively branched on right now but kept for parity.)
        lastDerived:    null,
    };

    // Use id selectors (not class) — bootstrap-select copies our custom
    // class onto the generated wrapper div, so $addCard.find('.rfq-add-…')
    // would return BOTH the wrapper and the <select>. Then .html() /
    // .prop() would clobber the wrapper's <button> + <div class=
    // "dropdown-menu"> with our raw options. id selectors hit only the
    // <select> element.
    const $vendorName      = $('#rfq-add-vendor-name');
    const $partSelect      = $('#rfq-add-part-select');
    const $vpRow           = $('#rfq-add-vp-row');
    const $vpSelect        = $('#rfq-add-vendor-part-select');
    const $vpSummary       = $('#rfq-add-vp-summary');
    const $qtyWrap         = $('#rfq-add-qty-wrap');
    const $qtyInput        = $('#rfq-add-qty');
    const $packWrap        = $('#rfq-add-pack-wrap');
    const $packagesInput   = $('#rfq-add-packages');
    const $packSizeDisplay = $('.rfq-add-pack-size-display');
    const $packStepperRow  = $('#rfq-add-pack-stepper-row');
    const $packPrev        = $('#rfq-add-pack-prev');
    const $packNext        = $('#rfq-add-pack-next');
    const $packValue       = $('#rfq-add-pack-value');
    const $priceRow        = $('#rfq-add-price-row');
    const $priceInput      = $('#rfq-add-price');
    const $currencyInp     = $('#rfq-add-currency');
    const $commentInp      = $('#rfq-add-comment');
    const $actionsRow      = $('#rfq-add-actions-row');
    const $saveBtn         = $addCard.find('.rfq-add-save');
    const $cancelBtn       = $addCard.find('.rfq-add-cancel');

    // Vendor name into the small muted line above the part picker so
    // the user always sees which vendor this RFQ belongs to without
    // needing a picker (vendor is fixed for the duration of the RFQ).
    $vendorName.text(addState.vendorName || '—');

    // ---- Helpers ----

    // Cycle the picked pack size through the sorted list by ±1.
    // Returns the new picked value, or null if there is no list /
    // nothing to cycle to in the requested direction.
    function stepRfqPackSize(packs, current, delta) {
        if (!Array.isArray(packs) || packs.length === 0) return null;
        const sorted = packs.slice().sort(function (a, b) { return parseFloat(a) - parseFloat(b); });
        const idx = sorted.findIndex(function (p) { return parseFloat(p) === parseFloat(current); });
        if (idx === -1) {
            // current not in list — jump to the boundary the user asked for.
            return parseFloat(delta > 0 ? sorted[sorted.length - 1] : sorted[0]);
        }
        const nextIdx = idx + delta;
        if (nextIdx < 0 || nextIdx >= sorted.length) return parseFloat(sorted[idx]);
        return parseFloat(sorted[nextIdx]);
    }

    // Build the "= N szt./opak." display text from the current
    // pickedPackSize and the VP's unit name (JM).
    function packSizeDisplayText(picked, unit) {
        return '= ' + formatQty(picked) + ' ' + (unit || 'szt.') + '/opak.';
    }

    // Non-blocking unevenness check (cart cart-view.js lines 385–388).
    // True when there's nothing to flag: no pack size yet, qty missing,
    // or qty/pack rounds cleanly within an epsilon.
    function isEven(qty, pack) {
        if (!pack || pack <= 0 || qty == null || isNaN(qty)) return true;
        return Math.abs(qty / pack - Math.round(qty / pack)) < 1e-9;
    }

    // Apply or remove the `packages-uneven` warning class + title on the
    // last-edited input between the two amount fields. `lastEdited` is a
    // jQuery object ($packagesInput or $qtyInput); the OTHER field's
    // class is cleared first so only the most recently typed field
    // flags. When `even === true` both fields are left clean. Passing
    // `lastEdited = null` + NaN qty is the canonical "clear both"
    // reset (cart cart-view.js lines 486–501, adapted).
    function applyEvenWarning(lastEdited, qty, pack) {
        $packagesInput.removeClass('packages-uneven').removeAttr('title');
        $qtyInput.removeClass('packages-uneven').removeAttr('title');
        if (isEven(qty, pack)) return;
        if (!lastEdited || !lastEdited.length) return;
        lastEdited.addClass('packages-uneven');
        lastEdited.attr('title',
            'Uwaga: ilość nie odpowiada pełnej liczbie opakowań (wielkość: ' + formatQty(pack) + ')');
    }

    // Render the pack-size UI: the "= N jm/opak." line is shown for
    // any VP with at least one tier; the − [value] + chip group is
    // shown only when there are 2+ tiers (single tier = redundant).
    // Caller still owns addState.pickedPackSize assignment.
    function populateRfqPackStepper(vp, picked) {
        const packs = (vp && Array.isArray(vp.pack_quantities)) ? vp.pack_quantities.slice() : [];
        packs.sort(function (a, b) { return parseFloat(a) - parseFloat(b); });
        const unit = (vp && vp.unit_name) || '';
        if (packs.length === 0 || picked === null || isNaN(picked)) {
            $packSizeDisplay.hide().text('');
            $packStepperRow.hide();
            $packValue.text('—');
            $packPrev.prop('disabled', true);
            $packNext.prop('disabled', true);
            return;
        }
        const p = parseFloat(picked);
        $packSizeDisplay.text(packSizeDisplayText(p, unit)).show();
        $packValue.text(formatQty(p));
        $packPrev.prop('disabled', p === parseFloat(packs[0]));
        $packNext.prop('disabled', p === parseFloat(packs[packs.length - 1]));
        $packStepperRow.toggle(packs.length > 1);
    }

    // Save button enable check. qty > 0 is the only hard requirement;
    // if a pack tier is defined, pickedPackSize is auto-set so it's
    // always > 0 by construction.
    function refreshSaveEnabled() {
        const qtyRaw = ($qtyInput.val() || '').toString().replace(',', '.');
        const qty = parseFloat(qtyRaw);
        const ok = addState.selectedVp !== null && !isNaN(qty) && qty > 0;
        $saveBtn.prop('disabled', !ok);
    }

    function resetAddForm() {
        addState.selectedVp     = null;
        addState.pickedPackSize = null;
        addState.lastEdited     = null;
        addState.lastDerived    = null;
        // Clear any leftover unevenness flag on the amount inputs.
        applyEvenWarning(null, NaN, addState.pickedPackSize);

        // Cascade resets.
        $vpRow.hide();
        $vpSelect.empty();
        $vpSelect.prop('disabled', true);
        $vpSelect.selectpicker('refresh');
        $vpSummary.hide().empty();

        // Pack / qty inputs reset. Neither is ever disabled — both
        // stay editable so the bidirectional binding can re-derive
        // freely once a pack tier is set.
        $qtyWrap.hide();
        $qtyInput.val('');
        $packWrap.hide();
        $packagesInput.val('');
        $packSizeDisplay.hide().text('');
        $packStepperRow.hide();
        $packValue.text('—');
        $packPrev.prop('disabled', true);
        $packNext.prop('disabled', true);

        // Price / currency / comment reset.
        $priceRow.hide();
        $priceInput.val('');
        $currencyInp.val('PLN');
        $commentInp.val('');

        // Actions reset.
        $actionsRow.hide();
        $saveBtn.prop('disabled', true);
        $cancelBtn.prop('disabled', false);

        // Part picker reset.
        $partSelect.selectpicker('val', '');
    }

    function openAddForm() {
        // Always start from a clean state — first-open, after Wyczyść,
        // and after a successful save-and-reload all need the same
        // empty form.
        resetAddForm();
        $addCard.show();
        $addToggle.hide();
        // bootstrap-select auto-inits on DOM ready when the card was
        // still display:none; a refresh now that the card is visible
        // recomputes the trigger width against the now-visible parent.
        $partSelect.selectpicker('refresh');
        $vpSelect.selectpicker('refresh');
    }

    function closeAddForm() {
        resetAddForm();
        $addCard.hide();
        $addToggle.show();
    }

    $addToggle.on('click', openAddForm);
    $cancelBtn.on('click', closeAddForm);

    // ---- Cascade: part → VP ----
    // Group VendorParts by producer for the current part — same
    // optgroup pattern as cart (cart-view.js lines ~935–989).
    // Excludes vendor parts already on this RFQ (same VP can't be
    // added twice). Auto-selects the only candidate when exactly one
    // remains after exclusion.
    function refreshRfqVendorPartRow(partId) {
        addState.selectedVp     = null;
        addState.pickedPackSize = null;
        addState.lastEdited     = null;
        addState.lastDerived    = null;
        // Clear unevenness flag — the previous VP's pack relationship
        // no longer applies once the user re-picks the part.
        applyEvenWarning(null, NaN, addState.pickedPackSize);
        $vpSummary.hide().empty();
        $qtyWrap.hide();
        $qtyInput.val('');
        $packWrap.hide();
        $packagesInput.val('');
        $packSizeDisplay.hide().text('');
        $packStepperRow.hide();
        $packValue.text('—');
        $packPrev.prop('disabled', true);
        $packNext.prop('disabled', true);
        $priceRow.hide();
        $priceInput.val('');
        $currencyInp.val('PLN');
        $commentInp.val('');
        $actionsRow.hide();
        $saveBtn.prop('disabled', true);

        const allForPart = addState.vpsByPart[partId] || [];
        const available = allForPart.filter(function (vp) {
            return addState.excludeIds.indexOf(vp.id) === -1;
        });

        if (available.length === 0) {
            $vpSelect.empty();
            $vpSelect.prop('disabled', true);
            $vpSelect.selectpicker('refresh');
            $vpRow.hide();
            return;
        }

        // Group by producer; preserve first-seen order. data-unit-name
        // rides on each VP option so cart-style JM lookups (and the
        // "= N jm/opak." display) can read it from the DOM if needed.
        const groups = {};
        const order = [];
        available.forEach(function (vp) {
            const g = vp.producer_name || 'Bez producenta';
            if (!groups[g]) { groups[g] = []; order.push(g); }
            groups[g].push(vp);
        });
        let html = '';
        let firstVp = null;
        order.forEach(function (g) {
            html += '<optgroup label="' + escapeHtml(g) + '">';
            groups[g].forEach(function (vp) {
                const sub = (vp.producer_part_no && vp.producer_part_no !== vp.vendor_part_no)
                    ? ' data-subtext="' + escapeHtml(vp.producer_part_no) + '"'
                    : '';
                html += '<option value="' + vp.id + '"' + sub +
                        ' data-unit-name="' + escapeHtml(vp.unit_name || '') + '">' +
                        escapeHtml(vp.vendor_part_no) +
                        '</option>';
            });
            html += '</optgroup>';
            if (!firstVp) { firstVp = groups[g][0]; }
        });

        $vpSelect.html(html);
        $vpSelect.prop('disabled', false);
        $vpSelect.selectpicker('refresh');
        $vpRow.show();

        // If there's exactly one variant, auto-pick it for one-click UX.
        if (available.length === 1 && firstVp) {
            $vpSelect.selectpicker('val', String(firstVp.id));
            selectRfqVendorPart(firstVp);
        }
    }

    function selectRfqVendorPart(vp) {
        addState.selectedVp = vp;
        addState.lastEdited  = null;
        addState.lastDerived = null;

        // Summary under the VP picker: name · producer · JM badge.
        let summary = '<strong>' + escapeHtml(vp.vendor_part_no) + '</strong>';
        if (vp.producer_name) {
            summary += ' <span class="text-muted ml-1">(prod. ' + escapeHtml(vp.producer_name) + ')</span>';
        }
        summary += ' &middot; ';
        summary += '<span class="badge badge-info">' + escapeHtml(vp.unit_name) + '</span>';
        $vpSummary.html(summary).show();

        // Pack-size picker (visible when this VP has tiers). Default
        // tier = packs[0] (smallest). The "= N jm/opak." display is
        // always shown for any non-empty pack list; the − [value] +
        // chip group is shown only when there are 2+ tiers.
        const packs = (vp && Array.isArray(vp.pack_quantities)) ? vp.pack_quantities : [];
        if (packs.length === 0) {
            $packWrap.hide();
            $packagesInput.val('');
            addState.pickedPackSize = null;
            populateRfqPackStepper(vp, null);
            // VP has no pack tiers — unevenness has no meaning here;
            // clear any flag carried over from the previous VP.
            applyEvenWarning(null, NaN, addState.pickedPackSize);
        } else {
            addState.pickedPackSize = parseFloat(packs[0]);
            populateRfqPackStepper(vp, addState.pickedPackSize);
            $packWrap.show();
            $packagesInput.val('');
        }

        // Qty + price + actions reveal. Neither qty nor packages is
        // ever disabled — both stay editable so the bidirectional
        // binding can re-derive freely.
        $qtyWrap.show();
        $qtyInput.val('').trigger('focus');
        $priceRow.show();
        $priceInput.val('');
        $currencyInp.val('PLN');
        $commentInp.val('');
        $actionsRow.show();
        refreshSaveEnabled();
    }

    $partSelect.on('change', function () {
        const partId = parseInt($(this).val(), 10);
        if (!partId) {
            $vpRow.hide();
            $vpSelect.empty();
            $vpSelect.prop('disabled', true);
            $vpSelect.selectpicker('refresh');
            addState.selectedVp     = null;
            addState.pickedPackSize = null;
            addState.lastEdited     = null;
            addState.lastDerived    = null;
            // Clear unevenness flag — no part → no amount context.
            applyEvenWarning(null, NaN, addState.pickedPackSize);
            $vpSummary.hide().empty();
            $qtyWrap.hide();
            $qtyInput.val('');
            $packWrap.hide();
            $packagesInput.val('');
            $packSizeDisplay.hide().text('');
            $packStepperRow.hide();
            $priceRow.hide();
            $priceInput.val('');
            $actionsRow.hide();
            $saveBtn.prop('disabled', true);
            return;
        }
        refreshRfqVendorPartRow(partId);
    });

    $vpSelect.on('change', function () {
        const vpId = parseInt($(this).val(), 10);
        if (!vpId) {
            addState.selectedVp     = null;
            addState.pickedPackSize = null;
            addState.lastEdited     = null;
            addState.lastDerived    = null;
            // Clear unevenness flag — no VP → no amount context.
            applyEvenWarning(null, NaN, addState.pickedPackSize);
            $vpSummary.hide().empty();
            $qtyWrap.hide();
            $qtyInput.val('');
            $packWrap.hide();
            $packagesInput.val('');
            $packSizeDisplay.hide().text('');
            $packStepperRow.hide();
            $priceRow.hide();
            $priceInput.val('');
            $actionsRow.hide();
            $saveBtn.prop('disabled', true);
            return;
        }
        // Find VP in the cached index (single vendor, so flat search).
        let found = null;
        Object.keys(addState.vpsByPart).forEach(function (pid) {
            if (found) return;
            addState.vpsByPart[pid].forEach(function (vp) { if (vp.id === vpId) found = vp; });
        });
        if (found) { selectRfqVendorPart(found); }
    });

    // ---- Bidirectional packages ↔ qty binding ----
    // Mirrors cart's state.lastEdited / state.lastDerived pattern
    // (cart-view.js lines 1700–1770). Neither input is ever disabled.
    // Reading parseFloat('') → NaN, treated as "empty" in both
    // branches (we clear the derived field instead of writing 0).
    $packagesInput.on('input', function () {
        addState.lastEdited  = 'packages';
        addState.lastDerived = 'qty';
        const pack = addState.pickedPackSize;
        const pkgs = parseFloat(($(this).val() || '').toString().replace(',', '.'));
        if (pack === null || isNaN(pkgs) || pkgs <= 0) {
            // Empty / 0 / non-numeric: clear the derived qty (cart's
            // behavior — do not write 0, leave blank or whatever user
            // last typed).
            $qtyInput.val('');
        } else {
            const qty = pkgs * pack;
            $qtyInput.val(parseFloat(qty.toFixed(6)));
        }
        // After re-deriving qty from pkgs, warn when qty/pack isn't
        // even. lastEdited is packages here, so packages flags yellow.
        applyEvenWarning($packagesInput, parseFloat($qtyInput.val() || ''), pack);
        refreshSaveEnabled();
    });

    $qtyInput.on('input', function () {
        addState.lastEdited  = 'qty';
        addState.lastDerived = 'packages';
        const pack = addState.pickedPackSize;
        const qty = parseFloat(($(this).val() || '').toString().replace(',', '.'));
        if (pack === null || isNaN(qty) || qty <= 0) {
            $packagesInput.val('');
        } else {
            // No rounding (cart uses parseFloat directly): if the
            // qty/pack ratio is non-integer the input shows the
            // decimal — that's the user-visible signal that the qty
            // doesn't divide cleanly into the picked pack tier.
            const pkgs = qty / pack;
            $packagesInput.val(parseFloat(pkgs.toFixed(6)));
        }
        // lastEdited is qty here, so qty flags yellow when uneven.
        applyEvenWarning($qtyInput, qty, pack);
        refreshSaveEnabled();
    });

    $priceInput.on('input', refreshSaveEnabled);

    // ---- Pack-size stepper − / + handlers ----
    // No pencil / add-tier editor here — only existing tiers can be
    // navigated. On click: update pickedPackSize, refresh the size
    // text + stepper value, then re-derive the field marked
    // lastEdited (cart's cartAfterPackChange pattern, lines 1724–1770).
    // If lastEdited is null (user picked a tier but never typed in
    // either packages or qty) we leave both inputs alone.
    function afterPackChange(newPack) {
        if (newPack === null || isNaN(newPack) || newPack <= 0) return;
        addState.pickedPackSize = newPack;
        populateRfqPackStepper(addState.selectedVp, newPack);
        const pkgs = parseFloat(($packagesInput.val() || '').toString().replace(',', '.'));
        const qty  = parseFloat(($qtyInput.val() || '').toString().replace(',', '.'));
        if (addState.lastEdited === 'packages'
            && !isNaN(pkgs) && pkgs > 0) {
            const newQty = pkgs * newPack;
            $qtyInput.val(parseFloat(newQty.toFixed(6)));
            // Re-derive flag against the re-computed qty / new pack.
            // lastEdited is still packages, so packages keeps the
            // warning class if it's still uneven after the tier swap.
            applyEvenWarning($packagesInput, newQty, newPack);
        } else if (addState.lastEdited === 'qty'
            && !isNaN(qty) && qty > 0) {
            const newPkgs = qty / newPack;
            $packagesInput.val(parseFloat(newPkgs.toFixed(6)));
            // qty is the source of truth here; flag it if the new
            // ratio (qty / newPack) doesn't divide cleanly.
            applyEvenWarning($qtyInput, qty, newPack);
        }
        // else: user hasn't typed in either input yet — leave both
        // inputs alone (and skip the warning call entirely).
        refreshSaveEnabled();
    }

    $packPrev.on('click', function () {
        if (addState.selectedVp === null || addState.pickedPackSize === null) return;
        const packs = addState.selectedVp.pack_quantities || [];
        const next = stepRfqPackSize(packs, addState.pickedPackSize, -1);
        if (next !== null) { afterPackChange(next); }
    });

    $packNext.on('click', function () {
        if (addState.selectedVp === null || addState.pickedPackSize === null) return;
        const packs = addState.selectedVp.pack_quantities || [];
        const next = stepRfqPackSize(packs, addState.pickedPackSize, +1);
        if (next !== null) { afterPackChange(next); }
    });

    // Ctrl/Cmd+Enter anywhere in the card submits the form.
    $addCard.on('keydown', function (e) {
        if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
            e.preventDefault();
            if (!$saveBtn.prop('disabled')) { $saveBtn.trigger('click'); }
        }
    });

    // ---- Save → AJAX POST to rfq-item-add.php ----
    $saveBtn.on('click', function () {
        if (addState.selectedVp === null) return;

        const qtyRaw   = ($qtyInput.val() || '').toString().replace(',', '.');
        const qty      = parseFloat(qtyRaw);
        const priceRaw = ($priceInput.val() || '').toString().trim();
        const unitPrice = priceRaw === '' ? null : parseFloat(priceRaw.replace(',', '.'));
        const currency = ($currencyInp.val() || 'PLN').toString().trim() || 'PLN';
        const pack     = addState.pickedPackSize;
        const comment  = ($commentInp.val() || '').toString();

        // ---- validation ----
        if (isNaN(qty) || qty <= 0) {
            if (typeof setAlert === 'function') { setAlert('Ilość musi być > 0.', 'warning'); }
            else { alert('Ilość musi być > 0.'); }
            $qtyInput.trigger('focus');
            return;
        }
        if ($packWrap.is(':visible')) {
            // Pack wrap is up only when the VP has at least one tier;
            // pickedPackSize is auto-set to packs[0] > 0 in that branch,
            // so the > 0 check is a defensive guard.
            if (pack === null || isNaN(pack) || pack <= 0) {
                if (typeof setAlert === 'function') { setAlert('Wielkość opakowania musi być > 0.', 'warning'); }
                return;
            }
            const pkgs = parseFloat(($packagesInput.val() || '').toString().replace(',', '.'));
            // 0 / NaN = "use qty directly" (packages input left empty).
            // Negative is the only invalid value here.
            if (!isNaN(pkgs) && pkgs < 0) {
                if (typeof setAlert === 'function') { setAlert('Opakowania nie mogą być ujemne.', 'warning'); }
                $packagesInput.trigger('focus');
                return;
            }
        }
        if (unitPrice !== null && (isNaN(unitPrice) || unitPrice < 0)) {
            if (typeof setAlert === 'function') { setAlert('Cena nie może być ujemna.', 'warning'); }
            else { alert('Cena nie może być ujemna.'); }
            $priceInput.trigger('focus');
            return;
        }

        $saveBtn.prop('disabled', true);
        $cancelBtn.prop('disabled', true);

        $.ajax({
            url: COMPONENTS_PATH + '/Admin/Purchase/RFQs/rfq-item-add.php',
            method: 'POST',
            dataType: 'json',
            data: {
                rfq_id:           addState.rfqId,
                vendor_part_id:   addState.selectedVp.id,
                quantity:         qty,
                unit_price:       unitPrice === null ? '' : unitPrice,
                currency:         currency,
                picked_pack_size: pack === null ? '' : pack,
                comment:          comment
            }
        })
        .done(function (resp) {
            if (resp && resp.success) {
                if (typeof setAlert === 'function') { setAlert(resp.message || 'Pozycja dodana.', 'success'); }
                // Reload whole page so the new row picks up server-side
                // formatting (qty trailing-zero strip, Wartość calc,
                // vendor-part-comment sub-line, JM badge).
                window.location.reload();
            } else {
                $saveBtn.prop('disabled', false);
                $cancelBtn.prop('disabled', false);
                if (typeof setAlert === 'function') {
                    setAlert((resp && resp.error) || 'Błąd zapisu.', 'danger');
                } else {
                    alert((resp && resp.error) || 'Błąd zapisu.');
                }
            }
        })
        .fail(function (xhr, status) {
            $saveBtn.prop('disabled', false);
            $cancelBtn.prop('disabled', false);
            const msg = (xhr && xhr.responseJSON && xhr.responseJSON.error) || status || 'Błąd sieci.';
            if (typeof setAlert === 'function') { setAlert(msg, 'danger'); }
            else { alert(msg); }
        });
    });

});