<?php
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Master\VendorRepository;

header('Content-Type: application/json; charset=utf-8');

if(!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Brak uprawnień']);
    exit();
}

if($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Metoda nieobsługiwana']);
    exit();
}

$name           = trim($_POST['name'] ?? '');
$address        = $_POST['address'] ?? null;
$additionalData = $_POST['additional_data'] ?? null;
$leadTimeDays   = $_POST['lead_time_days'] ?? null;
$comment        = $_POST['comment'] ?? null;

foreach (['address' => &$address, 'additional_data' => &$additionalData, 'comment' => &$comment] as $k => &$v) {
    if($v !== null) { $v = trim($v); if($v === '') { $v = null; } }
}
unset($v);

if($leadTimeDays !== null && $leadTimeDays !== '') {
    if(!is_numeric($leadTimeDays) || (int)$leadTimeDays < 0) {
        echo json_encode(['success' => false, 'error' => 'Lead time musi być nieujemną liczbą']);
        exit;
    }
    $leadTimeDays = (int)$leadTimeDays;
} else {
    $leadTimeDays = null;
}

try {
    $MsaDB = MsaDB::getInstance();
    $repo  = new VendorRepository($MsaDB);
    $id    = $repo->create($name, $address, $additionalData, $leadTimeDays, $comment);
    echo json_encode(['success' => true, 'id' => $id, 'message' => 'Dostawca dodany pomyślnie']);
} catch (\InvalidArgumentException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (\Throwable $e) {
    error_log('vendor-add error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Wystąpił błąd podczas dodawania dostawcy']);
}
exit;
