<?php
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Master\VendorRepository;

header('Content-Type: application/json; charset=utf-8');

if(!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Brak uprawnień']);
    exit();
}

$id = (int)($_GET['id'] ?? 0);
if($id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Nieprawidłowe ID dostawcy']);
    exit;
}

try {
    $MsaDB = MsaDB::getInstance();
    $repo  = new VendorRepository($MsaDB);
    $v     = $repo->getById($id);
    if($v === null) {
        echo json_encode(['success' => false, 'error' => 'Nie znaleziono dostawcy']);
    } else {
        echo json_encode([
            'success' => true,
            'vendor'  => [
                'id'             => $v->id,
                'name'           => $v->name,
                'address'        => $v->address,
                'additionalData' => $v->additionalData,
                'leadTimeDays'   => $v->leadTimeDays,
                'comment'        => $v->comment,
                'isActive'       => $v->isActive,
            ],
        ]);
    }
} catch (\Throwable $e) {
    error_log('vendor-get error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Wystąpił błąd podczas ładowania danych']);
}
exit;
