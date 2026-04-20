<?php
use Atte\DB\MsaDB;

$MsaDB = MsaDB::getInstance();

$MsaDB -> db -> beginTransaction();

$part__group_flipped = array_flip($MsaDB->readIdName('part__group'));
$part__type_flipped = array_flip($MsaDB->readIdName('part__type'));
$part__unit_flipped = array_flip($MsaDB->readIdName('part__unit'));

$newParts = json_decode($_POST['newParts'] ?? '[]', true);
$editedParts = json_decode($_POST['editedParts'] ?? '[]', true);

$columnsToInsert = [
    "id",
    "name",
    "description",
    "PartGroup",
    "PartType",
    "JM"
];

$wasSuccessful = true;
$errorMessage = "";

try {
    $allParts = array_merge($newParts, $editedParts);

    $missingGroups = [];
    $missingTypes = [];
    $missingUnits = [];

    foreach ($allParts as $row) {
        $group = trim($row[3]);
        $type = trim($row[4]);
        $unit = trim($row[5]);

        if (!isset($part__group_flipped[$group]) && !in_array($group, $missingGroups)) {
            $missingGroups[] = $group;
        }
        if ($type !== '' && !isset($part__type_flipped[$type]) && !in_array($type, $missingTypes)) {
            $missingTypes[] = $type;
        }
        if (!isset($part__unit_flipped[$unit]) && !in_array($unit, $missingUnits)) {
            $missingUnits[] = $unit;
        }
    }

    if (!empty($missingGroups)) {
        $MsaDB->insertBulk('part__group', ['name'], array_map(fn($g) => [$g], $missingGroups));
        $part__group_flipped = array_flip($MsaDB->readIdName('part__group'));
    }
    if (!empty($missingTypes)) {
        $MsaDB->insertBulk('part__type', ['name'], array_map(fn($t) => [$t], $missingTypes));
        $part__type_flipped = array_flip($MsaDB->readIdName('part__type'));
    }
    if (!empty($missingUnits)) {
        $MsaDB->insertBulk('part__unit', ['name'], array_map(fn($u) => [$u], $missingUnits));
        $part__unit_flipped = array_flip($MsaDB->readIdName('part__unit'));
    }

    if (!empty($newParts)) {
        $rowsToInsert = array_map(function($row) use ($part__group_flipped, $part__type_flipped, $part__unit_flipped){
            $partGroup = trim($row[3]);
            $partType = trim($row[4]);
            $partUnit = trim($row[5]);

            return [
                $row[0],
                trim($row[1]),
                trim($row[2]),
                $part__group_flipped[$partGroup],
                $partType == '' ? null : $part__type_flipped[$partType],
                $part__unit_flipped[$partUnit]
            ];
        }, $newParts);

        $MsaDB -> insertBulk('list__parts', $columnsToInsert, $rowsToInsert);
    }

    if (!empty($editedParts)) {
        foreach ($editedParts as $row) {
            $partGroup = trim($row[3]);
            $partType = trim($row[4]);
            $partUnit = trim($row[5]);

            $updateValues = [
                'name' => trim($row[1]),
                'description' => trim($row[2]),
                'PartGroup' => $part__group_flipped[$partGroup],
                'PartType' => $partType == '' ? null : $part__type_flipped[$partType],
                'JM' => $part__unit_flipped[$partUnit]
            ];

            $MsaDB->update('list__parts', $updateValues, 'id', $row[0]);
        }
    }

    $MsaDB -> db -> commit();
} catch (\Throwable $e) {
    $wasSuccessful = false;
    $errorMessage = $e -> getMessage();
    $MsaDB -> db -> rollBack();
}

echo json_encode([
    "wasSuccessful" => $wasSuccessful,
    "errorMessage" => $errorMessage
]);