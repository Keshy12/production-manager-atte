<?php
/**
 * Debug tool — FlowPin ↔ MSA SKU comparison.
 *
 * Compares the current state of FlowPin [dbo].[ProductTypes] (CompanyId = 1)
 * against MSA list__sku. Built to surface the SKUs that a future one-time sync
 * will need to remove inventory for (misnamed devices and orphans).
 *
 * Sections, in priority order:
 *   1. Mismatched SKUs   — same Id on both sides, Symbol ≠ name        (cleanup)
 *   2. Missing in FlowPin — exist in MSA, gone from FlowPin            (cleanup / orphan)
 *   3. Missing in MSA    — exist in FlowPin, will be auto-created next sync (info)
 *   4. ID conflicts      — same Symbol/name on both sides under different Ids (info)
 *
 * Accessible at /test (publicly, per index.php route table).
 */

use Atte\DB\MsaDB;
use Atte\DB\FlowpinDB;

const ROW_LIMIT = 5000;

// Tables that hold inventory data tied to list__sku.id. None of these have
// ON DELETE CASCADE — the cleanup script must DELETE rows here before the SKU.
// Order matters:
//   - bom__flat has bom_sku_id → bom__sku.id, so bom__flat must be deleted
//     before bom__sku (and the WHERE must also catch bom__flat rows whose
//     sku_id is outside the cleanup set but whose bom_sku_id points at
//     cleanup bom__sku rows).
//   - used__sku and bom__sku are siblings (both FK list__sku.id); user wants
//     used__sku swept before bom__sku.
const INVENTORY_TABLES = ['inventory__sku', 'bom__flat', 'used__sku', 'bom__sku'];

// Notifications that reference list__sku.id via notification__list.value_for_action.
// value_for_action is TEXT (not a foreign key), but MySQL coerces numeric strings
// for the IN comparison, so cleanup works. Children first, then notification__list,
// before list__sku.
const NOTIFICATION_CHILD_TABLES = ['notification__receivers', 'notification__queries_affected'];
const NOTIFICATION_PARENT_TABLE = 'notification__list';

function fetchAll($db, string $sql): array {
    $rows = $db->query($sql);
    return is_array($rows) ? $rows : [];
}

/**
 * Compare two strings. Returns:
 *   ['match' => true,  'caseOnly' => false] — identical
 *   ['match' => false, 'caseOnly' => true]  — equal after case-fold
 *   ['match' => false, 'caseOnly' => false] — anything else
 */
function nameCmp(string $a, string $b): array {
    if ($a === $b)               return ['match' => true,  'caseOnly' => false];
    if (strcasecmp($a, $b) === 0) return ['match' => false, 'caseOnly' => true];
    return ['match' => false, 'caseOnly' => false];
}

/**
 * Hard-delete inventory rows and SKU entries for the given candidate ids.
 * Atomic via transaction. In dry-run mode, only counts are returned — no writes.
 *
 * @param int[] $cleanupIds  list__sku.id values to clean up
 * @return array             status report (counts always; deletions only when executed)
 */
