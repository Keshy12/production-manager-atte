<?php
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Master\VendorRepository;
use Atte\Utils\Purchase\Order\RFQRepository;
use Atte\Utils\Purchase\Order\RFQItemRepository;

if(!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    header("Location: http://".BASEURL."/");
    exit();
}

$MsaDB = MsaDB::getInstance();
$vendorRepository  = new VendorRepository($MsaDB);
$rfqRepository     = new RFQRepository($MsaDB);
$rfqItemRepository = new RFQItemRepository($MsaDB);

$vendors = $vendorRepository->getAll(true);   // active only
$rfqs    = $rfqRepository->getAll(false);      // include terminal states

// Pre-compute item counts per RFQ.
foreach ($rfqs as $rfq) {
    $rfq->_itemCount = $rfqItemRepository->countByRfq($rfq->id);
}

$stateLabels = [
    'draft'      => ['Szkic',         'badge-secondary'],
    'sent'       => ['Wysłane',       'badge-primary'],
    'responded'  => ['Odpowiedź',     'badge-success'],
    'cancelled'  => ['Anulowane',     'badge-danger'],
    'converted'  => ['Skonwertowane', 'badge-info'],
];

include('modals.php');
include('table-row-template.php');
?>

<div class="container-fluid w-75 mt-3">
    <div class="row">
        <div class="col-12 my-2">
            <h2>Zapytania ofertowe</h2>
            <div id="alertContainer"></div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h5>Nowe zapytanie</h5>
                </div>
                <div class="card-body">
                    <form id="addRfqForm">
                        <div class="row">
                            <div class="col-md-7">
                                <div class="form-group">
                                    <label for="rfq_vendor_id">Dostawca:</label>
                                    <select id="rfq_vendor_id" class="selectpicker form-control" data-live-search="true" data-width="100%" required>
                                        <option value="">Wybierz dostawcę...</option>
                                        <?php foreach ($vendors as $v): ?>
                                            <option value="<?= $v->id ?>"><?= htmlspecialchars($v->name) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-5">
                                <div class="form-group">
                                    <label for="rfq_expected_reply_date">Oczekiwana data odpowiedzi:</label>
                                    <input type="date" class="form-control" id="rfq_expected_reply_date">
                                </div>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="rfq_comment">Komentarz:</label>
                            <textarea class="form-control" id="rfq_comment" rows="2"></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-plus-circle"></i> Dodaj zapytanie
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
                        <i class="bi bi-list-ul"></i> Lista zapytań
                        <span class="badge badge-info"><?= count($rfqs) ?></span>
                    </h5>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <input type="text" id="rfqFilter" class="form-control" placeholder="Filtruj (numer, dostawca, stan...)">
                    </div>
                    <?php if (empty($rfqs)): ?>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i> Brak zapytań ofertowych w systemie.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-striped table-hover" id="rfqsTable">
                                <thead class="thead-light">
                                <tr>
                                    <th>ID</th>
                                    <th>Numer</th>
                                    <th>Vendor</th>
                                    <th>#Pozycji</th>
                                    <th>Stan</th>
                                    <th>Data utworzenia</th>
                                    <th>Akcje</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($rfqs as $rfq):
                                    $stateInfo = $stateLabels[$rfq->state] ?? [$rfq->state, 'badge-secondary'];
                                    $canSend   = $rfq->state === 'draft';
                                    $canCancel = in_array($rfq->state, ['draft','sent','responded'], true);
                                ?>
                                    <tr>
                                        <td class="text-center"><?= $rfq->id ?></td>
                                        <td><?= htmlspecialchars($rfq->rfqNumber ?? '—') ?></td>
                                        <td><?= htmlspecialchars($rfq->vendorName ?? '—') ?></td>
                                        <td class="text-center"><span class="badge badge-secondary"><?= $rfq->_itemCount ?></span></td>
                                        <td><span class="badge <?= $stateInfo[1] ?>"><?= htmlspecialchars($stateInfo[0]) ?></span></td>
                                        <td><small class="text-muted"><?= htmlspecialchars($rfq->createdAt ?? '') ?></small></td>
                                        <td>
                                            <div class="btn-group" role="group">
                                                <a class="btn btn-sm btn-warning"
                                                   href="http://<?= BASEURL ?>/admin/purchase/rfqs/edit?id=<?= $rfq->id ?>">
                                                    <i class="bi bi-pencil"></i> Edytuj
                                                </a>
                                                <?php if ($canSend): ?>
                                                    <button class="btn btn-sm btn-primary send-rfq-btn"
                                                            data-id="<?= $rfq->id ?>"
                                                            data-number="<?= htmlspecialchars($rfq->rfqNumber ?? '') ?>"
                                                            data-vendor="<?= htmlspecialchars($rfq->vendorName ?? '') ?>">
                                                        <i class="bi bi-send"></i> Wyślij
                                                    </button>
                                                <?php endif; ?>
                                                <?php if ($canCancel): ?>
                                                    <button class="btn btn-sm btn-danger cancel-rfq-btn"
                                                            data-id="<?= $rfq->id ?>"
                                                            data-number="<?= htmlspecialchars($rfq->rfqNumber ?? '') ?>">
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

<script src="<?= asset('public_html/components/Admin/Purchase/Rfqs/rfqs-view.js') ?>"></script>
