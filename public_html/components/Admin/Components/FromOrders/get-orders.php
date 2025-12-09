<?php
use Atte\Api\GoogleSheets;
use Atte\DB\MsaDB;

$MsaDB = MsaDB::getInstance();
$queryResult = $MsaDB -> query("SELECT * FROM `ref__timestamp` WHERE `id` = 3", PDO::FETCH_ASSOC);
$startCell = (int)$queryResult[0]['params'];

// Extract filter parameters
$filterGrnId = $_POST['grnId'] ?? '';
$filterPoId = $_POST['poId'] ?? '';
$filterPartNames = $_POST['partName'] ?? [];
$filterDateFrom = $_POST['dateFrom'] ?? '';
$filterDateTo = $_POST['dateTo'] ?? '';
$page = max(1, (int)($_POST['page'] ?? 1));
$itemsPerPage = 10;
$offset = ($page - 1) * $itemsPerPage;

$GoogleSheets = new GoogleSheets();

$sheetResult = $GoogleSheets -> readSheet("1F-uzgUWxYfUYaFNPhfcMyGpd8qrkMyXVBb6V_TlYnKA", "to_warehouse", "A".$startCell.":P");

if($sheetResult === null) $sheetResult = [];

$lastCell = $startCell + count($sheetResult);

$list__parts = $MsaDB -> readIdName('list__parts');
$list__parts_lookup = array_flip($list__parts);

$missingParts = [];

$transformRow = function ($row) use ($list__parts_lookup, &$missingParts) {
    $GRN_ID = $row[0];
    $PO_ID = $row[1];
    $DateReceived = $row[2] ?? ''; // Date field (adjust column index if needed)
    $PartName = $row[13];
    if(isset($list__parts_lookup[$PartName])) {
        $PartId = $list__parts_lookup[$PartName];
    }
    else {
        $missingParts[] = $PartName;
        $PartId = null;
    }
    $Qty = $row[14];
    $Vendor_JM = $row[15];

    return [
        'GRN_ID' => $GRN_ID,
        'PO_ID' => $PO_ID,
        'DateReceived' => $DateReceived,
        'PartName' => $PartName,
        'PartId' => $PartId,
        'Qty' => $Qty,
        'Vendor_JM' => $Vendor_JM
    ];
};


$result = array_map($transformRow, $sheetResult);

// Collect all unique values for filter dropdowns BEFORE filtering
$allPOIDs = array_unique(array_column($result, 'PO_ID'));
$allGRNIDs = array_unique(array_column($result, 'GRN_ID'));
$allPartNames = array_unique(array_column($result, 'PartName'));
sort($allPOIDs);
sort($allGRNIDs);
sort($allPartNames);

// Apply filters
$filteredResult = array_filter($result, function($order) use ($filterGrnId, $filterPoId, $filterPartNames, $filterDateFrom, $filterDateTo) {
    // Filter by GRN_ID if specified
    if (!empty($filterGrnId) && $order['GRN_ID'] !== $filterGrnId) {
        return false;
    }

    // Filter by PO_ID if specified
    if (!empty($filterPoId) && $order['PO_ID'] !== $filterPoId) {
        return false;
    }

    // Filter by PartName array if specified (multi-select)
    if (!empty($filterPartNames) && !in_array($order['PartName'], $filterPartNames)) {
        return false;
    }

    // Filter by date range if specified
    if (!empty($filterDateFrom) && !empty($order['DateReceived']) && $order['DateReceived'] < $filterDateFrom) {
        return false;
    }
    if (!empty($filterDateTo) && !empty($order['DateReceived']) && $order['DateReceived'] > $filterDateTo) {
        return false;
    }

    return true;
});

// Reset array keys after filtering
$filteredResult = array_values($filteredResult);

// Calculate statistics
$totalCount = count($filteredResult);
$withMissingParts = count(array_filter($filteredResult, fn($o) => is_null($o['PartId'])));
$stats = [
    'total' => $totalCount,
    'withMissingParts' => $withMissingParts,
    'readyToImport' => $totalCount - $withMissingParts
];

// Apply pagination
$paginatedResult = array_slice($filteredResult, $offset, $itemsPerPage);
$nextPageAvailable = count($filteredResult) > ($offset + $itemsPerPage);

// Return enhanced response
echo json_encode([
    $paginatedResult,      // [0] Paginated orders for display
    $missingParts,         // [1] Missing parts list
    $lastCell,             // [2] Last cell number
    $nextPageAvailable,    // [3] Has next page
    $totalCount,           // [4] Total filtered count
    $stats,                // [5] Statistics
    $allPOIDs,             // [6] All unique PO IDs
    $allGRNIDs,            // [7] All unique GRN IDs
    $allPartNames,         // [8] All unique part names
    $filteredResult        // [9] FULL filtered dataset (for import)
], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);