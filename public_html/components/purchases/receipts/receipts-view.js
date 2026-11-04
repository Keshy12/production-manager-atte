// Przyjęcia towaru — /purchase/receipts (lista).
//
// Trzy zakładki:
//   „Do przyjęcia"     — kolejka z receipts-queue.php (POST), render tutaj,
//                        pogrupowana wg dostawcy; jeden przycisk
//                        „Przyjmij dostawę" na dostawcę,
//   „Robocze"          — szkice przyjęć (delivery-list.php?state=draft),
//   „Historia przyjęć" — zatwierdzone dostawy (delivery-list.php?state=posted)
//                        + starsze przyjęcia wg zamówień (receipts-history.php,
//                        legacy_only=1, paginacja po stronie serwera).
//
// Nawigacja (konwencja z cart-view.js → docEditUrl()):
//   window.location.origin + ROOT_DIR + '/purchase/receipts/new?vendor_id=N'
//   window.location.origin + ROOT_DIR + '/purchase/receipts/new?delivery_id=N'
//   window.location.origin + ROOT_DIR + '/purchase/receipts/view?id=N'
// ROOT_DIR pochodzi z header.js (np. "/atte_ms_new") — 'http://' + ROOT_DIR
// dałoby martwy link http:///atte_ms_new/…
//
// Zakładki mają hashe #robocze i #historia (strona szczegółów linkuje
// z powrotem właśnie tam).
//
// Po zapisie przyjęcia receive-page.js przekierowuje tu z
//   ?saved=<doc>&n=<items>&po=<po>&po_state=<state>
// — pokazujemy komunikat sukcesu i podświetlamy nowy wiersz historii
// (klasa .receipt-row-new, wiersz szukany po data-doc).
//
// Potwierdzenie zamówienia (stan `sent`) ma własną stronę:
// /admin/purchase/documents/respond?id=POID&type=po — operator wpisuje
// tam wynegocjowane przez dostawcę ceny per pozycja, a zapis przenosi
// zamówienie do stanu `confirmed`. Tu tylko renderujemy link do niej.

