<?php
namespace Atte\Utils;

use Atte\DB\MsaDB;
use Exception;

class TransferGroupManager {
    private $MsaDB;

    public function __construct(MsaDB $MsaDB) {
        $this->MsaDB = $MsaDB;
    }

    /**
     * Create a new transfer group
     * @param int $userId User creating the group
     * @param string $notes Optional notes
     * @return int Transfer group ID
     */
    public function createTransferGroup($userId, $notes = '') {
        return $this->MsaDB->insert(
            'inventory__transfer_groups',
            ['created_by', 'notes'],
            [$userId, $notes]
        );
    }

    /**
     * Cancel a transfer group and all its inventory movements
     * @param int $groupId Transfer group ID
     * @param int $userId User cancelling the group
     * @return array ['itemsCancelled' => int, 'alerts' => array]
     */
    public function cancelTransferGroup($groupId, $userId) {
        $now = date("Y-m-d H:i:s");
        $allAlerts = [];
        $itemsCancelled = 0;

        // Get all inventory items in this transfer group across all device types
        $deviceTypes = ['sku', 'smd', 'tht', 'parts'];

        foreach ($deviceTypes as $deviceType) {
            $items = $this->MsaDB->query("
                SELECT id, qty, commission_id
                FROM inventory__{$deviceType}
                WHERE transfer_group_id = {$groupId}
                AND is_cancelled = 0
            ");

            foreach ($items as $item) {
                // Mark item as cancelled
                $this->MsaDB->update(
                    "inventory__{$deviceType}",
                    [
                        'is_cancelled' => 1,
                        'cancelled_at' => $now,
                        'cancelled_by' => $userId
                    ],
                    'id',
                    $item['id']
                );

                $itemsCancelled++;

                // Update commission if this was part of a commission
                if ($item['commission_id']) {
                    $this->updateCommissionAfterRollback($item['commission_id'], $item['qty']);
                }
            }
        }

        // Mark transfer group as cancelled
        $this->MsaDB->update(
            'inventory__transfer_groups',
            [
                'is_cancelled' => 1,
                'cancelled_at' => $now,
                'cancelled_by' => $userId
            ],
            'id',
            $groupId
        );

        return [
            'itemsCancelled' => $itemsCancelled,
            'alerts' => $allAlerts
        ];
    }

    /**
     * Update commission quantities after rollback
     * @param int $commissionId Commission ID
     * @param int $quantityChange Quantity to subtract (will be negative)
     */
    private function updateCommissionAfterRollback($commissionId, $quantityChange) {
        $commissionRepo = new CommissionRepository($this->MsaDB);
        try {
            $commission = $commissionRepo->getCommissionById($commissionId);

            // If rolling back production, decrease qty_produced
            if ($quantityChange > 0) {
                $currentProduced = $commission->commissionValues['qty_produced'];
                $newProduced = max(0, $currentProduced - $quantityChange);

                $this->MsaDB->update(
                    'commission__list',
                    ['qty_produced' => $newProduced],
                    'id',
                    $commissionId
                );

                // Update commission state
                $commission->commissionValues['qty_produced'] = $newProduced;
                $commission->updateStateAuto();
            }
        } catch (Exception $e) {
            // Commission might not exist or be accessible, continue
        }
    }

    /**
     * Get all items in a transfer group
     * @param int $groupId Transfer group ID
     * @return array Array of items grouped by device type
     */
    public function getTransferGroupItems($groupId) {
        $items = [];
        $deviceTypes = ['sku', 'smd', 'tht', 'parts'];

        foreach ($deviceTypes as $deviceType) {
            $typeItems = $this->MsaDB->query("
                SELECT i.*, l.name as component_name, m.sub_magazine_name as magazine_name
                FROM inventory__{$deviceType} i
                LEFT JOIN list__{$deviceType} l ON i.{$deviceType}_id = l.id
                LEFT JOIN magazine__list m ON i.sub_magazine_id = m.sub_magazine_id
                WHERE i.transfer_group_id = {$groupId}
                ORDER BY i.timestamp ASC
            ");

            if (!empty($typeItems)) {
                $items[$deviceType] = $typeItems;
            }
        }

        return $items;
    }
}