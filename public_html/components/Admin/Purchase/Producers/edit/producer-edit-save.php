<?php
/**
 * POST handler for the dedicated producer edit page.
 *
 * Wired at /public_html/components/Admin/Purchase/Producers/edit/producer-edit-save.php
 * — a real .php file under public_html/components so the .htaccess
 * POST rule (lines 7-9) stops further rewriting and Apache serves
 * it directly. Returns JSON (Content-Type: application/json) so the
 * client-side AJAX handler on producer-edit-view.js can show
 * inline errors without a page reload. See AGENTS.md "AJAX endpoints
 * (component-side)".
 *
 * One endpoint handles both create and update:
 *   - `id` absent or <= 0 → CREATE (returns the new id in `new_id`)
 *   - `id` > 0            → UPDATE
 *
 * On a successful CREATE the client receives `edit_url` so it can
 * navigate to the dedicated edit page for the freshly-created
 * producer (mirrors the cart-view.js "open in new tab" pattern).
 *
 * The DB error message is included in the response payload so a
 * developer can see exactly what the database is complaining about
 * (without having to read error_log). Generic catch-all messages
 * are kept in Polish.
 */
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Master\ProducerRepository;

header('Content-Type: application/json; charset=utf-8');

$listingUrl = 'http://' . BASEURL . '/admin/purchase/producers';

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
$id         = (int)($_POST['id']      ?? 0);
$name       = trim((string)($_POST['name']    ?? ''));
$comment    = $_POST['comment']         ?? null;
if ($comment !== null) {
    $comment = trim((string)$comment);
    if ($comment === '') { $comment = null; }
}
$isActiveRaw = $_POST['isActive']       ?? null;

// --- Validation --------------------------------------------------------
if ($name === '') {
    echo json_encode(['success' => false, 'error' => 'Nazwa producenta jest wymagana.']);
    exit();
}

// isActive: radio can submit '0' or '1'. Treat anything else as 1
// (active) since the form always renders with one radio checked.
$isActive = ($isActiveRaw === '0' || $isActiveRaw === 0) ? false : true;

// --- Persist ------------------------------------------------------------
try {
    $MsaDB = MsaDB::getInstance();
    $repo  = new ProducerRepository($MsaDB);

    if ($id <= 0) {
        // CREATE — name uniqueness is enforced by the repo's existing
        // validation (empty check) and surfaced as InvalidArgumentException.
        $newId = $repo->create($name, $comment);
        echo json_encode([
            'success'  => true,
            'message'  => 'Producent dodany pomyślnie.',
            'new_id'   => $newId,
            'edit_url' => $listingUrl . '/edit?id=' . $newId,
        ]);
        exit();
    }

    // UPDATE — name + comment + isActive. The repo's update() keeps the
    // existing isActive if not passed, so we always pass it explicitly.
    $ok = $repo->update($id, $name, $comment);
    // Update isActive as a separate call so the operator can flip status
    // without re-submitting name/comment logic.
    $repo->toggleActive($id, $isActive);

    if (!$ok && $repo->getById($id) === null) {
        echo json_encode(['success' => false, 'error' => 'Nie znaleziono producenta #' . $id . '.']);
        exit();
    }

    echo json_encode([
        'success' => true,
        'message' => 'Zapisano zmiany.',
    ]);
} catch (\InvalidArgumentException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (\PDOException $e) {
    error_log('producer-edit-save PDO error: ' . $e->getMessage() . ' SQLSTATE=' . $e->getCode());
    // MySQL duplicate-entry: SQLSTATE 23000, error code 1062.
    if ($e->getCode() === '23000' || (isset($e->errorInfo[1]) && (int)$e->errorInfo[1] === 1062)) {
        echo json_encode(['success' => false, 'error' => 'Producent o tej nazwie już istnieje.']);
    } else {
        // Surface the raw PDO message so we can see what's actually
        // failing without digging through the error log.
        echo json_encode([
            'success' => false,
            'error'   => 'Błąd bazy danych: ' . $e->getMessage() . ' (SQLSTATE ' . $e->getCode() . ')',
        ]);
    }
} catch (\Throwable $e) {
    error_log('producer-edit-save error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error'   => 'Wystąpił nieoczekiwany błąd: ' . $e->getMessage(),
    ]);
}