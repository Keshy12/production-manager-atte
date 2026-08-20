<?php
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Master\VendorRepository;
use Atte\Utils\Purchase\Master\VendorPartRepository;
use Atte\Utils\Purchase\Order\RFQRepository;
use Atte\Utils\Purchase\Order\RFQItemRepository;

if(!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    header("Location: http://".BASEURL."/");
    exit();
}

$MsaDB = MsaDB::getInstance();
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    header("Location: http://".BASEURL."/admin/purchase/rfqs");
    exit;
}

$rfqRepository = new RFQRepository($MsaDB);
$rfq = $rfqRepository->getById($id);
if ($rfq === null) {
    header("Location: http://".BASEURL."/admin/purchase/rfqs");
    exit;
}

$rfqItemRepository = new RFQItemRepository($MsaDB);
$vendorRepository  = new VendorRepository($MsaDB);
$vendorPartRepository = new VendorPartRepository($MsaDB);

$vendor = $vendorRepository->getById($rfq->vendorId);
$vendorParts = $vendorPartRepository->getByVendor($rfq->vendorId, true);
$items = $rfqItemRepository->getByRfq($rfq->id);

$stateLabels = [
    'draft'      => ['Szkic',         'badge-secondary'],
    'sent'       => ['Wysłane',       'badge-primary'],
    'responded'  => ['Odpowiedź',     'badge-success'],
    'cancelled'  => ['Anulowane',     'badge-danger'],
    'converted'  => ['Skonwertowane', 'badge-info'],
];
$stateInfo = $stateLabels[$rfq->state] ?? [$rfq->state, 'badge-secondary'];
$canEdit   = $rfq->state === 'draft';

include('modals.php');
?>

<div id="rfqPageContext" data-rfq-id="<?= $rfq->id ?>" data-vendor-id="<?= $rfq->vendorId ?>" data-can-edit="<?= $canEdit ? '1' : '0' ?>"></div>

<div class="container-fluid w-75 mt-3">
    <div class="row">
        <div class="col-12 my-2">
            <h2>Edycja zapytania: <small class="text-muted"><?= htmlspecialchars($rfq->rfqNumber ?? ('#' . $rfq->id)) ?></small></h2>
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
                        <?php if ($rfq->state === 'draft'): ?>
                            <button class="btn btn-sm btn-primary send-rfq-btn"
                                    data-id="<?= $rfq->id ?>"
                                    data-number="<?= htmlspecialchars($rfq->rfqNumber ?? '') ?>"
                                    data-vendor="<?= htmlspecialchars($vendor ? $vendor->name : '') ?>">
                                <i class="bi bi-send"></i> Wyślij
                            </button>
                        <?php endif; ?>
                        <?php if (!in_array($rfq->state, ['cancelled','converted'], true)): ?>
                            <button class="btn btn-sm btn-danger cancel-rfq-btn"
                                    data-id="<?= $rfq->id ?>"
                                    data-number="<?= htmlspecialchars($rfq->rfqNumber ?? '') ?>">
                                <i class="bi bi-x-circle"></i> Anuluj
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <strong>Numer:</strong>
                            <?= htmlspecialchars($rfq->rfqNumber ?? '—') ?>
                        </div>
                        <div class="col-md-4">
                            <strong>Oczekiwana data:</strong>
                            <?= htmlspecialchars($rfq->expectedReplyDate ?? '—') ?>
                        </div>
                        <div class="col-md-4">
                            <strong>Wysłano:</strong>
                            <?= htmlspecialchars($rfq->sentAt ?? '—') ?>
                        </div>
                    </div>
                    <?php if ($rfq->comment): ?>
                        <hr>
                        <strong>Komentarz:</strong>
                        <div><?= nl2br(htmlspecialchars($rfq->comment)) ?></div>
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
                    <form id="addRfqItemForm">
                        <input type="hidden" id="rfq_item_rfq_id" value="<?= $rfq->id ?>">
                        <div class="row">
                            <div class="col-md-8">
                                <div class="form-group">
                                    <label>Artykuł u dostawcy:</label>
                                    <select id="rfq_item_vendor_part_select" class="selectpicker form-control" data-live-search="true" data-width="100%">
                                        <option value="">Wybierz...</option>
                                        <?php foreach ($vendorParts as $vp): ?>
                                            <option value="<?= $vp->id ?>"
                                                    data-vendor-jm-id="<?= $vp->vendorJmId ?>">
                                                <?= htmlspecialchars(($vp->vendorPartNo ?? '') . ' — ' . ($vp->partName ?? '') . ' (' . ($vp->producerName ?? '') . ')') ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="hidden" id="rfq_item_vendor_part_id">
                                    <input type="hidden" id="rfq_item_quantity_unit_id">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="rfq_item_quantity">Ilość:</label>
                                    <input type="number" step="0.0001" min="0.0001" class="form-control" id="rfq_item_quantity" required>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="rfq_item_unit_price">Cena (opcjonalna):</label>
                                    <input type="number" step="0.0001" min="0" class="form-control" id="rfq_item_unit_price">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="rfq_item_currency">Waluta:</label>
                                    <select class="form-control" id="rfq_item_currency">
                                        <option value="PLN">PLN</option>
                                        <option value="EUR">EUR</option>
                                        <option value="USD">USD</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="rfq_item_comment">Komentarz:</label>
                            <textarea class="form-control" id="rfq_item_comment" rows="2"></textarea>
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
                            <i class="bi bi-info-circle"></i> Brak pozycji w tym zapytaniu.
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
                                    <th>Cena</th>
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
                                        <td class="text-center"><?= $item->unitPrice !== null ? htmlspecialchars(number_format($item->unitPrice, 4, '.', ' ')) : '—' ?></td>
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

<script src="<?= asset('public_html/components/Admin/Purchase/Rfqs/Edit/edit-rfq-view.js') ?>"></script>
