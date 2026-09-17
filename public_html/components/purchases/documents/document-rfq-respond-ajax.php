<?php
/**
 * AJAX: oznacza zapytanie ofertowe (RFQ) jako `responded` —
 * operator zarejestrował odpowiedź od dostawcy. Endpoint dla
 * wizarda "Wyślij dokument" (krok po otrzymaniu cennika).
 *
 * Kontrakt wejścia/wyjścia zamrożony — frontend (send-view.js) z niego
 * korzysta bezpośrednio.
 *
 * POST (form-encoded):
 *   id             int                    (wymagane, > 0)
 *   responded_at   'Y-m-d H:i:s' | 'Y-m-d' | ''
 *                                  opcjonalne — data odpowiedzi.
 *                                  'Y-m-d' normalizowane do
 *                                  'Y-m-d 00:00:00'; puste → null,
 *                                  handler użyje NOW().
 *
 * Odpowiedź sukces:
 *   {
 *     success:     true,
 *     state:       'responded',
 *     state_label: 'Odpowiedź'
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

$id            = (int)($_POST['id'] ?? 0);
$respondedAtRaw = $_POST['responded_at'] ?? null;

if ($id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Nieprawidłowy identyfikator zapytania.']);
    exit;
}

// Normalizacja responded_at: 'Y-m-d' → 'Y-m-d 00:00:00'; puste → null.
// Handler waliduje ściśle 'Y-m-d H:i:s', więc endpoint jest właścicielem
// tej konwersji (identycznie jak document-send-ajax.php dla sent_at).
$respondedAt = null;
if ($respondedAtRaw !== null) {
    $respondedAtRaw = trim((string)$respondedAtRaw);
    if ($respondedAtRaw !== '') {
        $respondedAt = $respondedAtRaw;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $respondedAt)) {
            $respondedAt .= ' 00:00:00';
        }
    }
}

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Brak zalogowanego użytkownika.']);
    exit;
}

$MsaDB   = MsaDB::getInstance();
$handler = new PurchaseActionHandler($MsaDB);

try {
    $handler->markRfqResponded($id, $userId, $respondedAt);

    echo json_encode([
        'success'     => true,
        'state'       => 'responded',
        'state_label' => 'Odpowiedź',
    ]);
} catch (\LogicException | \InvalidArgumentException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (\Throwable $e) {
    error_log('document-rfq-respond error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Błąd oznaczania odpowiedzi.']);
}
exit;