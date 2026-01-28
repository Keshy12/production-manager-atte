<?php
/**
 * Database Migration Script (PHP Version)
 * Migrates data from atte_ms_old to the current database with progress tracking.
 * 
 * Usage: php migration_runner.php
 */

require_once 'config/config.php';
use Atte\DB\MsaDB;

// Configuration
$sourceDbName = 'atte_ms_old';

// Target Connection (using existing singleton)
$targetDb = MsaDB::getInstance()->db;
$targetDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Source Connection
$sourceUrl = str_replace('dbname=' . ($_ENV['DB_DATABASE'] ?? 'atte_ms'), "dbname=$sourceDbName", $_ENV['MSAURL']);

try {
    $sourceDb = new PDO($sourceUrl, $_ENV['MSAUSERNAME'], $_ENV['MSAPASSWORD']);
    $sourceDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    die("Error: Failed to connect to source database [$sourceDbName]: " . $e->getMessage() . "\n");
}

echo "\033[1;32m=== ATTE MS Migration Started ===\033[0m\n";
echo "Source: $sourceDbName\n";
echo "Target: " . ($_ENV['DB_DATABASE'] ?? 'atte_ms') . "\n\n";

// Disable foreign key checks
$targetDb->exec("SET FOREIGN_KEY_CHECKS = 0;");

/**
 * Helper to print progress clearly in one line
 */
function updateProgress($current, $total, $label = "") {
    $percent = $total > 0 ? round(($current / $total) * 100) : 100;
    $width = 30;
    $filled = $total > 0 ? floor(($current / $total) * $width) : $width;
    $bar = str_repeat("=", $filled) . str_repeat(" ", $width - $filled);
    
    printf("\r  %-25s [%s] %d%% (%d/%d)", 
        substr($label, 0, 25), 
        $bar, 
        $percent, 
        $current, 
        $total
    );
    
    if ($current === $total) {
        echo "\n";
    }
}

/**
 * Migrates a table by selecting target columns and mapping them from source
 */
function migrateTable($sourceDb, $targetDb, $tableName, $customMapping = [], $extraData = []) {
    // Check if source table exists
    $stmt = $sourceDb->query("SHOW TABLES LIKE '$tableName'");
    if (!$stmt->fetch()) {
        echo "  Table [$tableName] does not exist in source. Skipping.\n";
        return;
    }

    // 1. Get counts
    $total = $sourceDb->query("SELECT COUNT(*) FROM `$tableName`")->fetchColumn();
    
    // 2. Get target columns
    $q = $targetDb->query("DESCRIBE `$tableName` ");
    $targetCols = $q->fetchAll(PDO::FETCH_COLUMN);
    
    // 3. Clear target - Use DELETE instead of TRUNCATE for better compatibility with FK
    $targetDb->exec("DELETE FROM `$tableName` ");
    
    // 4. Get source columns to check for renames
    $sq = $sourceDb->query("DESCRIBE `$tableName` ");
    $sourceCols = $sq->fetchAll(PDO::FETCH_COLUMN);
    
    // 5. Prepare Source Query
    $sourceStmt = $sourceDb->query("SELECT * FROM `$tableName` ");
    $count = 0;
    
    $placeholders = implode(',', array_fill(0, count($targetCols), '?'));
    $insertSql = "REPLACE INTO `$tableName` (" . implode(',', array_map(fn($c) => "`$c`", $targetCols)) . ") VALUES ($placeholders)";
    $insertStmt = $targetDb->prepare($insertSql);
    
    updateProgress(0, $total, $tableName);

    while ($row = $sourceStmt->fetch(PDO::FETCH_ASSOC)) {
        $insertData = [];
        foreach ($targetCols as $col) {
            // Priority 1: Custom mapping
            if (isset($customMapping[$col]) && is_callable($customMapping[$col])) {
                $insertData[] = $customMapping[$col]($row);
            } elseif (isset($customMapping[$col])) {
                $insertData[] = $row[$customMapping[$col]] ?? null;
            } 
            // Priority 2: Direct match
            elseif (array_key_exists($col, $row)) {
                $insertData[] = $row[$col];
            } 
            // Priority 3: Common renames
            elseif ($col == 'qty' && isset($row['quantity'])) {
                $insertData[] = $row['quantity'];
            } elseif ($col == 'created_at' && isset($row['timestamp'])) {
                $insertData[] = $row['timestamp'];
            }
            // Priority 4: Defaults for new NOT NULL columns
            elseif ($col == 'is_cancelled') {
                $insertData[] = 0;
            } elseif ($col == 'isVerified') {
                $insertData[] = 1;
            } elseif ($col == 'type_id' && $tableName == 'inventory__transfer_groups') {
                // If migrating from a source that had 'notes', we might want to map it
                // but for simplicity we'll just set migration type
                static $migId = null;
                if ($migId === null) {
                    $res = $targetDb->query("SELECT id FROM ref__transfer_group_types WHERE slug = 'migration'")->fetch();
                    $migId = $res ? $res['id'] : null;
                }
                $insertData[] = $migId;
            } elseif ($col == 'params' && $tableName == 'inventory__transfer_groups') {
                $note = $row['notes'] ?? 'Migracja';
                $insertData[] = json_encode(['note' => $note], JSON_UNESCAPED_UNICODE);
            }
            // Priority 5: Extra data
            elseif (array_key_exists($col, $extraData)) {
                $insertData[] = $extraData[$col];
            } 
            else {
                $insertData[] = null;
            }
        }
        $insertStmt->execute($insertData);
        $count++;
        if ($count % 100 == 0 || $count == $total) {
            updateProgress($count, $total, $tableName);
        }
    }
}

