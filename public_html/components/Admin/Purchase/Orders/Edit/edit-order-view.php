<?php
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Master\VendorRepository;
use Atte\Utils\Purchase\Master\VendorPartRepository;
use Atte\Utils\Purchase\Order\PurchaseOrderRepository;
use Atte\Utils\Purchase\Order\PurchaseOrderItemRepository;

if(!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    header("Location: http://".BASEURL."/");
    exit();
}

$MsaDB = MsaDB::getInstance();
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    header("Location: http://".BASEURL."/admin/purchase/orders");
    exit;
}

$poRepository = new PurchaseOrderRepository($MsaDB);
$po = $poRepository->getById($id);
if ($po === null) {
    header("Location: http://".BASEURL."/admin/purchase/orders");
    exit;
}

$poItemRepository    = new PurchaseOrderItemRepository($MsaDB);
$vendorRepository    = new VendorRepository($MsaDB);
$vendorPartRepository = new VendorPartRepository($MsaDB);

$vendor      = $vendorRepository->getById($po->vendorId);
$vendorParts = $vendorPartRepository->getByVendor($po->vendorId, true);
$items       = $poItemRepository->getByPo($po->id);

// Pre-compute per-row line total to keep the template tidy.
foreach ($items as $item) {
    $item->_lineTotal = $item->quantity * $item->unitPrice;
}

$stateLabels = [
    'draft'              => ['Szkic',                'badge-secondary'],
    'sent'               => ['Wysłane',              'badge-primary'],
    'confirmed'          => ['Potwierdzone',         'badge-info'],
    'partially_received' => ['Częściowo odebrane',   'badge-warning'],
    'received'           => ['Odebrane',             'badge-success'],
    'cancelled'          => ['Anulowane',            'badge-danger'],
];
$stateInfo = $stateLabels[$po->state] ?? [$po->state, 'badge-secondary'];
$canEdit   = $po->state === 'draft';

include('modals.php');
?>

<div id="poPageContext"
     data-po-id="<?= $po->id ?>"
     data-vendor-id="<?= $po->vendorId ?>"
     data-can-edit="<?= $canEdit ? '1' : '0' ?>"
     data-can-receive="<?= in_array($po->state, ['confirmed','partially_received'], true) ? '1' : '0' ?>"></div>

