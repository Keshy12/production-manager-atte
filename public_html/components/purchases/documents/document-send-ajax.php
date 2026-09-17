<?php
/**
 * AJAX: wysyła dokument (RFQ lub PO) — ustawia stan `sent`, stempluje
 * `sent_at` i opcjonalnie wstawia placeholder PDF. Endpoint dla
 * wizarda "Wyślij dokument" (Admin/Purchase/Documents/documents-send.php).
 *
 * Kontrakt wejścia/wyjścia zamrożony — frontend (send-view.js) z niego
 * korzysta bezpośrednio, więc kształt odpowiedzi (snake_case + polskie
 * `state_label`) jest obowiązujący.
 *
 * POST (form-encoded, parsowany przez PHP do $_POST):
 *   type           'rfq' | 'po'           (wymagane)
 *   id             int                    (wymagane, > 0)
 *   sent_at        'Y-m-d H:i:s' | 'Y-m-d' | ''
 *                                  opcjonalne — data wysłania.
 *                                  'Y-m-d' normalizowane do
 *                                  'Y-m-d 00:00:00'; puste → null,
 *                                  handler użyje NOW().
 *   generate_pdf   '1' | '0'              (domyślnie '1' → placeholder PDF)
 *
 * Odpowiedź sukces:
 *   {
 *     success:           true,
 *     document_number:   string,         // 'RFQ/2026/0007' | 'PO/2026/0042'
 *     state:             'sent',
 *     state_label:       'Wysłane',
 *     pdf_placeholder:   bool
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
use Atte\Utils\Purchase\Order\RFQRepository;

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

$type        = (string)($_POST['type'] ?? '');
$id          = (int)($_POST['id'] ?? 0);
$sentAtRaw   = $_POST['sent_at'] ?? null;
$generatePdf = !isset($_POST['generate_pdf']) || $_POST['generate_pdf'] === '1'
            || $_POST['generate_pdf'] === 1
            || $_POST['generate_pdf'] === true;

if (!in_array($type, ['rfq', 'po'], true)) {
    echo json_encode(['success' => false, 'error' => 'Nieprawidłowy typ dokumentu.']);
    exit;
}
if ($id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Nieprawidłowy identyfikator dokumentu.']);
    exit;
}

// Normalizacja sent_at: 'Y-m-d' → 'Y-m-d 00:00:00'; puste → null.
// Handler waliduje ściśle 'Y-m-d H:i:s', więc endpoint jest właścicielem
// tej konwersji (identycznie jak receipt-create.php dla received_at).
$sentAt = null;
if ($sentAtRaw !== null) {
    $sentAtRaw = trim((string)$sentAtRaw);
    if ($sentAtRaw !== '') {
        $sentAt = $sentAtRaw;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $sentAt)) {
            $sentAt .= ' 00:00:00';
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

// Polskie etykiety stanów — mirror documents-view.php (linie 122-133).
// Trzymane inline w endpointach zamiast w handlerze, bo są prezentacyjne
// i należą do warstwy UI. Identyczne mapowanie RFQ/PO jak w cart-view.js.
$rfqStateLabels = [
    'draft'     => 'Szkic',
    'sent'      => 'Wysłane',
    'responded' => 'Odpowiedź',
    'converted' => 'Przekonwertowane',
    'cancelled' => 'Anulowane',
];
$poStateLabels = [
    'draft'              => 'Szkic',
    'sent'               => 'Wysłane',
    'confirmed'          => 'Potwierdzone',
    'partially_received' => 'Częściowo odebrane',
    'received'           => 'Odebrane',
    'cancelled'          => 'Anulowane',
];

try {
    if ($type === 'rfq') {
        $handler->sendRfq($id, $userId, $sentAt, $generatePdf);
        $rfq = (new RFQRepository($MsaDB))->getById($id);
        $documentNumber = $rfq !== null ? ($rfq->rfqNumber ?? null) : null;
        $state          = $rfq !== null ? $rfq->state : 'sent';
        $stateLabel     = $rfqStateLabels[$state] ?? $state;
    } else {
        $handler->sendPo($id, $userId, $sentAt, $generatePdf);
        $po  = (new PurchaseOrderRepository($MsaDB))->getById($id);
        $documentNumber = $po !== null ? ($po->poNumber ?? null) : null;
        $state          = $po !== null ? $po->state : 'sent';
        $stateLabel     = $poStateLabels[$state] ?? $state;
    }

    echo json_encode([
        'success'         => true,
        'document_number' => $documentNumber,
        'state'           => $state,
        'state_label'     => $stateLabel,
        'pdf_placeholder' => $generatePdf,
    ]);
} catch (\LogicException | \InvalidArgumentException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (\Throwable $e) {
    error_log('document-send error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Błąd wysyłki dokumentu.']);
}
exit;