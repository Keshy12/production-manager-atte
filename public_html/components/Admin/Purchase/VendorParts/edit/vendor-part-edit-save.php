<?php
/**
 * POST handler for the dedicated vendor-part edit/create page.
 *
 * Wired at /public_html/components/Admin/Purchase/VendorParts/edit/vendor-part-edit-save.php
 * — a real .php file under public_html/components so the .htaccess
 * POST rule (lines 7-9) stops further rewriting and Apache serves
 * it directly. Returns JSON (Content-Type: application/json) so the
 * client-side AJAX handler on vendor-part-edit-view.js can show
 * inline errors without a page reload. See AGENTS.md "AJAX endpoints
 * (component-side)".
 *
 * One endpoint handles both create and update:
 *   - `id` absent or <= 0 → CREATE (returns new id in `new_id` and
 *     a same-tab `edit_url` so the JS can navigate to the edit page
 *     for the freshly-created vendor-part — mirrors the
 *     producer-edit-save.php "open in new tab" pattern).
 *   - `id` > 0            → UPDATE
 *
 * The DB error message is included in the response payload so a
 * developer can see exactly what the database is complaining about
 * (without having to read error_log). Generic catch-all messages
 * are kept in Polish.
 */
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Master\VendorPartRepository;

header('Content-Type: application/json; charset=utf-8');

$listingUrl = 'http://' . BASEURL . '/admin/purchase/vendor-parts';

// --- Auth gate ----------------------------------------------------------
if (!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Brak uprawnień.']);
    exit();
}

// --- Method gate --------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Metoda nieobsługiwana.']);
    exit();
}

// --- Read & sanitise inputs --------------------------------------------
$id             = (int)($_POST['id']                ?? 0);
$vendorId       = (int)($_POST['vendor_id']         ?? 0);
$producerId     = (int)($_POST['producer_id']       ?? 0);
$partsId        = (int)($_POST['parts_id']          ?? 0);
$vendorJmId     = (int)($_POST['vendor_jm_id']      ?? 0);
$vendorPartNo   = trim((string)($_POST['vendor_part_no']   ?? ''));
$producerPartNo = trim((string)($_POST['producer_part_no'] ?? ''));
$isActiveRaw    = $_POST['isActive']                ?? null;
$comment        = $_POST['comment']                 ?? null;
if ($comment !== null) {
    $comment = trim((string)$comment);
    if ($comment === '') { $comment = null; }
}

// Packs: array of raw strings from `name="pack_quantities[]"`.
$rawPacks = $_POST['pack_quantities'] ?? null;
if (!is_array($rawPacks)) { $rawPacks = []; }

// --- Validation --------------------------------------------------------
// Field-level checks apply to BOTH create and update — the form
// requires the same set of fields regardless of mode.
if ($vendorId   <= 0) { echo json_encode(['success' => false, 'error' => 'Dostawca jest wymagany.']); exit(); }
if ($producerId <= 0) { echo json_encode(['success' => false, 'error' => 'Producent jest wymagany.']); exit(); }
if ($partsId    <= 0) { echo json_encode(['success' => false, 'error' => 'Część jest wymagana.']); exit(); }
if ($vendorJmId <= 0) { echo json_encode(['success' => false, 'error' => 'Jednostka miary (JM) jest wymagana.']); exit(); }
if ($vendorPartNo === '') { echo json_encode(['success' => false, 'error' => 'Numer części u dostawcy jest wymagany.']); exit(); }

// Normalise + validate pack list. Each pack must be a positive
// numeric; duplicates are deduped and the result is sorted ASC.
// Empty pack list is valid (parts that aren't packaged).
$packList = [];
foreach ($rawPacks as $v) {
    if (is_numeric($v)) {
        $f = (float)str_replace(',', '.', (string)$v);
        if ($f > 0) { $packList[] = $f; }
    }
}
$packList = array_values(array_unique($packList));
sort($packList);
foreach ($packList as $p) {
    if (!is_finite($p) || $p <= 0) {
        echo json_encode(['success' => false, 'error' => 'Każde opakowanie musi być liczbą dodatnią.']);
        exit();
    }
}

// isActive: radio can submit '0' or '1'. Treat anything else as 1
// (active) since the form always renders with one radio checked.
$isActive = ($isActiveRaw === '0' || $isActiveRaw === 0) ? false : true;

// producer_part_no empty string → null at the repository layer.
$producerPartNoForRepo = ($producerPartNo === '') ? null : $producerPartNo;

