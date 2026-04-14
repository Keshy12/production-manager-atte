<?php
use Atte\DB\MsaDB;

$MsaDB = MsaDB::getInstance();

$MsaDB -> db -> beginTransaction();

// We flip the arrays, to make searching for values faster.
$part__group_flipped = array_flip($MsaDB->readIdName('part__group'));
$part__type_flipped = array_flip($MsaDB->readIdName('part__type'));
$part__unit_flipped = array_flip($MsaDB->readIdName('part__unit'));

$newParts = json_decode(json: $_POST['newParts'], associative: true);


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
    $missingGroups = [];
    $missingTypes = [];
    $missingUnits = [];

    foreach ($newParts as $row) {
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

    $rowsToInsert = array_map(function($row) use ($part__group_flipped, $part__type_flipped, $part__unit_flipped){
        $partGroup = trim($row[3]);
        $partType = trim($row[4]);
        $partUnit = trim($row[5]);

        if (!isset($part__group_flipped[$partGroup])) {
            throw new \Exception("PartGroup '{$partGroup}' not found in row id={$row[0]}");
        }
        if ($partType !== '' && !isset($part__type_flipped[$partType])) {
            throw new \Exception("PartType '{$partType}' not found in row id={$row[0]}");
        }
        if (!isset($part__unit_flipped[$partUnit])) {
            throw new \Exception("JM '{$partUnit}' not found in row id={$row[0]}");
        }

        return [
            $row[0],
            $row[1],
            $row[2],
            $part__group_flipped[$partGroup],
            $partType == '' ? null : $part__type_flipped[$partType],
            $part__unit_flipped[$partUnit]
        ];
    }, $newParts);

    $insertedIds = $MsaDB -> insertBulk('list__parts', $columnsToInsert, $rowsToInsert);
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


