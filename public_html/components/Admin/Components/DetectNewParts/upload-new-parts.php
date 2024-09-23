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
    $rowsToInsert = array_map(function($row) use ($part__group_flipped, $part__type_flipped, $part__unit_flipped){
        if (!isset($part__group_flipped[$row[3]]) 
            || ($row[4] !== '' && !isset($part__type_flipped[$row[4]])) 
            || !isset($part__unit_flipped[$row[5]])) {
            throw new \Exception("One or more values are not set in the flipped array.");
        }
        return [
            $row[0],
            $row[1],
            $row[2],
            $part__group_flipped[$row[3]],
            $row[4] == '' ? null : $part__type_flipped[$row[4]],
            $part__unit_flipped[$row[5]]
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


