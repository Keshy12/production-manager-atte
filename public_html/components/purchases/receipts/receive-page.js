// Standalone receive page — /purchase/receipts/receive?po_id=N.
//
// Wyodrębnione z receipts-view.js, gdzie do tej pory formularz
// był panelem przełączanym d-none wewnątrz strony /purchase/receipts.
// Teraz jest samodzielną trasą: receive-page.php renderuje formularz
// i ustawia data-po-id + data-current-user na #receiptsReceiveView,
// ten plik je czyta i startuje ładowanie.
//
// Identyczny kontrakt z endpointami co poprzednio:
//   receipt-po-get.php   nagłówek + pozycje jednego zamówienia (POST)
//   receipt-create.php   zapis przyjęcia (POST)
//
// KREATOR (trzy kroki). Formularz nie pokazuje już wszystkiego naraz:
//   krok 1 — „Dokument przyjęcia": magazyn, nr dokumentu dostawcy, data,
//            komentarz. Domknięcie przyciskiem „Akceptuję" zwija kartę do
//            podsumowania i odblokowuje krok 2.
//   krok 2 — „Pozycje do przyjęcia": tabela. Do czasu akceptacji kroku 1
//            karta ma klasę .receipt-step-locked (pointer-events: none).
//   krok 3 — „Zapisz": pasek akcji. Przycisk zapisu odblokowuje się, gdy
//            krok 1 jest zaakceptowany ORAZ tabela ma poprawne ilości.
// Pasek kroków (#receiptStepper) jest rysowany z tego stanu, nie
// przestawiany ręcznie — patrz setStep()/syncStepper() niżej.
//
// Dostawa dzielona (PO w stanie partially_received): krok 1 zalicza się
// sam, a jego pola wypełnia `lastReceipt` z receipt-po-get.php — patrz
// applyLastReceipt().
// Po zapisie — prawdziwy reload na /purchase/receipts z query
// stringiem niosącym info o sukcesie; receipts-view.js czyta to
// przy starcie i wyświetla alert + podświetla nowy wiersz historii.

