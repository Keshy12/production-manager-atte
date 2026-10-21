<?php
/**
 * Koszyk zakupowy — /purchase/cart.
 *
 * Wygląd: ten sam język co /purchase/receipts i /purchase/documents.
 * receipts-ui.css jest wczytywany jako baza (tylko do odczytu), potem
 * purchases.css (wspólny, nietknięty), a cart-ui.css dokłada style
 * widoczne tylko tutaj (.cx-*, wszystko pod .cx-page).
 *
 * Zachowanie (selektory kaskadowe, tryb „Artykuł", koszyk w localStorage,
 * tworzenie dokumentów) jest w cart-view.js — ten plik renderuje tylko
 * szkielet. Wszystkie identyfikatory (#vendorSelect, #cartBody, …) i
 * atrybuty data-* są używane przez JS; zmieniając układ, nie ruszaj ich.
 *
 * Panel „Dodaj pozycję" (tryb „wybór"): Dostawca + Część (z przyciskami
 * „wyczyść") → tabela wszystkich wariantów części (#variantsPanel) →
 * ilość/cena wybranego wariantu (#pickVariantRow). Wybrany wariant żyje
 * w ukrytym #vendorPartNoSelect — to nośnik stanu dla reszty JS.
 *
 * Elementy pokazywane/chowane przez JS (.show()/.hide()/.toggle()) mają
 * początkowe inline `style="display:none"` — to celowe: jQuery .show()
 * zdejmuje ten inline i wraca do `display` z CSS (grid / flex / contents).
 */
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Master\CurrencyRepository;

if(!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    header("Location: http://".BASEURL."/");
    exit();
}

$MsaDB = MsaDB::getInstance();

// Vendors with their parts_ids (only active vendors, only active VendorParts).
// `default_currency_code` is JOINed from list__currency so the cart JS can
// pre-select the vendor's preferred currency on vendor change (matches the
// "Domyślna waluta" picker on /admin/purchase/vendors/edit). lead_time_days
// and comment drive the Dostawca picker's subtext line (below the vendor
// name, which itself carries the currency as a Bootstrap badge via
// data-content). Sorted by currency code then vendor name so the PHP
// group-by below produces alphabetical optgroups without a re-sort.
$vendorsWithParts = $MsaDB->query(
    "SELECT v.id, v.name,
            v.lead_time_days,
            v.comment,
            c.code AS default_currency_code,
            GROUP_CONCAT(vp.parts_id) AS parts_ids
       FROM `list__vendor` v
       LEFT JOIN `list__vendor_part` vp ON vp.vendor_id = v.id AND vp.isActive = 1
       LEFT JOIN `list__currency`    c ON c.id = v.default_currency
      WHERE v.isActive = 1
      GROUP BY v.id, v.name, v.lead_time_days, v.comment, c.code
      ORDER BY c.code ASC, v.name ASC"
);

// Group by currency code for <optgroup> rendering. Defensive fallback
// to 'PLN' for any vendor whose FK happens to point at a missing row
// (shouldn't happen post-migration, but keeps the dropdown safe).
$vendorsByCurrency = [];
foreach ($vendorsWithParts as $v) {
    $code = (string)($v['default_currency_code'] ?? '') ?: 'PLN';
    if (!isset($vendorsByCurrency[$code])) {
        $vendorsByCurrency[$code] = [];
    }
    $vendorsByCurrency[$code][] = $v;
}
ksort($vendorsByCurrency);

// Active currencies for the Waluta selectpicker. Same shape as on
// /admin/purchase/vendors/edit — code is the value, name is the label.
$currencies = (new CurrencyRepository($MsaDB))->getActive();

// Parts with their vendors_ids
$partsWithVendors = $MsaDB->query(
    "SELECT p.id, p.name, p.description, p.JM, GROUP_CONCAT(vp.vendor_id) AS vendors_ids
       FROM `list__parts` p
       LEFT JOIN `list__vendor_part` vp ON vp.parts_id = p.id AND vp.isActive = 1
      WHERE p.isActive = 1
      GROUP BY p.id, p.name, p.description, p.JM
      ORDER BY p.name ASC"
);

