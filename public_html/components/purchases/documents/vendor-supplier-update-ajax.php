<?php
/**
 * AJAX (POST): aktualizuje dane kontaktu dostawcy (list__vendor_supplier).
 *
 * Używany przez wizarda "Wyślij dokument" (documents-send.js) do
 * inline-owej edycji komentarza / telefonu / emailu / stanowiska
 * istniejącego kontaktu (klik w ołówek przy karcie kontaktu).
 *
 * Konwencje zgodne z AGENTS.md:
 *   - Content-Type JSON, UTF-8
 *   - $SESSION['isAdmin'] gate → 403
 *   - REQUEST_METHOD gate → 405
 *   - Walidacja wejść → {success:false, error:<msg>} HTTP 200
 *   - Dokładnie jeden echo json_encode(...) + exit
 *
 * Wejście (POST, form-encoded):
 *   id          int     wymagane, > 0
 *   name        string  opcjonalne (musi być niepuste jeśli podane)
 *   job_title   string  opcjonalne
 *   email       string  opcjonalne
 *   phone       string  opcjonalne
 *   comment     string  opcjonalne
 *
 * Każde pole opcjonalne, ale wymagane jest podanie CO NAJMNIEJ jednego
 * pola do aktualizacji (PUT-like semantics) — pusty POST nie ma sensu.
 *
 * Wyjście sukces: {success: true, supplier: {...}}
 */
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Master\VendorSupplierRepository;

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

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

$id     = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Nieprawidłowy identyfikator kontaktu.']);
    exit;
}

// Pusta wartość stringowa === null (zapisujemy NULL w bazie zamiast '').
// Niepusty string === trim(string). Każde pole opcjonalne.
$fields = ['name', 'job_title', 'email', 'phone', 'comment'];
$updates = [];
foreach ($fields as $f) {
    if (!array_key_exists($f, $_POST)) continue;
    $raw = $_POST[$f];
    if (!is_string($raw)) {
        echo json_encode(['success' => false, 'error' => "Nieprawidłowa wartość pola {$f}."]);
        exit;
    }
    $trimmed = trim($raw);
    $updates[$f] = $trimmed === '' ? null : $trimmed;
}

if (empty($updates)) {
    echo json_encode(['success' => false, 'error' => 'Brak pól do aktualizacji.']);
    exit;
}

// `name` nie może być pusty (UNIQUE-ish constraint w logice domenowej).
if (array_key_exists('name', $updates) && ($updates['name'] === null || $updates['name'] === '')) {
    echo json_encode(['success' => false, 'error' => 'Imię i nazwisko nie może być puste.']);
    exit;
}

// `email` opcjonalna walidacja formatu (jeśli podana).
if (array_key_exists('email', $updates) && $updates['email'] !== null
    && !filter_var($updates['email'], FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'error' => 'Nieprawidłowy format adresu email.']);
    exit;
}

$MsaDB = MsaDB::getInstance();
$supplierRepo = new VendorSupplierRepository($MsaDB);

$existing = $supplierRepo->getById($id);
if ($existing === null) {
    echo json_encode(['success' => false, 'error' => 'Kontakt nie istnieje.']);
    exit;
}

try {
    $supplierRepo->update(
        $id,
        array_key_exists('name',      $updates) ? $updates['name']      : $existing->name,
        array_key_exists('job_title', $updates) ? $updates['job_title'] : $existing->jobTitle,
        array_key_exists('phone',     $updates) ? $updates['phone']     : $existing->phone,
        array_key_exists('email',     $updates) ? $updates['email']     : $existing->email,
        array_key_exists('comment',   $updates) ? $updates['comment']   : $existing->comment
    );

    $fresh = $supplierRepo->getById($id);
    // Dołącz isPrimary z DB (kolumna istnieje po migracji; czytamy wprost).
    $primStmt = $MsaDB->db->prepare(
        "SELECT is_primary AS isPrimary FROM `list__vendor_supplier` WHERE id = ?"
    );
    $primStmt->execute([$id]);
    $primRow = $primStmt->fetch(\PDO::FETCH_ASSOC);
    $isPrimary = $primRow !== false ? (int)$primRow['isPrimary'] : 0;

    echo json_encode([
        'success'  => true,
        'supplier' => [
            'id'        => (int)$fresh->id,
            'vendorId'  => (int)$fresh->vendorId,
            'name'      => (string)$fresh->name,
            'jobTitle'  => $fresh->jobTitle,
            'email'     => $fresh->email,
            'phone'     => $fresh->phone,
            'comment'   => $fresh->comment,
            'isPrimary' => $isPrimary,
        ],
    ]);
} catch (\Throwable $e) {
    error_log('vendor-supplier-update error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Błąd zapisu zmian.']);
}
exit;
