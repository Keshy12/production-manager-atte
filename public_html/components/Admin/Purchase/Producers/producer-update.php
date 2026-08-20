<?php
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Master\ProducerRepository;

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

$id      = (int)($_POST['id'] ?? 0);
$name    = trim($_POST['name'] ?? '');
$comment = $_POST['comment'] ?? null;
if($comment !== null) { $comment = trim($comment); }
if($comment === '')   { $comment = null; }

if($id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Nieprawidłowe ID producenta']);
    exit;
}

try {
    $MsaDB = MsaDB::getInstance();
    $repo  = new ProducerRepository($MsaDB);
    $repo->update($id, $name, $comment);
    echo json_encode(['success' => true, 'message' => 'Producent zaktualizowany pomyślnie']);
} catch (\InvalidArgumentException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (\Throwable $e) {
    error_log('producer-update error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Wystąpił błąd podczas aktualizacji producenta']);
}
exit;
