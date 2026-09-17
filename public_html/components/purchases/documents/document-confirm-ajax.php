<?php
/**
 * AJAX: potwierdza zamówienie (PO) — ustawia stan `confirmed`, opcjonalnie
 * zapisuje numer PO u dostawcy (`vendor_po_number`) i/lub datę potwierdzenia
 * (`confirmed_at`). Endpoint dla wizarda "Wyślij dokument"
 * (Admin/Purchase/Documents/documents-send.php).
 *
 * Kontrakt wejścia/wyjścia zamrożony — frontend (send-view.js) z niego
 * korzysta bezpośrednio, więc kształt odpowiedzi (snake_case + polskie
 * `state_label`) jest obowiązujący.
 *
 * POST (form-encoded, parsowany przez PHP do $_POST):
 *   id                int                (wymagane, > 0)
 *   vendor_po_number  string             (opcjonalne; trim → null gdy puste;
 *                                         handler obcina do 64 znaków)
 *   confirmed_at      'Y-m-d H:i:s' | 'Y-m-d' | ''
 *                                  opcjonalne — data potwierdzenia.
 *                                  'Y-m-d' normalizowane do 'Y-m-d 00:00:00';
 *                                  puste → null, handler użyje NOW().
 *
 * Odpowiedź sukces:
 *   {
 *     success:           true,
 *     document_number:   string|null,    // 'PO/2026/0042'
 *     state:             'confirmed',
 *     state_label:       'Potwierdzone',
 *     vendor_po_number:  string|null
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
use Atte\Utils\Purchase\Order\PurchaseOrderRepository;

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

$id                = (int)($_POST['id'] ?? 0);
$vendorPoNumberRaw = $_POST['vendor_po_number'] ?? null;
$confirmedAtRaw    = $_POST['confirmed_at'] ?? null;

if ($id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Nieprawidłowe ID dokumentu.']);
    exit;
}

// Normalizacja vendor_po_number: trim; puste → null. Handler dodatkowo
// obcina do 64 znaków (szerokość kolumny purchase__order.vendor_po_number).
$vendorPoNumber = null;
if ($vendorPoNumberRaw !== null) {
    $vendorPoNumberRaw = trim((string)$vendorPoNumberRaw);
    if ($vendorPoNumberRaw !== '') {
        $vendorPoNumber = $vendorPoNumberRaw;
    }
}

// Normalizacja confirmed_at: 'Y-m-d' → 'Y-m-d 00:00:00'; puste → null.
// Handler waliduje ściśle 'Y-m-d H:i:s', więc endpoint jest właścicielem
// tej konwersji (identycznie jak document-send-ajax.php dla sent_at).
$confirmedAt = null;
if ($confirmedAtRaw !== null) {
    $confirmedAtRaw = trim((string)$confirmedAtRaw);
    if ($confirmedAtRaw !== '') {
        $confirmedAt = $confirmedAtRaw;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $confirmedAt)) {
            $confirmedAt .= ' 00:00:00';
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
    $handler->confirmPo($id, $userId, $vendorPoNumber, $confirmedAt);

    $po = (new PurchaseOrderRepository($MsaDB))->getById($id);
    $documentNumber = $po !== null ? ($po->poNumber ?? null) : null;
    $vendorPoOut    = $po !== null ? ($po->vendorPoNumber ?? null) : null;

    echo json_encode([
        'success'          => true,
        'document_number'  => $documentNumber,
        'state'            => 'confirmed',
        'state_label'      => 'Potwierdzone',
        'vendor_po_number' => $vendorPoOut,
    ]);
} catch (\LogicException | \InvalidArgumentException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (\Throwable $e) {
    error_log('document-confirm error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Błąd potwierdzania dokumentu.']);
}
exit;