$(document).ready(function() {
    const ajaxBase = COMPONENTS_PATH + "/purchases/receipts/";

    // =====================================================================
    // Drobiazgi wspólne
    // =====================================================================

    function esc(str) {
        return $('<div>').text(str == null ? '' : String(str)).html();
    }

    // Escape do WARTOŚCI ATRYBUTU. esc() nie zamienia cudzysłowów, więc
    // nazwa dostawcy z apostrofem rozwalałaby atrybut data-vendor-name.
    function escAttr(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    // Alert trafia do #receiveAlertContainer — jedynego .alert-host na
    // tej stronie (lista i formularz nie współżyją, więc nie potrzeba
    // logiki "który kontener jest widoczny" jak w receipts-view.js).
    function showAlert(message, type) {
        if (!type) type = 'success';
        const html = '<div class="alert alert-' + type + ' alert-dismissible fade show" role="alert">'
            + message
            + '<button type="button" class="close" data-dismiss="alert"><span aria-hidden="true">&times;</span></button>'
            + '</div>';
        $('#receiveAlertContainer').html(html);
        $('html, body').animate({ scrollTop: 0 }, 300);
    }

    function getAjax(endpoint, data) {
        // POST jest wymagany przez .htaccess (AGENTS.md) — GET do AJAX
        // endpointów pod /atte_ms_new/public_html/... jest blokowany i
        // zwraca parsererror.
        return $.ajax({ url: ajaxBase + endpoint, type: 'POST', data: data, dataType: 'json' });
    }

    // Liczby do wyświetlenia — separator tysięcy robi realną robotę
    // przy odczycie 5 000 vs 50 000 z tabeli.
    function fmtQty(n) {
        const v = parseFloat(n);
        if (isNaN(v)) return '—';
        return v.toLocaleString('pl-PL', { maximumFractionDigits: 4 });
    }

    // Liczba do wpisania w <input type="number"> — kropka dziesiętna,
    // bez ogonów zmiennoprzecinkowych (0.30000000000000004).
    function trimNum(v, decimals) {
        const n = parseFloat(v);
        if (isNaN(n)) return '';
        return String(parseFloat(n.toFixed(decimals === undefined ? 6 : decimals)));
    }

    function num(v) {
        const n = parseFloat(v);
        return isNaN(n) ? 0 : n;
    }

    // Ta sama mapa klas co w kolejce/historii — stan dokumentu ma
    // wyglądać identycznie w całym module.
    function stateBadgeClass(state) {
        switch (state) {
            case 'draft':              return 'badge-secondary';
            case 'sent':               return 'badge-primary';
            case 'responded':          return 'badge-info';
            case 'confirmed':          return 'badge-info';
            // 'in_progress' = operator zaakceptował nagłówek GRN (Akceptuję)
            // ale nie wprowadził jeszcze pozycji. badge-info jak 'confirmed':
            // ten sam „status logistyczny, nie błąd" ton wizualny.
            case 'in_progress':        return 'badge-info';
            case 'partially_received': return 'badge-warning';
            case 'received':           return 'badge-success';
            case 'cancelled':          return 'badge-danger';
            default:                   return 'badge-secondary';
        }
    }

    function stateBadgeLabel(state) {
        return ({
            draft: 'Szkic', sent: 'Wysłane', responded: 'Odpowiedź',
            confirmed: 'Potwierdzone', in_progress: 'Częściowo przyjęte',
            partially_received: 'Częściowo odebrane',
            received: 'Odebrane', cancelled: 'Anulowane'
        })[state] || state;
    }

    // 1 pozycję / 2-4 pozycje / 5+ pozycji (z wyjątkiem końcówek 12-14).
    function pozycjeWord(n) {
        const abs = Math.abs(n);
        if (abs === 1) return 'pozycję';
        const last = abs % 10;
        const last2 = abs % 100;
        if (last >= 2 && last <= 4 && (last2 < 12 || last2 > 14)) return 'pozycje';
        return 'pozycji';
    }

    function dayWord(n) { return n === 1 ? 'dzień' : 'dni'; }

    function pad2(n) { return String(n).length < 2 ? '0' + n : String(n); }

    function todayIso() {
        const d = new Date();
        return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate());
    }

    function nowStamp() {
        const d = new Date();
        return todayIso() + ' ' + pad2(d.getHours()) + ':' + pad2(d.getMinutes()) + ':' + pad2(d.getSeconds());
    }

    function hasSelectpicker() { return typeof $.fn.selectpicker === 'function'; }

    // Inicjalizacja Bootstrap-select. Bez tego wtyczka zgłasza klasy, ale
    // nie renderuje pickera — operator widziałby surowy <select multiple>
    // (zajmujący pół strony i nieklikalny jak prawdziwy picker).
    // AGENTS.md: data-width="100%" + parent + min-width:0 — już stosowane
    // w markupie; poniższe wywołanie aktywuje sam plugin.
    if (hasSelectpicker()) {
        $('.selectpicker').selectpicker();
    }

    // =====================================================================
    // Ładowanie formularza
    // =====================================================================

    const $view        = $('#receiptsReceiveView');
    const $linesBody   = $('#receiveLinesBody');
    const $magSelect   = $('#receiptMagazineSelect');

    let receiveState = {
        poId: null,
        poNumber: '',
        vendorName: '',
        items: [],               // surowe pozycje z receipt-po-get.php
        headerMagazineId: '',
        receivedAtOpened: false, // czy ktoś w ogóle otworzył pole daty
        submitReady: false,      // pozycje wypełnione i bez błędów (krok 3)
        loading: false,
        submitting: false
    };

    // Opcje magazynów renderuje PHP raz, w pickerze nagłówka. Listy przy
    // pozycjach klonujemy z nich jako zwykłe .custom-select — 20+ widgetów
    // bootstrap-select w jednej tabeli to niepotrzebny koszt.
    let magazineOptionsHtml = '';
    $magSelect.find('option').each(function() {
        const val = String($(this).attr('value') || '');
        if (val === '') return;
        magazineOptionsHtml += '<option value="' + escAttr(val) + '">' + esc($.trim($(this).text())) + '</option>';
    });

    function setHeaderMagazine(id) {
        receiveState.headerMagazineId = String(id == null ? '' : id);
        if (hasSelectpicker()) {
            // Kanoniczna sekwencja refresh → val → refresh (jak w koszyku).
            $magSelect.selectpicker('refresh');
            try { $magSelect.selectpicker('val', receiveState.headerMagazineId); } catch (e) { /* noop */ }
            $magSelect.selectpicker('refresh');
        } else {
            $magSelect.val(receiveState.headerMagazineId);
        }
    }

    // =====================================================================
    // Kreator: krok 1 (dokument) → krok 2 (pozycje) → krok 3 (zapis)
    // =====================================================================
    //
    // Cały stan kreatora to dwie flagi. `step1Accepted` bramkuje zapis
    // (karta pozycji jest wprawdzie zablokowana przez CSS, ale
    // pointer-events nie chroni przed zmianą programową — bramka na
    // przycisku zapisu chroni). `currentStep` służy tylko za pamięć
    // ostatnio narysowanego kroku, żeby setStep() nie przemalowywał
    // paska przy każdym wciśniętym klawiszu w tabeli.

    let step1Accepted = false;
    let currentStep   = 0;

    // Rysuje pasek kroków. Krok przed bieżącym = domknięty (numer
    // ustępuje miejsca ptaszkowi), bieżący = aktywny, dalsze = szare.
    function setStep(step) {
        if (step === currentStep) return;
        currentStep = step;

        $('#receiptStepper .receipt-step').each(function() {
            const $li = $(this);
            const s   = parseInt($li.attr('data-step'), 10);
            const $num = $li.find('.receipt-step-num');

            $li.removeClass('receipt-step-active receipt-step-completed').removeAttr('aria-current');

            if (s < step) {
                $li.addClass('receipt-step-completed');
                $num.html('<i class="bi bi-check2"></i>');
            } else {
                if (s === step) $li.addClass('receipt-step-active').attr('aria-current', 'step');
                $num.text(s);
            }
        });
    }

    // Pasek kroków jako funkcja stanu, nie jako ręcznie przestawiany
    // licznik: dopóki krok 1 nie zaakceptowany — jesteśmy w kroku 1;
    // potem w kroku 2, a gdy formularz jest gotowy do zapisu — w kroku 3.
    function syncStepper() {
        if (!step1Accepted) { setStep(1); return; }
        setStep(receiveState.submitReady ? 3 : 2);
    }

    // Zwija kartę „Dokument przyjęcia" do podsumowania i odblokowuje
    // pozycje. Wartości czyta wprost z kontrolek — dzięki temu działa
    // tak samo po kliknięciu „Akceptuję" i po automatycznym zaliczeniu
    // kroku przy dostawie częściowej.
    function showDocumentSummary() {
        const magName    = $.trim(String($magSelect.find('option:selected').text() || ''));
        const vendorDoc  = $.trim(String($('#vendorDocumentNumber').val() || ''));
        const comment    = $.trim(String($('#receiptComment').val() || ''));
        // Data przyjęcia idzie na serwer tylko wtedy, gdy operator
        // świadomie otworzył pole (receivedAtOpened) — w pozostałych
        // przypadkach stempluje ją serwer, więc pokazujemy dzisiejszą.
        const receivedAt = receiveState.receivedAtOpened
            ? ($('#receivedAt').val() || todayIso())
            : todayIso();

        $('#receiptSummaryMagazine').text(magName || '—');
        $('#receiptSummaryVendorDoc').text(vendorDoc || '—');
        $('#receiptSummaryDate').text(receivedAt
            + (receiveState.receivedAtOpened ? '' : ' (dzisiaj)'));
        $('#receiptSummaryComment').text(comment || '—');

        $('#receiptDocumentEdit').addClass('d-none');
        $('#receiptDocumentSummary').removeClass('d-none');
        $('#receiptDocumentEditBtn').removeClass('d-none');
        $('#receiptLinesCard').removeClass('receipt-step-locked');
        // Pasek akcji startuje zablokowany (licznik „Przyjmujesz X z Y"
        // dotyczy kroku 2) — zdejmujemy tę samą klasą co kartę pozycji,
        // żeby stan obu bloków szedł w parze.
        $('#receiveActionBar').removeClass('receipt-step-locked');

        step1Accepted = true;
        refreshSummary();   // przelicza bramkę zapisu i dociąga pasek kroków
    }

    // Powrót do edycji kroku 1 — pozycje znów zablokowane, zapis zdjęty.
    function showDocumentEdit() {
        $('#receiptDocumentSummary').addClass('d-none');
        $('#receiptDocumentEdit').removeClass('d-none');
        $('#receiptDocumentEditBtn').addClass('d-none');
        $('#receiptLinesCard').addClass('receipt-step-locked');
        $('#receiveActionBar').addClass('receipt-step-locked');

        step1Accepted = false;
        $('#receiveSubmitBtn').prop('disabled', true);
        updateProgress();   // krok 1 znów otwarty — pasek wraca do stanu sprzed zapisu
        syncStepper();
    }

    $('#receiptAcceptBtn').on('click', function() {
        // Magazyn jest pre-selectowany przez JS, więc to bramka na wypadek
        // zamówienia otwartego bez ani jednego aktywnego magazynu.
        if (!$magSelect.val()) {
            showAlert('Wybierz magazyn docelowy.', 'warning');
            return;
        }

        // Akceptacja nagłówka jest persistence, nie tylko UI — w odróżnieniu
        // od poprzedniej wersji (która tylko zwijala kartę) zapisujemy
        // metadane na serwerze i przenosimy PO do stanu 'in_progress'.
        // Dzięki temu operator może wyjść i wrócić: krok 1 sam się
        // zaliczy po ponownym otwarciu formularza (patrz applyLastReceipt).
        //
        // Payload: tylko pola z kroku 1 — magazyn, nr dokumentu dostawcy,
        // komentarz, opcjonalna data. Pozycje NIE są tu częścią — idą
        // razem z końcowym Zapiszem w receipt-create.php.
        const payload = {
            po_id:                  receiveState.poId,
            comment:                $.trim(String($('#receiptComment').val() || '')),
            vendor_document_number: $.trim(String($('#vendorDocumentNumber').val() || ''))
        };
        // received_at leci tylko wtedy, gdy operator świadomie otworzył
        // pole daty — w przeciwnym razie serwer stempluje NOW().
        if (receiveState.receivedAtOpened && $('#receivedAt').val()) {
            payload.received_at = $('#receivedAt').val();
        }

        const $btn = $('#receiptAcceptBtn');
        $btn.prop('disabled', true)
            .html('<span class="spinner-border spinner-border-sm"></span> Akceptuję…');

        getAjax('receipt-accept.php', payload)
            .done(function(r) {
                $btn.prop('disabled', false)
                    .html('<i class="bi bi-check2"></i> Akceptuję');
                if (!r || !r.success) {
                    showAlert(esc((r && r.error) || 'Nie udało się zaakceptować dokumentu.'), 'danger');
                    return;
                }

                // Sukces — aktualizujemy badge stanu PO w nagłówku, żeby
                // operator widział, że PO przeszło do 'in_progress'.
                // Reszta UI (karta podsumowania, scroll do tabeli) jest
                // wspólna z auto-zaliczeniem przy partially_received,
                // więc idzie przez showDocumentSummary().
                if (r.po_state) {
                    $('#receivePoState').html(
                        '<span class="badge ' + stateBadgeClass(r.po_state) + ' ml-2">'
                        + esc(stateBadgeLabel(r.po_state)) + '</span>'
                    );
                }

                showDocumentSummary();
                // Adnotacja „dane przepisane" dotyczy tylko auto-zaliczenia;
                // gdy operator sam kliknął „Akceptuję", jest nieprawdziwa.
                $('#receiptSummaryInherited').addClass('d-none');

                // Karta się zwinęła, więc tabela podskoczyła w górę — bez
                // tego operator patrzy w miejsce, w którym przed chwilą
                // był przycisk, a nie w pozycje, które ma teraz sprawdzić.
                const $lines = $('#receiptLinesCard');
                if ($lines.length) {
                    $('html, body').animate({ scrollTop: $lines.offset().top - 16 }, 250);
                }
            })
            .fail(function(xhr, status) {
                $btn.prop('disabled', false)
                    .html('<i class="bi bi-check2"></i> Akceptuję');
                showAlert(esc((xhr.responseJSON && xhr.responseJSON.error) || status || 'Błąd komunikacji z serwerem'), 'danger');
            });
    });

    $('#receiptDocumentEditBtn').on('click', function() {
        showDocumentEdit();
    });

    function openReceiveForm(poId) {
        if (!poId || receiveState.loading || receiveState.submitting) return;

        receiveState.loading = true;

        $('#receivePoNumber').text('');
        $('#receivePoState').empty();
        $('#receiveVendorName').text('—');
        $('#receiveVendorPoNumber').text('—');
        $('#receiveExpectedDelivery').text('—');
        $('#receivePoCommentWrap').addClass('d-none');
        $('#receiveSummary').text('');
        $('#receiveHint').text('');
        $('#receiveSubmitBtn').prop('disabled', true);
        $linesBody.html('<tr><td colspan="10" class="text-center text-muted py-5">'
            + '<span class="spinner-border spinner-border-sm"></span> Ładowanie pozycji…</td></tr>');

        getAjax('receipt-po-get.php', { po_id: poId })
            .done(function(r) {
                receiveState.loading = false;
                if (!r || !r.success) {
                    showAlert(esc((r && r.error) || 'Nie udało się pobrać zamówienia.'), 'danger');
                    return;
                }
                fillReceiveForm(r);
            })
            .fail(function(xhr, status) {
                receiveState.loading = false;
                showAlert(esc((xhr.responseJSON && xhr.responseJSON.error) || status || 'Błąd komunikacji z serwerem'), 'danger');
            });
    }

    function fillReceiveForm(r) {
        const po = r.po || {};

        receiveState.poId       = parseInt(po.poId, 10);
        receiveState.poNumber   = po.poNumber || ('#' + po.poId);
        receiveState.vendorName = po.vendorName || '';
        receiveState.items      = r.items || [];
        receiveState.receivedAtOpened = false;

        $('#receivePoNumber').text(receiveState.poNumber);
        $('#receivePoState').html(
            '<span class="badge ' + stateBadgeClass(po.state) + ' ml-2">'
            + esc(stateBadgeLabel(po.state)) + '</span>'
        );
        $('#receiveVendorName').text(receiveState.vendorName || '—');
        $('#receiveVendorPoNumber').text(po.vendorPoNumber || '—');
        $('#receiveExpectedDelivery').text(po.expectedDeliveryDate || '—');
        if (po.comment && String(po.comment).trim() !== '') {
            $('#receivePoComment').text(po.comment);
            $('#receivePoCommentWrap').removeClass('d-none');
        } else {
            $('#receivePoCommentWrap').addClass('d-none');
        }

        // Nagłówek dokumentu — czysty start przy każdym otwarciu.
        $('#vendorDocumentNumber').val('');
        $('#receiptComment').val('');
        $('#receivedAtWrap').addClass('d-none');
        $('#receivedAt').val(todayIso());
        $('#receivedAtHint').text('Domyślnie dzisiejsza data.');
        $('#toggleReceivedAt').html('<i class="bi bi-calendar-event"></i> Zmień datę przyjęcia').show();

        // Magazyn docelowy — domyślny z odpowiedzi, a gdyby go nie było na
        // liście aktywnych, pierwszy z brzegu.
        let def = String(r.defaultSubMagazineId == null ? '' : r.defaultSubMagazineId);
        if (def === '' || $magSelect.find('option[value="' + def.replace(/"/g, '') + '"]').length === 0) {
            def = String($magSelect.find('option').first().attr('value') || '');
        }
        setHeaderMagazine(def);

        renderReceiveLines();
        applyLastReceipt(po, r.lastReceipt);
    }

    // Dostawa dzielona LUB wznowienie po Akceptuję: krok 1 zalicza się
    // sam — operator wraca tu po to, żeby dobić pozycje (partially_received)
    // albo żeby uzupełnić formularz po wyjściu i powrocie (in_progress),
    // a nie żeby od nowa opisywać dokument. Przepisujemy nr dokumentu
    // dostawcy, komentarz i magazyn z ostatniego wpisu (real receipt
    // albo draft), po czym od razu pokazujemy podsumowanie.
    //
    // DATY NIE PRZEPISUJEMY — to jedyne pole, które dla nowego dokumentu
    // musi znaczyć „kiedy towar przyszedł teraz", a nie „kiedy przyszła
    // poprzednia partia" / „kiedy zaakceptowałem nagłówek". Zostaje
    // więc dzisiaj (serwer i tak stempluje datę, dopóki operator nie
    // otworzy pola ręcznie), a datę poprzedniego wpisu pokazujemy w
    // karcie „Zamówienie" jako kontekst.
    //
    // receive-po-get.php prefers drafts over real receipts when both
    // exist (comment block there explains why), so dla in_progress
    // PO z draftem `last` jest właśnie tym draftem i ma już
    // zserializowane metadane (vendor doc # w commentu, datę). To
    // działa identycznie jak partially_received z real receiptem —
    // ten sam kształt odpowiedzi.
    function applyLastReceipt(po, last) {
        const isResume = po && (po.state === 'partially_received' || po.state === 'in_progress') && last;

        if (!isResume) {
            $('#receiveLastReceiptWrap').addClass('d-none');
            $('#receiptSummaryInherited').addClass('d-none');
            showDocumentEdit();
            return;
        }

        const prevParts = [];
        if (last.documentNumber) prevParts.push(esc(last.documentNumber));
        if (last.receivedAt)     prevParts.push(esc(String(last.receivedAt).substring(0, 10)));
        $('#receiveLastReceipt').html(prevParts.length
            ? prevParts.join(' <span class="text-muted">&middot;</span> ')
            : '—');
        $('#receiveLastReceiptWrap').removeClass('d-none');

        if (last.vendorDocumentNumber) $('#vendorDocumentNumber').val(last.vendorDocumentNumber);
        if (last.comment)              $('#receiptComment').val(last.comment);

        // Magazyn z ostatniego przyjęcia wygrywa z domyślnym magazynem
        // przyjęć — kolejna partia tej samej dostawy ląduje tam, gdzie
        // poprzednia. setHeaderMagazine nie przestawia wierszy, więc
        // robimy to ręcznie (nikt jeszcze niczego nie nadpisał).
        const lastMag = String(last.lastSubMagazineId == null ? '' : last.lastSubMagazineId);
        if (lastMag !== '' && $magSelect.find('option[value="' + lastMag.replace(/"/g, '') + '"]').length > 0) {
            setHeaderMagazine(lastMag);
            $linesBody.find('.line-magazine').val(lastMag).attr('data-overridden', '0');
        }

        $('#receiptSummaryInherited').removeClass('d-none');
        showDocumentSummary();
    }

    // pickedPackSize === null → dostawca nie ma zdefiniowanej wielkości
    // opakowania dla tej pozycji; kolumna Opak. pokazuje "—", a Ilość
    // zostaje polem swobodnym.
    function packSizeOf(item) {
        if (!item) return null;
        const p = parseFloat(item.pickedPackSize);
        return (!isNaN(p) && p > 0) ? p : null;
    }

    function itemOfRow($row) {
        return receiveState.items[parseInt($row.attr('data-idx'), 10)];
    }

    function isDone(item) { return num(item.quantityRemaining) <= 0; }

    function lineRowHtml(item, idx) {
        const done      = isDone(item);
        const remaining = num(item.quantityRemaining);
        const pack      = packSizeOf(item);
        const unit      = item.unitName || '';

        // PREFILL: pełna reszta dostawy. Otworzyć i zapisać = potwierdzić
        // całą pozostałą dostawę; to 90% przypadków.
        const qtyVal = done ? '' : trimNum(remaining, 6);
        const pkgVal = (!done && pack !== null) ? trimNum(remaining / pack, 2) : '';

// Nazwa + opis urządzenia zaraz pod nazwą, potem producent.
// Dodatkowe informacje (uwagi do urządzenia + uwagi do pozycji PO)
// idą pod „Nr dostawcy" — tam są wizualnie związane z identyfikatorem.
        let nameCell = '<span class="font-weight-bold">' + esc(item.partName || '—') + '</span>';
        if (item.partDescription && String(item.partDescription).trim() !== '') {
            nameCell += '<div><small class="text-muted">'
                      + esc(item.partDescription) + '</small></div>';
        }
        const sub = [];
        if (item.producerName)   sub.push(esc(item.producerName));
        if (item.producerPartNo) sub.push('<span class="font-weight-bold">' + esc(item.producerPartNo) + '</span>');
        if (sub.length) {
            nameCell += '<div><small class="text-muted">' + sub.join(' &middot; ') + '</small></div>';
        }

        // „Nr dostawcy" — numer katalogowy + uwagi (do urządzenia i do PO).
        // Żółty = uwagi do urządzenia (vendor_part.comment, system-level),
        // niebieski = uwagi do pozycji PO (kupiec zostawił przy zamówieniu).
        //
        // Dwa uwagowe wiersze wyglądają podobnie, a pochodzą z zupełnie
        // różnych miejsc — pomylenie ich jest kosztowne (operator myśli,
        // że kupiec coś zaznaczył na tym zamówieniu, a to notatka
        // systemowa, albo odwrotnie). Każdy wiersz ma więc w `title`
        // jednoznaczne źródło: skąd komentarz pochodzi i jak długo żyje.
        //
        // Uwagi zapisujemy w title jako tekst jednoliniowy — przeglądarki
        // i tak zwijają w title każdą newline na spację.
        const vendorPartNote = String(item.vendorPartComment || '').replace(/\s+/g, ' ').trim();
        const poItemNote     = String(item.comment || '').replace(/\s+/g, ' ').trim();

        let numCell = '<span class="text-monospace">' + esc(item.vendorPartNo || '—') + '</span>';
        if (vendorPartNote !== '') {
            numCell += '<div title="'
                     + escAttr('Uwagi do urządzenia — zapisane przy tym artykule u dostawcy. '
                              + 'Obowiązują przy każdym zamówieniu tego artykułu.')
                     + '"><small class="text-warning">'
                     + '<i class="bi bi-journal-text"></i> ' + esc(item.vendorPartComment)
                     + '</small></div>';
        }
        if (poItemNote !== '') {
            numCell += '<div title="'
                     + escAttr('Uwagi do pozycji — zapisane przez kupca w tym zamówieniu. '
                              + 'Dotyczą wyłącznie tego zamówienia.')
                     + '"><small class="text-info">'
                     + '<i class="bi bi-chat-left-text"></i> ' + esc(item.comment)
                     + '</small></div>';
        }

        let packCell;
        if (pack === null) {
            packCell = '<span class="text-muted" title="Brak zdefiniowanej wielkości opakowania">—</span>';
        } else {
            packCell = '<input type="number" class="form-control form-control-sm text-right line-packages"'
                     + ' step="any" min="0" value="' + escAttr(pkgVal) + '"' + (done ? ' disabled' : '') + '>'
                     + '<small class="text-muted">po ' + esc(fmtQty(pack)) + '</small>';
        }

        const remainingCell = done
            ? '<span class="badge badge-light border text-muted">Przyjęte w całości</span>'
            : '<span class="h6 mb-0 font-weight-bold">' + esc(fmtQty(remaining)) + '</span>'
              + (unit ? '<div><small class="text-muted">' + esc(unit) + '</small></div>' : '');

        // Checkbox „Przyjmujesz" — domyślnie zaznaczony (prefill qty z
        // quantityRemaining = przyjmujemy całą resztę). Odznaczenie =
        // pomijamy pozycję w tym przyjęciu (partial reception). Dla
        // pozycji domkniętych (done) input jest disabled.
        const toggleCell = '<td class="receipt-col-toggle text-center">'
                         + '<input type="checkbox" class="form-check-input line-receive-toggle"'
                         + ' data-idx="' + idx + '"' + (done ? ' disabled checked' : ' checked') + '>'
                         + '</td>';
        return '<tr class="receipt-line' + (done ? ' receipt-line-done bg-light' : '') + '"'
             +   ' data-idx="' + idx + '" data-item-id="' + parseInt(item.poItemId, 10) + '">'
             + toggleCell
             + '<td>' + numCell + '</td>'
             + '<td>' + nameCell + '</td>'
             + '<td class="text-right">' + esc(fmtQty(item.quantityOrdered))
             +   (unit ? '<div><small class="text-muted">' + esc(unit) + '</small></div>' : '') + '</td>'
             + '<td class="text-right">' + esc(fmtQty(item.quantityReceived))
             +   (unit ? '<div><small class="text-muted">' + esc(unit) + '</small></div>' : '') + '</td>'
             + '<td class="text-right">' + remainingCell + '</td>'
             + '<td>' + packCell + '</td>'
             + '<td>'
             +   '<div class="d-flex align-items-center">'
             +     '<span class="receipt-amount-indicator mr-2"></span>'
             +     '<input type="number" class="form-control form-control-sm text-right line-qty flex-grow-1"'
             +     ' step="any" min="0" value="' + escAttr(qtyVal) + '"' + (done ? ' disabled' : '') + '>'
             +   '</div>'
             +   '<div class="line-diff small mt-1 text-right"></div>'
             +   '<div class="line-msg small mt-1"></div>'
             + '</td>'
             + '<td><select class="custom-select custom-select-sm line-magazine" data-overridden="0"'
             +   (done ? ' disabled' : '') + '>' + magazineOptionsHtml + '</select></td>'
             + '<td><input type="text" class="form-control form-control-sm line-comment" maxlength="255"'
             +   (done ? ' disabled' : '') + '></td>'
             + '</tr>';
    }

    function renderReceiveLines() {
        const items = receiveState.items;
        $('#receiveLineCount').text(items.length);

        if (items.length === 0) {
            $linesBody.html('<tr><td colspan="10" class="text-center text-muted py-4">'
                + 'To zamówienie nie ma pozycji.</td></tr>');
            refreshSummary();
            return;
        }

        let html = '';
        items.forEach(function(item, idx) { html += lineRowHtml(item, idx); });
        $linesBody.html(html);

        // Magazyn z nagłówka na start — dopóki użytkownik nie wybierze
        // czegoś innego, wiersz "dziedziczy" wartość nagłówka.
        $linesBody.find('.line-magazine').val(receiveState.headerMagazineId).attr('data-overridden', '0');

        // Prefill mógł wyjść nierówny względem opakowania — od razu to
        // pokazujemy, tak samo jak koszyk po wpisaniu ilości.
        $linesBody.find('tr.receipt-line').each(function() {
            const $row = $(this);
            const item = itemOfRow($row);
            if (!item || isDone(item)) return;
            applyEvenWarning($row, $row.find('.line-qty'), num(item.quantityRemaining), packSizeOf(item));
            updateAmountIndicator($row, parseFloat($row.find('.line-qty').val()), num(item.quantityRemaining));
        });

        refreshSummary();
    }

    // ---- Opak. <-> Ilość (ta sama logika co w koszyku) ------------------

    // Czy `qty` dzieli się na całkowitą liczbę opakowań `pack`.
    // Brak/zero/NaN → true: to "jeszcze nic nie wpisano", nie błąd.
    function isEven(qty, pack) {
        if (!pack || pack <= 0 || qty == null || isNaN(qty)) return true;
        return Math.abs(qty / pack - Math.round(qty / pack)) < 1e-9;
    }

    // Ostrzeżenie .packages-uneven ląduje na ostatnio edytowanym polu;
    // drugie pole zawsze czyścimy, żeby świeciło tylko jedno.
    function applyEvenWarning($row, $lastEdited, qty, pack) {
        const $pkg = $row.find('.line-packages');
        const $qty = $row.find('.line-qty');
        $pkg.removeClass('packages-uneven').removeAttr('title');
        $qty.removeClass('packages-uneven').removeAttr('title');
        if (isEven(qty, pack)) return;
        if (!$lastEdited || !$lastEdited.length) return;
        $lastEdited.addClass('packages-uneven').attr('title',
            'Uwaga: ilość nie odpowiada pełnej liczbie opakowań (wielkość: ' + fmtQty(pack) + ')');
    }

    // Mały krążek przy polu Ilość: zielony = qty == remaining (cała
    // reszta), żółty = 0 < qty < remaining (część). Puste / 0 /
    // nadwyżka = brak (nie kolorujemy nadwyżki żółto, bo to nie
    // „część" — to „więcej niż miało być", i tak wyłapie ją walidacja
    // jako table-danger).
    function updateAmountIndicator($row, qty, remaining) {
        const $ind = $row.find('.receipt-amount-indicator');
        if (!isNaN(qty) && qty > 0 && remaining > 0) {
            if (Math.abs(qty - remaining) < 1e-9) {
                $ind.attr('data-state', 'full').attr('title', 'Przyjmujesz całość');
            } else if (qty < remaining) {
                $ind.attr('data-state', 'partial').attr('title', 'Przyjmujesz część');
            } else {
                $ind.attr('data-state', '').removeAttr('title');
            }
        } else {
            $ind.attr('data-state', '').removeAttr('title');
        }
    }

    // Opak. → Ilość
    $linesBody.on('input', '.line-packages', function() {
        const $row = $(this).closest('tr.receipt-line');
        const item = itemOfRow($row);
        const pack = packSizeOf(item);
        if (pack === null) return;

        const $qty = $row.find('.line-qty');
        const pkgs = parseFloat($(this).val());
        let qty;
        if (isNaN(pkgs) || pkgs < 0) {
            qty = NaN;
            $qty.val('');
            applyEvenWarning($row, $(this), NaN, pack);
        } else {
            qty = pkgs * pack;
            $qty.val(trimNum(qty, 6));
            applyEvenWarning($row, $(this), qty, pack);
        }
        updateAmountIndicator($row, qty, num(item.quantityRemaining));
        refreshSummary();
    });

    // Ilość → Opak.
    $linesBody.on('input', '.line-qty', function() {
        const $row = $(this).closest('tr.receipt-line');
        const item = itemOfRow($row);
        const pack = packSizeOf(item);
        const remaining = num(item.quantityRemaining);
        if (pack === null) {
            updateAmountIndicator($row, parseFloat($(this).val()), remaining);
            refreshSummary();
            return;
        }

        const $pkg = $row.find('.line-packages');
        const qty = parseFloat($(this).val());
        if (isNaN(qty) || qty < 0) {
            $pkg.val('');
            applyEvenWarning($row, $(this), NaN, pack);
        } else {
            $pkg.val(trimNum(qty / pack, 2));
            applyEvenWarning($row, $(this), qty, pack);
        }
        updateAmountIndicator($row, isNaN(qty) ? NaN : qty, remaining);
        refreshSummary();
    });

    // ---- checkbox: odbierz / pomiń pozycję ------------------------------
    // Zaznaczony = pozycja idzie w tym przyjęciu (prefill z quantityRemaining).
    // Odznaczony = pomijamy pozycję: cały wiersz się wyszarza, ilość/opak.
    // zerujemy, wejścia wyłączamy, a submit/validate/summary ją omijają.
    // Używamy osobnej klasy .receipt-line-skip zamiast .receipt-line-done,
    // żeby nie dostać zielonej krawędzi (done = „ukończone", tu = „pominięte").
    $linesBody.on('change', '.line-receive-toggle', function() {
        const $cb  = $(this);
        const $row = $cb.closest('tr.receipt-line');
        const item = itemOfRow($row);
        if (!item) return;

        const $qty = $row.find('.line-qty');
        const $pkg = $row.find('.line-packages');
        const $mag = $row.find('.line-magazine');
        const $cmt = $row.find('.line-comment');

        if ($cb.is(':checked')) {
            $row.removeClass('receipt-line-skip bg-light');
            $qty.prop('disabled', false);
            $pkg.prop('disabled', false);
            $mag.prop('disabled', false);
            $cmt.prop('disabled', false);
            // Przywróć prefill (identyczny jak przy pierwszym renderze).
            const remaining = num(item.quantityRemaining);
            const pack = packSizeOf(item);
            $qty.val(trimNum(remaining, 6));
            if (pack !== null) $pkg.val(trimNum(remaining / pack, 2));
            $cmt.val('');
            applyEvenWarning($row, $qty, remaining, pack);
            updateAmountIndicator($row, remaining, remaining);
        } else {
            $row.addClass('receipt-line-skip bg-light');
            $qty.val('').prop('disabled', true);
            $pkg.val('').prop('disabled', true);
            $mag.prop('disabled', true);
            $cmt.val('').prop('disabled', true);
            $qty.removeClass('is-invalid packages-uneven').removeAttr('title');
            $pkg.removeClass('packages-uneven').removeAttr('title');
            $row.removeClass('table-warning table-danger table-info');
            updateAmountIndicator($row, NaN, num(item.quantityRemaining));
        }
        refreshSummary();
    });

    // ---- walidacja na żywo ---------------------------------------------

    // Zwraca 'empty' | 'ok' | 'over' i od razu maluje wiersz.
    // 'over' (także „znacznie więcej niż oczekiwano") nigdy nie blokuje zapisu.
    function validateRow($row) {
        const item = itemOfRow($row);
        const $qty = $row.find('.line-qty');
        const $msg = $row.find('.line-msg');

        $row.removeClass('table-warning table-danger table-info');
        $qty.removeClass('is-invalid');
        $msg.empty();
        const $diff = $row.find('.line-diff').empty();

        if (!item || isDone(item)) return 'empty';
        if (!$row.find('.line-receive-toggle').is(':checked')) return 'empty';

        const raw = $.trim(String($qty.val()));
        if (raw === '') return 'empty';
        const qty = parseFloat(raw);
        if (isNaN(qty) || qty <= 0) return 'empty';

        const remaining = num(item.quantityRemaining);
        const max       = num(item.maxAllowed);
        const unit      = item.unitName ? (' ' + item.unitName) : '';

        if (qty > max) {
            $row.addClass('table-danger');
            $qty.addClass('is-invalid');
            $msg.html('<span class="text-danger font-weight-bold">Znacznie więcej niż oczekiwano (oczekiwane do '
                + esc(fmtQty(max) + unit) + ').</span>');
            $diff.html('<span class="text-danger font-weight-bold">Nadwyżka: +'
                + esc(fmtQty(qty - remaining) + unit) + '</span>');
            return 'over';
        }
        if (qty > remaining) {
            $row.addClass('table-warning');
            $msg.html('<span class="badge badge-warning">nadwyżka (dopuszczalna)</span>');
            $diff.html('<span class="text-warning font-weight-bold">Nadwyżka: +'
                + esc(fmtQty(qty - remaining) + unit) + '</span>');
            return 'over';
        }
        if (remaining - qty > 1e-9) {
            // Niedobór (przyjęcie częściowe): wiersz też dostaje kolor, tak
            // jak przy nadwyżce — ale niebieski (table-info), żeby nie
            // mylić go z żółtą nadwyżką. Nie blokuje zapisu.
            $row.addClass('table-info');
            $msg.html('<span class="badge badge-info">przyjęcie częściowe</span>');
            $diff.html('<span class="text-muted">Brakuje: <span class="font-weight-bold">'
                + esc(fmtQty(remaining - qty) + unit) + '</span></span>');
        }
        return 'ok';
    }

    function refreshSummary() {
        let filled = 0;
        let open   = 0;

        $linesBody.find('tr.receipt-line').each(function() {
            const $row = $(this);
            const item = itemOfRow($row);
            // Pozycja odznaczona = pomijamy ją w przyjęciu (nie liczymy
            // jako „open" ani „filled"; validateRow i tak zwróci 'empty').
            if ($row.find('.line-receive-toggle').is(':not(:checked)')) return;
            if (item && !isDone(item)) open++;
            const st = validateRow($row);
            if (st === 'ok' || st === 'over') filled++;
        });

        $('#receiveSummary').text('Przyjmujesz ' + filled + ' z ' + open + ' pozycji');

        let hint = '';
        let ok = true;
        if (filled === 0) {
            ok = false;
            hint = 'Wpisz ilość w co najmniej jednej pozycji.';
        }
        // Krok 1 niezaakceptowany = zapis zablokowany, niezależnie od
        // tego, co jest w tabeli. Karta pozycji jest wtedy zasłonięta
        // przez .receipt-step-locked (pointer-events: none), ale to
        // chroni tylko przed kliknięciem — prefill ilości siedzi w
        // wierszach od pierwszego renderu, więc bramka musi być tutaj.
        receiveState.submitReady = ok;
        if (!step1Accepted) {
            ok = false;
            hint = 'Najpierw zatwierdź dokument przyjęcia („Akceptuję").';
        }

        $('#receiveHint').text(hint);
        $('#receiveSubmitBtn').prop('disabled', !ok || receiveState.submitting);
        updateProgress();
        syncStepper();
    }

    // ---- postęp całego zamówienia --------------------------------------

    // Sumuje po WSZYSTKICH pozycjach zamówienia: to, co już przyjęto, plus
    // to, co operator wpisał w tym przyjęciu (tylko zaznaczone wiersze).
    // `entered` = mapa idx → wpisana ilość; pusta mapa daje stan sprzed zapisu.
    // Nadwyżka na jednej pozycji nie maskuje braków na innej, więc każdą
    // pozycję przycinamy do jej zamówionej ilości.
    function computeProgress(entered) {
        let ordered = 0;
        let received = 0;
        let linesDone = 0;
        const items = receiveState.items;
        for (let i = 0; i < items.length; i++) {
            const it = items[i];
            const ord = Math.max(0, num(it.quantityOrdered));
            const got = Math.max(0, num(it.quantityReceived)) + Math.max(0, entered[i] || 0);
            ordered += ord;
            received += Math.min(got, ord);
            if (ord > 0 && got >= ord - 1e-9) linesDone++;
        }
        return { ordered: ordered, received: received, linesDone: linesDone, linesTotal: items.length };
    }

    function enteredByIdx() {
        const entered = {};
        $linesBody.find('tr.receipt-line').each(function() {
            const $row = $(this);
            const item = itemOfRow($row);
            if (!item || isDone(item)) return;
            if (!$row.find('.line-receive-toggle').is(':checked')) return;
            const q = parseFloat($.trim(String($row.find('.line-qty').val())));
            if (!isNaN(q) && q > 0) entered[parseInt($row.attr('data-idx'), 10)] = q;
        });
        return entered;
    }

    function updateProgress() {
        const $box = $('#receiveProgress');
        if ($box.length === 0) return;
        // Przed zaakceptowaniem kroku 1 pokazujemy tylko to, co już
        // przyjęto — prefill ilości nie ma wyglądać jak postęp.
        const p = computeProgress(step1Accepted ? enteredByIdx() : {});
        if (p.linesTotal === 0) { $box.addClass('d-none'); return; }
        $box.removeClass('d-none');
        const pct = p.ordered > 0 ? Math.max(0, Math.min(100, (p.received / p.ordered) * 100)) : 0;
        $('#receiveProgressLines').html('<strong>' + p.linesDone + '</strong> z ' + p.linesTotal + ' pozycji');
        $('#receiveProgressPct').text(Math.round(pct) + '%');
        $('#receiveProgressSub').text('przyjęto ' + fmtQty(p.received) + ' z ' + fmtQty(p.ordered));
        $('#receiveProgressBar')
            .attr('aria-valuenow', Math.round(pct))
            .attr('title', 'Przyjęto ' + fmtQty(p.received) + ' z ' + fmtQty(p.ordered))
            .children('span').toggleClass('is-done', pct >= 100).css('width', pct.toFixed(1) + '%');
    }

    // ---- nagłówek formularza -------------------------------------------

    // Zmiana magazynu w nagłówku przestawia tylko te wiersze, w których
    // nikt sam nie wybrał innego magazynu.
    $magSelect.on('changed.bs.select change', function() {
        receiveState.headerMagazineId = String($(this).val() || '');
        $linesBody.find('.line-magazine').each(function() {
            if ($(this).attr('data-overridden') === '1') return;
            $(this).val(receiveState.headerMagazineId);
        });
    });

    $linesBody.on('change', '.line-magazine', function() {
        $(this).attr('data-overridden', '1');
    });

    $('#toggleReceivedAt').on('click', function(e) {
        e.preventDefault();
        const $wrap = $('#receivedAtWrap');
        const opening = $wrap.hasClass('d-none');
        $wrap.toggleClass('d-none', !opening);
        receiveState.receivedAtOpened = opening;
        if (!opening) $('#receivedAt').val(todayIso());
        $(this).html(opening
            ? '<i class="bi bi-x-lg"></i> Użyj dzisiejszej daty'
            : '<i class="bi bi-calendar-event"></i> Zmień datę przyjęcia');
        $('#receivedAtHint').text(opening
            ? 'Dostawa wpisywana wstecz.'
            : 'Domyślnie dzisiejsza data.');
        if (opening) $('#receivedAt').trigger('focus');
    });

    // ---- masowe wypełnianie --------------------------------------------

    $('#receiveFillAllBtn').on('click', function() {
        $linesBody.find('tr.receipt-line').each(function() {
            const $row = $(this);
            const item = itemOfRow($row);
            if (!item || isDone(item)) return;
            const pack = packSizeOf(item);
            const remaining = num(item.quantityRemaining);
            const $qty = $row.find('.line-qty');
            $qty.val(trimNum(remaining, 6));
            if (pack !== null) $row.find('.line-packages').val(trimNum(remaining / pack, 2));
            applyEvenWarning($row, $qty, remaining, pack);
        });
        refreshSummary();
    });

    $('#receiveClearAllBtn').on('click', function() {
        $linesBody.find('tr.receipt-line').each(function() {
            const $row = $(this);
            $row.find('.line-qty').val('');
            $row.find('.line-packages').val('');
            applyEvenWarning($row, null, NaN, null);
        });
        refreshSummary();
    });

    // Powrót do listy — prawdziwa nawigacja (nie history.pushState),
    // żeby kolejka + historia przeładowały się z aktualnym stanem
    // (PO po zapisie zmienia stan na partially_received/received i
    // powinno zniknąć z kolejki).
    function goBackToList() {
        window.location.href = ROOT_DIR + '/purchase/receipts';
    }

    $('#receiveBackBtn, #receiveCancelBtn').on('click', function() {
        if (receiveState.submitting) return;
        goBackToList();
    });

    // ---- zapis ----------------------------------------------------------

    function setSubmitting(on) {
        receiveState.submitting = on;
        $('#receiveSubmitBtn')
            .prop('disabled', true)
            .html(on
                ? '<span class="spinner-border spinner-border-sm"></span> Zapisywanie…'
                : '<i class="bi bi-check2-circle"></i> Zapisz przyjęcie');
        $('#receiveCancelBtn, #receiveBackBtn, #receiveFillAllBtn, #receiveClearAllBtn, #receiptDocumentEditBtn')
            .prop('disabled', on);
        if (!on) refreshSummary();
    }

    // Ten sam query string niesie info o zapisie do receipts-view.js
    // (alert + podświetlenie wiersza). Kodujemy, bo numery PO/GRN mogą
    // zawierać '/', '&' i spacje.
    function redirectToList(documentNumber, n, poState) {
        const params = [
            'saved='    + encodeURIComponent(documentNumber || ''),
            'n='        + encodeURIComponent(n),
            'po='       + encodeURIComponent(receiveState.poNumber),
            'po_state=' + encodeURIComponent(poState || '')
        ];
        window.location.href = ROOT_DIR + '/purchase/receipts?' + params.join('&');
    }

    // ---- okno „Przyjęcie zapisane" (są braki) ---------------------------
    //
    // Pytanie jest jedno: zostawić zamówienie otwarte (domyślnie; każde
    // zamknięcie okna — przycisk, ×, Escape — znaczy to samo) czy oznaczyć
    // jako odebrane. Przekierowanie następuje dopiero po decyzji.

    const $shortModal = $('#receiveShortModal');
    let shortCtx = null;   // { r, n, closing, leaving }

    function shortTitle(n) {
        const last = n % 10, last2 = n % 100;
        let w = 'nieukończonych';
        if (n === 1) w = 'nieukończona';
        else if (last >= 2 && last <= 4 && (last2 < 12 || last2 > 14)) w = 'nieukończone';
        const noun = n === 1 ? 'pozycja' : ((last >= 2 && last <= 4 && (last2 < 12 || last2 > 14)) ? 'pozycje' : 'pozycji');
        return 'Przyjęcie zapisane — ' + n + ' ' + noun + ' ' + w;
    }

    function openShortModal(r, savedItems) {
        // Ilości zapisane w tym przyjęciu → postęp PO „po zapisie".
        const saved = {};
        for (let i = 0; i < savedItems.length; i++) {
            for (let k = 0; k < receiveState.items.length; k++) {
                if (parseInt(receiveState.items[k].poItemId, 10) === savedItems[i].po_item_id) {
                    saved[k] = (saved[k] || 0) + savedItems[i].quantity_received;
                }
            }
        }
        const p = computeProgress(saved);
        const pct = p.ordered > 0 ? Math.max(0, Math.min(100, (p.received / p.ordered) * 100)) : 0;
        const linesTotal = parseInt(r.lines_total, 10) || p.linesTotal;
        const linesDone  = (r.lines_received === undefined || r.lines_received === null)
            ? p.linesDone : (parseInt(r.lines_received, 10) || 0);

        $('#receiveShortTitle').text(shortTitle(r.short_items.length));
        $('#receiveShortDoc').text(r.document_number || '');
        $('#receiveShortProgressLines').html('<strong>' + linesDone + '</strong> z ' + linesTotal + ' pozycji');
        $('#receiveShortProgressPct').text(Math.round(pct) + '%');
        $('#receiveShortProgressSub').text('przyjęto ' + fmtQty(p.received) + ' z ' + fmtQty(p.ordered));
        $('#receiveShortBar')
            .attr('aria-valuenow', Math.round(pct))
            .attr('title', 'Przyjęto ' + fmtQty(p.received) + ' z ' + fmtQty(p.ordered))
            .children('span').toggleClass('is-done', pct >= 100).css('width', pct.toFixed(1) + '%');

        let html = '';
        $.each(r.short_items, function(_, s) {
            const unit = s.unitName ? (' ' + s.unitName) : '';
            html += '<li class="receipt-short-row">'
                + '<span class="receipt-short-no">' + esc(s.vendorPartNo || '—') + '</span>'
                + '<span class="receipt-short-name text-muted" title="' + escAttr(s.partName || '') + '">' + esc(s.partName || '') + '</span>'
                + '<span class="receipt-short-qty">przyjęto ' + esc(fmtQty(s.received)) + ' z ' + esc(fmtQty(s.ordered)) + esc(unit)
                + ' · brakuje <strong>' + esc(fmtQty(s.remaining)) + esc(unit) + '</strong></span>'
                + '</li>';
        });
        $('#receiveShortList').html(html);
        $('#receiveShortError').addClass('d-none').empty();

        shortCtx = { r: r, n: savedItems.length, closing: false, leaving: false };
        $shortModal.modal('show');
    }

    // Wyjście z okna = „Zostaw otwarte": stan PO zostaje taki, jaki ustawił serwer.
    function leaveShortModal(poState) {
        if (!shortCtx || shortCtx.leaving) return;
        shortCtx.leaving = true;
        redirectToList(shortCtx.r.document_number, shortCtx.n, poState || shortCtx.r.po_state);
    }

    // W trakcie zamykania PO okna nie da się zamknąć (Escape/×).
    $shortModal.on('hide.bs.modal', function(e) {
        if (shortCtx && shortCtx.closing) e.preventDefault();
    });
    $shortModal.on('hidden.bs.modal', function() {
        leaveShortModal();
    });
    $shortModal.on('shown.bs.modal', function() {
        $('#receiveShortKeepBtn').trigger('focus');
    });

    $('#receiveShortCloseBtn').on('click', function() {
        if (!shortCtx || shortCtx.closing) return;
        const $btn = $(this);
        const originalHtml = $btn.html();
        shortCtx.closing = true;
        $btn.prop('disabled', true)
            .html('<span class="spinner-border spinner-border-sm"></span> Zamykanie…');
        $('#receiveShortKeepBtn').prop('disabled', true);
        $('#receiveShortError').addClass('d-none').empty();

        const restore = function() {
            shortCtx.closing = false;
            $btn.prop('disabled', false).html(originalHtml);
            $('#receiveShortKeepBtn').prop('disabled', false);
        };

        getAjax('receipt-close-po.php', { po_id: receiveState.poId })
            .done(function(res) {
                if (!res || !res.success) {
                    restore();
                    $('#receiveShortError')
                        .text((res && res.error) || 'Nie udało się zamknąć zamówienia.')
                        .removeClass('d-none');
                    return;
                }
                leaveShortModal(res.po_state || 'received');
            })
            .fail(function(xhr, status) {
                restore();
                $('#receiveShortError')
                    .text((xhr.responseJSON && xhr.responseJSON.error) || status || 'Błąd komunikacji z serwerem')
                    .removeClass('d-none');
            });
    });

    // ---- okno „Zamknij zamówienie" (akcja z paska formularza) ----------
    //
    // Cała logika (modal, AJAX, redirect) żyje teraz w close-po.js
    // (window.ReceiptClosePo.open). Tu zostaje tylko cienki binding
    // #receiveClosePoBtn → wywołanie z agregatami z receiveState.
    // close-po.js jest ładowany po receive-page.js (patrz receive-page.php),
    // więc API jest dostępne gdy ten binding się odpala.

    $('#receiveClosePoBtn').on('click', function() {
        // Ten sam bramkarz co przy zapisie — żeby podwójne kliknięcia
        // nie otwierały dwóch okien naraz. (Zamknięcie nie blokuje
        // stanu submit-formy, ale spójność z resztą modali ma sens.)
        if (receiveState.submitting) return;
        if (!window.ReceiptClosePo) {
            // close-po.js nie załadował się (np. edge-case w przyszłości).
            // Bez tego rozwala się flow — lepiej zatrzymać klik niż
            // wysłać pusty request.
            showAlert('Moduł zamykania zamówienia nie jest dostępny.', 'danger');
            return;
        }
        const items = receiveState.items || [];
        // Z formularza przyjęcia znamy pozycje per-PO — przekazujemy je
        // wprost, modal pokaże listę nieodebranych pozycji (max 5).
        window.ReceiptClosePo.open({
            poId:     receiveState.poId,
            poNumber: receiveState.poNumber,
            items:    items,
            // orderedTotal/receivedTotal/lineCount open() przeliczy z items.
        });
    });

    $('#receiveSubmitBtn').on('click', function() {
        if (receiveState.submitting) return;
        // Ostatnia bramka kroku 1 — przycisk jest wyłączony, ale klik
        // mógłby przyjść z klawiatury tuż przed przemalowaniem.
        if (!step1Accepted) {
            showAlert('Najpierw zatwierdź dokument przyjęcia („Akceptuję").', 'warning');
            return;
        }

        const items = [];

        $linesBody.find('tr.receipt-line').each(function() {
            const $row = $(this);
            const item = itemOfRow($row);
            if (!item || isDone(item)) return;
            // Odznaczony checkbox = pozycja pomijana w tym przyjęciu
            // (partial reception: bierzemy tylko zaznaczone).
            if (!$row.find('.line-receive-toggle').is(':checked')) return;

            const raw = $.trim(String($row.find('.line-qty').val()));
            if (raw === '') return;
            const qty = parseFloat(raw);
            if (isNaN(qty) || qty <= 0) return;      // tylko wypełnione pozycje
            // Znacznie większa ilość niż oczekiwana jest tylko ostrzeżeniem
            // (czerwone w validateRow) — nie blokuje zapisu.

            items.push({
                po_item_id:        parseInt(item.poItemId, 10),
                quantity_received: qty,
                sub_magazine_id:   parseInt($row.find('.line-magazine').val(), 10),
                comment:           $.trim(String($row.find('.line-comment').val() || ''))
            });
        });

        if (items.length === 0) {
            showAlert('Wpisz ilość w co najmniej jednej pozycji.', 'warning');
            return;
        }

        // Struktura, nie JSON-string — jQuery wyśle items[0][po_item_id]=…,
        // dzięki czemu serwer czyta zwykłe $_POST (AGENTS.md).
        const payload = {
            po_id:                  receiveState.poId,
            comment:                $('#receiptComment').val(),
            vendor_document_number: $.trim(String($('#vendorDocumentNumber').val() || '')),
            items:                  items
        };
        // received_at leci tylko wtedy, gdy ktoś świadomie otworzył pole
        // daty — inaczej datę ustawia serwer.
        if (receiveState.receivedAtOpened && $('#receivedAt').val()) {
            payload.received_at = $('#receivedAt').val();
        }

        setSubmitting(true);
        $.ajax({
            url: ajaxBase + 'receipt-create.php',
            type: 'POST',
            data: payload,
            dataType: 'json'
        })
            .done(function(r) {
                if (!r || !r.success) {
                    setSubmitting(false);
                    showAlert(esc((r && r.error) || 'Nie udało się zapisać przyjęcia.'), 'danger');
                    return;
                }
                // Przyjęcie jest już zapisane — przy brakach pytamy, co
                // z zamówieniem, i dopiero potem przekierowujemy. Przycisk
                // zapisu zostaje zablokowany (setSubmitting(false) tu NIE
                // wołamy), żeby nie dało się zapisać drugiego GRN.
                const shortItems = $.isArray(r.short_items) ? r.short_items : [];
                if (r.has_short && shortItems.length > 0) {
                    openShortModal(r, items);
                    return;
                }
                setSubmitting(false);
                // Sukces — prawdziwy reload na listę z query stringiem
                // niosącym info o zapisie. receipts-view.js czyta to przy
                // starcie, wyświetla alert (success) i podświetla nowy
                // wiersz historii (klasa .receipt-row-new, animacja w
                // receipts.css). Parametry kodujemy przez encodeURIComponent,
                // bo numery PO i komentarze mogą zawierać '/', '&', spacje.
                redirectToList(r.document_number, items.length, r.po_state);
            })
            .fail(function(xhr, status) {
                setSubmitting(false);
                showAlert(esc((xhr.responseJSON && xhr.responseJSON.error) || status || 'Błąd komunikacji z serwerem'), 'danger');
            });
    });

    // =====================================================================
    // Start
    // =====================================================================

    if ($view.length === 0) return; // formularz nie istnieje na stronie

    const poId = parseInt($view.attr('data-po-id'), 10);
    if (!poId || poId <= 0) {
        // Strona powinna nie dopuścić do tego (receive-page.php sama
        // wyświetla alert przy brakującym ?po_id=), ale dla pewności:
        $linesBody.html('<tr><td colspan="10" class="text-center text-danger py-4">'
            + 'Brak identyfikatora zamówienia.</td></tr>');
        $('#receiveSubmitBtn').prop('disabled', true);
        return;
    }

    openReceiveForm(poId);
});