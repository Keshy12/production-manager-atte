<?php

use Atte\DB\MsaDB;
use Atte\Utils\NotificationRepository;

$MsaDB = MsaDB::getInstance();
$notificationRepository = new NotificationRepository($MsaDB);
$id = $_GET["id"];
$notification = $notificationRepository -> getNotificationById($id);
$notificationValues = array_values($notification -> notificationValues);
list($id, $timestamp, $actionNeededId, $valueForAction, $isResolved) = $notificationValues;
$queriesAffectedCount = $MsaDB -> query("SELECT COUNT(*) FROM `notification__queries_affected` WHERE notification_id = $id");


$list__sku = $MsaDB -> readIdName("list__sku");

$message = $MsaDB -> query("SELECT description FROM notification__action_needed
                                WHERE id = $actionNeededId", \PDO::FETCH_COLUMN)[0];
$resolved = "nierozwiązane";
$alert = "alert-danger";
if($isResolved) {
    $resolved = "rozwiązane";
    $alert = "alert-success";
}

$userId = $_SESSION["userid"] ?? null;

?>
<script>
const userId = <?= json_encode($userId) ?>;
</script>
<div class="d-flex-column align-items-center justify-content-center mt-4">
    <div class="d-flex align-items-center justify-content-center text-center">
        <div>
            <h1 id="notificationId" data-id="<?=$id?>" class="<?=$alert?>">Powiadomienie o id: <?=$id?> </h1>
            <small><?=$resolved?></small>
        </div>
    </div>
    <hr>
    <div class="d-flex align-items-center justify-content-center mt-2">
        <div class="w-50 text-center">
            <h3>Potrzebna akcja:</h3>
            <?=$message?>
        </div>
    </div>
    <div class="d-flex align-items-center justify-content-center mt-2">
        <div class="w-50 text-center">
            <h3>Wartość potrzebna do akcji:</h3>
            <?=$valueForAction?><br>
            <?php echo $actionNeededId == 1 ? ($list__sku[$valueForAction] ?? "SKU nie znalezione w bazie danych") : "" ; ?>
        </div>
    </div>
    <hr>
    <div class="d-flex align-items-center justify-content-center mt-2">
        <div class="w-50 text-center">
            <h4>Ilość zapytan czekajacych na rozwiazanie:</h4>
            <span id="queriesAffected"><?=$queriesAffectedCount[0][0]?></span><br>
        </div>
    </div>
    <div class="d-flex align-items-center justify-content-center mt-2">
        <div class="w-50 text-center">
            <h4>Ilość rozwiazanych zapytan:</h4>
            <div class="progress">
                <div id="progressBar" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%"></div>
            </div>
            <span id="percentCompleted">0%</span>
        </div>
    </div>

    <div class="d-flex align-items-center justify-content-center mt-4">
        <div class="text-center">
            <button id="tryResolve" class="btn btn-primary" <?= $isResolved ? "disabled" : ""?>>Spróbuj rozwiązać</button>
            <br>
            <div id="spinnerResolve" style="display: none;">
                <div class="spinner-border mt-2"
                     role="status">
                </div>
                <br>
                <b>Rozwiązywanie w toku, proszę NIE zamykać strony.</b>
            </div>
            <br>
            <small>Jeżeli na dole pojawia się niezrozumiały błąd, prześlij go do Marcin Stożek na basecamp.</small>
        </div>
    </div>
    <div class="d-flex align-items-center justify-content-center mt-4">
        <span id="result" class="text-center w-50"></span>
    </div>
</div>

<script src="http://<?=BASEURL?>/public_html/components/notification/notification-view.js"></script>