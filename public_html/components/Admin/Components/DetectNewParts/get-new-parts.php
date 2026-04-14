<?php
use Atte\DB\MsaDB;
use Atte\Api\GoogleSheets;

$MsaDB = MsaDB::getInstance();

$googleSheets = new GoogleSheets();

$ref_mag_parts_sheet = getRefMagParts($googleSheets);
$list__parts = $MsaDB -> readIdName('list__parts');

$newParts = array_filter($ref_mag_parts_sheet, function($ref_mag_part) use ($list__parts) {
    $id = (int)$ref_mag_part[0];
    return !array_key_exists($id, $list__parts);
});

$part__group = $MsaDB->readIdName('part__group');
$part__type = $MsaDB->readIdName('part__type');
$part__unit = $MsaDB->readIdName('part__unit');

$missingRefs = [
    'part__group' => [],
    'part__type' => [],
    'part__unit' => [],
];

foreach ($newParts as $row) {
    $group = trim($row[3]);
    $type = trim($row[4]);
    $unit = trim($row[5]);

    if (!in_array($group, $missingRefs['part__group']) && !in_array($group, $part__group)) {
        $missingRefs['part__group'][] = $group;
    }
    if ($type !== '' && !in_array($type, $missingRefs['part__type']) && !in_array($type, $part__type)) {
        $missingRefs['part__type'][] = $type;
    }
    if (!in_array($unit, $missingRefs['part__unit']) && !in_array($unit, $part__unit)) {
        $missingRefs['part__unit'][] = $unit;
    }
}

echo json_encode([
    'newParts' => $newParts,
    'missingRefs' => $missingRefs,
], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);

function getRefMagParts($googleSheets){
    $result = $googleSheets -> readSheet('1OowYceg8hWtuCmnqPiqCyg5N3rVaAngEvmnGRhjeOew', 'ref_mag_parts', 'H:M');
    // Remove header row
    unset($result[0]);
    array_walk($result, function(&$row){
        $row[0] = (int)$row[0];
    });
    return $result;
}