// --- STEP 1: Static / Simple Tables ---
echo "\033[1;34mStep 1: Simple Tables\033[0m\n";
$simpleTables = [
    'user', 'part__group', 'part__type', 'part__unit', 'list__laminate', 
    'list__parts', 'list__sku', 'list__smd', 'list__tht', 
    'magazine__type', 'magazine__list', 'inventory__input_type', 
    'google_oauth', 'group__list', 'group__contractors', 
    'ref__package_exclude', 'ref__valuepackage', 'ref__timestamp',
    'lowstock__parts', 'lowstock__sku', 'lowstock__smd', 'lowstock__tht',
    'notification__action_needed', 'notification__flowpin_query_type', 
    'notification__list', 'notification__queries_affected', 'notification__receivers',
    'used__sku', 'used__smd', 'used__tht', 'ref__flowpin_checkpoints',
    'inventory__transfer_groups', // Added here to migrate if it exists
    'bom__sku', 'bom__smd', 'bom__tht', 'bom__flat'
];

foreach ($simpleTables as $table) {
    try {
        migrateTable($sourceDb, $targetDb, $table);
    } catch (Exception $e) {
        echo "\n\033[0;31mError in $table:\033[0m " . $e->getMessage() . "\n";
    }
}

// --- STEP 2: Create Transfer Groups (If they don't exist in source) ---
echo "\n\033[1;34mStep 2: Checking Transfer Groups\033[0m\n";
$tStmt = $targetDb->query("SELECT COUNT(*) FROM `inventory__transfer_groups` ");
$targetGroupCount = $tStmt->fetchColumn();

if ($targetGroupCount == 0) {
    echo "Creating legacy [inventory__transfer_groups] from timestamps...\n";
    $groupsQuery = "
        SELECT DISTINCT user_id, timestamp FROM (
            SELECT user_id, timestamp FROM inventory__parts
            UNION SELECT user_id, timestamp FROM inventory__sku
            UNION SELECT user_id, timestamp FROM inventory__smd
            UNION SELECT user_id, timestamp FROM inventory__tht
        ) AS combined WHERE user_id IS NOT NULL
    ";
    $gCountTotal = $sourceDb->query("SELECT COUNT(*) FROM ($groupsQuery) AS t")->fetchColumn();
    $gStmt = $sourceDb->query($groupsQuery);

    $migrationTypeResult = $targetDb->query("SELECT id FROM ref__transfer_group_types WHERE slug = 'migration'")->fetch(PDO::FETCH_ASSOC);
    $migrationTypeId = $migrationTypeResult ? $migrationTypeResult['id'] : null;

    $groupInsert = $targetDb->prepare("INSERT INTO inventory__transfer_groups (created_by, created_at, type_id, params) VALUES (?, ?, ?, ?)");
    $gCount = 0;

    updateProgress(0, $gCountTotal, "transfer_groups");
    while ($gRow = $gStmt->fetch(PDO::FETCH_ASSOC)) {
        $params = json_encode(['note' => 'Migracja danych'], JSON_UNESCAPED_UNICODE);
        $groupInsert->execute([$gRow['user_id'], $gRow['timestamp'], $migrationTypeId, $params]);
        $gCount++;
        if ($gCount % 50 == 0 || $gCount == $gCountTotal) {
            updateProgress($gCount, $gCountTotal, "transfer_groups");
        }
    }
} else {
    echo "  [inventory__transfer_groups] already migrated from source.\n";
}

