<?php
/**
 * AJAX endpoint for /purchase/documents combined RFQ + PO table.
 *
 * Mirrors archive-table.php conventions:
 *   - POST form-encoded payload (jQuery $.ajax with structured `data:`)
 *   - JSON response with HTTP 200
 *   - `mode` toggle = 'count' (just total) or 'data' (one page)
 *   - Filter builder inside WHERE clauses (no prepared-statement placeholders
 *     — params are intval'd / strval'd / quoted before interpolation, exactly
 *     like archive-table.php)
 *
 * Document universe is the UNION of `purchase__rfq` + `purchase__order`.
 * Rows are returned sorted by created_at DESC across both tables.
 *
 * v1 scope (locked with user):
 *   - 7 columns: Number, Type, Vendor, State, Items (count), Created, Value (per-currency)
 *   - 6 filters: type, vendor_ids[], states[], number (partial), date_from, date_to
 *   - Action button (Edytuj vs Podgląd) is computed per row from
 *     PurchaseActionHandler::allowedEditStates() — JS reads `editable` and
 *     renders the right label/icon. Both link to /admin/purchase/documents/edit.
 *
 * Filter quirks worth knowing:
 *   - states[] validated against the per-type valid set. Picking a state that
 *     exists in the other type yields 0 rows (no implicit dual-type state).
 *   - number LIKE on rfq_number / po_number / vendor_po_number. Wildcards
 *     (% _ \) are stripped from the search term so users can't accidentally
 *     write a regex.
 *   - date filters use server local time against created_at; server renders
 *     'created_at DESC'.
 *
 * Pagination: per-type limit is generous (`($page+1)*itemsPerPage` from each
 * table), merged in PHP by created_at DESC, sliced for the page. v1 dataset
 * is bounded by the auto-applied 90-day window from the JS side; if we ever
 * need a true UNION-then-LIMIT, profile first.
 */
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Order\PurchaseActionHandler;

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if (!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    http_response_code(403);
    echo json_encode(['error' => 'Brak uprawnień.']);
    exit;
}

$MsaDB = MsaDB::getInstance();

// ---- read + sanitize inputs ----
$typeFilter   = (string)($_POST['type'] ?? 'both');
$vendorIds    = (array)($_POST['vendor_ids'] ?? []);
$states       = (array)($_POST['states'] ?? []);
$numberSearch = trim((string)($_POST['number'] ?? ''));
$dateFrom     = (string)($_POST['date_from'] ?? '');
$dateTo       = (string)($_POST['date_to'] ?? '');
$mode         = (string)($_POST['mode'] ?? 'data');
$page         = max(1, (int)($_POST['page'] ?? 1));
$itemsPerPage = (int)($_POST['items_per_page'] ?? 20);
if ($itemsPerPage < 1 || $itemsPerPage > 100) {
    $itemsPerPage = 20;
}
$offset = ($page - 1) * $itemsPerPage;

$sanitizedVendors = array_values(array_filter(array_map('intval', $vendorIds)));
$sanitizedStates  = array_values(array_filter(array_map('strval', $states)));

// Strip LIKE wildcards from user input so the term can never escape into
// a regex-anything match. Users can't search for literal % but that's fine.
$numberSearch = str_replace(['%', '_', '\\'], '', $numberSearch);

$validRfqStates = ['draft', 'sent', 'responded', 'cancelled', 'converted'];
$validPoStates  = ['draft', 'sent', 'confirmed', 'partially_received', 'received', 'cancelled'];

$includeRfq = in_array($typeFilter, ['both', 'rfq'], true);
$includePo  = in_array($typeFilter, ['both', 'po'],  true);

