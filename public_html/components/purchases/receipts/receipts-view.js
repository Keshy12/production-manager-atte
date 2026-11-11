// Przyjęcia towaru — /purchase/receipts (lista).
//
// Trzy zakładki:
//   „Do przyjęcia"     — kolejka z receipts-queue.php (POST), render tutaj,
//                        pogrupowana wg dostawcy; jeden przycisk
//                        „Przyjmij dostawę" na dostawcę,
//   „Robocze"          — szkice przyjęć (delivery-list.php?state=draft),
//   „Historia przyjęć" — zatwierdzone dostawy (delivery-list.php?state=posted);
//                        wiersze renderuje JS z pobranej listy, filtr
//                        (szukajka + zakres dat) działa po stronie klienta.
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
// Wiersze historii (dostawy) renderuje JS z listy pobranej przez
// delivery-list.php — filtr po szukajce i zakresie dat działa lokalnie
// na tej liście.
//
// Potwierdzenie zamówienia (stan `sent`) ma własną stronę:
// /admin/purchase/documents/respond?id=POID&type=po — operator wpisuje
// tam wynegocjowane przez dostawcę ceny per pozycja, a zapis przenosi
// zamówienie do stanu `confirmed`. Tu tylko renderujemy link do niej.

$(document).ready(function () {
    const ajaxBase = COMPONENTS_PATH + '/purchases/receipts/';

    const HISTORY_HASH = '#historia';
    const DRAFTS_HASH = '#robocze';
    // Krok przycisku „Pokaż więcej" w tabeli dostaw. Po podłączeniu starego
    // GRN-ów do warstwy dostaw (2026-11-11) historia ma ~3 800 pozycji —
    // przy kroku 25 trzeba by było klikać 150 razy, stąd 500.
    const DELIVERIES_PAGE = 500;

    // Karty filtrujące w kolejce → zestawy statusów (wartości opcji
    // #queueStates). Ten sam wzorzec co /purchase/documents
    // (documents-view.js → STAT_STATES): karta „Przyjęcie w toku"
    // obejmuje dwa stany — in_progress + partially_received.
    //
    // Karta „Po terminie" jest OSOBNA i WYŁĄCZNA z kartami statusu —
    // ten sam wzorzec co dwie grupy kafelków na /admin/purchase/vendors
    // (tam: grupa „Status" z zasadą „co najmniej jeden" i osobna grupa
    // niezależnych kafelków). Włączenie „Po terminie" czyści wybór
    // statusów i blokuje karty statusu (są wyłączone do czasu jej
    // wyłączenia).
    const QUEUE_STAT_STATES = {
        sent:        ['sent'],
        confirmed:   ['confirmed'],
        in_progress: ['in_progress', 'partially_received']
    };

    // Tooltip kart zablokowanych (karta „Po terminie" jest włączona).
    const LATE_LOCK_TITLE = 'Karta „Po terminie" jest włączona — karty statusu są wyłączone';

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

    function hasSelectpicker() { return typeof $.fn.selectpicker === 'function'; }

    function setPicker($el, values) {
        if (hasSelectpicker()) {
            try { $el.selectpicker('val', values); } catch (e) { $el.val(values); }
            $el.selectpicker('refresh');
        } else {
            $el.val(values);
        }
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
    //
    // Jednostką tej listy jest POZYCJA ZAMÓWIENIA, nie zamówienie.
    // Operator pyta „co ma przyjechać i co się spóźnia", a nie „które PO
    // jest w jakim stanie" — więc:
    //
    //   • karta dostawcy (domyślnie ZWINIĘTA) niesie tylko to, co da się
    //     ocenić rzutem oka: nazwę, jeden czerwony sygnał spóźnienia,
    //     liczbę otwartych pozycji i zbiorczy postęp,
    //   • po rozwinięciu: pasek zamówień (chipy z menu Edytuj / Zamknij /
    //     Anuluj / Potwierdź odpowiedź) + PŁASKA tabela wszystkich
    //     otwartych pozycji dostawcy, posortowana wg terminu —
    //     spóźnione najpierw (od najstarszych), bez terminu na końcu.
    //
    // Terminy liczy receipts-queue.php per POZYCJA
    // (purchase__order_item.expected_delivery_date z fallbackiem na termin
    // nagłówka PO) i tylko dla pozycji otwartych. `daysLate` pozycji:
    // >0 = dni po terminie, 0 = dzisiaj, <0 = dni do terminu, null = brak.
    // =====================================================================

    const $queueStatus    = $('#queueStatus');
    const $queueTableWrap = $('#queueTableWrap');
    const $queueBody      = $('#queueBody');
    const $queueVendor    = $('#queueVendor');
    const $queueStates    = $('#queueStates');
    const $queueSearch    = $('#queueSearch');
    const $queueOverdue   = $('#queueOverdueOnly');
    const $refreshBtn     = $('#queueRefreshBtn');

    // Ostatnio wczytany payload (zamówienia) + spłaszczona lista pozycji,
    // na której pracują filtry. Filtry pracują na danych, nie na tekście
    // wiersza — „Dostawca" dopasowuje dokładną nazwę.
    let queueData  = [];
    let queueItems = [];   // [{ it, p, g, idx }]
    let queueGroups = [];  // grupy dostawców ostatniego renderu

    // vendor_id => liczba szkiców (z zakładki „Robocze") — chip w karcie dostawcy.
    let draftsByVendor = {};

    // Karty dostawców: rozwinięte/zwinięte. Domyślnie WSZYSTKO zwinięte —
    // kolejka to 200+ pozycji u 25 dostawców, więc na wejściu operator ma
    // dostać listę dostawców, a nie ścianę wierszy. Stan trzymamy w pamięci
    // (klucz = vendorKey), więc „Odśwież" i doczytanie szkiców go nie kasują.
    const vendorOpen = {};
    // Nadpisania na czas szukania: przy niepustej frazie karty z trafieniami
    // rozwijają się same, ale operator może je ręcznie zwinąć. Czyścimy je
    // przy każdej zmianie frazy — po wyczyszczeniu pola wraca jego własny
    // (ręczny) układ z vendorOpen.
    let searchOpen = {};

    // Zawężenie karty do JEDNEGO zamówienia: vendorKey => poId. Ustawia je
    // klik w chip zamówienia w pasku (patrz poChipHtml / handler
    // „.vq-pochip"). Mapa żyje tak jak vendorOpen — przeżywa „Odśwież" i
    // przerysowanie karty; czyści ją tylko ponowny klik w ten sam chip,
    // przycisk „Pokaż wszystkie" w karcie, globalne „Wyczyść filtry" oraz
    // zniknięcie zamówienia z paska (walidacja w applyQueueFilters).
    //
    // Zwinięcie karty NIE kasuje zawężenia — jest częścią stanu karty tak
    // samo jak jej rozwinięcie, a po ponownym rozwinięciu widać i aktywny
    // chip, i pasek „Pokazuję tylko …" z przyciskiem czyszczącym, więc
    // operator nie zgubi kontekstu.
    const vendorPoFilter = {};

    function vendorKey(p) {
        return p.vendorId ? 'v' + p.vendorId : 'n:' + (p.vendorName || '');
    }

    function poFilterOf(key) { return intOf(vendorPoFilter[key]); }

    function setPoFilter(key, poId) {
        if (poId) vendorPoFilter[key] = poId;
        else delete vendorPoFilter[key];
    }

    function anyPoFilter() {
        return Object.keys(vendorPoFilter).length > 0;
    }

    function searchActive() {
        return String($queueSearch.val() || '').trim() !== '';
    }

    function groupOpen(key, hasHits) {
        if (searchActive()) {
            return Object.prototype.hasOwnProperty.call(searchOpen, key)
                ? !!searchOpen[key] : !!hasHits;
        }
        return !!vendorOpen[key];
    }

    function setGroupOpen(key, open) {
        if (searchActive()) searchOpen[key] = open;
        else vendorOpen[key] = open;
    }

    // ---- drobiazgi na danych kolejki ------------------------------------

    function intOf(v) {
        const n = parseInt(v, 10);
        return isNaN(n) ? 0 : n;
    }

    function openItemsOf(p) { return Array.isArray(p.openItems) ? p.openItems : []; }
    function lateItemsOf(p) { return Array.isArray(p.lateItems) ? p.lateItems : []; }
    function lateLines(p)   { return intOf(p.lateLines); }
    function isLatePo(p)    { return lateLines(p) > 0; }

    function daysLateOf(it) {
        return (it.daysLate === null || it.daysLate === undefined)
            ? null : parseInt(it.daysLate, 10);
    }

    // Najdłuższe spóźnienie w zamówieniu (dla kafelka „po terminie").
    function maxDaysLateOfPo(p) {
        let mx = 0;
        lateItemsOf(p).forEach(function (it) {
            const d = daysLateOf(it);
            if (d !== null && d > mx) mx = d;
        });
        if (mx === 0 && p.daysOverdue !== null && p.daysOverdue !== undefined) {
            mx = Math.max(0, intOf(p.daysOverdue));
        }
        return mx;
    }

    function pozWord(n) { return plural(n, 'pozycja', 'pozycje', 'pozycji'); }

    // Ton terminu pozycji — steruje plakietką i czerwoną krawędzią wiersza.
    function dueTone(d) {
        if (d === null) return 'none';
        if (d > 0)  return 'late';
        if (d === 0) return 'today';
        return d >= -7 ? 'soon' : 'far';
    }

    function dueLabel(d) {
        if (d === null) return 'bez terminu';
        if (d > 0)   return d + ' ' + dayWord(d) + ' po terminie';
        if (d === 0) return 'dzisiaj';
        const n = Math.abs(d);
        if (n === 1) return 'jutro';
        if (n === 2) return 'pojutrze';
        return 'za ' + n + ' ' + dayWord(n);
    }

    // Kolejność pozycji w tabeli dostawcy: termin rosnąco (czyli spóźnione
    // od najstarszych), bez terminu na końcu, potem wg zamówienia i pozycji.
    function itemSortKey(r) {
        const due = r.it.dueDate ? String(r.it.dueDate).substring(0, 10) : '9999-99-99';
        return due + '|' + (1e9 + intOf(r.p.poId)) + '|' + (1e9 + intOf(r.it.itemId));
    }

    // ---- stany panelu (ładowanie / pusto / brak wyników / błąd) --------

    function skeletonHtml() {
        let html = '';
        for (let i = 0; i < 5; i++) {
            html += '<div class="vq-skel-head" aria-hidden="true">'
                  + '<span class="rx-skel" style="width:14rem"></span>'
                  + '<span class="rx-skel rx-skel--pill"></span>'
                  + '<span class="rx-skel rx-skel--btn"></span>'
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
        queueItems = [];
        queueGroups = [];
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
        syncExpandAllBtn();
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

    // Liczniki na kartach filtrujących. Licznik karty = ile zamówień
    // zostało w kolejce po zastosowaniu pozostałych filtrów (dostawca,
    // szukanie) — własnej osy karty nie liczymy (status przy karcie
    // statusu, termin przy karcie „Po terminie"), bo włączenie karty
    // zerowałoby jej własny licznik.
    //
    // Karta „Po terminie" liczy ZAMÓWIENIA, które mają co najmniej jedną
    // otwartą pozycję po terminie (receipts-queue.php → lateLines).
    // Podpis pod wartością pokazuje, ile to łącznie pozycji i jak
    // długie jest największe spóźnienie.
    function updateStats() {
        const f = queueFilters();
        let sent = 0, confirmed = 0, inProgress = 0, overdue = 0;
        let lateItemsTotal = 0, maxLate = 0;

        queueData.forEach(function (p) {
            if (!poInScope(p, f)) return;
            const state = p.state || '';
            if (state === 'sent') sent++;
            else if (state === 'confirmed') confirmed++;
            else if (state === 'in_progress' || state === 'partially_received') inProgress++;
            if (isLatePo(p)) {
                overdue++;
                lateItemsTotal += lateLines(p);
                maxLate = Math.max(maxLate, maxDaysLateOfPo(p));
            }
        });

        setStatValue('sent', sent);
        setStatValue('confirmed', confirmed);
        setStatValue('in_progress', inProgress);
        setStatValue('overdue', overdue);

        $('[data-stat="overdue"] [data-stat-sub]').text(overdue > 0
            ? lateItemsTotal + ' ' + pozWord(lateItemsTotal)
                + (maxLate > 0 ? ' · do ' + maxLate + ' ' + dayWord(maxLate) : '')
            : 'wszystko w terminie');
        $('.rx-stat--overdue').toggleClass('has-value', overdue > 0);
    }

    // Wpis licznika do karty + stan „zero" (bledna wartość).
    function setStatValue(key, n) {
        const $tile = $('.rx-stats--filter .rx-stat[data-stat="' + key + '"]');
        $tile.find('[data-stat-value]').text(n);
        $tile.toggleClass('is-zero', n === 0);
    }

    // ---- render ---------------------------------------------------------

    function renderQueue() {
        $('#queueCountBadge').text(queueData.length);
        updateStats();
        rebuildVendorFilter();

        if (queueData.length === 0) {
            $queueBody.empty();
            queueItems = [];
            queueGroups = [];
            showQueueState(emptyStateHtml(
                'bi-check2-all', 'ok',
                'Nic nie czeka na przyjęcie',
                'Wszystkie wysłane i potwierdzone zamówienia zostały odebrane.'
            ));
            updateQueueSummary(0, 0);
            syncQueueFilterUi();
            syncExpandAllBtn();
            return;
        }

        // Grupowanie wg dostawcy. Kolejność kart = kolejność pierwszego
        // zamówienia danego dostawcy (serwer sortuje po najwcześniejszym
        // terminie otwartej pozycji, więc na górze lądują ci, którzy mają
        // coś najpilniejszego).
        const groups = [];
        const byKey = {};
        queueItems = [];
        queueData.forEach(function (p) {
            const key = vendorKey(p);
            if (!byKey[key]) {
                byKey[key] = { key: key, vendorId: p.vendorId || 0, vendorName: p.vendorName || '—', pos: [], items: [] };
                groups.push(byKey[key]);
            }
            const g = byKey[key];
            g.pos.push(p);
            openItemsOf(p).forEach(function (it) {
                const rec = { it: it, p: p, g: g, idx: queueItems.length };
                queueItems.push(rec);
                g.items.push(rec);
            });
        });
        groups.forEach(function (g, gi) {
            g.index = gi;
            g.items.sort(function (a, b) {
                const ka = itemSortKey(a), kb = itemSortKey(b);
                return ka < kb ? -1 : (ka > kb ? 1 : 0);
            });
        });
        queueGroups = groups;

        // Pozycje dostawcy siedzą we WŁASNYM kontenerze .vq-vbody — to on
        // jest celem aria-controls nagłówka i to jego zwijamy jednym ruchem.
        let html = '';
        groups.forEach(function (g) {
            const isOpen = groupOpen(g.key, false);
            html += vendorHeadHtml(g, g.items, g.pos, isOpen);
            html += '<div class="vq-vbody' + (isOpen ? '' : ' vq-vbody--collapsed') + '"'
                  + ' id="vqGroup' + g.index + '" data-vg="' + g.index + '" role="region"'
                  + ' aria-label="' + escAttr('Pozycje do przyjęcia: ' + g.vendorName) + '">'
                  + poStripHtml(g, g.pos, countByPo(g.items), 0)
                  + poFilterBarHtml(g, g.pos, 0, 0)
                  + itemsTableHtml(g.items)
                  + '<div class="vq-itempty' + (g.items.length > 0 ? ' d-none' : '') + '">'
                  +   emptyItemsText(g.items.length === 0 && g.pos.length > 0, 0)
                  + '</div>'
                  + '</div>';
        });
        $queueBody.html(html);
        showQueueList();
        applyQueueFilters();
    }

    function emptyItemsText(noOpenAtAll, poFiltered, poHasNoOpenItems) {
        if (noOpenAtAll) {
            return 'Nie ma tu otwartych pozycji — wszystko zostało przyjęte.'
                 + ' Zamówienie zamkniesz z menu „⋮" przy jego numerze.';
        }
        if (poHasNoOpenItems) {
            return 'To zamówienie nie ma otwartych pozycji — wszystko zostało przyjęte.'
                 + ' Zamkniesz je z menu „⋮" przy numerze albo kliknij „Pokaż wszystkie",'
                 + ' żeby wrócić do pozostałych zamówień dostawcy.';
        }
        if (poFiltered) {
            return 'To zamówienie nie ma pozycji pasujących do pozostałych filtrów.'
                 + ' Kliknij „Pokaż wszystkie", żeby zobaczyć pozostałe zamówienia dostawcy.';
        }
        return 'Żadna pozycja nie pasuje do filtrów.';
    }

    function countByPo(items) {
        const map = {};
        items.forEach(function (r) {
            const k = intOf(r.p.poId);
            map[k] = (map[k] || 0) + 1;
        });
        return map;
    }

    // ---- karta dostawcy: nagłówek --------------------------------------
    //
    // Zwinięty nagłówek to JEDNA linia i tylko cztery informacje:
    //   [>] NAZWA DOSTAWCY   [⚠ 6 po terminie · do 23 dni]   15 pozycji   [■■□ 36%]   [2 robocze]  [Przyjmij dostawę]
    // Reszta (które pozycje, które zamówienia, stany) jest w rozwinięciu —
    // nagłówek ma być skanowalny, nie wyczerpujący.

    function vendorHeadHtml(g, items, pos, isOpen) {
        let late = 0, maxLate = 0;
        items.forEach(function (r) {
            const d = daysLateOf(r.it);
            if (d !== null && d > 0) {
                late++;
                if (d > maxLate) maxLate = d;
            }
        });

        let ordered = 0, received = 0, ready = 0, waiting = 0;
        pos.forEach(function (p) {
            ordered  += num(p.orderedQty);
            received += num(p.receivedQty);
            if (p.canReceive === false) waiting++; else ready++;
        });
        const pct = ordered > 0 ? Math.max(0, Math.min(100, (received / ordered) * 100)) : 0;

        // Plakietkę renderujemy ZAWSZE (pustą, gdy nic nie jest spóźnione) —
        // nagłówki stoją na siatce, więc brakujący element przesunąłby
        // kolumny sąsiednich kart i cała lista przestałaby się skanować.
        const flag = late > 0
            ? '<span class="vq-flag" title="' + escAttr('Pozycje po terminie: ' + late
                + '. Najstarsza spóźniona jest o ' + maxLate + ' ' + dayWord(maxLate) + '.') + '">'
              + '<i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i> '
              + '<strong>' + late + '</strong> po terminie · do <strong>' + maxLate + '</strong> ' + dayWord(maxLate)
              + '</span>'
            : '<span class="vq-flag vq-flag--none" aria-hidden="true"></span>';

        const count = items.length > 0
            ? '<span class="vq-vcount"><strong>' + items.length + '</strong> ' + pozWord(items.length) + '</span>'
            : '<span class="vq-vcount vq-vcount--none">0 pozycji otwartych</span>';

        const prog = '<span class="vq-vprog" title="'
                   + escAttr('Przyjęto ' + fmtQty(received) + ' z ' + fmtQty(ordered)
                       + ' zamówionych (' + pos.length + ' ' + plural(pos.length, 'zamówienie', 'zamówienia', 'zamówień') + ')') + '">'
                   + '<span class="rx-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100"'
                   +   ' aria-valuenow="' + Math.round(pct) + '">'
                   +   '<span class="' + (pct >= 100 ? 'is-done' : '') + '" style="width:' + pct.toFixed(1) + '%"></span>'
                   + '</span>'
                   + '<span class="vq-pct">' + Math.round(pct) + '%</span>'
                   + '</span>';

        const drafts = draftsByVendor[g.vendorId] || 0;
        const draftChip = drafts > 0
            ? '<a class="dv-vchip js-goto-drafts" href="' + DRAFTS_HASH + '" title="Przejdź do roboczych przyjęć">'
              + '<i class="bi bi-pencil-square"></i> ' + drafts + ' ' + plural(drafts, 'robocze', 'robocze', 'roboczych') + '</a>'
            : '';

        const action = (ready > 0 && g.vendorId)
            ? '<a class="btn btn-primary btn-sm" href="' + escAttr(newDeliveryUrl(g.vendorId)) + '">'
              + '<i class="bi bi-box-arrow-in-down"></i> Przyjmij dostawę</a>'
            : '<span class="dv-vwait">' + (waiting > 0 ? 'czeka na potwierdzenie' : 'brak zamówień do przyjęcia') + '</span>';

        return '<div class="dv-vhead vq-vhead' + (late > 0 ? ' is-late' : '') + (isOpen ? ' is-open' : '') + '"'
             +   ' data-vg="' + g.index + '" data-vkey="' + escAttr(g.key) + '">'
             + '<button type="button" class="vq-vtoggle" aria-expanded="' + (isOpen ? 'true' : 'false') + '"'
             +   ' aria-controls="vqGroup' + g.index + '">'
             +   '<span class="vq-chev" aria-hidden="true"><i class="bi bi-chevron-right"></i></span>'
             +   '<span class="vq-vname">' + esc(g.vendorName) + '</span>'
             +   '<span class="sr-only">' + (isOpen ? 'Zwiń' : 'Rozwiń') + ' ' + items.length + ' ' + pozWord(items.length) + '</span>'
             + '</button>'
             + flag
             + count
             + prog
             + '<span class="dv-vchips">' + draftChip + '</span>'
             + '<span class="dv-vgo">' + action + '</span>'
             + '</div>';
    }

    // ---- karta dostawcy: pasek zamówień --------------------------------
    //
    // Jeden chip = jedno zamówienie: kropka stanu, numer (link do ekranu
    // zamówienia), licznik otwartych pozycji i menu „⋮" z akcjami.
    // Zamówienie bez otwartych pozycji (wszystko przyjęte, ale PO wciąż
    // otwarte) NIE ma wierszy w tabeli — chip jest jedynym miejscem, z
    // którego da się je zamknąć, więc zostaje w pasku zawsze.
    //
    // data-action="close-po" / "cancel-po" + data-po-* czyta delegowany
    // handler w close-po.js (ten sam kontrakt co wcześniejszy kosz w
    // wierszu); close-po.js robi stopPropagation, więc klik w pozycję menu
    // nie zwija karty.
    //
    // Klik w SAM CHIP (kropka stanu, numer PO, licznik, wolne miejsce
    // wokół numeru) zawęża tabelę poniżej do tego jednego zamówienia;
    // ponowny klik zawężenie zdejmuje. Numer PO NIE jest linkiem —
    // kliknięcie go filtruje tak samo jak resztę chipa; ekran zamówienia
    // otwiera pozycja „Edytuj" w menu „⋮".
    // Klawiaturowym punktem wejścia do filtra są: numer PO (role=button +
    // Enter/Space) oraz licznik pozycji (prawdziwy <button aria-pressed>).

    function poChipHtml(p, nItems, vkey, activePoId) {
        const poId   = intOf(p.poId);
        const label  = p.poNumber || ('#' + poId);
        const meta   = stateMeta(p.state);
        const canRx  = p.canReceive !== false;
        const empty  = nItems === 0;
        const active = activePoId === poId;

        let menu = '<a class="dropdown-item" href="' + escAttr(editUrl(poId)) + '">'
                 + '<i class="bi bi-pencil-square"></i> Edytuj</a>';
        if (!canRx) {
            menu += '<a class="dropdown-item rx-action-success" href="' + escAttr(respondPoUrl(poId)) + '">'
                  + '<i class="bi bi-reply"></i> Potwierdź odpowiedź</a>';
        }
        menu += '<div class="dropdown-divider"></div>';
        if (canRx) {
            menu += '<button type="button" class="dropdown-item vq-action-danger"'
                  + ' data-action="close-po"'
                  + ' data-po-id="' + poId + '"'
                  + ' data-po-number="' + escAttr(label) + '"'
                  + ' data-ordered-qty="' + escAttr(num(p.orderedQty)) + '"'
                  + ' data-received-qty="' + escAttr(num(p.receivedQty)) + '"'
                  + ' data-line-count="' + escAttr(intOf(p.lineCount)) + '">'
                  + '<i class="bi bi-x-square"></i> Zamknij zamówienie</button>';
        } else {
            menu += '<button type="button" class="dropdown-item vq-action-danger"'
                  + ' data-action="cancel-po"'
                  + ' data-po-id="' + poId + '"'
                  + ' data-po-number="' + escAttr(label) + '">'
                  + '<i class="bi bi-x-square"></i> Anuluj zamówienie</button>';
        }

        const vendorNo = p.vendorPoNumber ? ' · nr u dostawcy ' + p.vendorPoNumber : '';
        const chipTitle = label + ' — ' + meta.label + vendorNo
            + ' · ' + (empty ? 'brak otwartych pozycji' : nItems + ' ' + pozWord(nItems) + ' do przyjęcia')
            + ' · ' + (active ? 'kliknij, aby pokazać wszystkie zamówienia'
                              : 'kliknij, aby pokazać tylko to zamówienie');
        const pickTitle = active
            ? 'Pokaż z powrotem pozycje wszystkich zamówień'
            : 'Pokaż tylko pozycje z zamówienia ' + label;

        return '<span class="vq-pochip dropdown' + (empty ? ' is-empty' : '') + (active ? ' is-filtered' : '') + '"'
             +   ' data-vkey="' + escAttr(vkey) + '" data-po-id="' + poId + '"'
             +   ' title="' + escAttr(chipTitle) + '">'
             + '<span class="vq-dot vq-dot--' + meta.tone + '" aria-hidden="true"></span>'
             + '<a class="vq-pochip-no" role="button" tabindex="0"'
             +   ' title="' + escAttr(pickTitle) + '">' + esc(label) + '</a>'
             + '<span class="sr-only">' + esc(meta.label) + '</span>'
             + '<button type="button" class="vq-pochip-n" aria-pressed="' + (active ? 'true' : 'false') + '"'
             +   ' title="' + escAttr(pickTitle) + '">' + nItems
             +   '<span class="sr-only"> ' + pozWord(nItems) + ' — ' + esc(pickTitle) + '</span></button>'
             + '<button type="button" class="vq-pochip-btn dropdown-toggle" data-toggle="dropdown"'
             +   ' aria-haspopup="true" aria-expanded="false"'
             +   ' aria-label="' + escAttr('Akcje zamówienia ' + label) + '">'
             +   '<i class="bi bi-three-dots-vertical" aria-hidden="true"></i></button>'
             + '<span class="dropdown-menu dropdown-menu-right">' + menu + '</span>'
             + '</span>';
    }

    // Pasek zamówień pokazuje ZAWSZE wszystkie zamówienia, które przeszły
    // filtry globalne — także wtedy, gdy karta jest zawężona do jednego
    // z nich. Inaczej nie dałoby się przełączyć na inne zamówienie ani
    // zdjąć zawężenia. Z tego samego powodu liczniki na chipach liczą
    // pozycje SPRZED zawężenia.
    function poStripHtml(g, pos, byPo, activePoId) {
        if (pos.length === 0) return '<div class="vq-postrip"></div>';
        let chips = '';
        pos.forEach(function (p) {
            chips += poChipHtml(p, byPo[intOf(p.poId)] || 0, g.key, activePoId);
        });
        return '<div class="vq-postrip">'
             + '<span class="vq-postrip-lbl">' + plural(pos.length, 'Zamówienie', 'Zamówienia', 'Zamówienia') + '</span>'
             + chips + '</div>';
    }

    // Pasek „Pokazuję tylko PO/… (N pozycji)" + wyjście z zawężenia.
    // Renderujemy go ZAWSZE (pusty i ukryty, gdy zawężenia nie ma), żeby
    // applyQueueFilters mógł go podmienić jednym replaceWith.
    function poFilterBarHtml(g, pos, activePoId, nShown) {
        if (!activePoId) return '<div class="vq-pofilter d-none"></div>';
        let label = '#' + activePoId;
        pos.forEach(function (p) {
            if (intOf(p.poId) === activePoId) label = p.poNumber || label;
        });
        return '<div class="vq-pofilter" data-vkey="' + escAttr(g.key) + '">'
             + '<i class="bi bi-funnel-fill" aria-hidden="true"></i> '
             + 'Pokazuję tylko <strong>' + esc(label) + '</strong> '
             + '<span class="vq-pofilter-n">(' + nShown + ' ' + pozWord(nShown) + ')</span>'
             + '<button type="button" class="vq-pofilter-clear js-po-filter-clear"'
             +   ' data-vkey="' + escAttr(g.key) + '">'
             +   '<i class="bi bi-x-circle" aria-hidden="true"></i> Pokaż wszystkie</button>'
             + '</div>';
    }

    // ---- karta dostawcy: tabela pozycji --------------------------------

    function itemsTableHtml(items) {
        if (items.length === 0) return '';
        let rows = '';
        items.forEach(function (r) { rows += itemRowHtml(r); });
        return '<div class="vq-itbl">'
             + '<div class="vq-ithead" aria-hidden="true">'
             +   '<div>Pozycja</div>'
             +   '<div>Zamówienie</div>'
             +   '<div>Termin</div>'
             +   '<div class="text-right">Pozostało</div>'
             + '</div>'
             + rows
             + '</div>';
    }

    function itemRowHtml(r) {
        const it = r.it;
        const p  = r.p;
        const d    = daysLateOf(it);
        const tone = dueTone(d);
        const canRx = p.canReceive !== false;
        const poId  = intOf(p.poId);

        const prod = (it.producerPartNo && it.producerPartNo !== it.vendorPartNo)
            ? '<span class="vq-it-prod">' + esc(it.producerPartNo) + '</span>' : '';
        const name = it.partName
            ? '<span class="vq-it-name" title="' + escAttr(it.partName) + '">' + esc(it.partName) + '</span>'
            : '<span class="vq-it-name vq-it-name--none">bez nazwy w kartotece</span>';

        // Ilość: pozostało / zamówiono + jednostka; „przyjęto N" tylko
        // wtedy, gdy część już przyszła (inaczej to zerowy szum).
        const remaining = num(it.remainingQty);
        const quantity  = num(it.quantity);
        const got       = num(it.quantityReceived);
        const unit      = it.unit ? ' ' + esc(it.unit) : '';
        const qtyMain = '<span class="vq-it-rem">' + esc(fmtQty(remaining)) + '</span>'
                      + '<span class="vq-it-of"> / ' + esc(fmtQty(quantity)) + unit + '</span>';
        const qtySub = got > 0
            ? '<div class="vq-it-sub">przyjęto ' + esc(fmtQty(got)) + '</div>' : '';

        // ⓘ = termin odziedziczony z nagłówka zamówienia (pozycja nie ma
        // własnego). Subtelnie, bo na razie dotyczy to większości danych.
        const hint = (it.dueDate && !it.hasRowDate)
            ? ' <i class="bi bi-info-circle vq-hint" title="'
              + escAttr('Termin z nagłówka zamówienia — pozycja nie ma własnego terminu')
              + '" aria-hidden="true"></i>'
            : '';

        // Przy terminach dalszych niż tydzień sama data mówi wszystko —
        // plakietka tylko dokładałaby szumu.
        const whenMod = tone === 'late' ? ' rx-when--late'
                      : (tone === 'today' ? ' rx-when--today'
                      : (tone === 'soon' ? ' rx-when--soon' : ''));
        const when = (tone === 'far')
            ? ''
            : '<span class="rx-when' + whenMod + '">' + esc(dueLabel(d)) + '</span>';

        // Stan pozycji to w praktyce stan jej zamówienia, więc stoi pod
        // numerem PO, a nie w osobnej kolumnie (ta u większości dostawców
        // byłaby pusta). Liczy się jedno: `sent` = dostawca nie potwierdził,
        // więc termin jest orientacyjny.
        const waitNote = canRx
            ? ''
            : '<span class="vq-it-wait" title="Zamówienie czeka na potwierdzenie dostawcy — termin jest orientacyjny">'
              + '<i class="bi bi-hourglass-split" aria-hidden="true"></i> czeka na potwierdzenie</span>';

        return '<div class="vq-itrow' + (tone === 'late' ? ' is-late' : '') + (canRx ? '' : ' is-wait') + '"'
             +   ' data-idx="' + r.idx + '" data-vg="' + r.g.index + '" data-po-id="' + poId + '">'
             + '<div class="vq-itcell vq-it-part"><span class="vq-cell-label">Pozycja</span>'
             +   '<span class="vq-it-top"><span class="vq-it-no">' + esc(it.vendorPartNo || '—') + '</span>' + prod + '</span>'
             +   name
             + '</div>'
             + '<div class="vq-itcell vq-it-po"><span class="vq-cell-label">Zamówienie</span>'
             +   '<a class="vq-it-polink" href="' + escAttr(editUrl(poId)) + '"'
             +     ' title="Otwórz zamówienie">' + esc(p.poNumber || ('#' + poId)) + '</a>'
             +   waitNote
             + '</div>'
             + '<div class="vq-itcell vq-it-due"><span class="vq-cell-label">Termin</span>'
             +   '<span class="vq-it-date">' + esc(it.dueDate ? fmtDate(it.dueDate) : '—') + hint + '</span>'
             +   when
             + '</div>'
             + '<div class="vq-itcell vq-it-qty"><span class="vq-cell-label">Pozostało</span>'
             +   qtyMain + qtySub
             + '</div>'
             + '</div>';
    }

    // ---- filtry kolejki -------------------------------------------------
    //
    // Filtry działają na POZYCJACH. Zamówienie bez otwartych pozycji nie ma
    // czego dopasować, więc jego chip oceniamy osobno (emptyPoHit) — inaczej
    // zniknęłoby z paska i nie dałoby się go zamknąć.

    function queueFilters() {
        return {
            search:  String($queueSearch.val() || '').toLowerCase().trim(),
            vendors: $queueVendor.val() || [],
            states:  ($queueStates.val() || []).map(String),
            // overdue: karta „Po terminie" (a razem z chipem „Tylko po
            // terminie" w pasku filtrów) — filtr po terminie.
            overdue: $queueOverdue.is(':checked')
        };
    }

    function queueFiltersActive(f) {
        return f.search !== '' || f.vendors.length > 0 || f.states.length > 0 || f.overdue;
    }

    function haystack(parts) {
        return parts.map(function (x) { return String(x == null ? '' : x); })
            .join(' ').toLowerCase();
    }

    // ---- filtry kolejki -------------------------------------------------
    //
    // Dwie osie filtra:
    //   1) STATUS — karty „Wysłane" / „Potwierdzone" / „Przyjęcie w toku"
    //      sterują pickerem #queueStates; kilka kart może być włączonych
    //      naraz (filtr to suma ich statusów),
    //   2) TERMIN — karta „Po terminie" (a razem z chipem „Tylko po
    //      terminie" w pasku filtrów) steruje #queueOverdueOnly.
    //
    // Karta „Po terminie" jest WYŁĄCZNA z kartami statusu: włączenie
    // czyści wybór statusów i blokuje karty statusu (jak zablokowany
    // kafelek na /admin/purchase/vendors). Patrz toggleQueueStat.

    function itemHit(r, f) {
        const it = r.it, p = r.p;
        if (f.vendors.length && f.vendors.indexOf(p.vendorName || '') === -1) return false;
        if (f.states.length && f.states.indexOf(p.state || '') === -1) return false;
        if (f.overdue) {
            const d = daysLateOf(it);
            if (d === null || d <= 0) return false;
        }
        if (f.search !== '') {
            const hay = haystack([it.vendorPartNo, it.producerPartNo, it.partName,
                                  p.poNumber, p.vendorPoNumber, p.vendorName]);
            if (hay.indexOf(f.search) === -1) return false;
        }
        return true;
    }

    function emptyPoHit(p, f) {
        if (f.vendors.length && f.vendors.indexOf(p.vendorName || '') === -1) return false;
        if (f.states.length && f.states.indexOf(p.state || '') === -1) return false;
        // Zamówienie bez otwartych pozycji nie może być „po terminie".
        if (f.overdue) return false;
        if (f.search !== '') {
            const hay = haystack([p.poNumber, p.vendorPoNumber, p.vendorName]);
            if (hay.indexOf(f.search) === -1) return false;
        }
        return true;
    }

    // Zamówienie w zakresie pozostałych filtrów (dostawca, szukanie) —
    // bez względu na wybór statusów i bez filtra terminu. Używane do
    // liczników kart: każda karta liczy „co by było widać, gdyby włączyć
    // TĘ kartę".
    function poInScope(p, f) {
        if (f.vendors.length && f.vendors.indexOf(p.vendorName || '') === -1) return false;
        if (f.search !== '') {
            const hay = haystack([p.poNumber, p.vendorPoNumber, p.vendorName]);
            if (hay.indexOf(f.search) === -1) return false;
        }
        return true;
    }

    // Podświetlenie aktywnych filtrów + stan kart filtra.
    function syncQueueFilterUi() {
        const f = queueFilters();
        $('#queueVendorCell').toggleClass('is-active', f.vendors.length > 0);
        $('#queueStatesCell').toggleClass('is-active', f.states.length > 0);
        $('#clearQueueFilters').toggleClass('d-none', !queueFiltersActive(f));

        // Karty filtra. Karta „Po terminie" jest WYŁĄCZNA z kartami
        // statusu: gdy jest włączona, karty statusu są zablokowane
        // (przygaszone, aria-disabled, tooltip tłumaczy dlaczego; klik
        // nic nie robi) — ten sam wzorzec co zablokowany kafelek na
        // /admin/purchase/vendors.
        const locked = f.overdue;
        $('.rx-stats--filter .rx-stat[data-stat]').each(function () {
            const $tile = $(this);
            const key = $tile.attr('data-stat');
            const isLate = key === 'overdue';
            const on = isLate ? f.overdue : queueStatActive(key, f.states);
            const isLocked = locked && !isLate;

            if ($tile.attr('data-orig-title') === undefined) {
                $tile.attr('data-orig-title', $tile.attr('title') || '');
            }
            const title = isLocked ? LATE_LOCK_TITLE : $tile.attr('data-orig-title');
            if (title) { $tile.attr('title', title); } else { $tile.removeAttr('title'); }

            $tile.toggleClass('is-active', on && !isLocked)
                 .toggleClass('is-locked', isLocked)
                 .attr('aria-pressed', (on && !isLocked) ? 'true' : 'false')
                 .attr('aria-disabled', isLocked ? 'true' : 'false');
        });

        // Podpis grupy statusu mówi wprost, że karty są wyłączone.
        $('[data-status-hint]').text(locked
            ? 'wyłączone przez „Po terminie"'
            : 'włącz, aby filtrować');
    }

    // Karta statusu jest włączona, gdy wybrany jest którykolwiek z jej
    // statusów (karta „Przyjęcie w toku" obejmuje dwa).
    function queueStatActive(key, states) {
        const set = QUEUE_STAT_STATES[key] || [];
        for (let i = 0; i < set.length; i++) {
            if (states.indexOf(set[i]) !== -1) return true;
        }
        return false;
    }

    // Przełączenie karty.
    //
    // Karta statusu: włączona → zdejmuje swoje statusy z filtra, wyłączona
    // → dodaje je (brakujące). Wybór pozostałych kart nietknięty, więc kilka
    // kart może być włączonych naraz, a filtr to suma ich statusów.
    //
    // Karta „Po terminie": włączenie czyści wybór statusów (karty statusu
    // są wtedy wyłączone) — dlatego te dwie osie nie działają razem.
    function toggleQueueStat(key) {
        if (key === 'overdue') {
            const on = !$queueOverdue.is(':checked');
            $queueOverdue.prop('checked', on);
            if (on) clearStates();            // wyklucza karty statusu
            applyQueueFilters();
            return;
        }
        // Karty statusu są zablokowane, gdy włączona jest „Po terminie".
        if ($queueOverdue.is(':checked')) return;
        const set = QUEUE_STAT_STATES[key] || [];
        if (!set.length) return;
        const current = ($queueStates.val() || []).map(String);
        const allOn = set.every(function (s) { return current.indexOf(s) !== -1; });
        let next;
        if (allOn) {
            next = current.filter(function (s) { return set.indexOf(s) === -1; });
        } else {
            next = current.slice();
            set.forEach(function (s) {
                if (next.indexOf(s) === -1) next.push(s);
            });
        }
        setPicker($queueStates, next);
        applyQueueFilters();
    }

    // Dwa przebiegi:
    //   1. filtry GLOBALNE (dostawca, status, po terminie, szukaj) — ich
    //      wynik buduje pasek zamówień i liczniki na chipach,
    //   2. zawężenie karty do jednego zamówienia (klik w chip) — zabiera
    //      tylko wiersze tabeli, nagłówek karty i postęp; paska zamówień
    //      NIE rusza, żeby dało się przełączyć na inne zamówienie.
    function applyQueueFilters() {
        const f = queueFilters();
        syncQueueFilterUi();
        // Liczniki na kartach zależą od pozostałych filtrów (dostawca,
        // szukanie) — przeliczamy je przy każdej zmianie filtrów.
        updateStats();
        if (queueItems.length === 0 && queueData.length === 0) { syncExpandAllBtn(); return; }

        const matched = {};        // vg => pozycje po filtrach globalnych
        const rowsByGroup = {};    // vg => elementy DOM (do drugiego przebiegu)
        let shownEmptyPos = 0;     // zamówienia bez otwartych pozycji, które zostają w pasku

        $queueBody.find('.vq-itrow').each(function () {
            const $row = $(this);
            const r = queueItems[parseInt($row.attr('data-idx'), 10)];
            if (!r) { $row.addClass('d-none'); return; }
            const hit = itemHit(r, f);
            $row.toggleClass('d-none', !hit);
            if (!hit) return;
            if (!matched[r.g.index]) { matched[r.g.index] = []; rowsByGroup[r.g.index] = []; }
            matched[r.g.index].push(r);
            rowsByGroup[r.g.index].push($row);
        });

        // Karta dostawcy znika razem z ostatnią widoczną pozycją (chyba że
        // zostaje w niej zamówienie bez otwartych pozycji). Liczniki nagłówka
        // i pasek zamówień PRZELICZAMY na tym, co przeszło filtry.
        let shown = 0;
        let visibleGroups = 0;
        queueGroups.forEach(function (g) {
            const base = matched[g.index] || [];
            const byPo = countByPo(base);
            const seen = {};
            base.forEach(function (r) { seen[intOf(r.p.poId)] = true; });

            const pos = [];
            g.pos.forEach(function (p) {
                if (seen[intOf(p.poId)]) { pos.push(p); return; }
                if (openItemsOf(p).length === 0 && emptyPoHit(p, f)) { pos.push(p); shownEmptyPos++; }
            });

            const $head = $queueBody.find('.dv-vhead[data-vg="' + g.index + '"]');
            const $body = $queueBody.find('.vq-vbody[data-vg="' + g.index + '"]');

            if (base.length === 0 && pos.length === 0) {
                $head.addClass('d-none');
                $body.addClass('d-none');
                return;
            }
            visibleGroups++;

            // Zawężenie do jednego zamówienia obowiązuje tylko dopóki ten
            // chip jest w pasku — inaczej filtr globalny mógłby go ukryć
            // i karta zostałaby pusta bez widocznego sposobu wyjścia.
            let activePo = poFilterOf(g.key);
            if (activePo && !pos.some(function (p) { return intOf(p.poId) === activePo; })) {
                setPoFilter(g.key, 0);
                activePo = 0;
            }

            let items = base;
            let activeIsEmptyPo = false;
            if (activePo) {
                items = base.filter(function (r) { return intOf(r.p.poId) === activePo; });
                (rowsByGroup[g.index] || []).forEach(function ($row) {
                    if (intOf($row.attr('data-po-id')) !== activePo) $row.addClass('d-none');
                });
                activeIsEmptyPo = pos.some(function (p) {
                    return intOf(p.poId) === activePo && openItemsOf(p).length === 0;
                });
            }
            shown += items.length;

            // Postęp i liczniki nagłówka liczymy na zawężonym zestawie —
            // tak samo jak dla pozostałych filtrów.
            const headPos = activePo
                ? pos.filter(function (p) { return intOf(p.poId) === activePo; })
                : pos;

            const isOpen = groupOpen(g.key, true);
            $head.replaceWith(vendorHeadHtml(g, items, headPos, isOpen));
            $body.removeClass('d-none').toggleClass('vq-vbody--collapsed', !isOpen);
            $body.find('> .vq-postrip').replaceWith(poStripHtml(g, pos, byPo, activePo));
            $body.find('> .vq-pofilter').replaceWith(poFilterBarHtml(g, pos, activePo, items.length));
            $body.find('> .vq-itbl').toggleClass('d-none', items.length === 0);
            $body.find('> .vq-itempty')
                .toggleClass('d-none', items.length > 0)
                .text(emptyItemsText(g.items.length === 0, activePo !== 0, activeIsEmptyPo));
        });
        syncExpandAllBtn();

        // Pusty stan całej listy TYLKO wtedy, gdy nie została ani jedna
        // karta. Przy zawężeniu do zamówienia bez trafień karta zostaje
        // (z paskiem zamówień i „Pokaż wszystkie"), więc nie podmieniamy
        // listy na komunikat — operator musi mieć jak wrócić.
        if (visibleGroups === 0) {
            showQueueState(emptyStateHtml(
                'bi-funnel', '',
                'Brak pozycji dla wybranych filtrów',
                'Zmień kryteria albo wyczyść filtry, żeby zobaczyć całą kolejkę.',
                '<button type="button" class="btn btn-sm rx-btn-quiet js-clear-queue-filters">'
              + '<i class="bi bi-x-circle"></i> Wyczyść filtry</button>'
            ));
        } else {
            showQueueList();
        }

        updateQueueSummary(shown, queueItems.length, shownEmptyPos);
    }

    function updateQueueSummary(shown, total, emptyPos) {
        const $el = $('#queueFilterSummary');
        if (queueData.length === 0) {
            $el.text('Kolejka jest pusta.');
            return;
        }

        // Same zamówienia bez otwartych pozycji — „0 z 223 pozycji" byłoby
        // mylące, bo na liście coś jednak widać.
        if (shown === 0 && emptyPos > 0) {
            $el.html('<i class="bi bi-funnel-fill"></i> Brak pozycji do przyjęcia · <strong>' + emptyPos
                + '</strong> ' + plural(emptyPos, 'zamówienie', 'zamówienia', 'zamówień')
                + ' bez otwartych pozycji (do zamknięcia)');
            return;
        }

        // anyPoFilter(): przy zawężeniu karty do jednego zamówienia nawet
        // „wszystko widoczne" jest wynikiem filtrowania, więc nie pokazujemy
        // podsumowania pełnej kolejki.
        if (shown === total && !anyPoFilter()) {
            // Ile zamówień ma spóźnione pozycje — liczymy po lateLines,
            // nie po dacie nagłówka.
            let latePos = 0;
            queueData.forEach(function (p) { if (isLatePo(p)) latePos++; });
            const lateNote = latePos > 0
                ? ' · <span class="vq-sum-late"><i class="bi bi-exclamation-triangle-fill"></i> '
                  + latePos + ' ' + plural(latePos, 'zamówienie ma', 'zamówienia mają', 'zamówień ma')
                  + ' pozycje po terminie</span>'
                : '';
            $el.html('<strong>' + total + '</strong> ' + pozWord(total) + ' do przyjęcia w <strong>'
                + queueData.length + '</strong> ' + plural(queueData.length, 'zamówieniu', 'zamówieniach', 'zamówieniach')
                + lateNote + ' · kliknij dostawcę, żeby zobaczyć pozycje');
            return;
        }
        $el.html('<i class="bi bi-funnel-fill"></i> Pokazano <strong>' + shown + '</strong> z <strong>'
            + total + '</strong> ' + pozWord(total));
    }

    function clearQueueFilters() {
        $queueSearch.val('');
        $queueOverdue.prop('checked', false);
        searchOpen = {};
        // „Wyczyść filtry" zdejmuje też zawężenia kart do pojedynczych
        // zamówień — to również filtr, tyle że lokalny.
        Object.keys(vendorPoFilter).forEach(function (k) { delete vendorPoFilter[k]; });
        setPicker($queueVendor, []);
        setPicker($queueStates, []);
        applyQueueFilters();
    }

    // ---- zwijanie kart dostawców ---------------------------------------

    // Etykieta „Rozwiń / Zwiń wszystkie" zależy od tego, czy wszystkie
    // WIDOCZNE (po filtrach) karty są już rozwinięte.
    function syncExpandAllBtn() {
        const $btn = $('#queueExpandAll');
        if (!$btn.length) return;
        const $heads = $queueBody.find('.dv-vhead').not('.d-none');
        const total   = $heads.length;
        const allOpen = total > 0 && $heads.filter('.is-open').length === total;
        $btn.prop('disabled', total === 0)
            .attr('aria-pressed', allOpen ? 'true' : 'false')
            .attr('title', allOpen ? 'Zwiń wszystkich dostawców' : 'Rozwiń wszystkich dostawców');
        $btn.find('.vq-ea-label').text(allOpen ? 'Zwiń wszystkie' : 'Rozwiń wszystkie');
        $btn.find('.vq-ea-icon').attr('class',
            'bi vq-ea-icon ' + (allOpen ? 'bi-chevron-bar-contract' : 'bi-chevron-bar-expand'));
    }

    function toggleGroup($head, open) {
        const vg = $head.attr('data-vg');
        setGroupOpen($head.attr('data-vkey'), open);
        $head.toggleClass('is-open', open)
            .find('.vq-vtoggle').attr('aria-expanded', open ? 'true' : 'false');
        $queueBody.find('.vq-vbody[data-vg="' + vg + '"]').toggleClass('vq-vbody--collapsed', !open);
        // sr-only w przycisku mówi, co zrobi następne kliknięcie.
        const n = $queueBody.find('.vq-vbody[data-vg="' + vg + '"] .vq-itrow').not('.d-none').length;
        $head.find('.vq-vtoggle .sr-only').text((open ? 'Zwiń ' : 'Rozwiń ') + n + ' ' + pozWord(n));
        syncExpandAllBtn();
    }

    // Klik w kartę dostawcy = zwiń/rozwiń. Chevron i nazwa siedzą w
    // <button class="vq-vtoggle"> (semantyka przycisku + Enter/Space z
    // klawiatury), ale klikalny jest cały nagłówek — oprócz chipa
    // „robocze" i przycisku „Przyjmij dostawę", które muszą działać bez
    // zwijania karty.
    $(document).on('click', '.vq-vhead', function (e) {
        const $t = $(e.target);
        if ($t.closest('a, button').length && !$t.closest('.vq-vtoggle').length) return;
        const $head = $(this);
        toggleGroup($head, !$head.hasClass('is-open'));
    });

    // Klik w chip zamówienia = zawęź kartę do tego zamówienia (ponowny
    // klik zdejmuje zawężenie, klik w inny chip przełącza). Pomijamy:
    //   - „⋮" i pozycje menu — mają otwierać menu i swoje akcje
    //     (data-action="close-po" / "cancel-po" czyta close-po.js),
    // a bierzemy wszystko inne: kropkę stanu, numer PO, licznik
    // (prawdziwy <button aria-pressed> — wejście z klawiatury) i wolne
    // miejsce chipa.
    $(document).on('click', '.vq-pochip', function (e) {
        const $t = $(e.target);
        if ($t.closest('.vq-pochip-btn, .dropdown-menu').length) return;
        e.preventDefault();
        e.stopPropagation();

        const $chip = $(this);
        const vkey  = $chip.attr('data-vkey');
        const poId  = intOf($chip.attr('data-po-id'));
        if (!vkey || !poId) return;

        setPoFilter(vkey, poFilterOf(vkey) === poId ? 0 : poId);
        applyQueueFilters();
    });

    // Numer PO to role="button" (nie link) — Enter/Space filtrują tak samo
    // jak klik myszką.
    $(document).on('keydown', '.vq-pochip-no', function (e) {
        if (e.key !== 'Enter' && e.key !== ' ' && e.key !== 'Spacebar') return;
        e.preventDefault();
        $(this).closest('.vq-pochip').trigger('click');
    });

    $(document).on('click', '.js-po-filter-clear', function (e) {
        e.preventDefault();
        e.stopPropagation();
        setPoFilter($(this).attr('data-vkey'), 0);
        applyQueueFilters();
    });

    $(document).on('click', '#queueExpandAll', function () {
        const $heads = $queueBody.find('.dv-vhead').not('.d-none');
        if ($heads.length === 0) return;
        const open = $heads.filter('.is-open').length !== $heads.length;
        $heads.each(function () { setGroupOpen($(this).attr('data-vkey'), open); });
        applyQueueFilters();
    });

    // Zmiana frazy = nowy zestaw trafień, więc ręczne zwinięcia z
    // poprzedniego szukania przestają obowiązywać.
    $queueSearch.on('input', function () {
        searchOpen = {};
        applyQueueFilters();
    });
    // ---- Ustawianie filtra statusów (bez zapętlenia) --------------------
    //
    // setPicker() wywołuje 'changed.bs.select', a handler potrafi znowu
    // ustawiać picker (karta „Po terminie" czyści statusy, a ręczna
    // zmiana w pickerze ma je przywrócić). Bez strażnika wywołania
    // zapętlają się — klik w „Po terminie" generował 449 zdarzeń
    // i ~3 s zawieszenia. `statesLock` przerywa rekurencję.
    let statesLock = false;

    // Czyści wybór statusów (karty statusu są wyłączone, gdy włączone
    // jest „Po terminie"). Nic nie robi, gdy picker jest już pusty.
    function clearStates() {
        const cur = $queueStates.val() || [];
        if (!cur.length) return;
        statesLock = true;
        setPicker($queueStates, []);
        statesLock = false;
    }

    // Chip „Tylko po terminie" — to samo wejście co karta „Po terminie":
    // włączenie czyści wybór statusów (karty statusu są wtedy wyłączone).
    $queueOverdue.on('change', function () {
        if ($queueOverdue.is(':checked')) clearStates();
        applyQueueFilters();
    });
    $queueVendor.on('changed.bs.select change', applyQueueFilters);
    $queueStates.on('changed.bs.select change', function () {
        if (statesLock) return;              // wywołanie z clearStates()
        // Karty statusu są wyłączone, gdy włączone jest „Po terminie" —
        // ręczna zmiana w pickerze nie może tego obejść.
        if ($queueOverdue.is(':checked')) clearStates();
        applyQueueFilters();
    });
    $refreshBtn.on('click', loadQueue);
    $(document).on('click', '.js-clear-queue-filters', clearQueueFilters);
    $(document).on('click', '.js-queue-retry', loadQueue);

    // Karty filtrujące: klik włącza albo wyłącza własne wiadro filtra
    // (status albo „po terminie"), a wybór pozostałych kart zostaje.
    // Kilka kart może być włączonych naraz — filtr jest sumą wiader.
    $('.rx-stats--filter').on('click', '.rx-stat', function () {
        toggleQueueStat($(this).attr('data-stat'));
    });

    // Wiersz pozycji NIE jest klikalny jako całość — klikalne są numer
    // zamówienia (link do ekranu PO) i menu „⋮" w pasku zamówień.
    // followRowLink() zostaje, bo nadal zasila klik w wierszach HISTORII
    // przyjęć, gdzie klikalność całego wiersza ma sens (podgląd dostawy).

    // =====================================================================
    // ZAKŁADKA 2 — historia przyjęć
    //
    // Wiersze dostaw lecą z delivery-list.php (AJAX POST, stan „posted"),
    // a filtr (szukajka + zakres dat) działa lokalnie na pobranej
    // liście — stąd deliveriesAll / deliveriesFiltered.
    // =====================================================================

    const $historySearch = $('#historySearch');
    const $dateFrom      = $('#historyDateFrom');
    const $dateTo        = $('#historyDateTo');

    function historyFiltersActive() {
        return String($historySearch.val() || '').trim() !== ''
            || String($dateFrom.val() || '') !== ''
            || String($dateTo.val() || '') !== '';
    }

    // Serwerowe mirroring helperów rx_fmt_date / rx_fmt_time /
    // rx_relative_day / rx_initials (PHP → JS). Formatowanie
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

    // Licznik zakładki i linijka podsumowania — źródłem jest jedna
    // tabela: dostawy wg faktur / WZ.
    function updateHistoryBadge() {
        const nd = deliveriesFiltered.length;
        $('#historyCountBadge').text(nd);

        const $el = $('#historyFilterSummary');
        if (nd === 0) { $el.text(''); return; }
        const parts = [];
        parts.push('<strong>' + nd + '</strong> ' + plural(nd, 'dostawa', 'dostawy', 'dostaw'));
        $el.html((historyFiltersActive() ? '<i class="bi bi-funnel-fill"></i> Znaleziono ' : '') + parts.join(' · ') + ' · od najnowszych');
    }

    // ---- dostawy zatwierdzone (delivery-list.php?state=posted) ----------

    let deliveriesAll       = [];
    let deliveriesFiltered  = [];
    let deliveriesShown     = DELIVERIES_PAGE;
    let deliveriesLoaded    = false;
    let deliveriesLoading   = false;
    let deliveriesTotal     = 0;   // liczba wszystkich dostaw (z endpointu)

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

        // Linijka przy nagłówku: liczba dostaw po filtrze + liczba
        // wszystkich (z endpointu), np. „· 25 z 3800".
        const nd = deliveriesFiltered.length;
        const total = deliveriesTotal;
        let secInfo = '';
        if (nd > 0 || total > 0) {
            secInfo = '· ' + nd;
            if (total > nd) secInfo += ' z ' + total;
        }
        $('#deliveriesSecInfo').text(secInfo);

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
            deliveriesTotal = r.total || deliveriesAll.length;
            deliveriesLoaded = true;
            deliveriesShown = DELIVERIES_PAGE;
            renderDeliveries();
        }).catch(function (err) {
            deliveriesAll = [];
            deliveriesTotal = 0;
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

    // Filtry historii działają na tabeli dostaw.
    function reloadHistory() {
        deliveriesShown = DELIVERIES_PAGE;
        if (deliveriesLoaded) renderDeliveries();
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

    function clearHistoryFilters() {
        $historySearch.val('');
        $dateFrom.val('');
        $dateTo.val('');
        reloadHistory();
    }

    $historySearch.on('input', reloadHistory);
    $dateFrom.add($dateTo).on('change', reloadHistory);
    $(document).on('click', '.js-clear-history-filters', clearHistoryFilters);

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
        }
    });

    // =====================================================================
    // Start
    // =====================================================================

    const successParams = new URLSearchParams(window.location.search);

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

    // ---- powrót po zamknięciu PO z formularza przyjęcia (?closed=…) -----
    //
    // Tu NIE było zapisu przyjęcia, więc nie ma wiersza do
    // podświetlenia ani linku „Pokaż w historii". Operator wybrał stan
    // wynikowy (received/cancelled) w oknie „Zamknij zamówienie" na
    // formularzu. Komunikat krótszy, bez akcji, ale ten sam schemat
    // strip-query po wyrenderowaniu.

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
});
