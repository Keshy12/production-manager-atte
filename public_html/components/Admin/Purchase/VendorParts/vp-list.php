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

// --- Read & sanitise filters ---
// Multi-select arrays come through $_POST as nested arrays when the client
// uses $.ajax({ data: { vendorIds: [1,2] } }); PHP parses them natively
// into $_POST['vendorIds'].
$filters = [
    'vendorIds'   => isset($_POST['vendorIds'])   && is_array($_POST['vendorIds'])   ? $_POST['vendorIds']   : [],
    'producerIds' => isset($_POST['producerIds']) && is_array($_POST['producerIds']) ? $_POST['producerIds'] : [],
    'partIds'     => isset($_POST['partIds'])     && is_array($_POST['partIds'])     ? $_POST['partIds']     : [],
    'status'      => $_POST['status']   ?? 'all',
    'search'      => $_POST['search']   ?? '',
    'dateFrom'    => $_POST['dateFrom'] ?? '',
    'dateTo'      => $_POST['dateTo']   ?? '',
    // 'true' / 'false' from jQuery; anything else (incl. absent) = no-op.
    'hasPacks'    => match ($_POST['hasPacks'] ?? null) {
        'true', '1'  => true,
        'false', '0' => false,
        default      => null,
    },
];

// Status whitelist — anything else falls back to 'all' so a tampered client
// can't smuggle a literal into the SQL.
$allowedStatuses = ['all', 'active', 'inactive'];
if (!in_array($filters['status'], $allowedStatuses, true)) {
    $filters['status'] = 'all';
}

$page        = isset($_POST['page']) ? (int)$_POST['page'] : 1;
$itemsPerPage = 10;

try {
    $MsaDB = MsaDB::getInstance();
    $repo  = new VendorPartRepository($MsaDB);
    $result = $repo->search($filters, $page, $itemsPerPage);

    $rows = [];
    foreach ($result['rows'] as $vp) {
        $rows[] = [
            'id'           => $vp->id,
            'vendorId'     => $vp->vendorId,
            'producerId'   => $vp->producerId,
            'partsId'      => $vp->partsId,
            'vendorPartNo' => $vp->vendorPartNo,
            'producerPartNo' => $vp->producerPartNo,
            'vendorJmId'   => $vp->vendorJmId,
            'fullPackQuantity' => $vp->fullPackQuantity, // min pack (back-compat)
            'packQuantities'   => $vp->packQuantities,   // all packs ascending
            'isActive'     => $vp->isActive,
            'comment'      => $vp->comment,
            'createdAt'    => $vp->createdAt,
            'updatedAt'    => $vp->updatedAt,
            'vendorName'   => $vp->vendorName,
            'producerName' => $vp->producerName,
            'partName'     => $vp->partName,
            'unitName'     => $vp->unitName,
        ];
    }

    // Stat tiles above the list. Each count = the current filters with
    // exactly one dimension overridden — the one the tile sets when
    // clicked — so a tile always shows what clicking it would return.
    $stats = [
        'all'      => $repo->count(array_merge($filters, ['status' => 'all'])),
        'active'   => $repo->count(array_merge($filters, ['status' => 'active'])),
        'inactive' => $repo->count(array_merge($filters, ['status' => 'inactive'])),
        'noPacks'  => $repo->count(array_merge($filters, ['hasPacks' => false])),
    ];

    echo json_encode([
        'success'      => true,
        'rows'         => $rows,
        'total'        => $result['total'],
        'page'         => $result['page'],
        'itemsPerPage' => $result['itemsPerPage'],
        'stats'        => $stats,
    ], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    error_log('vp-list error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Wystąpił błąd podczas ładowania listy']);
}
exit;