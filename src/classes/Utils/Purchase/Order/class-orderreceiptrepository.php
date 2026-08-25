<?php
namespace Atte\Utils\Purchase\Order;

use Atte\DB\MsaDB;

class OrderReceiptRepository {
    private $MsaDB;

    public function __construct(MsaDB $MsaDB){
        $this->MsaDB = $MsaDB;
    }

    private function buildSelectJoin(): string {
        return "SELECT r.id,
                       r.po_id AS poId,
                       r.document_number AS documentNumber,
                       r.received_by AS receivedBy,
                       r.received_at AS receivedAt,
                       r.comment,
                       po.po_number AS poNumber,
                       v.name AS vendorName,
                       CONCAT(u.name, ' ', u.surname) AS receivedByName
                FROM `purchase__order_receipt` r
                LEFT JOIN `purchase__order` po ON r.po_id = po.id
                LEFT JOIN `list__vendor` v  ON po.vendor_id = v.id
                LEFT JOIN `user` u           ON r.received_by = u.user_id";
    }

    public function getById(int $id): ?OrderReceipt {
        $MsaDB = $this->MsaDB;
        $sql = $this->buildSelectJoin() . " WHERE r.id = ?";
        $stmt = $MsaDB->db->prepare($sql);
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : new OrderReceipt($row);
    }

    public function getAll(bool $onlyActive = false): array {
        $MsaDB = $this->MsaDB;
        $where = $onlyActive ? "WHERE po.state != 'cancelled'" : "";
        $sql = $this->buildSelectJoin()
            . " {$where}"
            . " ORDER BY r.received_at DESC, r.id DESC";
        $stmt = $MsaDB->db->prepare($sql);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[] = new OrderReceipt($row);
        }
        return $result;
    }

    public function getByPo(int $poId): array {
        $MsaDB = $this->MsaDB;
        $sql = $this->buildSelectJoin()
            . " WHERE r.po_id = ?"
            . " ORDER BY r.received_at DESC, r.id DESC";
        $stmt = $MsaDB->db->prepare($sql);
        $stmt->execute([$poId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[] = new OrderReceipt($row);
        }
        return $result;
    }

    public function getByReceivedBy(int $userId): array {
        $MsaDB = $this->MsaDB;
        $sql = $this->buildSelectJoin()
            . " WHERE r.received_by = ?"
            . " ORDER BY r.received_at DESC, r.id DESC";
        $stmt = $MsaDB->db->prepare($sql);
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[] = new OrderReceipt($row);
        }
        return $result;
    }

    public function create(int $poId, int $receivedBy, ?string $documentNumber = null, ?string $comment = null): int {
        $MsaDB = $this->MsaDB;
        if ($poId <= 0)      throw new \InvalidArgumentException("OrderReceipt poId must be positive.");
        if ($receivedBy <= 0) throw new \InvalidArgumentException("OrderReceipt receivedBy must be positive.");
        if ($documentNumber !== null) {
            $documentNumber = trim($documentNumber);
            if ($documentNumber === '') $documentNumber = null;
        }
        if ($comment !== null) {
            $comment = trim($comment);
            if ($comment === '') $comment = null;
        }
        return $MsaDB->insert(
            'purchase__order_receipt',
            ['po_id', 'document_number', 'received_by', 'comment'],
            [$poId, $documentNumber, $receivedBy, $comment]
        );
    }

    public function delete(int $id): bool {
        $MsaDB = $this->MsaDB;
        // Caller is expected to verify the parent PO state. We do one final guard
        // here so a fully-received PO can never lose its terminal-receipt trail.
        $stmt = $MsaDB->db->prepare(
            "SELECT po.state
             FROM `purchase__order_receipt` r
             JOIN `purchase__order` po ON r.po_id = po.id
             WHERE r.id = ?"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return false;
        }
        if ($row['state'] === 'received') {
            throw new \LogicException("Cannot delete a receipt once the PO has been fully received");
        }
        $del = $MsaDB->db->prepare("DELETE FROM `purchase__order_receipt` WHERE id = ?");
        return $del->execute([$id]);
    }
}
