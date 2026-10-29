/*
 * "Oznacz odpowiedź" / "Potwierdź odpowiedź" — frontend strony odpowiedzi
 * (RFQ i PO). Wygląd: documents-respond-ui.css (warstwa dx-respond-* na rx-*).
 *
 * Co robi ten plik:
 *   1. Pamięta wartości zapisane w bazie (cena, ilość, waluta) i na
 *      żywo porównuje z nimi to, co wpisuje operator:
 *        - wiersz, który się różni, dostaje klasę .is-changed (pasek akcentu),
 *        - pod ceną pokazuje się różnica (+2,50 zielona / −1,00 czerwona),
 *        - kolumna "Wartość" = ilość × cena (tylko do odczytu),
 *        - suma dokumentu per waluta + różnica względem sumy zapisanej,
 *        - licznik "Zmieniono: N z M pozycji" (panel + pasek akcji).
 *      Ilość i Waluta są domyślnie tylko do odczytu (tekst); klik / Enter
 *      otwiera edytor w komórce, Enter / wyjście z pola zatwierdza,
 *      Esc przywraca wartość sprzed edycji. Cena jest edytowalna zawsze.
 *   2. Dwukierunkowe powiązanie ilość <-> opakowania (stały rozmiar opakowania
 *      w data-pack wiersza); .packages-uneven to tylko podpowiedź.
 *   3. Akcje zbiorcze w nagłówku panelu:
 *        #respondFillAllBtn  — przepisuje data-asked-qty do pola ilości,
 *        #respondClearAllBtn — czyści pola cen (w PO: przywraca ceny
 *        zapisane w bazie, bo puste pole = "zachowaj cenę").
 *   4. „Uwagi do artykułu" (list__vendor_part.comment) — edytowane W MIEJSCU
 *      (ołówek → edytor) i zapisywane OD RAZU osobnym POSTem do
 *      purchases/cart/vendor-part-comment.php (jak w koszyku). To NIE jest
 *      część odpowiedzi: textarea nie ma name, więc nie trafia do payloadu,
 *      a edycja nie liczy się do zmian pozycji.
 *   5. Zapis: waliduje ilość > 0 w każdym wierszu, a dla PO dodatkowo
 *      wymaga ceny > 0 w każdej pozycji (pozycja "BRAK CENY" blokuje zapis),
 *      blokuje przyciski i pokazuje spinner, wysyła POST do
 *      document-rfq-respond-ajax.php (type=rfq) albo
 *      document-po-respond-ajax.php (type=po) i przekierowuje na stronę edycji.
 *      Typ i id pochodzą z data-respond-type / data-respond-id formularza.
 *
 * Semantyka cen (ważna, nie zmieniać):
 *   - RFQ: pusta cena = pozycja niewyceniona (serwer zapisuje NULL).
 *   - PO: kolumna `unit_price` jest nullable — pozycja bez ceny ma NULL.
 *     PUSTE pole = "zachowaj dotychczasową cenę" — nigdy nie wysyłamy
 *     sztucznego 0.
 *   - PO: pozycja BEZ CENY (NULL albo cena <= 0 w bazie i puste pole)
 *     BLOKUJE zapis — zamówienie w stanie "confirmed" musi mieć cenę w
 *     każdej pozycji (kontrola jest też po stronie serwera:
 *     PurchaseActionHandler).
 *   - Waluta zawsze ma wartość (select z zaznaczoną dotychczasową).
 *
 * Konwencje (AGENTS.md / header.js): setAlert() pisze do #alertContainer,
 * COMPONENTS_PATH / ROOT_DIR, jQuery $.ajax + .done()/.fail(), tylko
 * wyłącznie let/const.
 */

