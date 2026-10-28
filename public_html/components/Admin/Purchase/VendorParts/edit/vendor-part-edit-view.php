<?php
/**
 * Dedicated edit/create page for a single vendor-part.
 *
 * Wired at /admin/purchase/vendor-parts/edit (mirrors the
 * documents-edit.php convention; see also the
 * producers/edit dual-mode page). Renders the form on GET and lets
 * vendor-part-edit-save.php handle the POST submission.
 *
 * Modes:
 *   - `?id=N` (N > 0) → EDIT existing vendor-part (pre-populated fields)
 *   - absent / id<=0  → CREATE new vendor-part (empty form)
 *
 * Form fields mirror list__vendor_part + list__vendor_part_pack:
 *   - vendor / producer / part / JM (live-search selectpickers,
 *     full lists pre-rendered server-side — no AJAX search needed
 *     since this page is a single-record form)
 *   - vendor_part_no, producer_part_no (text)
 *   - multi-pack editing: one number input per pack size, with
 *     add/remove buttons; pre-populated from list__vendor_part_pack
 *     (empty in CREATE mode)
 *   - isActive (radio: Aktywny / Nieaktywny; defaults to Aktywny in
 *     CREATE mode)
 *   - comment (textarea)
 *
 * Formularz to pięć kart (każda z ikoną, tytułem i jednym zdaniem opisu):
 *   Dane podstawowe (część, dostawca, producent, JM), Numery części,
 *   Opakowania, Status oraz „Więcej" (komentarz).
 * WSZYSTKIE startują zwinięte i pokazują jednolinijkowe podsumowanie
 * bieżących wartości (tylko do odczytu); kliknięcie nagłówka rozwija
 * edytowalne pola. Podsumowanie leży POZA przyciskiem nagłówka, a odświeża
 * je vendor-part-edit-view.js przy każdej zmianie pola — format musi być
 * identyczny po obu stronach. Wszystkie karty leżą w jednym #vpEditForm:
 * zwinięcie karty chowa pola tylko CSS-em, więc serialize() nadal je wysyła
 * (łącznie ze wszystkimi pack_quantities[]).
 *
 * The form is AJAX-driven. On a successful CREATE the JS follows the
 * `edit_url` returned by the save endpoint so the operator lands on
 * the edit page for the freshly-created vendor-part (mirrors the
 * cart-view.js "open in new tab" pattern). On a successful UPDATE
 * the JS stays on the page and shows an inline success alert. On a
 * validation error the save handler returns JSON and the JS shows an
 * inline alert in #vpFormAlertHost.
 *
 * Wygląd: ten sam język co edycja dostawcy i producenta
 * (/admin/purchase/vendors/edit, /admin/purchase/producers/edit) —
 * receipts-ui.css jako baza (tylko do odczytu) + vendor-part-edit-ui.css
 * (świadoma kopia systemu kart, prefiks `vpe-`). Cała strona stoi w jednej
 * kolumnie o szerokości 75%.
 */
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Master\VendorPartRepository;

if (!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    header("Location: http://" . BASEURL . "/");
    exit();
}

$id         = (int)($_GET['id'] ?? 0);
$isEditMode = $id > 0;
$vp         = null;
$notFound   = false;

$MsaDB = MsaDB::getInstance();
$repo  = new VendorPartRepository($MsaDB);

if ($isEditMode) {
    $vp = $repo->getById($id);
    if ($vp === null) {
        // Nie ma takiego artykułu — strona pokazuje pusty stan zamiast
        // formularza (który i tak nie miałby czego zapisywać).
        $notFound = true;
    }
}

// Source lists for the FK dropdowns. Full lists — this page doesn't
// cascade (single-record form), and bootstrap-select's live-search
// gives the operator a quick filter even with hundreds of options.
$vendor_list       = [];
$producer_list     = [];
$unit_list         = [];
$part_names        = [];
$part_descriptions = [];

if (!$notFound) {
    $vendor_list   = $MsaDB->readIdName('list__vendor',   'id', 'name', 'ORDER BY name ASC');
    $producer_list = $MsaDB->readIdName('list__producer', 'id', 'name', 'ORDER BY name ASC');
    $unit_list     = $MsaDB->readIdName('part__unit',     'id', 'name', 'ORDER BY name ASC');

    // Część dropdown needs the description next to every part so each
    // <option> can render a bootstrap-select `data-subtext` (the canonical
    // second-line under each option label). Same pattern as the listing.
    $parts_rows = $MsaDB->db->query(
        "SELECT id, name, description FROM `list__parts` ORDER BY name ASC",
        \PDO::FETCH_ASSOC
    )->fetchAll();
    foreach ($parts_rows as $pr) {
        $idKey = (int)$pr['id'];
        $part_names[$idKey]        = $pr['name'];
        $part_descriptions[$idKey] = (string)($pr['description'] ?? '');
    }
}

