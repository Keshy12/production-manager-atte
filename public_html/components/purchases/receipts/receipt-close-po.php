<?php
/**
 * AJAX: zamknij krótkie / „w trakcie przyjęcia" zamówienie (PO) jako
 * w pełni odebrane (`received`).
 *
 * Kształt endpointu zgodny z AGENTS.md (admin gate, method gate,
 * jQuery form-encoded $_POST, walidacja jako JSON 200):
 *
 *   POST po_id (int > 0, wymagane)
 *
 * Guardy (obsługiwane w PurchaseActionHandler::closePoAsReceived —
 * ten endpoint NIE zapisuje stanu samodzielnie):
 *   - PO musi istnieć;
 *   - stan PO musi być dokładnie `in_progress` albo `partially_received`
 *     — każdy inny (już odebrane, szkic, wysłane, potwierdzone,
 *     anulowane) jest odrzucany z polskim komunikatem;
 *   - PO musi mieć co najmniej jeden zapisany wiersz przyjęcia
 *     (`purchase__order_receipt`) — szkic `in_progress` (utworzony
 *     przez acceptReceiptMetadata) się liczy. Inaczej operator
 *     „zamykałby" PO, na którym nic nie zostało przyjęte.
 *
 * Odpowiedź na sukces:
 *   {success: true, po_state: 'received', po_state_label: 'Odebrane'}
 *
 * Odpowiedź na błąd walidacji / guarda: HTTP 200 + JSON
 *   {success: false, error: '<polski komunikat>'}
 *
 * Obsługa wyjątków:
 *   - \LogicException / \InvalidArgumentException z handlera niosą
 *     komunikaty zrozumiałe dla operatora (guardy stanu, brak PO,
 *     brak zapisanego przyjęcia). Zwracane jako JSON
 *     {success:false, error: <komunikat>}.
 *   - Każdy inny \Throwable → generyczny polski komunikat +
 *     error_log z prawdziwym wyjątkiem. Surowy tekst wyjątku nigdy
 *     nie wycieka do klienta.
 *
 * Efekt uboczny (ważny): stan `received` jest terminalny — po jego
 * ustawieniu OrderReceiptRepository::delete() odmawia kasowania
 * jakiegokolwiek przyjęcia dla tego PO (zob. delete() w
 * class-orderreceiptrepository.php). UI musi to jasno komunikować
 * przed wywołaniem.
 */
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Order\PurchaseActionHandler;

header('Content-Type: application/json; charset=utf-8');

if(!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Brak uprawnień']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Metoda nieobsługiwana']);
    exit;
}

$poId = (int)($_POST['po_id'] ?? 0);
if ($poId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Nieprawidłowe ID zamówienia.']);
    exit;
}

$MsaDB   = MsaDB::getInstance();
$handler = new PurchaseActionHandler($MsaDB);

try {
    // Handler jest jedynym miejscem, które decyduje o zamknięciu PO
    // jako odebranego — sam endpoint nie pisze do `purchase__order.state`.
    // closePoAsReceived() otwiera własną transakcję wokół setState().
    $result = $handler->closePoAsReceived($poId);

    echo json_encode([
        'success'        => true,
        'po_state'       => $result['poState'],
        'po_state_label' => 'Odebrane',
    ]);
} catch (\LogicException | \InvalidArgumentException $e) {
    // Walidacyjne wyjątki z handlera — komunikaty zrozumiałe dla operatora.
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (\Throwable $e) {
    error_log('receipt-close-po error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Błąd zamykania zamówienia.']);
}
exit;