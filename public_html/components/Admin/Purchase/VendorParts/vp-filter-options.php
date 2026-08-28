<?php
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Master\VendorPartRepository;

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

// Mode whitelist — anything else is rejected so a tampered client can't
// smuggle a literal into the SQL path.
$mode = (string)($_POST['mode'] ?? '');
$allowedModes = ['producers_for_vendors', 'vendors_for_producers'];
if (!in_array($mode, $allowedModes, true)) {
    echo json_encode(['success' => false, 'error' => 'Nieprawidłowy tryb filtra']);
    exit();
}

// Accept `ids` either as a form-encoded array (project convention —
// jQuery $.ajax({ data: { ids: [1,2,3] } }) form-encodes as
// ids[]=1&ids[]=2&…) or as a JSON string (fallback). Whitelist positive
// ints only so the IN (...) placeholder count matches what we bind.
$ids = [];
if (isset($_POST['ids']) && is_array($_POST['ids'])) {
    $ids = $_POST['ids'];
} elseif (isset($_POST['ids']) && is_string($_POST['ids']) && $_POST['ids'] !== '') {
    $decoded = json_decode($_POST['ids'], true);
    if (is_array($decoded)) { $ids = $decoded; }
}
$ids = array_values(array_filter(array_map('intval', $ids), fn($v) => $v > 0));

try {
    $MsaDB = MsaDB::getInstance();
    $repo  = new VendorPartRepository($MsaDB);

    $items = $mode === 'producers_for_vendors'
        ? $repo->getProducersForVendorIds($ids)
        : $repo->getVendorsForProducerIds($ids);

    echo json_encode(['success' => true, 'items' => $items], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    error_log('vp-filter-options error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Wystąpił błąd podczas ładowania opcji filtra']);
}
exit;
