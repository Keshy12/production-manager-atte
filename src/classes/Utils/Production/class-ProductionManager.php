<?php

namespace Atte\Utils;

use Atte\DB\MsaDB;
use Exception;

class ProductionManager {
    protected $MsaDB;
    protected $userRepository;
    protected $bomRepository;

    /**
     * Constructor
     *
     * @param MsaDB $MsaDB An instance of the MsaDB class.
     */
    public function __construct($MsaDB) {
        $this->MsaDB = $MsaDB;
        $this->userRepository = new UserRepository($MsaDB);
        $this->bomRepository = new BomRepository($MsaDB);
    }

    /**
     * Processes production for either SMD or THT.
     *
     * @param int    $userId         ID of the user performing production.
     * @param int    $deviceId       ID of the device.
     * @param string $version        Version of the BOM.
     * @param int    $quantity       Quantity to produce (can be negative for corrections).
     * @param string $comment        Comment to be saved with production.
     * @param mixed  $productionDate Production date (formatted as needed).
     * @param string $deviceType     'smd' or 'tht'.
     * @param mixed  $laminateId     (Optional) Required if $deviceType is 'smd'.
     *
     * @return array [firstInsertedId, lastInsertedId, negativeStockAlerts, commissionAlerts]
     * @throws Exception If errors occur during production.
     */
    public function produce($userId, $deviceId, $version, $quantity, $comment, $productionDate, $deviceType, $laminateId = null): array
    {
        try {
            // Retrieve user information
            $user = $this->userRepository->getUserById($userId);
            $userInfo = $user->getUserInfo();
            $sub_magazine_id = $userInfo["sub_magazine_id"];

            // Build BOM filter values. SMD requires an additional laminate_id.
            $bomValues = [ $deviceType . "_id" => $deviceId, "version" => $version == 'n/d' ? null : $version];

            if ($deviceType === "smd") {
                if ($laminateId === null) {
                    throw new \Exception("Laminate ID is required for SMD production.");
                }
                $bomValues["laminate_id"] = $laminateId;
            }

            // Retrieve BOM using the repository.
            $bomsFound = $this->bomRepository->getBomByValues($deviceType, $bomValues);
            if (!is_null($bomsFound) && count($bomsFound) > 1) {
                throw new \Exception("Multiple BOM records found for the provided values. Unable to proceed with the production.");
            }
            $bom = $bomsFound[0];
            $bomId = $bom->id;
            $bomComponents = $bom->getComponents(1);

            // Determine which inventory table and field names to use.
            $inventoryTable = "inventory__" . $deviceType;
            $deviceField    = $deviceType . "_id";
            $bomField       = $deviceType . "_bom_id";

            $firstInsertedId = null;
            $lastInsertedId = null;
            $allInsertedIds = []; // Track all inserted IDs for this production
            $commissionAlerts = []; // Track commission-related notifications

            // Handle negative quantities (corrections/rollbacks)
            if ($quantity < 0) {
                return $this->handleNegativeProduction($userId, $deviceId, $version, abs($quantity), $comment, $productionDate, $deviceType, $laminateId, $bom, $bomComponents);
            }

            // Filter commissions relevant to the production
            $getRelevant = function ($commission) use ($bomId, $deviceType) {
                return ($commission->deviceType === $deviceType &&
                    $commission->commissionValues['deviceBomId'] === $bomId &&
                    $commission->commissionValues['state_id'] === 1);
            };

            $commissions = array_filter($user->getActiveCommissions(), $getRelevant);

            // Process each commission until the production quantity is exhausted.
            foreach ($commissions as $commission) {
                $row = $commission->commissionValues;
                if ($quantity === 0) {
                    break;
                }
                $commission_id = $row["id"];
                $quantity_needed = $row["quantity"] - $row["quantity_produced"];
                $quantity -= $quantity_needed;
                $state_id = 2;
                if ($quantity < 0) {
                    $state_id = 1;
                    $quantity_needed += $quantity; // Adjust for over-allocation
                    $quantity = 0;
                }

                // Deduct each BOM component from inventory (components table names are prefixed with "inventory__")
                foreach ($bomComponents as $component) {
                    $type = $component["type"];
                    $component_id = $component["componentId"];
                    $component_quantity = ($component["quantity"] * $quantity_needed) * -1;
                    $this->MsaDB->insert(
                        "inventory__" . $type,
                        [$type . "_id", "commission_id", "user_id", "sub_magazine_id", "quantity", "input_type_id", "comment"],
                        [$component_id, $commission_id, $userId, $sub_magazine_id, $component_quantity, '6', 'Zejście z magazynu do produkcji']
                    );
                }

                // Insert production record for the commission
                $quantity_produced = $row["quantity_produced"] + $quantity_needed;
                $insertedId = $this->MsaDB->insert(
                    $inventoryTable,
                    [$deviceField, $bomField, "commission_id", "user_id", "sub_magazine_id", "quantity", "input_type_id", "comment", "production_date"],
                    [$deviceId, $bomId, $commission_id, $userId, $sub_magazine_id, $quantity_needed, '4', $comment, $productionDate]
                );

                $allInsertedIds[] = $insertedId;
                if (empty($firstInsertedId)) {
                    $firstInsertedId = $insertedId;
                }
                $lastInsertedId = $insertedId;

                $this->MsaDB->update(
                    "commission__list",
                    ["quantity_produced" => $quantity_produced, "state_id" => $state_id],
                    "id",
                    $commission_id
                );
            }

            // If there is any remaining quantity (not allocated to commissions)
            if ($quantity != 0) {
                foreach ($bomComponents as $component) {
                    $type = $component["type"];
                    $component_id = $component["componentId"];
                    $component_quantity = ($component["quantity"] * $quantity) * -1;
                    $this->MsaDB->insert(
                        "inventory__" . $type,
                        [$type . "_id", "user_id", "sub_magazine_id", "quantity", "input_type_id", "comment"],
                        [$component_id, $userId, $sub_magazine_id, $component_quantity, '6', 'Zejście z magazynu do produkcji']
                    );
                }
                $insertedId = $this->MsaDB->insert(
                    $inventoryTable,
                    [$deviceField, $bomField, "user_id", "sub_magazine_id", "quantity", "input_type_id", "comment", "production_date"],
                    [$deviceId, $bomId, $userId, $sub_magazine_id, $quantity, '4', $comment, $productionDate]
                );

                $allInsertedIds[] = $insertedId;
                if (empty($firstInsertedId)) {
                    $firstInsertedId = $insertedId;
                }
                $lastInsertedId = $insertedId;
            }

            $bomComponentIds = $this->prepareComponents($bomComponents);
            $negativeStockAlerts = $this->checkLowStock($userId, $sub_magazine_id, $bomComponentIds);

            return [$firstInsertedId, $lastInsertedId, $negativeStockAlerts, []];
        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * Handle negative production (corrections/adjustments)
     */
    private function handleNegativeProduction($userId, $deviceId, $version, $quantity, $comment, $productionDate, $deviceType, $laminateId, $bom, $bomComponents): array
    {
        $user = $this->userRepository->getUserById($userId);
        $userInfo = $user->getUserInfo();
        $sub_magazine_id = $userInfo["sub_magazine_id"];
        $bomId = $bom->id;

        // Determine which inventory table and field names to use.
        $inventoryTable = "inventory__" . $deviceType;
        $deviceField    = $deviceType . "_id";
        $bomField       = $deviceType . "_bom_id";

        $remainingQuantity = $quantity;
        $allInsertedIds = [];
        $commissionAlerts = [];

        // Filter commissions relevant to the negative production
        $getRelevant = function ($commission) use ($bomId, $deviceType) {
            return ($commission->deviceType === $deviceType &&
                $commission->commissionValues['deviceBomId'] === $bomId &&
                in_array($commission->commissionValues['state_id'], [1, 2])); // Active or completed
        };

        $commissions = array_filter($user->getActiveCommissions(), $getRelevant);

        // Sort by state (completed first, then active) to prioritize rolling back completed commissions
        usort($commissions, function($a, $b) {
            return $b->commissionValues['state_id'] <=> $a->commissionValues['state_id'];
        });

        // Process commissions for negative adjustment
        foreach ($commissions as $commission) {
            if ($remainingQuantity <= 0) break;

            $row = $commission->commissionValues;
            $commission_id = $row["id"];
            $quantityProduced = $row["quantity_produced"];
            $quantityReturned = $row["quantity_returned"];
            $totalQuantity = $row["quantity"];

            // Calculate how much can be subtracted from this commission
            $availableForSubtraction = $quantityProduced - $quantityReturned;

            if ($availableForSubtraction <= 0) continue;

            $subtractionAmount = min($remainingQuantity, $availableForSubtraction);
            $newQuantityProduced = $quantityProduced - $subtractionAmount;
            $remainingQuantity -= $subtractionAmount;

            // Add components back to inventory
            foreach ($bomComponents as $component) {
                $type = $component["type"];
                $component_id = $component["componentId"];
                $component_quantity = $component["quantity"] * $subtractionAmount; // Positive to add back
                $this->MsaDB->insert(
                    "inventory__" . $type,
                    [$type . "_id", "commission_id", "user_id", "sub_magazine_id", "quantity", "input_type_id", "comment"],
                    [$component_id, $commission_id, $userId, $sub_magazine_id, $component_quantity, '6', 'Powrót do magazynu z korekty produkcji']
                );
            }

            // Insert negative production record for the commission
            $insertedId = $this->MsaDB->insert(
                $inventoryTable,
                [$deviceField, $bomField, "commission_id", "user_id", "sub_magazine_id", "quantity", "input_type_id", "comment", "production_date"],
                [$deviceId, $bomId, $commission_id, $userId, $sub_magazine_id, -$subtractionAmount, '4', $comment, $productionDate]
            );

            $allInsertedIds[] = $insertedId;

            // Update commission
            $newStateId = 1; // Default to active
            if ($newQuantityProduced >= $totalQuantity && $quantityReturned < $totalQuantity) {
                $newStateId = 2; // Completed
            }
            if ($quantityReturned >= $totalQuantity && $newQuantityProduced >= $totalQuantity) {
                $newStateId = 3; // Returned
            }

            $this->MsaDB->update(
                "commission__list",
                ["quantity_produced" => $newQuantityProduced, "state_id" => $newStateId],
                "id",
                $commission_id
            );

            // Add commission notification
            $commissionAlerts[] = '<div class="alert alert-info" role="alert">Odjęto <b>' . $subtractionAmount . '</b> sztuk z zamówienia ID: <b>' . $commission_id . '</b></div>';
        }

        // Handle remaining quantity that couldn't be subtracted from commissions
        if ($remainingQuantity > 0) {
            // Add components back to inventory (not tied to any commission)
            foreach ($bomComponents as $component) {
                $type = $component["type"];
                $component_id = $component["componentId"];
                $component_quantity = $component["quantity"] * $remainingQuantity; // Positive to add back
                $this->MsaDB->insert(
                    "inventory__" . $type,
                    [$type . "_id", "user_id", "sub_magazine_id", "quantity", "input_type_id", "comment"],
                    [$component_id, $userId, $sub_magazine_id, $component_quantity, '6', 'Powrót do magazynu z korekty produkcji']
                );
            }

            // Insert negative production record (not tied to any commission)
            $insertedId = $this->MsaDB->insert(
                $inventoryTable,
                [$deviceField, $bomField, "user_id", "sub_magazine_id", "quantity", "input_type_id", "comment", "production_date"],
                [$deviceId, $bomId, $userId, $sub_magazine_id, -$remainingQuantity, '4', $comment, $productionDate]
            );

            $allInsertedIds[] = $insertedId;

            // Add notification about remainder
            $commissionAlerts[] = '<div class="alert alert-warning" role="alert">Ilość <b>' . $remainingQuantity . '</b> sztuk została odjęta jako korekta niezwiązana z zamówieniem</div>';
        }

        $firstInsertedId = !empty($allInsertedIds) ? min($allInsertedIds) : null;
        $lastInsertedId = !empty($allInsertedIds) ? max($allInsertedIds) : null;

        $bomComponentIds = $this->prepareComponents($bomComponents);
        $negativeStockAlerts = $this->checkLowStock($userId, $sub_magazine_id, $bomComponentIds);

        return [$firstInsertedId, $lastInsertedId, $negativeStockAlerts, $commissionAlerts];
    }

    /**
     * Rollback production entries by reversing their effects
     *
     * @param int    $userId         ID of the user performing rollback.
     * @param int    $deviceId       ID of the device.
     * @param string $version        Version of the BOM.
     * @param int    $quantity       Quantity from original entry (can be positive or negative, will be reversed).
     * @param string $comment        Comment for rollback entry.
     * @param mixed  $productionDate Production date (formatted as needed).
     * @param string $deviceType     'smd' or 'tht'.
     * @param mixed  $laminateId     (Optional) Required if $deviceType is 'smd'.
     * @param int    $commissionId   (Optional) Commission ID if rollback is for commission production.
     *
     * @return array [firstInsertedId, lastInsertedId, negativeStockAlerts, commissionAlerts]
     * @throws Exception If errors occur during rollback.
     */
    public function rollback($userId, $deviceId, $version, $quantity, $comment, $productionDate, $deviceType, $laminateId = null, $commissionId = null): array
    {
        try {
            // Retrieve user information
            $user = $this->userRepository->getUserById($userId);
            $userInfo = $user->getUserInfo();
            $sub_magazine_id = $userInfo["sub_magazine_id"];

            // Build BOM filter values. SMD requires an additional laminate_id.
            $bomValues = [ $deviceType . "_id" => $deviceId, "version" => $version == 'n/d' ? null : $version];

            if ($deviceType === "smd") {
                if ($laminateId === null) {
                    throw new \Exception("Laminate ID is required for SMD rollback.");
                }
                $bomValues["laminate_id"] = $laminateId;
            }

            // Retrieve BOM using the repository.
            $bomsFound = $this->bomRepository->getBomByValues($deviceType, $bomValues);
            if (!is_null($bomsFound) && count($bomsFound) > 1) {
                throw new \Exception("Multiple BOM records found for the provided values. Unable to proceed with the rollback.");
            }
            $bom = $bomsFound[0];
            $bomId = $bom->id;
            $bomComponents = $bom->getComponents(1);

            // Determine which inventory table and field names to use.
            $inventoryTable = "inventory__" . $deviceType;
            $deviceField    = $deviceType . "_id";
            $bomField       = $deviceType . "_bom_id";

            $rollbackQuantity = -$quantity; // Reverse the original quantity

            // Add back BOM components to inventory (reverse the component deduction)
            foreach ($bomComponents as $component) {
                $type = $component["type"];
                $component_id = $component["componentId"];
                // If original was positive production, components were subtracted, so add them back
                // If original was negative production, components were added, so subtract them back
                $component_quantity = ($component["quantity"] * $rollbackQuantity);
                $componentInsertData = [$type . "_id", "user_id", "sub_magazine_id", "quantity", "input_type_id", "comment"];
                $componentInsertValues = [$component_id, $userId, $sub_magazine_id, $component_quantity, '6', 'Rollback: powrót/korekta magazynu'];

                if ($commissionId) {
                    $componentInsertData[] = "commission_id";
                    $componentInsertValues[] = $commissionId;
                }

                $this->MsaDB->insert("inventory__" . $type, $componentInsertData, $componentInsertValues);
            }

            // Insert rollback production record
            $insertData = [$deviceField, $bomField, "user_id", "sub_magazine_id", "quantity", "input_type_id", "comment", "production_date"];
            $insertValues = [$deviceId, $bomId, $userId, $sub_magazine_id, $rollbackQuantity, '4', $comment, $productionDate];

            if ($commissionId) {
                $insertData[] = "commission_id";
                $insertValues[] = $commissionId;
            }

            $insertedId = $this->MsaDB->insert($inventoryTable, $insertData, $insertValues);

            // Handle commission updates if applicable
            if ($commissionId) {
                $commission = $this->MsaDB->query("
                    SELECT quantity_produced, quantity_returned, quantity, state_id 
                    FROM commission__list 
                    WHERE id = '$commissionId'
                ");

                if (!empty($commission)) {
                    $commissionData = $commission[0];
                    $currentQuantityProduced = $commissionData[0];
                    $quantityReturned = $commissionData[1];
                    $totalQuantity = $commissionData[2];

                    // For rollback, we reverse the original production:
                    // If original entry was +5, rollback subtracts 5 from commission
                    // If original entry was -3, rollback subtracts -3 (adds 3) to commission
                    $newQuantityProduced = $currentQuantityProduced - $quantity;

                    // Ensure produced doesn't go below returned (but can go below 0 in total)
                    if ($newQuantityProduced < $quantityReturned) {
                        $maxRollbackAmount = $currentQuantityProduced - $quantityReturned;
                        $quantity = $quantity + 0;
                        throw new \Exception("Nie można cofnąć pełnej ilości. Można cofnąć tylko {$maxRollbackAmount} z {$quantity} (cofnięcie więcej spowodowałoby, że ilość wyprodukowana byłaby niższa niż zwrócona) Spróbuj cofnąć za pomocą korekty (ujemna ilość).");
                    }

                    // Determine new state: 1 = active, 2 = completed, 3 = returned
                    $newStateId = 1; // Default to active
                    if ($newQuantityProduced >= $totalQuantity && $quantityReturned < $totalQuantity) {
                        $newStateId = 2; // Completed
                    }
                    if ($quantityReturned >= $totalQuantity && $newQuantityProduced >= $totalQuantity) {
                        $newStateId = 3; // Returned
                    }

                    $this->MsaDB->update(
                        "commission__list",
                        ["quantity_produced" => $newQuantityProduced, "state_id" => $newStateId],
                        "id",
                        $commissionId
                    );
                }
            }

            $bomComponentIds = $this->prepareComponents($bomComponents);
            $negativeStockAlerts = $this->checkLowStock($userId, $sub_magazine_id, $bomComponentIds);

            return [$insertedId, $insertedId, $negativeStockAlerts, []];
        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * Checks for low stock items for only the specified BOM component IDs and auto-produces them if allowed,
     * returning bootstrap alert elements.
     *
     * This method retrieves low stock items (joined with the corresponding list tables where applicable)
     * for the given sub_magazine, but only for the component IDs provided in $bomComponents.
     * For each item with negative total_quantity, it checks the isAutoProduced flag.
     * If auto production is enabled, it triggers production using default parameters and returns an alert element
     * with class "alert-success" displaying the device name and needed quantity. Otherwise, it returns an alert element
     * with class "alert-danger" displaying the device name.
     *
     * Device names are retrieved using the readIdName() method for the respective device type tables.
     *
     * The returned array is a list of HTML string alerts, for example:
     * [
     *   '<div class="alert alert-success" role="alert">Automatycznie wyprodukowano: <b>DeviceName w ilości 5</b></div>',
     *   '<div class="alert alert-danger" role="alert">Ujemne wartości magazynowe dla: <b>DeviceName</b></div>'
     * ]
     *
     * @param int   $sub_magazine_id The magazine id for which to check low stock.
     * @param array $bomComponents   An associative array with keys "sku", "tht", "smd", "parts" containing arrays of component IDs.
     * @return string[] An array of bootstrap alert HTML elements as strings.
     * @throws Exception If an error occurs during production.
     */
    private function checkLowStock($userId, $sub_magazine_id, $bomComponents): array {

        // Get device name mappings.
        $list__sku   = $this->MsaDB->readIdName("list__sku");
        $list__tht   = $this->MsaDB->readIdName("list__tht");
        $list__smd   = $this->MsaDB->readIdName("list__smd");
        $list__parts = $this->MsaDB->readIdName("list__parts");

        $nameMap = [
            "sku"   => $list__sku,
            "tht"   => $list__tht,
            "smd"   => $list__smd,
            "parts" => $list__parts
        ];

        // This will store the bootstrap alert strings.
        $alerts = [];

        // Mapping each device type to its lowstock and list table, plus the identifier column.
        $deviceTypes = [
            "sku"   => [
                "lowstockTable" => "lowstock__sku",
                "listTable"     => "list__sku",
                "idColumn"      => "sku_id"
            ],
            "tht"   => [
                "lowstockTable" => "lowstock__tht",
                "listTable"     => "list__tht",
                "idColumn"      => "tht_id"
            ],
            "smd"   => [
                "lowstockTable" => "lowstock__smd",
                "listTable"     => "list__smd",
                "idColumn"      => "smd_id"
            ],
            "parts" => [
                "lowstockTable" => "lowstock__parts",
                "listTable"     => "list__parts",
                "idColumn"      => "parts_id"
            ]
        ];

        // Loop over each device type and process low stock items
        foreach ($deviceTypes as $deviceType => $tables) {
            // Only proceed if there are BOM component IDs for this device type.
            if (!isset($bomComponents[$deviceType]) || empty($bomComponents[$deviceType])) {
                continue;
            }

            // Prepare a comma-separated list of IDs. (Assumes IDs are integers.)
            $ids = implode(',', $bomComponents[$deviceType]);

            $lowstockTable = $tables["lowstockTable"];
            $listTable     = $tables["listTable"];
            $idColumn      = $tables["idColumn"];

            // Build the SQL query based on device type.
            if (in_array($deviceType, ['smd', 'parts'])) {
                // For SMD and Parts, there is no isAutoProduced column.
                $sql = "SELECT {$idColumn} AS id, total_quantity, 0 AS isAutoProduced 
                    FROM {$lowstockTable} 
                    WHERE sub_magazine_id = {$sub_magazine_id} 
                      AND total_quantity < 0 
                      AND {$idColumn} IN ({$ids})";
            } else {
                // For sku and tht, join to retrieve isAutoProduced.
                $sql = "SELECT ls.{$idColumn} AS id, ls.total_quantity, l.isAutoProduced, l.autoProduceVersion
                    FROM {$lowstockTable} ls 
                    JOIN {$listTable} l ON l.id = ls.{$idColumn}
                    WHERE ls.sub_magazine_id = {$sub_magazine_id} 
                      AND ls.total_quantity < 0 
                      AND ls.{$idColumn} IN ({$ids})";
            }

            $items = $this->MsaDB->query($sql, \PDO::FETCH_ASSOC);

            foreach ($items as $item) {
                $identifier     = $item['id'];
                $neededQuantity = abs($item["total_quantity"]);
                // Use the device name from the mapping, if available.
                $deviceName = $nameMap[$deviceType][$identifier] ?? $identifier;

                if ($item["isAutoProduced"]) {
                    // Auto produce the low stock item.
                    $version        = $item["autoProduceVersion"];
                    $comment        = "Automatyczna produkcja wygenerowana przez ujemne ilości magazynowe.";
                    $productionDate = "'" . date("Y-m-d") . "'";
                    // Assumes $userId is available (e.g. as a property or previously defined variable).
                    $this->produce($userId, $identifier, $version, $neededQuantity, $comment, $productionDate, $deviceType);
                    $alerts[] = '<div class="alert alert-success" role="alert">Automatycznie wyprodukowano: <b>' . htmlspecialchars($deviceName) . ' w ilości ' . $neededQuantity . '</b></div>';
                } else {
                    $alerts[] = '<div class="alert alert-danger" role="alert">Ujemne wartości magazynowe dla: <b>' . htmlspecialchars($deviceName) . '</b></div>';
                }
            }
        }

        return $alerts;
    }

    private function prepareComponents($bomComponents)
    {
        // Initialize the result array with empty arrays for each type.
        $result = [
            'sku'   => [],
            'tht'   => [],
            'smd'   => [],
            'parts' => [],
        ];

        // Loop through each component.
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