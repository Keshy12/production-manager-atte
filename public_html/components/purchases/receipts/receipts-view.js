// Przyjęcia towaru — /purchase/receipts.
//
// Strona ma dwa widoki najwyższego poziomu (patrz receipts-view.php):
//   #receiptsListView     — kolejka „Do przyjęcia" + historia przyjęć
//   #receiptsReceiveView  — formularz przyjęcia
// Przełączanie to wyłącznie `d-none` na tych dwóch elementach. Poprzednia
// wersja trzymała formularz w panelu zakładki i na czas edycji zdejmowała
// `w-75` z kontenera strony — strona zmieniała szerokość pod operatorem.
// Teraz każdy widok ma własną szerokość zadeklarowaną w HTML.
//
// Endpointy (wszystkie POST — .htaccess blokuje GET, AGENTS.md):
//   receipts-queue.php   lista zamówień oczekujących na towar
//   receipt-po-get.php   nagłówek + pozycje jednego zamówienia
//   receipt-create.php   zapis przyjęcia
//   receipt-get.php      szczegóły zapisanego przyjęcia (modal)
// Kontrakty są zamrożone — ten plik tylko je konsumuje.
//
// Para Opak./Ilość działa tak samo jak w koszyku (cart-view.js): wpisanie
// opakowań przelicza ilość, wpisanie ilości przelicza opakowania, a gdy
// ilość nie dzieli się na pełne opakowania, ostatnio edytowane pole
// dostaje klasę .packages-uneven (ostrzeżenie, nie blokada).

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

    // Escape do WARTOŚCI ATRYBUTU. esc() nie zamienia cudzysłowów, więc
    // nazwa dostawcy z apostrofem lub cudzysłowem rozwalała atrybut
    // data-vendor-name (który zasila modal potwierdzenia PO).
    function escAttr(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    // Alert ląduje w tym kontenerze, który jest aktualnie widoczny —
    // lista i formularz mają własne (.alert-host), bo to osobne widoki.
    function showAlert(message, type) {
        if (!type) type = 'success';
        const html = '<div class="alert alert-' + type + ' alert-dismissible fade show" role="alert">'
            + message
            + '<button type="button" class="close" data-dismiss="alert"><span aria-hidden="true">&times;</span></button>'
            + '</div>';
        $('.alert-host').empty();
        const $host = $('.alert-host').filter(':visible').first();
        ($host.length ? $host : $('#alertContainer')).html(html);
        $('html, body').animate({ scrollTop: 0 }, 300);
    }

    function getAjax(endpoint, data) {
        // POST jest wymagany przez .htaccess (AGENTS.md) — GET do AJAX
        // endpointów pod /atte_ms_new/public_html/... jest blokowany i
        // zwraca parsererror.
        return $.ajax({ url: ajaxBase + endpoint, type: 'POST', data: data, dataType: 'json' });
    }

    // Liczby do wyświetlenia (nie do inputów) — separator tysięcy robi
    // realną robotę przy odczycie 5 000 vs 50 000 z tabeli.
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

    // Ta sama mapa klas co w koszyku (cart-view.js) — stan dokumentu ma
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

    // 1 pozycję / 2-4 pozycje / 5+ pozycji (z wyjątkiem końcówek 12-14).
    function pozycjeWord(n) {
        const abs = Math.abs(n);
        if (abs === 1) return 'pozycję';
        const last = abs % 10;
        const last2 = abs % 100;
        if (last >= 2 && last <= 4 && (last2 < 12 || last2 > 14)) return 'pozycje';
        return 'pozycji';
    }

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
    // nie renderuje pickera — operator widziłby surowy <select multiple>
    // (zajmujący pół strony i nieklikalny jak prawdziwy picker).
    // AGENTS.md: data-width="100%" + parent + min-width:0 — już stosowane
    // w markupie; poniższe wywołanie aktywuje sam plugin.
    $(function() {
        if (hasSelectpicker()) {
            $('.selectpicker').selectpicker();
        }
    });

    // =====================================================================
    // Przełączanie widoków
    // =====================================================================

    const $listView    = $('#receiptsListView');
    const $receiveView = $('#receiptsReceiveView');

    function showReceiveView() {
        $listView.addClass('d-none');
        $receiveView.removeClass('d-none');
        $('html, body').animate({ scrollTop: 0 }, 200);
    }

    function showListView() {
        $receiveView.addClass('d-none');
        $listView.removeClass('d-none');
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

    $(document).on('click', '.receive-po-btn', function(e) {
        e.stopPropagation();
        openReceiveForm(parseInt($(this).data('po-id'), 10));
    });

    $(document).on('click', '.queue-row', function() {
        // Wiersze „pending" (PO w stanie `sent`) są nieklikalne — akcję
        // niesie przycisk „Potwierdź odbiór" w środku wiersza.
        if ($(this).hasClass('queue-row-pending')) return;
        openReceiveForm(parseInt($(this).data('po-id'), 10));
    });

    // =====================================================================
    // Formularz przyjęcia
    // =====================================================================

    const $linesBody = $('#receiveLinesBody');
    const $magSelect = $('#receiptMagazineSelect');

    let receiveState = {
        poId: null,
        poNumber: '',
        vendorName: '',
        items: [],               // surowe pozycje z receipt-po-get.php
        headerMagazineId: '',
        receivedAtOpened: false, // czy ktoś w ogóle otworzył pole daty
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

    function openReceiveForm(poId) {
        if (!poId || receiveState.loading || receiveState.submitting) return;

        receiveState.loading = true;
        showReceiveView();

        $('#receivePoNumber').text('');
        $('#receivePoState').empty();
        $('#receiveVendorName').text('—');
        $('#receiveVendorPoNumber').text('—');
        $('#receiveExpectedDelivery').text('—');
        $('#receivePoCommentWrap').addClass('d-none');
        $('#receiveSummary').text('');
        $('#receiveHint').text('');
        $('#receiveSubmitBtn').prop('disabled', true);
        $linesBody.html('<tr><td colspan="9" class="text-center text-muted py-5">'
            + '<span class="spinner-border spinner-border-sm"></span> Ładowanie pozycji…</td></tr>');

        getAjax('receipt-po-get.php', { po_id: poId })
            .done(function(r) {
                receiveState.loading = false;
                if (!r || !r.success) {
                    showListView();
                    showAlert(esc((r && r.error) || 'Nie udało się pobrać zamówienia.'), 'danger');
                    return;
                }
                fillReceiveForm(r);
            })
            .fail(function(xhr, status) {
                receiveState.loading = false;
                showListView();
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
        // vendorPoNumber i comment przychodzą z receipt-po-get.php od
        // początku, ale poprzednia wersja formularza ich nie pokazywała.
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

        // Nazwa + podlinijka producenta (jak kolumna Producent w koszyku)
        // + komentarz z pozycji zamówienia, jeśli kupiec go zostawił.
        let nameCell = '<span class="font-weight-bold">' + esc(item.partName || '—') + '</span>';
        const sub = [];
        if (item.producerName)   sub.push(esc(item.producerName));
        if (item.producerPartNo) sub.push('<span class="font-weight-bold">' + esc(item.producerPartNo) + '</span>');
        if (sub.length) {
            nameCell += '<div><small class="text-muted">' + sub.join(' &middot; ') + '</small></div>';
        }
        // item.comment to komentarz z purchase__order_item — endpoint go
        // zwracał, a formularz go nie pokazywał.
        if (item.comment && String(item.comment).trim() !== '') {
            nameCell += '<div><small class="text-info">'
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

        return '<tr class="receipt-line' + (done ? ' receipt-line-done bg-light' : '') + '"'
             +   ' data-idx="' + idx + '" data-item-id="' + parseInt(item.poItemId, 10) + '">'
             + '<td class="text-monospace">' + esc(item.vendorPartNo || '—') + '</td>'
             + '<td>' + nameCell + '</td>'
             + '<td class="text-right">' + esc(fmtQty(item.quantityOrdered)) + '</td>'
             + '<td class="text-right">' + esc(fmtQty(item.quantityReceived)) + '</td>'
             + '<td class="text-right">' + remainingCell + '</td>'
             + '<td>' + packCell + '</td>'
             + '<td>'
             +   '<input type="number" class="form-control form-control-sm text-right line-qty"'
             +   ' step="any" min="0" value="' + escAttr(qtyVal) + '"' + (done ? ' disabled' : '') + '>'
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
            $linesBody.html('<tr><td colspan="9" class="text-center text-muted py-4">'
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

    // Opak. → Ilość
    $linesBody.on('input', '.line-packages', function() {
        const $row = $(this).closest('tr.receipt-line');
        const pack = packSizeOf(itemOfRow($row));
        if (pack === null) return;

        const $qty = $row.find('.line-qty');
        const pkgs = parseFloat($(this).val());
        if (isNaN(pkgs) || pkgs < 0) {
            $qty.val('');
            applyEvenWarning($row, $(this), NaN, pack);
            refreshSummary();
            return;
        }
        const qty = pkgs * pack;
        $qty.val(trimNum(qty, 6));
        applyEvenWarning($row, $(this), qty, pack);
        refreshSummary();
    });

    // Ilość → Opak.
    $linesBody.on('input', '.line-qty', function() {
        const $row = $(this).closest('tr.receipt-line');
        const pack = packSizeOf(itemOfRow($row));
        if (pack === null) { refreshSummary(); return; }

        const $pkg = $row.find('.line-packages');
        const qty = parseFloat($(this).val());
        if (isNaN(qty) || qty < 0) {
            $pkg.val('');
            applyEvenWarning($row, $(this), NaN, pack);
            refreshSummary();
            return;
        }
        $pkg.val(trimNum(qty / pack, 2));
        applyEvenWarning($row, $(this), qty, pack);
        refreshSummary();
    });

    // ---- walidacja na żywo ---------------------------------------------

    // Zwraca 'empty' | 'ok' | 'over' | 'error' i od razu maluje wiersz.
    function validateRow($row) {
        const item = itemOfRow($row);
        const $qty = $row.find('.line-qty');
        const $msg = $row.find('.line-msg');

        $row.removeClass('table-warning table-danger');
        $qty.removeClass('is-invalid');
        $msg.empty();

        if (!item || isDone(item)) return 'empty';

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
            $msg.html('<span class="text-danger">Maksymalnie ' + esc(fmtQty(max) + unit) + '.</span>');
            return 'error';
        }
        if (qty > remaining) {
            $row.addClass('table-warning');
            $msg.html('<span class="badge badge-warning">nadwyżka (dopuszczalna)</span>');
            return 'over';
        }
        return 'ok';
    }

    function refreshSummary() {
        let filled = 0;
        let errors = 0;
        let open   = 0;

        $linesBody.find('tr.receipt-line').each(function() {
            const $row = $(this);
            const item = itemOfRow($row);
            if (item && !isDone(item)) open++;
            const st = validateRow($row);
            if (st === 'error') errors++;
            else if (st === 'ok' || st === 'over') filled++;
        });

        $('#receiveSummary').text('Przyjmujesz ' + filled + ' z ' + open + ' pozycji');

        let hint = '';
        let ok = true;
        if (errors > 0) {
            ok = false;
            hint = 'Popraw pozycje oznaczone na czerwono — ilość przekracza dopuszczalne maksimum.';
        } else if (filled === 0) {
            ok = false;
            hint = 'Wpisz ilość w co najmniej jednej pozycji.';
        }
        $('#receiveHint').text(hint);
        $('#receiveSubmitBtn').prop('disabled', !ok || receiveState.submitting);
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

    $('#receiveBackBtn, #receiveCancelBtn').on('click', function() {
        if (receiveState.submitting) return;
        showListView();
    });

    // ---- zapis ----------------------------------------------------------

    function setSubmitting(on) {
        receiveState.submitting = on;
        $('#receiveSubmitBtn')
            .prop('disabled', true)
            .html(on
                ? '<span class="spinner-border spinner-border-sm"></span> Zapisywanie…'
                : '<i class="bi bi-check2-circle"></i> Zapisz przyjęcie');
        $('#receiveCancelBtn, #receiveBackBtn, #receiveFillAllBtn, #receiveClearAllBtn')
            .prop('disabled', on);
        if (!on) refreshSummary();
    }

    $('#receiveSubmitBtn').on('click', function() {
        if (receiveState.submitting) return;

        const items = [];
        let blocked = false;

        $linesBody.find('tr.receipt-line').each(function() {
            const $row = $(this);
            const item = itemOfRow($row);
            if (!item || isDone(item)) return;

            const raw = $.trim(String($row.find('.line-qty').val()));
            if (raw === '') return;
            const qty = parseFloat(raw);
            if (isNaN(qty) || qty <= 0) return;      // tylko wypełnione pozycje
            if (qty > num(item.maxAllowed)) { blocked = true; return; }

            items.push({
                po_item_id:        parseInt(item.poItemId, 10),
                quantity_received: qty,
                sub_magazine_id:   parseInt($row.find('.line-magazine').val(), 10),
                comment:           $.trim(String($row.find('.line-comment').val() || ''))
            });
        });

        if (blocked) {
            showAlert('Popraw ilości przekraczające dopuszczalne maksimum.', 'danger');
            return;
        }
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

        const poNumber   = receiveState.poNumber;
        const vendorName = receiveState.vendorName;

        setSubmitting(true);
        $.ajax({
            url: ajaxBase + 'receipt-create.php',
            type: 'POST',
            data: payload,
            dataType: 'json'
        })
            .done(function(r) {
                setSubmitting(false);
                if (!r || !r.success) {
                    showAlert(esc((r && r.error) || 'Nie udało się zapisać przyjęcia.'), 'danger');
                    return;
                }
                onReceiptSaved(r, items, poNumber, vendorName);
            })
            .fail(function(xhr, status) {
                setSubmitting(false);
                showAlert(esc((xhr.responseJSON && xhr.responseJSON.error) || status || 'Błąd komunikacji z serwerem'), 'danger');
            });
    });

    function onReceiptSaved(r, items, poNumber, vendorName) {
        const n = items.length;
        const totalQty = items.reduce(function(sum, i) { return sum + i.quantity_received; }, 0);

        showListView();
        showAlert(
            'Przyjęto ' + n + ' ' + pozycjeWord(n) + '. '
            + 'Dokument ' + esc(r.document_number || '—') + '. '
            + 'Zamówienie ' + esc(poNumber) + ' &rarr; ' + esc(r.po_state_label || r.po_state || '—') + '.',
            'success'
        );

        loadQueue();
        prependHistoryRow(r, n, totalQty, poNumber, vendorName);
    }

    // =====================================================================
    // ZAKŁADKA 2 — historia przyjęć
    // =====================================================================

    // Świeżo zapisane przyjęcie dokładamy na górę historii z danych, które
    // i tak mamy — bez przeładowania strony. Szablon wiersza pochodzi
    // z table-row-template.php, żeby kolumny się nie rozjechały.
    const rowTemplate = $('script[data-template="receiptRowTemplate"]').html() || '';

    function renderTemplate(tpl, data) {
        return tpl.replace(/\$\{(\w+)\}/g, function(match, key) {
            return esc(data[key] == null ? '' : data[key]);
        });
    }

    function prependHistoryRow(r, itemCount, totalQty, poNumber, vendorName) {
        if (!rowTemplate) return;

        const stamp = nowStamp();
        const html = renderTemplate(rowTemplate, {
            id:             r.receipt_id,
            documentNumber: r.document_number || '—',
            poNumber:       poNumber,
            vendorName:     vendorName || '—',
            itemCount:      itemCount,
            totalQty:       totalQty.toFixed(4),
            receivedByName: $listView.attr('data-current-user') || '',
            receivedAt:     stamp,
            receivedDate:   stamp.substring(0, 10)
        });

        $('#receiptsEmptyAlert').addClass('d-none');
        $('#receiptsTableWrap').removeClass('d-none');
        $('#receiptsTable tbody').prepend($($.trim(html)).addClass('receipt-row-new'));
        // Nowy wiersz ma trafić na pierwszą stronę i zostać policzony —
        // przerysowanie widoku robi jedno i drugie.
        historyPage = 1;
        applyHistoryView();
    }

    // ---- filtr + paginacja historii -------------------------------------

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

    // ---- start ----------------------------------------------------------
    loadQueue();
    applyHistoryView();
});