// Field defaults. In EDIT mode they pull from the loaded row; in
// CREATE mode the strings are empty and the radio defaults to active.
$hasRow         = $isEditMode && !$notFound;
$vendorId       = $hasRow ? $vp->vendorId       : null;
$producerId     = $hasRow ? $vp->producerId     : null;
$partsId        = $hasRow ? $vp->partsId        : null;
$vendorJmId     = $hasRow ? $vp->vendorJmId     : null;
$vendorPartNo   = $hasRow ? (string)$vp->vendorPartNo : '';
$producerPartNo = $hasRow ? (string)($vp->producerPartNo ?? '') : '';
$isActive       = $hasRow ? (bool)$vp->isActive : true;  // default Aktywny on create
$comment        = $hasRow ? (string)($vp->comment ?? '') : '';
$packs          = $hasRow
    ? array_map(
        // The repo returns the full set sorted ASC; render each as a
        // trimmed numeric string for the HTML input.
        fn($p) => rtrim(rtrim(number_format((float)$p, 10, '.', ''), '0'), '.'),
        $vp->packQuantities
    )
    : [];
// In CREATE mode no pack rows render; the operator can add the
// first pack via the "Dodaj opakowanie" button. Empty pack set is
// also valid — parts that aren't packaged.

// Inline option renderer that respects a `selected` id (the existing
// SelectRenderer doesn't — it always emits plain options).
function vp_render_options(array $array, ?int $selectedId): void {
    foreach ($array as $idKey => $value) {
        $sel     = ($selectedId !== null && (int)$idKey === (int)$selectedId) ? ' selected' : '';
        $valEsc  = htmlspecialchars((string)$idKey, ENT_QUOTES);
        $valHtml = htmlspecialchars((string)$value, ENT_QUOTES);
        echo '<option value="' . $valEsc . '"' . $sel . '>' . $valHtml . '</option>';
    }
}
// Same, with the part description as bootstrap-select `data-subtext`.
function vp_render_options_with_subtext(array $array, array $subText, ?int $selectedId): void {
    foreach ($array as $idKey => $value) {
        $sel     = ($selectedId !== null && (int)$idKey === (int)$selectedId) ? ' selected' : '';
        $valEsc  = htmlspecialchars((string)$idKey, ENT_QUOTES);
        $valHtml = htmlspecialchars((string)$value, ENT_QUOTES);
        $sub     = htmlspecialchars((string)($subText[$idKey] ?? ''), ENT_QUOTES);
        echo '<option data-subtext="' . $sub . '" data-tokens="' . $valHtml . ' ' . $sub . '" value="' . $valEsc . '"' . $sel . '>' . $valHtml . '</option>';
    }
}

$backUrl = 'http://' . BASEURL . '/admin/purchase/vendor-parts';

// Skróty do kart dostawcy i producenta tego artykułu (tylko tryb edycji).
$vendorEditBase   = 'http://' . BASEURL . '/admin/purchase/vendors/edit?id=';
$producerEditBase = 'http://' . BASEURL . '/admin/purchase/producers/edit?id=';

// ---- Linijki podsumowania zwiniętych kart --------------------------
// Wszystkie karty startują zwinięte, więc podsumowanie musi być gotowe
// przy pierwszym malowaniu strony (bez czekania na JS). vendor-part-edit-view.js
// odświeża te same teksty przy każdej zmianie pola — format musi być
// identyczny po obu stronach (separator „ · ").
// Każde podsumowanie to para [html, tekst]: html idzie do strony (z
// <strong> przy pierwszej wartości), tekst do atrybutu title (jawna lista
// „pole: wartość", „—" gdy puste).
$vpEsc = static function ($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES);
};
// Pole bez wartości: sama krótka nazwa w stylu „brak" (kursywa + wyblakły
// kolor). Wypełnione = jego wartość.
$vpMissing = static function (string $name) use ($vpEsc): string {
    return '<span class="vpe-sum-missing">' . $vpEsc($name) . '</span>';
};
// Spacje/nowe linie w wartości do jednej linii.
$vpOneLine = static function ($s): string {
    return trim((string)preg_replace('/\s+/u', ' ', (string)$s));
};
$vpTip = static function (string $label, string $value) use ($vpOneLine): string {
    return $label . ': ' . ($value === '' ? '—' : $vpOneLine($value));
};
// Wielkość opakowania bez zer końcowych (100.0000 → 100, 0.5 → 0.5).
$vpFmtQty = static function ($n): string {
    return rtrim(rtrim(number_format((float)$n, 4, '.', ''), '0'), '.');
};
$vpSEP = ' · ';

