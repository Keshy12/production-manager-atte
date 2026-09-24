<?php
/**
 * AJAX: konwertuje zapytanie ofertowe (RFQ) w stanie `responded` na
 * zamówienie (PO). Wywołuje PurchaseActionHandler::createPoFromRfq(),
 * który atomowo: tworzy PO z numerem `PO/<year>/<seq>`, kopiuje
 * pozycje RFQ (z `unit_price` = cena z odpowiedzi dostawcy), ustawia
 * stan RFQ na `converted`.
 *
 * Endpoint dla CTA "Utwórz zamówienie z odpowiedzi" na stronie
 * edycji RFQ (documents-edit.php) — renderowanego tylko w stanie
 * `responded`. Po stronie PHP handler zarządza własną transakcją
 * (beginTransaction/commit/rollBack), więc ten endpoint NIE
 * opakowuje wywołania w dodatkowy beginTransaction.
 *
 * Kontrakt wejścia/wyjścia zamrożony — frontend (documents-edit.js)
 * z niego korzysta bezpośrednio.
 *
 * POST (form-encoded):
 *   rfq_id  int  (wymagane, > 0)
 *
 * Odpowiedź sukces:
 *   {
 *     success:   true,
 *     edit_url:  'http://<BASEURL>/admin/purchase/documents/edit?id=<newPoId>&type=po'
 *   }
 *
 * Obsługa wyjątków:
 *   - \LogicException / \InvalidArgumentException z handlera mają
 *     polskie komunikaty przeznaczone dla operatora → oddajemy w JSON
 *     z HTTP 200 (zgodnie z konwencją AGENTS.md dla endpointów).
 *   - Inny \Throwable → generyczny komunikat + error_log. Surowy tekst
 *     wyjątku nigdy nie wycieka do klienta.
 */
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Order\PurchaseActionHandler;

header('Content-Type: application/json; charset=utf-8');

// Konwencja endpointów projektu (AGENTS.md): błędy walidacji / autoryzacji
// zwracamy zawsze z HTTP 200, a JS decyduje o UX po `success` w body.
// Stosujemy to spójnie dla wszystkich bramek (admin / metoda / input).
if (!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    echo json_encode(['success' => false, 'error' => 'Brak uprawnień']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Metoda niedozwolona']);
    exit;
}

$rfqIdRaw = $_POST['rfq_id'] ?? null;
$rfqId    = filter_var($rfqIdRaw, FILTER_VALIDATE_INT);
if ($rfqId === false || $rfqId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Nieprawidłowe ID RFQ']);
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Brak zalogowanego użytkownika']);
    exit;
}

$MsaDB   = MsaDB::getInstance();
$handler = new PurchaseActionHandler($MsaDB);

try {
    // createPoFromRfq() zarządza własną transakcją wewnętrznie —
    // zwraca int (id nowo utworzonego PO) lub rzuca wyjątek.
    $newPoId = $handler->createPoFromRfq($rfqId, $userId);

    echo json_encode([
        'success'   => true,
        'edit_url'  => 'http://' . BASEURL . '/admin/purchase/documents/edit?id=' . $newPoId . '&type=po',
    ]);
} catch (\LogicException | \InvalidArgumentException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (\Throwable $e) {
    error_log('document-rfq-convert error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Nie udało się utworzyć zamówienia']);
}
exit;
