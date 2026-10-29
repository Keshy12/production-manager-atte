<?php
/**
 * "Oznacz odpowiedź" / "Potwierdź odpowiedź" — dedykowana strona dla RFQ i PO
 * w stanie `sent`, gdzie operator wpisuje wynegocjowane ceny/waluty od
 * dostawcy i jednym klikiem przechodzi dokument dalej (RFQ → `responded`,
 * PO → `confirmed`). Następca "kroku odpowiedź" w wizzardzie
 * documents-send.php — wizzard jest wieloetapowy i niewłaściwy dla tego
 * jednoetapowego przepływu, więc wyodrębniliśmy tę stronę.
 *
 * Routing: /admin/purchase/documents/respond?id=N&type=rfq|po.
 * Strona obsługuje dwa typy z jednym układem: RFQ (document-rfq-respond-ajax.php)
 * oraz zamówienie PO (document-po-respond-ajax.php — nr zamówienia u dostawcy
 * + data potwierdzenia; pusta cena = zachowaj dotychczasową).
 * Walidacja serwerowa:
 *   - isAdmin (analogicznie do documents-send.php)
 *   - type=rfq|po (inne typy → 404)
 *   - dokument istnieje
 *   - stan === 'sent' (bez tego przekierowanie na stronę edycji z flagą
 *     błędu — stan mógł się zmienić między otwarciem strony a klikiem)
 *
 * Wygląd: ten sam system co strona edycji (documents-edit.php) — klasy rx-*
 * z receipts-ui.css + warstwa dx-respond-* z documents-respond-ui.css.
 * Kolor typu (RFQ bursztyn / PO niebieski) daje klasa dx-edit--rfq|po.
 *
 * Widoczność zmian: każdy wiersz pamięta wartości zapisane w bazie
 * (data-orig-price + wartości początkowe pól), a documents-respond.js na
 * żywo pokazuje "było → jest", różnicę ceny, podświetla zmienione wiersze
 * i liczy sumę dokumentu oraz jej różnicę względem zapisanej.
 *
 * Kontrakt z backendem (bez zmian): pola id, items[<id>][quantity|unit_price|
 * currency], RFQ: vendor_reference + responded_at, PO:
 * vendor_po_number + confirmed_at. Formularz niesie data-respond-type /
 * data-respond-id. Uwaga do pozycji dokumentu NIE jest już częścią tej
 * strony — operator edytuje „Uwagi do artykułu" (list__vendor_part.comment)
 * w miejscu, a zapis idzie osobnym POSTem do
 * purchases/cart/vendor-part-comment.php (jak w koszyku).
 *
 * UI: waluta to bootstrap-select (ładowany globalnie przez header.php) z
 * `data-width="100%"` + `data-container` wskazującym na komórkę + `min-width:0`
 * na wrapperze (patrz AGENTS.md).
 */
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Master\CurrencyRepository;
use Atte\Utils\Purchase\Order\RFQRepository;
use Atte\Utils\Purchase\Order\RFQItemRepository;
use Atte\Utils\Purchase\Order\PurchaseOrderRepository;
use Atte\Utils\Purchase\Order\PurchaseOrderItemRepository;

if (!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    header("Location: http://" . BASEURL . "/");
    exit();
}

$type = (string)($_GET['type'] ?? '');
if ($type !== 'rfq' && $type !== 'po') {
    http_response_code(404);
    echo '<div class="container-fluid w-75 mt-3"><div class="alert alert-danger">Nieprawidłowy typ dokumentu (wymagane ?type=rfq lub ?type=po).</div></div>';
    return;
}
$isPo = ($type === 'po');
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(404);
    echo '<div class="container-fluid w-75 mt-3"><div class="alert alert-danger">Brak id dokumentu.</div></div>';
    return;
}

$MsaDB    = MsaDB::getInstance();
$curRepo  = new CurrencyRepository($MsaDB);

// Dwa typy dokumentów, jeden kod strony: encje pozycji RFQItem i
// PurchaseOrderItem wystawiają te same pola, których potrzebuje tabela.
if ($isPo) {
    $doc = (new PurchaseOrderRepository($MsaDB))->getById($id);
    $itemRepo = new PurchaseOrderItemRepository($MsaDB);
} else {
    $doc = (new RFQRepository($MsaDB))->getById($id);
    $itemRepo = new RFQItemRepository($MsaDB);
}
if ($doc === null) {
    http_response_code(404);
    echo '<div class="container-fluid w-75 mt-3"><div class="alert alert-danger">'
        . ($isPo ? 'Zamówienie' : 'Zapytanie') . ' #' . (int)$id . ' nie istnieje.</div></div>';
    return;
}