function runCleanup(array $cleanupIds, $MsaDB, bool $dryRun): array {
    $report = [
        'dry_run'     => $dryRun,
        'cleanup_ids' => $cleanupIds,
        'status'      => 'noop',
    ];

    if (empty($cleanupIds)) {
        $report['message'] = 'No cleanup candidates.';
        return $report;
    }

    $idList = implode(',', array_map('intval', $cleanupIds));

    // Pre-fetch the bom__sku ids we'll delete, so we can also sweep the
    // bom__flat rows that reference them via bom_sku_id (not just sku_id).
    $bomSkuRows = $MsaDB->query(
        "SELECT id FROM `bom__sku` WHERE sku_id IN ($idList)"
    );
    $bomSkuIds    = array_map('intval', array_column($bomSkuRows, 'id'));
    $bomSkuIdList = !empty($bomSkuIds) ? implode(',', $bomSkuIds) : '';

    // bom__flat predicate that catches both the direct (sku_id) and the
    // indirect (bom_sku_id → bom__sku.id) cleanup targets.
    $bomFlatWhere = "sku_id IN ($idList)"
        . ($bomSkuIdList !== '' ? " OR bom_sku_id IN ($bomSkuIdList)" : '');

    // Pre-fetch: notification ids whose value_for_action references a
    // cleanup SKU. value_for_action is TEXT, but MySQL coerces numeric
    // strings in the IN comparison.
    $notificationRows   = $MsaDB->query(
        "SELECT id FROM `" . NOTIFICATION_PARENT_TABLE . "` WHERE value_for_action IN ($idList)"
    );
    $notificationIds    = array_map('intval', array_column($notificationRows, 'id'));
    $notificationIdList = !empty($notificationIds) ? implode(',', $notificationIds) : '';

    // Always compute what would be (or was) affected.
    foreach (INVENTORY_TABLES as $table) {
        $where = ($table === 'bom__flat') ? $bomFlatWhere : "sku_id IN ($idList)";
        $rows  = $MsaDB->query("SELECT COUNT(*) AS c FROM `$table` WHERE $where");
        $report["{$table}_count"] = (int)($rows[0]['c'] ?? 0);
    }
    foreach (NOTIFICATION_CHILD_TABLES as $table) {
        $count = 0;
        if ($notificationIdList !== '') {
            $rows  = $MsaDB->query(
                "SELECT COUNT(*) AS c FROM `$table` WHERE notification_id IN ($notificationIdList)"
            );
            $count = (int)($rows[0]['c'] ?? 0);
        }
        $report["{$table}_count"] = $count;
    }
    $report[NOTIFICATION_PARENT_TABLE . '_count'] = count($notificationIds);

    $skuCountRow = $MsaDB->query(
        "SELECT COUNT(*) AS c FROM `list__sku` WHERE `id` IN ($idList)"
    );
    $report['list__sku_count'] = (int)($skuCountRow[0]['c'] ?? 0);

    if ($dryRun) {
        $report['status'] = 'preview';
        return $report;
    }

    // Execute — child rows first, then list__sku (no CASCADE on these FKs).
    $MsaDB->db->beginTransaction();
    try {
        // Inventory (FK children of list__sku).
        foreach (INVENTORY_TABLES as $table) {
            $where = ($table === 'bom__flat') ? $bomFlatWhere : "sku_id IN ($idList)";
            $stmt  = $MsaDB->db->prepare("DELETE FROM `$table` WHERE $where");
            $stmt->execute();
            $report["{$table}_deleted"] = $stmt->rowCount();
        }

        // Notifications (children first, then notification__list).
        foreach (NOTIFICATION_CHILD_TABLES as $table) {
            if ($notificationIdList === '') continue;
            $stmt = $MsaDB->db->prepare(
                "DELETE FROM `$table` WHERE notification_id IN ($notificationIdList)"
            );
            $stmt->execute();
            $report["{$table}_deleted"] = $stmt->rowCount();
        }
        if (!empty($notificationIds)) {
            $stmt = $MsaDB->db->prepare(
                "DELETE FROM `" . NOTIFICATION_PARENT_TABLE . "` WHERE id IN ($notificationIdList)"
            );
            $stmt->execute();
            $report[NOTIFICATION_PARENT_TABLE . '_deleted'] = $stmt->rowCount();
        }

        // list__sku last.
        $stmt = $MsaDB->db->prepare("DELETE FROM `list__sku` WHERE `id` IN ($idList)");
        $stmt->execute();
        $report['list__sku_deleted'] = $stmt->rowCount();

        $MsaDB->db->commit();
        $report['status'] = 'completed';
    } catch (\Throwable $e) {
        $MsaDB->db->rollBack();
        $report['status'] = 'error';
        $report['error']  = $e->getMessage();
    }

    return $report;
}

$MsaDB     = MsaDB::getInstance();
$FlowpinDB = FlowpinDB::getInstance();

// ---- Load current state -------------------------------------------------

$flowpinProducts = fetchAll($FlowpinDB,
    "SELECT Id, Symbol, Description FROM [dbo].[ProductTypes] WHERE CompanyId = 1"
);
$msaSkus = fetchAll($MsaDB,
    "SELECT id, name, description, isActive FROM list__sku"
);