// ---- per-type WHERE clause builders (reused for COUNT and DATA queries) ----
// function_exists guards so this file can be require'd twice in the same
// request (defensive — current routing doesn't, but it's cheap).
if (!function_exists('rfqDocsConds')) {
    function rfqDocsConds($sanitizedVendors, $sanitizedStates, $numberSearch, $dateFrom, $dateTo, $validRfqStates, $MsaDB) {
        $c = ['1=1'];
        if (!empty($sanitizedVendors)) {
            $c[] = 'r.vendor_id IN (' . implode(',', $sanitizedVendors) . ')';
        }
        if (!empty($sanitizedStates)) {
            $valid = array_values(array_intersect($sanitizedStates, $validRfqStates));
            if (!empty($valid)) {
                $quoted = array_map(fn($s) => $MsaDB->db->quote($s), $valid);
                $c[] = 'r.state IN (' . implode(',', $quoted) . ')';
            } else {
                // No requested state is valid for rfq → short-circuit.
                $c[] = '1=0';
            }
        }
        if ($numberSearch !== '') {
            $c[] = 'r.rfq_number LIKE ' . $MsaDB->db->quote('%' . $numberSearch . '%');
        }
        if ($dateFrom !== '') {
            $c[] = 'r.created_at >= ' . $MsaDB->db->quote($dateFrom . ' 00:00:00');
        }
        if ($dateTo !== '') {
            $c[] = 'r.created_at <= ' . $MsaDB->db->quote($dateTo . ' 23:59:59');
        }
        return $c;
    }
}
if (!function_exists('poDocsConds')) {
    function poDocsConds($sanitizedVendors, $sanitizedStates, $numberSearch, $dateFrom, $dateTo, $validPoStates, $MsaDB) {
        $c = ['1=1'];
        if (!empty($sanitizedVendors)) {
            $c[] = 'o.vendor_id IN (' . implode(',', $sanitizedVendors) . ')';
        }
        if (!empty($sanitizedStates)) {
            $valid = array_values(array_intersect($sanitizedStates, $validPoStates));
            if (!empty($valid)) {
                $quoted = array_map(fn($s) => $MsaDB->db->quote($s), $valid);
                $c[] = 'o.state IN (' . implode(',', $quoted) . ')';
            } else {
                $c[] = '1=0';
            }
        }
        if ($numberSearch !== '') {
            // PO: match BOTH the internal po_number and the vendor-supplied
            // vendor_po_number, since operators tend to search by the latter.
            $like  = $MsaDB->db->quote('%' . $numberSearch . '%');
            $c[] = "(o.po_number LIKE $like OR COALESCE(NULLIF(o.vendor_po_number, ''), '') LIKE $like)";
        }
        if ($dateFrom !== '') {
            $c[] = 'o.created_at >= ' . $MsaDB->db->quote($dateFrom . ' 00:00:00');
        }
        if ($dateTo !== '') {
            $c[] = 'o.created_at <= ' . $MsaDB->db->quote($dateTo . ' 23:59:59');
        }
        return $c;
    }
}

$rfqWhereSql = '';
$poWhereSql  = '';
if ($includeRfq) {
    $rfqConds    = rfqDocsConds($sanitizedVendors, $sanitizedStates, $numberSearch, $dateFrom, $dateTo, $validRfqStates, $MsaDB);
    $rfqWhereSql = 'WHERE ' . implode(' AND ', $rfqConds);
}
if ($includePo) {
    $poConds    = poDocsConds($sanitizedVendors, $sanitizedStates, $numberSearch, $dateFrom, $dateTo, $validPoStates, $MsaDB);
    $poWhereSql = 'WHERE ' . implode(' AND ', $poConds);
}

