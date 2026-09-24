<?php
/**
 * Przyjęcia towaru — /purchase/receipts.
 *
 * Lista (kolejka + historia). Formularz przyjęcia wyodrębniony do
 * osobnej trasy /purchase/receipts/receive — patrz receive-page.php +
 * receive-page.js. Klik w wiersz kolejki (albo w przycisk „Przyjmij
 * towar") nawiguje na formularz przez
 *   window.location.origin + ROOT_DIR + '/purchase/receipts/receive?po_id=N'
 * (ROOT_DIR to prefix ścieżki z header.js). Po zapisie formularz
 * przekierowuje tu z powrotem, niosąc w query stringu
 *   ?saved=<doc>&n=<items>&po=<po>&po_state=<state>
 * a receipts-view.js czyta to przy starcie: wyświetla alert sukcesu
 * i podświetla świeży wiersz historii (klasa .receipt-row-new,
 * animacja w receipts.css). Nowy wiersz renderuje PHP w normalnym
 * cyklu reloadu — JS tylko dokłada klasę migającą.
 *
 * Słownictwo układu skopiowane z /purchase/documents: zwijana karta
 * filtrów (`alert-primary` w nagłówku, czerwone „Wyczyść" przy każdym
 * filtrze), „Odśwież" w stopce karty, paginacja nad i pod tabelą.
 */

use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Order\OrderReceiptRepository;

if(!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    header("Location: http://".BASEURL."/");
    exit();
}

$MsaDB = MsaDB::getInstance();
$receiptRepo = new OrderReceiptRepository($MsaDB);
$receipts = $receiptRepo->getAll(true);

// Pre-compute counts/totals per receipt in one SQL pass.
// We can't put this in the repo's buildSelectJoin() because the GROUP BY
// shape conflicts with the per-receipt SELECT; do it as a separate read.
$countsByReceipt = [];
if (!empty($receipts)) {
    $stmt = $MsaDB->db->prepare(
        "SELECT receipt_id,
                COUNT(*)              AS item_count,
                COALESCE(SUM(quantity_received), 0) AS total_qty
         FROM `purchase__order_receipt_item`
         WHERE receipt_id IN (" . implode(',', array_map('intval', array_map(fn($r) => $r->id, $receipts))) . ")
         GROUP BY receipt_id"
    );
    $stmt->execute();
    foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
        $countsByReceipt[(int)$row['receipt_id']] = [
            'itemCount' => (int)$row['item_count'],
            'totalQty'  => (float)$row['total_qty'],
        ];
    }
}

// Name of the logged-in user. Już nieużywany do wypełniania świeżego
// wiersza w appendHistoryRow() — formularz po zapisie robi pełny
// reload na /purchase/receipts, więc dane wiersza pochodzą z PHP,
// nie z JS. Wystawiamy atrybut na #receiptsListView na wypadek
// przyszłego użycia (np. tooltip „Przyjmuje: <imię>").
$currentUserStmt = $MsaDB->db->prepare(
    "SELECT CONCAT(`name`, ' ', `surname`) FROM `user` WHERE `user_id` = ?"
);
$currentUserStmt->execute([(int)($_SESSION['user_id'] ?? 0)]);
$currentUserName = (string)($currentUserStmt->fetchColumn() ?: ('#' . (int)($_SESSION['user_id'] ?? 0)));

include('modals.php');
include('table-row-template.php');
// Współdzielony modal "Potwierdź odbiór PO" — triggerowany z kolejki
// "Do przyjęcia" (button z data-action="confirm-po" dla wierszy w stanie
// `sent`). Używamy __DIR__ zamiast "../" — PHP rozwiązuje ścieżki
// względne z "../" od cwd skryptu wywołującego (index.php), nie od tego
// pliku, więc ../documents/… wskazywałoby na <project>/documents/….
include __DIR__ . '/../documents/confirm-po-modal.php';
?>

<link rel="stylesheet" href="<?= asset('public_html/components/purchases/cart/purchases.css') ?>">
<link rel="stylesheet" href="<?= asset('public_html/components/purchases/receipts/receipts.css') ?>">
<link rel="stylesheet" href="<?= asset('public_html/components/purchases/documents/confirm-po-modal.css') ?>">


<!-- ==================================================================
     LISTA (kolejka + historia)
     ================================================================== -->
