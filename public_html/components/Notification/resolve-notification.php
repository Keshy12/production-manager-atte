<?php

use Atte\Utils\Locker;
use Atte\DB\MsaDB;
use Atte\DB\FlowpinDB;
use Atte\Utils\NotificationRepository;

set_time_limit(0);

$id = $_POST["id"];
$userId = $_POST["userId"] ?? null;
if (!$userId) {
    echo "Musisz być zalogowany, aby rozwiązać powiadomienie.";
    exit;
}
$locker = new Locker("notification-locks/notification".$id.".lock");
$isLocked = !($locker -> lock(FALSE));
$isResolvable = true;

if($isLocked) {
    $isResolvable = false;
    $result = 'Ktoś inny jest w trakcie rozwiązywania tego powiadomienia. Spróbuj ponownie później.';
} else {
    try{
        $MsaDB = MsaDB::getInstance();
        $notificationRepository = new NotificationRepository($MsaDB);
        $unresolvedNotification = $notificationRepository -> getNotificationById($id);
        $actionNeededId = $unresolvedNotification -> notificationValues["action_needed_id"];
        $valueForAction = $unresolvedNotification -> notificationValues["value_for_action"];
        switch($actionNeededId) {
            case 1:
                // First ensure SKU exists - fetch from FlowPin if missing
                $skuExists = $MsaDB -> query("SELECT id FROM list__sku WHERE id = " . (int)$valueForAction, PDO::FETCH_COLUMN);
                if (empty($skuExists)) {
                    try {
                        $FlowpinDB = FlowpinDB::getInstance();
                        $flowpinSku = $FlowpinDB->query("SELECT Symbol, Description FROM ProductTypes WHERE Id = " . (int)$valueForAction);
                        if (!empty($flowpinSku)) {
                            $skuName = $flowpinSku[0]["Symbol"];
                            $skuDescription = $flowpinSku[0]["Description"];
                            $MsaDB->insert("list__sku", ["id", "name", "description", "isActive"], [(int)$valueForAction, $skuName, $skuDescription, 1]);
                        }
                    } catch (\Throwable $e) {
                        // Continue with BOM check even if FlowPin fetch fails
                        error_log("Failed to fetch SKU {$valueForAction} from FlowPin during resolve: " . $e->getMessage());
                    }
                }
                $checkIfResolvable = $MsaDB -> query("SELECT isActive FROM `bom__sku` where sku_id = $valueForAction");
                if(!isset($checkIfResolvable[0][0]) || $checkIfResolvable[0][0] == 0) $isResolvable = false;
                $result = 'BOM nie został zweryfikowany przez administratora. 
                Zweryfikuj poprawność BOMu w edycji, a następnie zaznacz opcję "Aktywny?".';
                break;
            case 2:
                $checkIfResolvable = $MsaDB -> query("SELECT user_id FROM `user` WHERE `email` = '$valueForAction'");
                if(empty($checkIfResolvable)) $isResolvable = false;
                $result = 'Nie znaleziono użytkownika o podanym adresie e-mail. 
                Stwórz nowego użytkownika lub przypisz adres e-mail do już istniejącego.';
                break;

            default:
                break;
        }
        if($isResolvable) {
            if($unresolvedNotification -> tryToResolveNotification($userId)){
                $result = "Pomyślnie rozwiązano powiadomienie.";
            } else {
                $result = "Niestety powiadomienia nie udało się rozwiązać. Sprawdź jeszcze raz, czy aby na pewno
                wszystkie akcje wymagane do rozwiązania problemu zostały zrobione. Jeśli problem nie ustępuje,
                skontaktuj się z administratorem.";
            }
        }
    } catch(\Throwable $e) {
        $result = print_r($e, true);
    }
}

$locker -> unlock();

unlink(realpath(dirname(__FILE__, 4))."/public_html/var/locks/notification-locks/notification".$id.".lock");
echo $result;