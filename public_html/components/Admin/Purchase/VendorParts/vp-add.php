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
// field so older clients still work. Empty / non-positive entries are dropped;
// duplicates collapsed. The pack payload is OPTIONAL — when both
// `pack_quantities` and the legacy `full_pack_quantity` are absent/blank,
// no pack row is written (the VP simply has no pack tiers recorded).
$packList = [];
if (isset($_POST['pack_quantities']) && is_array($_POST['pack_quantities'])) {
    foreach ($_POST['pack_quantities'] as $v) {
        if ($v === null || $v === '') continue;
        if (is_numeric($v)) {
            $f = (float)str_replace(',', '.', (string)$v);
            if ($f > 0) { $packList[] = $f; }
        }
    }
} else {
    $rawLegacy = $_POST['full_pack_quantity'] ?? null;
    if ($rawLegacy !== null && $rawLegacy !== '' && is_numeric($rawLegacy)) {
        $legacyF = (float)str_replace(',', '.', (string)$rawLegacy);
        if ($legacyF > 0) { $packList[] = $legacyF; }
    }
}
$packList = array_values(array_unique($packList));
sort($packList);
// null when no pack size was provided (the cart UI defaults the input to
// empty and lets the user opt-in by typing a value); smallest pack
// otherwise — keeps back-compat with code paths that still read the
// singular `full_pack_quantity` column on `list__vendor_part`.
$fullPackQuantity = empty($packList) ? null : $packList[0];

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
            // Pass the smallest pack (or null when the user didn't enter
            // any pack size). The repo inserts the seed pack row when
            // non-null; the remaining tiers are written below.
            $fullPackQuantity,
            $comment,
            $producerPartNo
        );

        // Insert one row per ADDITIONAL pack size. The repo already
        // inserted the smallest pack as a legacy single-row when
        // $fullPackQuantity is non-null; skip it here. When the user
        // didn't enter a pack size, $fullPackQuantity is null, the
        // repo skipped its insert, AND array_slice on [] yields no
        // additional rows — so the VP ends up with no pack tiers.
        // ON DUPLICATE KEY UPDATE on UNIQUE (vendor_part_id,
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