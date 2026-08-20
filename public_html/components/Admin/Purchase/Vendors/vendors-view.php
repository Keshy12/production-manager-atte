<?php
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Master\VendorRepository;

if(!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    header("Location: http://".BASEURL."/");
    exit();
}

$MsaDB = MsaDB::getInstance();
$vendorRepository = new VendorRepository($MsaDB);
$vendors = $vendorRepository->getAll(false);

// Pre-compute counts so the table render is fast
foreach ($vendors as $v) {
    $v->_supplierCount = $vendorRepository->countSuppliers($v->id);
    $v->_vendorPartCount = $vendorRepository->countVendorParts($v->id);
}

include('modals.php');
include('table-row-template.php');
?>

<div class="container-fluid w-75 mt-3">
    <div class="row">
        <div class="col-12 my-2">
            <h2>Dostawcy</h2>
            <div id="alertContainer"></div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h5>Dodaj dostawcę</h5>
                </div>
                <div class="card-body">
                    <form id="addVendorForm">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="vendor_name">Nazwa dostawcy:</label>
                                    <input type="text" class="form-control" id="vendor_name" required>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="vendor_lead_time">Lead time (dni):</label>
                                    <input type="number" min="0" class="form-control" id="vendor_lead_time">
                                </div>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="vendor_address">Adres:</label>
                            <input type="text" class="form-control" id="vendor_address">
                        </div>
                        <div class="form-group">
                            <label for="vendor_additional_data">Dodatkowe dane:</label>
                            <input type="text" class="form-control" id="vendor_additional_data">
                        </div>
                        <div class="form-group">
                            <label for="vendor_comment">Komentarz:</label>
                            <textarea class="form-control" id="vendor_comment" rows="2"></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-plus-circle"></i> Dodaj dostawcę
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
                        <i class="bi bi-list-ul"></i> Lista dostawców
                        <span class="badge badge-info"><?= count($vendors) ?></span>
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (empty($vendors)): ?>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i> Brak dostawców w systemie.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead class="thead-light">
                                <tr>
                                    <th>ID</th>
                                    <th>Nazwa</th>
                                    <th>Adres</th>
                                    <th>Lead&nbsp;Time&nbsp;(dni)</th>
                                    <th>Komentarz</th>
                                    <th>#Dostawców</th>
                                    <th>#Artykułów</th>
                                    <th>Status</th>
                                    <th>Akcje</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($vendors as $v): ?>
                                    <tr class="<?= !$v->isActive ? 'table-secondary' : '' ?>">
                                        <td class="text-center"><?= $v->id ?></td>
                                        <td><?= htmlspecialchars($v->name) ?></td>
                                        <td><small class="text-muted"><?= htmlspecialchars($v->address ?? '') ?></small></td>
                                        <td class="text-center"><?= $v->leadTimeDays !== null ? (int)$v->leadTimeDays : '—' ?></td>
                                        <td><small class="text-muted"><?= htmlspecialchars($v->comment ?? '') ?></small></td>
                                        <td class="text-center"><span class="badge badge-secondary"><?= $v->_supplierCount ?></span></td>
                                        <td class="text-center"><span class="badge badge-secondary"><?= $v->_vendorPartCount ?></span></td>
                                        <td>
                                            <?php if ($v->isActive): ?>
                                                <span class="badge badge-success">Aktywny</span>
                                            <?php else: ?>
                                                <span class="badge badge-danger">Nieaktywny</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="btn-group" role="group">
                                                <button class="btn btn-sm btn-warning edit-vendor-btn"
                                                        data-id="<?= $v->id ?>">
                                                    <i class="bi bi-pencil"></i> Edytuj
                                                </button>
                                                <button class="btn btn-sm btn-info detail-vendor-btn"
                                                        data-id="<?= $v->id ?>"
                                                        data-name="<?= htmlspecialchars($v->name) ?>">
                                                    <i class="bi bi-info-circle"></i> Szczegóły
                                                </button>
                                                <button class="btn btn-sm toggle-vendor-btn <?= $v->isActive ? 'btn-danger' : 'btn-success' ?>"
                                                        data-id="<?= $v->id ?>"
                                                        data-is-active="<?= $v->isActive ? '1' : '0' ?>"
                                                        data-name="<?= htmlspecialchars($v->name) ?>"
                                                        data-has-parts="<?= $v->_vendorPartCount > 0 ? '1' : '0' ?>">
                                                    <i class="bi bi-<?= $v->isActive ? 'x-circle' : 'check-circle' ?>"></i>
                                                    <?= $v->isActive ? 'Wyłącz' : 'Włącz' ?>
                                                </button>
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

<script src="<?= asset('public_html/components/Admin/Purchase/Vendors/vendors-view.js') ?>"></script>