// Etykiety wybranych pozycji pickerów (nazwa części, nie jej opis).
$vpPartLabel     = $partsId    !== null ? trim((string)($part_names[(int)$partsId]       ?? '')) : '';
$vpVendorLabel   = $vendorId   !== null ? trim((string)($vendor_list[(int)$vendorId]     ?? '')) : '';
$vpProducerLabel = $producerId !== null ? trim((string)($producer_list[(int)$producerId] ?? '')) : '';
$vpUnitLabel     = $vendorJmId !== null ? trim((string)($unit_list[(int)$vendorJmId]     ?? '')) : '';

// Wypełnione pole z małym, wyciszonym kluczem przed wartością
// („DOSTAWCA TME") — żeby było jasne, która wartość jest czym. Puste pole
// zostaje samą nazwą w stylu „brak" (bez klucza).
$vpKeyed = static function (string $key, string $valueHtml) use ($vpEsc): string {
    return '<span class="vpe-sum-key">' . $vpEsc($key) . '</span> ' . $valueHtml;
};

// Dane podstawowe: część · dostawca · producent · JM.
$vpSumBasic = [
    implode($vpSEP, [
        $vpPartLabel     !== '' ? $vpKeyed('część', '<strong>' . $vpEsc($vpPartLabel) . '</strong>') : $vpMissing('część'),
        $vpVendorLabel   !== '' ? $vpKeyed('dostawca', $vpEsc($vpVendorLabel))   : $vpMissing('dostawca'),
        $vpProducerLabel !== '' ? $vpKeyed('producent', $vpEsc($vpProducerLabel)) : $vpMissing('producent'),
        $vpUnitLabel     !== '' ? $vpKeyed('JM', $vpEsc($vpUnitLabel))           : $vpMissing('JM'),
    ]),
    implode($vpSEP, [
        $vpTip('część', $vpPartLabel),
        $vpTip('dostawca', $vpVendorLabel),
        $vpTip('producent', $vpProducerLabel),
        $vpTip('JM', $vpUnitLabel),
    ]),
];

// Numery części: nr dostawcy · nr producenta.
$vpVpn = trim($vendorPartNo);
$vpPpn = trim($producerPartNo);
$vpSumNumbers = [
    implode($vpSEP, [
        $vpVpn !== '' ? $vpKeyed('nr dostawcy', '<strong>' . $vpEsc($vpVpn) . '</strong>') : $vpMissing('nr dostawcy'),
        $vpPpn !== '' ? $vpKeyed('nr producenta', $vpEsc($vpPpn)) : $vpMissing('nr producenta'),
    ]),
    implode($vpSEP, [
        $vpTip('nr dostawcy', $vpVpn),
        $vpTip('nr producenta', $vpPpn),
    ]),
];

// Opakowania: „100 · 500 · 2500" albo nazwa „opakowania" w stylu „brak".
$vpPackList = [];
foreach ($packs as $vpPackVal) {
    if (trim((string)$vpPackVal) !== '') {
        $vpPackList[] = $vpFmtQty($vpPackVal);
    }
}
$vpPackText = implode($vpSEP, $vpPackList);
$vpSumPacks = [
    $vpPackText !== '' ? $vpEsc($vpPackText) : $vpMissing('opakowania'),
    $vpTip('opakowania', $vpPackText),
];

// Status.
$vpSumStatusLabel = $isActive ? 'Aktywny' : 'Nieaktywny';
$vpSumStatus = [
    '<span class="vpe-sum-dot ' . ($isActive ? 'is-on' : 'is-off') . '" aria-hidden="true"></span>'
        . $vpSumStatusLabel,
    $vpSumStatusLabel,
];

// Więcej: komentarz (jedna linia).
$vpCom = $vpOneLine(trim($comment));
$vpSumMore = [
    $vpCom !== '' ? $vpEsc($vpCom) : $vpMissing('komentarz'),
    $vpTip('komentarz', $vpCom),
];

