<?php
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Master\VendorRepository;
use Atte\Utils\Purchase\Order\PurchaseOrderRepository;
use Atte\Utils\Purchase\Order\PurchaseOrderItemRepository;

if(!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    header("Location: http://".BASEURL."/");
    exit();
}

$MsaDB = MsaDB::getInstance();
$vendorRepository        = new VendorRepository($MsaDB);
$poRepository            = new PurchaseOrderRepository($MsaDB);
$poItemRepository        = new PurchaseOrderItemRepository($MsaDB);

$vendors = $vendorRepository->getAll(true);
$pos    = $poRepository->getAll(false);

// Pre-compute item counts + value sums per PO.
foreach ($pos as $po) {
    $po->_itemCount = $poItemRepository->countByPo($po->id);
    $po->_value     = $poItemRepository->sumByPo($po->id);
}

$stateLabels = [
    'draft'              => ['Szkic',                'badge-secondary'],
    'sent'               => ['Wysłane',              'badge-primary'],
    'confirmed'          => ['Potwierdzone',         'badge-info'],
    'partially_received' => ['Częściowo odebrane',   'badge-warning'],
    'received'           => ['Odebrane',             'badge-success'],
    'cancelled'          => ['Anulowane',            'badge-danger'],
];

include('modals.php');
include('table-row-template.php');
?>

<div class="container-fluid w-75 mt-3">
    <div class="row">
        <div class="col-12 my-2">
            <h2>Zamówienia</h2>
            <div id="alertContainer"></div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h5>Nowe zamówienie</h5>
                </div>
                <div class="card-body">
                    <form id="addOrderForm">
                        <div class="row">
                            <div class="col-md-7">
                                <div class="form-group">
                                    <label for="po_vendor_id">Dostawca:</label>
                                    <select id="po_vendor_id" class="selectpicker form-control" data-live-search="true" data-width="100%" required>
                                        <option value="">Wybierz dostawcę...</option>
                                        <?php foreach ($vendors as $v): ?>
                                            <option value="<?= $v->id ?>"><?= htmlspecialchars($v->name) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-5">
                                <div class="form-group">
                                    <label for="po_expected_delivery_date">Oczekiwana data dostawy:</label>
                                    <input type="date" class="form-control" id="po_expected_delivery_date">
                                </div>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="po_comment">Komentarz:</label>
                            <textarea class="form-control" id="po_comment" rows="2"></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-plus-circle"></i> Dodaj zamówienie
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="row mt-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5>
                        <i class="bi bi-list-ul"></i> Lista zamówień
                        <span class="badge badge-info"><?= count($pos) ?></span>
                    </h5>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <input type="text" id="poFilter" class="form-control" placeholder="Filtruj (numer, dostawca, stan...)">
                    </div>
                    <?php if (empty($pos)): ?>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i> Brak zamówień w systemie.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-striped table-hover" id="poTable">
                                <thead class="thead-light">
                                <tr>
                                    <th>ID</th>
                                    <th>Numer</th>
                                    <th>Vendor</th>
                                    <th>#Pozycji</th>
                                    <th>Wartość</th>
                                    <th>Stan</th>
                                    <th>Data utworzenia</th>
                                    <th>Akcje</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($pos as $po):
                                    $stateInfo = $stateLabels[$po->state] ?? [$po->state, 'badge-secondary'];
                                    $canSend   = $po->state === 'draft';
                                    $canConfirm= $po->state === 'sent';
                                    $canCancel = in_array($po->state, ['draft','sent','confirmed'], true);
                                ?>
                                    <tr>
                                        <td class="text-center"><?= $po->id ?></td>
                                        <td><?= htmlspecialchars($po->poNumber ?? '—') ?></td>
                                        <td><?= htmlspecialchars($po->vendorName ?? '—') ?></td>
                                        <td class="text-center"><span class="badge badge-secondary"><?= $po->_itemCount ?></span></td>
                                        <td class="text-right"><?= number_format($po->_value, 2, '.', ' ') ?></td>
                                        <td><span class="badge <?= $stateInfo[1] ?>"><?= htmlspecialchars($stateInfo[0]) ?></span></td>
                                        <td><small class="text-muted"><?= htmlspecialchars($po->createdAt ?? '') ?></small></td>
                                        <td>
                                            <div class="btn-group" role="group">
                                                <a class="btn btn-sm btn-warning"
                                                   href="http://<?= BASEURL ?>/admin/purchase/orders/edit?id=<?= $po->id ?>">
                                                    <i class="bi bi-pencil"></i> Edytuj
                                                </a>
                                                <?php if ($canSend): ?>
                                                    <button class="btn btn-sm btn-primary send-po-btn"
                                                            data-id="<?= $po->id ?>"
                                                            data-number="<?= htmlspecialchars($po->poNumber ?? '') ?>"
                                                            data-vendor="<?= htmlspecialchars($po->vendorName ?? '') ?>">
                                                        <i class="bi bi-send"></i> Wyślij
                                                    </button>
                                                <?php endif; ?>
                                                <?php if ($canConfirm): ?>
                                                    <button class="btn btn-sm btn-info confirm-po-btn"
                                                            data-id="<?= $po->id ?>"
                                                            data-number="<?= htmlspecialchars($po->poNumber ?? '') ?>"
                                                            data-vendor="<?= htmlspecialchars($po->vendorName ?? '') ?>">
                                                        <i class="bi bi-check-circle"></i> Potwierdź
                                                    </button>
                                                <?php endif; ?>
                                                <?php if ($canCancel): ?>
                                                    <button class="btn btn-sm btn-danger cancel-po-btn"
                                                            data-id="<?= $po->id ?>"
                                                            data-number="<?= htmlspecialchars($po->poNumber ?? '') ?>">
                                                        <i class="bi bi-x-circle"></i> Anuluj
                                                    </button>
                                                <?php endif; ?>
                                            </div>
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

<script src="<?= asset('public_html/components/Admin/Purchase/Orders/orders-view.js') ?>"></script>