// Stan inny niż 'sent' → z powrotem na stronę edycji z flagą błędu.
// Czystsze niż render pustej strony z alertem — operator wraca tam,
// gdzie kontekst (pozycje, komentarze, konwersja do PO) jest dostępny.
// Strona edycji (documents-edit.php) wyświetla komunikat z ?respond_error=.
if ($doc->state !== 'sent') {
    header(
        'Location: http://' . BASEURL
        . '/admin/purchase/documents/edit?id=' . (int)$id . '&type=' . $type
        . '&respond_error=' . rawurlencode(
            'Odpowiedź można oznaczyć tylko na wysłanym ' . ($isPo ? 'dokumencie' : 'zapytaniu')
            . ' (bieżący stan: ' . $doc->state . ').'
        )
    );
    exit();
}

$items = $isPo ? $itemRepo->getByPo($id) : $itemRepo->getByRfq($id);

// Aktywne waluty do pickera. Bez getActiveEnsuring() — nie mamy tu
// vendor-default flow, a każda pozycja ma już currency zapisaną w
// bazie; jeśli tam leży coś niestandardowego, dropdown też powinien
// to pokazać (zapobiega "wybrana wartość znika z listy"). Dlatego
// budujemy listę lokalnie, dolewając ewentualne niestandardowe kody
// z pozycji.
$activeCurrencies = $curRepo->getActive();
$codesInItems = [];
foreach ($items as $it) {
    if (!empty($it->currency)) {
        $codesInItems[$it->currency] = true;
    }
}
$currenciesList = $activeCurrencies;
foreach (array_keys($codesInItems) as $code) {
    $exists = false;
    foreach ($currenciesList as $row) {
        if ($row['code'] === $code) { $exists = true; break; }
    }
    if (!$exists) {
        $currenciesList[] = ['code' => $code, 'name' => $code . ' (nieaktywna — spoza listy)'];
    }
}
// Zamieniamy na mapę code => name do szybkiego lookupu w szablonie.
$currencies = [];
foreach ($currenciesList as $row) {
    $currencies[$row['code']] = $row['name'];
}

// ---- Formatery --------------------------------------------------------
// Wartość pola input[type=number]: bez separatora tysięcy (przeglądarka
// odrzuca "1 000" i renderuje puste pole).
$rawQty = function ($q) {
    return rtrim(rtrim(number_format((float)$q, 4, '.', ''), '0'), '.');
};
// Ścisła wersja dla DECIMAL(30,10): obcina tylko zbędne zera po kropce.
// Uwaga: cena PO dociera z bazy jako float (PurchaseOrderItem::$unitPrice),
// więc dla dokładności wyższej niż ~15 cyfr znaczących wartość w polu
// jest już zaokrąglona przez PHP — nie obiecujemy tu cyfra do cyfry.
$rawDecimal = function ($v) {
    $s = (string)$v;
    if (strpos($s, '.') !== false) {
        $s = rtrim(rtrim($s, '0'), '.');
    }
    return ($s === '' || $s === '-') ? '0' : $s;
};
$nbsp = "\u{00A0}";
// Tekst do wyświetlenia: spacja tysięcy, przecinek, zera końcowe obcięte.
$fmtQty = function ($q) use ($nbsp) {
    $s = number_format((float)$q, 4, ',', $nbsp);
    return rtrim(rtrim($s, '0'), ',');
};
// Kwoty: zawsze min. 2 miejsca po przecinku (12,50), maks. 4 (12,3456).
// Ta sama reguła co formatPrice() w documents-respond.js.
$fmtMoney = function ($v) use ($nbsp) {
    $v = (float)$v;
    $parts = explode('.', number_format(abs($v), 4, '.', ''));
    $dec = str_pad(rtrim($parts[1], '0'), 2, '0');
    $int = preg_replace('/\B(?=(\d{3})+(?!\d))/', $nbsp, $parts[0]);
    return ($v < 0 ? '-' : '') . $int . ',' . $dec;
};

$docTitle        = $isPo ? 'Zamówienie' : 'Zapytanie';
$crumbLast       = $isPo ? 'Potwierdź odpowiedź' : 'Oznacz odpowiedź';
$docNumber       = $isPo
    ? ($doc->poNumber ?? ('#' . $doc->id))
    : ($doc->rfqNumber ?? ('#' . $doc->id));