// --- Persist ------------------------------------------------------------
try {
    $MsaDB = MsaDB::getInstance();
    $repo  = new VendorPartRepository($MsaDB);

    // Friendly pre-check for the UNIQUE (vendor_id, vendor_part_no)
    // constraint. The real race guard is the SQLSTATE 23000 catch
    // below — if two admins edit/create simultaneously both can pass
    // this check, but only one will commit.
    if ($repo->existsForVendorAndPartNo($vendorId, $vendorPartNo)) {
        $existing = $MsaDB->db->prepare(
            'SELECT id FROM `list__vendor_part` WHERE vendor_id = ? AND vendor_part_no = ? LIMIT 1'
        );
        $existing->execute([$vendorId, $vendorPartNo]);
        $existingRow = $existing->fetch(\PDO::FETCH_ASSOC);
        // On CREATE any duplicate is an error. On UPDATE only a
        // duplicate owned by a *different* id is an error.
        $existingId = $existingRow ? (int)$existingRow['id'] : 0;
        $isConflict = $existingId > 0 && ($id <= 0 || $existingId !== $id);
        if ($isConflict) {
            echo json_encode(['success' => false, 'error' => 'Artykuł z tym numerem u dostawcy już istnieje dla wybranego dostawcy.']);
            exit();
        }
    }

    if ($id <= 0) {
        // ----- CREATE -----
        // VendorPartRepository::create() takes a single $fullPackQuantity
        // (legacy param) — pass the smallest pack as the seed and
        // insert the rest as additional list__vendor_part_pack rows in
        // the same transaction. Mirrors the pattern in vp-add.php so
        // the multi-pack UI works on both the dedicated edit page and
        // the legacy AJAX add endpoint.
        $MsaDB->db->beginTransaction();
        try {
            $seedPack = empty($packList) ? 1.0 : $packList[0];
            $newId = $repo->create(
                $vendorId,
                $producerId,
                $partsId,
                $vendorPartNo,
                $vendorJmId,
                $seedPack,
                $comment,
                $producerPartNoForRepo
            );

            // Insert one row per ADDITIONAL pack size. The repo
            // already inserted the smallest as the seed, so skip it
            // here. INSERT IGNORE for defence-in-depth against any
            // residual duplicate (the UNIQUE (vendor_part_id,
            // full_pack_quantity) index is the real guard).
            if (count($packList) > 1) {
                $packStmt = $MsaDB->db->prepare(
                    "INSERT IGNORE INTO `list__vendor_part_pack` (`vendor_part_id`, `full_pack_quantity`) VALUES (?, ?)"
                );
                foreach (array_slice($packList, 1) as $qty) {
                    $packStmt->execute([$newId, $qty]);
                }
            }

            $MsaDB->db->commit();
        } catch (\Throwable $inner) {
            if ($MsaDB->db->inTransaction()) { $MsaDB->db->rollBack(); }
            throw $inner;
        }

        echo json_encode([
            'success'   => true,
            'message'   => 'Artykuł dodany pomyślnie.',
            'new_id'    => $newId,
            'edit_url'  => $listingUrl . '/edit?id=' . $newId,
        ]);
        exit();
    }

    // ----- UPDATE -----
    $repo->update(
        $id,
        $vendorId,
        $producerId,
        $partsId,
        $vendorPartNo,
        $vendorJmId,
        $packList,
        $isActive,
        $comment,
        $producerPartNoForRepo
    );

    echo json_encode([
        'success' => true,
        'message' => 'Zapisano zmiany.',
    ]);
} catch (\InvalidArgumentException $e) {
    $msg = $e->getMessage();
    $map = [
        'VendorPart vendorId must be positive.'   => 'Dostawca jest wymagany.',
        'VendorPart producerId must be positive.' => 'Producent jest wymagany.',
        'VendorPart partsId must be positive.'    => 'Część jest wymagana.',
        'VendorPart vendorJmId must be positive.' => 'Jednostka miary (JM) jest wymagana.',
        'VendorPart vendorPartNo cannot be empty.'=> 'Numer części u dostawcy jest wymagany.',
    ];
    echo json_encode(['success' => false, 'error' => $map[$msg] ?? 'Nieprawidłowe dane artykułu.']);
} catch (\PDOException $e) {
    error_log('vendor-part-edit-save PDO error: ' . $e->getMessage() . ' SQLSTATE=' . $e->getCode());
    // MySQL duplicate-entry: SQLSTATE 23000, error code 1062.
    if ($e->getCode() === '23000' || (isset($e->errorInfo[1]) && (int)$e->errorInfo[1] === 1062)) {
        echo json_encode(['success' => false, 'error' => 'Artykuł z tym numerem u dostawcy już istnieje dla wybranego dostawcy.']);
    } else {
        // Surface the raw PDO message so we can see what's actually
        // failing without digging through the error log.
        echo json_encode([
            'success' => false,
            'error'   => 'Błąd bazy danych: ' . $e->getMessage() . ' (SQLSTATE ' . $e->getCode() . ')',
        ]);
    }
} catch (\Throwable $e) {
    error_log('vendor-part-edit-save error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error'   => 'Wystąpił nieoczekiwany błąd: ' . $e->getMessage(),
    ]);
}