<div class="container-fluid w-75 mt-3" id="receiptsListView"
     data-current-user="<?= htmlspecialchars($currentUserName, ENT_QUOTES) ?>">

    <div class="row">
        <div class="col-12 my-2">
            <h2><i class="bi bi-box-arrow-in-down"></i> Przyjęcia</h2>
            <p class="text-muted mb-2">
                Zamówienia oczekujące na towar oraz historia zarejestrowanych
                przyjęć. Numer PZ nadawany jest automatycznie przy zapisie.
            </p>
            <div class="alert-host" id="alertContainer"></div>
        </div>
    </div>

    <ul class="nav nav-tabs" id="receiptsTabs" role="tablist">
        <li class="nav-item">
            <a class="nav-link active" id="queueTabLink" data-toggle="tab"
               href="#queuePane" role="tab" aria-controls="queuePane" aria-selected="true">
                <i class="bi bi-inbox"></i> Do przyjęcia
                <span class="badge badge-warning ml-1" id="queueCountBadge">…</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" id="historyTabLink" data-toggle="tab"
               href="#historyPane" role="tab" aria-controls="historyPane" aria-selected="false">
                <i class="bi bi-clock-history"></i> Historia przyjęć
                <span class="badge badge-secondary ml-1" id="historyCountBadge"><?= count($receipts) ?></span>
            </a>
        </li>
    </ul>

    <div class="tab-content mb-4">

        <!-- ============ ZAKŁADKA 1: kolejka ============ -->
        <div class="tab-pane fade show active" id="queuePane" role="tabpanel" aria-labelledby="queueTabLink">

            <!-- Karta filtrów — ten sam układ co /purchase/documents -->
            <div class="card mb-3">
                <div class="card-header alert-primary" style="cursor: pointer;"
                     data-toggle="collapse" data-target="#queueFilterCollapse">
                    <div class="d-flex justify-content-between align-items-center">
                        <h6 class="mb-0"><i class="bi bi-funnel"></i> Filtry</h6>
                        <i class="bi bi-chevron-down"></i>
                    </div>
                </div>
                <div id="queueFilterCollapse" class="collapse show alert-info">
                    <div class="card-body p-3">

                        <div class="form-row mb-2">
                            <div class="col-md-10 col-12 mb-2" id="queueVendorCell">
                                <label class="small mb-1" for="queueVendor"><strong>Dostawca:</strong></label>
                                <!-- Opcje wypełnia JS z payloadu kolejki: lista
                                     zawiera tylko dostawców, którzy faktycznie
                                     mają coś w kolejce (brak pustych wyborów). -->
                                <select id="queueVendor"
                                        class="selectpicker form-control form-control-sm"
                                        data-live-search="true"
                                        data-actions-box="true"
                                        data-selected-text-format="count > 2"
                                        data-title="Wszyscy dostawcy"
                                        multiple
                                        data-width="100%"
                                        data-container="#queueVendorCell"></select>
                            </div>
                            <div class="col-md-2 col-12 d-flex align-items-end">
                                <button type="button" id="clearQueueVendor"
                                        class="btn btn-danger btn-sm btn-block mb-2">Wyczyść</button>
                            </div>
                        </div>

                        <hr class="my-2">

                        <div class="form-row mb-2">
                            <div class="col-md-10 col-12 mb-2" id="queueStatesCell">
                                <label class="small mb-1" for="queueStates"><strong>Status:</strong></label>
                                <select id="queueStates"
                                        class="selectpicker form-control form-control-sm"
                                        data-actions-box="true"
                                        data-selected-text-format="count > 2"
                                        data-title="Wszystkie statusy"
                                        multiple
                                        data-width="100%"
                                        data-container="#queueStatesCell">
                                    <option value="sent">Wysłane (czeka na potwierdzenie)</option>
                                    <option value="confirmed">Potwierdzone</option>
                                    <!-- „W trakcie przyjęcia" = PO, dla którego operator kliknął
                                         Akceptuję (zapis metadanych) ale jeszcze nie wpisał
                                         pozycji. Bez tej opcji w filtrze wiersz znika z
                                         kolejki po akceptacji, jeśli operator ma aktywny
                                         filtr statusów (applyQueueFilters, receipts-view.js). -->
                                    <option value="in_progress">W trakcie przyjęcia</option>
                                    <option value="partially_received">Częściowo odebrane</option>
                                </select>
                            </div>
                            <div class="col-md-2 col-12 d-flex align-items-end">
                                <button type="button" id="clearQueueStates"
                                        class="btn btn-danger btn-sm btn-block mb-2">Wyczyść</button>
                            </div>
                        </div>

                        <hr class="my-2">

                        <div class="form-row mb-2">
                            <div class="col-md-10 col-12 mb-2">
                                <label class="small mb-1" for="queueSearch"><strong>Szukaj:</strong></label>
                                <input type="text" id="queueSearch" class="form-control form-control-sm"
                                       placeholder="numer zamówienia, numer u dostawcy, dostawca…">
                            </div>
                            <div class="col-md-2 col-12 d-flex align-items-end">
                                <button type="button" id="clearQueueSearch"
                                        class="btn btn-danger btn-sm btn-block mb-2">Wyczyść</button>
                            </div>
                        </div>

                        <hr class="my-2">

                        <div class="form-row mb-2">
                            <div class="col-md-10 col-12 mb-2">
                                <div class="custom-control custom-checkbox mt-1">
                                    <input type="checkbox" class="custom-control-input" id="queueOverdueOnly">
                                    <label class="custom-control-label small" for="queueOverdueOnly">
                                        <strong>Tylko po terminie</strong>
                                        <span class="text-muted">— dostawa spóźniona względem oczekiwanej daty</span>
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-2 col-12 d-flex align-items-end">
                                <button type="button" id="clearQueueOverdue"
                                        class="btn btn-danger btn-sm btn-block mb-2">Wyczyść</button>
                            </div>
                        </div>

                    </div>
                </div>
            </div>

            <!-- Stopka sterowania — układ jak w /purchase/documents -->
            <div class="card mb-3">
                <div class="card-footer py-1 small text-muted d-flex align-items-center justify-content-between">
                    <div id="queueFilterSummary">
                        <i class="bi bi-info-circle"></i>
                        Kliknij wiersz potwierdzonego zamówienia, aby przyjąć towar.
                    </div>
                    <button type="button" id="queueRefreshBtn" class="btn btn-primary btn-sm">
                        <i class="bi bi-arrow-clockwise"></i> Odśwież
                    </button>
                </div>
            </div>

            <div id="queueStatus" class="text-muted py-3">
                <span class="spinner-border spinner-border-sm"></span> Ładowanie…
            </div>

            <div class="table-responsive d-none" id="queueTableWrap">
                <table class="table table-striped table-hover mb-0" id="queueTable">
                    <thead class="thead-light">
                    <tr>
                        <th scope="col">Zamówienie</th>
                        <th scope="col">Dostawca</th>
                        <th scope="col">Status</th>
                        <th scope="col">Dostawa</th>
                        <th scope="col">Postęp</th>
                        <th scope="col" class="text-right" style="width: 180px;">Akcja</th>
                    </tr>
                    </thead>
                    <tbody id="queueBody"></tbody>
                </table>
            </div>
        </div>

        <!-- ============ ZAKŁADKA 2: historia ============ -->
        <div class="tab-pane fade" id="historyPane" role="tabpanel" aria-labelledby="historyTabLink">

            <div class="card mb-3">
                <div class="card-header alert-primary" style="cursor: pointer;"
                     data-toggle="collapse" data-target="#historyFilterCollapse">
                    <div class="d-flex justify-content-between align-items-center">
                        <h6 class="mb-0"><i class="bi bi-funnel"></i> Filtry</h6>
                        <i class="bi bi-chevron-down"></i>
                    </div>
                </div>
                <div id="historyFilterCollapse" class="collapse show alert-info">
                    <div class="card-body p-3">

                        <div class="form-row mb-2">
                            <div class="col-md-10 col-12 mb-2">
                                <label class="small mb-1" for="historySearch"><strong>Szukaj:</strong></label>
                                <input type="text" id="historySearch" class="form-control form-control-sm"
                                       placeholder="numer dokumentu, zamówienie, dostawca, przyjmujący…">
                            </div>
                            <div class="col-md-2 col-12 d-flex align-items-end">
                                <button type="button" id="clearHistorySearch"
                                        class="btn btn-danger btn-sm btn-block mb-2">Wyczyść</button>
                            </div>
                        </div>

                        <hr class="my-2">

                        <div class="form-row mb-2">
                            <div class="col-md-5 col-12 mb-2">
                                <label class="small mb-1" for="historyDateFrom"><strong>Data od:</strong></label>
                                <input type="date" id="historyDateFrom" class="form-control form-control-sm">
                            </div>
                            <div class="col-md-5 col-12 mb-2">
                                <label class="small mb-1" for="historyDateTo"><strong>Data do:</strong></label>
                                <input type="date" id="historyDateTo" class="form-control form-control-sm">
                            </div>
                            <div class="col-md-2 col-12 d-flex align-items-end">
                                <button type="button" id="clearHistoryDates"
                                        class="btn btn-danger btn-sm btn-block mb-2">Wyczyść daty</button>
                            </div>
                        </div>

                    </div>
                </div>
            </div>

            <div class="alert alert-info <?= empty($receipts) ? '' : 'd-none' ?>" id="receiptsEmptyAlert">
                <i class="bi bi-info-circle"></i> Brak przyjęć w systemie.
            </div>

            <!-- Paginacja góra -->
            <div id="historyPaginationTop" class="mb-3"></div>

            <div class="table-responsive <?= empty($receipts) ? 'd-none' : '' ?>" id="receiptsTableWrap">
                <table class="table table-striped table-hover mb-0" id="receiptsTable">
                    <thead class="thead-light">
                    <tr>
                        <th scope="col">Numer dokumentu</th>
                        <th scope="col">Zamówienie</th>
                        <th scope="col">Dostawca</th>
                        <th scope="col" class="text-right">Pozycje</th>
                        <th scope="col" class="text-right">Ilość łącznie</th>
                        <th scope="col">Przyjął</th>
                        <th scope="col">Data</th>
                        <th scope="col" class="text-right" style="width: 120px;">Akcje</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($receipts as $r):
                        $c = $countsByReceipt[$r->id] ?? ['itemCount' => 0, 'totalQty' => 0.0];
                        // data-received-at zasila filtr zakresu dat po stronie
                        // klienta — sam tekst komórki zawiera też godzinę,
                        // więc porównanie stringów wymagałoby cięcia przy
                        // każdym keyup.
                        $receivedDate = substr((string)($r->receivedAt ?? ''), 0, 10);
                    ?>
                        <tr class="receipt-row" data-id="<?= $r->id ?>"
                            data-received-at="<?= htmlspecialchars($receivedDate, ENT_QUOTES) ?>">
                            <td>
                                <div class="font-weight-bold"><?= htmlspecialchars($r->documentNumber ?? '—') ?></div>
                                <small class="text-muted">ID <?= $r->id ?></small>
                            </td>
                            <td>
                                <?php if ($r->poNumber): ?>
                                    <a href="http://<?= BASEURL ?>/admin/purchase/documents/edit?id=<?= $r->poId ?>&amp;type=po"
                                       onclick="event.stopPropagation();">
                                        <?= htmlspecialchars($r->poNumber) ?>
                                    </a>
                                <?php else: ?>
                                    #<?= $r->poId ?>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($r->vendorName ?? '—') ?></td>
                            <td class="text-right">
                                <span class="badge badge-secondary"><?= $c['itemCount'] ?></span>
                            </td>
                            <td class="text-right"><?= number_format($c['totalQty'], 4, '.', ' ') ?></td>
                            <td><?= htmlspecialchars($r->receivedByName ?? ('#' . $r->receivedBy)) ?></td>
                            <td><small class="text-muted"><?= htmlspecialchars($r->receivedAt ?? '') ?></small></td>
                            <td class="text-right">
                                <button class="btn btn-sm btn-outline-info view-receipt-btn"
                                        data-id="<?= $r->id ?>"
                                        onclick="event.stopPropagation();">
                                    <i class="bi bi-eye"></i> Zobacz
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Paginacja dół -->
            <div id="historyPaginationBottom" class="mt-3"></div>
        </div>

    </div>
</div>


<script src="<?= asset('public_html/components/purchases/receipts/receipts-view.js') ?>"></script>
<script src="<?= asset('public_html/components/purchases/documents/confirm-po-modal.js') ?>"></script>