$vendorName      = $doc->vendorName ?? '—';
$sentAtLabel     = $doc->sentAt ?? '—';
$respondedAtDefault = date('Y-m-d');
// PO: kolumna confirmed_at to DATETIME → pole datetime-local (YYYY-MM-DDTHH:MM).
// Domyślnie ZAWSZE "teraz" — nie podpajamy daty z poprzedniego
// potwierdzenia: sendPo() nie czyści confirmed_at przy ponownym
// wysłaniu (`confirmed → sent`), więc stare potwierdzenie wracałoby
// jako domyślna data nowego potwierdzenia.
$confirmedAtDefault = date('Y-m-d\TH:i');
// PO: kontekst konwersji z RFQ (opcjonalny, tylko do odczytu).
$fromRfqId        = $isPo ? (int)($doc->convertedFromRfqId ?? 0) : 0;
$fromRfqNumber    = $isPo ? trim((string)($doc->convertedFromRfqNumber ?? '')) : '';
$fromRfqVendorRef = $isPo ? trim((string)($doc->convertedFromRfqVendorReference ?? '')) : '';
$expectedDelivery = $isPo ? trim((string)($doc->expectedDeliveryDate ?? '')) : '';
$rfqVendorRefNow  = !$isPo ? trim((string)($doc->vendorReference ?? '')) : '';
$backUrl          = 'http://' . BASEURL . '/admin/purchase/documents/edit?id=' . (int)$id . '&type=' . $type;
$listUrl          = 'http://' . BASEURL . '/purchase/documents';
$itemCount        = count($items);
?>
<link rel="stylesheet" href="<?= htmlspecialchars(asset('public_html/components/purchases/receipts/receipts-ui.css'), ENT_QUOTES) ?>">
<link rel="stylesheet" href="<?= htmlspecialchars(asset('public_html/components/Admin/Purchase/Documents/documents-respond-ui.css'), ENT_QUOTES) ?>">

<div class="rx-page rx-detail dx-edit dx-edit--<?= $isPo ? 'po' : 'rfq' ?> dx-respond w-75" id="docRespondView">

    <nav aria-label="Ścieżka">
        <ol class="rx-crumbs">
            <li><a href="<?= htmlspecialchars($listUrl, ENT_QUOTES) ?>"><i class="bi bi-files"></i> Dokumenty</a></li>
            <li aria-hidden="true"><i class="bi bi-chevron-right"></i></li>
            <li><a href="<?= htmlspecialchars($backUrl, ENT_QUOTES) ?>"><?= htmlspecialchars($docTitle) ?> <?= htmlspecialchars($docNumber) ?></a></li>
            <li aria-hidden="true"><i class="bi bi-chevron-right"></i></li>
            <li aria-current="page"><?= htmlspecialchars($crumbLast) ?></li>
        </ol>
    </nav>

    <div id="alertContainer" class="mb-2"></div>

    <!-- ===== Arkusz dokumentu: nagłówek + dane ===== -->
    <section class="rx-panel rx-sheet mb-3">
        <div class="rx-dochead">
            <div class="rx-dochead-main">
                <div class="rx-eyebrow dx-type-tag"><?= htmlspecialchars($docTitle) ?></div>
                <h1 class="rx-docnum"><?= htmlspecialchars($docNumber) ?></h1>
                <div class="rx-dochead-meta">
                    <?= htmlspecialchars($vendorName) ?>
                    <span class="rx-meta-sep">·</span>
                    <span class="text-faint">ID <?= (int)$doc->id ?></span>
                </div>
            </div>

            <div class="rx-dochead-side">
                <!-- Ten sam odcień co na stronie edycji dla stanu `sent`. -->
                <span class="rx-badge rx-badge--violet">wysłane</span>
                <div class="rx-dochead-actions rx-no-print">
                    <a href="<?= htmlspecialchars($backUrl, ENT_QUOTES) ?>" class="btn btn-sm rx-btn-quiet">
                        <i class="bi bi-arrow-left"></i> Wróć
                    </a>
                </div>
            </div>
        </div>

        <!-- Dostawca jest już w nagłówku powyżej — tu tylko to, czego tam nie ma. -->
        <dl class="rx-facts">
            <div class="rx-fact">
                <dt>Wysłano</dt>
                <dd><?= htmlspecialchars($sentAtLabel) ?></dd>
            </div>
            <?php if ($isPo): ?>
                <?php if ($expectedDelivery !== ''): ?>
                    <div class="rx-fact">
                        <dt>Planowana dostawa</dt>
                        <dd><?= htmlspecialchars($expectedDelivery) ?></dd>
                    </div>
                <?php endif; ?>
                <?php if ($fromRfqNumber !== ''): ?>
                    <div class="rx-fact">
                        <dt>Z zapytania</dt>
                        <dd>
                            <?php if ($fromRfqId > 0): ?>
                                <a href="http://<?= BASEURL ?>/admin/purchase/documents/edit?id=<?= $fromRfqId ?>&amp;type=rfq"><?= htmlspecialchars($fromRfqNumber) ?></a>
                            <?php else: ?>
                                <?= htmlspecialchars($fromRfqNumber) ?>
                            <?php endif; ?>
                        </dd>
                    </div>
                <?php endif; ?>
                <?php if ($fromRfqVendorRef !== ''): ?>
                    <div class="rx-fact">
                        <dt>Numer oferty u dostawcy</dt>
                        <dd><?= htmlspecialchars($fromRfqVendorRef) ?></dd>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="rx-fact">
                    <dt>Numer oferty u dostawcy</dt>
                    <dd><?= $rfqVendorRefNow !== '' ? htmlspecialchars($rfqVendorRefNow) : '—' ?></dd>
                </div>
            <?php endif; ?>
        </dl>
    </section>

