// Przyjęcia towaru — /purchase/receipts.
//
// Strona renderuje TYLKO listę: zakładka "Do przyjęcia" (kolejka
// zamówień oczekujących na towar) + zakładka "Historia przyjęć".
// Formularz przyjęcia wyodrębniony do osobnej strony
// /purchase/receipts/receive?po_id=N — patrz receive-page.php +
// receive-page.js.
//
// Konwencja nawigacji do formularza (identyczna z cart-view.js →
// docEditUrl()): window.location.origin + ROOT_DIR + '/purchase/
// receipts/receive?po_id=' + poId. ROOT_DIR to prefix ścieżki z
// header.js (np. "/atte_ms_new"); cart-view.js ma komentarz o tym,
// dlaczego nie 'http://' + ROOT_DIR (dałoby http:///atte_ms_new/…,
// martwy link).
//
// Po zapisie formularz przekierowuje na /purchase/receipts z query
// stringiem ?saved=<doc>&n=<items>&po=<po>&po_state=<state>.
// Ten plik czyta to przy starcie (po applyHistoryView()), wyświetla
// alert sukcesu i podświetla nowy wiersz historii (klasa
// .receipt-row-new, animacja w receipts.css).
//
// Endpointy (POST, .htaccess blokuje GET, AGENTS.md):
//   receipts-queue.php   lista zamówień oczekujących na towar
//   receipt-get.php      szczegóły zapisanego przyjęcia (modal)

