<?php
declare(strict_types=1);

require_once __DIR__ . '/config/config.php';

use Atte\DB\MsaDB;

$MsaDB = MsaDB::getInstance();

$queries = [
    // Add index on commission_id for inventory tables if they don't exist
    "ALTER TABLE `inventory__parts` ADD INDEX IF NOT EXISTS `idx_commission_id` (`commission_id`);",
    "ALTER TABLE `inventory__sku` ADD INDEX IF NOT EXISTS `idx_commission_id` (`commission_id`);",
    "ALTER TABLE `inventory__smd` ADD INDEX IF NOT EXISTS `idx_commission_id` (`commission_id`);",
    "ALTER TABLE `inventory__tht` ADD INDEX IF NOT EXISTS `idx_commission_id` (`commission_id`);",
    
    // Add index on common lookup columns for dictionary tables
    "ALTER TABLE `list__parts` ADD INDEX IF NOT EXISTS `idx_is_active` (`isActive`);",
    "ALTER TABLE `list__sku` ADD INDEX IF NOT EXISTS `idx_is_active` (`isActive`);",
    "ALTER TABLE `list__smd` ADD INDEX IF NOT EXISTS `idx_is_active` (`isActive`);",
    "ALTER TABLE `list__tht` ADD INDEX IF NOT EXISTS `idx_is_active` (`isActive`);",
    
    // Add index on bom_id for commission_list
    "ALTER TABLE `commission__list` ADD INDEX IF NOT EXISTS `idx_bom_id` (`bom_id`);",
    "ALTER TABLE `commission__list` ADD INDEX IF NOT EXISTS `idx_state_cancelled` (`state`, `is_cancelled`);"
];

echo "Starting database migration...\n";

foreach ($queries as $query) {
    try {
        echo "Executing: $query\n";
        $MsaDB->query($query);
        echo "Success.\n";
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
        // MariaDB < 10.5.2 doesn't support IF NOT EXISTS for ADD INDEX
        if (strpos($e->getMessage(), "Duplicate key name") !== false) {
            echo "Index already exists, skipping.\n";
        }
    }
}

echo "Migration finished.\n";
