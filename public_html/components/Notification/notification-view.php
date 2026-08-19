<?php

use Atte\DB\MsaDB;
use Atte\DB\FlowpinDB;
use Atte\Utils\NotificationRepository;

$MsaDB = MsaDB::getInstance();
$notificationRepository = new NotificationRepository($MsaDB);
$id = $_GET["id"];
$notification = $notificationRepository -> getNotificationById($id);
$notificationValues = array_values($notification -> notificationValues);
list($id, $timestamp, $actionNeededId, $valueForAction, $isResolved) = $notificationValues;
$queriesAffectedCount = $MsaDB -> query("SELECT COUNT(*) FROM `notification__queries_affected` WHERE notification_id = $id");


$list__sku = $MsaDB -> readIdName("list__sku");

// Fallback: If this is a BOM notification (actionNeededId == 1) and SKU is not found locally,
// fetch from FlowPin and display/insert it
if ($actionNeededId == 1 && !isset($list__sku[$valueForAction])) {
    try {
        $FlowpinDB = FlowpinDB::getInstance();
        $flowpinSku = $FlowpinDB->query("SELECT Symbol, Description FROM ProductTypes WHERE Id = " . (int)$valueForAction . " AND CompanyId = 1");
        if (!empty($flowpinSku)) {
            $skuName = $flowpinSku[0]["Symbol"];
            $skuDescription = $flowpinSku[0]["Description"];
            // Insert into list__sku so it's available next time
            $MsaDB->insert("list__sku", ["id", "name", "description", "isActive"], [(int)$valueForAction, $skuName, $skuDescription, 1]);
            // Add to the local array for display
            $list__sku[$valueForAction] = $skuName;
        }
    } catch (\Throwable $e) {
        // Log error but don't break the page - will display "not found" message
        error_log("Failed to fetch SKU {$valueForAction} from FlowPin: " . $e->getMessage());
    }
}

$message = $MsaDB -> query("SELECT description FROM notification__action_needed
                                WHERE id = $actionNeededId", \PDO::FETCH_COLUMN)[0];

// Fetch SKU name discrepancy context if this is action_needed_id == 6
$discrepancyContext = null;
$firstAffectedRow = null;
$skuDiscrepancyEventCount = 0;
if ($actionNeededId == 6) {
    $firstAffectedRow = $MsaDB->query(
        "SELECT id, values_to_resolve, exception_values_serialized, flowpin_query_type_id
         FROM `notification__queries_affected`
         WHERE notification_id = $id
         ORDER BY id ASC LIMIT 1",
        \PDO::FETCH_ASSOC
    );
    if (!empty($firstAffectedRow)) {
        $contextJson = $firstAffectedRow[0]['exception_values_serialized'] ?? '';
        $decoded = json_decode($contextJson, true);
        if (is_array($decoded)) {
            $discrepancyContext = $decoded;
        }
    }
    $countRow = $MsaDB->query(
        "SELECT COUNT(*) AS c FROM `notification__queries_affected` WHERE notification_id = $id"
    );
    $skuDiscrepancyEventCount = isset($countRow[0]) ? (int)$countRow[0][0] : 0;
}

$resolved = "nierozwiązane";
$alert = "alert-danger";
if($isResolved) {
    $resolved = "rozwiązane";
    $alert = "alert-success";
}

$userId = $_SESSION['user_id'] ?? null;

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

    <?php if ($actionNeededId == 6): ?>
    <div class="d-flex align-items-center justify-content-center mt-4">
        <div class="w-75">
            <div class="card">
                <div class="card-body py-3">
                    <div class="text-muted small mb-2">
                        <span class="text-danger mr-1">●</span>
                        Rozbieżność w nazwie urządzenia
                        <?php if ($discrepancyContext !== null): ?>
                            · SKU #<?= htmlspecialchars($discrepancyContext['device_id'] ?? '?', ENT_QUOTES, 'UTF-8') ?>
                        <?php endif; ?>
                        · <?= $skuDiscrepancyEventCount ?> zdarzeń
                    </div>
                    <?php if ($discrepancyContext !== null): ?>
                        <div class="row">
                            <div class="col-md-6">
                                <span class="text-muted small mr-1">MSA:</span>
                                <span class="text-danger text-break sku-value"
                                      title="<?= htmlspecialchars($discrepancyContext['msa_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars($discrepancyContext['msa_name'] ?? '(brak)', ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </div>
                            <div class="col-md-6">
                                <span class="text-muted small mr-1">FlowPin:</span>
                                <span class="text-success text-break sku-value"
                                      title="<?= htmlspecialchars($discrepancyContext['flowpin_symbol'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars($discrepancyContext['flowpin_symbol'] ?? '(brak)', ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="text-muted small">
                            <em>Brak danych o rozbieżności.</em>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

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

<script src="<?= asset('public_html/components/notification/notification-view.js') ?>"></script>
