<?php
/**
 * "Oznacz odpowiedź" — dedykowana strona dla RFQ w stanie `sent`, gdzie
 * operator wpisuje wynegocjowane ceny/waluty/uwagi od dostawcy i jednym
 * klikem przechodzi zapytanie w stan `responded`. Następca "kroku
 * odpowiedź" w wizzardzie documents-send.php — wizzard jest wieloetapowy
 * i niewłaściwy dla tego jednoetapowego przepływu, więc wyodrębniliśmy
 * tę stronę zamiast linkować z powrotem do send-wizarda.
 *
 * Routing: /admin/purchase/documents/respond?id=N&type=rfq.
 * Walidacja serwerowa:
 *   - isAdmin (analogicznie do documents-send.php)
 *   - type=rfq (inne typy → 404)
 *   - RFQ istnieje
 *   - stan === 'sent' (bez tego przekierowanie na stronę edycji z flagą
 *     błędu — stan mógł się zmienić między otwarciem strony a klikem)
 *
 * Ładujemy pozycje przez RFQItemRepository::getByRfq() i formularz w
 * jednym kroku wysyła wszystko do document-rfq-respond-ajax.php, który
 * wywoła PurchaseActionHandler::markRfqRespondedWithPrices() — ten sam
 * endpoint, którego używa wizzard, ale z nowym payloadem items.
 *
 * UI: styl bootstrap-select z `data-width="100%"` + `data-container`
 * wskazujący na formularz + `min-width:0` na wrapperze flex
 * (zapobieganie "picker przekracza kolumnę" — purchases.css .bootstrap-select
 * { min-width:0 }).
 */
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Master\CurrencyRepository;
use Atte\Utils\Purchase\Order\RFQRepository;
use Atte\Utils\Purchase\Order\RFQItemRepository;

if (!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    header("Location: http://" . BASEURL . "/");
    exit();
}

$type = (string)($_GET['type'] ?? '');
if ($type !== 'rfq') {
    http_response_code(404);
    echo '<div class="container-fluid w-75 mt-3"><div class="alert alert-danger">Nieprawidłowy typ dokumentu (wymagane ?type=rfq).</div></div>';
    return;
}
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(404);
    echo '<div class="container-fluid w-75 mt-3"><div class="alert alert-danger">Brak id dokumentu.</div></div>';
    return;
}

$MsaDB    = MsaDB::getInstance();
$rfqRepo  = new RFQRepository($MsaDB);
$itemRepo = new RFQItemRepository($MsaDB);
$curRepo  = new CurrencyRepository($MsaDB);

$rfq = $rfqRepo->getById($id);
if ($rfq === null) {
    http_response_code(404);
    echo '<div class="container-fluid w-75 mt-3"><div class="alert alert-danger">Zapytanie #' . (int)$id . ' nie istnieje.</div></div>';
    return;
}

// Stan inny niż 'sent' → z powrotem na stronę edycji z flagą błędu.
// Czystsze niż render pustej strony z alertem — operator wraca tam,
// gdzie kontekst (pozycje, komentarze, konwersja do PO) jest dostępny.
// Flaga 'error=state' w URL umożliwia stronie edycji (documents-edit.php)
// wyświetlenie dedykowanego ostrzeżenia. Tutaj hook nie istnieje jeszcze,
// więc zwrócony alert jest zwięzły i wprost mówi co zrobić.
if ($rfq->state !== 'sent') {
    header(
        'Location: http://' . BASEURL
        . '/admin/purchase/documents/edit?id=' . (int)$id . '&type=rfq'
        . '&respond_error=' . rawurlencode(
            'Odpowiedź można oznaczyć tylko na wysłanym zapytaniu (bieżący stan: ' . $rfq->state . ').'
        )
    );
    exit();
}

$items = $itemRepo->getByRfq($id);

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

// Units for the per-row qty+unit input-group. Same query as cart-view.php:127-130.
$unitStmt = $MsaDB->db->query("SELECT id, name FROM `part__unit` ORDER BY name ASC");
$units = $unitStmt ? $unitStmt->fetchAll(\PDO::FETCH_ASSOC) : [];

