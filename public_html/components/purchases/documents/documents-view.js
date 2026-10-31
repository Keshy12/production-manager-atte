// Zapytania i zamówienia — /purchase/documents.
//
// Dane: documents-table.php (POST, kontrakt zamrożony):
//   type           'both' | 'rfq' | 'po'
//   vendor_ids[]   int[]
//   states[]       string[]
//   number         tekst (LIKE na rfq_number / po_number / vendor_po_number)
//   date_from      'YYYY-MM-DD' (domyślnie dziś − 90 dni)
//   date_to        'YYYY-MM-DD'
//   hide_finished  0 | 1 (ukrywa RFQ `converted`, PO `received`
//                 oraz `cancelled` w obu typach)
//   page, items_per_page, snapshot_ts
//   mode           'data' → {docs[], currentPage, snapshot_ts}
//                  'count' → {totalCount}
//
// Liczniki statusów (kafelki) to te same zapytania mode=count — po jednym
// na kafelek, z bieżącymi filtrami, ale z własnym zestawem statusów.
//
// Kafelki to niezależne przełączniki filtra statusu (ten sam wzorzec co
// /admin/purchase/vendor-parts): klik włącza albo wyłącza statusy tego
// kafelka, a wybór pozostałych kafelków zostaje. Kilka kafelków może być
// włączonych naraz — filtr statusu to wtedy suma ich statusów.
//
// Filtry są zapamiętywane w history.state: strona edycji ma przycisk
// „Wróć" oparty na history.back(), więc po powrocie lista wraca do tych
// samych filtrów i tej samej strony zamiast do ustawień domyślnych.
//
// Akcja w wierszu: Edytuj (editable) albo Podgląd — oba do edit_url
// z serwera. Obok przycisk następnego kroku (jak w nagłówku strony edycji).

