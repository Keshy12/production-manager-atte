<?php
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Master\VendorSupplierRepository;

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
$name     = trim($_POST['name'] ?? '');
$jobTitle = $_POST['job_title'] ?? null;
$phone    = $_POST['phone'] ?? null;
$email    = $_POST['email'] ?? null;
$comment  = $_POST['comment'] ?? null;

foreach (['job_title' => &$jobTitle, 'phone' => &$phone, 'email' => &$email, 'comment' => &$comment] as $k => &$v) {
    if($v !== null) { $v = trim($v); if($v === '') { $v = null; } }
}
unset($v);

if($id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Nieprawidłowe ID osoby kontaktowej']);
    exit;
}

try {
    $MsaDB = MsaDB::getInstance();
    $repo  = new VendorSupplierRepository($MsaDB);
    $repo->update($id, $name, $jobTitle, $phone, $email, $comment);
    echo json_encode(['success' => true, 'message' => 'Osoba kontaktowa zaktualizowana pomyślnie']);
} catch (\InvalidArgumentException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (\Throwable $e) {
    error_log('supplier-update error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Wystąpił błąd podczas aktualizacji osoby kontaktowej']);
}
exit;