// Pre-fetch groups for faster lookup during inventory migration
echo "  Indexing groups for lookup... ";
$groups = [];
$gIndexStmt = $targetDb->query("SELECT id, created_by, created_at FROM inventory__transfer_groups");
while($g = $gIndexStmt->fetch(PDO::FETCH_ASSOC)) {
    $groups[$g['created_by'] . '_' . $g['created_at']] = $g['id'];
}
echo "Done.\n";

// --- STEP 3: Migrate Inventory Tables with Group Mapping ---
echo "\n\033[1;34mStep 3: Inventory Migration\033[0m\n";
$inventoryTables = ['parts', 'smd', 'tht', 'sku'];
foreach ($inventoryTables as $type) {
    $tableName = "inventory__$type";
    
    // Check if source table exists
    $stmt = $sourceDb->query("SHOW TABLES LIKE '$tableName'");
    if (!$stmt->fetch()) continue;

    $total = $sourceDb->query("SELECT COUNT(*) FROM `$tableName`")->fetchColumn();
    $targetDb->exec("DELETE FROM `$tableName` ");
    
    $q = $targetDb->query("DESCRIBE `$tableName` ");
    $targetCols = $q->fetchAll(PDO::FETCH_COLUMN);
    $placeholders = implode(',', array_fill(0, count($targetCols), '?'));
    $insertStmt = $targetDb->prepare("REPLACE INTO `$tableName` (" . implode(',', array_map(fn($c) => "`$c`", $targetCols)) . ") VALUES ($placeholders)");

    $sourceStmt = $sourceDb->query("SELECT * FROM `$tableName` ");
    $count = 0;
    updateProgress(0, $total, $tableName);
    while ($row = $sourceStmt->fetch(PDO::FETCH_ASSOC)) {
        $insertData = [];
        
        // Find group ID
        $groupId = null;
        if (isset($row['transfer_group_id'])) {
            $groupId = $row['transfer_group_id'];
        } else {
            $groupId = $groups[($row['user_id'] ?? '') . '_' . ($row['timestamp'] ?? '')] ?? null;
        }
        
        foreach ($targetCols as $col) {
            if ($col == 'qty') $insertData[] = $row['qty'] ?? ($row['quantity'] ?? null);
            elseif ($col == 'transfer_group_id') $insertData[] = $groupId;
            elseif ($col == "${type}_bom_id") $insertData[] = $row["${type}_bom_id"] ?? ($row["bom_${type}_id"] ?? null);
            elseif (array_key_exists($col, $row)) $insertData[] = $row[$col];
            elseif ($col == 'is_cancelled') $insertData[] = 0;
            elseif ($col == 'isVerified') $insertData[] = 1;
            else $insertData[] = null;
        }
        $insertStmt->execute($insertData);
        $count++;
        if ($count % 500 == 0 || $count == $total) {
            updateProgress($count, $total, $tableName);
        }
    }
}