$(function () {
    const $form = $('#respondForm');
    if ($form.length === 0) {
        return;
    }
    const $btn = $('#respondSubmitBtn');
    // Typ dokumentu ('rfq' | 'po') i id pochodzą z atrybutów data-* formularza.
    const TYPE     = String($form.attr('data-respond-type') || 'rfq');
    const DOC_ID   = $form.attr('data-respond-id');
    const IS_PO    = (TYPE === 'po');
    const ENDPOINT = COMPONENTS_PATH + '/purchases/documents/' + (IS_PO
        ? 'document-po-respond-ajax.php'
        : 'document-rfq-respond-ajax.php');

    const EPS = 1e-9;
    const NBSP = '\u00a0';
    const MINUS = '\u2212';

    const $rows         = $form.find('div.dx-respond-row');
    const $totals       = $('#respondTotals');
    const $unpriced     = $('#respondUnpriced');
    const $changed      = $('#respondChanged');
    const $barTotals    = $('#respondBarTotals');
    const $barChanged   = $('#respondBarChanged');
    const $summary      = $('#respondSummary');
    const $hint         = $('#respondHint');
    const TOTAL_ROWS    = $rows.length;

    // ----- Parsowanie / formatowanie -----

    // '12,5' | '12.5' | '' → liczba albo null (puste / niepoprawne).
    function parseNum(s) {
        const t = String(s === undefined || s === null ? '' : s).replace(',', '.').trim();
        if (t === '') { return null; }
        const n = parseFloat(t);
        return isNaN(n) ? null : n;
    }

    // Cena efektywna pozycji — ta, która zostanie zapisana:
    //   RFQ — co jest w polu (puste = niewycenione → null),
    //   PO  — co jest w polu; puste pole = zachowaj cenę zapisaną w bazie.
    //         Baza ma `unit_price` nullable, więc "brak ceny" = NULL
    //         (albo cena <= 0).
    function effectivePrice(r) {
        const p = parseNum(r.$price.val());
        if (p === null && IS_PO) {
            return r.origPrice;
        }
        return p;
    }

    function esc(s) {
        return $('<div>').text(String(s)).html();
    }

    function groupThousands(intPart) {
        return intPart.replace(/\B(?=(\d{3})+(?!\d))/g, NBSP);
    }

    // Kwoty i ceny: spacja tysięcy, przecinek dziesiętny; min. 2 a maks. 4
    // miejsca po przecinku (12,50 · 980,00 · 12,3456). Ta sama reguła co
    // $fmtMoney w documents-respond.php.
    function formatPrice(v) {
        if (v === null || v === undefined || isNaN(v)) { return '—'; }
        const rounded = Math.round(v * 10000) / 10000;
        const parts = Math.abs(rounded).toFixed(4).split('.');
        let dec = parts[1].replace(/0+$/, '');
        while (dec.length < 2) { dec += '0'; }
        const sign = (rounded < 0) ? '-' : '';
        return sign + groupThousands(parts[0]) + ',' + dec;
    }

    // Ilości: zera końcowe obcięte (2 000 · 0,5).
    function formatQty(v) {
        if (v === null || v === undefined || isNaN(v)) { return '—'; }
        const parts = v.toFixed(4).split('.');
        const dec = parts[1].replace(/0+$/, '');
        return groupThousands(parts[0]) + (dec ? ',' + dec : '');
    }

    // +2,50 / −1,00 (znak zawsze widoczny; null gdy różnica jest zerowa).
    function formatDelta(d) {
        if (Math.round(d * 10000) === 0) { return null; }
        return (d > 0 ? '+' : MINUS) + formatPrice(Math.abs(d));
    }

    // ----- Stan początkowy wierszy (wartości zapisane w bazie) -----

    const rows = $rows.map(function () {
        const $row = $(this);
        const origPriceS = ($row.attr('data-orig-price') || '').trim();
        const r = {
            $row:     $row,
            $qty:     $row.find('.js-qty'),
            $packs:   $row.find('.js-packs'),
            $price:   $row.find('.js-price'),
            // UWAGA: tylko <select> — bootstrap-select kopiuje klasy selecta
            // (także .js-currency) na swój wrapper i kontener menu, a .val()
            // na <div> zwraca ''.
            $cur:     $row.find('select.js-currency'),
            $flag:    $row.find('.js-flag'),
            // Widoki tylko do odczytu (Ilość / Waluta) i ich ramki.
            $qtyBox:  $row.find('[data-cell="qty"] .js-view'),
            $qtyView: $row.find('.js-qty-view'),
            $qtySub:  $row.find('.js-qty-sub'),
            $qtyWas:  $row.find('.js-qty-was'),
            $curBox:  $row.find('[data-cell="cur"] .js-view'),
            $curView: $row.find('.js-cur-view'),
            unit:     String($row.attr('data-asked-unit') || ''),
            pack:     parseFloat(($row.attr('data-pack') || '').replace(',', '.')) || 0,
            origQty:     parseNum($row.find('.js-qty').val()),
            // null = brak ceny w bazie (RFQ niewyceniona; PO bez ustawionej ceny).
            origPrice:   origPriceS === '' ? null : parseNum(origPriceS),
            origCur:     String($row.find('select.js-currency').val() || '')
        };
        return r;
    }).get();

    // ----- Podsumowania -----

    // Zwraca HTML podsumowania per waluta. compact=true → bez "było".
    function totalsHtml(now, was, compact) {
        const codes = Object.keys(now).concat(Object.keys(was))
            .filter(function (c, i, a) { return a.indexOf(c) === i; })
            .sort();
        if (codes.length === 0) {
            return '<span class="dx-respond-dash">—</span>';
        }
        return codes.map(function (c) {
            const n = now[c] || 0;
            const w = was[c] || 0;
            let html = '<span class="dx-respond-tline">'
                + '<span class="dx-respond-tval">' + esc(formatPrice(n)) + '</span>'
                + '<span class="dx-respond-tcur">' + esc(c) + '</span>';
            if (!compact) {
                html += '<span class="dx-respond-was">było' + (Math.abs(w) < 5e-5 ? ': ' : ' ')
                    + esc(Math.abs(w) < 5e-5 ? formatPrice(0) : formatPrice(w) + NBSP + c) + '</span>';
            }
            // Gdy zapisana suma była 0 — samo "było: 0,00", bez znaczka różnicy.
            if (Math.abs(w) >= 5e-5) {
                const dTxt = formatDelta(n - w);
                if (dTxt !== null) {
                    html += '<span class="dx-delta ' + ((n - w) > 0 ? 'dx-delta--up' : 'dx-delta--down') + '">'
                        + esc(dTxt) + '</span>';
                }
            }
            return html + '</span>';
        }).join('');
    }

    function setChangedChip($el, n, active) {
        $el.html('Zmieniono: <strong>' + n + '</strong> z ' + TOTAL_ROWS + ' pozycji')
           .toggleClass('is-active', active);
    }

    // ----- Przeliczenie wszystkiego -----

    function recalc() {
        const now = {};
        const was = {};
        let changedCount = 0;
        let unpricedCount = 0;
        let pricedCount = 0;

        rows.forEach(function (r) {
            const qty     = parseNum(r.$qty.val());
            const priceIn = parseNum(r.$price.val());
            const cur     = String(r.$cur.val() || r.origCur);

            // Cena efektywna: RFQ — to co w polu (puste = niewycenione);
            // PO — puste pole = zachowaj dotychczasową.
            const eff = effectivePrice(r);

            // Co się zmieniło względem bazy?
            const qtyChanged = (qty === null || r.origQty === null || Math.abs(qty - r.origQty) > EPS);
            let priceChanged;
            if (IS_PO) {
                priceChanged = (priceIn !== null)
                    && (r.origPrice === null || Math.abs(priceIn - r.origPrice) > EPS);
            } else if (priceIn === null || r.origPrice === null) {
                priceChanged = ((priceIn === null) !== (r.origPrice === null));
            } else {
                priceChanged = Math.abs(priceIn - r.origPrice) > EPS;
            }
            const curChanged = (cur !== r.origCur);
            const rowChanged = qtyChanged || priceChanged || curChanged;
            if (rowChanged) { changedCount++; }

            // Wskaźniki zmian na samych polach.
            r.$qty.toggleClass('is-dirty', qtyChanged);
            r.$price.toggleClass('is-dirty', priceChanged);
            r.$cur.closest('.dx-respond-cur').toggleClass('is-dirty', curChanged);

            // Widoki tylko do odczytu: tekst + wyróżnienie, gdy różni się od bazy.
            const qtyBad = (qty === null || qty <= 0);
            r.$qtyView.text(qty === null ? '—' : formatQty(qty) + (r.unit ? NBSP + r.unit : ''))
                .toggleClass('is-invalid', qtyBad);
            if (r.pack > 0) {
                if (!qtyBad) {
                    const pk = qty / r.pack;
                    r.$qtySub.text(formatQty(pk) + ' opak. × ' + formatQty(r.pack))
                        .toggleClass('is-uneven', Math.abs(pk - Math.round(pk)) > 1e-9);
                } else {
                    r.$qtySub.text('').removeClass('is-uneven');
                }
            }
            if (qtyChanged && r.origQty !== null) {
                r.$qtyWas.text((IS_PO ? 'Zamówiono: ' : 'Zapytano: ') + formatQty(r.origQty)).prop('hidden', false);
            } else {
                r.$qtyWas.text('').prop('hidden', true);
            }
            r.$curView.text(cur);
            r.$qtyBox.toggleClass('is-dirty', qtyChanged);
            r.$curBox.toggleClass('is-dirty', curChanged);

            // Pozycja bez ceny: RFQ — brak ceny; PO — cena <= 0.
            const noPrice = IS_PO ? (eff === null || eff <= 0) : (eff === null);
            if (noPrice) { unpricedCount++; } else { pricedCount++; }
            r.$row.toggleClass('is-changed', rowChanged);
            if (IS_PO) {
                r.$row.toggleClass('is-noprice', noPrice);
            } else {
                r.$row.toggleClass('is-unpriced', noPrice);
            }

            // --- Różnica ceny jednostkowej ("było" jest już wyrenderowane w PHP) ---
            const $delta = r.$row.find('.js-price-delta');
            let deltaTxt = null;
            let deltaUp = false;
            if (priceIn !== null && r.origPrice !== null) {
                deltaTxt = formatDelta(priceIn - r.origPrice);
                deltaUp = (priceIn - r.origPrice) > 0;
            }
            if (deltaTxt !== null) {
                $delta.text(deltaTxt)
                    .toggleClass('dx-delta--up', deltaUp)
                    .toggleClass('dx-delta--down', !deltaUp)
                    .prop('hidden', false);
            } else {
                $delta.text('').prop('hidden', true).removeClass('dx-delta--up dx-delta--down');
            }

            // --- PO: pusta cena = "zostanie …" (albo "brak ceny", gdy
            //     w bazie też jej nie ma — wtedy pozycja blokuje zapis) ---
            if (IS_PO) {
                const $keep = r.$row.find('.js-price-keep');
                if (priceIn === null) {
                    const keepPrice = (r.origPrice === null) ? 0 : r.origPrice;
                    $keep.text(keepPrice > 0
                        ? ('zostanie ' + formatPrice(keepPrice))
                        : 'brak ceny — uzupełnij')
                        .toggleClass('is-warn', keepPrice <= 0)
                        .prop('hidden', false);
                } else {
                    $keep.text('').prop('hidden', true);
                }
            }

            // --- Kolumna Wartość + sumy ---
            const $val  = r.$row.find('.js-line-val');
            const $vcur = r.$row.find('.js-line-cur');
            const $vwas = r.$row.find('.js-line-was');
            let line = null;
            if (!noPrice && qty !== null && qty > 0) {
                line = qty * eff;
                now[cur] = (now[cur] || 0) + line;
            }
            $val.text(line === null ? '—' : formatPrice(line));
            $vcur.text(line === null ? '' : cur);

            // Zapisana wartość wiersza (do sumy "było" i do podpisu pod wartością).
            let oldLine = null;
            if (r.origPrice !== null && (!IS_PO || r.origPrice > 0) && r.origQty !== null) {
                oldLine = r.origQty * r.origPrice;
                was[r.origCur] = (was[r.origCur] || 0) + oldLine;
            }
            const lineDiffers = (oldLine !== null)
                && (line === null || curChanged || Math.abs(line - oldLine) >= 5e-5);
            if (rowChanged && lineDiffers) {
                $vwas.text('było ' + formatPrice(oldLine) + NBSP + r.origCur).prop('hidden', false);
            } else {
                $vwas.text('').prop('hidden', true);
            }
        });

        // Panel: sumy z "było" i różnicą; pasek akcji: wersja zwarta.
        $totals.html(totalsHtml(now, was, false));
        $barTotals.html(totalsHtml(now, was, true));
        $unpriced.text(unpricedCount > 0
            ? ('Bez ceny: ' + unpricedCount + ' z ' + TOTAL_ROWS + ' pozycji')
            : '');

        setChangedChip($changed, changedCount, changedCount > 0);
        setChangedChip($barChanged, changedCount, changedCount > 0);

        $summary.text((IS_PO ? 'Zapisujesz potwierdzenie odpowiedzi dla ' : 'Zapisujesz odpowiedź dla ')
            + pricedCount + ' z ' + TOTAL_ROWS + ' pozycji');

        if (unpricedCount > 0) {
            $hint.text(IS_PO
                ? ('Uzupełnij cenę w ' + unpricedCount + ' '
                    + (unpricedCount === 1 ? 'pozycji' : 'pozycjach')
                    + ' — bez ceny nie potwierdzisz zamówienia.')
                : ('Pozostawienie pustej ceny oznacza pozycję niewycenioną (' + unpricedCount + ').'));
        } else if (changedCount === 0) {
            $hint.text('Nic nie zmieniono — zapis zatwierdzi dotychczasowe wartości.');
        } else {
            $hint.text('');
        }
    }

    // ----- Dwukierunkowo: ilość <-> opakowania -----
    //   - zmiana .js-qty   → przelicza .js-packs (= ilość / opakowanie)
    //   - zmiana .js-packs → przelicza .js-qty (= opakowania × opakowanie)
    // Pole opakowań nie ma `name` — wysyłana jest tylko ilość.
    function packOf($row) {
        return parseFloat(($row.attr('data-pack') || '').toString().replace(',', '.'));
    }
    function recalcPacksFromQty($row) {
        const pack = packOf($row);
        const $packs = $row.find('.js-packs');
        const qty = parseNum($row.find('.js-qty').val());
        if (!pack || pack <= 0 || qty === null || qty <= 0) {
            $packs.val('').removeClass('packages-uneven');
            return;
        }
        const packages = qty / pack;
        const wholeOk = Math.abs(packages - Math.round(packages)) < 1e-9;
        $packs.val(wholeOk ? String(Math.round(packages)) : packages.toFixed(4));
        $packs.toggleClass('packages-uneven', !wholeOk);
    }
    function recalcQtyFromPacks($row) {
        const pack = packOf($row);
        const $qty = $row.find('.js-qty');
        const packs = parseNum($row.find('.js-packs').val());
        if (!pack || pack <= 0 || packs === null || packs < 0) {
            return;
        }
        // Mnożenie zawsze daje pełne opakowania — gasimy ostrzeżenie.
        $qty.val(pack * packs);
        $row.find('.js-packs').removeClass('packages-uneven');
    }

    $form.on('input', '.js-qty', function () {
        // Poprawiona ilość gasi komunikat o błędzie.
        $(this).removeClass('is-invalid')
            .closest('.dx-respond-editor').find('.js-qty-error').text('').hide();
        recalcPacksFromQty($(this).closest('div.dx-respond-row'));
        recalc();
    });
    $form.on('input', '.js-packs', function () {
        recalcQtyFromPacks($(this).closest('div.dx-respond-row'));
        recalc();
    });
    // Cena: na żywo przy pisaniu; waluta: przy zmianie wyboru
    // (bootstrap-select odpala natywne `change` na <select>).
    $form.on('input change', '.js-price', recalc);
    $form.on('change changed.bs.select', 'select.js-currency', recalc);

    // Enter w polu ceny nie wysyła całego formularza (zapis tylko przyciskiem).
    $form.on('keydown', '.js-price', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); }
    });

    // ----- Opis części: domyślnie zwinięty (numer dostawcy + nazwa własna ·
    // producent + uwaga), przycisk "Opis" rozwija PEŁNY opis.
    // Każda pozycja rozwija się niezależnie.
    $form.on('click', '.js-details', function () {
        const $b = $(this);
        const open = $b.attr('aria-expanded') !== 'true';
        const label = open ? 'Ukryj opis części' : 'Pokaż opis części';
        $b.attr('aria-expanded', String(open)).toggleClass('is-open', open)
            .attr('title', label).attr('aria-label', label);
        $('#' + $b.attr('aria-controls')).prop('hidden', !open);
    });
    // ----- Klik-aby-edytować: Ilość / Waluta -----
    // Każda taka komórka (.dx-respond-cell) ma widok tylko do odczytu
    // (.dx-respond-view) i edytor (.dx-respond-editor) z PRAWDZIWYMI polami
    // formularza. Pola nigdy nie są usuwane ani disabled — w trybie odczytu
    // chowa je tylko CSS, więc zawsze trafiają do serializacji.
    //   klik / Enter / Spacja na widoku → otwiera edytor,
    //   Enter albo wyjście z pola       → zatwierdza (widok się odświeża),
    //   Esc                             → przywraca wartość sprzed edycji.
    function cellSnapshot($cell) {
        const kind = $cell.attr('data-cell');
        if (kind === 'qty') {
            return { qty: $cell.find('.js-qty').val(), packs: $cell.find('.js-packs').val() };
        }
        if (kind === 'cur') {
            return { cur: $cell.find('select.js-currency').val() };
        }
        return null;
    }

    function cellRestore($cell, snap) {
        if (!snap) { return; }
        const kind = $cell.attr('data-cell');
        if (kind === 'qty') {
            $cell.find('.js-qty').val(snap.qty).removeClass('is-invalid');
            $cell.find('.js-qty-error').text('').hide();
            recalcPacksFromQty($cell.closest('div.dx-respond-row'));
        } else if (kind === 'cur') {
            $cell.find('select.js-currency').selectpicker('val', snap.cur);
        }
    }

    function closeCell($cell, revert, refocus) {
        if (!$cell.hasClass('is-editing')) { return; }
        const snap = $cell.data('snap');
        $cell.removeClass('is-editing');
        $cell.find('.js-view').attr('aria-expanded', 'false');
        if ($cell.attr('data-cell') === 'cur' && $cell.find('.dropdown-menu.show').length) {
            $cell.find('select.js-currency').selectpicker('toggle');   // domknij menu
        }
        if (revert && snap) { cellRestore($cell, snap); }
        recalc();
        if (refocus) { $cell.find('.js-view').trigger('focus'); }
    }

    function openCell($cell) {
        if ($cell.hasClass('is-editing')) { return; }
        // Jedna otwarta komórka naraz — poprzednią zatwierdzamy.
        $form.find('.dx-respond-cell.is-editing').each(function () {
            closeCell($(this), false, false);
        });
        $cell.data('snap', cellSnapshot($cell)).addClass('is-editing');
        $cell.find('.js-view').attr('aria-expanded', 'true');
        const kind = $cell.attr('data-cell');
        if (kind === 'cur') {
            const $sel = $cell.find('select.js-currency');
            $sel.selectpicker('refresh');
            // Po zakończeniu bieżącego kliknięcia — inaczej globalny handler
            // zamknąłby menu tym samym zdarzeniem.
            setTimeout(function () {
                if ($cell.hasClass('is-editing')) { $sel.selectpicker('toggle'); }
            }, 0);
            return;
        }
        const el = $cell.find('.js-qty')[0];
        if (!el) { return; }
        el.focus();
        try { el.select(); } catch (err) { /* number input bez zaznaczenia */ }
    }

    $form.on('click', '.dx-respond-view', function () {
        openCell($(this).closest('.dx-respond-cell'));
    });
    $form.on('keydown', '.dx-respond-view', function (e) {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            openCell($(this).closest('.dx-respond-cell'));
        }
    });
    $form.on('keydown', '.dx-respond-cell.is-editing', function (e) {
        const $cell = $(this);
        if (e.key === 'Escape') {
            e.preventDefault();
            e.stopPropagation();
            closeCell($cell, true, true);
        } else if (e.key === 'Enter' && $cell.attr('data-cell') !== 'cur') {
            // Enter nie wysyła formularza — tylko zatwierdza komórkę.
            e.preventDefault();
            closeCell($cell, false, true);
        }
    });
    // Wyjście z pola poza komórkę zatwierdza (Ilość ma dwa pola — ilość i
    // opakowania — przejście między nimi nie zamyka edytora).
    $form.on('focusout', '.dx-respond-cell[data-cell="qty"]', function (e) {
        const cell = this;
        if (!$(cell).hasClass('is-editing')) { return; }
        if (e.relatedTarget && $.contains(cell, e.relatedTarget)) { return; }
        setTimeout(function () {
            if (!cell.contains(document.activeElement)) { closeCell($(cell), false, false); }
        }, 0);
    });
    // Waluta: menu zamknięte (wybór albo kliknięcie obok) → zatwierdź.
    $form.on('hidden.bs.select', 'select.js-currency', function () {
        const $cell = $(this).closest('.dx-respond-cell');
        setTimeout(function () {
            closeCell($cell, false, $cell.find('.dx-respond-cur').has(document.activeElement).length > 0);
        }, 0);
    });

    // ----- „Uwagi do artykułu" (list__vendor_part.comment) --------------
    // Jak w koszyku (/purchase/cart) i na stronie edycji dokumentu: ołówek
    // otwiera mały edytor w miejscu, „Zapisz" POSTuje do
    // purchases/cart/vendor-part-comment.php i zapisuje NATYCHMIAST.
    //
    // To NIE jest część odpowiedzi na dokument:
    //   - textarea nie ma atrybutu `name` → nie trafia do payloadu,
    //   - edycja nie woła recalc() → nie liczy się do „Zmieniono: N z M",
    //   - nie wpływa na przycisk zapisu odpowiedzi.
    function vpcWrap($el) {
        // Uwaga: klasa kontenera to `.dx-line-vpcomment` (nie `.dx-vpcomment`) —
        // `closest()` dopasuje całe nazwy klas, więc skrócona nazwa nic nie
        // znajdzie i handler wyjdzie bez efektu.
        const $wrap = $el.closest('.dx-line-vpcomment');
        if ($wrap.length === 0) { return null; }
        const $line = $wrap.find('.js-vpc-line').first();
        if ($line.length === 0) { return null; }
        return {
            $line:   $line,
            $wrap:   $wrap,
            $editor: $wrap.find('.js-vpc-editor').first(),
            $input:  $wrap.find('.js-vpc-input').first()
        };
    }

    $form.on('click', '.js-vpc-edit', function (e) {
        e.preventDefault();
        e.stopPropagation();
        const $btn = $(this);
        const ctx = vpcWrap($btn);
        if (!ctx || ctx.$editor.length === 0 || ctx.$input.length === 0) { return; }
        // Surowa wartość z atrybutu data-vp-comment (NIE z wyrenderowanego
        // tekstu — ten może być „Brak").
        const raw = String(ctx.$line.attr('data-vp-comment') || '');
        ctx.$input.val(raw);
        ctx.$editor.removeAttr('hidden');
        // Klasa stanu: chowamy linię odczytu, zostaje sam edytor.
        ctx.$wrap.addClass('is-editing');
        ctx.$input.trigger('focus');
        // Kursor na końcu — operator dopisuje do istniejącej uwagi.
        const v = ctx.$input.val();
        if (v) {
            const el = ctx.$input[0];
            el.selectionStart = el.selectionEnd = v.length;
        }
    });

    $form.on('click', '.js-vpc-cancel', function (e) {
        e.preventDefault();
        const $editor = $(this).closest('.js-vpc-editor');
        const $wrap = $editor.closest('.dx-line-vpcomment');
        // Porzucone wpisane słowa — nic nie idzie na serwer.
        $editor.find('.js-vpc-input').val('');
        // Zamykamy edycję — wraca linia odczytu.
        $wrap.removeClass('is-editing');
        $editor.attr('hidden', true);
    });

    $form.on('click', '.js-vpc-save', function (e) {
        e.preventDefault();
        const $btn = $(this);
        const $editor = $btn.closest('.js-vpc-editor');
        const ctx = vpcWrap($editor);
        if (!ctx) { return; }
        const vpId = parseInt(ctx.$line.find('.js-vpc-edit').attr('data-vp-id') || '0', 10);
        if (!vpId) { return; }
        const val = $.trim(ctx.$input.val() || '');
        const $cancel = $editor.find('.js-vpc-cancel');
        $btn.prop('disabled', true);
        $cancel.prop('disabled', true);

        $.ajax({
            url: COMPONENTS_PATH + '/purchases/cart/vendor-part-comment.php',
            method: 'POST',
            data: { vp_id: vpId, comment: val },
            dataType: 'json'
        }).done(function (r) {
            if (r && r.success) {
                // Aktualizujemy linię w miejscu (bez przeładowania strony).
                ctx.$line.attr('data-vp-comment', val);
                ctx.$line.find('.js-vpc-text')
                    .text(val === '' ? 'Brak' : val)
                    .toggleClass('is-empty', val === '');
                ctx.$line.find('.js-vpc-edit')
                    .attr('title', val === '' ? 'Dodaj uwagi' : 'Edytuj uwagi')
                    .attr('aria-label', val === '' ? 'Dodaj uwagi do artykułu' : 'Edytuj uwagi do artykułu');
                // Zamykamy edycję — wraca linia odczytu.
                ctx.$wrap.removeClass('is-editing');
                $editor.attr('hidden', true);
                setAlert('Komentarz zapisany.', 'success');
            } else {
                setAlert('Błąd zapisu komentarza: ' + (r && r.error ? r.error : 'nieznany'), 'danger');
                $btn.prop('disabled', false);
                $cancel.prop('disabled', false);
            }
        }).fail(function () {
            setAlert('Błąd zapisu komentarza.', 'danger');
            $btn.prop('disabled', false);
            $cancel.prop('disabled', false);
        });
    });

    $form.on('keydown', '.js-vpc-input', function (e) {
        if (e.key === 'Escape') {
            e.preventDefault();
            $(this).closest('.js-vpc-editor').find('.js-vpc-cancel').trigger('click');
        } else if (e.key === 'Enter') {
            // Enter nigdy nie wysyła formularza odpowiedzi.
            e.preventDefault();
            if (e.ctrlKey || e.metaKey) {
                $(this).closest('.js-vpc-editor').find('.js-vpc-save').trigger('click');
            }
        }
    });

    // ----- Akcje zbiorcze -----
    $('#respondFillAllBtn').on('click', function () {
        $rows.each(function () {
            const $row = $(this);
            $row.find('.js-qty').val($row.attr('data-asked-qty') || '');
            recalcPacksFromQty($row);
        });
        recalc();
    });
    $('#respondClearAllBtn').on('click', function () {
        // Puste pole ceny: RFQ = pozycja niewyceniona, PO = "zachowaj cenę
        // zapisaną w bazie" (przywrócenie ceny z dokumentu).
        $rows.each(function () {
            $(this).find('.js-price').val('');
        });
        recalc();
    });

    // ----- Pierwsze renderowanie -----
    $rows.each(function () { recalcPacksFromQty($(this)); });
    recalc();

    // ----- Zapis -----
    $form.on('submit', function (e) {
        e.preventDefault();
        let firstBad = null;
        $rows.each(function () {
            const $row = $(this);
            const $qty = $row.find('.js-qty');
            const $err = $row.find('.js-qty-error');
            const qtyS = ($qty.val() || '').toString().replace(',', '.').trim();
            const qty  = parseFloat(qtyS);
            if (qtyS === '' || isNaN(qty) || qty <= 0) {
                $qty.addClass('is-invalid');
                $err.text('Ilość musi być większa od zera.').show();
                if (!firstBad) { firstBad = $row; }
            } else {
                $qty.removeClass('is-invalid');
                $err.text('').hide();
            }
        });
        if (firstBad) {
            setAlert('Uzupełnij ilość we wszystkich pozycjach.', 'warning');
            // Pierwsza błędna pozycja: otwieramy edytor ilości z komunikatem.
            // Pozostałe zostają w trybie odczytu, ale ich widok jest czerwony.
            openCell(firstBad.find('[data-cell="qty"]'));
            firstBad.find('.js-qty').trigger('focus');
            return;
        }

        // PO: żadna pozycja nie może zostać bez ceny. Puste pole ceny
        // oznacza "zachowaj cenę z bazy" — blokada dotyczy pozycji, które
        // po zastosowaniu tej reguły nadal nie mają ceny > 0 (UI: "brak ceny").
        // RFQ tego nie wymaga: pozycja niewyceniona jest poprawną odpowiedzią.
        if (IS_PO) {
            const noPriceRows = rows.filter(function (r) {
                const eff = effectivePrice(r);
                return eff === null || eff <= 0;
            });
            if (noPriceRows.length > 0) {
                setAlert(
                    'Uzupełnij cenę w ' + noPriceRows.length + ' '
                    + (noPriceRows.length === 1 ? 'pozycji' : 'pozycjach')
                    + ' — zamówienia bez cen nie można potwierdzić.',
                    'warning'
                );
                // Fokus na pierwszym polu ceny bez ceny: pole jest zawsze
                // widoczne (nie jest ukryte w edytorze komórki).
                const $price = noPriceRows[0].$price;
                const el = $price[0];
                if (el) {
                    $price.trigger('focus');
                    if (typeof el.scrollIntoView === 'function') {
                        el.scrollIntoView({ block: 'center', behavior: 'smooth' });
                    }
                }
                return;
            }
        }

        // Blokada + spinner.
        const $buttons = $form.find('button').add($btn)
            .add($('#respondFillAllBtn')).add($('#respondClearAllBtn'))
            .prop('disabled', true);
        const originalHtml = $btn.html();
        $btn.html('<span class="spinner-border spinner-border-sm mr-1"></span> Zapisuję…');

        // Endpoint PO przyjmuje confirmed_at jako 'Y-m-d H:i:s' (lub puste = teraz),
        // a pole datetime-local zwraca 'YYYY-MM-DDTHH:MM' — normalizujemy.
        // Puste pola cen zostają puste (serwer: RFQ → NULL, PO → zachowaj dotychczasową).
        const payload = $form.serializeArray().map(function (f) {
            if (IS_PO && f.name === 'confirmed_at' && f.value) {
                let v = f.value.replace('T', ' ');
                if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/.test(v)) { v += ':00'; }
                return { name: f.name, value: v };
            }
            return f;
        });

        $.ajax({
            url: ENDPOINT,
            method: 'POST',
            data: $.param(payload),
            dataType: 'json'
        }).done(function (r) {
            if (!r || !r.success) {
                setAlert((r && r.error) || 'Błąd zapisu odpowiedzi.', 'danger');
                $buttons.prop('disabled', false);
                $btn.html(originalHtml);
                return;
            }
            setAlert(IS_PO ? 'Potwierdzenie odpowiedzi zapisane. Przekierowanie…' : 'Odpowiedź zapisana. Przekierowanie…', 'success');
            setTimeout(function () {
                window.location.href = window.location.origin + ROOT_DIR + '/admin/purchase/documents/edit?id=' + DOC_ID + '&type=' + TYPE;
            }, 700);
        }).fail(function (xhr) {
            setAlert((xhr.responseJSON && xhr.responseJSON.error) || 'Błąd komunikacji z serwerem.', 'danger');
            $buttons.prop('disabled', false);
            $btn.html(originalHtml);
        });
    });
});
