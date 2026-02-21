<?php
use Atte\DB\MsaDB;

$MsaDB = MsaDB::getInstance();

$bomId = $_POST['bomId'];
$quantity = $_POST['quantity'];

$wasSuccessful = true;
$errorMessage = '';

try {
    $MsaDB->update('bom__tht', ['out_tht_quantity' => $quantity], 'id', $bomId);
} catch (Exception $e) {
    $wasSuccessful = false;
    $errorMessage = $e->getMessage();
}

echo json_encode([$wasSuccessful, $errorMessage], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
