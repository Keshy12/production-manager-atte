<?php
namespace Atte\Utils\Purchase\Order;

use Atte\DB\MsaDB;

class PurchaseOrderRepository {
    private $MsaDB;

    public function __construct(MsaDB $MsaDB){
        $this->MsaDB = $MsaDB;
    }

    private function buildSelectJoin(): string {
        return "SELECT o.id,
                       o.vendor_id AS vendorId,
                       o.state,
                       o.po_number AS poNumber,
                       o.vendor_po_number AS vendorPoNumber,
                       o.converted_from_rfq_id AS convertedFromRfqId,
                       o.expected_delivery_date AS expectedDeliveryDate,
                       o.sent_at AS sentAt,
                       o.confirmed_at AS confirmedAt,
                       o.created_by AS createdBy,
                       o.comment,
                       o.created_at AS createdAt,
                       o.updated_at AS updatedAt,
                       v.name AS vendorName,
                       rfq.rfq_number AS convertedFromRfqNumber
                FROM `purchase__order` o
                LEFT JOIN `list__vendor` v ON o.vendor_id = v.id
                LEFT JOIN `purchase__rfq` rfq ON o.converted_from_rfq_id = rfq.id";
    }

    public function getById(int $id): ?PurchaseOrder {
        $MsaDB = $this->MsaDB;
        $sql = $this->buildSelectJoin() . " WHERE o.id = ?";
        $stmt = $MsaDB->db->prepare($sql);
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : new PurchaseOrder($MsaDB, $row);
    }

    public function getAll(bool $onlyActive = false): array {
        $MsaDB = $this->MsaDB;
        $where = $onlyActive ? "WHERE o.state IN ('draft','sent','confirmed','partially_received')" : "";
        $sql = $this->buildSelectJoin() . " {$where} ORDER BY o.id DESC";
        $stmt = $MsaDB->db->prepare($sql);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[] = new PurchaseOrder($MsaDB, $row);
        }
        return $result;
    }

    public function getByVendor(int $vendorId): array {
        $MsaDB = $this->MsaDB;
        $sql = $this->buildSelectJoin() . " WHERE o.vendor_id = ? ORDER BY o.id DESC";
        $stmt = $MsaDB->db->prepare($sql);
        $stmt->execute([$vendorId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[] = new PurchaseOrder($MsaDB, $row);
        }
        return $result;
    }

    public function getByState(string $state): array {
        $MsaDB = $this->MsaDB;
        $sql = $this->buildSelectJoin() . " WHERE o.state = ? ORDER BY o.id DESC";
        $stmt = $MsaDB->db->prepare($sql);
        $stmt->execute([$state]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[] = new PurchaseOrder($MsaDB, $row);
        }
        return $result;
    }

    public function getByRfq(int $rfqId): ?PurchaseOrder {
        $MsaDB = $this->MsaDB;
        $sql = $this->buildSelectJoin() . " WHERE o.converted_from_rfq_id = ? ORDER BY o.id DESC LIMIT 1";
        $stmt = $MsaDB->db->prepare($sql);
        $stmt->execute([$rfqId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : new PurchaseOrder($MsaDB, $row);
    }

    public function create(int $vendorId, int $createdBy, ?int $convertedFromRfqId = null, ?string $expectedDeliveryDate = null, ?string $comment = null): int {
        $MsaDB = $this->MsaDB;
        if ($vendorId <= 0) throw new \InvalidArgumentException("PurchaseOrder vendorId must be positive.");
        if ($createdBy <= 0) throw new \InvalidArgumentException("PurchaseOrder createdBy must be positive.");
        $expectedDeliveryDate = self::normaliseDate($expectedDeliveryDate);
        return $MsaDB->insert(
            'purchase__order',
            ['vendor_id', 'state', 'converted_from_rfq_id', 'expected_delivery_date', 'created_by', 'comment'],
            [$vendorId, 'draft', $convertedFromRfqId, $expectedDeliveryDate, $createdBy, $comment]
        );
    }

    public function update(int $id, int $vendorId, ?string $expectedDeliveryDate, ?string $comment): bool {
        $MsaDB = $this->MsaDB;
        if ($vendorId <= 0) throw new \InvalidArgumentException("PurchaseOrder vendorId must be positive.");
        $expectedDeliveryDate = self::normaliseDate($expectedDeliveryDate);
        return $MsaDB->update(
            'purchase__order',
            [
                'vendor_id'              => $vendorId,
                'expected_delivery_date' => $expectedDeliveryDate,
                'comment'                => $comment,
            ],
            'id',
            $id
        );
    }

    public function setState(int $id, string $state): bool {
        $valid = ['draft','sent','confirmed','partially_received','received','cancelled'];
        if (!in_array($state, $valid, true)) {
            throw new \InvalidArgumentException("PurchaseOrder state must be one of: " . implode(', ', $valid));
        }
        $MsaDB = $this->MsaDB;
        return $MsaDB->update(
            'purchase__order',
            ['state' => $state],
            'id',
            $id
        );
    }

    public function setPoNumber(int $id, string $number): bool {
        $MsaDB = $this->MsaDB;
        return $MsaDB->update(
            'purchase__order',
            ['po_number' => $number],
            'id',
            $id
        );
    }

    public function setVendorPoNumber(int $id, string $number): bool {
        $MsaDB = $this->MsaDB;
        return $MsaDB->update(
            'purchase__order',
            ['vendor_po_number' => $number],
            'id',
            $id
        );
    }

    public function setConfirmedAt(int $id, string $confirmedAt): bool {
        $MsaDB = $this->MsaDB;
        return $MsaDB->update(
            'purchase__order',
            ['confirmed_at' => $confirmedAt],
            'id',
            $id
        );
    }

    public function setSentAt(int $id, string $sentAt): bool {
        $MsaDB = $this->MsaDB;
        return $MsaDB->update(
            'purchase__order',
            ['sent_at' => $sentAt],
            'id',
            $id
        );
    }

    public function delete(int $id): bool {
        $MsaDB = $this->MsaDB;
        $stmt = $MsaDB->db->prepare("DELETE FROM `purchase__order` WHERE id = ?");
        return $stmt->execute([$id]);
    }

    private static function normaliseDate(?string $value): ?string {
        if ($value === null || $value === '') return null;
        $d = \DateTime::createFromFormat('Y-m-d', $value);
        if ($d === false) {
            throw new \InvalidArgumentException("Date must be in YYYY-MM-DD format.");
        }
        return $d->format('Y-m-d');
    }
}
