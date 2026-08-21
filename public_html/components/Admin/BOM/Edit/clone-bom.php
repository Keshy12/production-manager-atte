<?php
use Atte\DB\MsaDB;
use Atte\Utils\Bom\PriceCalculator;
use Atte\Utils\BomRepository;

header('Content-Type: application/json');
$MsaDB = MsaDB::getInstance();
$bomRepository = new BomRepository($MsaDB);

$wasSuccessful = true;
$errorMessage = '';
$insertedCount = 0;

$allowedTypes = ['sku', 'tht', 'smd'];
$bomType = $_POST['bomType'] ?? null;
$targetBomId = $_POST['targetBomId'] ?? null;
$sourceBomId = $_POST['sourceBomId'] ?? null;

// --- Validate input ---
if (!is_string($bomType) || !in_array($bomType, $allowedTypes, true)) {
    $wasSuccessful = false;
    $errorMessage = 'Nieprawidłowy typ BOM.';
}

$targetBomInt = 0;
if ($wasSuccessful) {
    if (!is_string($targetBomId) && !is_int($targetBomId)) {
        $wasSuccessful = false;
        $errorMessage = 'Nieprawidłowe ID BOM docelowego.';
    } else {
        $targetBomInt = (int)$targetBomId;
        if ($targetBomInt <= 0 || (string)$targetBomInt !== (string)((string)(int)$targetBomId)) {
            $wasSuccessful = false;
            $errorMessage = 'Nieprawidłowe ID BOM docelowego.';
        }
    }
}

$sourceBomInt = 0;
if ($wasSuccessful) {
    if (!is_string($sourceBomId) && !is_int($sourceBomId)) {
        $wasSuccessful = false;
        $errorMessage = 'Nieprawidłowe ID BOM źródłowego.';
    } else {
        $sourceBomInt = (int)$sourceBomId;
        if ($sourceBomInt <= 0) {
            $wasSuccessful = false;
            $errorMessage = 'Nieprawidłowe ID BOM źródłowego.';
        }
    }
}

if ($wasSuccessful && $targetBomInt === $sourceBomInt) {
    $wasSuccessful = false;
    $errorMessage = 'BOM źródłowy i docelowy muszą być różne.';
}

// Confirm source BOM exists. BomRepository::getBomById throws if not.
if ($wasSuccessful) {
    try {
        $bomRepository->getBomById($bomType, $sourceBomInt);
    } catch (\Throwable $e) {
        $wasSuccessful = false;
        $errorMessage = 'Nie znaleziono BOM źródłowego.';
    }
}

// --- Perform destructive clone inside a single transaction ---
if ($wasSuccessful) {
    $MsaDB->db->beginTransaction();
    try {
        // Remove every existing row of the target BOM.
        $MsaDB->query("DELETE FROM bom__flat WHERE bom_{$bomType}_id = {$targetBomInt}");

        // Read source rows.
        $sourceRows = $MsaDB->query(
            "SELECT sku_id, tht_id, smd_id, parts_id, quantity
             FROM bom__flat
             WHERE bom_{$bomType}_id = {$sourceBomInt}"
        );

        $columns = [
            'bom_' . $bomType . '_id',
            'sku_id',
            'tht_id',
            'smd_id',
            'parts_id',
            'quantity',
        ];

        foreach ($sourceRows as $row) {
            $values = [
                $targetBomInt,
                $row['sku_id'],
                $row['tht_id'],
                $row['smd_id'],
                $row['parts_id'],
                $row['quantity'],
            ];
            $MsaDB->insert('bom__flat', $columns, $values);
            $insertedCount++;
        }

        // Cloned content is a draft until reviewed — mark target BOM inactive.
        $MsaDB->update('bom__' . $bomType, ['isActive' => 0], 'id', $targetBomInt);

        $MsaDB->db->commit();
    } catch (\Throwable $e) {
        if ($MsaDB->db->inTransaction()) {
            $MsaDB->db->rollBack();
        }
        $wasSuccessful = false;
        $errorMessage = 'Nie udało się skopiować pozycji BOM.';
    }
}

// --- Recalculate price (don't fail the clone if propagation errors) ---
if ($wasSuccessful) {
    $PriceCalculator = new PriceCalculator($MsaDB);
    try {
        $PriceCalculator->updateBomPriceAndPropagate($targetBomInt, $bomType);
    } catch (\Throwable $e) {
        // Intentional: price propagation is best-effort here.
    }
}

echo json_encode([
    'wasSuccessful' => $wasSuccessful,
    'errorMessage'  => $errorMessage,
    'insertedCount' => $insertedCount,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
exit;
