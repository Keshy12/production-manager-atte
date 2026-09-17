/**
 * Wizard "Wyślij dokument" — klient dla /admin/purchase/documents/send.
 *
 * Liniowy, czterokrokowy kreator. Jeden endpoint GET na wejściu
 * (documents-send-get.php) zwraca pełny snapshot dokumentu (nagłówek,
 * pozycje, kontakty, breakdown wartości per waluta, maszyna stanów).
 *
 * Kroki:
 *   1. Podsumowanie  — read-only; toggle backdate; ostrzeżenie przy
 *                      re-send (<1 min od sent_at).
 *   2. Kontakty      — checkbox cards (główny kontakt odznaczony ⭐),
 *                      filtr substringiem, placeholder link "Dodaj
 *                      pierwszy kontakt" (backend POST /purchases/
 *                      admin/contacts/add do dorobienia przez fixer).
 *   3. PDF           — radio Tak/Nie, placeholder-preview.
 *   4. Potwierdzenie — recapa + commit (POST do document-send-ajax.php
 *                      albo document-rfq-respond-ajax.php dla rfq+sent).
 *
 * Kontrakt GET/POST jest zamrożony (patrz documents-send-get.php,
 * document-send-ajax.php, document-rfq-respond-ajax.php).
 *
 * Konwencje: jQuery + Bootstrap 4 (bez bootstrap-select w tym wizardze
 * — formularze są proste, żaden picker). let/const wyłącznie. setAlert
 * z header.js do ogłaszania błędów sieciowych; własny alert na
 * sukces commita.
 */
