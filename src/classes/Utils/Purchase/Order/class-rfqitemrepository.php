<?php
namespace Atte\Utils\Purchase\Order;

use Atte\DB\MsaDB;

class RFQItemRepository {
    private $MsaDB;

    public function __construct(MsaDB $MsaDB){
        $this->MsaDB = $MsaDB;
    }

    private function buildSelectJoin(): string {
        return "SELECT i.id,
                       i.rfq_id AS rfqId,
                       i.vendor_part_id AS vendorPartId,
                       i.quantity,
                       i.quantity_unit_id AS quantityUnitId,
                       i.unit_price AS unitPrice,
                       i.currency,
                       i.comment,
                       v.name  AS vendorName,
                       p.name  AS producerName,
                       lp.name AS partName,
                       u.name  AS unitName,
                       vp.vendor_part_no AS vendorPartNo
                FROM `purchase__rfq_item` i
                LEFT JOIN `list__vendor_part` vp ON i.vendor_part_id   = vp.id
                LEFT JOIN `list__vendor` v       ON vp.vendor_id       = v.id
                LEFT JOIN `list__producer` p     ON vp.producer_id     = p.id
                LEFT JOIN `list__parts` lp       ON vp.parts_id        = lp.id
                LEFT JOIN `part__unit` u         ON i.quantity_unit_id = u.id";
    }

    public function getById(int $id): ?RFQItem {
        $MsaDB = $this->MsaDB;
        $sql = $this->buildSelectJoin() . " WHERE i.id = ?";
        $stmt = $MsaDB->db->prepare($sql);
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : new RFQItem($row);
    }

    public function getByRfq(int $rfqId): array {
        $MsaDB = $this->MsaDB;
        $sql = $this->buildSelectJoin() . " WHERE i.rfq_id = ? ORDER BY i.id ASC";
        $stmt = $MsaDB->db->prepare($sql);
        $stmt->execute([$rfqId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[] = new RFQItem($row);
        }
        return $result;
    }

    public function create(int $rfqId, int $vendorPartId, float $quantity, int $quantityUnitId, ?float $unitPrice = null, string $currency = 'PLN', ?string $comment = null): int {
        $MsaDB = $this->MsaDB;
        if ($rfqId <= 0)          throw new \InvalidArgumentException("RFQItem rfqId must be positive.");
        if ($vendorPartId <= 0)   throw new \InvalidArgumentException("RFQItem vendorPartId must be positive.");
        if ($quantityUnitId <= 0) throw new \InvalidArgumentException("RFQItem quantityUnitId must be positive.");
        if ($quantity <= 0)       throw new \InvalidArgumentException("RFQItem quantity must be positive.");
        return $MsaDB->insert(
            'purchase__rfq_item',
            ['rfq_id', 'vendor_part_id', 'quantity', 'quantity_unit_id', 'unit_price', 'currency', 'comment'],
            [$rfqId, $vendorPartId, $quantity, $quantityUnitId, $unitPrice, $currency, $comment]
        );
    }

    public function update(int $id, int $vendorPartId, float $quantity, int $quantityUnitId, ?float $unitPrice, string $currency, ?string $comment): bool {
        $MsaDB = $this->MsaDB;
        if ($vendorPartId <= 0)   throw new \InvalidArgumentException("RFQItem vendorPartId must be positive.");
        if ($quantityUnitId <= 0) throw new \InvalidArgumentException("RFQItem quantityUnitId must be positive.");
        if ($quantity <= 0)       throw new \InvalidArgumentException("RFQItem quantity must be positive.");
        return $MsaDB->update(
            'purchase__rfq_item',
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
        $stmt = $MsaDB->db->prepare("DELETE FROM `purchase__rfq_item` WHERE id = ?");
        return $stmt->execute([$id]);
    }

    public function countByRfq(int $rfqId): int {
        $MsaDB = $this->MsaDB;
        $stmt = $MsaDB->db->prepare("SELECT COUNT(*) AS c FROM `purchase__rfq_item` WHERE rfq_id = ?");
        $stmt->execute([$rfqId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return (int)($row['c'] ?? 0);
    }
}
