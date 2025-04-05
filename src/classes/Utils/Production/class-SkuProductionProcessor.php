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

            // Check if SKU exists and insert if not
            $MsaId = $this->MsaDB->query("SELECT id FROM list__sku WHERE id = $deviceId", PDO::FETCH_COLUMN);
            if (empty($MsaId)) {
                $newSKU = $this->FlowpinDB->query("SELECT Symbol, Description FROM ProductTypes WHERE Id = $deviceId")[0];
                $this->MsaDB->insert("list__sku", ["id", "name", "description", "isActive"], [$deviceId, $newSKU["Symbol"], $newSKU["Description"], 1]);
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
     *
     * @param array $production Production data from FlowPin
     * @param string|null $productionDate Optional production date
     * @return int The highest event ID processed successfully
     */
    public function processAndExecuteProduction(array $production, $productionDate = null) {
        $queries = $this->processProduction($production, $productionDate);
        $lastEventId = 0;
        $processedEvents = [];

        // First, organize queries by event ID and type
        $bulkOperations = [];

        foreach ($queries as $eventId => $queryList) {
            if ($queryList[0] === "SELECT 1") continue;

            foreach ($queryList as $query) {
                // For each event, organize queries by table and operation type
                if (preg_match('/INSERT INTO `([\w_]+)`/', $query, $tableMatch)) {
                    $table = $tableMatch[1];

                    // Extract column names
                    preg_match('/\((.*?)\) VALUES/', $query, $columnsMatch);
                    $columns = $columnsMatch[1];

                    // Extract values
                    preg_match('/VALUES \((.*)\)/', $query, $valuesMatch);
                    if (isset($valuesMatch[1])) {
                        if (!isset($bulkOperations[$table])) {
                            $bulkOperations[$table] = [
                                'columns' => $columns,
                                'values' => [],
                                'events' => []
                            ];
                        }
                        $bulkOperations[$table]['values'][] = $valuesMatch[1];
                        if (!in_array($eventId, $bulkOperations[$table]['events'])) {
                            $bulkOperations[$table]['events'][] = $eventId;
                        }
                    }
                }
                elseif (preg_match('/UPDATE `commission__list` SET (.*) WHERE `commission__list`\.`id` = (\d+)/', $query, $updateMatch)) {
                    $table = 'commission__list_updates';
                    $id = $updateMatch[2];
                    $updates = $updateMatch[1];

                    if (!isset($bulkOperations[$table])) {
                        $bulkOperations[$table] = [
                            'updates' => [],
                            'events' => []
                        ];
                    }

                    $bulkOperations[$table]['updates'][] = [
                        'id' => $id,
                        'data' => $updates
                    ];

                    if (!in_array($eventId, $bulkOperations[$table]['events'])) {
                        $bulkOperations[$table]['events'][] = $eventId;
                    }
                }
            }
        }

        // Now execute bulk operations with proper error handling
        foreach ($bulkOperations as $table => $data) {
            try {
                if ($table === 'commission__list_updates') {
                    // Handle commission list updates
                    $updateCases = [
                        'quantity_produced' => [],
                        'state_id' => []
                    ];

                    $ids = [];
                    foreach ($data['updates'] as $update) {
                        $id = $update['id'];
                        $ids[] = $id;

                        if (preg_match('/`quantity_produced` = \'(\d+)\'/', $update['data'], $qtyMatch)) {
                            $updateCases['quantity_produced'][] = "WHEN $id THEN {$qtyMatch[1]}";
                        }

                        if (preg_match('/state_id = \'(\d+)\'/', $update['data'], $stateMatch)) {
                            $updateCases['state_id'][] = "WHEN $id THEN {$stateMatch[1]}";
                        }
                    }

                    if (!empty($ids)) {
                        $sql = "UPDATE `commission__list` SET ";
                        $sets = [];

                        foreach ($updateCases as $column => $cases) {
                            if (!empty($cases)) {
                                $sets[] = "`$column` = CASE `id` " . implode(' ', $cases) . " ELSE `$column` END";
                            }
                        }

                        $sql .= implode(', ', $sets) . " WHERE `id` IN (" . implode(',', $ids) . ")";
                        $this->MsaDB->query($sql);

                        // Mark events as processed on success
                        foreach ($data['events'] as $eventId) {
                            $processedEvents[$eventId] = true;
                        }
                    }
                }
                else {
                    // Handle inserts
                    $chunks = array_chunk($data['values'], 100); // Split into chunks to avoid huge queries

                    foreach ($chunks as $chunk) {
                        $sql = "INSERT INTO `$table` ({$data['columns']}) VALUES (" . implode('), (', $chunk) . ")";
                        $this->MsaDB->query($sql);
                    }

                    // Mark events as processed on success
                    foreach ($data['events'] as $eventId) {
                        $processedEvents[$eventId] = true;
                    }
                }
            } catch (\Exception $e) {
                // Log error but continue with other operations
                error_log("Error executing bulk operation for table $table: " . $e->getMessage());
                // Don't mark these events as processed
            }
        }

        // Update lastEventId based on successfully processed events only
        foreach (array_keys($processedEvents) as $eventId) {
            $lastEventId = max($lastEventId, (int)$eventId);
        }

        return $lastEventId;
    }
}