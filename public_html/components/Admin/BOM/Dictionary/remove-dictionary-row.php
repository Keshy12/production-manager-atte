<?php
use Atte\DB\MsaDB;

$MsaDB = MsaDB::getInstance();

$dictionaryType = $_POST['dictionaryType'];
$rowId = $_POST['rowId'];

$wasSuccessful = $MsaDB -> deleteById($dictionaryType, $rowId);

echo json_encode($wasSuccessful);


        