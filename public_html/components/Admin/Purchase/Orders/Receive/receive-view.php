<?php
use Atte\DB\MsaDB;
use Atte\Utils\MagazineRepository;
use Atte\Utils\Purchase\Order\PurchaseOrderRepository;
use Atte\Utils\Purchase\Order\PurchaseOrderItemRepository;

if(!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    header("Location: http://".BASEURL."/");
    exit();
}

$MsaDB = MsaDB::getInstance();
$poId = (int)($_GET['po_id'] ?? 0);
if ($poId <= 0) {
    header("Location: http://".BASEURL."/admin/purchase/orders");
    exit;
}

$poRepo     = new PurchaseOrderRepository($MsaDB);
$poItemRepo = new PurchaseOrderItemRepository($MsaDB);
$magRepo    = new MagazineRepository($MsaDB);

$po = $poRepo->getById($poId);
if ($po === null) {
    header("Location: http://".BASEURL."/admin/purchase/orders");
    exit;
}

$canReceive = in_array($po->state, ['confirmed','partially_received'], true);
$magazines  = $magRepo->getAllMagazines(onlyIsActive: true);
$items      = $poItemRepo->getByPo($po->id);

include('modals.php');
?>

<div id="receivePageContext"
     data-po-id="<?= $po->id ?>"
     data-can-receive="<?= $canReceive ? '1' : '0' ?>"></div>

<div class="container-fluid w-75 mt-3">
    <div class="row">
        <div class="col-12 my-2">
            <h2>Przyjęcie towaru — zamówienie: <small class="text-muted"><?= htmlspecialchars($po->poNumber ?? ('#' . $po->id)) ?></small></h2>
            <div id="alertContainer"></div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="bi bi-truck"></i> <?= htmlspecialchars($po->vendorName ?? '—') ?>
                        <span class="badge <?= $po->state === 'confirmed' ? 'badge-info' : ($po->state === 'partially_received' ? 'badge-warning' : 'badge-secondary') ?> ml-2">
                            <?= htmlspecialchars([
                                'draft' => 'Szkic', 'sent' => 'Wysłane', 'confirmed' => 'Potwierdzone',
                                'partially_received' => 'Częściowo odebrane', 'received' => 'Odebrane',
                                'cancelled' => 'Anulowane',
                            ][$po->state] ?? $po->state) ?>
                        </span>
                    </h5>
                    <a class="btn btn-sm btn-secondary" href="http://<?= BASEURL ?>/admin/purchase/orders/edit?id=<?= $po->id ?>">
                        <i class="bi bi-arrow-left"></i> Powrót do zamówienia
                    </a>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <strong>Numer PO:</strong>
                            <?= htmlspecialchars($po->poNumber ?? '—') ?>
                        </div>
                        <div class="col-md-4">
                            <strong>Numer u dostawcy:</strong>
                            <?= htmlspecialchars($po->vendorPoNumber ?? '—') ?>
                        </div>
                        <div class="col-md-4">
                            <strong>Oczekiwana dostawa:</strong>
                            <?= htmlspecialchars($po->expectedDeliveryDate ?? '—') ?>
                        </div>
                    </div>
                    <?php if ($po->comment): ?>
                        <hr>
                        <strong>Komentarz:</strong>
                        <div><?= nl2br(htmlspecialchars($po->comment)) ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php if (!$canReceive): ?>
        <div class="row mt-4">
            <div class="col-md-12">
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle"></i>
                    <strong>Nie można przyjąć towaru.</strong>
                    Zamówienie jest w stanie <b><?= htmlspecialchars($po->state) ?></b>.
                    Aby przyjmować towary, zamówienie musi być w stanie
                    <b>Potwierdzone</b> lub <b>Częściowo odebrane</b>.
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="row mt-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header"><h5>Nowe przyjęcie</h5></div>
                    <div class="card-body">
                        <form id="receiveForm">
                            <input type="hidden" id="receive_po_id" value="<?= $po->id ?>">
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="receive_document_number">Numer dokumentu (PZ/WZ):</label>
                                        <input type="text" class="form-control" id="receive_document_number" placeholder="np. PZ/2026/0042">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="receive_sub_magazine_id">Magazyn docelowy:</label>
                                        <select id="receive_sub_magazine_id" class="selectpicker form-control" data-live-search="true" data-width="100%" required>
                                            <option value="">Wybierz magazyn...</option>
                                            <?php foreach ($magazines as $mag): ?>
                                                <option value="<?= $mag['sub_magazine_id'] ?>">
                                                    <?= htmlspecialchars($mag['sub_magazine_name']) ?>
                                                    <?php if (!empty($mag['type_name'])): ?>
                                                        (<?= htmlspecialchars($mag['type_name']) ?>)
                                                    <?php endif; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="receive_comment">Komentarz do przyjęcia:</label>
                                <textarea class="form-control" id="receive_comment" rows="2"></textarea>
                            </div>

                            <hr>
                            <h6>Pozycje do przyjęcia</h6>
                            <?php if (empty($items)): ?>
                                <div class="alert alert-warning">
                                    <i class="bi bi-exclamation-triangle"></i> Zamówienie nie ma pozycji.
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-striped table-hover">
                                        <thead class="thead-light">
                                        <tr>
                                            <th>VendorPartNo</th>
                                            <th>Part</th>
                                            <th>JM</th>
                                            <th>Zamówiono</th>
                                            <th>Już&nbsp;przyjęto</th>
                                            <th>Pozostało</th>
                                            <th>Max&nbsp;(110%)</th>
                                            <th>Ilość&nbsp;do&nbsp;przyjęcia</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($items as $item):
                                            $remaining = max(0.0, (float)$item->quantity - (float)$item->quantityReceived);
                                            $maxAllowed = $remaining * 1.10 + 1e-6;
                                        ?>
                                            <tr>
                                                <td><?= htmlspecialchars($item->vendorPartNo ?? '—') ?></td>
                                                <td><?= htmlspecialchars($item->partName ?? '—') ?></td>
                                                <td><?= htmlspecialchars($item->unitName ?? '—') ?></td>
                                                <td class="text-center"><?= htmlspecialchars(number_format((float)$item->quantity, 4, '.', ' ')) ?></td>
                                                <td class="text-center"><?= htmlspecialchars(number_format((float)$item->quantityReceived, 4, '.', ' ')) ?></td>
                                                <td class="text-center">
                                                    <span class="badge badge-info remaining-badge" data-row="<?= $item->id ?>">
                                                        <?= htmlspecialchars(number_format($remaining, 4, '.', ' ')) ?>
                                                    </span>
                                                </td>
                                                <td class="text-center">
                                                    <span class="text-muted max-badge" data-row="<?= $item->id ?>">
                                                        <?= htmlspecialchars(number_format($maxAllowed, 4, '.', ' ')) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <input type="number"
                                                           class="form-control receive-qty"
                                                           data-row="<?= $item->id ?>"
                                                           data-remaining="<?= htmlspecialchars(number_format($remaining, 10, '.', '')) ?>"
                                                           data-max="<?= htmlspecialchars(number_format($maxAllowed, 10, '.', '')) ?>"
                                                           step="0.0001"
                                                           min="0"
                                                           placeholder="0">
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>

                            <button type="submit" class="btn btn-primary" <?= empty($items) ? 'disabled' : '' ?>>
                                <i class="bi bi-save"></i> Zapisz przyjęcie
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<script src="<?= asset('public_html/components/Admin/Purchase/Orders/Receive/receive-view.js') ?>"></script>
