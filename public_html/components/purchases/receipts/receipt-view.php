<?php
/**
 * Szczegóły przyjęcia — /purchase/receipts/view?id=N.
 *
 * Osobna strona zamiast dawnego modala (#receiptInfoModal). Renderowana
 * w całości po stronie PHP — te same dane co receipt-get.php (nagłówek
 * + pozycje), plus dwie rzeczy, których modal nie miał:
 *   - stan pozycji PO TYM przyjęciu (suma przyjęć tej pozycji do tego
 *     dokumentu włącznie), więc widać, czy ta dostawa domknęła pozycję,
 *     czy była częściowa — niezależnie od późniejszych dostaw,
 *   - lista wszystkich przyjęć do tego samego zamówienia.
 * receipt-get.php zostaje bez zmian (kontrakt endpointu nietknięty).
 *
 * Tylko odczyt. Stanów magazynowych tu nie liczymy (robią to triggery) —
 * sumy poniżej to wyłącznie arytmetyka do wyświetlenia.
 *
 * Nieprawidłowe / brakujące ?id albo nieistniejące przyjęcie → stan
 * błędu z linkiem powrotnym (bez przekierowania, jak receive-page.php).
 */

use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Order\OrderReceiptRepository;

if(!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    header("Location: http://".BASEURL."/");
    exit();
}

require_once __DIR__ . '/receipts-helpers.php';

$EPS        = 1e-6;
$baseUrl    = 'http://' . BASEURL;
$listUrl    = $baseUrl . '/purchase/receipts';
$historyUrl = $listUrl . '#historia';

$rawId = trim((string)($_GET['id'] ?? ''));
$id    = ctype_digit($rawId) ? (int)$rawId : 0;

$error    = null;
$receipt  = null;
$po       = null;
$items    = [];
$related  = [];
$relatedCounts = [];

if ($id <= 0) {
    $error = [
        'title' => 'Nieprawidłowy adres przyjęcia',
        'text'  => 'W adresie strony brakuje numeru przyjęcia albo jest on nieprawidłowy. '
                 . 'Wybierz przyjęcie z listy w historii.',
    ];
} else {
    try {
        $MsaDB = MsaDB::getInstance();
        $repo  = new OrderReceiptRepository($MsaDB);
        $receipt = $repo->getById($id);

        if ($receipt === null) {
            $error = [
                'title' => 'Nie znaleziono przyjęcia',
                'text'  => 'Przyjęcie o ID ' . $id . ' nie istnieje albo zostało usunięte.',
            ];
        } else {
            // Zamówienie: bieżący stan + numer u dostawcy.
            $poStmt = $MsaDB->db->prepare(
                "SELECT state, vendor_po_number AS vendorPoNumber,
                        expected_delivery_date AS expectedDeliveryDate
                   FROM `purchase__order` WHERE id = ?"
            );
            $poStmt->execute([$receipt->poId]);
            $po = $poStmt->fetch(\PDO::FETCH_ASSOC) ?: null;

            // Pozycje — te same JOIN-y co receipt-get.php, plus receivedUpTo:
            // ile tej pozycji przyjęto łącznie do tego dokumentu włącznie
            // (kolejność rejestracji = id przyjęcia, potem id pozycji).
            $itStmt = $MsaDB->db->prepare(
                "SELECT ri.id,
                        ri.po_item_id AS poItemId,
                        ri.quantity_received AS quantityReceived,
                        ri.sub_magazine_id AS subMagazineId,
                        ri.comment,
                        vp.vendor_part_no AS vendorPartNo,
                        lp.name AS partName,
                        pr.name AS producerName,
                        u.name AS unitName,
                        m.sub_magazine_name AS magazineName,
                        poi.quantity AS orderedQty,
                        poi.quantity_received AS totalReceivedQty,
                        (SELECT COALESCE(SUM(ri2.quantity_received), 0)
                           FROM `purchase__order_receipt_item` ri2
                          WHERE ri2.po_item_id = ri.po_item_id
                            AND (ri2.receipt_id < ri.receipt_id
                                 OR (ri2.receipt_id = ri.receipt_id AND ri2.id <= ri.id))
                        ) AS receivedUpTo
                 FROM `purchase__order_receipt_item` ri
                 LEFT JOIN `purchase__order_item` poi     ON ri.po_item_id    = poi.id
                 LEFT JOIN `list__vendor_part` vp          ON poi.vendor_part_id = vp.id
                 LEFT JOIN `list__parts` lp                ON vp.parts_id        = lp.id
                 LEFT JOIN `list__producer` pr             ON vp.producer_id     = pr.id
                 LEFT JOIN `part__unit` u                  ON poi.quantity_unit_id = u.id
                 LEFT JOIN `magazine__list` m              ON ri.sub_magazine_id = m.sub_magazine_id
                 WHERE ri.receipt_id = ?
                 ORDER BY ri.id ASC"
            );
            $itStmt->execute([$id]);
            $items = $itStmt->fetchAll(\PDO::FETCH_ASSOC);

            // Wszystkie przyjęcia do tego zamówienia (chronologicznie).
            $related = array_reverse($repo->getByPo($receipt->poId));
            if (!empty($related)) {
                $ids = implode(',', array_map(fn($r) => (int)$r->id, $related));
                $cStmt = $MsaDB->db->prepare(
                    "SELECT receipt_id, COUNT(*) AS n
                       FROM `purchase__order_receipt_item`
                      WHERE receipt_id IN ($ids)
                      GROUP BY receipt_id"
                );
                $cStmt->execute();
                foreach ($cStmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                    $relatedCounts[(int)$row['receipt_id']] = (int)$row['n'];
                }
            }
        }
    } catch (\Throwable $e) {
        error_log('receipt-view error: ' . $e->getMessage());
        $error = [
            'title' => 'Nie udało się wczytać przyjęcia',
            'text'  => 'Wystąpił błąd po stronie serwera. Odśwież stronę, a jeśli problem wróci — zgłoś go administratorowi.',
        ];
    }
}