$(document).ready(function () {
    const ajaxBase = COMPONENTS_PATH + '/purchases/receipts/';

    const HISTORY_PER_PAGE = 25;
    const HISTORY_HASH = '#historia';
    const DRAFTS_HASH = '#robocze';
    const DELIVERIES_PAGE = 25;
    const READY_STATES = ['confirmed', 'in_progress', 'partially_received'];

    // Ta sama mapa co rx_state_meta() w receipts-helpers.php.
    const STATE_META = {
        draft:              { label: 'Szkic',               tone: 'neutral' },
        sent:               { label: 'Wysłane',             tone: 'violet' },
        responded:          { label: 'Odpowiedź',           tone: 'cyan' },
        confirmed:          { label: 'Potwierdzone',        tone: 'blue' },
        in_progress:        { label: 'Częściowo przyjęte',  tone: 'cyan' },
        partially_received: { label: 'Częściowo odebrane',  tone: 'amber' },
        received:           { label: 'Odebrane',            tone: 'green' },
        cancelled:          { label: 'Anulowane',           tone: 'red' }
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

    // Przyjęcie dostawy wg faktury / WZ (delivery-page.php).
    function newDeliveryUrl(vendorId) {
        return window.location.origin + ROOT_DIR + '/purchase/receipts/new'
            + (vendorId ? '?vendor_id=' + encodeURIComponent(vendorId) : '');
    }

    function deliveryUrl(id) {
        return window.location.origin + ROOT_DIR
            + '/purchase/receipts/new?delivery_id=' + encodeURIComponent(id);
    }

    // Endpointy delivery-*.php: POST (konwencja .htaccess). Zwraca Promise
    // z odpowiedzią; błąd = Error z polskim komunikatem.
    function fetchJson(endpoint, data) {
        return new Promise(function (resolve, reject) {
            $.ajax({ url: ajaxBase + endpoint, type: 'POST', data: data || {}, dataType: 'json' })
                .done(function (r) {
                    if (!r || !r.success) { reject(new Error((r && r.error) || 'Operacja nie powiodła się.')); return; }
                    resolve(r);
                })
                .fail(function (xhr, status) {
                    reject(new Error((xhr && xhr.responseJSON && xhr.responseJSON.error)
                        || 'Błąd komunikacji z serwerem (' + ((xhr && xhr.status) || status) + ').'));
                });
        });
    }

    // „PO/1, PO/2" albo ['PO/1','PO/2'] → tablica numerów.
    function poList(v) {
        if (Array.isArray(v)) return v.filter(function (x) { return x !== null && x !== ''; });
        return String(v == null ? '' : v).split(',').map(function (x) { return x.trim(); }).filter(Boolean);
    }

    function poChipsHtml(v) {
        const list = poList(v);
        if (list.length === 0) return '<span class="text-muted">—</span>';
        return '<div class="dv-pochips">' + list.map(function (n) {
            return '<span class="dv-pochip">' + esc(n) + '</span>';
        }).join('') + '</div>';
    }

    function docCellHtml(d) {
        const type = d.document_type === 'wz' ? 'WZ' : 'FV';
        const nr = d.vendor_document_number
            ? '<span class="dv-docno">' + esc(d.vendor_document_number) + '</span>'
            : '<span class="dv-docno dv-docno--none">bez numeru</span>';
        return '<span class="dv-type">' + type + '</span>' + nr;
    }

    function detailUrl(id) {
        return window.location.origin + ROOT_DIR
            + '/purchase/receipts/view?id=' + encodeURIComponent(id);
    }

    // Ekran zamówienia (formularz PO) — cel kliknięcia w numer PO na
    // kolejce. Ścieżka ta sama, którą buduje documents-view.js dla
    // typu 'po', więc obie listy prowadzą do tego samego miejsca.
    function editUrl(poId) {
        return window.location.origin + ROOT_DIR
            + '/admin/purchase/documents/edit?id=' + encodeURIComponent(poId) + '&type=po';
    }

    // Strona "Potwierdź odpowiedź" dla PO w stanie `sent` — operator
    // wpisuje tam wynegocjowane przez dostawcę ceny per pozycja, a
    // zapis przenosi zamówienie do stanu `confirmed`. Ten sam router co
    // dla RFQ (documents-respond.php), inny parametr type.
    function respondPoUrl(poId) {
        return window.location.origin + ROOT_DIR
            + '/admin/purchase/documents/respond?id=' + encodeURIComponent(poId) + '&type=po';
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

    // vendor_id => liczba szkiców (z zakładki „Robocze") — chip w nagłówku grupy.
    let draftsByVendor = {};

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

        // Grupowanie wg dostawcy. Kolejność grup = kolejność pierwszego
        // wiersza danego dostawcy (serwer sortuje po terminie dostawy, więc
        // na górze lądują ci, którzy mają coś najpilniejszego).
        const groups = [];
        const byKey = {};
        queueData.forEach(function (p, idx) {
            const key = (p.vendorId ? 'v' + p.vendorId : 'n:' + (p.vendorName || ''));
            if (!byKey[key]) {
                byKey[key] = { key: key, vendorId: p.vendorId || 0, vendorName: p.vendorName || '—', rows: [] };
                groups.push(byKey[key]);
            }
            byKey[key].rows.push({ p: p, idx: idx });
        });

        let html = '';
        groups.forEach(function (g, gi) {
            g.index = gi;
            html += vendorHeadHtml(g);
            g.rows.forEach(function (r) {
                html += queueRowHtml(r.p, r.idx, queueGroup(r.p), gi);
            });
        });
        $queueBody.html(html);
        showQueueList();
        applyQueueFilters();
    }

    // Nagłówek grupy dostawcy: nazwa, podsumowanie, szkice i JEDEN
    // przycisk akcji „Przyjmij dostawę".
    function vendorHeadHtml(g) {
        let ready = 0, openLines = 0, overdue = 0, waiting = 0;
        g.rows.forEach(function (r) {
            const p = r.p;
            if (p.canReceive === false) { waiting++; return; }
            ready++;
            openLines += Math.max(0, (parseInt(p.lineCount, 10) || 0) - (parseInt(p.linesFullyReceived, 10) || 0));
            const od = daysOverdue(p);
            if (od !== null && od > 0) overdue++;
        });

        const initials = String(g.vendorName || '?').replace(/[^A-Za-zĄĆĘŁŃÓŚŹŻąćęłńóśźż0-9 ]/g, '').trim()
            .split(/\s+/).slice(0, 2).map(function (w) { return w.substring(0, 1); }).join('').toUpperCase() || '?';

        const meta = [];
        if (ready > 0) {
            meta.push('<strong>' + ready + '</strong> ' + plural(ready, 'zamówienie', 'zamówienia', 'zamówień')
                + ' do przyjęcia · <strong>' + openLines + '</strong> ' + plural(openLines, 'otwarta pozycja', 'otwarte pozycje', 'otwartych pozycji'));
        }
        if (overdue > 0) meta.push('<span class="text-danger font-weight-bold">' + overdue + ' po terminie</span>');
        if (waiting > 0) meta.push(waiting + ' czeka na potwierdzenie dostawcy');

        const drafts = draftsByVendor[g.vendorId] || 0;
        const chips = drafts > 0
            ? '<a class="dv-vchip js-goto-drafts" href="' + DRAFTS_HASH + '" title="Przejdź do roboczych przyjęć">'
              + '<i class="bi bi-pencil-square"></i> ' + drafts + ' ' + plural(drafts, 'robocze', 'robocze', 'roboczych') + '</a>'
            : '';

        const action = (ready > 0 && g.vendorId)
            ? '<a class="btn btn-primary btn-sm" href="' + escAttr(newDeliveryUrl(g.vendorId)) + '">'
              + '<i class="bi bi-box-arrow-in-down"></i> Przyjmij dostawę</a>'
            : '<span class="dv-vwait">' + (waiting > 0 ? 'czeka na potwierdzenie' : 'brak zamówień do przyjęcia') + '</span>';

        return '<div class="dv-vhead" data-vg="' + g.index + '">'
             + '<span class="dv-vmark" aria-hidden="true">' + esc(initials) + '</span>'
             + '<div class="dv-vmain"><div class="dv-vname">' + esc(g.vendorName) + '</div>'
             +   '<div class="dv-vmeta">' + meta.join(' · ') + '</div></div>'
             + '<div class="dv-vchips">' + chips + '</div>'
             + '<div class="dv-vgo">' + action + '</div>'
             + '</div>';
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

    // Pozycje menu pod caretem split-buttona Edytuj w wierszu kolejki.
    // Kolejka ma tylko PO, więc gałąz jest krótsza niż odpowiednik
    // w documents-view.js:
    //   - sent (canRx === false) → „Potwierdź odpowiedź" jako
    //     <a href> do dedykowanej strony odpowiedzi
    //     /admin/purchase/documents/respond?id=POID&type=po (operator
    //     wpisuje tam wynegocjowane ceny dostawcy per pozycja).
    //     Item jest zielony (.rx-action-success) — potwierdzenie
    //     odpowiedzi dostawcy zamyka etap „czeka na odpowiedź",
    //     więc zielony.
    //   - confirmed             → „Potwierdź odbiór"
    //   - in_progress           → „Dokończ przyjęcie"
    //   - partially_received    → „Przyjmij towar"
    //     Itemy są niebieskie (.rx-action-primary) — akcja, która zabiera
    //     operatora do dalszej pracy z towarem (formularz przyjęcia).
    //
    // Odcienie celowo NIE odzwierciedlają klasy przycisku na stronie
    // edycji (tam „Potwierdź odbiór" / „Przyjmij towar" są zielone).
    // To decyzja produktowa, nie Follow the Edit Page: w menu kolor
    // rozróżnia rodzaj akcji, a nie dokładnie ten sam przycisk.
    // Item to <a> dla receive i dla confirm — zachowujemy
    // dotychczasowe typy elementów (BS4 dropdown sam dopilnuje pozycjonowania).
    // Klasy kolorów są współdzielone z documents-view.js (actionMenuItemsHtml)
    // — ich definicja siedzi w receipts-ui.css, który obie strony wczytują,
    // więc odcień nie może się rozjechać między listami.
    //
    // Przyjmowanie towaru NIE jest już akcją wiersza zamówienia — robi się
    // to z nagłówka dostawcy („Przyjmij dostawę", nagłówek grupy), więc dla
    // zamówień gotowych do przyjęcia menu jest puste (zostaje sam link
    // Edytuj). Menu zostaje tylko dla `sent` („Potwierdź odpowiedź").
    function queueActionMenuHtml(p, poId, poLabel) {
        if (p.canReceive === false) {
            return '<a class="dropdown-item rx-action-success"'
                + ' href="' + escAttr(respondPoUrl(poId)) + '">'
                + '<i class="bi bi-reply"></i> Potwierdź odpowiedź</a>';
        }
        return '';
    }

    function queueRowHtml(p, idx, group, vg) {
        const poId  = parseInt(p.poId, 10);
        // canReceive === false tylko dla `sent` (czeka na odpowiedź
        // dostawcy). Taki wiersz nie prowadzi do formularza — akcję niesie
        // link „Potwierdź odpowiedź" do strony respond?id=N&type=po.
        const canRx = p.canReceive !== false;
        const poLabel = p.poNumber || ('#' + poId);

        // Numer PO jest JAWNYM linkiem do ekranu zamówienia — jedynym
        // klikalnym fragmentem wiersza. Bez tego (kiedy klik w cały wiersz
        // prowadził do formularza) operator nie miał jak dostać się do
        // zamówienia, a wyjątek „sent" blokował nawigację w ogóle.
        // Zwykły <a href> zamiast delegacji na .queue-row: działa z
        // Ctrl/Cmd (nowa karta), nie interceptujemy zaznaczania tekstu,
        // a focus klawiaturowy i kopia w kontekstowym dostaje za darmo.
        let numberCell = '<a class="rx-po-number rx-po-number--link" href="'
                       + escAttr(editUrl(poId)) + '">' + esc(poLabel) + '</a>';
        numberCell += p.vendorPoNumber
            ? '<div class="rx-sub">Nr u dostawcy: ' + esc(p.vendorPoNumber) + '</div>'
            : '<div class="rx-sub text-faint">brak nr u dostawcy</div>';

        let stateCell = stateBadge(p.state);
        if (!canRx) {
            stateCell += '<div class="rx-sub"><i class="bi bi-hourglass-split"></i> czeka na odpowiedź dostawcy</div>';
        }

        // Drugi przycisk w komórce akcji — mały, tylko ikona kosza:
        //   - canReceive === true (confirmed/in_progress/partially_received)
        //     → data-action="close-po": otwiera modal z wyborem stanu
        //       (received / cancelled).
        //   - canReceive === false (sent): closePoFromReceive() tego
        //     stanu nie przyjmuje, więc jedyną legalną opcją jest
        //     anulowanie → data-action="cancel-po" → prosty modal
        //     #receiveCancelPoModal.
        //
        // Agregaty (ordered/received/lineCount) przekazujemy w data-*,
        // żeby modal mógł pokazać podsumowanie bez okrągłego zapytania
        // po pozycje (kolejka ich nie ma).
        const orderedQty  = num(p.orderedQty);
        const receivedQty = num(p.receivedQty);
        const lineCount   = parseInt(p.lineCount, 10) || 0;
        const trashBtn = canRx
            ? '<button type="button" class="btn btn-sm btn-outline-danger rx-close-po-btn" '
              + 'data-action="close-po" '
              + 'data-po-id="' + poId + '" '
              + 'data-po-number="' + escAttr(poLabel) + '" '
              + 'data-ordered-qty="' + escAttr(orderedQty) + '" '
              + 'data-received-qty="' + escAttr(receivedQty) + '" '
              + 'data-line-count="' + escAttr(lineCount) + '" '
              + 'title="Zamknij zamówienie (odebrane / anulowane)" '
              + 'aria-label="Zamknij zamówienie">'
              + '<i class="bi bi-x-square"></i>'
              + '</button>'
            : '<button type="button" class="btn btn-sm btn-outline-danger rx-close-po-btn" '
              + 'data-action="cancel-po" '
              + 'data-po-id="' + poId + '" '
              + 'data-po-number="' + escAttr(poLabel) + '" '
              + 'title="Anuluj zamówienie" '
              + 'aria-label="Anuluj zamówienie">'
              + '<i class="bi bi-x-square"></i>'
              + '</button>';

        // Edytuj/Podgląd jako split-button (ten sam wzorzec co
        // documents-view.js → editControlHtml): etykieta Edytuj jest
        // zwykłym linkiem do ekranu zamówienia, a caret otwiera menu
        // z kolejnym krokiem (Potwierdź odbiór / Dokończ przyjęcie /
        // Przyjmij towar / Potwierdź odpowiedź). Kosz stoi przed split-buttonem, żeby
        // „mniej kuszący" przycisk nie wyglądał jak wizualne
        // następstwo Edytuj. Każdy wiersz kolejki ma co najmniej
        // jedną pozycję menu; gdyby kiedyś tak nie było, editControl
        // zwraca sam link bez careta (dokumenty robią tak samo).
        const editLink = '<a class="btn btn-sm rx-btn-quiet" href="'
                       + escAttr(editUrl(poId)) + '" title="Edytuj dokument">'
                       + '<i class="bi bi-pencil-square"></i> Edytuj</a>';
        const menu = queueActionMenuHtml(p, poId, poLabel);
        const editControl = menu
            ? '<div class="btn-group btn-group-sm">'
              + editLink
              + '<button type="button"'
              +   ' class="btn btn-sm rx-btn-quiet dropdown-toggle dropdown-toggle-split"'
              +   ' data-toggle="dropdown" aria-haspopup="true" aria-expanded="false"'
              +   ' aria-label="Więcej akcji dla zamówienia">'
              +   '<span class="sr-only">Więcej akcji dla zamówienia</span>'
              + '</button>'
              + '<div class="dropdown-menu dropdown-menu-right">' + menu + '</div>'
              + '</div>'
            : editLink;

        const actionCell = '<div class="rx-action-cell d-flex align-items-center justify-content-end">'
                         + trashBtn
                         + editControl
                         + '</div>';

        return '<div class="rx-qrow queue-row is-' + group + (canRx ? '' : ' queue-row-pending') + '"'
             +   ' data-po-id="' + poId + '" data-idx="' + idx + '" data-group="' + group + '" data-vg="' + vg + '">'
             + '<div class="rx-qcell rx-qcell--po">' + numberCell + '</div>'
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
                const g = $row.attr('data-vg');
                perGroup[g] = (perGroup[g] || 0) + 1;
            }
        });

        // Nagłówek dostawcy znika razem z ostatnim widocznym wierszem.
        $queueBody.find('.dv-vhead').each(function () {
            const n = perGroup[$(this).attr('data-vg')] || 0;
            $(this).toggleClass('d-none', n === 0);
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
                + ' w kolejce · towar przyjmujesz przyciskiem „Przyjmij dostawę" przy dostawcy');
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

    // Wiersz kolejki NIE jest klikalny. Kolejka ma w komórce akcji
    // dwa przyciski („Przyjmij towar" + kosz) i kilka pól do odczytu —
    // kliknięcie w losowy fragment wiersza nie ma żadnego sensownego
    // domyślnienia, a przy „niespodziewanej" nawigacji operator traci
    // formularz, który właśnie wypełniał. Wyjątkiem jest numer PO:
    // to jawny link do ekranu zamówienia (patrz queueRowHtml), dzięki
    // któremu da się tam trafić także z wiersza `sent`.
    //
    // followRowLink() zostaje, bo nadal zasila klik w wierszach
    // HISTORII przyjęć — tam klikalność całego wiersza jest w porządku
    // i została podyktowana inną potrzebą (podgląd przyjęcia).

    // =====================================================================
    // ZAKŁADKA 2 — historia przyjęć
    //
    // Wiersze NIE renderują się już po stronie PHP — receipts-history.php
    // (AJAX POST) zwraca 25 rekordów na stronę, a pager kieruje klienta
    // z powrotem do endpointu z innym `page`. Filtry (search + zakres
    // dat) wysyłamy do endpointu, żeby SQL filtrował po wszystkich
    // rekordach, nie tylko po tych aktualnie wyrenderowanych w DOM
    // (poprzednio filtr był lokalny i przy 25 wierszach w DOM nie
    // miałoby to sensu).
    // =====================================================================

    const $historyBody   = $('#historyBody');
    const $historySearch = $('#historySearch');
    const $dateFrom      = $('#historyDateFrom');
    const $dateTo        = $('#historyDateTo');
    const $historyPane   = $('#historyPane');
    const $historyPager  = $('#historyPagination');

    let historyPage     = 1;
    let historyPages    = 1;
    let historyTotal    = 0;
    let historyLoaded   = false;  // czy któraś strona została już pobrana
    let historyLoading  = false;  // czy request jest w toku (blokada double-fetch)

    function historyFiltersActive() {
        return String($historySearch.val() || '').trim() !== ''
            || String($dateFrom.val() || '') !== ''
            || String($dateTo.val() || '') !== '';
    }

    function historyFiltersParams() {
        return {
            search:    String($historySearch.val() || '').trim(),
            date_from: String($dateFrom.val() || ''),
            date_to:   String($dateTo.val() || ''),
            // Dostawy (purchase__delivery) mają własną tabelę powyżej —
            // tu zostają tylko przyjęcia sprzed dostaw.
            legacy_only: 1
        };
    }

    // Serwerowe mirroring helperów rx_fmt_date / rx_fmt_time /
    // rx_relative_day / rx_initials / rx_fmt_qty (PHP → JS). Formatowanie
    // widocznych w komórkach, żeby warstwa widoku nie musiała odpytywać
    // PHP o szczegóły. Zasady formatowania pokrywają się 1:1 ze swoimi
    // odpowiednikami w receipts-helpers.php — patrz też kolejka (tamten
    // fragment ma swój własny zestaw formatterów, ale współdzieli konwencję).
    function hxFmtTime(iso) {
        if (iso == null || String(iso).length < 16) return '';
        const hm = String(iso).substring(11, 16);
        return hm === '00:00' ? '' : hm;
    }
    function hxRelativeDay(iso) {
        if (iso == null || String(iso) === '') return '';
        const day = String(iso).substring(0, 10);
        const today = new Date();
        const todayIso = today.getFullYear() + '-' + pad2(today.getMonth() + 1) + '-' + pad2(today.getDate());
        if (day === todayIso) return 'dzisiaj';
        const yest = new Date(today.getTime() - 86400000);
        const yIso = yest.getFullYear() + '-' + pad2(yest.getMonth() + 1) + '-' + pad2(yest.getDate());
        if (day === yIso) return 'wczoraj';
        return '';
    }
    function hxInitials(name) {
        name = String(name == null ? '' : name).trim();
        if (name === '' || name[0] === '#') return '?';
        const parts = name.split(/\s+/);
        let out = '';
        for (let i = 0; i < Math.min(parts.length, 2); i++) {
            out += parts[i].substring(0, 1);
        }
        return out.toUpperCase();
    }
    function hxFmtQty(n) {
        const v = parseFloat(n);
        if (isNaN(v)) return '—';
        // toLocaleString PL: ',' dziesiętny + spacje niełamliwe od 10 000,
        // max 4 miejsca. Rx_fmt_qty tnie zera końcowe i zamienia ',-0'/'-.'
        // na '0'; tu robimy odpowiednik regexem, żeby wynik pasował 1:1.
        const s = v.toLocaleString('pl-PL', { maximumFractionDigits: 4 });
        const trimmed = s
            .replace(/,0+$/, '')           // 1,2300 → 1,23
            .replace(/(\.\d*?)0+$/, '$1'); // 1.2300 → 1.23 (styl pl-PL nie używa kropki,
                                            // ale zostawiamy na wszelki wypadek)
        return trimmed === '' || trimmed === '-0' ? '0' : trimmed;
    }
    function historyEditOrderUrl(poId) {
        return window.location.origin + ROOT_DIR
            + '/admin/purchase/documents/edit?id=' + encodeURIComponent(poId) + '&type=po';
    }
    function historyViewUrl(id) {
        return window.location.origin + ROOT_DIR
            + '/purchase/receipts/view?id=' + encodeURIComponent(id);
    }

    // Buduje jeden wiersz historii. Klasy, atrybuty data-* i struktura
    // komórek są 1:1 z wcześniejszym renderem PHP (receipts-view.php,
    // szablon $r wiersza) — klik-nawigacja (history-row click →
    // followRowLink → detailUrl), highlight .receipt-row-new (saved),
    // oraz podgląd „Zobacz" (.view-receipt-btn) zależą od tych
    // identyfikatorów.
    function historyRowHtml(r) {
        const receivedFull = r.receivedAt || '';
        const receivedDate = receivedFull.substring(0, 10);
        const relDay = hxRelativeDay(receivedFull);
        const time   = hxFmtTime(receivedFull);
        const receiver = r.receivedByName || ('#' + r.receivedBy);

        let docCell = '';
        if (r.documentNumber) {
            docCell = '<div class="rx-doc">' + esc(r.documentNumber) + '</div>';
        } else {
            docCell = '<div class="rx-doc rx-doc--none"'
                    + ' title="Zapisany sam nagłówek — numer GRN nadawany jest przy zapisie ilości">'
                    + 'bez numeru GRN</div>';
        }
        docCell += '<div class="rx-sub">ID ' + parseInt(r.id, 10) + '</div>';

        let rawSub = relDay + (relDay && time ? ', ' : '') + time;
        rawSub = rawSub.trim();
        const subLabel = rawSub !== '' ? esc(rawSub) : '&nbsp;';

        let poCell;
        if (r.poNumber) {
            poCell = '<a class="rx-po-link" href="'
                   + escAttr(historyEditOrderUrl(r.poId))
                   + '" title="Otwórz zamówienie">' + esc(r.poNumber) + '</a>';
        } else {
            poCell = '<span class="text-muted">#' + parseInt(r.poId, 10) + '</span>';
        }

        const itemCell = r.itemCount > 0
            ? '<span class="rx-pill">' + r.itemCount + '</span>'
            : '<span class="rx-pill rx-pill--empty"'
              + ' title="Zapisany sam nagłówek dokumentu">bez pozycji</span>';

        return '<tr class="receipt-row" data-id="' + parseInt(r.id, 10) + '"'
             + ' data-doc="' + escAttr(r.documentNumber || '') + '"'
             + ' data-received-at="' + escAttr(receivedDate) + '"'
             + ' data-received-full="' + escAttr(receivedFull) + '">'
             +   '<td>' + docCell + '</td>'
             +   '<td class="text-nowrap">'
             +     '<div class="rx-date-main">' + esc(fmtDate(receivedFull)) + '</div>'
             +     '<div class="rx-sub">' + subLabel + '</div>'
             +   '</td>'
             +   '<td class="text-nowrap">' + poCell + '</td>'
             +   '<td class="rx-vendor">' + esc(r.vendorName || '—') + '</td>'
             +   '<td class="rx-num">' + itemCell + '</td>'
             +   '<td class="rx-num d-none d-md-table-cell">'
             +       esc(hxFmtQty(r.totalQty))
             +   '</td>'
             +   '<td class="d-none d-lg-table-cell">'
             +     '<span class="rx-person">'
             +       '<span class="rx-avatar" aria-hidden="true">'
             +           esc(hxInitials(r.receivedByName))
             +       '</span>'
             +       esc(receiver)
             +     '</span>'
             +   '</td>'
             +   '<td class="text-right text-nowrap">'
             +     '<a class="btn btn-sm rx-btn-quiet view-receipt-btn" href="'
             +         escAttr(historyViewUrl(r.id))
             +     '">Zobacz <i class="bi bi-chevron-right rx-go"></i></a>'
             +   '</td>'
             + '</tr>';
    }

    function historyPlaceholderHtml(message) {
        return '<tr class="rx-history-placeholder" aria-hidden="true">'
             +   '<td colspan="8" class="text-center text-muted py-4">'
             +     '<span class="spinner-border spinner-border-sm align-middle" role="status"></span>'
             +     '<span class="ml-2 align-middle">' + esc(message) + '</span>'
             +   '</td>'
             + '</tr>';
    }

    // Ładowanie strony. Zwraca jqXHR (przydatne dla revealHistoryRow,
    // gdzie chcemy podświetlić wiersz po zakończeniu requestu). Druga
    // próba wywołania w trakcie requestu jest ignorowana — podwójne
    // kliknięcia w pager nie powinny generować zduplikowanych requestów.
    function loadHistoryPage(page) {
        if (historyLoading) return $.Deferred().resolve().promise();
        historyLoading = true;

        const params = Object.assign({
            page: page,
            per_page: HISTORY_PER_PAGE
        }, historyFiltersParams());

        $historyBody.html(historyPlaceholderHtml('Ładowanie…'));
        $historyPager.empty();

        const req = getAjax('receipts-history.php', params);
        req.done(function (r) {
            if (!r || !r.success) {
                historyFailed((r && r.error) || 'Nie udało się pobrać historii.');
                return;
            }
            renderHistoryPage(r);
        });
        req.fail(function (xhr, status) {
            historyFailed((xhr && xhr.responseJSON && xhr.responseJSON.error)
                       || 'Błąd komunikacji z serwerem (' + status + ').');
        });
        req.always(function () {
            historyLoading = false;
        });
        return req;
    }

    function historyFailed(message) {
        $historyBody.empty();
        $('#receiptsTableWrap').addClass('d-none');
        $('#receiptsEmptyAlert').removeClass('d-none').html(emptyStateHtml(
            'bi-exclamation-octagon', 'danger',
            'Nie udało się wczytać historii',
            message,
            '<button type="button" class="btn btn-sm btn-primary js-history-retry">'
                + '<i class="bi bi-arrow-clockwise"></i> Spróbuj ponownie</button>'
        ));
        $historyPager.empty();
        historyTotal = 0;
        updateHistoryBadge();
    }

    function renderHistoryPage(r) {
        const receipts = r.receipts || [];
        historyTotal  = r.total || 0;
        historyPages  = r.pages || 1;
        historyPage   = r.page || 1;
        historyLoaded = true;

        if (historyTotal === 0) {
            $historyBody.empty();
            $('#receiptsTableWrap').addClass('d-none');
            const $empty = $('#receiptsEmptyAlert');
            if (historyFiltersActive()) {
                $empty.removeClass('d-none').html(emptyStateHtml(
                    'bi-funnel', '',
                    'Brak przyjęć dla wybranych filtrów',
                    'Zmień wyszukiwaną frazę lub zakres dat.',
                    '<button type="button" class="btn btn-sm rx-btn-quiet js-clear-history-filters">'
                        + '<i class="bi bi-x-circle"></i> Wyczyść filtry</button>'
                ));
            } else {
                $empty.removeClass('d-none').html(emptyStateHtml(
                    'bi-archive', '',
                    'Brak starszych przyjęć',
                    'Przyjęcia zapisane przed wprowadzeniem dostaw wg faktur pojawiają się tutaj.'
                ));
            }
            $historyPager.empty();
            // Bez filtrów i bez starszych przyjęć panel byłby pustym szumem.
            $('#legacyPanel').toggleClass('d-none', !historyFiltersActive());
            updateHistoryBadge();
            syncHistoryFilterUi(String($dateFrom.val() || ''), String($dateTo.val() || ''));
            maybeHighlightSavedReceipt();
            return;
        }

        let html = '';
        receipts.forEach(function (rcpt) { html += historyRowHtml(rcpt); });
        $historyBody.html(html);
        $('#receiptsTableWrap').removeClass('d-none');
        $('#receiptsEmptyAlert').addClass('d-none');
        $('#legacyPanel').removeClass('d-none');

        renderHistoryPagination(historyTotal);
        updateHistoryBadge();
        syncHistoryFilterUi(String($dateFrom.val() || ''), String($dateTo.val() || ''));

        maybeHighlightSavedReceipt();
    }

    // Licznik zakładki i linijka podsumowania łączą oba źródła:
    // dostawy wg faktur + starsze przyjęcia wg zamówień.
    function updateHistoryBadge() {
        const nd = deliveriesFiltered.length;
        $('#historyCountBadge').text(nd + historyTotal);
        $('#legacySecInfo').text(historyTotal > 0 ? '· ' + historyTotal : '');

        const $el = $('#historyFilterSummary');
        if (nd + historyTotal === 0) { $el.text(''); return; }
        const parts = [];
        parts.push('<strong>' + nd + '</strong> ' + plural(nd, 'dostawa', 'dostawy', 'dostaw'));
        if (historyTotal > 0) {
            parts.push('<strong>' + historyTotal + '</strong> ' + plural(historyTotal, 'starsze przyjęcie', 'starsze przyjęcia', 'starszych przyjęć'));
        }
        $el.html((historyFiltersActive() ? '<i class="bi bi-funnel-fill"></i> Znaleziono ' : '') + parts.join(' · ') + ' · od najnowszych');
    }

    // ---- dostawy zatwierdzone (delivery-list.php?state=posted) ----------

    let deliveriesAll       = [];
    let deliveriesFiltered  = [];
    let deliveriesShown     = DELIVERIES_PAGE;
    let deliveriesLoaded    = false;
    let deliveriesLoading   = false;

    function deliveryDay(d) {
        return String(d.received_at || d.posted_at || '').substring(0, 10);
    }

    function filterDeliveries() {
        const q = String($historySearch.val() || '').trim().toLowerCase();
        const from = String($dateFrom.val() || '');
        const to = String($dateTo.val() || '');
        deliveriesFiltered = deliveriesAll.filter(function (d) {
            const day = deliveryDay(d);
            if (from !== '' && day < from) return false;
            if (to !== '' && day > to) return false;
            if (q !== '') {
                const hay = [d.grn_number, d.vendor_document_number, d.vendor_name, poList(d.po_numbers).join(' '), d.created_by_name]
                    .map(function (x) { return String(x == null ? '' : x); }).join(' ').toLowerCase();
                if (hay.indexOf(q) === -1) return false;
            }
            return true;
        });
        deliveriesFiltered.sort(function (a, b) {
            const ka = String(a.received_at || a.posted_at || '') + String(1e9 + (parseInt(a.id, 10) || 0));
            const kb = String(b.received_at || b.posted_at || '') + String(1e9 + (parseInt(b.id, 10) || 0));
            return ka < kb ? 1 : (ka > kb ? -1 : 0);
        });
    }

    function deliveryRowHtml(d) {
        const full = d.received_at || d.posted_at || '';
        const rel = hxRelativeDay(full);
        const time = hxFmtTime(full);
        const sub = (rel + (rel && time ? ', ' : '') + time).trim();
        const person = d.created_by_name || '';
        const cancelled = d.state === 'cancelled';
        return '<tr class="receipt-row dv-row-click' + (cancelled ? ' dv-row-cancelled' : '') + '" data-delivery-id="' + parseInt(d.id, 10) + '">'
             + '<td><div class="dv-docno">' + (d.grn_number ? esc(d.grn_number) : '<span class="dv-docno--none">bez numeru</span>') + '</div>'
             +   '<div class="rx-sub">ID ' + parseInt(d.id, 10) + (cancelled ? ' · <span class="text-danger font-weight-bold">anulowane</span>' : '') + '</div></td>'
             + '<td>' + docCellHtml(d) + '</td>'
             + '<td class="text-nowrap"><div class="rx-date-main">' + esc(fmtDate(full)) + '</div>'
             +   '<div class="rx-sub">' + (sub !== '' ? esc(sub) : '&nbsp;') + '</div></td>'
             + '<td class="rx-vendor">' + esc(d.vendor_name || '—') + '</td>'
             + '<td class="d-none d-md-table-cell">' + poChipsHtml(d.po_numbers) + '</td>'
             + '<td class="rx-num"><span class="rx-pill">' + (parseInt(d.line_count, 10) || 0) + '</span></td>'
             + '<td class="d-none d-lg-table-cell"><span class="rx-person"><span class="rx-avatar" aria-hidden="true">'
             +   esc(hxInitials(person)) + '</span>' + esc(person || '—') + '</span></td>'
             + '<td class="text-right text-nowrap"><a class="btn btn-sm rx-btn-quiet" href="' + escAttr(deliveryUrl(d.id)) + '">'
             +   'Otwórz <i class="bi bi-chevron-right rx-go"></i></a></td>'
             + '</tr>';
    }

    function renderDeliveries() {
        filterDeliveries();
        const $status = $('#deliveriesStatus');
        const $wrap = $('#deliveriesTableWrap');
        $('#deliveriesSecInfo').text(deliveriesFiltered.length > 0 ? '· ' + deliveriesFiltered.length : '');

        if (deliveriesFiltered.length === 0) {
            $wrap.addClass('d-none');
            $status.removeClass('d-none').html(historyFiltersActive()
                ? emptyStateHtml('bi-funnel', '', 'Brak dostaw dla wybranych filtrów',
                    'Zmień wyszukiwaną frazę lub zakres dat.',
                    '<button type="button" class="btn btn-sm rx-btn-quiet js-clear-history-filters"><i class="bi bi-x-circle"></i> Wyczyść filtry</button>')
                : emptyStateHtml('bi-truck', '', 'Brak zatwierdzonych dostaw',
                    'Dostawy pojawią się tu po zatwierdzeniu pierwszego przyjęcia wg faktury lub WZ.',
                    '<a class="btn btn-sm btn-primary" href="' + escAttr(newDeliveryUrl(0)) + '"><i class="bi bi-plus-lg"></i> Nowe przyjęcie</a>'));
            updateHistoryBadge();
            return;
        }
        $status.addClass('d-none').empty();
        $wrap.removeClass('d-none');
        let html = '';
        deliveriesFiltered.slice(0, deliveriesShown).forEach(function (d) { html += deliveryRowHtml(d); });
        $('#deliveriesBody').html(html);
        $('#deliveriesMore').toggleClass('d-none', deliveriesFiltered.length <= deliveriesShown);
        updateHistoryBadge();
    }

    function loadDeliveries() {
        if (deliveriesLoading) return Promise.resolve();
        deliveriesLoading = true;
        $('#deliveriesStatus').removeClass('d-none').html(
            '<div class="text-center text-muted py-4"><span class="spinner-border spinner-border-sm align-middle"></span>'
            + '<span class="ml-2 align-middle">Ładowanie dostaw…</span></div>');
        return fetchJson('delivery-list.php', { state: 'posted' }).then(function (r) {
            deliveriesAll = r.deliveries || [];
            deliveriesLoaded = true;
            deliveriesShown = DELIVERIES_PAGE;
            renderDeliveries();
        }).catch(function (err) {
            deliveriesAll = [];
            $('#deliveriesTableWrap').addClass('d-none');
            $('#deliveriesStatus').removeClass('d-none').html(emptyStateHtml(
                'bi-exclamation-octagon', 'danger', 'Nie udało się wczytać dostaw', err.message,
                '<button type="button" class="btn btn-sm btn-primary js-deliveries-retry"><i class="bi bi-arrow-clockwise"></i> Spróbuj ponownie</button>'));
        }).then(function () { deliveriesLoading = false; });
    }

    $(document).on('click', '.js-deliveries-retry', loadDeliveries);
    $('#deliveriesMoreBtn').on('click', function () {
        deliveriesShown += DELIVERIES_PAGE;
        renderDeliveries();
    });
    $(document).on('click', '#deliveriesTable tbody tr.dv-row-click', function (e) {
        if ($(e.target).closest('a, button').length) return;
        followRowLink(e, deliveryUrl(parseInt($(this).attr('data-delivery-id'), 10)));
    });

    // Filtry historii działają na obu tabelach.
    function reloadHistory() {
        historyPage = 1;
        deliveriesShown = DELIVERIES_PAGE;
        if (deliveriesLoaded) renderDeliveries();
        loadHistoryPage(1);
    }

    // ---- szkice (delivery-list.php?state=draft) --------------------------

    let draftsData = [];

    function renderDrafts() {
        const $status = $('#draftsStatus');
        const $wrap = $('#draftsTableWrap');
        $('#draftsCountBadge').text(draftsData.length);
        draftsByVendor = {};
        draftsData.forEach(function (d) {
            const k = parseInt(d.vendor_id, 10);
            draftsByVendor[k] = (draftsByVendor[k] || 0) + 1;
        });
        if (queueData.length) renderQueue();

        if (draftsData.length === 0) {
            $('#draftsBody').empty();
            $wrap.addClass('d-none');
            $('#draftsSummary').text('Nie masz rozpoczętych przyjęć.');
            $status.removeClass('d-none').html(emptyStateHtml(
                'bi-pencil-square', '', 'Brak roboczych przyjęć',
                'Szkic zapisuje się sam, gdy zaczniesz wpisywać dostawę — możesz do niego wrócić z tego miejsca.',
                '<a class="btn btn-sm btn-primary" href="' + escAttr(newDeliveryUrl(0)) + '"><i class="bi bi-plus-lg"></i> Nowe przyjęcie</a>'));
            return;
        }
        $status.addClass('d-none').empty();
        $wrap.removeClass('d-none');
        $('#draftsSummary').html('<strong>' + draftsData.length + '</strong> '
            + plural(draftsData.length, 'szkic', 'szkice', 'szkiców') + ' · najnowsze na górze');

        let html = '';
        draftsData.forEach(function (d) {
            const person = d.created_by_name || '';
            const created = d.created_at || '';
            html += '<tr data-delivery-id="' + parseInt(d.id, 10) + '">'
                  + '<td>' + docCellHtml(d) + '<div class="rx-sub">ID ' + parseInt(d.id, 10)
                  +   (created ? ' · założono ' + esc(fmtDate(created)) + (hxFmtTime(created) ? ' ' + esc(hxFmtTime(created)) : '') : '') + '</div></td>'
                  + '<td class="rx-vendor">' + esc(d.vendor_name || '—') + '</td>'
                  + '<td>' + poChipsHtml(d.po_numbers) + '</td>'
                  + '<td class="rx-num"><span class="rx-pill' + ((parseInt(d.line_count, 10) || 0) === 0 ? ' rx-pill--empty' : '') + '">'
                  +   (parseInt(d.line_count, 10) || 0) + '</span></td>'
                  + '<td class="d-none d-lg-table-cell"><span class="rx-person"><span class="rx-avatar" aria-hidden="true">'
                  +   esc(hxInitials(person)) + '</span>' + esc(person || '—') + '</span></td>'
                  + '<td class="text-right text-nowrap">'
                  +   '<div class="rx-action-cell">'
                  +     '<button type="button" class="btn btn-sm btn-outline-danger rx-close-po-btn js-draft-discard" '
                  +       'data-delivery-id="' + parseInt(d.id, 10) + '" title="Usuń szkic" aria-label="Usuń szkic"><i class="bi bi-trash"></i></button>'
                  +     '<a class="btn btn-sm btn-primary" href="' + escAttr(deliveryUrl(d.id)) + '"><i class="bi bi-pencil-square"></i> Kontynuuj</a>'
                  +   '</div></td>'
                  + '</tr>';
        });
        $('#draftsBody').html(html);
    }

    function loadDrafts() {
        return fetchJson('delivery-list.php', { state: 'draft' }).then(function (r) {
            draftsData = r.deliveries || [];
            renderDrafts();
        }).catch(function (err) {
            $('#draftsCountBadge').text('0');
            $('#draftsSummary').text('Szkice niedostępne.');
            $('#draftsTableWrap').addClass('d-none');
            $('#draftsStatus').removeClass('d-none').html(emptyStateHtml(
                'bi-exclamation-octagon', 'danger', 'Nie udało się wczytać szkiców', err.message,
                '<button type="button" class="btn btn-sm btn-primary js-drafts-retry"><i class="bi bi-arrow-clockwise"></i> Spróbuj ponownie</button>'));
        });
    }

    $(document).on('click', '.js-drafts-retry', loadDrafts);
    $(document).on('click', '.js-draft-discard', function () {
        const id = parseInt($(this).attr('data-delivery-id'), 10);
        if (!id) return;
        if (!window.confirm('Usunąć ten szkic przyjęcia razem z wpisanymi pozycjami? Nic nie zostało zaksięgowane.')) return;
        const $b = $(this).prop('disabled', true);
        fetchJson('delivery-discard.php', { delivery_id: id }).then(function () {
            showAlert('<i class="bi bi-check-circle-fill"></i> Szkic został usunięty.', 'success');
            return loadDrafts();
        }).catch(function (err) {
            $b.prop('disabled', false);
            showAlert(esc(err.message), 'danger');
        });
    });

    $(document).on('click', '.js-goto-drafts', function (e) {
        e.preventDefault();
        $('#draftsTabLink').tab('show');
    });

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
        $historyPager.empty();
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
        $historyPager.html(html);
    }

    function goToHistoryPage(page) {
        loadHistoryPage(page);
        // Pager stoi pod tabelą — po zmianie strony wracamy na górę listy.
        const top = $historyPane.offset().top - 16;
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
        reloadHistory();
    }

    $historySearch.on('input', reloadHistory);
    $dateFrom.add($dateTo).on('change', reloadHistory);
    $(document).on('click', '.js-clear-history-filters', clearHistoryFilters);
    $(document).on('click', '.js-history-retry', function () { loadHistoryPage(historyPage); });

    $('.rx-seg [data-preset]').on('click', function () {
        const days = parseInt($(this).attr('data-preset'), 10);
        if ($(this).hasClass('is-active')) {
            $dateFrom.val('');
        } else {
            $dateFrom.val(presetFrom(days === 0 ? 1 : days));
        }
        $dateTo.val('');
        reloadHistory();
    });

    // Klik w wiersz historii (poza linkami) → strona szczegółów przyjęcia.
    // Delegowane na #receiptsTable tbody tr.receipt-row — działa też dla
    // wierszy wstawionych przez AJAX (których nie było przy inicjalizacji).
    $(document).on('click', '#receiptsTable tbody tr.receipt-row', function (e) {
        if ($(e.target).closest('a, button').length) return;
        followRowLink(e, detailUrl(parseInt($(this).attr('data-id'), 10)));
    });

    // =====================================================================
    // Zakładki ↔ hash w adresie
    // =====================================================================

    $('#receiptsTabs a[data-toggle="tab"]').on('shown.bs.tab', function (e) {
        let hash = '';
        if (e.target.id === 'historyTabLink') hash = HISTORY_HASH;
        else if (e.target.id === 'draftsTabLink') hash = DRAFTS_HASH;
        if (window.history && window.history.replaceState) {
            window.history.replaceState(null, '', window.location.pathname + window.location.search + hash);
        }
        // Lazy load historii przy pierwszej aktywacji zakładki: wiersze
        // nie lecą w HTML przy pierwszym renderze strony (patrz
        // receipts-view.php — tbody ma tylko placeholder „Ładowanie…").
        // Dzięki temu wejście na /purchase/receipts nie ciągnie 9 MB
        // od razu, a tylko wtedy, gdy operator faktycznie zerka w historię.
        if (e.target.id === 'historyTabLink') {
            if (!deliveriesLoaded && !deliveriesLoading) loadDeliveries();
            if (!historyLoaded && !historyLoading) loadHistoryPage(historyPage);
        }
    });

    // =====================================================================
    // Start
    // =====================================================================

    // Hoistowane „saved" — podświetlenie wiersza leci dopiero po
    // doczytaniu historii (komunikatu nie chcemy stracić, on leci
    // od razu w init niżej).
    let savedDoc        = null;
    let savedN          = 0;
    let savedPo         = null;
    let savedState      = null;
    let savedAlertShown = false;  // czy alert „zapisano" już wyświetlono
    let savedHighlighted = false; // czy .receipt-row-new już nałożono

    const successParams = new URLSearchParams(window.location.search);
    savedDoc   = successParams.get('saved');
    savedN     = parseInt(successParams.get('n'), 10);
    savedPo    = successParams.get('po');
    savedState = successParams.get('po_state');
    if (isNaN(savedN)) savedN = 0;

    if (window.location.hash === HISTORY_HASH) {
        $('#historyTabLink').tab('show');
    } else if (window.location.hash === DRAFTS_HASH) {
        $('#draftsTabLink').tab('show');
    }

    // Szkice ładują się od razu (mały zestaw): licznik zakładki i chipy
    // „N robocze" w nagłówkach dostawców. Kolejka renderuje się ponownie
    // po ich dojściu (renderDrafts).
    loadDrafts();
    loadQueue();
    // Historia celowo NIE ładuje się tu — patrz hook `shown.bs.tab`
    // powyżej. Jeśli użytkownik wskoczył na stronę z #historia, hook
    // odpali się po przełączeniu zakładki. Jeśli nie — pierwsze
    // wejście nie ciągnie nic z bazy dla zakładki historii.

    // ---- powrót z formularza przyjęcia (?saved=…) ----------------------
    //
    // Komunikat sukcesu leci zawsze (niezależnie od tego, czy
    // użytkownik zajrzy do historii). Link „Pokaż w historii"
    // natomiast kieruje do zakładki i czyści filtry (revealHistoryRow) —
    // jego obsługa wymaga już doczytanej strony, więc musi poczekać
    // na pierwszy loadHistoryPage (patrz maybeHighlightSavedReceipt
    // w renderHistoryPage). Podświetlenie wiersza klasą
    // .receipt-row-new nakładane jest identycznie jak poprzednio —
    // po znalezieniu wiersza w DOM (po data-doc).

    if (savedDoc !== null && !savedAlertShown) {
        // Pusty `?saved=` też pokazuje alert (z placeholder-em „—” w numerze
        // GRN) — zachowanie identyczne z wersją sprzed refaktora.
        const displayDoc = (savedDoc && savedDoc !== '') ? savedDoc : '—';
        const msg = '<i class="bi bi-check-circle-fill"></i> '
                  + 'Zapisano przyjęcie <strong>' + esc(displayDoc) + '</strong> — przyjęto '
                  + savedN + ' ' + plural(savedN, 'pozycję', 'pozycje', 'pozycji') + '.'
                  + (savedPo
                      ? ' Zamówienie <strong>' + esc(savedPo) + '</strong>'
                        + (savedState ? ' ma teraz status „' + esc(stateMeta(savedState).label) + '".' : '.')
                      : '')
                  + '<span class="rx-alert-actions">'
                  +   '<a class="alert-link js-reveal-new-receipt" href="' + HISTORY_HASH + '">Pokaż w historii</a>'
                  + '</span>';
        showAlert(msg, 'success');
        savedAlertShown = true;

        // Bez query stringu w pasku adresu F5 nie pokaże komunikatu ponownie.
        if (window.history && window.history.replaceState) {
            window.history.replaceState({}, '', window.location.pathname + window.location.hash);
        }
    }

    // Po wyrenderowaniu strony przez AJAX szukamy wiersza po data-doc
    // i nakładamy klasę .receipt-row-new. Wywoływane z renderHistoryPage.
    // Link „Zobacz przyjęcie" (z URL do strony szczegółów) dodajemy
    // dopiero gdy wiersz faktycznie siedzi w DOM, bo dopiero wtedy
    // znamy jego id.
    function maybeHighlightSavedReceipt() {
        if (savedDoc === null || savedDoc === '' || savedHighlighted) return;
        if (historyTotal === 0) return;
        const $newRow = $('#receiptsTable tbody tr.receipt-row').filter(function () {
            return $(this).attr('data-doc') === savedDoc;
        }).first();
        if (!$newRow.length) return;
        $newRow.addClass('receipt-row-new');
        savedHighlighted = true;

        // Doklejamy „Zobacz przyjęcie" obok „Pokaż w historii" w alercie
        // — tu już mamy id z data-id wiersza, więc URL budujemy śmiało.
        const $actions = $('#alertContainer .rx-alert-actions').first();
        if ($actions.length && $actions.find('.js-view-new-receipt').length === 0) {
            $actions.prepend(
                '<a class="alert-link js-view-new-receipt" href="'
                + escAttr(detailUrl($newRow.attr('data-id')))
                + '">Zobacz przyjęcie</a>'
            );
        }
    }

    $(document).on('click', '.js-reveal-new-receipt', function (e) {
        e.preventDefault();
        revealHistoryRow();
    });

    // ---- powrót po zamknięciu PO z formularza przyjęcia (?closed=…) -----
    //
    // Osobna gałąź od powyższego „saved" — tu NIE było zapisu przyjęcia,
    // więc nie ma wiersza do podświetlenia ani linku „Pokaż w historii".
    // Operator wybrał stan wynikowy (received/cancelled) w oknie
    // „Zamknij zamówienie" na formularzu. Komunikat krótszy, bez akcji,
    // ale ten sam schemat strip-query po wyrenderowaniu.

    const closedState = successParams.get('closed');
    if (closedState !== null && closedState !== '') {
        const closedPo = successParams.get('po');
        const meta = stateMeta(closedState);
        const icon = meta.tone === 'red'
            ? '<i class="bi bi-x-circle-fill"></i> '
            : '<i class="bi bi-check-circle-fill"></i> ';
        let closedMsg = icon
            + 'Zamówienie <strong>' + esc(closedPo || '—') + '</strong> zamknięto'
            + ' — ma teraz status „<strong>' + esc(meta.label) + '</strong>".';
        showAlert(closedMsg, 'success');

        if (window.history && window.history.replaceState) {
            window.history.replaceState({}, '', window.location.pathname + window.location.hash);
        }
    }

    // Przejście do konkretnego wiersza historii: czyści filtry, przeładowuje
    // stronę 1 i po doczytaniu szuka wiersza po data-doc, scrolluje do niego
    // i nakłada .receipt-row-new. Najnowsze przyjęcie zawsze ląduje na
    // stronie 1 po wyczyszczeniu filtrów — patrz receipts-history.php
    // (ORDER BY received_at DESC, id DESC), więc „Pokaż w historii"
    // z powrotu z formularza zawsze znajdzie wiersz po jednym fetchu.
    function revealHistoryRow() {
        if (savedDoc === null || savedDoc === '') return;
        $historySearch.val('');
        $dateFrom.val('');
        $dateTo.val('');
        historyPage = 1;
        savedHighlighted = false;  // resetujemy, bo clear filtrów to świeży render

        const scrollToRow = function () {
            const $fresh = $('#receiptsTable tbody tr.receipt-row').filter(function () {
                return $(this).attr('data-doc') === savedDoc;
            }).first();
            if (!$fresh.length) return;
            $fresh.removeClass('receipt-row-new');
            void $fresh[0].offsetWidth; // restart animacji
            $fresh.addClass('receipt-row-new');
            $('html, body').animate({ scrollTop: $fresh.offset().top - 120 }, 300);
        };

        const fetchAndShow = function () {
            loadHistoryPage(1).done(scrollToRow);
        };

        if ($historyPane.hasClass('active')) {
            fetchAndShow();
        } else {
            $historyPane.one('shown.bs.tab', fetchAndShow);
            $('#historyTabLink').tab('show');
        }
    }
});
