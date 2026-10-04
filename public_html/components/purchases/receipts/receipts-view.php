<?php
/**
 * Przyjęcia towaru — /purchase/receipts.
 *
 * Lista w dwóch zakładkach:
 *   „Do przyjęcia"     — kolejka zamówień czekających na towar
 *                        (dane z receipts-queue.php, render w JS),
 *   „Historia przyjęć" — zapisane dokumenty PZ (render w PHP poniżej,
 *                        filtrowanie i paginacja po stronie klienta).
 *
 * Nawigacja:
 *   - przyjęcie towaru: /purchase/receipts/receive?po_id=N
 *     (receive-page.php + receive-page.js),
 *   - szczegóły przyjęcia: /purchase/receipts/view?id=N
 *     (receipt-view.php — osobna strona, zastąpiła dawny modal),
 *   - zakładka historii ma adres /purchase/receipts#historia, więc
 *     „Wróć" ze strony szczegółów ląduje od razu w historii.
 *
 * Po zapisie formularz przyjęcia wraca tu z
 *   ?saved=<doc>&n=<items>&po=<po>&po_state=<state>
 * a receipts-view.js pokazuje komunikat sukcesu i podświetla nowy wiersz
 * historii (klasa .receipt-row-new — wiersz szukany po data-doc).
 *
 * Wygląd: receipts-ui.css (wspólny z receipt-view.php). receipts.css
 * zostaje dla formularza przyjęcia.
 */

use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Order\OrderReceiptRepository;

if(!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    header("Location: http://".BASEURL."/");
    exit();
}

require_once __DIR__ . '/receipts-helpers.php';

$MsaDB = MsaDB::getInstance();
$receiptRepo = new OrderReceiptRepository($MsaDB);
$receipts = $receiptRepo->getAll(true);

// Liczba pozycji i suma ilości per przyjęcie — jedno zapytanie
// grupujące. Nie da się tego wpiąć w buildSelectJoin() repozytorium
// (GROUP BY gryzie się z SELECT-em per przyjęcie), więc osobny odczyt.
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

// Współdzielony modal „Potwierdź odbiór" — otwierany z kolejki przyciskiem
// data-action="confirm-po" (zamówienia w stanie `sent`). __DIR__ zamiast
// "../" — ścieżki względne PHP liczy od index.php, nie od tego pliku.
include __DIR__ . '/../documents/confirm-po-modal.php';

$historyCount = count($receipts);
?>

<link rel="stylesheet" href="<?= asset('public_html/components/purchases/cart/purchases.css') ?>">
<link rel="stylesheet" href="<?= asset('public_html/components/purchases/receipts/receipts-ui.css') ?>">
<link rel="stylesheet" href="<?= asset('public_html/components/purchases/documents/confirm-po-modal.css') ?>">

