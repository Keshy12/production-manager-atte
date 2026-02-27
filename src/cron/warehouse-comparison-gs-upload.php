<?php
use Atte\Api\GoogleSheets;
use Atte\DB\MsaDB;
use Atte\DB\FlowpinDB;
use Atte\Utils\Locker;

// Path to config for manual execution if needed
if (!defined('ROOT_DIRECTORY')) {
    define('ROOT_DIRECTORY', __DIR__ . '/../../');
}

$locker = new Locker('warehouse_comparison_gs_upload.lock');

if ($locker->isLocked()) {
    exit("Process is already running.");
}

try {
    $locker->lock();
    
    $googleSheets = new GoogleSheets();
    $MsaDB = MsaDB::getInstance();
    $FlowpinDB = FlowpinDB::getInstance();

    // 1. Fetch Main Magazines from MSA
    $mainMagazines = $MsaDB->query("SELECT sub_magazine_id, sub_magazine_name FROM magazine__list WHERE type_id = 1 AND isActive = 1 ORDER BY sub_magazine_id ASC");
    $mainMagIds = array_column($mainMagazines, 'sub_magazine_id');
    $mainMagNames = array_column($mainMagazines, 'sub_magazine_name');

    // 2. Fetch FlowPin Data
    $flowpinQuery = "
    SELECT 
        pt.Symbol,
        SUM(CASE WHEN (p.WarehouseId = 3 AND pt.Symbol LIKE '%[_]INTER') OR (p.WarehouseId = 4 AND pt.Symbol NOT LIKE '%[_]INTER') THEN 1 ELSE 0 END) AS ATTE,
        SUM(CASE WHEN p.WarehouseId = 83 THEN 1 ELSE 0 END) AS MJ,
        SUM(CASE WHEN p.WarehouseId = 86 THEN 1 ELSE 0 END) AS Zagranica
    FROM ProductTypes pt
    JOIN Products p ON p.ProductTypeId = pt.Id
    WHERE pt.CompanyId = 1 AND p.WarehouseId IN (3, 4, 83, 86)
    GROUP BY pt.Symbol";

    $flowpinRaw = $FlowpinDB->query($flowpinQuery);
    $flowpinData = [];
    foreach ($flowpinRaw as $row) {
        $symbol = $row['Symbol'];
        $atte = (int)$row['ATTE'];
        $mj = (int)$row['MJ'];
        $zag = (int)$row['Zagranica'];
        $flowpinData[$symbol] = [
            'atte' => $atte,
            'zagranica' => $zag,
            'suma' => $atte + $zag,
            'mj' => $mj
        ];
    }

    // 3. Fetch Local MSA Data
    $types = ['sku', 'tht', 'smd']; 
    $localData = [];

    foreach ($types as $type) {
        $idColumn = $type . "_id";
        $names = $MsaDB->readIdName("list__" . $type);
        
        $inventoryQuery = "
            SELECT 
                i.$idColumn as id, 
                ml.type_id,
                i.sub_magazine_id,
                SUM(i.qty) as total_qty
            FROM inventory__$type i
            JOIN magazine__list ml ON i.sub_magazine_id = ml.sub_magazine_id
            WHERE ml.isActive = 1
            GROUP BY i.$idColumn, ml.type_id, i.sub_magazine_id
        ";
        
        $inventoryRaw = $MsaDB->query($inventoryQuery);
        
        foreach ($inventoryRaw as $row) {
            $id = $row['id'];
            if (!isset($names[$id])) continue;
            
            $name = $names[$id];
            if (!isset($localData[$name])) {
                $localData[$name] = [
                    'main_sum' => 0,
                    'mj_mag_11' => 0,
                    'individual_mags' => array_fill_keys($mainMagIds, 0)
                ];
            }
            
            $qty = $row['total_qty'];
            
            // Main warehouses (type_id = 1)
            if ($row['type_id'] == 1) {
                $localData[$name]['main_sum'] += $qty;
                if (isset($localData[$name]['individual_mags'][$row['sub_magazine_id']])) {
                    $localData[$name]['individual_mags'][$row['sub_magazine_id']] += $qty;
                }
            }
            
            // Sub Mag 11 (Michał Janocha)
            if ($row['sub_magazine_id'] == 11) {
                $localData[$name]['mj_mag_11'] += $qty;
            }
        }
    }

    // 4. Merge and Format Data
    $allNames = array_unique(array_merge(array_keys($flowpinData), array_keys($localData)));
    sort($allNames);

    $header = [
        "Nazwa", 
        "Suma (ATTE+Zag) [FP]", 
        "Suma Główne [MSA]", 
        "Różnica", 
        "", // Break
        "MJ [FP]", 
        "MJ Mag 11 [MSA]", 
        "Różnica MJ", 
        "", // Break
        "ATTE [FP]", 
        "Zagranica [FP]", 
        "", // Break
    ];
    // Add all individual main magazines to the end
    foreach ($mainMagNames as $magName) {
        $header[] = $magName;
    }

    $finalResult = [$header];

    foreach ($allNames as $name) {
        $fp = $flowpinData[$name] ?? ['atte' => 0, 'zagranica' => 0, 'suma' => 0, 'mj' => 0];
        $msa = $localData[$name] ?? ['main_sum' => 0, 'mj_mag_11' => 0, 'individual_mags' => array_fill_keys($mainMagIds, 0)];
        
        $diff = $fp['suma'] - $msa['main_sum'];
        $diffMj = $fp['mj'] - $msa['mj_mag_11'];

        $row = [
            $name,
            $fp['suma'],
            $msa['main_sum'],
            $diff,
            "", // Break
            $fp['mj'],
            $msa['mj_mag_11'],
            $diffMj,
            "", // Break
            $fp['atte'],
            $fp['zagranica'],
            "", // Break
        ];
        
        // Add individual magazine quantities
        foreach ($mainMagIds as $magId) {
            $row[] = $msa['individual_mags'][$magId] ?? 0;
        }
        
        $finalResult[] = $row;
    }

    // 5. Upload to Google Sheets
    $spreadsheetId = '1dVUCdqrqaMKBEN_ol75SjiFODKelDKX0fqRaO94_ydM';
    $sheetName = 'ds_php_sku_quantity';
    $range = 'A2'; // Start writing from the second row

    if ($googleSheets->writeToSheet($spreadsheetId, $sheetName, $range, $finalResult)) {
        echo "Successfully uploaded warehouse comparison data.\n";
    } else {
        throw new Exception("Failed to write data to Google Sheets");
    }

} catch (Exception $e) {
    echo "Error occurred: " . $e->getMessage() . "\n";
} finally {
    $locker->unlock();
}