// Współdzielona logika dla modali „Zamknij zamówienie" i „Anuluj zamówienie".
//
// Dwa wejścia korzystają z tego samego pliku:
//
//   1. /purchase/receipts/receive — przycisk #receiveClosePoBtn w pasku
//      akcji formularza przyjęcia. JS na tej stronie robi tylko:
//
//          $('#receiveClosePoBtn').on('click', function () {
//              window.ReceiptClosePo.open({
//                  poId:          receiveState.poId,
//                  poNumber:      receiveState.poNumber,
//                  items:         receiveState.items,
//                  orderedTotal:  ...,
//                  receivedTotal: ...,
//                  lineCount:     receiveState.items.length
//              });
//          });
//
//   2. /purchase/receipts — kolejka „Do przyjęcia". Delegowane handlery
//      na $(document) reagują na data-action="close-po" i
//      data-action="cancel-po" (patrz sekcja „Handler delegation" niżej).
//      Kolejka nie zna pozycji per-PO, więc przy close-po wywołuje
//      window.ReceiptClosePo.open() BEZ `items` — modal wtedy ukrywa
//      sekcję z listą pozycji i pokazuje tylko numer + agregaty.
//
// Modal markup: close-po-modal.php (współdzielony partial).
// Endpointy:   receipts/receipt-close-po.php        (close-with-choice)
//              documents/document-cancel-ajax.php    (cancel-only)
//
// Konwencje: Bootstrap 4 jQuery API ($('#…').modal('show'/'hide')),
// brak `var`, własne helpery esc/escAttr/fmtQty/pozycjeWord (identyczne
// jak w receive-page.js / receipts-view.js, ale lokalne — moduł nie
// zależy od innego skryptu na stronie).