// ---- Indexes ------------------------------------------------------------

$flowpinById = [];
$flowpinIdsBySymbol = [];   // strtolower(trim(symbol)) => [id, id, ...]
foreach ($flowpinProducts as $p) {
    $id = (int)$p['Id'];
    $flowpinById[$id] = $p;
    $sym = trim((string)($p['Symbol'] ?? ''));
    if ($sym !== '') {
        $flowpinIdsBySymbol[strtolower($sym)][] = $id;
    }
}

$msaById = [];
$msaIdsByName = [];         // strtolower(trim(name)) => [id, id, ...]
foreach ($msaSkus as $s) {
    $id = (int)$s['id'];
    $msaById[$id] = $s;
    $name = trim((string)($s['name'] ?? ''));
    if ($name !== '') {
        $msaIdsByName[strtolower($name)][] = $id;
    }
}

// ---- Classify each FlowPin row -----------------------------------------

$matched       = [];
$mismatches    = [];
$missingInMsa  = [];

foreach ($flowpinById as $id => $fp) {
    if (!isset($msaById[$id])) {
        $missingInMsa[] = [
            'id'          => $id,
            'symbol'      => (string)($fp['Symbol'] ?? ''),
            'description' => $fp['Description'] ?? null,
        ];
        continue;
    }

    $msa     = $msaById[$id];
    $fpSym   = (string)($fp['Symbol'] ?? '');
    $msaName = (string)($msa['name'] ?? '');
    $cmp     = nameCmp(trim($fpSym), trim($msaName));

    if ($cmp['match']) {
        $matched[] = $id;
    } else {
        $mismatches[] = [
            'id'             => $id,
            'msa_name'       => $msaName,
            'flowpin_symbol' => $fpSym,
            'case_only'      => $cmp['caseOnly'],
        ];
    }
}

// ---- MSA rows with no FlowPin counterpart (orphans) --------------------

$missingInFlowpin = [];
foreach ($msaById as $id => $msa) {
    if (isset($flowpinById[$id])) continue;
    $missingInFlowpin[] = [
        'id'          => $id,
        'name'        => $msa['name'] ?? null,
        'description' => $msa['description'] ?? null,
        'isActive'    => $msa['isActive'] ?? null,
    ];
}

// ---- ID conflicts ------------------------------------------------------
// A name appears on both sides but the id sets aren't identical — at least
// one side has an id the other doesn't.

$idConflicts = [];
foreach ($flowpinIdsBySymbol as $key => $fpIds) {
    if (!isset($msaIdsByName[$key])) continue;
    $msaIds  = $msaIdsByName[$key];
    $shared  = array_values(array_intersect($fpIds, $msaIds));
    $extraFp = array_values(array_diff($fpIds, $msaIds));
    $extraM  = array_values(array_diff($msaIds, $fpIds));
    if (empty($extraFp) && empty($extraM)) continue;   // everything matches — not a conflict

    $displaySymbol = '';
    foreach ($fpIds as $fid) {
        if (isset($flowpinById[$fid])) {
            $displaySymbol = trim((string)($flowpinById[$fid]['Symbol'] ?? ''));
            break;
        }
    }

    $idConflicts[] = [
        'symbol'         => $displaySymbol,
        'flowpin_ids'    => array_values($fpIds),
        'msa_ids'        => array_values($msaIds),
    ];
}

// ---- Inventory impact for cleanup candidates ----------------------------
// Cleanup candidates = mismatches ∪ missing-in-FlowPin. Inventory counts
// are loaded in one grouped query per table (no N+1).

$cleanupCandidateIds = array_merge(
    array_column($mismatches, 'id'),
    array_column($missingInFlowpin, 'id')
);
$inventoryImpact = [];                                    // id => [table => count]
$inventoryTotals = array_fill_keys(INVENTORY_TABLES, 0);