// ---- COUNT mode ----
if ($mode === 'count') {
    $rfqCount = 0;
    $poCount  = 0;
    if ($includeRfq) {
        $rfqCount = (int)$MsaDB->query("SELECT COUNT(*) AS c FROM `purchase__rfq` r $rfqWhereSql", \PDO::FETCH_ASSOC)[0]['c'];
    }
    if ($includePo) {
        $poCount = (int)$MsaDB->query("SELECT COUNT(*) AS c FROM `purchase__order` o $poWhereSql", \PDO::FETCH_ASSOC)[0]['c'];
    }
    echo json_encode(['totalCount' => $rfqCount + $poCount], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- DATA mode ----
// Fetch (id, created_at) from each table with a generous upper bound so
// the merged sort has enough rows to slice the page. Mirrors the
// archive-table.php "discovery → bulk fetch → stitch" pattern.
// $fetchLimit cap = (page+1) * itemsPerPage keeps the over-fetch bounded.
$fetchLimit = $itemsPerPage * ($page + 1);

$merged = [];

if ($includeRfq) {
    $sql = "SELECT r.id, r.created_at
              FROM `purchase__rfq` r
              $rfqWhereSql
             ORDER BY r.created_at DESC, r.id DESC
             LIMIT $fetchLimit";
    foreach ($MsaDB->query($sql, \PDO::FETCH_ASSOC) as $r) {
        $merged[] = ['type' => 'rfq', 'id' => (int)$r['id'], 'created_at' => $r['created_at']];
    }
}
if ($includePo) {
    $sql = "SELECT o.id, o.created_at
              FROM `purchase__order` o
              $poWhereSql
             ORDER BY o.created_at DESC, o.id DESC
             LIMIT $fetchLimit";
    foreach ($MsaDB->query($sql, \PDO::FETCH_ASSOC) as $r) {
        $merged[] = ['type' => 'po', 'id' => (int)$r['id'], 'created_at' => $r['created_at']];
    }
}

// Stable cross-type sort by created_at DESC, then id DESC as a tiebreaker
// (MySQL DATETIME can collide for docs created in the same second).
usort($merged, function ($a, $b) {
    $cmp = strcmp($b['created_at'], $a['created_at']);
    return $cmp !== 0 ? $cmp : ($b['id'] - $a['id']);
});

$pageSlice = array_slice($merged, $offset, $itemsPerPage);

if (empty($pageSlice)) {
    echo json_encode([
        'docs'        => [],
        'currentPage' => $page,
        'snapshot_ts' => date('Y-m-d H:i:s'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$rfqIds = [];
$poIds  = [];
foreach ($pageSlice as $row) {
    if ($row['type'] === 'rfq') { $rfqIds[] = $row['id']; }
    else                         { $poIds[]  = $row['id']; }
}

// ---- per-doc header metadata ----
$docs = [];

if (!empty($rfqIds)) {
    $sql = "SELECT r.id, r.rfq_number AS primary_number,
                   r.vendor_id, v.name AS vendor_name, r.state, r.created_at
              FROM `purchase__rfq` r
              JOIN `list__vendor` v ON v.id = r.vendor_id
             WHERE r.id IN (" . implode(',', $rfqIds) . ")";
    foreach ($MsaDB->query($sql, \PDO::FETCH_ASSOC) as $r) {
        $docs[] = [
            'type'            => 'rfq',
            'id'              => (int)$r['id'],
            'primary_number'  => (string)($r['primary_number'] ?? ''),
            'secondary_number'=> '',
            'vendor_id'       => (int)$r['vendor_id'],
            'vendor_name'     => (string)$r['vendor_name'],
            'state'           => (string)$r['state'],
            'created_at'      => (string)$r['created_at'],
            'editable'        => in_array($r['state'], PurchaseActionHandler::allowedEditStates('rfq'), true),
            'edit_url'        => 'http://' . BASEURL . '/admin/purchase/documents/edit?id=' . (int)$r['id'] . '&type=rfq',
            'item_count'      => 0,
            'value_breakdown' => [],
        ];
    }
}
if (!empty($poIds)) {
    $sql = "SELECT o.id, o.po_number AS primary_number, o.vendor_po_number AS secondary_number,
                   o.vendor_id, v.name AS vendor_name, o.state, o.created_at
              FROM `purchase__order` o
              JOIN `list__vendor` v ON v.id = o.vendor_id
             WHERE o.id IN (" . implode(',', $poIds) . ")";
    foreach ($MsaDB->query($sql, \PDO::FETCH_ASSOC) as $r) {
        $docs[] = [
            'type'            => 'po',
            'id'              => (int)$r['id'],
            'primary_number'  => (string)($r['primary_number'] ?? ''),
            'secondary_number'=> (string)($r['secondary_number'] ?? ''),
            'vendor_id'       => (int)$r['vendor_id'],
            'vendor_name'     => (string)$r['vendor_name'],
            'state'           => (string)$r['state'],
            'created_at'      => (string)$r['created_at'],
            'editable'        => in_array($r['state'], PurchaseActionHandler::allowedEditStates('po'), true),
            'edit_url'        => 'http://' . BASEURL . '/admin/purchase/documents/edit?id=' . (int)$r['id'] . '&type=po',
            'item_count'      => 0,
            'value_breakdown' => [],
        ];
    }
}

// ---- per-doc item aggregates (count + per-currency totals) ----
// GROUP_CONCAT over a subquery that pre-aggregates per (doc, currency). The
// CSV format is "CURRENCY:AMOUNT||CURRENCY:AMOUNT"; PHP re-parses. Cheaper
// than fetching every item row and aggregating in PHP.
$itemsByDoc = [];

if (!empty($rfqIds)) {
    $sql = "SELECT sub.rfq_id,
                   SUM(sub.cnt) AS cnt,
                   GROUP_CONCAT(
                       CONCAT(sub.currency, ':', CAST(sub.total AS DECIMAL(30, 10)))
                       ORDER BY sub.currency ASC
                       SEPARATOR '||'
                   ) AS val_csv
              FROM (
                    SELECT rfq_id, currency,
                           COUNT(*) AS cnt,
                           SUM(quantity * COALESCE(unit_price, 0)) AS total
                      FROM `purchase__rfq_item`
                     WHERE rfq_id IN (" . implode(',', $rfqIds) . ")
                     GROUP BY rfq_id, currency
                   ) AS sub
             GROUP BY sub.rfq_id";
    foreach ($MsaDB->query($sql, \PDO::FETCH_ASSOC) as $r) {
        $values = [];
        if (!empty($r['val_csv'])) {
            foreach (explode('||', $r['val_csv']) as $entry) {
                $parts = explode(':', $entry, 2);
                if (count($parts) === 2) {
                    $values[] = ['currency' => $parts[0], 'total' => (float)$parts[1]];
                }
            }
        }
        $itemsByDoc['rfq:' . $r['rfq_id']] = ['count' => (int)$r['cnt'], 'values' => $values];
    }
}
if (!empty($poIds)) {
    $sql = "SELECT sub.po_id,
                   SUM(sub.cnt) AS cnt,
                   GROUP_CONCAT(
                       CONCAT(sub.currency, ':', CAST(sub.total AS DECIMAL(30, 10)))
                       ORDER BY sub.currency ASC
                       SEPARATOR '||'
                   ) AS val_csv
              FROM (
                    SELECT po_id, currency,
                           COUNT(*) AS cnt,
                           SUM(quantity * COALESCE(unit_price, 0)) AS total
                      FROM `purchase__order_item`
                     WHERE po_id IN (" . implode(',', $poIds) . ")
                     GROUP BY po_id, currency
                   ) AS sub
             GROUP BY sub.po_id";
    foreach ($MsaDB->query($sql, \PDO::FETCH_ASSOC) as $r) {
        $values = [];
        if (!empty($r['val_csv'])) {
            foreach (explode('||', $r['val_csv']) as $entry) {
                $parts = explode(':', $entry, 2);
                if (count($parts) === 2) {
                    $values[] = ['currency' => $parts[0], 'total' => (float)$parts[1]];
                }
            }
        }
        $itemsByDoc['po:' . $r['po_id']] = ['count' => (int)$r['cnt'], 'values' => $values];
    }
}

// Stitch items into docs and re-order to match the cross-type page slice.
$docsByKey = [];
foreach ($docs as $d) {
    $key = $d['type'] . ':' . $d['id'];
    $agg = $itemsByDoc[$key] ?? ['count' => 0, 'values' => []];
    $d['item_count']      = $agg['count'];
    $d['value_breakdown'] = $agg['values'];
    $docsByKey[$key] = $d;
}

$ordered = [];
foreach ($pageSlice as $row) {
    $key = $row['type'] . ':' . $row['id'];
    if (isset($docsByKey[$key])) {
        $ordered[] = $docsByKey[$key];
    }
}

echo json_encode([
    'docs'        => $ordered,
    'currentPage' => $page,
    'snapshot_ts' => date('Y-m-d H:i:s'),
], JSON_UNESCAPED_UNICODE);
