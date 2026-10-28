<?php
/**
 * Endpoint AJAX: lista „Artykuły u dostawców" w modale relacji na
 * stronie /admin/purchase/vendors/edit.
 *
 * Wired at /public_html/components/Admin/Purchase/Vendors/edit/vendor-parts-modal-list.php
 * — a real .php file under public_html/components so the .htaccess
 * POST rule (lines 7-9) stops further rewriting and Apache serves
 * it directly. Returns JSON (Content-Type: application/json) so the
 * client-side AJAX handler on vendor-edit-view.js can render the table
 * together with the pagination controls without a page reload.
 * See AGENTS.md "AJAX endpoints (component-side)".
 *
 * Tabela jest dotąd renderowana po stronie serwera (wszystkie wiersze
 * naraz) — ten endpoint zastępuje ją odpytywaniem pojedynczej
 * strony wyników, dzięki czemu można dodać filtrowanie i
 * stronicowanie bez obciążania renderu.
 *
 * Parametry (POST):
 *     vendor_id  int     WYMAGANY. Id dostawcy (> 0).
 *     page       int     Numer strony (1-based), domyślnie 1, minimalnie 1.
 *     per_page   int     Liczba wierszy na stronę, domyślnie 10, zakres 1..100.
 *     q          string  Fraza wyszukiwania (podciąg), maks. 100 znaków.
 *     status     string  'all' | 'active' | 'inactive' (domyślnie 'all').
 *     packs      string  'all' | 'with' | 'without' (domyślnie 'all').
 *
 * Odpowiedź (JSON, zawsze HTTP 200 poza bramkami 403/405):
 *     {
 *       "success":  true,
 *       "rows":     [ { id, partName, producerName, unitName,
 *                      vendorPartNo, producerPartNo, comment,
 *                      isActive, packQuantities } ],
 *       "total":    int,             // liczba wierszy przy tych samych filtrach
 *       "page":     int,             // efektywna strona
 *       "per_page": int,             // efektywna liczba wierszy na stronę
 *       "pages":    int              // ceil(total / per_page), 0 gdy total = 0
 *     }
 *
 * Błędy zwracane są jako {"success": false, "error": "..."} — komunikat
 * z bazy trafia do payloadu, żeby developer widział dokładny powód
 * bez dostępu do logów serwera. Komunikaty generyczne po polsku.
 */
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Master\VendorPartRepository;

header('Content-Type: application/json; charset=utf-8');

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
$vendorId = (int)($_POST['vendor_id'] ?? 0);

$page = isset($_POST['page']) ? (int)$_POST['page'] : 1;
if ($page < 1) {
    $page = 1;
}

$perPage = isset($_POST['per_page']) ? (int)$_POST['per_page'] : 10;
if ($perPage < 1)  { $perPage = 1; }
if ($perPage > 100) { $perPage = 100; }

$q = trim((string)($_POST['q'] ?? ''));
if (mb_strlen($q) > 100) {
    $q = mb_substr($q, 0, 100);
}

// Whitelisty — nieznana wartość wraca do 'all', żeby zmieniony klient
// nie mógł przemycić literału do zapytania.
$allowedStatuses = ['all', 'active', 'inactive'];
$status = (string)($_POST['status'] ?? 'all');
if (!in_array($status, $allowedStatuses, true)) {
    $status = 'all';
}

$allowedPacks = ['all', 'with', 'without'];
$packs = (string)($_POST['packs'] ?? 'all');
if (!in_array($packs, $allowedPacks, true)) {
    $packs = 'all';
}

// --- Validation ---------------------------------------------------------
if ($vendorId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Brak identyfikatora dostawcy.']);
    exit();
}

// --- Build filters ------------------------------------------------------
// Klucze zgodne z buildSearchWhere() w VendorPartRepository — brak
// klucza 'status' oznacza 'all', brak klucza 'hasPacks' = brak filtra.
$filters = [
    'vendorIds' => [$vendorId],
    // W modalu nazwa części jest pierwszą kolumną, więc operator szuka
    // po niej — a nie tylko po numerach. Strona listy artykułów tego
    // klucza nie używa i zachowuje dotychczasowe wyszukiwanie.
    'searchAlsoPartName' => true,
];

if ($q !== '') {
    $filters['search'] = $q;
}
if ($status !== 'all') {
    $filters['status'] = $status;
}
if ($packs === 'with') {
    $filters['hasPacks'] = true;
} elseif ($packs === 'without') {
    $filters['hasPacks'] = false;
}

// --- Query --------------------------------------------------------------
try {
    $MsaDB = MsaDB::getInstance();
    $repo  = new VendorPartRepository($MsaDB);
    $result = $repo->search($filters, $page, $perPage);

    // Mapujemy tylko pola potrzebne w tabeli — nie lejemy całego
    // obiektu VendorPart do JSON-a.
    $rows = [];
    foreach ($result['rows'] as $vp) {
        $rows[] = [
            'id'             => (int)$vp->id,
            'partName'       => $vp->partName,
            'producerName'   => $vp->producerName,
            'unitName'       => $vp->unitName,
            'vendorPartNo'   => (string)$vp->vendorPartNo,
            'producerPartNo' => $vp->producerPartNo,
            'comment'        => $vp->comment,
            'isActive'       => (bool)$vp->isActive,
            'packQuantities' => array_map('floatval', (array)$vp->packQuantities),
        ];
    }

    $total   = (int)$result['total'];
    $perPage = (int)$result['itemsPerPage'];

    echo json_encode([
        'success'  => true,
        'rows'     => $rows,
        'total'    => $total,
        'page'     => (int)$result['page'],
        'per_page' => $perPage,
        'pages'    => $total > 0 ? (int)ceil($total / $perPage) : 0,
    ], JSON_UNESCAPED_UNICODE);
} catch (\PDOException $e) {
    echo json_encode([
        'success' => false,
        'error'   => 'Błąd bazy danych: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    echo json_encode([
        'success' => false,
        'error'   => 'Wystąpił nieoczekiwany błąd: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
exit;