if (!empty($cleanupCandidateIds)) {
    $idList = implode(',', array_map('intval', $cleanupCandidateIds));
    foreach (INVENTORY_TABLES as $table) {
        $rows = $MsaDB->query(
            "SELECT sku_id, COUNT(*) AS cnt FROM `$table` WHERE sku_id IN ($idList) GROUP BY sku_id"
        );
        foreach ($rows as $row) {
            $id    = (int)$row['sku_id'];
            $count = (int)$row['cnt'];
            $inventoryImpact[$id][$table] = $count;
            $inventoryTotals[$table]     += $count;
        }
    }
}

// ---- Cleanup action handler (POST/Redirect/GET) ------------------------

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cleanup') {
    $dryRun = !empty($_POST['dry_run']);
    $result = runCleanup($cleanupCandidateIds, $MsaDB, $dryRun);
    $_SESSION['cleanup_result'] = $result;
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}

$cleanupResult = $_SESSION['cleanup_result'] ?? null;
unset($_SESSION['cleanup_result']);

// Stamp impact counts onto each cleanup-candidate row.
foreach ($mismatches as &$m) {
    $id = $m['id'];
    $m['inv_count']      = $inventoryImpact[$id]['inventory__sku'] ?? 0;
    $m['bom_count']      = $inventoryImpact[$id]['bom__sku'] ?? 0;
    $m['bom_flat_count'] = $inventoryImpact[$id]['bom__flat'] ?? 0;
    $m['used_count']     = $inventoryImpact[$id]['used__sku'] ?? 0;
    $m['total_impact']   = $m['inv_count'] + $m['bom_count'] + $m['bom_flat_count'] + $m['used_count'];
}
unset($m);

foreach ($missingInFlowpin as &$mf) {
    $id = $mf['id'];
    $mf['inv_count']      = $inventoryImpact[$id]['inventory__sku'] ?? 0;
    $mf['bom_count']      = $inventoryImpact[$id]['bom__sku'] ?? 0;
    $mf['bom_flat_count'] = $inventoryImpact[$id]['bom__flat'] ?? 0;
    $mf['used_count']     = $inventoryImpact[$id]['used__sku'] ?? 0;
    $mf['total_impact']   = $mf['inv_count'] + $mf['bom_count'] + $mf['bom_flat_count'] + $mf['used_count'];
}
unset($mf);

// Most-impactful first, then by id.
usort($mismatches, function ($a, $b) {
    return $b['total_impact'] <=> $a['total_impact'] ?: $a['id'] <=> $b['id'];
});
usort($missingInFlowpin, function ($a, $b) {
    return $b['total_impact'] <=> $a['total_impact'] ?: $a['id'] <=> $b['id'];
});
usort($idConflicts, fn($a, $b) => strnatcasecmp($a['symbol'] ?? '', $b['symbol'] ?? ''));

// ---- KPIs ---------------------------------------------------------------

$totalFlowpin            = count($flowpinProducts);
$totalMsa                = count($msaSkus);
$totalMatched            = count($matched);
$totalMismatched         = count($mismatches);
$totalMissingInMsa       = count($missingInMsa);
$totalMissingInFlowpin   = count($missingInFlowpin);
$totalIdConflicts        = count($idConflicts);
$totalCleanupCandidates  = $totalMismatched + $totalMissingInFlowpin;
$totalInventoryRows      = array_sum($inventoryTotals);

// ---- Render helpers -----------------------------------------------------

$h = fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');

function renderTable(array $rows, array $columns, ?callable $cellRenderer = null): void {
    if (empty($rows)) {
        echo '<div class="alert alert-light border mb-4">No rows.</div>';
        return;
    }
    global $h;
    $truncated = count($rows) > ROW_LIMIT;
    if ($truncated) {
        $rows = array_slice($rows, 0, ROW_LIMIT);
    }
    ?>
    <div class="table-responsive mb-4">
        <table class="table table-sm table-striped table-bordered">
            <thead class="thead-light">
            <tr>
                <?php foreach ($columns as $col): ?>
                    <th><?= $h($col) ?></th>
                <?php endforeach; ?>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <?php foreach ($columns as $col):
                        $cell = $row[$col] ?? '';
                        if ($cellRenderer) {
                            $cell = $cellRenderer($col, $cell, $row);
                        }
                        ?>
                        <td><?= $h($cell) ?></td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($truncated): ?>
        <p class="text-warning small">(capped at <?= ROW_LIMIT ?> rows of original list)</p>
    <?php endif;
}

