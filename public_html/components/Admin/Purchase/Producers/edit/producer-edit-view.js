/**
 * producer-edit-view.js
 *
 * UX helpers for the dedicated producer edit/create page.
 *
 * Two responsibilities:
 *   1. Dirty-form enable — the submit button starts disabled (rendered
 *      that way by the PHP view). Any user input — typing in a text
 *      field, toggling a radio — enables it. Once enabled, it stays
 *      enabled: the AJAX submit handler re-disables it on click and
 *      re-enables it on completion, so even a successful save leaves
 *      the button clickable (and the operator can tweak the form
 *      further).
 *   2. AJAX form submit — intercept the form's submit event, POST to
 *      producer-edit-save.php, show the response as an inline alert
 *      (success or error). On a successful CREATE, the response
 *      includes `edit_url`; the JS navigates there so the operator
 *      lands on the edit page for the freshly-created producer. On a
 *      successful UPDATE, the JS stays on the page and shows the
 *      success alert (matching the vendor-parts edit flow).
 *
 * The form has no `action` attribute so the browser would POST to
 * the current URL on submit (the view file, which would just
 * re-render the form and lose the input). We intercept the submit
 * and POST the serialized form data to the dedicated save endpoint.
 */
(function() {
    'use strict';

    // ----- Inline alert helper -----
    // Shows the server response message at the top of the page. Used
    // for both error (red) and success (green) replies. Idempotent:
    // if the alert already exists we just swap its body + kind.
    // Success / info / warning alerts auto-dismiss after a few seconds
    // so the operator can keep working without an extra click. Error
    // alerts stay until the operator dismisses them — the message is
    // action-required.
    function prFlash(message, kind) {
        kind = kind || 'danger';
        let $alert = $('#prFormAlert');
        if ($alert.length === 0) {
            $alert = $(
                '<div id="prFormAlert" class="alert alert-dismissible fade show" role="alert" style="display:none">'
                + '<i class="bi bi-exclamation-triangle"></i> '
                + '<span class="pr-form-alert-text"></span>'
                + '<button type="button" class="close" data-dismiss="alert" aria-label="Zamknij">'
                +   '<span aria-hidden="true">&times;</span>'
                + '</button>'
                + '</div>'
            );
            $('.container-fluid.w-75').first().prepend($alert);
        }
        $alert.removeClass('alert-danger alert-success alert-warning alert-info')
              .addClass('alert-' + kind)
              .show();
        $alert.find('.pr-form-alert-text').text(message);

        if (prFlash._dismissTimer) {
            clearTimeout(prFlash._dismissTimer);
            prFlash._dismissTimer = null;
        }
        if (kind === 'success' || kind === 'info' || kind === 'warning') {
            prFlash._dismissTimer = setTimeout(function() {
                $alert.fadeOut(200, function() { $(this).remove(); });
            }, 3000);
        }
    }

    $(document).ready(function() {

        // ----- Dirty-form enable/disable -----
        const $submitBtn = $('#prSubmitBtn');
        function enableSubmit() { $submitBtn.prop('disabled', false); }
        $('#prEditForm').on('input change', 'input, select, textarea', enableSubmit);

        // ----- AJAX form submit -----
        const saveUrl = window.location.origin + '/atte_ms_new/public_html/components/Admin/Purchase/Producers/edit/producer-edit-save.php';

        $('#prEditForm').on('submit', function(e) {
            e.preventDefault();
            const $form = $(this);
            const $submit = $form.find('button[type="submit"]');
            $submit.prop('disabled', true);

            $.ajax({
                url: saveUrl,
                type: 'POST',
                data: $form.serialize(),
                dataType: 'json',
            })
            .done(function(r) {
                if (r && r.success) {
                    // On CREATE the save endpoint returns edit_url — navigate
                    // there so the operator lands on the edit page for the
                    // freshly-created producer (mirrors the cart-view.js
                    // "open in new tab" pattern, just in same-tab).
                    if (r.edit_url) {
                        // Update the listing so the new producer appears in
                        // its filters when the operator returns to it.
                        // We can't reach the listing's history from here,
                        // so just navigate; the listing reloads on visit.
                        window.location.href = r.edit_url + '&created=1';
                        return;
                    }
                    // On UPDATE — stay on the page, show success alert.
                    prFlash(r.message || 'Zapisano zmiany.', 'success');
                    // Keep the submit button disabled — the form is now
                    // clean against the DB. Any further input re-enables
                    // it via the dirty-form handlers above.
                    $(window).scrollTop(0);
                } else {
                    const msg = (r && r.error) ? r.error : 'Nieznany błąd.';
                    prFlash(msg, 'danger');
                    $submit.prop('disabled', false);
                    $(window).scrollTop(0);
                }
            })
            .fail(function(xhr) {
                let msg = 'Błąd komunikacji z serwerem.';
                // The endpoint always returns JSON with {success, error}
                // — try to surface its message even on HTTP-level failures
                // (e.g. 500 from a hard crash that bypassed the catch).
                try {
                    const r = JSON.parse(xhr.responseText);
                    if (r && r.error) { msg = r.error; }
                } catch (e) { /* leave default */ }
                prFlash(msg, 'danger');
                $submit.prop('disabled', false);
                $(window).scrollTop(0);
            });
        });
    });
})();