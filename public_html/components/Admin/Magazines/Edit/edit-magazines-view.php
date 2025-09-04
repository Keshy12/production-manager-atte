<?php
use Atte\DB\MsaDB;
use Atte\Utils\MagazineRepository;
use Atte\Utils\UserRepository;

// Check if user is admin
if(!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] != true) {
    header("Location: http://".BASEURL."/");
    exit();
}

$db = MsaDB::getInstance();
$magazineRepository = new MagazineRepository($db);
$userRepository = new UserRepository($db);

// Get data using repositories
$magazines = $magazineRepository->getAllMagazines(onlyIsActive: false);
$magazine_types = $magazineRepository->getMagazineTypes();
$all_users = $userRepository->getAllUsers();

include('modals.php');

?>

<div class="container-fluid w-75 mt-3">
    <div class="row">
        <div class="col-12 my-2">
            <h2>Edycja Magazynów</h2>
            <div id="alertContainer"></div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h5>Dodaj Nowy Magazyn</h5>
                </div>
                <div class="card-body">
                    <form id="addMagazineForm">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="sub_magazine_name">Nazwa Magazynu:</label>
                                    <input type="text" class="form-control" id="sub_magazine_name" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="type_id">Typ Magazynu:</label>
                                    <select class="form-control" id="type_id" required>
                                        <option value="">Wybierz typ...</option>
                                        <?php foreach ($magazine_types as $id => $name): ?>
                                            <option value="<?= $id ?>"><?= htmlspecialchars($name) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-plus-circle"></i> Dodaj Magazyn
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
                        <i class="bi bi-list-ul"></i> Lista Magazynów
                        <span class="badge badge-info"><?= count($magazines) ?></span>
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (empty($magazines)): ?>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i> Brak magazynów w systemie.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead class="thead-light">
                                <tr>
                                    <th>ID</th>
                                    <th>Nazwa Magazynu</th>
                                    <th>Status</th>
                                    <th>Przypisani Użytkownicy</th>
                                    <th>Akcje</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($magazines as $magazine): ?>
                                    <?php
                                    $assignedUsers = $magazineRepository->getUsersAssignedToMagazine($magazine['sub_magazine_id']);
                                    $hasUsers = count($assignedUsers) > 0;
                                    $isActive = !isset($magazine['isActive']) || $magazine['isActive'];
                                    ?>
                                    <tr class="<?= !$isActive ? 'table-secondary' : '' ?>">
                                        <td class="text-center">
                                            <?= htmlspecialchars($magazine['sub_magazine_id']) ?>
                                            <br>
                                            <?php if ($magazine['type_id'] == 1): ?>
                                                <span class="badge badge-primary ml-1">Główny</span>
                                            <?php else: ?>
                                                <span class="badge badge-secondary ml-1">Zewnętrzny</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars($magazine['sub_magazine_name']) ?>
                                        </td>
                                        <td>
                                            <?php if (!$hasUsers): ?>
                                                <span class="badge badge-success">Pusty</span>
                                            <?php endif; ?>
                                            <?php if (!$isActive): ?>
                                                <span class="badge badge-danger">Nieaktywny</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($hasUsers): ?>
                                                <div class="d-flex flex-wrap">
                                                    <?php foreach ($assignedUsers as $index => $user): ?>
                                                        <small class="text-muted mr-2 mb-1">
                                                            <?=htmlspecialchars($user->name . ' ' . $user->surname)?>
                                                            <?php if ($index < count($assignedUsers) - 1): ?>
                                                                ,
                                                            <?php endif; ?>
                                                        </small>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="btn-group" role="group">
                                                <button class="btn btn-sm btn-warning edit-magazine-btn"
                                                        data-id="<?= $magazine['sub_magazine_id'] ?>"
                                                        data-name="<?= htmlspecialchars($magazine['sub_magazine_name']) ?>"
                                                        data-type-id="<?= $magazine['type_id'] ?>">
                                                    <i class="bi bi-pencil"></i> Edytuj
                                                </button>
                                                <?php if ($isActive): ?>
                                                    <?php if ($magazine['type_id'] != 1): // Only show disable button for non-type-1 magazines ?>
                                                        <button class="btn btn-sm btn-danger toggle-magazine-btn"
                                                                data-id="<?= $magazine['sub_magazine_id'] ?>"
                                                                data-name="<?= htmlspecialchars($magazine['sub_magazine_name']) ?>"
                                                                data-action="disable"
                                                                data-has-users="<?= $hasUsers ? 'true' : 'false' ?>">
                                                            <i class="bi bi-x-circle"></i> Wyłącz
                                                        </button>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <button class="btn btn-sm btn-success toggle-magazine-btn"
                                                            data-id="<?= $magazine['sub_magazine_id'] ?>"
                                                            data-name="<?= htmlspecialchars($magazine['sub_magazine_name']) ?>"
                                                            data-action="enable">
                                                        <i class="bi bi-check-circle"></i> Włącz
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

<script src="http://<?=BASEURL?>/public_html/components/admin/magazines/edit/edit-magazines-view.js"></script>