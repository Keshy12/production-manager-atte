<?php
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Master\ProducerRepository;

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
// hasArticles comes through as a literal string from jQuery's $.ajax
// (no JSON encoding). Whitelist true/false; anything else (including
// absent) is treated as null so the filter is a no-op.
$hasArticlesRaw = $_POST['hasArticles'] ?? null;
if ($hasArticlesRaw === 'true' || $hasArticlesRaw === '1') {
    $hasArticles = true;
} elseif ($hasArticlesRaw === 'false' || $hasArticlesRaw === '0') {
    $hasArticles = false;
} else {
    $hasArticles = null;
}

$filters = [
    'status'      => $_POST['status']   ?? 'all',
    'search'      => $_POST['search']   ?? '',
    'hasArticles' => $hasArticles,
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
    $repo  = new ProducerRepository($MsaDB);
    $result = $repo->search($filters, $page, $itemsPerPage);

    $rows = [];
    foreach ($result['rows'] as $p) {
        $rows[] = [
            'id'              => $p->id,
            'name'            => $p->name,
            'comment'         => $p->comment,
            'isActive'        => $p->isActive,
            'vendorPartCount' => $p->vendorPartCount,
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
    error_log('producer-list error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Wystąpił błąd podczas ładowania listy']);
}
exit;