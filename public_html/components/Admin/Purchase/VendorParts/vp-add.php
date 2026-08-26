<?php
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Master\VendorPartRepository;

header('Content-Type: application/json; charset=utf-8');

if(!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Brak uprawnień.']);
    exit();
}

if($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Metoda nieobsługiwana']);
    exit();
}

$vendorId         = (int)($_POST['vendor_id'] ?? 0);
$producerId       = (int)($_POST['producer_id'] ?? 0);
$partsId          = (int)($_POST['parts_id'] ?? 0);
$vendorPartNo     = trim($_POST['vendor_part_no'] ?? '');
$producerPartNo   = trim($_POST['producer_part_no'] ?? '');
if ($producerPartNo === '') { $producerPartNo = null; }
$vendorJmId       = (int)($_POST['vendor_jm_id'] ?? 0);
$comment          = $_POST['comment'] ?? null;
if($comment !== null) { $comment = trim($comment); if($comment === '') { $comment = null; } }

// pack_quantities: array of positive floats (sorted asc on the client).
// Fall back to a single-element list using the legacy full_pack_quantity form
// field (defaults to 1.0 if even that is missing) so older clients still work.
// Empty / non-positive entries are dropped; duplicates collapsed.
$packList = [];
if (isset($_POST['pack_quantities']) && is_array($_POST['pack_quantities'])) {
    foreach ($_POST['pack_quantities'] as $v) {
        if (is_numeric($v)) {
            $f = (float)str_replace(',', '.', (string)$v);
            if ($f > 0) { $packList[] = $f; }
        }
    }
} else {
    $legacy = str_replace(',', '.', (string)($_POST['full_pack_quantity'] ?? '1'));
    $legacyF = (float)$legacy;
    if ($legacyF > 0) { $packList[] = $legacyF; }
}
$packList = array_values(array_unique($packList));
sort($packList);
if (empty($packList)) { $packList = [1.0]; }
$fullPackQuantity = $packList[0]; // min after sort asc

try {
    $MsaDB = MsaDB::getInstance();
    $repo  = new VendorPartRepository($MsaDB);

    if($repo->existsForVendorAndPartNo($vendorId, $vendorPartNo)) {
        echo json_encode(['success' => false, 'error' => 'Vendor part no już istnieje dla tego dostawcy']);
        exit;
    }

    // Insert the VendorPart header (no pack column on this table — packs
    // live in list__vendor_part_pack as separate rows). Then insert N pack
    // rows, all inside one transaction so partial writes never land.
    $MsaDB->db->beginTransaction();
    try {
        $id = $repo->create(
            $vendorId,
            $producerId,
            $partsId,
            $vendorPartNo,
            $vendorJmId,
            // VendorPartRepository::create() expects a single $fullPackQuantity
            // (legacy param). We pass the smallest pack here so the admin form
            // still shows a sensible default; the multi-pack rows are written
            // below and override any duplicate-pack uniqueness.
            $fullPackQuantity,
            $comment,
            $producerPartNo
        );

        // Insert one row per ADDITIONAL pack size. The repo already
        // inserted the smallest pack as a legacy single-row, so skip it
        // here. ON DUPLICATE KEY UPDATE on UNIQUE (vendor_part_id,
        // full_pack_quantity) is a defence-in-depth — collapses a
        // concurrent insert race, no effect otherwise.
        $packStmt = $MsaDB->db->prepare(
            "INSERT INTO `list__vendor_part_pack` (`vendor_part_id`, `full_pack_quantity`)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE `full_pack_quantity` = VALUES(`full_pack_quantity`)"
        );
        foreach (array_slice($packList, 1) as $qty) {
            $packStmt->execute([$id, $qty]);
        }

        $MsaDB->db->commit();
    } catch (\Throwable $inner) {
        if ($MsaDB->db->inTransaction()) { $MsaDB->db->rollBack(); }
        throw $inner;
    }

    echo json_encode([
        'success'           => true,
        'id'                => $id,
        'full_pack_quantity'=> $fullPackQuantity,
        'pack_quantities'   => $packList,
        'message'           => 'Artykuł dodany pomyślnie',
    ]);
} catch (\InvalidArgumentException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (\Throwable $e) {
    error_log('vp-add error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Wystąpił błąd podczas dodawania artykułu']);
}
exit;