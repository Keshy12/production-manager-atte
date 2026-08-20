<?php
namespace Atte\Utils\Purchase\Order;

use Atte\DB\MsaDB;

class PurchaseOrderItemRepository {
    private $MsaDB;

    public function __construct(MsaDB $MsaDB){
        $this->MsaDB = $MsaDB;
    }

    private function buildSelectJoin(): string {
        return "SELECT i.id,
                       i.po_id AS poId,
                       i.vendor_part_id AS vendorPartId,
                       i.quantity,
                       i.quantity_unit_id AS quantityUnitId,
                       i.unit_price AS unitPrice,
                       i.currency,
                       i.quantity_received AS quantityReceived,
                       i.comment,
                       v.name  AS vendorName,
                       p.name  AS producerName,
                       lp.name AS partName,
                       u.name  AS unitName,
                       vp.vendor_part_no AS vendorPartNo
                FROM `purchase__order_item` i
                LEFT JOIN `list__vendor_part` vp ON i.vendor_part_id   = vp.id
                LEFT JOIN `list__vendor` v       ON vp.vendor_id       = v.id
                LEFT JOIN `list__producer` p     ON vp.producer_id     = p.id
                LEFT JOIN `list__parts` lp       ON vp.parts_id        = lp.id
                LEFT JOIN `part__unit` u         ON i.quantity_unit_id = u.id";
    }

    public function getById(int $id): ?PurchaseOrderItem {
        $MsaDB = $this->MsaDB;
        $sql = $this->buildSelectJoin() . " WHERE i.id = ?";
        $stmt = $MsaDB->db->prepare($sql);
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : new PurchaseOrderItem($MsaDB, $row);
    }

    public function getByPo(int $poId): array {
        $MsaDB = $this->MsaDB;
        $sql = $this->buildSelectJoin() . " WHERE i.po_id = ? ORDER BY i.id ASC";
        $stmt = $MsaDB->db->prepare($sql);
        $stmt->execute([$poId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[] = new PurchaseOrderItem($MsaDB, $row);
        }
        return $result;
    }

    public function create(int $poId, int $vendorPartId, float $quantity, int $quantityUnitId, float $unitPrice = 0, string $currency = 'PLN', ?string $comment = null): int {
        $MsaDB = $this->MsaDB;
        if ($poId <= 0)          throw new \InvalidArgumentException("PurchaseOrderItem poId must be positive.");
        if ($vendorPartId <= 0)   throw new \InvalidArgumentException("PurchaseOrderItem vendorPartId must be positive.");
        if ($quantityUnitId <= 0) throw new \InvalidArgumentException("PurchaseOrderItem quantityUnitId must be positive.");
        if ($quantity <= 0)       throw new \InvalidArgumentException("PurchaseOrderItem quantity must be positive.");
        return $MsaDB->insert(
            'purchase__order_item',
            ['po_id', 'vendor_part_id', 'quantity', 'quantity_unit_id', 'unit_price', 'currency', 'comment'],
            [$poId, $vendorPartId, $quantity, $quantityUnitId, $unitPrice, $currency, $comment]
        );
    }

    public function update(int $id, int $vendorPartId, float $quantity, int $quantityUnitId, float $unitPrice, string $currency, ?string $comment): bool {
        $MsaDB = $this->MsaDB;
        if ($vendorPartId <= 0)   throw new \InvalidArgumentException("PurchaseOrderItem vendorPartId must be positive.");
        if ($quantityUnitId <= 0) throw new \InvalidArgumentException("PurchaseOrderItem quantityUnitId must be positive.");
        if ($quantity <= 0)       throw new \InvalidArgumentException("PurchaseOrderItem quantity must be positive.");
        return $MsaDB->update(
            'purchase__order_item',
            [
                'vendor_part_id'   => $vendorPartId,
                'quantity'         => $quantity,
                'quantity_unit_id' => $quantityUnitId,
                'unit_price'       => $unitPrice,
                'currency'         => $currency,
                'comment'          => $comment,
            ],
            'id',
            $id
        );
    }

    public function delete(int $id): bool {
        $MsaDB = $this->MsaDB;
        $stmt = $MsaDB->db->prepare("DELETE FROM `purchase__order_item` WHERE id = ?");
        return $stmt->execute([$id]);
    }

    public function countByPo(int $poId): int {
        $MsaDB = $this->MsaDB;
        $stmt = $MsaDB->db->prepare("SELECT COUNT(*) AS c FROM `purchase__order_item` WHERE po_id = ?");
        $stmt->execute([$poId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return (int)($row['c'] ?? 0);
    }

    public function sumByPo(int $poId): float {
        $MsaDB = $this->MsaDB;
        $stmt = $MsaDB->db->prepare(
            "SELECT COALESCE(SUM(quantity * unit_price), 0) AS s FROM `purchase__order_item` WHERE po_id = ?"
        );
        $stmt->execute([$poId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return (float)($row['s'] ?? 0);
    }

    public function sumQuantityReceivedByPo(int $poId): float {
        $MsaDB = $this->MsaDB;
        $stmt = $MsaDB->db->prepare(
            "SELECT COALESCE(SUM(quantity_received), 0) AS s FROM `purchase__order_item` WHERE po_id = ?"
        );
        $stmt->execute([$poId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return (float)($row['s'] ?? 0);
    }
}