$renderIds = fn($col, $cell): string => is_array($cell)
    ? implode(', ', array_map(fn($v) => (string)$v, $cell))
    : (string)$cell;

$renderCountCell = static function (string $col, $cell): string {
    $n = (int)$cell;
    return $n > 0 ? (string)$n : '0';
};
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>FlowPin ↔ MSA — SKU comparison</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.3.1/dist/css/bootstrap.min.css">
    <style>
        body  { padding: 1.5rem; }
        table { font-size: 0.85rem; }
        th    { white-space: nowrap; }
        h4    { border-bottom: 1px solid #dee2e6; padding-bottom: .25rem; margin-top: 2rem; }
        .kpi  { font-size: 1.6rem; font-weight: 600; }
        td.count { text-align: right; font-variant-numeric: tabular-nums; }
        td.count.zero { color: #adb5bd; }
        tr.has-impact > td { background-color: #fff8e1 !important; }
    </style>
</head>
<body>
<div class="container-fluid">
    <h3 class="mb-3">FlowPin ↔ MSA — SKU comparison</h3>
    <p class="text-muted small">
        Compares the current state of FlowPin <code>[dbo].[ProductTypes]</code> (CompanyId = 1)
        against MSA <code>list__sku</code>. Dev tool, accessible at <code>/test</code>.
    </p>

    <div class="row text-center mb-3">
        <div class="col"><div class="card"><div class="card-body py-2">
                    <div class="kpi"><?= $totalFlowpin ?></div>
                    <div class="small text-muted">FlowPin products</div>
                </div></div></div>
        <div class="col"><div class="card"><div class="card-body py-2">
                    <div class="kpi"><?= $totalMsa ?></div>
                    <div class="small text-muted">MSA SKUs</div>
                </div></div></div>
        <div class="col"><div class="card"><div class="card-body py-2">
                    <div class="kpi text-success"><?= $totalMatched ?></div>
                    <div class="small text-muted">Matched</div>
                </div></div></div>
        <div class="col"><div class="card <?= $totalMismatched ? 'border-warning' : '' ?>"><div class="card-body py-2">
                    <div class="kpi <?= $totalMismatched ? 'text-warning' : 'text-success' ?>"><?= $totalMismatched ?></div>
                    <div class="small text-muted">Mismatched</div>
                </div></div></div>
        <div class="col"><div class="card <?= $totalMissingInFlowpin ? 'border-danger' : '' ?>"><div class="card-body py-2">
                    <div class="kpi <?= $totalMissingInFlowpin ? 'text-danger' : 'text-success' ?>"><?= $totalMissingInFlowpin ?></div>
                    <div class="small text-muted">Missing in FlowPin</div>
                </div></div></div>
        <div class="col"><div class="card"><div class="card-body py-2">
                    <div class="kpi"><?= $totalMissingInMsa ?></div>
                    <div class="small text-muted">Missing in MSA</div>
                </div></div></div>
        <div class="col"><div class="card <?= $totalIdConflicts ? 'border-info' : '' ?>"><div class="card-body py-2">
                    <div class="kpi <?= $totalIdConflicts ? 'text-info' : 'text-success' ?>"><?= $totalIdConflicts ?></div>
                    <div class="small text-muted">ID conflicts</div>
                </div></div></div>
    </div>

    <?php if ($totalCleanupCandidates > 0): ?>
        <div class="alert alert-warning">
            <strong><?= $totalCleanupCandidates ?></strong> cleanup candidate<?= $totalCleanupCandidates === 1 ? '' : 's' ?>
            (<?= $totalMismatched ?> mismatched + <?= $totalMissingInFlowpin ?> missing in FlowPin).
            Together they reference <strong><?= $totalInventoryRows ?></strong> inventory row<?= $totalInventoryRows === 1 ? '' : 's' ?>
            across <code><?= implode('</code>, <code>', INVENTORY_TABLES) ?></code> — these will be removed by the upcoming one-time sync.
            <?php foreach ($inventoryTotals as $t => $c): if ($c > 0): ?>
                <span class="badge badge-secondary ml-2"><?= $h($t) ?>: <?= $c ?></span>
            <?php endif; endforeach; ?>
        </div>
    <?php else: ?>
        <div class="alert alert-success">
            No cleanup candidates — every FlowPin SKU either matches MSA or will be auto-created on the next sync.
        </div>
    <?php endif; ?>

    <?php if ($cleanupResult): ?>
        <?php
        $alertClass = match($cleanupResult['status'] ?? '') {
            'completed' => 'success',
            'error'     => 'danger',
            'preview'   => 'info',
            default     => 'secondary',
        };
        ?>
        <div class="alert alert-<?= $alertClass ?>">
            <strong>
                <?php if (($cleanupResult['status'] ?? '') === 'preview'): ?>
                    Preview — no changes made.
                <?php elseif (($cleanupResult['status'] ?? '') === 'completed'): ?>
                    Cleanup completed.
                <?php elseif (($cleanupResult['status'] ?? '') === 'error'): ?>
                    Cleanup failed — transaction rolled back.
                <?php else: ?>
                    Nothing to do.
                <?php endif; ?>
            </strong>
            <ul class="mb-0 mt-2 small">
                <?php if (!empty($cleanupResult['dry_run'])): ?>
                    <?php foreach (INVENTORY_TABLES as $t): ?>
                        <li><code><?= $h($t) ?></code>: <?= (int)($cleanupResult["{$t}_count"] ?? 0) ?> row<?= (int)($cleanupResult["{$t}_count"] ?? 0) === 1 ? '' : 's' ?> would be deleted</li>
                    <?php endforeach; ?>
                    <?php foreach (NOTIFICATION_CHILD_TABLES as $t): ?>
                        <li><code><?= $h($t) ?></code>: <?= (int)($cleanupResult["{$t}_count"] ?? 0) ?> row<?= (int)($cleanupResult["{$t}_count"] ?? 0) === 1 ? '' : 's' ?> would be deleted</li>
                    <?php endforeach; ?>
                    <li><code><?= $h(NOTIFICATION_PARENT_TABLE) ?></code>: <?= (int)($cleanupResult[NOTIFICATION_PARENT_TABLE . '_count'] ?? 0) ?> notification<?= (int)($cleanupResult[NOTIFICATION_PARENT_TABLE . '_count'] ?? 0) === 1 ? '' : 's' ?> would be deleted</li>
                    <li><code>list__sku</code>: <?= (int)($cleanupResult['list__sku_count'] ?? 0) ?> SKU<?= (int)($cleanupResult['list__sku_count'] ?? 0) === 1 ? '' : 's' ?> would be deleted</li>
                <?php else: ?>
                    <?php foreach (INVENTORY_TABLES as $t): ?>
                        <li><code><?= $h($t) ?></code>: <?= (int)($cleanupResult["{$t}_deleted"] ?? 0) ?> row<?= (int)($cleanupResult["{$t}_deleted"] ?? 0) === 1 ? '' : 's' ?> deleted</li>
                    <?php endforeach; ?>
                    <?php foreach (NOTIFICATION_CHILD_TABLES as $t): ?>
                        <li><code><?= $h($t) ?></code>: <?= (int)($cleanupResult["{$t}_deleted"] ?? 0) ?> row<?= (int)($cleanupResult["{$t}_deleted"] ?? 0) === 1 ? '' : 's' ?> deleted</li>
                    <?php endforeach; ?>
                    <li><code><?= $h(NOTIFICATION_PARENT_TABLE) ?></code>: <?= (int)($cleanupResult[NOTIFICATION_PARENT_TABLE . '_deleted'] ?? 0) ?> notification<?= (int)($cleanupResult[NOTIFICATION_PARENT_TABLE . '_deleted'] ?? 0) === 1 ? '' : 's' ?> deleted</li>
                    <li><code>list__sku</code>: <?= (int)($cleanupResult['list__sku_deleted'] ?? 0) ?> SKU<?= (int)($cleanupResult['list__sku_deleted'] ?? 0) === 1 ? '' : 's' ?> deleted</li>
                <?php endif; ?>
            </ul>
            <?php if (!empty($cleanupResult['error'])): ?>
                <div class="text-danger mt-2 small"><?= $h($cleanupResult['error']) ?></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($totalCleanupCandidates > 0): ?>
        <div class="card border-warning mb-4">
            <div class="card-body">
                <h5 class="card-title mb-2">Cleanup actions</h5>
                <p class="card-text small text-muted mb-3">
                    The cleanup hard-deletes inventory rows and SKU entries for the
                    <strong><?= $totalCleanupCandidates ?></strong> candidate<?= $totalCleanupCandidates === 1 ? '' : 's' ?>
                    listed below (<strong><?= $totalInventoryRows ?></strong> inventory row<?= $totalInventoryRows === 1 ? '' : 's' ?> total).
                    The next sync will re-create the misnamed SKUs with correct names from FlowPin.
                    This action is <strong>irreversible</strong> — run the preview first.
                </p>
                <form method="POST" action="" class="d-inline">
                    <input type="hidden" name="action" value="cleanup">
                    <input type="hidden" name="dry_run" value="1">
                    <button type="submit" class="btn btn-outline-secondary btn-sm">
                        Preview (dry run)
                    </button>
                </form>
                <form method="POST" action="" class="d-inline ml-2"
                      onsubmit="return confirm('This will DELETE <?= $totalCleanupCandidates ?> SKU entr<?= $totalCleanupCandidates === 1 ? 'y' : 'ies' ?> and <?= $totalInventoryRows ?> inventory row<?= $totalInventoryRows === 1 ? '' : 's' ?>. This cannot be undone. Proceed?');">
                    <input type="hidden" name="action" value="cleanup">
                    <input type="hidden" name="dry_run" value="0">
                    <button type="submit" class="btn btn-danger btn-sm">
                        Execute cleanup
                    </button>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <h4>Mismatched SKUs
        <small class="text-muted">— same Id on both sides, Symbol ≠ name. Cleanup target.</small>
    </h4>
    <?php renderTable(
        $mismatches,
        ['id', 'msa_name', 'flowpin_symbol', 'case_only', 'inv_count', 'bom_count', 'bom_flat_count', 'used_count', 'total_impact'],
        function (string $col, $cell, array $row) use ($renderCountCell): string {
            if ($col === 'case_only') return $row['case_only'] ? 'case-only' : 'different';
            if (in_array($col, ['inv_count', 'bom_count', 'bom_flat_count', 'used_count', 'total_impact'], true)) {
                return $renderCountCell($col, $cell);
            }
            return (string)$cell;
        }
    ); ?>

    <h4>Missing in FlowPin
        <small class="text-muted">— exist in MSA <code>list__sku</code> but absent from FlowPin. Cleanup target (orphans).</small>
    </h4>
    <?php renderTable(
        $missingInFlowpin,
        ['id', 'name', 'isActive', 'inv_count', 'bom_count', 'bom_flat_count', 'used_count', 'total_impact'],
        function (string $col, $cell, array $row) use ($renderCountCell): string {
            if (in_array($col, ['inv_count', 'bom_count', 'bom_flat_count', 'used_count', 'total_impact'], true)) {
                return $renderCountCell($col, $cell);
            }
            if ($col === 'isActive') return $cell ? 'yes' : 'no';
            return (string)$cell;
        }
    ); ?>

    <h4>Missing in MSA
        <small class="text-muted">— exist in FlowPin but absent from MSA. Will be auto-created on the next sync.</small>
    </h4>
    <?php renderTable($missingInMsa, ['id', 'symbol', 'description']); ?>

    <h4>ID conflicts
        <small class="text-muted">— same Symbol/name present on both sides, but Ids don't fully line up.</small>
    </h4>
    <?php renderTable($idConflicts, ['symbol', 'flowpin_ids', 'msa_ids'], $renderIds); ?>
</div>
</body>
</html>
