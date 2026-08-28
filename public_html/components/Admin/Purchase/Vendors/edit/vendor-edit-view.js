/**
 * vendor-edit-view.js
 *
 * UX for the dedicated vendor edit/create page. Three concerns:
 *
 *   1. Vendor dirty-form enable + AJAX submit
 *      - Submit button starts disabled (rendered that way by PHP).
 *      - Any user input enables it.
 *      - Submit POSTs the serialized form to vendor-edit-save.php
 *        and shows the response as an inline alert. On CREATE the
 *        response carries `edit_url`; we navigate there so the
 *        operator lands on the edit page for the new vendor. On
 *        UPDATE we stay on the page and show a success alert.
 *
 *   2. Supplier inline CRUD (edit mode only)
 *      - A single shared form handles both "add new" and "edit
 *        existing". Clicking "Edytuj" on a row populates the form
 *        from the row's data-supplier-json attribute and highlights
 *        the row. "Anuluj edycję" clears the form.
 *      - "Włącz/Wyłącz" on a row opens a small confirm modal that
 *        POSTs to supplier-toggle-active.php. We deliberately keep
 *        the confirm-modal friction for status flips — the producer
 *        page uses a radio in the form, but suppliers are managed
 *        inline and the toggle button matches the legacy UX.
 *      - After add/update/toggle we reload the supplier list via
 *        AJAX so the operator stays on the page.
 *
 *   3. Inline alert helper
 *      - Used for vendor-save responses, supplier-save responses,
 *        and supplier-toggle responses. Idempotent; success / info
 *        / warning alerts auto-dismiss, error alerts stay.
 */
