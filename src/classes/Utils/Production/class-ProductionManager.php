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
     * @param int    $quantity       Quantity to produce.
     * @param string $comment        Comment to be saved with production.
     * @param mixed  $productionDate Production date (formatted as needed).
     * @param string $deviceType     'smd' or 'tht'.
     * @param mixed  $laminateId     (Optional) Required if $deviceType is 'smd'.
     *
     * @return int|object|null The ID of the first inserted inventory record.
     * @throws Exception If errors occur during production.
     */
    public function produce($userId, $deviceId, $version, $quantity, $comment, $productionDate, $deviceType, $laminateId = null): object|int|null
    {
        try {
            // Retrieve user information
            $user = $this->userRepository->getUserById($userId);
            $userInfo = $user->getUserInfo();
            $sub_magazine_id = $userInfo["sub_magazine_id"];

            // Build BOM filter values. SMD requires an additional laminate_id.
            $bomValues = [ $deviceType . "_id" => $deviceId, "version" => $version ];
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
                    $quantity = abs($quantity);
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
                if (empty($firstInsertedId)) {
                    $firstInsertedId = $insertedId;
                }
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
                if (empty($firstInsertedId)) {
                    $firstInsertedId = $insertedId;
                }
            }

            return $firstInsertedId;
        } catch (\Exception $e) {
            throw $e;
        }
    }


    /**
     * Checks for low stock items for all device types and auto-produces them if allowed.
     *
     * This method retrieves low stock items from the lowstock tables (joined with the corresponding list tables)
     * for the user's sub_magazine. For each item with negative total_quantity, it checks the isAutoProduce flag.
     * If auto production is enabled, it triggers production using default parameters; otherwise, it adds the item's
     * identifier to a result array.
     *
     * The returned array is structured as follows:
     * [
     *   "sku"   => [<sku_ids>],
     *   "tht"   => [<tht_ids>],
     *   "smd"   => [<smd_ids>],
     *   "parts" => [<parts_ids>]
     * ]
     *
     * @param int $userId The ID of the user to check low stock for.
     * @return array An associative array listing low stock item IDs by device type.
     * @throws Exception Optionally, you might choose to throw an exception if auto production is disabled.
     */
    public function checkLowStock($userId): array {
        // Retrieve user information
        $user = $this->userRepository->getUserById($userId);
        $sub_magazine_id = $user->getUserInfo()["sub_magazine_id"];

        // Initialize result array for each device type
        $lowStockIds = [
            "sku"   => [],
            "tht"   => [],
            "smd"   => [],
            "parts" => []
        ];

        $lowStockIdsAutoProduced = [
            "sku"   => [],
            "tht"   => [],
            "smd"   => [],
            "parts" => []
        ];

        // Mapping each device type to its lowstock and list table, plus the identifier column
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
            $lowstockTable = $tables["lowstockTable"];
            $listTable     = $tables["listTable"];
            $idColumn      = $tables["idColumn"];

            if (in_array($deviceType, ['smd', 'parts'])) {
                // For SMD and Parts, there is no isAutoProduce column.
                // Set it to 0 (false) by default.
                $sql = "SELECT {$idColumn} AS id, total_quantity, 0 AS isAutoProduced 
                FROM {$lowstockTable} 
                WHERE sub_magazine_id = {$sub_magazine_id} AND total_quantity < 0";
            } else {
                // For sku and tht, join to retrieve isAutoProduce.
                $sql = "SELECT ls.{$idColumn} AS id, ls.total_quantity, l.isAutoProduced 
                FROM {$lowstockTable} ls 
                JOIN {$listTable} l ON l.id = ls.{$idColumn}
                WHERE ls.sub_magazine_id = {$sub_magazine_id} AND ls.total_quantity < 0";
            }

            $items = $this->MsaDB->query($sql, \PDO::FETCH_ASSOC);

            foreach ($items as $item) {
                $identifier     = $item["id"];
                $neededQuantity = abs($item["total_quantity"]);

                if ($item["isAutoProduced"]) {
                    $version        = 'A';
                    $comment        = "Automatyczna produkcja wygenerowana przez ujemne ilości magazynowe.";
                    $productionDate = "'" . date("Y-m-d") . "'";
                    $this->produce($userId, $identifier, $version, $neededQuantity, $comment, $productionDate, $deviceType);
                    $lowStockIdsAutoProduced[$deviceType][] = $identifier;
                } else {
                    $lowStockIds[$deviceType][] = $identifier;
                }
            }
        }

        return [
            "lowStockIds" => $lowStockIds,
            "lowStockIdsAutoProduced" => $lowStockIdsAutoProduced
        ];
    }
}
