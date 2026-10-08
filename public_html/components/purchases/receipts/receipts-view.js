// Przyjęcia towaru — /purchase/receipts (lista).
//
// Dwie zakładki:
//   „Do przyjęcia"     — kolejka z receipts-queue.php (POST), render tutaj,
//   „Historia przyjęć" — wiersze renderuje PHP, tu tylko filtr + paginacja.
//
// Nawigacja (konwencja z cart-view.js → docEditUrl()):
//   window.location.origin + ROOT_DIR + '/purchase/receipts/receive?po_id=N'
//   window.location.origin + ROOT_DIR + '/purchase/receipts/view?id=N'
// ROOT_DIR pochodzi z header.js (np. "/atte_ms_new") — 'http://' + ROOT_DIR
// dałoby martwy link http:///atte_ms_new/…
//
// Zakładka historii ma hash #historia (strona szczegółów linkuje
// z powrotem właśnie tam).
//
// Po zapisie przyjęcia receive-page.js przekierowuje tu z
//   ?saved=<doc>&n=<items>&po=<po>&po_state=<state>
// — pokazujemy komunikat sukcesu i podświetlamy nowy wiersz historii
// (klasa .receipt-row-new, wiersz szukany po data-doc).
//
// Potwierdzenie zamówienia (stan `sent`) obsługuje współdzielony
// ../documents/confirm-po-modal.js — tu tylko renderujemy przycisk
// z data-action="confirm-po" i kompletem atrybutów data-*.

