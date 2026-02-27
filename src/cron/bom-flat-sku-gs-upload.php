<?php

use Atte\Api\GoogleSheets;
use Atte\DB\MsaDB;
use Atte\Utils\Locker;

$locker = new Locker('bom_flat_sku_gs_upload.lock');

if ($locker->isLocked()) {
    exit("Process is already running.");
}

try {
    $locker->lock();
    
    // Initialize Google Sheets and Database connections
    $googleSheets = new GoogleSheets();
    $MsaDB = MsaDB::getInstance();

    // Begin database transaction for consistency
    $MsaDB->db->beginTransaction();

    try {
        // 1. Fetch all names and descriptions for mapping
        $partsInfo = $MsaDB->query("SELECT id, name, description FROM list__parts", \PDO::FETCH_ASSOC);
        $smdInfo = $MsaDB->query("SELECT id, name, description, default_bom_id FROM list__smd", \PDO::FETCH_ASSOC);
        $thtInfo = $MsaDB->query("SELECT id, name, description, default_bom_id FROM list__tht", \PDO::FETCH_ASSOC);
        $skuInfo = $MsaDB->query("SELECT id, name, description FROM list__sku", \PDO::FETCH_ASSOC);

        $lookup = ['parts' => [], 'smd' => [], 'tht' => [], 'sku' => []];
        foreach ($partsInfo as $row) $lookup['parts'][$row['id']] = $row;
        foreach ($smdInfo as $row) $lookup['smd'][$row['id']] = $row;
        foreach ($thtInfo as $row) $lookup['tht'][$row['id']] = $row;
        foreach ($skuInfo as $row) $lookup['sku'][$row['id']] = $row;

        /**
         * Recursively flattens BOM to base components (parts)
         * Tracks source path with names and quantities
         */
        $flattenBom = function($type, $id, $multiplier, $path = [], &$collected, $missingDefaults = []) use (&$MsaDB, &$lookup, &$flattenBom) {
            $bomId = null;
            $localMissingDefaults = $missingDefaults;

            if ($type === 'sku') {
                $res = $MsaDB->query("SELECT id FROM bom__sku WHERE sku_id = $id AND isActive = 1 LIMIT 1", \PDO::FETCH_ASSOC);
                $bomId = $res[0]['id'] ?? null;
            } else {
                $bomId = $lookup[$type][$id]['default_bom_id'] ?? null;
                if (!$bomId) {
                    $localMissingDefaults[] = $lookup[$type][$id]['name'];
                    $res = $MsaDB->query("SELECT id FROM bom__$type WHERE {$type}_id = $id AND isActive = 1 ORDER BY id ASC LIMIT 1", \PDO::FETCH_ASSOC);
                    $bomId = $res[0]['id'] ?? null;
                }
            }

            if (!$bomId) return;

            $components = $MsaDB->query("SELECT parts_id, smd_id, tht_id, sku_id, quantity FROM bom__flat WHERE bom_{$type}_id = $bomId", \PDO::FETCH_ASSOC);

            foreach ($components as $comp) {
                $compQtyInParent = (float)$comp['quantity'];
                $totalQty = $compQtyInParent * $multiplier;
                
                if ($comp['parts_id']) {
                    $partId = $comp['parts_id'];
                    $p = $lookup['parts'][$partId] ?? ['name' => "Unknown Part ($partId)", 'description' => ''];
                    
                    // Reverse path for sources: [Lowest, Middle, Highest]
                    $reversedPath = array_reverse($path);
                    
                    $collected[] = [
                        'name' => $p['name'],
                        'description' => $p['description'],
                        'quantity' => $totalQty,
                        'sources' => [
                            ['name' => $reversedPath[0]['name'] ?? '', 'qty' => $reversedPath[0]['qty'] ?? ''],
                            ['name' => $reversedPath[1]['name'] ?? '', 'qty' => $reversedPath[1]['qty'] ?? ''],
                            ['name' => $reversedPath[2]['name'] ?? '', 'qty' => $reversedPath[2]['qty'] ?? '']
                        ],
                        'missing_defaults' => $localMissingDefaults
                    ];
                } else {
                    $subType = $comp['smd_id'] ? 'smd' : ($comp['tht_id'] ? 'tht' : 'sku');
                    $subId = $comp['smd_id'] ?: ($comp['tht_id'] ?: $comp['sku_id']);
                    $subName = $lookup[$subType][$subId]['name'] ?? "Unknown $subType ($subId)";
                    
                    $newPath = $path;
                    $newPath[] = ['name' => $subName, 'qty' => $totalQty];
                    
                    $flattenBom($subType, $subId, $totalQty, $newPath, $collected, $localMissingDefaults);
                }
            }
        };

        // 2. Get active SKU devices (excluding those ending with _INTER)
        $devicesQuery = "SELECT id, name FROM list__sku WHERE isActive = 1 AND name NOT LIKE '%_INTER' ORDER BY name ASC";
        $devices = $MsaDB->query($devicesQuery, \PDO::FETCH_ASSOC);

        // 3. Prepare result data
        $result = [
            ["SKU", "Komponent", "Opis", "Ilość", "", "Źródło 1", "Ilość", "Źródło 2", "Ilość", "Źródło 3", "Ilość", "Uwagi", "."]
        ];

        foreach ($devices as $device) {
            $deviceId = $device['id'];
            $collectedParts = [];
            $flattenBom('sku', $deviceId, 1, [], $collectedParts);

            if (empty($collectedParts)) continue;

            $isFirstSkuRow = true;
            foreach ($collectedParts as $p) {
                $uwagi = "";
                if (!empty($p['missing_defaults'])) {
                    $uwagi = "Brak domyślnej wersji źródła dla: " . implode(", ", array_unique($p['missing_defaults']));
                }

                $result[] = [
                    $device['name'],
                    $p['name'],
                    $p['description'],
                    (float)$p['quantity'],
                    '', // Empty break column
                    $p['sources'][0]['name'],
                    $p['sources'][0]['qty'] !== '' ? (float)$p['sources'][0]['qty'] : '',
                    $p['sources'][1]['name'],
                    $p['sources'][1]['qty'] !== '' ? (float)$p['sources'][1]['qty'] : '',
                    $p['sources'][2]['name'],
                    $p['sources'][2]['qty'] !== '' ? (float)$p['sources'][2]['qty'] : '',
                    $uwagi,
                    $isFirstSkuRow ? "." : ""
                ];
                $isFirstSkuRow = false;
            }
        }

        // 4. Write to Google Sheet
        $spreadsheetId = '1dVUCdqrqaMKBEN_ol75SjiFODKelDKX0fqRaO94_ydM';
        $sheetName = 'ds_php_bom_flat_sku';

        if ($googleSheets->writeToSheet($spreadsheetId, $sheetName, '', $result)) {
            echo "Successfully updated Google Sheet.\n";
        } else {
            throw new Exception("Failed to write data to Google Sheets");
        }

        $MsaDB->db->commit();

    } catch (Exception $e) {
        if ($MsaDB->db->inTransaction()) $MsaDB->db->rollback();
        throw $e;
    }

} catch (Exception $e) {
    echo "Error occurred: " . $e->getMessage() . "\n";
} finally {
    $locker->unlock();
}
