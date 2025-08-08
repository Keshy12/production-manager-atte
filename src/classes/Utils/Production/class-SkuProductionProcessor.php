<?php

namespace Atte\Utils\Production;

use Atte\DB\MsaDB;
use Atte\DB\FlowpinDB;
use Atte\Utils\BomRepository;
use Atte\Utils\NotificationRepository;
use Atte\Utils\UserRepository;
use Exception;
use PDO;

class SkuProductionProcessor {
    private $MsaDB;
    private $FlowpinDB;
    private $notificationRepository;
    private $userRepository;
    private $flowpinQueryTypeId = 1;

    public function __construct(MsaDB $MsaDB, FlowpinDB $FlowpinDB) {
        $this->MsaDB = $MsaDB;
        $this->FlowpinDB = $FlowpinDB;
        $this->notificationRepository = new NotificationRepository($MsaDB);
        $this->userRepository = new UserRepository($MsaDB);
    }

    /**
     * Process production data and return SQL queries
     *
     * @param array $production Production data from FlowPin
     * @param string|null $productionDate Optional production date
     * @return array Array of queries indexed by event ID
     */
    public function processProduction(array $production, $productionDate = null) {
        $isResolving = is_array($production[0][1] ?? null);
        $result = [];

        foreach ($production as $row) {
            $queries = [];

            if ($isResolving) {
                $idToDel = $row[0];
                list($eventId, $executionTimestamp, $userEmail, $deviceId, $quantity) = $row[1];
            } else {
                list($eventId, $executionTimestamp, $userEmail, $deviceId, $quantity) = $row;
                $idToDel = $eventId;
            }

            // Validate deviceId before using it in SQL query
            if (empty($deviceId) || !is_numeric($deviceId)) {
                $exception = new Exception("Invalid or empty deviceId: " . var_export($deviceId, true), 2);
                if ($isResolving) {
                    $this->notificationRepository->createNotificationFromException($exception, $row[1], $this->flowpinQueryTypeId);
                } else {
                    $this->notificationRepository->createNotificationFromException($exception, $row, $this->flowpinQueryTypeId);
                }
                $result[$idToDel] = ["SELECT 1"];
                continue;
            }

            // Check if SKU exists and insert if not
            $MsaId = $this->MsaDB->query("SELECT id FROM list__sku WHERE id = " . (int)$deviceId, PDO::FETCH_COLUMN);
            if (empty($MsaId)) {
                try {
                    $newSKU = $this->FlowpinDB->query("SELECT Symbol, Description FROM ProductTypes WHERE Id = " . (int)$deviceId);
                    if (empty($newSKU)) {
                        throw new Exception("SKU with ID $deviceId not found in FlowPin database", 3);
                    }
                    $newSKU = $newSKU[0];
                    $this->MsaDB->insert("list__sku", ["id", "name", "description", "isActive"], [$deviceId, $newSKU["Symbol"], $newSKU["Description"], 1]);
                } catch (\Throwable $exception) {
                    if ($isResolving) {
                        $this->notificationRepository->createNotificationFromException($exception, $row[1], $this->flowpinQueryTypeId);
                    } else {
                        $this->notificationRepository->createNotificationFromException($exception, $row, $this->flowpinQueryTypeId);
                    }
                    $result[$idToDel] = ["SELECT 1"];
                    continue;
                }
            }

            try {
                $user = $this->userRepository->getUserByEmail($userEmail);
                $userId = $user->userId;
            } catch (\Throwable $exception) {
                if ($isResolving) {
                    $this->notificationRepository->createNotificationFromException($exception, $row[1], $this->flowpinQueryTypeId);
                } else {
                    $this->notificationRepository->createNotificationFromException($exception, $row, $this->flowpinQueryTypeId);
                }
                $result[$idToDel] = ["SELECT 1"];
                continue;
            }

            $comment = "Automatyczna produkcja z FlowPin, EventId:" . $eventId;
            $userinfo = $user->getUserInfo();
            $sub_magazine_id = $userinfo["sub_magazine_id"];
            $version = null;
            $executionTimestamp = "'" . $executionTimestamp . "'";
            $formattedProductionDate = !empty($productionDate) ? "'" . $productionDate . "'" : 'null';
            $type = "sku";
            $bomRepository = new BomRepository($this->MsaDB);

            try {
                $bomValues = [
                    "sku_id" => $deviceId,
                    "version" => $version
                ];
                $bomsFound = $bomRepository->getBomByValues($type, $bomValues);
                if (count($bomsFound) !== 1) {
                    throw new Exception("Can't get bom ID with provided values: type:$type, id:$deviceId, version:$version", 1);
                }
                $bom = $bomsFound[0];
                $bomId = $bom->id;
            } catch (\Throwable $exception) {
                if ($isResolving) {
                    $this->notificationRepository->createNotificationFromException($exception, $row[1], $this->flowpinQueryTypeId);
                } else {
                    $this->notificationRepository->createNotificationFromException($exception, $row, $this->flowpinQueryTypeId);
                }
                $result[$idToDel] = ["SELECT 1"];
                continue;
            }

            // Get components for 1 device, so we can just multiply it by quantity needed
            $bomComponents = $bom->getComponents(1);

            $components = array();
            foreach ($bomComponents as $component) {
                $component_type = $component['type'];
                $component_id = $component['componentId'];
                $component_quantity = $component['quantity'];
                $components[] = ["type" => $component_type, "component_id" => $component_id, "quantity" => $component_quantity];
            }

            /**
             * Filters all commissions to only get relevant to production
             * type == 1             - commissions for SKU
             * bom_tht_id == bomId   - commissions for produced SKU
             * state_id == 1         - commissions that still need production
             */
            $getRelevant = function ($var) use ($bomId, $type) {
                return ($var->deviceType == $type
                    && $var->commissionValues['bom_sku_id'] == $bomId
                    && $var->commissionValues['state_id'] == 1);
            };

            $commissions = $user->getActiveCommissions();
            $commissions = array_filter($commissions, $getRelevant);

            foreach ($commissions as $commission) {
                if ($quantity == 0) {
                    break;
                }
                $row = $commission['row'];
                $commission_id = $row["id"];
                $quantity_needed = $row["quantity"] - $row["quantity_produced"];
                $quantity -= $quantity_needed;
                $state_id = 2;
                if ($quantity < 0) {
                    $state_id = 1;
                    $quantity_needed += $quantity;
                    $quantity = 0;
                }
                foreach ($components as $component) {
                    $type = $component["type"];
                    $component_id = $component["component_id"];
                    $component_quantity = ($component["quantity"] * $quantity_needed) * -1;
                    $queries[] = "INSERT INTO `inventory__" . $type . "` (`" . $type . "_id`, `commission_id`, `user_id`, `sub_magazine_id`, `quantity`, `timestamp`, `input_type_id`, `comment`) VALUES ('$component_id', '$commission_id', '$userId', '$sub_magazine_id', '$component_quantity', $executionTimestamp, '6', 'Zejście z magazynu do produkcji')";
                }
                $quantity_produced = $row["quantity_produced"] + $quantity_needed;
                $queries[] = "INSERT INTO `inventory__sku` (`sku_id`, `commission_id`, `user_id`, `sub_magazine_id`, `quantity`, `timestamp`, `input_type_id`, `comment`, `production_date`) VALUES ('$deviceId', '$commission_id', '$userId', '$sub_magazine_id', '$quantity_needed', $executionTimestamp, '4', '$comment', $formattedProductionDate)";
                $queries[] = "UPDATE `commission__list` SET `quantity_produced` = '$quantity_produced', state_id = '$state_id' WHERE `commission__list`.`id` = $commission_id";
            }

            if ($quantity != 0) {
                foreach ($components as $component) {
                    $type = $component["type"];
                    $component_id = $component["component_id"];
                    $component_quantity = ($component["quantity"] * $quantity) * -1;
                    $queries[] = "INSERT INTO `inventory__" . $type . "` (`" . $type . "_id`, `user_id`, `sub_magazine_id`, `quantity`, `timestamp`, `input_type_id`, `comment`) VALUES ('$component_id', '$userId', '$sub_magazine_id', '$component_quantity', $executionTimestamp, '6', 'Zejście z magazynu do produkcji')";
                }
                $queries[] = "INSERT INTO `inventory__sku` (`sku_id`, `user_id`, `sub_magazine_id`, `quantity`, `timestamp`, `input_type_id`, `comment`, `production_date`) VALUES ('$deviceId', '$userId', '$sub_magazine_id', '$quantity', $executionTimestamp, '4', '$comment', $formattedProductionDate)";
            }

            $result[$idToDel] = $queries;
        }

        return $result;
    }