// --- STEP 4: Migrate Commission List ---
echo "\n\033[1;34mStep 4: Commissions\033[0m\n";
$tableName = 'commission__list';
$stmt = $sourceDb->query("SHOW TABLES LIKE '$tableName'");
if ($stmt->fetch()) {
    $total = $sourceDb->query("SELECT COUNT(*) FROM `$tableName`")->fetchColumn();
    $targetDb->exec("DELETE FROM `$tableName` ");
    $q = $targetDb->query("DESCRIBE `$tableName` ");
    $targetCols = $q->fetchAll(PDO::FETCH_COLUMN);
    $placeholders = implode(',', array_fill(0, count($targetCols), '?'));
    $insertStmt = $targetDb->prepare("REPLACE INTO `$tableName` (" . implode(',', array_map(fn($c) => "`$c`", $targetCols)) . ") VALUES ($placeholders)");

    $sourceStmt = $sourceDb->query("SELECT * FROM `$tableName` ");
    $count = 0;
    $pMap = [0 => 'none', 1 => 'standard', 2 => 'urgent', 3 => 'critical'];

    updateProgress(0, $total, $tableName);
    while ($row = $sourceStmt->fetch(PDO::FETCH_ASSOC)) {
        $insertData = [];
        foreach ($targetCols as $col) {
            if (array_key_exists($col, $row)) {
                $insertData[] = $row[$col];
                continue;
            }

            switch ($col) {
                case 'created_by': $insertData[] = $row['user_id'] ?? null; break;
                case 'warehouse_from_id': $insertData[] = $row['magazine_from'] ?? null; break;
                case 'warehouse_to_id': $insertData[] = $row['magazine_to'] ?? null; break;
                case 'qty': $insertData[] = $row['quantity'] ?? ($row['qty'] ?? 0); break;
                case 'qty_produced': $insertData[] = $row['quantity_produced'] ?? ($row['qty_produced'] ?? 0); break;
                case 'qty_returned': $insertData[] = $row['quantity_returned'] ?? ($row['qty_returned'] ?? 0); break;
                case 'created_at': $insertData[] = $row['timestamp_created'] ?? ($row['created_at'] ?? null); break;
                case 'updated_at': $insertData[] = $row['timestamp_finished'] ?? ($row['updated_at'] ?? ($row['timestamp_created'] ?? null)); break;
                case 'is_cancelled': $insertData[] = $row['isCancelled'] ?? ($row['is_cancelled'] ?? 0); break;
                case 'state': 
                    if (isset($row['state'])) {
                        $insertData[] = $row['state'];
                    } else {
                        if (($row['isCancelled'] ?? 0) || ($row['is_cancelled'] ?? 0)) $insertData[] = 'cancelled';
                        elseif (($row['state_id'] ?? 0) == 3) $insertData[] = 'completed';
                        else $insertData[] = 'active';
                    }
                    break;
                case 'priority': 
                    if (isset($row['priority']) && !is_numeric($row['priority'])) {
                        $insertData[] = $row['priority'];
                    } else {
                        $insertData[] = $pMap[$row['priority'] ?? 0] ?? 'none'; 
                    }
                    break;
                case 'device_type':
                    if (isset($row['device_type'])) {
                        $insertData[] = $row['device_type'];
                    } else {
                        if ($row['bom_tht_id'] ?? null) $insertData[] = 'tht';
                        elseif ($row['bom_smd_id'] ?? null) $insertData[] = 'smd';
                        else $insertData[] = 'sku';
                    }
                    break;
                case 'bom_id':
                    if (isset($row['bom_id'])) {
                        $insertData[] = $row['bom_id'];
                    } else {
                        $insertData[] = ($row['bom_tht_id'] ?? 0) ?: (($row['bom_smd_id'] ?? 0) ?: (($row['bom_sku_id'] ?? 0) ?: 0));
                    }
                    break;
                default:
                    $insertData[] = null;
            }
        }
        $insertStmt->execute($insertData);
        $count++;
        if ($count % 100 == 0 || $count == $total) {
            updateProgress($count, $total, $tableName);
        }
    }
}

// --- STEP 5: Commission Receivers ---
echo "\n\033[1;34mStep 5: Relationships\033[0m\n";
$tableName = 'commission__receivers';
$stmt = $sourceDb->query("SHOW TABLES LIKE '$tableName'");
if ($stmt->fetch()) {
    $total = $sourceDb->query("SELECT COUNT(*) FROM `$tableName`")->fetchColumn();
    $targetDb->exec("DELETE FROM `$tableName` ");
    $sourceStmt = $sourceDb->query("SELECT * FROM `$tableName` ");
    
    // Check if target has id or composite key
    $q = $targetDb->query("DESCRIBE `$tableName` ");
    $targetCols = $q->fetchAll(PDO::FETCH_COLUMN);
    $placeholders = implode(',', array_fill(0, count($targetCols), '?'));
    $insertStmt = $targetDb->prepare("REPLACE INTO `$tableName` (" . implode(',', array_map(fn($c) => "`$c`", $targetCols)) . ") VALUES ($placeholders)");

    $count = 0;
    updateProgress(0, $total, $tableName);
    while($row = $sourceStmt->fetch(PDO::FETCH_ASSOC)) {
        $insertData = [];
        foreach ($targetCols as $col) {
            $insertData[] = $row[$col] ?? null;
        }
        $insertStmt->execute($insertData);
        $count++;
        if ($count % 100 == 0 || $count == $total) {
            updateProgress($count, $total, $tableName);
        }
    }
}

$targetDb->exec("SET FOREIGN_KEY_CHECKS = 1;");
echo "\n\033[1;32m=== Migration Finished Successfully! ===\033[0m\n";