// VendorParts lookup index — keyed by "vendorId:partId" → list of {id, vendor_part_no,
// producer_part_no, pack_quantities, full_pack_quantity (MIN for back-compat),
// vendor_jm_id, unit_name, vendor_name, …}
// (consumed by cart-view.js: variants table, step-3 summary, cart rows).
//
// Pack sizes live in `list__vendor_part_pack` (1-to-many). We fetch them in
// a second query and merge by vendor_part_id so the main SELECT doesn't
// multiply rows. `full_pack_quantity` is kept as MIN(pack) for back-compat
// with the cart JS that displays "opak. N" today; multi-pack UI comes later.
$vpRows = $MsaDB->query(
    "SELECT vp.id, vp.vendor_id, vp.parts_id, vp.vendor_part_no,
            vp.producer_part_no, vp.vendor_jm_id,
            v.name AS vendor_name, u.name AS unit_name, p.name AS part_name,
            pr.name AS producer_name, vp.comment AS private_comment,
            v.shop_url AS shop_url, cur.code AS currency_code
       FROM `list__vendor_part` vp
       JOIN `list__vendor` v ON vp.vendor_id = v.id
       LEFT JOIN `list__currency` cur ON cur.id = v.default_currency
       JOIN `part__unit`    u ON vp.vendor_jm_id = u.id
       JOIN `list__parts`   p ON vp.parts_id = p.id
       LEFT JOIN `list__producer` pr ON pr.id = vp.producer_id
      WHERE vp.isActive = 1 AND v.isActive = 1"
);
$packRows = $MsaDB->query(
    "SELECT vendor_part_id,
            MIN(full_pack_quantity) AS min_pack,
            GROUP_CONCAT(full_pack_quantity ORDER BY full_pack_quantity ASC) AS packs_csv
       FROM `list__vendor_part_pack`
      GROUP BY vendor_part_id"
);
$packsByVpId = [];
foreach ($packRows as $pr) {
    $packsByVpId[(int)$pr['vendor_part_id']] = [
        'packs'   => $pr['packs_csv'] === null ? [] : array_map('floatval', explode(',', $pr['packs_csv'])),
        'minPack' => $pr['min_pack'] === null ? null : (float)$pr['min_pack'],
    ];
}
$vendorPartsIndex = [];
foreach ($vpRows as $r) {
    $vpId = (int)$r['id'];
    $pack = $packsByVpId[$vpId] ?? ['packs' => [], 'minPack' => null];
    $entry = [
        'id'                 => $vpId,
        'vendor_id'          => (int)$r['vendor_id'],
        'parts_id'           => (int)$r['parts_id'],
        'vendor_part_no'     => $r['vendor_part_no'],
        'producer_part_no'   => $r['producer_part_no'],
        'producer_name'      => $r['producer_name'],
        'private_comment'    => $r['private_comment'],
        'shop_url'           => $r['shop_url'],
        'currency_code'      => $r['currency_code'],
        'vendor_jm_id'       => (int)$r['vendor_jm_id'],
        'unit_name'          => $r['unit_name'],
        'full_pack_quantity' => $pack['minPack'], // back-compat: smallest pack
        'pack_quantities'    => $pack['packs'],   // all pack sizes (asc)
        'vendor_name'        => $r['vendor_name'],
        'part_name'          => $r['part_name'],
    ];
    $vendorPartsIndex[$r['vendor_id'] . ':' . $r['parts_id']][] = $entry;
}

// Producers and units (add-mode pickers) — populated server-side so the
// user can pick an existing Producer / JM when adding a new VendorPart
// on the fly. (Adding new Producers or Units themselves is out of scope
// here — that lives under /admin/purchase/.)
$producers = $MsaDB->query(
    "SELECT id, name FROM `list__producer`
      WHERE isActive = 1
      ORDER BY name ASC"
);
$units = $MsaDB->query(
    "SELECT id, name FROM `part__unit`
      ORDER BY name ASC"
);

$baseUrl = 'http://' . BASEURL;
?>

<link rel="stylesheet" href="<?= asset('public_html/components/purchases/receipts/receipts-ui.css') ?>">
<link rel="stylesheet" href="<?= asset('public_html/components/purchases/cart/purchases.css') ?>">
<link rel="stylesheet" href="<?= asset('public_html/components/purchases/cart/cart-ui.css') ?>">

