<?php
use Atte\DB\MsaDB;
use Atte\Utils\CommissionRepository;
use Atte\Utils\BomRepository;

$MsaDB = MsaDB::getInstance();
$MsaDB->db->beginTransaction();
$wasSuccessful = true;
$errorMessage = "";

try {
    $commissionRepository = new CommissionRepository($MsaDB);
    $bomRepository = new BomRepository($MsaDB);

    $commission = $commissionRepository->getCommissionById($_POST["id"]);
    $rollbackOption = $_POST["rollbackOption"] ?? 'none';
    $unreturnedOption = $_POST["unreturnedOption"] ?? 'keep';

    // Cancel the commission
    $commission->cancel();

    // Handle rollback/delete based on selected option
    if ($rollbackOption !== 'none') {
        $commissionId = $_POST["id"];
        $commissionValues = $commission->commissionValues;
        $magazineFromId = $commissionValues['magazine_from'];
        $magazineToId = $commissionValues['magazine_to'];

        // Get BOM components to calculate remaining quantities
        $deviceType = $commission->deviceType;
        $bomId = $commissionValues["bom_{$deviceType}_id"];
        $deviceBom = $bomRepository->getBomById($deviceType, $bomId);
        $bomComponents = $deviceBom->getComponents(1);

        $inventoryTypes = ['parts', 'sku', 'tht', 'smd'];

        foreach ($inventoryTypes as $type) {
            // Get transferred items for this type (input_type_id = 2)
            $transferredItems = $MsaDB->query("
                SELECT id, quantity, user_id, production_date, comment, {$type}_id, timestamp
                FROM inventory__{$type} 
                WHERE commission_id = $commissionId 
                AND input_type_id = 2
                AND quantity > 0
                ORDER BY timestamp ASC
            ");

            // Calculate remaining quantities and process items
            foreach ($transferredItems as $item) {
                $itemId = $item[$type . '_id'];
                $originalQuantity = $item['quantity'];
                $usedQuantity = 0;

                // Find matching BOM component to calculate used quantity
                foreach ($bomComponents as $bomComponent) {
                    if ($bomComponent['type'] === $type && $bomComponent['componentId'] == $itemId) {
                        $usedQuantity = $bomComponent['quantity'] * $commissionValues['quantity_produced'];
                        break;
                    }
                }

                $remainingQuantity = max(0, $originalQuantity - $usedQuantity);

                // Only process if there's remaining quantity
                if ($remainingQuantity > 0) {
                    if ($rollbackOption === 'remaining') {
                        // Create negative entry in destination magazine
                        $MsaDB->insert("inventory__{$type}", [
                            $type . '_id',
                            'commission_id',
                            'user_id',
                            'sub_magazine_id',
                            'quantity',
                            'production_date',
                            'input_type_id',
                            'comment'
                        ], [
                            $itemId,
                            $commissionId,
                            $item['user_id'],
                            $magazineToId,
                            -$remainingQuantity,
                            $item['production_date'],
                            2,
                            $item['comment'] . " (Rollback - anulacja zlecenia)"
                        ]);

                        // Create positive entry in source magazine
                        $MsaDB->insert("inventory__{$type}", [
                            $type . '_id',
                            'commission_id',
                            'user_id',
                            'sub_magazine_id',
                            'quantity',
                            'production_date',
                            'input_type_id',
                            'comment'
                        ], [
                            $itemId,
                            $commissionId,
                            $item['user_id'],
                            $magazineFromId,
                            $remainingQuantity,
                            $item['production_date'],
                            2,
                            $item['comment'] . " (Rollback - anulacja zlecenia)"
                        ]);
                    } else if ($rollbackOption === 'delete') {
                        // Create negative entry in destination magazine (delete remaining)
                        $MsaDB->insert("inventory__{$type}", [
                            $type . '_id',
                            'commission_id',
                            'user_id',
                            'sub_magazine_id',
                            'quantity',
                            'production_date',
                            'input_type_id',
                            'comment'
                        ], [
                            $itemId,
                            $commissionId,
                            $item['user_id'],
                            $magazineToId,
                            -$remainingQuantity,
                            $item['production_date'],
                            3,
                            $item['comment'] . " (Usunięcie - anulacja zlecenia)"
                        ]);
                    }
                }
            }
        }
    }

    // Handle unreturned products
    $commissionValues = $commission->commissionValues;
    $unreturnedProducts = max(0, $commissionValues['quantity_produced'] - $commissionValues['quantity_returned']);

    if ($unreturnedProducts > 0 && $unreturnedOption !== 'keep') {
        $deviceType = $commission->deviceType;
        $bomId = $commissionValues["bom_{$deviceType}_id"];
        $commissionId = $_POST["id"];
        $magazineFromId = $commissionValues['magazine_from'];
        $magazineToId = $commissionValues['magazine_to'];

        // Get the device BOM to get the actual device ID
        $deviceBom = $bomRepository->getBomById($deviceType, $bomId);
        $deviceId = $deviceBom->deviceId; // Use deviceId property directly

        if ($unreturnedOption === 'transfer') {
            // Transfer unreturned products to destination magazine (original source)
            $MsaDB->insert("inventory__{$deviceType}", [
                "{$deviceType}_id",
                "{$deviceType}_bom_id",
                'commission_id',
                'user_id',
                'sub_magazine_id',
                'quantity',
                'input_type_id',
                'comment',
                'production_date'
            ], [
                $deviceId,
                $bomId,
                $commissionId,
                $_SESSION["userid"] ?? 1,
                $magazineFromId, // Transfer to original source magazine
                $unreturnedProducts,
                2,
                'Przeniesienie niewróconych produktów przy anulacji zlecenia',
                date('Y-m-d H:i:s')
            ]);

            // Remove from contractor magazine (negative entry)
            $MsaDB->insert("inventory__{$deviceType}", [
                "{$deviceType}_id",
                "{$deviceType}_bom_id",
                'commission_id',
                'user_id',
                'sub_magazine_id',
                'quantity',
                'input_type_id',
                'comment',
                'production_date'
            ], [
                $deviceId,
                $bomId,
                $commissionId,
                $_SESSION["userid"] ?? 1,
                $magazineToId, // Remove from contractor magazine
                -$unreturnedProducts,
                2,
                'Przeniesienie niewróconych produktów przy anulacji zlecenia',
                date('Y-m-d H:i:s')
            ]);

        } else if ($unreturnedOption === 'remove') {
            // Remove unreturned products from contractor magazine
            $MsaDB->insert("inventory__{$deviceType}", [
                "{$deviceType}_id",
                "{$deviceType}_bom_id",
                'commission_id',
                'user_id',
                'sub_magazine_id',
                'quantity',
                'input_type_id',
                'comment',
                'production_date'
            ], [
                $deviceId,
                $bomId,
                $commissionId,
                $_SESSION["userid"] ?? 1,
                $magazineToId, // Remove from contractor magazine
                -$unreturnedProducts,
                3,
                'Usunięcie niewróconych produktów przy anulacji zlecenia',
                date('Y-m-d H:i:s')
            ]);
        }
    }

    $MsaDB->db->commit();
}
catch (\Throwable $e) {
    $MsaDB->db->rollBack();
    $wasSuccessful = false;
    $errorMessage = "ERROR! Error message:".$e->getMessage();
}

echo json_encode([$wasSuccessful, $errorMessage]
    , JSON_FORCE_OBJECT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