$(document).ready(function() {
    const ajaxBase = COMPONENTS_PATH + "/purchases/receipts/";

    // Ile wierszy historii na stronę. Historia jest renderowana przez PHP
    // w całości, więc paginacja jest po stronie klienta (pokaż/ukryj) —
    // żaden wiersz nie znika z DOM, wszystkie są osiągalne.
    const HISTORY_PER_PAGE = 25;

    // =====================================================================
    // Drobiazgi wspólne
    // =====================================================================

    // Escape do treści HTML (tekst między tagami).
    function esc(str) {
        return $('<div>').text(str == null ? '' : String(str)).html();
    }

    function getAjax(endpoint, data) {
        // POST jest wymagany przez .htaccess (AGENTS.md) — GET do AJAX
        // endpointów pod /atte_ms_new/public_html/... jest blokowany i
        // zwraca parsererror.
        return $.ajax({ url: ajaxBase + endpoint, type: 'POST', data: data, dataType: 'json' });
    }

    // Liczby do wyświetlania (nie do inputów) — separator tysięcy robi
    // realną robotę przy odczycie 5 000 vs 50 000 z tabeli.
    function fmtQty(n) {
        const v = parseFloat(n);
        if (isNaN(v)) return '—';
        return v.toLocaleString('pl-PL', { maximumFractionDigits: 4 });
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
            case 'partially_received': return 'badge-warning';
            case 'received':           return 'badge-success';
            case 'cancelled':          return 'badge-danger';
            default:                   return 'badge-secondary';
        }
    }

    function stateBadgeLabel(state) {
        return ({
            draft: 'Szkic', sent: 'Wysłane', responded: 'Odpowiedź',
            confirmed: 'Potwierdzone', partially_received: 'Częściowo odebrane',
            received: 'Odebrane', cancelled: 'Anulowane'
        })[state] || state;
    }

    // "dzień" ma tylko jedną formę mnogą.
    function dayWord(n) { return n === 1 ? 'dzień' : 'dni'; }

    // 1 zamówienie / 2-4 zamówienia / 5+ zamówień.
    function zamowieniaWord(n) {
        const abs = Math.abs(n);
        if (abs === 1) return 'zamówienie';
        const last = abs % 10;
        const last2 = abs % 100;
        if (last >= 2 && last <= 4 && (last2 < 12 || last2 > 14)) return 'zamówienia';
        return 'zamówień';
    }

    function pad2(n) { return String(n).length < 2 ? '0' + n : String(n); }

    function nowStamp() {
        const d = new Date();
        return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate())
             + ' ' + pad2(d.getHours()) + ':' + pad2(d.getMinutes()) + ':' + pad2(d.getSeconds());
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
    // ZAKŁADKA 1 — kolejka "Do przyjęcia"
    // =====================================================================

    const $queueStatus    = $('#queueStatus');
    const $queueTableWrap = $('#queueTableWrap');
    const $queueBody      = $('#queueBody');
    const $queueVendor    = $('#queueVendor');
    const $queueStates    = $('#queueStates');

    // Ostatnio wczytany payload kolejki. Filtry pracują na tych danych
    // (a nie na tekście wiersza), więc „Dostawca" dopasowuje dokładnie
    // nazwę, a nie przypadkowy fragment innej kolumny.
    let queueData = [];

    function loadQueue() {
        $queueTableWrap.addClass('d-none');
        $queueStatus.removeClass('d-none')
            .html('<span class="spinner-border spinner-border-sm"></span> Ładowanie…');

        getAjax('receipts-queue.php')
            .done(function(r) {
                if (!r || !r.success) {
                    queueData = [];
                    queueMessage('danger', (r && r.error) || 'Nie udało się pobrać listy zamówień.');
                    $('#queueCountBadge').text('0');
                    return;
                }
                queueData = r.pos || [];
                renderQueue();
            })
            .fail(function() {
                queueData = [];
                queueMessage('danger', 'Błąd komunikacji z serwerem.');
                $('#queueCountBadge').text('0');
            });
    }

    function queueMessage(type, text) {
        $queueTableWrap.addClass('d-none');
        $queueStatus.removeClass('d-none').html(
            '<div class="alert alert-' + type + ' mb-0"><i class="bi bi-info-circle"></i> ' + esc(text) + '</div>'
        );
    }

    // Escape do WARTOŚCI ATRYBUTU. esc() nie zamienia cudzysłowów, więc
    // nazwa dostawcy z apostrofem lub cudzysłowem rozwalałaby atrybut
    // data-vendor-name (który zasila modal potwierdzenia PO).
    function escAttr(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    // Lista dostawców w filtrze pochodzi z payloadu — tylko ci, którzy
    // faktycznie mają coś w kolejce. Zaznaczenie przeżywa odświeżenie,
    // o ile dostawca nadal jest na liście.
    function rebuildVendorFilter() {
        const previous = $queueVendor.val() || [];
        const names = [];
        queueData.forEach(function(p) {
            const v = p.vendorName || '';
            if (v !== '' && names.indexOf(v) === -1) names.push(v);
        });
        names.sort(function(a, b) { return a.localeCompare(b, 'pl'); });

        let html = '';
        names.forEach(function(n) {
            html += '<option value="' + escAttr(n) + '">' + esc(n) + '</option>';
        });
        $queueVendor.html(html);

        const keep = previous.filter(function(v) { return names.indexOf(v) !== -1; });
        if (hasSelectpicker()) {
            $queueVendor.selectpicker('refresh');
            try { $queueVendor.selectpicker('val', keep); } catch (e) { /* noop */ }
            $queueVendor.selectpicker('refresh');
        } else {
            $queueVendor.val(keep);
        }
    }

    function renderQueue() {
        $('#queueCountBadge').text(queueData.length);
        rebuildVendorFilter();

        if (queueData.length === 0) {
            queueMessage('info', 'Brak zamówień oczekujących na przyjęcie.');
            updateQueueSummary(0, 0);
            return;
        }

        let html = '';
        queueData.forEach(function(p, idx) { html += queueRowHtml(p, idx); });
        $queueBody.html(html);
        $queueStatus.addClass('d-none');
        $queueTableWrap.removeClass('d-none');
        applyQueueFilters();
    }

    // Termin dostawy: po terminie = czerwono i z licznikiem dni, przed
    // terminem = wyszarzone, brak daty = "—".
    function deliveryCellHtml(p) {
        if (!p.expectedDeliveryDate) {
            return '<span class="text-muted">—</span>';
        }
        const date = esc(p.expectedDeliveryDate);
        const od = (p.daysOverdue === null || p.daysOverdue === undefined)
            ? null : parseInt(p.daysOverdue, 10);

        if (od !== null && od > 0) {
            return '<span class="text-danger font-weight-bold text-nowrap">'
                 + '<i class="bi bi-exclamation-triangle-fill"></i> ' + date + '</span>'
                 + '<div><small class="text-danger text-nowrap">' + od + ' ' + dayWord(od) + ' po terminie</small></div>';
        }
        let sub = '';
        if (od === 0) {
            sub = '<div><small class="text-muted">dzisiaj</small></div>';
        } else if (od !== null && od < 0) {
            sub = '<div><small class="text-muted text-nowrap">za ' + Math.abs(od) + ' ' + dayWord(Math.abs(od)) + '</small></div>';
        }
        return '<span class="text-nowrap">' + date + '</span>' + sub;
    }

    // Postęp: ile pozycji domkniętych + cienki pasek na ilościach.
    function progressCellHtml(p) {
        const full  = parseInt(p.linesFullyReceived, 10) || 0;
        const total = parseInt(p.lineCount, 10) || 0;
        const ordered  = num(p.orderedQty);
        const received = num(p.receivedQty);
        const pct = ordered > 0 ? Math.max(0, Math.min(100, (received / ordered) * 100)) : 0;
        const barClass = pct >= 100 ? 'bg-success' : (pct > 0 ? 'bg-warning' : 'bg-secondary');

        return '<div class="small text-nowrap">' + full + ' / ' + total + ' pozycji</div>'
             + '<div class="progress progress-thin mt-1" title="'
             +   escAttr(fmtQty(received) + ' z ' + fmtQty(ordered) + ' (' + Math.round(pct) + '%)') + '">'
             + '<div class="progress-bar ' + barClass + '" role="progressbar" style="width: ' + pct.toFixed(1) + '%"></div>'
             + '</div>'
             + '<div><small class="text-muted text-nowrap">'
             +   esc(fmtQty(received)) + ' / ' + esc(fmtQty(ordered))
             + '</small></div>';
    }

    function queueRowHtml(p, idx) {
        const poId  = parseInt(p.poId, 10);
        // canReceive === false tylko dla stanu `sent` (czeka na
        // potwierdzenie dostawcy). Taki wiersz jest przygaszony i
        // nieklikalny, ale ma AKTYWNY przycisk „Potwierdź odbiór",
        // który otwiera współdzielony modal (confirm-po-modal.php)
        // i przeprowadza PO ze stanu `sent` → `confirmed`.
        const canRx = p.canReceive !== false;

        // Numer zamówienia + numer u dostawcy jako podlinijka — ten sam
        // układ, co kolumna „Numer" w /purchase/documents (renderRow()).
        // Kolumna „Nr u dostawcy" zniknęła jako osobna, dane zostały.
        let numberCell = '<div class="font-weight-bold">' + esc(p.poNumber || ('#' + poId)) + '</div>';
        if (p.vendorPoNumber) {
            numberCell += '<div><small class="text-muted">Nr dost.: '
                        + esc(p.vendorPoNumber) + '</small></div>';
        }

        let stateCell = '<span class="badge ' + stateBadgeClass(p.state) + '">'
            + esc(stateBadgeLabel(p.state)) + '</span>';
        if (!canRx) {
            stateCell += '<div><small class="text-muted text-nowrap">'
                       + '<i class="bi bi-hourglass-split"></i> czeka na potwierdzenie'
                       + '</small></div>';
        }

        // Klik w wiersz LUB przycisk „Przyjmij towar" → nawigacja na
        // /purchase/receipts/receive?po_id=N (osobna trasa, formularz
        // wyodrębniony z tej strony). Wiersze pending (PO w stanie
        // `sent`) są nieklikalne — akcję niesie przycisk „Potwierdź
        // odbiór" otwierający współdzielony modal.
        const actionCell = canRx
            ? '<button type="button" class="btn btn-sm btn-primary receive-po-btn" data-po-id="' + poId + '">'
                + '<i class="bi bi-box-arrow-in-down"></i> Przyjmij towar'
              + '</button>'
            : '<button type="button" class="btn btn-sm btn-outline-success" '
                + 'data-action="confirm-po" '
                + 'data-po-id="' + poId + '" '
                + 'data-po-number="' + escAttr(p.poNumber || ('#' + poId)) + '" '
                + 'data-vendor-name="' + escAttr(p.vendorName || '') + '" '
                + 'data-vendor-po-number="' + escAttr(p.vendorPoNumber || '') + '">'
                + '<i class="bi bi-check2-square"></i> Potwierdź odbiór'
              + '</button>';

        return '<tr class="queue-row' + (canRx ? '' : ' queue-row-pending') + '"'
             +   ' data-po-id="' + poId + '" data-idx="' + idx + '">'
             + '<td>' + numberCell + '</td>'
             + '<td>' + esc(p.vendorName || '—') + '</td>'
             + '<td>' + stateCell + '</td>'
             + '<td>' + deliveryCellHtml(p) + '</td>'
             + '<td>' + progressCellHtml(p) + '</td>'
             + '<td class="text-right text-nowrap">' + actionCell + '</td>'
             + '</tr>';
    }

    // ---- filtry kolejki -------------------------------------------------

    function applyQueueFilters() {
        const search   = String($('#queueSearch').val() || '').toLowerCase().trim();
        const vendors  = $queueVendor.val() || [];
        const states   = $queueStates.val() || [];
        const overdue  = $('#queueOverdueOnly').is(':checked');

        let shown = 0;

        $queueBody.find('tr.queue-row').each(function() {
            const $row = $(this);
            const p = queueData[parseInt($row.attr('data-idx'), 10)];
            if (!p) { $row.hide(); return; }

            let hit = true;

            if (vendors.length && vendors.indexOf(p.vendorName || '') === -1) hit = false;
            if (hit && states.length && states.indexOf(p.state || '') === -1) hit = false;
            if (hit && overdue) {
                const od = (p.daysOverdue === null || p.daysOverdue === undefined)
                    ? null : parseInt(p.daysOverdue, 10);
                if (od === null || od <= 0) hit = false;
            }
            if (hit && search !== '') {
                const hay = [p.poNumber, p.vendorPoNumber, p.vendorName]
                    .map(function(x) { return String(x == null ? '' : x); })
                    .join(' ')
                    .toLowerCase();
                if (hay.indexOf(search) === -1) hit = false;
            }

            $row.toggle(hit);
            if (hit) shown++;
        });

        if (shown === 0 && queueData.length > 0) {
            $queueTableWrap.addClass('d-none');
            queueMessage('info', 'Żadne zamówienie nie pasuje do filtrów.');
        } else if (queueData.length > 0) {
            $queueStatus.addClass('d-none');
            $queueTableWrap.removeClass('d-none');
        }

        updateQueueSummary(shown, queueData.length);
    }

    function updateQueueSummary(shown, total) {
        const $el = $('#queueFilterSummary');
        if (total === 0) {
            $el.html('<i class="bi bi-info-circle"></i> Brak zamówień oczekujących na przyjęcie.');
            return;
        }
        if (shown === total) {
            $el.html('<i class="bi bi-info-circle"></i> '
                + total + ' ' + zamowieniaWord(total) + ' w kolejce. '
                + 'Kliknij wiersz potwierdzonego zamówienia, aby przyjąć towar.');
            return;
        }
        $el.html('<i class="bi bi-funnel-fill"></i> Pokazano <strong>'
            + shown + '</strong> z <strong>' + total + '</strong> ' + zamowieniaWord(total)
            + ' (filtry aktywne).');
    }

    $('#queueSearch').on('keyup', applyQueueFilters);
    $('#queueOverdueOnly').on('change', applyQueueFilters);
    $queueVendor.on('changed.bs.select change', applyQueueFilters);
    $queueStates.on('changed.bs.select change', applyQueueFilters);
    $('#queueRefreshBtn').on('click', loadQueue);

    function clearPicker($el) {
        if (hasSelectpicker()) {
            try { $el.selectpicker('deselectAll'); } catch (e) { $el.val([]); }
            $el.selectpicker('refresh');
        } else {
            $el.val([]);
        }
    }

    $('#clearQueueVendor').on('click', function() { clearPicker($queueVendor); applyQueueFilters(); });
    $('#clearQueueStates').on('click', function() { clearPicker($queueStates); applyQueueFilters(); });
    $('#clearQueueSearch').on('click', function() { $('#queueSearch').val(''); applyQueueFilters(); });
    $('#clearQueueOverdue').on('click', function() { $('#queueOverdueOnly').prop('checked', false); applyQueueFilters(); });

    // Klik w wiersz kolejki (z wyjątkiem pending) LUB w przycisk
    // „Przyjmij towar" → nawigacja na formularz przyjęcia. Ten sam
    // wzorzec co cart-view.js → docEditUrl(): window.location.origin +
    // ROOT_DIR + '/purchase/receipts/receive?po_id=' + poId (ROOT_DIR
    // jest w header.js, np. "/atte_ms_new"; "http://" + ROOT_DIR
    // dawałoby http:///atte_ms_new/… i martwy link).
    function goToReceiveForm(poId) {
        window.location.href = window.location.origin + ROOT_DIR
            + '/purchase/receipts/receive?po_id=' + encodeURIComponent(poId);
    }

    $(document).on('click', '.queue-row', function() {
        // Wiersze „pending" (PO w stanie `sent`) są nieklikalne — akcję
        // niesie przycisk „Potwierdź odbiór" w środku wiersza.
        if ($(this).hasClass('queue-row-pending')) return;
        goToReceiveForm(parseInt($(this).data('po-id'), 10));
    });

    $(document).on('click', '.receive-po-btn', function(e) {
        e.stopPropagation();
        goToReceiveForm(parseInt($(this).data('po-id'), 10));
    });

    // =====================================================================
    // ZAKŁADKA 2 — historia przyjęć
    // =====================================================================

    let historyPage = 1;

    // Jedno przejście: najpierw filtr (oznacza wiersze pasujące), potem
    // paginacja na odfiltrowanym zbiorze, potem widget paginacji.
    function applyHistoryView() {
        const search = String($('#historySearch').val() || '').toLowerCase().trim();
        const from   = String($('#historyDateFrom').val() || '');
        const to     = String($('#historyDateTo').val() || '');

        const $rows = $('#receiptsTable tbody tr.receipt-row');
        const matched = [];

        $rows.each(function() {
            const $row = $(this);
            let hit = true;

            if (search !== '' && $row.text().toLowerCase().indexOf(search) === -1) hit = false;
            if (hit && (from !== '' || to !== '')) {
                const d = String($row.attr('data-received-at') || '');
                if (d === '') hit = false;
                else {
                    if (from !== '' && d < from) hit = false;
                    if (to !== ''   && d > to)   hit = false;
                }
            }

            if (hit) matched.push($row);
            else $row.hide();
        });

        const total = matched.length;
        const pages = Math.max(1, Math.ceil(total / HISTORY_PER_PAGE));
        if (historyPage > pages) historyPage = pages;
        if (historyPage < 1) historyPage = 1;

        const firstIdx = (historyPage - 1) * HISTORY_PER_PAGE;
        const lastIdx  = firstIdx + HISTORY_PER_PAGE;
        matched.forEach(function($row, i) {
            $row.toggle(i >= firstIdx && i < lastIdx);
        });

        $('#historyCountBadge').text($rows.length);
        renderHistoryPagination(total, pages);

        // Pusta historia vs pusty wynik filtrowania to dwie różne sytuacje
        // i operator musi je rozróżnić.
        if ($rows.length === 0) {
            $('#receiptsEmptyAlert')
                .removeClass('d-none')
                .html('<i class="bi bi-info-circle"></i> Brak przyjęć w systemie.');
            $('#receiptsTableWrap').addClass('d-none');
        } else if (total === 0) {
            $('#receiptsEmptyAlert')
                .removeClass('d-none')
                .html('<i class="bi bi-info-circle"></i> Żadne przyjęcie nie pasuje do filtrów.');
            $('#receiptsTableWrap').addClass('d-none');
        } else {
            $('#receiptsEmptyAlert').addClass('d-none');
            $('#receiptsTableWrap').removeClass('d-none');
        }
    }

    // Widget paginacji przeniesiony z documents-view.js renderPagination()
    // (ta sama bryła: licznik u góry, btn-group z first/prev/dropdown/next).
    function renderHistoryPagination(total, pages) {
        const $containers = $('#historyPaginationTop, #historyPaginationBottom');
        $containers.empty();
        if (total === 0) return;

        const first = (historyPage - 1) * HISTORY_PER_PAGE + 1;
        const last  = Math.min(historyPage * HISTORY_PER_PAGE, total);

        let items = '';
        for (let p = 1; p <= pages; p++) {
            items += '<a class="dropdown-item history-page-item" href="#" data-page="' + p + '">' + p + '</a>';
        }

        const html = '<div class="d-flex flex-column align-items-center">'
            + '<div class="text-muted small mb-2">Wyświetlanie <strong>'
            +   first + '-' + last + '</strong> z <strong>' + total + '</strong> przyjęć</div>'
            + '<div class="btn-group btn-group-sm">'
            + '<button type="button" class="btn btn-outline-primary history-page-btn" data-action="first"'
            +   (historyPage === 1 ? ' disabled' : '') + '><i class="bi bi-chevron-double-left"></i></button>'
            + '<button type="button" class="btn btn-outline-primary history-page-btn" data-action="prev"'
            +   (historyPage === 1 ? ' disabled' : '') + '><i class="bi bi-chevron-left"></i></button>'
            + '<div class="btn-group">'
            + '<button type="button" class="btn btn-primary dropdown-toggle" data-toggle="dropdown">'
            +   historyPage + ' / ' + pages + '</button>'
            + '<div class="dropdown-menu" style="max-height: 300px; overflow-y: auto;">' + items + '</div>'
            + '</div>'
            + '<button type="button" class="btn btn-outline-primary history-page-btn" data-action="next"'
            +   (historyPage >= pages ? ' disabled' : '') + '><i class="bi bi-chevron-right"></i></button>'
            + '<button type="button" class="btn btn-outline-primary history-page-btn" data-action="last"'
            +   (historyPage >= pages ? ' disabled' : '') + '><i class="bi bi-chevron-double-right"></i></button>'
            + '</div></div>';

        $containers.html(html);
    }

    // Delegowane — widget jest przerysowywany, więc bindowanie za każdym
    // razem dorzucałoby kolejne handlery do tych samych akcji.
    $(document).on('click', '.history-page-btn', function() {
        const act = $(this).data('action');
        const pages = Math.max(1, Math.ceil(
            $('#receiptsTable tbody tr.receipt-row').length / HISTORY_PER_PAGE));
        if (act === 'first')     historyPage = 1;
        else if (act === 'prev') historyPage = Math.max(1, historyPage - 1);
        else if (act === 'next') historyPage = historyPage + 1;
        else if (act === 'last') historyPage = pages;
        applyHistoryView();
    });

    $(document).on('click', '.history-page-item', function(e) {
        e.preventDefault();
        historyPage = parseInt($(this).data('page'), 10) || 1;
        applyHistoryView();
    });

    $('#historySearch').on('keyup', function() { historyPage = 1; applyHistoryView(); });
    $('#historyDateFrom, #historyDateTo').on('change', function() { historyPage = 1; applyHistoryView(); });
    $('#clearHistorySearch').on('click', function() {
        $('#historySearch').val(''); historyPage = 1; applyHistoryView();
    });
    $('#clearHistoryDates').on('click', function() {
        $('#historyDateFrom, #historyDateTo').val(''); historyPage = 1; applyHistoryView();
    });

    // ---- modal szczegółów ----------------------------------------------

    $(document).on('click', '.receipt-row', function() {
        openReceiptModal(parseInt($(this).data('id'), 10));
    });

    $(document).on('click', '.view-receipt-btn', function(e) {
        e.stopPropagation();
        openReceiptModal(parseInt($(this).data('id'), 10));
    });

    function openReceiptModal(id) {
        getAjax('receipt-get.php', { id: id })
            .done(function(r) {
                if (!r.success) { showAlert(r.error || 'Błąd ładowania', 'danger'); return; }
                const rc = r.receipt;
                const items = r.items || [];

                // ROOT_DIR ma już wiodący ukośnik ("/atte_ms_new"), więc
                // 'http://' + ROOT_DIR dawało http:///atte_ms_new/… i link
                // był martwy. Ten sam wzorzec co cart-view.js: origin + ROOT_DIR.
                const poLink = rc.poNumber
                    ? '<a href="' + window.location.origin + ROOT_DIR
                      + '/admin/purchase/documents/edit?id=' + parseInt(rc.poId, 10) + '&type=po">'
                      + esc(rc.poNumber) + '</a>'
                    : '#' + parseInt(rc.poId, 10);

                const headerHtml =
                    '<dl class="row mb-0 small">'
                  + '<dt class="col-sm-2">Numer dokumentu:</dt>'
                  + '<dd class="col-sm-4 mb-1"><strong>' + esc(rc.documentNumber || '—') + '</strong>'
                  +   ' <span class="text-muted">(ID ' + parseInt(rc.id, 10) + ')</span></dd>'
                  + '<dt class="col-sm-2">Zamówienie:</dt>'
                  + '<dd class="col-sm-4 mb-1">' + poLink + '</dd>'
                  + '<dt class="col-sm-2">Dostawca:</dt>'
                  + '<dd class="col-sm-4 mb-1">' + esc(rc.vendorName || '—') + '</dd>'
                  + '<dt class="col-sm-2">Przyjął:</dt>'
                  + '<dd class="col-sm-4 mb-1">' + esc(rc.receivedByName || ('#' + rc.receivedBy))
                  +   ' <span class="text-muted">' + esc(rc.receivedAt || '') + '</span></dd>'
                  + (rc.comment
                        ? '<dt class="col-sm-2">Komentarz:</dt>'
                          + '<dd class="col-sm-10 mb-1">' + esc(rc.comment) + '</dd>'
                        : '')
                  + '</dl>';

                $('#receiptInfoHeader').html(headerHtml);

                let rows = '';
                if (items.length === 0) {
                    rows = '<tr><td colspan="9" class="text-center text-muted">Brak pozycji.</td></tr>';
                } else {
                    items.forEach(function(i) {
                        // orderedQty i totalReceivedQty endpoint zwracał od
                        // początku — dopiero teraz są widoczne, więc da się
                        // odczytać, czy ta dostawa domknęła pozycję.
                        const ordered = num(i.orderedQty);
                        const totalRx = num(i.totalReceivedQty);
                        const closed  = ordered > 0 && totalRx + 1e-6 >= ordered;
                        rows += '<tr>'
                              + '<td class="text-monospace">' + esc(i.vendorPartNo || '—') + '</td>'
                              + '<td>' + esc(i.partName || '—') + '</td>'
                              + '<td>' + esc(i.producerName || '—') + '</td>'
                              + '<td>' + esc(i.unitName || '—') + '</td>'
                              + '<td class="text-right">' + esc(fmtQty(i.orderedQty)) + '</td>'
                              + '<td class="text-right font-weight-bold">' + esc(fmtQty(i.quantityReceived)) + '</td>'
                              + '<td class="text-right">' + esc(fmtQty(i.totalReceivedQty))
                              +   (closed
                                     ? ' <span class="badge badge-success">komplet</span>'
                                     : ' <span class="badge badge-warning">częściowo</span>')
                              + '</td>'
                              + '<td>' + esc(i.magazineName || ('#' + i.subMagazineId)) + '</td>'
                              + '<td>' + esc(i.comment || '') + '</td>'
                              + '</tr>';
                    });
                }
                $('#receiptInfoItems').html(rows);
                $('#receiptInfoModal').modal('show');
            })
            .fail(function() { showAlert('Błąd komunikacji z serwerem', 'danger'); });
    }

    function showAlert(message, type) {
        if (!type) type = 'success';
        const html = '<div class="alert alert-' + type + ' alert-dismissible fade show" role="alert">'
            + message
            + '<button type="button" class="close" data-dismiss="alert"><span aria-hidden="true">&times;</span></button>'
            + '</div>';
        $('#alertContainer').html(html);
        $('html, body').animate({ scrollTop: 0 }, 300);
    }

    // ---- start ----------------------------------------------------------

    loadQueue();
    applyHistoryView();

    // Sukces powrotu z /purchase/receipts/receive — formularz przekierował
    // tu z query stringiem ?saved=…&n=…&po=…&po_state=… (parametry kodowane
    // przez encodeURIComponent). Wyświetlamy alert i podświetlamy świeży
    // wiersz historii (klasa .receipt-row-new, animacja w receipts.css).
    // URLSearchParams parsuje zarówno ?, jak i # — window.location.search
    // zwraca tylko część ?, więc fragment hasza nas nie interesuje.
    const successParams = new URLSearchParams(window.location.search);
    const savedDoc   = successParams.get('saved');
    const savedN     = parseInt(successParams.get('n'), 10);
    const savedPo     = successParams.get('po');
    const savedState = successParams.get('po_state');
    if (savedDoc) {
        const n     = isNaN(savedN) ? 0 : savedN;
        const word  = n === 1 ? 'pozycję' : (n >= 2 && n <= 4 && !(n >= 12 && n <= 14) ? 'pozycje' : 'pozycji');
        const stateLabel = ({
            partially_received: 'częściowo odebrane',
            received: 'odebrane'
        })[savedState] || savedState;
        showAlert(
            'Przyjęto ' + n + ' ' + word + '. '
            + 'Dokument <strong>' + esc(savedDoc) + '</strong>. '
            + 'Zamówienie ' + esc(savedPo || '—') + ' &rarr; ' + esc(stateLabel || '—') + '.',
            'success'
        );
        // Świeży wiersz historii został wyrenderowany przez PHP (reload
        // strony). Szukamy go po numerze dokumentu w pierwszej komórce
        // i dokładamy klasę .receipt-row-new — animacja 2s ease-out
        // (receipts.css) miga zielonym tłem, żeby operator zauważył,
        // że przyjęcie wylądowało na górze.
        // Po reloadzie strony historyPage startuje od 1, więc świeży
        // wiersz (na górze DOM) domyślnie ląduje na pierwszej stronie.
        // Jeśli filtr go wyklucza, applyHistoryView go ukryje — i tak
        // nie ma sensu do niego przewijać.
        const $newRow = $('#receiptsTable tbody tr.receipt-row').filter(function() {
            return $(this).find('td:first-child .font-weight-bold').text() === savedDoc;
        }).first();
        if ($newRow.length) {
            $newRow.addClass('receipt-row-new');
            applyHistoryView();
            // Daj przeglądarce chwilę na reflow po zmianie widoczności
            // (applyHistoryView właśnie przełączył display:none/table-row)
            // i dopiero wtedy przewiń — .offset().top na ukrytym wierszu
            // zwracałoby 0.
            setTimeout(function() {
                const $visible = $('#receiptsTable tbody tr.receipt-row:visible')
                    .filter(function() {
                        return $(this).find('td:first-child .font-weight-bold').text() === savedDoc;
                    }).first();
                const $target = $visible.length ? $visible : $newRow;
                if ($target.is(':visible')) {
                    $('html, body').animate({ scrollTop: $target.offset().top - 80 }, 400);
                }
            }, 50);
        }
        // Czyścimy query string z paska adresu, żeby odświeżenie strony
        // (F5) nie pokazało alertu ponownie.
        const cleanUrl = window.location.protocol + '//' + window.location.host
            + window.location.pathname + window.location.hash;
        window.history.replaceState({}, '', cleanUrl);
    }
});