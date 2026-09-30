/**
 * Kanoniczny format listy pozycji dokumentu (RFQ/PO) — jedyne źródło
 * prawdy dla:
 *   1. clipboard HTML+plain-text (przycisk „Kopiuj pozycje"
 *      w /admin/purchase/documents/send) → htmlTable + plainText
 *   2. podstawienia {items} w temacie/treści maila (krok 4 wysyłki)
 *      → plainText (separator ' | ')
 *   3. podglądu szablonu w /admin/purchase/email-drafts/edit
 *      → plainText (separator ' | ')
 *
 * Łączone przez AtteItemsFormat, żeby zmiana w jednym miejscu nie
 * rozjeżdżała się z drugim.
 *
 * `formatQty` i `plainText` były wcześniej lokalne w documents-send.js
 * i documents-edit.js. Po refaktorze bullets → tabela (2026-09):
 *   - plainText → separator ' | ' (nagłówek + wiersze) — czytelne
 *     w textarei podczas edycji, stabilne po wklejeniu w Gmaila.
 *   - htmlTable → <table> z inline border="1" — wklejone w Gmaila
 *     renderuje się jako prawdziwa tabela z ramką.
 * `formatPrice` / `escapeHtml` przeniesione z documents-send.js, żeby
 * PlainText + HtmlTable mogły z nich korzystać spójnie.
 *
 * `previewBody` — wspólny helper podglądu „na żywo" dla obu stron
 * (krok 4 wysyłki + edytor szablonów): wkleja htmlTable() w miejsce
 * pozycji w tekście ciała maila, resztę tekstu escapuje. Domyślnie
 * dzieli na literał '{items}' (email-draft-edit, gdzie token
 * przeżywa substytucję, bo vars świadomie nie ma klucza `items`);
 * documents-send.js podstawia {items} NATYCHMIAST przy generowaniu
 * treści (buildTemplateVars().items to już TSV), więc tam trzeci
 * argument `marker` przekazuje dokładny string TSV do zamiany.
 */
