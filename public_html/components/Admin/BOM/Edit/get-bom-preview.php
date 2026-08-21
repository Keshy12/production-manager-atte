<?php
use Atte\DB\MsaDB;
use Atte\Utils\BomRepository;

header('Content-Type: application/json');
$MsaDB = MsaDB::getInstance();
$bomRepository = new BomRepository($MsaDB);

$wasSuccessful = true;
$errorMessage  = '';
$sourceBomId   = null;
$componentCount = 0;
$components    = [];

$allowedTypes = ['sku', 'tht', 'smd'];
$bomType      = $_POST['bomType']    ?? null;
$deviceId     = $_POST['deviceId']   ?? null;
$version      = $_POST['version']    ?? null;
$laminateId   = $_POST['laminateId'] ?? null;

if (!is_string($bomType) || !in_array($bomType, $allowedTypes, true)) {
    $wasSuccessful = false;
    $errorMessage = 'Nieprawidłowy typ BOM.';
}

// Trim deviceId is numeric (component id from list__sku/tht/smd). Version can be string or null.
if ($wasSuccessful) {
    $deviceIdInt = (int)$deviceId;
    if ($deviceIdInt <= 0) {
        $wasSuccessful = false;
        $errorMessage = 'Nieprawidłowy identyfikator komponentu.';
    } else {
        $deviceId = $deviceIdInt;
    }
}

if ($bomType === 'smd' && $wasSuccessful) {
    $laminateIdInt = (int)$laminateId;
    if ($laminateIdInt <= 0) {
        $wasSuccessful = false;
        $errorMessage = 'Nieprawidłowy identyfikator laminatu.';
    } else {
        $laminateId = $laminateIdInt;
    }
}

if ($wasSuccessful) {
    $values = [$bomType . '_id' => $deviceId];
    if ($bomType === 'sku' || $bomType === 'tht') {
        // Match get-bom-components.php: 'n/d' -> null
        if ($version === null || $version === '' || $version === 'n/d') {
            $values['version'] = null;
        } else {
            $values['version'] = $version;
        }
    } else { // smd
        $values['laminate_id'] = $laminateId;
        if ($version === null || $version === '' || $version === 'n/d') {
            $values['version'] = null;
        } else {
            $values['version'] = $version;
        }
    }

    $bomsFound = [];
    try {
        $bomsFound = $bomRepository->getBomByValues($bomType, $values);
    } catch (\Throwable $e) {
        $bomsFound = [];
    }

    if (!$bomsFound || count($bomsFound) === 0) {
        $wasSuccessful = false;
        $errorMessage = 'Nie znaleziono BOM dla podanych parametrów.';
    } elseif (count($bomsFound) > 1) {
        $wasSuccessful = false;
        $errorMessage = 'Znaleziono wiele BOM dla podanych parametrów.';
    } else {
        $bom = $bomsFound[0];
        $sourceBomId = (int)$bom->id;

        $list__sku = $MsaDB->readIdName('list__sku');
        $list__tht = $MsaDB->readIdName('list__tht');
        $list__smd = $MsaDB->readIdName('list__smd');
        $list__parts = $MsaDB->readIdName('list__parts');

        try {
            $rows = $bom->getComponents(1);
            foreach ($rows as $row) {
                $type = $row['type'];
                $id   = (int)$row['componentId'];
                $bucket = 'list__' . $type;
                $name = ${$bucket}[$id] ?? null;
                if ($name === null) {
                    continue;
                }
                $components[] = [
                    'type'     => $type,
                    'name'     => (string)$name,
                    'quantity' => (float)$row['quantity'],
                ];
            }
            $componentCount = count($components);
        } catch (\Throwable $e) {
            $wasSuccessful = false;
            $errorMessage = 'Nie udało się wczytać komponentów BOM.';
        }
    }
}

echo json_encode([
    'wasSuccessful'  => $wasSuccessful,
    'errorMessage'   => $errorMessage,
    'sourceBomId'    => $sourceBomId,
    'componentCount' => $componentCount,
    'components'     => $components,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
exit;
