<?php
/**
 * Minimal PO edit page (read-only for now).
 *
 * Wired at /admin/purchase/orders/edit?id=N. Will grow into a real
 * edit form later (vendor / dates / items / state transitions).
 *
 * Loads the header via PurchaseOrderRepository and the items via
 * PurchaseOrderItemRepository, mirrors receipts-view.php style.
 */
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Order\PurchaseOrderRepository;
use Atte\Utils\Purchase\Order\PurchaseOrderItemRepository;

if(!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    header("Location: http://".BASEURL."/");
    exit();
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    echo '<div class="container-fluid w-75 mt-3"><div class="alert alert-danger">Brak id zamówienia.</div></div>';
    return;
}

$MsaDB = MsaDB::getInstance();
$poRepo = new PurchaseOrderRepository($MsaDB);
$poiRepo = new PurchaseOrderItemRepository($MsaDB);

$po = $poRepo->getById($id);
if ($po === null) {
    echo '<div class="container-fluid w-75 mt-3"><div class="alert alert-danger">Zamówienie #' . htmlspecialchars((string)$id) . ' nie istnieje.</div></div>';
    return;
}
$items = $poiRepo->getByPo($id);
$total = $poiRepo->sumByPo($id);

$stateBadgeClass = [
    'draft'              => 'badge-secondary',
    'sent'               => 'badge-primary',
    'confirmed'          => 'badge-info',
    'partially_received' => 'badge-warning',
    'received'           => 'badge-success',
    'cancelled'          => 'badge-danger',
];
$stateBadgeLabel = [
    'draft'              => 'szkic',
    'sent'               => 'wysłane',
    'confirmed'          => 'potwierdzone',
    'partially_received' => 'częściowo odebrane',
    'received'           => 'odebrane',
    'cancelled'          => 'anulowane',
];

function formatMoney($v) {
    if ($v === null || $v === '') return '—';
    return number_format((float)$v, 4, '.', ' ') . ' / szt';
}
function formatQty($v) {
    if ($v === null || $v === '') return '—';
    return rtrim(rtrim(number_format((float)$v, 4, '.', ' '), '0'), '.');
}
?>
<div class="container-fluid w-75 mt-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="mb-0">
            <i class="bi bi-file-earmark-text"></i>
            Zamówienie
            <span class="font-weight-bold"><?= htmlspecialchars($po->poNumber ?? ('#' . $po->id)) ?></span>
            <span class="badge <?= $stateBadgeClass[$po->state] ?? 'badge-secondary' ?> ml-2"><?= $stateBadgeLabel[$po->state] ?? $po->state ?></span>
        </h2>
        <a href="http://<?= BASEURL ?>/purchase/cart" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> Wróć do koszyka
        </a>
    </div>

    <div class="card mb-3">
        <div class="card-header"><strong>Nagłówek</strong></div>
        <div class="card-body py-2">
            <dl class="row mb-0">
                <dt class="col-sm-2">Dostawca:</dt>
                <dd class="col-sm-4"><?= htmlspecialchars($po->vendorName ?? '—') ?></dd>
                <dt class="col-sm-2">Numer PO:</dt>
                <dd class="col-sm-4"><?= htmlspecialchars($po->poNumber ?? '—') ?></dd>

                <dt class="col-sm-2">Numer u dostawcy:</dt>
                <dd class="col-sm-4"><?= htmlspecialchars($po->vendorPoNumber ?? '—') ?></dd>
                <dt class="col-sm-2">Planowana dostawa:</dt>
                <dd class="col-sm-4"><?= $po->expectedDeliveryDate ? htmlspecialchars($po->expectedDeliveryDate) : '—' ?></dd>

                <?php if ($po->convertedFromRfqId): ?>
                    <dt class="col-sm-2">Z zapytania:</dt>
                    <dd class="col-sm-4">
                        <a href="http://<?= BASEURL ?>/admin/purchase/rfqs/edit?id=<?= (int)$po->convertedFromRfqId ?>">
                            <?= htmlspecialchars($po->convertedFromRfqNumber ?? ('RFQ #' . $po->convertedFromRfqId)) ?>
                        </a>
                    </dd>
                <?php endif; ?>

                <dt class="col-sm-2">Utworzył:</dt>
                <dd class="col-sm-4"><?= (int)$po->createdBy ?></dd>
                <dt class="col-sm-2">Utworzone:</dt>
                <dd class="col-sm-4"><?= htmlspecialchars($po->createdAt ?? '—') ?></dd>

                <?php if ($po->comment): ?>
                    <dt class="col-sm-2">Komentarz:</dt>
                    <dd class="col-sm-10"><?= nl2br(htmlspecialchars($po->comment)) ?></dd>
                <?php endif; ?>
            </dl>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <strong>Pozycje</strong>
            <span class="text-muted">Łącznie: <?= htmlspecialchars(formatQty($poiRepo->sumQuantityReceivedByPo($id))) ?> / <?= htmlspecialchars(formatQty(array_sum(array_map(fn($i) => (float)$i->quantity, $items)))) ?> szt (odebrane / zamówione)</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-striped mb-0">
                    <thead class="thead-light">
                        <tr>
                            <th>Część</th>
                            <th>Nr u dostawcy</th>
                            <th>Producent</th>
                            <th class="text-right">Ilość</th>
                            <th>JM</th>
                            <th class="text-right">Cena / szt</th>
                            <th>Waluta</th>
                            <th class="text-right">Odebrane</th>
                            <th>Komentarz</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($items)): ?>
                        <tr><td colspan="9" class="text-center text-muted">Brak pozycji.</td></tr>
                    <?php else: foreach ($items as $i): ?>
                        <tr>
                            <td><?= htmlspecialchars($i->partName ?? '—') ?></td>
                            <td><?= htmlspecialchars($i->vendorPartNo ?? '—') ?></td>
                            <td><?= htmlspecialchars($i->producerName ?? '—') ?></td>
                            <td class="text-right"><?= htmlspecialchars(formatQty($i->quantity)) ?></td>
                            <td><?= htmlspecialchars($i->unitName ?? '—') ?></td>
                            <td class="text-right"><?= htmlspecialchars(formatMoney($i->unitPrice)) ?></td>
                            <td><?= htmlspecialchars($i->currency ?? '—') ?></td>
                            <td class="text-right"><?= htmlspecialchars(formatQty($i->quantityReceived)) ?></td>
                            <td><?= $i->comment ? htmlspecialchars($i->comment) : '' ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                    <tfoot>
                        <tr class="font-weight-bold">
                            <td colspan="5" class="text-right">Suma wg cen:</td>
                            <td class="text-right"><?= number_format((float)$total, 4, '.', ' ') ?></td>
                            <td colspan="3"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <p class="text-muted small">
        Widok minimalny — pola edycji i akcje (wyślij / potwierdź / odbierz) pojawią się w kolejnych iteracjach.
    </p>
</div>
