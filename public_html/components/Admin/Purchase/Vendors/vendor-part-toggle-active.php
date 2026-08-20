<?php
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Master\VendorPartRepository;

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

$id       = (int)($_POST['id'] ?? 0);
$isActive = isset($_POST['is_active']) && in_array((string)$_POST['is_active'], ['1', 'true', 'on'], true);

if($id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Nieprawidłowe ID artykułu']);
    exit;
}

try {
    $MsaDB = MsaDB::getInstance();
    $repo  = new VendorPartRepository($MsaDB);
    $repo->toggleActive($id, $isActive);
    $msg = $isActive ? 'Artykuł włączony' : 'Artykuł wyłączony';
    echo json_encode(['success' => true, 'is_active' => $isActive, 'message' => $msg]);
} catch (\Throwable $e) {
    error_log('vendor-part-toggle-active error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Wystąpił błąd podczas zmiany statusu']);
}
exit;
