<?php
/**
 * AJAX: create a new VendorPart record for the currently selected
 * cart vendor. POST endpoint.
 *
 * POST params:
 *   vendor_id (int)        — required, must be the cart's selected vendor
 *   parts_id (int)         — required, FK to list__parts
 *   vendor_part_no (str)   — required, vendor's own part reference
 *   vendor_jm_id (int)     — required, FK to part__unit (vendor's JM)
 *   full_pack_quantity (flt) — optional, default 1
 *   comment (str)          — optional
 *
 * Response: {success, id, vendor_part_no, parts_id, vendor_jm_id,
 *            full_pack_quantity, message}
 *        or {success: false, error: "..."}
 */
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Master\VendorPartRepository;

if(!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'Brak uprawnień.']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$MsaDB = MsaDB::getInstance();

// Accept form-encoded or JSON body
$raw = json_decode(file_get_contents('php://input'), true);
if (!is_array($raw)) {
    parse_str(file_get_contents('php://input'), $raw);
}
if (empty($raw)) {
    $raw = $_POST;
}

$vendorId = (int)($raw['vendor_id'] ?? 0);
$partsId = (int)($raw['parts_id'] ?? 0);
$vendorPartNo = trim((string)($raw['vendor_part_no'] ?? ''));
$vendorJmId = (int)($raw['vendor_jm_id'] ?? 0);
$fullPackQuantity = (float)($raw['full_pack_quantity'] ?? 1);
$comment = trim((string)($raw['comment'] ?? '')) ?: null;

if ($vendorId <= 0 || $partsId <= 0 || $vendorPartNo === '' || $vendorJmId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Uzupełnij część, numer katalogowy u dostawcy i JM.']);
    exit;
}
if ($fullPackQuantity <= 0) {
    $fullPackQuantity = 1;
}

try {
    $repo = new VendorPartRepository($MsaDB);
    $newId = $repo->create($vendorId, $partsId, $vendorPartNo, $vendorJmId, $fullPackQuantity, $comment);
    echo json_encode([
        'success'           => true,
        'id'                => $newId,
        'vendor_part_no'    => $vendorPartNo,
        'parts_id'          => $partsId,
        'vendor_jm_id'      => $vendorJmId,
        'full_pack_quantity'=> $fullPackQuantity,
        'message'           => 'Dodano artykuł u dostawcy.',
    ]);
} catch (\InvalidArgumentException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (\PDOException $e) {
    // 23000 = integrity constraint violation (duplicate vendor_part_no per vendor)
    if ($e->getCode() === '23000' || str_contains($e->getMessage(), 'Duplicate') || str_contains($e->getMessage(), 'uq_vendor_part_no')) {
        echo json_encode([
            'success' => false,
            'error'   => 'Taki numer katalogowy u dostawcy już istnieje. Wybierz inny lub edytuj istniejący.',
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}
