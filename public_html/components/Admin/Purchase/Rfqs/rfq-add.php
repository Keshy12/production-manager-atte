<?php
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Order\PurchaseActionHandler;
use Atte\Utils\Purchase\Order\RFQRepository;

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

$vendorId          = (int)($_POST['vendor_id'] ?? 0);
$expectedReplyDate = $_POST['expected_reply_date'] ?? null;
$comment           = $_POST['comment'] ?? null;
if ($expectedReplyDate === '') $expectedReplyDate = null;
if ($comment !== null) { $comment = trim($comment); if ($comment === '') $comment = null; }

if ($vendorId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Wybierz dostawcę']);
    exit;
}

try {
    $MsaDB    = MsaDB::getInstance();
    $handler  = new PurchaseActionHandler($MsaDB);
    $userId   = (int)($_SESSION['user_id'] ?? 0);
    $newId    = $handler->createDocument('rfq', $vendorId, $userId);

    // expected_reply_date + comment are not part of createDocument's signature;
    // apply them with a follow-up update so the user's draft carries them.
    if ($expectedReplyDate !== null || $comment !== null) {
        $repo = new RFQRepository($MsaDB);
        $repo->update($newId, $vendorId, $expectedReplyDate, $comment);
    }

    $rfq = (new RFQRepository($MsaDB))->getById($newId);
    $rfqNumber = $rfq ? $rfq->rfqNumber : null;

    echo json_encode([
        'success'    => true,
        'id'         => $newId,
        'rfq_number' => $rfqNumber,
        'message'    => 'Zapytanie dodane pomyślnie',
    ]);
} catch (\InvalidArgumentException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (\LogicException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (\Throwable $e) {
    error_log('rfq-add error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Wystąpił błąd podczas dodawania zapytania']);
}
exit;
