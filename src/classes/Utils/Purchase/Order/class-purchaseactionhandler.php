<?php
namespace Atte\Utils\Purchase\Order;

use Atte\DB\MsaDB;

class PurchaseActionHandler {
    private $MsaDB;

    public function __construct(MsaDB $MsaDB){
        $this->MsaDB = $MsaDB;
    }

    public function allocateDocumentNumber(string $type, ?int $year = null): string {
        $valid = ['rfq','po'];
        if (!in_array($type, $valid, true)) {
            throw new \InvalidArgumentException("type must be one of: " . implode(', ', $valid));
        }
        if ($year === null) {
            $year = (int)date('Y');
        }

        $MsaDB = $this->MsaDB;

        // If the caller has already begun a transaction we participate in it
        // (the FOR UPDATE below relies on that). If not, we open our own so
        // the read-after-write is consistent.
        $ownedTransaction = !$MsaDB->db->inTransaction();
        if ($ownedTransaction) {
            $MsaDB->db->beginTransaction();
        }

        try {
            $stmt = $MsaDB->db->prepare(
                "INSERT INTO `purchase__number_counter` (`year`, `type`, `last_value`)
                 VALUES (?, ?, 1)
                 ON DUPLICATE KEY UPDATE `last_value` = `last_value` + 1"
            );
            $stmt->execute([$year, $type]);

            $stmt = $MsaDB->db->prepare(
                "SELECT `last_value` FROM `purchase__number_counter`
                 WHERE `year` = ? AND `type` = ?
                 FOR UPDATE"
            );
            $stmt->execute([$year, $type]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            $n = $row ? (int)$row['last_value'] : 1;

            if ($ownedTransaction) {
                $MsaDB->db->commit();
            }
            return sprintf('%s/%04d/%04d', strtoupper($type), $year, $n);
        } catch (\Throwable $e) {
            if ($ownedTransaction && $MsaDB->db->inTransaction()) {
                $MsaDB->db->rollBack();
            }
            throw $e;
        }
    }

    public function createDocument(string $type, int $vendorId, int $userId): int {
        $valid = ['rfq','po'];
        if (!in_array($type, $valid, true)) {
            throw new \InvalidArgumentException("type must be one of: " . implode(', ', $valid));
        }
        if ($type === 'po') {
            throw new \LogicException("PO creation is implemented in P3");
        }
        if ($vendorId <= 0) throw new \InvalidArgumentException("vendorId must be positive.");
        if ($userId   <= 0) throw new \InvalidArgumentException("userId must be positive.");

        $MsaDB = $this->MsaDB;
        $year  = (int)date('Y');

        $MsaDB->db->beginTransaction();
        try {
            $number = $this->allocateDocumentNumber('rfq', $year);

            $repo = new RFQRepository($MsaDB);
            $id   = $repo->create($vendorId, $userId, null, null);
            $repo->setRfqNumber($id, $number);

            $MsaDB->db->commit();
            return $id;
        } catch (\Throwable $e) {
            if ($MsaDB->db->inTransaction()) {
                $MsaDB->db->rollBack();
            }
            throw $e;
        }
    }

    public function computeLastKnownPrice(int $vendorPartId, ?string $currency = null): ?float {
        $MsaDB = $this->MsaDB;
        try {
            $sql = "SELECT `unit_price` FROM `purchase__order_item`
                    WHERE `vendor_part_id` = ?
                      AND `unit_price` IS NOT NULL"
                  . ($currency !== null && $currency !== '' ? " AND `currency` = ?" : "")
                  . " ORDER BY `id` DESC LIMIT 1";
            $stmt = $MsaDB->db->prepare($sql);
            $stmt->execute(
                $currency !== null && $currency !== ''
                    ? [$vendorPartId, $currency]
                    : [$vendorPartId]
            );
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $row === false ? null : (float)$row['unit_price'];
        } catch (\PDOException $e) {
            // purchase__order_item doesn't exist yet (P3) — treat as no prior data.
            return null;
        }
    }

    public function sendRfq(int $rfqId, int $userId): bool {
        $MsaDB = $this->MsaDB;
        $repo = new RFQRepository($MsaDB);
        $itemRepo = new RFQItemRepository($MsaDB);

        $rfq = $repo->getById($rfqId);
        if ($rfq === null) {
            throw new \LogicException("RFQ not found");
        }
        if ($rfq->state !== 'draft') {
            throw new \LogicException("RFQ is not in draft state");
        }
        if ($itemRepo->countByRfq($rfqId) === 0) {
            throw new \LogicException("Cannot send RFQ with no line items");
        }

        $MsaDB->db->beginTransaction();
        try {
            $repo->setState($rfqId, 'sent');
            $repo->setSentAt($rfqId, date('Y-m-d H:i:s'));
            $MsaDB->db->commit();
            return true;
        } catch (\Throwable $e) {
            if ($MsaDB->db->inTransaction()) {
                $MsaDB->db->rollBack();
            }
            throw $e;
        }
    }

    public function cancelRfq(int $rfqId, int $userId): bool {
        $MsaDB = $this->MsaDB;
        $repo = new RFQRepository($MsaDB);

        $rfq = $repo->getById($rfqId);
        if ($rfq === null) {
            throw new \LogicException("RFQ not found");
        }
        if (in_array($rfq->state, ['cancelled','converted'], true)) {
            throw new \LogicException("RFQ is already in a terminal state");
        }

        $MsaDB->db->beginTransaction();
        try {
            $repo->setState($rfqId, 'cancelled');
            $MsaDB->db->commit();
            return true;
        } catch (\Throwable $e) {
            if ($MsaDB->db->inTransaction()) {
                $MsaDB->db->rollBack();
            }
            throw $e;
        }
    }

    public function createPoFromRfq(int $rfqId, int $userId): int {
        throw new \LogicException("Implemented in P3");
    }
}