    /**
     * Process production data and execute queries directly with better error handling
     * Can handle single row or multiple rows with detailed results for each
     *
     * @param array $production Production data from FlowPin (single row or array of rows)
     * @param string|null $productionDate Optional production date
     * @return array Array containing overall results and individual row results
     *               Format: [
     *                  'overall' => ['success' => bool, 'processedCount' => int, 'errorCount' => int, 'highestEventId' => int],
     *                  'results' => [eventId => ['success' => bool, 'eventId' => int, 'errorType' => string|null, 'errorMessage' => string|null, 'exception' => Exception|null]],
     *                  'errorSummary' => ['DATABASE_ERROR' => int, 'PROCESSING_ERROR' => int, 'NO_DATA' => int, 'BOM_ERROR' => int, 'USER_ERROR' => int]
     *               ]
     */
    public function processAndExecuteProduction(array $production, $productionDate = null) {
        if (empty($production)) {
            return [
                'overall' => [
                    'success' => false,
                    'processedCount' => 0,
                    'errorCount' => 1,
                    'highestEventId' => 0
                ],
                'results' => [],
                'errorSummary' => ['NO_DATA' => 1, 'DATABASE_ERROR' => 0, 'PROCESSING_ERROR' => 0, 'BOM_ERROR' => 0, 'USER_ERROR' => 0]
            ];
        }

        $queries = $this->processProduction($production, $productionDate);
        $results = [];
        $errorSummary = ['DATABASE_ERROR' => 0, 'PROCESSING_ERROR' => 0, 'NO_DATA' => 0, 'BOM_ERROR' => 0, 'USER_ERROR' => 0];
        $processedCount = 0;
        $errorCount = 0;
        $highestEventId = 0;

        foreach ($queries as $eventId => $queryList) {
            $result = [
                'success' => false,
                'eventId' => (int)$eventId,
                'errorType' => null,
                'errorMessage' => null,
                'exception' => null,
                'queriesExecuted' => 0,
                'totalQueries' => count($queryList)
            ];

            if ($queryList[0] === "SELECT 1") {
                // This indicates a processing error occurred during processProduction()
                // We need to analyze the original row to determine what type of error it was
                $originalRow = null;
                foreach ($production as $row) {
                    if ($row[0] == $eventId) { // EventId is first element
                        $originalRow = $row;
                        break;
                    }
                }

                // Try to categorize the processing error by re-attempting the problematic steps
                $categorizedError = $this->categorizeProcessingError($originalRow);
                $result['errorType'] = $categorizedError['errorType'];
                $result['errorMessage'] = $categorizedError['errorMessage'];
                $result['exception'] = $categorizedError['exception'];
                $errorSummary[$categorizedError['errorType']]++;
                $errorCount++;
            } else {
                $eventSuccess = true;
                $queriesExecuted = 0;
                $queryErrorMessage = null;
                $queryException = null;

                foreach ($queryList as $queryIndex => $query) {
                    try {
                        $this->MsaDB->query($query);
                        $queriesExecuted++;

                    } catch (\Exception $e) {
                        $eventSuccess = false;
                        $queryErrorMessage = "Database error on query " . ($queryIndex + 1) . "/" . count($queryList) . ": " . $e->getMessage();
                        $queryException = $e;
                        break;
                    }
                }

                $result['queriesExecuted'] = $queriesExecuted;

                if ($eventSuccess) {
                    $result['success'] = true;
                    $processedCount++;
                    $highestEventId = max($highestEventId, (int)$eventId);
                } else {
                    $result['errorType'] = 'DATABASE_ERROR';
                    $result['errorMessage'] = $queryErrorMessage;
                    $result['exception'] = $queryException;
                    $errorSummary['DATABASE_ERROR']++;
                    $errorCount++;
                }
            }

            $results[$eventId] = $result;
        }

        // Handle case where no queries were generated (empty production data)
        if (empty($queries)) {
            $errorSummary['NO_DATA']++;
            $errorCount++;
        }

        $overallSuccess = $processedCount > 0 && $errorCount === 0;

        return [
            'overall' => [
                'success' => $overallSuccess,
                'processedCount' => $processedCount,
                'errorCount' => $errorCount,
                'highestEventId' => $highestEventId,
                'totalRows' => count($production)
            ],
            'results' => $results,
            'errorSummary' => $errorSummary
        ];
    }

