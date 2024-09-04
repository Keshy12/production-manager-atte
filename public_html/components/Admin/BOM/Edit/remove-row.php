<?php
use Atte\DB\MsaDB;

$MsaDB = MsaDB::getInstance();

$rowId = $_POST['rowId'];

$wasSuccessful = $MsaDB -> deleteById('bom__flat', $rowId);

echo json_encode($wasSuccessful);
