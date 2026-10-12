<?php
/**
 * AJAX: zamknij zamówienie (PO) ze strony formularza przyjęcia —
 * operator wybiera stan wynikowy (`received` albo `cancelled`).
 *
 * Wywoływany z dwóch miejsc:
 *   1. okno „Przyjęcie zapisane" (post-save) → bez `target_state`,
 *      traktowane jak `received` (zachowanie wstecz);
 *   2. nowy przycisk „Zamknij zamówienie" w pasku akcji formularza
 *      (kroki 1 i 2 kreatora) → `target_state` z radia w oknie
 *      potwierdzenia, `received` lub `cancelled`.
 *
 * Kształt endpointu zgodny z AGENTS.md (admin gate, method gate,
 * jQuery form-encoded $_POST, walidacja jako JSON 200):
 *
 *   POST po_id        int    > 0, wymagane
 *   POST target_state string 'received' (domyślnie gdy brak/pusty)
 *                          albo 'cancelled'
 *
 * Guardy (obsługiwane w PurchaseActionHandler::closePoFromReceive —
 * ten endpoint NIE zapisuje stanu samodzielnie):
 *   - PO musi istnieć;
 *   - stan PO musi być `confirmed`, `in_progress` albo
 *     `partially_received` — każdy inny (już odebrane, szkic,
 *     wysłane, anulowane) jest odrzucany z polskim komunikatem
 *     nawiązującym do aktualnego stanu;
 *   - brak wymogu istniejącego wiersza `purchase__order_receipt` —
 *     to jest świadoma różnica wobec dawnego `closePoAsReceived()`:
 *     pozwala zamknąć PO, na którym nic nie przyjęto (np. confirmed →
 *     cancelled, gdy dostawa się nie odbędzie).
 *
 * Odpowiedź na sukces:
 *   {success: true, po_state: 'received'|'cancelled',
 *    po_state_label: 'Odebrane'|'Anulowane'}
 *
 * Odpowiedź na błąd walidacji / guarda: HTTP 200 + JSON
 *   {success: false, error: '<polski komunikat>'}
 *
 * Obsługa wyjątków:
 *   - \LogicException / \InvalidArgumentException z handlera niosą
 *     komunikaty zrozumiałe dla operatora (guardy stanu, brak PO,
 *     nieprawidłowy `target_state`). Zwracane jako JSON
 *     {success:false, error: <komunikat>}.
 *   - Każdy inny \Throwable → generyczny polski komunikat +
 *     error_log z prawdziwym wyjątkiem. Surowy tekst wyjątku nigdy
 *     nie wycieka do klienta.
 *
 * Efekt uboczny (ważny): oba stany wynikowe są terminalne — po
 * `received` `OrderReceiptRepository::delete()` odmawia kasowania
 * przyjęcia dla tego PO (zob. delete() w
 * class-orderreceiptrepository.php); `cancelled` wyłącza PO z
 * kolejki i listy aktywnych dokumentów. UI musi to jasno
 * komunikować przed wywołaniem.
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

// target_state: brak albo puste → 'received' (zachowanie wstecz dla
// okna „Przyjęcie zapisane", które tego parametru nie wysyła).
// Jawne wartości inne niż dozwolone → JSON-200 z błędem walidacji
// (nie 4xx — JS obsługuje komunikat przez setAlert).
$rawTargetState = $_POST['target_state'] ?? 'received';
if ($rawTargetState === null || $rawTargetState === '') {
    $rawTargetState = 'received';
}
$rawTargetState = (string)$rawTargetState;
if (!in_array($rawTargetState, ['received', 'cancelled'], true)) {
    echo json_encode(['success' => false, 'error' => 'Nieprawidłowy stan zamknięcia.']);
    exit;
}
$targetState = $rawTargetState;

// user_id z sesji — ten sam wzorzec co receipt-accept.php i
// receipt-create.php. Brak zalogowanego użytkownika → JSON, nie wyjątek.
$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Brak zalogowanego użytkownika.']);
    exit;
}

$MsaDB   = MsaDB::getInstance();
$handler = new PurchaseActionHandler($MsaDB);

// Mapa stan → etykieta dla UI. Trzymana w endpoincie, żeby JS
// nie musiał duplikować tej logiki (stan → polska etykieta).
$stateLabels = [
    'received'  => 'Odebrane',
    'cancelled' => 'Anulowane',
];

try {
    // Handler jest jedynym miejscem, które decyduje o zamknięciu PO
    // — sam endpoint nie pisze do `purchase__order.state`.
    // closePoFromReceive() dołącza otwartą transakcję wywołującego,
    // więc żaden endpoint nie musi otaczać tego wywołania własną.
    $result = $handler->closePoFromReceive($poId, $userId, $targetState);

    echo json_encode([
        'success'        => true,
        'po_state'       => $result['poState'],
        'po_state_label' => $stateLabels[$result['poState']] ?? $result['poState'],
    ]);
} catch (\LogicException | \InvalidArgumentException $e) {
    // Walidacyjne wyjątki z handlera — komunikaty zrozumiałe dla operatora.
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (\Throwable $e) {
    error_log('receipt-close-po error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Błąd zamykania zamówienia.']);
}
exit;