$(function () {

    // ---- DOM hooks --------------------------------------------------

    const $root            = $('#send-wizard-root');
    if ($root.length === 0) return;

    const DOC_TYPE         = $root.attr('data-doc-type') || '';
    const DOC_ID           = parseInt($root.attr('data-doc-id'), 10) || 0;
    const LOADER_URL       = $root.attr('data-loader-url')  || '';
    const SEND_URL         = $root.attr('data-send-url')    || '';
    const RESPOND_URL      = $root.attr('data-respond-url') || '';
    const BACK_URL         = $root.attr('data-back-url')    || '';
    const LIST_URL         = $root.attr('data-list-url')    || '';
    const SUPPLIERS_URL    = $root.attr('data-suppliers-url') || '';
    const SUPPLIER_CREATE_URL = $root.attr('data-supplier-create-url') || '';
    const SUPPLIER_UPDATE_URL = $root.attr('data-supplier-update-url') || '';

    const $errorBanner     = $('#send-wizard-error');
    const $title           = $('#send-wizard-title');
    const $subtitle        = $('#send-wizard-subtitle');
    const $stepper         = $('#send-wizard-stepper');
    const $stepEls         = $root.find('.send-step');
    const $nextButtons     = $root.find('.send-step-next');
    const $prevButtons     = $root.find('.send-step-prev');
    const $commitBtn       = $('#send-commit-btn');
    const $commitBtnLabel  = $('#send-commit-btn-label');
    const $backToDoc       = $('#send-back-to-doc');
    const $successWrap     = $('#send-success-wrap');
    const $successAlert    = $('#send-success-alert');

    // Cacheuj snapshot z loadera — wszystkie kroki czytają z niego.
    const state = {
        doc:              null,    // pełny `doc` z loadera
        step:             1,
        // Decyzje użytkownika
        backdatedAt:      null,    // string 'Y-m-d H:i:s' albo null (= "teraz")
        resendWarnAck:    false,   // czy kliknięto "rozumiem" w ostrzeżeniu re-send
        selectedSuppliers: [],     // [id, id, ...]
        generatePdf:      true,    // radio: Tak/Nie w kroku 3
        // Lokalny cache kopii filtrującej (nie mutujemy `doc.suppliers`)
        supplierFilter:   '',
        committing:       false,
    };

    // ---- Helpers ---------------------------------------------------

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function formatQty(n) {
        if (n === null || n === undefined || n === '') return '—';
        const v = parseFloat(n);
        if (isNaN(v)) return '—';
        return String(+parseFloat(parseFloat(v).toFixed(4)).toString());
    }

    function formatPrice(n) {
        if (n === null || n === undefined || n === '') return '—';
        const v = parseFloat(n);
        if (isNaN(v)) return '—';
        // Polish convention: space thousand separator, strip trailing
        // zeros, locale en-US jako w documents-view.js (linie 211-225).
        let s = v.toLocaleString('en-US', { useGrouping: true, maximumFractionDigits: 10 });
        s = s.replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, '');
        return s.replace(/,/g, ' ');
    }

    function copyToClipboard(text) {
        // Bezpieczna kopia tekstu do schowka. Fallback na textarea +
        // execCommand dla starszych przeglądarek lub braku HTTPS.
        if (!text) return;
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).catch(function () { fallbackCopy(text); });
            return;
        }
        fallbackCopy(text);
    }
    function fallbackCopy(text) {
        const $ta = $('<textarea>').val(text)
            .css({ position: 'fixed', left: '-9999px' })
            .appendTo('body');
        $ta[0].select();
        try { document.execCommand('copy'); } catch (e) { /* ignore */ }
        $ta.remove();
    }
    function flashCopyToast(msg) {
        // Krótki toast „Skopiowano" — górny-prawy róg, znika sam po 1.2 s.
        $('#send-copy-toast').remove();
        const $t = $('<div id="send-copy-toast" class="send-copy-toast alert alert-success py-1 px-2">'
            + '<i class="bi bi-check-circle-fill"></i> ' + escapeHtml(msg || 'Skopiowano')
        + '</div>').appendTo('#send-wizard-root');
        setTimeout(function () { $t.fadeOut(200, function () { $(this).remove(); }); }, 1200);
    }
    function flashCopyButton($btn) {
        // Lokalny feedback na przycisku: ikona → check, kolor → zielony,
        // delikatne „pulse" scale. Po 1.2s wraca do normy. Działa na
        // tej samej klasie .send-copy-btn w kroku 2 i kroku 4.
        const $icon = $btn.find('i');
        const origClass = $icon.attr('class') || 'bi bi-clipboard';
        $btn.addClass('is-copied');
        $icon.attr('class', 'bi bi-check-circle-fill');
        setTimeout(function () {
            $icon.attr('class', origClass);
            $btn.removeClass('is-copied');
        }, 1200);
    }

    function setStep(n) {
        state.step = n;
        // Pokaż tylko aktywny panel; reszta display:none. Używamy
        // .is-active (per .send-step.is-active) do obu: wizualnego
        // podświetlenia i widoczności — style CSS chowają nieaktywne.
        $stepEls.each(function () {
            const step = parseInt($(this).attr('data-step'), 10);
            if (step === n) {
                $(this).addClass('is-active').show();
            } else {
                $(this).removeClass('is-active').hide();
            }
        });
        // Przeładuj treść aktywnego kroku przed pokazaniem — recap
        // kroku 4 i tak jest już przeładowywany przy każdej zmianie
        // zaznaczenia (refreshStep2NextEnabled), ale inne kroki (np.
        // po edycji daty wysłania w kroku 1 w drodze powrotnej) też
        // korzystają z „świeżego" renderu.
        if (n === 4 && typeof renderStep4 === 'function') renderStep4();
        if (n === 2 && typeof renderStep2 === 'function') renderStep2();
        if (n === 3 && typeof renderStep3 === 'function') renderStep3();
        // Stepper — aktywny + completed po lewej od aktywnego.
        $stepper.find('.breadcrumb-item').each(function () {
            const step = parseInt($(this).attr('data-step'), 10);
            $(this).removeClass('is-active is-done');
            if (step === n) $(this).addClass('is-active');
            else if (step < n) $(this).addClass('is-done');
        });
        // Przewiń na górę panelu (klik "Dalej" często zostawia scroll
        // na dole poprzedniego).
        $('html, body').animate({ scrollTop: $root.offset().top - 60 }, 200);
    }

    function showError(msg) {
        $errorBanner.text(msg).show();
    }

    function disableAllNavigation() {
        // Tryb read-only: sendable=false. Wszystkie buttony zablokowane,
        // banner na górze. Loader i tak załadował dane do wyświetlenia
        // podsumowania — tylko akcje są wyłączone.
        $nextButtons.prop('disabled', true);
        $prevButtons.prop('disabled', true);
        $commitBtn.prop('disabled', true);
        $root.find('input, select, textarea').prop('disabled', true);
    }

    function refreshStep1NextEnabled() {
        // Wymagane: brak nierozwiązanego ostrzeżenia re-send.
        const needsAck = needsResendAck();
        const $btn = $('#send-step-1 .send-step-next');
        $btn.prop('disabled', needsAck && !state.resendWarnAck);
    }

    function needsResendAck() {
        // Re-send ostrzeżenie: gdy doc był wysłany < 60s temu, kolejna
        // wysyłka nadpisze sent_at. Wymuszamy potwierdzenie checkboxem
        // zanim odblokujemy "Dalej".
        if (!state.doc || !state.doc.sentAt) return false;
        const sent = Date.parse(state.doc.sentAt.replace(' ', 'T'));
        if (isNaN(sent)) return false;
        const diffMs = Date.now() - sent;
        return diffMs >= 0 && diffMs < 60_000;
    }

    // ---- Loader ----------------------------------------------------

    function loadDocument() {
        // Pokaż spinner w pierwszym kroku.
        $('#send-step-1 .send-step-spinner').show();

        $.ajax({
            url: LOADER_URL,
            method: 'POST',
            dataType: 'json',
            data: { type: DOC_TYPE, id: DOC_ID }
        })
        .done(function (resp) {
            $('#send-step-1 .send-step-spinner').hide();
            if (!resp || !resp.success) {
                showError((resp && resp.error) || 'Nie udało się załadować dokumentu.');
                disableAllNavigation();
                return;
            }
            state.doc = resp.doc;
            renderPageFromDoc();
        })
        .fail(function (xhr, status) {
            $('#send-step-1 .send-step-spinner').hide();
            const msg = (xhr && xhr.responseJSON && xhr.responseJSON.error) || status || 'Błąd sieci.';
            showError('Nie udało się załadować dokumentu: ' + msg);
            disableAllNavigation();
        });
    }

    // ---- Renderowanie po załadowaniu -------------------------------

    function renderPageFromDoc() {
        const d = state.doc;
        const typeLabel = DOC_TYPE === 'po' ? 'zamówienie' : 'zapytanie';

        // Tytuł + subtitle (typ + numer + stan). Spec: type=rfq + state=sent
        // → "Oznacz odpowiedź" zamiast "Wyślij dokument".
        const isRespondMode = (DOC_TYPE === 'rfq' && d.state === 'sent');
        if (isRespondMode) {
            $title.text('Oznacz odpowiedź');
        } else {
            $title.text('Wyślij dokument');
        }
        $subtitle.text(typeLabel + ' ' + (d.number || ('#' + d.id)));

        // Tytuły commit buttona.
        $commitBtnLabel.text(d.actionLabel || ('Wyślij ' + typeLabel));

        // Link "Wróć do dokumentu" — ustawiony raz z data-attr.
        $backToDoc.attr('href', BACK_URL);

        // sendable=false → tryb read-only z bannerem. Podsumowanie i tak
        // renderujemy, żeby operator widział, CO próbuje wysłać i dlaczego
        // nie wolno (np. PO w stanie `sent`).
        if (!d.sendable) {
            showReadOnlyBanner();
        }

        renderStep1();
        renderStep2();
        renderStep3();
        renderStep4();
    }

    function showReadOnlyBanner() {
        const d = state.doc;
        const stateLbl = d.stateLabel || d.state;
        const reason = (DOC_TYPE === 'po' && d.state === 'sent')
            ? 'Zamówienie zostało już wysłane i oczekuje na potwierdzenie. '
              + 'Aby wysłać ponownie, cofnij stan do szkicu.'
            : 'Dokument jest w stanie „' + escapeHtml(stateLbl) + '”, '
              + 'który nie pozwala na wysyłkę.';
        $errorBanner
            .removeClass('alert-danger')
            .addClass('alert-warning')
            .html(
                '<i class="bi bi-lock-fill"></i> <strong>Wysyłka zablokowana.</strong> '
                + escapeHtml(reason)
                + ' Kreator działa w trybie podglądu.'
            )
            .show();
        // Podmień banner error→warning po ponownym wywołaniu.
    }

    // ---- Step 1: Podsumowanie --------------------------------------

    function renderStep1() {
        const d = state.doc;
        const typeLabel = DOC_TYPE === 'po' ? 'Zamówienie' : 'Zapytanie';

        // Per-type expected-date label.
        let expectedLabel, expectedValue;
        if (DOC_TYPE === 'rfq') {
            expectedLabel = 'Oczekiwana odpowiedź:';
            expectedValue = d.expectedReplyDate || '—';
        } else {
            expectedLabel = 'Planowana dostawa:';
            expectedValue = d.expectedDeliveryDate || '—';
        }

        // Nagłówek w 2-kolumnowej tabeli (dt/dd).
        const respondedRowHtml = (DOC_TYPE === 'rfq' && d.respondedAt)
            ? '<dt class="col-sm-2">Odpowiedź:</dt>'
              + '<dd class="col-sm-4">' + escapeHtml(d.respondedAt) + '</dd>'
            : (DOC_TYPE === 'rfq'
                ? '<dt class="col-sm-2">&nbsp;</dt><dd class="col-sm-4">&nbsp;</dd>'
                : (d.vendorPoNumber
                    ? '<dt class="col-sm-2">Numer u dostawcy:</dt>'
                      + '<dd class="col-sm-4">' + escapeHtml(d.vendorPoNumber) + '</dd>'
                    : '<dt class="col-sm-2">&nbsp;</dt><dd class="col-sm-4">&nbsp;</dd>'));

        const headerHtml =
            '<dl class="row mb-3">'
            + '<dt class="col-sm-2">Numer:</dt>'
            + '<dd class="col-sm-4"><strong>' + escapeHtml(d.number || ('#' + d.id)) + '</strong></dd>'
            + '<dt class="col-sm-2">Stan:</dt>'
            + '<dd class="col-sm-4">'
                + '<span class="badge badge-' + stateBadgeClass(d.state) + '">'
                    + escapeHtml(d.stateLabel || d.state)
                + '</span>'
            + '</dd>'

            + '<dt class="col-sm-2">Dostawca:</dt>'
            + '<dd class="col-sm-4">' + escapeHtml(d.vendorName || '—') + '</dd>'
            + '<dt class="col-sm-2">Wysłane:</dt>'
            + '<dd class="col-sm-4">' + escapeHtml(d.sentAt || '—') + '</dd>'

            + '<dt class="col-sm-2">' + escapeHtml(expectedLabel) + '</dt>'
            + '<dd class="col-sm-4">' + escapeHtml(expectedValue) + '</dd>'
            + respondedRowHtml

            + '<dt class="col-sm-2">Utworzone:</dt>'
            + '<dd class="col-sm-4">' + escapeHtml(d.createdAt || '—') + '</dd>'
            + '<dt class="col-sm-2">&nbsp;</dt>'
            + '<dd class="col-sm-4">&nbsp;</dd>'
            + '</dl>';

        // Tabela pozycji (cart-style: nazwa, nr dostawcy/producenta jako
        // muted subline, ilość, JM, cena, wartość, waluta).
        const linesHtml = renderLinesTable(d.lines || []);

        // Breakdown wartości per waluta (mirror documents-view.js
        // renderValue, sekcja Step 1 mówi "Total value: sum grouped per
        // currency (mirroring the documents-view.js renderValue()
        // pattern at lines 230-240)").
        const totalHtml = renderValueBreakdown(d.valueBreakdown || []);

        // Resend-warning: widoczne tylko gdy sent_at jest świeży.
        const resendAcked = state.resendWarnAck;
        const resendHtml = needsResendAck()
            ? '<div class="send-resend-warning">'
                + '<div class="form-check">'
                    + '<input class="form-check-input" type="checkbox" id="send-resend-ack" '
                        + (resendAcked ? 'checked' : '') + '>'
                    + '<label class="form-check-label" for="send-resend-ack">'
                        + '<strong>Uwaga:</strong> dokument został wysłany mniej niż minutę temu. '
                        + 'Kontynuacja nadpisze znacznik <code>sent_at</code> aktualną datą. '
                        + 'Zaznacz, aby potwierdzić.'
                    + '</label>'
                + '</div>'
              + '</div>'
            : '';

        // Backdate toggle — ukryty input datetime-local, domyślnie
        // "teraz". Operator musi kliknąć "Zmień datę wysłania", żeby
        // cokolwiek edytować.
        const backdateHtml =
            '<div class="send-backdate-toggle text-muted small" id="send-backdate-toggle">'
                + '<i class="bi bi-calendar-event"></i> Zmień datę wysłania (zaawansowane)'
            + '</div>'
            + '<div class="send-backdate-wrap" id="send-backdate-wrap">'
                + '<label class="small mb-1" for="send-backdate-input">Data wysłania:</label>'
                + '<input type="datetime-local" class="form-control form-control-sm" '
                    + 'id="send-backdate-input" step="60">'
                + '<small class="text-muted d-block mt-1">'
                    + 'Domyślnie „teraz”. Pozostaw puste, żeby użyć bieżącego czasu.'
                + '</small>'
            + '</div>';

        $('#send-step-1-body').html(
            headerHtml
            + '<h6 class="mt-2">Pozycje</h6>'
            + linesHtml
            + '<h6 class="mt-3">Łączna wartość</h6>'
            + totalHtml
            + resendHtml
            + backdateHtml
        );

        // Wire resend ack checkbox.
        $('#send-resend-ack').on('change', function () {
            state.resendWarnAck = $(this).is(':checked');
            refreshStep1NextEnabled();
        });

        // Wire backdate toggle + input.
        $('#send-backdate-toggle').on('click', function () {
            $('#send-backdate-wrap').addClass('is-shown');
            // Przy pierwszym otwarciu ustaw "teraz" w formacie
            // datetime-local ('YYYY-MM-DDTHH:MM'); pole jest
            // nieaktywne do momentu edycji.
            const $inp = $('#send-backdate-input');
            if (!$inp.val()) {
                const now = new Date();
                const pad = (n) => String(n).padStart(2, '0');
                $inp.val(
                    now.getFullYear() + '-' + pad(now.getMonth() + 1) + '-' + pad(now.getDate())
                    + 'T' + pad(now.getHours()) + ':' + pad(now.getMinutes())
                );
            }
            $(this).hide();
        });
        $('#send-backdate-input').on('change input', function () {
            const v = $(this).val();
            if (!v) { state.backdatedAt = null; return; }
            // datetime-local → 'Y-m-d H:i:s' (handler oczekuje tego formatu).
            const norm = v.replace('T', ' ');
            state.backdatedAt = (norm.length === 16) ? norm + ':00' : norm;
        });

        refreshStep1NextEnabled();
    }

    function stateBadgeClass(state) {
        // Mirror documents-edit.php maps (linie 117-130) — kolory dla
        // BS4 badge-utility. Zduplikowane tutaj, bo wizard nie ma
        // dostępu do tych map (znajdują się w documents-edit.php i nie
        // są współdzielone).
        const m = {
            'draft':              'secondary',
            'sent':               'primary',
            'responded':          'info',
            'confirmed':          'info',
            'partially_received': 'warning',
            'received':           'success',
            'cancelled':          'danger',
            'converted':          'success',
        };
        return m[state] || 'secondary';
    }

    function renderLinesTable(lines) {
        if (!lines || lines.length === 0) {
            return '<p class="text-muted">Brak pozycji.</p>';
        }
        let rows = '';
        lines.forEach(function (ln) {
            const vp = ln.vendorPartNo || '';
            const pn = ln.producerPartNo || '';
            let subLines = '';
            if (vp && pn && vp === pn) {
                subLines = '<div class="doc-line-vp">Nr dost./prod.: <strong>'
                    + escapeHtml(vp) + '</strong></div>';
            } else {
                if (vp) {
                    subLines += '<div class="doc-line-vp">Nr dost.: <strong>'
                        + escapeHtml(vp) + '</strong></div>';
                }
                if (pn) {
                    subLines += '<div class="doc-line-vp">Nr prod.: <strong>'
                        + escapeHtml(pn) + '</strong></div>';
                }
            }
            const priceCell = ln.unitPrice === null || ln.unitPrice === undefined
                ? '<span class="text-muted">—</span>'
                : escapeHtml(formatPrice(ln.unitPrice));
            const valueCell = ln.unitPrice === null || ln.unitPrice === undefined
                ? '<span class="text-muted">—</span>'
                : escapeHtml(formatPrice(
                    (parseFloat(ln.unitPrice) || 0) * (parseFloat(ln.quantityOrdered) || 0)
                ));
            rows += '<tr>'
                + '<td>'
                    + '<div><strong>' + escapeHtml(ln.partName || '—') + '</strong></div>'
                    + subLines
                    + (ln.producerName
                        ? '<div class="doc-line-producer">prod. ' + escapeHtml(ln.producerName) + '</div>'
                        : '')
                + '</td>'
                + '<td class="text-right">' + escapeHtml(formatQty(ln.quantityOrdered))
                    + (ln.unitName
                        ? '<div class="doc-line-muted">' + escapeHtml(ln.unitName) + '</div>'
                        : '')
                + '</td>'
                + '<td class="text-right">' + priceCell + '</td>'
                + '<td>' + escapeHtml(ln.currency || '—') + '</td>'
                + '<td class="text-right doc-line-value-cell">' + valueCell + '</td>'
              + '</tr>';
        });
        return '<div class="table-responsive">'
            + '<table class="table table-sm table-striped mb-0">'
                + '<thead class="thead-light">'
                    + '<tr>'
                        + '<th>Część</th>'
                        + '<th class="text-right">Ilość</th>'
                        + '<th class="text-right">Cena</th>'
                        + '<th>Waluta</th>'
                        + '<th class="text-right">Wartość</th>'
                    + '</tr>'
                + '</thead>'
                + '<tbody>' + rows + '</tbody>'
            + '</table>'
        + '</div>';
    }

    function renderValueBreakdown(breakdown) {
        // Mirror documents-view.js renderValue() (linie 230-240) — ten
        // sam układ "<total> <currency>" per wiersz, opakowany w
        // muted <small>. Bez "—" dla pustej listy (Step 1 wyświetla
        // breakdown nawet gdy pusty, bo pokazuje też stan wierszy).
        if (!breakdown || breakdown.length === 0) {
            return '<p class="text-muted mb-0">Brak wycenionych pozycji.</p>';
        }
        const lines = breakdown.map(function (b) {
            return '<div><small class="text-muted">'
                + escapeHtml(formatPrice(b.total)) + ' ' + escapeHtml(b.currency)
                + '</small></div>';
        });
        return '<div>' + lines.join('') + '</div>';
    }

    // ---- Step 2: Kontakty ------------------------------------------

    function buildContactCardHtml(s) {
        // Wiersz checkboxa + badge ⭐ jeśli isPrimary. SQL sortuje główne
        // na górze (VendorSupplierRepository::listByVendor → ORDER BY
        // is_primary DESC, name ASC); używane zarówno do renderu
        // początkowej listy, jak i do append po sukcesie inline-create.
        const checked = state.selectedSuppliers.indexOf(s.id) !== -1 ? 'checked' : '';
        const primaryBadge = s.isPrimary
            ? '<span class="contact-primary-badge">⭐ główny kontakt</span>'
            : '';
        const job = s.jobTitle
            ? '<span class="contact-card-job">' + escapeHtml(s.jobTitle) + '</span>'
            : '';
        const meta = [];
        if (s.email) {
            meta.push(
                '<span class="contact-meta-item">'
                + '<i class="bi bi-envelope"></i>'
                + '<span class="contact-meta-text">' + escapeHtml(s.email) + '</span>'
                + '<button type="button" class="send-copy-btn" data-copy="' + escapeHtml(s.email) + '" '
                    + 'title="Skopiuj email"><i class="bi bi-clipboard"></i></button>'
                + '</span>'
            );
        }
        if (s.phone) {
            meta.push(
                '<span class="contact-meta-item">'
                + '<i class="bi bi-telephone"></i>'
                + '<span class="contact-meta-text">' + escapeHtml(s.phone) + '</span>'
                + '<button type="button" class="send-copy-btn" data-copy="' + escapeHtml(s.phone) + '" '
                    + 'title="Skopiuj telefon"><i class="bi bi-clipboard"></i></button>'
                + '</span>'
            );
        }
        const comment = s.comment
            ? '<div class="contact-card-comment-row">'
                + '<i class="bi bi-chat-left-text contact-card-comment-icon" aria-hidden="true"></i>'
                + '<span class="contact-card-comment-text">' + escapeHtml(s.comment) + '</span>'
              + '</div>'
            : '';
        const editBtn =
            '<button type="button" class="send-contact-edit-btn" data-supplier-id="' + s.id + '" '
                + 'title="Edytuj kontakt">'
                + '<i class="bi bi-pencil"></i>'
            + '</button>';
        const editForm =
            '<div class="send-contact-edit-form-wrap" style="display:none;">'
                + '<textarea class="form-control form-control-sm send-contact-edit-comment" rows="2" '
                    + 'placeholder="Komentarz…">' + escapeHtml(s.comment || '') + '</textarea>'
                + '<div class="d-flex justify-content-end mt-1">'
                    + '<button type="button" class="btn btn-outline-secondary btn-sm mr-2 send-contact-edit-cancel">Anuluj</button>'
                    + '<button type="button" class="btn btn-primary btn-sm send-contact-edit-save" '
                        + 'data-supplier-id="' + s.id + '">Zapisz</button>'
                + '</div>'
            + '</div>';
        return '<label class="contact-card" data-supplier-id="' + s.id + '">'
            + '<input type="checkbox" class="contact-card-check send-supplier-check" '
                + 'value="' + s.id + '" ' + checked + '>'
            + '<span class="contact-card-body">'
                + '<span class="contact-card-headline">'
                    + primaryBadge
                    + '<span class="contact-card-name">' + escapeHtml(s.name) + '</span>'
                    + job
                + '</span>'
                + (meta.length > 0
                    ? '<div class="contact-card-meta">' + meta.join('') + '</div>'
                    : '')
                + comment
            + '</span>'
            // Formularz edycji jest renderowany OBCOKARTY (po </label>),
            // żeby klik w buttony Anuluj/Zapisz nie togglował checkboxa
            // przez label-input semantics.
        + '</label>'
        + editForm;
    }

    function renderStep2() {
        const d = state.doc;
        const suppliers = d.suppliers || [];
        const vendorId = parseInt(d.vendorId, 10) || 0;
        // Empty state (vendor has no active contacts) — pokaż krótki alert
        // oraz formularz inline OTWARTY domyślnie (operator i tak musi
        // dodać kogoś, więc nie zmuszajmy do klikania toggle).
        const emptyStateHtml = suppliers.length === 0
            ? '<div class="alert alert-info mb-3">'
                + 'Ten dostawca nie ma jeszcze przypisanych osób kontaktowych. '
                + 'Dodaj kontakt przed wysyłką.'
            + '</div>'
            : '';

        // Filter input (case-insensitive substring na name/email/jobTitle).
        const filterHtml =
            '<div class="form-row mb-3">'
                + '<div class="col-md-8">'
                    + '<input type="text" class="form-control form-control-sm" '
                        + 'id="send-supplier-filter" '
                        + 'placeholder="Filtruj po imieniu, emailu, stanowisku…">'
                + '</div>'
                + '<div class="col-md-4 text-right">'
                    + '<button type="button" class="btn btn-outline-secondary btn-sm" id="send-supplier-all">'
                        + 'Zaznacz wszystkie widoczne'
                    + '</button>'
                + '</div>'
            + '</div>';

        // Kontakty jako lista kart. SQL sortuje is_primary DESC, name ASC.
        const cardsHtml = suppliers.map(buildContactCardHtml).join('');

        // Toggle do inline-formularza. Formularz renderowany zawsze,
        // domyślnie ukryty — operator klika „Dodaj kontakt" nawet
        // przy pustej liście (zamiast być zmuszany wpisywać od razu).
        const listOrEmpty = suppliers.length === 0 ? '' : cardsHtml;
        const formOpenAttr = '';

        const addToggleHtml =
            '<div class="send-add-contact-bar">'
                + '<button type="button" class="btn btn-outline-primary btn-sm" id="send-add-contact-toggle">'
                    + '<i class="bi bi-person-plus"></i> Dodaj kontakt'
                + '</button>'
            + '</div>';

        const addFormHtml =
            '<form id="send-add-contact-form" class="send-add-contact-form' + formOpenAttr + '" '
                + 'data-vendor-id="' + vendorId + '">'
                + '<div class="form-row">'
                    + '<div class="form-group col-md-6 mb-2">'
                        + '<label for="send-contact-name">Imię i nazwisko <span class="text-danger">*</span></label>'
                        + '<input type="text" id="send-contact-name" name="name" class="form-control form-control-sm" required maxlength="128">'
                    + '</div>'
                    + '<div class="form-group col-md-6 mb-2">'
                        + '<label for="send-contact-job">Stanowisko</label>'
                        + '<input type="text" id="send-contact-job" name="job_title" class="form-control form-control-sm" maxlength="128">'
                    + '</div>'
                + '</div>'
                + '<div class="form-row">'
                    + '<div class="form-group col-md-6 mb-2">'
                        + '<label for="send-contact-email">Email</label>'
                        + '<input type="email" id="send-contact-email" name="email" class="form-control form-control-sm" maxlength="128">'
                    + '</div>'
                    + '<div class="form-group col-md-6 mb-2">'
                        + '<label for="send-contact-phone">Telefon</label>'
                        + '<input type="text" id="send-contact-phone" name="phone" class="form-control form-control-sm" maxlength="64">'
                    + '</div>'
                + '</div>'
                + '<div class="form-group mb-2">'
                    + '<label for="send-contact-comment">Komentarz</label>'
                    + '<textarea id="send-contact-comment" name="comment" class="form-control form-control-sm" rows="2" maxlength="512"></textarea>'
                + '</div>'
                + '<div class="d-flex justify-content-end align-items-center">'
                    + '<div id="send-add-contact-error" class="text-danger small mr-auto" style="display: none;"></div>'
                    + '<button type="button" class="btn btn-outline-secondary btn-sm mr-2" id="send-add-contact-cancel">Anuluj</button>'
                    + '<button type="submit" class="btn btn-primary btn-sm" id="send-add-contact-submit">'
                        + '<i class="bi bi-check2"></i> Zapisz kontakt'
                    + '</button>'
                + '</div>'
            + '</form>';

        $('#send-step-2-body').html(
            emptyStateHtml
            + '<p class="text-muted small mb-2">'
                + 'Zaznacz osoby, do których ma zostać wysłany dokument. '
                + 'Główny kontakt (⭐) jest wyróżniony.'
            + '</p>'
            + filterHtml
            + '<div id="send-supplier-list">' + listOrEmpty + '</div>'
            + addToggleHtml
            + addFormHtml
        );

        // Wire interakcji.
        $('#send-supplier-filter').on('input', function () {
            state.supplierFilter = $(this).val() || '';
            applySupplierFilter();
        });
        $('#send-supplier-all').on('click', function () {
            // Zaznacz wszystkie aktualnie widoczne (nie-filtrowane-out).
            const visibleIds = $('#send-supplier-list .contact-card')
                .not('.is-filtered-out')
                .map(function () { return parseInt($(this).attr('data-supplier-id'), 10); })
                .get();
            const set = new Set(state.selectedSuppliers.concat(visibleIds));
            state.selectedSuppliers = Array.from(set);
            // Odśwież checkboxy + UI.
            $('#send-supplier-list .contact-card').each(function () {
                const id = parseInt($(this).attr('data-supplier-id'), 10);
                $(this).find('.send-supplier-check').prop('checked',
                    state.selectedSuppliers.indexOf(id) !== -1);
                $(this).toggleClass('is-selected',
                    state.selectedSuppliers.indexOf(id) !== -1);
            });
            refreshStep2NextEnabled();
        });
        $('#send-supplier-list').on('change', '.send-supplier-check', function () {
            const $card = $(this).closest('.contact-card');
            const id = parseInt($card.attr('data-supplier-id'), 10);
            const set = new Set(state.selectedSuppliers);
            if ($(this).is(':checked')) set.add(id);
            else set.delete(id);
            state.selectedSuppliers = Array.from(set);
            $card.toggleClass('is-selected', $(this).is(':checked'));
            refreshStep2NextEnabled();
        });
        // Klik w kartę (nie w sam checkbox): nie powielamy natywnej
        // semantyki label-input (label sam toggluje checkbox). Jedyny
        // wyjątek: klik w buttony wewnątrz karty (copy / edit pencil) —
        // te mają własne handlery i stopPropagation, żeby label-input
        // ich nie łapał.

        // ---- Copy-to-clipboard (per pole email/telefon) -----------
        $('#send-supplier-list').on('click', '.send-copy-btn', function (e) {
            e.preventDefault();
            e.stopPropagation();
            const $btn  = $(this);
            const text  = ($btn.attr('data-copy') || '').toString();
            if (!text) return;
            copyToClipboard(text);
            flashCopyButton($btn);
            flashCopyToast(text);
        });

        // ---- Inline edycja kontaktu (ołówek) ----------------------
        // Otwiera mały textarea pod kartą (poza <label>, więc klik w
        // buttony nie toggluje checkboxa). Save POST-uje do
        // vendor-supplier-update-ajax.php; po sukcesie podmienia tekst
        // komentarza w DOM i aktualizuje state.doc.suppliers.
        $('#send-supplier-list').on('click', '.send-contact-edit-btn', function (e) {
            e.preventDefault();
            e.stopPropagation();
            const $card   = $(this).closest('.contact-card');
            const $form   = $card.next('.send-contact-edit-form-wrap');
            $form.show().find('.send-contact-edit-comment').trigger('focus');
        });
        $('#send-supplier-list').on('click', '.send-contact-edit-cancel', function (e) {
            e.preventDefault();
            e.stopPropagation();
            const $card = $(this).closest('.send-contact-edit-form-wrap')
                .prev('.contact-card');
            // Przywróć oryginalny komentarz (anulowanie) — czyścimy edytowane wartości.
            const id   = parseInt($card.attr('data-supplier-id'), 10) || 0;
            const sup  = (state.doc && state.doc.suppliers || []).find(function (x) { return x.id === id; });
            $(this).closest('.send-contact-edit-form-wrap')
                .find('.send-contact-edit-comment').val(sup ? (sup.comment || '') : '');
            $(this).closest('.send-contact-edit-form-wrap').hide();
        });
        $('#send-supplier-list').on('click', '.send-contact-edit-save', function (e) {
            e.preventDefault();
            e.stopPropagation();
            const $btn    = $(this);
            const $form   = $btn.closest('.send-contact-edit-form-wrap');
            const id      = parseInt($btn.attr('data-supplier-id'), 10) || 0;
            const comment = ($form.find('.send-contact-edit-comment').val() || '').toString();
            if (id <= 0) return;
            $btn.prop('disabled', true);
            $.ajax({
                url: SUPPLIER_UPDATE_URL,
                method: 'POST',
                data: { id: id, comment: comment },
                dataType: 'json'
            })
            .done(function (resp) {
                if (!resp || !resp.success) {
                    alert((resp && resp.error) || 'Błąd zapisu zmian.');
                    $btn.prop('disabled', false);
                    return;
                }
                // Aktualizuj model + DOM.
                const s = resp.supplier;
                if (state.doc && Array.isArray(state.doc.suppliers)) {
                    const i = state.doc.suppliers.findIndex(function (x) { return x.id === s.id; });
                    if (i !== -1) state.doc.suppliers[i] = s;
                }
                const $card = $form.prev('.contact-card');
                const $row = $card.find('.contact-card-comment-row');
                const $icon = $card.find('.contact-card-comment-icon');
                if (s.comment) {
                    if ($row.length === 0) {
                        // Wstaw nowy wiersz komentarza (handluje przypadek „dodaj nowy komentarz").
                        $card.find('.contact-card-body').append(
                            '<div class="contact-card-comment-row">'
                            + '<i class="bi bi-chat-left-text contact-card-comment-icon" aria-hidden="true"></i>'
                            + '<span class="contact-card-comment-text">' + escapeHtml(s.comment) + '</span>'
                            + '</div>'
                        );
                    } else {
                        $row.find('.contact-card-comment-text').text(s.comment);
                    }
                } else {
                    $row.remove();
                }
                $form.hide();
                $btn.prop('disabled', false);
                flashCopyToast('Zapisano');
            })
            .fail(function (xhr, status) {
                alert((xhr && xhr.responseJSON && xhr.responseJSON.error) || status || 'Błąd komunikacji.');
                $btn.prop('disabled', false);
            });
        });

        // ---- Inline dodawanie kontaktu ----------------------------
        // Toggle otwiera/zamyka formularz; anuluj chowa bez zapisu;
        // submit POST-uje do vendor-supplier-create-ajax.php, po
        // sukcesie dopisuje kartę do listy i zaznacza checkbox.
        const $addForm    = $('#send-add-contact-form');
        const $addError   = $('#send-add-contact-error');
        const clearAddForm = function () {
            $addForm.find('input[type="text"], input[type="email"], textarea').val('');
            $addForm.removeClass('is-open');
            $addError.hide().text('');
        };
        $('#send-add-contact-toggle').on('click', function () {
            $addForm.toggleClass('is-open');
            if ($addForm.hasClass('is-open')) {
                $('#send-contact-name').trigger('focus');
            }
        });
        $('#send-add-contact-cancel').on('click', function () {
            clearAddForm();
        });
        $addForm.on('submit', function (e) {
            e.preventDefault();
            e.stopPropagation();
            $addError.hide().text('');
            const vendorId = parseInt($addForm.attr('data-vendor-id'), 10) || 0;
            if (vendorId <= 0) {
                $addError.text('Brak identyfikatora dostawcy.').show();
                return;
            }
            const name = ($('#send-contact-name').val() || '').toString().trim();
            if (name === '') {
                $addError.text('Imię i nazwisko jest wymagane.').show();
                $('#send-contact-name').trigger('focus');
                return;
            }
            const $submit = $('#send-add-contact-submit').prop('disabled', true);
            $.ajax({
                url: SUPPLIER_CREATE_URL,
                method: 'POST',
                data: {
                    vendor_id: vendorId,
                    name:      name,
                    job_title: $('#send-contact-job').val()    || '',
                    email:     $('#send-contact-email').val()  || '',
                    phone:     $('#send-contact-phone').val()  || '',
                    comment:   $('#send-contact-comment').val()|| ''
                },
                dataType: 'json'
            })
            .done(function (resp) {
                if (!resp || !resp.success) {
                    $addError.text((resp && resp.error) || 'Błąd zapisu kontaktu.').show();
                    $submit.prop('disabled', false);
                    return;
                }
                // Dopisz do modelu + do DOM. Nowy kontakt nigdy nie jest
                // główny (is_primary=0 z endpointu), więc trafia na koniec
                // listy (serwer sortuje główne wyżej, reszta po nazwie —
                // dla jednego nowego dopisku to wystarczające).
                const s = resp.supplier;
                if (state.doc && Array.isArray(state.doc.suppliers)) {
                    state.doc.suppliers.push(s);
                }
                $('#send-supplier-list').append(buildContactCardHtml(s));

                // Nowy kontakt: domyślnie zaznaczony do wysyłki.
                state.selectedSuppliers.push(s.id);
                const $newCard = $('#send-supplier-list .contact-card[data-supplier-id="' + s.id + '"]');
                $newCard.addClass('is-selected')
                    .find('.send-supplier-check').prop('checked', true);

                clearAddForm();
                applySupplierFilter();
                refreshStep2NextEnabled();
                $submit.prop('disabled', false);
            })
            .fail(function (xhr, status) {
                const msg = (xhr && xhr.responseJSON && xhr.responseJSON.error)
                    || status
                    || 'Błąd komunikacji z serwerem.';
                $addError.text(msg).show();
                $submit.prop('disabled', false);
            });
        });

        // Inicjalne zaznaczenie: domyślnie pre-zaznaczony główny kontakt,
        // jeśli istnieje (pierwszy rekord, bo SQL sortuje isPrimary DESC).
        if (state.selectedSuppliers.length === 0) {
            const primary = suppliers.find(function (s) { return s.isPrimary; });
            if (primary) {
                state.selectedSuppliers = [primary.id];
                $('#send-supplier-list .contact-card[data-supplier-id="' + primary.id + '"]')
                    .addClass('is-selected')
                    .find('.send-supplier-check').prop('checked', true);
            }
        }

        applySupplierFilter();
        refreshStep2NextEnabled();
    }

    function applySupplierFilter() {
        const q = (state.supplierFilter || '').toLowerCase().trim();
        const suppliers = (state.doc && state.doc.suppliers) || [];
        $('#send-supplier-list .contact-card').each(function () {
            const id = parseInt($(this).attr('data-supplier-id'), 10);
            const s = suppliers.find(function (x) { return x.id === id; });
            if (!s) { $(this).addClass('is-filtered-out'); return; }
            const hay = ((s.name || '') + ' ' + (s.email || '') + ' ' + (s.jobTitle || ''))
                .toLowerCase();
            const hit = q === '' || hay.indexOf(q) !== -1;
            $(this).toggleClass('is-filtered-out', !hit);
        });
    }

    function refreshStep2NextEnabled() {
        const $btn = $('#send-step-2 .send-step-next');
        $btn.prop('disabled', state.selectedSuppliers.length === 0);
        $('#send-step-2-count').text(
            state.selectedSuppliers.length + ' zaznaczonych'
        );
        // Krok 4 zależy od zaznaczonych kontaktów — przeładuj jego
        // treść, żeby wybrane kontakty + ich pola pojawiły się w
        // recap natychmiast po dodaniu / zaznaczeniu.
        if (typeof renderStep4 === 'function') renderStep4();
    }

    // ---- Step 3: PDF -----------------------------------------------

    function renderStep3() {
        const d = state.doc;
        const docKind = DOC_TYPE === 'po' ? 'zamówienia' : 'zapytania';
        // Przy rfq+sent → tryb respond, PDF nie ma sensu (już wysłane).
        // UX: pokazujemy panel, ale bez możliwości wyboru — committed
        // było przy pierwszej wysyłce.
        const respondMode = (DOC_TYPE === 'rfq' && d.state === 'sent');

        const radiosHtml = respondMode
            ? '<div class="alert alert-info mb-0">'
                + 'Dokument został już wysłany. Oznaczenie odpowiedzi nie zmienia statusu PDF.'
              + '</div>'
            : '<label class="pdf-radio-card" id="send-pdf-yes-card">'
                + '<input type="radio" name="send-pdf-choice" value="1" '
                    + (state.generatePdf ? 'checked' : '') + '>'
                + '<strong>Tak</strong> — utworzony zostanie placeholder PDF. '
                + 'Prawdziwy PDF pojawi się w przyszłości.'
            + '</label>'
            + '<label class="pdf-radio-card" id="send-pdf-no-card">'
                + '<input type="radio" name="send-pdf-choice" value="0" '
                    + (!state.generatePdf ? 'checked' : '') + '>'
                + '<strong>Nie</strong> — dokument zostanie wysłany bez PDF.'
            + '</label>';

        // Preview placeholder — szary box z ikoną + meta. Generowany
        // "na teraz" (po prostu timestamp z frontu; prawdziwe dane
        // przyjdą z endpointu).
        const now = new Date();
        const pad = (n) => String(n).padStart(2, '0');
        const nowStr = now.getFullYear() + '-' + pad(now.getMonth() + 1) + '-'
            + pad(now.getDate()) + ' ' + pad(now.getHours()) + ':'
            + pad(now.getMinutes());
        const previewHtml = respondMode
            ? ''
            : '<div class="pdf-preview-placeholder">'
                + '<i class="bi bi-file-earmark-pdf"></i>'
                + '<div><strong>' + escapeHtml((d.number || ('#' + d.id)) + '_' + DOC_TYPE.toUpperCase() + '.pdf') + '</strong></div>'
                + '<div class="pdf-preview-meta">'
                    + '≈ 2 strony · wygenerowano <strong>' + escapeHtml(nowStr) + '</strong>'
                + '</div>'
              + '</div>';

        $('#send-step-3-body').html(
            '<p class="text-muted">'
                + 'Czy wygenerować PDF dla ' + docKind + '? '
                + 'Placeholder wskazuje miejsce na przyszły, prawdziwy PDF.'
            + '</p>'
            + radiosHtml
            + previewHtml
        );

        // Wire radio cards (klik w label → zaznacz radio + zaktualizuj styl).
        if (!respondMode) {
            $('input[name="send-pdf-choice"]').on('change', function () {
                state.generatePdf = $(this).val() === '1';
                refreshPdfRadioStyle();
            });
            refreshPdfRadioStyle();
        }
    }

    function refreshPdfRadioStyle() {
        $('#send-pdf-yes-card, #send-pdf-no-card').each(function () {
            const $card = $(this);
            const checked = $card.find('input[type="radio"]').is(':checked');
            $card.toggleClass('is-selected', checked);
        });
    }

    // ---- Step 4: Potwierdzenie ------------------------------------