$(document).ready(function () {
    const ajaxBase = COMPONENTS_PATH + '/purchases/receipts/';

    const HISTORY_PER_PAGE = 25;
    const HISTORY_HASH = '#historia';
    const READY_STATES = ['confirmed', 'in_progress', 'partially_received'];

    // Ta sama mapa co rx_state_meta() w receipts-helpers.php.
    const STATE_META = {
        draft:              { label: 'Szkic',               tone: 'neutral' },
        sent:               { label: 'Wysłane',             tone: 'violet' },
        responded:          { label: 'Odpowiedź',           tone: 'cyan' },
        confirmed:          { label: 'Potwierdzone',        tone: 'blue' },
        in_progress:        { label: 'W trakcie przyjęcia', tone: 'cyan' },
        partially_received: { label: 'Częściowo odebrane',  tone: 'amber' },
        received:           { label: 'Odebrane',            tone: 'green' },
        cancelled:          { label: 'Anulowane',           tone: 'red' }
    };

    // Grupy kolejki wg terminu dostawy. Serwer sortuje po dacie rosnąco
    // (puste na końcu), więc grupy wychodzą w tej kolejności same.
    const QUEUE_GROUPS = {
        overdue: 'Po terminie',
        today:   'Dostawa dzisiaj',
        week:    'W ciągu 7 dni',
        later:   'Później',
        nodate:  'Bez terminu dostawy'
    };

    // =====================================================================
    // Drobiazgi wspólne
    // =====================================================================

    function esc(str) {
        return $('<div>').text(str == null ? '' : String(str)).html();
    }

    // Escape do wartości atrybutu (esc() nie zamienia cudzysłowów).
    function escAttr(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function getAjax(endpoint, data) {
        // .htaccess przepuszcza do endpointów tylko POST.
        return $.ajax({ url: ajaxBase + endpoint, type: 'POST', data: data, dataType: 'json' });
    }

    function num(v) {
        const n = parseFloat(v);
        return isNaN(n) ? 0 : n;
    }

    function fmtQty(n) {
        const v = parseFloat(n);
        if (isNaN(v)) return '—';
        return v.toLocaleString('pl-PL', { maximumFractionDigits: 4 });
    }

    function pad2(n) { return n < 10 ? '0' + n : String(n); }

    function isoDay(d) {
        return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate());
    }

    // 'YYYY-MM-DD' → 'DD.MM.YYYY'
    function fmtDate(iso) {
        const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(iso || ''));
        return m ? (m[3] + '.' + m[2] + '.' + m[1]) : String(iso || '');
    }

    // 1 zamówienie / 2-4 zamówienia / 5+ zamówień
    function plural(n, one, few, many) {
        const a = Math.abs(n);
        if (a === 1) return one;
        const l = a % 10;
        const l2 = a % 100;
        if (l >= 2 && l <= 4 && (l2 < 12 || l2 > 14)) return few;
        return many;
    }

    function dayWord(n) { return Math.abs(n) === 1 ? 'dzień' : 'dni'; }

    function stateMeta(state) {
        return STATE_META[state] || { label: String(state || '—'), tone: 'neutral' };
    }

    function stateBadge(state) {
        const m = stateMeta(state);
        return '<span class="rx-badge rx-badge--' + m.tone + '">' + esc(m.label) + '</span>';
    }

    function hasSelectpicker() { return typeof $.fn.selectpicker === 'function'; }

    function setPicker($el, values) {
        if (hasSelectpicker()) {
            try { $el.selectpicker('val', values); } catch (e) { $el.val(values); }
            $el.selectpicker('refresh');
        } else {
            $el.val(values);
        }
    }

    function sameSet(a, b) {
        if (a.length !== b.length) return false;
        return a.every(function (x) { return b.indexOf(x) !== -1; });
    }

    function receiveUrl(poId) {
        return window.location.origin + ROOT_DIR
            + '/purchase/receipts/receive?po_id=' + encodeURIComponent(poId);
    }

    function detailUrl(id) {
        return window.location.origin + ROOT_DIR
            + '/purchase/receipts/view?id=' + encodeURIComponent(id);
    }

    // Klik w wiersz działa jak link: Ctrl/Cmd otwiera w nowej karcie,
    // a zaznaczanie tekstu w wierszu (kopiowanie numeru) nie nawiguje.
    function followRowLink(e, url) {
        const sel = window.getSelection ? String(window.getSelection()) : '';
        if (sel !== '') return;
        if (e.ctrlKey || e.metaKey) window.open(url, '_blank');
        else window.location.href = url;
    }

    function showAlert(message, type) {
        setAlert(message, type || 'success');
        $('html, body').animate({ scrollTop: 0 }, 250);
    }

    // Plugin jest już zainicjalizowany w header.js (z polskimi tekstami)
    // — to wywołanie to tylko zabezpieczenie, na zainicjalizowanym
    // selekcie nic nie robi.
    if (hasSelectpicker()) {
        $('.selectpicker').selectpicker();
    }

    // =====================================================================
    // ZAKŁADKA 1 — kolejka „Do przyjęcia"
    // =====================================================================

    const $queueStatus    = $('#queueStatus');
    const $queueTableWrap = $('#queueTableWrap');
    const $queueBody      = $('#queueBody');
    const $queueVendor    = $('#queueVendor');
    const $queueStates    = $('#queueStates');
    const $queueSearch    = $('#queueSearch');
    const $queueOverdue   = $('#queueOverdueOnly');
    const $refreshBtn     = $('#queueRefreshBtn');

    // Ostatnio wczytany payload. Filtry pracują na danych, nie na
    // tekście wiersza — „Dostawca" dopasowuje dokładną nazwę.
    let queueData = [];

    function daysOverdue(p) {
        return (p.daysOverdue === null || p.daysOverdue === undefined)
            ? null : parseInt(p.daysOverdue, 10);
    }

    function queueGroup(p) {
        const od = daysOverdue(p);
        if (od === null || !p.expectedDeliveryDate) return 'nodate';
        if (od > 0)   return 'overdue';
        if (od === 0) return 'today';
        if (od >= -7) return 'week';
        return 'later';
    }

    // ---- stany panelu (ładowanie / pusto / brak wyników / błąd) --------

    function skeletonHtml() {
        let html = '';
        for (let i = 0; i < 4; i++) {
            html += '<div class="rx-qrow rx-skel-row" aria-hidden="true">'
                  + '<div><span class="rx-skel" style="width:70%"></span><span class="rx-skel rx-skel--sm" style="width:45%"></span></div>'
                  + '<div><span class="rx-skel" style="width:80%"></span></div>'
                  + '<div><span class="rx-skel" style="width:60%"></span><span class="rx-skel rx-skel--sm" style="width:40%"></span></div>'
                  + '<div><span class="rx-skel" style="width:90%"></span><span class="rx-skel rx-skel--bar"></span></div>'
                  + '<div><span class="rx-skel rx-skel--pill"></span></div>'
                  + '<div><span class="rx-skel rx-skel--btn"></span></div>'
                  + '</div>';
        }
        return '<span class="sr-only">Ładowanie…</span>' + html;
    }

    function emptyStateHtml(icon, tone, title, text, actionHtml) {
        return '<div class="rx-empty">'
             + '<div class="rx-empty-icon' + (tone ? ' rx-empty-icon--' + tone : '') + '"><i class="bi ' + icon + '"></i></div>'
             + '<div class="rx-empty-title">' + esc(title) + '</div>'
             + (text ? '<div class="rx-empty-text">' + esc(text) + '</div>' : '')
             + (actionHtml || '')
             + '</div>';
    }

    function showQueueState(html) {
        $queueTableWrap.addClass('d-none');
        $queueStatus.removeClass('d-none').html(html);
    }

    function showQueueList() {
        $queueStatus.addClass('d-none').empty();
        $queueTableWrap.removeClass('d-none');
    }

    function setRefreshing(on) {
        $refreshBtn.prop('disabled', on).toggleClass('is-spinning', on);
    }

    // ---- wczytanie ------------------------------------------------------

    function loadQueue() {
        setRefreshing(true);
        showQueueState(skeletonHtml());
        $('#queueFilterSummary').text('Wczytywanie kolejki…');

        getAjax('receipts-queue.php')
            .done(function (r) {
                if (!r || !r.success) {
                    queueFailed((r && r.error) || 'Nie udało się pobrać listy zamówień.');
                    return;
                }
                queueData = r.pos || [];
                renderQueue();
            })
            .fail(function (xhr, status) {
                queueFailed((xhr && xhr.responseJSON && xhr.responseJSON.error) || 'Błąd komunikacji z serwerem (' + status + ').');
            })
            .always(function () { setRefreshing(false); });
    }

    function queueFailed(message) {
        queueData = [];
        $('#queueCountBadge').text('0');
        updateStats();
        $('#queueFilterSummary').text('Kolejka niedostępna.');
        showQueueState(emptyStateHtml(
            'bi-exclamation-octagon', 'danger',
            'Nie udało się wczytać kolejki',
            message,
            '<button type="button" class="btn btn-sm btn-primary js-queue-retry">'
          + '<i class="bi bi-arrow-clockwise"></i> Spróbuj ponownie</button>'
        ));
    }

    // Lista dostawców w filtrze = tylko ci, którzy faktycznie mają coś
    // w kolejce. Zaznaczenie przeżywa odświeżenie, o ile dostawca nadal
    // jest na liście.
    function rebuildVendorFilter() {
        const previous = $queueVendor.val() || [];
        const names = [];
        queueData.forEach(function (p) {
            const v = p.vendorName || '';
            if (v !== '' && names.indexOf(v) === -1) names.push(v);
        });
        names.sort(function (a, b) { return a.localeCompare(b, 'pl'); });

        let html = '';
        names.forEach(function (n) {
            html += '<option value="' + escAttr(n) + '">' + esc(n) + '</option>';
        });
        $queueVendor.html(html);
        if (hasSelectpicker()) $queueVendor.selectpicker('refresh');

        const keep = previous.filter(function (v) { return names.indexOf(v) !== -1; });
        setPicker($queueVendor, keep);
    }

    // Liczniki w nagłówku strony — zawsze z pełnych danych, nie po filtrach.
    function updateStats() {
        let ready = 0, overdue = 0, pending = 0;
        queueData.forEach(function (p) {
            if (p.canReceive !== false) ready++;
            else pending++;
            const od = daysOverdue(p);
            if (od !== null && od > 0) overdue++;
        });
        $('#statReady').text(ready);
        $('#statOverdue').text(overdue);
        $('#statPending').text(pending);
        $('.rx-stat--overdue').toggleClass('has-value', overdue > 0);
    }

    function renderQueue() {
        $('#queueCountBadge').text(queueData.length);
        updateStats();
        rebuildVendorFilter();

        if (queueData.length === 0) {
            $queueBody.empty();
            showQueueState(emptyStateHtml(
                'bi-check2-all', 'ok',
                'Nic nie czeka na przyjęcie',
                'Wszystkie wysłane i potwierdzone zamówienia zostały odebrane.'
            ));
            updateQueueSummary(0, 0);
            syncQueueFilterUi();
            return;
        }

        let html = '';
        let lastGroup = null;
        queueData.forEach(function (p, idx) {
            const g = queueGroup(p);
            if (g !== lastGroup) {
                html += '<div class="rx-qgroup is-' + g + '" data-group="' + g + '">'
                      + '<span class="rx-dot" aria-hidden="true"></span>'
                      + esc(QUEUE_GROUPS[g])
                      + ' <span class="rx-qgroup-count"></span>'
                      + '</div>';
                lastGroup = g;
            }
            html += queueRowHtml(p, idx, g);
        });
        $queueBody.html(html);
        showQueueList();
        applyQueueFilters();
    }

    // ---- komórki wiersza ------------------------------------------------

    function deliveryCellHtml(p) {
        if (!p.expectedDeliveryDate) {
            return '<div class="rx-date-main text-muted">—</div>'
                 + '<span class="rx-when">brak terminu</span>';
        }
        const od = daysOverdue(p);
        let when = '';
        if (od !== null && od > 0) {
            when = '<span class="rx-when rx-when--late"><i class="bi bi-exclamation-triangle-fill"></i> '
                 + od + ' ' + dayWord(od) + ' po terminie</span>';
        } else if (od === 0) {
            when = '<span class="rx-when rx-when--today">dzisiaj</span>';
        } else if (od === -1) {
            when = '<span class="rx-when rx-when--soon">jutro</span>';
        } else if (od !== null && od >= -7) {
            when = '<span class="rx-when rx-when--soon">za ' + Math.abs(od) + ' ' + dayWord(od) + '</span>';
        } else if (od !== null) {
            when = '<span class="rx-when">za ' + Math.abs(od) + ' ' + dayWord(od) + '</span>';
        }
        return '<div class="rx-date-main">' + esc(fmtDate(p.expectedDeliveryDate)) + '</div>' + when;
    }

    function progressCellHtml(p) {
        const full     = parseInt(p.linesFullyReceived, 10) || 0;
        const total    = parseInt(p.lineCount, 10) || 0;
        const ordered  = num(p.orderedQty);
        const received = num(p.receivedQty);
        if (total === 0) {
            return '<span class="rx-sub">Zamówienie bez pozycji</span>';
        }
        const pct = ordered > 0 ? Math.max(0, Math.min(100, (received / ordered) * 100)) : 0;
        const barCls = pct >= 100 ? 'is-done' : '';

        return '<div class="rx-progress-top">'
             +   '<span><strong>' + full + '</strong> z ' + total + ' pozycji</span>'
             +   '<span class="rx-pct">' + Math.round(pct) + '%</span>'
             + '</div>'
             + '<div class="rx-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100"'
             +   ' aria-valuenow="' + Math.round(pct) + '"'
             +   ' title="' + escAttr('Przyjęto ' + fmtQty(received) + ' z ' + fmtQty(ordered)) + '">'
             +   '<span class="' + barCls + '" style="width:' + pct.toFixed(1) + '%"></span>'
             + '</div>'
             + '<div class="rx-sub">przyjęto ' + esc(fmtQty(received)) + ' z ' + esc(fmtQty(ordered)) + '</div>';
    }

    function queueRowHtml(p, idx, group) {
        const poId  = parseInt(p.poId, 10);
        // canReceive === false tylko dla `sent` (czeka na potwierdzenie
        // dostawcy). Taki wiersz nie prowadzi do formularza — akcję niesie
        // przycisk „Potwierdź odbiór" (współdzielony modal).
        const canRx = p.canReceive !== false;
        const poLabel = p.poNumber || ('#' + poId);

        let numberCell = '<div class="rx-po-number">' + esc(poLabel) + '</div>';
        numberCell += p.vendorPoNumber
            ? '<div class="rx-sub">Nr u dostawcy: ' + esc(p.vendorPoNumber) + '</div>'
            : '<div class="rx-sub text-faint">brak nr u dostawcy</div>';

        let stateCell = stateBadge(p.state);
        if (!canRx) {
            stateCell += '<div class="rx-sub"><i class="bi bi-hourglass-split"></i> czeka na potwierdzenie dostawcy</div>';
        }

        let actionCell;
        if (canRx) {
            const label = p.state === 'in_progress' ? 'Dokończ przyjęcie' : 'Przyjmij towar';
            actionCell = '<a class="btn btn-sm btn-primary receive-po-btn" href="' + escAttr(receiveUrl(poId)) + '"'
                       + ' data-po-id="' + poId + '">'
                       + '<i class="bi bi-box-arrow-in-down"></i> ' + label
                       + '</a>';
        } else {
            actionCell = '<button type="button" class="btn btn-sm btn-outline-success" '
                       + 'data-action="confirm-po" '
                       + 'data-po-id="' + poId + '" '
                       + 'data-po-number="' + escAttr(poLabel) + '" '
                       + 'data-vendor-name="' + escAttr(p.vendorName || '') + '" '
                       + 'data-vendor-po-number="' + escAttr(p.vendorPoNumber || '') + '" '
                       + 'data-after-confirm-url="' + escAttr(receiveUrl(poId)) + '">'
                       + '<i class="bi bi-check2-square"></i> Potwierdź odbiór'
                       + '</button>';
        }

        return '<div class="rx-qrow queue-row is-' + group + (canRx ? '' : ' queue-row-pending') + '"'
             +   ' data-po-id="' + poId + '" data-idx="' + idx + '" data-group="' + group + '">'
             + '<div class="rx-qcell rx-qcell--po">' + numberCell + '</div>'
             + '<div class="rx-qcell rx-qcell--vendor"><span class="rx-cell-label">Dostawca</span>'
             +   '<span class="rx-vendor">' + esc(p.vendorName || '—') + '</span></div>'
             + '<div class="rx-qcell rx-qcell--delivery"><span class="rx-cell-label">Dostawa</span>' + deliveryCellHtml(p) + '</div>'
             + '<div class="rx-qcell rx-qcell--progress"><span class="rx-cell-label">Postęp</span>' + progressCellHtml(p) + '</div>'
             + '<div class="rx-qcell rx-qcell--status">' + stateCell + '</div>'
             + '<div class="rx-qcell rx-qcell--action">' + actionCell + '</div>'
             + '</div>';
    }

    // ---- filtry kolejki -------------------------------------------------

    function queueFilters() {
        return {
            search:  String($queueSearch.val() || '').toLowerCase().trim(),
            vendors: $queueVendor.val() || [],
            states:  $queueStates.val() || [],
            overdue: $queueOverdue.is(':checked')
        };
    }

    function queueFiltersActive(f) {
        return f.search !== '' || f.vendors.length > 0 || f.states.length > 0 || f.overdue;
    }

    // Podświetlenie aktywnych filtrów + stan kafelków w nagłówku.
    function syncQueueFilterUi() {
        const f = queueFilters();
        $('#queueVendorCell').toggleClass('is-active', f.vendors.length > 0);
        $('#queueStatesCell').toggleClass('is-active', f.states.length > 0);
        $('#clearQueueFilters').toggleClass('d-none', !queueFiltersActive(f));

        $('.rx-stat[data-stat="ready"]').toggleClass('is-active', sameSet(f.states, READY_STATES))
            .attr('aria-pressed', sameSet(f.states, READY_STATES) ? 'true' : 'false');
        $('.rx-stat[data-stat="pending"]').toggleClass('is-active', sameSet(f.states, ['sent']))
            .attr('aria-pressed', sameSet(f.states, ['sent']) ? 'true' : 'false');
        $('.rx-stat[data-stat="overdue"]').toggleClass('is-active', f.overdue)
            .attr('aria-pressed', f.overdue ? 'true' : 'false');
    }

    function applyQueueFilters() {
        const f = queueFilters();
        syncQueueFilterUi();
        if (queueData.length === 0) return;

        const perGroup = {};
        let shown = 0;

        $queueBody.find('.queue-row').each(function () {
            const $row = $(this);
            const p = queueData[parseInt($row.attr('data-idx'), 10)];
            if (!p) { $row.addClass('d-none'); return; }

            let hit = true;
            if (f.vendors.length && f.vendors.indexOf(p.vendorName || '') === -1) hit = false;
            if (hit && f.states.length && f.states.indexOf(p.state || '') === -1) hit = false;
            if (hit && f.overdue) {
                const od = daysOverdue(p);
                if (od === null || od <= 0) hit = false;
            }
            if (hit && f.search !== '') {
                const hay = [p.poNumber, p.vendorPoNumber, p.vendorName]
                    .map(function (x) { return String(x == null ? '' : x); })
                    .join(' ')
                    .toLowerCase();
                if (hay.indexOf(f.search) === -1) hit = false;
            }

            $row.toggleClass('d-none', !hit);
            if (hit) {
                shown++;
                const g = $row.attr('data-group');
                perGroup[g] = (perGroup[g] || 0) + 1;
            }
        });

        // Nagłówek grupy znika razem z ostatnim widocznym wierszem.
        $queueBody.find('.rx-qgroup').each(function () {
            const n = perGroup[$(this).attr('data-group')] || 0;
            $(this).toggleClass('d-none', n === 0)
                .find('.rx-qgroup-count').text(n);
        });

        if (shown === 0) {
            showQueueState(emptyStateHtml(
                'bi-funnel', '',
                'Brak zamówień dla wybranych filtrów',
                'Zmień kryteria albo wyczyść filtry, żeby zobaczyć całą kolejkę.',
                '<button type="button" class="btn btn-sm rx-btn-quiet js-clear-queue-filters">'
              + '<i class="bi bi-x-circle"></i> Wyczyść filtry</button>'
            ));
        } else {
            showQueueList();
        }

        updateQueueSummary(shown, queueData.length);
    }

    function updateQueueSummary(shown, total) {
        const $el = $('#queueFilterSummary');
        if (total === 0) {
            $el.text('Kolejka jest pusta.');
            return;
        }
        if (shown === total) {
            $el.html('<strong>' + total + '</strong> ' + plural(total, 'zamówienie', 'zamówienia', 'zamówień')
                + ' w kolejce · kliknij wiersz, aby przyjąć towar');
            return;
        }
        $el.html('<i class="bi bi-funnel-fill"></i> Pokazano <strong>' + shown + '</strong> z <strong>'
            + total + '</strong> ' + plural(total, 'zamówienia', 'zamówień', 'zamówień'));
    }

    function clearQueueFilters() {
        $queueSearch.val('');
        $queueOverdue.prop('checked', false);
        setPicker($queueVendor, []);
        setPicker($queueStates, []);
        applyQueueFilters();
    }

    $queueSearch.on('input', applyQueueFilters);
    $queueOverdue.on('change', applyQueueFilters);
    $queueVendor.on('changed.bs.select change', applyQueueFilters);
    $queueStates.on('changed.bs.select change', applyQueueFilters);
    $refreshBtn.on('click', loadQueue);
    $(document).on('click', '.js-clear-queue-filters', clearQueueFilters);
    $(document).on('click', '.js-queue-retry', loadQueue);

    // Kafelki w nagłówku = skróty do filtrów. Drugi klik zdejmuje filtr.
    $('.rx-stat').on('click', function () {
        const kind = $(this).attr('data-stat');
        const states = $queueStates.val() || [];

        if (kind === 'overdue') {
            $queueOverdue.prop('checked', !$queueOverdue.is(':checked'));
        } else if (kind === 'ready') {
            setPicker($queueStates, sameSet(states, READY_STATES) ? [] : READY_STATES.slice());
        } else if (kind === 'pending') {
            setPicker($queueStates, sameSet(states, ['sent']) ? [] : ['sent']);
        }
        $('#queueTabLink').tab('show');
        applyQueueFilters();
    });

    // Klik w wiersz (poza linkami/przyciskami) → formularz przyjęcia.
    // Wiersze `sent` (.queue-row-pending) nie prowadzą do formularza.
    $(document).on('click', '.queue-row', function (e) {
        if ($(this).hasClass('queue-row-pending')) return;
        if ($(e.target).closest('a, button, input, label').length) return;
        followRowLink(e, receiveUrl(parseInt($(this).attr('data-po-id'), 10)));
    });

    // =====================================================================
    // ZAKŁADKA 2 — historia przyjęć
    // =====================================================================

    const $historyRows   = $('#receiptsTable tbody tr.receipt-row');
    const $historySearch = $('#historySearch');
    const $dateFrom      = $('#historyDateFrom');
    const $dateTo        = $('#historyDateTo');

    // Tekst do wyszukiwania liczony raz: treść wiersza + pełna data ISO
    // (w komórce data jest w formacie d.m.Y).
    $historyRows.each(function () {
        const $row = $(this);
        $row.data('hay', ($row.text() + ' ' + ($row.attr('data-received-full') || ''))
            .replace(/\s+/g, ' ').toLowerCase());
    });

    let historyPage = 1;
    let historyPages = 1;

    function historyFiltersActive() {
        return String($historySearch.val() || '').trim() !== ''
            || String($dateFrom.val() || '') !== ''
            || String($dateTo.val() || '') !== '';
    }

    // Jedno przejście: filtr → paginacja na odfiltrowanym zbiorze → widget.
    function applyHistoryView() {
        const search = String($historySearch.val() || '').toLowerCase().trim();
        const from   = String($dateFrom.val() || '');
        const to     = String($dateTo.val() || '');

        const matched = [];
        $historyRows.each(function () {
            const $row = $(this);
            let hit = true;

            if (search !== '' && String($row.data('hay') || '').indexOf(search) === -1) hit = false;
            if (hit && (from !== '' || to !== '')) {
                const d = String($row.attr('data-received-at') || '');
                if (d === '') hit = false;
                else {
                    if (from !== '' && d < from) hit = false;
                    if (to !== ''   && d > to)   hit = false;
                }
            }
            if (hit) matched.push($row);
            else $row.addClass('d-none');
        });

        const total = matched.length;
        historyPages = Math.max(1, Math.ceil(total / HISTORY_PER_PAGE));
        if (historyPage > historyPages) historyPage = historyPages;
        if (historyPage < 1) historyPage = 1;

        const firstIdx = (historyPage - 1) * HISTORY_PER_PAGE;
        const lastIdx  = firstIdx + HISTORY_PER_PAGE;
        matched.forEach(function ($row, i) {
            $row.toggleClass('d-none', !(i >= firstIdx && i < lastIdx));
        });

        $('#historyCountBadge').text($historyRows.length);
        renderHistoryPagination(total);
        syncHistoryFilterUi(from, to);
        updateHistorySummary(total);

        // Pusta historia i pusty wynik filtrowania to dwie różne sytuacje.
        const $empty = $('#receiptsEmptyAlert');
        if ($historyRows.length === 0) {
            $empty.removeClass('d-none');
            $('#receiptsTableWrap').addClass('d-none');
        } else if (total === 0) {
            $empty.removeClass('d-none').html(emptyStateHtml(
                'bi-funnel', '',
                'Brak przyjęć dla wybranych filtrów',
                'Zmień wyszukiwaną frazę lub zakres dat.',
                '<button type="button" class="btn btn-sm rx-btn-quiet js-clear-history-filters">'
              + '<i class="bi bi-x-circle"></i> Wyczyść filtry</button>'
            ));
            $('#receiptsTableWrap').addClass('d-none');
        } else {
            $empty.addClass('d-none');
            $('#receiptsTableWrap').removeClass('d-none');
        }
        return matched;
    }

    function updateHistorySummary(total) {
        const $el = $('#historyFilterSummary');
        const all = $historyRows.length;
        if (all === 0) { $el.text(''); return; }
        if (!historyFiltersActive()) {
            $el.html('<strong>' + all + '</strong> ' + plural(all, 'przyjęcie', 'przyjęcia', 'przyjęć')
                + ' · od najnowszych');
            return;
        }
        $el.html('<i class="bi bi-funnel-fill"></i> Znaleziono <strong>' + total + '</strong> z <strong>'
            + all + '</strong> ' + plural(all, 'przyjęcia', 'przyjęć', 'przyjęć'));
    }

    // Aktywny preset = „od" ustawione dokładnie na dziś minus N dni,
    // „do" puste.
    function presetFrom(days) {
        const d = new Date();
        d.setDate(d.getDate() - Math.max(0, days - 1));
        return isoDay(d);
    }

    function syncHistoryFilterUi(from, to) {
        $('#historyDateRange').toggleClass('is-active', from !== '' || to !== '');
        $('#clearHistoryFilters').toggleClass('d-none', !historyFiltersActive());
        $('.rx-seg [data-preset]').each(function () {
            const days = parseInt($(this).attr('data-preset'), 10);
            const active = to === '' && from !== '' && from === presetFrom(days === 0 ? 1 : days);
            $(this).toggleClass('is-active', active).attr('aria-pressed', active ? 'true' : 'false');
        });
    }

    function renderHistoryPagination(total) {
        const $box = $('#historyPagination');
        $box.empty();
        if (total === 0) return;

        const first = (historyPage - 1) * HISTORY_PER_PAGE + 1;
        const last  = Math.min(historyPage * HISTORY_PER_PAGE, total);

        let html = '<div class="rx-pager">'
                 + '<div class="rx-pager-info">Wyświetlanie <strong>' + first + '–' + last + '</strong> z <strong>'
                 + total + '</strong> ' + plural(total, 'przyjęcia', 'przyjęć', 'przyjęć') + '</div>';

        if (historyPages > 1) {
            let items = '';
            for (let p = 1; p <= historyPages; p++) {
                items += '<a class="dropdown-item history-page-item' + (p === historyPage ? ' active' : '')
                       + '" href="#" data-page="' + p + '">Strona ' + p + '</a>';
            }
            const atStart = historyPage === 1;
            const atEnd   = historyPage >= historyPages;
            html += '<div class="btn-group btn-group-sm rx-pager-nav" role="group" aria-label="Strony historii">'
                  + '<button type="button" class="btn rx-btn-quiet history-page-btn" data-action="first" title="Pierwsza strona"'
                  +   (atStart ? ' disabled' : '') + '><i class="bi bi-chevron-double-left"></i></button>'
                  + '<button type="button" class="btn rx-btn-quiet history-page-btn" data-action="prev" title="Poprzednia strona"'
                  +   (atStart ? ' disabled' : '') + '><i class="bi bi-chevron-left"></i></button>'
                  + '<div class="btn-group btn-group-sm dropup">'
                  +   '<button type="button" class="btn rx-btn-quiet dropdown-toggle rx-pager-current" data-toggle="dropdown">'
                  +     'Strona ' + historyPage + ' z ' + historyPages + '</button>'
                  +   '<div class="dropdown-menu dropdown-menu-right rx-pager-menu">' + items + '</div>'
                  + '</div>'
                  + '<button type="button" class="btn rx-btn-quiet history-page-btn" data-action="next" title="Następna strona"'
                  +   (atEnd ? ' disabled' : '') + '><i class="bi bi-chevron-right"></i></button>'
                  + '<button type="button" class="btn rx-btn-quiet history-page-btn" data-action="last" title="Ostatnia strona"'
                  +   (atEnd ? ' disabled' : '') + '><i class="bi bi-chevron-double-right"></i></button>'
                  + '</div>';
        }
        html += '</div>';
        $box.html(html);
    }

    function goToHistoryPage(page) {
        historyPage = page;
        applyHistoryView();
        // Pager stoi pod tabelą — po zmianie strony wracamy na górę listy.
        const top = $('#historyPane').offset().top - 16;
        if ($(window).scrollTop() > top) {
            $('html, body').animate({ scrollTop: top }, 200);
        }
    }

    $(document).on('click', '.history-page-btn', function () {
        const act = $(this).attr('data-action');
        if (act === 'first')     goToHistoryPage(1);
        else if (act === 'prev') goToHistoryPage(Math.max(1, historyPage - 1));
        else if (act === 'next') goToHistoryPage(Math.min(historyPages, historyPage + 1));
        else if (act === 'last') goToHistoryPage(historyPages);
    });

    $(document).on('click', '.history-page-item', function (e) {
        e.preventDefault();
        goToHistoryPage(parseInt($(this).attr('data-page'), 10) || 1);
    });

    function clearHistoryFilters() {
        $historySearch.val('');
        $dateFrom.val('');
        $dateTo.val('');
        historyPage = 1;
        applyHistoryView();
    }

    $historySearch.on('input', function () { historyPage = 1; applyHistoryView(); });
    $dateFrom.add($dateTo).on('change', function () { historyPage = 1; applyHistoryView(); });
    $(document).on('click', '.js-clear-history-filters', clearHistoryFilters);

    $('.rx-seg [data-preset]').on('click', function () {
        const days = parseInt($(this).attr('data-preset'), 10);
        if ($(this).hasClass('is-active')) {
            $dateFrom.val('');
        } else {
            $dateFrom.val(presetFrom(days === 0 ? 1 : days));
        }
        $dateTo.val('');
        historyPage = 1;
        applyHistoryView();
    });

    // Klik w wiersz historii (poza linkami) → strona szczegółów przyjęcia.
    $(document).on('click', '#receiptsTable tbody tr.receipt-row', function (e) {
        if ($(e.target).closest('a, button').length) return;
        followRowLink(e, detailUrl(parseInt($(this).attr('data-id'), 10)));
    });

    // =====================================================================
    // Zakładki ↔ hash w adresie
    // =====================================================================

    $('#receiptsTabs a[data-toggle="tab"]').on('shown.bs.tab', function (e) {
        const hash = e.target.id === 'historyTabLink' ? HISTORY_HASH : '';
        if (window.history && window.history.replaceState) {
            window.history.replaceState(null, '', window.location.pathname + window.location.search + hash);
        }
    });

    // =====================================================================
    // Start
    // =====================================================================

    if (window.location.hash === HISTORY_HASH) {
        $('#historyTabLink').tab('show');
    }

    loadQueue();
    applyHistoryView();

    // ---- powrót z formularza przyjęcia (?saved=…) ----------------------

    const successParams = new URLSearchParams(window.location.search);
    const savedDoc   = successParams.get('saved');
    const savedN     = parseInt(successParams.get('n'), 10);
    const savedPo    = successParams.get('po');
    const savedState = successParams.get('po_state');

    if (savedDoc !== null) {
        const n = isNaN(savedN) ? 0 : savedN;
        const $newRow = $historyRows.filter(function () {
            return savedDoc !== '' && $(this).attr('data-doc') === savedDoc;
        }).first();

        let msg = '<i class="bi bi-check-circle-fill"></i> '
                + 'Zapisano przyjęcie <strong>' + esc(savedDoc || '—') + '</strong> — przyjęto '
                + n + ' ' + plural(n, 'pozycję', 'pozycje', 'pozycji') + '.';
        if (savedPo) {
            msg += ' Zamówienie <strong>' + esc(savedPo) + '</strong>'
                 + (savedState ? ' ma teraz status „' + esc(stateMeta(savedState).label) + '".' : '.');
        }

        if ($newRow.length) {
            // Klasa zostaje na wierszu: animacja (receipts-ui.css) odpali
            // się przy pierwszym pokazaniu zakładki historii, a zielony
            // znacznik przy krawędzi zostaje do przeładowania strony.
            $newRow.addClass('receipt-row-new');
            msg += '<span class="rx-alert-actions">'
                 + '<a class="alert-link" href="' + escAttr(detailUrl($newRow.attr('data-id'))) + '">Zobacz przyjęcie</a>'
                 + '<a class="alert-link js-reveal-new-receipt" href="' + HISTORY_HASH + '">Pokaż w historii</a>'
                 + '</span>';
        }
        showAlert(msg, 'success');

        $(document).on('click', '.js-reveal-new-receipt', function (e) {
            e.preventDefault();
            revealHistoryRow($newRow);
        });

        // Bez query stringu w pasku adresu F5 nie pokaże komunikatu ponownie.
        if (window.history && window.history.replaceState) {
            window.history.replaceState({}, '', window.location.pathname + window.location.hash);
        }
    }

    // Przejście do konkretnego wiersza historii: czyści filtry, ustawia
    // właściwą stronę, przełącza zakładkę, przewija i odpala podświetlenie.
    function revealHistoryRow($row) {
        if (!$row || !$row.length) return;
        $historySearch.val('');
        $dateFrom.val('');
        $dateTo.val('');
        historyPage = Math.floor($historyRows.index($row) / HISTORY_PER_PAGE) + 1;
        applyHistoryView();

        const scrollToRow = function () {
            $row.removeClass('receipt-row-new');
            void $row[0].offsetWidth; // restart animacji
            $row.addClass('receipt-row-new');
            $('html, body').animate({ scrollTop: $row.offset().top - 120 }, 300);
        };

        if ($('#historyPane').hasClass('active')) {
            scrollToRow();
        } else {
            $('#historyTabLink').one('shown.bs.tab', scrollToRow).tab('show');
        }
    }
});