// ---- Przygotowanie danych do widoku ------------------------------------

$rows       = [];
$totalQty   = 0.0;
$cntClosed  = 0;
$cntPartial = 0;
$cntExtra   = 0;

foreach ($items as $it) {
    $ordered = (float)$it['orderedQty'];
    $thisQty = (float)$it['quantityReceived'];
    $upTo    = (float)$it['receivedUpTo'];
    $before  = max(0.0, $upTo - $thisQty);
    $now     = (float)$it['totalReceivedQty'];
    $totalQty += $thisQty;

    if ($ordered <= $EPS) {
        $status = 'unknown';
    } elseif ($before + $EPS >= $ordered) {
        $status = 'extra';      // pozycja była kompletna już przed tą dostawą
        $cntExtra++;
    } elseif ($upTo + $EPS >= $ordered) {
        $status = 'closed';     // ta dostawa domknęła pozycję
        $cntClosed++;
    } else {
        $status = 'partial';    // po tej dostawie nadal brakowało
        $cntPartial++;
    }

    $pctBefore = $ordered > $EPS ? min(100.0, $before / $ordered * 100) : 0.0;
    $pctUpTo   = $ordered > $EPS ? min(100.0, $upTo / $ordered * 100) : 0.0;

    $rows[] = [
        'it'        => $it,
        'ordered'   => $ordered,
        'thisQty'   => $thisQty,
        'upTo'      => $upTo,
        'before'    => $before,
        'now'       => $now,
        'status'    => $status,
        'missing'   => max(0.0, $ordered - $upTo),
        'over'      => max(0.0, $upTo - $ordered),
        'pctBefore' => $pctBefore,
        'pctThis'   => max(0.0, $pctUpTo - $pctBefore),
        'unit'      => (string)($it['unitName'] ?? ''),
    ];
}

$itemCount = count($rows);

// Numer dokumentu dostawcy siedzi w komentarzu jako pierwsza linijka
// „Nr dok. dostawcy: …" (konwencja receipt-create.php / receipt-accept.php,
// rozbijana tak samo w receipt-po-get.php).
$vendorDocNumber = null;
$commentBody     = $receipt ? $receipt->comment : null;
$prefix          = 'Nr dok. dostawcy: ';
if ($commentBody !== null && strncmp($commentBody, $prefix, strlen($prefix)) === 0) {
    $after = substr($commentBody, strlen($prefix));
    $nl    = strpos($after, "\n");
    if ($nl === false) {
        $vendorDocNumber = rtrim($after, "\r");
        $commentBody     = null;
    } else {
        $vendorDocNumber = rtrim(substr($after, 0, $nl), "\r");
        $commentBody     = substr($after, $nl + 1);
    }
    if ($vendorDocNumber === '') $vendorDocNumber = null;
}
if ($commentBody !== null && trim($commentBody) === '') $commentBody = null;

