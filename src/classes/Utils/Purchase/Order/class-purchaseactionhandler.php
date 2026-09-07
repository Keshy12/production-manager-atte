<?php
namespace Atte\Utils\Purchase\Order;

use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Master\VendorPartRepository;
use Atte\Utils\TransferGroupManager;

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
        if ($vendorId <= 0) throw new \InvalidArgumentException("vendorId must be positive.");
        if ($userId   <= 0) throw new \InvalidArgumentException("userId must be positive.");

        $MsaDB = $this->MsaDB;
        $year  = (int)date('Y');

        $MsaDB->db->beginTransaction();
        try {
            if ($type === 'rfq') {
                $number = $this->allocateDocumentNumber('rfq', $year);
                $repo   = new RFQRepository($MsaDB);
                $id     = $repo->create($vendorId, $userId, null, null);
                $repo->setRfqNumber($id, $number);
                $MsaDB->db->commit();
                return $id;
            }
            // type === 'po'
            $number = $this->allocateDocumentNumber('po', $year);
            $repo   = new PurchaseOrderRepository($MsaDB);
            $id     = $repo->create($vendorId, $userId);
            $repo->setPoNumber($id, $number);
            $MsaDB->db->commit();
            return $id;
        } catch (\Throwable $e) {
            if ($MsaDB->db->inTransaction()) {
                $MsaDB->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Add a single line item to an existing draft RFQ or PO. Thin wrapper
     * around the per-type item repository that lets external callers
     * (cart-create-document.php, the Koszyk UI) stay agnostic of which
     * repo to instantiate — picked_pack_size flows through here so the
     * cart UI can persist the operator-chosen pack size on the line.
     *
     * Expected keys in $itemData:
     *   - vendor_part_id      int    (required, > 0)
     *   - quantity            float  (required, > 0)
     *   - quantity_unit_id    int    (required, > 0)
     *   - unit_price          float|null (optional, null/blank allowed)
     *   - currency            string (default 'PLN')
     *   - comment             string|null (optional)
     *   - picked_pack_size    float|null (optional, > 0 when provided)
     *
     * Joins any open transaction the caller started (caller owns
     * header+items commit semantics). Returns the new item id.
     */
    public function addItem(string $type, int $docId, array $itemData): int {
        $valid = ['rfq','po'];
        if (!in_array($type, $valid, true)) {
            throw new \InvalidArgumentException("type must be one of: " . implode(', ', $valid));
        }
        if ($docId <= 0) {
            throw new \InvalidArgumentException("docId must be positive.");
        }
        if (!is_array($itemData) || empty($itemData)) {
            throw new \InvalidArgumentException("itemData must be a non-empty array.");
        }

        $vpId        = (int)($itemData['vendor_part_id']   ?? 0);
        $qty         = (float)($itemData['quantity']        ?? 0);
        $unitId      = (int)($itemData['quantity_unit_id'] ?? 0);
        $unitPrice   = $itemData['unit_price']   ?? null;
        $currency    = (string)($itemData['currency']      ?? 'PLN');
        $comment     = $itemData['comment']      ?? null;
        $pickedPack  = $itemData['picked_pack_size'] ?? null;

        if ($vpId   <= 0) throw new \InvalidArgumentException("vendor_part_id must be positive.");
        if ($unitId <= 0) throw new \InvalidArgumentException("quantity_unit_id must be positive.");
        if ($qty    <= 0) throw new \InvalidArgumentException("quantity must be positive.");
        // unit_price may legitimately be null/blank for RFQs (target price).
        if ($unitPrice !== null && $unitPrice !== '' && is_numeric($unitPrice) === false) {
            throw new \InvalidArgumentException("unit_price must be numeric or null.");
        }
        if ($unitPrice === '' ) $unitPrice = null;
        if ($unitPrice !== null) $unitPrice = (float)$unitPrice;
        if ($pickedPack !== null && $pickedPack !== '' && is_numeric($pickedPack) === false) {
            throw new \InvalidArgumentException("picked_pack_size must be numeric or null.");
        }
        if ($pickedPack === '' ) $pickedPack = null;
        if ($pickedPack !== null) $pickedPack = (float)$pickedPack;

        $MsaDB = $this->MsaDB;
        if ($type === 'po') {
            $itemRepo = new PurchaseOrderItemRepository($MsaDB);
            return $itemRepo->create(
                $docId, $vpId, $qty, $unitId,
                $unitPrice === null ? 0.0 : (float)$unitPrice,
                $currency !== '' ? $currency : 'PLN',
                $comment,
                $pickedPack
            );
        }
        // 'rfq'
        $itemRepo = new RFQItemRepository($MsaDB);
        return $itemRepo->create(
            $docId, $vpId, $qty, $unitId,
            $unitPrice, // null ok for RFQs (target price, not negotiated)
            $currency !== '' ? $currency : 'PLN',
            $comment,
            $pickedPack
        );
    }

    /**
     * States in which a document (RFQ or PO) accepts new items, edits
     * to existing items, and deletions. Terminal states (cancelled,
     * converted for RFQ; cancelled, partially_received, received for
     * PO) reject all item-level mutations.
     *
     * Single source of truth for the state guard — the edit page, the
     * AJAX endpoints, and any future state-transition UI all branch on
     * this. Adding a new state means one place to update.
     *
     *   RFQ: editable until converted (the conversion to PO is the last
     *        write; once converted, no further edits).
     *   PO:  editable until confirmed — partial / full receipts freeze
     *        the line items so receipt history stays consistent. (See
     *        document-item-update.php for the quantity_received floor
     *        check on edits while partially_received.)
     */
    public static function allowedEditStates(string $type): array {
        switch ($type) {
            case 'rfq': return ['draft', 'sent', 'responded'];
            case 'po':  return ['draft', 'sent', 'confirmed'];
            default:
                throw new \InvalidArgumentException(
                    "type must be 'rfq' or 'po', got: {$type}"
                );
        }
    }

    /**
     * Returns the item repository for the given document type. Lets
     * callers (cart-create-document.php, the AJAX endpoints) stay
     * agnostic of which class backs each table — the type stays in
     * one place instead of leaking into every caller.
     */
    public function itemRepository(string $type) {
        switch ($type) {
            case 'rfq': return new RFQItemRepository($this->MsaDB);
            case 'po':  return new PurchaseOrderItemRepository($this->MsaDB);
            default:
                throw new \InvalidArgumentException(
                    "type must be 'rfq' or 'po', got: {$type}"
                );
        }
    }

    public function computeLastKnownPrice(int $vendorPartId, ?string $currency = null): ?float {
        $MsaDB = $this->MsaDB;
        try {
            $sql = "SELECT `unit_price` FROM `purchase__order_item`
                    WHERE `vendor_part_id` = ?
                      AND `unit_price` IS NOT NULL
                      AND `unit_price` > 0"
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
            // purchase__order_item doesn't exist (e.g. P3 not yet applied) — treat as no prior data.
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
        $MsaDB    = $this->MsaDB;
        $rfqRepo  = new RFQRepository($MsaDB);
        $itemRepo = new RFQItemRepository($MsaDB);
        $poRepo   = new PurchaseOrderRepository($MsaDB);
        $poItemRepo = new PurchaseOrderItemRepository($MsaDB);

        $rfq = $rfqRepo->getById($rfqId);
        if ($rfq === null) {
            throw new \LogicException("RFQ not found");
        }
        if (in_array($rfq->state, ['converted','cancelled'], true)) {
            throw new \LogicException("RFQ is already converted or cancelled");
        }
        $items = $itemRepo->getByRfq($rfqId);
        if (count($items) === 0) {
            throw new \LogicException("Cannot convert an RFQ with no line items");
        }

        $year = (int)date('Y');

        $MsaDB->db->beginTransaction();
        try {
            $number = $this->allocateDocumentNumber('po', $year);

            $poId = $poRepo->create($rfq->vendorId, $userId, $rfqId);
            $poRepo->setPoNumber($poId, $number);

            foreach ($items as $item) {
                $poItemRepo->create(
                    $poId,
                    $item->vendorPartId,
                    $item->quantity,
                    $item->quantityUnitId,
                    $item->unitPrice === null ? 0.0 : $item->unitPrice,
                    $item->currency,
                    $item->comment,
                    $item->pickedPackSize
                );
            }

            $rfqRepo->setState($rfqId, 'converted');

            $MsaDB->db->commit();
            return $poId;
        } catch (\Throwable $e) {
            if ($MsaDB->db->inTransaction()) {
                $MsaDB->db->rollBack();
            }
            throw $e;
        }
    }

    public function createReceipt(
        int $poId,
        int $receivedBy,
        array $items,
        ?string $documentNumber = null,
        ?string $receiptComment = null
    ): int {
        if ($poId <= 0)      throw new \InvalidArgumentException("poId must be positive.");
        if ($receivedBy <= 0) throw new \InvalidArgumentException("receivedBy must be positive.");
        if (empty($items))    throw new \InvalidArgumentException("At least one receipt item is required.");

        if ($documentNumber !== null) {
            $documentNumber = trim($documentNumber);
            if ($documentNumber === '') $documentNumber = null;
        }
        if ($receiptComment !== null) {
            $receiptComment = trim($receiptComment);
            if ($receiptComment === '') $receiptComment = null;
        }

        $MsaDB = $this->MsaDB;
        $poRepo     = new PurchaseOrderRepository($MsaDB);
        $poItemRepo = new PurchaseOrderItemRepository($MsaDB);
        $vpRepo     = new VendorPartRepository($MsaDB);
        $receiptRepo = new OrderReceiptRepository($MsaDB);
        $transferGroupManager = new TransferGroupManager($MsaDB);

        // 1. Load PO; state guard.
        $po = $poRepo->getById($poId);
        if ($po === null) {
            throw new \LogicException("PO not found");
        }
        if (!in_array($po->state, ['confirmed','partially_received'], true)) {
            throw new \LogicException("PO must be confirmed or partially_received to receive goods");
        }

        // 2. Load PO items; build id-indexed map for ownership + remaining math.
        $poItems = $poItemRepo->getByPo($poId);
        $poItemsById = [];
        $orderedTotal = 0.0;
        foreach ($poItems as $pi) {
            $poItemsById[$pi->id] = $pi;
            $orderedTotal += (float)$pi->quantity;
        }

        // 3. Validate each receipt item: ownership, qty > 0, magazine active, lenient 110% cap.
        $epsilon = 1e-6;
        $normalized = [];
        foreach ($items as $idx => $raw) {
            $poItemId    = (int)($raw['po_item_id']      ?? 0);
            $qtyRecvRaw  = $raw['quantity_received']   ?? null;
            $subMag      = (int)($raw['sub_magazine_id'] ?? 0);
            $lineComment = $raw['comment']              ?? null;

            if ($poItemId <= 0) {
                throw new \InvalidArgumentException("receipt item #{$idx}: missing po_item_id");
            }
            if (!isset($poItemsById[$poItemId])) {
                throw new \LogicException("receipt item #{$idx}: po_item_id {$poItemId} does not belong to PO {$poId}");
            }
            $poItem = $poItemsById[$poItemId];

            if (!is_numeric($qtyRecvRaw) || (float)$qtyRecvRaw <= 0) {
                throw new \InvalidArgumentException("receipt item #{$idx}: quantity_received must be > 0");
            }
            $qtyRecv = (float)$qtyRecvRaw;

            if ($subMag <= 0) {
                throw new \InvalidArgumentException("receipt item #{$idx}: sub_magazine_id must be positive");
            }

            // Active magazine check.
            $magStmt = $MsaDB->db->prepare("SELECT isActive FROM `magazine__list` WHERE sub_magazine_id = ?");
            $magStmt->execute([$subMag]);
            $magRow = $magStmt->fetch(\PDO::FETCH_ASSOC);
            if ($magRow === false) {
                throw new \LogicException("receipt item #{$idx}: sub_magazine_id {$subMag} does not exist");
            }
            if ((int)$magRow['isActive'] !== 1) {
                throw new \LogicException("receipt item #{$idx}: sub_magazine_id {$subMag} is not an active magazine");
            }

            // Lenient 110% over-delivery cap.
            $remaining = ((float)$poItem->quantity) - ((float)$poItem->quantityReceived);
            $maxAllowed = $remaining * 1.10 + $epsilon;
            $newRunning = ((float)$poItem->quantityReceived) + $qtyRecv;
            if ($newRunning > $maxAllowed) {
                throw new \LogicException(sprintf(
                    "Line %d (%s): over-delivery would push running total %.4f above max %.4f (remaining %.4f x 1.10)",
                    $poItem->id,
                    $poItem->vendorPartNo ?? ('vp#' . $poItem->vendorPartId),
                    $newRunning,
                    $maxAllowed,
                    $remaining
                ));
            }

            if ($lineComment !== null) {
                $lineComment = trim((string)$lineComment);
                if ($lineComment === '') $lineComment = null;
            }

            $normalized[] = [
                'poItemId'         => $poItemId,
                'poItem'           => $poItem,
                'quantityReceived' => $qtyRecv,
                'subMagazineId'    => $subMag,
                'comment'          => $lineComment,
            ];
        }

        // Transaction participation: join caller's transaction if already open,
        // otherwise own the transaction for the duration of this call.
        $ownedTransaction = !$MsaDB->db->inTransaction();
        if ($ownedTransaction) {
            $MsaDB->db->beginTransaction();
        }

        try {
            // 5.1 Resolve input_type_id (prefer a 'purchase%' row, fall back to id=1).
            $typeStmt = $MsaDB->db->prepare(
                "SELECT id FROM `inventory__input_type`
                 WHERE name LIKE 'purchase%' OR id = 1
                 ORDER BY (name LIKE 'purchase%') DESC, id ASC
                 LIMIT 1"
            );
            $typeStmt->execute();
            $typeRow = $typeStmt->fetch(\PDO::FETCH_ASSOC);
            if ($typeRow === false) {
                throw new \RuntimeException("No inventory__input_type row - please configure at least one input type");
            }
            $inputTypeId = (int)$typeRow['id'];

            // 5.2 Open the transfer group (used to attribute inventory__parts rows).
            $transferGroupId = $transferGroupManager->createTransferGroup(
                $receivedBy,
                'purchase_receipt',
                ['po_id' => $poId]
            );

            // 5.3 Create the receipt header.
            $receiptId = $receiptRepo->create($poId, $receivedBy, $documentNumber, $receiptComment);

            // 5.4 For each receipt item: insert receipt item, bump quantity_received,
            //     resolve parts_id, write a positive inventory__parts row.
            foreach ($normalized as $ni) {
                $poItem = $ni['poItem'];

                // a) receipt-item row
                $riCols = ['receipt_id', 'po_item_id', 'quantity_received', 'sub_magazine_id', 'comment'];
                $riVals = [
                    $receiptId,
                    $ni['poItemId'],
                    $ni['quantityReceived'],
                    $ni['subMagazineId'],
                    $ni['comment'],
                ];
                $riPlaceholders = array_fill(0, count($riCols), '?');
                $riSql = "INSERT INTO `purchase__order_receipt_item` (`"
                    . implode('`,`', $riCols) . "`) VALUES ("
                    . implode(',', $riPlaceholders) . ")";
                $riStmt = $MsaDB->db->prepare($riSql);
                $riStmt->execute($riVals);

                // b) bump running total
                $MsaDB->update(
                    'purchase__order_item',
                    [
                        'quantity_received' => (float)$poItem->quantityReceived + $ni['quantityReceived'],
                    ],
                    'id',
                    $ni['poItemId']
                );

                // c) resolve parts_id from the vendor part
                $vp = $vpRepo->getById($poItem->vendorPartId);
                if ($vp === null) {
                    throw new \LogicException("Vendor part {$poItem->vendorPartId} not found for PO item {$poItem->id}");
                }

                // d) positive inventory ledger row. timestamp = NOW(), isVerified = 0
                //    (audit-only column per DATABASE.md; admin UI never flips it to 1).
                $comment = sprintf(
                    'Przyjecie z PO %s (%s)',
                    $po->poNumber ?? ('#' . $po->id),
                    $poItem->vendorPartNo ?? ('vp#' . $poItem->vendorPartId)
                );
                $invCols = [
                    'parts_id', 'sub_magazine_id', 'qty', 'commission_id',
                    'transfer_group_id', 'is_cancelled', 'input_type_id',
                    'comment', 'timestamp', 'isVerified',
                ];
                $invVals = [
                    $vp->partsId,
                    $ni['subMagazineId'],
                    $ni['quantityReceived'],
                    null,
                    $transferGroupId,
                    0,
                    $inputTypeId,
                    $comment,
                    date('Y-m-d H:i:s'),
                    0,
                ];
                $invPlaceholders = array_fill(0, count($invCols), '?');
                $invSql = "INSERT INTO `inventory__parts` (`"
                    . implode('`,`', $invCols) . "`) VALUES ("
                    . implode(',', $invPlaceholders) . ")";
                $invStmt = $MsaDB->db->prepare($invSql);
                $invStmt->execute($invVals);
            }

            // 5.5 PO state transition (based on the post-write running total).
            $sum = $poItemRepo->sumQuantityReceivedByPo($poId);
            if ($orderedTotal > 0 && $sum + $epsilon >= $orderedTotal) {
                $poRepo->setState($poId, 'received');
            } elseif ($sum > 0) {
                $poRepo->setState($poId, 'partially_received');
            }

            if ($ownedTransaction) {
                $MsaDB->db->commit();
            }
            return $receiptId;
        } catch (\Throwable $e) {
            if ($ownedTransaction && $MsaDB->db->inTransaction()) {
                $MsaDB->db->rollBack();
            }
            throw $e;
        }
    }
}