$(document).ready(function () {

    // ---- Helpers (lokalne — zgodne z resztą modułu, ale bez zależności) ----

    function esc(str) {
        return $('<div>').text(str == null ? '' : String(str)).html();
    }

    function escAttr(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function fmtQty(n) {
        const v = parseFloat(n);
        if (isNaN(v)) return '—';
        return v.toLocaleString('pl-PL', { maximumFractionDigits: 4 });
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

    // Ścieżki bazowe. Jeśli strona nie ładuje header.js (np. inna
    // podstrona w przyszłości), spadamy na te same wartości — modal
    // potrzebuje tylko ROOT_DIR do redirectu i COMPONENTS_PATH do AJAX.
    const ROOT_DIR = (typeof window.ROOT_DIR !== 'undefined') ? window.ROOT_DIR : '/atte_ms_new';
    const COMPONENTS_PATH = (typeof window.COMPONENTS_PATH !== 'undefined')
        ? window.COMPONENTS_PATH
        : '/atte_ms_new/public_html/components';

    function getAjax(endpoint, data) {
        // POST wymuszony przez .htaccess (AGENTS.md).
        return $.ajax({
            url: COMPONENTS_PATH + '/purchases/' + endpoint,
            type: 'POST',
            data: data,
            dataType: 'json'
        });
    }

    // Ładujemy endpoint document-cancel-ajax.php z osobnej ścieżki —
    // COMPONENTS_PATH + '/purchases/documents/' zamiast /receipts/.
    function cancelAjax(data) {
        return $.ajax({
            url: COMPONENTS_PATH + '/purchases/documents/document-cancel-ajax.php',
            type: 'POST',
            data: data,
            dataType: 'json'
        });
    }

    // ---- DOM hooks ------------------------------------------------------

    // Modale są renderowane w PHP — instancje mogą nie istnieć na
    // każdej stronie (np. na stronie edycji PO, która też ładuje ten
    // skrypt, ale nie dołącza close-po-modal.php). Sprawdzamy .length
    // w każdym publicznym wywołaniu zamiast na starcie, żeby skrypt
    // był bezpieczny do wpięcia na dowolną stronę.
    function $closeModal()  { return $('#receiveClosePoModal'); }
    function $cancelModal() { return $('#receiveCancelPoModal'); }

    // ---- open(): modal „Zamknij zamówienie" z wyborem received/cancelled

    // Argumenty (poza poId/poNumber reszta jest opcjonalna):
    //   poId           int     wymagane
    //   poNumber       string — etykieta w UI; '' lub '#id' gdy brak
    //   items          array? — pełne pozycje (jak w formularzu
    //                          przyjęcia). Pusty/undefined → chowamy
    //                          sekcję z listą (kolejka zna tylko
    //                          agregaty).
    //   orderedTotal   number  — suma zamówiona. Opcjonalne, gdy items
    //                            podane — liczone z items. Gdy brak
    //                            items → caller MUSI podać.
    //   receivedTotal  number  — j.w.
    //   lineCount      number  — liczba pozycji (do „przyjęto X z Y pozycji").
    //                           Opcjonalne, gdy items podane.
    //   backUrl        string? — opcjonalny URL powrotu po sukcesie
    //                            (pełny, z hostem — caller używa
    //                            `appUrl(...)`). Bez niego moduł
    //                            wraca na /purchase/receipts (domyślne
    //                            zachowanie formularza przyjęcia +
    //                            kolejki). Strona /purchase/documents
    //                            przekazuje swoją ścieżkę.
    function open(opts) {
        if (!opts || !opts.poId) {
            // Błąd programistyczny (caller bez poId) — nie ma sensu
            // otwierać modala. Globalny alert, jeśli istnieje
            // (header.js go dostarcza), w przeciwnym razie konsola.
            if (typeof window.setAlert === 'function') {
                window.setAlert('Brak identyfikatora zamówienia.', 'danger');
            } else {
                console.error('ReceiptClosePo.open: brak poId');
            }
            return;
        }

        const $modal = $closeModal();
        if ($modal.length === 0) {
            console.error('ReceiptClosePo.open: #receiveClosePoModal nie istnieje na tej stronie');
            return;
        }

        const poId     = parseInt(opts.poId, 10);
        const poNumber = opts.poNumber || ('#' + poId);
        const items    = Array.isArray(opts.items) ? opts.items : null;

        let orderedTotal  = 0;
        let receivedTotal = 0;
        let lineCount     = 0;
        let outstanding   = [];

        if (items !== null) {
            // Formularz przyjęcia — znamy pozycje per-PO.
            for (let i = 0; i < items.length; i++) {
                const it = items[i];
                const ord = Math.max(0, parseFloat(it.quantityOrdered) || 0);
                const got = Math.max(0, parseFloat(it.quantityReceived) || 0);
                orderedTotal  += ord;
                receivedTotal += got;
                lineCount++;
                if (ord - got > 1e-9) outstanding.push(it);
            }
        } else {
            // Kolejka — caller podał agregaty (albo same poId/poNumber,
            // a wtedy i tak wyświetlamy tylko numer PO).
            orderedTotal  = parseFloat(opts.orderedTotal)  || 0;
            receivedTotal = parseFloat(opts.receivedTotal) || 0;
            lineCount     = parseInt(opts.lineCount, 10)   || 0;
        }

        // Podsumowanie (numer PO + agregaty). Różni się między
        // formularzem (zna pozycje) a kolejką (ma agregaty), ale
        // tekst jest ten sam: „przyjęto X z Y pozycji".
        const summaryHtml = '<i class="bi bi-check-circle-fill"></i>'
            + ' Zamówienie <strong>' + esc(poNumber) + '</strong>'
            + ' — przyjęto <strong>' + esc(fmtQty(receivedTotal)) + '</strong>'
            + ' z <strong>' + esc(fmtQty(orderedTotal)) + '</strong>'
            + (lineCount > 0 ? ' ' + esc(pozycjeWord(lineCount)) + '.' : '.');
        $('#receiveClosePoSummary').html(summaryHtml);

        // Lista nieodebranych pozycji — TYLKO gdy caller przekazał items.
        // Bez items chowamy i nagłówek, i listę (kolejka nie zna pozycji,
        // nie pokazujemy "Wszystkie pozycje są już w pełni odebrane." —
        // to byłoby mylące, bo nie wiemy czy to prawda).
        const $heading = $('#receiveClosePoHeading');
        const $list    = $('#receiveClosePoList');
        if (items === null) {
            $heading.addClass('d-none');
            $list.empty();
        } else {
            $heading.removeClass('d-none');
            const MAX_VISIBLE = 5;
            const visible = outstanding.slice(0, MAX_VISIBLE);
            let listHtml = '';
            for (let i = 0; i < visible.length; i++) {
                const it = visible[i];
                const unit = it.unitName ? (' ' + it.unitName) : '';
                listHtml += '<li class="receipt-close-row">'
                    + '<span class="receipt-close-no">' + esc(it.vendorPartNo || '—') + '</span>'
                    + '<span class="receipt-close-name text-muted" title="' + escAttr(it.partName || '') + '">'
                    + esc(it.partName || '') + '</span>'
                    + '<span class="receipt-close-qty">przyjęto ' + esc(fmtQty(it.quantityReceived))
                    + ' z ' + esc(fmtQty(it.quantityOrdered)) + esc(unit) + '</span>'
                    + '</li>';
            }
            if (outstanding.length > MAX_VISIBLE) {
                listHtml += '<li class="receipt-close-more">+ '
                    + (outstanding.length - MAX_VISIBLE) + ' więcej '
                    + esc(pozycjeWord(outstanding.length - MAX_VISIBLE)) + '</li>';
            }
            if (outstanding.length === 0 && items.length > 0) {
                listHtml = '<li class="receipt-close-more text-muted">Wszystkie pozycje są już w pełni odebrane.</li>';
            }
            $list.html(listHtml);
        }

        // Reset: domyślnie „Odebrane" (zachowanie wstecz dla scenariusza
        // „zamknij PO z niedoborem" — najczęstszy), błąd ukryty.
        // Podświetlenie zaznaczonej opcji przez klasę .is-selected
        // (nie :has() — przeglądarki bez :has() nie dostałyby podświetlenia).
        $('input[name="receiveCloseTarget"][value="received"]').prop('checked', true);
        syncSelectedOption();
        $('#receiveClosePoError').addClass('d-none').empty();

        // Zapisz identyfikator w data-* na samym modalu, żeby handler
        // confirm mógł go odczytać bez domykania stanu w zmiennej
        // (modale są re-używane dla różnych PO).
        $modal.attr('data-current-po-id', poId).attr('data-current-po-number', poNumber);

        // backUrl opcjonalny — formularz przyjęcia + kolejka nie
        // przekazują (zostaje domyślna ścieżka /purchase/receipts),
        // strona dokumentów przekazuje appUrl('/purchase/documents').
        if (opts.backUrl) {
            $modal.attr('data-current-back-url', String(opts.backUrl));
        } else {
            $modal.removeAttr('data-current-back-url');
        }

        $modal.modal('show');
    }

    // ---- openCancel(): modal „Anuluj zamówienie" (cancel-only)

    // Argumenty:
    //   poId      int    wymagane
    //   poNumber  string — etykieta w UI; '' lub '#id' gdy brak
    //   backUrl   string? — j.w. (opcjonalny URL powrotu)
    //   docType   'po'|'rfq' — rodzaj dokumentu; steruje wysyłanym
    //             typem do document-cancel-ajax.php oraz tekstem okna
    //             (zamówienie / zapytanie ofertowe). Domyślnie 'po'.
    function openCancel(opts) {
        if (!opts || !opts.poId) {
            if (typeof window.setAlert === 'function') {
                window.setAlert('Brak identyfikatora dokumentu.', 'danger');
            } else {
                console.error('ReceiptClosePo.openCancel: brak poId');
            }
            return;
        }

        const $modal = $cancelModal();
        if ($modal.length === 0) {
            console.error('ReceiptClosePo.openCancel: #receiveCancelPoModal nie istnieje na tej stronie');
            return;
        }

        const poId     = parseInt(opts.poId, 10);
        const poNumber = opts.poNumber || ('#' + poId);
        const isRfq    = (opts.docType || 'po') === 'rfq';

        // Zapytanie ofertowe nie ma „dostawy" — anulowanie oznacza, że
        // odpowiedzi nie będziemy szukać. Zakup jest w drugiej części
        // zdania, więc dla RFQ mówimy o wycofaniu zapytania.
        const subject = isRfq ? 'Zapytanie ofertowe' : 'Zamówienie';
        const summaryHtml = subject + ' <strong>' + esc(poNumber) + '</strong>'
            + ' zostanie <strong>anulowane</strong>. Anulowanie oznacza, że '
            + (isRfq ? 'odpowiedzi nie będziemy szukać.'
                     : 'dostawa się nie odbędzie.');
        $('#receiveCancelPoSummary').html(summaryHtml);
        $('#receiveCancelPoError').addClass('d-none').empty();

        $modal.attr('data-current-po-id', poId).attr('data-current-po-number', poNumber)
              .attr('data-current-doc-type', isRfq ? 'rfq' : 'po');
        if (opts.backUrl) {
            $modal.attr('data-current-back-url', String(opts.backUrl));
        } else {
            $modal.removeAttr('data-current-back-url');
        }
        $modal.modal('show');
    }

    // ---- Helpers: podświetlenie wybranej opcji radia -------------------

    // Poprzednia implementacja używała selektora :has(input:checked),
    // ale to nie działa w starszych przeglądarkach (Chrome <105,
    // Safari <15.4, Firefox <121). Klasa .is-selected togglowana
    // przez JS działa wszędzie.
    function syncSelectedOption() {
        $('.receipt-close-option').each(function () {
            const $opt = $(this);
            const $radio = $opt.find('input[type="radio"]');
            $opt.toggleClass('is-selected', $radio.is(':checked'));
        });
    }

    $(document).on('change', 'input[name="receiveCloseTarget"]', syncSelectedOption);

    // ---- Submit: close-with-choice -------------------------------------

    $(document).on('click', '#receiveClosePoConfirmBtn', function () {
        const $modal = $closeModal();
        if ($modal.length === 0) return;
        const $btn = $(this);
        if ($btn.prop('disabled')) return;
        const originalHtml = $btn.html();
        const poId     = parseInt($modal.attr('data-current-po-id'), 10);
        const poNumber = $modal.attr('data-current-po-number') || '';
        const targetState = $('input[name="receiveCloseTarget"]:checked').val() || 'received';

        if (!poId || poId <= 0) {
            if (typeof window.setAlert === 'function') {
                window.setAlert('Brak identyfikatora zamówienia.', 'danger');
            }
            return;
        }

        $btn.prop('disabled', true)
            .html('<span class="spinner-border spinner-border-sm"></span> Zamykanie…');
        $('#receiveClosePoError').addClass('d-none').empty();

        const restore = function () {
            $btn.prop('disabled', false).html(originalHtml);
        };

        // URL powrotu po sukcesie — caller mógł ustawić data-current-back-url
        // (np. strona dokumentów). Brak → domyślna ścieżka modułu
        // (kolejka i formularz przyjęcia).
        const backUrl = $modal.attr('data-current-back-url')
            || (ROOT_DIR + '/purchase/receipts');

        getAjax('receipts/receipt-close-po.php', {
            po_id:       poId,
            target_state: targetState
        })
            .done(function (res) {
                if (!res || !res.success) {
                    restore();
                    $('#receiveClosePoError')
                        .text((res && res.error) || 'Nie udało się zamknąć zamówienia.')
                        .removeClass('d-none');
                    return;
                }
                // Sukces — zamknij modal i przekieruj na listę z ?closed.
                // Ten sam query string co w poprzedniej implementacji
                // (receipts-view.js / documents-view.js go czytają
                // i pokazują alert).
                $modal.modal('hide');
                window.location.href = backUrl + '?closed='
                    + encodeURIComponent(res.po_state || targetState)
                    + '&po=' + encodeURIComponent(poNumber);
            })
            .fail(function (xhr, status) {
                restore();
                $('#receiveClosePoError')
                    .text((xhr.responseJSON && xhr.responseJSON.error) || status || 'Błąd komunikacji z serwerem')
                    .removeClass('d-none');
            });
    });

    // ---- Submit: cancel-only -------------------------------------------

    $(document).on('click', '#receiveCancelPoConfirmBtn', function () {
        const $modal = $cancelModal();
        if ($modal.length === 0) return;
        const $btn = $(this);
        if ($btn.prop('disabled')) return;
        const originalHtml = $btn.html();
        const poId     = parseInt($modal.attr('data-current-po-id'), 10);
        const poNumber = $modal.attr('data-current-po-number') || '';
        // Rodzaj dokumentu ustawiony przy otwarciu okna — 'po' albo 'rfq'.
        // document-cancel-ajax.php używa go, żeby wybrać cancelPo() czy
        // cancelRfq(); bez tego każdy RFQ zostałby anulowany jako PO.
        const docType  = ($modal.attr('data-current-doc-type') === 'rfq') ? 'rfq' : 'po';
        const subject  = (docType === 'rfq') ? 'zapytania ofertowego' : 'zamówienia';
        // j.w. — brak data-current-back-url → domyślna ścieżka modułu.
        const backUrl = $modal.attr('data-current-back-url')
            || (ROOT_DIR + '/purchase/receipts');

        if (!poId || poId <= 0) {
            if (typeof window.setAlert === 'function') {
                window.setAlert('Brak identyfikatora dokumentu.', 'danger');
            }
            return;
        }

        $btn.prop('disabled', true)
            .html('<span class="spinner-border spinner-border-sm"></span> Anulowanie…');
        $('#receiveCancelPoError').addClass('d-none').empty();

        const restore = function () {
            $btn.prop('disabled', false).html(originalHtml);
        };

        cancelAjax({ type: docType, id: poId })
            .done(function (res) {
                if (!res || !res.success) {
                    restore();
                    $('#receiveCancelPoError')
                        .text((res && res.error) || ('Nie udało się anulować ' + subject + '.'))
                        .removeClass('d-none');
                    return;
                }
                // Sukces — zamknij modal i przekieruj na listę z ?closed.
                // Ten sam kształt alertu co close-with-choice, dzięki
                // czemu receipts-view.js / documents-view.js mają jeden
                // branch dla obu ścieżek.
                $modal.modal('hide');
                window.location.href = backUrl + '?closed='
                    + encodeURIComponent(res.state || 'cancelled')
                    + '&po=' + encodeURIComponent(poNumber);
            })
            .fail(function (xhr, status) {
                restore();
                $('#receiveCancelPoError')
                    .text((xhr.responseJSON && xhr.responseJSON.error) || status || 'Błąd komunikacji z serwerem')
                    .removeClass('d-none');
            });
    });

    // ---- Publiczne API --------------------------------------------------

    window.ReceiptClosePo = {
        open:       open,
        openCancel: openCancel
    };

    // ---- Delegowane handlery dla kolejek (receipts + documents) ---------
    //
    // Przyciski w wierszach kolejki są przemalowywane przy każdym
    // przeładowaniu (loadQueue / loadDocuments), więc nie można
    // bindować bezpośrednio na .btn. Delegacja na $(document) działa
    // też wtedy, gdy skrypt jest ładowany PO wyrenderowaniu wierszy
    // (normalna kolejność: skrypt → DOM → AJAX → render — ale
    // defensywnie).
    //
    // data-action="close-po" → wiersze, w których PO jest w stanie
    //                         receive-eligible (operator może wybrać
    //                         received lub cancelled);
    // data-action="cancel-po" → wiersze PO w stanie `sent` (jedyną
    //                          legalną opcją jest anulowanie).
    //
    // Opcjonalny atrybut data-back-url na triggerze zmienia stronę
    // powrotu po sukcesie (np. /purchase/documents zamiast domyślnego
    // /purchase/receipts). Brak atrybutu → stare zachowanie.
    // Opcjonalny data-doc-type ('rfq') przełącza anulowanie na
    // cancelRfq() i zmienia tekst okna; brak → 'po'.

    $(document).on('click', '[data-action="close-po"]', function (e) {
        e.preventDefault();
        e.stopPropagation();
        const $trigger = $(this);
        const poId   = parseInt($trigger.attr('data-po-id'), 10);
        const number = $trigger.attr('data-po-number') || '';
        if (!poId || poId <= 0) return;

        // Agregaty z data-* (kolejka / documents nie znają pozycji
        // per-PO). Brak agregatów (np. dokument) → modal i tak
        // wyświetli tylko numer PO + „przyjęto 0 z 0".
        const ordered    = parseFloat($trigger.attr('data-ordered-qty'))  || 0;
        const received   = parseFloat($trigger.attr('data-received-qty')) || 0;
        const lineCount  = parseInt($trigger.attr('data-line-count'), 10) || 0;
        const backUrl    = $trigger.attr('data-back-url') || '';

        const opts = {
            poId:          poId,
            poNumber:      number,
            // items=null (nie przekazujemy) → modal ukryje sekcję z listą.
            orderedTotal:  ordered,
            receivedTotal: received,
            lineCount:     lineCount
        };
        if (backUrl) opts.backUrl = backUrl;
        open(opts);
    });

    $(document).on('click', '[data-action="cancel-po"]', function (e) {
        e.preventDefault();
        e.stopPropagation();
        const $trigger = $(this);
        const poId   = parseInt($trigger.attr('data-po-id'), 10);
        const number = $trigger.attr('data-po-number') || '';
        if (!poId || poId <= 0) return;
        const backUrl = $trigger.attr('data-back-url') || '';
        const docType = $trigger.attr('data-doc-type') || '';
        const opts = { poId: poId, poNumber: number };
        if (backUrl) opts.backUrl = backUrl;
        if (docType) opts.docType = docType;
        openCancel(opts);
    });
});