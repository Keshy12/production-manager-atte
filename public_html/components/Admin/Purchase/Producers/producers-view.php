<?php
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Master\ProducerRepository;

if(!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    header("Location: http://".BASEURL."/");
    exit();
}

$MsaDB = MsaDB::getInstance();
$producerRepository = new ProducerRepository($MsaDB);
$producers = $producerRepository->getAll(false);

include('modals.php');
include('table-row-template.php');
?>

<div class="container-fluid w-75 mt-3">
    <div class="row">
        <div class="col-12 my-2">
            <h2>Producenci</h2>
            <div id="alertContainer"></div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h5>Dodaj producenta</h5>
                </div>
                <div class="card-body">
                    <form id="addProducerForm">
                        <div class="form-group">
                            <label for="producer_name">Nazwa producenta:</label>
                            <input type="text" class="form-control" id="producer_name" required>
                        </div>
                        <div class="form-group">
                            <label for="producer_comment">Komentarz:</label>
                            <textarea class="form-control" id="producer_comment" rows="2"></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-plus-circle"></i> Dodaj producenta
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
                        <i class="bi bi-list-ul"></i> Lista producentów
                        <span class="badge badge-info"><?= count($producers) ?></span>
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (empty($producers)): ?>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i> Brak producentów w systemie.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead class="thead-light">
                                <tr>
                                    <th>ID</th>
                                    <th>Nazwa</th>
                                    <th>Komentarz</th>
                                    <th>Status</th>
                                    <th>Akcje</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($producers as $p): ?>
                                    <tr class="<?= !$p->isActive ? 'table-secondary' : '' ?>">
                                        <td class="text-center"><?= $p->id ?></td>
                                        <td><?= htmlspecialchars($p->name) ?></td>
                                        <td><small class="text-muted"><?= htmlspecialchars($p->comment ?? '') ?></small></td>
                                        <td>
                                            <?php if ($p->isActive): ?>
                                                <span class="badge badge-success">Aktywny</span>
                                            <?php else: ?>
                                                <span class="badge badge-danger">Nieaktywny</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="btn-group" role="group">
                                                <button class="btn btn-sm btn-warning edit-producer-btn"
                                                        data-id="<?= $p->id ?>"
                                                        data-name="<?= htmlspecialchars($p->name) ?>"
                                                        data-comment="<?= htmlspecialchars($p->comment ?? '') ?>">
                                                    <i class="bi bi-pencil"></i> Edytuj
                                                </button>
                                                <button class="btn btn-sm toggle-producer-btn <?= $p->isActive ? 'btn-danger' : 'btn-success' ?>"
                                                        data-id="<?= $p->id ?>"
                                                        data-is-active="<?= $p->isActive ? '1' : '0' ?>"
                                                        data-name="<?= htmlspecialchars($p->name) ?>">
                                                    <i class="bi bi-<?= $p->isActive ? 'x-circle' : 'check-circle' ?>"></i>
                                                    <?= $p->isActive ? 'Wyłącz' : 'Włącz' ?>
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

<script src="<?= asset('public_html/components/Admin/Purchase/Producers/producers-view.js') ?>"></script>
