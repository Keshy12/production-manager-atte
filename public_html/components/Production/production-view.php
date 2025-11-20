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

<script src="http://<?=BASEURL?>/public_html/components/production/production-view.js?deviceType=<?=$deviceType?>"></script>