<?php if (empty($items)): ?>
    <!-- ===== Brak pozycji ===== -->
    <section class="rx-panel mb-3">
        <div class="rx-panel-head">
            <h2 class="rx-panel-title">Pozycje <span class="rx-count">0</span></h2>
        </div>
        <div class="rx-empty">
            <div class="rx-empty-icon"><i class="bi bi-inbox"></i></div>
            <div class="rx-empty-title"><?= $isPo ? 'To zamówienie nie ma żadnych pozycji.' : 'To zapytanie nie ma żadnych pozycji.' ?></div>
            <div class="rx-empty-text">Dodaj pozycje przed oznaczeniem odpowiedzi.</div>
            <a href="<?= htmlspecialchars($backUrl, ENT_QUOTES) ?>" class="btn btn-sm rx-btn-quiet">
                <i class="bi bi-arrow-left"></i> Wróć do dokumentu
            </a>
        </div>
    </section>
<?php else: ?>
    <form id="respondForm" novalidate
          data-respond-type="<?= htmlspecialchars($type, ENT_QUOTES) ?>"
          data-respond-id="<?= (int)$doc->id ?>">
        <input type="hidden" name="id" value="<?= (int)$doc->id ?>">

        <!-- ===== Pozycje odpowiedzi ===== -->
        <section class="rx-panel mb-3" id="respondItemsPanel">
            <div class="rx-panel-head">
                <h2 class="rx-panel-title">
                    Pozycje
                    <span class="rx-count"><?= $itemCount ?></span>
                </h2>
                <div class="dx-respond-tools rx-no-print">
                    <button type="button" class="btn btn-sm rx-btn-quiet" id="respondFillAllBtn">
                        <i class="bi bi-arrow-clockwise"></i> <?= $isPo ? 'Przywróć zamówione ilości' : 'Przepisz ilości z zapytania' ?>
                    </button>
                    <button type="button" class="btn btn-sm rx-btn-quiet" id="respondClearAllBtn">
                        <i class="bi bi-eraser"></i> <?= $isPo ? 'Przywróć ceny z zamówienia' : 'Wyczyść ceny' ?>
                    </button>
                </div>
            </div>

            <!-- Żywe podsumowanie: wartość per waluta + różnica względem zapisanej,
                 licznik zmienionych pozycji. Wypełnia documents-respond.js. -->
            <div class="dx-sumbar" aria-live="polite">
                <div class="dx-sumbar-total">
                    <span class="dx-respond-sum-label"><?= $isPo ? 'Wartość zamówienia' : 'Wartość odpowiedzi' ?></span>
                    <div class="dx-respond-totals" id="respondTotals"><span class="dx-respond-dash">—</span></div>
                </div>
                <div class="dx-sumbar-side">
                    <span class="dx-respond-unpriced" id="respondUnpriced"></span>
                    <span class="dx-respond-changed" id="respondChanged">Zmieniono: <strong>0</strong> z <?= $itemCount ?> pozycji</span>
                </div>
            </div>

            <!-- Pozycje to wiersze-siatka, nie tabela: po lewej tożsamość części,
                 po prawej odpowiedź dostawcy (Ilość · Cena + waluta · Wartość), a pod
                 spodem uwagi do artykułu. Prawe strefy wszystkich pozycji leżą w tych
                 samych kolumnach, więc pola ceny tworzą jedną pionową kolumnę.
                 Ilość i Waluta są domyślnie tylko do odczytu — klik/Enter
                 otwiera edytor w tym samym miejscu. Prawdziwe pola formularza
                 (name="items[…]") ZAWSZE zostają w DOM (chowa je tylko CSS). -->
            <div class="dx-lines" id="respondLines" role="list">
                <div class="dx-lines-head" aria-hidden="true">
                    <span>Część</span>
                    <span class="is-r">Ilość</span>
                    <span>Cena</span>
                    <span class="is-r">Wartość</span>
                </div>
                <?php foreach ($items as $it):
                    $iid      = (int)$it->id;
                    $unitName = (string)($it->unitName ?? '');
                    $pack     = isset($it->pickedPackSize) ? (float)$it->pickedPackSize : null;
                    $hasPack  = ($pack !== null && $pack > 0);
                    $vn       = (string)($it->vendorPartNo ?? '');
                    $pn       = (string)($it->producerPartNo ?? '');
                    $prName   = (string)($it->producerName ?? '');
                    // Numer producenta pomijamy, gdy jest taki sam jak numer dostawcy (nie dublujemy).
                    $pnShown  = ($pn !== '' && $pn !== $vn) ? $pn : '';
                    // Linia 1 (nagłówek): numer dostawcy; bez niego — nasza nazwa.
                    $headTxt   = ($vn !== '') ? $vn : (string)($it->partName ?? '—');
                    $headTitle = ($vn !== '') ? 'Numer części u dostawcy' : 'Nasza nazwa części';
                    // Linia 2 (zawsze widoczna): nasza nazwa · producent · nr producenta.
                    $subParts = [];
                    if ($vn !== '') { $subParts[] = '<span class="dx-sub-own" title="Nasza nazwa części">' . htmlspecialchars((string)($it->partName ?? '—')) . '</span>'; }
                    if ($prName !== '') { $subParts[] = '<span class="dx-sub-prod" title="Producent">' . htmlspecialchars($prName) . '</span>'; }
                    if ($pnShown !== '') { $subParts[] = '<span class="dx-sub-prod" title="Numer części producenta">' . htmlspecialchars($pnShown) . '</span>'; }
                    $subHtml  = implode('<span class="dx-sub-sep" aria-hidden="true">·</span>', $subParts);
                    $subPlain = implode(' · ', array_filter([$vn !== '' ? (string)($it->partName ?? '') : '', $prName, $pnShown], function ($s) { return $s !== ''; }));
                    $askedQty = (float)$it->quantity;
                    $askedQtyStr = $rawQty($askedQty);
                    // Uwagi do artykułu (list__vendor_part.comment) — te same
                    // dane co w koszyku i na stronie edycji; repozytorium
                    // pozycji już je dobiera (vp.comment AS vendorPartComment).
                    $vpId  = (int)($it->vendorPartId ?? 0);
                    $vpCmt = (string)($it->vendorPartComment ?? '');

                    // Cena zapisana w bazie. Kolumna `unit_price` jest nullable
                    // dla PO i dla RFQ: NULL = cena nieustalona. "Bez ceny" =
                    // NULL albo cena <= 0; puste pole ceny oznacza "zachowaj
                    // dotychczasową".
                    $storedPrice = ($it->unitPrice !== null) ? (float)$it->unitPrice : null;
                    $hasPrice    = ($storedPrice !== null && (!$isPo || $storedPrice > 0));
                    $isUnpriced  = !$hasPrice;
                    $priceValue  = $hasPrice ? $rawDecimal($it->unitPrice) : '';
                    $origPrice   = ($storedPrice !== null) ? $rawDecimal($it->unitPrice) : '';

                    $curCode = !empty($it->currency) ? (string)$it->currency : 'PLN';

                    if ($isPo) {
                        $placeholder = $hasPrice ? 'bez zmian' : 'brak ceny — do uzupełnienia';
                        $wasText = 'było: ' . $fmtMoney($storedPrice ?? 0);
                    } else {
                        $placeholder = 'nie wyceniono';
                        $wasText = $storedPrice === null ? 'było: nie wyceniono' : 'było: ' . $fmtMoney($storedPrice);
                    }

                    $rowClass = 'dx-line dx-respond-row';
                    if ($isUnpriced) {
                        $rowClass .= $isPo ? ' is-noprice' : ' is-unpriced';
                    }

                    // Tekst widoku tylko do odczytu (JS odświeża go przy każdej zmianie).
                    $qtyViewText = $fmtQty($askedQty) . ($unitName !== '' ? $nbsp . $unitName : '');
                    $qtySubText  = $hasPack ? ($fmtQty($askedQty / $pack) . ' opak. × ' . $fmtQty($pack)) : '';
                ?>
                <div class="<?= $rowClass ?>" role="listitem"
                     data-item-id="<?= $iid ?>"
                     data-orig-price="<?= htmlspecialchars($origPrice, ENT_QUOTES) ?>"
                     data-pack="<?= $hasPack ? htmlspecialchars((string)$pack, ENT_QUOTES) : '' ?>"
                     data-asked-qty="<?= htmlspecialchars($askedQtyStr, ENT_QUOTES) ?>"
                     data-asked-unit="<?= htmlspecialchars($unitName, ENT_QUOTES) ?>">

                    <!-- Tożsamość. Domyślnie (zwinięte):
                         linia 1 = numer dostawcy (nagłówek) + etykieta ceny,
                         linia 2 = nasza nazwa · producent + przycisk "Opis" (opis części),
                         linia 3 = uwagi do artykułu (edycja w miejscu).
                         "Opis" rozwija pełny opis części. -->
                    <?php $hasDetails = !empty($it->partDescription); ?>
                    <div class="dx-line-id">
                        <div class="dx-headrow">
                            <span class="dx-headline" title="<?= htmlspecialchars($headTitle, ENT_QUOTES) ?>"><?= htmlspecialchars($headTxt) ?></span>
                            <span class="dx-respond-flag js-flag"><?= $isPo ? 'brak ceny' : 'niewyceniona' ?></span>
                        </div>

                        <!-- Nasza nazwa · producent + przycisk rozwijający opis części. -->
                        <?php if ($subHtml !== '' || $hasDetails): ?>
                            <div class="dx-subrow">
                                <?php if ($subHtml !== ''): ?>
                                    <span class="dx-subline" title="<?= htmlspecialchars($subPlain, ENT_QUOTES) ?>"><?= $subHtml ?></span>
                                <?php endif; ?>
                                <?php if ($hasDetails): ?>
                                    <button type="button" class="dx-details-toggle js-details" aria-expanded="false"
                                            aria-controls="respond-det-<?= $iid ?>"
                                            title="Pokaż opis części" aria-label="Pokaż opis części">
                                        <span>Opis</span>
                                        <i class="bi bi-chevron-down dx-details-chev" aria-hidden="true"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <!-- Uwagi do artykułu (list__vendor_part.comment) — jak w koszyku:
                             ołówek otwiera edytor w miejscu, zapis od razu (vendor-part-comment.php). -->
                        <div class="dx-line-vpcomment">
                            <span class="dx-vpcomment-line js-vpc-line"
                                  data-vp-id="<?= $vpId ?>"
                                  data-vp-comment="<?= htmlspecialchars($vpCmt, ENT_QUOTES, 'UTF-8') ?>">
                                <i class="bi bi-journal-text" aria-hidden="true"></i>
                                <span class="js-vpc-label">Uwagi do artykułu:</span>
                                <span class="js-vpc-text<?= $vpCmt === '' ? ' is-empty' : '' ?>"><?= $vpCmt !== '' ? htmlspecialchars($vpCmt) : 'Brak' ?></span>
                                <button type="button" class="dx-vpc-edit js-vpc-edit"
                                        data-vp-id="<?= $vpId ?>"
                                        title="<?= $vpCmt !== '' ? 'Edytuj uwagi' : 'Dodaj uwagi' ?>"
                                        aria-label="<?= $vpCmt !== '' ? 'Edytuj uwagi do artykułu' : 'Dodaj uwagi do artykułu' ?>"><i class="bi bi-pencil"></i></button>
                            </span>
                            <span class="dx-vpcomment-editor js-vpc-editor" hidden>
                                <textarea rows="2" class="form-control form-control-sm js-vpc-input" maxlength="255"
                                          placeholder="Uwagi do artykułu…" aria-label="Uwagi do artykułu"></textarea>
                                <button type="button" class="btn btn-sm btn-success js-vpc-save" title="Zapisz (Ctrl+Enter)"><i class="bi bi-check-lg"></i> Zapisz</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary js-vpc-cancel" title="Anuluj (Esc)">Anuluj</button>
                            </span>
                        </div>

                        <?php if ($hasDetails): ?>
                            <div class="dx-details" id="respond-det-<?= $iid ?>" hidden>
                                <div class="dx-det-desc"><?= htmlspecialchars($it->partDescription) ?></div>
                            </div>
                        <?php endif; ?>
                    </div>

                    
                    <!-- Ilość: odczyt (domyślnie) / edytor: ilość + opakowania -->
                    <div class="dx-line-qty" data-label="Ilość">
                        <div class="dx-respond-cell" data-cell="qty">
                            <button type="button" class="dx-respond-view js-view"
                                    aria-label="Zmień ilość" title="Zmień ilość">
                                <span class="dx-respond-view-main js-qty-view"><?= htmlspecialchars($qtyViewText) ?></span>
                                <?php if ($hasPack): ?>
                                    <span class="dx-respond-view-sub js-qty-sub"><?= htmlspecialchars($qtySubText) ?></span>
                                <?php endif; ?>
                                <span class="dx-respond-view-was js-qty-was" hidden></span>
                                <i class="bi bi-pencil dx-respond-pen" aria-hidden="true"></i>
                            </button>
                            <div class="dx-respond-editor">
                                <div class="input-group input-group-sm">
                                    <input type="number" inputmode="decimal" step="0.0001" min="0.0001"
                                           class="form-control text-right js-qty"
                                           name="items[<?= $iid ?>][quantity]"
                                           value="<?= htmlspecialchars($askedQtyStr, ENT_QUOTES) ?>"
                                           aria-label="Ilość">
                                    <?php if ($unitName !== ''): ?>
                                        <div class="input-group-append">
                                            <span class="input-group-text dx-respond-unit" title="<?= htmlspecialchars($unitName, ENT_QUOTES) ?>"><?= htmlspecialchars($unitName) ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <?php if ($hasPack): ?>
                                <div class="dx-respond-qty-sub">
                                    <input type="number" inputmode="decimal" step="0.0001" min="0"
                                           class="form-control form-control-sm text-right js-packs"
                                           aria-label="Opakowania"
                                           title="Opakowania — dwukierunkowo powiązane z ilością">
                                    <small class="text-muted">opak.</small>
                                    <span class="dx-respond-pack" title="Wielkość opakowania (stała)">× <?= htmlspecialchars($rawQty($pack)) ?></span>
                                </div>
                                <?php endif; ?>
                                <div class="dx-respond-asked"><?= $isPo ? 'Zamówiono' : 'Zapytano' ?>: <?= htmlspecialchars($fmtQty($askedQty)) ?><?= $unitName !== '' ? ' ' . htmlspecialchars($unitName) : '' ?></div>
                                <div class="invalid-feedback js-qty-error"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Cena: jedyne zawsze otwarte pole; waluta dokleja się do niego
                         (odczyt / edytor bootstrap-select), pod spodem "było" i różnica. -->
                    <div class="dx-line-price" data-label="Cena">
                        <div class="dx-priceinput">
                            <input type="number" inputmode="decimal" step="0.0001" min="0"
                                   class="form-control text-right js-price"
                                   name="items[<?= $iid ?>][unit_price]"
                                   value="<?= htmlspecialchars($priceValue, ENT_QUOTES) ?>"
                                   placeholder="<?= htmlspecialchars($placeholder, ENT_QUOTES) ?>"
                                   title="<?= htmlspecialchars($placeholder, ENT_QUOTES) ?>"
                                   aria-label="Cena jednostkowa">
                            <div class="dx-respond-cell dx-curcell" data-cell="cur">
                                <button type="button" class="dx-respond-view dx-cur-btn js-view"
                                        aria-label="Zmień walutę" title="Zmień walutę">
                                    <span class="js-cur-view"><?= htmlspecialchars($curCode) ?></span>
                                    <i class="bi bi-chevron-down dx-cur-caret" aria-hidden="true"></i>
                                </button>
                                <div class="dx-respond-editor">
                                    <div class="dx-respond-cur" id="respond-cur-<?= $iid ?>" style="min-width: 0;">
                                        <select class="selectpicker js-currency"
                                                name="items[<?= $iid ?>][currency]"
                                                data-width="100%"
                                                data-container="#respond-cur-<?= $iid ?>"
                                                data-style="dx-respond-pick"
                                                data-size="8"
                                                title="Waluta"
                                                aria-label="Waluta">
                                            <?php foreach ($currencies as $code => $label): ?>
                                                <option value="<?= htmlspecialchars($code, ENT_QUOTES) ?>"
                                                    <?= ($code === $curCode) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($code) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="dx-price-meta">
                            <span class="dx-respond-was"><?= htmlspecialchars($wasText) ?></span>
                            <span class="dx-delta js-price-delta" hidden></span>
                            <?php if ($isPo): ?>
                                <span class="dx-respond-keep js-price-keep" hidden></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Wartość: ilość × cena, tylko do odczytu, liczona na żywo -->
                    <div class="dx-line-value" data-label="Wartość">
                        <div class="dx-value-main">
                            <span class="dx-respond-val js-line-val">—</span>
                            <span class="dx-respond-val-cur js-line-cur"></span>
                        </div>
                        <div class="dx-respond-val-was js-line-was" hidden></div>
                    </div>

                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- ===== Dane odpowiedzi ===== -->
        <section class="rx-panel mb-3">
            <div class="rx-panel-head">
                <h2 class="rx-panel-title"><i class="bi bi-card-text"></i> Dane odpowiedzi</h2>
            </div>
            <div class="dx-respond-fields">
                <?php if ($isPo): ?>
                <div class="dx-respond-field">
                    <label for="vendor_po_number" class="dx-respond-label">Numer zamówienia u dostawcy</label>
                    <input type="text" id="vendor_po_number" name="vendor_po_number" maxlength="64"
                           class="form-control form-control-sm"
                           value="<?= htmlspecialchars($doc->vendorPoNumber ?? '', ENT_QUOTES) ?>"
                           placeholder="np. ZAM/2026/114">
                    <small class="dx-respond-help">Numer nadany zamówieniu przez dostawcę — pozostaw puste, aby nie zmieniać.</small>
                </div>
                <div class="dx-respond-field">
                    <label for="confirmed_at" class="dx-respond-label">Data potwierdzenia</label>
                    <input type="datetime-local" id="confirmed_at" name="confirmed_at" step="60"
                           class="form-control form-control-sm"
                           value="<?= htmlspecialchars($confirmedAtDefault, ENT_QUOTES) ?>">
                    <small class="dx-respond-help">Domyślnie bieżąca data i godzina. Możesz cofnąć.</small>
                </div>
                <?php else: ?>
                <div class="dx-respond-field">
                    <label for="vendor_reference" class="dx-respond-label">Numer Oferty u dostawcy</label>
                    <input type="text" id="vendor_reference" name="vendor_reference" maxlength="128"
                           class="form-control form-control-sm"
                           value="<?= htmlspecialchars($doc->vendorReference ?? '', ENT_QUOTES) ?>"
                           placeholder="np. OF/2026/114">
                    <small class="dx-respond-help">Numer oferty nadany przez dostawcę do jego odpowiedzi — używany w dalszej korespondencji.</small>
                </div>
                <div class="dx-respond-field">
                    <label for="responded_at" class="dx-respond-label">Data odpowiedzi</label>
                    <input type="date" id="responded_at" name="responded_at"
                           class="form-control form-control-sm"
                           value="<?= htmlspecialchars($respondedAtDefault) ?>">
                    <small class="dx-respond-help">Domyślnie dzisiejsza. Możesz cofnąć.</small>
                </div>
                <?php endif; ?>
            </div>
        </section>
    </form>

    <!-- ===== Pasek akcji (przyklejony do dołu) ===== -->
    <div class="dx-respond-actionbar rx-no-print">
        <div class="dx-respond-bar-main">
            <div class="dx-respond-bar-title" id="respondSummary"><?= $isPo ? 'Zapisujesz potwierdzenie odpowiedzi dla 0 z ' . $itemCount . ' pozycji' : 'Zapisujesz odpowiedź dla 0 z ' . $itemCount . ' pozycji' ?></div>
            <div class="dx-respond-bar-meta">
                <span class="dx-respond-bar-totals" id="respondBarTotals"><span class="dx-respond-dash">—</span></span>
                <span class="dx-respond-changed" id="respondBarChanged">Zmieniono: <strong>0</strong> z <?= $itemCount ?> pozycji</span>
                <span class="dx-respond-bar-hint" id="respondHint"></span>
            </div>
        </div>
        <div class="dx-respond-bar-actions">
            <a href="<?= htmlspecialchars($backUrl, ENT_QUOTES) ?>" class="btn rx-btn-quiet" id="respondCancelBtn">Anuluj</a>
            <button type="submit" form="respondForm" class="btn btn-primary px-4" id="respondSubmitBtn">
                <i class="bi bi-check2-circle"></i> <?= $isPo ? 'Zapisz potwierdzenie odpowiedzi' : 'Zapisz odpowiedź' ?>
            </button>
        </div>
    </div>
<?php endif; ?>
</div>

<?php if (!empty($items)): ?>
<script src="<?= htmlspecialchars(asset('public_html/components/Admin/Purchase/Documents/documents-respond.js'), ENT_QUOTES) ?>"></script>
<?php endif; ?>
