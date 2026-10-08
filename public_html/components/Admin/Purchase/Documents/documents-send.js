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
 *   4. Potwierdzenie — pasek kontekstu, kompozytor „E-mail" + karta
 *                      „PDF" (placeholder) obok, zwinięte „Szczegóły";
 *                      commit „Oznacz jako wysłane" (POST do
 *                      document-send-ajax.php albo
 *                      document-rfq-respond-ajax.php dla rfq+sent).
 *
 * Kontrakt GET/POST jest zamrożony (patrz documents-send-get.php,
 * document-send-ajax.php, document-rfq-respond-ajax.php).
 *
 * Konwencje: jQuery + Bootstrap 4. let/const wyłącznie. setAlert z
 * header.js do ogłaszania błędów sieciowych; własny alert na sukces
 * commita.
 *
 * Jedyny bootstrap-select w tym kreatorze to kompaktowy picker
 * „Szablon:" w nagłówku karty „E-mail" w kroku 4 (#send-draft-picker) — pozwala nadpisać
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
        // Krok 3 — oczekiwana data ('Y-m-d' albo ''): RFQ → odpowiedź,
        // PO → dostawa. Zapisywana dopiero przy finalnym „Oznacz jako wysłane".
        // `expectedDateTouched` = operator zmienił pole ręcznie; dopóki
        // false, wartość jest wyliczana na nowo (data z dokumentu albo,
        // dla PO, data wysyłki + lead time dostawcy).
        expectedDate:        '',
        expectedDateTouched: false,
        // Lokalny cache kopii filtrującej (nie mutujemy `doc.suppliers`)
        supplierFilter:   '',
        committing:       false,
        // Krok 4 — kompozytor wiadomości. `null` = "użyj wygenerowanego
        // szablonu"; string = operator edytował pole ręcznie i jego
        // wersja ma przetrwać ponowny render kroku 4 (setStep(4) oraz
        // refreshStep2NextEnabled() renderują panel od nowa).
        emailSubjectEdited: null,
        emailBodyEdited:    null,
        // Krok 4 — czy tabela pozycji w treści jest w trybie edycji
        // (surowy tekst z kolumnami rozdzielonymi „|"). Tekst wiadomości
        // przed i po tabeli jest edytowalny zawsze; ta flaga dotyczy
        // wyłącznie tabeli. Przeżywa ponowny render kroku 4.
        tableEdit:          false,
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
        // Data wysyłki (backdate) mogła się zmienić w kroku 1 → odśwież
        // domyślną datę PO zanim kroki 3/4 się narysują.
        if (n >= 3) refreshExpectedDateDefault();
        // Krok 4 ma układ dwukolumnowy (E-mail | PDF) i potrzebuje
        // szerszego kontenera niż w-75 kroków 1-3 (CSS: .is-step-4).
        $root.toggleClass('is-step-4', n === 4);
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
            refreshExpectedDateDefault();
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

        // Etykieta + tooltip commit buttona. System nie wysyła maili,
        // więc przycisk mówi wprost, co robi: zmienia stan dokumentu.
        // Tryb respond zostaje przy etykiecie z loadera („Oznacz jako
        // otrzymaną odpowiedź"). Ponowna wysyłka (RFQ responded / PO
        // confirmed) dostaje dopisek, tak jak actionLabel z loadera.
        if (isRespondMode) {
            $commitBtnLabel.text(d.actionLabel || 'Oznacz jako otrzymaną odpowiedź');
            $commitBtn.attr('title', 'Zapisuje odpowiedź dostawcy — nic nie jest wysyłane.');
        } else {
            const isResend = (DOC_TYPE === 'rfq' && d.state === 'responded')
                          || (DOC_TYPE === 'po'  && d.state === 'confirmed');
            $commitBtnLabel.text('Oznacz jako wysłane' + (isResend ? ' (ponownie)' : ''));
            $commitBtn.attr('title', 'System nie wysyła e-maili — ta akcja tylko zmienia stan '
                + 'dokumentu na „wysłany”. Skopiuj wiadomość do swojego klienta poczty.');
        }

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

        // Numer oferty u dostawcy — pole RFQ dostępne na entity (alias
        // `r.vendor_reference` w RFQRepository::buildSelectJoin()), dla PO
        // loader zwraca null. Renderujemy wyłącznie dla RFQ; przy pustej
        // wartości wstawiamy &nbsp; w obu kolumnach, żeby zachować
        // 2-kolumnowy rytm okalających wierszy.
        const vendorReferenceRowHtml = (DOC_TYPE === 'rfq')
            ? (d.vendorReference
                ? '<dt class="col-sm-2">Numer oferty u dostawcy:</dt>'
                  + '<dd class="col-sm-4">' + escapeHtml(d.vendorReference) + '</dd>'
                : '<dt class="col-sm-2">&nbsp;</dt><dd class="col-sm-4">&nbsp;</dd>')
            : '';

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
            + vendorReferenceRowHtml

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

        // Inicjalne zaznaczenie:
        //  a) jest dokładnie jeden kontakt → zaznaczamy go (jedyny wybór,
        //     operator nie musi klikać, żeby przejść dalej);
        //  b) w przeciwnym razie domyślnie pre-zaznaczony główny kontakt,
        //     jeśli istnieje (pierwszy rekord, bo SQL sortuje isPrimary DESC).
        if (state.selectedSuppliers.length === 0) {
            const auto = suppliers.length === 1
                ? suppliers[0]
                : suppliers.find(function (s) { return s.isPrimary; });
            if (auto) {
                state.selectedSuppliers = [auto.id];
                $('#send-supplier-list .contact-card[data-supplier-id="' + auto.id + '"]')
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

    // ---- Step 3: Oczekiwana data -----------------------------------
    //
    // RFQ: „Oczekiwana odpowiedź", PO: „Planowana dostawa". Pole jest
    // opcjonalne. Nic nie trafia do bazy, dopóki operator nie kliknie
    // „Oznacz jako wysłane" w kroku 4 — data jedzie w tym samym POST-cie co wysyłka
    // (document-send-ajax.php → pole `expected_date`).

    function pad2(n) { return String(n).padStart(2, '0'); }

    function ymdFromDate(dt) {
        return dt.getFullYear() + '-' + pad2(dt.getMonth() + 1) + '-' + pad2(dt.getDate());
    }

    function addDaysYmd(ymd, days) {
        const p = String(ymd).split('-');
        return ymdFromDate(new Date(parseInt(p[0], 10), parseInt(p[1], 10) - 1, parseInt(p[2], 10) + days));
    }

    // Data wysyłki 'Y-m-d': wybrany backdate albo dziś.
    function sendBaseDateYmd() {
        return state.backdatedAt
            ? String(state.backdatedAt).substring(0, 10)
            : ymdFromDate(new Date());
    }

    // Różnica w dniach (toYmd − fromYmd), liczona w UTC → bez błędów DST. Przyjmuje 'Y-m-d'
    // oraz pełne daty ('Y-m-d H:i:s' / ISO) — bierze tylko pierwsze 10 znaków. Zły format → NaN.
    // Wspólne dla kroków 3 i 4.
    function diffDaysYmd(fromYmd, toYmd) {
        const f = String(fromYmd).substring(0, 10).split('-');
        const t = String(toYmd).substring(0, 10).split('-');
        const a = Date.UTC(parseInt(f[0], 10), parseInt(f[1], 10) - 1, parseInt(f[2], 10));
        const b = Date.UTC(parseInt(t[0], 10), parseInt(t[1], 10) - 1, parseInt(t[2], 10));
        return Math.round((b - a) / 86400000);
    }

    // „16 dni" / „1 dzień" itd. — używa modułowego plPlural (niżej, hoisting). Wspólne dla kroków 3 i 4.
    function plUnit(n, one, few, many) {
        return n + ' ' + plPlural(n, one, few, many);
    }

    // Data zapisana już na dokumencie (RFQ: reply, PO: delivery) albo ''.
    function docExpectedDate() {
        const d = state.doc;
        if (!d) return '';
        const v = DOC_TYPE === 'po' ? d.expectedDeliveryDate : d.expectedReplyDate;
        return v ? String(v).substring(0, 10) : '';
    }

    // PO: podpowiedź = data wysyłki + lead time dostawcy. RFQ: brak.
    function leadTimeDefaultYmd() {
        const d = state.doc;
        if (DOC_TYPE !== 'po' || !d || !d.vendorLeadTimeDays) return '';
        return addDaysYmd(sendBaseDateYmd(), parseInt(d.vendorLeadTimeDays, 10));
    }

    function refreshExpectedDateDefault() {
        if (!state.doc || state.expectedDateTouched) return;
        state.expectedDate = docExpectedDate() || leadTimeDefaultYmd();
    }

    function renderStep3() {
        const d = state.doc;
        const isPo = (DOC_TYPE === 'po');
        const label = isPo ? 'Planowana dostawa' : 'Oczekiwana odpowiedź';
        // rfq+sent → tryb respond: dokument już wysłany, daty tu nie
        // zmieniamy (można to zrobić na stronie edycji dokumentu).
        const respondMode = (DOC_TYPE === 'rfq' && d.state === 'sent');

        if (respondMode) {
            $('#send-step-3-body').html(
                '<div class="alert alert-info mb-0">'
                    + 'Dokument został już wysłany — data oczekiwanej odpowiedzi nie jest tu zmieniana'
                    + (docExpectedDate() ? ' (obecnie: <strong>' + escapeHtml(docExpectedDate()) + '</strong>)' : '')
                    + '. Możesz ją zmienić na stronie edycji dokumentu.'
                + '</div>'
            );
            return;
        }

        // Informacje o dostawcy pod kontrolką daty: nazwa + „Lead time" (liczba) + komentarz (wolny tekst).
        // Nazwa i komentarz: RFQ i PO. „Lead time": tylko PO (to on wypełnia domyślną datę PO).
        // Brak wszystkiego → pusty string, bez pustego kontenera.
        // Komentarz dostawcy: undefined traktujemy jak brak; zawsze escapeHtml (tekst z bazy).
        const leadDays = (isPo && d.vendorLeadTimeDays) ? parseInt(d.vendorLeadTimeDays, 10) : 0;
        const vendorComment = (typeof d.vendorComment === 'string') ? d.vendorComment.trim() : '';
        // Długi komentarz (> 120 znaków albo > 2 linie) jest przycinany do 2 linii + „pokaż więcej".
        const commentIsLong = vendorComment.length > 120 || vendorComment.split('\n').length > 2;
        let leadHint = '';
        // Nazwa dostawcy = cichy kontekst nad wierszami. null / '' / undefined → brak elementu.
        const vendorNameText = (typeof d.vendorName === 'string') ? d.vendorName.trim() : '';
        if (leadDays > 0 || vendorComment || vendorNameText) {
            leadHint = '<div class="send-exp-lead">'
                + (vendorNameText
                    ? '<div class="send-exp-lead-name" title="' + escapeHtml(vendorNameText) + '">'
                        + escapeHtml(vendorNameText) + '</div>'
                    : '')
                + (leadDays > 0
                    ? '<div class="send-exp-lead-item">'
                        + '<span class="send-exp-lead-label">Lead time</span>'
                        + '<span class="send-exp-lead-value">' + escapeHtml(plUnit(leadDays, 'dzień', 'dni', 'dni')) + '</span>'
                    + '</div>'
                    : '')
                + (vendorComment
                    ? '<div class="send-exp-lead-item send-exp-lead-note">'
                        + '<i class="bi bi-chat-left-text" aria-hidden="true"></i>'
                        + '<div class="send-exp-lead-note-body">'
                            + '<div class="send-exp-lead-note-text' + (commentIsLong ? ' is-clamped' : '') + '" '
                                + 'title="' + escapeHtml(vendorComment) + '">' + escapeHtml(vendorComment) + '</div>'
                            + (commentIsLong
                                ? '<button type="button" class="btn btn-link btn-sm p-0 send-exp-lead-more">pokaż więcej</button>'
                                : '')
                        + '</div>'
                    + '</div>'
                    : '')
            + '</div>';
        }

        // plPlural / plUnit / diffDaysYmd są teraz na poziomie modułu (współdzielone z krokiem 4).

        // Miesiąc = 30 dni (tak samo jak szybki wybór „+1 miesiąc"). Max dwie największe jednostki.
        function humanizeDays(n) {
            const months = Math.floor(n / 30);
            const weeks = Math.floor((n % 30) / 7);
            const days = n - months * 30 - weeks * 7;
            const parts = [];
            if (months) parts.push(plUnit(months, 'miesiąc', 'miesiące', 'miesięcy'));
            if (weeks) parts.push(plUnit(weeks, 'tydzień', 'tygodnie', 'tygodni'));
            if (days && !months) parts.push(plUnit(days, 'dzień', 'dni', 'dni'));
            return parts.slice(0, 2).join(' i ');
        }

        function relativeHintHtml(ymd) {
            if (!ymd) return '<span class="text-muted">nie ustawiono</span>';
            const n = diffDaysYmd(sendBaseDateYmd(), ymd);
            // Łączna liczba dni (z tego samego n) — tylko dla dziś/przyszłości; w „po terminie" już jest w „X temu".
            const daysSpan = ' <span class="send-exp-rel-days">(' + plUnit(n, 'dzień', 'dni', 'dni') + ')</span>';
            if (n === 0) return '<span class="send-exp-rel-today">dzisiaj' + daysSpan + '</span>';
            if (n === 1) return '<span class="send-exp-rel-future">jutro' + daysSpan + '</span>';
            if (n > 0) return '<span class="send-exp-rel-future">za ' + escapeHtml(humanizeDays(n)) + daysSpan + '</span>';
            return '<span class="send-exp-rel-past"><i class="bi bi-exclamation-triangle"></i> po terminie ('
                + escapeHtml(humanizeDays(-n)) + ' temu)</span>';
        }

        // Kroki względem aktualnej daty (data-days ze znakiem). 0 = „Dziś" → zawsze data bazowa.
        // Grupy: Dziś | −/+ dzień | −/+ tydzień | −/+ miesiąc.
        // Minus = czerwony (send-exp-quick--minus), plus = zielony (send-exp-quick--plus), Dziś = neutralny.
        const quickGroups = [
            [{ days: 0, text: 'Dziś' }],
            [{ days: -1, text: '−1 dzień' }, { days: 1, text: '+1 dzień' }],
            [{ days: -7, text: '−1 tydzień' }, { days: 7, text: '+1 tydzień' }],
            [{ days: -30, text: '−1 miesiąc' }, { days: 30, text: '+1 miesiąc' }]
        ];
        const quickHtml = quickGroups.map(function (grp) {
            return '<div class="btn-group btn-group-sm send-exp-quick-group">'
                + grp.map(function (q) {
                    const tone = q.days > 0 ? 'send-exp-quick--plus'
                        : (q.days < 0 ? 'send-exp-quick--minus' : 'btn-outline-secondary');
                    return '<button type="button" class="btn send-exp-quick ' + tone + '" data-days="' + q.days + '">'
                        + escapeHtml(q.text) + '</button>';
                }).join('')
                + '</div>';
        }).join('');

        $('#send-step-3-body').html(
            '<p class="text-muted">Pole opcjonalne. Data zostanie zapisana razem z wysyłką dokumentu.</p>'
            + '<div class="form-group mb-0 send-exp">'
                + '<label for="send-expected-date" class="font-weight-bold">' + escapeHtml(label) + ':</label>'
                + '<div class="send-exp-row">'
                    + '<input type="date" id="send-expected-date" class="form-control send-exp-input" '
                        + 'value="' + escapeHtml(state.expectedDate || '') + '">'
                    + '<span class="send-exp-rel" id="send-expected-date-rel" aria-live="polite">'
                        + relativeHintHtml(state.expectedDate || '') + '</span>'
                    + '<button type="button" class="btn btn-outline-secondary btn-sm send-exp-clear" id="send-expected-date-clear">'
                        + '<i class="bi bi-x-lg"></i> Wyczyść'
                    + '</button>'
                + '</div>'
                + '<div class="send-exp-quick-row" role="group" aria-label="Szybki wybór daty">' + quickHtml + '</div>'
                + leadHint
            + '</div>'
        );

        // Jedna ścieżka zapisu: wartość → state + flaga „dotknięte" + odświeżenie podpowiedzi.
        function setExpected(ymd) {
            $('#send-expected-date').val(ymd);
            state.expectedDate = ymd;
            state.expectedDateTouched = true;
            $('#send-expected-date-rel').html(relativeHintHtml(ymd));
        }

        // Elementy powstały przed chwilą (.html() wyrzuca stare), więc nie ma wycieku handlerów.
        $('#send-expected-date').on('change input', function () {
            setExpected($(this).val() || '');
        });
        $('#send-expected-date-clear').on('click', function () {
            setExpected('');
        });
        $('#send-step-3-body .send-exp-lead-more').on('click', function () {
            const $text = $(this).siblings('.send-exp-lead-note-text');
            const expanded = $text.toggleClass('is-clamped').hasClass('is-clamped') === false;
            $(this).text(expanded ? 'pokaż mniej' : 'pokaż więcej');
        });
        $('#send-step-3-body .send-exp-quick').on('click', function () {
            const days = parseInt($(this).attr('data-days'), 10);
            const base = sendBaseDateYmd();
            if (days === 0) { // „Dziś": dokładnie data bazowa
                setExpected(base);
                return;
            }
            const current = $('#send-expected-date').val() || base; // puste pole → od daty bazowej
            const next = addDaysYmd(current, days);
            // Daty 'Y-m-d' porównują się poprawnie jako tekst; nie schodzimy przed datę bazową.
            setExpected(next < base ? base : next);
        });
    }

    // ---- Step 4: Potwierdzenie ------------------------------------
    //
    // Panel 4 to powierzchnia „przejrzyj i oznacz jako wysłane". Ma
    // pokazać na jeden rzut oka to, co ważne: cały e-mail (Do / Temat /
    // Treść), PDF i akcję. Kolejność bloków (renderStep4):
    //
    //   1. bloker „brak kontaktów" (tylko gdy dotyczy)
    //   2. pasek kontekstu — dostawca / numer / pozycje / wartość /
    //      termin; jedna cienka linia tylko do odczytu
    //   3. układ dwukolumnowy (>= lg; węziej jedno pod drugim):
    //        lewa  (~64%) — karta „E-mail" (kompozytor) + pod nią
    //                        zwinięta karta „Szczegóły"
    //        prawa (~36%) — karta „PDF" (sticky; na razie placeholder)
    //   Tryb respond (rfq+sent): nic nie jest wysyłane, więc bez
    //   kompozytora i bez PDF — pasek kontekstu, jednozdaniowe
    //   wyjaśnienie i „Szczegóły" na pełną szerokość.
    //
    // Data wysłania ustawiona wstecz (state.backdatedAt) nie jest już
    // osobnym alertem — to krótka notka obok przycisku commita na
    // sticky barze (#send-commit-note).
    //
    // WAŻNE: backend nie wysyła maila. W całym repo nie ma mail(), a
    // przycisk „Oznacz jako wysłane" na sticky barze robi wyłącznie
    // commit stanu (draft → sent) przez document-send-ajax.php. Dlatego
    // szablon wiadomości jest w 100% po stronie klienta: operator
    // kopiuje go przyciskiem „Kopiuj wiadomość" i wkleja do własnego
    // klienta poczty.
    //
    // Kopiowanie ograniczone do czterech akcji na wierzchu: ikony przy
    // Do / Temat / Treść + przycisk „Kopiuj wiadomość" w stopce.
    // (Zwinięte „Szczegóły" mają jeszcze „Kopiuj pozycje" i kopiowanie
    // danych kontaktowych.) Feedback: WYŁĄCZNIE swap ikony na przycisku
    // (bi-clipboard → bi-clipboard-check na 1.2 s), bez toastu.

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
        // Rekap/e-mail pokazują datę z kroku 3 (jeszcze niezapisaną),
        // nie tę z bazy. W trybie respond krok 3 niczego nie zmienia,
        // więc wartością jest data z dokumentu.
        const isRespondMode = (DOC_TYPE === 'rfq' && d.state === 'sent');
        return isRespondMode ? docExpectedDate() : (state.expectedDate || '');
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
        //                ten sam payload co przycisk „Kopiuj wiadomość"
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
        // Nasz własny numer dokumentu — wyliczany raz, bo używa go
        // {document_number} oraz fallback {vendor_document_number}.
        const ourNumber = blank(d.number || (d.id ? '#' + d.id : ''));
        // Numer po stronie dostawcy: RFQ → vendor_reference, PO →
        // vendor_po_number. Loader zwraca tylko właściwe pole dla typu.
        // Pusty string / null → niżej pada fallback na nasz numer.
        const vendorSideNumber = blank(
            isPo ? d.vendorPoNumber : d.vendorReference
        ).trim();

        return {
            vendor_name:           blank(d.vendorName),
            vendor_contact_name:   blank(contact && contact.name),
            vendor_contact_email:  blank(contact && contact.email),
            // {vendor_address} usunięte — loader kroku 4 nie zwraca już
            // d.vendorAddress. Gdyby szablon dostawcy zawierał ten token,
            // applyTemplate zostawi go w treści jako literał.
            document_type:         isPo ? 'zamówienie' : 'zapytanie',
            document_number:       ourNumber,
            // Numer dokumentu po stronie dostawcy: PO → vendor_po_number
            // ("Numer u dostawcy"), RFQ → vendor_reference ("Numer oferty
            // u dostawcy"). Loader kroku 4 zwraca tylko właściwe pole
            // dla danego typu, drugie jest zawsze null.
            // Fallback: gdy dostawca nie podał numeru (albo dokument nie
            // był jeszcze potwierdzony / odpowiedzi nie było), wstawiamy
            // NASZ numer — szablon „dotyczy: {vendor_document_number}"
            // ma zawsze coś sensownego, a nie pustkę w mailu.
            vendor_document_number: blank(vendorSideNumber || ourNumber),
            // Powiązane zapytanie — tylko PO powstałe z konwersji
            // (converted_from_rfq_id). Dla PO kupionego wprost oraz dla
            // samego RFQ obie są puste i token renderuje się jako pusty
            // string (jest w `vars`, więc applyTemplate podstawia '', a
            // nie zostawia literału). Świadomie BEZ fallbacku na numer
            // tego dokumentu — to numer innego dokumentu niż ten, o
            // którym idzie wiadomość, więc podstawienie naszego byłoby
            // mylące.
            rfq_number:           blank(d.rfqNumber),
            rfq_vendor_document_number: blank(d.rfqVendorReference),
            document_comment:      blank(d.comment),
            // Wartość z kroku 3 (jeszcze niezapisana) dla bieżącego typu;
            // drugi typ nie ma tu sensu, więc zostaje z dokumentu.
            expected_reply_date:   blank(isPo ? d.expectedReplyDate : step4ExpectedDate()),
            expected_delivery_date: blank(isPo ? step4ExpectedDate() : d.expectedDeliveryDate),
            created_by_name:       blank(d.createdByName),
            created_at:            blank(d.createdAt),
            item_count:            String(lines.length),
            // Pusty dokument dostaje tekst zastępczy w kolumnie
            // VendorPartNo (mirror pustego stanu tabeli pozycji w
            // buildStep4ItemsTableHtml); obsługa wewnątrz plainText.
            // Owijamy tabelę markerami — dzięki temu renderComposerPreview
            // lokalizuje tabelę nawet po edycji komórek (markery stoją
            // na zewnątrz, edycja wnętrza ich nie psuje).
            items: AtteItemsFormat.wrapItems(AtteItemsFormat.plainText(
                lines,
                { documentNumber: ourNumber }
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
    // dostawcy → GLOBALNY domyślny (isDefaultRfq / isDefaultPo dla
    // bieżącego typu dokumentu — zawsze PODRZĘDNY wobec preferencji
    // dostawcy, więc wchodzi do gry tylko gdy preferencja nie trafiła
    // w katalog) → PIERWSZY szablon z katalogu → szablon systemowy PL
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
        // pickera — patrz buildDraftPickerHtml() / buildDraftEmptyNoticeHtml().
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

        // Brak trafienia w preferencję dostawcy (albo preferencja
        // wskazuje szablon spoza katalogu) → szukamy globalnego
        // domyślnego dla bieżącego typu dokumentu. Flagi
        // isDefaultRfq / isDefaultPo przychodzą z loadera; starsze
        // snapshoty mogą ich nie mieć, więc nie zakładamy, że klucz
        // istnieje — Boolean(…) zamienia undefined / null / 0 w false
        // bez rzucania. Ten domyślny jest ZAWSZE podrzędny wobec
        // preferencji dostawcy, więc korzystamy z niego tylko gdy `hit`
        // jest pusty.
        if (!hit) {
            const defaultFlagKey = (DOC_TYPE === 'rfq') ? 'isDefaultRfq' : 'isDefaultPo';
            hit = drafts.find(function (dr) {
                return Boolean(dr[defaultFlagKey]);
            });
        }

        // Żadna preferencja, żaden globalny domyślny (albo oba wskazują
        // szablon spoza katalogu) → pierwsza pozycja listy. Dzięki
        // temu picker i treść wiadomości zawsze pokazują to samo.
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
     * Krok 4 ma dwie powierzchnie pierwszego planu — „E-mail" i „PDF" —
     * oraz jeden drilldown („Szczegóły": notatka, kontakty, pozycje),
     * który operator otwiera tylko wtedy, gdy chce coś sprawdzić. Ten
     * drugi budujemy tym helperem: nagłówek jest przyciskiem toggle,
     * a body siedzi w Bootstrapowym .collapse.
     *
     * Nagłówek to <div> z <button> w środku, nie <button class="card-header">,
     * bo karta „Szczegóły" trzyma w nagłówku własny przycisk „Kopiuj
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

    /**
     * Polska odmiana liczebnika: 1 pozycja / 2 pozycje / 5 pozycji.
     * Używana tylko w meta nagłówka „Szczegóły".
     */
    function plPlural(n, one, few, many) {
        n = Math.abs(n);
        if (n === 1) return one;
        const m10 = n % 10;
        const m100 = n % 100;
        if (m10 >= 2 && m10 <= 4 && (m100 < 12 || m100 > 14)) return few;
        return many;
    }

    /** Wartość dokumentu per waluta — ten sam markup .send-review-money co wcześniej. */
    function step4MoneyHtml() {
        const breakdown = (state.doc && state.doc.valueBreakdown) || [];
        if (breakdown.length === 0) return '<span class="text-muted">brak wyceny</span>';
        return breakdown.map(function (b) {
            return '<span class="send-review-money">'
                + escapeHtml(formatPrice(b.total))
                + ' <span class="send-review-money-cur">' + escapeHtml(b.currency) + '</span>'
            + '</span>';
        }).join('');
    }

    /**
     * Pasek kontekstu na górze kroku 4 — jedna cienka linia tylko do
     * odczytu: dostawca, numer, liczba pozycji, wartość per waluta,
     * termin. Zastępuje dawną kartę „Podsumowanie…" (bez autora i daty
     * utworzenia, bez przycisków kopiowania — to nie są dane, które
     * operator wkleja do maila). Notatka dokumentu siedzi w „Szczegółach".
     */
    function buildStep4ContextHtml() {
        const d      = state.doc;
        const number = d.number || ('#' + d.id);
        const lines  = d.lines || [];
        const date   = step4ExpectedDate();

        // Odległość od daty wysyłki (ta sama baza i diff co w kroku 3). Pusta data / zły format → ''.
        const dateDaysHtml = function (ymd) {
            if (!ymd) return '';
            const n = diffDaysYmd(sendBaseDateYmd(), ymd);
            if (isNaN(n)) return '';
            const txt = n >= 0 ? plUnit(n, 'dzień', 'dni', 'dni') : plUnit(-n, 'dzień', 'dni', 'dni') + ' temu';
            return ' <span class="send-ctx-days">(' + txt + ')</span>';
        };

        const item = function (label, valueHtml, extraClass) {
            return '<div class="send-context-item' + (extraClass ? ' ' + extraClass : '') + '">'
                + '<dt class="send-context-label">' + escapeHtml(label) + '</dt>'
                + '<dd class="send-context-value">' + valueHtml + '</dd>'
            + '</div>';
        };

        return '<dl class="send-context" aria-label="Podsumowanie dokumentu">'
            + item('Dostawca',
                   '<strong>' + escapeHtml(d.vendorName || '—') + '</strong>',
                   'send-context-vendor')
            + item(DOC_TYPE === 'po' ? 'Zamówienie' : 'Zapytanie',
                   '<strong class="send-context-number">' + escapeHtml(number) + '</strong>')
            + item('Pozycji', '<strong>' + lines.length + '</strong>')
            + item('Wartość', step4MoneyHtml(), 'send-context-money')
            + item(step4ExpectedLabel(),
                   date
                       ? '<strong>' + escapeHtml(date) + '</strong>' + dateDaysHtml(date)
                       : '<span class="text-muted">nie podano</span>')
        + '</dl>';
    }

    /**
     * Dane wybranych kontaktów (e-mail / telefon / komentarz, z mailto:
     * i tel: oraz kopiowaniem). Siedzi w zwiniętych „Szczegółach" —
     * same adresy i tak są w wierszu „Do" kompozytora.
     */
    function buildStep4ContactsHtml(selectedList) {
        if (selectedList.length === 0) {
            return '<p class="text-muted mb-0">Nie wybrano kontaktów.</p>';
        }
        const copyIcon = function (value, label) {
            return '<button type="button" class="send-copy-btn" data-copy="' + escapeHtml(value) + '" '
                + 'title="' + label + '" aria-label="' + label + '">'
                + '<i class="bi bi-clipboard" aria-hidden="true"></i></button>';
        };
        return '<div class="summary-recap"><div class="summary-recap-contacts">'
            + selectedList.map(function (s) {
                let html = '<div class="summary-contact-row">'
                    + '<strong>' + escapeHtml(s.name) + '</strong>'
                    + (s.jobTitle ? ' <small class="text-muted">— ' + escapeHtml(s.jobTitle) + '</small>' : '')
                + '</div>';
                if (s.email) {
                    html += '<div class="summary-contact-line">'
                        + '<i class="bi bi-envelope"></i> '
                        + '<a href="mailto:' + escapeHtml(s.email) + '">' + escapeHtml(s.email) + '</a>'
                        + copyIcon(s.email, 'Kopiuj adres e-mail')
                    + '</div>';
                }
                if (s.phone) {
                    html += '<div class="summary-contact-line">'
                        + '<i class="bi bi-telephone"></i> '
                        + '<a href="tel:' + escapeHtml(s.phone) + '">' + escapeHtml(s.phone) + '</a>'
                        + copyIcon(s.phone, 'Kopiuj numer telefonu')
                    + '</div>';
                }
                if (s.comment) {
                    html += '<div class="summary-contact-line summary-contact-comment">'
                        + '<i class="bi bi-chat-left-text"></i> '
                        + '<span class="summary-contact-comment-text">' + escapeHtml(s.comment) + '</span>'
                        + copyIcon(s.comment, 'Kopiuj komentarz kontaktu')
                    + '</div>';
                }
                return html;
            }).join('')
        + '</div></div>';
    }

    /**
     * Tabela pozycji do „Szczegółów" — bez kopiowania per komórka.
     * Zostają opis części i komentarz z kartoteki (vendorPartComment),
     * bo tych informacji nie ma w treści maila.
     */
    function buildStep4ItemsTableHtml(lines) {
        if (lines.length === 0) {
            return '<p class="text-muted mb-0">Dokument nie ma pozycji.</p>';
        }

        const rows = lines.map(function (ln) {
            const prodNo    = ln.producerPartNo   || '';
            const vendNo    = ln.vendorPartNo     || '';
            const comment   = ln.comment          || '';
            const vpComment = ln.vendorPartComment || '';

            // Nr części producenta, a pod nim (gdy inny) nr u dostawcy.
            let noCell = '';
            if (prodNo) {
                noCell += '<span class="send-items-copyrow">'
                    + '<code class="send-items-no-val">' + escapeHtml(prodNo) + '</code>'
                + '</span>';
            }
            if (vendNo && vendNo !== prodNo) {
                noCell += '<span class="send-items-copyrow send-items-secondary">'
                    + '<span class="send-items-tag">dost.</span>'
                    + '<code class="send-items-no-val">' + escapeHtml(vendNo) + '</code>'
                + '</span>';
            }
            if (noCell === '') noCell = '<span class="text-muted">—</span>';

            // Kolumna „Komentarz" to WYŁĄCZNIE komentarz pozycji
            // dokumentu. Komentarz z kartoteki części opisuje samą część,
            // więc stoi w kolumnie „Część", pod opisem.
            const commentCell = comment
                ? '<span class="send-items-comment-text">' + escapeHtml(comment) + '</span>'
                : '<span class="text-muted">—</span>';

            // Cena jednostkowa — to samo formatowanie co kolumna Price
            // w {items} (AtteItemsFormat.formatPrice + kod waluty), ale
            // brak ceny (RFQ przed odpowiedzią) to samo „—", bez waluty:
            // loader i tak dokleja 'PLN' domyślnie, więc „— PLN" byłoby
            // szumem. Wartości pozycji loader nie zwraca — nie liczymy jej.
            const hasPrice = (ln.unitPrice !== null && ln.unitPrice !== undefined && ln.unitPrice !== '');
            const priceCell = hasPrice
                ? escapeHtml(formatPrice(ln.unitPrice))
                    + (ln.currency
                        ? ' <span class="send-items-cur">' + escapeHtml(ln.currency) + '</span>'
                        : '')
                : '<span class="text-muted">—</span>';

            return '<tr>'
                + '<td class="send-items-part">'
                    + '<span class="send-items-part-name">' + escapeHtml(ln.partName || '—') + '</span>'
                    + (ln.partDescription
                        ? '<span class="send-items-part-desc">' + escapeHtml(ln.partDescription) + '</span>'
                        : '')
                    // Ikona bi-journal-text — ten sam wskaźnik komentarza
                    // co w /purchase/cart. Dekoracyjna, więc kontekst dla
                    // czytników ekranu niesie etykieta sr-only.
                    + (vpComment
                        ? '<span class="send-items-part-vpcomment send-items-copyrow">'
                            + '<i class="bi bi-journal-text send-items-comment-icon" aria-hidden="true"></i>'
                            + '<span class="sr-only">Komentarz z kartoteki części:</span>'
                            + '<span>' + escapeHtml(vpComment) + '</span>'
                          + '</span>'
                        : '')
                + '</td>'
                + '<td class="send-items-producer">' + escapeHtml(ln.producerName || '—') + '</td>'
                + '<td class="send-items-no">' + noCell + '</td>'
                + '<td class="text-right send-items-qty">' + escapeHtml(AtteItemsFormat.formatQty(ln.quantityOrdered)) + '</td>'
                + '<td class="send-items-unit">' + escapeHtml(ln.unitName || '—') + '</td>'
                + '<td class="text-right send-items-price">' + priceCell + '</td>'
                + '<td class="send-items-comment">' + commentCell + '</td>'
            + '</tr>';
        }).join('');

        return '<div class="table-responsive">'
            + '<table class="table table-sm send-items-table mb-0">'
                + '<thead>'
                    + '<tr>'
                        + '<th>Część</th>'
                        + '<th>Producent</th>'
                        + '<th>Nr części prod.</th>'
                        + '<th class="text-right">Ilość</th>'
                        + '<th>Jedn.</th>'
                        + '<th class="text-right">Cena jedn.</th>'
                        + '<th>Komentarz</th>'
                    + '</tr>'
                + '</thead>'
                + '<tbody>' + rows + '</tbody>'
            + '</table>'
        + '</div>';
    }

    /**
     * Jedna zwinięta karta „Szczegóły": notatka dokumentu, dane
     * wybranych kontaktów i tabela pozycji. Zastępuje dawne
     * „Parametry wysyłki" i „Pozycje". Meta w nagłówku pokazuje
     * liczniki, więc bez rozwijania widać, co jest w środku.
     * Domyślnie zwinięta; `open` = true zachowuje rozwinięcie przy
     * ponownym renderze kroku 4.
     */
    function buildStep4DetailsHtml(selectedList, open) {
        const d     = state.doc;
        const lines = d.lines || [];
        const count = lines.length;
        const nContacts = selectedList.length;

        const metaParts = [
            count + ' ' + plPlural(count, 'pozycja', 'pozycje', 'pozycji'),
            nContacts + ' ' + plPlural(nContacts, 'kontakt', 'kontakty', 'kontaktów'),
        ];
        if (d.comment) metaParts.push('notatka');
        const meta = ' <span class="send-review-meta">' + escapeHtml(metaParts.join(' · ')) + '</span>';

        const noteHtml = d.comment
            ? '<section class="send-details-section">'
                + '<h6 class="send-details-heading">Notatka do dokumentu</h6>'
                + '<div class="send-review-note">'
                    + '<i class="bi bi-chat-left-text" aria-hidden="true"></i>'
                    + '<span>' + escapeHtml(d.comment) + '</span>'
                + '</div>'
              + '</section>'
            : '';

        const contactsHtml = '<section class="send-details-section">'
            + '<h6 class="send-details-heading">Wybrane kontakty</h6>'
            + buildStep4ContactsHtml(selectedList)
        + '</section>';

        // „Kopiuj pozycje" tylko gdy jest co kopiować.
        const copyItemsBtn = (count > 0)
            ? '<button type="button" class="send-copy-block-btn" data-copy-key="items" '
                + 'title="Kopiuj wszystkie pozycje jako tabelę" '
                + 'aria-label="Kopiuj wszystkie pozycje jako tabelę">'
                + '<i class="bi bi-clipboard" aria-hidden="true"></i> Kopiuj pozycje'
              + '</button>'
            : '';

        return step4CollapsibleCardHtml({
            id:    'send-step-4-details-body',
            icon:  'bi-card-list',
            title: 'Szczegóły',
            meta:  meta,
            open:  open === true,
            headerExtra: copyItemsBtn,
            bodyHtml: '<div class="card-body send-review-card-body">'
                + '<div class="send-details-meta">' + noteHtml + contactsHtml + '</div>'
                + '<section class="send-details-section send-details-items">'
                    + '<h6 class="send-details-heading">Pozycje</h6>'
                    + buildStep4ItemsTableHtml(lines)
                + '</section>'
            + '</div>'
        });
    }

    /**
     * Karta „PDF" (prawa kolumna). Na razie placeholder — generowanie
     * PDF jeszcze nie istnieje, więc przyciski są nieaktywne.
     *
     * Kontrakt pod przyszły endpoint PDF:
     *   #send-pdf-preview  — kontener podglądu; wstawić do niego
     *                        <iframe class="send-pdf-frame" src="…"> w
     *                        miejsce .send-pdf-placeholder (proporcje A4
     *                        trzyma CSS kontenera)
     *   #send-pdf-open     — „Podgląd": zamienić <button disabled> na
     *                        <a href="…" target="_blank" rel="noopener">
     *   #send-pdf-download — „Pobierz": <a href="…" download>
     * Klasa .is-unavailable na karcie steruje stanem „wkrótce".
     */
    function buildStep4PdfHtml() {
        const unavailable = 'Generowanie PDF nie jest jeszcze dostępne.';
        return '<div class="card send-review-card send-pdf-card is-unavailable">'
            + '<div class="card-header send-review-card-header d-flex align-items-center">'
                + '<span><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> PDF</span>'
                + '<span class="send-pdf-status ml-auto">wkrótce</span>'
            + '</div>'
            + '<div class="card-body send-review-card-body">'
                + '<div class="send-pdf-preview" id="send-pdf-preview">'
                    + '<div class="send-pdf-placeholder">'
                        + '<i class="bi bi-file-earmark-pdf" aria-hidden="true"></i>'
                        + '<span>Podgląd PDF będzie dostępny wkrótce.</span>'
                    + '</div>'
                + '</div>'
                // Wrapper z title: wyłączony <button> w części przeglądarek
                // nie pokazuje własnego tooltipa.
                + '<div class="send-pdf-actions" title="' + unavailable + '">'
                    + '<button type="button" class="btn btn-outline-secondary btn-sm" id="send-pdf-open" '
                        + 'disabled aria-disabled="true" title="' + unavailable + '">'
                        + '<i class="bi bi-eye" aria-hidden="true"></i> Podgląd'
                    + '</button>'
                    + '<button type="button" class="btn btn-outline-secondary btn-sm" id="send-pdf-download" '
                        + 'disabled aria-disabled="true" title="' + unavailable + '">'
                        + '<i class="bi bi-download" aria-hidden="true"></i> Pobierz'
                    + '</button>'
                + '</div>'
            + '</div>'
        + '</div>';
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
     * Kompaktowy wybór szablonu w nagłówku karty „E-mail":
     * „Szablon: <nazwa> ▾". Nazwa ▾ to sam przycisk bootstrap-select
     * (ostylowany na cichy, mały trigger), więc jeden klik otwiera
     * listę z wyszukiwarką — bez osobnego paska na całą szerokość.
     *
     * Picker listuje wyłącznie szablony z katalogu — wartością opcji
     * jest id szablonu. Wybór nadpisuje preferencję dostawcy TYLKO
     * w tej sesji kreatora; nic nie ląduje w bazie (mówi o tym title
     * na etykiecie „Szablon").
     *
     * Gdy katalog nie ma szablonu dla bieżącego typu dokumentu, zamiast
     * pickera stoi wyciszone „Szablon: wbudowany", a pod nagłówkiem
     * pełny komunikat (buildDraftEmptyNoticeHtml) — jednoopcyjny,
     * martwy select udawałby wybór, którego nie ma.
     *
     * bootstrap-select: data-width="100%" + własny, wymiarowany
     * kontener (#send-draft-picker-cell) + data-container na ten sam
     * kontener + min-width: 0 na flex-wrapperze — kanoniczny zestaw z
     * AGENTS.md dla pickera, który ma przyciąć długie nazwy zamiast
     * rozpychać nagłówek.
     */
    function buildDraftPickerHtml() {
        const drafts = (state.doc && state.doc.availableEmailDrafts) || [];
        const hint = 'Tylko na tę wysyłkę — preferencja dostawcy zostaje bez zmian.';

        if (drafts.length === 0) {
            return '<div class="send-composer-tpl" id="send-composer-tplbar">'
                + '<span class="send-composer-tpl-key">Szablon:</span>'
                + '<span class="send-composer-tpl-builtin">wbudowany</span>'
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
            // Opcja oznaczona jako globalny domyślny dla bieżącego
            // typu dokumentu dostaje w pickierze dopisek „(domyślny)”
            // — tylko wtedy, gdy NIE jest aktualnie zaznaczona
            // (aktywny wybór sam w sobie jest wizualną wskazówką;
            // podwajanie byłoby szumem). Starsze snapshoty mogą nie
            // mieć tej kolumny — Boolean(…) toleruje brak klucza.
            const defaultFlagKey = (DOC_TYPE === 'rfq') ? 'isDefaultRfq' : 'isDefaultPo';
            const isGlobalDefault = Boolean(dr[defaultFlagKey]);
            const labelSuffix = (isGlobalDefault && dr.id !== selectedId) ? ' (domyślny)' : '';
            return '<option value="' + dr.id + '"'
                + (selectedId === dr.id ? ' selected' : '') + '>'
                + escapeHtml(dr.name + labelSuffix)
            + '</option>';
        }).join('');

        // Bez `title` na samym <select> — bootstrap-select traktuje go
        // jako tekst „nic nie wybrano" i podpis przycisku.
        return '<div class="send-composer-tpl" id="send-composer-tplbar" title="' + escapeHtml(hint) + '">'
            + '<label class="send-composer-tpl-key" for="send-draft-picker">Szablon:</label>'
            + '<div class="send-composer-tpl-cell" id="send-draft-picker-cell">'
                + '<select class="selectpicker" id="send-draft-picker" '
                    + 'data-width="100%" '
                    + 'data-container="#send-draft-picker-cell" '
                    + 'aria-label="Szablon wiadomości (tylko na tę wysyłkę)">'
                    + optionsHtml
                + '</select>'
            + '</div>'
        + '</div>';
    }

    /**
     * Komunikat o pustym katalogu szablonów — pełny wiersz pod
     * nagłówkiem karty „E-mail". To stan do naprawienia w katalogu,
     * więc ma być widoczny, a nie schowany za klikiem.
     */
    function buildDraftEmptyNoticeHtml() {
        const drafts = (state.doc && state.doc.availableEmailDrafts) || [];
        if (drafts.length > 0) return '';
        const href = escapeHtml(emailDraftsUrl());
        return '<div class="send-composer-tplbar send-composer-tplbar-empty">'
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

        // Główne wyjście z kompozytora: treść wiadomości do schowka
        // (text/html z tabelą + text/plain). Temat kopiuje się osobno,
        // ikoną przy polu „Temat". Renderuje się ZAWSZE — kopiowanie nie
        // potrzebuje adresatów.
        const copyMailHtml = '<button type="button" class="btn btn-primary btn-sm" '
                + 'id="send-copy-mail-btn" '
                + 'title="Kopiuj treść wiadomości do schowka (temat kopiujesz osobno, przy polu Temat)" '
                + 'aria-label="Kopiuj wiadomość">'
                + '<i class="bi bi-clipboard" aria-hidden="true"></i> Kopiuj wiadomość'
            + '</button>';

        // Znacznik „skąd jest ta treść" — tooltip nazywa konkretny
        // szablon, jeśli go znamy.
        const tplBadge = tplBadgeHtml();

        // Ile adresów w wierszu „Do"? Ikona kopiuj gaśnie przy zerze.
        const recipientsCount = emails.length;

        return '<div class="card send-review-card send-composer mb-3">'
            + '<div class="card-header send-review-card-header send-composer-header">'
                + '<span id="send-composer-title" class="send-composer-title">'
                    + '<i class="bi bi-envelope-paper" aria-hidden="true"></i> E-mail'
                    + tplBadge
                + '</span>'
                + '<div class="send-composer-header-tools">'
                    + buildDraftPickerHtml()
                    + '<button type="button" id="send-email-reset" '
                        + 'class="send-composer-reset' + (isDirty ? '' : ' is-hidden') + '" '
                        + 'title="Przywróć wygenerowany szablon" '
                        + 'aria-label="Przywróć wygenerowany szablon">'
                        + '<i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Przywróć szablon'
                    + '</button>'
                + '</div>'
            + '</div>'
            + buildDraftEmptyNoticeHtml()
            // Ikona „kopiuj" przy każdym z trzech wierszy (Do / Temat /
            // Treść) stoi PO LEWEJ od etykiety — wszystkie trzy tworzą
            // jedną kolumnę na krawędzi karty. Ikona przy „Do" gaśnie,
            // gdy brak odbiorców z adresem e-mail.
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
            // Treść to jedno pole (host contenteditable): tekst przed
            // tabelą → wyspa z tabelą pozycji → tekst po tabeli. Tekst
            // jest edytowalny od razu; tylko tabela ma własny przycisk
            // „Edytuj tabelę" (surowy tekst z kolumnami rozdzielonymi „|").
            // Jedno pole = Ctrl+A zaznacza i kopiuje całą wiadomość.
            //
            // Źródłem prawdy zostaje JEDEN string z markerami w
            // ukrytej textarei #send-email-body — wszystko niżej
            // (schowek, stan, „Przywróć szablon") czyta ją tak jak
            // wcześniej. Host jest tylko widokiem: po każdej edycji
            // serializujemy go z powrotem do tego stringa.
            + '<div class="send-composer-body">'
                + buildComposerBodyBarHtml()
                + buildComposerSegmentsHtml(AtteItemsFormat.splitBody(body))
                + '<textarea id="send-email-body" hidden tabindex="-1"'
                    + ' aria-hidden="true">'
                    // Parser HTML zjada pierwszy \n tuż po <textarea>,
                    // więc dokładamy własny — inaczej body zaczynające
                    // się od markera (tabela na samej górze) traciłoby
                    // wiodący \n, a wraz z nim dopasowanie MARKER_START.
                    + '\n' + escapeHtml(body)
                + '</textarea>'
            + '</div>'
            + '<div class="send-composer-foot">'
                + copyMailHtml
            + '</div>'
        + '</div>';
    }

    /**
     * Jedna część tekstu wiadomości w hoście (przed / po tabeli albo cała
     * wiadomość bez tabeli): blok `div.send-body-seg` z czystym tekstem.
     * Znacznik `<br>` na końcu to placeholder przeglądarki — dzięki niemu
     * pusta część albo tekst kończący się nową linią ma widoczną linię z
     * kursorem. Serializer (AtteItemsFormat.domToTextParts) go ignoruje.
     */
    function composerHostSegHtml(seg, text) {
        const t = String(text || '');
        return '<div class="send-body-seg" data-seg="' + seg + '">'
            + escapeHtml(t)
            + ((t === '' || t.charAt(t.length - 1) === '\n') ? '<br>' : '')
        + '</div>';
    }

    /**
     * Cała treść wiadomości jako JEDEN edytowalny host (`contenteditable`),
     * zbudowany z wyniku AtteItemsFormat.splitBody():
     *
     *   • z tabelą:  tekst przed → wyspa z tabelą (`contenteditable="false"`,
     *                przycisk edycji w prawym górnym rogu) → tekst po,
     *   • bez tabeli (szablon bez {items} albo markery usunięte): sam
     *                tekst, żadnych kontrolek tabeli.
     *
     * Dzięki jednemu hostowi Ctrl+A zaznacza całą wiadomość razem z
     * tabelą (zaznaczenie nie jest uwięzione w jednej textarei).
     *
     * Markery nigdy nie trafiają do DOM — doklejane są dopiero w
     * readComposerBody(). Wyspa trzyma też ukrytą textareę z tekstem
     * tabeli (`#send-body-table`); jest źródłem tabeli niezależnie od
     * trybu widoku, a w trybie edycji jest po prostu widoczna.
     *
     * Wywoływane przy renderze kroku 4 i przy zmianie struktury (nowy
     * szablon, markery w tekście); podczas pisania NIE — przebudowa
     * hosta zabrałaby fokus, kursor i Ctrl+Z.
     */
    function buildComposerSegmentsHtml(seg) {
        const hostOpen = '<div class="send-body-host' + (seg.hasTable ? '' : ' is-plain') + '"'
            + ' id="send-body-host" contenteditable="true" role="textbox"'
            + ' aria-multiline="true" aria-label="Treść wiadomości" spellcheck="true">';
        if (!seg.hasTable) {
            return hostOpen + composerHostSegHtml('before', seg.before) + '</div>';
        }
        const editing = (state.tableEdit === true);
        const toggleLabel = editing ? 'Zakończ edycję tabeli' : 'Edytuj tabelę';
        return hostOpen
            + composerHostSegHtml('before', seg.before)
            + '<div class="send-body-table" id="send-body-table-block" contenteditable="false"'
                + ' data-mode="' + (editing ? 'edit' : 'view') + '">'
                // Pasek kontrolek wyspy (prawy górny róg): edycja tabeli
                // i usunięcie tabeli z wiadomości. Cały pasek ma
                // data-atte-ui, więc serializer / wycinanie go pomija.
                // Usunięcie nie pyta o potwierdzenie — da się je cofnąć
                // („Przywróć szablon") albo wstawić tabelę ponownie
                // („Wstaw zmienną → Lista pozycji").
                + '<div class="send-body-table-tools" data-atte-ui="1">'
                    + '<button type="button" class="send-body-table-edit" id="send-body-table-toggle"'
                        + ' data-atte-ui="1"'
                        + ' aria-pressed="' + (editing ? 'true' : 'false') + '"'
                        + ' title="' + toggleLabel + '"'
                        + ' aria-label="' + toggleLabel + '">'
                        + '<i class="bi bi-pencil" data-show-in="view" aria-hidden="true"></i>'
                        + '<i class="bi bi-check-lg" data-show-in="edit" aria-hidden="true"></i>'
                        + '<span class="send-body-table-edit-label" data-show-in="edit">Gotowe</span>'
                    + '</button>'
                    + '<button type="button" class="send-body-table-edit send-body-table-remove"'
                        + ' id="send-body-table-remove" data-atte-ui="1"'
                        + ' title="Usuń tabelę z wiadomości"'
                        + ' aria-label="Usuń tabelę z wiadomości">'
                        + '<i class="bi bi-trash" aria-hidden="true"></i>'
                    + '</button>'
                + '</div>'
                + '<div class="send-body-table-view" id="send-email-body-preview"'
                    + ' data-show-in="view"></div>'
                + '<div class="send-body-table-editor" data-show-in="edit">'
                    + '<div class="send-body-table-hint" data-atte-ui="1">'
                        + 'Pierwszy wiersz to nagłówki, kolumny rozdzielone znakiem „|”.'
                    + '</div>'
                    + '<textarea id="send-body-table" class="send-body-table-textarea"'
                        + ' data-seg="table" rows="4" wrap="off" spellcheck="false"'
                        + ' aria-label="Tabela pozycji — tekst z kolumnami rozdzielonymi znakiem |">'
                        // Parser HTML zjada pierwszy \n tuż po <textarea>.
                        + '\n' + escapeHtml(seg.table)
                    + '</textarea>'
                + '</div>'
            + '</div>'
            + composerHostSegHtml('after', seg.after)
        + '</div>';
    }

    function composerHost() { return document.getElementById('send-body-host'); }
    function composerIsland() { return document.getElementById('send-body-table-block'); }
    function composerIsIslandNode(n) { return n.nodeType === 1 && n.id === 'send-body-table-block'; }
    function inComposerIsland(node) {
        const isl = composerIsland();
        return !!(isl && node && isl.contains(node));
    }

    /**
     * Składa pełne body (z markerami) z hosta. Bez wyspy → sam tekst.
     * Gdy hosta nie ma w DOM (markup jeszcze niewstrzyknięty), zwraca to,
     * co trzyma ukryte źródło prawdy.
     */
    function readComposerBody() {
        const host = composerHost();
        if (!host) return ($('#send-email-body').val() || '').toString();
        const parts = AtteItemsFormat.domToTextParts(host, composerIsIslandNode);
        const ta = document.getElementById('send-body-table');
        return AtteItemsFormat.bodyFromHostParts(parts, ta ? ta.value : '');
    }

    /**
     * Dopasowuje wysokość textarei tabeli do treści. Host rośnie sam (to
     * zwykły div). Gdy panel jest ukryty (scrollHeight = 0) nic nie robi —
     * setStep(4) i tak renderuje krok od nowa.
     */
    function autoGrowTextarea(el) {
        if (!el) return;
        el.style.height = 'auto';
        const h = el.scrollHeight;
        if (h > 0) el.style.height = (h + (el.offsetHeight - el.clientHeight)) + 'px';
    }

    function autoGrowComposer() {
        autoGrowTextarea(document.getElementById('send-body-table'));
    }

    // ---- Host: zaznaczenie, wyspa, polecenia edycji -----------------

    let composerSavedRange = null;     // ostatnie zaznaczenie w hoście (dla „Wstaw zmienną")
    let composerCmdBusy = false;       // true, gdy to MY wywołujemy execCommand

    // ---- Cofanie operacji strukturalnych (Ctrl+Z / Ctrl+Y) ------------
    //
    // Operacje na tabeli — usunięcie (kosz), przeniesienie („Wstaw zmienną
    // → Lista pozycji" przy istniejącej tabeli) i wstawienie tabeli do
    // wiadomości bez tabeli — przebudowują host (rebuildComposerSegments),
    // więc natywna historia przeglądarki ich nie zna. Trzymamy dla nich
    // własny, mały stos migawek.
    //
    // Model hybrydowy: przy Ctrl+Z najpierw próbujemy NATYWNEGO cofnięcia
    // (execCommand('undo')). Jeśli zmieniło treść — to było zwykłe pisanie
    // i na tym koniec. Jeśli treść się nie zmieniła (historia natywna
    // pusta albo trzyma tylko wpisy z podmienionego hosta), zdejmujemy
    // migawkę ze stosu. Dzięki temu „usuń tabelę → pisz → Ctrl+Z" cofa
    // najpierw pisanie, a kolejne Ctrl+Z przywraca tabelę. Redo symetrycznie.
    //
    // Gdy stos dla danego kierunku jest pusty, w ogóle nie przechwytujemy
    // klawisza — zwykłe pisanie ma w 100% natywne cofanie.
    //
    // Stosy czyści renderStep4() (render od zera) i applyDraftPick()
    // (nowy szablon zastępuje całą wiadomość).
    const STRUCT_HISTORY_MAX = 50;
    const structUndo = [];
    const structRedo = [];
    let structBusy = false;            // true w trakcie naszego undo/redo

    /** Pozycja kursora w hoście bez tabeli (jeden segment) — albo null. */
    function composerPlainCaretOffset() {
        if (composerIsland()) return null;
        const host = composerHost();
        let r = composerSelectionRange();
        // Klik w menu „Wstaw zmienną" zabiera zaznaczenie — wtedy
        // ostatnie zapamiętane miejsce kursora w hoście.
        if (!r && host && composerSavedRange && host.contains(composerSavedRange.startContainer)) {
            r = composerSavedRange;
        }
        const seg = host && host.querySelector('.send-body-seg');
        if (!r || !seg || !r.collapsed) return null;
        if (r.startContainer === seg.firstChild && r.startContainer.nodeType === 3) return r.startOffset;
        return null;
    }

    /** Migawka bieżącej wiadomości (body z markerami + stan edycji). */
    function structSnapshot() {
        return {
            body:        readComposerBody(),
            edited:      state.emailBodyEdited,
            tableEdit:   state.tableEdit === true,
            caretOffset: composerPlainCaretOffset(),
        };
    }

    /** Zapis przed nową operacją strukturalną — nowa gałąź kasuje redo. */
    function pushStructUndo(snap) {
        structUndo.push(snap);
        if (structUndo.length > STRUCT_HISTORY_MAX) structUndo.shift();
        structRedo.length = 0;
    }

    function clearStructHistory() {
        structUndo.length = 0;
        structRedo.length = 0;
    }

    /**
     * Odtwarza migawkę: body (z tabelą i edycjami komórek operatora
     * dokładnie w tym miejscu, gdzie była), przypięcie edycji, tryb
     * tabeli. emailBodyEdited === null → wiadomość wraca do stanu „bez
     * zmian", więc „Przywróć szablon" znów się chowa.
     */
    function restoreStructSnapshot(snap) {
        state.emailBodyEdited = snap.edited;
        $('#send-email-reset').toggleClass('is-hidden', snap.edited === null);
        state.tableEdit = snap.tableEdit === true;
        lastComposerFieldId = 'send-body-host';
        rebuildComposerSegments(snap.body);

        const host = composerHost();
        if (!host) return;
        if (composerIsland()) {
            // Kursor na początku tekstu za tabelą — tuż przy niej.
            composerCaretTo(host.querySelector('.send-body-seg[data-seg="after"]'), false);
        } else {
            const seg = host.querySelector('.send-body-seg');
            const tn  = seg && seg.firstChild && seg.firstChild.nodeType === 3 ? seg.firstChild : null;
            if (tn && typeof snap.caretOffset === 'number') {
                host.focus({ preventScroll: true });
                const r = document.createRange();
                r.setStart(tn, Math.min(snap.caretOffset, tn.nodeValue.length));
                r.collapse(true);
                composerSetSelection(r);
            } else {
                composerCaretTo(seg, false);
            }
        }
        const sel = composerSelectionRange();
        composerSavedRange = sel ? sel.cloneRange() : null;
    }

    /** Czy dla kierunku `dir` jest co cofać/ponawiać po naszej stronie. */
    function hasStructHistory(dir) {
        return (dir === 'undo' ? structUndo : structRedo).length > 0;
    }

    /**
     * Ctrl+Z / Ctrl+Y w kompozytorze, gdy stos strukturalny nie jest
     * pusty: natywnie, a jeśli to nic nie zmieniło — migawka ze stosu.
     */
    function composerHistory(dir) {
        if (structBusy) return;
        if (!composerHost()) return;
        structBusy = true;
        try {
            const bodyBefore = readComposerBody();
            composerExec(dir);
            const bodyAfter = composerHost() ? readComposerBody() : bodyBefore;
            if (bodyAfter !== bodyBefore) return;   // natywne cofnięcie zadziałało

            const from = (dir === 'undo') ? structUndo : structRedo;
            const to   = (dir === 'undo') ? structRedo : structUndo;
            if (from.length === 0) return;
            const snap = from.pop();
            to.push(structSnapshot());
            restoreStructSnapshot(snap);
        } finally {
            structBusy = false;
        }
    }

    /** execCommand z flagą, żeby własne beforeinput nie łapał samego siebie. */
    function composerExec(cmd, value) {
        composerCmdBusy = true;
        try {
            return document.execCommand(cmd, false, value);
        } catch (err) {
            return false;
        } finally {
            composerCmdBusy = false;
        }
    }

    function composerSetSelection(range) {
        const sel = window.getSelection();
        if (!sel) return;
        sel.removeAllRanges();
        sel.addRange(range);
    }

    /** Aktualne zaznaczenie, o ile leży w hoście. */
    function composerSelectionRange() {
        const sel = window.getSelection();
        const host = composerHost();
        if (!sel || sel.rangeCount === 0 || !host) return null;
        const r = sel.getRangeAt(0);
        return host.contains(r.commonAncestorContainer) ? r : null;
    }

    function composerRangeTouchesIsland(range) {
        const isl = composerIsland();
        return !!(isl && range && range.intersectsNode(isl));
    }

    /** Punkt tuż przed wyspą: koniec tekstu poprzedzającego (przed jego `<br>`). */
    function composerPointBeforeIsland(isl) {
        const p = isl.previousSibling;
        if (p && p.nodeType === 1 && p.classList.contains('send-body-seg')) {
            let n = p.childNodes.length;
            if (n > 0 && p.lastChild.nodeName === 'BR') n--;
            return [p, n];
        }
        return [isl.parentNode, Array.prototype.indexOf.call(isl.parentNode.childNodes, isl)];
    }

    /** Punkt tuż za wyspą: początek tekstu następującego po niej. */
    function composerPointAfterIsland(isl) {
        const nx = isl.nextSibling;
        if (nx && nx.nodeType === 1 && nx.classList.contains('send-body-seg')) return [nx, 0];
        return [isl.parentNode, Array.prototype.indexOf.call(isl.parentNode.childNodes, isl) + 1];
    }

    /**
     * Kasuje z zaznaczenia wszystko POZA wyspą. Zaznaczenie obejmujące
     * tabelę (Ctrl+A, Delete / wpisanie znaku / wklejenie) dzielimy na
     * część przed i część za wyspą i usuwamy je osobno — przeglądarka
     * sama usunęłaby wyspę razem z markerami logiki. Tabela znika tylko
     * przez własny tryb edycji (kasowanie jej tekstu), nigdy przez
     * Backspace / Delete / zaznaczenie. Po wszystkim kursor jest zwinięty
     * w miejscu, gdzie zaczynało się zaznaczenie.
     */
    function composerDeleteAroundIsland(range) {
        const isl = composerIsland();
        if (!isl) return;
        const bp = composerPointBeforeIsland(isl);
        const ap = composerPointAfterIsland(isl);
        const pieces = [];
        if (range.comparePoint(bp[0], bp[1]) === 0) {
            const r1 = range.cloneRange();
            r1.setEnd(bp[0], bp[1]);
            if (!r1.collapsed) pieces.push(r1);
        }
        if (range.comparePoint(ap[0], ap[1]) === 0) {
            const r2 = range.cloneRange();
            r2.setStart(ap[0], ap[1]);
            if (!r2.collapsed) pieces.push(r2);
        }
        // Najpierw późniejsza część, żeby offsety wcześniejszej zostały ważne.
        pieces.reverse().forEach(function (p) {
            composerSetSelection(p);
            composerExec('delete');
        });
        if (pieces.length === 0) {
            const c = document.createRange();
            c.setStart(ap[0], ap[1]);
            c.collapse(true);
            composerSetSelection(c);
        }
    }

    /** Czy kursor (zwinięty) stoi tuż przy wyspie od strony `dir` ('back' / 'fwd')? */
    function composerCaretAdjacentToIsland(range, dir) {
        const isl = composerIsland();
        if (!isl || !range || !range.collapsed) return false;
        const between = document.createRange();
        if (dir === 'back') {
            const ap = composerPointAfterIsland(isl);
            const mark = document.createRange();
            mark.setStart(ap[0], ap[1]);
            mark.collapse(true);
            if (mark.compareBoundaryPoints(Range.START_TO_START, range) > 0) return false;  // kursor przed wyspą
            between.setStart(ap[0], ap[1]);
            between.setEnd(range.startContainer, range.startOffset);
        } else {
            const bp = composerPointBeforeIsland(isl);
            const mark = document.createRange();
            mark.setStart(bp[0], bp[1]);
            mark.collapse(true);
            if (mark.compareBoundaryPoints(Range.START_TO_START, range) < 0) return false;  // kursor za wyspą
            between.setStart(range.startContainer, range.startOffset);
            between.setEnd(bp[0], bp[1]);
        }
        return between.toString() === '';
    }

    /** Czy zaznaczenie obejmuje całą zawartość hosta (tekst + tabelę)? */
    function composerWholeSelected(range) {
        const host = composerHost();
        if (!host || !range || range.collapsed) return false;
        const isl = composerIsland();
        const hasContent = function (r) {
            if (isl && r.intersectsNode(isl)) return true;
            return r.toString().replace(/[\s\u200b]/g, '') !== '';
        };
        const pre = document.createRange();
        pre.setStart(host, 0);
        pre.setEnd(range.startContainer, range.startOffset);
        const post = document.createRange();
        post.setStart(range.endContainer, range.endOffset);
        post.setEnd(host, host.childNodes.length);
        return !hasContent(pre) && !hasContent(post);
    }

    /** Wstawia nową linię tak samo przy Enterze i przy wklejaniu. */
    function composerBreak() {
        if (!composerExec('insertLineBreak')) composerExec('insertText', '\n');
    }

    /** Ostatnia deska ratunku, gdy execCommand nie działa: ręczny splice (bez undo). */
    function composerManualInsert(text) {
        const r = composerSelectionRange();
        if (!r) return;
        r.deleteContents();
        const node = document.createTextNode(text);
        r.insertNode(node);
        r.setStartAfter(node);
        r.collapse(true);
        composerSetSelection(r);
        $(composerHost()).trigger('input');
    }

    /**
     * Wstawia czysty tekst w miejscu kursora hosta (wklejanie, drop,
     * wstawianie zmiennych). Zaznaczenie obejmujące tabelę najpierw
     * przycinamy do tekstu poza nią.
     */
    function composerInsertText(text) {
        const range = composerSelectionRange();
        if (range && composerRangeTouchesIsland(range)) composerDeleteAroundIsland(range);
        if (!composerExec('insertText', text)) composerManualInsert(text);
    }

    function composerCaretTo(segEl, atEnd) {
        const host = composerHost();
        if (!host || !segEl) return;
        host.focus({ preventScroll: true });
        const r = document.createRange();
        r.selectNodeContents(segEl);
        if (atEnd) {
            const n = segEl.childNodes.length;
            const trailingBr = n > 0 && segEl.lastChild.nodeName === 'BR';
            r.setStart(segEl, trailingBr ? n - 1 : n);
        }
        r.collapse(!atEnd);
        composerSetSelection(r);
    }

    /**
     * Przerysowuje host z podanego body i synchronizuje ukryte źródło
     * prawdy. Używane tylko przy zmianie struktury (nowy szablon,
     * w body pojawiły się markery) — nie przy zwykłym pisaniu.
     */
    function rebuildComposerSegments(body) {
        $('#send-email-body').val(body);
        composerSavedRange = null;
        const $old = $('#send-body-host');
        if ($old.length === 0) return;
        $old.replaceWith(buildComposerSegmentsHtml(AtteItemsFormat.splitBody(body)));
        renderComposerPreview();
        autoGrowComposer();
    }

    /**
     * Włącza / wyłącza edycję tabeli. Samo przestawienie atrybutu
     * data-mode (CSS chowa to, co do trybu nie należy) — pola tekstowe
     * nie są ruszane.
     */
    function setTableEdit(on) {
        state.tableEdit = !!on;
        const $block = $('#send-body-table-block');
        if ($block.length === 0) return;
        $block.attr('data-mode', state.tableEdit ? 'edit' : 'view');
        const label = state.tableEdit ? 'Zakończ edycję tabeli' : 'Edytuj tabelę';
        $('#send-body-table-toggle')
            .attr({ title: label, 'aria-label': label,
                    'aria-pressed': state.tableEdit ? 'true' : 'false' });
        if (state.tableEdit) {
            const ta = document.getElementById('send-body-table');
            autoGrowTextarea(ta);
            if (ta) ta.focus();
        } else {
            renderComposerPreview();
        }
    }

    /**
     * Renderuje podgląd TABELI pozycji w bloku tabeli (tekst wiadomości
     * ma własne pola, więc nie jest tu powtarzany). Tabela pochodzi z
     * tej samej ścieżki co „Kopiuj pozycje" i „Kopiuj wiadomość":
     * parsujemy pipe-table z body, więc edycje komórek (5000→5500) i
     * nagłówków („Price"→„Prices") są widoczne od razu.
     *
     * Blok bez żadnego poprawnego wiersza dostaje podpowiedź zamiast
     * kanonicznej tabeli z dokumentu — schowek w takim przypadku też
     * pomija tabelę, więc podgląd nie może udawać czegoś innego.
     */
    function renderComposerPreview() {
        try {
            const $view = $('#send-email-body-preview');
            if ($view.length === 0) return;   // brak tabeli w tej wiadomości
            const body = ($('#send-email-body').val() || '').toString().replace(/\r\n?/g, '\n');
            const d    = state.doc || {};
            const ctx  = { documentNumber: d.number || (d.id ? '#' + d.id : '') };
            const fromBody = AtteItemsFormat.clipboardItemsFromBody(body, ctx);
            if (!fromBody) {
                $view.html('<span class="send-body-table-empty">'
                    + 'Brak poprawnych wierszy tabeli — nie trafi do skopiowanej wiadomości. '
                    + 'Kliknij ołówek, żeby ją poprawić.'
                + '</span>');
                return;
            }
            $view.html(AtteItemsFormat.htmlTable(fromBody.parsed, ctx, fromBody.headers));
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
        // Nowy szablon zastępuje całą wiadomość — stara historia
        // operacji na tabeli nie ma już do czego wracać.
        clearStructHistory();
        state.emailSubjectEdited = null;
        state.emailBodyEdited    = null;
        $('#send-email-reset').addClass('is-hidden');

        // Temat + treść z nowego szablonu. Podmieniamy wartości pól, a
        // nie cały markup — przyciski „Kopiuj temat" / „Kopiuj treść
        // wiadomości" i tak czytają .val() dopiero przy kliknięciu,
        // więc kopiują aktualną wersję bez żadnej dodatkowej pracy.
        // Pola treści (przed tabelą / tabela / po tabeli) przebudowujemy
        // od nowa z nowego body — nowy szablon może mieć inny układ, a
        // tabela wraca do podglądu.
        $('#send-email-subject').val(buildEmailSubject());
        state.tableEdit = false;
        rebuildComposerSegments(buildEmailBody());

        refreshTplBadge();

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
        { group: 'Dokument', name: 'vendor_document_number', label: 'Numer dokumentu u dostawcy' },
        { group: 'Zapytanie', name: 'rfq_number',       label: 'Numer powiązanego zapytania' },
        { group: 'Zapytanie', name: 'rfq_vendor_document_number', label: 'Numer zapytania u dostawcy' },
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

        // Etykieta to <span>, nie <label for>: opisuje CAŁY blok treści
        // (kilka pól), a każde pole ma własne aria-label.
        //
        // „Wstaw zmienną" jest zawsze aktywne — tekst wiadomości jest
        // edytowalny od razu. Wartość ląduje w ostatnio używanym polu
        // (tekst przed tabelą, po tabeli albo tabela w trybie edycji).
        return '<div class="send-composer-body-bar">'
            + copyBtnHtml({ full: true, label: 'Kopiuj treść wiadomości',
                            title: 'Kopiuj treść wiadomości do schowka',
                            extraClass: 'send-composer-row-copy mr-2' })
            + '<span class="send-composer-key send-composer-body-label">Treść</span>'
            + '<div class="send-composer-body-tools">'
                + '<div class="dropdown send-var-dropdown">'
                    // Cichy przycisk (btn-link) — przygaszony, pełny
                    // kontrast przy hover / focus-within kompozytora
                    // (CSS). Zostaje zwykłym, fokusowalnym <button>.
                    + '<button type="button" class="btn btn-link btn-sm send-var-btn"'
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
            + '</div>'
        + '</div>';
    }

    /**
     * Gdzie trafi „Wstaw zmienną": do textarei tabeli (tylko gdy ostatnio
     * w niej pisano i tabela jest w trybie edycji) albo do hosta z tekstem
     * wiadomości. Kliknięcie przycisku w menu zabiera fokus, więc
     * zapamiętujemy ostatnie pole przy `focusin`, a zaznaczenie w hoście
     * przy `selectionchange` (composerSavedRange).
     */
    let lastComposerFieldId = null;

    function composerInsertTarget() {
        const ta = document.getElementById('send-body-table');
        if (ta && lastComposerFieldId === 'send-body-table' && state.tableEdit === true) {
            return { kind: 'table', el: ta };
        }
        return { kind: 'host', el: composerHost() };
    }

    /**
     * Wstawia tekst w hoście: w ostatnim zapamiętanym miejscu kursora
     * (a gdy go nie ma — na końcu tekstu przed tabelą). Przez
     * execCommand('insertText'), więc Ctrl+Z cofa wstawkę natywnie.
     * `asBlock` (dla {items} i innych wieloliniowych wartości) dokłada
     * nowe linie z obu stron, żeby blok stanął osobno.
     */
    function insertIntoHost(text, asBlock) {
        const host = composerHost();
        if (!host) return;
        let range = null;
        if (composerSavedRange
            && host.contains(composerSavedRange.startContainer)
            && host.contains(composerSavedRange.endContainer)) {
            range = composerSavedRange.cloneRange();
        }
        host.focus({ preventScroll: true });
        if (!range) {
            const first = host.querySelector('.send-body-seg');
            range = document.createRange();
            if (first) {
                range.selectNodeContents(first);
                const n = first.childNodes.length;
                range.setStart(first, (n > 0 && first.lastChild.nodeName === 'BR') ? n - 1 : n);
                range.collapse(true);
            } else {
                range.selectNodeContents(host);
                range.collapse(false);
            }
        }
        composerSetSelection(range);
        if (composerRangeTouchesIsland(range)) {
            composerDeleteAroundIsland(range);
            range = composerSelectionRange() || range;
        }

        let chunk = String(text);
        if (asBlock) {
            // Tekst przed / za kursorem liczymy w obrębie bieżącej części
            // (nie całego hosta — tabela między nimi nie jest „tekstem").
            let segEl = range.startContainer;
            while (segEl && segEl !== host
                   && !(segEl.nodeType === 1 && segEl.classList.contains('send-body-seg'))) {
                segEl = segEl.parentNode;
            }
            if (segEl && segEl !== host) {
                const pre = document.createRange();
                pre.selectNodeContents(segEl);
                pre.setEnd(range.startContainer, range.startOffset);
                const post = document.createRange();
                post.selectNodeContents(segEl);
                post.setStart(range.endContainer, range.endOffset);
                const preText = pre.toString();
                const postText = post.toString();
                if (preText !== '' && preText.charAt(preText.length - 1) !== '\n') chunk = '\n' + chunk;
                if (postText !== '' && postText.charAt(0) !== '\n') chunk = chunk + '\n';
            }
        }
        if (!composerExec('insertText', chunk)) composerManualInsert(chunk);
    }

    /**
     * „Wstaw zmienną → Lista pozycji" przy wiadomości, która JUŻ ma
     * tabelę. Model treści zna dokładnie jedną wyspę (splitBody /
     * joinBody), więc zamiast wklejać drugą kopię jako surowy tekst
     * „a | b | c", PRZENOSIMY istniejącą tabelę w miejsce kursora.
     *
     * Tekst tabeli bierzemy z #send-body-table (źródło tabeli w obu
     * trybach, więc edycje komórek operatora zostają). Miejsce kursora
     * wyznacza unikalny znacznik wstawiony zwykłą ścieżką
     * insertIntoHost() — ta sama logika zapamiętanego zaznaczenia,
     * przycinania zaznaczenia sięgającego wyspy i fallbacku „koniec
     * tekstu przed tabelą". Potem czytamy host jako [przed, po],
     * rozcinamy go na znaczniku i składamy body na nowo przez joinBody().
     *
     * Host jest przebudowywany (rebuildComposerSegments), więc Ctrl+Z
     * nie cofa tej operacji — tak samo jak przy zmianie szablonu.
     * Tabela wraca do podglądu (jak po applyDraftPick), a kursor staje
     * na początku tekstu za tabelą.
     */
    function moveItemsTableToCaret() {
        const host = composerHost();
        const ta   = document.getElementById('send-body-table');
        if (!host || !ta) return;
        const tableText = String(ta.value || '').replace(/\r\n?/g, '\n');

        // Migawka PRZED wstawieniem znacznika — Ctrl+Z wraca do tabeli
        // w dotychczasowym miejscu (patrz composerHistory).
        pushStructUndo(structSnapshot());

        const marker = 'ATTEITEMSCARET' + Date.now().toString(36)
            + Math.random().toString(36).slice(2, 8) + 'X';
        insertIntoHost(marker, false);

        const parts = AtteItemsFormat.domToTextParts(host, composerIsIslandNode);
        const p0 = parts[0] || '';
        const p1 = parts.length > 1 ? (parts[1] || '') : '';
        const trimEnd   = function (s) { return String(s).replace(/(?:\n[ \t]*)+$/, ''); };
        const trimStart = function (s) { return String(s).replace(/^(?:[ \t]*\n)+/, ''); };

        let before, after;
        const i0 = p0.indexOf(marker);
        const i1 = p1.indexOf(marker);
        if (i0 !== -1) {
            // Kursor w tekście przed tabelą: reszta tego tekstu + cały
            // dotychczasowy tekst za tabelą schodzą pod tabelę.
            before = p0.slice(0, i0);
            const rest = trimEnd(p0.slice(i0 + marker.length));
            after = (rest.trim() !== '')
                ? rest + (p1.trim() !== '' ? '\n\n' + trimStart(p1) : '')
                : p1;
        } else if (i1 !== -1) {
            // Kursor w tekście za tabelą: dotychczasowy tekst przed
            // tabelą + początek tekstu za nią wchodzą nad tabelę.
            const head = trimEnd(p0);
            const pre  = p1.slice(0, i1);
            before = (head.trim() !== '' ? head + '\n\n' : '') + trimStart(pre);
            after  = p1.slice(i1 + marker.length);
        } else {
            // Znacznik się nie wstawił (np. brak zaznaczenia w hoście) —
            // tabela zostaje na końcu tekstu przed nią, jak była.
            before = p0;
            after  = p1;
        }
        // Na wszelki wypadek: znacznik nie może przeżyć w treści.
        before = before.split(marker).join('');
        after  = trimEnd(after.split(marker).join(''));

        const body = AtteItemsFormat.joinBody(before, tableText, after);
        state.emailBodyEdited = body;
        $('#send-email-reset').removeClass('is-hidden');
        state.tableEdit = false;
        lastComposerFieldId = 'send-body-host';
        rebuildComposerSegments(body);

        const hostEl = composerHost();
        composerCaretTo(hostEl && hostEl.querySelector('.send-body-seg[data-seg="after"]'), false);
    }

    /**
     * Przycisk „Usuń tabelę z wiadomości" na wyspie. Jedyna droga
     * usunięcia tabeli — Backspace / Delete / wycinanie dalej jej nie
     * ruszają (patrz beforeinput.composer / cut.composer).
     *
     * Tekst przed i za tabelą bierzemy z ŻYWEGO hosta (readComposerBody
     * + splitBody), nie ze state — operator mógł go właśnie edytować.
     * Nowe body = przed + pusta linia + po, bez markerów (splitBody da
     * hasTable: false), więc host przebudowuje się jako zwykły tekst,
     * a „Wstaw zmienną → Lista pozycji" idzie ścieżką „bez wyspy" i
     * buduje tabelę na nowo. Kursor staje tam, gdzie była tabela.
     */
    function removeItemsTable() {
        if (!composerHost() || !composerIsland()) return;
        const seg = AtteItemsFormat.splitBody(readComposerBody());
        if (!seg.hasTable) return;

        // Migawka do Ctrl+Z — przywraca tabelę z edycjami komórek
        // w tym samym miejscu (patrz composerHistory).
        pushStructUndo(structSnapshot());

        const before = String(seg.before).replace(/(?:\n[ \t]*)+$/, '');
        const after  = String(seg.after).replace(/^(?:[ \t]*\n)+/, '').replace(/(?:\n[ \t]*)+$/, '');
        const hasBefore = before.trim() !== '';
        const hasAfter  = after.trim() !== '';
        const body = (hasBefore && hasAfter)
            ? before + '\n\n' + after
            : (hasBefore ? before : after);

        state.emailBodyEdited = body;
        $('#send-email-reset').removeClass('is-hidden');
        state.tableEdit = false;
        lastComposerFieldId = 'send-body-host';
        rebuildComposerSegments(body);

        // Kursor: koniec tekstu przed tabelą albo — gdy go nie było —
        // początek tekstu za nią (offset 0). Zapisujemy go też jako
        // composerSavedRange, żeby „Wstaw zmienną" od razu trafiło w to
        // samo miejsce, nawet bez kliknięcia w treść.
        const host  = composerHost();
        const segEl = host && host.querySelector('.send-body-seg');
        if (!host || !segEl) return;
        host.focus({ preventScroll: true });
        const r = document.createRange();
        const tn = (segEl.firstChild && segEl.firstChild.nodeType === 3) ? segEl.firstChild : null;
        if (tn) {
            r.setStart(tn, hasBefore ? Math.min(before.length, tn.nodeValue.length) : 0);
        } else {
            r.setStart(segEl, 0);
        }
        r.collapse(true);
        composerSetSelection(r);
        composerSavedRange = r.cloneRange();
    }

    /**
     * Buduje payload CAŁEGO BODY dla przycisków kopiujących treść maila
     * („Kopiuj wiadomość" w stopce + ikona przy wierszu „Treść").
     * Zwraca OBIE wersje:
     *   - `html` — body z `<table>` inline (zamiast pipe-text między
     *     markerami). Gmail renderuje jako prawdziwą tabelę.
     *   - `plainText` — body z pipe-text (markery usunięte). Fallback
     *     dla przeglądarki bez ClipboardItem / dla paste-as-plain-text.
     *
     * Edycje operatora w komórkach tabeli zachowane w obu wersjach:
     * parser pipe-table → htmlTable() bierze aktualną zawartość textarei.
     */
    function buildComposerBodyPayload(bodyOverride) {
        const body = (typeof bodyOverride === 'string')
            ? bodyOverride
            : ($('#send-email-body').val() || '').toString();
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

    /** Plain-text fallback dla „Kopiuj wiadomość" — używane też przez
     * inne ścieżki, które nie chcą schowka HTML (np. „Otwórz w mailu"). */
    function buildComposerBodyText() {
        return buildComposerBodyPayload().plainText;
    }

    function renderStep4() {
        const d = state.doc;
        if (!d) return;
        const isRespondMode = (DOC_TYPE === 'rfq' && d.state === 'sent');

        const supplierCount = state.selectedSuppliers.length;
        const selectedSet   = new Set(state.selectedSuppliers);
        const selectedList  = (d.suppliers || []).filter(function (s) { return selectedSet.has(s.id); });

        // Brak kontaktów → blokuj commit + alert z CTA do kroku 2.
        const noSuppliersHtml = (supplierCount === 0)
            ? '<div class="alert alert-danger">'
                + 'Brak kontaktów — wróć do kroku 2.'
                + '<button type="button" class="btn btn-outline-danger btn-sm ml-3" data-go="2">'
                    + '← Wróć do kroku 2'
                + '</button>'
              + '</div>'
            : '';

        // Data ustawiona wstecz w kroku 1 — krótka notka obok przycisku
        // commita na sticky barze (#send-commit-note w PHP), widoczna
        // tylko gdy ustawiona.
        const $commitNote = $('#send-commit-note');
        if (state.backdatedAt) {
            $commitNote
                .html('<i class="bi bi-clock-history" aria-hidden="true"></i> '
                    + (isRespondMode ? 'Data odpowiedzi: ' : 'Data wysłania: ')
                    + '<strong>' + escapeHtml(state.backdatedAt) + '</strong>')
                .attr('title', 'Data ustawiona ręcznie w kroku 1 — nadpisze bieżący znacznik czasu.')
                .removeClass('d-none');
        } else {
            $commitNote.empty().removeAttr('title').addClass('d-none');
        }

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

        // „Szczegóły" zachowują stan rozwinięcia między renderami (krok 4
        // przerysowuje się m.in. po „Przywróć szablon").
        const $body = $('#send-step-4-body');
        const detailsWasOpen = $body.find('#send-step-4-details-body').hasClass('show');
        const detailsHtml = buildStep4DetailsHtml(selectedList, detailsWasOpen);

        // Układ panelu (patrz komentarz nad sekcją Step 4):
        //   bloker → pasek kontekstu → [E-mail + Szczegóły | PDF]
        // Tryb respond: bez kompozytora i PDF — nic nie jest wysyłane.
        // Sticky action bar zostaje poza tym kontenerem (PHP).
        let mainHtml;
        if (isRespondMode) {
            mainHtml = '<p class="send-respond-note">'
                    + '<i class="bi bi-info-circle" aria-hidden="true"></i> '
                    + 'Zapytanie jest już wysłane — zapisujesz tylko odpowiedź dostawcy.'
                + '</p>'
                + detailsHtml;
        } else {
            mainHtml = '<div class="send-review-layout">'
                + '<div class="send-review-main">'
                    + buildStep4ComposerHtml()
                    + detailsHtml
                + '</div>'
                + '<aside class="send-review-aside" aria-label="PDF">'
                    + buildStep4PdfHtml()
                + '</aside>'
            + '</div>';
        }

        $body.html(
            noSuppliersHtml
            + buildStep4ContextHtml()
            + mainHtml
        );

        // Tabela w treści dostaje podgląd od razu po wstrzyknięciu
        // markupu — jest domyślnie na wierzchu, więc musi mieć zawartość
        // przed pierwszym malowaniem, a nie po pierwszej edycji. Pola
        // tekstu dostają wysokość dopasowaną do treści. W trybie respond
        // kompozytora nie ma — obie funkcje same sprawdzają obecność DOM.
        lastComposerFieldId = null;
        composerSavedRange = null;
        // Host jest nowy — migawki operacji na tabeli odnoszą się do
        // poprzedniego renderu (m.in. po „Przywróć szablon").
        clearStructHistory();
        renderComposerPreview();
        autoGrowComposer();

        // Chevron zwijanej karty „Szczegóły".
        wireStep4Chevrons($body);

        // ---- Picker szablonu wiadomości ---------------------------
        // Init po wstrzyknięciu markupu — plugin musi zobaczyć <select>
        // w drzewie, żeby policzyć szerokość dla data-width="100%".
        // size: 8 nie pozwala rosnącemu katalogowi zasłonić kompozytora;
        // liveSearch — ten sam wybór co picker preferencji na
        // /admin/purchase/vendors/edit (vendor-edit-view.js:399).
        // Picker siedzi przy prawej krawędzi nagłówka karty „E-mail",
        // więc menu otwiera się do prawej, gdy brak miejsca (auto).
        const $draftPicker = $('#send-draft-picker');
        if ($draftPicker.length > 0 && typeof $draftPicker.selectpicker === 'function') {
            $draftPicker.selectpicker({
                style: 'btn-light border',
                dropdownAlignRight: 'auto',
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
        // Treść to jeden edytowalny host (`#send-body-host`) z tekstem i
        // wyspą-tabelą. Każda edycja „przypina" wersję operatora w state,
        // żeby przetrwała ponowny render panelu; „Przywróć szablon" czyści
        // przypięcie i renderuje krok 4 od zera.
        //
        // Pisanie NIE przebudowuje hosta (fokus, kursor i Ctrl+Z zostają
        // natywne): serializujemy DOM do ukrytego body z markerami, a
        // podgląd tabeli odświeżamy tylko wtedy, gdy edytowana jest sama
        // tabela. Jedyny wyjątek to chwila, gdy w wiadomości bez tabeli
        // pojawiają się markery (np. „Wstaw zmienną → Lista pozycji") —
        // wtedy host trzeba zbudować od nowa, żeby tabela dostała wyspę.
        $body.off('input.composer')
            .on('input.composer', '#send-body-host', function (e) {
                const inIsland = inComposerIsland(e.target);
                // Z wyspy interesuje nas wyłącznie textarea tabeli.
                if (inIsland && e.target.id !== 'send-body-table') return;
                if (inIsland) autoGrowTextarea(e.target);
                // Nowa edycja operatora (nie nasze undo/redo) zaczyna
                // nową gałąź historii — strukturalne redo traci sens.
                if (!structBusy) structRedo.length = 0;

                const hiddenBody = ($('#send-email-body').val() || '').toString();
                const combined = readComposerBody();
                state.emailBodyEdited = combined;
                $('#send-email-reset').removeClass('is-hidden');

                // Wyspa zniknęła z DOM-u (przeglądarka ją usunęła mimo
                // blokad) — przywracamy tabelę z ostatniego dobrego body,
                // żeby nie zgubić pozycji wiadomości. Tekst, który został,
                // ląduje przed tabelą.
                if (!composerIsland()) {
                    const prev = AtteItemsFormat.splitBody(hiddenBody);
                    if (prev.hasTable) {
                        const restored = AtteItemsFormat.joinBody(combined, prev.table, '');
                        state.emailBodyEdited = restored;
                        rebuildComposerSegments(restored);
                        const hostEl = composerHost();
                        composerCaretTo(hostEl && hostEl.querySelector('.send-body-seg[data-seg="before"]'), true);
                        return;
                    }
                }

                if (!composerIsland() && AtteItemsFormat.splitBody(combined).hasTable) {
                    rebuildComposerSegments(combined);
                    const hostEl = composerHost();
                    composerCaretTo(hostEl && hostEl.querySelector('.send-body-seg[data-seg="after"]'), false);
                    return;
                }
                $('#send-email-body').val(combined);
                if (inIsland) renderComposerPreview();
            });

        // Ostatnie pole, w którym operator pisał (cel „Wstaw zmienną").
        $body.off('focusin.composer')
            .on('focusin.composer', '#send-body-host', function (e) {
                if (e.target.id === 'send-body-table') {
                    lastComposerFieldId = 'send-body-table';
                } else if (!inComposerIsland(e.target)) {
                    lastComposerFieldId = 'send-body-host';
                }
            });
        // Pozycja kursora / zaznaczenia w hoście — klik w „Wstaw zmienną"
        // zabiera fokus, więc miejsce wstawienia pamiętamy z zaznaczenia.
        $(document).off('selectionchange.composer')
            .on('selectionchange.composer', function () {
                const r = composerSelectionRange();
                if (r && !inComposerIsland(r.startContainer)) composerSavedRange = r.cloneRange();
            });

        // ---- Host: tylko czysty tekst, tabela nietykalna ------------
        $body.off('beforeinput.composer')
            .on('beforeinput.composer', '#send-body-host', function (e) {
                if (composerCmdBusy || inComposerIsland(e.target)) return;
                const ev = e.originalEvent;
                const type = (ev && ev.inputType) || '';

                // Cofnij / Ponów spoza klawiatury (menu Edycja, gest na
                // mobile). Skróty klawiszowe obsługuje keydown.composerUndo
                // i robi preventDefault, więc tu nie dochodzą — brak
                // podwójnej obsługi. Pusty stos strukturalny → natywnie.
                if (type === 'historyUndo' || type === 'historyRedo') {
                    const dir = (type === 'historyUndo') ? 'undo' : 'redo';
                    if (structBusy || !hasStructHistory(dir)) return;
                    e.preventDefault();
                    composerHistory(dir);
                    return;
                }

                // Żadnego formatowania (Ctrl+B/I/U, menu kontekstowe, listy…).
                if (type.indexOf('format') === 0
                    || type === 'insertOrderedList' || type === 'insertUnorderedList'
                    || type === 'insertHorizontalRule' || type === 'insertLink') {
                    e.preventDefault();
                    return;
                }

                // Enter → zawsze jedna spójna nowa linia (bez <div> / <p>).
                if (type === 'insertParagraph' || type === 'insertLineBreak') {
                    e.preventDefault();
                    const r = composerSelectionRange();
                    if (r && composerRangeTouchesIsland(r)) composerDeleteAroundIsland(r);
                    composerBreak();
                    return;
                }

                const isDelete = (type.indexOf('delete') === 0);
                const isInsert = (type === 'insertText' || type === 'insertReplacementText'
                                  || type === 'insertFromDrop');
                if (!isDelete && !isInsert) return;

                // Zakres, którego dotyczy operacja: dla kasowania —
                // targetRange przeglądarki (np. całe słowo), inaczej zaznaczenie.
                let range = null;
                if (isDelete && ev.getTargetRanges) {
                    const tr = ev.getTargetRanges();
                    if (tr.length > 0) {
                        try {
                            range = document.createRange();
                            range.setStart(tr[0].startContainer, tr[0].startOffset);
                            range.setEnd(tr[0].endContainer, tr[0].endOffset);
                        } catch (err) {
                            range = null;
                        }
                    }
                }
                if (!range) range = composerSelectionRange();
                if (!range) return;

                if (composerRangeTouchesIsland(range)) {
                    // Operacja sięga tabeli: robimy ją tylko na tekście
                    // poza tabelą (patrz composerDeleteAroundIsland).
                    e.preventDefault();
                    composerDeleteAroundIsland(range);
                    if (isInsert && ev.data) composerExec('insertText', ev.data);
                    return;
                }
                // Backspace / Delete tuż przy tabeli nie mogą jej skasować.
                if (isDelete) {
                    const sel = composerSelectionRange();
                    if (sel && sel.collapsed) {
                        if (type.indexOf('Backward') !== -1 && composerCaretAdjacentToIsland(sel, 'back')) {
                            e.preventDefault();
                        } else if (type.indexOf('Forward') !== -1 && composerCaretAdjacentToIsland(sel, 'fwd')) {
                            e.preventDefault();
                        }
                    }
                }
            });

        $body.off('keydown.composer')
            .on('keydown.composer', '#send-body-host', function (e) {
                if (inComposerIsland(e.target)) return;
                // Skróty formatowania — beforeinput je też blokuje, ale nie
                // każda przeglądarka go odpala dla skrótów klawiszowych.
                if ((e.ctrlKey || e.metaKey) && !e.altKey) {
                    const k = String(e.key || '').toLowerCase();
                    if (k === 'b' || k === 'i' || k === 'u') e.preventDefault();
                }
            });

        // Ctrl/Cmd+Z, Ctrl+Y, Ctrl/Cmd+Shift+Z — cofanie operacji na
        // tabeli (patrz composerHistory). Delegacja na całą kartę
        // „E-mail", bo tuż po kliknięciu kosza / „Wstaw zmienną" fokus
        // może stać na przycisku, a nie w treści. Pola formularza
        // (temat, textarea tabeli, wyszukiwarka pickera) zostają przy
        // natywnym cofaniu. Gdy nasz stos jest pusty, nic nie
        // przechwytujemy — zwykłe pisanie cofa się natywnie.
        $body.off('keydown.composerUndo')
            .on('keydown.composerUndo', '.send-composer', function (e) {
                if (!(e.ctrlKey || e.metaKey) || e.altKey) return;
                const t = e.target;
                const tag = t && t.tagName ? String(t.tagName).toUpperCase() : '';
                if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
                const k = String(e.key || '').toLowerCase();
                let dir = null;
                if (k === 'z' || e.code === 'KeyZ') {
                    dir = e.shiftKey ? 'redo' : 'undo';
                } else if ((k === 'y' || e.code === 'KeyY') && !e.shiftKey) {
                    dir = 'redo';
                }
                if (dir === null || structBusy || !hasStructHistory(dir)) return;
                e.preventDefault();
                composerHistory(dir);
            });

        // Wklejanie: zawsze text/plain (bez formatowania, stylów, obrazków).
        $body.off('paste.composer')
            .on('paste.composer', '#send-body-host', function (e) {
                if (inComposerIsland(e.target)) return;      // textarea tabeli: natywnie
                const cd = e.originalEvent && e.originalEvent.clipboardData;
                if (!cd) return;
                e.preventDefault();
                const text = (cd.getData('text/plain') || '').replace(/\r\n?/g, '\n');
                if (text !== '') composerInsertText(text);
            });

        // Upuszczanie (drag & drop) tekstu / plików — blokujemy, żeby nic
        // nie wchodziło do hosta w innej postaci niż czysty tekst.
        $body.off('drop.composer')
            .on('drop.composer', '#send-body-host', function (e) {
                if (inComposerIsland(e.target)) return;
                e.preventDefault();
            });

        // Kopiowanie / wycinanie z hosta.
        //   • całe zaznaczenie (Ctrl+A) → ten sam payload co „Kopiuj
        //     treść maila": text/html (tabela inline) + text/plain, bez
        //     markerów, z edytowanymi nagłówkami / kolumnami tabeli;
        //   • zaznaczenie częściowe → natywna kopia (przyciski wyspy mają
        //     user-select: none i nie mają tekstu, więc nie wyciekają);
        //   • wycięcie sięgające tabeli: kopia do schowka, a potem
        //     kasujemy tylko tekst — tabela zostaje na miejscu.
        $body.off('copy.composer cut.composer')
            .on('copy.composer cut.composer', '#send-body-host', function (e) {
                if (inComposerIsland(e.target)) return;      // textarea tabeli: natywnie
                const range = composerSelectionRange();
                if (!range || range.collapsed) return;
                const cd = e.originalEvent && e.originalEvent.clipboardData;
                if (!cd) return;
                const isCut = (e.type === 'cut');

                if (composerWholeSelected(range)) {
                    // Treść bierzemy świeżo z DOM-u (też w trybie edycji tabeli).
                    const p = buildComposerBodyPayload(readComposerBody());
                    e.preventDefault();
                    cd.setData('text/html', p.html);
                    cd.setData('text/plain', p.plainText);
                    if (isCut) composerDeleteAroundIsland(range);
                    return;
                }
                if (!composerRangeTouchesIsland(range)) return;   // sam tekst: natywnie

                if (isCut) {
                    const holder = document.createElement('div');
                    holder.appendChild(range.cloneContents());
                    $(holder).find('[data-atte-ui], textarea, .send-body-table-editor').remove();
                    e.preventDefault();
                    cd.setData('text/html', holder.innerHTML);
                    cd.setData('text/plain', window.getSelection().toString());
                    composerDeleteAroundIsland(range);
                }
            });

        // ---- Edycja tabeli pozycji --------------------------------
        // Mały przycisk w rogu wyspy: ołówek → surowy tekst z kolumnami
        // „|", „Gotowe" → z powrotem wyrenderowana tabela. Tekst
        // wiadomości nie zależy od tego trybu.
        $body.off('click.composerTable')
            .on('click.composerTable', '#send-body-table-toggle', function (e) {
                e.preventDefault();
                e.stopPropagation();
                setTableEdit(state.tableEdit !== true);
            });

        // Kosz w rogu wyspy: usuwa tabelę z wiadomości, tekst zostaje.
        // Bez confirm() — „Przywróć szablon" i „Wstaw zmienną → Lista
        // pozycji" przywracają tabelę.
        $body.off('click.composerTableRemove')
            .on('click.composerTableRemove', '#send-body-table-remove', function (e) {
                e.preventDefault();
                e.stopPropagation();
                removeItemsTable();
            });

        // Zmiana szerokości okna zmienia zawijanie tekstu, więc wysokość
        // textarei tabeli trzeba policzyć od nowa (host rośnie sam).
        $(window).off('resize.composer').on('resize.composer', autoGrowComposer);

        $body.off('click.composerReset')
            .on('click.composerReset', '#send-email-reset', function (e) {
                e.preventDefault();
                state.emailSubjectEdited = null;
                state.emailBodyEdited    = null;
                renderStep4();
            });

        // „Kopiuj wiadomość" w stopce — własny handler, bo to
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

                // {items} niesie markery tabeli, a wiadomość może mieć
                // tylko JEDNĄ tabelę. Gdy już ją ma — przenosimy ją w
                // miejsce kursora (z edycjami komórek), zamiast wklejać
                // drugą kopię jako surowy tekst „a | b | c". Nigdy nie
                // wstawiamy pozycji do textarei tabeli: przy ostatnim
                // fokusie w tabeli liczy się zapamiętany kursor w tekście
                // (albo koniec tekstu przed tabelą). Bez tabeli markery
                // trafiają do tekstu, a input.composer buduje z nich wyspę.
                if (name === 'items') {
                    if (composerIsland() && document.getElementById('send-body-table')) {
                        moveItemsTableToCaret();
                    } else {
                        // Wstawienie tabeli do wiadomości bez tabeli też
                        // przebudowuje host (input.composer) — migawka,
                        // żeby Ctrl+Z ją cofnął.
                        pushStructUndo(structSnapshot());
                        insertIntoHost(text, true);
                    }
                    return;
                }

                const target = composerInsertTarget();
                if (!target.el) return;
                // Wartość wieloliniowa (np. komentarz) dostaje własne linie.
                const asBlock = (text.indexOf('\n') !== -1);
                if (target.kind === 'table') {
                    insertAtCursor($(target.el), text, asBlock);
                } else {
                    insertIntoHost(text, asBlock);
                }
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
        $commitBtn.html('<span class="spinner-border spinner-border-sm mr-2"></span>Zapisywanie…');

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
                // Zawsze stemplujemy placeholder PDF (prawdziwego PDF
                // jeszcze nie ma; przełącznik z kroku 3 został usunięty).
                generate_pdf: 1,
                // Data z kroku 3 ('' = brak / wyczyszczona) — zapisywana
                // razem ze zmianą stanu.
                expected_date: state.expectedDate || '',
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
                + (isRespondMode ? 'Zapisano odpowiedź dostawcy: ' : 'Oznaczono jako wysłane: ')
                + '<strong>' + escapeHtml(docNumber) + '</strong>. Stan: ' + stateBadge + '.'
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
