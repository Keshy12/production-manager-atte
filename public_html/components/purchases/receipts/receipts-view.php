<?php
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

include('modals.php');
include('table-row-template.php');
?>

<div class="container-fluid w-75 mt-3">
    <div class="row">
        <div class="col-12 my-2">
            <h2>Przyjęcia</h2>
            <div id="alertContainer"></div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5>
                        <i class="bi bi-list-ul"></i> Lista przyjęć
                        <span class="badge badge-info"><?= count($receipts) ?></span>
                    </h5>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <input type="text" id="receiptFilter" class="form-control" placeholder="Filtruj (numer dokumentu, PO, dostawca, przyjmujący...)">
                    </div>
                    <?php if (empty($receipts)): ?>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i> Brak przyjęć w systemie.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-striped table-hover" id="receiptsTable">
                                <thead class="thead-light">
                                <tr>
                                    <th>ID</th>
                                    <th>Numer dokumentu</th>
                                    <th>Zamówienie</th>
                                    <th>Dostawca</th>
                                    <th>Pozycje</th>
                                    <th>Ilość łącznie</th>
                                    <th>Przyjął</th>
                                    <th>Data</th>
                                    <th>Akcje</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($receipts as $r):
                                    $c = $countsByReceipt[$r->id] ?? ['itemCount' => 0, 'totalQty' => 0.0];
                                ?>
                                    <tr class="receipt-row"
                                        data-id="<?= $r->id ?>"
                                        style="cursor: pointer;">
                                        <td class="text-center"><?= $r->id ?></td>
                                        <td><?= htmlspecialchars($r->documentNumber ?? '—') ?></td>
                                        <td>
                                            <?php if ($r->poNumber): ?>
                                                <a href="http://<?= BASEURL ?>/admin/purchase/orders/edit?id=<?= $r->poId ?>"
                                                   onclick="event.stopPropagation();">
                                                    <?= htmlspecialchars($r->poNumber) ?>
                                                </a>
                                            <?php else: ?>
                                                #<?= $r->poId ?>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($r->vendorName ?? '—') ?></td>
                                        <td class="text-center">
                                            <span class="badge badge-secondary"><?= $c['itemCount'] ?></span>
                                        </td>
                                        <td class="text-right"><?= number_format($c['totalQty'], 4, '.', ' ') ?></td>
                                        <td><?= htmlspecialchars($r->receivedByName ?? ('#' . $r->receivedBy)) ?></td>
                                        <td><small class="text-muted"><?= htmlspecialchars($r->receivedAt ?? '') ?></small></td>
                                        <td>
                                            <button class="btn btn-sm btn-info view-receipt-btn"
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
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="<?= asset('public_html/components/purchases/receipts/receipts-view.js') ?>"></script>
