<?php
/**
 * AJAX: delete one row of /admin/purchase/rfqs/edit.
 *
 * Irreversible. Confirmation lives on the client (Bootstrap modal).
 * Server-side just validates the id and delegates to the repository.
 *
 * Form-encoded payload (PHP $_POST):
 *   id = int   (required, > 0)
 *
 * Response:
 *   {success: true, message: 'Pozycja usunięta.'}     on delete
 *   {success: false, error: '...'}                     on validation/server error
 */
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Order\RFQItemRepository;

header('Content-Type: application/json; charset=utf-8');

if(!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Brak uprawnień.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Metoda nieobsługiwana']);
    exit();
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Nieprawidłowy identyfikator pozycji.']);
    exit;
}

$MsaDB = MsaDB::getInstance();
$rfiRepo = new RFQItemRepository($MsaDB);

try {
    $ok = $rfiRepo->delete($id);
    if (!$ok) {
        throw new \RuntimeException('Usunięcie nie powiodło się.');
    }
} catch (\Throwable $e) {
    error_log('rfq-item-delete: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Błąd usuwania: ' . $e->getMessage()]);
    exit;
}

echo json_encode(['success' => true, 'message' => 'Pozycja usunięta.']);
exit;