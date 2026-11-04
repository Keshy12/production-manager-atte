<?php
namespace Atte\Utils\Purchase\Order;

use Atte\DB\MsaDB;

/**
 * Pozycje dostawy (`purchase__delivery_line`) i ich przydziały do pozycji
 * zamówień (`purchase__delivery_allocation`). Obie tabele w jednym
 * repozytorium — przydział nigdy nie istnieje bez pozycji.
 */
class DeliveryLineRepository {
    private $MsaDB;

    public function __construct(MsaDB $MsaDB){
        $this->MsaDB = $MsaDB;
    }

    /** DECIMAL(30,10) jako ciąg bez notacji naukowej. */
    public static function dec(float $v): string {
        return number_format($v, 10, '.', '');
    }

    private function lineSelect(): string {
        return "SELECT l.id,
                       l.delivery_id AS deliveryId,
                       l.line_no AS lineNo,
                       l.vendor_part_id AS vendorPartId,
                       l.quantity,
                       l.unit_price AS unitPrice,
                       l.sub_magazine_id AS subMagazineId,
                       l.inventory_parts_id AS inventoryPartsId,
                       l.comment,
                       vp.parts_id AS partsId,
                       vp.vendor_part_no AS vendorPartNo,
                       vp.producer_part_no AS producerPartNo,
                       lp.name AS partName,
                       m.sub_magazine_name AS subMagazineName
                FROM `purchase__delivery_line` l
                LEFT JOIN `list__vendor_part` vp ON l.vendor_part_id = vp.id
                LEFT JOIN `list__parts` lp       ON vp.parts_id = lp.id
                LEFT JOIN `magazine__list` m     ON l.sub_magazine_id = m.sub_magazine_id";
    }

    private function allocationSelect(): string {
        return "SELECT a.id,
                       a.delivery_line_id AS deliveryLineId,
                       a.po_item_id AS poItemId,
                       a.quantity,
                       a.is_manual AS isManual,
                       i.po_id AS poId,
                       po.po_number AS poNumber,
                       i.unit_price AS poUnitPrice,
                       i.currency AS poCurrency
                FROM `purchase__delivery_allocation` a
                LEFT JOIN `purchase__order_item` i ON a.po_item_id = i.id
                LEFT JOIN `purchase__order` po     ON i.po_id = po.id";
    }

    public function getById(int $lineId): ?DeliveryLine {
        $stmt = $this->MsaDB->db->prepare($this->lineSelect() . " WHERE l.id = ?");
        $stmt->execute([$lineId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) return null;
        $line = new DeliveryLine($row);
        $line->allocations = $this->getAllocationsByLine($lineId);
        return $line;
    }

    /** Pozycje dostawy (kolejność jak na fakturze) wraz z przydziałami. */
    public function getByDelivery(int $deliveryId): array {
        $stmt = $this->MsaDB->db->prepare($this->lineSelect() . " WHERE l.delivery_id = ? ORDER BY l.line_no ASC, l.id ASC");
        $stmt->execute([$deliveryId]);
        $lines = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $lines[$row['id']] = new DeliveryLine($row);
        }
        if (empty($lines)) return [];

        $astmt = $this->MsaDB->db->prepare(
            $this->allocationSelect()
            . " JOIN `purchase__delivery_line` l ON a.delivery_line_id = l.id"
            . " WHERE l.delivery_id = ? ORDER BY a.id ASC"
        );
        $astmt->execute([$deliveryId]);
        foreach ($astmt->fetchAll(\PDO::FETCH_ASSOC) as $arow) {
            $lid = $arow['deliveryLineId'];
            if (isset($lines[$lid])) {
                $lines[$lid]->allocations[] = new DeliveryAllocation($arow);
            }
        }
        return array_values($lines);
    }