// Pieczątka dokumentu — wynik TEJ dostawy.
if ($receipt) {
    if ($itemCount === 0) {
        $stamp = ['label' => 'Bez pozycji', 'tone' => 'neutral'];
    } elseif ($cntPartial > 0) {
        $stamp = ['label' => 'Dostawa częściowa', 'tone' => 'amber'];
    } else {
        $stamp = ['label' => 'Pozycje kompletne', 'tone' => 'green'];
    }
}

$poState   = $po['state'] ?? null;
$poEditUrl = $receipt ? ($baseUrl . '/admin/purchase/documents/edit?id=' . (int)$receipt->poId . '&type=po') : '';
$canResume = $poState !== null && in_array($poState, ['confirmed', 'in_progress', 'partially_received'], true);
?>

<link rel="stylesheet" href="<?= asset('public_html/components/purchases/receipts/receipts-ui.css') ?>">

<div class="rx-page rx-detail" id="receiptDetailView"
     data-list-url="<?= rx_e($listUrl) ?>">

    <nav aria-label="Ścieżka">
        <ol class="rx-crumbs">
            <li><a href="<?= rx_e($listUrl) ?>"><i class="bi bi-box-arrow-in-down"></i> Przyjęcia</a></li>
            <li aria-hidden="true"><i class="bi bi-chevron-right"></i></li>
            <li><a href="<?= rx_e($historyUrl) ?>" class="js-back-to-history">Historia przyjęć</a></li>
            <li aria-hidden="true"><i class="bi bi-chevron-right"></i></li>
            <li aria-current="page">
                <?= $receipt ? rx_e($receipt->documentNumber ?: ('Przyjęcie #' . $receipt->id)) : 'Szczegóły przyjęcia' ?>
            </li>
        </ol>
    </nav>

<?php if ($error !== null): ?>

    <div class="rx-panel">
        <div class="rx-empty rx-empty--page">
            <div class="rx-empty-icon rx-empty-icon--danger"><i class="bi bi-file-earmark-x"></i></div>
            <div class="rx-empty-title"><?= rx_e($error['title']) ?></div>
            <div class="rx-empty-text"><?= rx_e($error['text']) ?></div>
            <a href="<?= rx_e($historyUrl) ?>" class="btn btn-primary btn-sm">
                <i class="bi bi-arrow-left"></i> Wróć do historii przyjęć
            </a>
        </div>
    </div>

