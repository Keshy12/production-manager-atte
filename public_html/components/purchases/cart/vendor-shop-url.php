<?php
/**
 * AJAX: inline edit of a vendor's shop URL from the cart variants table.
 *
 * POST: vendor_id (int > 0), shop_url (string; '' clears it)
 * Response: {success:true, vendor_id, shop_url (normalised|null)}
 *           | {success:false, error}
 */
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Master\VendorRepository;

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

$vendorId = (int)($_POST['vendor_id'] ?? 0);
if ($vendorId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Nieprawidłowy dostawca.']);
    exit;
}

try {
    $shopUrl = VendorRepository::normalizeShopUrl($_POST['shop_url'] ?? null);

    $MsaDB = MsaDB::getInstance();
    $repo  = new VendorRepository($MsaDB);
    if ($repo->getById($vendorId) === null) {
        echo json_encode(['success' => false, 'error' => 'Nie znaleziono dostawcy #' . $vendorId . '.']);
        exit;
    }
    $repo->updateShopUrl($vendorId, $shopUrl);

    echo json_encode([
        'success'   => true,
        'vendor_id' => $vendorId,
        'shop_url'  => $shopUrl,
    ]);
} catch (\InvalidArgumentException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (\Throwable $e) {
    echo json_encode(['success' => false, 'error' => 'Błąd zapisu adresu sklepu: ' . $e->getMessage()]);
}
exit;