function renderStep4() {
        const d = state.doc;
        const typeLabel = DOC_TYPE === 'po' ? 'zamówienie' : 'zapytanie';
        const isRespondMode = (DOC_TYPE === 'rfq' && d.state === 'sent');

        // Trzy wiersze recapa. W trybie respond trzeci wiersz jest
        // „Data odpowiedzi" zamiast „Data wysłania".
        const datumLabel = isRespondMode ? 'Data odpowiedzi:' : 'Data wysłania:';
        const datumValue = isRespondMode
            ? (state.backdatedAt || 'teraz')
            : (state.backdatedAt || 'teraz');

        const supplierCount = state.selectedSuppliers.length;
        const datumHelp = state.backdatedAt
            ? '<small class="text-warning">— nadpisze bieżący znacznik czasu.</small>'
            : '<small class="text-muted">— bieżący czas serwera.</small>';

        // Rozwinięcie listy kontaktów do wysyłki — każdy rekord
        // dostaje swoje wiersze z polami (email/phone/comment) i
        // przyciskami „kopiuj". Alias mailto:/tel: też obecne.
        const allSuppliers = (d.suppliers || []);
        const selectedSet  = new Set(state.selectedSuppliers);
        const selectedList = allSuppliers.filter(function (s) { return selectedSet.has(s.id); });
        const contactsExpandedHtml = (supplierCount > 0)
            ? '<div class="summary-recap-contacts">'
                + selectedList.map(function (s) {
                    const lineParts = [];
                    lineParts.push('<div class="summary-contact-row">'
                        + '<strong>' + escapeHtml(s.name) + '</strong>'
                        + (s.jobTitle ? ' <small class="text-muted">— ' + escapeHtml(s.jobTitle) + '</small>' : '')
                    + '</div>');
                    if (s.email) {
                        lineParts.push(
                            '<div class="summary-contact-line">'
                            + '<i class="bi bi-envelope"></i> '
                            + '<a href="mailto:' + escapeHtml(s.email) + '">'
                                + escapeHtml(s.email)
                            + '</a>'
                            + '<button type="button" class="send-copy-btn" data-copy="' + escapeHtml(s.email) + '" '
                                + 'title="Skopiuj email"><i class="bi bi-clipboard"></i></button>'
                            + '</div>'
                        );
                    }
                    if (s.phone) {
                        lineParts.push(
                            '<div class="summary-contact-line">'
                            + '<i class="bi bi-telephone"></i> '
                            + '<a href="tel:' + escapeHtml(s.phone) + '">'
                                + escapeHtml(s.phone)
                            + '</a>'
                            + '<button type="button" class="send-copy-btn" data-copy="' + escapeHtml(s.phone) + '" '
                                + 'title="Skopiuj telefon"><i class="bi bi-clipboard"></i></button>'
                            + '</div>'
                        );
                    }
                    if (s.comment) {
                        lineParts.push(
                            '<div class="summary-contact-line summary-contact-comment">'
                            + '<i class="bi bi-chat-left-text"></i> '
                            + '<span class="summary-contact-comment-text">'
                                + escapeHtml(s.comment)
                            + '</span>'
                            + '<button type="button" class="send-copy-btn" data-copy="' + escapeHtml(s.comment) + '" '
                                + 'title="Skopiuj komentarz"><i class="bi bi-clipboard"></i></button>'
                            + '</div>'
                        );
                    }
                    return lineParts.join('');
                }).join('')
            + '</div>'
            : '';

        const contactsCell = (supplierCount > 0)
            ? '<span class="summary-recap-value">'
                + supplierCount + ' kontakt' + (supplierCount === 1 ? '' : 'ów')
                + '</span>'
                + contactsExpandedHtml
            : '<span class="summary-recap-value">'
                + '<span class="text-danger">brak</span>'
              + '</span>';

        const recapHtml =
            '<div class="summary-recap mb-3">'
            + '<div class="summary-recap-row">'
                + '<span class="summary-recap-label">Dokument:</span>'
                + '<span class="summary-recap-value">'
                    + '<span class="summary-recap-value-large">'
                        + escapeHtml(typeLabel) + ' ' + escapeHtml(d.number || ('#' + d.id))
                    + '</span>'
                + '</span>'
            + '</div>'
            + '<div class="summary-recap-row summary-recap-row-contacts">'
                + '<span class="summary-recap-label">Wysyłane do:</span>'
                + contactsCell
            + '</div>'
            + '<div class="summary-recap-row">'
                + '<span class="summary-recap-label">' + escapeHtml(datumLabel) + '</span>'
                + '<span class="summary-recap-value">'
                    + escapeHtml(datumValue) + ' ' + datumHelp
                + '</span>'
            + '</div>'
            + (isRespondMode
                ? ''
                : '<div class="summary-recap-row">'
                    + '<span class="summary-recap-label">PDF:</span>'
                    + '<span class="summary-recap-value">'
                        + (state.generatePdf ? 'Tak — placeholder' : 'Nie')
                    + '</span>'
                  + '</div>')
            + '</div>';

        // Brak kontaktów → blokuj commit + alert z CTA do kroku 2.
        const noSuppliersHtml = (supplierCount === 0)
            ? '<div class="alert alert-danger">'
                + 'Brak kontaktów — wróć do kroku 2.'
                + '<button type="button" class="btn btn-outline-danger btn-sm ml-3" data-go="2">'
                    + '← Wróć do kroku 2'
                + '</button>'
              + '</div>'
            : '';

        // Ostrzeżenie o backdate — gdy operator wybrał datę wsteczną,
        // przypominamy, że to nadpisze bieżący znacznik czasu.
        const backdateHtml = state.backdatedAt
            ? '<div class="alert alert-warning small">'
                + '<i class="bi bi-exclamation-triangle"></i> '
                + 'Wybrana data wysłania to <strong>' + escapeHtml(state.backdatedAt)
                + '</strong>. Nadpisze bieżący znacznik czasu wysyłki.'
              + '</div>'
            : '';

        $('#send-step-4-body').html(recapHtml + backdateHtml + noSuppliersHtml);

        // Step 4: copy buttons dla każdego pola kontaktu. Delegujemy
        // pod #send-step-4-body bo markup jest właśnie wstrzyknięty.
        $('#send-step-4-body').off('click.copyStep4').on('click.copyStep4', '.send-copy-btn', function (e) {
            e.preventDefault();
            const $btn = $(this);
            const text = ($btn.attr('data-copy') || '').toString();
            if (!text) return;
            copyToClipboard(text);
            flashCopyButton($btn);
            flashCopyToast(text);
        });

        // Włącz / wyłącz commit + reakcja na „wróć do kroku 2".
        $commitBtn.prop('disabled', supplierCount === 0 || state.committing);
        $('#send-step-4-body').find('[data-go]').on('click', function () {
            const go = parseInt($(this).attr('data-go'), 10);
            if (go >= 1 && go <= 4) setStep(go);
        });
    }

    // ---- Nawigacja -------------------------------------------------

    $nextButtons.on('click', function () {
        const go = parseInt($(this).attr('data-go'), 10);
        if (go >= 1 && go <= 4) setStep(go);
    });
    $prevButtons.on('click', function () {
        const go = parseInt($(this).attr('data-go'), 10);
        if (go >= 1 && go <= 4) setStep(go);
    });

    // ---- Commit ----------------------------------------------------

    $commitBtn.on('click', function () {
        if (state.committing) return;
        const d = state.doc;
        if (!d) return;
        const isRespondMode = (DOC_TYPE === 'rfq' && d.state === 'sent');

        // Zapisujemy oryginalną zawartość przycisku (ikona + span z id)
        // tylko za pierwszym kliknięciem — żeby na error/fail móc
        // odtworzyć oryginalny markup, łącznie z `id="send-commit-btn-label"`,
        // na którym trzyma referencję $commitBtnLabel.
        if (!$commitBtn.data('orig-html')) {
            $commitBtn.data('orig-html', $commitBtn.html());
        }

        state.committing = true;
        $commitBtn.prop('disabled', true);
        $commitBtn.html('<span class="spinner-border spinner-border-sm mr-2"></span>Wysyłanie…');

        let url, payload;
        if (isRespondMode) {
            url = RESPOND_URL;
            payload = {
                id:           DOC_ID,
                responded_at: state.backdatedAt || '',
            };
        } else {
            url = SEND_URL;
            payload = {
                type:         DOC_TYPE,
                id:           DOC_ID,
                sent_at:      state.backdatedAt || '',
                generate_pdf: state.generatePdf ? 1 : 0,
            };
        }

        function restoreCommitBtn() {
            // Odtwórz oryginalny HTML (zachowuje id na <span>). Po
            // .html() oryginalna referencja $commitBtnLabel wskazuje
            // na detached node; odnajdujemy świeży węzeł na nowo, żeby
            // ewentualna ponowna próba commita działała z poprawnym
            // elementem. Inne wywołania $commitBtnLabel.text(...)
            // (jest tylko jedno w renderPageFromDoc) używają cached
            // referencji, która po restore staje się nieaktualna —
            // akceptujemy to, bo po błędzie commita użytkownik i tak
            // nie wraca do renderPageFromDoc().
            const origHtml = $commitBtn.data('orig-html');
            $commitBtn.html(origHtml || '');
            const $fresh = $commitBtn.find('#send-commit-btn-label');
            if ($fresh.length) {
                $commitBtnLabel.length = 0;
                $commitBtnLabel.push.apply($commitBtnLabel, $fresh);
            }
        }

        $.ajax({
            url: url,
            method: 'POST',
            dataType: 'json',
            data: payload
        })
        .done(function (r) {
            if (!r || !r.success) {
                state.committing = false;
                $commitBtn.prop('disabled', false);
                restoreCommitBtn();
                if (typeof setAlert === 'function') {
                    setAlert((r && r.error) || 'Błąd wysyłki.', 'danger');
                } else {
                    alert((r && r.error) || 'Błąd wysyłki.');
                }
                return;
            }
            // Sukces — pokaż alert w miejscu wizarda i przekieruj za 2 s.
            const docNumber = r.document_number || d.number || ('#' + d.id);
            const stateBadge = '<span class="badge badge-'
                + stateBadgeClass(r.state || 'sent') + '">'
                + escapeHtml(r.state_label || r.state || 'sent') + '</span>';
            $successAlert.html(
                '<i class="bi bi-check-circle-fill"></i> '
                + 'Wysłano <strong>' + escapeHtml(d.actionLabel || '') + '</strong> '
                + escapeHtml(docNumber) + '. Stan: ' + stateBadge + '.'
                + '<br><small class="text-muted">Przekierowanie do listy dokumentów za 2 s…</small>'
            );
            $root.hide();
            $successWrap.show();
            window.scrollTo({ top: 0, behavior: 'smooth' });
            setTimeout(function () {
                window.location.href = LIST_URL;
            }, 2000);
        })
        .fail(function (xhr, status) {
            state.committing = false;
            $commitBtn.prop('disabled', false);
            restoreCommitBtn();
            const msg = (xhr && xhr.responseJSON && xhr.responseJSON.error) || status || 'Błąd sieci.';
            if (typeof setAlert === 'function') {
                setAlert(msg, 'danger');
            } else {
                alert(msg);
            }
        });
    });

    // ---- Start -----------------------------------------------------
    loadDocument();
});
