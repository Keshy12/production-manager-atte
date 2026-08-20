<?php
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Master\VendorPartRepository;

if(!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    header("Location: http://".BASEURL."/");
    exit();
}

$MsaDB = MsaDB::getInstance();
$vendorPartRepository = new VendorPartRepository($MsaDB);
$vendorParts = $vendorPartRepository->getAll(false);

include('modals.php');
include('table-row-template.php');
?>

<div class="container-fluid w-75 mt-3">
    <div class="row">
        <div class="col-12 my-2">
            <h2>Artykuły u dostawców</h2>
            <div id="alertContainer"></div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h5>Dodaj artykuł u dostawcy</h5>
                </div>
                <div class="card-body">
                    <form id="addVpForm">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Dostawca:</label>
                                    <select id="add_vendor_select" class="selectpicker form-control" data-live-search="true" data-width="100%">
                                        <option value="">Wybierz dostawcę...</option>
                                    </select>
                                    <input type="hidden" id="add_vendor_id" name="vendor_id">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Producent:</label>
                                    <select id="add_producer_select" class="selectpicker form-control" data-live-search="true" data-width="100%">
                                        <option value="">Wybierz producenta...</option>
                                    </select>
                                    <input type="hidden" id="add_producer_id" name="producer_id">
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-8">
                                <div class="form-group">
                                    <label>Part (nasz katalog):</label>
                                    <select id="add_part_select" class="selectpicker form-control" data-live-search="true" data-width="100%">
                                        <option value="">Wybierz part...</option>
                                    </select>
                                    <input type="hidden" id="add_part_id" name="parts_id">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>JM:</label>
                                    <select id="add_unit_select" class="selectpicker form-control" data-live-search="true" data-width="100%">
                                        <option value="">Wybierz jednostkę...</option>
                                    </select>
                                    <input type="hidden" id="add_unit_id" name="vendor_jm_id">
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-7">
                                <div class="form-group">
                                    <label for="add_vendor_part_no">Numer części u dostawcy:</label>
                                    <input type="text" class="form-control" id="add_vendor_part_no" required>
                                </div>
                            </div>
                            <div class="col-md-5">
                                <div class="form-group">
                                    <label for="add_full_pack_quantity">Pełne opakowanie:</label>
                                    <input type="number" min="0.0001" step="0.0001" class="form-control" id="add_full_pack_quantity" value="1">
                                </div>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="add_comment">Komentarz:</label>
                            <textarea class="form-control" id="add_comment" rows="2"></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-plus-circle"></i> Dodaj artykuł
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
                        <i class="bi bi-list-ul"></i> Lista artykułów u dostawców
                        <span class="badge badge-info"><?= count($vendorParts) ?></span>
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (empty($vendorParts)): ?>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i> Brak artykułów u dostawców.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead class="thead-light">
                                <tr>
                                    <th>ID</th>
                                    <th>Vendor</th>
                                    <th>Producer</th>
                                    <th>PartNo</th>
                                    <th>JM</th>
                                    <th>Full&nbsp;Pack</th>
                                    <th>Status</th>
                                    <th>Akcje</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($vendorParts as $vp): ?>
                                    <tr class="<?= !$vp->isActive ? 'table-secondary' : '' ?>">
                                        <td class="text-center"><?= $vp->id ?></td>
                                        <td><?= htmlspecialchars($vp->vendorName ?? '—') ?></td>
                                        <td><?= htmlspecialchars($vp->producerName ?? '—') ?></td>
                                        <td>
                                            <?= htmlspecialchars($vp->partName ?? '—') ?>
                                            <br><small class="text-muted"><?= htmlspecialchars($vp->vendorPartNo ?? '') ?></small>
                                        </td>
                                        <td><?= htmlspecialchars($vp->unitName ?? '—') ?></td>
                                        <td class="text-center"><?= htmlspecialchars((string)$vp->fullPackQuantity) ?></td>
                                        <td>
                                            <?php if ($vp->isActive): ?>
                                                <span class="badge badge-success">Aktywny</span>
                                            <?php else: ?>
                                                <span class="badge badge-danger">Nieaktywny</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="btn-group" role="group">
                                                <button class="btn btn-sm btn-warning edit-vp-btn"
                                                        data-id="<?= $vp->id ?>">
                                                    <i class="bi bi-pencil"></i> Edytuj
                                                </button>
                                                <button class="btn btn-sm toggle-vp-btn <?= $vp->isActive ? 'btn-danger' : 'btn-success' ?>"
                                                        data-id="<?= $vp->id ?>"
                                                        data-is-active="<?= $vp->isActive ? '1' : '0' ?>">
                                                    <i class="bi bi-<?= $vp->isActive ? 'x-circle' : 'check-circle' ?>"></i>
                                                    <?= $vp->isActive ? 'Wyłącz' : 'Włącz' ?>
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

<script src="<?= asset('public_html/components/Admin/Purchase/VendorParts/vendor-parts-view.js') ?>"></script>