// Okruszek: numer u dostawcy, a gdy go brak — nazwa części.
$vpCrumb = $vpVpn !== '' ? $vpVpn : $vpPartLabel;

?>
<link rel="stylesheet" href="<?= asset('public_html/components/purchases/receipts/receipts-ui.css') ?>">
<link rel="stylesheet" href="<?= asset('public_html/components/Admin/Purchase/VendorParts/edit/vendor-part-edit-ui.css') ?>">

<div class="rx-page vpe-page" id="vendorPartEditView">

  <!-- Cała strona w jednej kolumnie (75%) — okruszki, nagłówek i
       formularz mają tę samą szerokość. -->
  <div class="vpe-column">

    <!-- ===== Ścieżka nawigacji ===== -->
    <nav aria-label="Ścieżka">
        <ol class="rx-crumbs">
            <li><a href="<?= htmlspecialchars($backUrl, ENT_QUOTES) ?>">Artykuły u dostawców</a></li>
            <li aria-hidden="true"><i class="bi bi-chevron-right"></i></li>
            <li aria-current="page">
                <?php if ($notFound): ?>
                    Artykuł #<?= (int)$id ?>
                <?php elseif ($isEditMode): ?>
                    <?= htmlspecialchars($vpCrumb, ENT_QUOTES) ?>
                <?php else: ?>
                    Nowy artykuł
                <?php endif; ?>
            </li>
        </ol>
    </nav>

    <!-- ===== Nagłówek strony ===== -->
    <header class="rx-head">
        <div class="rx-head-main">
            <div class="rx-eyebrow"><i class="bi bi-box-seam"></i> Zamówienia komponentów</div>
            <h1 class="rx-title">
                <?= !$notFound && !$isEditMode ? 'Dodaj artykuł u dostawcy' : 'Edytuj artykuł u dostawcy' ?>
            </h1>
            <p class="rx-lead">
                <?php if ($notFound): ?>
                    Nie znaleziono artykułu o&nbsp;podanym identyfikatorze.
                <?php elseif ($isEditMode): ?>
                    Część z&nbsp;naszego katalogu, dostawca, producent, numery części i&nbsp;opakowania.
                    Zmiany zapisujesz przyciskiem na&nbsp;dole formularza.
                <?php else: ?>
                    Połącz część z&nbsp;naszego katalogu z&nbsp;dostawcą i&nbsp;producentem —
                    numery części i&nbsp;opakowania uzupełnisz w&nbsp;kartach poniżej.
                <?php endif; ?>
            </p>
        </div>
        <?php if ($hasRow): ?>
            <!-- Id artykułu + dwa ciche skróty do kart dostawcy i producenta
                 tego artykułu. Bez przycisku „Wróć do listy": jest w okruszkach
                 i w pasku zapisu pod formularzem. Skróty podążają za tym, co
                 wybrano w pickerach (JS), a ich title mówi dokąd prowadzą. -->
            <div class="vpe-head-links">
                <span class="rx-count vpe-head-id" title="Identyfikator artykułu">#<?= (int)$vp->id ?></span>
                <a class="btn rx-btn-quiet vpe-rel-link" id="vpVendorLink"
                   href="<?= htmlspecialchars($vendorEditBase . (int)$vendorId, ENT_QUOTES) ?>"
                   data-base="<?= htmlspecialchars($vendorEditBase, ENT_QUOTES) ?>"
                   data-label="Karta dostawcy"
                   title="Karta dostawcy: <?= htmlspecialchars($vpVendorLabel, ENT_QUOTES) ?>">
                    <i class="bi bi-truck"></i> Dostawca
                </a>
                <a class="btn rx-btn-quiet vpe-rel-link" id="vpProducerLink"
                   href="<?= htmlspecialchars($producerEditBase . (int)$producerId, ENT_QUOTES) ?>"
                   data-base="<?= htmlspecialchars($producerEditBase, ENT_QUOTES) ?>"
                   data-label="Karta producenta"
                   title="Karta producenta: <?= htmlspecialchars($vpProducerLabel, ENT_QUOTES) ?>">
                    <i class="bi bi-building"></i> Producent
                </a>
            </div>
        <?php endif; ?>
    </header>

    <!-- Host na komunikaty AJAX (wynik zapisu). Pusty, dopiero JS wstawia
         tu alert — dlatego osobny, stały punkt zaczepienia. -->
    <div id="vpFormAlertHost" class="rx-alerts"></div>

