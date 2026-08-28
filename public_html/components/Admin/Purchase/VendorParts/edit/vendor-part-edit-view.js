/**
 * vendor-part-edit-view.js
 *
 * UX helpers for the dedicated vendor-part edit/create page.
 *
 * Two responsibilities:
 *   1. Multi-pack row UX — append an empty row on "Dodaj opakowanie",
 *      remove on ×, no enforced minimum (parts that aren't packaged
 *      are allowed to have zero pack sizes).
 *   2. AJAX form submit — intercept the form's submit event, POST to
 *      vendor-part-edit-save.php, show the response as an inline
 *      alert (success or error). On a successful CREATE the response
 *      includes `edit_url`; the JS navigates there so the operator
 *      lands on the edit page for the freshly-created vendor-part
 *      (mirrors the cart-view.js "open in new tab" pattern). On a
 *      successful UPDATE the JS stays on the page and shows an
 *      inline success alert.
 *
 * The number inputs use `name="pack_quantities[]"`, which PHP
 * parses into $_POST['pack_quantities'] as an array — standard
 * jQuery-free form serialization, no client-side magic needed.
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
    function vpFlash(message, kind) {
        kind = kind || 'danger';
        let $alert = $('#vpFormAlert');
        if ($alert.length === 0) {
            $alert = $(
                '<div id="vpFormAlert" class="alert alert-dismissible fade show" role="alert" style="display:none">'
                + '<i class="bi bi-exclamation-triangle"></i> '
                + '<span class="vp-form-alert-text"></span>'
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
        $alert.find('.vp-form-alert-text').text(message);

        if (vpFlash._dismissTimer) {
            clearTimeout(vpFlash._dismissTimer);
            vpFlash._dismissTimer = null;
        }
        if (kind === 'success' || kind === 'info' || kind === 'warning') {
            vpFlash._dismissTimer = setTimeout(function() {
                $alert.fadeOut(200, function() { $(this).remove(); });
            }, 3000);
        }
    }

    $(document).ready(function() {
        const $list  = $('#vpPackList');
        const $addBtn = $('#vpAddPackBtn');

        if ($list.length === 0 || $addBtn.length === 0) {
            // Page didn't render the pack list — nothing to wire.
            return;
        }

        // Mirror the rendering shape used by vendor-part-edit-view.php so
        // a server-side row and a JS-added row are visually identical.
        // step="0.0001" matches the smallest DECIMAL precision the
        // project ever asks for (see list__vendor_part_pack).
        function rowHtml(value) {
            const v = (value == null) ? '' : String(value);
            return ''
                + '<div class="vp-pack-row input-group">'
                +   '<input type="number" name="pack_quantities[]" '
                +          'class="form-control" '
                +          'step="0.0001" min="0.0001" required '
                +          'placeholder="np. 100" value="' + $('<div>').text(v).html() + '">'
                +   '<div class="input-group-append">'
                +     '<button type="button" class="btn btn-outline-danger vp-pack-remove" '
                +             'title="Usuń opakowanie">'
                +       '<i class="bi bi-x-lg"></i>'
                +     '</button>'
                +   '</div>'
                + '</div>';
        }

        // Re-evaluate the × button state across every existing row.
        // Called on init + after every add / remove so the rule
        // always reflects the current row count. Zero packs is a
        // valid state (parts that aren't packaged) so the × button
        // is always enabled — the only thing that disappears when
        // the user removes the last row is the row itself. They can
        // re-add via "Dodaj opakowanie".
        function refreshRemoveState() {
            $list.children('.vp-pack-row').each(function() {
                const $btn = $(this).find('.vp-pack-remove');
                $btn.prop('disabled', false)
                    .attr('title', 'Usuń opakowanie');
            });
        }

        $addBtn.on('click', function() {
            $list.append(rowHtml(''));
            refreshRemoveState();
            enableSubmit();
            // Focus the new input so the operator can type immediately.
            $list.children('.vp-pack-row').last().find('input[type="number"]').trigger('focus');
        });

        // Delegate the remove click so it works for both server-
        // rendered and JS-added rows.
        $list.on('click', '.vp-pack-remove', function() {
            $(this).closest('.vp-pack-row').remove();
            refreshRemoveState();
            enableSubmit();
        });

        refreshRemoveState();

        // ----- Dirty-form enable/disable -----
        // The submit button starts disabled (rendered that way by
        // the PHP view). Any user input — typing in a text field,
        // toggling a radio, adding/removing a pack row, picking
        // something from a bootstrap-select — enables it. Once
        // enabled, it stays enabled: the AJAX submit handler
        // re-disables it on click and re-enables it on completion,
        // so even a successful save leaves the button clickable
        // (and the operator can tweak the form further).
        const $submitBtn = $('#vpSubmitBtn');
        function enableSubmit() { $submitBtn.prop('disabled', false); }
        $('#vpEditForm').on('input change', 'input, select, textarea', enableSubmit);
        // bootstrap-select uses a delegated event fired on the
        // underlying native select when the operator picks a value.
        $('#vpEditForm').on('change.bs.select', 'select.selectpicker', enableSubmit);
        // New pack rows are added with `name="pack_quantities[]"`.
        // The `input` event bubbles from JS-added inputs because
        // we delegate on `#vpEditForm` (not on the rows themselves),
        // so typing in a fresh pack row also enables submit.

        // ----- AJAX form submit -----
        // The form has no `action` attribute so the browser would
        // POST to the current URL on submit (the view file, which
        // would just re-render the form and lose the input). We
        // intercept the submit, POST the serialized form data to
        // the dedicated save endpoint, and show the JSON response
        // as an inline alert.
        const saveUrl = window.location.origin + '/atte_ms_new/public_html/components/Admin/Purchase/VendorParts/edit/vendor-part-edit-save.php';

        $('#vpEditForm').on('submit', function(e) {
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
                    // On CREATE the save endpoint returns `edit_url` —
                    // navigate there so the operator lands on the edit
                    // page for the freshly-created vendor-part. The
                    // form fields then reflect the canonical DB state
                    // (id visible in the heading, packs pre-populated)
                    // and further submits do UPDATEs instead of a
                    // duplicate CREATE.
                    if (r.edit_url) {
                        window.location.href = r.edit_url + '&created=1';
                        return;
                    }
                    // On UPDATE — stay on the page, show success alert.
                    vpFlash(r.message || 'Zapisano zmiany.', 'success');
                    // Keep the submit button disabled — the form is
                    // now clean against the DB. Any further input
                    // re-enables it via the dirty-form handlers above.
                    $(window).scrollTop(0);
                } else {
                    const msg = (r && r.error) ? r.error : 'Nieznany błąd.';
                    vpFlash(msg, 'danger');
                    $submit.prop('disabled', false);
                    // Scroll to the top so the alert is visible.
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
                vpFlash(msg, 'danger');
                $submit.prop('disabled', false);
                $(window).scrollTop(0);
            });
        });
    });
})();