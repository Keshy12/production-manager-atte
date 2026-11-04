// Przyjęcie dostawy wg faktury / WZ — /purchase/receipts/new
// (?delivery_id=N wznawia szkic albo pokazuje zatwierdzone przyjęcie,
//  ?vendor_id=N wstępnie wybiera dostawcę).
//
// Zasada: szkic żyje na serwerze, a KAŻDA odpowiedź zapisu niesie pełny
// stan dostawy (PurchaseActionHandler::getDeliveryState). Ten plik
// renderuje wszystko z tego jednego obiektu (render(state)) i niczego nie
// łata lokalnie. Zapisy idą jednym szeregiem (enqueue), żeby odpowiedzi
// nie wyprzedzały się nawzajem.
//
// Endpointy: COMPONENTS_PATH + '/purchases/receipts/delivery-*.php'.
// Wszystkie wywołania są POST-em (konwencja .htaccess — GET do plików
// w components/ nie dociera do PHP).

$(document).ready(function () {
    const $page = $('#deliveryPage');
    if ($page.length === 0) return;

    const ajaxBase = COMPONENTS_PATH + '/purchases/receipts/';
    const LIST_URL = String($page.attr('data-list-url') || (window.location.origin + ROOT_DIR + '/purchase/receipts'));
    const EPS = 1e-6;
    const MAGAZINES = Array.isArray(window.DV_MAGAZINES) ? window.DV_MAGAZINES : [];
    // Domyślny magazyn zalogowanego (user.sub_magazine_id) z atrybutu strony.
    // Pusty atrybut = brak domyślnego (NULL). Uwaga: „0" to PRAWIDŁOWY magazyn,
    // więc trzymamy go jako tekst i nigdy nie sprawdzamy „prawdziwości".
    const USER_MAGAZINE = (function () {
        const raw = $page.attr('data-user-magazine-id');
        return (raw === undefined || raw === null || raw === '') ? null : String(raw);
    })();

    const PO_STATE = {
        confirmed:          { label: 'Potwierdzone',       tone: 'blue' },
        in_progress:        { label: 'Częściowo przyjęte', tone: 'cyan' },
        partially_received: { label: 'Częściowo odebrane', tone: 'amber' },
        received:           { label: 'Odebrane',           tone: 'green' },
        cancelled:          { label: 'Anulowane',          tone: 'red' }
    };

    // =====================================================================
    // Stan klienta
    // =====================================================================

    const S = {
        deliveryId: parseInt($page.attr('data-delivery-id'), 10) || 0,
        vendorId: 0,
        vendorsLoaded: false,   // lista dostawców jeszcze nie przyszła z serwera
        state: null,            // ostatni pełny stan z serwera
        vendors: [],
        vendorMap: {},
        openItems: [],          // otwarte pozycje PO wybranego dostawcy
        openLoadedFor: 0,
        unitByVp: {},
        picked: null,           // wybrany artykuł w wierszu wpisywania
        editLineId: 0,
        entryMagDefault: '',    // magazyn domyślny dostawy użyty przy ostatnim wypełnieniu pickera pozycji
        entryBusy: false,
        creating: null,
        docAttempted: false,    // operator kliknął „Zapisz dokument dostawcy" przy brakach
        headerEdit: false,      // szkic istnieje: false = nagłówek zablokowany (odczyt), true = tryb edycji
        openNotes: {},          // line_id => true (rozwinięty wiersz komentarza)
        showAllBlockers: false,
        posting: false,
        writeSeq: 0,
        pending: 0,
        mute: 0                 // >0 = zmiana pickera pochodzi z kodu, nie od operatora
    };

    // =====================================================================
    // Drobiazgi
    // =====================================================================

    function esc(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
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

    function fmtPrice(n) {
        const v = parseFloat(n);
        if (isNaN(v)) return '';
        return v.toLocaleString('pl-PL', { minimumFractionDigits: 2, maximumFractionDigits: 4 });
    }

    // Do pola tekstowego: bez zbędnych zer, przecinek dziesiętny.
    function inputNum(n) {
        const v = parseFloat(n);
        if (isNaN(v)) return '';
        return v.toFixed(6).replace(/\.?0+$/, '').replace('.', ',');
    }

    // „12,5" / „12.5" / „1 200,50" → 12.5 ; błąd → NaN ; puste → NaN
    function parseNum(s) {
        const t = String(s == null ? '' : s).replace(/\s+/g, '').replace(',', '.');
        if (!/^-?(\d+\.?\d*|\.\d+)$/.test(t)) return NaN;
        return parseFloat(t);
    }

    function plural(n, one, few, many) {
        const a = Math.abs(n);
        if (a === 1) return one;
        const l = a % 10;
        const l2 = a % 100;
        if (l >= 2 && l <= 4 && (l2 < 12 || l2 > 14)) return few;
        return many;
    }

    function fmtDate(iso) {
        const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(iso || ''));
        return m ? (m[3] + '.' + m[2] + '.' + m[1]) : String(iso || '');
    }

    function todayIso() {
        const d = new Date();
        const p = function (n) { return n < 10 ? '0' + n : String(n); };
        return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate());
    }

    function nowHm() {
        const d = new Date();
        const p = function (n) { return n < 10 ? '0' + n : String(n); };
        return p(d.getHours()) + ':' + p(d.getMinutes());
    }

    function poEditUrl(poId) {
        return window.location.origin + ROOT_DIR + '/admin/purchase/documents/edit?id=' + encodeURIComponent(poId) + '&type=po';
    }

    function deliveryUrl(id) {
        return window.location.origin + ROOT_DIR + '/purchase/receipts/new?delivery_id=' + encodeURIComponent(id);
    }

    function showError(message) {
        setAlert(esc(message).replace(/\n/g, '<br>'), 'danger');
        $('html, body').animate({ scrollTop: 0 }, 200);
    }

    function showOk(html) {
        setAlert(html, 'success');
    }

    function stateBadge(state) {
        const m = PO_STATE[state] || { label: String(state || '—'), tone: 'neutral' };
        return '<span class="rx-badge rx-badge--' + m.tone + '">' + esc(m.label) + '</span>';
    }

    // Magazyn „0" („Produkcja - Cegielskiego") jest PRAWIDŁOWY. Id magazynu
    // sprawdzamy więc wyłącznie przez null / undefined / '' — nigdy przez
    // `!x`, `x ||` ani `!!x` (połykają 0). Wartość z .val() jest tekstem,
    // z JSON-a stanu liczbą; obie formy przechodzą tędy bez zmian.
    function hasMag(v) {
        return v !== null && v !== undefined && v !== '';
    }

    // Do payloadu zapisu: id magazynu albo '' (= brak, zapisze się NULL).
    function magParam(v) {
        return hasMag(v) ? v : '';
    }

    function magName(id) {
        const k = parseInt(id, 10);
        for (let i = 0; i < MAGAZINES.length; i++) {
            if (MAGAZINES[i].id === k) return MAGAZINES[i].name;
        }
        return '';
    }

    // Opcje magazynu: same prawdziwe magazyny, bez pustej opcji „domyślny" —
    // magazyn domyślny dostawy jest po prostu zaznaczoną opcją (`selectedId`).
    // Zaznaczona opcja ZAWSZE niesie atrybut `selected`: bootstrap-select
    // (data-title) dokłada na początku własną pustą opcję „placeholder"
    // i zaznacza ją, o ile żadna opcja nie ma tego atrybutu. Gdy `selectedId`
    // jest puste (dostawa bez magazynu domyślnego), zostaje sam placeholder.
    // `orphanName`: nazwa zaznaczonego magazynu, którego nie ma już na liście
    // aktywnych — dostaje własną opcję, żeby zapis nie zgubił go po cichu.
    function magOptionsHtml(selectedId, orphanName) {
        let html = '';
        let found = false;
        MAGAZINES.forEach(function (m) {
            const sel = hasMag(selectedId) && String(selectedId) === String(m.id);
            if (sel) found = true;
            html += '<option value="' + m.id + '"' + (sel ? ' selected' : '') + '>' + esc(m.name) + '</option>';
        });
        if (hasMag(selectedId) && !found) {
            html += '<option value="' + esc(selectedId) + '" selected>'
                  + esc(orphanName || ('Magazyn #' + selectedId)) + ' (nieaktywny)</option>';
        }
        return html;
    }

    // Nazwa magazynu domyślnego dostawy (z nagłówka stanu); '' gdy brak.
    function defaultMagName(st) {
        const d = st && st.delivery;
        if (!d) return '';
        if (d.default_sub_magazine_name) return d.default_sub_magazine_name;
        return hasMag(d.default_sub_magazine_id) ? (magName(d.default_sub_magazine_id) || ('#' + d.default_sub_magazine_id)) : '';
    }

    // Domyślny magazyn użytkownika, jeśli jest na liście aktywnych magazynów
    // (i jako opcja w pickerze); w przeciwnym razie null → picker zostaje pusty.
    function userDefaultMagazine() {
        if (USER_MAGAZINE === null) return null;
        const inList = MAGAZINES.some(function (m) { return String(m.id) === USER_MAGAZINE; });
        if (!inList) return null;
        const inPicker = $('#dvMagazine option').filter(function () { return String($(this).val()) === USER_MAGAZINE; }).length > 0;
        return inPicker ? USER_MAGAZINE : null;
    }

    function hasPicker() { return typeof $.fn.selectpicker === 'function'; }

    // =====================================================================
    // Komunikacja z serwerem
    // =====================================================================

    // Zwraca Promise z odpowiedzią (success === true) albo odrzuca
    // komunikatem tekstowym (Polskim).
    function call(endpoint, data) {
        return new Promise(function (resolve, reject) {
            $.ajax({ url: ajaxBase + endpoint, type: 'POST', data: data || {}, dataType: 'json' })
                .done(function (r) {
                    if (!r || r.success === false || r.success === undefined) {
                        reject(new Error((r && r.error) || 'Operacja nie powiodła się.'));
                        return;
                    }
                    resolve(r);
                })
                .fail(function (xhr, status) {
                    reject(new Error((xhr.responseJSON && xhr.responseJSON.error)
                        || (xhr.status === 403 ? 'Brak uprawnień lub sesja wygasła.' : 'Błąd komunikacji z serwerem (' + (xhr.status || status) + ').')));
                });
        });
    }

    function stateOf(r) {
        return (r && r.state && typeof r.state === 'object') ? r.state : null;
    }

    // ---- szereg zapisów + wskaźnik "Zapisano" --------------------------

    let writeChain = Promise.resolve();

    function setSaveState(kind, text) {
        const $el = $('#dvSaveState');
        $el.removeClass('is-saving is-error');
        if (kind === 'saving') {
            $el.addClass('is-saving').html('<span class="spinner-border spinner-border-sm" role="status"></span> Zapisywanie…');
        } else if (kind === 'error') {
            $el.addClass('is-error').html('<i class="bi bi-exclamation-triangle-fill"></i>' + esc(text || 'Błąd zapisu'));
        } else if (kind === 'saved') {
            $el.html('<i class="bi bi-check2"></i>Zapisano ' + esc(nowHm()));
        } else {
            $el.empty();
        }
    }

    // Zapis (POST) ustawiany w kolejce. Odpowiedź z pełnym stanem jest
    // renderowana tylko wtedy, gdy nie czekają za nią kolejne zapisy.
    function write(endpoint, data, opts) {
        opts = opts || {};
        S.pending++;
        const seq = ++S.writeSeq;
        setSaveState('saving');
        const job = writeChain.catch(function () {}).then(function () {
            return call(endpoint, data);
        });
        writeChain = job.then(function () {}, function () {});
        return job.then(function (r) {
            S.pending--;
            const st = stateOf(r);
            if (st && !opts.noRender && seq === S.writeSeq) render(st);
            else if (st && seq !== S.writeSeq) S.state = st;
            if (S.pending === 0) setSaveState('saved');
            return r;
        }, function (err) {
            S.pending--;
            setSaveState('error', err.message);
            throw err;
        });
    }

    // =====================================================================
    // Nagłówek dokumentu
    // =====================================================================

    const $vendor    = $('#dvVendor');
    const $docNumber = $('#dvDocNumber');
    const $docDate   = $('#dvDocDate');
    const $recvAt    = $('#dvReceivedAt');
    const $magazine  = $('#dvMagazine');
    const $currency  = $('#dvCurrency');
    const $comment   = $('#dvComment');

    function docType() {
        return $('#dvDocType button.is-active').attr('data-type') || 'invoice';
    }

    function setDocType(t) {
        $('#dvDocType button').each(function () {
            const on = $(this).attr('data-type') === t;
            $(this).toggleClass('is-active', on).attr('aria-pressed', on ? 'true' : 'false');
        });
    }

    // Dzisiejsza data (RRRR-MM-DD) tak, jak ją wyrenderował serwer w polu
    // „Data przyjęcia" (atrybut value); zapasowo — data z przeglądarki.
    const TODAY = $recvAt.prop('defaultValue') || (function () {
        const t = new Date();
        const p = function (n) { return (n < 10 ? '0' : '') + n; };
        return t.getFullYear() + '-' + p(t.getMonth() + 1) + '-' + p(t.getDate());
    })();

    // Obie daty zawsze idą do serwera jako sama data (RRRR-MM-DD), bez godziny.
    // Serwer zapisuje „Data przyjęcia" jako RRRR-MM-DD 00:00:00 i odrzuca wartość
    // późniejszą niż teraz + 1 dzień — dopisanie bieżącej godziny uniemożliwiłoby
    // wybór jutra. Dokładny moment zatwierdzenia trafia osobno do posted_at.
    function readHeader() {
        return {
            vendor_id: S.vendorId || '',
            document_type: docType(),
            vendor_document_number: $.trim($docNumber.val()),
            document_date: $docDate.val() || '',
            received_at: $recvAt.val() || '',
            currency: $currency.val() || 'PLN',
            default_sub_magazine_id: magParam($magazine.val()),
            comment: $.trim($comment.val())
        };
    }

    function ensureCurrencyOption(code) {
        if (!code) return;
        const has = $currency.find('option').filter(function () { return $(this).val() === code; }).length;
        if (!has) $currency.append('<option value="' + esc(code) + '">' + esc(code) + '</option>');
    }

    function setField($el, value, force) {
        if (!force && $el.is(document.activeElement)) return;
        if ($el.val() !== value) $el.val(value);
    }

    // Programowa zmiana pickera nie może uruchamiać autozapisu
    // (bootstrap-select wysyła przy val() zdarzenia changed/change).
    //
    // `force` — przestawiamy wartość nawet, gdy już się zgadza. Potrzebne tam,
    // gdzie podmieniamy listę opcji: bootstrap-select.render() szuka w swoich
    // danych (main.data) pozycji opcji po jej `liIndex`, a `liIndex` nadaje
    // dopiero buildData() — wywoływany PO render(). Przy podmianie opcji
    // pierwsze render() (w refresh()) widzi więc opcje bez `liIndex`, nie buduje
    // treści przycisku i pokazuje zamiast niego placeholder (data-title).
    // Dopiero selectpicker('val', …) po odświeżeniu renderuje przycisk
    // poprawnie — tak jak robi to „Magazyn docelowy" w nagłówku.
    function setPickerVal($el, value, force) {
        const v = (value === null || value === undefined) ? '' : String(value);
        if (!force && String($el.val() == null ? '' : $el.val()) === v) return;
        S.mute++;
        try {
            if (hasPicker()) $el.selectpicker('val', v);
            else $el.val(v);
        } finally {
            S.mute--;
        }
    }

    function setPickerDisabled($el, disabled) {
        $el.prop('disabled', disabled);
        if (hasPicker()) $el.selectpicker('refresh');
        $el.closest('.dv-field').toggleClass('is-locked', disabled);
    }

    // Tryb nagłówka dokumentu dostawcy:
    //   new     — szkic jeszcze nie istnieje: pola edytowalne, przycisk zakłada szkic,
    //   wait    — szkic istnieje, ale jego stan jeszcze nie dotarł z serwera,
    //   locked  — szkic istnieje, nagłówek tylko do odczytu („Edytuj dokument dostawcy"),
    //   editing — szkic istnieje, operator edytuje (autozapis, „Zakończ edycję"),
    //   closed  — przyjęcie zatwierdzone / anulowane: tylko odczyt, bez przycisku.
    function headerMode() {
        if (!S.deliveryId) return 'new';
        if (!S.state || !S.state.delivery) return 'wait';
        if (S.state.delivery.state !== 'draft') return 'closed';
        return S.headerEdit ? 'editing' : 'locked';
    }

    // Data + godzina („04.11.2026 14:05"); sama data, gdy brak godziny.
    function fmtStamp(iso) {
        const m = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/.exec(String(iso || ''));
        if (!m) return String(iso || '');
        return m[3] + '.' + m[2] + '.' + m[1] + (m[4] !== undefined ? ' ' + m[4] + ':' + m[5] : '');
    }

    // Jedna pozycja podsumowania: etykieta + wartość. `html` to gotowy,
    // już zabezpieczony znacznik wartości; pusta wartość = „—".
    // `labelExtra` — gotowy znacznik dopisany za etykietą (np. kłódka).
    function sumItem(label, html, mod, labelExtra) {
        const empty = (html === null || html === undefined || html === '');
        return '<div class="dv-sum-item' + (mod ? ' ' + mod : '') + '">'
             +   '<div class="dv-label">' + esc(label) + (labelExtra || '') + '</div>'
             +   '<div class="dv-sum-val">' + (empty ? '<span class="dv-sum-empty">—</span>' : html) + '</div>'
             + '</div>';
    }

    // Tekst → znacznik wartości (pusty / same spacje → '' = „—").
    function sumText(v) {
        const t = $.trim(v == null ? '' : String(v));
        return t === '' ? '' : esc(t);
    }

    // Podsumowanie zapisanego dokumentu dostawcy (tryb tylko do odczytu).
    // Rysowane z ostatniego stanu serwera (S.state.delivery) — pokazuje to,
    // co jest zapisane, a nie to, co wpisano w ukryty formularz. Woła je
    // applyHeaderLock(), czyli przy każdej zmianie stanu i trybu nagłówka.
    function renderSummary() {
        const $sum = $('#dvSummary');
        const st = S.state;
        if (!st || !st.delivery) {
            $sum.html('<div class="dv-summary-note">Wczytywanie dokumentu…</div>');
            return;
        }
        const d = st.delivery;

        // Typ dokumentu jako zwykły tekst (taki sam jak pozostałe wartości).
        let typeHtml = '';
        if (d.document_type === 'invoice') typeHtml = 'Faktura';
        else if (d.document_type === 'wz') typeHtml = 'WZ';
        else typeHtml = sumText(d.document_type);

        // Magazyn domyślny: nazwa z serwera → lista magazynów → „#id".
        // Magazyn „0" jest prawidłowy — defaultMagName() sprawdza go przez hasMag().
        const mag = defaultMagName(st);

        const docNo = $.trim(d.vendor_document_number == null ? '' : String(d.vendor_document_number));

        // Numer dokumentu jako druga część tytułu karty („ · FV/123/2026").
        $('#dvDocNo').text(docNo === '' ? '' : ' · ' + docNo);

        // Tylko do wyświetlenia: „Produkcja - Cegielskiego" → „Produkcja – Cegielskiego".
        const magShown = mag.replace(/ - /g, ' \u2013 ');

        const lock = ' <i class="bi bi-lock dv-sum-lock" role="img"'
                   + ' aria-label="Dostawcy nie można zmienić po utworzeniu szkicu."'
                   + ' title="Dostawcy nie można zmienić po utworzeniu szkicu."></i>';

        // Kolejność w DOM jest kolumnowa (siatka wypełnia kolumny w dół):
        // kol. 1 Dostawca / Nr dokumentu, kol. 2 Typ / Waluta,
        // kol. 3 Data dokumentu / Data przyjęcia, kol. 4 Magazyn.
        let grid = '';
        grid += sumItem('Dostawca', sumText(d.vendor_name), '', lock);
        grid += sumItem('Nr dokumentu', docNo === '' ? '' : '<span class="dv-sum-doc">' + esc(docNo) + '</span>');
        grid += sumItem('Typ dokumentu', typeHtml);
        grid += sumItem('Waluta', sumText(d.currency));
        grid += sumItem('Data dokumentu', sumText(fmtDate(d.document_date)));
        grid += sumItem('Data przyjęcia', sumText(fmtDate(d.received_at)));
        grid += sumItem('Magazyn docelowy', mag === '' ? '' : esc(magShown));

        let html = '';
        if (d.state === 'posted') {
            // GRN jest już w znaczniku #dvGrnTag w nagłówku karty — nie dublujemy.
            const stamp = fmtStamp(d.posted_at);
            const by = $.trim(d.posted_by_name == null ? '' : String(d.posted_by_name));
            html += sumItem('Zatwierdzono',
                stamp === '' && by === '' ? '' : esc(stamp) + (by !== '' ? '<span class="dv-sum-sub"> · ' + esc(by) + '</span>' : ''), 'dv-sum-item--audit');
        } else if (d.state === 'cancelled' && $.trim(d.grn_number == null ? '' : String(d.grn_number)) !== '') {
            // Dla anulowanego znacznik GRN w nagłówku jest ukryty — numer pokazujemy tu.
            html += sumItem('Numer GRN', '<span class="dv-sum-doc">' + esc(d.grn_number) + '</span>', 'dv-sum-item--audit');
        }

        // Komentarz tylko, gdy coś w nim jest.
        const comment = $.trim(d.comment == null ? '' : String(d.comment));
        if (comment !== '') {
            html += '<div class="dv-sum-item dv-sum-item--wide">'
                  +   '<div class="dv-label">Komentarz</div>'
                  +   '<div class="dv-sum-val dv-sum-comment">' + esc(comment) + '</div>'
                  + '</div>';
        }

        // Ślad zatwierdzenia i komentarz — osobny blok pod główną siatką.
        $sum.html('<div class="dv-sum-grid">' + grid + '</div>'
                + (html === '' ? '' : '<div class="dv-sum-extra">' + html + '</div>'));
    }

    // Odczyt vs edycja pól nagłówka. Pola tekstowe i daty dostają `readonly`
    // (zapisane wartości zostają czytelne); to, co nie ma stanu readonly —
    // waluta, typ dokumentu, picker magazynu — dostaje `disabled`.
    // Dostawca jest zablokowany ZAWSZE po założeniu szkicu (także w trybie
    // edycji): zmiana dostawcy szkicu z pozycjami zepsułaby dopasowanie do PO.
    function applyHeaderLock() {
        const mode = headerMode();
        const ro = (mode !== 'new' && mode !== 'editing');
        // Dwie prezentacje nagłówka: formularz (new / editing) albo
        // podsumowanie zapisanego dokumentu (locked / closed / wait).
        // Formularz jest tylko ukrywany (CSS), nie usuwany — pola i pickery
        // zachowują wartości, które renderHeader() wpisuje z tego samego stanu.
        // Przełączenie musi nastąpić PRZED fokusem w startHeaderEdit().
        $('#dvHeaderCard').toggleClass('is-hdr-summary', ro);
        if (ro) renderSummary();
        // Numer w tytule karty — tylko przy podsumowaniu (w edycji pole pokazuje go samo).
        $('#dvDocNo').toggleClass('d-none', !ro);
        $docNumber.add($docDate).add($recvAt).add($comment).prop('readOnly', ro);
        $currency.prop('disabled', ro);
        $('#dvDocType button').prop('disabled', ro);
        $('#dvDocType').toggleClass('is-locked', ro);
        setPickerDisabled($magazine, ro);
        applyVendorPickerState();
        $('#dvHeaderCard').toggleClass('is-hdr-locked', ro).toggleClass('is-hdr-editing', mode === 'editing');
    }

    // Krok 1 jest aktywny → reszta strony przygaszona (ten sam stan co przed
    // zapisem dokumentu dostawcy). Dwa tryby:
    //   new     — szkicu jeszcze nie ma: dokument dostawcy nie zapisany,
    //   editing — szkic jest, ale operator poprawia dokument dostawcy.
    // W obu pozycje z faktury są zablokowane: w „new" nie ma jeszcze czego
    // wpisywać, w „editing" — do czasu „Zakończ edycję".
    function applyStepGate() {
        const mode = headerMode();
        $page.toggleClass('dv-pre-draft', mode === 'new');
        $page.toggleClass('dv-editing', mode === 'editing');
    }

    function renderHeader(st, force) {
        const d = st.delivery;
        const editable = d.state === 'draft';

        // Gdy operator ma niezapisane zmiany w nagłówku (timer autozapisu),
        // odpowiedź serwera nie może ich nadpisać.
        const dirty = headerTimer !== null && !force;
        if (!dirty) {
            setField($docNumber, d.vendor_document_number || '', force);
            setField($docDate, d.document_date ? String(d.document_date).substring(0, 10) : '', force);
            // Zapisana data przyjęcia; szkic sprzed zmiany (received_at = NULL)
            // pokazuje dzisiejszą datę zamiast pustego pola (zapisze się
            // przy najbliższym zapisie nagłówka).
            setField($recvAt, d.received_at ? String(d.received_at).substring(0, 10) : TODAY, force);
            setField($comment, d.comment || '', force);
            setDocType(d.document_type || 'invoice');

            ensureCurrencyOption(d.currency);
            if (!$currency.is(document.activeElement)) $currency.val(d.currency || 'PLN');
            $('#dvPriceCur').text(d.currency || 'PLN');

            setPickerVal($magazine, d.default_sub_magazine_id);
        }

        if (d.vendor_id && String($vendor.val()) !== String(d.vendor_id)) {
            ensureVendorOption(d.vendor_id, d.vendor_name);
            setPickerVal($vendor, d.vendor_id);
        }

        // Edytowalny jest tylko szkic w trybie edycji; reszta to odczyt.
        applyHeaderLock();

        // Wskazówki „czego brakuje" pojawiają się, gdy są już pozycje.
        const lineCount = (st.lines || []).length;
        $docNumber.toggleClass('is-invalid', editable && lineCount > 0 && $.trim(d.vendor_document_number || '') === '');
        $('#dvMagazineCell').toggleClass('is-invalid', editable && lineCount > 0 && !hasMag(d.default_sub_magazine_id)
            && (st.lines || []).some(function (l) { return !hasMag(l.effective_sub_magazine_id); }));

        // Duplikat numeru dokumentu u tego dostawcy.
        const dup = st.duplicate_document || [];
        let hint = '';
        if (editable && dup.length) {
            hint = '<span class="dv-hint--warn"><i class="bi bi-exclamation-triangle-fill"></i> Ten numer już występuje: '
                + dup.map(function (x) {
                    const lbl = x.state === 'draft' ? 'szkic #' + x.id : (x.grn_number || ('przyjęcie #' + x.id));
                    return '<a href="' + esc(deliveryUrl(x.id)) + '">' + esc(lbl) + '</a>';
                }).join(', ') + '</span>';
        } else if (editable && $.trim(d.vendor_document_number || '') === '') {
            hint = 'Wymagany do zatwierdzenia.';
        }
        $('#dvDocNumberHint').html(hint);

        // Tytuł strony.
        const vname = d.vendor_name || '';
        const badge = $('#dvStateBadge');
        badge.removeClass('rx-badge--blue rx-badge--green rx-badge--red');
        const $grn = $('#dvGrnTag');
        if (d.state === 'posted') {
            badge.addClass('rx-badge--green').text('Zatwierdzone');
            $('#dvTitle').text(d.grn_number || ('Przyjęcie #' + d.id));
            $('#dvCrumb').text(d.grn_number || ('Przyjęcie #' + d.id));
            $('#dvEyebrow').html('<i class="bi bi-receipt"></i> Przyjęcie zatwierdzone');
            $('#dvLead').text(vname + (d.posted_at ? ' · zaksięgowano ' + fmtDate(d.posted_at) : ''));
            $grn.removeClass('d-none').text(d.grn_number || '');
        } else if (d.state === 'cancelled') {
            badge.addClass('rx-badge--red').text('Anulowane');
            $('#dvTitle').text((d.grn_number || ('Przyjęcie #' + d.id)) + ' — anulowane');
            $('#dvCrumb').text(d.grn_number || ('Przyjęcie #' + d.id));
            $('#dvEyebrow').html('<i class="bi bi-receipt"></i> Przyjęcie anulowane');
            $('#dvLead').text('Przyjęcie zostało wycofane — stany magazynowe i zamówienia wróciły do poprzedniego stanu.');
            $grn.addClass('d-none');
        } else {
            badge.addClass('rx-badge--blue').text('Szkic');
            $('#dvTitle').text(vname ? ('Przyjęcie od: ' + vname) : 'Nowe przyjęcie dostawy');
            $('#dvCrumb').text('Szkic #' + d.id);
            $('#dvEyebrow').html('<i class="bi bi-receipt"></i> Przyjęcie wg faktury / WZ');
            $('#dvLead').text('Wpisuj pozycje w kolejności z dokumentu dostawcy — każda od razu dopasowuje się do otwartych zamówień tego dostawcy.');
            $grn.addClass('d-none');
        }
    }

    // ---- autozapis nagłówka -------------------------------------------

    let headerTimer = null;
    let headerInflight = null;   // zapis nagłówka w locie (Promise<boolean>)
    let headerFailed = false;    // ostatni zapis nagłówka się nie udał
    let headerFinishing = false; // trwa „Zakończ edycję"

    // Autozapis działa tylko w trybie edycji. Zablokowany nagłówek (odczyt)
    // nie zapisuje niczego — także przy blur / change na polach tylko do odczytu.
    function scheduleHeaderSave(immediate) {
        if (headerMode() !== 'editing') return;
        clearTimeout(headerTimer);
        headerTimer = setTimeout(saveHeader, immediate ? 0 : 500);
    }

    // Zapisuje oczekujące zmiany (timer) i czeka na zapis w locie — np. taki,
    // który wystartował z blur tuż przed kliknięciem. Zwraca Promise<boolean>
    // (false = zapis się nie udał; komunikat jest już pokazany).
    function flushHeader() {
        if (headerTimer !== null) {
            clearTimeout(headerTimer);
            headerTimer = null;
            return saveHeader();
        }
        return headerInflight || Promise.resolve(true);
    }

    function saveHeader() {
        headerTimer = null;
        if (!S.deliveryId || !S.state || S.state.delivery.state !== 'draft') return Promise.resolve(true);
        const payload = $.extend({ delivery_id: S.deliveryId }, readHeader());
        delete payload.vendor_id;
        const job = write('delivery-header-save.php', payload).then(function () {
            headerFailed = false;
            return true;
        }, function (err) {
            headerFailed = true;
            showError(err.message);
            return false;
        });
        headerInflight = job;
        job.then(function () { if (headerInflight === job) headerInflight = null; });
        return job;
    }

    // ---- tryb nagłówka: odczyt → edycja → odczyt ------------------------

    function startHeaderEdit() {
        if (headerMode() !== 'locked') return;
        S.headerEdit = true;
        applyHeaderLock();
        applyStepGate();
        refreshDocGate();
        // Fokus na pierwszym edytowalnym polu (numer dokumentu), kursor na końcu.
        $docNumber.trigger('focus');
        try {
            const n = $docNumber.val().length;
            $docNumber[0].setSelectionRange(n, n);
        } catch (e) { /* ignore */ }
    }

    // „Zakończ edycję": najpierw zapis oczekujących zmian. Przy błędzie
    // zostajemy w trybie edycji (komunikat pokazuje saveHeader).
    async function finishHeaderEdit() {
        if (headerMode() !== 'editing' || headerFinishing) return;
        headerFinishing = true;
        $('#dvDocSubmit').prop('disabled', true);
        try {
            let ok = await flushHeader();
            // Poprzedni zapis padł, a nic nowego nie czeka — ponów, żeby nie
            // zablokować nagłówka z niezapisanymi wartościami w polach.
            if (ok !== false && headerFailed) ok = await saveHeader();
            if (ok === false) return;
            S.headerEdit = false;
            // Krok 1 zakończony → pozycje znów dostępne, reszta strony wraca
            // do pełnej jasności.
            applyStepGate();
            // Pola pokazują zapisany stan z serwera, już tylko do odczytu.
            if (S.state) renderHeader(S.state, true);
        } finally {
            headerFinishing = false;
            refreshDocGate();
        }
    }

    // ---- zapis dokumentu dostawcy (założenie szkicu) -------------------
    //
    // Dopóki szkic nie istnieje, nagłówek nie autozapisuje się i nie da się
    // wpisywać pozycji. Przycisk „Zapisz dokument dostawcy" tworzy szkic
    // (delivery-create.php) — wymaga dostawcy, numeru dokumentu i magazynu.

    function docGateMissing() {
        const miss = [];
        if (!S.vendorId) miss.push({ key: 'vendor', label: 'dostawca' });
        if ($.trim($docNumber.val()) === '') miss.push({ key: 'doc', label: 'numer dokumentu' });
        const mv = $magazine.val();
        // Magazyn „0" jest prawidłowy — pusty to tylko null / undefined / ''.
        if (mv === null || mv === undefined || mv === '') miss.push({ key: 'mag', label: 'magazyn docelowy' });
        return miss;
    }

    // Pasek pod dokumentem: komunikat + jeden przycisk, którego etykieta,
    // ikona, kolor i działanie zależą od trybu nagłówka (patrz headerMode()
    // i handler kliknięcia niżej).
    function paintDocFoot(o) {
        const $msg = $('#dvDocSubmitMsg');
        $msg.toggleClass('is-error', !!o.error);
        $msg.find('.bi').attr('class', o.msgIcon);
        $('#dvDocSubmitMsgText').text(o.text);
        $('#dvDocSubmitLabel').text(o.label);
        $('#dvDocSubmit').prop('disabled', !!o.disabled)
            .removeClass('btn-primary btn-outline-primary btn-success').addClass(o.btnClass)
            .find('i').attr('class', o.btnIcon);
    }

    function refreshDocGate() {
        const mode = headerMode();
        // Pasek jest widoczny dla szkicu (przed zapisem, w odczycie, w edycji);
        // dla zatwierdzonego / anulowanego przyjęcia i do czasu wczytania
        // wznawianego szkicu — ukryty.
        // W trybie „locked" stopki nie ma — akcja „Edytuj" i status „Zapisano"
        // są w nagłówku karty.
        $('#dvDocFoot').attr('data-mode', mode)
            .toggleClass('d-none', mode === 'closed' || mode === 'wait' || mode === 'locked');
        $('#dvDocEditBtn').toggleClass('d-none', mode !== 'locked');
        $('#dvDocSaved').toggleClass('d-none', mode !== 'locked');
        // „Wyczyść" ma sens tylko przed założeniem szkicu — wtedy formularz
        // jest jeszcze nie zapisany i można go wyzerować bez straty.
        $('#dvDocClear').toggleClass('d-none', mode !== 'new');

        if (mode !== 'new') {
            $('#dvVendorCell').removeClass('is-invalid');
            if (mode === 'locked') {
                // Stopka ukryta — nic do rysowania.
            } else if (mode === 'editing') {
                paintDocFoot({
                    text: 'Edycja dokumentu — zmiany zapisują się automatycznie. Gdy skończysz, kliknij „Zakończ edycję".',
                    msgIcon: 'bi bi-pencil-square',
                    label: 'Zakończ edycję', btnIcon: 'bi bi-check2', btnClass: 'btn-success',
                    disabled: headerFinishing
                });
            } else {
                // closed / wait: pasek ukryty, przycisk bez akcji.
                paintDocFoot({
                    text: '', msgIcon: 'bi bi-info-circle',
                    label: 'Edytuj dokument dostawcy', btnIcon: 'bi bi-pencil', btnClass: 'btn-outline-primary',
                    disabled: true
                });
            }
            return;
        }

        const miss = docGateMissing();
        const lack = {};
        miss.forEach(function (m) { lack[m.key] = true; });
        const showErr = S.docAttempted;

        $('#dvVendorCell').toggleClass('is-invalid', showErr && !!lack.vendor);
        $docNumber.toggleClass('is-invalid', showErr && !!lack.doc);
        $('#dvMagazineCell').toggleClass('is-invalid', showErr && !!lack.mag);

        let text;
        let icon;
        if (miss.length === 0) {
            text = 'Wszystko gotowe — zapisz dokument, aby odblokować wpisywanie pozycji.';
            icon = 'bi bi-check2-circle';
        } else {
            text = 'Do zapisania brakuje: ' + miss.map(function (m) { return m.label; }).join(', ') + '.';
            icon = showErr ? 'bi bi-exclamation-triangle-fill' : 'bi bi-info-circle';
        }
        paintDocFoot({
            text: text, msgIcon: icon, error: showErr && miss.length > 0,
            label: S.creating ? 'Zapisywanie…' : 'Zapisz dokument dostawcy',
            btnIcon: 'bi bi-save2', btnClass: 'btn-primary',
            disabled: miss.length > 0 || !!S.creating
        });
    }

    // Wyzerowanie formularza dokumentu dostawcy. Tylko przed założeniem
    // szkicu (mode „new") — wtedy nic nie jest jeszcze zapisane, więc nie ma
    // czego stracić. Kasuje wpisane pola i wraca do stanu początkowego:
    // pusty dostawca, puste numery, dziś jako daty, PLN i magazyn domyślny.
    function clearHeaderForm() {
        if (S.deliveryId || S.creating) return;
        // setVendor(0) czyści picker dostawcy, panel zamówień i wiersz dodawania.
        setVendor(0, false);
        $docNumber.val('');
        $docDate.val(TODAY);
        $recvAt.val(TODAY);
        $comment.val('');
        $currency.val('PLN');
        $('#dvPriceCur').text('PLN');
        setPickerVal($magazine, userDefaultMagazine(), true);
        // Komunikat o brakach wraca do neutralnego (bez czerwonego błędu).
        S.docAttempted = false;
        refreshDocGate();
    }

    function submitDocument() {
        if (S.deliveryId || S.creating) return;
        S.docAttempted = true;
        const miss = docGateMissing();
        refreshDocGate();
        if (miss.length) {
            // Informacja zwrotna: potrząśnięcie przyciskiem + fokus na pierwszym braku.
            const $w = $('#dvDocSubmitWrap').removeClass('is-shake');
            void $w[0].offsetWidth; // restart animacji
            $w.addClass('is-shake');
            setTimeout(function () { $w.removeClass('is-shake'); }, 400);
            const first = miss[0].key;
            if (first === 'doc') $docNumber.trigger('focus');
            else if (hasPicker()) (first === 'vendor' ? $vendor : $magazine).selectpicker('toggle');
            return;
        }

        $('#dvDocSubmit').prop('disabled', true);
        $('#dvDocSubmitLabel').text('Zapisywanie…');
        ensureDraft().then(function () {
            // Szkic istnieje → render() odsłonił wiersz wpisywania.
            focusArt();
        }).catch(function (err) {
            showError(err.message);
        }).then(function () {
            // Etykieta i działanie przycisku wynikają z trybu: po sukcesie
            // to „Edytuj dokument dostawcy", po błędzie z powrotem „Zapisz…".
            refreshDocGate();
        });
    }

    // Jeden przycisk, trzy działania — wg trybu nagłówka. Wrapper łapie też
    // kliknięcie w wyłączony przycisk (przy brakach: potrząśnięcie + fokus).
    $('#dvDocSubmitWrap').on('click', function () {
        const mode = headerMode();
        if (mode === 'new') submitDocument();
        else if (mode === 'locked') startHeaderEdit();
        else if (mode === 'editing') finishHeaderEdit();
    });

    // „Edytuj" w nagłówku karty — to samo działanie co dawny przycisk w stopce.
    $('#dvDocEditBtn').on('click', function () { startHeaderEdit(); });

    // „Wyczyść" — zeruje formularz (tylko przed założeniem szkicu).
    $('#dvDocClear').on('click', function () { clearHeaderForm(); });

    $docNumber.on('input', function () {
        refreshDocGate();
        scheduleHeaderSave(false);
    });
    $docNumber.on('blur', function () {
        if (S.deliveryId) scheduleHeaderSave(true);
    });
    $docNumber.on('keydown', function (e) {
        if (e.key === 'Enter' && !S.deliveryId) { e.preventDefault(); submitDocument(); }
    });
    $comment.on('input', function () { scheduleHeaderSave(false); });
    $comment.on('blur', function () { scheduleHeaderSave(true); });
    $docDate.on('change', function () { scheduleHeaderSave(true); });
    $recvAt.on('change', function () {
        // „Data przyjęcia" zawsze ma wartość — wyczyszczone pole wraca do dziś.
        if ($recvAt.val() === '') $recvAt.val(TODAY);
        scheduleHeaderSave(true);
    });
    $currency.on('change', function () {
        $('#dvPriceCur').text($currency.val());
        scheduleHeaderSave(true);
    });
    $magazine.on('changed.bs.select', function () {
        refreshDocGate();
        // Picker pozycji podąża za magazynem domyślnym dostawy.
        fillEntryMag();
        if (S.mute) return;
        scheduleHeaderSave(true);
    });
    $('#dvDocType').on('click', 'button', function () {
        if ($(this).prop('disabled')) return;
        setDocType($(this).attr('data-type'));
        scheduleHeaderSave(true);
    });

    // =====================================================================
    // Dostawcy
    // =====================================================================

    function ensureVendorOption(id, name) {
        const sel = '#dvVendor option[value="' + id + '"]';
        if ($(sel).length === 0) {
            $vendor.append('<option value="' + esc(id) + '">' + esc(name || ('Dostawca #' + id)) + '</option>');
            if (hasPicker()) $vendor.selectpicker('refresh');
        }
    }

    // Lista opcji dostawców. Liczba otwartych zamówień jest plakietką w treści
    // pozycji listy (data-content — bootstrap-select wstawia go jako HTML),
    // a tekst opcji zostaje samą nazwą, więc przycisk pickera i wyszukiwarka
    // widzą czystą nazwę. `onlyOpen` ukrywa dostawców bez otwartych zamówień —
    // z wyjątkiem aktualnie wybranego, żeby wartość pickera się nie zepsuła.
    function renderVendorOptions(onlyOpen) {
        const keep = String(S.vendorId || $vendor.val() || '');
        let withPo = '';
        let others = '';
        (S.vendors || []).forEach(function (v) {
            const n = num(v.open_po_count);
            if (n > 0) {
                // Plakietka: sama liczba (pogrubiona), słowo „zamówień" obok, przygaszone.
                const poWord = plural(n, 'zamówienie', 'zamówienia', 'zamówień');
                const content = esc(v.name) + ' <span class="dv-opt-badge" title="' + esc(n + ' ' + plural(n, 'otwarte zamówienie', 'otwarte zamówienia', 'otwartych zamówień')) + '">' + n + '</span>'
                              + ' <span class="dv-opt-unit">' + esc(poWord) + '</span>';
                withPo += '<option value="' + esc(v.id) + '" data-content="' + esc(content) + '">' + esc(v.name) + '</option>';
            } else if (!onlyOpen || String(v.id) === keep) {
                others += '<option value="' + esc(v.id) + '">' + esc(v.name) + '</option>';
            }
        });
        let html = '';
        if (withPo) html += '<optgroup label="Z otwartymi zamówieniami">' + withPo + '</optgroup>';
        if (others) html += '<optgroup label="Pozostali dostawcy">' + others + '</optgroup>';
        $vendor.html(html);
        if (hasPicker()) $vendor.selectpicker('refresh');
        // Odtwórz wybór (przebudowa opcji go zeruje); force — patrz setPickerVal().
        if (hasPicker()) setPickerVal($vendor, keep, true); else $vendor.val(keep);
    }

    function buildVendorPicker(vendors) {
        S.vendors = vendors;
        S.vendorMap = {};
        vendors.forEach(function (v) { S.vendorMap[v.id] = v; });
        S.vendorsLoaded = true;
        renderVendorOptions($('#dvVendorOnlyOpen').prop('checked'));
        // Lista już jest — można otwierać picker.
        applyVendorPickerState();
    }

    // Picker dostawcy: zablokowany, dopóki nie przyjdzie lista (delivery-vendors.php)
    // — inaczej operator otwiera pustą listę. Po założeniu szkicu zablokowany
    // jest zawsze (dostawcy nie zmieniamy).
    function applyVendorPickerState() {
        const loading = !S.vendorsLoaded;
        setPickerDisabled($vendor, !!S.deliveryId || loading);
        $('#dvVendorCell').toggleClass('is-loading', loading);
    }

    // bootstrap-select 1.13 ignoruje atrybut disabled i mimo to otwiera listę
    // (a w środku rzuca wyjątek) — dlatego klik w przycisk łapiemy w fazie
    // przechwytywania, zanim handler plugina zdąży zareagować.
    $('#dvVendorCell')[0].addEventListener('click', function (e) {
        if (S.vendorsLoaded) return;
        e.preventDefault();
        e.stopPropagation();
    }, true);

    // Przełącznik „Tylko z otwartymi zamówieniami" — filtr samej listy, nic nie
    // zapisuje ani nie wysyła. Dotyczy tylko nowego przyjęcia (bez szkicu).
    $('#dvVendorOnlyOpen').on('change', function () {
        if (S.deliveryId) return;
        renderVendorOptions(this.checked);
    });

    function updateVendorHint() {
        const v = S.vendorMap[S.vendorId];
        const $h = $('#dvVendorHint');
        // Filtr listy ma sens tylko przy nowym przyjęciu; dla szkicu wyszarzony.
        $('#dvVendorOnlyOpen').prop('disabled', !!S.deliveryId).closest('.dv-switch').toggleClass('is-disabled', !!S.deliveryId);
        if (!v || S.deliveryId) { $h.empty(); return; }
        const parts = [];
        if (num(v.open_po_count) === 0) {
            parts.push('<span class="dv-hint--warn"><i class="bi bi-info-circle"></i> Brak otwartych zamówień — pozycje trafią jako nadwyżka.</span>');
        }
        if (num(v.draft_count) > 0) {
            parts.push('<span class="dv-hint--warn"><i class="bi bi-exclamation-triangle-fill"></i> Ma już ' + num(v.draft_count)
                + ' ' + plural(num(v.draft_count), 'robocze przyjęcie', 'robocze przyjęcia', 'roboczych przyjęć')
                + ' — <a href="' + esc(LIST_URL + '#robocze') + '">zobacz</a></span>');
        }
        $h.html(parts.join('<br>'));
    }

    function setVendor(id, userPicked) {
        id = parseInt(id, 10) || 0;
        S.vendorId = id;
        $page.toggleClass('dv-no-vendor', id === 0);
        if (hasPicker() && String($vendor.val()) !== String(id || '')) setPickerVal($vendor, id || '');

        const v = S.vendorMap[id];
        if (userPicked && v && v.default_currency_code && !S.deliveryId) {
            ensureCurrencyOption(v.default_currency_code);
            $currency.val(v.default_currency_code);
            $('#dvPriceCur').text(v.default_currency_code);
        }
        updateVendorHint();
        $('#dvTitle').text(v ? ('Przyjęcie od: ' + v.name) : 'Nowe przyjęcie dostawy');
        resetEntry();
        loadOpenItems(id);
        if (id) $('#dvDocNumber').trigger('focus');
        renderAll();
    }

    $vendor.on('changed.bs.select', function () {
        if (S.mute || S.deliveryId) return;
        setVendor($vendor.val(), true);
    });

    // =====================================================================
    // Otwarte pozycje zamówień (panel po prawej + podpowiedzi)
    // =====================================================================

    function loadOpenItems(vendorId) {
        S.openItems = [];
        S.openLoadedFor = 0;
        if (!vendorId) { renderSide(); return Promise.resolve(); }
        $('#dvSideBody').html('<div class="dv-side-empty"><span class="spinner-border spinner-border-sm"></span> Ładowanie zamówień…</div>');
        return call('delivery-open-items.php', { vendor_id: vendorId }).then(function (r) {
            if (vendorId !== S.vendorId) return;
            S.openItems = (r.items || []).map(function (it) {
                it.po_item_id = parseInt(it.po_item_id, 10);
                it.po_id = parseInt(it.po_id, 10);
                it.vendor_part_id = parseInt(it.vendor_part_id, 10);
                it.remaining = num(it.remaining);
                return it;
            });
            S.openLoadedFor = vendorId;
            renderSide();
            renderLines();
        }).catch(function (err) {
            $('#dvSideBody').html('<div class="dv-side-empty text-danger">' + esc(err.message) + '</div>');
        });
    }

    function itemsForPart(vpId) {
        return S.openItems.filter(function (i) { return i.vendor_part_id === vpId; });
    }

    function itemById(id) {
        for (let i = 0; i < S.openItems.length; i++) {
            if (S.openItems[i].po_item_id === id) return S.openItems[i];
        }
        return null;
    }

    // po_item_id => ile przydziela ta dostawa (wszystkie pozycje).
    function allocatedByItem(st, exceptLineId) {
        const map = {};
        ((st && st.lines) || []).forEach(function (l) {
            if (exceptLineId && l.id === exceptLineId) return;
            (l.allocations || []).forEach(function (a) {
                const k = parseInt(a.po_item_id, 10);
                map[k] = (map[k] || 0) + num(a.quantity);
            });
        });
        return map;
    }

    function renderSide() {
        const st = S.state;
        const mode = st ? st.delivery.state : 'draft';
        if (mode !== 'draft') { renderSideSummary(st); return; }

        $('#dvSideTitleText').text('Otwarte zamówienia');
        // Licznik w nagłówku — ta sama liczba, co plakietka w pickerze dostawcy
        // (open_po_count). Bez wybranego dostawcy licznika nie ma.
        const vs = S.vendorMap[S.vendorId];
        $('#dvSideCount').text(vs ? num(vs.open_po_count) : 0)
            .toggleClass('d-none', !S.vendorId);
        const $body = $('#dvSideBody');
        if (!S.vendorId) {
            $body.html('<div class="dv-side-empty">Wybierz dostawcę, aby zobaczyć jego otwarte zamówienia.</div>');
            return;
        }
        if (S.openLoadedFor !== S.vendorId) return;
        if (S.openItems.length === 0) {
            $body.html('<div class="dv-side-empty"><i class="bi bi-inbox"></i><br>Ten dostawca nie ma otwartych zamówień.<br>Pozycje z dokumentu zostaną przyjęte jako nadwyżka.</div>');
            return;
        }

        const touched = allocatedByItem(st, 0);
        const q = $.trim($('#dvSideSearch').val()).toLowerCase();

        const groups = {};
        const order = [];
        S.openItems.forEach(function (it) {
            if (!groups[it.po_id]) {
                groups[it.po_id] = { po_id: it.po_id, po_number: it.po_number, po_state: it.po_state, due: it.due_date, items: [], touched: 0 };
                order.push(it.po_id);
            }
            const g = groups[it.po_id];
            g.items.push(it);
            if (touched[it.po_item_id]) g.touched++;
            if (it.due_date && (!g.due || it.due_date < g.due)) g.due = it.due_date;
        });

        const list = order.map(function (id) { return groups[id]; });
        list.sort(function (a, b) {
            if ((b.touched > 0) !== (a.touched > 0)) return b.touched > 0 ? 1 : -1;
            return 0; // reszta: kolejność z serwera (termin, potem data PO)
        });

        let html = '';
        let shown = 0;
        const today = todayIso();
        list.forEach(function (g) {
            const items = g.items.filter(function (it) {
                if (!q) return true;
                return (String(g.po_number || '') + ' ' + String(it.vendor_part_no || '') + ' ' + String(it.producer_part_no || '') + ' ' + String(it.part_name || ''))
                    .toLowerCase().indexOf(q) !== -1;
            });
            if (items.length === 0) return;
            shown++;
            const late = g.due && String(g.due).substring(0, 10) < today;
            const dueHtml = g.due
                ? '<span class="' + (late ? 'is-late' : '') + '">' + (late ? 'po terminie ' : 'dostawa ') + esc(fmtDate(g.due)) + '</span>'
                : 'bez terminu';
            html += '<div class="dv-po' + (g.touched ? ' is-touched' : '') + '">'
                  + '<div class="dv-po-head">'
                  +   '<a class="dv-po-no" href="' + esc(poEditUrl(g.po_id)) + '" target="_blank" rel="noopener" title="Otwórz zamówienie">' + esc(g.po_number || ('#' + g.po_id)) + '</a>'
                  +   '<span class="dv-po-meta">' + dueHtml
                  +     (g.touched ? ' · <span class="dv-po-hit" title="Pozycje tego zamówienia w tej dostawie">' + g.touched + '/' + g.items.length + '</span>' : '')
                  +   '</span>'
                  + '</div>';
            items.forEach(function (it) {
                const alloc = touched[it.po_item_id] || 0;
                const after = Math.max(0, it.remaining - alloc);
                let remHtml;
                if (alloc > EPS) {
                    remHtml = '<b>' + esc(fmtQty(it.remaining)) + '</b><span class="dv-poi-arrow">→</span>'
                            + '<span class="dv-poi-after ' + (after <= EPS ? 'is-zero' : 'is-left') + '">' + esc(fmtQty(after)) + '</span>';
                } else {
                    remHtml = '<b>' + esc(fmtQty(it.remaining)) + '</b>';
                }
                // Wpisywanie pozycji z panelu działa dopiero po założeniu szkicu.
                const clickable = !!S.deliveryId;
                html += '<div class="dv-poi' + (clickable ? ' is-clickable' : '') + (alloc > EPS ? ' is-touched' : '') + '" data-po-item-id="' + it.po_item_id + '"'
                      + (clickable ? ' title="Kliknij, aby wpisać tę pozycję"' : '') + '>'
                      +   '<div class="dv-poi-no">' + esc(it.vendor_part_no || '—') + '</div>'
                      +   '<div class="dv-poi-rem" title="pozostało przed → po tej dostawie">' + remHtml + '</div>'
                      +   '<div class="dv-poi-name" title="' + esc(it.part_name || '') + '">' + esc(it.part_name || '') + '</div>'
                      + '</div>';
            });
            html += '</div>';
        });

        // Ilości przyjmowane poza zamówieniami.
        let outside = 0;
        let outsideLines = 0;
        ((st && st.lines) || []).forEach(function (l) {
            if (num(l.surplus) > EPS) { outside += num(l.surplus); outsideLines++; }
        });
        if (outsideLines > 0) {
            html += '<div class="dv-side-foot"><i class="bi bi-box-arrow-in-down"></i> Poza zamówieniami: <strong>'
                  + outsideLines + '</strong> ' + plural(outsideLines, 'pozycja', 'pozycje', 'pozycji')
                  + ' · <strong>' + esc(fmtQty(outside)) + '</strong> szt. (nadwyżka)</div>';
        }

        if (shown === 0) html = '<div class="dv-side-empty">Brak wyników dla filtra.</div>' + html;
        $('#dvSideBody').html(html);
    }

    $('#dvSideSearch').on('input', renderSide);

    $('#dvSideBody').on('click', '.dv-poi.is-clickable', function () {
        if (!S.state || S.state.delivery.state !== 'draft') return;
        const it = itemById(parseInt($(this).attr('data-po-item-id'), 10));
        if (!it) return;
        const already = allocatedByItem(S.state, 0)[it.po_item_id] || 0;
        const left = it.remaining - already;
        pickPart({
            vendor_part_id: it.vendor_part_id,
            vendor_part_no: it.vendor_part_no,
            producer_part_no: it.producer_part_no,
            part_name: it.part_name,
            suggested_price: it.unit_price,
            on_open_po: true
        });
        $('#dvQty').val(inputNum(left > EPS ? left : it.remaining)).trigger('focus').trigger('select');
    });

    // Tryb odczytu: podsumowanie zamówień dotkniętych dostawą.
    function renderSideSummary(st) {
        $('#dvSideTitleText').text('Zamówienia w tej dostawie');
        const pos = (st && st.pos) || [];
        // Licznik: w zatwierdzonym przyjęciu to liczba zamówień tej dostawy.
        $('#dvSideCount').text(pos.length).removeClass('d-none');
        const $body = $('#dvSideBody');
        if (pos.length === 0) {
            $body.html('<div class="dv-side-empty">Brak powiązanych zamówień.</div>');
            return;
        }
        const posted = st.delivery.state === 'posted';
        let html = '';
        pos.forEach(function (p) {
            const ordered = num(p.ordered);
            const received = num(p.received);
            const pct = ordered > 0 ? Math.max(0, Math.min(100, received / ordered * 100)) : 0;
            const canClose = posted && ['confirmed', 'in_progress', 'partially_received'].indexOf(p.new_state) !== -1;
            html += '<div class="dv-pos-sum" data-po-id="' + parseInt(p.po_id, 10) + '">'
                  + '<div class="dv-pos-sum-top">'
                  +   '<a class="dv-po-no" href="' + esc(poEditUrl(p.po_id)) + '">' + esc(p.po_number || ('#' + p.po_id)) + '</a>'
                  +   '<span class="js-po-badge">' + stateBadge(p.new_state) + '</span>'
                  + '</div>'
                  + '<div class="rx-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' + Math.round(pct) + '">'
                  +   '<span class="' + (pct >= 100 ? 'is-done' : '') + '" style="width:' + pct.toFixed(1) + '%"></span></div>'
                  + '<div class="rx-sub">przyjęto ' + esc(fmtQty(received)) + ' z ' + esc(fmtQty(ordered)) + '</div>'
                  + missingListHtml(p.missing_lines, 'dv-pos-sum-missing');
            if (canClose) {
                html += '<div class="dv-pos-sum-actions">' + closePoButtonsHtml(p) + '</div>';
            }
            html += '</div>';
        });
        $body.html(html);
    }

    function missingListHtml(lines, cls) {
        lines = lines || [];
        if (lines.length === 0) return '';
        const max = 5;
        let html = '<ul class="' + cls + '">';
        lines.slice(0, max).forEach(function (m) {
            html += '<li><span><span class="n">' + esc(m.vendor_part_no || '—') + '</span></span>'
                  + '<span>brakuje ' + esc(fmtQty(m.remaining)) + '</span></li>';
        });
        if (lines.length > max) {
            html += '<li><span class="text-muted">+ ' + (lines.length - max) + ' więcej</span><span></span></li>';
        }
        return html + '</ul>';
    }

    // =====================================================================
    // Pozycje dostawy
    // =====================================================================

    function flagsHtml(l) {
        const f = l.flags || {};
        let html = '';
        (f.price_mismatch || []).forEach(function (m) {
            const d = m.delta_pct;
            const dTxt = (d === null || d === undefined) ? '' : (' ' + (d > 0 ? '+' : '') + String(d).replace('.', ',') + '%');
            html += '<span class="dv-flag dv-flag--warn" title="' + esc((m.po_number || 'PO') + ': cena w zamówieniu ' + fmtPrice(m.po_price)
                + ', na dokumencie ' + fmtPrice(m.line_price) + '. To tylko ostrzeżenie — przyjęcie można zatwierdzić.') + '">'
                + '<i class="bi bi-currency-exchange"></i> cena ≠ PO' + esc(dTxt) + '</span>';
        });
        if ((f.price_missing_on_po || []).length) {
            html += '<span class="dv-flag dv-flag--info" title="Zamówienie nie ma ceny dla tej pozycji — cena z dokumentu zostanie w nim zapisana.">'
                  + '<i class="bi bi-tag"></i> cena brak w PO</span>';
        }
        if (num(f.surplus) > EPS && !f.not_on_po) {
            html += '<span class="dv-flag dv-flag--violet" title="Ilość ponad otwarte zamówienia — trafi na stan jako nadwyżka. Wymaga komentarza.">'
                  + '<i class="bi bi-plus-circle"></i> nadwyżka ' + esc(fmtQty(f.surplus)) + '</span>';
        }
        if (f.not_on_po) {
            html += '<span class="dv-flag dv-flag--violet" title="Artykuł nie występuje na żadnym otwartym zamówieniu tego dostawcy. Wymaga komentarza.">'
                  + '<i class="bi bi-question-circle"></i> brak w zamówieniach</span>';
        }
        (f.currency_mismatch || []).forEach(function (m) {
            html += '<span class="dv-flag dv-flag--red" title="' + esc((m.po_number || 'PO') + ' jest w ' + m.po_currency
                + ', dokument w ' + m.doc_currency + '. Zmień walutę dokumentu — inaczej nie da się zatwierdzić.') + '">'
                + '<i class="bi bi-cash-coin"></i> waluta PO: ' + esc(m.po_currency) + '</span>';
        });
        if (f.duplicate_line && f.duplicate_line.length) {
            html += '<span class="dv-flag" title="Ten artykuł występuje na dokumencie więcej niż raz.">'
                  + '<i class="bi bi-files"></i> duplikat</span>';
        }
        return html;
    }

    function allocCellHtml(l, st, ro) {
        const taken = allocatedByItem(st, 0);
        let html = '<div class="dv-allocs">';
        let manual = false;
        const allocs = l.allocations || [];
        allocs.forEach(function (a) {
            if (a.is_manual) manual = true;
            let note = '';
            if (!ro) {
                const it = itemById(parseInt(a.po_item_id, 10));
                if (it) {
                    const after = it.remaining - (taken[it.po_item_id] || 0);
                    note = after <= EPS
                        ? '<span class="dv-alloc-note is-close">zamknie pozycję</span>'
                        : '<span class="dv-alloc-note">zostanie ' + esc(fmtQty(after)) + '</span>';
                }
            }
            html += '<div class="dv-alloc">'
                  + '<a class="dv-alloc-po" href="' + esc(poEditUrl(a.po_id)) + '" target="_blank" rel="noopener">' + esc(a.po_number || ('#' + a.po_id)) + '</a> '
                  + '— <span class="dv-alloc-q">' + esc(fmtQty(a.quantity)) + '</span> ' + note
                  + (a.is_manual ? '<span class="dv-alloc-manual" title="Przydział ustawiony ręcznie"><i class="bi bi-hand-index-thumb"></i> ręcznie</span>' : '')
                  + '</div>';
        });
        const f = l.flags || {};
        if (num(l.surplus) > EPS) {
            html += '<div class="dv-alloc dv-alloc--surplus"><span class="dv-alloc-po">bez zamówienia</span> — <span class="dv-alloc-q">'
                  + esc(fmtQty(l.surplus)) + '</span></div>';
        } else if (allocs.length === 0) {
            html += '<div class="dv-alloc dv-alloc--none">brak przydziału</div>';
        }
        html += '</div>';
        if (!ro) {
            html += '<div class="dv-flags">' + flagsHtml(l);
            if (manual) {
                html += '<button type="button" class="rx-link-btn js-line-auto" data-line-id="' + l.id + '" title="Wróć do automatycznego podziału">'
                      + '<i class="bi bi-arrow-counterclockwise"></i> przywróć automatyczny</button>';
            }
            html += '</div>';
        }
        return html;
    }

    // Magazyn pozycji — zawsze pokazujemy EFEKTYWNY (nadpisanie pozycji albo,
    // gdy go brak, magazyn domyślny dostawy). Nadpisanie jest wyróżnione
    // (plakietka), odziedziczony domyślny jest stonowany. Id magazynu 0 jest
    // prawidłowe — wszędzie hasMag(), nigdy sprawdzenie prawdziwości.
    function lineMagHtml(l, st) {
        const d = st.delivery;
        const own = hasMag(l.sub_magazine_id);
        const effId = hasMag(l.effective_sub_magazine_id)
            ? l.effective_sub_magazine_id
            : (own ? l.sub_magazine_id : d.default_sub_magazine_id);
        let name;
        if (own) {
            name = l.sub_magazine_name || magName(l.sub_magazine_id) || ('#' + l.sub_magazine_id);
        } else {
            name = defaultMagName(st);
            if (name === '' && hasMag(effId)) name = magName(effId) || ('#' + effId);
        }
        if (!hasMag(effId)) {
            return '<span class="dv-mag dv-mag--none" title="Brak magazynu — ustaw magazyn docelowy w dokumencie albo dla tej pozycji.">'
                 + '<i class="bi bi-exclamation-triangle-fill"></i> <span class="dv-mag-name">brak magazynu</span></span>';
        }
        if (own) {
            return '<span class="dv-mag dv-mag--own" title="Magazyn ustawiony dla tej pozycji (inny niż domyślny dla dostawy)">'
                 + '<i class="bi bi-box-seam"></i> <span class="dv-mag-name">' + esc(name) + '</span></span>';
        }
        return '<span class="dv-mag dv-mag--def" title="Magazyn domyślny dostawy">'
             + '<i class="bi bi-box-seam"></i> <span class="dv-mag-name">' + esc(name) + '</span>'
             + '<span class="sr-only"> (domyślny)</span></span>';
    }

    function lineRowsHtml(l, st, ro) {
        const f = l.flags || {};
        const needComment = !!(f.surplus || f.not_on_po);
        const hasBlock = !!(f.currency_mismatch && f.currency_mismatch.length);
        const hasWarn = !!(f.price_mismatch || f.price_missing_on_po || f.duplicate_line);
        const commentTxt = l.comment || '';
        const notesOpen = !ro && (S.openNotes[l.id] || needComment || commentTxt !== '');
        const commentMissing = needComment && $.trim(commentTxt) === '';
        const classes = ['dv-line'];
        if (hasBlock) classes.push('has-block');
        else if (commentMissing) classes.push('needs-comment');
        else if (hasWarn || needComment) classes.push('has-warn');
        if (S.editLineId === l.id) classes.push('is-editing');

        const unit = S.unitByVp[l.vendor_part_id] ? '<span class="rx-unit">' + esc(S.unitByVp[l.vendor_part_id]) + '</span>' : '';
        const magHtml = lineMagHtml(l, st);

        // Kolumna „Magazyn" znika na wąskich ekranach (patrz delivery-ui.css) —
        // wtedy ten sam magazyn pokazuje się pod nazwą artykułu (.dv-mag-inline),
        // więc nigdy nie ginie z widoku.
        let html = '<tr class="' + classes.join(' ') + '" data-line-id="' + l.id + '">'
                 + '<td class="dv-c-no">' + esc(l.line_no) + '</td>'
                 + '<td><span class="dv-art-no">' + esc(l.vendor_part_no || ('vp#' + l.vendor_part_id)) + '</span>'
                 +   (l.producer_part_no && l.producer_part_no !== l.vendor_part_no ? ' <span class="dv-art-sub">' + esc(l.producer_part_no) + '</span>' : '')
                 +   '<div class="dv-art-name">' + esc(l.part_name || '') + '</div>'
                 +   '<div class="dv-mag-inline">' + magHtml + '</div>'
                 + '</td>'
                 + '<td class="dv-c-mag">' + magHtml + '</td>'
                 + '<td class="dv-c-qty"><span class="dv-qty">' + esc(fmtQty(l.quantity)) + '</span>' + unit + '</td>'
                 + '<td class="dv-c-price">' + (l.unit_price === null || l.unit_price === undefined || l.unit_price === ''
                        ? '<span class="dv-price--none">—</span>'
                        : '<span class="dv-price">' + esc(fmtPrice(l.unit_price)) + '</span>') + '</td>'
                 + '<td>' + allocCellHtml(l, st, ro) + '</td>';

        if (ro) {
            html += '<td class="dv-c-act"></td></tr>';
            if (commentTxt !== '') {
                html += '<tr class="dv-note"><td></td><td colspan="6"><div class="dv-ro-note"><i class="bi bi-chat-left-text"></i> ' + esc(commentTxt) + '</div></td></tr>';
            }
            return html;
        }

        html += '<td class="dv-c-act"><div class="dv-actions">'
              + '<button type="button" class="dv-icon-btn js-line-alloc" data-line-id="' + l.id + '" title="Zmień przydział do zamówień" aria-label="Zmień przydział"><i class="bi bi-diagram-3"></i></button>'
              + '<button type="button" class="dv-icon-btn js-line-notes' + (notesOpen ? ' is-on' : '') + (commentMissing ? ' dv-icon-btn--warn' : '') + '" data-line-id="' + l.id + '" title="Komentarz" aria-label="Komentarz"><i class="bi bi-chat-left-text"></i></button>'
              + '<button type="button" class="dv-icon-btn js-line-edit" data-line-id="' + l.id + '" title="Popraw ilość / cenę / artykuł / magazyn" aria-label="Edytuj"><i class="bi bi-pencil"></i></button>'
              + '<button type="button" class="dv-icon-btn dv-icon-btn--danger js-line-del" data-line-id="' + l.id + '" title="Usuń pozycję" aria-label="Usuń"><i class="bi bi-trash"></i></button>'
              + '</div></td></tr>';

        if (notesOpen) {
            html += '<tr class="dv-note' + (commentMissing ? ' is-required' : '') + '" data-line-id="' + l.id + '"><td></td><td colspan="6">'
                  + '<div class="dv-note-box">'
                  +   '<i class="bi bi-chat-left-text"></i>'
                  +   '<input type="text" class="form-control form-control-sm dv-note-input js-line-comment" data-line-id="' + l.id + '" maxlength="500" '
                  +     'value="' + esc(commentTxt) + '" autocomplete="off" '
                  +     'placeholder="' + (needComment ? 'Wymagany komentarz: dlaczego przyjmujemy ponad zamówienie / spoza zamówień?' : 'Komentarz do pozycji (opcjonalnie)') + '">'
                  + '</div></td></tr>';
        }
        return html;
    }

    function renderLines() {
        const st = S.state;
        const lines = (st && st.lines) || [];
        const ro = !!(st && st.delivery.state !== 'draft');
        $('#dvLineCount').text(lines.length);

        // Zachowaj fokus w polach komentarza przy przerysowaniu.
        const act = document.activeElement;
        let keep = null;
        if (act && $(act).hasClass('js-line-comment')) {
            keep = { id: $(act).attr('data-line-id'), s: act.selectionStart, e: act.selectionEnd };
        }

        let html = '';
        lines.forEach(function (l) { html += lineRowsHtml(l, st, ro); });
        $('#dvLinesBody').html(html);
        $('#dvEmptyLines').toggleClass('d-none', lines.length > 0 || ro);
        $('#dvTableWrap').toggleClass('d-none', !S.vendorId && !st);

        if (keep) {
            const $in = $('#dvLinesBody .js-line-comment[data-line-id="' + keep.id + '"]');
            if ($in.length) { $in.trigger('focus'); try { $in[0].setSelectionRange(keep.s, keep.e); } catch (e) { /* ignore */ } }
        }
    }

    function findLine(id) {
        const lines = (S.state && S.state.lines) || [];
        for (let i = 0; i < lines.length; i++) if (lines[i].id === id) return lines[i];
        return null;
    }

    function flashLine(id) {
        const $row = $('#dvLinesBody tr.dv-line[data-line-id="' + id + '"]');
        if (!$row.length) return;
        $row.addClass('is-flash');
        const el = $row[0];
        const r = el.getBoundingClientRect();
        if (r.top < 0 || r.bottom > window.innerHeight - 200) {
            el.scrollIntoView({ block: 'center', behavior: 'smooth' });
        }
    }

    // Zapis pól pozycji (magazyn / komentarz) — API wymaga kompletu pól.
    function saveLineFields(line, patch) {
        const payload = {
            delivery_id: S.deliveryId,
            line_id: line.id,
            vendor_part_id: line.vendor_part_id,
            quantity: line.quantity,
            unit_price: (line.unit_price === null || line.unit_price === undefined) ? '' : line.unit_price,
            sub_magazine_id: magParam(line.sub_magazine_id),
            comment: line.comment || ''
        };
        $.extend(payload, patch);
        return write('delivery-line-save.php', payload).catch(function (err) { showError(err.message); });
    }

    $('#dvLinesBody')
        .on('click', '.js-line-del', function () {
            const id = parseInt($(this).attr('data-line-id'), 10);
            if (S.editLineId === id) resetEntry();
            write('delivery-line-delete.php', { delivery_id: S.deliveryId, line_id: id })
                .catch(function (err) { showError(err.message); });
        })
        .on('click', '.js-line-edit', function () {
            const l = findLine(parseInt($(this).attr('data-line-id'), 10));
            if (l) startEdit(l);
        })
        .on('click', '.js-line-notes', function () {
            const id = parseInt($(this).attr('data-line-id'), 10);
            S.openNotes[id] = !S.openNotes[id];
            renderLines();
            if (S.openNotes[id]) $('#dvLinesBody .js-line-comment[data-line-id="' + id + '"]').trigger('focus');
        })
        .on('click', '.js-line-alloc', function () {
            openAlloc(parseInt($(this).attr('data-line-id'), 10));
        })
        .on('click', '.js-line-auto', function () {
            const id = parseInt($(this).attr('data-line-id'), 10);
            write('delivery-line-allocate.php', { delivery_id: S.deliveryId, line_id: id })
                .catch(function (err) { showError(err.message); });
        })
        .on('change', '.js-line-comment', function () {
            const l = findLine(parseInt($(this).attr('data-line-id'), 10));
            if (!l) return;
            const val = $.trim($(this).val());
            if (val === (l.comment || '')) return;
            saveLineFields(l, { comment: val });
        })
        .on('keydown', '.js-line-comment', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); $(this).trigger('blur'); $('#dvArt').trigger('focus'); }
        });

    // =====================================================================
    // Wiersz wpisywania + wyszukiwanie artykułu
    // =====================================================================

    const $art = $('#dvArt');
    const $qty = $('#dvQty');
    const $price = $('#dvPrice');
    const $entryMag = $('#dvMag');
    const $dd = $('#dvArtList');
    let ddItems = [];
    let ddActive = -1;
    let ddSeq = 0;
    let ddTimer = null;

    function hl(text, q) {
        text = String(text == null ? '' : text);
        if (!q) return esc(text);
        const i = text.toLowerCase().indexOf(q.toLowerCase());
        if (i === -1) return esc(text);
        return esc(text.substring(0, i)) + '<mark>' + esc(text.substring(i, i + q.length)) + '</mark>' + esc(text.substring(i + q.length));
    }

    function ddClose() {
        $dd.removeClass('is-open is-up').empty();
        ddItems = [];
        ddActive = -1;
        $art.attr('aria-expanded', 'false');
    }

    // Nazwa dostawcy bieżącego przyjęcia: ze stanu dostawy, a zapasowo z
    // tekstu wybranej opcji w #dvVendor. '' = nie uda się ustalić.
    function currentVendorName() {
        const d = S.state && S.state.delivery;
        if (d && d.vendor_name) return String(d.vendor_name);
        const sel = document.getElementById('dvVendor');
        if (sel && sel.selectedIndex >= 0 && sel.value !== '') {
            const opt = sel.options[sel.selectedIndex];
            return opt ? String(opt.text || '') : '';
        }
        return '';
    }

    // Czy podana nazwa (np. producenta) to ten sam podmiot co dostawca?
    // Porównanie po trimie, bez rozróżniania wielkości liter. Gdy nie znamy
    // nazwy dostawcy lub nazwy do porównania — false (lepiej pokazać).
    function sameAsVendor(name) {
        const a = $.trim(name || '').toLowerCase();
        const b = $.trim(currentVendorName()).toLowerCase();
        return a !== '' && b !== '' && a === b;
    }

    function ddOpen(items, head, q) {
        ddItems = items;
        ddActive = items.length ? 0 : -1;
        let html = head ? '<div class="dv-dd-head">' + esc(head) + '</div>' : '';
        if (!items.length) {
            html += '<div class="dv-dd-empty">Nie znaleziono artykułu w katalogu tego dostawcy.</div>';
        }
        items.forEach(function (r, i) {
            // Druga linia z fragmentów łączonych dopiero, gdy są niepuste
            // (producent równy dostawcy jest pomijany — bez wiszących kropek).
            const subParts = [r.producer_part_no ? hl(r.producer_part_no, q) : '—'];
            if (r.producer_name && !sameAsVendor(r.producer_name)) subParts.push(esc(r.producer_name));
            if (r.suggested_price !== null && r.suggested_price !== undefined && r.suggested_price !== '') {
                subParts.push(esc(fmtPrice(r.suggested_price)) + ' ' + esc(r.suggested_currency || ''));
            }
            const side = r.on_open_po
                ? '<span class="dv-dd-po" title="Pozostało na otwartych zamówieniach">na PO: ' + esc(fmtQty(r.open_remaining)) + '</span>'
                : '<span class="dv-dd-nopo">brak w PO</span>';
            html += '<div class="dv-dd-item' + (i === 0 ? ' is-active' : '') + '" role="option" data-idx="' + i + '">'
                  + '<div class="dv-dd-name">' + hl(r.vendor_part_no, q) + '<span class="dv-dd-sep" aria-hidden="true">|</span>' + hl(r.part_name, q) + '</div>'
                  + '<div class="dv-dd-sub">' + subParts.join(' · ') + '</div>'
                  + (r.part_description ? '<div class="dv-dd-desc" title="' + esc(r.part_description) + '">' + esc(r.part_description) + '</div>' : '')
                  + '<div class="dv-dd-side">' + side + '</div>'
                  + '</div>';
        });
        $dd.html(html);

        // Kierunek: w dół, a gdy brak miejsca (pasek akcji zasłania dół
        // okna) — w górę. Wysokość listy dopasowana do dostępnego miejsca.
        const rect = $art[0].getBoundingClientRect();
        const bar = document.getElementById('dvBar');
        const floor = bar ? Math.min(window.innerHeight, bar.getBoundingClientRect().top) : window.innerHeight;
        const below = floor - rect.bottom - 8;
        const above = rect.top - 8;
        const up = below < 260 && above > below;
        $dd.toggleClass('is-up', up);
        $dd.css('max-height', Math.max(150, Math.min(304, (up ? above : below) - 6)) + 'px');
        $dd.addClass('is-open');
        $art.attr('aria-expanded', 'true');
    }

    function ddMove(delta) {
        if (!ddItems.length) return;
        ddActive = (ddActive + delta + ddItems.length) % ddItems.length;
        $dd.find('.dv-dd-item').removeClass('is-active').eq(ddActive).addClass('is-active');
        const el = $dd.find('.dv-dd-item')[ddActive];
        if (el && el.scrollIntoView) el.scrollIntoView({ block: 'nearest' });
    }

    // Bez wpisanej frazy: artykuły z otwartych zamówień (lokalnie).
    function showOpenParts() {
        if (!S.vendorId || S.openLoadedFor !== S.vendorId) return;
        const seen = {};
        const out = [];
        S.openItems.forEach(function (it) {
            if (seen[it.vendor_part_id]) { seen[it.vendor_part_id].open_remaining += it.remaining; return; }
            const r = {
                vendor_part_id: it.vendor_part_id,
                vendor_part_no: it.vendor_part_no,
                producer_part_no: it.producer_part_no,
                part_name: it.part_name,
                part_description: it.part_description || '',
                producer_name: '',
                on_open_po: true,
                open_remaining: it.remaining,
                suggested_price: it.unit_price,
                suggested_currency: it.currency
            };
            seen[it.vendor_part_id] = r;
            out.push(r);
        });
        if (out.length === 0) { ddClose(); return; }
        ddOpen(out.slice(0, 40), 'Z otwartych zamówień tego dostawcy', '');
    }

    function runSearch() {
        const q = $.trim($art.val());
        if (q === '') { showOpenParts(); return; }
        const seq = ++ddSeq;
        call('delivery-part-search.php', { vendor_id: S.vendorId, q: q }).then(function (r) {
            if (seq !== ddSeq || $.trim($art.val()) !== q) return;
            const res = r.results || [];
            res.forEach(function (x) { if (x.unit) S.unitByVp[x.vendor_part_id] = x.unit; });
            ddOpen(res, res.length ? 'Katalog dostawcy' : '', q);
        }).catch(function (err) {
            if (seq !== ddSeq) return;
            $dd.html('<div class="dv-dd-empty text-danger">' + esc(err.message) + '</div>').addClass('is-open');
        });
    }

    // Opis części pod polem artykułu — widoczny tylko przy wybranym artykule
    // i tylko gdy opis nie jest pusty (wstawiany jako tekst).
    function setArtDesc(desc) {
        const d = $.trim(desc || '');
        const $d = $('#dvArtDesc');
        if (d === '') { $d.addClass('d-none').text('').removeAttr('title'); return; }
        $d.removeClass('d-none').text(d).attr('title', d);
    }

    function pickPart(r) {
        S.picked = r;
        if (r.unit) S.unitByVp[r.vendor_part_id] = r.unit;
        $art.val((r.vendor_part_no || '') + ' — ' + (r.part_name || ''));
        $('.dv-art-wrap').addClass('is-picked');
        setArtDesc(r.part_description);
        $('#dvArtClear').removeClass('d-none');
        ddClose();
        const p = r.suggested_price;
        if (p !== null && p !== undefined && p !== '' && !isNaN(parseFloat(p)) && $.trim($price.val()) === '') {
            $price.val(inputNum(p));
        }
        entryError('');
        updateEntryHint();
    }

    function unpick() {
        S.picked = null;
        $('.dv-art-wrap').removeClass('is-picked');
        setArtDesc('');
        $('#dvArtClear').addClass('d-none');
        updateEntryHint();
    }

    function updateEntryHint() {
        const $h = $('#dvEntryHint');
        $h.removeClass('is-onpo is-nopo');
        if (!S.picked) {
            $h.html('<kbd>Tab</kbd> przechodzi dalej · <kbd>Enter</kbd> w polu ceny zapisuje pozycję · <kbd>↓</kbd><kbd>↑</kbd> wybierają z listy');
            return;
        }
        const items = itemsForPart(S.picked.vendor_part_id);
        if (items.length === 0) {
            $h.addClass('is-nopo').html('<i class="bi bi-question-circle"></i> Brak na otwartych zamówieniach — pozycja trafi jako nadwyżka (wymaga komentarza).');
            return;
        }
        const taken = allocatedByItem(S.state, S.editLineId);
        $h.addClass('is-onpo').html('<i class="bi bi-check2-circle"></i> Na zamówieniach: ' + items.map(function (it) {
            const left = it.remaining - (taken[it.po_item_id] || 0);
            return esc(it.po_number || ('#' + it.po_id)) + ' · ' + esc(fmtQty(left)) + (it.unit_price !== null && it.unit_price !== undefined && it.unit_price !== ''
                ? ' po ' + esc(fmtPrice(it.unit_price)) : '');
        }).join('  |  '));
    }

    function entryError(msg) {
        const $e = $('#dvEntryError');
        if (!msg) { $e.addClass('d-none').empty(); return; }
        $e.removeClass('d-none').text(msg);
    }

    // Czy operator właśnie korzysta z pickera magazynu pozycji? Fokus bywa:
    //  - na ukrytym <select> (bez pickera),
    //  - na przycisku pickera (wewnątrz .bootstrap-select),
    //  - w polu wyszukiwania otwartej listy. Uwaga: przy data-container menu
    //    (a z nim pole wyszukiwania) jest przenoszone do .bs-container w
    //    #dvEntryMagCell — to RODZEŃSTWO .bootstrap-select, nie jego dziecko,
    //    więc samo sprawdzenie .bootstrap-select nie wystarcza.
    function entryMagBusy() {
        const act = document.activeElement;
        if (act && $entryMag.is(act)) return true;
        const $wrap = $entryMag.closest('.bootstrap-select');
        if ($wrap.length && ($wrap.hasClass('show') || (act && $wrap[0].contains(act)))) return true;
        const cell = document.getElementById('dvEntryMagCell');
        return !!(act && cell && cell.contains(act));
    }

    // Magazyn domyślny dostawy jako tekst ('' = dostawa go nie ma). Bierzemy
    // wartość z pola „Magazyn docelowy" (bieżący wybór, także jeszcze nie
    // zapisany), a w razie braku — z ostatniego stanu serwera. Id „0" jest
    // prawidłowe, dlatego wszędzie hasMag(), nigdy sprawdzenie prawdziwości.
    function currentDefaultMag() {
        const hv = $magazine.val();
        if (hasMag(hv)) return String(hv);
        const d = S.state && S.state.delivery;
        return (d && hasMag(d.default_sub_magazine_id)) ? String(d.default_sub_magazine_id) : '';
    }

    // Magazyn pozycji do zapisu: '' = pozycja dziedziczy magazyn domyślny
    // dostawy (w bazie NULL — idzie za zmianą „Magazynu docelowego"),
    // inna wartość = konkretny magazyn (nadpisanie). Wybór równy domyślnemu
    // NIE staje się nadpisaniem.
    function entryMagParam() {
        const v = $entryMag.val();
        if (!hasMag(v)) return '';
        return String(v) === currentDefaultMag() ? '' : String(v);
    }

    // Lista magazynów w pasku wpisywania (selectpicker z wyszukiwarką).
    // Zaznaczony jest zawsze prawdziwy magazyn: domyślny dostawy dla nowej
    // pozycji i pozycji dziedziczącej, własny dla pozycji z nadpisaniem.
    // `selected` (tekst z .val() albo liczba z JSON-a): '' = magazyn domyślny
    // dostawy, inna wartość = ten magazyn. Gdy pominięty, zostaje bieżący wybór
    // operatora — a jeśli był nim dotychczasowy magazyn domyślny, picker idzie
    // za nowym domyślnym.
    function fillEntryMag(selected) {
        // Przerysowanie z serwera nie może wytrącić operatora, który właśnie
        // wybiera magazyn lub wpisuje frazę w wyszukiwarkę — wtedy nic nie
        // ruszamy (przebudowa zgasiłaby listę i skasowała wpisaną frazę).
        // Jawne ustawienie (reset / edycja pozycji) przebudowuje zawsze.
        if (selected === undefined && entryMagBusy()) return;
        const def = currentDefaultMag();
        let pick;
        if (selected === undefined) {
            const cur = $entryMag.val();
            pick = (!hasMag(cur) || String(cur) === S.entryMagDefault) ? def : String(cur);
        } else {
            pick = hasMag(selected) ? String(selected) : def;
        }
        S.entryMagDefault = def;

        // Nazwa dla magazynu spoza listy aktywnych: własny magazyn edytowanej
        // pozycji albo (praktycznie niemożliwy) nieaktywny magazyn domyślny.
        const ln = S.editLineId ? findLine(S.editLineId) : null;
        let orphanName = '';
        if (ln && hasMag(ln.sub_magazine_id) && String(ln.sub_magazine_id) === pick) {
            orphanName = ln.sub_magazine_name || '';
        } else if (pick === def) {
            orphanName = defaultMagName(S.state);
        }

        // Wybór niesie atrybut `selected` w opcjach (magOptionsHtml) — dzięki
        // temu także id 0 jest zaznaczone zaraz po refresh.
        $entryMag.html(magOptionsHtml(pick, orphanName));
        if (hasPicker()) {
            S.mute++;
            try { $entryMag.selectpicker('refresh'); } finally { S.mute--; }
        }
        // `force` — refresh() renderuje przycisk, zanim buildData() nada opcjom
        // `liIndex`, więc sam refresh pokazałby placeholder zamiast wybranego
        // magazynu. selectpicker('val', …) po refresh renderuje przycisk
        // poprawnie (tak jak „Magazyn docelowy" w nagłówku).
        setPickerVal($entryMag, pick, true);
    }

    function resetEntry() {
        S.editLineId = 0;
        S.picked = null;
        $art.val('');
        $qty.val('');
        $price.val('');
        fillEntryMag('');
        $('.dv-art-wrap').removeClass('is-picked');
        setArtDesc('');
        $('#dvArtClear').addClass('d-none');
        $('#dvEntry').removeClass('is-editing');
        $('#dvEntryTag').text('Nowa pozycja');
        $('#dvEntrySaveLabel').text('Dodaj');
        $('#dvEntrySave i').attr('class', 'bi bi-plus-lg');
        $('#dvEntryCancel').addClass('d-none');
        $('#dvLinesBody tr.is-editing').removeClass('is-editing');
        ddClose();
        entryError('');
        updateEntryHint();
    }

    function startEdit(l) {
        resetEntry();
        S.editLineId = l.id;
        S.picked = {
            vendor_part_id: l.vendor_part_id,
            vendor_part_no: l.vendor_part_no,
            producer_part_no: l.producer_part_no,
            part_name: l.part_name,
            part_description: l.part_description || ''
        };
        $art.val((l.vendor_part_no || '') + ' — ' + (l.part_name || ''));
        $('.dv-art-wrap').addClass('is-picked');
        setArtDesc(l.part_description);
        $('#dvArtClear').removeClass('d-none');
        $qty.val(inputNum(l.quantity));
        $price.val(l.unit_price === null || l.unit_price === undefined ? '' : inputNum(l.unit_price));
        // Magazyn: nadpisanie pozycji (w tym 0) albo magazyn domyślny dostawy,
        // gdy pozycja dziedziczy — zapis wyśle wtedy '' (entryMagParam), więc
        // zwykła poprawka ilości/ceny nie zamienia odziedziczonego magazynu
        // w nadpisanie.
        fillEntryMag(hasMag(l.sub_magazine_id) ? String(l.sub_magazine_id) : '');
        $('#dvEntry').addClass('is-editing');
        $('#dvEntryTag').text('Edycja pozycji ' + l.line_no);
        $('#dvEntrySaveLabel').text('Zapisz');
        $('#dvEntrySave i').attr('class', 'bi bi-check2');
        $('#dvEntryCancel').removeClass('d-none');
        $('#dvLinesBody tr.dv-line[data-line-id="' + l.id + '"]').addClass('is-editing');
        updateEntryHint();
        $qty.trigger('focus').trigger('select');
    }

    $art.on('input', function () {
        if (S.picked) unpick();
        clearTimeout(ddTimer);
        ddTimer = setTimeout(runSearch, $.trim($art.val()) === '' ? 0 : 180);
    });
    // Fokus nadany z kodu (po zapisie pozycji, Esc) nie otwiera listy —
    // inaczej zasłaniałaby dopiero co dodany wiersz. Lista otwiera się po
    // kliknięciu w pole, wpisaniu znaku albo strzałce w dół.
    let quietFocus = false;
    function focusArt() {
        quietFocus = true;
        $art.trigger('focus');
        quietFocus = false;
    }
    $art.on('focus', function () {
        if (quietFocus) return;
        if (!S.picked && $.trim($art.val()) === '') showOpenParts();
    });
    $art.on('mousedown', function () {
        if (!S.picked && $.trim($art.val()) === '' && !$dd.hasClass('is-open')) showOpenParts();
    });
    $art.on('blur', function () { setTimeout(ddClose, 150); });
    $art.on('keydown', function (e) {
        const open = $dd.hasClass('is-open');
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            if (!open) runSearch(); else ddMove(1);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            if (open) ddMove(-1);
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (open && ddActive >= 0 && ddItems[ddActive]) { pickPart(ddItems[ddActive]); $qty.trigger('focus').trigger('select'); }
            else if (S.picked) $qty.trigger('focus').trigger('select');
        } else if (e.key === 'Tab' && !e.shiftKey) {
            if (open && ddActive >= 0 && ddItems[ddActive]) {
                e.preventDefault();
                pickPart(ddItems[ddActive]);
                $qty.trigger('focus').trigger('select');
            }
        } else if (e.key === 'Escape') {
            if (open) { e.preventDefault(); ddClose(); }
            else if (S.editLineId || S.picked || $art.val()) { e.preventDefault(); resetEntry(); }
        }
    });
    $dd.on('mousedown', '.dv-dd-item', function (e) {
        e.preventDefault();
        const r = ddItems[parseInt($(this).attr('data-idx'), 10)];
        if (r) { pickPart(r); $qty.trigger('focus').trigger('select'); }
    });
    $dd.on('mousemove', '.dv-dd-item', function () {
        const i = parseInt($(this).attr('data-idx'), 10);
        if (i === ddActive) return;
        ddActive = i;
        $dd.find('.dv-dd-item').removeClass('is-active').eq(i).addClass('is-active');
    });
    $('#dvArtClear').on('click', function () { resetEntry(); focusArt(); });

    $qty.on('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); $price.trigger('focus').trigger('select'); }
        else if (e.key === 'Escape') { e.preventDefault(); resetEntry(); focusArt(); }
    });
    $price.on('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); saveEntry(); }
        else if (e.key === 'Escape') { e.preventDefault(); resetEntry(); focusArt(); }
    });
    // Magazyn pozycji to selectpicker z wyszukiwarką — klawiaturą rządzi sam
    // bootstrap-select (Enter wybiera / otwiera listę, Esc ją zamyka), więc
    // nie podpinamy tu własnego keydown. Zapis pozycji klawiaturą zostaje
    // tam, gdzie był: Enter w polu ceny.
    // Otwarta lista musi leżeć nad paskiem akcji (.dv-bar ma wyższy z-index
    // niż wiersz wpisywania), inaczej przy otwarciu w dół zasłoni ją pasek.
    $entryMag.on('show.bs.select', function () { $('#dvEntry').addClass('is-pick-open'); });
    $entryMag.on('hidden.bs.select', function () { $('#dvEntry').removeClass('is-pick-open'); });
    $qty.add($price).on('focus', function () { this.select(); });
    $('#dvEntrySave').on('click', saveEntry);
    $('#dvEntryCancel').on('click', function () { resetEntry(); focusArt(); });

    function ensureDraft() {
        if (S.deliveryId) return Promise.resolve();
        if (S.creating) return S.creating;
        if (!S.vendorId) return Promise.reject(new Error('Najpierw wybierz dostawcę.'));

        const h = readHeader();
        const payload = {};
        Object.keys(h).forEach(function (k) {
            if (h[k] !== '' && h[k] !== null && h[k] !== undefined) payload[k] = h[k];
        });
        S.creating = write('delivery-create.php', payload, { noRender: true }).then(function (r) {
            S.deliveryId = parseInt(r.delivery_id, 10);
            $page.attr('data-delivery-id', S.deliveryId);
            if (window.history && window.history.replaceState) {
                window.history.replaceState({}, '', window.location.pathname + '?delivery_id=' + S.deliveryId);
            }
            const st = stateOf(r);
            if (st) { render(st, true); return null; }
            return call('delivery-get.php', { delivery_id: S.deliveryId }).then(function (g) { render(stateOf(g) || g, true); });
        }).then(function () { S.creating = null; }, function (err) { S.creating = null; throw err; });
        return S.creating;
    }

    async function saveEntry() {
        if (S.entryBusy) return;
        if (!S.picked) { entryError('Wybierz artykuł z listy.'); focusArt(); return; }
        const qty = parseNum($qty.val());
        if (isNaN(qty) || qty <= 0) { entryError('Podaj ilość większą od zera.'); $qty.trigger('focus'); return; }
        const priceTxt = $.trim($price.val());
        let price = '';
        if (priceTxt !== '') {
            price = parseNum(priceTxt);
            if (isNaN(price) || price < 0) { entryError('Cena musi być liczbą (np. 0,12).'); $price.trigger('focus'); return; }
        }

        S.entryBusy = true;
        $('#dvEntrySave').prop('disabled', true);
        entryError('');
        const before = {};
        ((S.state && S.state.lines) || []).forEach(function (l) { before[l.id] = true; });
        const editId = S.editLineId;
        try {
            await ensureDraft();
            const payload = {
                delivery_id: S.deliveryId,
                vendor_part_id: S.picked.vendor_part_id,
                quantity: qty,
                unit_price: price,
                // '' = bez nadpisania (magazyn domyślny dostawy → NULL),
                // liczba (także "0") różna od domyślnej = konkretny magazyn.
                sub_magazine_id: entryMagParam()
            };
            if (editId) {
                const ex = findLine(editId);
                payload.line_id = editId;
                payload.comment = (ex && ex.comment) || '';
            }
            await write('delivery-line-save.php', payload);
            let flashId = editId;
            if (!flashId) {
                ((S.state && S.state.lines) || []).forEach(function (l) { if (!before[l.id]) flashId = l.id; });
            }
            resetEntry();
            renderLines();
            if (flashId) flashLine(flashId);
            focusArt();
        } catch (err) {
            entryError(err.message);
        } finally {
            S.entryBusy = false;
            $('#dvEntrySave').prop('disabled', false);
        }
    }

    // =====================================================================
    // Edytor przydziału
    // =====================================================================

    let allocLineId = 0;

    function openAlloc(lineId) {
        const l = findLine(lineId);
        if (!l) return;
        allocLineId = lineId;
        const items = itemsForPart(l.vendor_part_id);
        const others = allocatedByItem(S.state, lineId);
        const cur = {};
        (l.allocations || []).forEach(function (a) { cur[parseInt(a.po_item_id, 10)] = num(a.quantity); });

        $('#dvAllocIntro').html('<span class="dv-art-no">' + esc(l.vendor_part_no || '') + '</span>'
            + esc(l.part_name || '') + '<div class="rx-sub">Ilość na dokumencie: <strong>' + esc(fmtQty(l.quantity))
            + '</strong>. Podziel ją między otwarte pozycje zamówień — reszta zostanie przyjęta jako nadwyżka.</div>');

        let html = '';
        if (items.length === 0) {
            html = '<div class="dv-side-empty">Ten artykuł nie występuje na żadnym otwartym zamówieniu tego dostawcy.</div>';
        } else {
            html += '<div class="dv-alloc-row dv-alloc-rowhead" style="border:0;padding-top:0;padding-bottom:.2rem">'
                  + '<div>Zamówienie</div><div>Termin / cena</div><div>Pozostało</div><div class="text-right">Przydziel</div></div>';
            items.forEach(function (it) {
                const cap = Math.max(0, it.remaining - (others[it.po_item_id] || 0));
                const val = cur[it.po_item_id] ? inputNum(cur[it.po_item_id]) : '';
                html += '<div class="dv-alloc-row" data-po-item-id="' + it.po_item_id + '" data-cap="' + cap + '">'
                      + '<div><a class="dv-alloc-po" href="' + esc(poEditUrl(it.po_id)) + '" target="_blank" rel="noopener">' + esc(it.po_number || ('#' + it.po_id)) + '</a>'
                      +   '<div class="dv-sub">' + stateBadgeText(it.po_state) + '</div></div>'
                      + '<div><div>' + (it.due_date ? esc(fmtDate(it.due_date)) : 'bez terminu') + '</div>'
                      +   '<div class="dv-sub">' + (it.unit_price !== null && it.unit_price !== undefined && it.unit_price !== ''
                              ? esc(fmtPrice(it.unit_price)) + ' ' + esc(it.currency || '') : 'cena nieustalona') + '</div></div>'
                      + '<div><strong>' + esc(fmtQty(cap)) + '</strong>'
                      +   (cap < it.remaining - EPS ? '<div class="dv-sub">z ' + esc(fmtQty(it.remaining)) + ' (reszta w innych pozycjach)</div>' : '')
                      +   '<div><button type="button" class="rx-link-btn js-alloc-max">maks.</button></div></div>'
                      + '<div><input type="text" inputmode="decimal" class="form-control form-control-sm text-right js-alloc-qty" value="' + esc(val) + '" placeholder="0" aria-label="Ilość do ' + esc(it.po_number || '') + '"></div>'
                      + '</div>';
            });
        }
        $('#dvAllocRows').html(html).data('lineQty', num(l.quantity));
        $('#dvAllocError').addClass('d-none').empty();
        $('#dvAllocSave').prop('disabled', items.length === 0);
        updateAllocSum();
        $('#dvAllocModal').modal('show');
    }

    function stateBadgeText(st) {
        const m = PO_STATE[st];
        return esc(m ? m.label : (st || ''));
    }

    function readAlloc() {
        const rows = [];
        let sum = 0;
        let over = false;
        $('#dvAllocRows .dv-alloc-row[data-po-item-id]').each(function () {
            const $r = $(this);
            const raw = $.trim($r.find('.js-alloc-qty').val());
            const cap = num($r.attr('data-cap'));
            let q = 0;
            let bad = false;
            if (raw !== '') {
                q = parseNum(raw);
                if (isNaN(q) || q < 0) { bad = true; q = 0; }
            }
            const isOver = bad || q > cap + EPS;
            $r.toggleClass('is-over', isOver).toggleClass('is-set', q > 0 && !isOver);
            if (isOver) over = true;
            if (q > 0) rows.push({ po_item_id: parseInt($r.attr('data-po-item-id'), 10), quantity: q });
            sum += q;
        });
        return { rows: rows, sum: sum, over: over };
    }

    function updateAllocSum() {
        const lineQty = num($('#dvAllocRows').data('lineQty'));
        const a = readAlloc();
        const $s = $('#dvAllocSum').removeClass('is-bad is-surplus');
        if (a.over) {
            $s.addClass('is-bad').html('<i class="bi bi-exclamation-triangle-fill"></i> Któraś ilość przekracza to, co zostało na zamówieniu, albo nie jest liczbą.');
        } else if (a.sum > lineQty + EPS) {
            $s.addClass('is-bad').html('<i class="bi bi-exclamation-triangle-fill"></i> Przydzielono <strong>' + esc(fmtQty(a.sum)) + '</strong> — więcej niż ilość na dokumencie (' + esc(fmtQty(lineQty)) + ').');
        } else if (lineQty - a.sum > EPS && a.sum > 0) {
            $s.addClass('is-surplus').html('Przydzielono <strong>' + esc(fmtQty(a.sum)) + '</strong> z ' + esc(fmtQty(lineQty))
                + ' · <strong>' + esc(fmtQty(lineQty - a.sum)) + '</strong> trafi jako nadwyżka (wymaga komentarza).');
        } else if (a.sum > 0) {
            $s.html('<i class="bi bi-check2-circle"></i> Przydzielono całą ilość: <strong>' + esc(fmtQty(a.sum)) + '</strong>.');
        } else {
            $s.html('Wpisz ilości przy wybranych zamówieniach albo wróć do podziału automatycznego.');
        }
        $('#dvAllocSave').prop('disabled', a.over || a.sum <= EPS || a.sum > lineQty + EPS);
    }

    $('#dvAllocRows').on('input', '.js-alloc-qty', updateAllocSum);
    $('#dvAllocRows').on('click', '.js-alloc-max', function () {
        const $r = $(this).closest('.dv-alloc-row');
        const lineQty = num($('#dvAllocRows').data('lineQty'));
        let others = 0;
        $('#dvAllocRows .dv-alloc-row[data-po-item-id]').not($r).each(function () {
            const q = parseNum($(this).find('.js-alloc-qty').val());
            if (!isNaN(q)) others += q;
        });
        const v = Math.max(0, Math.min(num($r.attr('data-cap')), lineQty - others));
        $r.find('.js-alloc-qty').val(v > 0 ? inputNum(v) : '');
        updateAllocSum();
    });
    $('#dvAllocModal').on('shown.bs.modal', function () {
        $('#dvAllocRows .js-alloc-qty').first().trigger('focus');
    });
    $('#dvAllocModal').on('keydown', '.js-alloc-qty', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); if (!$('#dvAllocSave').prop('disabled')) $('#dvAllocSave').trigger('click'); }
    });

    $('#dvAllocSave').on('click', function () {
        const a = readAlloc();
        if (a.over || a.rows.length === 0) return;
        const $b = $(this).prop('disabled', true);
        write('delivery-line-allocate.php', { delivery_id: S.deliveryId, line_id: allocLineId, allocations: a.rows })
            .then(function () { $('#dvAllocModal').modal('hide'); })
            .catch(function (err) { $('#dvAllocError').removeClass('d-none').text(err.message); })
            .then(function () { $b.prop('disabled', false); });
    });
    $('#dvAllocAuto').on('click', function () {
        write('delivery-line-allocate.php', { delivery_id: S.deliveryId, line_id: allocLineId })
            .then(function () { $('#dvAllocModal').modal('hide'); })
            .catch(function (err) { $('#dvAllocError').removeClass('d-none').text(err.message); });
    });

    // =====================================================================
    // Pasek akcji
    // =====================================================================

    function renderBar(st) {
        const mode = st ? st.delivery.state : 'draft';
        const sum = (st && st.summary) || { line_count: 0, po_count: 0, warning_count: 0, can_post: false, blockers: [] };
        const lines = sum.line_count || 0;
        const pos = sum.po_count || 0;
        const warn = sum.warning_count || 0;

        $('#dvStatLines').text(lines);
        $('#dvStatLinesLbl').text(plural(lines, 'pozycja', 'pozycje', 'pozycji'));
        $('#dvStatPos').text(pos);
        $('#dvStatPosLbl').text(plural(pos, 'zamówienie', 'zamówienia', 'zamówień'));
        $('#dvStatWarn').text(warn);
        $('#dvStatWarnLbl').text(plural(warn, 'ostrzeżenie', 'ostrzeżenia', 'ostrzeżeń'));
        $('#dvStatWarnWrap').toggleClass('has-warn', warn > 0);

        const $bl = $('#dvBlockers');
        if (mode === 'draft' && st) {
            const blockers = sum.blockers || [];
            if (blockers.length) {
                const max = 3;
                const shown = S.showAllBlockers ? blockers : blockers.slice(0, max);
                let html = '<span class="dv-blockers-title"><i class="bi bi-slash-circle"></i> Do zatwierdzenia brakuje:</span><ul>';
                shown.forEach(function (b) { html += '<li>' + esc(b) + '</li>'; });
                html += '</ul>';
                if (blockers.length > max) {
                    html += '<button type="button" class="dv-blockers-more js-blockers-toggle">'
                          + (S.showAllBlockers ? 'Pokaż mniej' : 'Pokaż wszystkie (' + blockers.length + ')') + '</button>';
                }
                $bl.removeClass('d-none is-warn').html(html);
            } else {
                $bl.addClass('d-none').empty();
            }
        } else {
            $bl.addClass('d-none').empty();
        }

        const canPost = !!(st && mode === 'draft' && sum.can_post && (sum.blockers || []).length === 0);
        $('#dvPostBtn').prop('disabled', !canPost || S.posting)
            .attr('title', canPost ? '' : (S.deliveryId ? 'Usuń blokady wymienione nad paskiem.' : 'Najpierw zapisz dokument dostawcy.'));
        $('#dvDiscardBtn').prop('disabled', !S.deliveryId).toggleClass('d-none', !S.deliveryId);
        $('#dvCancelDeliveryBtn').toggleClass('d-none', mode !== 'posted');
        $('#dvBackBtn').toggleClass('d-none', mode === 'draft');
    }

    $('#dvBlockers').on('click', '.js-blockers-toggle', function () {
        S.showAllBlockers = !S.showAllBlockers;
        renderBar(S.state);
    });

    $('#dvDiscardBtn').on('click', function () {
        if (!S.deliveryId) return;
        const n = (S.state && S.state.lines ? S.state.lines.length : 0);
        const msg = 'Usunąć szkic przyjęcia' + (n ? ' wraz z ' + n + ' ' + plural(n, 'pozycją', 'pozycjami', 'pozycjami') : '') + '? Nic nie zostało zaksięgowane.';
        if (!window.confirm(msg)) return;
        $(this).prop('disabled', true);
        clearTimeout(headerTimer);
        headerTimer = null;
        write('delivery-discard.php', { delivery_id: S.deliveryId }, { noRender: true })
            .then(function () { window.location.href = LIST_URL + '#robocze'; })
            .catch(function (err) { $('#dvDiscardBtn').prop('disabled', false); showError(err.message); });
    });

    $('#dvCancelDeliveryBtn').on('click', function () {
        if (!S.deliveryId) return;
        if (!window.confirm('Anulować całe przyjęcie? Towar zostanie zdjęty ze stanu, a ilości na zamówieniach cofnięte. Tej operacji nie da się wykonać dla pojedynczych pozycji.')) return;
        const $b = $(this).prop('disabled', true);
        write('delivery-cancel.php', { delivery_id: S.deliveryId })
            .then(function (r) {
                if (!stateOf(r)) return call('delivery-get.php', { delivery_id: S.deliveryId }).then(function (g) { render(stateOf(g)); });
                return null;
            })
            .then(function () { showOk('<i class="bi bi-check-circle-fill"></i> Przyjęcie zostało anulowane.'); })
            .catch(function (err) { showError(err.message); })
            .then(function () { $b.prop('disabled', false); });
    });

    $('#dvPostBtn').on('click', async function () {
        if ($(this).prop('disabled') || S.posting) return;
        S.posting = true;
        const $b = $(this).prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Zatwierdzanie…');
        try {
            await flushHeader();
            const r = await write('delivery-post.php', { delivery_id: S.deliveryId });
            if (!stateOf(r)) {
                const g = await call('delivery-get.php', { delivery_id: S.deliveryId });
                render(stateOf(g));
            }
            openPostSummary(r.result || {});
        } catch (err) {
            showError(err.message);
            try {
                const g = await call('delivery-get.php', { delivery_id: S.deliveryId });
                render(stateOf(g));
            } catch (e) { /* zostaje poprzedni stan */ }
        } finally {
            S.posting = false;
            $b.html('<i class="bi bi-check2-circle"></i> Zatwierdź przyjęcie');
            renderBar(S.state);
        }
    });

    // =====================================================================
    // Podsumowanie po zatwierdzeniu: per zamówienie „Zostaw otwarte" /
    // „Zamknij zamówienie" (receipt-close-po.php, cel „received").
    // =====================================================================

    function closePoButtonsHtml(p) {
        return '<div class="dv-post-choice" data-po-id="' + parseInt(p.po_id, 10) + '" data-po-number="' + esc(p.po_number || '') + '">'
             + '<button type="button" class="btn btn-sm btn-primary js-po-keep" disabled><i class="bi bi-hourglass-split"></i> Zostaw otwarte</button>'
             + '<button type="button" class="btn btn-sm btn-outline-secondary js-po-close"><i class="bi bi-check2-all"></i> Zamknij zamówienie</button>'
             + '<span class="dv-sub">Brakujące pozycje przyjmiesz przy kolejnej dostawie.</span>'
             + '</div>';
    }

    function openPostSummary(result) {
        $('#dvPostTitle').text(result.grn_number || (S.state && S.state.delivery.grn_number) || 'Przyjęcie');
        const pos = result.pos || [];
        let html = '';
        if (pos.length === 0) {
            html = '<div class="dv-side-empty">Dostawa nie dotyczyła żadnego zamówienia — cała ilość trafiła na stan jako nadwyżka.</div>';
        }
        pos.forEach(function (p) {
            const ordered = num(p.ordered);
            const received = num(p.received);
            const pct = ordered > 0 ? Math.max(0, Math.min(100, received / ordered * 100)) : 0;
            const open = ['confirmed', 'in_progress', 'partially_received'].indexOf(p.new_state) !== -1;
            html += '<div class="dv-post-po' + (open ? '' : ' is-closed') + '" data-po-id="' + parseInt(p.po_id, 10) + '">'
                  + '<div class="dv-post-po-top">'
                  +   '<a class="dv-post-po-no" href="' + esc(poEditUrl(p.po_id)) + '" target="_blank" rel="noopener">' + esc(p.po_number || ('#' + p.po_id)) + '</a>'
                  +   '<span class="js-po-badge">' + stateBadge(p.new_state) + '</span>'
                  + '</div>'
                  + '<div class="dv-post-po-prog">'
                  +   '<div class="rx-progress-top"><span>przyjęto <strong>' + esc(fmtQty(received)) + '</strong> z ' + esc(fmtQty(ordered)) + '</span>'
                  +   '<span class="rx-pct">' + Math.round(pct) + '%</span></div>'
                  +   '<div class="rx-bar"><span class="' + (pct >= 100 ? 'is-done' : '') + '" style="width:' + pct.toFixed(1) + '%"></span></div>'
                  + '</div>';
            const missing = p.missing_lines || [];
            if (missing.length) {
                html += '<ul class="dv-post-missing">';
                missing.slice(0, 5).forEach(function (m) {
                    html += '<li><span class="n">' + esc(m.vendor_part_no || '—') + '</span><span class="nm" title="' + esc(m.part_name || '') + '">' + esc(m.part_name || '') + '</span>'
                          + '<span>brakuje ' + esc(fmtQty(m.remaining)) + '</span></li>';
                });
                if (missing.length > 5) html += '<li><span></span><span class="text-muted">+ ' + (missing.length - 5) + ' więcej</span><span></span></li>';
                html += '</ul>';
            }
            if (open) {
                html += closePoButtonsHtml(p);
            } else {
                html += '<div class="dv-post-choice"><span class="dv-sub"><i class="bi bi-check-circle-fill text-success"></i> Zamówienie zamknięte — nie ma już nic do dostarczenia.</span></div>';
            }
            html += '<div class="dv-post-error d-none"></div></div>';
        });
        $('#dvPostPos').html(html);
        $('#dvPostModal').modal('show');
    }

    // Wspólna obsługa przycisków w modalu i w panelu bocznym trybu odczytu.
    $(document).on('click', '.js-po-close', function () {
        const $wrap = $(this).closest('.dv-post-choice');
        const $keep = $wrap.find('.js-po-keep');
        const $btn = $(this);
        if (!$btn.hasClass('is-confirm')) {
            // Krok 1: nieodwracalne → prosimy o potwierdzenie w miejscu.
            $btn.addClass('is-confirm btn-danger').removeClass('btn-outline-secondary')
                .html('<i class="bi bi-exclamation-triangle-fill"></i> Na pewno zamknąć?');
            $keep.prop('disabled', false).html('<i class="bi bi-x"></i> Wróć');
            return;
        }
        const poId = parseInt($wrap.attr('data-po-id'), 10);
        $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Zamykanie…');
        call('receipt-close-po.php', { po_id: poId, target_state: 'received' }).then(function (r) {
            const $card = $wrap.closest('.dv-post-po, .dv-pos-sum');
            $card.addClass('is-closed').find('.js-po-badge').html(stateBadge(r.po_state || 'received'));
            $wrap.html('<span class="dv-sub"><i class="bi bi-check-circle-fill text-success"></i> Zamówienie zamknięte jako odebrane.</span>');
            if (S.state) {
                (S.state.pos || []).forEach(function (p) { if (parseInt(p.po_id, 10) === poId) p.new_state = r.po_state || 'received'; });
            }
        }).catch(function (err) {
            $btn.prop('disabled', false).html('<i class="bi bi-exclamation-triangle-fill"></i> Spróbuj ponownie');
            const $card = $wrap.closest('.dv-post-po, .dv-pos-sum');
            let $er = $card.find('.dv-post-error');
            if (!$er.length) $er = $('<div class="dv-post-error"></div>').appendTo($card);
            $er.removeClass('d-none').text(err.message);
        });
    });

    $(document).on('click', '.js-po-keep', function () {
        const $wrap = $(this).closest('.dv-post-choice');
        // Wróć z kroku potwierdzenia.
        $wrap.find('.js-po-close').removeClass('is-confirm btn-danger').addClass('btn-outline-secondary')
            .html('<i class="bi bi-check2-all"></i> Zamknij zamówienie');
        $(this).prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Zostaw otwarte');
    });

    $('#dvPostDone').on('click', function () {
        $('#dvPostModal').modal('hide');
        if (S.deliveryId) {
            call('delivery-get.php', { delivery_id: S.deliveryId }).then(function (g) { render(stateOf(g)); }).catch(function () {});
        }
    });

    // =====================================================================
    // Render całości z jednego stanu
    // =====================================================================

    function render(st, forceHeader) {
        if (!st || !st.delivery) return;
        S.state = st;
        if (st.delivery.vendor_id) S.vendorId = parseInt(st.delivery.vendor_id, 10);
        S.deliveryId = parseInt(st.delivery.id, 10) || S.deliveryId;

        const mode = st.delivery.state || 'draft';
        // Edycja nagłówka ma sens tylko dla szkicu (zatwierdzenie / anulowanie ją kończy).
        if (mode !== 'draft') S.headerEdit = false;
        $page.removeClass('dv-mode-draft dv-mode-posted dv-mode-cancelled dv-no-vendor').addClass('dv-mode-' + mode);

        renderHeader(st, !!forceHeader);
        renderAll();
    }

    function renderAll() {
        // Krok 1 aktywny (dokument nie zapisany albo w edycji) → reszta strony
        // przygaszona i zablokowana.
        applyStepGate();
        renderLines();
        renderSide();
        renderBar(S.state);
        updateVendorHint();
        refreshDocGate();
        const draftOk = !S.state || S.state.delivery.state === 'draft';
        // Wiersz wpisywania dopiero po założeniu szkicu (S.deliveryId).
        $('#dvEntry').toggleClass('d-none', !S.deliveryId || !S.vendorId || !draftOk);
        $('#dvLinesNote').text(headerMode() === 'editing'
            ? 'Dokument dostawcy w edycji — pozycje zablokowane.'
            : (S.state && S.state.delivery.state === 'draft' ? 'Zmiany zapisują się na bieżąco.' : ''));
        fillEntryMag();
        if (S.picked) updateEntryHint();
    }

    // =====================================================================
    // Start
    // =====================================================================

    function init() {
        $page.addClass('dv-no-vendor');
        $('#dvEntry').addClass('d-none');
        $page.attr('data-booted', '0');
        // Wznawiany szkic: nagłówek od razu tylko do odczytu (do czasu wczytania
        // stanu); po wczytaniu render() zostawia go w trybie „zablokowany".
        if (S.deliveryId) applyHeaderLock();

        // Picker dostawcy czeka na listę (delivery-vendors.php) — w tym czasie
        // jest zablokowany, a pod nim jest komunikat, że trwa wczytywanie.
        $('#dvVendorHint').html('<span class="dv-hint--info"><i class="bi bi-hourglass-split"></i> Wczytywanie listy dostawców…</span>');

        const vendorsReq = call('delivery-vendors.php', {}).then(function (r) {
            buildVendorPicker(r.vendors || []);
        }).catch(function (err) {
            // Picker zostaje zablokowany — bez listy nie da się wybrać dostawcy.
            $('#dvVendorHint').html('<span class="dv-hint--warn"><i class="bi bi-exclamation-triangle-fill"></i> Nie udało się wczytać listy dostawców.</span>');
            showError('Nie udało się wczytać listy dostawców: ' + err.message);
        });

        vendorsReq.then(function () {
            if (S.deliveryId) {
                return call('delivery-get.php', { delivery_id: S.deliveryId }).then(function (r) {
                    const st = stateOf(r);
                    if (!st) throw new Error('Nie znaleziono przyjęcia.');
                    S.vendorId = parseInt(st.delivery.vendor_id, 10);
                    ensureVendorOption(st.delivery.vendor_id, st.delivery.vendor_name);
                    setPickerVal($vendor, st.delivery.vendor_id);
                    render(st, true);
                    if (st.delivery.state === 'draft') return loadOpenItems(S.vendorId);
                    return null;
                }).catch(function (err) {
                    showError(err.message);
                    $page.addClass('dv-no-vendor');
                    // Podsumowanie nie zostanie już narysowane — nie zostawiamy „Wczytywanie…".
                    $('#dvSummary').html('<div class="dv-summary-note is-error">Nie udało się wczytać dokumentu dostawcy.</div>');
                });
            }
            const vid = parseInt($page.attr('data-vendor-id'), 10) || 0;
            // Nowe przyjęcie: magazyn docelowy = domyślny magazyn zalogowanego
            // (user.sub_magazine_id). Brak / nieaktywny / spoza listy → pusty
            // picker (jawnie czyszczony, by przeglądarka nie podstawiła
            // pierwszej opcji). Wznowiony szkic nie przechodzi tędy — tam
            // wygrywa zapisany magazyn z serwera.
            setPickerVal($magazine, userDefaultMagazine());
            if (vid) {
                if (!S.vendorMap[vid]) {
                    showError('Nie znaleziono dostawcy #' + vid + ' na liście.');
                } else {
                    setVendor(vid, true);
                }
            } else {
                renderAll();
            }
            return null;
        }).then(function () {
            $page.attr('data-booted', '1');
            // Picker dostawcy NIE otwiera się sam — operator wybiera dostawcę
            // sam, a lista i tak jest już wczytana i odblokowana.
        });
    }

    // Dropdown typeahead nie może zasłaniać ostatnich wierszy po scrollu.
    $(window).on('scroll resize', function () { if ($dd.hasClass('is-open')) ddClose(); });

    init();
});
