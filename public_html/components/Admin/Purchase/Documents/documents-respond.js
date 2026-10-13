/*
 * "Oznacz odpowiedź" — frontend glue for the redesigned 6-column layout
 * that mirrors /purchase/receipts/receive ("Pozycje do przyjęcia").
 *
 * What this file does:
 *   1. Live recalculation of per-currency totals (qty × price, grouped
 *      by currency) and the per-currency grand total as the user edits
 *      qty/price/currency.
 *   2. Pack-size sanity hint: reuses the .packages-uneven class from
 *      purchases.css to flag qty values that don't divide evenly into
 *      the picked pack size (operator can still submit — it's a hint,
 *      not a hard error).
 *   3. Bulk actions in the card header:
 *        #respondFillAllBtn  — copy each row's `data-asked-qty` into the
 *                              editable qty input.
 *        #respondClearAllBtn — clear every row's price input.
 *   4. Submit-button state: enabled by default. The form is pre-filled
 *      with the RFQ's existing qty/unit/price values from the database,
 *      so the operator can hit submit without touching anything to
 *      confirm the response as-is (no-op save is intentional). Validation
 *      runs on submit (qty > 0 on every row).
 *   5. Submit: validates qty > 0 on every row (price may stay blank),
 *      disables buttons + shows spinner, POSTs to
 *      document-rfq-respond-ajax.php, redirects on success.
 *
 * Conventions (AGENTS.md / header.js globals):
 *   - setAlert() writes to #alertContainer
 *   - COMPONENTS_PATH / ROOT_DIR URL prefix
 *   - jQuery $.ajax + .done() / .fail()
 *   - `let`/`const` only, no `var`
 */

