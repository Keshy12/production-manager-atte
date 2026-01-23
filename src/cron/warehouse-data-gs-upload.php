<?php

use Atte\Api\GoogleSheets;
use Atte\DB\MsaDB;

// Initialize Google Sheets and Database connections
$googleSheets = new GoogleSheets();
$MsaDB = MsaDB::getInstance();

// Begin database transaction
$MsaDB->db->beginTransaction();

try {
    // Get magazine list and current timestamp
    $list__magazine = $MsaDB->readIdName("magazine__list", "sub_magazine_id", "sub_magazine_name", 'WHERE isActive = 1 ORDER BY type_id, sub_magazine_id ASC');
    $now = date("Y/m/d H:i:s", time());
    $types = [1 => "sku", 2 => "tht", 3 => "smd", 4 => "parts"];

    /**
     * Process inventory for a specific type
     * @param string $type The inventory type (sku, tht, smd, parts)
     * @param array $list__magazine Magazine list with IDs and names
     * @return array Processed inventory data
     */
    function processInventory($type, $list__magazine) {
        $MsaDB = MsaDB::getInstance();
        $resultInventory = [];

        // Get device list for this type
        $list__device = $MsaDB->readIdName("list__".$type);

        // Get inventory data with magazine type information
        $inventoryDevice = $MsaDB->query("SELECT ".$type."_id, i.sub_magazine_id, 
            sum(qty) as sum, ml.type_id 
            FROM `inventory__".$type."` i 
            JOIN magazine__list ml 
            ON i.sub_magazine_id = ml.sub_magazine_id 
            GROUP BY ".$type."_id, sub_magazine_id;");

        // Process each inventory item
        foreach($inventoryDevice as $item){
            list($id, $subMagazineId, $quantity, $typeId) = $item;

            // Initialize device entry if not exists
            if(!isset($resultInventory[$id])){
                $resultInventory[$id] = [$list__device[$id]];
                $resultInventory[$id][1] = 0; // Main magazines total
                $resultInventory[$id][2] = 0; // External magazines total

                // Initialize columns for each magazine
                foreach($list__magazine as $key => $innerItem){
                    $resultInventory[$id][$key+3] = 0;
                }
            }

            // Add quantity to appropriate magazine type totals
            switch($typeId) {
                case 1: // Main magazines
                    $resultInventory[$id][1] += $quantity;
                    break;
                case 2: // External magazines
                    $resultInventory[$id][2] += $quantity;
                    break;
            }

            // Add quantity to specific magazine column
            $resultInventory[$id][$subMagazineId + 3] += $quantity;
        }

        // Convert associative array to indexed array for sheet writing
        $resultInventory = array_map('array_values', $resultInventory);
        return $resultInventory;
    }

    // Build the result array starting with headers
    $result = [
        ["Urządzenie/Komponent", "Magazyny Główne", "Magazyny Zewnętrzne"]
    ];

    // Add magazine names to header row
    foreach($list__magazine as $key => $magazine) {
        $result[0][] = str_replace("SUB MAG ", "", $magazine);
    }

    // Process inventory for each type and merge results
    foreach($types as $type) {
        $result = array_merge($result, processInventory($type, $list__magazine));
    }

    // Write data to Google Sheet
    $spreadsheetId = '1rV1rbLXDdsOxT49sgJNm1Aicldg8ZvBdQo9yaX314QI';
    $sheetName = 'ds_php_mag';

    if($googleSheets->writeToSheet($spreadsheetId, $sheetName, '', $result)) {
        // Update timestamp on successful sheet write
        $MsaDB->update("ref__timestamp", ["last_timestamp" => $now], "id", 5);
    } else {
        throw new Exception("Failed to write data to Google Sheets");
    }

    // Commit the transaction
    $MsaDB->db->commit();

} catch (Exception $e) {
    // Rollback transaction on error
    $MsaDB->db->rollback();
    echo "Error occurred: " . $e->getMessage() . "\n";
    echo "Database transaction rolled back.\n";
}