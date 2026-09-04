<?php
namespace Atte\Utils\Purchase\Order;

use Atte\DB\MsaDB;

class RFQRepository {
    private $MsaDB;

    public function __construct(MsaDB $MsaDB){
        $this->MsaDB = $MsaDB;
    }

    private function buildSelectJoin(): string {
        return "SELECT r.id,
                       r.vendor_id AS vendorId,
                       r.state,
                       r.rfq_number AS rfqNumber,
                       r.expected_reply_date AS expectedReplyDate,
                       r.sent_at AS sentAt,
                       r.created_by AS createdBy,
                       r.comment,
                       r.created_at AS createdAt,
                       r.updated_at AS updatedAt,
                       v.name AS vendorName,
                       TRIM(CONCAT(u.name, ' ', u.surname)) AS createdByName
                FROM `purchase__rfq` r
                LEFT JOIN `list__vendor` v ON r.vendor_id = v.id
                LEFT JOIN `user` u        ON r.created_by = u.user_id";
    }

    public function getById(int $id): ?RFQ {
        $MsaDB = $this->MsaDB;
        $sql = $this->buildSelectJoin() . " WHERE r.id = ?";
        $stmt = $MsaDB->db->prepare($sql);
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : new RFQ($row);
    }

    public function getAll(bool $onlyActive = false): array {
        $MsaDB = $this->MsaDB;
        $where = $onlyActive ? "WHERE r.state IN ('draft','sent','responded')" : "";
        $sql = $this->buildSelectJoin() . " {$where} ORDER BY r.id DESC";
        $stmt = $MsaDB->db->prepare($sql);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[] = new RFQ($row);
        }
        return $result;
    }

    public function getByVendor(int $vendorId): array {
        $MsaDB = $this->MsaDB;
        $sql = $this->buildSelectJoin() . " WHERE r.vendor_id = ? ORDER BY r.id DESC";
        $stmt = $MsaDB->db->prepare($sql);
        $stmt->execute([$vendorId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[] = new RFQ($row);
        }
        return $result;
    }

    public function getByState(string $state): array {
        $MsaDB = $this->MsaDB;
        $sql = $this->buildSelectJoin() . " WHERE r.state = ? ORDER BY r.id DESC";
        $stmt = $MsaDB->db->prepare($sql);
        $stmt->execute([$state]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[] = new RFQ($row);
        }
        return $result;
    }

    public function create(int $vendorId, int $createdBy, ?string $expectedReplyDate = null, ?string $comment = null): int {
        $MsaDB = $this->MsaDB;
        if ($vendorId <= 0) throw new \InvalidArgumentException("RFQ vendorId must be positive.");
        if ($createdBy <= 0) throw new \InvalidArgumentException("RFQ createdBy must be positive.");
        $expectedReplyDate = self::normaliseDate($expectedReplyDate);
        return $MsaDB->insert(
            'purchase__rfq',
            ['vendor_id', 'state', 'expected_reply_date', 'created_by', 'comment'],
            [$vendorId, 'draft', $expectedReplyDate, $createdBy, $comment]
        );
    }

    public function update(int $id, int $vendorId, ?string $expectedReplyDate, ?string $comment): bool {
        $MsaDB = $this->MsaDB;
        if ($vendorId <= 0) throw new \InvalidArgumentException("RFQ vendorId must be positive.");
        $expectedReplyDate = self::normaliseDate($expectedReplyDate);
        return $MsaDB->update(
            'purchase__rfq',
            [
                'vendor_id'           => $vendorId,
                'expected_reply_date' => $expectedReplyDate,
                'comment'             => $comment,
            ],
            'id',
            $id
        );
    }

    public function setState(int $id, string $state): bool {
        $valid = ['draft','sent','responded','cancelled','converted'];
        if (!in_array($state, $valid, true)) {
            throw new \InvalidArgumentException("RFQ state must be one of: " . implode(', ', $valid));
        }
        $MsaDB = $this->MsaDB;
        return $MsaDB->update(
            'purchase__rfq',
            ['state' => $state],
            'id',
            $id
        );
    }

    public function setSentAt(int $id, string $sentAt): bool {
        $MsaDB = $this->MsaDB;
        return $MsaDB->update(
            'purchase__rfq',
            ['sent_at' => $sentAt],
            'id',
            $id
        );
    }

    public function setRfqNumber(int $id, string $number): bool {
        $MsaDB = $this->MsaDB;
        return $MsaDB->update(
            'purchase__rfq',
            ['rfq_number' => $number],
            'id',
            $id
        );
    }

    public function delete(int $id): bool {
        $MsaDB = $this->MsaDB;
        $stmt = $MsaDB->db->prepare("DELETE FROM `purchase__rfq` WHERE id = ?");
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