<?php if ($notFound): ?>

    <!-- ===== Nie ma takiego artykułu ===== -->
    <section class="rx-panel vpe-panel">
        <div class="rx-empty rx-empty--page">
            <div class="rx-empty-icon rx-empty-icon--danger"><i class="bi bi-exclamation-triangle"></i></div>
            <div class="rx-empty-title">Artykuł nie istnieje</div>
            <div class="rx-empty-text">
                Artykuł #<?= htmlspecialchars((string)$id, ENT_QUOTES) ?> nie został znaleziony
                albo został usunięty.
            </div>
            <a href="<?= htmlspecialchars($backUrl, ENT_QUOTES) ?>" class="btn btn-sm btn-primary">
                <i class="bi bi-arrow-left"></i> Wróć do listy
            </a>
        </div>
    </section>

<?php else: ?>

    <!-- ================================================================
         Formularz artykułu. Wszystkie pola leżą WEWNĄTRZ #vpEditForm,
         więc $form.serialize() widzi komplet (id, parts_id, vendor_id,
         producer_id, vendor_jm_id, vendor_part_no, producer_part_no,
         pack_quantities[], isActive, comment) także wtedy, gdy wszystkie
         karty są zwinięte.
         ================================================================ -->
    <form method="post" id="vpEditForm" autocomplete="off">
        <input type="hidden" name="id" value="<?= $isEditMode ? (int)$vp->id : 0 ?>">

        <!-- Wszystkie pięć kart ma ten sam wzór (zwijanie Bootstrap 4):
             <h2> NA ZEWNĄTRZ przycisku (przycisk przyjmuje tylko treść
             frazową), w nim ikona + tytuł + opis + strzałka; pod nagłówkiem
             linijka podsumowania (też poza przyciskiem — długi tekst nie
             ma być nazwą przycisku dla czytnika ekranu); potem ciało
             .collapse. Karty startują ZWINIĘTE (klasa `collapsed` na
             przycisku, brak `show` na ciele). Podsumowanie widać tylko
             przy zwiniętej karcie, opis tylko przy rozwiniętej — patrz
             vendor-part-edit-ui.css. -->

        <!-- Przełącznik wszystkich kart naraz. -->
        <div class="vpe-form-tools">
            <button type="button" class="btn rx-link-btn" id="vpToggleAllCards">
                <i class="bi bi-arrows-expand" aria-hidden="true"></i>
                <span class="vpe-tools-label">Rozwiń wszystko</span>
            </button>
        </div>

        <!-- ===== Karta 1: Dane podstawowe ===== -->
        <section class="rx-panel vpe-panel vpe-card" id="vpBasicCard">
            <h2 class="vpe-card-toggle-h">
                <button type="button" class="vpe-card-head vpe-card-head--toggle collapsed"
                        data-toggle="collapse" data-target="#vpBasicBody"
                        aria-expanded="false" aria-controls="vpBasicBody">
                    <span class="vpe-card-icon" aria-hidden="true"><i class="bi bi-box-seam"></i></span>
                    <span class="vpe-card-text">
                        <span class="vpe-section-label">Dane podstawowe</span>
                        <span class="vpe-card-desc">Część z&nbsp;naszego katalogu oraz dostawca, producent i&nbsp;jednostka miary.</span>
                    </span>
                    <span class="vpe-card-aside">
                        <i class="bi bi-chevron-down vpe-card-chevron" aria-hidden="true"></i>
                    </span>
                </button>
            </h2>
            <p class="vpe-card-summary" id="vpBasicSummary" title="<?= $vpEsc($vpSumBasic[1]) ?>"><?= $vpSumBasic[0] ?></p>
            <div class="collapse" id="vpBasicBody">
              <div class="vpe-card-body">
                <div class="vpe-grid">

                    <!-- AGENTS.md: każdy picker w komórce .vpe-picker z
                         min-width: 0, data-width="100%" i data-container
                         wskazującym tę komórkę (menu zostaje w kolumnie). -->
                    <div class="vpe-field vpe-field--12">
                        <label class="vpe-label" for="vp_edit_parts_id">
                            Część (nasz katalog) <span class="vpe-required">*</span>
                        </label>
                        <div class="vpe-picker" id="vpPartCell">
                            <select id="vp_edit_parts_id" name="parts_id"
                                    class="selectpicker"
                                    data-live-search="true"
                                    data-size="10"
                                    data-width="100%"
                                    data-container="#vpPartCell"
                                    data-style="rx-picker-btn"
                                    data-title="Wybierz część..."
                                    required>
                                <?php vp_render_options_with_subtext($part_names, $part_descriptions, $partsId !== null ? (int)$partsId : null); ?>
                            </select>
                        </div>
                    </div>

                    <div class="vpe-field vpe-field--4">
                        <label class="vpe-label" for="vp_edit_vendor_id">
                            Dostawca <span class="vpe-required">*</span>
                        </label>
                        <div class="vpe-picker" id="vpVendorCell">
                            <select id="vp_edit_vendor_id" name="vendor_id"
                                    class="selectpicker"
                                    data-live-search="true"
                                    data-size="10"
                                    data-width="100%"
                                    data-container="#vpVendorCell"
                                    data-style="rx-picker-btn"
                                    data-title="Wybierz dostawcę..."
                                    required>
                                <?php vp_render_options($vendor_list, $vendorId !== null ? (int)$vendorId : null); ?>
                            </select>
                        </div>
                    </div>

                    <div class="vpe-field vpe-field--4">
                        <label class="vpe-label" for="vp_edit_producer_id">
                            Producent <span class="vpe-required">*</span>
                        </label>
                        <div class="vpe-picker" id="vpProducerCell">
                            <select id="vp_edit_producer_id" name="producer_id"
                                    class="selectpicker"
                                    data-live-search="true"
                                    data-size="10"
                                    data-width="100%"
                                    data-container="#vpProducerCell"
                                    data-style="rx-picker-btn"
                                    data-title="Wybierz producenta..."
                                    required>
                                <?php vp_render_options($producer_list, $producerId !== null ? (int)$producerId : null); ?>
                            </select>
                        </div>
                    </div>

                    <div class="vpe-field vpe-field--4">
                        <label class="vpe-label" for="vp_edit_vendor_jm_id">
                            JM <span class="vpe-required">*</span>
                        </label>
                        <div class="vpe-picker" id="vpUnitCell">
                            <select id="vp_edit_vendor_jm_id" name="vendor_jm_id"
                                    class="selectpicker"
                                    data-live-search="true"
                                    data-size="10"
                                    data-width="100%"
                                    data-container="#vpUnitCell"
                                    data-style="rx-picker-btn"
                                    data-title="Wybierz jednostkę miary..."
                                    required>
                                <?php vp_render_options($unit_list, $vendorJmId !== null ? (int)$vendorJmId : null); ?>
                            </select>
                        </div>
                    </div>

                </div>
                <p class="vpe-help vpe-required-note">Pola oznaczone <span class="vpe-required">*</span> są wymagane.</p>
              </div>
            </div>
        </section>

        <!-- ===== Karta 2: Numery części ===== -->
        <section class="rx-panel vpe-panel vpe-card" id="vpNumbersCard">
            <h2 class="vpe-card-toggle-h">
                <button type="button" class="vpe-card-head vpe-card-head--toggle collapsed"
                        data-toggle="collapse" data-target="#vpNumbersBody"
                        aria-expanded="false" aria-controls="vpNumbersBody">
                    <span class="vpe-card-icon" aria-hidden="true"><i class="bi bi-upc-scan"></i></span>
                    <span class="vpe-card-text">
                        <span class="vpe-section-label">Numery części</span>
                        <span class="vpe-card-desc">Oznaczenia, pod którymi dostawca i&nbsp;producent znają ten artykuł.</span>
                    </span>
                    <span class="vpe-card-aside">
                        <i class="bi bi-chevron-down vpe-card-chevron" aria-hidden="true"></i>
                    </span>
                </button>
            </h2>
            <p class="vpe-card-summary" id="vpNumbersSummary" title="<?= $vpEsc($vpSumNumbers[1]) ?>"><?= $vpSumNumbers[0] ?></p>
            <div class="collapse" id="vpNumbersBody">
              <div class="vpe-card-body">
                <div class="vpe-grid">

                    <div class="vpe-field vpe-field--6">
                        <label class="vpe-label" for="vp_edit_vendor_part_no">
                            Numer części u dostawcy <span class="vpe-required">*</span>
                        </label>
                        <input type="text" id="vp_edit_vendor_part_no" name="vendor_part_no"
                               class="form-control" required maxlength="255"
                               value="<?= htmlspecialchars($vendorPartNo, ENT_QUOTES) ?>">
                    </div>

                    <div class="vpe-field vpe-field--6">
                        <label class="vpe-label" for="vp_edit_producer_part_no">Numer części u producenta</label>
                        <input type="text" id="vp_edit_producer_part_no" name="producer_part_no"
                               class="form-control" maxlength="255"
                               value="<?= htmlspecialchars($producerPartNo, ENT_QUOTES) ?>"
                               placeholder="opcjonalnie">
                        <p class="vpe-help">Pozostaw puste, jeżeli nie jest znany.</p>
                    </div>

                </div>
                <p class="vpe-help vpe-required-note">Pola oznaczone <span class="vpe-required">*</span> są wymagane.</p>
              </div>
            </div>
        </section>

        <!-- ===== Karta 3: Opakowania ===== -->
        <section class="rx-panel vpe-panel vpe-card" id="vpPacksCard">
            <h2 class="vpe-card-toggle-h">
                <button type="button" class="vpe-card-head vpe-card-head--toggle collapsed"
                        data-toggle="collapse" data-target="#vpPacksBody"
                        aria-expanded="false" aria-controls="vpPacksBody">
                    <span class="vpe-card-icon" aria-hidden="true"><i class="bi bi-boxes"></i></span>
                    <span class="vpe-card-text">
                        <span class="vpe-section-label">Opakowania</span>
                        <span class="vpe-card-desc">Pełne opakowania, w&nbsp;których dostawca sprzedaje ten artykuł.</span>
                    </span>
                    <span class="vpe-card-aside">
                        <i class="bi bi-chevron-down vpe-card-chevron" aria-hidden="true"></i>
                    </span>
                </button>
            </h2>
            <p class="vpe-card-summary" id="vpPacksSummary" title="<?= $vpEsc($vpSumPacks[1]) ?>"><?= $vpSumPacks[0] ?></p>
            <div class="collapse" id="vpPacksBody">
              <div class="vpe-card-body">
                <div class="vpe-label" id="vpPackLabel">Pełne opakowania</div>
                <!-- Bez białych znaków między znacznikami, gdy lista jest pusta:
                     CSS (:empty) pokazuje wtedy podpowiedź „Brak opakowań". -->
                <div id="vpPackList" class="vpe-pack-list" role="group" aria-labelledby="vpPackLabel"><?php foreach ($packs as $packVal): ?><div class="vp-pack-row"><input type="number" name="pack_quantities[]" class="form-control" step="0.0001" min="0.0001" required value="<?= htmlspecialchars((string)$packVal, ENT_QUOTES) ?>" placeholder="np. 100" aria-label="Liczba sztuk w pełnym opakowaniu"><button type="button" class="btn rx-btn-quiet vp-pack-remove" title="Usuń opakowanie" aria-label="Usuń opakowanie"><i class="bi bi-x-lg"></i></button></div><?php endforeach; ?></div>
                <div class="vpe-pack-tools">
                    <button type="button" id="vpAddPackBtn" class="btn rx-btn-quiet">
                        <i class="bi bi-plus-circle"></i> Dodaj opakowanie
                    </button>
                </div>
                <p class="vp-pack-help">
                    Każde opakowanie to liczba sztuk w&nbsp;jednym pełnym opakowaniu u&nbsp;dostawcy
                    (np. <code>100</code> dla szpuli rezystorów). Wartości muszą być liczbami
                    dodatnimi i&nbsp;nie mogą się powtarzać. <strong>Pozostaw listę pustą,
                    jeżeli część nie jest pakowana</strong> (np. kupowana na&nbsp;sztuki).
                </p>
              </div>
            </div>
        </section>

        <!-- ===== Karta 4: Status ===== -->
        <section class="rx-panel vpe-panel vpe-card" id="vpStatusCard">
            <h2 class="vpe-card-toggle-h">
                <button type="button" class="vpe-card-head vpe-card-head--toggle collapsed"
                        data-toggle="collapse" data-target="#vpStatusBody"
                        aria-expanded="false" aria-controls="vpStatusBody">
                    <span class="vpe-card-icon" aria-hidden="true"><i class="bi bi-toggle-on"></i></span>
                    <span class="vpe-card-text">
                        <span class="vpe-section-label">Status</span>
                        <span class="vpe-card-desc">Czy artykuł jest dostępny w&nbsp;codziennej pracy.</span>
                    </span>
                    <span class="vpe-card-aside">
                        <i class="bi bi-chevron-down vpe-card-chevron" aria-hidden="true"></i>
                    </span>
                </button>
            </h2>
            <p class="vpe-card-summary" id="vpStatusSummary" title="<?= $vpEsc($vpSumStatus[1]) ?>"><?= $vpSumStatus[0] ?></p>
            <div class="collapse" id="vpStatusBody">
              <div class="vpe-card-body">
                <fieldset class="vpe-fieldset vpe-status-row">
                    <legend class="sr-only">Status artykułu</legend>
                    <div class="vpe-seg">
                        <label class="vpe-seg-item">
                            <input type="radio" name="isActive" value="1" <?= $isActive ? 'checked' : '' ?>>
                            <span class="vpe-seg-face vpe-seg-face--active">
                                <span class="vpe-seg-dot"></span>Aktywny
                            </span>
                        </label>
                        <label class="vpe-seg-item">
                            <input type="radio" name="isActive" value="0" <?= !$isActive ? 'checked' : '' ?>>
                            <span class="vpe-seg-face vpe-seg-face--inactive">
                                <span class="vpe-seg-dot"></span>Nieaktywny
                            </span>
                        </label>
                    </div>
                    <p class="vpe-help">
                        Artykuły nieaktywne nie pojawiają się w&nbsp;domyślnym widoku listy
                        ani w&nbsp;wyszukiwarkach koszyka zakupowego.
                    </p>
                </fieldset>
              </div>
            </div>
        </section>

        <!-- ===== Karta 5: Więcej ===== -->
        <section class="rx-panel vpe-panel vpe-card" id="vpMoreCard">
            <h2 class="vpe-card-toggle-h">
                <button type="button" class="vpe-card-head vpe-card-head--toggle collapsed"
                        data-toggle="collapse" data-target="#vpMoreBody"
                        aria-expanded="false" aria-controls="vpMoreBody">
                    <span class="vpe-card-icon" aria-hidden="true"><i class="bi bi-sliders"></i></span>
                    <span class="vpe-card-text">
                        <span class="vpe-section-label">Więcej</span>
                        <span class="vpe-card-desc">Komentarz — uwagi wewnętrzne o&nbsp;artykule.</span>
                    </span>
                    <span class="vpe-card-aside">
                        <i class="bi bi-chevron-down vpe-card-chevron" aria-hidden="true"></i>
                    </span>
                </button>
            </h2>
            <p class="vpe-card-summary" id="vpMoreSummary" title="<?= $vpEsc($vpSumMore[1]) ?>"><?= $vpSumMore[0] ?></p>
            <div class="collapse" id="vpMoreBody">
                <div class="vpe-card-body">
                    <div class="vpe-grid">
                        <div class="vpe-field vpe-field--12">
                            <label class="vpe-label" for="vp_edit_comment">Komentarz</label>
                            <textarea id="vp_edit_comment" name="comment" class="form-control" rows="3"
                                      placeholder="Opcjonalne uwagi wewnętrzne"><?= htmlspecialchars($comment, ENT_QUOTES) ?></textarea>
                            <p class="vpe-help">
                                Uwagi wewnętrzne — widoczne też na liście artykułów.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- ===== Pasek zapisu ===== -->
        <div class="vpe-panel-foot">
            <span class="vpe-foot-hint">
                <?= $isEditMode
                    ? 'Zmiany zapisujesz przyciskiem po prawej.'
                    : 'Po zapisaniu otworzy się karta nowego artykułu.' ?>
            </span>
            <div class="vpe-foot-actions">
                <a href="<?= htmlspecialchars($backUrl, ENT_QUOTES) ?>" class="btn rx-btn-quiet">
                    <i class="bi bi-arrow-left"></i> Wróć do listy
                </a>
                <button type="submit" class="btn btn-primary vpe-save-btn" id="vpSubmitBtn" disabled>
                    <?php if ($isEditMode): ?>
                        <i class="bi bi-check2-circle"></i> Zapisz zmiany
                    <?php else: ?>
                        <i class="bi bi-plus-circle"></i> Dodaj artykuł
                    <?php endif; ?>
                </button>
            </div>
        </div>
    </form>

<?php endif; ?>

  </div><!-- /.vpe-column -->

</div>

<script src="<?= asset('public_html/components/Admin/Purchase/VendorParts/edit/vendor-part-edit-view.js') ?>"></script>
