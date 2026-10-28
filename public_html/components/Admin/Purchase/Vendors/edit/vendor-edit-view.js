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
 *   2. Supplier inline CRUD (edit mode only, inside #vrRelationsModal)
 *      - A single shared form handles both "add new" and "edit
 *        existing". Clicking "Edytuj" on a row populates the form
 *        from the row's data-supplier-json attribute and highlights
 *        the row. "Anuluj" clears the form.
 *      - "Włącz/Wyłącz" on a row shows an inline confirmation strip
 *        (NOT a second modal — a modal inside a modal stacks two
 *        backdrops in Bootstrap 4 and looks broken).
 *      - After add/update/toggle the page is reloaded, because the
 *        supplier list is server-rendered HTML. The reload keeps the
 *        operator's place: ?ok=<code> carries the message and the
 *        #vrRelations fragment reopens the modal.
 *
 *   3. Inline alert helper
 *      - Used for vendor-save responses, supplier-save responses,
 *        and supplier-toggle responses. Idempotent; success / info
 *        / warning alerts auto-dismiss, error alerts stay.
 *      - Alerts land in #vrFormAlertHost (top of the page) or, when
 *        the relations modal is open, in #vrModalAlertHost inside
 *        that modal — otherwise the backdrop would hide the message.
 *
 *   4. Preferred e-mail draft pickers (RFQ + PO)
 *      - Two bootstrap-select pickers pointing at the global
 *        `email_draft` catalogue. They live INSIDE #vrEditForm, so
 *        $form.serialize() picks them up — no manual POST patching.
 *      - The section is no longer collapsed, so the pickers size
 *        themselves correctly right away; a refresh on window.load
 *        is a safety net.
 *      - Editing the templates themselves happens in a separate
 *        module: /admin/purchase/email-drafts.
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
        }
        // Gdzie wstawić alert: wewnątrz modala powiązań (gdy jest
        // otwarty) albo na górze strony. .prepend() przenosi
        // istniejący element, więc alert „migruje" między hostami.
        const $modal = $('#vrRelationsModal');
        const $host = ($modal.length && $modal.hasClass('show'))
            ? $('#vrModalAlertHost')
            : $('#vrFormAlertHost');
        $host.prepend($alert);

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
    //
    // Reload nie może zamykać modala, w którym operator pracuje:
    //   ?ok=<code>  — kod komunikatu (treść siedzi w PHP),
    //   #vrRelations — po załadowaniu strony modal otwiera się sam.
    function reloadSuppliers(code) {
        const url = new URL(window.location.href);
        url.searchParams.set('ok', code);
        url.hash = '#vrRelations';
        window.location.replace(url.toString());
    }

    // ============================================================
    // Vendor dirty-form + AJAX submit
    // ============================================================
    $(document).ready(function() {
        const $vendorSubmitBtn = $('#vrSubmitBtn');
        function enableVendorSubmit() { $vendorSubmitBtn.prop('disabled', false); }
        $('#vrEditForm').on('input change', 'input, select, textarea', enableVendorSubmit);

        // Ścieżka z COMPONENTS_PATH (jak w kodzie osób kontaktowych) —
        // nie ma już zahardkodowanego prefiksu /atte_ms_new.
        const vendorSaveUrl = COMPONENTS_PATH
            + '/Admin/Purchase/Vendors/edit/vendor-edit-save.php';

        $('#vrEditForm').on('submit', function(e) {
            e.preventDefault();
            const $form = $(this);
            const $submit = $form.find('button[type="submit"]');
            $submit.prop('disabled', true);

            // Wszystkie pola (łącznie z pickerami preferowanych
            // szablonów) leżą wewnątrz #vrEditForm, więc serialize()
            // wystarcza — nic nie trzeba doklejać ręcznie.
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
                    // Odświeża licznik „N z 2 / domyślne" w nagłówku
                    // sekcji preferowanych szablonów.
                    $form.trigger('vr:saved');
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
    // Supplier inline CRUD (tylko w trybie edycji)
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
            // Formularz mieszka w modalu powiązań — jeśli modal jest
            // zamknięty (np. ktoś otworzył stronę z odnośnikiem),
            // trzeba go otworzyć.
            const $modal = $('#vrRelationsModal');
            if ($modal.length && !$modal.hasClass('show')) {
                $modal.modal('show');
            }
        }
        function hideSupplierForm() {
            $supplierForm.addClass('d-none');
            $supplierCancelBtn.addClass('d-none');
        }

        // Przewinięcie formularza osoby kontaktowej na widok. Formularz
        // siedzi w przewijanym .modal-body, więc przewijamy właśnie
        // jego, a nie okno strony (które przy otwartym modalu stoi).
        function scrollSupplierFormIntoView() {
            const $body = $('#vrRelationsModal .modal-body');
            if ($body.length === 0) {
                $('html, body').animate({ scrollTop: $supplierForm.offset().top - 80 }, 200);
                return;
            }
            const top = $supplierForm.offset().top - $body.offset().top + $body.scrollTop() - 12;
            $body.animate({ scrollTop: top }, 200);
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
            scrollSupplierFormIntoView();
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
                    // Przeładowanie z kodem komunikatu i fragmentem
                    // #vrRelations — modal wraca otwarty, komunikat
                    // widać po załadowaniu strony.
                    reloadSuppliers(id ? 'supplier-updated' : 'supplier-added');
                } else {
                    const msg = (r && r.error) ? r.error : 'Nieznany błąd.';
                    vrFlash(msg, 'danger');
                    $supplierSubmitBtn.prop('disabled', false);
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
            });
        });

        // ---- Toggle supplier (Włącz / Wyłącz) ----
        // Potwierdzenie to pasek wewnątrz modalu (a drugi modal
        // w modalu dawałby w Bootstrapie 4 dwie zasłony i wyglądał
        // zepsuty).
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
            $('#vrToggleConfirm').removeClass('d-none');
            $('#vrConfirmToggleSupplier').trigger('focus');
        });

        $('#vrCancelToggleSupplier').on('click', function() {
            $('#vrToggleConfirm').addClass('d-none');
        });

        $('#vrConfirmToggleSupplier').on('click', function() {
            const id       = parseInt($('#vrToggleSupplierId').val(), 10);
            const isActive = $('#vrToggleSupplierIsActive').val() === '1';
            $('#vrToggleConfirm').addClass('d-none');
            $.ajax({
                url: ajaxBase + 'supplier-toggle-active.php',
                type: 'POST',
                data: { id: id, isActive: isActive ? 1 : 0 },
                dataType: 'json',
            })
            .done(function(r) {
                if (r && r.success) {
                    reloadSuppliers('supplier-toggled');
                } else {
                    vrFlash((r && r.error) ? r.error : 'Błąd.', 'danger');
                }
            })
            .fail(function() {
                vrFlash('Błąd komunikacji z serwerem.', 'danger');
            });
        });
    });

    // ============================================================
    // Lista artykułów u dostawcy (blok w modalu powiązań)
    // ============================================================
    // Dostawca może mieć tysiące artykułów, więc lista jest rysowana
    // przez JS z AJAX-u (edit/vendor-parts-modal-list.php): jedna
    // strona wyników + filtry. PHP zostawia tylko skorupę tabeli.
    $(document).ready(function() {
        const $block = $('#vrVpBlock');
        if ($block.length === 0) return;   // tryb tworzenia — brak bloku

        const vendorId = parseInt($block.attr('data-vendor-id'), 10) || 0;
        const endpoint = COMPONENTS_PATH + '/Admin/Purchase/Vendors/edit/vendor-parts-modal-list.php';
        const PER_PAGE = 10;
        const COMMENT_TRUNCATE_AT = 200;

        const state = { q: '', status: 'all', packs: 'all', page: 1, total: null, pages: 0 };
        let xhr = null;
        let seq = 0;
        let loadedOnce = false;
        let timer = null;

        // ---------- Formaty ----------

        // Ilość bez zer końcowych (10 zamiast 10.0000; 0.5 zostaje 0.5).
        function vpFmtQty(n) {
            const f = parseFloat(n);
            if (isNaN(f)) { return String(n == null ? '—' : n); }
            return String(Math.round(f * 10000) / 10000);
        }

        // Polska liczba mnoga: 1 artykuł / 2 artykuły / 5 artykułów.
        function vpItemsGen(n) {
            if (n === 1) { return 'artykuł'; }
            const mod10  = n % 10;
            const mod100 = n % 100;
            if (mod10 >= 2 && mod10 <= 4 && !(mod100 >= 12 && mod100 <= 14)) {
                return 'artykuły';
            }
            return 'artykułów';
        }

        // Czy filtry są domyślne (nic nie zawężone)?
        function vpIsDefault() {
            return state.q === '' && state.status === 'all' && state.packs === 'all';
        }

        // Zakres w podsumowaniu: „tylko aktywne · bez opakowania".
        function vpScope() {
            const parts = [];
            if (state.status === 'active')   { parts.push('tylko aktywne'); }
            if (state.status === 'inactive') { parts.push('tylko nieaktywne'); }
            if (state.packs === 'with')      { parts.push('z opakowaniem'); }
            if (state.packs === 'without')   { parts.push('bez opakowania'); }
            return parts.join(' · ');
        }

        // ---------- Render ----------

        // Wiersz — ten sam markup, co renderował PHP przed AJAX-em
        // (klasy .vr-vp-* mają już style w vendor-edit-ui.css).
        function vpRenderRow(vp) {
            const isActive = vp.isActive === true || vp.isActive === 1 || vp.isActive === '1';
            const rowClass = isActive ? 'vr-vp-row--active' : 'vr-vp-row--inactive';

            // Artykuł: nazwa z katalogu + numer u dostawcy / producenta.
            let artykulCell = esc(vp.partName || '');
            const vn = vp.vendorPartNo || '';
            const pn = vp.producerPartNo || '';
            if (vn !== '' && pn !== '' && vn === pn) {
                artykulCell += '<span class="vr-vp-sub">Nr dost./prod.: <span class="vr-vp-code">'
                            + esc(vn) + '</span></span>';
            } else {
                if (vn !== '') {
                    artykulCell += '<span class="vr-vp-sub">Nr dost.: <span class="vr-vp-code">'
                                + esc(vn) + '</span></span>';
                }
                if (pn !== '') {
                    artykulCell += '<span class="vr-vp-sub">Nr prod.: <span class="vr-vp-code">'
                                + esc(pn) + '</span></span>';
                }
            }

            // Opakowania — po jednej plakietce na wielkość paczki.
            let packsHtml = '<span class="text-muted">—</span>';
            if (Array.isArray(vp.packQuantities) && vp.packQuantities.length > 0) {
                packsHtml = vp.packQuantities.map(function(p) {
                    return '<span class="vr-pack-badge">' + esc(vpFmtQty(p)) + '</span>';
                }).join('');
            }

            // Komentarz — ikona + treść ucięta; pełna w title.
            const rawCmt = (vp.comment == null ? '' : String(vp.comment)).trim();
            let cmtHtml = '<span class="text-muted">—</span>';
            if (rawCmt !== '') {
                const full = esc(rawCmt);
                const visible = rawCmt.length > COMMENT_TRUNCATE_AT
                    ? esc(rawCmt.slice(0, COMMENT_TRUNCATE_AT)) + '…'
                    : full;
                cmtHtml = '<small class="vr-vp-comment" title="' + full + '">'
                        + '<i class="bi bi-journal-text"></i> ' + visible + '</small>';
            }

            return '<tr class="' + rowClass + '">' +
                '<td class="vr-vp-col-id text-center">' + esc(vp.id) + '</td>' +
                '<td>' + artykulCell + '</td>' +
                '<td>' + (vp.producerName ? esc(vp.producerName) : '<span class="text-muted">—</span>') + '</td>' +
                '<td>' + (vp.unitName ? esc(vp.unitName) : '<span class="text-muted">—</span>') + '</td>' +
                '<td>' + packsHtml + '</td>' +
                '<td>' + cmtHtml + '</td>' +
            '</tr>';
        }

        // Szkielet pierwszego wczytania (klasy .rx-skel z receipts-ui.css).
        function vpSkeletonHtml() {
            let html = '';
            for (let i = 0; i < 6; i++) {
                html += '<tr class="rx-skel-row">' +
                    '<td><span class="rx-skel rx-skel--sm" style="width:1.5rem;margin:0 auto"></span></td>' +
                    '<td><span class="rx-skel" style="width:60%"></span><span class="rx-skel rx-skel--sm" style="width:40%"></span></td>' +
                    '<td><span class="rx-skel" style="width:70%"></span></td>' +
                    '<td><span class="rx-skel rx-skel--sm" style="width:3rem"></span></td>' +
                    '<td><span class="rx-skel rx-skel--sm" style="width:4rem"></span></td>' +
                    '<td><span class="rx-skel" style="width:80%"></span></td>' +
                '</tr>';
            }
            return html;
        }

        // Pusty stan: „nie ma żadnych" vs „filtry nie pasują".
        function vpEmptyHtml() {
            if (!vpIsDefault()) {
                return '<div class="rx-empty rx-empty--compact">' +
                    '<div class="rx-empty-icon"><i class="bi bi-funnel"></i></div>' +
                    '<div class="rx-empty-title">Brak wyników</div>' +
                    '<div class="rx-empty-text">' +
                        'Żaden artykuł nie pasuje do filtrów. Zmień je albo wyczyść.</div>' +
                    '</div>';
            }
            return '<div class="rx-empty rx-empty--compact">' +
                '<div class="rx-empty-icon"><i class="bi bi-box-seam"></i></div>' +
                '<div class="rx-empty-title">Brak artykułów</div>' +
                '<div class="rx-empty-text">' +
                    'Ten dostawca nie ma jeszcze przypisanych artykułów.</div>' +
                '</div>';
        }

        // Stan kontrolek: segmenty + link „Wyczyść filtry".
        function vpSyncUi() {
            $('#vrVpBlock .rx-seg [data-vp-dim]').each(function() {
                const on = state[$(this).attr('data-vp-dim')] === $(this).attr('data-vp-value');
                $(this).toggleClass('is-active', on).attr('aria-pressed', on ? 'true' : 'false');
            });
            $('#vrVpClear').toggleClass('d-none', vpIsDefault());
        }

        // Licznik w nagłówku bloku: po filtrach liczba przefiltrowanych,
        // a w title zawsze liczba wszystkich — żeby chip w nagłówku modala
        // (serwerowy, bez filtrów) nie wyglądał na sprzeczny.
        function vpUpdateChip() {
            if (state.total === null) { return; }
            const $chip = $('#vrVpCountChip');
            const all = $chip.attr('data-vp-all') || '0';
            $chip.text(state.total);
            $chip.attr('title', vpIsDefault()
                ? 'Wszystkie artykuły tego dostawcy'
                : 'Artykuły po zastosowaniu filtrów (wszystkich: ' + all + ')');
        }

        // Linijka wyników.
        function vpRenderSummary() {
            const $el = $('#vrVpSummary');
            if (state.total === null) {
                $el.text('Wczytywanie artykułów…');
                return;
            }
            if (state.total === 0) {
                $el.html((vpIsDefault() ? '' : '<i class="bi bi-funnel-fill"></i> ') + 'Brak wyników');
                return;
            }
            const first = (state.page - 1) * PER_PAGE + 1;
            const last  = Math.min(state.page * PER_PAGE, state.total);
            const scope = vpScope();
            $el.html((vpIsDefault() ? '' : '<i class="bi bi-funnel-fill"></i> ') +
                'Wyświetlanie <strong>' + first + '–' + last + '</strong> z <strong>' +
                state.total + '</strong> ' + vpItemsGen(state.total) +
                (scope ? ' <span class="vxe-vp-sep text-faint">·</span> ' + scope : ''));
        }

        // Pager — wzorowany na vendor-parts-view.js, z klasami
        // z prefiksem .vxe-vp-*, żeby nie kolidował z listą artykułów.
        function vpRenderPager() {
            const $top = $('#vrVpPagerTop');
            if (state.total === null || state.total === 0 || state.pages <= 1) {
                $top.empty();
                return;
            }
            const atStart = state.page <= 1;
            const atEnd   = state.page >= state.pages;

            function btn(action, icon, title, disabled) {
                return '<button type="button" class="btn rx-btn-quiet vxe-vp-page-btn" data-action="' + action + '"' +
                    ' title="' + title + '" aria-label="' + title + '"' + (disabled ? ' disabled' : '') + '>' +
                    '<i class="bi ' + icon + '"></i></button>';
            }

            let items = '';
            for (let p = 1; p <= state.pages; p++) {
                items += '<a class="dropdown-item vxe-vp-page-item' + (p === state.page ? ' active' : '') +
                    '" href="#" data-page="' + p + '">Strona ' + p + '</a>';
            }

            $top.html('<div class="btn-group btn-group-sm vxe-vp-pager-compact" role="group" aria-label="Strony artykułów">' +
                btn('prev', 'bi-chevron-left', 'Poprzednia strona', atStart) +
                '<div class="btn-group btn-group-sm">' +
                    '<button type="button" class="btn rx-btn-quiet dropdown-toggle rx-pager-current" data-toggle="dropdown">' +
                    'Strona ' + state.page + ' z ' + state.pages + '</button>' +
                    '<div class="dropdown-menu dropdown-menu-right rx-pager-menu">' + items + '</div>' +
                '</div>' +
                btn('next', 'bi-chevron-right', 'Następna strona', atEnd) +
                '</div>');
        }

        // ---------- Pobieranie ----------

        function vpLoad(page) {
            if (vendorId <= 0) { return; }
            const p = Math.max(1, parseInt(page, 10) || 1);
            state.page = p;
            state.q = ($('#vrVpSearch').val() || '').trim();
            vpSyncUi();

            const mySeq = ++seq;
            if (xhr) { xhr.abort(); }

            if (!loadedOnce) {
                // Pierwsze wczytanie — szkielet w miejscu wierszy.
                $('#vrVpTBody').html(vpSkeletonHtml());
                $('#vrVpTableWrap').prop('hidden', false);
                $('#vrVpEmpty').prop('hidden', true).empty();
            } else {
                // Kolejne wczytanie — poprzednie wiersze zostają widoczne
                // (przygaszone), żeby nie migać pustą tabelą.
                $('#vrVpTableWrap').addClass('is-loading');
            }

            xhr = $.ajax({
                url: endpoint,
                type: 'POST',
                data: {
                    vendor_id: vendorId,
                    page:      p,
                    per_page:  PER_PAGE,
                    q:         state.q,
                    status:    state.status,
                    packs:     state.packs,
                },
                dataType: 'json',
            })
            .done(function(r) {
                if (mySeq !== seq) { return; }
                $('#vrVpTableWrap').removeClass('is-loading');

                if (!r || !r.success) {
                    vrFlash((r && r.error) ? r.error : 'Błąd ładowania artykułów.', 'danger');
                    return;
                }

                const total = parseInt(r.total, 10) || 0;
                const pages = parseInt(r.pages, 10) || 0;
                const rows  = r.rows || [];

                // Strona poza zakresem (np. filtr zmieniony na dalszej
                // stronie) — wracamy na pierwszą.
                if (total > 0 && p > pages) {
                    vpLoad(1);
                    return;
                }

                state.total = total;
                state.pages = pages;
                loadedOnce  = true;

                if (total === 0) {
                    $('#vrVpTBody').empty();
                    $('#vrVpTableWrap').prop('hidden', true);
                    $('#vrVpEmpty').html(vpEmptyHtml()).prop('hidden', false);
                } else {
                    $('#vrVpEmpty').prop('hidden', true).empty();
                    $('#vrVpTableWrap').prop('hidden', false);
                    $('#vrVpTBody').html(rows.map(vpRenderRow).join(''));
                }

                vpUpdateChip();
                vpRenderSummary();
                vpRenderPager();
            })
            .fail(function(xhr2, status) {
                if (status === 'abort' || mySeq !== seq) { return; }
                $('#vrVpTableWrap').removeClass('is-loading');
                vrFlash('Błąd komunikacji z serwerem.', 'danger');
            });
        }

        // Zmiana filtra — zawsze wracamy na stronę 1.
        function vpFilterChanged() {
            vpLoad(1);
        }

        // ---------- Zdarzenia ----------

        // Wyszukiwarka: krótka zwłoka, żeby nie odpytywać po każdym
        // znaku; Enter od razu.
        $('#vrVpSearch').on('input', function() {
            clearTimeout(timer);
            timer = setTimeout(vpFilterChanged, 300);
        }).on('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                clearTimeout(timer);
                vpFilterChanged();
            }
        });

        // Przełączniki filtra.
        $('#vrVpBlock').on('click', '.rx-seg [data-vp-dim]', function() {
            const dim = $(this).attr('data-vp-dim');
            const val = $(this).attr('data-vp-value');
            if (state[dim] === val) { return; }
            state[dim] = val;
            vpFilterChanged();
        });

        // Wyczyść filtry.
        $('#vrVpClear').on('click', function() {
            state.q = '';
            state.status = 'all';
            state.packs = 'all';
            $('#vrVpSearch').val('');
            vpFilterChanged();
        });

        // Pager.
        $('#vrVpPagerTop').on('click', '.vxe-vp-page-btn', function() {
            const act = $(this).attr('data-action');
            if (act === 'prev')      { vpLoad(state.page - 1); }
            else if (act === 'next') { vpLoad(state.page + 1); }
        });
        $('#vrVpPagerTop').on('click', '.vxe-vp-page-item', function(e) {
            e.preventDefault();
            vpLoad(parseInt($(this).attr('data-page'), 10) || 1);
        });

        // Pierwsza strona wczytuje się przy otwarciu modala.
        $('#vrRelationsModal').on('shown.bs.modal', function() {
            vpLoad(1);
        });
    });

    // ============================================================
    // Modal powiązań + pickery preferowanych szablonów
    // ============================================================
    $(document).ready(function() {
        // Powrót po zmianie osoby kontaktowej: modal ma być znowu
        // otwarty (przeładowanie zostawia w adresie #vrRelations).
        if (window.location.hash === '#vrRelations') {
            const $modal = $('#vrRelationsModal');
            if ($modal.length) { $modal.modal('show'); }
        }

        const $card = $('#vrDraftCard');
        if ($card.length === 0) return;   // tryb bez karty preferencji

        // Tooltip przy tytule sekcji. Popper jest już w headerze, więc
        // wystarczy inicjalizacja zakresowa — globalnego initu projekt
        // nie ma (patrz transfer-view.js, ten sam wzorzec).
        $card.find('[data-toggle="tooltip"]').tooltip();

        const $pickers = $('select[name="preferred_email_draft_rfq_id"], '
                         + 'select[name="preferred_email_draft_po_id"]');
        if ($pickers.length === 0) return;

        // data-width="100%" + data-container="#vrDraft*Cell" + styl
        // rx-picker-btn siedzą już w HTML-u (patrz AGENTS.md — sekcja o
        // bootstrap-select); tutaj dokładamy tylko to, czego nie da się
        // wyrazić atrybutem.
        // size: 8 — lista szablonów rośnie, a picker bez limitu potrafi
        // zasłonić resztę formularza zamiast się przewijać.
        $pickers.selectpicker({
            size: 8,
            noneResultsText: 'Brak szablonu pasującego do {0}',
        });

        // Sekcja nie jest zwinięta, więc picker powinien policzyć swoją
        // szerokość od razu — odświeżenie po załadowaniu strony jest
        // siatką bezpieczeństwa (gdyby styl dociągnął się później).
        $(window).on('load', function() {
            $pickers.selectpicker('refresh');
        });

        // Chip w nagłówku sekcji: liczy typy dokumentów, dla których
        // dostawca ma WŁASNY szablon (różny od globalnego domyślnego).
        // `data-default-id` to id globalnego domyślnego dla danego
        // typu — wybór równy domyślnemu to wciąż „podążanie za
        // domyślnym", więc nie liczy się jako własny wybór. Ta sama
        // reguła po stronie serwera (vendor-edit-save.php) zamienia
        // taki wybór na NULL w `list__vendor`.
        function refreshDraftChip() {
            let custom = 0;
            $pickers.each(function() {
                const def = parseInt($(this).attr('data-default-id'), 10) || 0;
                const val = parseInt($(this).val(), 10) || 0;
                if (val > 0 && val !== def) { custom++; }
            });
            $('#vrDraftCount')
                .toggleClass('is-on', custom > 0)
                .text(custom === 0 ? 'domyślne' : (custom === 1 ? '1 własny' : custom + ' własne'));
        }

        // Po udanym zapisie licznik w nagłówku sekcji przestaje kłamać
        // o tym, ile własnych szablonów dostawca ma ustawionych.
        $('#vrEditForm').on('vr:saved', refreshDraftChip);
    });
})();
