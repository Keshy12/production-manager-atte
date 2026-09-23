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

        <div class="card mb-3">
            <div class="card-header"><strong>Pozycje odpowiedzi</strong></div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead class="thead-light">
                        <tr>
                            <th>Część</th>
                            <th>Nr u dostawcy</th>
                            <th class="text-right">Ilość</th>
                            <th>Jednostka</th>
                            <th class="text-right" style="width: 12rem;">Cena jedn. (z odpowiedzi)</th>
                            <th style="width: 7rem;">Waluta</th>
                            <th>Uwagi</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($items as $it): ?>
                        <tr>
                            <td>
                                <?= htmlspecialchars($it->partName ?? '—') ?>
                                <?php if (!empty($it->vendorPartNo)): ?>
                                    <div><small class="text-muted"><?= htmlspecialchars($it->vendorPartNo) ?></small></div>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($it->vendorPartNo ?? '—') ?></td>
                            <td class="text-right">
                                <?= htmlspecialchars(rtrim(rtrim(number_format((float)$it->quantity, 4, '.', ' '), '0'), '.')) ?>
                            </td>
                            <td><?= htmlspecialchars($it->unitName ?? '—') ?></td>
                            <td>
                                <input type="number"
                                       step="0.0001"
                                       min="0"
                                       class="form-control form-control-sm text-right"
                                       name="items[<?= (int)$it->id ?>][unit_price]"
                                       value="<?= $it->unitPrice === null ? '' : htmlspecialchars((string)$it->unitPrice) ?>"
                                       placeholder="—">
                                <input type="hidden"
                                       name="items[<?= (int)$it->id ?>][item_id]"
                                       value="<?= (int)$it->id ?>">
                            </td>
                            <td>
                                <select class="selectpicker form-control form-control-sm"
                                        name="items[<?= (int)$it->id ?>][currency]"
                                        data-width="100%"
                                        data-container="#rfq-respond-form">
                                    <?php foreach ($currencies as $code => $label): ?>
                                        <option value="<?= htmlspecialchars($code, ENT_QUOTES) ?>"
                                            <?= $code === ($it->currency ?? 'PLN') ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($code) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <input type="text"
                                       class="form-control form-control-sm"
                                       name="items[<?= (int)$it->id ?>][comment]"
                                       value="<?= htmlspecialchars($it->comment ?? '', ENT_QUOTES) ?>"
                                       placeholder="opcjonalne">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body py-2">
                <label for="responded_at" class="mb-1"><strong>Data odpowiedzi</strong></label>
                <input type="date"
                       id="responded_at"
                       name="responded_at"
                       class="form-control form-control-sm"
                       style="max-width: 200px;"
                       value="<?= htmlspecialchars($respondedAtDefault) ?>">
                <small class="text-muted d-block mt-1">
                    Domyślnie dzisiejsza data. Możesz cofnąć, jeśli odpowiedź przyszła wcześniej.
                </small>
            </div>
        </div>

        <div class="d-flex" style="gap: .5rem;">
            <button type="submit" id="respond-submit-btn" class="btn btn-success btn-sm">
                <i class="bi bi-check2"></i> Zapisz odpowiedź
            </button>
            <a href="<?= htmlspecialchars($backUrl, ENT_QUOTES) ?>" class="btn btn-outline-secondary btn-sm">Anuluj</a>
        </div>
    </form>
    <?php endif; ?>
</div>

<script src="<?= htmlspecialchars(asset('public_html/components/Admin/Purchase/Documents/documents-respond.js'), ENT_QUOTES) ?>"></script>