<div class="container-fluid w-75 mt-3">
    <div class="row">
        <div class="col-12 my-2">
            <h2>Edycja zamówienia: <small class="text-muted"><?= htmlspecialchars($po->poNumber ?? ('#' . $po->id)) ?></small></h2>
            <div id="alertContainer"></div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="bi bi-truck"></i> <?= htmlspecialchars($vendor ? $vendor->name : '—') ?>
                        <span class="badge <?= $stateInfo[1] ?> ml-2"><?= htmlspecialchars($stateInfo[0]) ?></span>
                    </h5>
                    <div>
                        <?php if ($po->state === 'draft'): ?>
                            <button class="btn btn-sm btn-primary send-po-btn"
                                    data-id="<?= $po->id ?>"
                                    data-number="<?= htmlspecialchars($po->poNumber ?? '') ?>"
                                    data-vendor="<?= htmlspecialchars($vendor ? $vendor->name : '') ?>">
                                <i class="bi bi-send"></i> Wyślij
                            </button>
                        <?php endif; ?>
                        <?php if ($po->state === 'sent'): ?>
                            <button class="btn btn-sm btn-info confirm-po-btn"
                                    data-id="<?= $po->id ?>"
                                    data-number="<?= htmlspecialchars($po->poNumber ?? '') ?>"
                                    data-vendor="<?= htmlspecialchars($vendor ? $vendor->name : '') ?>">
                                <i class="bi bi-check-circle"></i> Potwierdź
                            </button>
                        <?php endif; ?>
                        <?php if (in_array($po->state, ['confirmed','partially_received'], true)): ?>
                            <a class="btn btn-sm btn-success"
                               href="http://<?= BASEURL ?>/admin/purchase/orders/receive?po_id=<?= $po->id ?>">
                                <i class="bi bi-box-arrow-in-down"></i> Przyjmij towar
                            </a>
                        <?php elseif ($po->state === 'received'): ?>
                            <button class="btn btn-sm btn-secondary" disabled>
                                <i class="bi bi-check2-all"></i> Towar już przyjęty
                            </button>
                        <?php elseif ($po->state === 'cancelled'): ?>
                            <button class="btn btn-sm btn-secondary" disabled>
                                <i class="bi bi-x-octagon"></i> Zamówienie anulowane
                            </button>
                        <?php endif; ?>
                        <?php if (in_array($po->state, ['draft','sent','confirmed'], true)): ?>
                            <button class="btn btn-sm btn-danger cancel-po-btn"
                                    data-id="<?= $po->id ?>"
                                    data-number="<?= htmlspecialchars($po->poNumber ?? '') ?>">
                                <i class="bi bi-x-circle"></i> Anuluj
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3">
                            <strong>Numer:</strong>
                            <?= htmlspecialchars($po->poNumber ?? '—') ?>
                        </div>
                        <div class="col-md-3">
                            <strong>Numer u dostawcy:</strong>
                            <?= htmlspecialchars($po->vendorPoNumber ?? '—') ?>
                        </div>
                        <div class="col-md-3">
                            <strong>Oczekiwana dostawa:</strong>
                            <?= htmlspecialchars($po->expectedDeliveryDate ?? '—') ?>
                        </div>
                        <div class="col-md-3">
                            <strong>Wysłano / Potwierdzono:</strong>
                            <?= htmlspecialchars($po->sentAt ? $po->sentAt : '—') ?>
                            /
                            <?= htmlspecialchars($po->confirmedAt ? $po->confirmedAt : '—') ?>
                        </div>
                    </div>
                    <?php if ($po->convertedFromRfqId): ?>
                        <hr>
                        <i class="bi bi-arrow-left-right"></i>
                        <a href="http://<?= BASEURL ?>/admin/purchase/rfqs/edit?id=<?= $po->convertedFromRfqId ?>">
                            ← Powiązane zapytanie: <?= htmlspecialchars($po->convertedFromRfqNumber ?? ('#' . $po->convertedFromRfqId)) ?>
                        </a>
                    <?php endif; ?>
                    <?php if ($po->comment): ?>
                        <hr>
                        <strong>Komentarz:</strong>
                        <div><?= nl2br(htmlspecialchars($po->comment)) ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php if ($canEdit): ?>
    <div class="row mt-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header"><h5>Dodaj pozycję</h5></div>
                <div class="card-body">
                    <form id="addOrderItemForm">
                        <input type="hidden" id="po_item_po_id" value="<?= $po->id ?>">
                        <div class="row">
                            <div class="col-md-7">
                                <div class="form-group">
                                    <label>Artykuł u dostawcy:</label>
                                    <select id="po_item_vendor_part_select" class="selectpicker form-control" data-live-search="true" data-width="100%">
                                        <option value="">Wybierz...</option>
                                        <?php foreach ($vendorParts as $vp): ?>
                                            <option value="<?= $vp->id ?>"
                                                    data-vendor-jm-id="<?= $vp->vendorJmId ?>">
                                                <?= htmlspecialchars(($vp->vendorPartNo ?? '') . ' — ' . ($vp->partName ?? '') . ' (' . ($vp->producerName ?? '') . ')') ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="hidden" id="po_item_vendor_part_id">
                                    <input type="hidden" id="po_item_quantity_unit_id">
                                </div>
                            </div>
                            <div class="col-md-2">
                                <div class="form-group">
                                    <label for="po_item_quantity">Ilość:</label>
                                    <input type="number" step="0.0001" min="0.0001" class="form-control" id="po_item_quantity" required>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="po_item_unit_price">Cena jedn.:</label>
                                    <input type="number" step="0.0001" min="0" class="form-control" id="po_item_unit_price" value="0">
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="po_item_currency">Waluta:</label>
                                    <select class="form-control" id="po_item_currency">
                                        <option value="PLN">PLN</option>
                                        <option value="EUR">EUR</option>
                                        <option value="USD">USD</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="po_item_comment">Komentarz:</label>
                            <textarea class="form-control" id="po_item_comment" rows="2"></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-plus-circle"></i> Dodaj pozycję
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="row mt-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5>
                        <i class="bi bi-list-ul"></i> Pozycje
                        <span class="badge badge-info"><?= count($items) ?></span>
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (empty($items)): ?>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i> Brak pozycji w tym zamówieniu.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead class="thead-light">
                                <tr>
                                    <th>ID</th>
                                    <th>VendorPartNo</th>
                                    <th>Part</th>
                                    <th>Producent</th>
                                    <th>JM</th>
                                    <th>Ilość</th>
                                    <th>Cena&nbsp;jedn.</th>
                                    <th>Wartość</th>
                                    <th>Waluta</th>
                                    <th>Akcje</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($items as $item): ?>
                                    <tr>
                                        <td class="text-center"><?= $item->id ?></td>
                                        <td><?= htmlspecialchars($item->vendorPartNo ?? '—') ?></td>
                                        <td><?= htmlspecialchars($item->partName ?? '—') ?></td>
                                        <td><?= htmlspecialchars($item->producerName ?? '—') ?></td>
                                        <td><?= htmlspecialchars($item->unitName ?? '—') ?></td>
                                        <td class="text-center"><?= htmlspecialchars(rtrim(rtrim(number_format($item->quantity, 4, '.', ''), '0'), '.')) ?></td>
                                        <td class="text-right"><?= htmlspecialchars(number_format($item->unitPrice, 4, '.', ' ')) ?></td>
                                        <td class="text-right"><?= htmlspecialchars(number_format($item->_lineTotal, 2, '.', ' ')) ?></td>
                                        <td class="text-center"><?= htmlspecialchars($item->currency) ?></td>
                                        <td>
                                            <?php if ($canEdit): ?>
                                                <button class="btn btn-sm btn-warning edit-item-btn" data-id="<?= $item->id ?>">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <button class="btn btn-sm btn-danger delete-item-btn"
                                                        data-id="<?= $item->id ?>"
                                                        data-vendor-part-no="<?= htmlspecialchars($item->vendorPartNo ?? '') ?>">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
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

<script src="<?= asset('public_html/components/Admin/Purchase/Orders/Edit/edit-order-view.js') ?>"></script>
