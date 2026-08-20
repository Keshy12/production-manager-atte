<?php
use Atte\DB\MsaDB;
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

$id                = (int)($_POST['id'] ?? 0);
$vendorPoNumberRaw = $_POST['vendor_po_number'] ?? null;

if ($id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Nieprawidłowe ID zamówienia']);
    exit;
}
if ($vendorPoNumberRaw === null || trim((string)$vendorPoNumberRaw) === '') {
    echo json_encode(['success' => false, 'error' => 'Numer potwierdzenia u dostawcy jest wymagany']);
    exit;
}
$vendorPoNumber = trim((string)$vendorPoNumberRaw);

try {
    $MsaDB = MsaDB::getInstance();
    $repo  = new PurchaseOrderRepository($MsaDB);
    $po    = $repo->getById($id);
    if ($po === null) {
        echo json_encode(['success' => false, 'error' => 'Nie znaleziono zamówienia']);
        exit;
    }
    if ($po->state !== 'sent') {
        echo json_encode(['success' => false, 'error' => 'Zamówienie nie jest w stanie wysłanym']);
        exit;
    }

    $MsaDB->db->beginTransaction();
    try {
        $repo->setVendorPoNumber($id, $vendorPoNumber);
        $repo->setState($id, 'confirmed');
        $repo->setConfirmedAt($id, date('Y-m-d H:i:s'));
        $MsaDB->db->commit();
        echo json_encode([
            'success'   => true,
            'po_number' => $po->poNumber,
            'message'   => 'Zamówienie potwierdzone',
        ]);
    } catch (\Throwable $e) {
        if ($MsaDB->db->inTransaction()) { $MsaDB->db->rollBack(); }
        throw $e;
    }
} catch (\LogicException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (\Throwable $e) {
    error_log('order-confirm error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Wystąpił błąd podczas potwierdzania zamówienia']);
}
exit;