$(function () {
    const $form     = $('#rfq-respond-form');
    if ($form.length === 0) {
        return;
    }
    const $btn      = $('#respondSubmitBtn');
    const URL       = COMPONENTS_PATH + '/purchases/documents/document-rfq-respond-ajax.php';
    const $rows     = $form.find('tr.rfq-respond-row');
    const $totals   = $('#respond-totals');
    const $unpriced = $('#respond-unpriced');
    const $summary  = $('#respondSummary');
    const $hint     = $('#respondHint');
    const $hintSlot = $('.rfq-respond-actionbar-hint');

    // ----- Per-row recompute: line value + per-currency totals -----
    function recalc() {
        const perCurrency = {};
        let unpriced = 0;
        $rows.each(function () {
            const $row   = $(this);
            const qty    = parseFloat(($row.find('.js-qty').val() || '').toString().replace(',', '.'));
            const priceS = ($row.find('.js-price').val() || '').toString().replace(',', '.').trim();
            const price  = (priceS === '') ? null : parseFloat(priceS);
            const $lineTotal = $row.find('.js-line-total');
            $row.toggleClass('is-unpriced', price === null);
            if (price === null) { unpriced++; $lineTotal.text(''); return; }
            if (isNaN(qty) || qty <= 0) { $lineTotal.text(''); return; }
            const line = qty * price;
            const cur  = $row.find('.js-currency').val() || 'PLN';
            perCurrency[cur] = (perCurrency[cur] || 0) + line;
            $lineTotal.text('= ' + formatPrice(line) + ' ' + cur);
        });

        const codes = Object.keys(perCurrency).sort();
        $totals.html(codes.length
            ? codes.map(c => formatPrice(perCurrency[c]) + ' ' + c).join(' &middot; ')
            : '<span class="text-muted">—</span>');
        $unpriced.text(unpriced > 0 ? (unpriced + ' z ' + $rows.length + ' pozycji bez ceny') : '');

        $summary.text('Zapisujesz odpowiedź dla ' + $rows.length + ' z ' + $rows.length + ' pozycji');
        $hint.text(unpriced > 0
            ? ('Pozostawienie pustej ceny oznacza pozycję niewycenioną (' + unpriced + ').')
            : '');
    }

    // ----- Two-way binding: qty <-> packs (mirrors receive-page.js lines 638-660) -----
    //   - changing .js-qty  → recomputes .js-packs (= qty / pack)
    //   - changing .js-packs → recomputes .js-qty (= packs × pack)
    // The packs input has no `name` attribute — only qty is submitted.
    function packOf($row) {
        return parseFloat(($row.attr('data-pack') || '').toString().replace(',', '.'));
    }
    function recalcPacksFromQty($row) {
        const pack = packOf($row);
        const $packs = $row.find('.js-packs');
        const qty    = parseFloat(($row.find('.js-qty').val() || '').toString().replace(',', '.'));
        if (!pack || pack <= 0 || isNaN(qty) || qty <= 0) {
            $packs.val('').removeClass('packages-uneven');
            return;
        }
        const packages = qty / pack;
        const wholeOk  = Math.abs(packages - Math.round(packages)) < 1e-9;
        $packs.val(wholeOk ? String(Math.round(packages)) : packages.toFixed(4));
        $packs.toggleClass('packages-uneven', !wholeOk);
    }
    function recalcQtyFromPacks($row) {
        const pack = packOf($row);
        const $qty = $row.find('.js-qty');
        const packs = parseFloat(($row.find('.js-packs').val() || '').toString().replace(',', '.'));
        if (!pack || pack <= 0 || isNaN(packs) || packs < 0) {
            return;
        }
        // Multiplication always yields a whole-pack qty — clear the warning.
        $qty.val(pack * packs);
        $row.find('.js-packs').removeClass('packages-uneven');
    }

    function formatPrice(v) {
        if (v === null || isNaN(v)) return '—';
        // Polish number: space thousands, comma decimal, strip trailing zeros (max 4 fraction digits).
        return v.toFixed(4).replace('.', ',').replace(/,?0+$/, '').replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    }

    // ----- Two-way qty <-> packs + line totals on any input change -----
    $rows.on('input', '.js-qty', function () {
        const $row = $(this).closest('tr.rfq-respond-row');
        recalcPacksFromQty($row);
        recalc();
    });
    $rows.on('input', '.js-packs', function () {
        const $row = $(this).closest('tr.rfq-respond-row');
        recalcQtyFromPacks($row);
        recalc();
    });
    $rows.on('change', '.js-price, .js-currency', recalc);

    // ----- Bulk actions -----
    $('#respondFillAllBtn').on('click', function () {
        $rows.each(function () {
            const $row = $(this);
            $row.find('.js-qty').val($row.attr('data-asked-qty') || '');
            recalcPacksFromQty($row);
        });
        recalc();
    });
    $('#respondClearAllBtn').on('click', function () {
        $rows.each(function () {
            $(this).find('.js-price').val('');
        });
        recalc();
    });

    // ----- First paint -----
    $rows.each(function () { recalcPacksFromQty($(this)); });
    recalc();

    // ----- Submit -----
    $form.on('submit', function (e) {
        e.preventDefault();
        let firstBad = null;
        $rows.each(function () {
            const $row  = $(this);
            const qtyS  = ($row.find('.js-qty').val() || '').toString().replace(',', '.').trim();
            const qty   = parseFloat(qtyS);
            const $qty  = $row.find('.js-qty');
            const $err  = $row.find('.js-qty-error');
            const $msg  = $row.find('.js-qty-msg');
            if (qtyS === '' || isNaN(qty) || qty <= 0) {
                $qty.addClass('is-invalid');
                $err.text('Ilość musi być większa od zera.').show();
                $msg.text('Uzupełnij ilość.').addClass('text-warning');
                if (!firstBad) firstBad = $qty;
            } else {
                $qty.removeClass('is-invalid');
                $err.text('').hide();
                $msg.text('').removeClass('text-warning');
            }
        });
        if (firstBad) {
            setAlert('Uzupełnij ilość we wszystkich pozycjach.', 'warning');
            firstBad.focus();
            return;
        }

        // Lock + spinner.
        const $buttons = $form.find('button').add($('#respondSubmitBtn'))
            .add($('#respondFillAllBtn')).add($('#respondClearAllBtn'))
            .prop('disabled', true);
        const originalHtml = $btn.html();
        $btn.html('<span class="spinner-border spinner-border-sm mr-1"></span> Zapisuję…');

        $.ajax({
            url: URL,
            method: 'POST',
            data: $form.serialize(),
            dataType: 'json'
        }).done(function (r) {
            if (!r || !r.success) {
                setAlert((r && r.error) || 'Błąd zapisu odpowiedzi.', 'danger');
                $buttons.prop('disabled', false);
                $btn.html(originalHtml);
                return;
            }
            setAlert('Odpowiedź zapisana. Przekierowanie…', 'success');
            setTimeout(function () {
                window.location.href = window.location.origin + ROOT_DIR + '/admin/purchase/documents/edit?id=' + $form.data('rfq-id') + '&type=rfq';
            }, 700);
        }).fail(function (xhr) {
            setAlert((xhr.responseJSON && xhr.responseJSON.error) || 'Błąd komunikacji z serwerem.', 'danger');
            $buttons.prop('disabled', false);
            $btn.html(originalHtml);
        });
    });
});