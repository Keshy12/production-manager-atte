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
 * Konwencje: jQuery + Bootstrap 4. let/const wyłącznie. setAlert z
 * header.js do ogłaszania błędów sieciowych; własny alert na sukces
 * commita.
 *
 * Jedyny bootstrap-select w tym kreatorze to picker „Szablon
 * wiadomości" w kroku 4 (#send-draft-picker) — pozwala nadpisać
 * preferowany szablon dostawcy na tę jedną wysyłkę. Reszta formularzy
 * jest na tyle prosta, że nie potrzebuje pluginu.
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
        // Krok 4 — kompozytor wiadomości. `null` = "użyj wygenerowanego
        // szablonu"; string = operator edytował pole ręcznie i jego
        // wersja ma przetrwać ponowny render kroku 4 (setStep(4) oraz
        // refreshStep2NextEnabled() renderują panel od nowa).
        emailSubjectEdited: null,
        emailBodyEdited:    null,
        // Krok 4 — który widok treści jest na wierzchu. Domyślnie
        // 'preview': operator ma zobaczyć gotową wiadomość (z tabelą
        // pozycji), a nie surowy TSV w textarei. 'edit' włącza się po
        // kliknięciu „Edytuj" albo w samą treść i trzyma się do
        // kliknięcia „Gotowe" — przeżywa ponowny render kroku 4.
        composerView:       'preview',
        // Krok 4 — picker „Szablon wiadomości". Pozwala nadpisać
        // preferencję dostawcy w momencie wysyłki. Picker listuje
        // WYŁĄCZNIE szablony z katalogu — pozycji „wbudowany domyślny"
        // nie ma, bo zejście do szablonu systemowego nie jest decyzją
        // operatora, tylko awaryjnym wyjściem przy pustym katalogu.
        //   null → stan przejściowy przed initSelectedDraftFromDoc();
        //          po inicjalizacji już nie występuje
        //   0    → katalog nie ma szablonu dla tego typu dokumentu →
        //          obowiązuje wbudowany szablon systemowy
        //   > 0  → id szablonu z doc.availableEmailDrafts
        selectedDraftId:      null,
        selectedDraftName:    null,
        selectedDraftSubject: null,
        selectedDraftBody:    null,
    };

    // ---- Helpers ---------------------------------------------------

    // Aliasy do AtteItemsFormat.* — ~80 callsites escapeHtml/formatPrice
    // w tym pliku, więc trzymamy lokalne referencje zamiast przepisywać
    // każde wywołanie. Ciała obu funkcji mieszkają teraz w shared.
    const escapeHtml = AtteItemsFormat.escapeHtml;
    const formatPrice = AtteItemsFormat.formatPrice;

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
    function flashCopyButton($btn, doneIconClass) {
        // Lokalny feedback na przycisku: ikona → check, kolor → zielony,
        // delikatne „pulse" scale. Po 1.2s wraca do normy. Działa na
        // tej samej klasie .send-copy-btn w kroku 2 i kroku 4.
        //
        // `doneIconClass` jest opcjonalny — krok 2 woła bez argumentu i
        // dostaje dotychczasowe `bi-check-circle-fill`; krok 4 podaje
        // `bi bi-clipboard-check`, żeby swap ikony czytał się jako
        // „schowek → schowek z ptaszkiem" (tam icon-swap zastępuje
        // toast, więc musi być jednoznaczny).
        //
        // Oryginalną klasę ikony pamiętamy na elemencie, a nie w
        // zmiennej lokalnej: przy szybkim podwójnym kliknięciu drugie
        // wywołanie odczytałoby już klasę „done" i przycisk zostałby
        // z ptaszkiem na stałe. Poprzedni timer kasujemy z tego samego
        // powodu.
        const $icon = $btn.find('i');
        if ($btn.data('copy-orig-icon') === undefined) {
            $btn.data('copy-orig-icon', $icon.attr('class') || 'bi bi-clipboard');
        }
        const origClass = $btn.data('copy-orig-icon');
        const prevTimer = $btn.data('copy-timer');
        if (prevTimer) clearTimeout(prevTimer);
        $btn.addClass('is-copied');
        $icon.attr('class', doneIconClass || 'bi bi-check-circle-fill');
        $btn.data('copy-timer', setTimeout(function () {
            $icon.attr('class', origClass);
            $btn.removeClass('is-copied');
            $btn.removeData('copy-timer');
        }, 1200));
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
            initSelectedDraftFromDoc();
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
                + '<td class="text-right">' + escapeHtml(AtteItemsFormat.formatQty(ln.quantityOrdered))
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
                    setAlert((resp && resp.error) || 'Błąd zapisu zmian.', 'danger');
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
                setAlert((xhr && xhr.responseJSON && xhr.responseJSON.error) || status || 'Błąd komunikacji.', 'danger');
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
    //
    // Panel 4 to powierzchnia „przejrzyj i wyślij", nie ekran edycji —
    // gęstszy i spokojniejszy niż kroki 1-3. Kolejność bloków jest
    // stała i wynika z tego, jak operator faktycznie pracuje:
    //
    //   1. blokery (brak kontaktów) + ostrzeżenie o backdate
    //   2. „Wiadomość do dostawcy" — picker szablonu + gotowa treść
    //   3. „Podsumowanie zapytania" — jeden rzut oka na dokument
    //   4. „Parametry wysyłki" (kontakty, data, PDF)     — ZWINIĘTE
    //   5. „Pozycje" (tabela z kopiowaniem per wiersz)   — ZWINIĘTE
    //
    // Punkt 2 stoi najwyżej, bo jest jedyną CZYNNOŚCIĄ w tym kroku —
    // operator redaguje treść i kopiuje ją do klienta poczty. Punkty
    // 3-5 to kontrola („czy to ten dokument, te kontakty, te
    // pozycje?"), więc schodzą pod spód, a 4-5 dodatkowo do zwiniętych
    // drilldownów (step4CollapsibleCardHtml) — wcześniej 30-wierszowa
    // tabela pozycji wypychała kompozytor poza ekran.
    //
    // WAŻNE: backend nie wysyła maila. W całym repo nie ma mail(), a
    // przycisk „Wyślij" na sticky barze robi wyłącznie commit stanu
    // (draft → sent) przez document-send-ajax.php. Dlatego szablon
    // wiadomości jest w 100% po stronie klienta: operator kopiuje go
    // przyciskiem „Kopiuj treść maila" i wkleja do własnego klienta
    // poczty. Kopiowanie jest tu główną interakcją, nie ozdobą.
    //
    // Feedback po kopiowaniu: WYŁĄCZNIE swap ikony na przycisku
    // (bi-clipboard → bi-clipboard-check na 1.2 s). Bez toastu — w
    // kroku 4 kliknięć „kopiuj" jest kilkanaście, więc toast byłby
    // spamem. Krok 2 zostaje przy swoim toaście (bez zmian).

    // Bufor na wartości wieloliniowe. Nie trzymamy ich w atrybutach
    // data-copy, bo parser HTML normalizuje tam znaki nowej linii.
    const step4Clip = { items: '' };

    function step4CreatorName() {
        const n = (state.doc && state.doc.createdByName)
            ? String(state.doc.createdByName).trim()
            : '';
        return n !== '' ? n : 'Dział zakupów';
    }

    function step4ExpectedDate() {
        const d = state.doc;
        if (!d) return '';
        return (DOC_TYPE === 'po' ? d.expectedDeliveryDate : d.expectedReplyDate) || '';
    }

    function step4ExpectedLabel() {
        return DOC_TYPE === 'po' ? 'Oczekiwana dostawa' : 'Oczekiwana odpowiedź';
    }

    function step4SelectedEmails() {
        // Adresaci do wiersza „Do" w kompozytorze — najpierw kontakty
        // zaznaczone w kroku 2, a gdy żaden z nich nie ma adresu,
        // wszystkie aktywne kontakty dostawcy z mailem. Pusta tablica
        // => zamiast plakietek pokazujemy komunikat o braku adresu.
        const all = (state.doc && state.doc.suppliers) || [];
        const sel = new Set(state.selectedSuppliers);
        const hasMail = function (s) { return s.email && String(s.email).trim() !== ''; };
        const picked  = all.filter(function (s) { return sel.has(s.id) && hasMail(s); });
        const pool    = picked.length > 0 ? picked : all.filter(hasMail);
        return pool.map(function (s) { return String(s.email).trim(); });
    }

    function copyBtnHtml(opts) {
        // Jeden generator dla wszystkich ikon-kopiuj w kroku 4. Wartość
        // przekazujemy jednym z pięciu kanałów:
        //   copy       — krótki, jednoliniowy tekst w atrybucie data-copy
        //   target     — selektor inputa/textarea; czytamy .val() dopiero
        //                przy kliknięciu, więc kopiuje się AKTUALNA
        //                (edytowana) treść, nie ta z chwili renderu
        //   key        — klucz w step4Clip dla bloków wieloliniowych
        //   full       — treść wiadomości z buildComposerBodyText() (bez tematu);
        //                ten sam payload co przycisk „Kopiuj treść maila"
        //                w stopce, żeby nie było dwóch definicji „całego maila"
        //   recipients — adresy e-mail odbiorców wybranych w kroku 2,
        //                rozdzielone ", "; obsługuje też `disabledWhenEmpty`
        //                — renderuje wtedy przycisk nieaktywny zamiast
        //                bezproduktywnego kliku w pustkę
        const label = opts.label || 'Kopiuj';
        let attrs = '';
        if (opts.copy       !== undefined) attrs += ' data-copy="' + escapeHtml(opts.copy) + '"';
        if (opts.target     !== undefined) attrs += ' data-copy-target="' + escapeHtml(opts.target) + '"';
        if (opts.key        !== undefined) attrs += ' data-copy-key="' + escapeHtml(opts.key) + '"';
        if (opts.full)                     attrs += ' data-copy-full="1"';
        if (opts.recipients)               attrs += ' data-copy-recipients="1"';
        // `title` opcjonalnie dłuższy niż `label` (aria-label): tam,
        // gdzie sama nazwa akcji nie mówi, CO dokładnie wyląduje w
        // schowku. `disabledTitle` osobny, bo na wyłączonym przycisku
        // sensowniejszy jest komunikat „dlaczego nie działa" niż opis
        // akcji (której i tak nie da się wykonać).
        //
        // `disabled` przepuszczamy z renderera (np. zero odbiorców w
        // wierszu „Do") — handler delegujący .send-copy-btn pilnuje,
        // żeby wtedy nie wywoływać copyToClipboard() ani swap-u ikony.
        const dis = opts.disabled ? ' disabled aria-disabled="true"' : '';
        const titleText = opts.disabled
            ? (opts.disabledTitle || opts.title || label)
            : (opts.title || label);
        return '<button type="button"'
            + ' class="send-copy-btn' + (opts.extraClass ? ' ' + opts.extraClass : '') + '"'
            + attrs
            + dis
            + ' title="' + escapeHtml(titleText) + '"'
            + ' aria-label="' + escapeHtml(label) + '">'
            + '<i class="bi bi-clipboard" aria-hidden="true"></i>'
        + '</button>';
    }

    // ---- Szablony wiadomości (nadpisanie per dostawca) ------------
    //
    // Łańcuch fallbacku: szablon dostawcy → szablon systemowy → pusto.
    // Szablon dostawcy wybiera się z globalnego katalogu
    // /admin/purchase/email-drafts (FK list__vendor.preferred_email_draft_*
    // _id) i przyjeżdża w snapshocie jako doc.preferredEmailDraftSubject /
    // doc.preferredEmailDraftBody. null = brak wskazania / szablon
    // wyłączony → kreator wraca do szablonu systemowego PL.
    //
    // Składnia: {nazwa} — pojedyncze klamry, ściśle małe litery, bez
    // tolerancji na spacje, identyfikator [a-z][a-z0-9_]{0,31}.
    // Nieznana zmienna zostaje w tekście jako literał (operator widzi
    // literówkę w mailu zamiast cichej pustki); znana, ale pusta
    // renderuje się jako pusty string.
    //
    // Ten regex i applyTemplate() są ŚWIADOMIE zduplikowane w
    // public_html/components/Admin/Purchase/EmailDrafts/edit/email-draft-edit-view.js
    // (podgląd na żywo w edytorze szablonów). Projekt nie ma systemu
    // modułów, a oba pliki ładują się na rozłącznych stronach —
    // duplikat ~15 linii jest tańszy niż wspólny plik i dodatkowy
    // <script> w headerze. Przy zmianie składni trzeba ruszyć OBA miejsca.
    const TEMPLATE_VAR_RE = /\{([a-z][a-z0-9_]{0,31})\}/g;

    function applyTemplate(tpl, vars) {
        if (tpl === null || tpl === undefined) return '';
        return String(tpl).replace(TEMPLATE_VAR_RE, function (token, name) {
            // hasOwnProperty, NIE `name in vars` — `in` łapie też
            // prototyp Object, więc {constructor} czy {tostring}
            // (oba pasują do regexa!) podstawiłyby śmieci zamiast
            // zostać literałem.
            if (!Object.prototype.hasOwnProperty.call(vars, name)) return token;
            const v = vars[name];
            return (v === null || v === undefined) ? '' : String(v);
        });
    }

    function templateContact() {
        // {vendor_contact_*}: najpierw pierwszy kontakt zaznaczony w
        // kroku 2, potem główny (isPrimary), potem pierwszy z brzegu.
        const all = (state.doc && state.doc.suppliers) || [];
        if (all.length === 0) return null;
        const selIds = state.selectedSuppliers || [];
        for (let i = 0; i < selIds.length; i++) {
            const hit = all.find(function (s) { return s.id === selIds[i]; });
            if (hit) return hit;
        }
        return all.find(function (s) { return s.isPrimary; }) || all[0];
    }

    function buildTemplateVars(doc) {
        const d       = doc || state.doc || {};
        const lines   = d.lines || [];
        const contact = templateContact();
        const isPo    = (DOC_TYPE === 'po');
        // Pusta/nieznana wartość → pusty string, nigdy "null"/"undefined".
        const blank = function (v) { return (v === null || v === undefined) ? '' : String(v); };

        return {
            vendor_name:           blank(d.vendorName),
            vendor_contact_name:   blank(contact && contact.name),
            vendor_contact_email:  blank(contact && contact.email),
            // {vendor_address} usunięte — loader kroku 4 nie zwraca już
            // d.vendorAddress. Gdyby szablon dostawcy zawierał ten token,
            // applyTemplate zostawi go w treści jako literał.
            document_type:         isPo ? 'zamówienie' : 'zapytanie',
            document_number:       blank(d.number || (d.id ? '#' + d.id : '')),
            document_comment:      blank(d.comment),
            expected_reply_date:   blank(d.expectedReplyDate),
            expected_delivery_date: blank(d.expectedDeliveryDate),
            created_by_name:       blank(d.createdByName),
            created_at:            blank(d.createdAt),
            item_count:            String(lines.length),
            // Pusty dokument dostaje tekst zastępczy w kolumnie
            // VendorPartNo (mirror pustego stanu tabeli pozycji w
            // buildStep4ItemsHtml); obsługa wewnątrz plainText.
            // Owijamy tabelę markerami — dzięki temu renderComposerPreview
            // lokalizuje tabelę nawet po edycji komórek (markery stoją
            // na zewnątrz, edycja wnętrza ich nie psuje).
            items: AtteItemsFormat.wrapItems(AtteItemsFormat.plainText(
                lines,
                { documentNumber: blank(d.number || (d.id ? '#' + d.id : '')) }
            )),
        };
    }

    // ---- Picker szablonu („Szablon wiadomości") --------------------
    //
    // Preferencja dostawcy (FK list__vendor.preferred_email_draft_*_id)
    // jest tylko DOMYŚLNĄ pozycją pickera. Operator może w kroku 4
    // wybrać dowolny inny szablon z katalogu — wybór żyje wyłącznie
    // w tej sesji kreatora, nic się nie zapisuje do bazy.
    //
    // Pełny łańcuch rozstrzygania: wybór z pickera → preferencja
    // dostawcy → PIERWSZY szablon z katalogu → szablon systemowy PL
    // (ten ostatni wyłącznie wtedy, gdy katalog nie ma nic dla
    // bieżącego typu dokumentu).

    /** Przepisuje wybrany szablon do state; `null` = szablon systemowy. */
    function applySelectedDraft(dr) {
        state.selectedDraftId      = dr ? dr.id      : 0;
        state.selectedDraftName    = dr ? dr.name    : null;
        state.selectedDraftSubject = dr ? dr.subject : null;
        state.selectedDraftBody    = dr ? dr.body    : null;
    }

    function initSelectedDraftFromDoc() {
        // Domyślne ustawienie pickera po załadowaniu snapshotu.
        state.selectedDraftId      = null;
        state.selectedDraftName    = null;
        state.selectedDraftSubject = null;
        state.selectedDraftBody    = null;

        const d = state.doc;
        if (!d) return;

        const drafts = d.availableEmailDrafts || [];
        // Pusty katalog to JEDYNY przypadek, w którym kreator sięga po
        // szablon systemowy. Krok 4 pokaże wtedy komunikat zamiast
        // pickera — patrz buildDraftPickerHtml().
        if (drafts.length === 0) {
            applySelectedDraft(null);
            return;
        }

        // Preferencja dostawcy — najpierw po id (pewniejsze: dwa
        // szablony mogą mieć identyczny temat i treść). Gdy loader nie
        // poda id, dopasowujemy po zawartości; porównanie jest dokładne,
        // bo oba stringi pochodzą z tego samego wiersza `email_draft`.
        const prefId = parseInt(d.preferredEmailDraftId, 10);
        let hit = (prefId > 0)
            ? drafts.find(function (dr) { return dr.id === prefId; })
            : null;

        if (!hit) {
            const prefSubject = d.preferredEmailDraftSubject;
            const prefBody    = d.preferredEmailDraftBody;
            if (prefSubject !== null && prefSubject !== undefined) {
                hit = drafts.find(function (dr) {
                    return dr.subject === prefSubject && dr.body === prefBody;
                });
            }
        }

        // Brak preferencji — albo preferencja wskazuje szablon spoza
        // katalogu (dezaktywowany, albo nie dla tego typu dokumentu) →
        // pierwsza pozycja listy. Dzięki temu picker i treść wiadomości
        // zawsze pokazują to samo.
        applySelectedDraft(hit || drafts[0]);
    }

    function selectedDraftName() {
        // Nazwa szablonu aktualnie obowiązującego — albo null, gdy
        // rządzi wbudowany systemowy (albo gdy preferencji dostawcy
        // nie udało się dopasować do katalogu).
        return (state.selectedDraftId !== null && state.selectedDraftId > 0)
            ? (state.selectedDraftName || null)
            : null;
    }

    function vendorSubjectTemplate() {
        // Wybór z pickera wygrywa nad preferencją dostawcy.
        const picked = state.selectedDraftSubject;
        if (typeof picked === 'string' && picked.trim() !== '') return picked;
        // selectedDraftId !== null znaczy „picker jest rozstrzygnięty" —
        // albo katalog jest pusty (0 → szablon systemowy), albo wybrany
        // szablon ma to pole puste. W obu przypadkach fallback na
        // preferencję dostawcy byłby kłamstwem: picker pokazywałby jedno,
        // a pole treść z czegoś innego.
        if (state.selectedDraftId !== null) return null;
        const t = state.doc && state.doc.preferredEmailDraftSubject;
        return (typeof t === 'string' && t.trim() !== '') ? t : null;
    }
    function vendorBodyTemplate() {
        const picked = state.selectedDraftBody;
        if (typeof picked === 'string' && picked.trim() !== '') return picked;
        if (state.selectedDraftId !== null) return null;
        const t = state.doc && state.doc.preferredEmailDraftBody;
        return (typeof t === 'string' && t.trim() !== '') ? t : null;
    }
    function usesVendorTemplate() {
        // True, gdy obowiązuje JAKIKOLWIEK szablon z katalogu — wybrany
        // ręcznie w pickerze albo odziedziczony z preferencji dostawcy.
        return vendorSubjectTemplate() !== null || vendorBodyTemplate() !== null;
    }

    function buildEmailSubject() {
        const tpl = vendorSubjectTemplate();
        return (tpl === null)
            ? buildEmailSubjectDefault()
            : applyTemplate(tpl, buildTemplateVars(state.doc));
    }

    function buildEmailBody() {
        const tpl = vendorBodyTemplate();
        return (tpl === null)
            ? buildEmailBodyDefault()
            : applyTemplate(tpl, buildTemplateVars(state.doc));
    }

    function buildEmailSubjectDefault() {
        const d = state.doc;
        const number = d.number || ('#' + d.id);
        return (DOC_TYPE === 'po' ? 'Zamówienie ' : 'Zapytanie ofertowe ') + number;
    }

    function buildEmailBodyDefault() {
        const d       = state.doc;
        const number  = d.number || ('#' + d.id);
        const vendor  = d.vendorName || '';
        const date    = step4ExpectedDate();
        const isPo    = (DOC_TYPE === 'po');

        // Nagłówek „Dotyczy:" to standardowa polska forma korespondencji
        // handlowej — mieści numer dokumentu i nazwę dostawcy bez
        // sztucznego wciskania ich w zdanie powitalne.
        const parts = [];
        parts.push('Dotyczy: ' + (isPo ? 'zamówienie ' : 'zapytanie ofertowe ') + number
            + (vendor ? ' — ' + vendor : ''));
        parts.push('');
        parts.push('Szanowni Państwo,');
        parts.push('');
        parts.push(isPo
            ? 'Przesyłamy zamówienie na następujące części:'
            : 'Zwracamy się z prośbą o ofertę na następujące części:');
        parts.push('');
        // Marker `<<<ATTE_ITEMS_TABLE>>>` otacza tabelę — renderComposerPreview
        // używa go jako kotwicy pozycji, niezależnej od zawartości komórek.
        parts.push(AtteItemsFormat.wrapItems(AtteItemsFormat.plainText(
            d.lines || [],
            { documentNumber: d.number || (d.id ? '#' + d.id : '') }
        )));
        parts.push('');
        if (date) {
            parts.push((isPo ? 'Oczekiwany termin dostawy: ' : 'Oczekiwany termin odpowiedzi: ')
                + date + '.');
            parts.push('');
        }
        parts.push('Z poważaniem,');
        parts.push(step4CreatorName());

        return parts.join('\n');
    }

    /**
     * Zwijana karta kroku 4.
     *
     * Krok 4 ma dwie powierzchnie pierwszego planu — „Podsumowanie…"
     * i „Wiadomość do dostawcy" — oraz dwa drilldowny („Parametry
     * wysyłki", „Pozycje"), które operator otwiera tylko wtedy, gdy
     * chce coś sprawdzić. Te drugie budujemy tym helperem: nagłówek
     * jest przyciskiem toggle, a body siedzi w Bootstrapowym .collapse.
     *
     * Nagłówek to <div> z <button> w środku, nie <button class="card-header">,
     * bo karta „Pozycje" trzyma w nagłówku własny przycisk „Kopiuj
     * pozycje" — przycisk w przycisku jest niepoprawnym HTML-em.
     * Toggle rozpycha się na całą wolną szerokość (flex: 1), więc
     * klikalny jest praktycznie cały pasek, a nie sam chevron.
     *
     * opts: { id, icon, title, meta, open, headerExtra, bodyHtml }
     */
    function step4CollapsibleCardHtml(opts) {
        const isOpen = (opts.open === true);
        return '<div class="card send-review-card send-review-card-collapsible mb-3">'
            + '<div class="card-header send-review-card-header send-review-card-headrow'
                + (isOpen ? ' is-open' : '') + '">'
                + '<button type="button" class="send-review-card-toggle" '
                    + 'data-toggle="collapse" data-target="#' + opts.id + '" '
                    + 'aria-expanded="' + (isOpen ? 'true' : 'false') + '" '
                    + 'aria-controls="' + opts.id + '">'
                    + '<span class="send-review-card-title">'
                        + '<i class="bi ' + opts.icon + '" aria-hidden="true"></i> '
                        + escapeHtml(opts.title)
                        + (opts.meta || '')
                    + '</span>'
                    + '<i class="bi ' + (isOpen ? 'bi-chevron-up' : 'bi-chevron-down')
                        + ' send-review-chevron" aria-hidden="true"></i>'
                + '</button>'
                + (opts.headerExtra
                    ? '<span class="send-review-card-tools">' + opts.headerExtra + '</span>'
                    : '')
            + '</div>'
            + '<div class="collapse' + (isOpen ? ' show' : '') + '" id="' + opts.id + '">'
                + opts.bodyHtml
            + '</div>'
        + '</div>';
    }

    /**
     * Podmienia chevrony zwijanych kart kroku 4 na zgodne ze stanem.
     * Bootstrap 4 nie robi tego sam — bez tego nagłówek pokazuje
     * „w dół" także przy rozwiniętej karcie i przestaje cokolwiek
     * znaczyć. Ten sam wzorzec co vrWireChevron() w vendor-edit-view.js.
     *
     * Delegacja nie wchodzi w grę: shown/hidden.bs.collapse nie bąbelkują
     * przez .collapse w sposób, na którym można oprzeć jeden handler na
     * kontenerze, więc wiążemy je per karta po każdym renderze.
     */
    function wireStep4Chevrons($scope) {
        $scope.find('.collapse').each(function () {
            const $collapse = $(this);
            const $toggle   = $scope.find('[data-target="#' + this.id + '"]');
            const $icon     = $toggle.find('.send-review-chevron');
            if ($icon.length === 0) return;
            const $headrow  = $toggle.closest('.send-review-card-headrow');
            $collapse.off('shown.bs.collapse.step4 hidden.bs.collapse.step4')
                .on('shown.bs.collapse.step4', function (e) {
                    if (e.target !== this) return;
                    $icon.removeClass('bi-chevron-down').addClass('bi-chevron-up');
                    $toggle.attr('aria-expanded', 'true');
                    $headrow.addClass('is-open');
                })
                .on('hidden.bs.collapse.step4', function (e) {
                    if (e.target !== this) return;
                    $icon.removeClass('bi-chevron-up').addClass('bi-chevron-down');
                    $toggle.attr('aria-expanded', 'false');
                    $headrow.removeClass('is-open');
                });
        });
    }

    function buildStep4SummaryHtml() {
        const d          = state.doc;
        const number     = d.number || ('#' + d.id);
        const vendorName = d.vendorName || '—';
        const lines      = d.lines || [];
        const breakdown  = d.valueBreakdown || [];

        const valueHtml = breakdown.length === 0
            ? '<span class="text-muted">brak wyceny</span>'
            : breakdown.map(function (b) {
                return '<span class="send-review-money">'
                    + escapeHtml(formatPrice(b.total))
                    + ' <span class="send-review-money-cur">' + escapeHtml(b.currency) + '</span>'
                + '</span>';
              }).join('');

        const facts = [
            { label: 'Dostawca',
              value: '<span class="send-review-strong">' + escapeHtml(vendorName) + '</span>'
                   + copyBtnHtml({ copy: vendorName, label: 'Kopiuj nazwę dostawcy' }) },
            { label: (DOC_TYPE === 'po' ? 'Numer zamówienia' : 'Numer zapytania'),
              value: '<span class="send-review-strong">' + escapeHtml(number) + '</span>'
                   + copyBtnHtml({ copy: number, label: 'Kopiuj numer dokumentu' }) },
            { label: step4ExpectedLabel(),
              value: step4ExpectedDate()
                  ? escapeHtml(step4ExpectedDate())
                  : '<span class="text-muted">nie podano</span>' },
            { label: 'Pozycji',
              value: '<span class="send-review-strong">' + lines.length + '</span>' },
            { label: 'Wartość', value: valueHtml },
            { label: 'Utworzył', value: escapeHtml(d.createdByName || '—') },
            { label: 'Utworzono', value: escapeHtml(d.createdAt || '—') }
        ];

        const factsHtml = facts.map(function (f) {
            return '<div class="send-review-fact">'
                + '<dt class="send-review-fact-label">' + escapeHtml(f.label) + '</dt>'
                + '<dd class="send-review-fact-value">' + f.value + '</dd>'
            + '</div>';
        }).join('');

        const noteHtml = d.comment
            ? '<div class="send-review-note">'
                + '<i class="bi bi-chat-left-text" aria-hidden="true"></i>'
                + '<span>' + escapeHtml(d.comment) + '</span>'
              + '</div>'
            : '';

        return '<div class="card send-review-card mb-3">'
            + '<div class="card-header send-review-card-header">'
                + '<i class="bi bi-clipboard-data" aria-hidden="true"></i> '
                + (DOC_TYPE === 'po' ? 'Podsumowanie zamówienia' : 'Podsumowanie zapytania')
            + '</div>'
            + '<div class="card-body send-review-card-body">'
                + '<dl class="send-review-grid mb-0">' + factsHtml + '</dl>'
                + noteHtml
            + '</div>'
        + '</div>';
    }

    function buildStep4ItemsHtml() {
        const lines = (state.doc && state.doc.lines) || [];
        const count = lines.length;

        if (count === 0) {
            return step4CollapsibleCardHtml({
                id:    'send-step-4-items-body',
                icon:  'bi-list-ul',
                title: 'Pozycje',
                meta:  ' <span class="send-review-count">0</span>',
                open:  false,
                bodyHtml: '<div class="card-body send-review-card-body">'
                    + '<p class="text-muted mb-0">Dokument nie ma pozycji.</p>'
                + '</div>'
            });
        }

        const rows = lines.map(function (ln) {
            const prodNo    = ln.producerPartNo   || '';
            const vendNo    = ln.vendorPartNo     || '';
            const comment   = ln.comment          || '';
            const vpComment = ln.vendorPartComment || '';

            // Nr części producenta, a pod nim (gdy inny) nr u dostawcy.
            // Każdy z własnym „kopiuj" — to numery, które operator
            // najczęściej wkleja do maila i do sklepu dostawcy, więc
            // przycisk stoi TUŻ przy wartości, nie na końcu wiersza.
            let noCell = '';
            if (prodNo) {
                noCell += '<span class="send-items-copyrow">'
                    + '<code class="send-items-no-val">' + escapeHtml(prodNo) + '</code>'
                    + copyBtnHtml({ copy: prodNo, label: 'Kopiuj numer części producenta' })
                + '</span>';
            }
            if (vendNo && vendNo !== prodNo) {
                noCell += '<span class="send-items-copyrow send-items-secondary">'
                    + '<span class="send-items-tag">dost.</span>'
                    + '<code class="send-items-no-val">' + escapeHtml(vendNo) + '</code>'
                    + copyBtnHtml({ copy: vendNo, label: 'Kopiuj numer części u dostawcy' })
                + '</span>';
            }
            if (noCell === '') noCell = '<span class="text-muted">—</span>';

            // Kolumna „Komentarz" to WYŁĄCZNIE komentarz pozycji
            // dokumentu. Komentarz z kartoteki części (vendorPartComment)
            // opisuje samą część, a nie tę pozycję, więc renderuje się
            // niżej — w kolumnie „Część", pod opisem.
            let commentCell = '';
            if (comment) {
                commentCell += '<span class="send-items-copyrow">'
                    + '<span class="send-items-comment-text">' + escapeHtml(comment) + '</span>'
                    + copyBtnHtml({ copy: comment, label: 'Kopiuj komentarz pozycji' })
                + '</span>';
            }
            if (commentCell === '') commentCell = '<span class="text-muted">—</span>';

            return '<tr>'
                + '<td class="send-items-part">'
                    + '<span class="send-items-part-name">' + escapeHtml(ln.partName || '—') + '</span>'
                    + (ln.partDescription
                        ? '<span class="send-items-part-desc">' + escapeHtml(ln.partDescription) + '</span>'
                        : '')
                    // Komentarz z kartoteki części. Prefiksem jest ikona
                    // bi-journal-text — ten sam wskaźnik komentarza co w
                    // /purchase/cart (cart-view.js:1335) — zamiast badge'a
                    // z napisem „kartoteka". Ikona jest dekoracyjna
                    // (aria-hidden), więc kontekst dla czytników ekranu
                    // niosą: sr-only etykieta przed tekstem i nazwa
                    // przycisku „Kopiuj komentarz z kartoteki części".
                    + (vpComment
                        ? '<span class="send-items-part-vpcomment send-items-copyrow">'
                            + '<i class="bi bi-journal-text send-items-comment-icon" aria-hidden="true"></i>'
                            + '<span class="sr-only">Komentarz z kartoteki części:</span>'
                            + '<span>' + escapeHtml(vpComment) + '</span>'
                            + copyBtnHtml({ copy: vpComment,
                                            label: 'Kopiuj komentarz z kartoteki części' })
                          + '</span>'
                        : '')
                + '</td>'
                + '<td class="send-items-producer">' + escapeHtml(ln.producerName || '—') + '</td>'
                + '<td class="send-items-no">' + noCell + '</td>'
                + '<td class="text-right send-items-qty">' + escapeHtml(AtteItemsFormat.formatQty(ln.quantityOrdered)) + '</td>'
                + '<td class="send-items-unit">' + escapeHtml(ln.unitName || '—') + '</td>'
                + '<td class="send-items-comment">' + commentCell + '</td>'
            + '</tr>';
        }).join('');

        // Zwinięta domyślnie: tabela pozycji jest najwyższym blokiem
        // kroku 4 i przy 30 wierszach wypychała kompozytor wiadomości
        // poza ekran. Operator zajrzy tu, gdy chce sprawdzić, co
        // faktycznie leci do dostawcy.
        return step4CollapsibleCardHtml({
            id:    'send-step-4-items-body',
            icon:  'bi-list-ul',
            title: 'Pozycje',
            meta:  ' <span class="send-review-count">' + count + '</span>',
            open:  false,
            headerExtra: '<button type="button" class="send-copy-block-btn" data-copy-key="items" '
                + 'title="Kopiuj wszystkie pozycje jako tekst" '
                + 'aria-label="Kopiuj wszystkie pozycje jako tekst">'
                + '<i class="bi bi-clipboard" aria-hidden="true"></i> Kopiuj pozycje'
            + '</button>',
            bodyHtml: '<div class="table-responsive">'
                + '<table class="table table-sm send-items-table mb-0">'
                    + '<thead>'
                        + '<tr>'
                            + '<th>Część</th>'
                            + '<th>Producent</th>'
                            + '<th>Nr części prod.</th>'
                            + '<th class="text-right">Ilość</th>'
                            + '<th>Jedn.</th>'
                            + '<th>Komentarz</th>'
                        + '</tr>'
                    + '</thead>'
                    + '<tbody>' + rows + '</tbody>'
                + '</table>'
            + '</div>'
        });
    }

    function tplBadgeTitle() {
        const name = selectedDraftName();
        if (name !== null) return 'Używa szablonu: ' + name;
        // usesVendorTemplate() jest true, ale nazwy nie znamy (szablon
        // dostawcy nie dopasował się do katalogu aktywnych).
        if (usesVendorTemplate()) return 'Używa szablonu dostawcy';
        return 'Używa wbudowanego szablonu domyślnego';
    }

    /**
     * Plakietka przy tytule kompozytora — mówi, SKĄD wzięła się treść
     * poniżej. Złote gwiazdki = szablon z katalogu; wyszarzone zębatki
     * = szablon wbudowany (katalog nie ma nic dla tego typu dokumentu).
     */
    function tplBadgeHtml() {
        const isCatalog = usesVendorTemplate();
        const t = escapeHtml(tplBadgeTitle());
        return ' <i class="bi ' + (isCatalog ? 'bi-stars' : 'bi-gear')
            + ' send-composer-tpl-badge' + (isCatalog ? '' : ' is-builtin') + '"'
            + ' id="send-composer-tpl-badge"'
            + ' title="' + t + '"'
            + ' aria-label="' + t + '"></i>';
    }

    /** Adres katalogu szablonów — do linku w komunikacie o pustym katalogu. */
    function emailDraftsUrl() {
        // ROOT_DIR to globalny `const` z assets/layout/header.js. Nie
        // wisi na window (const nie tworzy właściwości globalnej), więc
        // sprawdzamy go przez typeof, a nie przez window.ROOT_DIR.
        const root = (typeof ROOT_DIR === 'string') ? ROOT_DIR : '';
        return root + '/admin/purchase/email-drafts';
    }

    /**
     * Pasek „Szablon wiadomości" na górze kompozytora.
     *
     * Picker listuje wyłącznie szablony z katalogu — wartością opcji
     * jest id szablonu. Wybór nadpisuje preferencję dostawcy TYLKO
     * w tej sesji kreatora; nic nie ląduje w bazie.
     *
     * Gdy katalog nie ma szablonu dla bieżącego typu dokumentu, pasek
     * zamienia się w komunikat — jednoopcyjny, martwy select udawałby
     * wybór, którego nie ma.
     *
     * bootstrap-select: data-width="100%" + własny, wymiarowany
     * kontener (#send-draft-picker-cell) + data-container na ten sam
     * kontener — kanoniczny zestaw z AGENTS.md dla pickera, który ma
     * wypełnić rodzica i przyciąć długie nazwy zamiast go rozpychać.
     */
    function buildDraftPickerHtml() {
        const drafts = (state.doc && state.doc.availableEmailDrafts) || [];

        if (drafts.length === 0) {
            const href = escapeHtml(emailDraftsUrl());
            return '<div class="send-composer-tplbar send-composer-tplbar-empty" '
                    + 'id="send-composer-tplbar">'
                + '<span class="send-composer-tpl-empty">'
                    + '<i class="bi bi-exclamation-triangle" aria-hidden="true"></i> '
                    + 'Brak szablonów dla tego typu dokumentu. Utwórz szablon w '
                    + '<a href="' + href + '" target="_blank" rel="noopener">'
                        + '/admin/purchase/email-drafts'
                    + '</a>. '
                    + 'Poniżej wstawiono wbudowany szablon domyślny.'
                + '</span>'
            + '</div>';
        }

        // Po initSelectedDraftFromDoc() / applyDraftPick() wybór zawsze
        // wskazuje istniejącą pozycję. `selectedId` domyka przypadek
        // brzegowy (id spoza listy), żeby picker nigdy nie stanął pusty.
        const known = drafts.some(function (dr) {
            return dr.id === state.selectedDraftId;
        });
        const selectedId = known ? state.selectedDraftId : drafts[0].id;

        const optionsHtml = drafts.map(function (dr) {
            return '<option value="' + dr.id + '"'
                + (selectedId === dr.id ? ' selected' : '') + '>'
                + escapeHtml(dr.name)
            + '</option>';
        }).join('');

        return '<div class="send-composer-tplbar" id="send-composer-tplbar">'
            + '<label class="send-composer-key send-composer-tpl-key" for="send-draft-picker">'
                + 'Szablon wiadomości'
            + '</label>'
            + '<div class="send-composer-tpl-cell" id="send-draft-picker-cell">'
                + '<select class="selectpicker" id="send-draft-picker" '
                    + 'data-width="100%" '
                    + 'data-container="#send-draft-picker-cell" '
                    + 'aria-label="Szablon wiadomości">'
                    + optionsHtml
                + '</select>'
            + '</div>'
            + '<span class="send-composer-tpl-hint">'
                + '<i class="bi bi-info-circle" aria-hidden="true"></i> '
                + 'Tylko na tę wysyłkę — preferencja dostawcy zostaje bez zmian.'
            + '</span>'
        + '</div>';
    }

    function buildStep4ComposerHtml() {
        // Temat i treść: jeśli operator coś zmienił (state.email*Edited
        // != null), jego wersja wygrywa nad wygenerowanym szablonem —
        // panel 4 renderuje się od nowa przy każdym wejściu i przy
        // każdej zmianie zaznaczenia w kroku 2, więc bez tego edycje
        // znikałyby.
        const subject = (state.emailSubjectEdited !== null)
            ? state.emailSubjectEdited : buildEmailSubject();
        const body = (state.emailBodyEdited !== null)
            ? state.emailBodyEdited : buildEmailBody();
        const emails  = step4SelectedEmails();
        const isDirty = (state.emailSubjectEdited !== null || state.emailBodyEdited !== null);

        const toHtml = emails.length > 0
            ? emails.map(function (e) {
                return '<span class="send-composer-chip">' + escapeHtml(e) + '</span>';
              }).join('')
            : '<span class="text-muted">brak adresu e-mail w kontaktach dostawcy</span>';

        // Jedno wyjście z kompozytora: cała wiadomość (temat + treść)
        // do schowka. Renderuje się ZAWSZE — kopiowanie nie potrzebuje
        // adresatów, a schowek nie ma limitu długości, który psuł
        // poprzednie „Otwórz w mailu" (mailto: ucinało dłuższe treści).
        const copyMailHtml = '<button type="button" class="btn btn-primary btn-sm" '
                + 'id="send-copy-mail-btn" '
                + 'title="Kopiuj całą wiadomość (temat + treść) do schowka" '
                + 'aria-label="Kopiuj treść maila">'
                + '<i class="bi bi-clipboard" aria-hidden="true"></i> Kopiuj treść maila'
            + '</button>';

        // Znacznik „skąd jest ta treść" — istotne, gdy wiadomość wygląda
        // inaczej niż operator pamięta z innych dostawców. Tooltip
        // nazywa konkretny szablon, jeśli go znamy.
        const tplBadge = tplBadgeHtml();

        // Ile adresów w wierszu „Do"? Ikona kopiuj gaśnie przy zerze.
        const recipientsCount = emails.length;

        return '<div class="card send-review-card send-composer mb-3">'
            + '<div class="card-header send-review-card-header d-flex justify-content-between align-items-center">'
                + '<span id="send-composer-title">'
                    + '<i class="bi bi-envelope-paper" aria-hidden="true"></i> Wiadomość do dostawcy'
                    + tplBadge
                + '</span>'
                + '<button type="button" id="send-email-reset" '
                    + 'class="send-composer-reset' + (isDirty ? '' : ' is-hidden') + '" '
                    + 'title="Przywróć wygenerowany szablon" '
                    + 'aria-label="Przywróć wygenerowany szablon">'
                    + '<i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Przywróć szablon'
                + '</button>'
            + '</div>'
            + buildDraftPickerHtml()
            // Ikona „kopiuj" przy każdym z trzech wierszy nagłówka
            // stoi PO LEWEJ od etykiety, dzięki czego wszystkie trzy
            // (Do / Temat / Treść) tworzą jedną kolumnę na krawędzi
            // karty. Przy „Do" kopiują się adresy odbiorców, przy
            // „Temat" sam temat, przy „Treść" cała wiadomość —
            // ikona jest na wysokości tego, co skopiuje, licząc od tego
            // wiersza w dół. Ikona przy „Do" gaśnie, gdy brak
            // odbiorców z adresem e-mail (chip po prawej też jest
            // wtedy zastąpiony komunikatem „brak adresu…").
            + '<div class="send-composer-head">'
                + '<div class="send-composer-field">'
                    + copyBtnHtml({ recipients: true,
                                    label: 'Kopiuj adresy odbiorców',
                                    title: 'Kopiuj adresy e-mail odbiorców (oddzielone przecinkami)',
                                    disabled: recipientsCount === 0,
                                    disabledTitle: recipientsCount === 0
                                        ? 'Wybierz co najmniej jednego odbiorcę (krok 2)'
                                        : undefined,
                                    extraClass: 'send-composer-row-copy mr-2' })
                    + '<span class="send-composer-key">Do</span>'
                    + '<span class="send-composer-to">' + toHtml + '</span>'
                + '</div>'
                + '<div class="send-composer-field send-composer-field-subject">'
                    + copyBtnHtml({ target: '#send-email-subject', label: 'Kopiuj temat',
                                    extraClass: 'send-composer-row-copy mr-2' })
                    + '<label class="send-composer-key" for="send-email-subject">Temat</label>'
                    + '<input type="text" id="send-email-subject" readonly '
                        + 'class="send-composer-subject" value="' + escapeHtml(subject) + '">'
                + '</div>'
            + '</div>'
            // Blok treści ma DWA widoki i pokazuje zawsze dokładnie
            // jeden — data-view na tym wrapperze przestawia oba przez
            // selektory [data-show-in] w CSS, bez dotykania styli w JS.
            //
            // Domyślnie 'preview': operator widzi wiadomość taką, jaka
            // pójdzie do Gmaila (tabela pozycji, nie surowy TSV).
            // Textarea to narzędzie edycji, nie „prawdziwa" treść —
            // dlatego nagłówek mówi „Treść", a nie „Podgląd".
            //
            // Klik w sam podgląd NIE przełącza trybu — jedyną drogą
            // do edycji jest przycisk „Edytuj". To zapobiega przypadkowym
            // przeskokom (klik w treść podczas czytania zamierza ustawiać
            // kursor tekstowy, nie wyrzucać z podglądu). W zamian
            // sam przycisk jest wyraźny i zawsze dostępny w pasku narzędzi.
            + '<div class="send-composer-body" data-view="'
                + (state.composerView === 'edit' ? 'edit' : 'preview') + '">'
                + buildComposerBodyBarHtml()
                + '<div class="send-composer-body-preview" id="send-email-body-preview"'
                    + ' data-show-in="preview" aria-hidden="false"></div>'
                + '<textarea id="send-email-body" class="send-composer-body-textarea"'
                    + ' rows="10" data-show-in="edit"'
                    + ' aria-label="Treść wiadomości"'
                    + ' placeholder="Treść wiadomości…">'
                    + escapeHtml(body)
                + '</textarea>'
            + '</div>'
            + '<div class="send-composer-foot">'
                + copyMailHtml
                + '<span class="send-composer-hint">'
                    + '<i class="bi bi-info-circle" aria-hidden="true"></i> '
                    + 'System nie wysyła e-maili. Skopiuj wiadomość do swojego klienta poczty — '
                    + 'przycisk „Wyślij” poniżej zmienia wyłącznie stan dokumentu.'
                + '</span>'
            + '</div>'
        + '</div>';
    }

    /**
     * Renderuje widok „Treść" — treść wiadomości z tabelą HTML w
     * miejscu pozycji. Inaczej niż w email-draft-edit (gdzie token
     * {items} przeżywa do podglądu) — tu {items} jest już podstawiony
     * na TSV na etapie generowania treści (buildTemplateVars /
     * buildEmailBodyDefault), więc treść w textarei nigdy nie ma
     * literału {items}. Podgląd szuka więc dokładnie TEGO SAMEGO
     * stringu TSV (ten sam ctx.documentNumber co przy generowaniu) i
     * podaje go jako trzeci argument AtteItemsFormat.previewBody() —
     * ta sama funkcja, co w email-draft-edit, inny marker do podziału.
     *
     * Brak dopasowania (operator ręcznie zmienił blok pozycji) →
     * cała treść wraca jako czysty tekst, bez zgadywania.
     *
     * Temat NIE jest tu powtarzany — ma własny, zawsze widoczny
     * wiersz nad blokiem treści; drugi raz byłby szumem.
     */
    function renderComposerPreview() {
        try {
            const $previewBody = $('#send-email-body-preview');
            if ($previewBody.length === 0) return;   // markup jeszcze nie wstrzyknięty
            // Normalizacja CRLF → LF: niektóre przeglądarki / schowek mogą
            // wstawić \r\n do textarei, a MARKER_END z wrapItems() ma zawsze
            // same \n — bez normalizacji indexOf nie trafi.
            const body = ($('#send-email-body').val() || '').toString().replace(/\r\n?/g, '\n');
            // Pusta treść (szablon nie wypełnił nic) — zamiast pustej,
            // niczego nie mówiącej płaszczyzny podpowiadamy, co zrobić.
            if (body.trim() === '') {
                $previewBody.html('<span class="send-composer-body-empty">'
                    + 'Brak treści — kliknij, aby zacząć pisać.'
                + '</span>');
                return;
            }
            const d       = state.doc || {};
            const ctx     = { documentNumber: d.number || (d.id ? '#' + d.id : '') };
            // Edycje operatora w komórkach (5000→5500) muszą być widoczne
            // w podglądzie — więc tabela pochodzi z TEJ SAMEJ ścieżki co
            // „Kopiuj pozycje": parsujemy pipe-table z textarei. Fallback
            // do kanonicznego state.doc.lines (gdy brak markerów albo
            // parsowanie nie zwróciło żadnego wiersza) — wtedy preview
            // pokazuje oryginał, ale schowek i tak kopiuje kanoniczny
            // (patrz click.copyStep4 branch „items"). `headers` z parsera
            // idą do htmlTable, więc zmiana „Price" na „Prices" też
            // odbija się w podglądzie.
            const fromBody = AtteItemsFormat.clipboardItemsFromBody(body, ctx);
            const lines = (fromBody && fromBody.parsed)
                ? fromBody.parsed
                : (d.lines || []);
            const itemsHtml = AtteItemsFormat.htmlTable(lines, ctx,
                fromBody && fromBody.headers);
            // Markery `<<<ATTE_ITEMS_TABLE>>>` / `<<<END_ATTE_ITEMS_TABLE>>>`
            // otaczają tabelę w body — lokalizują jej pozycję. previewBodyMarked
            // robi split na spanie między nimi i wkleja HTML w to samo miejsce.
            // Brak markerów → escape całości + HTML na końcu.
            $previewBody.html(AtteItemsFormat.previewBodyMarked(body, itemsHtml));
        } catch (err) {
            // Ciche przełknięcie — podgląd jest informacyjny; przy
            // błędzie zostaje poprzednia zawartość (lub pusty DOM).
        }
    }

    function refreshTplBadge() {
        // Plakietka „bi-stars" żyje w nagłówku karty kompozytora, ale
        // zmienia się przy każdym wyborze w pickerze — podmieniamy ją
        // w miejscu, bez przerysowywania całej karty (to zamknęłoby
        // rozwinięte karty niżej i zniszczyło picker w trakcie klikania).
        const $titleEl = $('#send-composer-title');
        if ($titleEl.length === 0) return;
        $titleEl.find('#send-composer-tpl-badge').remove();
        $titleEl.append(tplBadgeHtml());
    }

    /**
     * Reakcja na zmianę pickera „Szablon wiadomości".
     * `id` to id szablonu z katalogu. Wartość spoza katalogu (albo 0
     * z degradacji pickera do zwykłego selecta) spada na PIERWSZY
     * szablon — wbudowany domyślny nie jest już pozycją do wyboru,
     * tylko wyjściem awaryjnym przy pustym katalogu.
     */
    function applyDraftPick(id) {
        const drafts = (state.doc && state.doc.availableEmailDrafts) || [];
        const hit = drafts.find(function (dr) { return dr.id === id; })
                 || drafts[0]
                 || null;

        applySelectedDraft(hit);

        // Ręczne edycje muszą odpaść: przypięta wersja operatora wygrywa
        // nad wygenerowaną (patrz buildStep4ComposerHtml), więc bez tego
        // wybór nowego szablonu nie zmieniłby nic w treści. Mówimy o tym
        // wprost toastem, żeby utrata pracy nie była cicha.
        const hadEdits = (state.emailSubjectEdited !== null || state.emailBodyEdited !== null);
        state.emailSubjectEdited = null;
        state.emailBodyEdited    = null;
        $('#send-email-reset').addClass('is-hidden');

        // Temat + treść z nowego szablonu. Podmieniamy wartości pól, a
        // nie cały markup — przyciski „Kopiuj temat" / „Kopiuj treść
        // wiadomości" i tak czytają .val() dopiero przy kliknięciu,
        // więc kopiują aktualną wersję bez żadnej dodatkowej pracy.
        $('#send-email-subject').val(buildEmailSubject());
        $('#send-email-body').val(buildEmailBody());

        refreshTplBadge();
        renderComposerPreview();

        if (hadEdits) {
            flashCopyToast('Szablon podmieniony — ręczne zmiany nadpisane');
        }
    }

    // ---- „Wstaw zmienną" — inserter wartości z dokumentu -----------
    //
    // MIRROR OF $edVarCatalog in
    // public_html/components/Admin/Purchase/EmailDrafts/edit/email-draft-edit-view.php
    // (tam to katalog TOKENÓW do wstawiania w szablon; tutaj katalog
    // WARTOŚCI do wstawiania w gotową wiadomość). Ta sama lista nazw,
    // te same etykiety, ta sama kolejność grup — operator, który zna
    // edytor szablonów, znajdzie tu dokładnie to samo menu.
    //
    // Projekt nie ma systemu modułów, a PHP-owy katalog nie jest
    // eksportowany do JS — duplikat 12 wierszy jest tańszy niż nowy
    // endpoint albo wstrzykiwanie JSON-a do markupu. Przy dodaniu
    // zmiennej trzeba ruszyć TRZY miejsca: $edVarCatalog, ten katalog
    // i buildTemplateVars() wyżej.
    //
    // {vendor_address} celowo nie ma tu wpisu — loader kroku 4 nie
    // zwraca adresu dostawcy, więc buildTemplateVars() go nie zna.
    const STEP4_VAR_CATALOG = [
        { group: 'Dostawca', name: 'vendor_name',            label: 'Nazwa dostawcy' },
        { group: 'Dostawca', name: 'vendor_contact_name',    label: 'Imię i nazwisko osoby kontaktowej' },
        { group: 'Dostawca', name: 'vendor_contact_email',   label: 'E-mail osoby kontaktowej' },
        { group: 'Dokument', name: 'document_type',          label: 'Typ dokumentu' },
        { group: 'Dokument', name: 'document_number',        label: 'Numer dokumentu' },
        { group: 'Dokument', name: 'document_comment',       label: 'Komentarz z nagłówka dokumentu' },
        { group: 'Daty',     name: 'expected_reply_date',    label: 'Oczekiwany termin odpowiedzi' },
        { group: 'Daty',     name: 'expected_delivery_date', label: 'Oczekiwany termin dostawy' },
        { group: 'Daty',     name: 'created_at',             label: 'Data utworzenia dokumentu' },
        { group: 'Pozycje',  name: 'items',                  label: 'Lista pozycji — blok wieloliniowy' },
        { group: 'Pozycje',  name: 'item_count',             label: 'Liczba pozycji' },
        { group: 'Meta',     name: 'created_by_name',        label: 'Osoba, która utworzyła dokument' },
    ];

    /** Bieżąca wartość zmiennej jako string; '' gdy nieznana/pusta. */
    function step4VarValue(vars, name) {
        if (!Object.prototype.hasOwnProperty.call(vars, name)) return '';
        const v = vars[name];
        return (v === null || v === undefined) ? '' : String(v);
    }

    /**
     * Jednoliniowy podgląd wartości do menu. Zwija białe znaki (blok
     * {items} ma kilkanaście linii) i przycina do 40 znaków — menu ma
     * podpowiadać, CO się wstawi, a nie pokazywać całą treść.
     */
    function step4VarPreview(value) {
        const flat = String(value).replace(/\s+/g, ' ').trim();
        return flat.length > 40 ? flat.slice(0, 39) + '…' : flat;
    }

    /**
     * Wstawia `text` w miejscu kursora w polu $field (zaznaczenie
     * zostaje podmienione). Bliźniak insertAtCursor() z
     * email-draft-edit-view.js — tam wstawia token {zmienna}, tutaj
     * jego rozwiniętą wartość.
     *
     * `asBlock` obsługuje {items}: blok wieloliniowy musi stanąć w
     * osobnej linii, inaczej skleiłby się z akapitem, w który wpadł
     * kursor.
     *
     * DOM-friendly: preferuje document.execCommand('insertText') — to
     * jedyny sposób, żeby wstawka trafiła do NATURALNEGO undo stacku
     * textarei. Prosty `$field.val(newValue)` (jQuery setter) NIM nie
     * jest podpięty — Ctrl+Z po „Wstaw zmienną" cofał tylko ręczne
     * znaki, ale nie samą wstawkę.
     */
    function insertAtCursor($field, text, asBlock) {
        const el = $field.get(0);
        if (!el) return;

        // selectionStart/End przeżywają utratę fokusu w nowoczesnych
        // przeglądarkach (Chrome/Edge/Firefox), więc pozycja kursora
        // ustawiona przez ostatnie kliknięcie w textarea jest nadal
        // znana — ale sam element fokusu nie ma, bo kliknięcie w
        // przycisk „Wstaw zmienną" przeniosło go na button. execCommand
        // wymaga fokusu na textarei, więc zawsze go przywracamy PRZED
        // sprawdzeniem aktywnego elementu — inaczej wpadamy w gałąź
        // fallback, która niszczy historię undo.
        const hasSel = (typeof el.selectionStart === 'number'
                     && typeof el.selectionEnd === 'number');
        const value = el.value || '';
        const start = hasSel ? el.selectionStart : value.length;
        const end   = hasSel ? el.selectionEnd   : value.length;

        let chunk = String(text);
        if (asBlock) {
            if (start > 0 && value.charAt(start - 1) !== '\n') chunk = '\n' + chunk;
            if (end < value.length && value.charAt(end) !== '\n') chunk = chunk + '\n';
        }

        // Przywróć fokus i selekcję — execCommand musi działać na aktywnym
        // elemencie z poprawną pozycją kursora.
        if (document.activeElement !== el) {
            el.focus();
        }
        if (hasSel) {
            el.setSelectionRange(start, end);
        }

        // Ścieżka „undo-friendly": execCommand('insertText') jest jedynym
        // sposobem, żeby wstawka trafiła do NATURALNEGO undo stacku
        // textarei. Zwykły `el.value = …` tego nie robi — Ctrl+Z cofa
        // tylko ręczne znaki, ale nie samą wstawkę.
        if (document.execCommand) {
            const ok = document.execCommand('insertText', false, chunk);
            if (ok) return;     // execCommand sam emituje `input`
        }

        // Fallback (przeglądarki bez execCommand): ręczny splice + trigger.
        // Niszczy undo, ale zachowuje treść i `input`.
        el.value = value.slice(0, start) + chunk + value.slice(end);
        const caret = start + chunk.length;
        if (hasSel) { el.setSelectionRange(caret, caret); }
        // `input` ręcznie — bezpośrednie ustawienie el.value go nie
        // odpala, a od niego zależy przypięcie edycji operatora
        // (state.emailBodyEdited) i pokazanie przycisku „Przywróć
        // szablon".
        $field.trigger('input');
    }

    /**
     * Pasek nad treścią wiadomości: ikona kopiuj + etykieta „Treść" +
     * (z drugiej strony) rozwijane menu „Wstaw zmienną".
     *
     * Ikona kopiuj na lewej krawędzi lustrznie odbija układ wierszy
     * „Do" i „Temat" — wszystkie trzy tworzą jedną kolumnę na
     * krawędzi karty. Payload = buildComposerBodyText(), ten sam co ikona
     * przy „Do" w poprzednim wcieleniu i przycisk w stopce:
     * redundancja dla odkrywalności, jeden payload dla wszystkich
     * trzech kopii.
     *
     * Menu po prawej pokazuje ROZWIĄZANE wartości, nie tokeny — w
     * kroku 4 nie ma już czego parsować, wiadomość jest gotowa. Token
     * w monospace zostaje tylko jako etykieta, żeby operator znający
     * edytor szablonów rozpoznał wiersz po tej samej nazwie.
     *
     * Pusta wartość (np. brak komentarza) zostaje w menu jako pozycja
     * wyłączona z dopiskiem „(brak)" — zniknięcie wiersza kazałoby
     * operatorowi zgadywać, czy zmienna nie istnieje, czy tylko nie ma
     * wartości.
     */
    function buildComposerBodyBarHtml() {
        const vars = buildTemplateVars(state.doc);

        let itemsHtml = '';
        let lastGroup = null;
        STEP4_VAR_CATALOG.forEach(function (v) {
            if (v.group !== lastGroup) {
                if (lastGroup !== null) itemsHtml += '<div class="dropdown-divider"></div>';
                itemsHtml += '<h6 class="dropdown-header send-var-group">'
                    + escapeHtml(v.group)
                + '</h6>';
                lastGroup = v.group;
            }
            const value   = step4VarValue(vars, v.name);
            const isEmpty = value.trim() === '';
            const preview = isEmpty ? '(brak)' : step4VarPreview(value);
            const tip     = isEmpty
                ? v.label + ' — brak wartości w tym dokumencie'
                : v.label + ' — wstawi: ' + step4VarPreview(value);

            itemsHtml += '<button type="button" role="menuitem"'
                + ' class="dropdown-item send-var-item' + (isEmpty ? ' disabled' : '') + '"'
                + (isEmpty ? ' disabled' : ' data-var-name="' + escapeHtml(v.name) + '"')
                + ' title="' + escapeHtml(tip) + '">'
                + '<span class="send-var-line">'
                    + '<code class="send-var-token">{' + escapeHtml(v.name) + '}</code>'
                    + '<span class="send-var-label">' + escapeHtml(v.label) + '</span>'
                + '</span>'
                + '<span class="send-var-preview' + (isEmpty ? ' is-empty' : '') + '">'
                    + escapeHtml(preview)
                + '</span>'
            + '</button>';
        });

        // Etykieta to <span>, nie <label for>: textarea bywa ukryta
        // (tryb „Treść"), a etykieta opisuje CAŁY blok treści — i
        // podgląd, i edytor. Textarea ma własne aria-label.
        //
        // „Wstaw zmienną" ma data-show-in="edit" — wstawia w miejscu
        // kursora w textarei, więc w trybie podglądu nie ma czego
        // robić. Ukrywa je CSS po data-view na rodzicu, bez JS.
        return '<div class="send-composer-body-bar">'
            + copyBtnHtml({ full: true, label: 'Kopiuj treść wiadomości',
                            title: 'Kopiuj treść wiadomości do schowka',
                            extraClass: 'send-composer-row-copy mr-2' })
            + '<span class="send-composer-key send-composer-body-label">Treść</span>'
            + '<div class="send-composer-body-tools">'
                + '<div class="dropdown send-var-dropdown" data-show-in="edit">'
                    + '<button type="button" class="btn btn-outline-secondary btn-sm send-var-btn"'
                        + ' id="send-var-insert-btn" data-toggle="dropdown"'
                        + ' aria-haspopup="true" aria-expanded="false"'
                        + ' title="Wstaw wartość zmiennej w miejscu kursora"'
                        + ' aria-label="Wstaw zmienną z dokumentu">'
                        + '<i class="bi bi-braces" aria-hidden="true"></i> Wstaw zmienną'
                    + '</button>'
                    + '<div class="dropdown-menu dropdown-menu-right send-var-dropdown-menu"'
                        + ' role="menu" aria-labelledby="send-var-insert-btn">'
                        + itemsHtml
                    + '</div>'
                + '</div>'
                + composerToggleBtnHtml(state.composerView)
            + '</div>'
        + '</div>';
    }

    /**
     * Jeden przycisk w obie strony — nie zakładka, nie para radio.
     * `view` to widok AKTUALNY; `data-target-view` mówi, gdzie
     * przycisk zabierze po kliknięciu.
     *
     *   podgląd → „Edytuj"  (ołówek)  → włącza textareę
     *   edycja  → „Gotowe"  (fajka)   → wraca do podglądu
     */
    function composerToggleBtnHtml(view) {
        const toEdit = (view !== 'edit');
        return '<button type="button" class="btn btn-sm btn-outline-secondary send-composer-toggle"'
            + ' data-target-view="' + (toEdit ? 'edit' : 'preview') + '"'
            + ' title="' + (toEdit
                ? 'Edytuj treść wiadomości'
                : 'Zakończ edycję i wróć do widoku wiadomości') + '">'
            + (toEdit
                ? '<i class="bi bi-pencil" aria-hidden="true"></i> Edytuj'
                : '<i class="bi bi-check-lg" aria-hidden="true"></i> Gotowe')
        + '</button>';
    }

    /**
     * Przestawia kompozytor między „Treść" (podgląd) i edycją.
     * Atrybut data-view na .send-composer-body jest JEDYNYM przełącznikiem
     * widoczności — reszta to selektory [data-show-in] w CSS.
     */
    function setComposerView(view) {
        const next = (view === 'edit') ? 'edit' : 'preview';
        state.composerView = next;
        const $wrap = $('#send-step-4-body .send-composer-body');
        if ($wrap.length === 0) return;
        $wrap.attr('data-view', next);
        $wrap.find('.send-composer-toggle').replaceWith(composerToggleBtnHtml(next));
        if (next === 'edit') {
            // Kursor od razu w treści — kliknięcie w podgląd ma być
            // jednym ruchem do pisania, bez drugiego kliknięcia.
            $('#send-email-body').trigger('focus');
        } else {
            // Powrót do podglądu przerysowuje treść, żeby zmiany z
            // textarei były widoczne także wtedy, gdy `input` nie
            // zdążył odpalić (wklejenie przez menu kontekstowe itp.).
            renderComposerPreview();
        }
    }

    /**
     * Buduje payload CAŁEGO BODY dla przycisków kopiujących treść maila
     * („Kopiuj treść maila" w stopce + ikona przy wierszu „Treść").
     * Zwraca OBIE wersje:
     *   - `html` — body z `<table>` inline (zamiast pipe-text między
     *     markerami). Gmail renderuje jako prawdziwą tabelę.
     *   - `plainText` — body z pipe-text (markery usunięte). Fallback
     *     dla przeglądarki bez ClipboardItem / dla paste-as-plain-text.
     *
     * Edycje operatora w komórkach tabeli zachowane w obu wersjach:
     * parser pipe-table → htmlTable() bierze aktualną zawartość textarei.
     */
    function buildComposerBodyPayload() {
        const body = ($('#send-email-body').val() || '').toString();
        const ctx  = { documentNumber: state.doc ? (state.doc.number || (state.doc.id ? '#' + state.doc.id : '')) : '' };
        return AtteItemsFormat.clipboardBodyFromText(body, ctx);
    }

    /**
     * Wspólny handler dla „kopiuj treść maila" — wywoływany przez stopkę
     * (`#send-copy-mail-btn`) i przez ikonę `.send-copy-btn` z
     * `data-copy-full="1"`. Woła ClipboardItem z text/html + text/plain,
     * a przeglądarki bez wsparcia dostają sam plain text przez
     * `copyToClipboard()`.
     */
    async function copyBodyAsMail($btn) {
        const payload = buildComposerBodyPayload();
        if (navigator.clipboard && window.ClipboardItem) {
            try {
                await navigator.clipboard.write([
                    new ClipboardItem({
                        'text/html':   new Blob([payload.html],       { type: 'text/html' }),
                        'text/plain':  new Blob([payload.plainText],  { type: 'text/plain' }),
                    }),
                ]);
                flashCopyButton($btn, 'bi bi-clipboard-check');
                return;
            } catch (err) {
                // Fallthrough do copyToClipboard.
            }
        }
        copyToClipboard(payload.plainText);
        flashCopyButton($btn, 'bi bi-clipboard-check');
    }

    /** Plain-text fallback dla „Kopiuj treść maila" — używane też przez
     * inne ścieżki, które nie chcą schowka HTML (np. „Otwórz w mailu"). */
    function buildComposerBodyText() {
        return buildComposerBodyPayload().plainText;
    }

    function renderStep4() {
        const d = state.doc;
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
                                + 'title="Kopiuj adres e-mail" aria-label="Kopiuj adres e-mail">'
                                + '<i class="bi bi-clipboard" aria-hidden="true"></i></button>'
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
                                + 'title="Kopiuj numer telefonu" aria-label="Kopiuj numer telefonu">'
                                + '<i class="bi bi-clipboard" aria-hidden="true"></i></button>'
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
                                + 'title="Kopiuj komentarz kontaktu" aria-label="Kopiuj komentarz kontaktu">'
                                + '<i class="bi bi-clipboard" aria-hidden="true"></i></button>'
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

        // Parametry wysyłki. Wiersz „Dokument" wypadł — numer i typ
        // pokazuje teraz karta „Podsumowanie…", a powtarzanie go tutaj
        // tylko rozmywało hierarchię.
        //
        // Karta jest zwinięta domyślnie: to rekapitulacja decyzji z
        // kroków 1-3, które operator właśnie podjął. Liczbę adresatów
        // (najważniejszą wartość) pokazujemy w nagłówku, więc bez
        // rozwijania też widać, czy jest do kogo wysyłać.
        const recapMeta = (supplierCount > 0)
            ? ' <span class="send-review-count">' + supplierCount + '</span>'
            : ' <span class="send-review-count send-review-count-danger">brak</span>';

        const recapBodyHtml =
            '<div class="card-body send-review-card-body">'
            + '<div class="summary-recap">'
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
            + '</div>'
            + '</div>';

        const recapHtml = step4CollapsibleCardHtml({
            id:    'send-step-4-params-body',
            icon:  'bi-send',
            title: 'Parametry wysyłki',
            meta:  recapMeta,
            open:  false,
            bodyHtml: recapBodyHtml
        });

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

        // Pozycje trzymamy w buforze jako obiekt { plainText, htmlTable } —
        // handler click.copyStep4 używa obu wariantów przez ClipboardItem
        // (Gmail dostaje tabelę HTML, plain-text jest fallbackiem dla
        // przeglądarek bez ClipboardItem lub bez HTTPS).
        step4Clip.items = {
            plainText: AtteItemsFormat.plainText(
                d.lines || [],
                { documentNumber: d.number || (d.id ? '#' + d.id : '') }
            ),
            htmlTable: AtteItemsFormat.htmlTable(
                d.lines || [],
                { documentNumber: d.number || (d.id ? '#' + d.id : '') }
            ),
        };

        // Hierarchia panelu: blokery → wiadomość → podsumowanie →
        // parametry wysyłki → pozycje.
        //
        // Kompozytor idzie PIERWSZY, bo w kroku 4 to on jest realną
        // pracą: operator redaguje treść, kopiuje ją i dopiero potem
        // klika „Wyślij". Podsumowanie schodzi pod niego — to kontrola
        // („czy to na pewno ten dokument?"), a nie czynność.
        // „Parametry wysyłki" i „Pozycje" zostają zwiniętymi
        // drilldownami: dane weryfikowane raz, a wcześniej rozpychały
        // kompozytor na drugi ekran (tabela 30 pozycji potrafiła zająć
        // całą wysokość okna).
        //
        // Sticky action bar zostaje poza tym kontenerem (jest w
        // PHP-owym szkielecie kroku 4).
        const $body = $('#send-step-4-body');
        $body.html(
            noSuppliersHtml
            + backdateHtml
            + buildStep4ComposerHtml()
            + buildStep4SummaryHtml()
            + recapHtml
            + buildStep4ItemsHtml()
        );

        // Widok „Treść" wypełniamy od razu po wstrzyknięciu markupu —
        // to on jest domyślnie na wierzchu, więc musi mieć zawartość
        // przed pierwszym malowaniem, a nie po pierwszej edycji.
        renderComposerPreview();

        // Chevrony zwijanych kart („Parametry wysyłki", „Pozycje").
        wireStep4Chevrons($body);

        // ---- Picker szablonu wiadomości ---------------------------
        // Init po wstrzyknięciu markupu — plugin musi zobaczyć <select>
        // w drzewie, żeby policzyć szerokość dla data-width="100%".
        // size: 8 nie pozwala rosnącemu katalogowi zasłonić kompozytora;
        // liveSearch — ten sam wybór co picker preferencji na
        // /admin/purchase/vendors/edit (vendor-edit-view.js:399).
        const $draftPicker = $('#send-draft-picker');
        if ($draftPicker.length > 0 && typeof $draftPicker.selectpicker === 'function') {
            $draftPicker.selectpicker({
                style: 'btn-light border',
                size: 8,
                liveSearch: true,
                liveSearchPlaceholder: 'Szukaj szablonu…',
                noneResultsText: 'Brak szablonu pasującego do {0}',
            });
        }

        // Nasłuch na natywnym `change`, nie na `changed.bs.select` —
        // bootstrap-select i tak wyzwala `change` na oryginalnym
        // <select>, a dzięki temu picker działa też, gdyby plugin się
        // nie wczytał (degraduje do zwykłego selecta).
        $body.off('change.draftPicker')
            .on('change.draftPicker', '#send-draft-picker', function () {
                applyDraftPick(parseInt($(this).val(), 10) || 0);
            });

        // ---- Kopiowanie (jedyny feedback: swap ikony) --------------
        // Delegujemy pod #send-step-4-body, bo markup jest właśnie
        // wstrzyknięty. Pięć kanałów wartości — patrz copyBtnHtml().
        $body.off('click.copyStep4')
            .on('click.copyStep4', '.send-copy-btn, .send-copy-block-btn', async function (e) {
                e.preventDefault();
                e.stopPropagation();
                const $btn   = $(this);
                if ($btn.prop('disabled')) return;   // ikona wyłączona (brak odbiorców itp.)
                const target     = $btn.attr('data-copy-target');
                const key        = $btn.attr('data-copy-key');
                const full       = $btn.attr('data-copy-full');
                const recipients = $btn.attr('data-copy-recipients');
                // Specjalny kanał „items": schowek dostaje zarówno HTML
                // (Gmail renderuje tabelę), jak i plainText (fallback).
                // ClipboardItem (Chrome 76+, FF 127+, Safari 13.1+) —
                // wcześniejsze przeglądarki lub brak HTTPS dostają sam
                // tekst przez copyToClipboard().
                //
                // WAŻNE: payload NIE pochodzi ze step4Clip.items (który
                // jest kanoniczny i nie odzwierciedla edycji operatora
                // w textarei). Czyta AKTUALNĄ treść textarei, parsuje
                // pipe-table między markerami <<<ATTE_ITEMS_TABLE>>>
                // i renderuje HTML z tego, co operator siedzi w polu
                // edycji. Fallback (markery usunięte / parsowanie
                // nieudane) — kanoniczny step4Clip.items.
                if (key === 'items' && step4Clip.items && typeof step4Clip.items === 'object') {
                    const body = ($('#send-email-body').val() || '').toString().replace(/\r\n?/g, '\n');
                    const ctx  = { documentNumber: state.doc ? (state.doc.number || (state.doc.id ? '#' + state.doc.id : '')) : '' };
                    const fromBody = AtteItemsFormat.clipboardItemsFromBody(body, ctx);
                    const payload  = fromBody || step4Clip.items;
                    const html = payload.htmlTable || '';
                    const text = payload.plainText || '';
                    if (navigator.clipboard && window.ClipboardItem) {
                        try {
                            await navigator.clipboard.write([
                                new ClipboardItem({
                                    'text/html': new Blob([html], { type: 'text/html' }),
                                    'text/plain': new Blob([text], { type: 'text/plain' }),
                                }),
                            ]);
                            flashCopyButton($btn, 'bi bi-clipboard-check');
                            return;
                        } catch (err) {
                            // fall through do copyToClipboard(text)
                        }
                    }
                    copyToClipboard(text);
                    // Bez flashCopyToast — w kroku 4 przycisków „kopiuj"
                    // są dziesiątki, toast byłby spamem. Sam swap ikony.
                    flashCopyButton($btn, 'bi bi-clipboard-check');
                    return;
                }
                let text = '';
                if (full) {
                    // Ikona „kopiuj" przy wierszu „Treść" — ten sam
                    // ClipboardItem HTML flow co stopka, żeby Gmail też
                    // dostał tabelę zamiast pipe-text.
                    copyBodyAsMail($btn);
                    return;
                }
                else if (recipients)      text = step4SelectedEmails().join(', ');
                else if (target)          text = ($(target).val() || '').toString();
                else if (key)             text = (step4Clip[key] || '').toString();
                else                      text = ($btn.attr('data-copy') || '').toString();
                if (text === '') return;
                copyToClipboard(text);
                // Bez flashCopyToast — w kroku 4 przycisków „kopiuj"
                // są dziesiątki, toast byłby spamem. Sam swap ikony.
                flashCopyButton($btn, 'bi bi-clipboard-check');
            });

        // ---- Kompozytor wiadomości --------------------------------
        // Każda edycja treści „przypina" wersję operatora w state, żeby
        // przetrwała ponowny render panelu; „Przywróć szablon" czyści
        // przypięcie i renderuje krok 4 od zera.
        $body.off('input.composer')
            .on('input.composer', '#send-email-body', function () {
                state.emailBodyEdited = ($(this).val() || '').toString();
                $('#send-email-reset').removeClass('is-hidden');
                renderComposerPreview();
            });
        // ---- Przełącznik „Treść" ↔ edycja -------------------------
        // Jedyna droga do edycji: przycisk „Edytuj" / „Gotowe". Klik w
        // sam podgląd jest celowo ignorowany — operator czyta wiadomość
        // i klika w losowe miejsca tekstu, licząc na zaznaczenie, a nie
        // na przeskok do edycji.
        $body.off('click.composerToggle')
            .on('click.composerToggle', '.send-composer-toggle', function (e) {
                e.preventDefault();
                e.stopPropagation();
                setComposerView($(this).attr('data-target-view'));
            });

        $body.off('click.composerReset')
            .on('click.composerReset', '#send-email-reset', function (e) {
                e.preventDefault();
                state.emailSubjectEdited = null;
                state.emailBodyEdited    = null;
                renderStep4();
            });

        // „Kopiuj treść maila" w stopce — własny handler, bo to
        // btn-primary, a nie ikona .send-copy-btn. Używa copyBodyAsMail
        // (ClipboardItem z text/html + text/plain) — Gmail renderuje
        // tabelę zamiast surowego pipe-text.
        $body.off('click.copyMail')
            .on('click.copyMail', '#send-copy-mail-btn', function (e) {
                e.preventDefault();
                copyBodyAsMail($(this));
            });

        // ---- „Wstaw zmienną" --------------------------------------
        // Wstawiamy WARTOŚĆ, nie token — w kroku 4 wiadomość jest już
        // rozwiązana, więc {vendor_name} w treści zostałby literałem.
        // Wartości czytamy przy kliknięciu, nie przy renderze: operator
        // mógł w międzyczasie wrócić do kroku 2 i zmienić kontakty.
        //
        // Dropdown zamyka sam Bootstrap (globalny listener na document),
        // więc nie ma tu stopPropagation ani ręcznego .dropdown('hide').
        $body.off('click.varInsert')
            .on('click.varInsert', '.send-var-item', function (e) {
                e.preventDefault();
                const name = $(this).attr('data-var-name');
                if (!name) return;                       // pozycja „(brak)"
                const text = step4VarValue(buildTemplateVars(state.doc), name);
                if (text === '') return;
                // Blok wieloliniowy ({items}) dostaje własne linie.
                insertAtCursor($('#send-email-body'), text, text.indexOf('\n') !== -1);
            });

        // Włącz / wyłącz commit + reakcja na „wróć do kroku 2".
        $commitBtn.prop('disabled', supplierCount === 0 || state.committing);
        $body.find('[data-go]').on('click', function () {
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
                setAlert((r && r.error) || 'Błąd wysyłki.', 'danger');
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
            setAlert(msg, 'danger');
        });
    });

    // ---- Start -----------------------------------------------------
    loadDocument();
});
