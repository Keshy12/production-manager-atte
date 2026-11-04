<?php
namespace Atte\Utils\Purchase\Order;

use Atte\DB\MsaDB;

/**
 * Nagłówki dostaw (`purchase__delivery`). Pozycje i przydziały obsługuje
 * DeliveryLineRepository.
 */
class DeliveryRepository {
    private $MsaDB;

    /** Kolumny nagłówka, które wolno ustawiać przez create()/updateHeader(). */
    private const HEADER_COLUMNS = [
        'document_type', 'vendor_document_number', 'document_date', 'currency',
        'default_sub_magazine_id', 'received_at', 'comment',
    ];

    public function __construct(MsaDB $MsaDB){
        $this->MsaDB = $MsaDB;
    }

    public function buildSelectJoin(): string {
        return "SELECT d.id,
                       d.vendor_id AS vendorId,
                       d.state,
                       d.document_type AS documentType,
                       d.vendor_document_number AS vendorDocumentNumber,
                       d.document_date AS documentDate,
                       d.grn_number AS grnNumber,
                       d.currency,
                       d.default_sub_magazine_id AS defaultSubMagazineId,
                       d.received_at AS receivedAt,
                       d.transfer_group_id AS transferGroupId,
                       d.created_by AS createdBy,
                       d.posted_by AS postedBy,
                       d.created_at AS createdAt,
                       d.posted_at AS postedAt,
                       d.updated_at AS updatedAt,
                       d.comment,
                       v.name AS vendorName,
                       CONCAT(u.name, ' ', u.surname) AS createdByName,
                       CONCAT(pu.name, ' ', pu.surname) AS postedByName,
                       m.sub_magazine_name AS defaultSubMagazineName
                FROM `purchase__delivery` d
                LEFT JOIN `list__vendor` v    ON d.vendor_id = v.id
                LEFT JOIN `user` u            ON d.created_by = u.user_id
                LEFT JOIN `user` pu           ON d.posted_by = pu.user_id
                LEFT JOIN `magazine__list` m  ON d.default_sub_magazine_id = m.sub_magazine_id";
    }

    private function hydrateAll(array $rows): array {
        $result = [];
        foreach ($rows as $row) {
            $result[] = new Delivery($row);
        }
        return $result;
    }

