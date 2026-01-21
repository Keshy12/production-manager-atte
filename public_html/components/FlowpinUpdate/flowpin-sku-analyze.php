<?php

use Atte\DB\MsaDB;
use Atte\DB\FlowpinDB;
use Atte\Utils\UserRepository;
use Atte\Utils\BomRepository;

header('Content-Type: application/json');
set_time_limit(300); // 5 minutes max for analysis

try {
    $MsaDB = MsaDB::getInstance();
    $FlowpinDB = FlowpinDB::getInstance();
    $userRepository = new UserRepository($MsaDB);
    $bomRepository = new BomRepository($MsaDB);

    // Track issues by category
    $issues = [
        'users' => [],      // User-related issues
        'devices' => [],    // Device/SKU-related issues
        'warehouses' => [], // Warehouse-related issues
    ];

    $totalRecords = 0;
    $issueCount = 0;

    // Get checkpoint for each operation type
    function getCheckpoint($MsaDB, $operationType) {
        $result = $MsaDB->query("SELECT checkpoint_event_id FROM `ref__flowpin_checkpoints` WHERE operation_type = '$operationType'");
        return isset($result[0]) ? $result[0]["checkpoint_event_id"] : 0;
    }

    // Validate user exists and has required properties
    function validateUser($userEmail, $userRepository, $MsaDB, &$issues) {
        try {
            $user = $userRepository->getUserByEmail($userEmail);

            if (!$user->userId) {
                if (!isset($issues['users'][$userEmail])) {
                    $issues['users'][$userEmail] = [
                        'email' => $userEmail,
                        'reason' => 'User not found in system',
                        'count' => 0
                    ];
                }
                $issues['users'][$userEmail]['count']++;
                return false;
            }

            if (empty($user->subMagazineId)) {
                if (!isset($issues['users'][$userEmail])) {
                    $issues['users'][$userEmail] = [
                        'email' => $userEmail,
                        'reason' => 'User has no warehouse assigned',
                        'count' => 0
                    ];
                }
                $issues['users'][$userEmail]['count']++;
                return false;
            }

            if (!$user->isActive) {
                if (!isset($issues['users'][$userEmail])) {
                    $issues['users'][$userEmail] = [
                        'email' => $userEmail,
                        'reason' => 'User account is disabled',
                        'count' => 0
                    ];
                }
                $issues['users'][$userEmail]['count']++;
                return false;
            }

            // Check if warehouse is active
            $warehouseResult = $MsaDB->query(
                "SELECT isActive FROM magazine__list WHERE sub_magazine_id = " . (int)$user->subMagazineId
            );

            if (!empty($warehouseResult) && $warehouseResult[0]['isActive'] == 0) {
                if (!isset($issues['warehouses'][$user->subMagazineId])) {
                    $issues['warehouses'][$user->subMagazineId] = [
                        'magazine_id' => $user->subMagazineId,
                        'reason' => 'Warehouse is disabled',
                        'count' => 0
                    ];
                }
                $issues['warehouses'][$user->subMagazineId]['count']++;
                return false;
            }

            return true;

        } catch (\Exception $e) {
            if (!isset($issues['users'][$userEmail])) {
                $issues['users'][$userEmail] = [
                    'email' => $userEmail,
                    'reason' => 'Error: ' . $e->getMessage(),
                    'count' => 0
                ];
            }
            $issues['users'][$userEmail]['count']++;
            return false;
        }
    }

    // Validate SKU exists and has valid BOM
    function validateSku($deviceId, $MsaDB, $FlowpinDB, $bomRepository, &$issues) {
        // Check if SKU exists in MSA
        $MsaId = $MsaDB->query("SELECT id FROM list__sku WHERE id = " . (int)$deviceId, PDO::FETCH_COLUMN);

        if (empty($MsaId)) {
            // Check if exists in FlowPin
            $flowpinSku = $FlowpinDB->query("SELECT Symbol FROM ProductTypes WHERE Id = " . (int)$deviceId);

            if (!empty($flowpinSku)) {
                // SKU exists in FlowPin but not in MSA
                if (!isset($issues['devices'][$deviceId])) {
                    $issues['devices'][$deviceId] = [
                        'sku_id' => $deviceId,
                        'sku_name' => $flowpinSku[0]['Symbol'],
                        'reason' => 'SKU not found in MSA (will be auto-created)',
                        'count' => 0
                    ];
                }
                $issues['devices'][$deviceId]['count']++;
                return true; // Can be auto-created
            } else {
                // SKU doesn't exist in either database
                if (!isset($issues['devices'][$deviceId])) {
                    $issues['devices'][$deviceId] = [
                        'sku_id' => $deviceId,
                        'sku_name' => 'Unknown',
                        'reason' => 'SKU not found in FlowPin or MSA',
                        'count' => 0
                    ];
                }
                $issues['devices'][$deviceId]['count']++;
                return false;
            }
        }

        // Check if SKU has valid BOM (for production operations)
        try {
            $bomValues = [
                "sku_id" => $deviceId,
                "version" => null
            ];
            $bomsFound = $bomRepository->getBomByValues("sku", $bomValues);

            if (count($bomsFound) === 0) {
                if (!isset($issues['devices'][$deviceId])) {
                    $skuName = $MsaDB->query("SELECT name FROM list__sku WHERE id = " . (int)$deviceId, PDO::FETCH_COLUMN);
                    $issues['devices'][$deviceId] = [
                        'sku_id' => $deviceId,
                        'sku_name' => $skuName[0] ?? 'Unknown',
                        'reason' => 'No active BOM found for SKU',
                        'count' => 0
                    ];
                }
                $issues['devices'][$deviceId]['count']++;
            } else if (count($bomsFound) > 1) {
                if (!isset($issues['devices'][$deviceId])) {
                    $skuName = $MsaDB->query("SELECT name FROM list__sku WHERE id = " . (int)$deviceId, PDO::FETCH_COLUMN);
                    $issues['devices'][$deviceId] = [
                        'sku_id' => $deviceId,
                        'sku_name' => $skuName[0] ?? 'Unknown',
                        'reason' => 'Multiple active BOMs found for SKU',
                        'count' => 0
                    ];
                }
                $issues['devices'][$deviceId]['count']++;
            }
        } catch (\Exception $e) {
            // BOM check failed, but don't count as error for non-production operations
        }

        return true;
    }

    // Query functions (same as update script)
    $getSoldSku = function () use ($FlowpinDB, $MsaDB) {
        $checkpoint = getCheckpoint($MsaDB, 'sold_sku');
        $query = "SELECT EventId, ExecutionDate, ByUserEmail, ProductTypeId,
            CASE WHEN EventTypeValue = 'Modified'
            AND FieldOldValue = 'InOrder'
            AND FieldNewValue = 'ContractorHasIt'
            THEN -1
            WHEN EventTypeValue = 'Modified'
            AND FieldOldValue = 'ContractorHasIt'
            AND FieldNewValue = 'InOrder'
            THEN 1 END AS SaleQty
            FROM [report].[ProductQuantityHistoryView]
            WHERE EventTypeValue = 'Modified' AND (EventId > '$checkpoint') AND IsInter = 0
            AND ((FieldOldValue = 'ContractorHasIt' AND FieldNewValue = 'InOrder')
            OR (FieldOldValue = 'InOrder' AND FieldNewValue = 'ContractorHasIt')) ORDER BY EventId ASC";
        return $FlowpinDB->query($query);
    };

    $getReturnedSku = function () use ($FlowpinDB, $MsaDB) {
        $checkpoint = getCheckpoint($MsaDB, 'returned_sku');
        $query = "SELECT EventId, ExecutionDate, ByUserEmail, ProductTypeId,
            CASE WHEN ProductTypeId IS NOT NULL
            AND FieldOldValue = 4
            THEN -1
            WHEN ProductTypeId IS NOT NULL
            AND FieldNewValue = 4
            THEN 1 END AS ReturnQty
            FROM [report].[ProductQuantityHistoryView]
            WHERE (EventId > '$checkpoint')
            AND EventTypeValue = 'ProductReturn'
            AND FieldName = 'WarehouseId' AND IsInter = 0 AND (FieldNewValue = 4 OR FieldOldValue = 4)
            AND FieldOldValue != FieldNewValue
            AND FieldNewValue != 81
            ORDER BY EventId ASC";
        return $FlowpinDB->query($query);
    };

    $getMovedSku = function () use ($FlowpinDB, $MsaDB) {
        $checkpoint = getCheckpoint($MsaDB, 'moved_sku');
        $query = "SELECT [EventId], [ExecutionDate] ,[ByUserEmail] ,[ProductTypeId] ,[FieldOldValue] AS WarehouseOut ,
            CASE WHEN EventTypeValue = 'WarehouseChange'
            AND FieldName = 'WarehouseId'
            AND State = 1
            AND (FieldOldValue = 3 OR FieldOldValue = 4)
            THEN -1 END AS QtyOut ,[FieldNewValue] AS WarehouseIn ,
            CASE WHEN EventTypeValue = 'WarehouseChange'
            AND FieldName = 'WarehouseId'
            AND State = 1
            AND (FieldNewValue = 3 OR FieldNewValue = 4)
            THEN 1 END AS QtyIn
            FROM [report].[ProductQuantityHistoryView]
            WHERE (EventId > '$checkpoint')
            AND EventTypeValue = 'WarehouseChange'
            AND FieldName = 'WarehouseId'
            AND State = 1
            AND ((FieldNewValue = 3 OR FieldNewValue = 4) OR (FieldOldValue = 3 OR FieldOldValue = 4))
            AND ParentId IS NULL ORDER BY EventId ASC";
        return $FlowpinDB->query($query);
    };

    $getProducedSkuAndInter = function () use ($FlowpinDB, $MsaDB) {
        $checkpoint = getCheckpoint($MsaDB, 'production_sku');
        $query = "SELECT EventId, ExecutionDate, ByUserEmail, ProductTypeId, ProductionQty
            FROM [report].[ProductQuantityHistoryView]
            WHERE (EventId > '$checkpoint')
            AND (ProductionQty = 1 OR ProductionQty = -1)
            AND (WarehouseId = '3' OR WarehouseId = '4')
            ORDER BY EventId ASC";
        return $FlowpinDB->query($query);
    };

    // Analyze each operation type
    $operations = [
        'Production' => $getProducedSkuAndInter(),
        'Sold' => $getSoldSku(),
        'Returned' => $getReturnedSku(),
        'Moved' => $getMovedSku()
    ];

    foreach ($operations as $operationType => $records) {
        foreach ($records as $row) {
            $totalRecords++;

            // Extract common fields using associative array keys (FlowPin returns associative arrays)
            $eventId = $row['EventId'] ?? null;
            $executionDate = $row['ExecutionDate'] ?? null;
            $userEmail = $row['ByUserEmail'] ?? null;
            $deviceId = $row['ProductTypeId'] ?? null;

            // Skip if essential fields are missing
            if (!$userEmail || !$deviceId) {
                continue;
            }

            // Validate user
            $userValid = validateUser($userEmail, $userRepository, $MsaDB, $issues);
            if (!$userValid) {
                $issueCount++;
            }

            // Validate SKU
            $skuValid = validateSku($deviceId, $MsaDB, $FlowpinDB, $bomRepository, $issues);
            if (!$skuValid) {
                $issueCount++;
            }
        }
    }

    // Convert associative arrays to indexed arrays for JSON response
    $response = [
        'success' => true,
        'total_records' => $totalRecords,
        'total_issues' => $issueCount,
        'issues' => [
            'users' => array_values($issues['users']),
            'devices' => array_values($issues['devices']),
            'warehouses' => array_values($issues['warehouses'])
        ],
        'summary' => [
            'user_issues' => count($issues['users']),
            'device_issues' => count($issues['devices']),
            'warehouse_issues' => count($issues['warehouses'])
        ]
    ];

    echo json_encode($response, JSON_PRETTY_PRINT);

} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ], JSON_PRETTY_PRINT);
}
