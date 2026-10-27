<?php
/**
 * One-shot CLI: deactivate vendors that have no contact persons and no
 * vendor articles (empty vendor records left over in the catalogue).
 *
 * A vendor is a candidate when it has:
 *   - 0 rows in `list__vendor_supplier` (contact persons), and
 *   - 0 rows in `list__vendor_part`     (vendor's own part numbers).
 *
 * Child rows are counted regardless of their own `isActive` flag - this
 * deliberately matches the catalogue filters in
 * VendorRepository::buildSearchWhere(), which count rows, not active rows.
 *
 * For every candidate that is currently active (`isActive = 1`):
 *   - `isActive` is set to 0;
 *   - when `comment` is NULL / empty, the comment is set to
 *     'Automatycznie wyłączony: brak osób kontaktowych i artykułów';
 *   - when a comment already exists it is KEPT UNTOUCHED (never
 *     appended to, never overwritten).
 *
 * Vendors that are already inactive are only reported as skipped - they
 * are left completely alone (no UPDATE is issued for them at all).
 *
 * The script is idempotent and safe to re-run: after a successful run the
 * deactivated vendors are still matched by the selection query, but they
 * are reported as skipped, so a second run changes nothing.
 *
 * Usage:
 *   php src/cron/deactivate-empty-vendors.php            # DRY RUN (default)
 *   php src/cron/deactivate-empty-vendors.php --execute
 */

declare(strict_types=1);

use Atte\DB\MsaDB;

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("This script may only be run from the command line.\n");
}

require_once __DIR__ . '/../../config/config.php';

/** Comment written to vendors deactivated by this script (Polish - DB content). */
const EMPTY_VENDOR_COMMENT = 'Automatycznie wyłączony: brak osób kontaktowych i artykułów';

$execute = in_array('--execute', $argv ?? [], true);

function out(string $msg): void {
    global $execute;
    echo date('H:i:s') . ' ' . ($execute ? '' : '[DRY-RUN] ') . $msg . PHP_EOL;
}
function fail(string $msg): void { out("ERROR: $msg"); exit(1); }

out('Mode: ' . ($execute ? 'EXECUTE' : 'DRY RUN (pass --execute to apply)'));
out('Selection: vendors with 0 contact persons and 0 vendor articles.');

// ── Select candidates ─────────────────────────────────────────────────
$db  = MsaDB::getInstance();
$pdo = $db->db;

$selSql = "SELECT v.id, v.name, v.isActive, v.comment
             FROM `list__vendor` v
            WHERE NOT EXISTS (SELECT 1 FROM `list__vendor_supplier` s WHERE s.vendor_id = v.id)
              AND NOT EXISTS (SELECT 1 FROM `list__vendor_part` p WHERE p.vendor_id = v.id)
            ORDER BY v.name ASC";

$sel = $pdo->prepare($selSql);
$sel->execute();
$rows = $sel->fetchAll(\PDO::FETCH_ASSOC);

out('Matched vendors: ' . count($rows));

// Set isActive = 0 and write the auto comment.
$updWithComment = $pdo->prepare("UPDATE `list__vendor` SET `isActive` = 0, `comment` = ? WHERE `id` = ?");
// Set isActive = 0 only - the existing comment is preserved untouched.
$updOnlyActive  = $pdo->prepare("UPDATE `list__vendor` SET `isActive` = 0 WHERE `id` = ?");

$stats = ['matched' => 0, 'deactivated' => 0, 'skipped' => 0, 'comments' => 0];

// ── Apply ────────────────────────────────────────────────────────────
try {
    $pdo->beginTransaction();

    foreach ($rows as $v) {
        $id       = (int)$v['id'];
        $name     = (string)$v['name'];
        $isActive = (int)$v['isActive'];
        $comment  = (string)($v['comment'] ?? '');

        $stats['matched']++;

        if ($isActive !== 1) {
            // Already inactive - reported, but not touched at all.
            $stats['skipped']++;
            out("  {$id} | {$name} -> skipped (already inactive, untouched)");
            continue;
        }

        if (trim($comment) === '') {
            $updWithComment->execute([EMPTY_VENDOR_COMMENT, $id]);
            $stats['comments']++;
            $action = 'deactivated, comment written';
        } else {
            $updOnlyActive->execute([$id]);
            $action = 'deactivated, comment preserved (existing comment left untouched)';
        }
        $stats['deactivated']++;
        out("  {$id} | {$name} -> {$action}");
    }

    out("Summary: matched {$stats['matched']}, deactivated {$stats['deactivated']}, "
        . "skipped {$stats['skipped']}, comments written {$stats['comments']}");

    if ($execute) {
        $pdo->commit();
        out('Committed.');
    } else {
        $pdo->rollBack();
        out('Dry run - rolled back, nothing changed.');
    }
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    fail('Rolled back, nothing changed: ' . $e->getMessage());
}