window.AtteItemsFormat = (function () {
    'use strict';

    /**
     * Polskie formatowanie ilości — bez miejsc dziesiętnych dla liczb
     * całkowitych (parseFloat + toFixed(4) obcina śmieci zmiennoprzecinkowe
     * po stronie backendu, a +parseFloat(...toString()) zrzuca końcowe
     * zera). Separator tysięcy nie jest tu dorzucany — to różni tę
     * funkcję od formatPrice.
     */
    function formatQty(n) {
        if (n === null || n === undefined || n === '') return '—';
        const v = parseFloat(n);
        if (isNaN(v)) return '—';
        return String(+parseFloat(parseFloat(v).toFixed(4)).toString());
    }

    /**
     * Polskie formatowanie ceny — spacja niełamliwa jako separator
     * tysięcy (locale en-US + replace ,→space), trailing zeros obcięte.
     * Null/undefined/pusty/NaN → '—' (mirror formatQty).
     */
    function formatPrice(n) {
        if (n === null || n === undefined || n === '') return '—';
        const v = parseFloat(n);
        if (isNaN(v)) return '—';
        let s = v.toLocaleString('en-US', { useGrouping: true, maximumFractionDigits: 10 });
        s = s.replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, '');
        return s.replace(/,/g, ' ');
    }

    /**
     * Minimalny escape do tabel HTML — `& < > " '`. Używany przez
     * htmlTable() na każdej komórce oraz przez previewBodyMarked()
     * (gdzie podgląd zachowuje białe znaki przez CSS `white-space:
     * pre-wrap` — nie potrzeba `<br>`).
     */
    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    /**
     * Escape HTML z konwersją `\n` → `<br>` — dla treści wklejanej do
     * Gmaila (HTML ignoruje białe znaki, więc bez `<br>` tekst zleje
     * się w jeden akapit). Wywoływane przez `clipboardBodyFromText()`
     * na fragmencie BODY przed/za tabelą oraz przez `htmlTable()` na
     * komórkach, które mogą mieć wieloliniowy tekst (np. komentarz
     * pozycji). CRLF najpierw normalizowane do LF.
     */
    function escapeHtmlBreaks(s) {
        return escapeHtml(s).replace(/\r\n?/g, '\n').replace(/\n/g, '<br>');
    }

    /**
     * Dzieli tekst na akapity wg pustych linii (`\n\n+`) i owija każdy
     * w `<p style="margin:0 0 .8em 0">`. Pojedyncze `\n` wewnątrz
     * akapitu zamienia na `<br>`. Wyjście jest gotowe do wklejenia w
     * ciało maila w Gmailu — układ akapitowy jak w edytorze tekstu.
     */
    function paragraphsHtml(text) {
        return String(text || '')
            .replace(/\r\n?/g, '\n')
            .split(/\n[ \t]*\n+/)
            .filter(function (p) { return p.trim() !== ''; })
            .map(function (p) {
                return '<p style="margin:0 0 .8em 0;">'
                    + escapeHtml(p).replace(/\n/g, '<br>')
                    + '</p>';
            })
            .join('');
    }

    /**
     * Sanityzator komórki — spłaszcza taby i znaki nowej linii do
     * spacji, żeby nie rozbijały układu kolumn po wklejeniu w Gmaila
     * lub edycji w textarei. Nazwa `tsvCell` z historii (separator
     * był kiedyś tabulatorem); dziś separator to ' | ', ale funkcja
     * robi to samo — wymiata whitespace, który by złamał kolumny.
     */
    function tsvCell(s) {
        return String(s == null ? '' : s).replace(/[\t\r\n]+/g, ' ');
    }

    /**
     * Vendor part number z fallbackiem na producer part number —
     * historyczny wzorzec z buildItemsPlainText (vendor → producer).
     */
    function refOf(ln) {
        return ln.vendorPartNo || ln.producerPartNo || '';
    }

    /**
     * Komórka Price: `"<formatPrice>"` albo `"<formatPrice> <currency>"`.
     * Kiedy cena jest null/undefined (np. RFQ przed odpowiedzią
     * dostawcy), formatPrice zwraca '—'; sufiks waluty doklejamy
     * tylko wtedy, gdy waluta jest jawnie podana na pozycji (albo
     * w ctx jako fallback dokumentu) — nigdy '— PLN' dla pustego
     * dokumentu, który nie miałby żadnej waluty w ogóle.
     */
    function priceOf(ln, ctx) {
        const p = formatPrice(ln.unitPrice);
        const cur = (ln && ln.currency)
            ? String(ln.currency)
            : (ctx && ctx.currency ? String(ctx.currency) : '');
        return cur ? p + ' ' + cur : p;
    }

    /** Nagłówki kolumn — wspólne dla plainText i htmlTable. */
    const HEADERS = ['ID', 'VendorPartNo', 'QTY', 'JM', 'Producent', 'Price'];

    /**
     * Tab-separated values: wiersz nagłówka + jeden wiersz na pozycję.
     * `ctx.documentNumber` jest wklejane do kolumny ID w każdym
     * wierszu (identyfikator dokumentu ten sam dla wszystkich pozycji).
     *
     * Puste `lines` → nagłówek + jeden wiersz
     * "(dokument nie zawiera pozycji)" w kolumnie VendorPartNo,
     * reszta pusta (mirror starego tekstu zastępczego z buildTemplateVars,
     * żeby w podglądzie szablonu i w {items} nie została goła pustka).
     */
    /**
     * Separator kolumn: spacja + pipe + spacja (` | `). Wybrany, bo
     * w textarei widać go na pierwszy rzut oka (taby giną w białej
     * przestrzeni), a w Gmailu renderuje się stabilnie jako zwykły
     * tekst — ręczna edycja body nie musi walczyć z niewidocznymi
     * znakami.
     */
    const COL_SEP = ' | ';

    function plainText(lines, ctx) {
        ctx = ctx || {};
        const docNumber = tsvCell(ctx.documentNumber || '');
        const list = lines || [];
        const headerRow = HEADERS.join(COL_SEP);
        if (list.length === 0) {
            return headerRow + '\n'
                + [docNumber, '(dokument nie zawiera pozycji)', '', '', '', ''].join(COL_SEP);
        }
        const rows = list.map(function (ln) {
            return [
                docNumber,
                tsvCell(refOf(ln)),
                tsvCell(formatQty(ln.quantityOrdered)),
                tsvCell(ln.unitName),
                tsvCell(ln.producerName),
                tsvCell(priceOf(ln, ctx)),
            ].join(COL_SEP);
        });
        return [headerRow].concat(rows).join('\n');
    }

    /**
     * Pełny <table> z inline stylami na KAŻDEJ komórce — Gmail renderuje
     * go identycznie jak nasz preview w przeglądarce (Gmail nie czyta
     * <style> ani klas CSS, więc wszystko musi być inline).
     *
     * Puste `lines` → jeden wiersz `<td colspan="6">(dokument nie
     * zawiera pozycji)</td>` z tymi samymi stylami komórki.
     *
     * Styl komórki celowo powtórzony inline (nie na `<col>`/`<tbody>`):
     * Gmail obcina część atrybutów tabeli, ale inline style na `<th>`/`<td>`
     * zachowuje. `cellpadding="4"` na `<table>` to tania asekuracja dla
     * klientów, które ignorują inline padding.
     */
    const CELL_STYLE    = 'padding:4px 8px;border:1px solid #dee2e6;vertical-align:top;';
    const HEADER_STYLE  = CELL_STYLE + 'background-color:#f6f7f9;font-weight:600;white-space:nowrap;';
    const TABLE_STYLE   = 'border-collapse:collapse;margin:.55rem 0;font-size:.78rem;line-height:1.45;';

    function htmlTable(lines, ctx, headers) {
        ctx = ctx || {};
        const cols = (Array.isArray(headers) && headers.length > 0) ? headers : HEADERS;
        const docNumber = String(ctx.documentNumber || '');
        const list = lines || [];
        const head = '<thead><tr>'
            + cols.map(function (h) {
                return '<th style="' + HEADER_STYLE + '">' + escapeHtml(h) + '</th>';
            }).join('')
            + '</tr></thead>';
        let body;
        if (list.length === 0) {
            body = '<tbody><tr><td colspan="6" style="' + CELL_STYLE + '">'
                + '(dokument nie zawiera pozycji)'
                + '</td></tr></tbody>';
        } else {
            const rows = list.map(function (ln) {
                return '<tr>'
                    + '<td style="' + CELL_STYLE + '">' + escapeHtmlBreaks(docNumber) + '</td>'
                    + '<td style="' + CELL_STYLE + '">' + escapeHtmlBreaks(refOf(ln)) + '</td>'
                    + '<td style="' + CELL_STYLE + '">' + escapeHtmlBreaks(formatQty(ln.quantityOrdered)) + '</td>'
                    + '<td style="' + CELL_STYLE + '">' + escapeHtmlBreaks(ln.unitName) + '</td>'
                    + '<td style="' + CELL_STYLE + '">' + escapeHtmlBreaks(ln.producerName) + '</td>'
                    + '<td style="' + CELL_STYLE + '">' + escapeHtmlBreaks(priceOf(ln, ctx)) + '</td>'
                  + '</tr>';
            }).join('');
            body = '<tbody>' + rows + '</tbody>';
        }
        return '<table border="1" cellpadding="4" cellspacing="0" style="' + TABLE_STYLE + '">'
            + head + body + '</table>';
    }

    /**
     * Próbka obiektów o kształcie doc.lines. Używana tylko przez
     * podgląd szablonu w email-draft-edit; dane żywe (krok 4 wysyłki)
     * przychodzą z documents-send-ajax-load.php.
     *
     * Celowo bez unitPrice/currency — RFQ w seedzie nie ma jeszcze
     * wyceny, więc komórka Price renderuje się jako '—' w podglądzie
     * szablonu. producerName jest uzupełniony (Samsung/Yageo), żeby
     * kolumna Producent nie była pusta.
     */
    const sampleLines = [
        { partName: 'Kondensator 100nF', producerPartNo: 'CL10B104KB8NNNC',
          vendorPartNo: '', quantityOrdered: 5000, unitName: 'szt.',
          producerName: 'Samsung',
          comment: 'tolerancja 10%' },
        { partName: 'Rezystor 10k 0805', producerPartNo: 'RC0805FR-0710KL',
          vendorPartNo: '', quantityOrdered: 2500, unitName: 'szt.',
          producerName: 'Yageo', comment: '' },
    ];

    /**
     * Podgląd treści z tabelą HTML wklejoną w miejsce pozycji.
     * Dzieli `bodyText` na `marker` (domyślnie literał '{items}'),
     * escapuje każdy segment tekstu i wkleja między nimi surowy
     * `itemsHtml` (bez escape — to prawdziwy <table>).
     *
     * `marker` puste/undefined → '{items}' (email-draft-edit: vars
     * przekazane do edApplyTemplate nie mają klucza `items`, więc
     * token przeżywa substytucję i jest tu literałem do podziału).
     * documents-send.js podaje trzeci argument z dokładnym stringiem
     * TSV, bo tam {items} jest już podstawiony na etapie generowania
     * treści (buildTemplateVars/buildEmailBodyDefault) — nie ma
     * czego szukać jako literału, trzeba szukać samego TSV.
     *
     * Brak dopasowania markera (np. operator ręcznie zmienił blok
     * pozycji) → cała treść wraca jako czysty escapowany tekst, bez
     * zgadywania gdzie wkleić tabelę.
     */
    function previewBody(bodyText, itemsHtml, marker) {
        const token = (marker === undefined || marker === null || marker === '')
            ? '{items}' : String(marker);
        return String(bodyText || '')
            .split(token)
            .map(function (seg) { return escapeHtml(seg); })
            .join(itemsHtml || '');
    }

    /**
     * Markery otaczające tabelę pozycji w treści body. Wybór celowy:
     *   - `<<<…>>>` to składnia, której nikt nie wpisuje ręcznie w treść
     *     maila handlowego (polskie maile nie zawierają takich nawiasów),
     *   - separator `\n` przed/po sprawia, że blok w textarei stoi
     *     osobno, a nie klei się z sąsiednim akapitem,
     *   - marker jest ZEWNĄTRZ tabeli, więc edycja komórki nie psuje
     *     dopasowania — splitStart/splitEnd lokalizują tabelę nawet gdy
     *     operator wyrzucił pół wierszy.
     *
     * Zmiana tych stringów to ZMIANA SCHEMATU — wymaga wyczyszczenia
     * `state.emailBodyEdited` (stara treść body trzyma stare markery).
     */
    const MARKER_START = '\n<<<ATTE_ITEMS_TABLE>>>\n';
    const MARKER_END   = '\n<<<END_ATTE_ITEMS_TABLE>>>\n';

    /**
     * Owija tekst tabeli markerami — wywoływane przez buildTemplateVars
     * i buildEmailBodyDefault w documents-send.js przy generowaniu body.
     * Dzięki temu treść body ma stabilne „kotwice" lokalizujące tabelę,
     * niezależnie od tego, co operator w niej zmieni.
     */
    function wrapItems(tableText) {
        return MARKER_START + String(tableText || '') + MARKER_END;
    }

    /**
     * Podgląd body z markerami START/END (documents-send.js). W odróżnieniu
     * od `previewBody` nie szuka JEDNEGO substringu — znajduje SPAN
     * między dwoma markerami i zastępuje go tabelą HTML. Edycja komórek
     * wewnątrz tabeli nie psuje lokalizacji (markery zostają na miejscu).
     *
     * Bezpieczeństwo: wszystko asidega do `escapeHtml` z wyjątkiem
     * `itemsHtml`, który jest zaufany (produkowany przez nasze
     * `htmlTable()` na bazie `state.doc.lines`).
     *
     * Brak obu markerów → fallback jak w `previewBody`: escape całości
     * + tabela doklejona na końcu, żeby preview nie został pusty.
     */
    function previewBodyMarked(bodyText, itemsHtml) {
        const text = String(bodyText || '');
        const startIdx = text.indexOf(MARKER_START);
        if (startIdx === -1) {
            // Brak markera START — albo operator usunął markery, albo
            // korzystamy z cudzego szablonu, który ich nie ma. Doklejamy
            // tabelę na końcu, żeby operator nie stracił podglądu.
            return escapeHtml(text) + (itemsHtml || '');
        }
        const endIdx = text.indexOf(MARKER_END, startIdx + MARKER_START.length);
        if (endIdx === -1) {
            // Tylko START bez END — ten sam fallback, co wyżej.
            return escapeHtml(text) + (itemsHtml || '');
        }
        const before = text.substring(0, startIdx);
        const after  = text.substring(endIdx + MARKER_END.length);
        return escapeHtml(before) + (itemsHtml || '') + escapeHtml(after);
    }

    /**
     * Parser pipe-separated tabeli z powrotem do postaci `{ headers, rows }`.
     * Wejście: tekst w formacie plainText() (nagłówek + wiersze).
     * Wyjście: obiekt z:
     *   - `headers`: tablica stringów z pierwszej linii (mogą być
     *     edytowane przez operatora, np. „Price" → „Prices")
     *   - `rows`: tablica obiektów o kształcie zgodnym z doc.lines,
     *     do bezpośredniego przekazania do htmlTable().
     *
     * Używane przez „Kopiuj pozycje" + preview w documents-send.js, żeby
     * odzwierciedlić RĘCZNE EDYCJE operatora w textarei zamiast
     * kopiować kanoniczny state.doc.lines. Parser jest odporny na:
     *   - puste wiersze (filtrujemy),
     *   - myślnik `—` w komórkach ilość/cena (null → formatQty/Price
     *     zwracają `—`),
     *   - różną liczbę kolumn (brakujące → pusty string),
     *   - samą linię nagłówka bez wierszy (zwraca pustą tablicę `rows`,
     *     caller decyduje czy to fallback czy fallback do placeholder).
     *
     * Kolumny (stała kolejność, mirror plainText()):
     *   0: ID              (dokument — ten sam we wszystkich wierszach,
     *                        pomijamy w rows, bo htmlTable() czyta go z ctx)
     *   1: VendorPartNo
     *   2: QTY
     *   3: JM
     *   4: Producent
     *   5: Price           (opcjonalnie z sufiksem waluty, np. „1.23 PLN")
     *
     * Nagłówki mapowane są po POZYCJI (indeksie), nie po nazwie — bo
     * operator może je edytować, a kolumna wiersza zawsze jest na
     * swoim miejscu.
     */
    function parsePipeTable(text) {
        const lines = String(text || '')
            .replace(/\r\n?/g, '\n')
            .split('\n')
            .filter(function (l) { return l.trim() !== ''; });
        if (lines.length < 1) return { headers: [], rows: [] };

        const headers = lines[0].split(' | ').map(function (h) { return h.trim(); });
        const rows = lines.slice(1).map(function (line) {
            const cells = line.split(' | ');
            // Komórka ceny może mieć formę "1.23 PLN" — wyciągamy sam
            // numer, walutę zostawiamy pustą (ctx.currency pokryje ją
            // dla kanonicznych danych; dla edycji ręcznych brak sufiksu
            // jest akceptowalny — operator edytuje treść, nie walutę).
            const priceRaw = (cells[5] || '').trim();
            const priceNum = priceRaw === '—' ? ''
                : priceRaw.replace(/[^\d.,\-]/g, '').replace(',', '.');
            return {
                vendorPartNo:    (cells[1] || '').trim(),
                quantityOrdered: (cells[2] || '').trim(),
                unitName:        (cells[3] || '').trim(),
                producerName:    (cells[4] || '').trim(),
                unitPrice:       priceNum,
            };
        });
        return { headers: headers, rows: rows };
    }

    /**
     * Buduje payload dla „Kopiuj pozycje" na podstawie AKTUALNEJ treści
     * textarei (nie kanonicznego stanu dokumentu). Dzięki temu edycje
     * operatora w komórkach tabeli (ilość, numer części, cena) trafiają
     * do schowka, nie tylko do podglądu.
     *
     * Zwraca `{ plainText, htmlTable }` albo `null`, jeśli z body nie
     * da się wyciągnąć pipe-table (brak markerów, marker uszkodzony,
     * pusta lista po parsowaniu). Caller (documents-send.js) traktuje
     * `null` jako sygnał do użycia kanonicznego fallbacku ze state.doc.
     */
    function clipboardItemsFromBody(body, ctx) {
        ctx = ctx || {};
        const text = String(body || '').replace(/\r\n?/g, '\n');
        const startIdx = text.indexOf(MARKER_START);
        if (startIdx === -1) return null;
        const endIdx = text.indexOf(MARKER_END, startIdx + MARKER_START.length);
        if (endIdx === -1) return null;
        const spanText = text.substring(
            startIdx + MARKER_START.length,
            endIdx
        );
        const parsed = parsePipeTable(spanText);
        if (parsed.rows.length === 0) return null;
        return {
            plainText: spanText,
            htmlTable: htmlTable(parsed.rows, ctx, parsed.headers),
            parsed: parsed.rows,
            headers: parsed.headers,
        };
    }

    /**
     * Pełny payload CAŁEGO BODY dla „Kopiuj treść maila". Zamienia span
     * między markerami na tabelę HTML (jak previewBodyMarked), ale zwraca
     * OBIE wersje — `html` (z `<table>` inline + akapity `<p>` dla
     * Gmaila) i `plainText` (z pipe-text, do schowka w text/plain i
     * jako fallback dla przeglądarek bez ClipboardItem).
     *
     * Edycje operatora w komórkach tabeli są zachowane w obu wersjach:
     *   - html: parser → htmlTable → prawdziwy <table> w Gmailu
     *   - plainText: oryginalny pipe-text (z ewentualnymi edycjami)
     *
     * Brak markerów w body — zwraca treść verbatim (paragraphsHtml dla
     * html, raw dla plainText); caller traktuje to jako „brak tabeli
     * w tym mailu, wyślij co masz".
     */
    function clipboardBodyFromText(body, ctx) {
        ctx = ctx || {};
        const text = String(body || '').replace(/\r\n?/g, '\n');
        const startIdx = text.indexOf(MARKER_START);
        if (startIdx === -1) {
            return { plainText: text, html: paragraphsHtml(text) };
        }
        const endIdx = text.indexOf(MARKER_END, startIdx + MARKER_START.length);
        if (endIdx === -1) {
            return { plainText: text, html: paragraphsHtml(text) };
        }
        const before   = text.substring(0, startIdx);
        const spanText = text.substring(startIdx + MARKER_START.length, endIdx);
        const after    = text.substring(endIdx + MARKER_END.length);

        const parsed = parsePipeTable(spanText);
        // Tabela HTML: z edytowanych linii (gdy parser coś zwrócił),
        // pusty string gdy markery były, ale ciało puste (operator wyciął
        // wszystkie wiersze). Brak tabeli jest OK — caller wklei body
        // z samymi akapitami i Gmail to zrozumie.
        const tableHtml = (parsed.rows.length > 0)
            ? htmlTable(parsed.rows, ctx, parsed.headers)
            : '';
        return {
            plainText: before + spanText + after,
            html:      paragraphsHtml(before) + tableHtml + paragraphsHtml(after),
        };
    }

    return {
        formatQty: formatQty,
        formatPrice: formatPrice,
        escapeHtml: escapeHtml,
        escapeHtmlBreaks: escapeHtmlBreaks,
        paragraphsHtml: paragraphsHtml,
        plainText: plainText,
        htmlTable: htmlTable,
        previewBody: previewBody,
        previewBodyMarked: previewBodyMarked,
        wrapItems: wrapItems,
        parsePipeTable: parsePipeTable,
        clipboardItemsFromBody: clipboardItemsFromBody,
        clipboardBodyFromText: clipboardBodyFromText,
        sampleLines: sampleLines,
        // Eksportowane też dla ewentualnych testów diagnostycznych.
        MARKER_START: MARKER_START,
        MARKER_END: MARKER_END,
    };
})();