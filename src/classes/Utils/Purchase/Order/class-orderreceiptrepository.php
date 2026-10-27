<?php
namespace Atte\Utils\Purchase\Order;

use Atte\DB\MsaDB;

class OrderReceiptRepository {
    private $MsaDB;

    public function __construct(MsaDB $MsaDB){
        $this->MsaDB = $MsaDB;
    }

    /**
     * Bazowy SELECT + JOINy dla tabeli przyjęć. Współdzielony przez
     * wszystkie metody odczytujące oraz przez endpoint AJAX historii
     * (components/purchases/receipts/receipts-history.php), który musi
     * złożyć dodatkowe WHERE dla filtra wyszukiwarki / zakresu dat —
     * stąd publiczne, a nie private.
     */
    public function buildSelectJoin(): string {
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

    /**
     * Strona wierszy przyjęć (0-indexed wewnętrznie — $page min 1
     * z zewnątrz, offset liczony jako ($page-1)*$perPage). Kolejność
     * i WHERE identyczne jak w getAll(), żeby strony nie „przeskakiwały"
     * między wywołaniami. $perPage cap na 100 — endpoint paginacji
     * dostaje validate przed przekazaniem, ale dla pewności pilnuje też
     * tutaj, na wypadek użycia przez inne wywołania.
     */
    public function getPage(bool $onlyActive, int $page, int $perPage): array {
        $MsaDB = $this->MsaDB;
        $page    = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset  = ($page - 1) * $perPage;
        $where   = $onlyActive ? "WHERE po.state != 'cancelled'" : "";
        $sql = $this->buildSelectJoin()
            . " {$where}"
            . " ORDER BY r.received_at DESC, r.id DESC"
            . " LIMIT {$perPage} OFFSET {$offset}";
        $stmt = $MsaDB->db->prepare($sql);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[] = new OrderReceipt($row);
        }
        return $result;
    }

    /**
     * Całkowita liczba wierszy dla danego zestawu (ten sam WHERE co
     * getAll/getPage). Endpoint paginacji paruje to z getPage(), żeby
     * wiedzieć ile jest stron. Zwraca int — fetchColumn na COUNT(*).
     */
    public function countAll(bool $onlyActive = false): int {
        $MsaDB = $this->MsaDB;
        $where = $onlyActive ? "WHERE po.state != 'cancelled'" : "";
        $sql = "SELECT COUNT(*)
                FROM `purchase__order_receipt` r
                LEFT JOIN `purchase__order` po ON r.po_id = po.id
                {$where}";
        $stmt = $MsaDB->db->prepare($sql);
        $stmt->execute();
        return (int)$stmt->fetchColumn();
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

    /**
     * Najnowsze przyjęcie danego zamówienia (albo null, gdy PO nie ma
     * jeszcze żadnego przyjęcia). Kolejność identyczna jak w getByPo()
     * (received_at DESC, id DESC) — bierzemy tylko pierwszy wiersz.
     * Używane przez przycisk „Otwórz przyjęcia" w nagłówku zamówienia
     * (Admin/Purchase/Documents/documents-edit.php).
     */
    public function getLatestByPo(int $poId): ?OrderReceipt {
        $MsaDB = $this->MsaDB;
        $sql = $this->buildSelectJoin()
            . " WHERE r.po_id = ?"
            . " ORDER BY r.received_at DESC, r.id DESC"
            . " LIMIT 1";
        $stmt = $MsaDB->db->prepare($sql);
        $stmt->execute([$poId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : new OrderReceipt($row);
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

    /**
     * Create a receipt header.
     *
     * `$receivedAt` overrides the column's CURRENT_TIMESTAMP default when
     * supplied (e.g. when the operator backdates a delivery). When null the
     * DEFAULT CURRENT_TIMESTAMP() on `received_at` fires, so MySQL stamps
     * the insert time. Newer callers (the goods-receiving UI) pass an
     * explicit datetime so the receipt date and the inventory__parts
     * ledger date stay in sync.
     */
    public function create(int $poId, int $receivedBy, ?string $documentNumber = null, ?string $comment = null, ?string $receivedAt = null): int {
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
        if ($receivedAt !== null) {
            $receivedAt = trim($receivedAt);
            if ($receivedAt === '') $receivedAt = null;
        }
        // Build the column/value lists dynamically so passing null on
        // $receivedAt keeps the existing DEFAULT CURRENT_TIMESTAMP()
        // behaviour for older callers (backward compatible).
        $columns = ['po_id', 'document_number', 'received_by', 'comment'];
        $values  = [$poId, $documentNumber, $receivedBy, $comment];
        if ($receivedAt !== null) {
            $columns[] = 'received_at';
            $values[]  = $receivedAt;
        }
        return $MsaDB->insert('purchase__order_receipt', $columns, $values);
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