    public function getById(int $id): ?Delivery {
        $stmt = $this->MsaDB->db->prepare($this->buildSelectJoin() . " WHERE d.id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : new Delivery($row);
    }

    /**
     * Blokuje wiersz dostawy (SELECT ... FOR UPDATE) i zwraca świeży stan.
     * Wywołać wewnątrz transakcji. Najpierw blokada na samej tabeli
     * dostaw, potem zwykły SELECT z JOINami (żeby nie blokować `user`,
     * `list__vendor` itd.).
     */
    public function lockById(int $id): ?Delivery {
        $lock = $this->MsaDB->db->prepare("SELECT id FROM `purchase__delivery` WHERE id = ? FOR UPDATE");
        $lock->execute([$id]);
        if ($lock->fetchColumn() === false) {
            return null;
        }
        return $this->getById($id);
    }

    /** Robocze dostawy wybranego dostawcy (najnowsze pierwsze). */
    public function getDraftsByVendor(int $vendorId): array {
        $stmt = $this->MsaDB->db->prepare(
            $this->buildSelectJoin() . " WHERE d.vendor_id = ? AND d.state = 'draft' ORDER BY d.updated_at DESC, d.id DESC"
        );
        $stmt->execute([$vendorId]);
        return $this->hydrateAll($stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** Wszystkie robocze dostawy (zakładka „Robocze"). */
    public function getDrafts(): array {
        $stmt = $this->MsaDB->db->prepare(
            $this->buildSelectJoin() . " WHERE d.state = 'draft' ORDER BY d.updated_at DESC, d.id DESC"
        );
        $stmt->execute();
        return $this->hydrateAll($stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Historia: dostawy zaksięgowane i anulowane (strona wierszy, jak
     * OrderReceiptRepository::getPage()). Szkice nie wchodzą do historii.
     */
    public function getPage(int $page, int $perPage): array {
        $page    = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset  = ($page - 1) * $perPage;
        $stmt = $this->MsaDB->db->prepare(
            $this->buildSelectJoin()
            . " WHERE d.state IN ('posted','cancelled')"
            . " ORDER BY COALESCE(d.received_at, d.created_at) DESC, d.id DESC"
            . " LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute();
        return $this->hydrateAll($stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function countHistory(): int {
        $stmt = $this->MsaDB->db->prepare(
            "SELECT COUNT(*) FROM `purchase__delivery` WHERE state IN ('posted','cancelled')"
        );
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    /**
     * Lista dostaw dla UI (zakładki Robocze / Historia) — płaskie wiersze
     * z liczbą pozycji i numerami powiązanych PO (z przydziałów).
     *
     * @param string $state 'draft' | 'posted' (zaksięgowane + anulowane) | 'all'
     * @param int    $limit maks. liczba wierszy (1–500)
     * @return array<int, array<string, mixed>>
     */
    public function listForUi(string $state, int $limit = 200): array {
        $limit = max(1, min(500, $limit));
        if ($state === 'draft') {
            $where = "d.state = 'draft'";
            $order = "d.updated_at DESC, d.id DESC";
        } elseif ($state === 'posted') {
            $where = "d.state IN ('posted','cancelled')";
            $order = "COALESCE(d.received_at, d.created_at) DESC, d.id DESC";
        } else {
            $where = "1=1";
            $order = "COALESCE(d.posted_at, d.updated_at) DESC, d.id DESC";
        }
        $stmt = $this->MsaDB->db->prepare(
            "SELECT d.id, d.vendor_id, v.name AS vendor_name, d.state, d.document_type,
                    d.vendor_document_number, d.document_date, d.grn_number, d.received_at,
                    d.created_at, d.posted_at,
                    CONCAT(u.name, ' ', u.surname) AS created_by_name,
                    (SELECT COUNT(*) FROM `purchase__delivery_line` l WHERE l.delivery_id = d.id) AS line_count,
                    (SELECT GROUP_CONCAT(DISTINCT po.po_number ORDER BY po.po_number SEPARATOR '||')
                       FROM `purchase__delivery_line` l
                       JOIN `purchase__delivery_allocation` a ON a.delivery_line_id = l.id
                       JOIN `purchase__order_item` oi ON oi.id = a.po_item_id
                       JOIN `purchase__order` po ON po.id = oi.po_id
                      WHERE l.delivery_id = d.id) AS po_numbers
               FROM `purchase__delivery` d
               LEFT JOIN `list__vendor` v ON d.vendor_id = v.id
               LEFT JOIN `user` u ON d.created_by = u.user_id
              WHERE {$where}
              ORDER BY {$order}
              LIMIT {$limit}"
        );
        $stmt->execute();
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'id'                     => (int)$r['id'],
                'vendor_id'              => (int)$r['vendor_id'],
                'vendor_name'            => $r['vendor_name'],
                'state'                  => $r['state'],
                'document_type'          => $r['document_type'],
                'vendor_document_number' => $r['vendor_document_number'],
                'document_date'          => $r['document_date'],
                'grn_number'             => $r['grn_number'],
                'received_at'            => $r['received_at'],
                'line_count'             => (int)$r['line_count'],
                'po_numbers'             => ($r['po_numbers'] !== null && $r['po_numbers'] !== '')
                    ? explode('||', $r['po_numbers']) : [],
                'created_by_name'        => $r['created_by_name'],
                'created_at'             => $r['created_at'],
                'posted_at'              => $r['posted_at'],
            ];
        }
        return $out;
    }

    /**
     * Czy ten dostawca ma już dostawę (zaksięgowaną lub roboczą) o tym
     * numerze dokumentu? Miękkie ostrzeżenie — nie twarde UNIQUE.
     */
    public function findByVendorDocumentNumber(int $vendorId, string $number, int $excludeId = 0): array {
        $stmt = $this->MsaDB->db->prepare(
            $this->buildSelectJoin()
            . " WHERE d.vendor_id = ? AND d.vendor_document_number = ? AND d.state <> 'cancelled' AND d.id <> ?"
            . " ORDER BY d.id DESC"
        );
        $stmt->execute([$vendorId, $number, $excludeId]);
        return $this->hydrateAll($stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Tworzy nagłówek w stanie `draft`. $header: kolumna => wartość, tylko
     * klucze z listy HEADER_COLUMNS (znormalizowane przez handler).
     */
    public function create(int $vendorId, int $createdBy, array $header = []): int {
        if ($vendorId <= 0)  throw new \InvalidArgumentException("Delivery vendorId must be positive.");
        if ($createdBy <= 0) throw new \InvalidArgumentException("Delivery createdBy must be positive.");

        $columns = ['vendor_id', 'state', 'created_by'];
        $values  = [$vendorId, 'draft', $createdBy];
        foreach ($header as $col => $val) {
            if (!in_array($col, self::HEADER_COLUMNS, true)) continue;
            $columns[] = $col;
            $values[]  = $val;
        }
        return (int)$this->MsaDB->insert('purchase__delivery', $columns, $values);
    }

    /** Aktualizuje wskazane kolumny nagłówka (draft). */
    public function updateHeader(int $id, array $header): bool {
        $set = [];
        $values = [];
        foreach ($header as $col => $val) {
            if (!in_array($col, self::HEADER_COLUMNS, true)) continue;
            $set[] = "`{$col}` = ?";
            $values[] = $val;
        }
        $set[] = "`updated_at` = CURRENT_TIMESTAMP()";
        $values[] = $id;
        $stmt = $this->MsaDB->db->prepare("UPDATE `purchase__delivery` SET " . implode(', ', $set) . " WHERE id = ?");
        return $stmt->execute($values);
    }

    /** Dotyka `updated_at` (zmiana pozycji szkicu). */
    public function touch(int $id): void {
        $stmt = $this->MsaDB->db->prepare("UPDATE `purchase__delivery` SET `updated_at` = CURRENT_TIMESTAMP() WHERE id = ?");
        $stmt->execute([$id]);
    }

    public function markPosted(int $id, string $grnNumber, string $receivedAt, int $transferGroupId, int $postedBy): bool {
        $stmt = $this->MsaDB->db->prepare(
            "UPDATE `purchase__delivery`
                SET `state` = 'posted', `grn_number` = ?, `received_at` = ?, `transfer_group_id` = ?,
                    `posted_by` = ?, `posted_at` = CURRENT_TIMESTAMP(), `updated_at` = CURRENT_TIMESTAMP()
              WHERE id = ?"
        );
        return $stmt->execute([$grnNumber, $receivedAt, $transferGroupId, $postedBy, $id]);
    }

    public function markCancelled(int $id): bool {
        $stmt = $this->MsaDB->db->prepare(
            "UPDATE `purchase__delivery` SET `state` = 'cancelled', `updated_at` = CURRENT_TIMESTAMP() WHERE id = ?"
        );
        return $stmt->execute([$id]);
    }

    /** Kasuje szkic (CASCADE usuwa pozycje i przydziały). Tylko stan draft. */
    public function deleteDraft(int $id): bool {
        $stmt = $this->MsaDB->db->prepare("DELETE FROM `purchase__delivery` WHERE id = ? AND state = 'draft'");
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }
}
