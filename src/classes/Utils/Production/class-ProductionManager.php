<?php

namespace Atte\Utils;

use Atte\DB\MsaDB;
use Exception;

class ProductionManager {
    protected $MsaDB;
    protected $userRepository;
    protected $bomRepository;
    protected $transferGroupManager;

    public function __construct($MsaDB) {
        $this->MsaDB = $MsaDB;
        $this->userRepository = new UserRepository($MsaDB);
        $this->bomRepository = new BomRepository($MsaDB);
        $this->transferGroupManager = new TransferGroupManager($MsaDB);
    }

    /**
     * Processes production for SMD or THT with transfer group support
     * @return array [transferGroupId, negativeStockAlerts, commissionAlerts]
     */
    public function produce($userId, $deviceId, $version, $quantity, $comment, $productionDate, $deviceType, $laminateId = null): array
    {
        try {
            $user = $this->userRepository->getUserById($userId);
            $userInfo = $user->getUserInfo();
            $sub_magazine_id = $userInfo["sub_magazine_id"];

            if($userInfo["magazine_isActive"] == 0) {
                throw new \Exception("The magazine this user is on is not active.");
            }

            $bomValues = [$deviceType . "_id" => $deviceId, "version" => $version == 'n/d' ? null : $version];

            if ($deviceType === "smd") {
                if ($laminateId === null) {
                    throw new \Exception("Laminate ID is required for SMD production.");
                }
                $bomValues["laminate_id"] = $laminateId;
            }

            $bomsFound = $this->bomRepository->getBomByValues($deviceType, $bomValues);
            if (!is_null($bomsFound) && count($bomsFound) > 1) {
                throw new \Exception("Multiple BOM records found for the provided values.");
            }
            $bom = $bomsFound[0];
            $bomId = $bom->id;
            $bomComponents = $bom->getComponents(1);

            // Fetch device name for the transfer group
            $bom->getNameAndDescription();
            $deviceName = $bom->name;

            $inventoryTable = "inventory__" . $deviceType;
            $deviceField = $deviceType . "_id";
            $bomField = $deviceType . "_bom_id";

            if ($quantity < 0) {
                return $this->handleNegativeProduction($userId, $deviceId, $version, abs($quantity), $comment, $productionDate, $deviceType, $laminateId, $bom, $bomComponents);
            }

            // Handle auto-comment if empty
            if (empty($comment)) {
                $comment = "Produkcja {$deviceName} przez formularz " . strtoupper($deviceType);
            }

            // Create transfer group for this production
            $transferGroupId = $this->transferGroupManager->createTransferGroup($userId, 'production', [
                'comment' => $comment,
                'device_id' => $deviceId,
                'device_name' => $deviceName,
                'device_type' => $deviceType
            ]);



            $commissionAlerts = [];

            $getRelevant = function ($commission) use ($bomId, $deviceType) {
                return ($commission->deviceType === $deviceType &&
                    $commission->commissionValues['deviceBomId'] === $bomId &&
                    $commission->commissionValues['state'] === 'active');
            };

            $commissions = array_filter($user->getActiveCommissions(), $getRelevant);

            foreach ($commissions as $commission) {
                $row = $commission->commissionValues;
                if ($quantity === 0) break;

                $commission_id = $row["id"];
                $quantity_needed = $row["qty"] - $row["qty_produced"];
                $quantity -= $quantity_needed;
                $state = 'completed';

                if ($quantity < 0) {
                    $state = 'active';
                    $quantity_needed += $quantity;
                    $quantity = 0;
                }

                // Deduct components
                foreach ($bomComponents as $component) {
                    $type = $component["type"];
                    $component_id = $component["componentId"];
                    $component_quantity = ($component["quantity"] * $quantity_needed) * -1;

                    $this->MsaDB->insert(
                        "inventory__" . $type,
                        [$type . "_id", "commission_id", "sub_magazine_id", "qty", "input_type_id", "comment", "transfer_group_id"],
                        [$component_id, $commission_id, $sub_magazine_id, $component_quantity, '6', 'Zejście z magazynu do produkcji', $transferGroupId]
                    );
                }

                // Insert production record
                $qty_produced = $row["qty_produced"] + $quantity_needed;
                $this->MsaDB->insert(
                    $inventoryTable,
                    [$deviceField, $bomField, "commission_id", "sub_magazine_id", "qty", "input_type_id", "comment", "production_date", "transfer_group_id"],
                    [$deviceId, $bomId, $commission_id, $sub_magazine_id, $quantity_needed, '4', $comment, $productionDate, $transferGroupId]
                );

                $this->MsaDB->update(
                    "commission__list",
                    ["qty_produced" => $qty_produced, "state" => $state],
                    "id",
                    $commission_id
                );
            }

            // Handle remaining quantity
            if ($quantity != 0) {
                foreach ($bomComponents as $component) {
                    $type = $component["type"];
                    $component_id = $component["componentId"];
                    $component_quantity = ($component["quantity"] * $quantity) * -1;

                    $this->MsaDB->insert(
                        "inventory__" . $type,
                        [$type . "_id", "sub_magazine_id", "qty", "input_type_id", "comment", "transfer_group_id"],
                        [$component_id, $sub_magazine_id, $component_quantity, '6', 'Zejście z magazynu do produkcji', $transferGroupId]
                    );
                }

                $this->MsaDB->insert(
                    $inventoryTable,
                    [$deviceField, $bomField, "sub_magazine_id", "qty", "input_type_id", "comment", "production_date", "transfer_group_id"],
                    [$deviceId, $bomId, $sub_magazine_id, $quantity, '4', $comment, $productionDate, $transferGroupId]
                );
            }

            $bomComponentIds = $this->prepareComponents($bomComponents);
            $negativeStockAlerts = $this->checkLowStock($userId, $sub_magazine_id, $bomComponentIds);

            return [$transferGroupId, $negativeStockAlerts, []];
        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * Handle negative quantities (corrections/rollbacks)
     */
    private function handleNegativeProduction($userId, $deviceId, $version, $quantity, $comment, $productionDate, $deviceType, $laminateId, $bom, $bomComponents) {
        $user = $this->userRepository->getUserById($userId);
        $userInfo = $user->getUserInfo();
        $sub_magazine_id = $userInfo["sub_magazine_id"];

        $bomId = $bom->id;
        $inventoryTable = "inventory__" . $deviceType;
        $deviceField = $deviceType . "_id";
        $bomField = $deviceType . "_bom_id";

        // Fetch device name
        $bom->getNameAndDescription();
        $deviceName = $bom->name;

        // Handle auto-comment if empty
        if (empty($comment)) {
            $comment = "Korekta produkcji {$deviceName} przez formularz " . strtoupper($deviceType);
        }

        // Create transfer group for corrections
        $transferGroupId = $this->transferGroupManager->createTransferGroup($userId, 'production_correction', [
            'comment' => $comment,
            'device_id' => $deviceId,
            'device_name' => $deviceName,
            'device_type' => $deviceType
        ]);



        // Add components back
        foreach ($bomComponents as $component) {
            $type = $component["type"];
            $component_id = $component["componentId"];
            $component_quantity = $component["quantity"] * $quantity;

            $this->MsaDB->insert(
                "inventory__" . $type,
                [$type . "_id", "sub_magazine_id", "qty", "input_type_id", "comment", "transfer_group_id"],
                [$component_id, $sub_magazine_id, $component_quantity, '6', 'Powrót na magazyn - korekta', $transferGroupId]
            );
        }

        // Insert negative production record
        $this->MsaDB->insert(
            $inventoryTable,
            [$deviceField, $bomField, "sub_magazine_id", "qty", "input_type_id", "comment", "production_date", "transfer_group_id"],
            [$deviceId, $bomId, $sub_magazine_id, -$quantity, '4', $comment, $productionDate, $transferGroupId]
        );

        // Update commissions if applicable
        $getRelevant = function ($commission) use ($bomId, $deviceType) {
            return ($commission->deviceType === $deviceType &&
                $commission->commissionValues['deviceBomId'] === $bomId &&
                in_array($commission->commissionValues['state'], ['active', 'completed']));
        };

        $commissions = array_filter($user->getActiveCommissions(), $getRelevant);
        $remainingToDeduct = $quantity;

        foreach ($commissions as $commission) {
            if ($remainingToDeduct <= 0) break;

            $row = $commission->commissionValues;
            $commissionId = $row["id"];
            $currentProduced = $row["qty_produced"];
            $totalQuantity = $row["qty"];
            $quantityReturned = $row["quantity_returned"] ?? 0;

            $deductAmount = min($remainingToDeduct, $currentProduced);
            if ($deductAmount > 0) {
                $newQuantityProduced = $currentProduced - $deductAmount;
                $remainingToDeduct -= $deductAmount;

                $newState = 'active';
                if ($newQuantityProduced >= $totalQuantity) {
                    $newState = 'completed';
                }
                if ($quantityReturned >= $totalQuantity && $newQuantityProduced >= $totalQuantity) {
                    $newState = 'returned';
                }

                $this->MsaDB->update(
                    "commission__list",
                    ["qty_produced" => $newQuantityProduced, "state" => $newState],
                    "id",
                    $commissionId
                );
            }
        }

        $bomComponentIds = $this->prepareComponents($bomComponents);
        $negativeStockAlerts = $this->checkLowStock($userId, $sub_magazine_id, $bomComponentIds);

        return [$transferGroupId, $negativeStockAlerts, []];
    }

    private function checkLowStock($userId, $sub_magazine_id, $bomComponents): array {
        $list__sku = $this->MsaDB->readIdName("list__sku");
        $list__tht = $this->MsaDB->readIdName("list__tht");
        $list__smd = $this->MsaDB->readIdName("list__smd");
        $list__parts = $this->MsaDB->readIdName("list__parts");

        $nameMap = [
            "sku" => $list__sku,
            "tht" => $list__tht,
            "smd" => $list__smd,
            "parts" => $list__parts
        ];

        $alerts = [];

        $deviceTypes = [
            "sku" => [
                "lowstockTable" => "lowstock__sku",
                "listTable" => "list__sku",
                "idColumn" => "sku_id"
            ],
            "tht" => [
                "lowstockTable" => "lowstock__tht",
                "listTable" => "list__tht",
                "idColumn" => "tht_id"
            ],
            "smd" => [
                "lowstockTable" => "lowstock__smd",
                "listTable" => "list__smd",
                "idColumn" => "smd_id"
            ],
            "parts" => [
                "lowstockTable" => "lowstock__parts",
                "listTable" => "list__parts",
                "idColumn" => "parts_id"
            ]
        ];

        foreach ($deviceTypes as $deviceType => $tables) {
            if (!isset($bomComponents[$deviceType]) || empty($bomComponents[$deviceType])) {
                continue;
            }

            $ids = implode(',', $bomComponents[$deviceType]);
            $lowstockTable = $tables["lowstockTable"];
            $listTable = $tables["listTable"];
            $idColumn = $tables["idColumn"];

            if (in_array($deviceType, ['smd', 'parts'])) {
                $sql = "SELECT {$idColumn} AS id, total_quantity, 0 AS isAutoProduced 
                    FROM {$lowstockTable} 
                    WHERE sub_magazine_id = {$sub_magazine_id} 
                      AND total_quantity < 0 
                      AND {$idColumn} IN ({$ids})";
            } else {
                $sql = "SELECT ls.{$idColumn} AS id, ls.total_quantity, l.isAutoProduced, l.autoProduceVersion
                    FROM {$lowstockTable} ls 
                    JOIN {$listTable} l ON l.id = ls.{$idColumn}
                    WHERE ls.sub_magazine_id = {$sub_magazine_id} 
                      AND ls.total_quantity < 0 
                      AND ls.{$idColumn} IN ({$ids})";
            }

            $items = $this->MsaDB->query($sql, \PDO::FETCH_ASSOC);

            foreach ($items as $item) {
                $identifier = $item['id'];
                $neededQuantity = abs($item["total_quantity"]);
                $deviceName = $nameMap[$deviceType][$identifier] ?? $identifier;

                if ($item["isAutoProduced"]) {
                    $version = $item["autoProduceVersion"];
                    $comment = "Automatyczna produkcja wygenerowana przez ujemne ilości magazynowe.";
                    $productionDate = "'" . date("Y-m-d") . "'";
                    $this->produce($userId, $identifier, $version, $neededQuantity, $comment, $productionDate, $deviceType);
                    $alerts[] = '<div class="alert alert-success" role="alert">Automatycznie wyprodukowano: <b>' . htmlspecialchars($deviceName) . ' w ilości ' . $neededQuantity . '</b></div>';
                } else {
                    $alerts[] = '<div class="alert alert-danger" role="alert">Ujemne wartości magazynowe dla: <b>' . htmlspecialchars($deviceName) . '</b></div>';
                }
            }
        }

        return $alerts;
    }

    private function prepareComponents($bomComponents) {
        $result = [
            'sku' => [],
            'tht' => [],
            'smd' => [],
            'parts' => [],
        ];

        foreach ($bomComponents as $component) {
            if (isset($component['type'], $component['componentId'])) {
                if (array_key_exists($component['type'], $result)) {
                    $result[$component['type']][] = $component['componentId'];
                }
            }
        }

        return $result;
    }
}