    public function getAllocationsByLine(int $lineId): array {
        $stmt = $this->MsaDB->db->prepare($this->allocationSelect() . " WHERE a.delivery_line_id = ? ORDER BY a.id ASC");
        $stmt->execute([$lineId]);
        $result = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $result[] = new DeliveryAllocation($row);
        }
        return $result;
    }

    public function create(int $deliveryId, int $vendorPartId, float $quantity, ?float $unitPrice, ?int $subMagazineId, ?string $comment): int {
        if ($deliveryId <= 0)   throw new \InvalidArgumentException("DeliveryLine deliveryId must be positive.");
        if ($vendorPartId <= 0) throw new \InvalidArgumentException("DeliveryLine vendorPartId must be positive.");
        if ($quantity <= 0)     throw new \InvalidArgumentException("DeliveryLine quantity must be positive.");

        $stmt = $this->MsaDB->db->prepare("SELECT COALESCE(MAX(line_no), 0) + 1 FROM `purchase__delivery_line` WHERE delivery_id = ?");
        $stmt->execute([$deliveryId]);
        $lineNo = (int)$stmt->fetchColumn();

        return (int)$this->MsaDB->insert(
            'purchase__delivery_line',
            ['delivery_id', 'line_no', 'vendor_part_id', 'quantity', 'unit_price', 'sub_magazine_id', 'comment'],
            [
                $deliveryId, $lineNo, $vendorPartId, self::dec($quantity),
                $unitPrice !== null ? self::dec($unitPrice) : null,
                $subMagazineId, $comment,
            ]
        );
    }

    public function update(int $lineId, int $vendorPartId, float $quantity, ?float $unitPrice, ?int $subMagazineId, ?string $comment): bool {
        if ($vendorPartId <= 0) throw new \InvalidArgumentException("DeliveryLine vendorPartId must be positive.");
        if ($quantity <= 0)     throw new \InvalidArgumentException("DeliveryLine quantity must be positive.");
        $stmt = $this->MsaDB->db->prepare(
            "UPDATE `purchase__delivery_line`
                SET vendor_part_id = ?, quantity = ?, unit_price = ?, sub_magazine_id = ?, comment = ?
              WHERE id = ?"
        );
        return $stmt->execute([
            $vendorPartId, self::dec($quantity),
            $unitPrice !== null ? self::dec($unitPrice) : null,
            $subMagazineId, $comment, $lineId,
        ]);
    }

    /** Kasuje pozycję (przydziały znikają kaskadowo) i przenumerowuje resztę. */
    public function delete(int $lineId): bool {
        $stmt = $this->MsaDB->db->prepare("SELECT delivery_id FROM `purchase__delivery_line` WHERE id = ?");
        $stmt->execute([$lineId]);
        $deliveryId = $stmt->fetchColumn();
        if ($deliveryId === false) return false;

        $del = $this->MsaDB->db->prepare("DELETE FROM `purchase__delivery_line` WHERE id = ?");
        $del->execute([$lineId]);
        $this->renumber((int)$deliveryId);
        return true;
    }

    /** Układa line_no od 1 bez dziur, zachowując kolejność. */
    public function renumber(int $deliveryId): void {
        $stmt = $this->MsaDB->db->prepare("SELECT id FROM `purchase__delivery_line` WHERE delivery_id = ? ORDER BY line_no ASC, id ASC");
        $stmt->execute([$deliveryId]);
        $upd = $this->MsaDB->db->prepare("UPDATE `purchase__delivery_line` SET line_no = ? WHERE id = ?");
        $n = 1;
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            $upd->execute([$n++, $id]);
        }
    }

    public function setInventoryPartsId(int $lineId, ?int $inventoryPartsId): bool {
        $stmt = $this->MsaDB->db->prepare("UPDATE `purchase__delivery_line` SET inventory_parts_id = ? WHERE id = ?");
        return $stmt->execute([$inventoryPartsId, $lineId]);
    }

    // ── Allocations ───────────────────────────────────────────────

    public function insertAllocation(int $lineId, int $poItemId, float $quantity, bool $isManual): int {
        return (int)$this->MsaDB->insert(
            'purchase__delivery_allocation',
            ['delivery_line_id', 'po_item_id', 'quantity', 'is_manual'],
            [$lineId, $poItemId, self::dec($quantity), $isManual ? 1 : 0]
        );
    }

    /** Kasuje przydziały automatyczne całej dostawy (ręczne zostają). */
    public function deleteAutoAllocationsByDelivery(int $deliveryId): void {
        $stmt = $this->MsaDB->db->prepare(
            "DELETE a FROM `purchase__delivery_allocation` a
               JOIN `purchase__delivery_line` l ON a.delivery_line_id = l.id
              WHERE l.delivery_id = ? AND a.is_manual = 0"
        );
        $stmt->execute([$deliveryId]);
    }

    /** Kasuje WSZYSTKIE przydziały pozycji (ręczne i automatyczne). */
    public function deleteAllocationsByLine(int $lineId): void {
        $stmt = $this->MsaDB->db->prepare("DELETE FROM `purchase__delivery_allocation` WHERE delivery_line_id = ?");
        $stmt->execute([$lineId]);
    }

    /** Kasuje przydziały całej dostawy (np. gdy zmienił się dostawca/waluta). */
    public function deleteAllocationsByDelivery(int $deliveryId): void {
        $stmt = $this->MsaDB->db->prepare(
            "DELETE a FROM `purchase__delivery_allocation` a
               JOIN `purchase__delivery_line` l ON a.delivery_line_id = l.id
              WHERE l.delivery_id = ?"
        );
        $stmt->execute([$deliveryId]);
    }

    /**
     * Dane pozycji dostawcy potrzebne do walidacji: vendor_id, isActive,
     * parts_id, vendor_part_no. null gdy brak.
     */
    public function getVendorPartInfo(int $vendorPartId): ?array {
        $stmt = $this->MsaDB->db->prepare(
            "SELECT id, vendor_id AS vendorId, parts_id AS partsId, vendor_part_no AS vendorPartNo, isActive
               FROM `list__vendor_part` WHERE id = ?"
        );
        $stmt->execute([$vendorPartId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }
}
