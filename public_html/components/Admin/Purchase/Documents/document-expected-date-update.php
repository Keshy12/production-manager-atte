<?php
/**
 * AJAX: set / clear the expected date of one document on
 * /admin/purchase/documents/edit.
 *   RFQ → purchase__rfq.expected_reply_date    ("Oczekiwana odpowiedź")
 *   PO  → purchase__order.expected_delivery_date ("Planowana dostawa")
 *
 * Form-encoded payload (PHP $_POST):
 *   type = 'rfq' | 'po'   (required)
 *   id   = int            (required, > 0)
 *   date = 'Y-m-d' | ''   (empty clears the date)
 *
 * Allowed only while the document is in state draft or sent.
 *
 * Response:
 *   {success: true, date: 'Y-m-d'|null, message: '...'}
 *   {success: false, error: '...'}
 */
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Order\PurchaseOrderRepository;
use Atte\Utils\Purchase\Order\RFQRepository;

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Brak uprawnień.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Metoda nieobsługiwana']);
    exit();
}

$type = (string)($_POST['type'] ?? '');
$id   = (int)($_POST['id'] ?? 0);
$date = trim((string)($_POST['date'] ?? ''));

if (!in_array($type, ['rfq', 'po'], true)) {
    echo json_encode(['success' => false, 'error' => 'Nieprawidłowy typ dokumentu.']);
    exit;
}
if ($id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Nieprawidłowy identyfikator dokumentu.']);
    exit;
}
if ($date !== '') {
    $d = \DateTime::createFromFormat('Y-m-d', $date);
    if ($d === false || $d->format('Y-m-d') !== $date) {
        echo json_encode(['success' => false, 'error' => 'Nieprawidłowa data (wymagany format RRRR-MM-DD).']);
        exit;
    }
}

$MsaDB = MsaDB::getInstance();
$repo  = $type === 'po' ? new PurchaseOrderRepository($MsaDB) : new RFQRepository($MsaDB);

try {
    $doc = $repo->getById($id);
    if ($doc === null) {
        echo json_encode(['success' => false, 'error' => 'Dokument nie istnieje.']);
        exit;
    }
    if (!in_array($doc->state, ['draft', 'sent'], true)) {
        echo json_encode(['success' => false, 'error' => 'Datę można zmieniać tylko w stanie szkic lub wysłane.']);
        exit;
    }

    $stored = $type === 'po'
        ? $repo->setExpectedDeliveryDate($id, $date)
        : $repo->setExpectedReplyDate($id, $date);

    echo json_encode(['success' => true, 'date' => $stored, 'message' => 'Data zapisana.']);
} catch (\Throwable $e) {
    error_log('document-expected-date-update: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Błąd zapisu daty.']);
}
exit;
