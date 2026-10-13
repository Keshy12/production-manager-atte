<?php
/**
 * AJAX (POST): dodaje nowego kontakt dostawcy (list__vendor_supplier).
 *
 * Używany przez wizarda "Wyślij dokument" (documents-send.js) do
 * inline-owego dodawania osoby kontaktowej, gdy dostawca nie ma jeszcze
 * kontaktów (lub gdy brakuje konkretnej osoby).
 *
 * Konwencje zgodne z AGENTS.md:
 *   - Content-Type JSON, UTF-8
 *   - $SESSION['isAdmin'] gate → 403
 *   - REQUEST_METHOD gate → 405
 *   - Walidacja wejść → {success:false, error:<msg>} HTTP 200
 *   - Dokładnie jeden echo json_encode(...) + exit
 *
 * Wejście (POST, form-encoded):
 *   vendor_id    int     wymagane, > 0
 *   name         string  wymagane, niepuste po trim()
 *   job_title    string  opcjonalne
 *   email        string  opcjonalne
 *   phone        string  opcjonalne
 *   comment      string  opcjonalne
 *
 * Wyjście sukces:
 *   {
 *     "success": true,
 *     "supplier": {
 *       "id":        int,
 *       "vendorId":  int,
 *       "name":      string,
 *       "jobTitle":  string|null,
 *       "email":     string|null,
 *       "phone":     string|null,
 *       "comment":   string|null,
 *       "isPrimary": 0|1
 *     }
 *   }
 *
 * Błędy (HTTP 200):
 *   {success:false, error:'…'}
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

$vendorId = (int)($_POST['vendor_id'] ?? 0);
$name     = trim((string)($_POST['name'] ?? ''));
$jobTitle = trim((string)($_POST['job_title'] ?? ''));
$email    = trim((string)($_POST['email'] ?? ''));
$phone    = trim((string)($_POST['phone'] ?? ''));
$comment  = trim((string)($_POST['comment'] ?? ''));

if ($vendorId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Nieprawidłowy identyfikator dostawcy.']);
    exit;
}
if ($name === '') {
    echo json_encode(['success' => false, 'error' => 'Imię i nazwisko kontaktu jest wymagane.']);
    exit;
}

// Lekka walidacja formatu email/phone — tylko ostrzeżenie dla operatora,
// nie blokuje zapisu (brak kolizji z resztą systemu, która nie waliduje).
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'error' => 'Nieprawidłowy format adresu email.']);
    exit;
}

$MsaDB = MsaDB::getInstance();

// Potwierdź, że vendor istnieje i jest aktywny — obrona przed zapisem
// do nieistniejącej firmy (VendorSupplierRepository::create() tego
// nie sprawdza).
$vStmt = $MsaDB->db->prepare(
    "SELECT id, isActive FROM `list__vendor` WHERE id = ?"
);
$vStmt->execute([$vendorId]);
$vRow = $vStmt->fetch(\PDO::FETCH_ASSOC);
if ($vRow === false) {
    echo json_encode(['success' => false, 'error' => 'Dostawca nie istnieje.']);
    exit;
}
if ((int)$vRow['isActive'] !== 1) {
    echo json_encode(['success' => false, 'error' => 'Dostawca jest nieaktywny.']);
    exit;
}

try {
    $supplierRepo = new VendorSupplierRepository($MsaDB);
    $newId = $supplierRepo->create(
        $vendorId,
        $name,
        $jobTitle === '' ? null : $jobTitle,
        $phone    === '' ? null : $phone,
        $email    === '' ? null : $email,
        $comment  === '' ? null : $comment
    );
    // Nowy kontakt nigdy nie jest główny (brak flagi przy tworzeniu —
    // operator może ją nadać checkboxem w edytorze vendorów lub
    // bezpośrednim UPDATE, gdyby chciał uczynić go „głównym").
    echo json_encode([
        'success'  => true,
        'supplier' => [
            'id'        => (int)$newId,
            'vendorId'  => (int)$vendorId,
            'name'      => $name,
            'jobTitle'  => $jobTitle === '' ? null : $jobTitle,
            'email'     => $email    === '' ? null : $email,
            'phone'     => $phone    === '' ? null : $phone,
            'comment'   => $comment  === '' ? null : $comment,
            'isPrimary' => 0,
        ],
    ]);
} catch (\Throwable $e) {
    error_log('vendor-supplier-create error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Błąd zapisu kontaktu.']);
}
exit;