// Raw qty formatter for input values — strips the thousands separator.
// `type="number"` rejects "1 000" (renders blank), so input values
// must be space-free. The display-only formatter (with space separator)
// is fine for read-only cells but NOT for input value attributes.
$rawQty = function ($q) {
    return rtrim(rtrim(number_format((float)$q, 4, '.', ''), '0'), '.');
};

$rfqNumber       = $rfq->rfqNumber ?? ('#' . $rfq->id);
$vendorName      = $rfq->vendorName ?? '—';
$sentAtLabel     = $rfq->sentAt ?? '—';
$respondedAtDefault = date('Y-m-d');
$backUrl         = 'http://' . BASEURL . '/admin/purchase/documents/edit?id=' . (int)$id . '&type=rfq';
?>
<link rel="stylesheet" href="<?= htmlspecialchars(asset('public_html/components/purchases/cart/purchases.css'), ENT_QUOTES) ?>">

<div class="container-fluid w-75 mt-3">
    <div id="alertContainer" class="mb-2"></div>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="mb-0">
            <i class="bi bi-reply"></i>
            Oznacz odpowiedź
            <span class="font-weight-bold"><?= htmlspecialchars($rfqNumber) ?></span>
            <span class="badge badge-info ml-2">Wysłane</span>
        </h2>
        <a href="<?= htmlspecialchars($backUrl, ENT_QUOTES) ?>"
           class="btn btn-outline-secondary btn-sm"
           onclick="if (history.length > 1) { history.back(); return false; }">
            <i class="bi bi-arrow-left"></i> Wróć
        </a>
    </div>

    <div class="card mb-3">
        <div class="card-body py-2">
            <strong>Dostawca:</strong>
            <?= htmlspecialchars($vendorName) ?>
            &nbsp;·&nbsp;
            <strong>Wysłano:</strong>
            <?= htmlspecialchars($sentAtLabel) ?>
        </div>
    </div>

    <?php if (empty($items)): ?>
        <div class="alert alert-warning">
            To zapytanie nie ma żadnych pozycji. Dodaj pozycje przed oznaczeniem odpowiedzi.
        </div>
        <a href="<?= htmlspecialchars($backUrl, ENT_QUOTES) ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> Wróć do dokumentu
        </a>
    <?php else: ?>
    <form id="rfq-respond-form" data-rfq-id="<?= (int)$rfq->id ?>">
        <input type="hidden" name="id" value="<?= (int)$rfq->id ?>">

        <!-- ===== Card 1: Pozycje odpowiedzi (mirrors #receiptLinesCard) ===== -->
        <div class="card">
            <div class="card-header d-flex flex-wrap align-items-center py-2">
                <strong class="mr-auto">
                    <i class="bi bi-reply"></i> Pozycje odpowiedzi
                </strong>
                <button type="button" class="btn btn-sm btn-outline-primary mr-2" id="respondFillAllBtn">
                    <i class="bi bi-arrow-clockwise"></i> Przepisz ilości z zapytania
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="respondClearAllBtn">
                    <i class="bi bi-eraser"></i> Wyczyść ceny
                </button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0 rfq-respond-table" id="rfqRespondTable">
                        <thead class="thead-light">
                        <tr class="rfq-respond-group-row">
                            <th scope="colgroup" colspan="2" class="rfq-respond-group">Pozycja</th>
                            <th scope="colgroup" colspan="1" class="rfq-respond-group text-right">Zapytano</th>
                            <th scope="colgroup" colspan="2" class="rfq-respond-group rfq-respond-group-input">
                                <i class="bi bi-pencil-square"></i> Co oferuje dostawca
                            </th>
                            <th scope="colgroup" colspan="1" class="rfq-respond-group">Uwagi do odpowiedzi</th>
                        </tr>
                        <tr>
                            <th scope="col" class="rfq-respond-col-num">Nr dostawcy</th>
                            <th scope="col">Nazwa</th>
                            <th scope="col" class="text-right">Ilość</th>
                            <th scope="col" class="rfq-respond-col-qty">Ilość</th>
                            <th scope="col" class="rfq-respond-col-price">Cena jedn.</th>
                            <th scope="col" class="rfq-respond-col-note">Uwagi do odpowiedzi</th>
                        </tr>
                        </thead>
                        <tbody id="rfqRespondBody">
                        <?php foreach ($items as $it):
                            $iid      = (int)$it->id;
                            $unitName = $it->unitName ?? '—';
                            $pack     = isset($it->pickedPackSize) ? (float)$it->pickedPackSize : null;
                            $vn       = (string)($it->vendorPartNo ?? '');
                            $pn       = (string)($it->producerPartNo ?? '');
                            $askedQty = (float)$it->quantity;
                            $askedQtyStr = $rawQty($askedQty);
                            $isUnpriced = ($it->unitPrice === null);
                            $rowClass = $isUnpriced ? 'rfq-respond-row is-unpriced' : 'rfq-respond-row';
                        ?>
                            <tr class="<?= $rowClass ?>"
                                data-item-id="<?= $iid ?>"
                                data-pack="<?= ($pack !== null && $pack > 0) ? htmlspecialchars((string)$pack, ENT_QUOTES) : '' ?>"
                                data-asked-qty="<?= htmlspecialchars($askedQtyStr, ENT_QUOTES) ?>"
                                data-asked-unit="<?= htmlspecialchars($unitName, ENT_QUOTES) ?>">

                                <!-- ===== Pozycja (colspan=2): col1 = Nr dostawcy, col2 = Nazwa ===== -->
                                <!-- Mirrors receive-page.js nameCell/numCell pattern. Col 1 is the
                                     catalog code (vendor + producer number), col 2 is the human-
                                     readable part identity (name + description + unit). -->
                                <td>
                                    <?php if ($vn !== ''): ?>
                                        <?= htmlspecialchars($vn) ?>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                    <?php if ($pn !== '' && $pn !== $vn): ?>
                                        <div><small class="text-muted">Nr prod.: <?= htmlspecialchars($pn) ?></small></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="font-weight-bold"><?= htmlspecialchars($it->partName ?? '—') ?></div>
                                    <?php if (!empty($it->partDescription)): ?>
                                        <div><small class="text-muted"><?= htmlspecialchars($it->partDescription) ?></small></div>
                                    <?php endif; ?>
                                    <small class="text-muted d-block"><?= htmlspecialchars($it->unitName ?? '') ?></small>
                                </td>

                                <!-- ===== Zapytano (colspan=1, read-only reference) ===== -->
                                <td class="text-right rfq-respond-asked-cell">
                                    <span class="text-monospace"><?= htmlspecialchars($askedQtyStr) ?></span>
                                    <small class="text-muted d-block"><?= htmlspecialchars($unitName) ?></small>
                                </td>

                                <!-- ===== Co oferuje dostawca (colspan=2, EDITABLE — blue) ===== -->
                                <!-- Layout mirrors documents-edit inline-edit qty cell:
                                     qty input on its own line, then a flex row with unit
                                     label + packs input + muted "opak." + pack-size badge. -->
                                <td>
                                    <input type="number" inputmode="decimal" step="0.0001" min="0.0001" required
                                           class="form-control form-control-sm text-right js-qty"
                                           name="items[<?= $iid ?>][quantity]"
                                           value="<?= htmlspecialchars($askedQtyStr, ENT_QUOTES) ?>"
                                           aria-label="Ilość">
                                    <?php if ($pack !== null && $pack > 0): ?>
                                    <div class="d-flex align-items-center rfq-respond-qty-sub mt-1" style="gap:.25rem">
                                        <small class="text-muted"><?= htmlspecialchars($unitName) ?></small>
                                        <input type="number" inputmode="decimal" step="0.0001" min="0"
                                               class="form-control form-control-sm text-right text-muted js-packs"
                                               style="width: 4.5em;"
                                               aria-label="Opakowania"
                                               title="Opakowania — dwukierunkowo powiązane z ilością">
                                        <small class="text-muted">opak.</small>
                                        <span class="badge badge-light border text-monospace" title="Wielkość opakowania (stała)">× <?= htmlspecialchars(rtrim(rtrim(number_format($pack, 4, '.', ''), '0'), '.')) ?></span>
                                    </div>
                                    <?php endif; ?>
                                    <div class="invalid-feedback js-qty-error"></div>
                                    <div class="line-msg js-qty-msg"></div>
                                </td>
                                <td>
                                    <div class="input-group input-group-sm">
                                        <input type="number" inputmode="decimal" step="0.0001" min="0"
                                               class="form-control text-right js-price"
                                               name="items[<?= $iid ?>][unit_price]"
                                               value="<?= ($it->unitPrice === null) ? '' : htmlspecialchars((string)$it->unitPrice, ENT_QUOTES) ?>"
                                               placeholder="nie wyceniono" aria-label="Cena jednostkowa">
                                        <div class="input-group-append">
                                            <select class="custom-select custom-select-sm js-currency"
                                                    name="items[<?= $iid ?>][currency]"
                                                    style="max-width: 5rem;" title="Waluta">
                                                <?php foreach ($currencies as $code => $label): ?>
                                                    <option value="<?= htmlspecialchars($code, ENT_QUOTES) ?>"
                                                        <?= ($code === ($it->currency ?? 'PLN')) ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($code) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="js-line-total text-muted small mt-1 text-right"></div>
                                    <div class="line-msg js-price-msg"></div>
                                </td>

                                <!-- ===== Uwagi (colspan=1) ===== -->
                                <td>
                                    <input type="text" class="form-control form-control-sm"
                                           name="items[<?= $iid ?>][comment]"
                                           value="<?= htmlspecialchars($it->comment ?? '', ENT_QUOTES) ?>"
                                           placeholder="opcjonalne" aria-label="Uwagi do pozycji">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <!-- ===== Card-footer: data odpowiedzi + per-currency totals ===== -->
            <div class="card-footer">
                <div class="d-flex flex-wrap justify-content-between align-items-end" style="gap: 1rem;">
                    <div>
                        <label for="responded_at" class="small text-muted mb-1 d-block">Data odpowiedzi</label>
                        <input type="date" id="responded_at" name="responded_at"
                               class="form-control form-control-sm" style="max-width: 11rem;"
                               value="<?= htmlspecialchars($respondedAtDefault) ?>">
                        <small class="text-muted d-block mt-1">Domyślnie dzisiejsza. Możesz cofnąć.</small>
                    </div>
                    <div class="text-right">
                        <div class="small text-uppercase text-muted">Wartość odpowiedzi</div>
                        <div id="respond-totals" class="text-monospace font-weight-bold"><span class="text-muted">—</span></div>
                        <small id="respond-unpriced" class="text-muted d-block"></small>
                    </div>
                </div>
            </div>
        </div>
    </form>

    <!-- ===== Sticky actionbar (mirrors .receipt-actionbar) ===== -->
    <div class="rfq-respond-actionbar bg-white border border-top-0 shadow-sm py-2 px-3 d-flex flex-wrap align-items-center">
        <div class="mr-auto py-1 d-flex align-items-center">
            <i class="bi bi-reply rfq-respond-actionbar-icon text-primary mr-2"></i>
            <div>
                <span class="font-weight-bold d-block" id="respondSummary">Zapisujesz odpowiedź dla 0 z 0 pozycji</span>
                <small class="text-muted d-block rfq-respond-actionbar-hint" id="respondHint"></small>
            </div>
        </div>
        <a href="<?= htmlspecialchars($backUrl, ENT_QUOTES) ?>" class="btn btn-outline-secondary mr-2" id="respondCancelBtn">Anuluj</a>
    <button type="submit" form="rfq-respond-form" class="btn btn-success px-4" id="respondSubmitBtn">
        <i class="bi bi-check2-circle"></i> Zapisz odpowiedź
    </button>
    </div>
    <?php endif; ?>
</div>

<script src="<?= htmlspecialchars(asset('public_html/components/Admin/Purchase/Documents/documents-respond.js'), ENT_QUOTES) ?>"></script>