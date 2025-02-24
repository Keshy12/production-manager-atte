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
     * @return array The ID of the first inserted inventory record.
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
            $bomComponentIds = $this -> prepareComponents($bomComponents);
            $negativeStockAlerts = $this -> checkLowStock($userId, $sub_magazine_id, $bomComponentIds);
            return [$firstInsertedId, $negativeStockAlerts];
        } catch (\Exception $e) {
            throw $e;
        }
    }


    /**
     * Checks for low stock items for all device types and auto-produces them if allowed,
     * returning bootstrap alert elements.
     *
     * This method retrieves low stock items from the lowstock tables (joined with the corresponding list tables)
     * for the user's sub_magazine. For each item with negative total_quantity, it checks the isAutoProduced flag.
     * If auto production is enabled, it triggers production using default parameters and returns an alert element
     * with class "alert-success" displaying the device name. Otherwise, it returns an alert element with class
     * "alert-danger" displaying the device name.
     *
     * Device names are retrieved using the readIdName() method for the respective device type tables.
     *
     * The returned array is a list of HTML string alerts, for example:
     * [
     *   '<div class="alert alert-success">Auto-produced: DeviceName</div>',
     *   '<div class="alert alert-danger">Low stock: DeviceName</div>'
     * ]
     *
     * @param int $userId The ID of the user for whom to check low stock.
     * @return string[] An array of bootstrap alert HTML elements as strings.
     * @throws Exception If an error occurs during production.
     */
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