    /**
     * Categorize processing errors to determine proper action_needed_id
     * This mimics the logic from processProduction to identify the specific failure point
     *
     * @param array $row Original production row data
     * @return array Array with errorType, errorMessage, and exception
     */
    private function categorizeProcessingError($row) {
        if (empty($row) || count($row) < 5) {
            return [
                'errorType' => 'PROCESSING_ERROR',
                'errorMessage' => 'Invalid or empty production row data',
                'exception' => new \Exception("Invalid or empty production row data", 0)
            ];
        }

        list($eventId, $executionTimestamp, $userEmail, $deviceId, $quantity) = $row;

        // Test 1: Check deviceId validity (BOM-related error)
        if (empty($deviceId) || !is_numeric($deviceId)) {
            return [
                'errorType' => 'BOM_ERROR',
                'errorMessage' => "Invalid or empty deviceId: " . var_export($deviceId, true),
                'exception' => new \Exception("Invalid or empty deviceId: " . var_export($deviceId, true), 1) // Code 1 = BOM error
            ];
        }

        // Test 2: Check if SKU exists in MSA database
        try {
            $MsaId = $this->MsaDB->query("SELECT id FROM list__sku WHERE id = " . (int)$deviceId, \PDO::FETCH_COLUMN);
            if (empty($MsaId)) {
                // Try to get from FlowPin to see if it exists there
                try {
                    $newSKU = $this->FlowpinDB->query("SELECT Symbol, Description FROM ProductTypes WHERE Id = " . (int)$deviceId);
                    if (empty($newSKU)) {
                        return [
                            'errorType' => 'BOM_ERROR',
                            'errorMessage' => "SKU with ID $deviceId not found in FlowPin database",
                            'exception' => new \Exception("SKU with ID $deviceId not found in FlowPin database", 1) // Code 1 = BOM error
                        ];
                    }
                    // SKU exists in FlowPin but not in MSA - this is a synchronization issue (BOM-related)
                    return [
                        'errorType' => 'BOM_ERROR',
                        'errorMessage' => "SKU with ID $deviceId exists in FlowPin but not in MSA database",
                        'exception' => new \Exception("SKU with ID $deviceId exists in FlowPin but not in MSA database", 1) // Code 1 = BOM error
                    ];
                } catch (\Throwable $e) {
                    return [
                        'errorType' => 'BOM_ERROR',
                        'errorMessage' => "Error checking FlowPin for SKU $deviceId: " . $e->getMessage(),
                        'exception' => new \Exception("Error checking FlowPin for SKU $deviceId: " . $e->getMessage(), 1) // Code 1 = BOM error
                    ];
                }
            }
        } catch (\Throwable $e) {
            return [
                'errorType' => 'DATABASE_ERROR',
                'errorMessage' => "Database error checking SKU existence: " . $e->getMessage(),
                'exception' => $e
            ];
        }

        // Test 3: Check user validity (User-related error)
        try {
            $user = $this->userRepository->getUserByEmail($userEmail);
            if (!$user || !$user->userId) {
                return [
                    'errorType' => 'USER_ERROR',
                    'errorMessage' => "User with email '$userEmail' not found or invalid",
                    'exception' => new \Exception("User with email '$userEmail' not found or invalid", 2) // Code 2 = User error
                ];
            }
        } catch (\Throwable $e) {
            return [
                'errorType' => 'USER_ERROR',
                'errorMessage' => "Error retrieving user with email '$userEmail': " . $e->getMessage(),
                'exception' => new \Exception("Error retrieving user with email '$userEmail': " . $e->getMessage(), 2) // Code 2 = User error
            ];
        }

        // Test 4: Check BOM validity
        try {
            $bomRepository = new BomRepository($this->MsaDB);
            $bomValues = [
                "sku_id" => $deviceId,
                "version" => null
            ];
            $bomsFound = $bomRepository->getBomByValues("sku", $bomValues);
            if (count($bomsFound) !== 1) {
                return [
                    'errorType' => 'BOM_ERROR',
                    'errorMessage' => "Cannot get BOM ID with provided values: type:sku, id:$deviceId, version:null. Found " . count($bomsFound) . " BOMs",
                    'exception' => new \Exception("Cannot get BOM ID with provided values: type:sku, id:$deviceId, version:null", 1) // Code 1 = BOM error
                ];
            }
        } catch (\Throwable $e) {
            return [
                'errorType' => 'BOM_ERROR',
                'errorMessage' => "Error retrieving BOM for SKU $deviceId: " . $e->getMessage(),
                'exception' => new \Exception("Error retrieving BOM for SKU $deviceId: " . $e->getMessage(), 1) // Code 1 = BOM error
            ];
        }

        // If we got here, the error is something else entirely - critical error
        return [
            'errorType' => 'PROCESSING_ERROR',
            'errorMessage' => "Unknown processing error for EventId $eventId",
            'exception' => new \Exception("Unknown processing error for EventId $eventId", 0) // Code 0 = Critical error
        ];
    }
}