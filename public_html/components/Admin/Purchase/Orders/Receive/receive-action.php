<?php
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Order\PurchaseActionHandler;
use Atte\Utils\Purchase\Order\PurchaseOrderRepository;

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

$poId          = (int)($_POST['po_id'] ?? 0);
$subMagazineId = (int)($_POST['sub_magazine_id'] ?? 0);
$documentNumber= $_POST['document_number'] ?? null;
$comment       = $_POST['comment'] ?? null;
$rawItems      = $_POST['items'] ?? [];

if ($documentNumber !== null) { $documentNumber = trim($documentNumber); if ($documentNumber === '') $documentNumber = null; }
if ($comment !== null)      { $comment = trim($comment); if ($comment === '') $comment = null; }

if ($poId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Brak ID zamówienia']);
    exit;
}
if ($subMagazineId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Wybierz magazyn docelowy']);
    exit;
}
if (!is_array($rawItems) || empty($rawItems)) {
    echo json_encode(['success' => false, 'error' => 'Brak pozycji do przyjęcia']);
    exit;
}

// Re-validate PO state + magazine activity + line ownership + lenient cap on the
// server side (mirror of the handler's authoritative check). Doing it here gives
// the admin a clean per-field error before the transaction starts.
try {
    $MsaDB = MsaDB::getInstance();
    $poRepo = new PurchaseOrderRepository($MsaDB);
    $po = $poRepo->getById($poId);
    if ($po === null) {
        echo json_encode(['success' => false, 'error' => 'Nie znaleziono zamówienia']);
        exit;
    }
    if (!in_array($po->state, ['confirmed','partially_received'], true)) {
        echo json_encode(['success' => false, 'error' => 'Zamówienie nie jest w stanie pozwalającym na przyjęcie']);
        exit;
    }

    $magStmt = $MsaDB->db->prepare("SELECT isActive FROM `magazine__list` WHERE sub_magazine_id = ?");
    $magStmt->execute([$subMagazineId]);
    $magRow = $magStmt->fetch(\PDO::FETCH_ASSOC);
    if ($magRow === false) {
        echo json_encode(['success' => false, 'error' => 'Magazyn docelowy nie istnieje']);
        exit;
    }
    if ((int)$magRow['isActive'] !== 1) {
        echo json_encode(['success' => false, 'error' => 'Magazyn docelowy jest nieaktywny']);
        exit;
    }

    $epsilon = 1e-6;
    $normalized = [];
    foreach ($rawItems as $idx => $raw) {
        $poItemId   = (int)($raw['po_item_id']         ?? 0);
        $qtyRecvRaw = $raw['quantity_received']      ?? null;
        $lineComment= $raw['comment']                 ?? null;

        if ($poItemId <= 0) {
            echo json_encode(['success' => false, 'error' => "Pozycja #{$idx}: brak po_item_id"]);
            exit;
        }
        if (!is_numeric($qtyRecvRaw) || (float)$qtyRecvRaw <= 0) {
            echo json_encode(['success' => false, 'error' => "Pozycja #{$idx}: ilość musi być > 0"]);
            exit;
        }
        $qtyRecv = (float)$qtyRecvRaw;

        // Look up the PO item to enforce ownership + lenient cap.
        $piStmt = $MsaDB->db->prepare("SELECT id, quantity, quantity_received FROM `purchase__order_item` WHERE id = ? AND po_id = ?");
        $piStmt->execute([$poItemId, $poId]);
        $piRow = $piStmt->fetch(\PDO::FETCH_ASSOC);
        if ($piRow === false) {
            echo json_encode(['success' => false, 'error' => "Pozycja #{$idx} (po_item_id {$poItemId}) nie należy do tego zamówienia"]);
            exit;
        }
        $remaining = ((float)$piRow['quantity']) - ((float)$piRow['quantity_received']);
        $maxAllowed = $remaining * 1.10 + $epsilon;
        $newRunning = ((float)$piRow['quantity_received']) + $qtyRecv;
        if ($newRunning > $maxAllowed) {
            echo json_encode(['success' => false, 'error' => "Pozycja #{$idx}: przekroczony limit 110% (max {$maxAllowed}, wpisano {$newRunning})"]);
            exit;
        }

        if ($lineComment !== null) {
            $lineComment = trim((string)$lineComment);
            if ($lineComment === '') $lineComment = null;
        }

        $normalized[] = [
            'po_item_id'        => $poItemId,
            'quantity_received' => $qtyRecv,
            'sub_magazine_id'   => $subMagazineId,
            'comment'           => $lineComment,
        ];
    }

    $MsaDB    = MsaDB::getInstance();
    $handler  = new PurchaseActionHandler($MsaDB);
    $userId   = (int)($_SESSION['user_id'] ?? 0);
    $receiptId = $handler->createReceipt(
        $poId,
        $userId,
        $normalized,
        $documentNumber,
        $comment
    );

    echo json_encode([
        'success'    => true,
        'receipt_id' => $receiptId,
        'redirect'   => 'http://' . BASEURL . '/admin/purchase/receipts',
        'message'    => 'Przyjęcie zapisane',
    ]);
} catch (\InvalidArgumentException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (\LogicException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (\RuntimeException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (\Throwable $e) {
    error_log('receive-action error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Wystąpił błąd podczas zapisywania przyjęcia']);
}
exit;
