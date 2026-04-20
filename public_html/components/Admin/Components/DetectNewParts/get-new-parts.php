<?php
use Atte\DB\MsaDB;
use Atte\Api\GoogleSheets;

$MsaDB = MsaDB::getInstance();

$googleSheets = new GoogleSheets();

$ref_mag_parts_sheet = getRefMagParts($googleSheets);

$list__parts_db = getListPartsWithRefs($MsaDB);

$part__group = $MsaDB->readIdName('part__group');
$part__type = $MsaDB->readIdName('part__type');
$part__unit = $MsaDB->readIdName('part__unit');

$newParts = [];
$editedParts = [];

foreach ($ref_mag_parts_sheet as $ref_mag_part) {
    $id = (int)$ref_mag_part[0];
    $refName = trim($ref_mag_part[1]);
    $refDescription = trim($ref_mag_part[2]);
    $refPartGroup = trim($ref_mag_part[3]);
    $refPartType = trim($ref_mag_part[4]);
    $refJM = trim($ref_mag_part[5]);

    if (!array_key_exists($id, $list__parts_db)) {
        $newParts[] = $ref_mag_part;
    } else {
        $dbPart = $list__parts_db[$id];
        $dbName = trim($dbPart['name']);
        $dbDescription = trim($dbPart['description']);
        $dbPartGroup = trim($dbPart['PartGroup']);
        $dbPartType = trim($dbPart['PartType'] ?? '');
        $dbJM = trim($dbPart['JM']);

        $changes = [];

        if ($refName !== $dbName) {
            $changes['name'] = ['from' => $dbPart['name'], 'to' => $refName];
        }
        if ($refDescription !== $dbDescription) {
            $changes['description'] = ['from' => $dbPart['description'], 'to' => $refDescription];
        }
        if ($refPartGroup !== $dbPartGroup) {
            $changes['PartGroup'] = ['from' => $dbPart['PartGroup'], 'to' => $refPartGroup];
        }
        if ($refPartType !== $dbPartType) {
            $changes['PartType'] = ['from' => $dbPart['PartType'] ?? '', 'to' => $refPartType];
        }
        if ($refJM !== $dbJM) {
            $changes['JM'] = ['from' => $dbPart['JM'], 'to' => $refJM];
        }

        if (!empty($changes)) {
            $editedParts[] = [
                'id' => $id,
                'data' => $ref_mag_part,
                'changes' => $changes
            ];
        }
    }
}

$missingRefs = [
    'part__group' => [],
    'part__type' => [],
    'part__unit' => [],
];

$allPartsToCheck = array_merge($newParts, array_column($editedParts, 'data'));

foreach ($allPartsToCheck as $row) {
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
    'editedParts' => $editedParts,
    'missingRefs' => $missingRefs,
], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);

function getRefMagParts($googleSheets){
    $result = $googleSheets -> readSheet('1OowYceg8hWtuCmnqPiqCyg5N3rVaAngEvmnGRhjeOew', 'ref_mag_parts', 'H:M');
    unset($result[0]);
    array_walk($result, function(&$row){
        $row[0] = (int)$row[0];
    });
    return $result;
}

function getListPartsWithRefs($MsaDB) {
    $sql = "SELECT lp.id, lp.name, lp.description, pg.name as PartGroup, pt.name as PartType, pu.name as JM
            FROM list__parts lp
            LEFT JOIN part__group pg ON lp.PartGroup = pg.id
            LEFT JOIN part__type pt ON lp.PartType = pt.id
            LEFT JOIN part__unit pu ON lp.JM = pu.id";
    $rows = $MsaDB->query($sql, \PDO::FETCH_ASSOC);

    $result = [];
    foreach ($rows as $row) {
        $result[$row['id']] = [
            'name' => $row['name'],
            'description' => $row['description'],
            'PartGroup' => $row['PartGroup'],
            'PartType' => $row['PartType'] ?? '',
            'JM' => $row['JM']
        ];
    }
    return $result;
}