$(document).ready(function () {
    const $view = $('#documentsView');
    if ($view.length === 0) return;

    const ENDPOINT = COMPONENTS_PATH + '/purchases/documents/documents-table.php';
    // TYMCZASOWO: 10 wierszy na stronę. Wróć do 20, gdy wyjaśni się,
    // po co mniejszy limit (albo dodaj tu sterownik wyboru 10/20/50/100).
    const ITEMS_PER_PAGE = 10;
    const DEFAULT_RANGE = 90;
    const STATE_KEY = 'docsFilters';

    // Etykiety i odcienie jak na /purchase/receipts (rx_state_meta /
    // STATE_META) — ten sam stan wygląda tak samo na obu stronach.
    const STATE_META = {
        draft:              { label: 'Szkic',               tone: 'neutral' },
        sent:               { label: 'Wysłane',             tone: 'violet' },
        responded:          { label: 'Odpowiedź',           tone: 'cyan' },
        converted:          { label: 'Skonwertowane na PO', tone: 'green' },
        confirmed:          { label: 'Potwierdzone',        tone: 'blue' },
        in_progress:        { label: 'Częściowo przyjęte',  tone: 'cyan' },
        partially_received: { label: 'Częściowo odebrane',  tone: 'amber' },
        received:           { label: 'Odebrane',            tone: 'green' },
        cancelled:          { label: 'Anulowane',           tone: 'red' }
    };

    // Krótka podpowiedź pod plakietką — co dalej z dokumentem.
    const STATE_HINT = {
        rfq: {
            draft:     'do wysłania',
            sent:      'czeka na odpowiedź',
            responded: 'można utworzyć zamówienie'
        },
        po: {
            draft:              'do wysłania',
            sent:               'czeka na odpowiedź',
            confirmed:          'czeka na odbiór',
            in_progress:        'przyjęcie w toku',
            partially_received: 'czeka na resztę towaru'
        }
    };

    // Statusy „zakończone" (zielone) — znikają przy „Ukryj zakończone".
    const FINISHED_STATES = ['converted', 'received', 'cancelled'];

    // Kafelki → zestawy statusów (wartości opcji #states).
    // `in_progress` obejmuje też `partially_received` — oba stany to
    // trwające przyjęcie towaru. `confirmed` jest osobno: to zamówienie
    // potwierdzone przez dostawcę, przed startem odbioru.
    const STAT_STATES = {
        draft:       ['draft'],
        sent:        ['sent'],
        responded:   ['responded'],
        converted:   ['converted'],
        confirmed:   ['confirmed'],
        in_progress: ['in_progress', 'partially_received'],
        received:    ['received'],
        cancelled:   ['cancelled']
    };

    // Statusy należące do każdego z typów dokumentu. Statusy wspólne
    // (draft, sent, cancelled) pasują do obu typów — dlatego typ statusu
    // nie jest pojedynczą flagą, tylko parą obecności.
    const RFQ_STATES = ['draft', 'sent', 'responded', 'converted', 'cancelled'];
    const PO_STATES  = ['draft', 'sent', 'confirmed', 'in_progress', 'partially_received', 'received', 'cancelled'];

    // Typ dokumentu dla pojedynczego statusu: 'both' | 'rfq' | 'po'.
    function stateType(s) {
        const inRfq = RFQ_STATES.indexOf(s) !== -1;
        const inPo  = PO_STATES.indexOf(s) !== -1;
        if (inRfq && inPo) return 'both';
        return inRfq ? 'rfq' : 'po';
    }

    // Typ dokumentu, którego dotyczy kafelek. Wyliczany z listy statusów
    // kafelka, żeby nie rozjechać się z nią przy zmianie filtrów.
    function tileType(key) {
        let rfq = false, po = false;
        (STAT_STATES[key] || []).forEach(function (s) {
            const t = stateType(s);
            if (t === 'rfq' || t === 'both') rfq = true;
            if (t === 'po'  || t === 'both') po  = true;
        });
        if (rfq && po) return 'both';
        return rfq ? 'rfq' : 'po';
    }

    const $vendor    = $('#vendor');
    const $states    = $('#states');
    const $number    = $('#number');
    const $dateFrom  = $('#dateFrom');
    const $dateTo    = $('#dateTo');
    const $status    = $('#docsStatus');
    const $listWrap  = $('#docsListWrap');
    const $list      = $('#docsTableBody');
    const $panel     = $('#docsPanel');
    const $refresh   = $('#refreshDocs');
    const $hideDone  = $('#hideFinished');

    // „Ukryj zakończone" to preferencja użytkownika (nie część filtrów
    // czyszczonych przyciskiem) — trzymamy ją w localStorage.
    const HIDE_DONE_KEY = 'docsHideFinished';
    function loadHideDone() {
        // Domyślnie WŁĄCZONE: brak zapisu = zaznaczone; tylko jawne '0' wyłącza.
        try { return window.localStorage.getItem(HIDE_DONE_KEY) !== '0'; } catch (e) { return true; }
    }
    function saveHideDone(on) {
        try { window.localStorage.setItem(HIDE_DONE_KEY, on ? '1' : '0'); } catch (e) { /* bez pamięci */ }
    }

    let stateOptionsUiKey = null;
    // Liczniki ukrytych kafelków (pobierane na żądanie, przy otwarciu
    // modalu „Ukryte karty").
    const hiddenStatCounts = {};
    let docType = 'both';
    let currentPage = 1;
    let totalCount = null;
    let currentSnapshotTs = null;
    let hasLoadedOnce = false;
    let lastDocsCount = 0;
    let listFailed = false;

    let docsXhr = null;
    let countXhr = null;
    let statXhrs = [];
    let docsSeq = 0;
    let countSeq = 0;
    let statsSeq = 0;
    let numberTimer = null;

    // =====================================================================
    // Drobiazgi
    // =====================================================================

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function pad2(n) { return n < 10 ? '0' + n : String(n); }

    // Data lokalna (nie UTC — toISOString() przesuwa dzień po północy).
    function isoDay(d) {
        return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate());
    }

    function daysAgo(n) {
        const d = new Date();
        d.setDate(d.getDate() - n);
        return isoDay(d);
    }

    function fmtDate(iso) {
        const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(iso || ''));
        return m ? (m[3] + '.' + m[2] + '.' + m[1]) : String(iso || '');
    }

    function plural(n, one, few, many) {
        const a = Math.abs(n);
        if (a === 1) return one;
        const l = a % 10;
        const l2 = a % 100;
        if (l >= 2 && l <= 4 && (l2 < 12 || l2 > 14)) return few;
        return many;
    }

    // Kwoty: polski zapis (przecinek, spacja tysięcy), 2–4 miejsca.
    function fmtMoney(n) {
        const v = parseFloat(n);
        if (!isFinite(v)) return '0,00';
        return v.toLocaleString('pl-PL', { minimumFractionDigits: 2, maximumFractionDigits: 4 });
    }

    function appUrl(path) {
        return window.location.origin + ROOT_DIR + path;
    }

    // Programowa zmiana pickera bez zdarzenia `change` (nie przeładowuje
    // listy — wywołujący sam decyduje, kiedy wczytać).
    function setPicker($el, values) {
        $el.val(values);
        if (typeof $.fn.selectpicker === 'function') $el.selectpicker('refresh');
    }

    function stateMeta(state) {
        if (!state) return { label: 'Brak statusu', tone: 'neutral' };
        return STATE_META[state] || { label: String(state), tone: 'neutral' };
    }

    // =====================================================================
    // Filtry
    // =====================================================================

    function readFilters() {
        return {
            type:    docType,
            vendors: ($vendor.val() || []).map(String),
            states:  ($states.val() || []).map(String),
            number:  String($number.val() || '').trim(),
            from:    String($dateFrom.val() || ''),
            to:      String($dateTo.val() || ''),
            hideDone: $hideDone.is(':checked')
        };
    }

    function defaultFilters() {
        return { type: 'both', vendors: [], states: [], number: '', from: daysAgo(DEFAULT_RANGE), to: '' };
    }

    function isDefault(f) {
        const d = defaultFilters();
        return f.type === d.type && f.vendors.length === 0 && f.states.length === 0
            && f.number === '' && f.from === d.from && f.to === '';
    }

    function applyFiltersToUi(f) {
        setType(f.type);
        setPicker($vendor, f.vendors || []);
        setPicker($states, f.states || []);
        $number.val(f.number || '');
        $dateFrom.val(f.from || '');
        $dateTo.val(f.to || '');
    }

    function setType(type) {
        docType = (type === 'rfq' || type === 'po') ? type : 'both';
        $('#typeSeg [data-type]').each(function () {
            const on = $(this).attr('data-type') === docType;
            $(this).toggleClass('is-active', on).attr('aria-pressed', on ? 'true' : 'false');
        });
    }

    // Payload dla endpointu (bez page/mode — dokładają je wołający).
    function payload(f, states) {
        return {
            type:        f.type,
            vendor_ids:  f.vendors,
            states:      states,
            number:      f.number,
            date_from:   f.from,
            date_to:     f.to,
            hide_finished: f.hideDone ? 1 : 0
        };
    }

    // Który preset zakresu dat jest teraz ustawiony (albo null).
    function activeRange(f) {
        if (f.from === '' && f.to === '') return 'all';
        if (f.to !== '') return null;
        const presets = [30, 90, 365];
        for (let i = 0; i < presets.length; i++) {
            if (f.from === daysAgo(presets[i])) return String(presets[i]);
        }
        return null;
    }

    function rangeText(f) {
        const r = activeRange(f);
        if (r === 'all') return 'ze wszystkich dat';
        if (r === '365') return 'z ostatniego roku';
        if (r !== null) {
            return 'z ostatnich ' + r + ' dni' + (r === String(DEFAULT_RANGE) ? ' (zakres domyślny)' : '');
        }
        if (f.from && f.to) return 'utworzone ' + fmtDate(f.from) + ' – ' + fmtDate(f.to);
        if (f.from) return 'utworzone od ' + fmtDate(f.from);
        return 'utworzone do ' + fmtDate(f.to);
    }

    function isFinishedTile(key) {
        return (STAT_STATES[key] || []).some(function (s) { return FINISHED_STATES.indexOf(s) !== -1; });
    }

    // Kafelek jest widoczny, gdy pasuje do wybranego typu dokumentu
    // i nie jest statusem zakończonym przy włączonym „Ukryj zakończone".
    // Obie reguły sterują tą samą klasą d-none, więc decyzja o widoczności
    // musi być w jednym miejscu.
    function tileVisible(key, f) {
        const t = tileType(key);
        // Kafelek wspólny dla obu typów (Szkice, Wysłane, Anulowane)
        // pasuje do każdego filtra typu — chowamy tylko kafelki
        // przypisane do KONKRETNEGO, innego typu.
        if (f.type !== 'both' && t !== 'both' && t !== f.type) return false;
        if (f.hideDone && isFinishedTile(key)) return false;
        return true;
    }

    // ---- Kafelki jako niezależne przełączniki -------------------------
    // Ten sam wzorzec co /admin/purchase/vendor-parts: kafelek jest
    // przełącznikiem wł./wył., a kilka kafelków może być włączonych
    // naraz. Kafelek steruje filtrem statusu (#states) — włączenie
    // kafelka dodaje jego statusy do filtra, wyłączenie je zdejmuje.
    // Wybór z pozostałych kafelków nietknięty.

    // Statusy kafelka (kafelek „Częściowo przyjęte" obejmuje dwa:
    // in_progress + partially_received).
    function tileStates(key) {
        return STAT_STATES[key] || [];
    }

    // Kafelek jest włączony, gdy wybrany jest którykolwiek z jego statusów.
    function tileActive(key, states) {
        const set = tileStates(key);
        for (let i = 0; i < set.length; i++) {
            if (states.indexOf(set[i]) !== -1) return true;
        }
        return false;
    }

    // Przełączenie kafelka: włączony → zdejmuje swoje statusy z filtra,
    // wyłączony → dodaje je. Gdy wszystkie statusy kafelka są już
    // wybrane, klik wyłącza kafelek; w przeciwnym razie włącza go
    // (dodając brakujące statusy).
    function toggleTile(key) {
        const set = tileStates(key);
        if (!set.length) return;
        const current = ($states.val() || []).map(String);
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
        setPicker($states, next);
        filtersChanged(false);
    }

    // Kafelki są usuwane z paska w całości (d-none), a nie tylko
    // wyszarzane: kafelek innego typu dokumentu albo zakończonego
    // statusu nie prowadzi do żadnego wyniku. Ukryty kafelek znika też
    // z tabulatora (display:none) i z układu flex — pozostałe kafelki
    // rozciągają się na całą szerokość.
    //
    // Liczba ukrytych kafelków idzie do wskazówki „+N" na końcu paska,
    // żeby operator widział, że na pasku jest więcej pozycji niż widać.
    function syncTilesUi(f) {
        let hiddenCount = 0;
        $('.dx-stats .rx-stat[data-stat]').each(function () {
            const $t = $(this);
            const visible = tileVisible($t.attr('data-stat'), f);
            if (!visible) hiddenCount++;
            $t.toggleClass('d-none', !visible);
        });

        const $more = $('.dx-stats-more');
        $more.toggleClass('d-none', hiddenCount === 0);
        $more.prop('hidden', hiddenCount === 0);
        $more.find('[data-stat-more-count]').text(hiddenCount);
        $more.attr('title', 'Ukryte karty (' + hiddenCount + ') — kliknij, aby dowiedzieć się, co zmienić')
            .attr('aria-label', 'Ukryte karty: ' + hiddenCount + '. Kliknij, aby dowiedzieć się, co zmienić');
    }

    // Modal „Ukryte karty" ma dwa stany:
    //   info   — pokazuje ukryte karty i mówi, co zmienić (nic nie zmienia)
    //   confirm — pokazuje dokładnie, jakie filtry zostaną zmienione,
    //             i dopiero wtedy operator może je zastosować
    let statsMoreState = 'info';

    function showStatsMoreInfo() {
        statsMoreState = 'info';
        $('#docsStatsMoreModalTitle').text('Ukryte karty');
        $('#docsStatsMoreInfo').removeClass('d-none');
        $('#docsStatsMoreConfirm').addClass('d-none');
        $('#docsStatsMoreApply').removeClass('d-none');
        $('#docsStatsMoreCancel').addClass('d-none');
        $('#docsStatsMoreOk').text('Rozumiem');
    }

    function showStatsMoreConfirm() {
        const f = readFilters();
        const changes = [];
        if (f.type !== 'both') {
            changes.push('Typ dokumentu: ' + typeLabel(f.type) + ' → Wszystkie');
        }
        if (f.hideDone) {
            changes.push('Ukryj zakończone: włączone → wyłączone');
        }

        statsMoreState = 'confirm';
        $('#docsStatsMoreModalTitle').text('Zmiana filtrów');
        $('#docsStatsMoreInfo').addClass('d-none');
        $('#docsStatsMoreConfirm').removeClass('d-none');
        $('#docsStatsMoreApply').addClass('d-none');
        $('#docsStatsMoreCancel').removeClass('d-none');
        $('#docsStatsMoreOk').text('Zmień filtry');
        $('#docsStatsMoreChange').html(changes.map(function (c) {
            return '<li>' + esc(c) + '</li>';
        }).join(''));
    }

    // Etykieta segmentu typu dokumentu — tak, jak nazywa się przycisk.
    function typeLabel(t) {
        if (t === 'rfq') return 'Zapytania (RFQ)';
        if (t === 'po') return 'Zamówienia (PO)';
        return 'Wszystkie';
    }

    // Wskazówka „+N" NIE zmienia filtrów — tylko otwiera modal, który
    // pokazuje ukryte karty i mówi, co operator ma zmienić, żeby je
    // zobaczyć. Sama zmiana filtrów byłaby niespodzianką: kafelek
    // sugeruje, że ktoś może chcieć filtrować właśnie tym statusem.
    function explainHiddenTiles() {
        const f = readFilters();

        // Klucze ukrytych kafelków bierzemy z paska — dzięki temu
        // mini-karty w modalu pokazują dokładnie te nazwy, które
        // operator widzi na stronie.
        const hidden = [];
        $('.dx-stats .rx-stat[data-stat]').each(function () {
            if (tileVisible($(this).attr('data-stat'), f)) return;
            hidden.push($(this).attr('data-stat'));
        });
        if (!hidden.length) return;

        const cards = hidden.map(function (key) {
            const $tile = $('.dx-stats .rx-stat[data-stat="' + key + '"]');
            const label = $tile.find('.rx-stat-label').text().trim();
            // Znacznik typu (RFQ/PO) kopiujemy z kafelka — mini-karta ma
            // wyglądać jak pełna, tylko mniejsza.
            const $tag = $tile.find('.dx-stat-tag').first();
            const tagHtml = $tag.length ? $tag.outerHTML() : '';
            const value = hiddenStatCounts[key];
            return '<div class="rx-stat dx-stat--' + key + '" data-stat="' + key + '">'
                 + '<span class="rx-stat-value" data-stat-value>'
                 + (value === undefined ? '–' : value) + '</span>'
                 + '<span class="dx-stat-foot">'
                 +   '<span class="rx-stat-label">' + esc(label) + '</span>'
                 +   tagHtml
                 + '</span>'
                 + '</div>';
        }).join('');

        const steps = [];
        if (f.type !== 'both') steps.push('wybierz „Wszystkie" w filtrze typu dokumentu');
        if (f.hideDone) steps.push('wyłącz „Ukryj zakończone" w pasku filtrów');

        let hint;
        if (steps.length === 1) {
            hint = 'Aby zobaczyć wszystkie karty, ' + steps[0] + '.';
        } else if (steps.length > 1) {
            hint = 'Aby zobaczyć wszystkie karty, ' + steps[0] + ' oraz ' + steps[1] + '.';
        } else {
            hint = 'Wszystkie karty są widoczne.';
        }

        $('#docsStatsMoreCards').html(cards);
        $('#docsStatsMoreHint').text(hint);
        showStatsMoreInfo();
        $('#docsStatsMoreModal').modal('show');

        // Liczniki ukrytych kart dociągamy w tle — modal jest już
        // otwarty, więc liczby pojawiają się bez czekania.
        loadHiddenStatCounts(f);
    }

    // Liczniki dla UKRYTYCH kafelków. Liczymy je tak, jakby kafelek był
    // widoczny: własny typ dokumentu kafelka (nie bieżący filtr typu)
    // i bez „Ukryj zakończone". Dzięki temu mini-karta w modalu ma
    // dokładnie tę samą liczbę co pełna karta na pasku.
    function loadHiddenStatCounts(f) {
        Object.keys(STAT_STATES).forEach(function (key) {
            if (tileVisible(key, f)) { delete hiddenStatCounts[key]; return; }
            $.ajax({
                type: 'POST',
                url: ENDPOINT,
                data: $.extend({}, payload(f, STAT_STATES[key]), {
                    mode: 'count',
                    type: tileType(key),
                    hide_finished: 0
                }),
                dataType: 'json'
            })
            .done(function (res) {
                if (!res || typeof res.totalCount !== 'number') return;
                hiddenStatCounts[key] = res.totalCount;
                $('#docsStatsMoreCards .rx-stat[data-stat="' + key + '"] [data-stat-value]')
                    .text(res.totalCount);
            });
        });
    }

    // Przycisk „Pokaż wszystkie karty" w modalu: przywraca pełny zestaw
    // kafelków — typ dokumentu wraca do „Wszystkie", a „Ukryj
    // zakończone" jest wyłączane. Jedno przeładowanie listy, nie dwa.
    function showAllTiles() {
        const typeChanged = docType !== 'both';
        const hideChanged = $hideDone.is(':checked');

        if (typeChanged) setType('both');
        if (hideChanged) {
            $hideDone.prop('checked', false);
            saveHideDone(false);
        }
        $('#docsStatsMoreModal').modal('hide');

        if (!typeChanged && !hideChanged) return;
        // Przy powrocie do „Wszystkie" żaden status nie wypada.
        dropTypeMismatchStates('both');
        filtersChanged(true);
    }

    // Opcje w filtrze statusów muszą pasować do wybranego typu dokumentu
    // i nie mogą być statusem zakończonym przy włączonym „Ukryj
    // zakończone" — inaczej operator wybrałby filtr, który nie ma
    // odpowiadającego mu kafelka i nie zwróciłby żadnych wyników.
    function syncStateOptionsUi(f) {
        $states.find('option').each(function () {
            const v = $(this).val();
            const t = stateType(v);
            const typeOk = f.type === 'both' || t === 'both' || t === f.type;
            const finished = f.hideDone && FINISHED_STATES.indexOf(v) !== -1;
            $(this).prop('disabled', !typeOk || finished);
        });
        if (typeof $.fn.selectpicker === 'function') $states.selectpicker('refresh');
    }

    // Zdejmuje zakończone statusy z aktywnego filtra statusu.
    function dropFinishedStates() {
        const current = ($states.val() || []).map(String);
        const kept = current.filter(function (s) { return FINISHED_STATES.indexOf(s) === -1; });
        if (kept.length !== current.length) setPicker($states, kept);
    }

    // Przy zmianie typu dokumentu zdejmuje statusy, które do tego typu
    // nie pasują. Bez tego filtr wskazywałby na kafelek, którego na
    // pasku już nie ma (jest ukryty), a lista i tak byłaby pusta.
    function dropTypeMismatchStates(type) {
        const current = ($states.val() || []).map(String);
        if (!current.length) return;
        const kept = current.filter(function (s) {
            const t = stateType(s);
            // Status wspólny dla obu typów (draft, sent, cancelled)
            // zostaje — pasuje do każdego filtra typu.
            return type === 'both' || t === 'both' || type === t;
        });
        if (kept.length !== current.length) setPicker($states, kept);
    }

    function syncFilterUi(f) {
        $('#vendorCell').toggleClass('is-active', f.vendors.length > 0);
        $('#statesCell').toggleClass('is-active', f.states.length > 0);
        $('#dateRange').toggleClass('is-active', f.from !== '' || f.to !== '');
        $('#clearFilters').toggleClass('d-none', isDefault(f));

        // Widoczność kafelków zależy od dwóch rzeczy: wybranego typu
        // dokumentu i przełącznika „Ukryj zakończone".
        syncTilesUi(f);

        // Opcje w filtrze statusów aktualizujemy tylko przy zmianie
        // klucza — selectpicker('refresh') nie jest darmowy.
        const optsKey = f.type + '|' + (f.hideDone ? 1 : 0);
        if (stateOptionsUiKey !== optsKey) {
            stateOptionsUiKey = optsKey;
            syncStateOptionsUi(f);
        }

        const r = activeRange(f);
        $('#rangeSeg [data-range]').each(function () {
            const on = $(this).attr('data-range') === r;
            $(this).toggleClass('is-active', on).attr('aria-pressed', on ? 'true' : 'false');
        });

        // Kafelek jest włączony, gdy wybrany jest którykolwiek z jego
        // statusów — kilka kafelków może być włączonych naraz.
        $('.dx-stats .rx-stat').each(function () {
            const on = tileActive($(this).attr('data-stat'), f.states);
            $(this).toggleClass('is-active', on).attr('aria-pressed', on ? 'true' : 'false');
        });
    }

    // Zapamiętanie filtrów w bieżącym wpisie historii przeglądarki.
    function rememberState(f) {
        if (!window.history || !window.history.replaceState) return;
        const st = $.extend({}, window.history.state || {});
        st[STATE_KEY] = { filters: f, page: currentPage };
        try { window.history.replaceState(st, '', window.location.href); } catch (e) { /* bez pamięci */ }
    }

    function restoredState() {
        const st = window.history && window.history.state;
        return (st && st[STATE_KEY] && st[STATE_KEY].filters) ? st[STATE_KEY] : null;
    }

    // =====================================================================
    // Wczytywanie listy
    // =====================================================================

    // Zmiana filtra (nie strony): od strony 1, nowa liczba wyników,
    // nowe liczniki (o ile zmieniło się coś poza statusem).
    function filtersChanged(reloadStats) {
        currentPage = 1;
        totalCount = null;
        currentSnapshotTs = null;
        loadDocuments();
        if (reloadStats) loadStats();
    }

    function setBusy(on) {
        $refresh.prop('disabled', on).toggleClass('is-spinning', on);
        $panel.toggleClass('is-loading', on && hasLoadedOnce);
    }

    function loadDocuments() {
        const f = readFilters();
        syncFilterUi(f);
        rememberState(f);

        const seq = ++docsSeq;
        if (docsXhr) docsXhr.abort();

        setBusy(true);
        if (!hasLoadedOnce) showStatus(skeletonHtml());
        if (totalCount === null) $('#docsSummary').text('Wczytywanie dokumentów…');

        const base = payload(f, f.states);

        docsXhr = $.ajax({
            type: 'POST',
            url: ENDPOINT,
            data: $.extend({}, base, {
                page: currentPage,
                items_per_page: ITEMS_PER_PAGE,
                mode: 'data',
                snapshot_ts: currentSnapshotTs
            }),
            dataType: 'json'
        })
        .done(function (res) {
            if (seq !== docsSeq) return;
            const docs = (res && res.docs) || [];
            // Strona poza zakresem (np. po odświeżeniu ubyło dokumentów).
            if (docs.length === 0 && currentPage > 1) {
                currentPage = 1;
                loadDocuments();
                return;
            }
            currentSnapshotTs = (res && res.snapshot_ts) || currentSnapshotTs;
            hasLoadedOnce = true;
            listFailed = false;
            renderList(docs, f);
            renderPagination();
            updateSummary(f);
        })
        .fail(function (xhr, status) {
            if (status === 'abort' || seq !== docsSeq) return;
            listFailed = true;
            const msg = (xhr && xhr.responseJSON && xhr.responseJSON.error)
                || 'Błąd ładowania dokumentów.';
            setAlert(msg, 'danger');
            showStatus(emptyHtml('bi-exclamation-octagon', 'danger',
                'Nie udało się wczytać dokumentów', msg,
                '<button type="button" class="btn btn-sm btn-primary js-docs-retry">'
              + '<i class="bi bi-arrow-clockwise"></i> Spróbuj ponownie</button>'));
            $('#docsSummary').text('Lista niedostępna.');
            $('#paginationTop, #paginationBottom').empty();
        })
        .always(function () {
            if (seq === docsSeq) setBusy(false);
        });

        // Liczba wszystkich wyników — tylko gdy zmieniły się filtry
        // (przy zmianie strony zostaje poprzednia). Własny licznik
        // sekwencji: zmiana strony nie może unieważnić trwającego liczenia.
        if (totalCount === null) {
            const cseq = ++countSeq;
            if (countXhr) countXhr.abort();
            countXhr = $.ajax({
                type: 'POST',
                url: ENDPOINT,
                data: $.extend({}, base, { mode: 'count' }),
                dataType: 'json'
            })
            .done(function (res) {
                if (cseq !== countSeq) return;
                totalCount = (res && typeof res.totalCount === 'number') ? res.totalCount : 0;
                if (listFailed) return; // lista w stanie błędu — bez paginacji
                renderPagination();
                updateSummary(f);
            })
            .fail(function (xhr, status) {
                if (status === 'abort' || cseq !== countSeq || listFailed) return;
                $('#docsSummary').text('Nie udało się policzyć dokumentów.');
            });
        }
    }

    // Liczniki: jedno zapytanie mode=count na kafelek. Filtr statusu
    // pomijamy — kafelek pokazuje, ile jest dokumentów w danym statusie
    // przy pozostałych filtrach.
    function loadStats() {
        const f = readFilters();
        const seq = ++statsSeq;
        statXhrs.forEach(function (x) { x.abort(); });
        statXhrs = [];

        $('.dx-stats').addClass('is-loading');
        let pending = 0;
        Object.keys(STAT_STATES).forEach(function (key) {
            // Kafelki ukryte (inny typ dokumentu albo zakończony status)
            // nie mają licznika — nie pytamy o nie.
            if (!tileVisible(key, f)) return;
            pending++;
            const $tile = $('.dx-stats .rx-stat[data-stat="' + key + '"]');
            const xhr = $.ajax({
                type: 'POST',
                url: ENDPOINT,
                data: $.extend({}, payload(f, STAT_STATES[key]), { mode: 'count' }),
                dataType: 'json'
            })
            .done(function (res) {
                if (seq !== statsSeq) return;
                const n = (res && typeof res.totalCount === 'number') ? res.totalCount : null;
                $tile.find('[data-stat-value]').text(n === null ? '–' : n);
                $tile.toggleClass('is-zero', n === 0);
            })
            .fail(function (xhr2, status) {
                if (status === 'abort' || seq !== statsSeq) return;
                $tile.find('[data-stat-value]').text('–');
            })
            .always(function () {
                pending--;
                if (pending === 0 && seq === statsSeq) $('.dx-stats').removeClass('is-loading');
            });
            statXhrs.push(xhr);
        });
        // Gdyby wszystkie kafelki były ukryte, żaden request nie wystartował
        // by — trzeba zdjąć stan ładowania ręcznie, bo `.always` się nie odpali.
        if (pending === 0) $('.dx-stats').removeClass('is-loading');
    }

    // =====================================================================
    // Render
    // =====================================================================

    function showStatus(html) {
        $listWrap.addClass('d-none');
        $status.removeClass('d-none').html(html);
    }

    function showList() {
        $status.addClass('d-none').empty();
        $listWrap.removeClass('d-none');
    }

    function emptyHtml(icon, tone, title, text, actions) {
        return '<div class="rx-empty">'
             + '<div class="rx-empty-icon' + (tone ? ' rx-empty-icon--' + tone : '') + '"><i class="bi ' + icon + '"></i></div>'
             + '<div class="rx-empty-title">' + esc(title) + '</div>'
             + (text ? '<div class="rx-empty-text">' + esc(text) + '</div>' : '')
             + (actions ? '<div class="dx-empty-actions">' + actions + '</div>' : '')
             + '</div>';
    }

    function skeletonHtml() {
        let html = '<span class="sr-only">Ładowanie…</span>';
        for (let i = 0; i < 6; i++) {
            html += '<div class="dx-row dx-skel-row" aria-hidden="true">'
                  + '<div><span class="rx-skel" style="width:75%"></span><span class="rx-skel rx-skel--sm" style="width:45%"></span></div>'
                  + '<div><span class="rx-skel" style="width:70%"></span></div>'
                  + '<div><span class="rx-skel rx-skel--pill"></span></div>'
                  + '<div><span class="rx-skel" style="width:40%;margin-left:auto"></span></div>'
                  + '<div><span class="rx-skel" style="width:70%;margin-left:auto"></span></div>'
                  + '<div><span class="rx-skel" style="width:65%"></span><span class="rx-skel rx-skel--sm" style="width:35%"></span></div>'
                  + '<div><span class="rx-skel rx-skel--btn"></span></div>'
                  + '</div>';
        }
        return html;
    }

    function emptyStateFor(f) {
        const datesSet = f.from !== '' || f.to !== '';
        if (f.hideDone) {
            return emptyHtml('bi-check2-circle', '',
                'Brak aktywnych dokumentów',
                'Zakończone i anulowane zapytania oraz zamówienia są ukryte.',
                '<button type="button" class="btn btn-sm btn-primary js-show-finished">'
              + '<i class="bi bi-eye"></i> Pokaż zakończone i anulowane</button>');
        }
        const allDatesBtn = '<button type="button" class="btn btn-sm btn-primary js-range-all">'
                          + '<i class="bi bi-calendar3"></i> Pokaż wszystkie daty</button>';
        const clearBtn = '<button type="button" class="btn btn-sm rx-btn-quiet js-clear-filters">'
                       + '<i class="bi bi-x-circle"></i> Wyczyść filtry</button>';

        if (isDefault(f)) {
            return emptyHtml('bi-calendar-x', '',
                'Brak dokumentów z ostatnich ' + DEFAULT_RANGE + ' dni',
                'Starsze zapytania i zamówienia zobaczysz po rozszerzeniu zakresu dat.',
                allDatesBtn);
        }
        const onlyDatesCleared = f.from === '' && f.to === '' && f.type === 'both'
            && f.vendors.length === 0 && f.states.length === 0 && f.number === '';
        if (onlyDatesCleared) {
            return emptyHtml('bi-inbox', '',
                'Nie ma jeszcze żadnych dokumentów',
                'Zapytania i zamówienia tworzysz w koszyku zakupowym.',
                '<a class="btn btn-sm btn-primary" href="' + esc(appUrl('/purchase/cart')) + '">'
              + '<i class="bi bi-cart3"></i> Przejdź do koszyka</a>');
        }
        return emptyHtml('bi-funnel', '',
            'Brak dokumentów dla wybranych filtrów',
            'Zmień kryteria albo wyczyść filtry.',
            (datesSet ? allDatesBtn : '') + clearBtn);
    }

    function renderList(docs, f) {
        lastDocsCount = docs.length;
        if (docs.length === 0) {
            $list.empty();
            showStatus(emptyStateFor(f));
            return;
        }
        $list.html(docs.map(rowHtml).join(''));
        showList();
    }

    function createdHtml(createdAt) {
        const s = String(createdAt || '');
        const day = s.slice(0, 10);
        const time = s.length >= 16 ? s.slice(11, 16) : '';
        let rel = '';
        if (day === isoDay(new Date())) rel = 'dzisiaj';
        else if (day === daysAgo(1)) rel = 'wczoraj';
        const sub = [rel, time].filter(Boolean).join(', ');
        return '<div class="rx-date-main">' + esc(fmtDate(day) || '—') + '</div>'
             + (sub ? '<div class="rx-sub">' + esc(sub) + '</div>' : '');
    }

    function valueHtml(breakdown) {
        if (!Array.isArray(breakdown) || breakdown.length === 0) {
            return '<span class="text-faint">—</span>';
        }
        const priced = breakdown.filter(function (b) { return parseFloat(b.total) !== 0; });
        if (priced.length === 0) {
            // Ten sam znacznik co na ekranie dokumentu (`—` w text-muted),
            // żeby pusta kolumna Wartość wyglądała identycznie w obu
            // miejscach. Wcześniej było tu "----" w dx-noprice — reguła
            // .dx-noprice zniknęła razem z tym znacznikiem.
            return '<span class="text-muted">—</span>';
        }
        return priced.map(function (b) {
            return '<div class="dx-money">' + esc(fmtMoney(b.total))
                 + ' <span class="dx-cur">' + esc(b.currency) + '</span></div>';
        }).join('');
    }

    // Następne kroki dokumentu (Wyślij / Oznacz odpowiedź / Potwierdź
    // odpowiedź / Potwierdź odbiór / Dokończ przyjęcie / Przyjmij towar)
    // NIE są już osobnymi przyciskami obok Edytuj.
    // Stoją w menu pod caretem split-buttona edycji — patrz
    // actionMenuItemsHtml() poniżej. To samo z logistycznymi (Potwierdź
    // odbiór / Dokończ przyjęcie / Przyjmij towar), które wcześniej na
    // tej liście nie pojawiały się, a których brak był powodem długiej
    // kolumny Akcji.

    // Przycisk zamknięcia — współdzielony z kolejką /purchase/receipts
    // (patrz receipts-view.js → queueRowHtml). Stoi pierwszy w komórce
    // akcji; obok niego zostaje Edytuj/Podgląd (split button z menu
    // następnych kroków). close-po.js → #receiveClosePoModal (received/
    // cancelled) albo #receiveCancelPoModal (cancelled only) obsługuje
    // oba warianty — patrz delegacja [data-action="close-po"|"cancel-po"].
    //
    // Stan → akcja:
    //   PO    draft             cancel-po (prosty modal anulowania;
    //                            cancelPo() pozwala na `draft`, closePo
    //                            FromReceive() tego stanu nie przyjmuje)
    //   PO    sent              cancel-po (prosty modal anulowania,
    //                            jedyne legalne wyjście z `sent`)
    //   PO    confirmed /       close-po  (modal z wyborem received
    //         in_progress /     / cancelled przez receipt-close-po.php)
    //         partially_received
    //   PO    received /        terminal — bez przycisku
    //         cancelled
    //   RFQ   draft / sent /    cancel-po (z data-doc-type="rfq", żeby
    //         responded         handler wysłał cancelRfq() zamiast
    //                            cancelPo() i zmienił tekst okna)
    //   RFQ   converted /       terminal — bez przycisku
    //         cancelled
    //
    // data-back-url informuje close-po.js, że po sukcesie redirect ma
    // trafić z powrotem na /purchase/documents (a nie domyślne
    // /purchase/receipts z receipts-view.js).
    function closePoBtnHtml(d) {
        const id = parseInt(d.id, 10);
        // `esc` w tym pliku ucieka i cudzysłowy (→ &quot;/&#39;), więc nadaje się
        // do atrybutów — nie potrzeba osobnego escAttr.
        const number = esc(d.primary_number || ('#' + id));
        const backUrl = esc(appUrl('/purchase/documents'));
        const docTypeAttr = (d.type === 'rfq') ? ' data-doc-type="rfq"' : '';
        const commonAttrs = ' data-po-id="' + id + '"'
            + ' data-po-number="' + number + '"'
            + ' data-back-url="' + backUrl + '"'
            + docTypeAttr;

        if (d.type === 'po') {
            if (d.state === 'draft' || d.state === 'sent') {
                return '<button type="button" class="btn btn-sm btn-outline-danger rx-close-po-btn"'
                    + ' data-action="cancel-po"'
                    + commonAttrs
                    + ' title="Anuluj zamówienie"'
                    + ' aria-label="Anuluj zamówienie">'
                    + '<i class="bi bi-x-square"></i>'
                    + '</button>';
            }
            if (d.state === 'confirmed' || d.state === 'partially_received' || d.state === 'in_progress') {
                // close-po.js buduje podsumowanie modala („przyjęto X z Y")
                // z data-ordered-qty / data-received-qty — bez tych
                // atrybutów pokazywało „przyjęto 0 z 0". Agregaty przychodzą
                // z documents-table.php; /purchase/receipts ma swoje, stąd
                // duplikacja atrybutów w obu listach.
                const orderedQty  = Number(d.orderedQty)  || 0;
                const receivedQty = Number(d.receivedQty) || 0;
                const lineCount   = parseInt(d.item_count, 10) || 0;
                return '<button type="button" class="btn btn-sm btn-outline-danger rx-close-po-btn"'
                    + ' data-action="close-po"'
                    + ' data-ordered-qty="' + esc(orderedQty) + '"'
                    + ' data-received-qty="' + esc(receivedQty) + '"'
                    + ' data-line-count="' + esc(lineCount) + '"'
                    + commonAttrs
                    + ' title="Zamknij zamówienie (odebrane / anulowane)"'
                    + ' aria-label="Zamknij zamówienie">'
                    + '<i class="bi bi-x-square"></i>'
                    + '</button>';
            }
            // PO `received` / `cancelled` — stan terminalny, bez przycisku.
            return '';
        }

        if (d.type === 'rfq') {
            if (d.state === 'draft' || d.state === 'sent' || d.state === 'responded') {
                return '<button type="button" class="btn btn-sm btn-outline-danger rx-close-po-btn"'
                    + ' data-action="cancel-po"'
                    + commonAttrs
                    + ' title="Anuluj zapytanie"'
                    + ' aria-label="Anuluj zapytanie">'
                    + '<i class="bi bi-x-square"></i>'
                    + '</button>';
            }
            // RFQ `converted` / `cancelled` — stan terminalny, bez przycisku.
            return '';
        }

        return '';
    }

    // Pozycje menu pod caretem split-buttona Edytuj/Podgląd. Renderowane
    // tylko wtedy, gdy stan wiersze na to pozwala — terminalne (PO
    // received/cancelled, RFQ converted/cancelled) i tak ich nie mają,
    // więc caller ukrywa caret i zostaje zwykły link Edytuj.
    //
    // Konwencje data-* dla każdej pozycji:
    //   - Wyślij / Oznacz odpowiedź / Potwierdź odbiór / Dokończ
    //     przyjęcie / Przyjmij towar → zwykły <a href>; router je
    //     obsługuje po stronie serwera.
    //   - Potwierdź odpowiedź (PO `sent`) → zwykły <a href> do
    //     dedykowanej strony odpowiedzi
    //     /admin/purchase/documents/respond?id=N&type=po (ten sam
    //     router co dla RFQ, inny parametr type). Operator wpisuje tam
    //     wynegocjowane ceny dostawcy per pozycja.
    function actionMenuItemsHtml(d) {
        const id = parseInt(d.id, 10);
        const items = [];

        if (d.state === 'draft') {
            const url = esc(appUrl('/admin/purchase/documents/send?id=' + id + '&type=' + d.type));
// Klasy kolorów (.rx-action-primary/-success) z receipts-ui.css —
// współdzielone z receipts-view.js → queueActionMenuHtml, żeby
// obie listy miały identyczny odcień dla tej samej akcji.
//
// Odcienie rozróżniają RODZAJ akcji, nie kopiują klasy przycisku
// ze strony edycji: „Potwierdź odpowiedź" i „Utwórz zamówienie" są
// zielone (kończą etap / zakładają nowy), „Wyślij", „Oznacz odpowiedź",
// „Potwierdź odbiór", „Dokończ przyjęcie" i „Przyjmij towar" niebieskie
// (kierują operatora dalej). Mapping ustalony wprost przez operatora
// — nie „wygląda jak na edycji".
            items.push('<a class="dropdown-item rx-action-primary" href="' + url + '">'
                + '<i class="bi bi-send"></i> Wyślij</a>');
        }
        if (d.type === 'rfq' && d.state === 'sent') {
            const url = esc(appUrl('/admin/purchase/documents/respond?id=' + id + '&type=rfq'));
            items.push('<a class="dropdown-item rx-action-primary" href="' + url + '">'
                + '<i class="bi bi-reply"></i> Oznacz odpowiedź</a>');
        }
        if (d.type === 'po' && d.state === 'sent') {
            const url = esc(appUrl('/admin/purchase/documents/respond?id=' + id + '&type=po'));
            items.push('<a class="dropdown-item rx-action-success" href="' + url + '">'
                + '<i class="bi bi-reply"></i> Potwierdź odpowiedź</a>');
        }
        if (d.type === 'po' && d.state === 'confirmed') {
            const url = esc(appUrl('/purchase/receipts/receive?po_id=' + id));
            items.push('<a class="dropdown-item rx-action-primary" href="' + url + '">'
                + '<i class="bi bi-check2-square"></i> Potwierdź odbiór</a>');
        }
        if (d.type === 'po' && d.state === 'in_progress') {
            const url = esc(appUrl('/purchase/receipts/receive?po_id=' + id));
            items.push('<a class="dropdown-item rx-action-primary" href="' + url + '">'
                + '<i class="bi bi-box-arrow-in-down"></i> Dokończ przyjęcie</a>');
        }
        if (d.type === 'po' && d.state === 'partially_received') {
            const url = esc(appUrl('/purchase/receipts/receive?po_id=' + id));
            items.push('<a class="dropdown-item rx-action-primary" href="' + url + '">'
                + '<i class="bi bi-box-arrow-in-down"></i> Przyjmij towar</a>');
        }
        if (d.type === 'rfq' && d.state === 'responded') {
            // CTA otwiera #rfqConvertModal (współdzielony z header CTA
            // na stronie edycji). Delegowany handler w rfq-convert.js
            // czyta data-rfq-id / data-rfq-number / data-rfq-vendor-name
            // z tego triggera, więc musi mieć te atrybuty. Selektor
            // data-action (a nie id) — bo lista renderuje wiele wierszy
            // i stałe id kolidowałoby między wierszami.
            const number = esc(d.primary_number || ('#' + id));
            const vendor = esc(d.vendor_name || '');
            items.push('<button type="button" class="dropdown-item rx-action-success"'
                + ' data-action="rfq-convert"'
                + ' data-rfq-id="' + id + '"'
                + ' data-rfq-number="' + number + '"'
                + ' data-rfq-vendor-name="' + vendor + '">'
                + '<i class="bi bi-cart-check"></i> Utwórz zamówienie z odpowiedzi'
                + '</button>');
        }
        return items.join('');
    }

    // Kontrolka edycji — zwykły link Edytuj/Podgląd, opcjonalnie z
    // doczepionym caretem otwierającym menu następnych kroków.
    // Bootstrap 4 split button wymaga `<a>` + `.dropdown-toggle-split`,
    // Bootstrap sam dopisuje strzałkę przez `::after`. Dropdown
    // obsługuje delegowany `data-toggle="dropdown"` z bootstrap.min.js
    // (już wczytany na stronie); per-row init nie jest potrzebny.
    function editControlHtml(d) {
        const editUrl = d.edit_url || appUrl('/admin/purchase/documents/edit?id=' + d.id + '&type=' + d.type);
        const editable = !!d.editable;
        const linkClass = 'btn btn-sm rx-btn-quiet';
        const linkAttrs = ' href="' + esc(editUrl) + '"'
            + ' title="' + (editable ? 'Edytuj dokument' : 'Podgląd dokumentu (tylko do odczytu)') + '"';
        const link = editable
            ? '<a class="' + linkClass + '"' + linkAttrs + '><i class="bi bi-pencil-square"></i> Edytuj</a>'
            : '<a class="' + linkClass + '"' + linkAttrs + '><i class="bi bi-eye"></i> Podgląd</a>';

        const menu = actionMenuItemsHtml(d);
        if (!menu) return link;

        return '<div class="btn-group btn-group-sm">'
            + link
            + '<button type="button"'
            +   ' class="btn btn-sm rx-btn-quiet dropdown-toggle dropdown-toggle-split"'
            +   ' data-toggle="dropdown" aria-haspopup="true" aria-expanded="false"'
            +   ' aria-label="Więcej akcji dla dokumentu">'
            +   '<span class="sr-only">Więcej akcji dla dokumentu</span>'
            + '</button>'
            + '<div class="dropdown-menu dropdown-menu-right">' + menu + '</div>'
            + '</div>';
    }

    // Komórka akcji w wierszu: kosz (gdy jest sensowny) + kontrolka edycji.
    // Kontrolka edycji sama decyduje, czy jest zwykłym linkiem czy
    // split-buttonem z menu (na podstawie actionMenuItemsHtml) — więc
    // tu wystarczy złożyć kosz z kontrolką i ich opakować.
    function actionCellHtml(d) {
        return '<div class="dx-cell dx-cell--action">'
             + closePoBtnHtml(d)
             + editControlHtml(d)
             + '</div>';
    }

    function rowHtml(d) {
        const type = d.type === 'rfq' ? 'rfq' : 'po';
        const editUrl = d.edit_url || appUrl('/admin/purchase/documents/edit?id=' + d.id + '&type=' + type);
        const meta = stateMeta(d.state);
        const hint = (STATE_HINT[type] && STATE_HINT[type][d.state]) || '';
        const number = d.primary_number || '';

        const typeTag = type === 'rfq'
            ? '<span class="dx-type-tag dx-type-tag--rfq" title="Zapytanie ofertowe">RFQ</span>'
            : '<span class="dx-type-tag dx-type-tag--po" title="Zamówienie">PO</span>';

        let docCell = '<div class="dx-doc-line">' + typeTag
                    + '<a class="dx-number' + (number ? '' : ' is-empty') + '" href="' + esc(editUrl) + '">'
                    + esc(number || 'bez numeru') + '</a></div>';
        if (type === 'po' && d.secondary_number && d.secondary_number !== number) {
            docCell += '<div class="rx-sub">Nr u dostawcy: ' + esc(d.secondary_number) + '</div>';
        } else {
            docCell += '<div class="rx-sub text-faint">' + (type === 'rfq' ? 'Zapytanie' : 'Zamówienie') + ' #' + parseInt(d.id, 10) + '</div>';
        }

        const items = parseInt(d.item_count, 10) || 0;

        return '<div class="dx-row is-' + type + (d.state === 'cancelled' ? ' is-cancelled' : '') + '">'
             + '<div class="dx-cell dx-cell--doc">' + docCell + '</div>'
             + '<div class="dx-cell dx-cell--vendor"><span class="rx-cell-label">Dostawca</span>'
             +   '<span class="rx-vendor">' + esc(d.vendor_name || '—') + '</span></div>'
             + '<div class="dx-cell dx-cell--status">'
             +   '<span class="rx-badge rx-badge--' + meta.tone + '">' + esc(meta.label) + '</span>'
             + (hint ? '<div class="rx-sub">' + esc(hint) + '</div>' : '')
             + '</div>'
             + '<div class="dx-cell dx-cell--items"><span class="rx-cell-label">Pozycje</span>'
             +   (items > 0 ? '<span class="rx-pill">' + items + '</span>'
                            : '<span class="rx-pill rx-pill--empty">brak</span>')
             + '</div>'
             + '<div class="dx-cell dx-cell--value"><span class="rx-cell-label">Wartość</span>' + valueHtml(d.value_breakdown) + '</div>'
             + '<div class="dx-cell dx-cell--created"><span class="rx-cell-label">Utworzony</span>' + createdHtml(d.created_at) + '</div>'
             // Komórka akcji: kosz + kontrolka edycji (split-button
             // lub zwykły link — zależnie od tego, czy wiersz ma
             // kolejne kroki w menu).
             + actionCellHtml(d)
             + '</div>';
    }

    function updateSummary(f) {
        const $el = $('#docsSummary');
        if (lastDocsCount === 0 && totalCount === null) {
            $el.text('Brak wyników.');
            return;
        }
        const n = totalCount;
        const countPart = n === null
            ? 'Liczenie…'
            : '<strong>' + n + '</strong> ' + plural(n, 'dokument', 'dokumenty', 'dokumentów');
        let html = (isDefault(f) ? '' : '<i class="bi bi-funnel-fill"></i> ') + countPart
                 + ' <span class="dx-sep">·</span> ' + esc(rangeText(f));
        if (f.from !== '' || f.to !== '') {
            html += ' <button type="button" class="rx-link-btn js-range-all">pokaż wszystkie daty</button>';
        }
        // Wybrane statusy (mogą być włączone kilka kafelków naraz) —
        // w linijce wyników widać, co dokładnie zostało włączone.
        if (f.states.length) {
            html += ' <span class="dx-sep">·</span> ' + esc(f.states.map(function (s) {
                return stateMeta(s).label;
            }).join(', '));
        }
        $el.html(html);
    }

    // Paginacja: kompaktowa u góry (w linijce wyników), pełna na dole.
    function renderPagination() {
        const $top = $('#paginationTop');
        const $bottom = $('#paginationBottom');
        if (totalCount === null || totalCount === 0) {
            $top.empty();
            $bottom.empty();
            return;
        }
        const pages = Math.max(1, Math.ceil(totalCount / ITEMS_PER_PAGE));
        const first = (currentPage - 1) * ITEMS_PER_PAGE + 1;
        const last = Math.min(currentPage * ITEMS_PER_PAGE, totalCount);
        const atStart = currentPage <= 1;
        const atEnd = currentPage >= pages;

        function btn(action, icon, title, disabled) {
            return '<button type="button" class="btn rx-btn-quiet pagination-btn" data-action="' + action + '"'
                 + ' title="' + title + '" aria-label="' + title + '"' + (disabled ? ' disabled' : '') + '>'
                 + '<i class="bi ' + icon + '"></i></button>';
        }

        let items = '';
        for (let p = 1; p <= pages; p++) {
            items += '<a class="dropdown-item page-dropdown-item' + (p === currentPage ? ' active' : '')
                   + '" href="#" data-page="' + p + '">Strona ' + p + '</a>';
        }

        function pageMenu(dir) {
            return '<div class="btn-group btn-group-sm ' + dir + '">'
                 + '<button type="button" class="btn rx-btn-quiet dropdown-toggle rx-pager-current" data-toggle="dropdown">'
                 + 'Strona ' + currentPage + ' z ' + pages + '</button>'
                 + '<div class="dropdown-menu dropdown-menu-right rx-pager-menu">' + items + '</div>'
                 + '</div>';
        }

        if (pages > 1) {
            $top.html('<div class="btn-group btn-group-sm dx-pager-compact" role="group" aria-label="Strony (góra)">'
                + btn('prev', 'bi-chevron-left', 'Poprzednia strona', atStart)
                + pageMenu('')
                + btn('next', 'bi-chevron-right', 'Następna strona', atEnd)
                + '</div>');
        } else {
            $top.empty();
        }

        let html = '<div class="rx-pager">'
                 + '<div class="rx-pager-info">Wyświetlanie <strong>' + first + '–' + last + '</strong> z <strong>'
                 + totalCount + '</strong> ' + plural(totalCount, 'dokumentu', 'dokumentów', 'dokumentów') + '</div>';
        if (pages > 1) {
            html += '<div class="btn-group btn-group-sm rx-pager-nav" role="group" aria-label="Strony (dół)">'
                  + btn('first', 'bi-chevron-double-left', 'Pierwsza strona', atStart)
                  + btn('prev', 'bi-chevron-left', 'Poprzednia strona', atStart)
                  + pageMenu('dropup')
                  + btn('next', 'bi-chevron-right', 'Następna strona', atEnd)
                  + btn('last', 'bi-chevron-double-right', 'Ostatnia strona', atEnd)
                  + '</div>';
        }
        html += '</div>';
        $bottom.html(html);
    }

    function goToPage(page, fromBottom) {
        const pages = totalCount ? Math.max(1, Math.ceil(totalCount / ITEMS_PER_PAGE)) : 1;
        const next = Math.min(Math.max(1, page), pages);
        if (next === currentPage) return;
        currentPage = next;
        loadDocuments();
        if (fromBottom) {
            const top = $('#documentsView .dx-toolbar').offset().top - 16;
            if ($(window).scrollTop() > top) $('html, body').animate({ scrollTop: top }, 200);
        }
    }

    // =====================================================================
    // Zdarzenia
    // =====================================================================

    $('#typeSeg').on('click', '[data-type]', function () {
        const t = $(this).attr('data-type');
        if (t === docType) return;
        setType(t);
        // Statusy spoza nowego typu dokumentu nie mają sensu — zdejmujemy
        // je, żeby filtr nie wskazywał na ukryty kafelek.
        dropTypeMismatchStates(docType);
        filtersChanged(true);
    });

    // Wskazówka „+N" na końcu paska kafelków — klik otwiera modal
    // „Ukryte karty", który NIE zmienia filtrów, tylko informuje, co
    // trzeba zmienić, żeby zobaczyć wszystkie karty.
    $('.dx-stats').on('click', '.dx-stats-more', function () {
        explainHiddenTiles();
    });

    // Przycisk „Pokaż wszystkie karty" w modalu — NIE zmienia filtrów
    // od razu. Najpierw pokazuje potwierdzenie, co dokładnie się
    // zmieni; filtry ruszają dopiero po kliknięciu „Zmień filtry".
    $('#docsStatsMoreApply').on('click', function () {
        showStatsMoreConfirm();
    });

    // „Anuluj" w stanie potwierdzenia — wraca do stanu informacji.
    $('#docsStatsMoreCancel').on('click', function () {
        showStatsMoreInfo();
    });

    // Główny przycisk modalu. W stanie informacji nic nie zmienia
    // („Rozumiem" tylko zamyka), w stanie potwierdzenia stosuje zmianę.
    $('#docsStatsMoreOk').on('click', function () {
        if (statsMoreState === 'confirm') {
            showAllTiles();
        } else {
            $('#docsStatsMoreModal').modal('hide');
        }
    });

    $vendor.on('change', function () { filtersChanged(true); });
    $states.on('change', function () { filtersChanged(false); });
    $dateFrom.add($dateTo).on('change', function () { filtersChanged(true); });

    // Numer: krótka zwłoka, żeby nie wysyłać zapytania po każdym znaku.
    $number.on('input', function () {
        clearTimeout(numberTimer);
        numberTimer = setTimeout(function () { filtersChanged(true); }, 300);
    });
    $number.on('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            clearTimeout(numberTimer);
            filtersChanged(true);
        }
    });

    $('#rangeSeg').on('click', '[data-range]', function () {
        const r = $(this).attr('data-range');
        if (r === 'all' || $(this).hasClass('is-active')) {
            $dateFrom.val('');
        } else {
            $dateFrom.val(daysAgo(parseInt(r, 10)));
        }
        $dateTo.val('');
        filtersChanged(true);
    });

    $(document).on('click', '.js-range-all', function () {
        $dateFrom.val('');
        $dateTo.val('');
        filtersChanged(true);
    });

    // „Wyczyść filtry" = powrót do ustawień domyślnych (w tym 90 dni).
    $(document).on('click', '.js-clear-filters', function () {
        applyFiltersToUi(defaultFilters());
        filtersChanged(true);
    });

    $hideDone.on('change', function () {
        const on = $hideDone.is(':checked');
        saveHideDone(on);
        if (on) dropFinishedStates();
        filtersChanged(true);
    });
    $(document).on('click', '.js-show-finished', function () {
        $hideDone.prop('checked', false).trigger('change');
    });

    $refresh.on('click', function () { filtersChanged(true); });
    $(document).on('click', '.js-docs-retry', function () { filtersChanged(true); });

    // Kafelek = przełącznik filtra statusu (ten sam wzorzec co
    // /admin/purchase/vendor-parts): klik włącza albo wyłącza statusy
    // tego kafelka, a wybór pozostałych kafelków zostaje. Kilka kafelków
    // może być włączonych naraz — wtedy filtr to suma ich statusów.
    // Kafelki ukryte przy „Ukryj zakończone" (d-none) nie klikają się —
    // display:none wyciąga je z tabulatora.
    $('.dx-stats').on('click', '.rx-stat', function () {
        toggleTile($(this).attr('data-stat'));
    });

    $(document).on('click', '#documentsView .pagination-btn', function () {
        const act = $(this).attr('data-action');
        const pages = totalCount ? Math.max(1, Math.ceil(totalCount / ITEMS_PER_PAGE)) : 1;
        const fromBottom = $(this).closest('#paginationBottom').length > 0;
        if (act === 'first') goToPage(1, fromBottom);
        else if (act === 'prev') goToPage(currentPage - 1, fromBottom);
        else if (act === 'next') goToPage(currentPage + 1, fromBottom);
        else if (act === 'last') goToPage(pages, fromBottom);
    });

    $(document).on('click', '#documentsView .page-dropdown-item', function (e) {
        e.preventDefault();
        goToPage(parseInt($(this).attr('data-page'), 10) || 1,
            $(this).closest('#paginationBottom').length > 0);
    });

    // ---- Powrót po zamknięciu / anulowaniu PO z tej strony (?closed=…) --
    //
    // Osobna gałąź od ewentualnego „saved" (tego nie mamy — close-po.js
    // wysyła tylko ?closed=…&po=…). Tu NIE było zapisu przyjęcia, więc
    // nie ma wiersza do podświetlenia ani linku „Pokaż w historii".
    // Operator wybrał stan wynikowy (received/cancelled) w oknie
    // „Zamknij zamówienie" lub potwierdził anulowanie w „Anuluj
    // zamówienie" — close-po.js przekierował tu z powrotem z
    // data-back-url = /purchase/documents.

    const bootParams = new URLSearchParams(window.location.search);
    const bootClosed = bootParams.get('closed');
    if (bootClosed !== null && bootClosed !== '') {
        const bootPo = bootParams.get('po') || '';
        const meta = stateMeta(bootClosed);
        const icon = meta.tone === 'red'
            ? '<i class="bi bi-x-circle-fill"></i> '
            : '<i class="bi bi-check-circle-fill"></i> ';
        const msg = icon
            + 'Zamówienie <strong>' + esc(bootPo || '—') + '</strong> '
            + (bootClosed === 'cancelled' ? 'anulowano' : 'zamknięto')
            + ' — ma teraz status „<strong>' + esc(meta.label) + '</strong>".';
        // setAlert (z header.js) → Bootstrapowy alert do .alert-host
        // #alertContainer, ten sam co na /purchase/receipts.
        if (typeof setAlert === 'function') {
            setAlert(msg, 'success');
        }

        // Bez query stringu w pasku adresu F5 nie pokaże komunikatu ponownie.
        if (window.history && window.history.replaceState) {
            window.history.replaceState({}, '', window.location.pathname + window.location.hash);
        }
    }

    // =====================================================================
    // Start
    // =====================================================================

    const saved = restoredState();
    if (saved) {
        applyFiltersToUi($.extend(defaultFilters(), saved.filters));
        currentPage = Math.max(1, parseInt(saved.page, 10) || 1);
    } else {
        applyFiltersToUi(defaultFilters());
    }

    $hideDone.prop('checked', loadHideDone());
    if ($hideDone.is(':checked')) dropFinishedStates();

    loadDocuments();
    loadStats();
});
