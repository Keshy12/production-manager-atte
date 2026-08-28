<?php
/**
 * POST handler for the dedicated vendor edit page.
 *
 * Wired at /public_html/components/Admin/Purchase/Vendors/edit/vendor-edit-save.php
 * — a real .php file under public_html/components so the .htaccess
 * POST rule (lines 7-9) stops further rewriting and Apache serves
 * it directly. Returns JSON (Content-Type: application/json) so the
 * client-side AJAX handler on vendor-edit-view.js can show
 * inline errors without a page reload. See AGENTS.md "AJAX endpoints
 * (component-side)".
 *
 * One endpoint handles both create and update:
 *   - `id` absent or <= 0 → CREATE (returns the new id in `new_id`)
 *   - `id` > 0            → UPDATE
 *
 * On a successful CREATE the client receives `edit_url` so it can
 * navigate to the dedicated edit page for the freshly-created
 * vendor (mirrors the cart-view.js / producer-edit-save pattern).
 *
 * The DB error message is included in the response payload so a
 * developer can see exactly what the database is complaining about
 * without needing server-side log access. Generic catch-all
 * messages are kept in Polish.
 */
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Master\VendorRepository;

header('Content-Type: application/json; charset=utf-8');

$listingUrl = 'http://' . BASEURL . '/admin/purchase/vendors';

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
$id             = (int)($_POST['id']             ?? 0);
$name           = trim((string)($_POST['name']   ?? ''));
$address        = $_POST['address']              ?? null;
$additionalData = $_POST['additional_data']      ?? null;
$leadTimeDays   = $_POST['lead_time_days']       ?? null;
$comment        = $_POST['comment']              ?? null;
$isActiveRaw    = $_POST['isActive']             ?? null;

// Trim + null-ify the optional string fields.
foreach (['address' => &$address, 'additional_data' => &$additionalData, 'comment' => &$comment] as $k => &$v) {
    if ($v !== null) {
        $v = trim((string)$v);
        if ($v === '') { $v = null; }
    }
}
unset($v);

// lead_time_days: optional, must be a non-negative integer when present.
if ($leadTimeDays !== null && $leadTimeDays !== '') {
    if (!is_numeric($leadTimeDays) || (int)$leadTimeDays < 0) {
        echo json_encode(['success' => false, 'error' => 'Lead time musi być nieujemną liczbą.']);
        exit();
    }
    $leadTimeDays = (int)$leadTimeDays;
} else {
    $leadTimeDays = null;
}

// --- Validation --------------------------------------------------------
if ($name === '') {
    echo json_encode(['success' => false, 'error' => 'Nazwa dostawcy jest wymagana.']);
    exit();
}

// isActive: radio can submit '0' or '1'. Treat anything else as 1
// (active) since the form always renders with one radio checked.
$isActive = ($isActiveRaw === '0' || $isActiveRaw === 0) ? false : true;

// --- Persist ------------------------------------------------------------
try {
    $MsaDB = MsaDB::getInstance();
    $repo  = new VendorRepository($MsaDB);

    if ($id <= 0) {
        // CREATE — name uniqueness isn't a hard constraint at the
        // schema level (per docs/procurement/PLAN.md the schema was
        // never given a UNIQUE index on `name`); we accept duplicates
        // silently. Operators can deduplicate via the listing.
        $newId = $repo->create($name, $address, $additionalData, $leadTimeDays, $comment);
        echo json_encode([
            'success'  => true,
            'message'  => 'Dostawca dodany pomyślnie.',
            'new_id'   => $newId,
            'edit_url' => $listingUrl . '/edit?id=' . $newId,
        ]);
        exit();
    }

    // UPDATE — update name + address + additionalData + leadTimeDays
    // + comment, then flip isActive via toggleActive so we don't
    // need to extend the repo's update() signature.
    $ok = $repo->update($id, $name, $address, $additionalData, $leadTimeDays, $comment);
    $repo->toggleActive($id, $isActive);

    if (!$ok && $repo->getById($id) === null) {
        echo json_encode(['success' => false, 'error' => 'Nie znaleziono dostawcy #' . $id . '.']);
        exit();
    }

    echo json_encode([
        'success' => true,
        'message' => 'Zapisano zmiany.',
    ]);
} catch (\InvalidArgumentException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (\PDOException $e) {
    echo json_encode([
        'success' => false,
        'error'   => 'Błąd bazy danych: ' . $e->getMessage() . ' (SQLSTATE ' . $e->getCode() . ')',
    ]);
} catch (\Throwable $e) {
    echo json_encode([
        'success' => false,
        'error'   => 'Wystąpił nieoczekiwany błąd: ' . $e->getMessage(),
    ]);
}