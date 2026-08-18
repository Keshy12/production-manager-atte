<?php
use Atte\DB\MsaDB;
use Atte\Utils\CommissionRepository;

header('Content-Type: application/json');
$MsaDB = MsaDB::getInstance();
$MsaDB -> db -> beginTransaction();
$wasSuccessful = true;
$errorMessage = "";

try {
    if (!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
        throw new \Exception('Brak uprawnień');
    }

    $commissionRepository = new CommissionRepository($MsaDB);
    $commission = $commissionRepository -> getCommissionById($_POST["id"]);

    $quantity = isset($_POST['quantity']) ? (int)$_POST['quantity'] : 0;
    if ($quantity <= 0) {
        throw new \Exception('Ilość musi być liczbą dodatnią');
    }
    if ($quantity < $commission->commissionValues['qty_produced']) {
        throw new \Exception('Ilość nie może być mniejsza niż aktualnie wyprodukowana ilość (' . $commission->commissionValues['qty_produced'] . ')');
    }
    $state = $commission->commissionValues['state'];
    $isCancelled = $commission->commissionValues['is_cancelled'];
    if ($state === 'returned' || $state === 'cancelled' || $isCancelled == 1) {
        throw new \Exception('Nie można edytować ilości dla zlecenia w stanie: ' . $state);
    }

    $commission -> updatePriority($_POST["priority"]);

    // Normalize receivers to array — JS may send a comma-separated string ("1,2,3")
    $receiversRaw = $_POST["receivers"] ?? '';
    if (is_string($receiversRaw)) {
        $receiversArray = array_values(array_filter(
            array_map('trim', explode(',', $receiversRaw)),
            fn($r) => $r !== ''
        ));
    } elseif (is_array($receiversRaw)) {
        $receiversArray = array_values(array_filter($receiversRaw, fn($r) => $r !== '' && $r !== null));
    } else {
        $receiversArray = [];
    }
    if (empty($receiversArray)) {
        throw new \Exception('Zlecenie musi mieć co najmniej jednego zleceniobiorcę.');
    }
    $commission -> updateReceivers($receiversArray);

    $commission -> updateQuantity($quantity);

    $MsaDB -> db -> commit();
}
catch (\Throwable $e) {
    $MsaDB -> db -> rollBack();
    $wasSuccessful = false;
    $errorMessage = "ERROR! Error message:".$e -> getMessage();
}

echo json_encode([$wasSuccessful, $errorMessage]
                , JSON_FORCE_OBJECT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
exit;