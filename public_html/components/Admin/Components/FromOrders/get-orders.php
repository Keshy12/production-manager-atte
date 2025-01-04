<?php
use Atte\Api\GoogleSheets;
use Atte\DB\MsaDB;

$MsaDB = MsaDB::getInstance();
$queryResult = $MsaDB -> query("SELECT * FROM `ref__timestamp` WHERE `id` = 3", PDO::FETCH_ASSOC);
$startCell = (int)$queryResult[0]['params'];

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
        'PartName' => $PartName,
        'PartId' => $PartId,
        'Qty' => $Qty,
        'Vendor_JM' => $Vendor_JM
    ];
};


$result = array_map($transformRow, $sheetResult);

echo json_encode([$result, $missingParts, $lastCell], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);