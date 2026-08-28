<?php
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Master\VendorRepository;

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

// --- Read & sanitise filters ---
// hasArticles + hasSuppliers come through as literal strings from
// jQuery's $.ajax (no JSON encoding). Whitelist true/false; anything
// else (including absent) is treated as null so the filter is a no-op.
function filterBool($raw) {
    if ($raw === 'true' || $raw === '1')  return true;
    if ($raw === 'false' || $raw === '0') return false;
    return null;
}

$filters = [
    'status'       => $_POST['status']       ?? 'all',
    'search'       => $_POST['search']       ?? '',
    'hasArticles'  => filterBool($_POST['hasArticles']  ?? null),
    'hasSuppliers' => filterBool($_POST['hasSuppliers'] ?? null),
    'dateFrom'     => $_POST['dateFrom']     ?? '',
    'dateTo'       => $_POST['dateTo']       ?? '',
];

// Status whitelist — anything else falls back to 'all' so a tampered
// client can't smuggle a literal into the SQL.
$allowedStatuses = ['all', 'active', 'inactive'];
if (!in_array($filters['status'], $allowedStatuses, true)) {
    $filters['status'] = 'all';
}

$page        = isset($_POST['page']) ? (int)$_POST['page'] : 1;
// Single source of truth for the page size — the JS reads this value
// back from the response so the client and backend can never drift.
$itemsPerPage = 10;

try {
    $MsaDB = MsaDB::getInstance();
    $repo  = new VendorRepository($MsaDB);
    $result = $repo->search($filters, $page, $itemsPerPage);

    $rows = [];
    foreach ($result['rows'] as $v) {
        $rows[] = [
            'id'             => $v->id,
            'name'           => $v->name,
            'address'        => $v->address,
            'additionalData' => $v->additionalData,
            'leadTimeDays'   => $v->leadTimeDays,
            'comment'        => $v->comment,
            'isActive'       => $v->isActive,
            'createdAt'      => $v->createdAt,
            'supplierCount'  => $v->supplierCount,
            'vendorPartCount'=> $v->vendorPartCount,
        ];
    }

    echo json_encode([
        'success'      => true,
        'rows'         => $rows,
        'total'        => $result['total'],
        'page'         => $result['page'],
        'itemsPerPage' => $result['itemsPerPage'],
    ], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    echo json_encode(['success' => false, 'error' => 'Wystąpił błąd podczas ładowania listy']);
}
exit;