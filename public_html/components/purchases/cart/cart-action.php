<?php
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Master\VendorPartRepository;
use Atte\Utils\Purchase\Order\PurchaseActionHandler;
use Atte\Utils\Purchase\Order\PurchaseOrderRepository;
use Atte\Utils\Purchase\Order\PurchaseOrderItemRepository;
use Atte\Utils\Purchase\Order\RFQRepository;
use Atte\Utils\Purchase\Order\RFQItemRepository;

if(!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'Brak uprawnień.']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

// JS posts standard form-encoded data; $_POST is the right reader.
$payload = $_POST;

$docType  = $payload['doc_type']  ?? '';
$vendorId = (int)($payload['vendor_id'] ?? 0);
$date     = !empty($payload['date'])     ? $payload['date']     : null;
$comment  = !empty($payload['comment'])  ? $payload['comment']  : null;
$itemsRaw = $payload['items'] ?? '[]';
$items    = json_decode($itemsRaw, true);
if (!is_array($items)) {
    echo json_encode(['success' => false, 'error' => 'Nieprawidłowy format pozycji.']);
    exit;
}

if (!in_array($docType, ['rfq', 'po'], true)) {
    echo json_encode(['success' => false, 'error' => 'Nieprawidłowy typ dokumentu.']);
    exit;
}
if ($vendorId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Wybierz dostawcę.']);
    exit;
}
if (count($items) === 0) {
    echo json_encode(['success' => false, 'error' => 'Dodaj co najmniej jedną pozycję.']);
    exit;
}

// In the new part-first discovery UX the cart holds items from
// multiple vendors; each "Utwórz" button submits only the items
// for its own vendor. Filter here before validation/insertion.
if ($vendorId > 0) {
    $items = array_values(array_filter($items, function ($i) use ($vendorId) {
        return isset($i['vendor_id']) && (int)$i['vendor_id'] === $vendorId;
    }));
    if (count($items) === 0) {
        echo json_encode(['success' => false, 'error' => 'Brak pozycji dla wybranego dostawcy.']);
        exit;
    }
}

// Validate every item
foreach ($items as $i => $item) {
    $vpId = (int)($item['vendor_part_id'] ?? 0);
    $qty  = (float)($item['quantity']     ?? 0);
    if ($vpId <= 0 || $qty <= 0) {
        echo json_encode(['success' => false, 'error' => "Pozycja #" . ($i + 1) . " ma nieprawidłowe dane."]);
        exit;
    }
}

$MsaDB       = MsaDB::getInstance();
$handler     = new PurchaseActionHandler($MsaDB);
$vpRepo      = new VendorPartRepository($MsaDB);
$userId      = (int)$_SESSION['user_id'];

try {
    // Outer transaction wraps number allocation + document create + item inserts.
    $MsaDB->db->beginTransaction();

    $docId = $handler->createDocument($docType, $vendorId, $userId);

    if ($date !== null || $comment !== null) {
        if ($docType === 'rfq') {
            (new RFQRepository($MsaDB))->update($docId, $vendorId, $date, $comment);
        } else {
            (new PurchaseOrderRepository($MsaDB))->update($docId, $vendorId, $date, $comment);
        }
    }

    if ($docType === 'rfq') {
        $itemRepo = new RFQItemRepository($MsaDB);
        foreach ($items as $item) {
            $vpId = (int)$item['vendor_part_id'];
            $vp   = $vpRepo->getById($vpId);
            if (!$vp) {
                throw new \RuntimeException("Nie znaleziono artykułu $vpId");
            }
            $itemRepo->create(
                $docId,
                $vpId,
                (float)$item['quantity'],
                $vp->vendorJmId,
                isset($item['unit_price']) && $item['unit_price'] !== ''
                    ? (float)$item['unit_price']
                    : null,
                $item['currency'] ?? 'PLN'
            );
        }
    } else {
        $itemRepo = new PurchaseOrderItemRepository($MsaDB);
        foreach ($items as $item) {
            $vpId = (int)$item['vendor_part_id'];
            $vp   = $vpRepo->getById($vpId);
            if (!$vp) {
                throw new \RuntimeException("Nie znaleziono artykułu $vpId");
            }
            $itemRepo->create(
                $docId,
                $vpId,
                (float)$item['quantity'],
                $vp->vendorJmId,
                isset($item['unit_price']) && $item['unit_price'] !== ''
                    ? (float)$item['unit_price']
                    : 0,
                $item['currency'] ?? 'PLN'
            );
        }
    }

    $MsaDB->db->commit();

    echo json_encode([
        'success'  => true,
        'doc_id'   => $docId,
        'doc_type' => $docType,
        // RFQ/PO edit pages were removed with the old list pages; route
        // to the combined documents placeholder until it exists.
        'redirect' => '/admin/purchase/documents',
    ]);
} catch (\Throwable $e) {
    if ($MsaDB->db->inTransaction()) {
        $MsaDB->db->rollBack();
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
