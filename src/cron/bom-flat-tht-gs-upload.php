<?php

use Atte\Api\GoogleSheets;
use Atte\DB\MsaDB;
use Atte\Utils\Locker;

$locker = new Locker('bom_flat_tht_gs_upload.lock');

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
        $laminateNames = $MsaDB->readIdName("list__laminate");

        $lookup = ['parts' => [], 'smd' => [], 'tht' => [], 'sku' => []];
        foreach ($partsInfo as $row) $lookup['parts'][$row['id']] = $row;
        foreach ($smdInfo as $row) $lookup['smd'][$row['id']] = $row;
        foreach ($thtInfo as $row) $lookup['tht'][$row['id']] = $row;
        foreach ($skuInfo as $row) $lookup['sku'][$row['id']] = $row;

        /**
         * Recursively flattens BOM to base components (parts)
         */
        $flattenBom = function($type, $id, $multiplier, $parentName = '', &$collected, $anyMissingDefault = false) use (&$MsaDB, &$lookup, &$laminateNames, &$flattenBom) {
            $bomId = null;
            $details = '';
            $currentMissingDefault = false;

            if ($type === 'sku') {
                $res = $MsaDB->query("SELECT id, version FROM bom__sku WHERE sku_id = $id AND isActive = 1 LIMIT 1", \PDO::FETCH_ASSOC);
                $bomId = $res[0]['id'] ?? null;
                $details = $res[0]['version'] ?? '';
            } else {
                $bomId = $lookup[$type][$id]['default_bom_id'] ?? null;
                if (!$bomId) {
                    $currentMissingDefault = true;
                    $res = $MsaDB->query("SELECT id FROM bom__$type WHERE {$type}_id = $id AND isActive = 1 ORDER BY id ASC LIMIT 1", \PDO::FETCH_ASSOC);
                    $bomId = $res[0]['id'] ?? null;
                }
                
                if ($bomId) {
                    $bomInfo = $MsaDB->query("SELECT version" . ($type === 'smd' ? ", laminate_id" : "") . " FROM bom__$type WHERE id = $bomId", \PDO::FETCH_ASSOC);
                    $ver = $bomInfo[0]['version'] ?? '';
                    if ($type === 'smd' && !empty($bomInfo[0]['laminate_id'])) {
                        $lam = $laminateNames[$bomInfo[0]['laminate_id']] ?? '';
                        $details = ($lam && $ver) ? "$lam / $ver" : ($lam ?: $ver);
                    } else {
                        $details = $ver;
                    }
                }
            }

            if (!$bomId) return;

            $components = $MsaDB->query("SELECT parts_id, smd_id, tht_id, sku_id, quantity FROM bom__flat WHERE bom_{$type}_id = $bomId", \PDO::FETCH_ASSOC);

            foreach ($components as $comp) {
                $qty = (float)$comp['quantity'] * $multiplier;
                
                if ($comp['parts_id']) {
                    $partId = $comp['parts_id'];
                    $p = $lookup['parts'][$partId] ?? ['name' => "Unknown Part ($partId)", 'description' => ''];
                    $collected[] = [
                        'name' => $p['name'],
                        'description' => $p['description'],
                        'quantity' => $qty,
                        'source' => $parentName,
                        'source_details' => $parentName ? $details : '',
                        'missing_default' => ($anyMissingDefault || $currentMissingDefault)
                    ];
                } else {
                    $subType = $comp['smd_id'] ? 'smd' : ($comp['tht_id'] ? 'tht' : 'sku');
                    $subId = $comp['smd_id'] ?: ($comp['tht_id'] ?: $comp['sku_id']);
                    $subName = $lookup[$subType][$subId]['name'] ?? "Unknown $subType ($subId)";
                    $flattenBom($subType, $subId, $qty, $subName, $collected, ($anyMissingDefault || $currentMissingDefault));
                }
            }
        };

        // 2. Get active THT devices
        $devicesQuery = "SELECT id, name, default_bom_id FROM list__tht WHERE isActive = 1 ORDER BY name ASC";
        $devices = $MsaDB->query($devicesQuery, \PDO::FETCH_ASSOC);

        // 3. Prepare result data
        $result = [
            ["Urządzenie", "Wersja", "Komponent", "Opis", "Ilość", "Źródło", "Laminat/Wersja", "Uwagi"]
        ];

        foreach ($devices as $device) {
            $deviceId = $device['id'];
            $bomId = $device['default_bom_id'];
            $mainMissingDefault = empty($bomId);

            if (!$bomId) {
                $res = $MsaDB->query("SELECT id FROM bom__tht WHERE tht_id = $deviceId AND isActive = 1 ORDER BY id ASC LIMIT 1", \PDO::FETCH_ASSOC);
                $bomId = $res[0]['id'] ?? null;
            }

            if (!$bomId) continue;

            $bomInfo = $MsaDB->query("SELECT version FROM bom__tht WHERE id = $bomId", \PDO::FETCH_ASSOC);
            $mainVersion = (!empty($bomInfo[0]['version']) && trim($bomInfo[0]['version']) !== '') ? $bomInfo[0]['version'] : 'n/d';

            $collectedParts = [];
            $flattenBom('tht', $deviceId, 1, '', $collectedParts, $mainMissingDefault);

            if (empty($collectedParts)) continue;

            $isFirstRow = true;
            foreach ($collectedParts as $p) {
                $result[] = [
                    $device['name'],
                    $isFirstRow ? $mainVersion : '',
                    $p['name'],
                    $p['description'],
                    (float)$p['quantity'],
                    $p['source'],
                    $p['source_details'],
                    $p['missing_default'] ? "Brak domyślnej wersji źródła" : ""
                ];
                $isFirstRow = false;
            }
        }

        // 4. Write to Google Sheet
        $spreadsheetId = '1dVUCdqrqaMKBEN_ol75SjiFODKelDKX0fqRaO94_ydM';
        $sheetName = 'ds_php_bom_flat_tht';

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