<?php else: ?>

    <!-- ===== Arkusz dokumentu: nagłówek + najważniejsze dane ===== -->
    <section class="rx-panel rx-sheet mb-3">
        <div class="rx-dochead">
            <div class="rx-dochead-main">
                <div class="rx-eyebrow">Dokument przyjęcia (GRN)</div>
                <?php if ($receipt->documentNumber): ?>
                    <h1 class="rx-docnum"><?= rx_e($receipt->documentNumber) ?></h1>
                <?php else: ?>
                    <!-- Sam nagłówek (krok „Akceptuję") — numer GRN nadawany
                         jest dopiero przy zapisie ilości. -->
                    <h1 class="rx-docnum rx-docnum--none">
                        Przyjęcie #<?= (int)$receipt->id ?>
                        <span class="rx-badge rx-badge--neutral">bez numeru GRN</span>
                    </h1>
                <?php endif; ?>
                <div class="rx-dochead-meta">
                    do zamówienia
                    <?php if ($receipt->poNumber): ?>
                        <a href="<?= rx_e($poEditUrl) ?>"><?= rx_e($receipt->poNumber) ?></a>
                    <?php else: ?>
                        #<?= (int)$receipt->poId ?>
                    <?php endif; ?>
                    <span class="rx-meta-sep">·</span>
                    <?= rx_e($receipt->vendorName ?? '—') ?>
                    <span class="rx-meta-sep">·</span>
                    <span class="text-faint">ID <?= (int)$receipt->id ?></span>
                </div>
            </div>

            <div class="rx-dochead-side">
                <span class="rx-stamp rx-stamp--<?= rx_e($stamp['tone']) ?>"><?= rx_e($stamp['label']) ?></span>
                <div class="rx-dochead-actions rx-no-print">
                    <a href="<?= rx_e($historyUrl) ?>" class="btn btn-sm rx-btn-quiet js-back-to-history">
                        <i class="bi bi-arrow-left"></i> Wróć do historii
                    </a>
                    <a href="<?= rx_e($poEditUrl) ?>" class="btn btn-sm rx-btn-quiet">
                        <i class="bi bi-file-earmark-text"></i> Otwórz zamówienie
                    </a>
                    <button type="button" class="btn btn-sm rx-btn-quiet" id="receiptPrintBtn" title="Drukuj dokument">
                        <i class="bi bi-printer"></i><span class="sr-only">Drukuj</span>
                    </button>
                </div>
            </div>
        </div>

        <dl class="rx-facts">
            <div class="rx-fact">
                <dt>Dostawca</dt>
                <dd><?= rx_e($receipt->vendorName ?? '—') ?></dd>
            </div>
            <div class="rx-fact">
                <dt>Zamówienie</dt>
                <dd>
                    <?php if ($receipt->poNumber): ?>
                        <a href="<?= rx_e($poEditUrl) ?>"><?= rx_e($receipt->poNumber) ?></a>
                    <?php else: ?>
                        #<?= (int)$receipt->poId ?>
                    <?php endif; ?>
                    <?php if ($poState): ?>
                        <div class="rx-fact-sub"><?= rx_state_badge($poState) ?></div>
                    <?php endif; ?>
                </dd>
            </div>
            <?php if (!empty($po['vendorPoNumber'])): ?>
                <div class="rx-fact">
                    <dt>Nr zamówienia u dostawcy</dt>
                    <dd><?= rx_e($po['vendorPoNumber']) ?></dd>
                </div>
            <?php endif; ?>
            <?php if ($vendorDocNumber !== null): ?>
                <div class="rx-fact">
                    <dt>Dokument dostawcy</dt>
                    <dd><?= rx_e($vendorDocNumber) ?></dd>
                </div>
            <?php endif; ?>
            <div class="rx-fact">
                <dt>Przyjął</dt>
                <dd>
                    <span class="rx-person">
                        <span class="rx-avatar" aria-hidden="true"><?= rx_e(rx_initials($receipt->receivedByName)) ?></span>
                        <?= rx_e($receipt->receivedByName ?: ('#' . $receipt->receivedBy)) ?>
                    </span>
                </dd>
            </div>
            <div class="rx-fact">
                <dt>Data przyjęcia</dt>
                <dd>
                    <?= rx_e(rx_fmt_date($receipt->receivedAt)) ?>
                    <?php $t = rx_fmt_time($receipt->receivedAt); if ($t !== ''): ?>
                        <span class="rx-fact-time"><?= rx_e($t) ?></span>
                    <?php endif; ?>
                </dd>
            </div>
            <div class="rx-fact">
                <dt>Pozycje</dt>
                <dd><?= $itemCount ?></dd>
            </div>
            <div class="rx-fact">
                <dt>Ilość łącznie</dt>
                <dd class="rx-num-left"><?= rx_e(rx_fmt_qty($totalQty)) ?></dd>
            </div>
        </dl>

        <?php if ($commentBody !== null): ?>
            <div class="rx-note">
                <div class="rx-note-label"><i class="bi bi-chat-left-text"></i> Uwagi do przyjęcia</div>
                <?= rx_e($commentBody) ?>
            </div>
        <?php endif; ?>

        <?php if ($itemCount > 0): ?>
            <div class="rx-outcome">
                <span class="rx-outcome-title">Wynik tej dostawy:</span>
                <?php if ($cntClosed > 0): ?>
                    <span class="rx-outcome-item is-closed">
                        <span class="rx-dot"></span>
                        <strong><?= $cntClosed ?></strong>
                        <?= rx_plural($cntClosed, 'pozycja domknięta', 'pozycje domknięte', 'pozycji domkniętych') ?>
                    </span>
                <?php endif; ?>
                <?php if ($cntPartial > 0): ?>
                    <span class="rx-outcome-item is-partial">
                        <span class="rx-dot"></span>
                        <strong><?= $cntPartial ?></strong>
                        <?= rx_plural($cntPartial, 'pozycja przyjęta częściowo', 'pozycje przyjęte częściowo', 'pozycji przyjętych częściowo') ?>
                    </span>
                <?php endif; ?>
                <?php if ($cntExtra > 0): ?>
                    <span class="rx-outcome-item is-extra">
                        <span class="rx-dot"></span>
                        <strong><?= $cntExtra ?></strong>
                        <?= rx_plural($cntExtra, 'pozycja ponad zamówienie', 'pozycje ponad zamówienie', 'pozycji ponad zamówienie') ?>
                    </span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>

    <div class="rx-detail-grid">

        <!-- ===== Pozycje ===== -->
        <section class="rx-panel">
            <div class="rx-panel-head">
                <h2 class="rx-panel-title">
                    Pozycje przyjęte w tym dokumencie
                    <span class="rx-count"><?= $itemCount ?></span>
                </h2>
                <?php if ($itemCount > 0): ?>
                    <div class="rx-legend" aria-hidden="true">
                        <span><i class="rx-legend-prev"></i>wcześniejsze dostawy</span>
                        <span><i class="rx-legend-this"></i>ta dostawa</span>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($itemCount === 0): ?>
                <div class="rx-empty">
                    <div class="rx-empty-icon"><i class="bi bi-inbox"></i></div>
                    <div class="rx-empty-title">Ten dokument nie ma jeszcze pozycji</div>
                    <div class="rx-empty-text">
                        Zapisano tylko nagłówek przyjęcia (krok „Akceptuję" w formularzu).
                        Pozycje pojawią się po zapisaniu ilości.
                    </div>
                    <?php if ($canResume): ?>
                        <a class="btn btn-sm btn-primary rx-no-print"
                           href="<?= rx_e($baseUrl . '/purchase/receipts/receive?po_id=' . (int)$receipt->poId) ?>">
                            <i class="bi bi-box-arrow-in-down"></i> Dokończ przyjęcie
                        </a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table rx-table rx-items mb-0">
                        <thead>
                        <tr>
                            <th scope="col" class="rx-idx">#</th>
                            <th scope="col">Pozycja</th>
                            <th scope="col">Magazyn</th>
                            <th scope="col" class="text-right">Zamówiono</th>
                            <th scope="col" class="text-right">W tym GRN</th>
                            <th scope="col" class="rx-state-col">Stan pozycji po przyjęciu</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $i => $row):
                            $it   = $row['it'];
                            $unit = $row['unit'] !== '' ? ' ' . $row['unit'] : '';
                        ?>
                            <tr class="is-<?= rx_e($row['status']) ?>">
                                <td class="rx-idx"><?= $i + 1 ?></td>
                                <td>
                                    <?php if (!empty($it['vendorPartNo'])): ?>
                                        <span class="rx-partno"><?= rx_e($it['vendorPartNo']) ?></span>
                                    <?php endif; ?>
                                    <div class="rx-partname"><?= rx_e($it['partName'] ?: '—') ?></div>
                                    <?php if (!empty($it['producerName'])): ?>
                                        <div class="rx-sub"><?= rx_e($it['producerName']) ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($it['comment'])): ?>
                                        <div class="rx-line-note"><i class="bi bi-chat-left-text"></i> <?= rx_e($it['comment']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-nowrap">
                                    <?= rx_e($it['magazineName'] ?: ('#' . (int)$it['subMagazineId'])) ?>
                                </td>
                                <td class="rx-num">
                                    <?= rx_e(rx_fmt_qty($row['ordered'])) ?><span class="rx-unit"><?= rx_e($unit) ?></span>
                                </td>
                                <td class="rx-num">
                                    <span class="rx-qty-big"><?= rx_e(rx_fmt_qty($row['thisQty'])) ?></span><span class="rx-unit"><?= rx_e($unit) ?></span>
                                </td>
                                <td class="rx-state-col">
                                    <div class="rx-state-top">
                                        <?php if ($row['status'] === 'closed'): ?>
                                            <span class="rx-badge rx-badge--green">Domknięta</span>
                                        <?php elseif ($row['status'] === 'partial'): ?>
                                            <span class="rx-badge rx-badge--amber">Częściowo</span>
                                        <?php elseif ($row['status'] === 'extra'): ?>
                                            <span class="rx-badge rx-badge--violet">Ponad zamówienie</span>
                                        <?php else: ?>
                                            <span class="rx-badge rx-badge--neutral">Brak danych</span>
                                        <?php endif; ?>
                                        <span class="rx-state-qty">
                                            <?= rx_e(rx_fmt_qty($row['upTo'])) ?> / <?= rx_e(rx_fmt_qty($row['ordered'])) ?>
                                        </span>
                                    </div>
                                    <div class="rx-bar rx-bar--stacked"
                                         title="<?= rx_e('Przed tą dostawą: ' . rx_fmt_qty($row['before']) . ' · ta dostawa: ' . rx_fmt_qty($row['thisQty'])) ?>">
                                        <span class="is-prev" style="width: <?= number_format($row['pctBefore'], 1, '.', '') ?>%"></span>
                                        <span class="is-this is-<?= rx_e($row['status']) ?>" style="width: <?= number_format($row['pctThis'], 1, '.', '') ?>%"></span>
                                    </div>
                                    <div class="rx-sub">
                                        <?php if ($row['status'] === 'closed'): ?>
                                            ta dostawa uzupełniła pozycję<?= $row['over'] > $EPS ? ' (+' . rx_e(rx_fmt_qty($row['over'])) . rx_e($unit) . ' ponad zamówienie)' : '' ?>
                                        <?php elseif ($row['status'] === 'partial'): ?>
                                            po tej dostawie brakowało <?= rx_e(rx_fmt_qty($row['missing'])) . rx_e($unit) ?>
                                        <?php elseif ($row['status'] === 'extra'): ?>
                                            pozycja była już kompletna przed tą dostawą
                                        <?php endif; ?>
                                        <?php if (abs($row['now'] - $row['upTo']) > $EPS): ?>
                                            <span class="rx-later">· obecnie przyjęto <?= rx_e(rx_fmt_qty($row['now'])) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                        <tr>
                            <td></td>
                            <td colspan="3" class="rx-tfoot-label">Razem w tym dokumencie</td>
                            <td class="rx-num"><span class="rx-qty-big"><?= rx_e(rx_fmt_qty($totalQty)) ?></span></td>
                            <td></td>
                        </tr>
                        </tfoot>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <!-- ===== Wszystkie przyjęcia do zamówienia ===== -->
        <aside class="rx-panel rx-related">
            <div class="rx-panel-head">
                <h2 class="rx-panel-title">
                    Przyjęcia do zamówienia
                    <span class="rx-count"><?= count($related) ?></span>
                </h2>
            </div>
            <?php if (count($related) <= 1): ?>
                <p class="rx-related-empty">To jedyne przyjęcie zarejestrowane do tego zamówienia.</p>
            <?php endif; ?>
            <ol class="rx-timeline">
                <?php foreach ($related as $n => $rel):
                    $isCurrent = (int)$rel->id === (int)$receipt->id;
                    $relCount  = $relatedCounts[(int)$rel->id] ?? 0;
                ?>
                    <li class="<?= $isCurrent ? 'is-current' : '' ?>">
                        <?php if ($isCurrent): ?>
                            <div class="rx-timeline-doc"><?= rx_e($rel->documentNumber ?: ('#' . $rel->id)) ?></div>
                        <?php else: ?>
                            <a class="rx-timeline-doc" href="<?= rx_e($baseUrl . '/purchase/receipts/view?id=' . (int)$rel->id) ?>">
                                <?= rx_e($rel->documentNumber ?: ('#' . $rel->id)) ?>
                            </a>
                        <?php endif; ?>
                        <div class="rx-sub">
                            <?= 'Dostawa ' . ($n + 1) ?> · <?= rx_e(rx_fmt_date($rel->receivedAt)) ?>
                            · <?= $relCount ?> <?= rx_plural($relCount, 'pozycja', 'pozycje', 'pozycji') ?>
                            <?php if ($isCurrent): ?><span class="rx-current-tag">to przyjęcie</span><?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ol>
        </aside>
    </div>

    <script src="<?= asset('public_html/components/purchases/receipts/receipt-view.js') ?>"></script>

<?php endif; ?>
</div>