<div class="rx-page" id="receiptsListView">

    <!-- ===== Nagłówek strony + liczniki kolejki ===== -->
    <header class="rx-head">
        <div class="rx-head-main">
            <div class="rx-eyebrow"><i class="bi bi-box-arrow-in-down"></i> Zamówienia komponentów</div>
            <h1 class="rx-title">Przyjęcia towaru</h1>
            <p class="rx-lead">
                Zamówienia czekające na dostawę i historia przyjętych dokumentów.
                Numer PZ nadawany jest automatycznie przy zapisie.
            </p>
        </div>

        <!-- Liczniki są jednocześnie skrótami do filtrów kolejki.
             Wartości wypełnia JS po wczytaniu receipts-queue.php. -->
        <div class="rx-stats" role="group" aria-label="Podsumowanie kolejki">
            <button type="button" class="rx-stat rx-stat--ready" data-stat="ready"
                    title="Pokaż zamówienia gotowe do przyjęcia">
                <span class="rx-stat-value" id="statReady">–</span>
                <span class="rx-stat-label">Gotowe do przyjęcia</span>
            </button>
            <button type="button" class="rx-stat rx-stat--overdue" data-stat="overdue"
                    title="Pokaż tylko dostawy po terminie">
                <span class="rx-stat-value" id="statOverdue">–</span>
                <span class="rx-stat-label">Po terminie</span>
            </button>
            <button type="button" class="rx-stat rx-stat--pending" data-stat="pending"
                    title="Pokaż zamówienia czekające na potwierdzenie dostawcy">
                <span class="rx-stat-value" id="statPending">–</span>
                <span class="rx-stat-label">Czeka na potwierdzenie</span>
            </button>
        </div>
    </header>

    <div id="alertContainer" class="rx-alerts"></div>

    <!-- ===== Zakładki ===== -->
    <ul class="nav rx-tabs" id="receiptsTabs" role="tablist">
        <li class="nav-item">
            <a class="nav-link active" id="queueTabLink" data-toggle="tab"
               href="#queuePane" role="tab" aria-controls="queuePane" aria-selected="true">
                <i class="bi bi-inbox"></i> Do przyjęcia
                <span class="rx-count" id="queueCountBadge">…</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" id="historyTabLink" data-toggle="tab"
               href="#historyPane" role="tab" aria-controls="historyPane" aria-selected="false">
                <i class="bi bi-clock-history"></i> Historia przyjęć
                <span class="rx-count" id="historyCountBadge"><?= $historyCount ?></span>
            </a>
        </li>
    </ul>

    <div class="tab-content">

        <!-- ================= ZAKŁADKA 1: kolejka ================= -->
        <div class="tab-pane fade show active" id="queuePane" role="tabpanel" aria-labelledby="queueTabLink">

            <div class="rx-toolbar" role="search">
                <div class="rx-search">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input type="search" id="queueSearch" class="form-control form-control-sm"
                           placeholder="Szukaj: nr zamówienia, nr u dostawcy, dostawca"
                           aria-label="Szukaj w kolejce" autocomplete="off">
                </div>

                <!-- AGENTS.md: data-width="100%" + data-container na komórce
                     + min-width: 0 na komórce (.rx-tool w receipts-ui.css). -->
                <div class="rx-tool" id="queueVendorCell">
                    <select id="queueVendor"
                            class="selectpicker"
                            data-live-search="true"
                            data-actions-box="true"
                            data-selected-text-format="count > 1"
                            data-title="Wszyscy dostawcy"
                            data-style="rx-picker-btn"
                            aria-label="Dostawca"
                            multiple
                            data-width="100%"
                            data-container="#queueVendorCell"></select>
                </div>

                <div class="rx-tool" id="queueStatesCell">
                    <select id="queueStates"
                            class="selectpicker"
                            data-actions-box="true"
                            data-selected-text-format="count > 1"
                            data-title="Wszystkie statusy"
                            data-style="rx-picker-btn"
                            aria-label="Status"
                            multiple
                            data-width="100%"
                            data-container="#queueStatesCell">
                        <option value="sent">Wysłane (czeka na potwierdzenie)</option>
                        <option value="confirmed">Potwierdzone</option>
                        <!-- „W trakcie przyjęcia" = operator kliknął Akceptuję
                             w formularzu, ale nie zapisał jeszcze pozycji. -->
                        <option value="in_progress">W trakcie przyjęcia</option>
                        <option value="partially_received">Częściowo odebrane</option>
                    </select>
                </div>

                <input type="checkbox" class="rx-chip-input" id="queueOverdueOnly">
                <label class="rx-chip rx-chip--danger" for="queueOverdueOnly">
                    <i class="bi bi-exclamation-triangle rx-chip-off" aria-hidden="true"></i><i class="bi bi-exclamation-triangle-fill rx-chip-on" aria-hidden="true"></i> Tylko po terminie
                </label>

                <div class="rx-toolbar-end">
                    <button type="button" id="queueRefreshBtn" class="btn btn-sm rx-btn-quiet"
                            title="Wczytaj kolejkę ponownie">
                        <i class="bi bi-arrow-clockwise rx-spin-icon"></i>
                        <span class="d-none d-sm-inline">Odśwież</span>
                    </button>
                </div>
            </div>

            <div class="rx-filterbar">
                <span id="queueFilterSummary">Wczytywanie kolejki…</span>
                <button type="button" class="rx-link-btn d-none js-clear-queue-filters" id="clearQueueFilters">
                    <i class="bi bi-x-circle"></i> Wyczyść filtry
                </button>
            </div>

            <div class="rx-panel">
                <div id="queueStatus" aria-live="polite"></div>

                <div id="queueTableWrap" class="d-none">
                    <div class="rx-qhead" aria-hidden="true">
                        <div>Zamówienie</div>
                        <div>Dostawca</div>
                        <div>Dostawa</div>
                        <div>Postęp</div>
                        <div>Status</div>
                        <div class="text-right">Akcja</div>
                    </div>
                    <div id="queueBody" class="rx-qlist"></div>
                </div>
            </div>
        </div>

        <!-- ================= ZAKŁADKA 2: historia ================= -->
        <div class="tab-pane fade" id="historyPane" role="tabpanel" aria-labelledby="historyTabLink">

            <div class="rx-toolbar" role="search">
                <div class="rx-search">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input type="search" id="historySearch" class="form-control form-control-sm"
                           placeholder="Szukaj: nr PZ, zamówienie, dostawca, przyjmujący"
                           aria-label="Szukaj w historii" autocomplete="off">
                </div>

                <div class="rx-daterange" id="historyDateRange">
                    <i class="bi bi-calendar3" aria-hidden="true"></i>
                    <input type="date" id="historyDateFrom" aria-label="Data od" title="Data od">
                    <span class="rx-daterange-sep">–</span>
                    <input type="date" id="historyDateTo" aria-label="Data do" title="Data do">
                </div>

                <div class="rx-seg" role="group" aria-label="Szybki zakres dat">
                    <button type="button" data-preset="0">Dziś</button>
                    <button type="button" data-preset="7">7 dni</button>
                    <button type="button" data-preset="30">30 dni</button>
                    <button type="button" data-preset="90">90 dni</button>
                </div>
            </div>

            <div class="rx-filterbar">
                <span id="historyFilterSummary"></span>
                <button type="button" class="rx-link-btn d-none js-clear-history-filters" id="clearHistoryFilters">
                    <i class="bi bi-x-circle"></i> Wyczyść filtry
                </button>
            </div>

            <div class="rx-panel">
                <div id="receiptsEmptyAlert" class="<?= empty($receipts) ? '' : 'd-none' ?>">
                    <div class="rx-empty">
                        <div class="rx-empty-icon"><i class="bi bi-archive"></i></div>
                        <div class="rx-empty-title">Brak zarejestrowanych przyjęć</div>
                        <div class="rx-empty-text">
                            Przyjęcia pojawią się tutaj po zapisaniu pierwszej dostawy
                            z zakładki „Do przyjęcia".
                        </div>
                    </div>
                </div>

                <div class="table-responsive <?= empty($receipts) ? 'd-none' : '' ?>" id="receiptsTableWrap">
                    <table class="table rx-table mb-0" id="receiptsTable">
                        <thead>
                        <tr>
                            <th scope="col">Dokument</th>
                            <th scope="col">Data przyjęcia</th>
                            <th scope="col">Zamówienie</th>
                            <th scope="col">Dostawca</th>
                            <th scope="col" class="text-right">Pozycje</th>
                            <th scope="col" class="text-right d-none d-md-table-cell">Ilość</th>
                            <th scope="col" class="d-none d-lg-table-cell">Przyjął</th>
                            <th scope="col" class="text-right"><span class="sr-only">Akcje</span></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($receipts as $r):
                            $c = $countsByReceipt[$r->id] ?? ['itemCount' => 0, 'totalQty' => 0.0];
                            // data-received-at (Y-m-d) zasila filtr zakresu dat;
                            // data-received-full dokłada pełny ISO do wyszukiwarki,
                            // bo w komórce data jest pokazana jako d.m.Y.
                            $receivedFull = (string)($r->receivedAt ?? '');
                            $receivedDate = substr($receivedFull, 0, 10);
                            $relDay       = rx_relative_day($r->receivedAt);
                            $time         = rx_fmt_time($r->receivedAt);
                            $receiver     = $r->receivedByName ?: ('#' . $r->receivedBy);
                            $viewUrl      = 'http://' . BASEURL . '/purchase/receipts/view?id=' . (int)$r->id;
                        ?>
                            <tr class="receipt-row" data-id="<?= (int)$r->id ?>"
                                data-doc="<?= rx_e($r->documentNumber ?? '') ?>"
                                data-received-at="<?= rx_e($receivedDate) ?>"
                                data-received-full="<?= rx_e($receivedFull) ?>">
                                <td>
                                    <?php if ($r->documentNumber): ?>
                                        <div class="rx-doc"><?= rx_e($r->documentNumber) ?></div>
                                    <?php else: ?>
                                        <div class="rx-doc rx-doc--none" title="Zapisany sam nagłówek — numer PZ nadawany jest przy zapisie ilości">bez numeru PZ</div>
                                    <?php endif; ?>
                                    <div class="rx-sub">ID <?= (int)$r->id ?></div>
                                </td>
                                <td class="text-nowrap">
                                    <div class="rx-date-main"><?= rx_e(rx_fmt_date($r->receivedAt)) ?></div>
                                    <div class="rx-sub">
                                        <?= rx_e(trim(($relDay ?? '') . ($relDay && $time ? ', ' : '') . $time)) ?: '&nbsp;' ?>
                                    </div>
                                </td>
                                <td class="text-nowrap">
                                    <?php if ($r->poNumber): ?>
                                        <a class="rx-po-link"
                                           href="http://<?= BASEURL ?>/admin/purchase/documents/edit?id=<?= (int)$r->poId ?>&amp;type=po"
                                           title="Otwórz zamówienie">
                                            <?= rx_e($r->poNumber) ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted">#<?= (int)$r->poId ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="rx-vendor"><?= rx_e($r->vendorName ?? '—') ?></td>
                                <td class="rx-num">
                                    <?php if ($c['itemCount'] > 0): ?>
                                        <span class="rx-pill"><?= $c['itemCount'] ?></span>
                                    <?php else: ?>
                                        <span class="rx-pill rx-pill--empty" title="Zapisany sam nagłówek dokumentu">bez pozycji</span>
                                    <?php endif; ?>
                                </td>
                                <td class="rx-num d-none d-md-table-cell"><?= rx_e(rx_fmt_qty($c['totalQty'])) ?></td>
                                <td class="d-none d-lg-table-cell">
                                    <span class="rx-person">
                                        <span class="rx-avatar" aria-hidden="true"><?= rx_e(rx_initials($r->receivedByName)) ?></span>
                                        <?= rx_e($receiver) ?>
                                    </span>
                                </td>
                                <td class="text-right text-nowrap">
                                    <a class="btn btn-sm rx-btn-quiet view-receipt-btn" href="<?= rx_e($viewUrl) ?>">
                                        Zobacz <i class="bi bi-chevron-right rx-go"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div id="historyPagination"></div>
        </div>

    </div>
</div>

<script src="<?= asset('public_html/components/purchases/receipts/receipts-view.js') ?>"></script>
<script src="<?= asset('public_html/components/purchases/documents/confirm-po-modal.js') ?>"></script>