<div class="rx-page cx-page" id="cartView">

    <!-- ===== Nagłówek strony + liczniki koszyka ===== -->
    <header class="rx-head">
        <div class="rx-head-main">
            <div class="rx-eyebrow"><i class="bi bi-cart3"></i> Zamówienia komponentów</div>
            <h1 class="rx-title">Koszyk zakupowy</h1>
            <p class="rx-lead">
                Wybierz dostawcę i część, dodaj pozycje do koszyka, a potem utwórz z nich
                zapytanie (RFQ) lub zamówienie (PO).
            </p>
            <ul class="cx-rules">
                <li>
                    <i class="bi bi-person-check" aria-hidden="true"></i>
                    <span>Jeden dokument obejmuje <strong>jednego dostawcę</strong> — koszyk grupuje pozycje po dostawcy.</span>
                </li>
                <li>
                    <i class="bi bi-clock-history" aria-hidden="true"></i>
                    <span>Pozycje są zapamiętane w tej przeglądarce przez <strong>7 dni</strong>;
                        znikają po „Wyczyść koszyk" albo po utworzeniu dokumentu.</span>
                </li>
            </ul>
        </div>

        <div class="cx-head-links">
            <a class="btn btn-sm rx-btn-quiet" href="<?= htmlspecialchars($baseUrl . '/purchase/documents', ENT_QUOTES) ?>">
                <i class="bi bi-file-earmark-text"></i> Zapytania i zamówienia
            </a>
            <a class="btn btn-sm rx-btn-quiet" href="<?= htmlspecialchars($baseUrl . '/purchase/receipts', ENT_QUOTES) ?>">
                <i class="bi bi-box-arrow-in-down"></i> Przyjęcia
            </a>
        </div>
    </header>

    <div id="alertContainer" class="rx-alerts"></div>

    <!-- ===== Dodaj pozycję =====
         .cx-picker dostaje klasę .picker-add-mode z setPickerMode().
         Tryb „wybór" to proces w trzech krokach (#cxSteps pokazuje aktualny);
         jedno miejsce decyduje, co widać: setCartStep() w cart-view.js
         (zmienna cartStep: 'select' | 'details').
           Widok wyboru (kroki 1 + 2):
             1. #pickersStep — Dostawca i Część obok siebie, każdy z „×";
                przy Części także szukajka katalogu (#vpSearchBtn).
             2. #variantsPanel — tabela wszystkich wariantów części (wszyscy
                dostawcy; inni niż wybrany są zabarwieni). Wariant wybiera
                tylko przycisk „Wybierz" w wierszu. Kolumna „Aktywne
                dokumenty" (jedno zapytanie o wszystkie warianty części,
                z podwierszem szczegółów) zastąpiła dawną kartę
                „Aktywne dokumenty dla wybranej pozycji".
           Widok szczegółów (krok 3), kroki 1 + 2 są wtedy schowane:
             3. #pickVariantRow — podsumowanie wariantu (#pickedSummary),
                „← Zmień wariant", aktywne dokumenty wariantu, ilość / opak. /
                cena / waluta / komentarze.
         #vendorPartNoSelect to ukryty nośnik stanu (wybrany wariant);
         JS czyta z niego data-vp-id itd.
         Tryb „Artykuł" (add) to osobny tryb: pokazuje selektory i własne pola.
         Wiersze #cartPriceRow, #addVariantRow1/2 i #addVariantCommentRow
         to display: contents (dzieci leżą we wspólnej siatce). -->
    <section class="rx-panel cx-picker" aria-labelledby="cxPickerTitle">
        <div class="rx-panel-head">
            <h2 class="rx-panel-title" id="cxPickerTitle">
                <span class="cx-title-pick"><i class="bi bi-plus-circle" aria-hidden="true"></i> Dodaj pozycję do koszyka</span>
                <span class="cx-title-add"><i class="bi bi-plus-square" aria-hidden="true"></i> Nowy artykuł w katalogu dostawcy</span>
            </h2>
            <p class="cx-panel-note cx-note-pick">
                Wybierz część, porównaj jej warianty i kliknij „Wybierz".
                Dopiero potem wpiszesz ilość i cenę.
            </p>
            <p class="cx-panel-note cx-note-add">
                Najpierw zapisz artykuł w katalogu — ilość, cenę i walutę wpiszesz po zapisie.
            </p>
        </div>

        <div class="cx-picker-body">
            <!-- Wskaźnik kroków (tryb „wybór"); aktualny krok ustawia setCartStep(). -->
            <ol class="cx-steps" id="cxSteps" aria-label="Kroki">
                <li data-step="1"><span class="cx-steps-n">1</span> Wybierz część</li>
                <li data-step="2"><span class="cx-steps-n">2</span> Porównaj warianty</li>
                <li data-step="3"><span class="cx-steps-n">3</span> Ilość i cena</li>
            </ol>
            <div class="cx-fields">

                <!-- Krok 1: selektory (chowane w kroku 3 przez setCartStep()). -->
                <div id="pickersStep" class="cx-contents">
                <div class="cx-field cx-sm-6" id="vendorCell">
                    <label class="cx-label" for="vendorSelect">Dostawca</label>
                    <!--
                        Per bs-select 1.13.x docs, custom HTML for an option
                        goes in `data-content` (rendered as HTML in both the
                        dropdown row and the selected button via the built-in
                        sanitizer). Inner `<option>` text is plain — used for
                        search tokens / data-normalized-text / fallback.
                        Putting rich HTML inside the option tag itself breaks
                        subtext rendering in this version; data-content keeps
                        subtext (data-subtext) intact.
                    -->
                    <div class="cx-with-btn"><div class="cx-grow flex-grow-1">
                    <select id="vendorSelect" class="selectpicker form-control"
                            data-live-search="true" data-width="100%"
                            data-container="#vendorCell"
                            data-style="cx-picker-btn"
                            data-show-subtext="true"
                            title="Wybierz dostawcę...">
                        <?php foreach ($vendorsByCurrency as $currencyCode => $vendors):
                            $curEsc = htmlspecialchars($currencyCode, ENT_QUOTES);
                        ?>
                            <optgroup label="<?= $curEsc ?>">
                                <?php foreach ($vendors as $v):
                                    $cmt = trim((string)($v['comment'] ?? ''));
                                    $vendorNameEsc = htmlspecialchars($v['name'], ENT_QUOTES);

                                    // Subtext line (lead time + comment). Comment
                                    // is truncated to keep the subtext on one line
                                    // even in narrow viewports.
                                    $subtextParts = [];
                                    if ($v['lead_time_days'] !== null && (int)$v['lead_time_days'] > 0) {
                                        $subtextParts[] = ((int)$v['lead_time_days']) . ' dni';
                                    }
                                    if ($cmt !== '') {
                                        $subtextParts[] = htmlspecialchars(
                                            mb_strlen($cmt) > 60
                                                ? mb_substr($cmt, 0, 60) . '…'
                                                : $cmt,
                                            ENT_QUOTES
                                        );
                                    }
                                    $subtext = implode(' · ', $subtextParts);
                                ?>
                                    <option value="<?= (int)$v['id'] ?>"
                                            data-name="<?= $vendorNameEsc ?>"
                                            data-subtext="<?= htmlspecialchars($subtext, ENT_QUOTES) ?>"
                                            data-default-currency="<?= $curEsc ?>"
                                            data-parts='<?= htmlspecialchars(json_encode($v['parts_ids'] === null ? [] : array_map('intval', explode(',', $v['parts_ids']))), ENT_QUOTES) ?>'>
                                        <?= $vendorNameEsc ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                    </div>
                    <button type="button" id="vendorClearBtn" class="btn rx-btn-quiet cx-icon-btn cx-icon-btn--field"
                            title="Wyczyść dostawcę" aria-label="Wyczyść dostawcę"><i class="bi bi-x-lg"></i></button>
                    </div>
                </div>

                <div class="cx-field cx-sm-6" id="partCell">
                    <label class="cx-label" for="partSelect">Część</label>
                    <div class="cx-with-btn"><div class="cx-grow flex-grow-1">
                    <select id="partSelect" class="selectpicker form-control" data-live-search="true" data-width="100%" data-container="#partCell" data-style="cx-picker-btn" title="Wybierz część...">
                        <?php foreach ($partsWithVendors as $p): ?>
                            <option value="<?= (int)$p['id'] ?>"
                                    data-name="<?= htmlspecialchars($p['name']) ?>"
                                    data-subtext="<?= htmlspecialchars($p['description']) ?>"
                                    data-jm="<?= (int)$p['JM'] ?>"
                                    data-vendors='<?= htmlspecialchars(json_encode($p['vendors_ids'] === null ? [] : array_map('intval', explode(',', $p['vendors_ids']))), ENT_QUOTES) ?>'>
                                <?= htmlspecialchars($p['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    </div>
                    <button type="button" id="partClearBtn" class="btn rx-btn-quiet cx-icon-btn cx-icon-btn--field"
                            title="Wyczyść część" aria-label="Wyczyść część"><i class="bi bi-x-lg"></i></button>
                    <button type="button" id="vpSearchBtn" class="btn rx-btn-quiet cx-icon-btn cx-icon-btn--field"
                            title="Szukaj po numerze dostawcy / producenta / części"
                            aria-label="Szukaj numeru u dostawcy w całym katalogu">
                        <i class="bi bi-search"></i>
                    </button>
                    </div>
                </div>
                </div><!-- /#pickersStep -->

                <!-- Nośnik stanu: ukryty <select> z wariantami wybranej części.
                     Wybrany wariant (data-vp-id, data-vendor-id, …) czyta cała
                     reszta JS; użytkownik wybiera wiersz w tabeli poniżej. -->
                <div id="vendorPartCell" class="d-none" aria-hidden="true">
                    <select id="vendorPartNoSelect" tabindex="-1" aria-hidden="true"></select>
                </div>

                <!-- Krok 2: porównaj warianty wybranej części (wszystkich
                     dostawców) i wybierz jeden. Pełna szerokość siatki, tuż
                     pod Dostawcą/Częścią. Tylko tryb „wybór", gdy wybrano
                     część (renderVariantsTable() w cart-view.js). -->
                <section id="variantsPanel" class="cx-variants" style="display:none" aria-labelledby="variantsTitle">
                    <div class="cx-variants-head">
                        <span class="cx-step">2</span>
                        <h3 class="cx-variants-title" id="variantsTitle">Wybierz wariant</h3>
                        <span class="rx-count" id="variantsCount">0</span>
                        <span class="cx-legend" id="variantsLegend" style="display:none"><i class="cx-legend-dot" aria-hidden="true"></i> inny dostawca</span>
                        <span class="cx-variants-hint">Otwórz sklepy, porównaj ceny, potem kliknij wiersz.</span>
                    </div>
                    <div class="cx-variants-scroll">
                        <table class="table rx-table mb-0">
                            <thead>
                                <tr>
                                    <th>Dostawca</th>
                                    <th>Nr u dostawcy / Nr producenta</th>
                                    <th>Producent</th>
                                    <th>JM / opakowania</th>
                                    <th>Uwagi do artykułu</th>
                                    <th>Aktywne dokumenty</th>
                                    <th class="cx-col-shop">Sklep</th>
                                    <th><span class="sr-only">Akcja</span></th>
                                </tr>
                            </thead>
                            <tbody id="variantsBody"></tbody>
                        </table>
                    </div>
                </section>

                <!-- Krok 3 (tryb „wybór"): ilość, cena i waluta wybranego
                     wariantu. Sekcja pokazuje się dopiero po wyborze wiersza
                     (syncAmountsInputs) i chowa w trybie „Artykuł". -->
                <section id="pickVariantRow" class="cx-details" style="display:none" aria-labelledby="pickedVariantTitle">
                    <div class="cx-details-head">
                        <span class="cx-step">3</span>
                        <h3 class="cx-details-title" id="pickedVariantTitle">Ilość i cena</h3>
                        <button type="button" id="changeVariantBtn" class="btn btn-sm rx-btn-quiet cx-details-back">
                            <i class="bi bi-arrow-left"></i> Zmień wariant
                        </button>
                    </div>
                    <!-- Podsumowanie wybranego wariantu — renderPickedSummary(). -->
                    <div id="pickedSummary" class="cx-sum"></div>
                    <!-- Uwagi do wybranego wariantu; pokazywane przez JS
                         w syncAmountsInputs. -->
                    <div id="variantInfoRow" class="cx-varinfo" style="display:none">
                            <span id="variantCommentDisplay" class="cx-varnote">
                                <i class="bi bi-journal-text" aria-hidden="true"></i>
                                <strong>Uwagi do artykułu:</strong>
                                <em id="variantCommentText">Brak komentarza</em>
                                <button type="button" id="variantCommentEdit" class="rx-link-btn cx-iconlink"
                                        title="Edytuj komentarz — zapisze się od razu na karcie artykułu"
                                        aria-label="Edytuj komentarz do artykułu"><i class="bi bi-pencil"></i></button>
                            </span>
                            <span id="variantCommentEditBox" class="cx-inline-edit" style="display:none">
                                <input type="text" id="variantCommentInput" class="form-control form-control-sm"
                                       maxlength="255" aria-label="Komentarz do artykułu"
                                       placeholder="Komentarz tylko dla nas — nie trafi do dostawcy">
                                <button type="button" id="variantCommentSave" class="btn btn-sm btn-success" title="Zapisz" aria-label="Zapisz komentarz"><i class="bi bi-check"></i></button>
                                <button type="button" id="variantCommentCancel" class="btn btn-sm btn-secondary" title="Anuluj (Esc)" aria-label="Anuluj edycję komentarza"><i class="bi bi-x"></i></button>
                            </span>
                    </div>

                    <div class="cx-fields cx-fields--inner">
                    <!-- Opak. widoczne dopiero, gdy wybrany wariant ma
                         zdefiniowaną wielkość opakowania. Stepper wielkości
                         siedzi pod polem, blisko liczby, którą mnoży. -->
                    <div class="cx-field cx-xs-6 cx-md-3 cx-xl-3" id="cartPackagesWrap" style="display:none">
                        <label class="cx-label" for="cartPackages">Opak.</label>
                        <input type="number" id="cartPackages" class="form-control" min="0" step="1" placeholder="opak." title="Ilość = N × opak.">
                        <!-- Stepper +/- dla wybranej wielkości opakowania.
                             Ołówek otwiera pole „nowa wielkość" (to samo
                             co edycja komentarza: ukrywa stepper, aż do
                             zapisu albo anulowania). -->
                        <div id="cartPackSizePickerWrap" class="cx-pack" style="display:none">
                            <span class="cx-pack-label">wielkość opak.</span>
                            <!-- Jedna linia bez zawijania: stepper + ołówek
                                 albo (po kliknięciu ołówka) pole „nowa wielkość". -->
                            <div class="cx-pack-row">
                                <span id="cartPackSizeStepper" class="cx-stepper" role="group" aria-label="Wielkość opakowania">
                                    <button type="button" id="cartPackSizePrev" title="Poprzednia wielkość" aria-label="Poprzednia wielkość opakowania">&minus;</button>
                                    <span id="cartPackSizeValue" class="cx-step-val">&mdash;</span>
                                    <button type="button" id="cartPackSizeNext" title="Następna wielkość" aria-label="Następna wielkość opakowania">+</button>
                                </span>
                                <button type="button" id="cartPackSizeAdd" class="cx-mini-btn"
                                        title="Dodaj nową wielkość opakowania"
                                        aria-label="Dodaj nową wielkość opakowania"><i class="bi bi-pencil"></i></button>
                                <!-- Pole zaczyna jako `d-none`; JS przełącza
                                     d-none ↔ d-flex (bez walki o priorytet z inline). -->
                                <span id="cartPackSizeEditBox" class="cx-pack-edit d-none">
                                    <input type="number" id="cartPackSizeInput" class="form-control form-control-sm" min="0.0001" step="0.0001" placeholder="np. 2500" aria-label="Nowa wielkość opakowania">
                                    <button type="button" id="cartPackSizeSave" class="btn btn-sm btn-success" title="Zapisz" aria-label="Zapisz wielkość opakowania"><i class="bi bi-check"></i></button>
                                    <button type="button" id="cartPackSizeCancel" class="btn btn-sm btn-secondary" title="Anuluj (Esc)" aria-label="Anuluj"><i class="bi bi-x"></i></button>
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="cx-field cx-xs-6 cx-md-3 cx-xl-2" id="cartQtyWrap">
                        <label class="cx-label" for="cartQty">Ilość</label>
                        <div class="input-group">
                            <input type="number" id="cartQty" class="form-control" min="0.0001" step="0.0001">
                            <div class="input-group-append">
                                <span class="input-group-text" id="cartQtyUnit">szt.</span>
                            </div>
                        </div>
                    </div>

                <!-- Cena / Waluta / Komentarz pozycji — widoczne dopiero po
                     wyborze konkretnego wariantu (przy niejednoznacznym
                     wyborze nie mają sensu). „Komentarz pozycji" jest
                     jednorazowy dla tej linii koszyka i trafia do
                     RFQ / zamówienia; to coś innego niż „Uwagi do artykułu"
                     zapisywane na karcie artykułu. -->
                <div id="cartPriceRow" class="cx-contents" style="display:none">
                    <div class="cx-field cx-xs-6 cx-md-3 cx-xl-2" id="cartPriceWrap">
                        <label class="cx-label" for="cartPrice">Cena/szt.</label>
                        <input type="number" id="cartPrice" class="form-control" min="0" step="0.0001" placeholder="opcjonalna">
                    </div>
                    <div class="cx-field cx-xs-6 cx-md-3 cx-xl-2" id="cartCurrencyWrap">
                        <label class="cx-label" for="cartCurrency">Waluta</label>
                        <div id="cartCurrencyCell">
                            <select id="cartCurrency" name="cartCurrency"
                                    class="selectpicker form-control"
                                    data-width="100%"
                                    data-container="#cartCurrencyCell"
                                    data-style="cx-picker-btn"
                                    data-live-search="true"
                                    title="Wybierz walutę…">
                                <?php foreach ($currencies as $cur):
                                    $code = (string)$cur['code'];
                                    $name = (string)$cur['name'];
                                    $isSelected = ($code === 'PLN'); // initial default; JS overrides on vendor change
                                ?>
                                    <option value="<?= htmlspecialchars($code, ENT_QUOTES) ?>"
                                            <?= $isSelected ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($code, ENT_QUOTES) ?> — <?= htmlspecialchars($name, ENT_QUOTES) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="cx-field cx-md-6 cx-xl-3" id="cartLineCommentWrap">
                        <label class="cx-label" for="cartLineComment">Komentarz pozycji <span class="cx-opt">(opcjonalnie)</span></label>
                        <input type="text" id="cartLineComment" class="form-control" maxlength="500"
                               placeholder="Jednorazowe uwagi — trafią do RFQ / zamówienia">
                    </div>
                </div>
                    </div><!-- /.cx-fields--inner -->
                </section><!-- /#pickVariantRow -->

                <!-- Tryb „Artykuł": nowy wariant dostawcy do katalogu. -->
                <div id="addVariantRow1" class="cx-contents" style="display:none">
                    <div class="cx-field cx-sm-6 cx-xl-3">
                        <label class="cx-label" for="addProducerSelect">Producent</label>
                        <select id="addProducerSelect" class="selectpicker form-control" data-live-search="true" data-width="100%" data-style="cx-picker-btn" title="Wybierz producenta...">
                            <?php foreach ($producers as $pr): ?>
                                <option value="<?= (int)$pr['id'] ?>"
                                        data-name="<?= htmlspecialchars($pr['name']) ?>">
                                    <?= htmlspecialchars($pr['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="cx-field cx-sm-6 cx-xl-3">
                        <label class="cx-label" for="addUnitSelect">JM</label>
                        <select id="addUnitSelect" class="selectpicker form-control" data-live-search="true" data-width="100%" data-style="cx-picker-btn" title="Wybierz jednostkę...">
                            <?php foreach ($units as $u): ?>
                                <option value="<?= (int)$u['id'] ?>"
                                        data-name="<?= htmlspecialchars($u['name']) ?>">
                                    <?= htmlspecialchars($u['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="cx-field cx-sm-6 cx-xl-3">
                        <label class="cx-label" for="addVendorPartNo">Numer u dostawcy</label>
                        <input type="text" id="addVendorPartNo" class="form-control" maxlength="100" placeholder="np. ABC-1234">
                    </div>
                    <div class="cx-field cx-sm-6 cx-xl-3">
                        <label class="cx-label" for="addFullPackQuantity">Pełne opakowanie</label>
                        <input type="text" id="addFullPackQuantity" class="form-control" placeholder="np. 100/1000/5000">
                        <p class="cx-hint">Wielkości opakowań oddzielone `/`, np. 100/1000/5000. Puste = brak opakowania.</p>
                    </div>
                </div>

                <div id="addVariantRow2" class="cx-contents" style="display:none">
                    <div class="cx-field cx-md-6">
                        <label class="cx-label" for="addProducerPartNo">Numer części u producenta <span class="cx-opt">(opcjonalnie)</span></label>
                        <input type="text" id="addProducerPartNo" class="form-control" maxlength="100" placeholder="np. MFR-5678 (opcjonalnie)">
                    </div>
                </div>

                <div id="addVariantCommentRow" class="cx-contents" style="display:none">
                    <div class="cx-field cx-md-6">
                        <label class="cx-label" for="addComment">Komentarz <span class="cx-opt">(opcjonalnie)</span></label>
                        <input type="text" id="addComment" class="form-control" maxlength="255" placeholder="Komentarz tylko dla nas — nie trafi do dostawcy">
                    </div>
                </div>

            </div>
        </div>

        <div class="cx-picker-foot">
            <button type="button" id="toggleAddVariantBtn" class="btn btn-outline-info"
                    title="Dodaj nowy artykuł dostawcy do katalogu">
                <i class="bi bi-plus-square"></i> Artykuł
            </button>
            <div class="cx-foot-actions">
                <button type="button" id="clearSelectionBtn" class="btn rx-btn-quiet" title="Wyczyść wybór dostawcy i części">
                    Wyczyść
                </button>
                <button type="button" id="addToCartBtn" class="btn btn-success" disabled>
                    <i class="bi bi-plus-circle"></i> Dodaj
                </button>
            </div>
        </div>
    </section>

    <!-- ===== Koszyk (grupowany po dostawcy) ===== -->
    <section id="cartCard" aria-labelledby="cxCartTitle" style="display:none">
        <div class="cx-cart-head">
            <h2 class="cx-cart-title" id="cxCartTitle">
                <i class="bi bi-cart3" aria-hidden="true"></i> Koszyk
                <span class="rx-count" id="cartCount">0</span>
            </h2>
            <button type="button" id="clearCartBtn" class="btn btn-sm rx-btn-quiet cx-btn-danger">
                <i class="bi bi-trash"></i> Wyczyść koszyk
            </button>
        </div>
        <!-- Przełącznik dostawców — zakładki renderuje JS (renderCartTabs);
             to nie jest Bootstrapowe data-toggle="tab". -->
        <div class="nav rx-tabs cx-vtabs" id="cartTabs" role="tablist" aria-label="Dostawcy w koszyku"></div>
        <div id="cartBody" role="tabpanel"></div>
    </section>

    <!-- Pusty koszyk — JS pokazuje to zamiast #cartCard. -->
    <div class="rx-panel cx-empty" id="cartEmpty" style="display:none">
        <div class="rx-empty">
            <div class="rx-empty-icon"><i class="bi bi-cart3"></i></div>
            <div class="rx-empty-title">Koszyk jest pusty</div>
            <div class="rx-empty-text">
                Wybierz dostawcę i część powyżej, wpisz ilość i kliknij „Dodaj".
                Pozycje zostaną pogrupowane według dostawcy, a stąd utworzysz
                zapytanie albo zamówienie.
            </div>
        </div>
    </div>

    <!-- ===== Modal: szukaj numeru u dostawcy ===== -->
    <div class="modal fade cx-modal" id="vpSearchModal" tabindex="-1" role="dialog" aria-labelledby="vpSearchModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="vpSearchModalTitle"><i class="bi bi-search"></i> Szukaj numeru u dostawcy</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Zamknij"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <input type="text" id="vpSearchInput" class="form-control" placeholder="Min. 2 znaki — numer dostawcy, numer producenta, część lub dostawca…" aria-label="Szukaj numeru u dostawcy" autocomplete="off">
                    <div id="vpSearchStatus" class="text-muted mt-2" style="display:none"></div>
                    <div class="cx-search-scroll">
                        <table class="table rx-table mb-0">
                            <thead>
                                <tr><th>Numer u dostawcy</th><th>Nr producenta</th><th>Producent</th><th>Dostawca</th><th>Część</th><th>JM / opak.</th><th><span class="sr-only">Akcja</span></th></tr>
                            </thead>
                            <tbody id="vpSearchResults"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== Modal: adres sklepu dostawcy (pytamy tylko raz) ===== -->
    <div class="modal fade cx-modal" id="shopUrlModal" tabindex="-1" role="dialog" aria-labelledby="shopUrlModalTitle" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="shopUrlModalTitle"><i class="bi bi-box-arrow-up-right"></i> Adres sklepu — <span id="shopUrlVendorName"></span></h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Zamknij"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <p>Adres zapisze się na karcie dostawcy, więc zapytamy o niego tylko raz.</p>
                    <div id="shopUrlError" class="alert alert-danger py-2 px-3" role="alert" style="display:none"></div>
                    <input type="text" id="shopUrlInput" class="form-control" maxlength="500"
                           placeholder="https://www.sklep-dostawcy.pl" aria-label="Adres sklepu dostawcy" autocomplete="off">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn rx-btn-quiet" data-dismiss="modal">Anuluj</button>
                    <button type="button" class="btn btn-primary" id="shopUrlSave">Zapisz i otwórz</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== Help modal: shown when the user clicks "+ Artykuł" ===== -->
    <div class="modal fade cx-modal" id="addVariantHelpModal" tabindex="-1" role="dialog" aria-labelledby="addVariantHelpTitle" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addVariantHelpTitle"><i class="bi bi-info-circle"></i> Dodawanie nowego artykułu</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Zamknij"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <p>Tworzenie nowego artykułu u dostawcy przebiega w dwóch krokach:</p>
                    <ol>
                        <li><strong>Dodaj artykuł do katalogu</strong> &mdash; uzupełnij producenta, jednostkę (JM), numer u&nbsp;dostawcy i&nbsp;pełne opakowanie (oraz opcjonalnie numer u&nbsp;producenta i&nbsp;komentarz). Kliknij <strong>Zapisz</strong> &mdash; artykuł zostanie dodany do katalogu.</li>
                        <li><strong>Dodaj do koszyka</strong> &mdash; wrócisz do normalnego widoku koszyka z&nbsp;nowym artykułem już wybranym. Wpisz ilość, cenę i&nbsp;walutę, kliknij <strong>Dodaj</strong>.</li>
                    </ol>
                    <p class="text-muted small mb-0">
                        <i class="bi bi-info-circle"></i> Cena i&nbsp;ilość są specyficzne dla konkretnego zamówienia &mdash; dlatego najpierw zapisujemy artykuł w&nbsp;katalogu, a&nbsp;dopiero potem dodajemy do koszyka.
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn rx-btn-quiet" data-dismiss="modal">Anuluj</button>
                    <button type="button" class="btn btn-primary" id="addVariantHelpContinue">Rozumiem, kontynuuj</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== Reusable confirmation modal =====
         One Bootstrap modal driven by cartConfirm({title, body, okLabel,
         okClass, onConfirm}) in cart-view.js. Used for delete-row,
         delete-vendor-items, create-doc and clear-cart actions so the
         native window.confirm / no-confirm UX is replaced with a
         consistent themed dialog. -->
    <div class="modal fade cx-modal" id="cartConfirmModal" tabindex="-1" role="dialog" aria-labelledby="cartConfirmTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="cartConfirmTitle">Potwierdź</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Zamknij"><span>&times;</span></button>
                </div>
                <div class="modal-body" id="cartConfirmBody"></div>
                <div class="modal-footer">
                    <button type="button" class="btn rx-btn-quiet" data-dismiss="modal">Anuluj</button>
                    <button type="button" class="btn btn-danger" id="cartConfirmOk">Potwierdź</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    const VENDOR_PARTS_INDEX = <?= json_encode($vendorPartsIndex, JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="<?= asset('public_html/components/purchases/cart/cart-view.js') ?>"></script>
