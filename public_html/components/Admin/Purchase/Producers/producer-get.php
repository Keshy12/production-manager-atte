<?php
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Master\ProducerRepository;

header('Content-Type: application/json; charset=utf-8');

if(!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Brak uprawnień']);
    exit();
}

$id = (int)($_GET['id'] ?? 0);
if($id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Nieprawidłowe ID producenta']);
    exit;
}

try {
    $MsaDB = MsaDB::getInstance();
    $repo  = new ProducerRepository($MsaDB);
    $p     = $repo->getById($id);
    if($p === null) {
        echo json_encode(['success' => false, 'error' => 'Nie znaleziono producenta']);
    } else {
        echo json_encode([
            'success'  => true,
            'producer' => [
                'id'       => $p->id,
                'name'     => $p->name,
                'comment'  => $p->comment,
                'isActive' => $p->isActive,
            ],
        ]);
    }
} catch (\Throwable $e) {
    error_log('producer-get error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Wystąpił błąd podczas ładowania danych']);
}
exit;
