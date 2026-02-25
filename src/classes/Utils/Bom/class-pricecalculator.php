<?php
namespace Atte\Utils\Bom;

use Atte\DB\MsaDB;

class PriceCalculator {
    private $MsaDB;

    public function __construct(MsaDB $MsaDB) {
        $this->MsaDB = $MsaDB;
    }

    /**
     * Recalculates the price for a specific BOM and updates its price in the database.
     */
    public function updateBomPrice(int $bomId, string $deviceType): float {
        $totalPrice = 0.0;
        
        // 1. Calculate Material Cost
        $components = $this->MsaDB->query("
            SELECT 
                b.quantity,
                b.sku_id, b.tht_id, b.smd_id, b.parts_id
            FROM bom__flat b
            WHERE b.bom_{$deviceType}_id = $bomId
        ");

        foreach ($components as $component) {
            $qty = (float)$component['quantity'];
            $itemPrice = 0.0;

            if ($component['parts_id']) {
                $itemPrice = $this->getPartPrice($component['parts_id']);
            } elseif ($component['smd_id']) {
                $itemPrice = $this->getDevicePrice('smd', $component['smd_id']);
            } elseif ($component['tht_id']) {
                $itemPrice = $this->getDevicePrice('tht', $component['tht_id']);
            } elseif ($component['sku_id']) {
                $itemPrice = $this->getDevicePrice('sku', $component['sku_id']);
            }

            $totalPrice += ($qty * $itemPrice);
        }

        // 2. Add Processing Costs
        if ($deviceType === 'smd') {
            // SMD: Sum of components * 0.06 PLN
            $totalQty = 0;
            foreach ($components as $c) $totalQty += (float)$c['quantity'];
            $totalPrice += ($totalQty * 0.06);
        } elseif ($deviceType === 'tht') {
            // THT: Output quantity * 1.00 PLN
            $bomData = $this->MsaDB->query("SELECT out_tht_quantity FROM bom__tht WHERE id = $bomId");
            $outQty = (float)($bomData[0]['out_tht_quantity'] ?? 0);
            $totalPrice += ($outQty * 1.00);
        }

        // 3. Update BOM table
        $this->MsaDB->update("bom__$deviceType", ['price' => $totalPrice], 'id', $bomId);

        return $totalPrice;
    }

    private function getPartPrice(int $partId): float {
        $res = $this->MsaDB->query("SELECT price FROM list__parts WHERE id = $partId");
        return (float)($res[0]['price'] ?? 0);
    }

    private function getDevicePrice(string $type, int $deviceId): float {
        $bomId = null;
        if ($type === 'sku') {
            // SKUs only have one active BOM, fetch it directly
            $res = $this->MsaDB->query("SELECT id FROM bom__sku WHERE sku_id = $deviceId AND isActive = 1 LIMIT 1");
            $bomId = $res[0]['id'] ?? null;
        } else {
            // Get the default BOM for THT/SMD
            $res = $this->MsaDB->query("SELECT default_bom_id FROM list__$type WHERE id = $deviceId");
            $bomId = $res[0]['default_bom_id'] ?? null;

            // Fallback: If no default BOM is selected, assume the first active version for calculation purposes
            if (!$bomId) {
                $fallbackRes = $this->MsaDB->query("SELECT id FROM bom__$type WHERE {$type}_id = $deviceId AND isActive = 1 ORDER BY id ASC LIMIT 1");
                $bomId = $fallbackRes[0]['id'] ?? null;
            }
        }
        
        if (!$bomId) return 0.0;

        // Get the price of that BOM
        $res = $this->MsaDB->query("SELECT price FROM bom__$type WHERE id = $bomId");
        return (float)($res[0]['price'] ?? 0);
    }

    /**
     * Propagates price changes upwards.
     * When a part price or a BOM price changes, we need to find all BOMs 
     * that use the component and recalculate them.
     */
    public function propagatePriceChange(int $childId, string $childType) {
        $columnMap = [
            'parts' => 'parts_id',
            'smd' => 'smd_id',
            'tht' => 'tht_id',
            'sku' => 'sku_id'
        ];
        $col = $columnMap[$childType];

        $affectedBoms = $this->MsaDB->query("
            SELECT DISTINCT bom_smd_id, bom_tht_id, bom_sku_id 
            FROM bom__flat 
            WHERE $col = $childId
        ");

        foreach ($affectedBoms as $row) {
            if ($row['bom_smd_id']) {
                $this->updateBomPriceAndPropagate($row['bom_smd_id'], 'smd');
            }
            if ($row['bom_tht_id']) {
                $this->updateBomPriceAndPropagate($row['bom_tht_id'], 'tht');
            }
            if ($row['bom_sku_id']) {
                $this->updateBomPriceAndPropagate($row['bom_sku_id'], 'sku');
            }
        }
    }

    public function updateBomPriceAndPropagate(int $bomId, string $type) {
        $this->updateBomPrice($bomId, $type);
        
        // Now find which device uses this BOM
        $deviceRes = $this->MsaDB->query("SELECT {$type}_id as deviceId FROM bom__{$type} WHERE id = $bomId");
        $deviceId = $deviceRes[0]['deviceId'] ?? null;
        
        if ($deviceId) {
            $shouldPropagate = false;
            if ($type === 'sku') {
                // For SKU, any price change propagates as there is only one BOM
                $shouldPropagate = true;
            } else {
                // For THT/SMD, only propagate if this is the DEFAULT bom
                $defaultCheck = $this->MsaDB->query("SELECT id FROM list__{$type} WHERE id = $deviceId AND default_bom_id = $bomId");
                $shouldPropagate = !empty($defaultCheck);
            }

            if ($shouldPropagate) {
                $this->propagatePriceChange($deviceId, $type);
            }
        }
    }
}
