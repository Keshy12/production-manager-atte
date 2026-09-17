<?php
/**
 * AJAX: anuluje dokument (RFQ lub PO) — ustawia stan `cancelled`.
 * Endpoint dla wizarda "Wyślij dokument" (przycisk rezygnacji na
 * każdym kroku).
 *
 * Kontrakt wejścia/wyjścia zamrożony — frontend (send-view.js) z niego
 * korzysta bezpośrednio.
 *
 * POST (form-encoded):
 *   type   'rfq' | 'po'   (wymagane)
 *   id     int            (wymagane, > 0)
 *
 * Odpowiedź sukces:
 *   {
 *     success:     true,
 *     state:       'cancelled',
 *     state_label: 'Anulowane'
 *   }
 *
 * Obsługa wyjątków:
 *   - \LogicException / \InvalidArgumentException z handlera mają
 *     polskie komunikaty przeznaczone dla operatora → oddajemy w JSON
 *     z HTTP 200 (zgodnie z konwencją AGENTS.md dla endpointów).
 *   - Inny \Throwable → generyczny komunikat + error_log.
 */
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Order\PurchaseActionHandler;

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Brak uprawnień.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Metoda nieobsługiwana.']);
    exit;
}

$type = (string)($_POST['type'] ?? '');
$id   = (int)($_POST['id'] ?? 0);

if (!in_array($type, ['rfq', 'po'], true)) {
    echo json_encode(['success' => false, 'error' => 'Nieprawidłowy typ dokumentu.']);
    exit;
}
if ($id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Nieprawidłowy identyfikator dokumentu.']);
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Brak zalogowanego użytkownika.']);
    exit;
}

$MsaDB   = MsaDB::getInstance();
$handler = new PurchaseActionHandler($MsaDB);

try {
    if ($type === 'rfq') {
        $handler->cancelRfq($id, $userId);
    } else {
        $handler->cancelPo($id, $userId);
    }

    echo json_encode([
        'success'     => true,
        'state'       => 'cancelled',
        'state_label' => 'Anulowane',
    ]);
} catch (\LogicException | \InvalidArgumentException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (\Throwable $e) {
    error_log('document-cancel error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Błąd anulowania dokumentu.']);
}
exit;