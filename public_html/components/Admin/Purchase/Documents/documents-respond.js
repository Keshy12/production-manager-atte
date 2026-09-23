/*
 * "Oznacz odpowiedź" — submit handler.
 *
 * Submits #rfq-respond-form to the shared document-rfq-respond-ajax.php
 * endpoint (the same one the send-wizard's "respond" step uses; payload
 * now includes per-item unit_price/currency/comment). On success the
 * page redirects to the RFQ edit view so the operator sees the new
 * `responded` badge + the "Utwórz zamówienie z odpowiedzi" CTA that
 * becomes available in this state (see documents-edit.php lines 230-247).
 *
 * UI conventions mirrored from the rest of the project:
 *   - setAlert() global from header.js (writes to #alertContainer)
 *   - COMPONENTS_PATH/ROOT_DIR globals from header.js (URL prefix)
 *   - jQuery $.ajax + .done() / .fail()
 *   - spinner + disabled buttons during in-flight POST
 */

$(function () {
    const $form = $('#rfq-respond-form');
    if ($form.length === 0) {
        return;
    }
    const $btn = $('#respond-submit-btn');
    const URL = COMPONENTS_PATH + '/purchases/documents/document-rfq-respond-ajax.php';
    // rfqId przekazujemy z PHP jako data-rfq-id na formularzu
    // (nie inline <?= ?>, bo plik .js ładowany jest jako zwykły asset
    // — PHP-owa interpolacja nie zadziała po stronie serwera).
    const rfqId = $form.data('rfq-id');

    $form.on('submit', function (e) {
        e.preventDefault();
        const $buttons = $form.find('button');
        const originalHtml = $btn.html();
        $buttons.prop('disabled', true);
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
                window.location.href = window.location.origin + ROOT_DIR
                    + '/admin/purchase/documents/edit?id=' + encodeURIComponent(rfqId) + '&type=rfq';
            }, 700);
        }).fail(function (xhr) {
            const msg = (xhr && xhr.responseJSON && xhr.responseJSON.error)
                || (xhr && xhr.statusText)
                || 'Błąd komunikacji z serwerem.';
            setAlert(msg, 'danger');
            $buttons.prop('disabled', false);
            $btn.html(originalHtml);
        });
    });
});