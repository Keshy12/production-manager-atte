<?php
use Atte\DB\MsaDB;
use Atte\Utils\Locker;

$locker = new Locker("import-orders.lock");
$isLocked = !($locker -> lock(FALSE));
$oldLastCell = (int)$_POST['oldLastCellFound'];
$newLastCell = (int)$_POST['newLastCellFound']; 

if(!$isLocked) {
    $orders = json_decode($_POST['orders'], true);
    try {
        importOrders($orders, $oldLastCell, $newLastCell);
    } catch (\Exception $e) {
        echo $e -> getMessage();
    }
}


function importOrders($orders, $oldLastCell, $newLastCell) {
    $MsaDB = MsaDB::getInstance();
    $MsaDB -> db -> beginTransaction();

    $lastReadCell = (int)$MsaDB -> query("SELECT * FROM `ref__timestamp` WHERE `id` = 3")[0]['params'];
    if(($oldLastCell+1) !== $lastReadCell) {
        $MsaDB -> db -> rollBack();
        throw new \Exception("Dane się zmieniły, proszę odświeżyć stronę.");
        return;
    }

    if($lastReadCell + count($orders) !== $newLastCell) {
        $MsaDB -> db -> rollBack();
        throw new \Exception("Nieprawidłowa ilość zamówień, coś poszło nie tak.");
        return;
    }

    foreach ($orders as $order) {
        $partId = $order['PartId'];
        $quantity = $order['Qty'];
        $comment = $order['GRN_ID'];

        $MsaDB -> insert("inventory__parts", ["parts_id", "user_id", "sub_magazine_id", "quantity", "input_type_id", "comment"], [$partId, '8', '27', $quantity, '7', $comment]);
    }
    
    $MsaDB -> update("ref__timestamp", ["params" => $newLastCell], "id", 3);
    $MsaDB -> db -> commit();
}