(function() {
    'use strict';

    // ============================================================
    // Inline alert helper (shared by vendor + supplier ops)
    // ============================================================
    function vrFlash(message, kind) {
        kind = kind || 'danger';
        let $alert = $('#vrFormAlert');
        if ($alert.length === 0) {
            $alert = $(
                '<div id="vrFormAlert" class="alert alert-dismissible fade show" role="alert" style="display:none">'
                + '<i class="bi bi-exclamation-triangle"></i> '
                + '<span class="vr-form-alert-text"></span>'
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
        $alert.find('.vr-form-alert-text').text(message);

        if (vrFlash._dismissTimer) {
            clearTimeout(vrFlash._dismissTimer);
            vrFlash._dismissTimer = null;
        }
        if (kind === 'success' || kind === 'info' || kind === 'warning') {
            vrFlash._dismissTimer = setTimeout(function() {
                $alert.fadeOut(200, function() { $(this).remove(); });
            }, 3000);
        }
    }

    // ============================================================
    // Helpers
    // ============================================================
    function esc(str) {
        return $('<div>').text(str == null ? '' : String(str)).html();
    }

    // ============================================================
    // Supplier list re-render (reload after add/update/toggle)
    // ============================================================
    //
    // After any supplier mutation we reload the whole page. Reasons:
    //   - The supplier list is server-rendered with HTML that already
    //     encodes the row tint, status badge, and per-row buttons —
    //     rebuilding that on the client duplicates a lot of markup.
    //   - Operators edit suppliers occasionally; the cost of a refresh
    //     is acceptable.
    //   - A bespoke AJAX endpoint just for the supplier list would
    //     add backend code without much UX win.
    function reloadSuppliers() {
        window.location.reload();
    }

    // ============================================================
    // Vendor dirty-form + AJAX submit
    // ============================================================
    $(document).ready(function() {
        const $vendorSubmitBtn = $('#vrSubmitBtn');
        function enableVendorSubmit() { $vendorSubmitBtn.prop('disabled', false); }
        $('#vrEditForm').on('input change', 'input, select, textarea', enableVendorSubmit);

        const vendorSaveUrl = window.location.origin
            + '/atte_ms_new/public_html/components/Admin/Purchase/Vendors/edit/vendor-edit-save.php';

        $('#vrEditForm').on('submit', function(e) {
            e.preventDefault();
            const $form = $(this);
            const $submit = $form.find('button[type="submit"]');
            $submit.prop('disabled', true);

            $.ajax({
                url: vendorSaveUrl,
                type: 'POST',
                data: $form.serialize(),
                dataType: 'json',
            })
            .done(function(r) {
                if (r && r.success) {
                    if (r.edit_url) {
                        // CREATE — navigate to the edit page for the
                        // freshly-created vendor so the operator can
                        // add suppliers immediately. The &created=1
                        // flag triggers a success toast on the edit
                        // page (handled by the listing JS — not used
                        // here, but harmless to carry forward for
                        // future-proofing).
                        window.location.href = r.edit_url + '&created=1';
                        return;
                    }
                    vrFlash(r.message || 'Zapisano zmiany.', 'success');
                    $(window).scrollTop(0);
                } else {
                    const msg = (r && r.error) ? r.error : 'Nieznany błąd.';
                    vrFlash(msg, 'danger');
                    $submit.prop('disabled', false);
                    $(window).scrollTop(0);
                }
            })
            .fail(function(xhr) {
                let msg = 'Błąd komunikacji z serwerem.';
                try {
                    const r = JSON.parse(xhr.responseText);
                    if (r && r.error) { msg = r.error; }
                } catch (e) { /* leave default */ }
                vrFlash(msg, 'danger');
                $submit.prop('disabled', false);
                $(window).scrollTop(0);
            });
        });
    });

    // ============================================================
    // Supplier inline CRUD (only on edit mode — page has the form
    // + table only when $isEditMode is true)
    // ============================================================
    $(document).ready(function() {
        const $supplierForm = $('#vrSupplierForm');
        if ($supplierForm.length === 0) return; // create mode: no suppliers

        const ajaxBase = COMPONENTS_PATH + "/Admin/Purchase/Vendors/";
        const $supplierSubmitBtn = $('#vrSupplierSubmitBtn');
        const $supplierCancelBtn = $('#vrSupplierCancelBtn');
        const $formTitle = $('#vrSupplierFormTitle');

        function resetSupplierForm() {
            $supplierForm[0].reset();
            $('#vrSupplierId').val('');
            // Reset isActive to "Aktywna" by default for fresh adds.
            $supplierForm.find('input[name="isActive"][value="1"]').prop('checked', true);
            $supplierSubmitBtn.prop('disabled', true);
            $formTitle.text('Dodaj osobę kontaktową');
            $('#vrSupplierTBody tr.vr-supplier-row--editing').removeClass('vr-supplier-row--editing');
        }

        // The form + its Anuluj button are hidden by default (d-none
        // on both). The "Dodaj osobę kontaktową" button reveals them
        // for new entries, and "Edytuj" on a row reveals + repopulates
        // them for existing entries. "Anuluj" hides them again.
        function showSupplierForm() {
            $supplierForm.removeClass('d-none');
            $supplierCancelBtn.removeClass('d-none');
            $('#vrSupplierCollapse').collapse('show');
        }
        function hideSupplierForm() {
            $supplierForm.addClass('d-none');
            $supplierCancelBtn.addClass('d-none');
        }

        // Dirty-form enable for the supplier form. Independent from
        // the vendor dirty-form so they don't interfere.
        function enableSupplierSubmit() { $supplierSubmitBtn.prop('disabled', false); }
        $supplierForm.on('input change', 'input, select, textarea', enableSupplierSubmit);

        // ---- Edytuj row ----
        // Repurpose the shared form: populate with the row's data and
        // highlight the row being edited.
        $(document).on('click', '.vr-supplier-edit-btn', function() {
            const $row = $(this).closest('tr');
            const data = $row.data('supplier-json');
            if (!data) { vrFlash('Nie udało się wczytać danych osoby kontaktowej.', 'danger'); return; }

            $('#vrSupplierId').val(data.id);
            $('#vr_supplier_name').val(data.name || '');
            $('#vr_supplier_job_title').val(data.jobTitle || '');
            $('#vr_supplier_phone').val(data.phone || '');
            $('#vr_supplier_email').val(data.email || '');
            $('#vr_supplier_comment').val(data.comment || '');

            const activeVal = data.isActive ? '1' : '0';
            $supplierForm.find('input[name="isActive"]').each(function() {
                $(this).prop('checked', $(this).val() === activeVal);
            });

            // Highlight the row being edited; clear any previous highlight.
            $('#vrSupplierTBody tr.vr-supplier-row--editing').removeClass('vr-supplier-row--editing');
            $row.addClass('vr-supplier-row--editing');

            $supplierSubmitBtn.prop('disabled', false);
            $formTitle.text('Edytuj osobę kontaktową #' + data.id);
            // Form is hidden by default — reveal it so the operator
            // can tweak immediately.
            showSupplierForm();

            // Scroll the form into view + focus the name field so
            // the operator can tweak immediately.
            $('html, body').animate({ scrollTop: $supplierForm.offset().top - 80 }, 200);
            $('#vr_supplier_name').trigger('focus');
        });

        $supplierCancelBtn.on('click', function() {
            resetSupplierForm();
            hideSupplierForm();
        });

        // ---- Dodaj osobę kontaktową (new) ----
        // Reveals the form, cleared and ready for a fresh entry.
        $('#vrSupplierAddBtn').on('click', function() {
            resetSupplierForm();
            showSupplierForm();
            $('#vr_supplier_name').trigger('focus');
        });

        // ---- Submit supplier form ----
        $supplierForm.on('submit', function(e) {
            e.preventDefault();
            const id = $('#vrSupplierId').val();
            const data = {
                vendor_id: $('#vrSupplierVendorId').val(),
                name:      $('#vr_supplier_name').val().trim(),
                job_title: $('#vr_supplier_job_title').val().trim(),
                phone:     $('#vr_supplier_phone').val().trim(),
                email:     $('#vr_supplier_email').val().trim(),
                comment:   $('#vr_supplier_comment').val().trim(),
                isActive:  $supplierForm.find('input[name="isActive"]:checked').val(),
            };
            if (!data.name) { vrFlash('Imię i nazwisko jest wymagane.', 'warning'); return; }

            const endpoint = id ? 'supplier-update.php' : 'supplier-add.php';
            if (id) { data.id = id; }

            $supplierSubmitBtn.prop('disabled', true);

            $.ajax({
                url: ajaxBase + endpoint,
                type: 'POST',
                data: data,
                dataType: 'json',
            })
            .done(function(r) {
                if (r && r.success) {
                    vrFlash(r.message || 'Zapisano.', 'success');
                    // Reload to re-render the supplier table from
                    // server-rendered HTML (see "Reload strategy"
                    // comment above for why).
                    reloadSuppliers();
                } else {
                    const msg = (r && r.error) ? r.error : 'Nieznany błąd.';
                    vrFlash(msg, 'danger');
                    $supplierSubmitBtn.prop('disabled', false);
                    $(window).scrollTop(0);
                }
            })
            .fail(function(xhr) {
                let msg = 'Błąd komunikacji z serwerem.';
                try {
                    const r = JSON.parse(xhr.responseText);
                    if (r && r.error) { msg = r.error; }
                } catch (e) { /* leave default */ }
                vrFlash(msg, 'danger');
                $supplierSubmitBtn.prop('disabled', false);
                $(window).scrollTop(0);
            });
        });

        // ---- Toggle supplier (Włącz / Wyłącz) ----
        // Opens a confirm modal so the operator doesn't flip status
        // by accident. Mirrors the legacy inline-toggle UX.
        $(document).on('click', '.vr-supplier-toggle-btn', function() {
            const id       = $(this).data('id');
            const isActive = $(this).data('is-active') == 1;
            const name     = $(this).data('name');
            const verb     = isActive ? 'wyłączyć' : 'włączyć';
            $('#vrToggleSupplierId').val(id);
            $('#vrToggleSupplierIsActive').val(isActive ? '0' : '1');
            $('#vrToggleSupplierBody').html(
                'Czy na pewno chcesz <b>' + verb + '</b> osobę <b>'
                + esc(name) + '</b>?'
            );
            $('#vrToggleSupplierModal').modal('show');
        });

        $('#vrConfirmToggleSupplier').on('click', function() {
            const id       = parseInt($('#vrToggleSupplierId').val(), 10);
            const isActive = $('#vrToggleSupplierIsActive').val() === '1';
            $('#vrToggleSupplierModal').modal('hide');
            $.ajax({
                url: ajaxBase + 'supplier-toggle-active.php',
                type: 'POST',
                data: { id: id, isActive: isActive ? 1 : 0 },
                dataType: 'json',
            })
            .done(function(r) {
                if (r && r.success) {
                    vrFlash(r.message || 'Status zmieniony.', 'success');
                    reloadSuppliers();
                } else {
                    vrFlash((r && r.error) ? r.error : 'Błąd.', 'danger');
                }
            })
            .fail(function() {
                vrFlash('Błąd komunikacji z serwerem.', 'danger');
            });
        });
    });
})();