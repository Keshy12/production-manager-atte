<?php
use Atte\DB\MsaDB;
use Atte\Utils\{UserRepository, BomRepository};
use Atte\Utils\ComponentRenderer\SelectRenderer;

$MsaDB = MsaDB::getInstance();
$userRepository = new UserRepository($MsaDB);
$selectRenderer = new SelectRenderer($MsaDB);

// Determine device type from URL, GET, or POST (for redirects)
$deviceType = $_GET['type'] ?? $_POST['device_type'] ?? 'smd';
if (!in_array($deviceType, ['smd', 'tht'])) {
    $deviceType = 'smd';
}

$bomValues = "";
//If redirected from index
if (isset($_GET['redirect']) && $_GET['redirect'] === 'true' && isset($_POST['device_id'])) {
    $bomRepository = new BomRepository($MsaDB);
    $bom = $bomRepository->getBomById($deviceType, $_POST["device_id"]);
    if ($deviceType === 'smd') {
        $bomValues = [$bom->deviceId, $bom->laminateId, $bom->version];
    } else {
        $bomValues = [$bom->deviceId, $bom->version];
    }
    $headerDir = ROOT_DIRECTORY.'/public_html/assets/layout/header.php';
    includeWithVariables($headerDir, array('title' => 'Produkcja ' . strtoupper($deviceType)));
    echo("<script>history.replaceState({},'','/atte_ms_new/production/{$deviceType}');</script>");
}

$userId = $_SESSION["userid"];
$user = $userRepository->getUserById($userId);
$userInfo = $user->getUserInfo();

$usedDevices = $user->getDevicesUsed($deviceType);

$isSMD = ($deviceType === 'smd');
$pageTitle = $isSMD ? 'Produkcja SMD' : 'Produkcja THT';
?>

<div id="correctionModal" class="modal fade" role="dialog">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h4 class="modal-title">Korekta</h4>
                <button type="button" class="change close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                Wpisałeś ujemną ilość. Aby utworzyć korektę, musisz wpisać komentarz.
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Zamknij</button>
            </div>
        </div>
    </div>
</div>

<h1 class="text-center mt-4"><?=$pageTitle?></h1>
<h4 id="raportAs" class="text-center font-weight-light">Raport jako: <?=$userInfo["login"]?></h4>

<?php if (!$isSMD): ?>
    <div>
        <div class="mx-auto d-block">
            <div id="marking" style="width:500px; max-width:100%;" class="d-flex mx-auto">
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="container mt-5">
    <form id="form" method="post" action="http://<?=BASEURL?>/public_html/components/production/production-handler.php">
        <input type="hidden" name="device_type" value="<?=$deviceType?>">
        <div class="form-group">
            <input type="hidden" name="user_id" value="<?=$userId?>">
            <label for="list__device">Wybierz urządzenie</label>
            <div class="d-flex">
                <select class="form-control selectpicker" title="Wybierz urządzenie..." name="device_id" id="list__device" data-auto-select='<?=json_encode($bomValues)?>' data-live-search="true" data-width="<?=$isSMD ? '80%' : '90%'?>" required>
                    <?php
                    if ($isSMD) {
                        $selectRenderer->renderSMDBOMSelect($usedDevices);
                    } else {
                        $selectRenderer->renderTHTBOMSelect($usedDevices);
                    }
                    ?>
                </select>
                <?php if ($isSMD): ?>
                    <select class="form-control selectpicker" title="Lam..." name="laminate" id="laminate" data-width="10%" style="width: 10%;" required></select>
                <?php endif; ?>
                <select class="form-control selectpicker" title="Ver.." name="version" id="version" data-width="10%" style="width: 10%;" required></select>
            </div>
        </div>
        <div class="form-group">
            <label for="device_description">Opis urządzenia:</label>
            <input type="text" style="padding-left: 0.75rem;" readonly class="form-control-plaintext" id="device_description">
        </div>
        <div class="form-group">
            <label for="quantity">Ilość:</label>
            <input type="number" class="form-control" id="quantity" name="qty" placeholder="Wpisz ilość" required>
        </div>
        <div class="form-group">
            <label for="comment">Komentarz (opcjonalnie)</label>
            <textarea class="form-control" name="comment" id="comment" rows="3"></textarea>
        </div>
        <div class="form-group">
            <label for="date">Data produkcji (jeśli inna niż dziś)</label>
            <input class="form-control" type="date" name="prod_date" id="date">
        </div>
        <div class="d-flex align-items-center justify-content-center">
            <button id="send" class="btn btn-primary" type="submit">Wyślij</button>
        </div>
    </form>
</div>

<div class="d-flex align-items-center justify-content-center mt-2">
    <div id="alerts" class="w-50"></div>
</div>

<div id="lastProduction" data-last-id="" class="d-flex align-items-center justify-content-center">
</div>

<!-- Rollback Confirmation Modal -->
<div class="modal fade" id="rollbackProductionModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Cofnij produkcję</h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle"></i>
                    <strong>Uwaga!</strong> Czy na pewno chcesz cofnąć zaznaczone transfery?
                    <br>Tej operacji nie można cofnąć.
                </div>

                <!-- Group rollback warning -->
                <div id="groupRollbackWarning" class="alert alert-info" style="display: none;">
                    <h6><i class="bi bi-info-circle"></i> Cofanie całej akcji produkcji</h6>
                    <div id="groupRollbackDetails"></div>
                    <p class="mb-0"><strong>Zostaną cofnięte wszystkie transfery</strong> (w tym zużyte komponenty) z zaznaczonych grup.</p>
                    Dla większej kontroli nad cofaniem transferów, spróbuj użyć zakładki "Archiwum".
                </div>

                <h6 class="mb-3">Podsumowanie cofnięcia:</h6>

                <div class="mb-3">
                    <strong>Liczba transferów do cofnięcia:</strong>
                    <span class="badge badge-danger badge-pill ml-2" id="rollbackCount">0</span>
                </div>

                <!-- Collapsible group structure container -->
                <div id="groupedRollbackView" style="display: none;">
                    <!-- Will be populated with grouped structure via JavaScript -->
                </div>

                <!-- Traditional flat table view -->
                <div id="flatRollbackView" class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                    <table class="table table-sm table-bordered">
                        <thead class="thead-light" style="position: sticky; top: 0; z-index: 1;">
                            <tr>
                                <th>Użytkownik</th>
                                <th>Urządzenie</th>
                                <th>Typ operacji</th>
                                <th>Ilość</th>
                                <th>Komentarz</th>
                                <th>Data</th>
                            </tr>
                        </thead>
                        <tbody id="rollbackSummaryBody">
                            <!-- Will be populated via JavaScript -->
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Anuluj</button>
                <button type="button" id="confirmRollback" class="btn btn-danger">
                    <i class="bi bi-arrow-counterclockwise"></i> Potwierdź cofnięcie
                </button>
            </div>
        </div>
    </div>
</div>

<script src="http://<?=BASEURL?>/public_html/components/production/production-view.js?deviceType=<?=$deviceType?>"></script>