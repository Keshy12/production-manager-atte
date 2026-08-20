<?php
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Order\RFQItemRepository;

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

$id    = (int)($_POST['id'] ?? 0);
$rfqId = (int)($_POST['rfq_id'] ?? 0);

if ($id <= 0 || $rfqId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Nieprawidłowe dane formularza']);
    exit;
}

try {
    $MsaDB = MsaDB::getInstance();
    $repo  = new RFQItemRepository($MsaDB);
    $existing = $repo->getById($id);
    if ($existing === null || $existing->rfqId !== $rfqId) {
        echo json_encode(['success' => false, 'error' => 'Pozycja nie należy do tego zapytania']);
        exit;
    }
    $repo->delete($id);
    echo json_encode(['success' => true, 'message' => 'Pozycja usunięta']);
} catch (\Throwable $e) {
    error_log('rfq-item-delete error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Wystąpił błąd podczas usuwania pozycji']);
}
exit;
