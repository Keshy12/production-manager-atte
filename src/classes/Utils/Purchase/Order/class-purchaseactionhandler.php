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
        $valid = ['rfq','po','pz'];
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

    /**
     * Wysyła zapytanie ofertowe (RFQ) — przechodzi stan `draft → sent`.
     * Dopuszczalne jest ponowne wysłanie po `responded` (np. uzupełnienie
     * opisu po rozmowie z dostawcą) — stan końcowy po wywołaniu to zawsze
     * `sent`. `$sentAt` pozwala nadpisać stempel czasu (np. przy
     * wprowadzaniu wysłanego dokumentu z opóźnieniem); null/empty → NOW().
     * `$generatePdfPlaceholder` ustawia `pdf_generated_at` + `pdf_path`
     * na placeholder, żeby UI mógł odróżnić "wysłano" od "czeka na PDF".
     *
     * @param int      $rfqId
     * @param int      $userId
     * @param string|null $sentAt               'Y-m-d H:i:s' lub null/empty → NOW()
     * @param bool|null    $generatePdfPlaceholder czy wstawić placeholder PDF
     * @return bool
     */
    public function sendRfq(int $rfqId, int $userId, ?string $sentAt = null, ?bool $generatePdfPlaceholder = true): bool {
        $MsaDB = $this->MsaDB;
        $repo = new RFQRepository($MsaDB);
        $itemRepo = new RFQItemRepository($MsaDB);

        $rfq = $repo->getById($rfqId);
        if ($rfq === null) {
            throw new \LogicException("Nie znaleziono zapytania.");
        }
        if (!in_array($rfq->state, ['draft','responded'], true)) {
            throw new \LogicException(
                "Zapytanie jest w stanie '{$rfq->state}'. Wysłać można tylko szkic lub zapytanie z odpowiedzią."
            );
        }
        if ($itemRepo->countByRfq($rfqId) === 0) {
            throw new \LogicException("Nie można wysłać zapytania bez pozycji.");
        }

        $normalisedSentAt = $this->normaliseSentAt($sentAt, "wysłania");

        $MsaDB->db->beginTransaction();
        try {
            $repo->setState($rfqId, 'sent');
            $repo->setSentAt($rfqId, $normalisedSentAt);
            if ($generatePdfPlaceholder === true) {
                $repo->setPdfPlaceholder($rfqId);
            }
            $MsaDB->db->commit();
            return true;
        } catch (\Throwable $e) {
            if ($MsaDB->db->inTransaction()) {
                $MsaDB->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Wysyła zamówienie (PO) — przechodzi stan `draft → sent` lub
     * `confirmed → sent` (ponowne wysłanie po aktualizacji ceny jest
     * normalnym scenariuszem obsługiwanym przez wizard). `$sentAt` i
     * `$generatePdfPlaceholder` działają identycznie jak w sendRfq().
     *
     * @param int         $poId
     * @param int         $userId
     * @param string|null $sentAt               'Y-m-d H:i:s' lub null/empty → NOW()
     * @param bool|null   $generatePdfPlaceholder czy wstawić placeholder PDF
     * @return bool
     */
    public function sendPo(int $poId, int $userId, ?string $sentAt = null, ?bool $generatePdfPlaceholder = true): bool {
        $MsaDB = $this->MsaDB;
        $repo = new PurchaseOrderRepository($MsaDB);
        $itemRepo = new PurchaseOrderItemRepository($MsaDB);

        $po = $repo->getById($poId);
        if ($po === null) {
            throw new \LogicException("Nie znaleziono zamówienia.");
        }
        if (!in_array($po->state, ['draft','confirmed'], true)) {
            throw new \LogicException(
                "Zamówienie jest w stanie '{$po->state}'. Wysłać można tylko szkic lub potwierdzone zamówienie."
            );
        }
        if ($itemRepo->countByPo($poId) === 0) {
            throw new \LogicException("Nie można wysłać zamówienia bez pozycji.");
        }

        $normalisedSentAt = $this->normaliseSentAt($sentAt, "wysłania");

        $MsaDB->db->beginTransaction();
        try {
            $repo->setState($poId, 'sent');
            $repo->setSentAt($poId, $normalisedSentAt);
            if ($generatePdfPlaceholder === true) {
                $repo->setPdfPlaceholder($poId);
            }
            $MsaDB->db->commit();
            return true;
        } catch (\Throwable $e) {
            if ($MsaDB->db->inTransaction()) {
                $MsaDB->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Oznacza zapytanie (RFQ) jako 'responded' — operator zarejestrował
     * odpowiedź od dostawcy (cennik, termin, alternatywy). Dozwolone
     * wyłącznie ze stanu `sent`. `$respondedAt` opcjonalny — null/empty
     * → NOW(). Po migracji schematu (krok P5+) zapisuje też
     * `responded_at` + `responded_by`.
     *
     * @param int         $rfqId
     * @param int         $userId
     * @param string|null $respondedAt         'Y-m-d H:i:s' lub null/empty → NOW()
     * @return bool
     */
    public function markRfqResponded(int $rfqId, int $userId, ?string $respondedAt = null): bool {
        $MsaDB = $this->MsaDB;
        $repo = new RFQRepository($MsaDB);

        $rfq = $repo->getById($rfqId);
        if ($rfq === null) {
            throw new \LogicException("Nie znaleziono zapytania.");
        }
        if ($rfq->state !== 'sent') {
            throw new \LogicException(
                "Zapytanie jest w stanie '{$rfq->state}'. Odpowiedź można oznaczyć tylko na wysłanym zapytaniu."
            );
        }

        $normalisedRespondedAt = $this->normaliseSentAt($respondedAt, "odpowiedzi");

        $MsaDB->db->beginTransaction();
        try {
            $repo->setState($rfqId, 'responded');

            // responded_at / responded_by zostały dodane migracją P5+
            // (migrate-to-procurement-schema.php). Kolumny istnieją
            // tylko na bazach, które przeszły ten krok; poza tym
            // zapisem trzymamy się istniejącego kontraktu. Aktualizacja
            // wykonywana bezpośrednio, bo dedykowana metoda repo
            // (setResponded) wymagałaby obsługi brakujących kolumn przy
            // każdym wywołaniu — tu robimy to w jednym miejscu.
            $stmt = $MsaDB->db->prepare(
                "UPDATE `purchase__rfq`
                    SET `responded_at` = ?,
                        `responded_by` = ?
                  WHERE `id` = ?"
            );
            $stmt->execute([$normalisedRespondedAt, $userId, $rfqId]);

            $MsaDB->db->commit();
            return true;
        } catch (\Throwable $e) {
            if ($MsaDB->db->inTransaction()) {
                $MsaDB->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Anuluje zamówienie (PO). Dozwolone ze stanów `draft`, `sent`,
     * `confirmed`. Blokuje `received`, `partially_received`, `cancelled`.
     * Komunikaty po polsku, bo trafiają prosto do operatora.
     */
    public function cancelPo(int $poId, int $userId): bool {
        $MsaDB = $this->MsaDB;
        $repo = new PurchaseOrderRepository($MsaDB);

        $po = $repo->getById($poId);
        if ($po === null) {
            throw new \LogicException("Nie znaleziono zamówienia.");
        }
        if (in_array($po->state, ['received','partially_received','cancelled'], true)) {
            throw new \LogicException(
                "Zamówienie jest w stanie '{$po->state}'. Anulowanie nie jest możliwe."
            );
        }

        $MsaDB->db->beginTransaction();
        try {
            $repo->setState($poId, 'cancelled');
            $MsaDB->db->commit();
            return true;
        } catch (\Throwable $e) {
            if ($MsaDB->db->inTransaction()) {
                $MsaDB->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Potwierdza zamówienie (PO) — przechodzi stan `sent → confirmed`.
     * Operator może opcjonalnie podać numer PO u dostawcy
     * (`vendor_po_number`) i/lub datę potwierdzenia (`confirmed_at`).
     *
     * `$vendorPoNumber` przycina do 64 znaków (szerokość kolumny)
     * bez rzucania — cicha normalizacja jest przyjaźniejsza dla UI niż
     * błąd walidacji, którego operator i tak nie widzi w formularzu.
     * Pusty string po trymowaniu traktowany jak null (kolumna pozostaje
     * bez zmian).
     *
     * `$confirmedAt` musi mieć format 'Y-m-d H:i:s'; endpoint normalizuje
     * 'Y-m-d' → 'Y-m-d 00:00:00' zanim tu trafi, więc null/empty → NOW().
     * Walidacja i etykiety błędów ('Nieprawidłowy format daty potwierdzenia.'
     * / 'Data potwierdzenia nie może być z przyszłości.') współdzielone
     * z sendRfq/sendPo poprzez normaliseSentAt($value, "potwierdzenia").
     *
     * @param int         $poId
     * @param int         $userId
     * @param string|null $vendorPoNumber      numer PO u dostawcy (max 64 zn.)
     * @param string|null $confirmedAt         'Y-m-d H:i:s' lub null/empty → NOW()
     * @return bool
     */
    public function confirmPo(int $poId, int $userId, ?string $vendorPoNumber = null, ?string $confirmedAt = null): bool {
        $MsaDB = $this->MsaDB;
        $repo  = new PurchaseOrderRepository($MsaDB);

        $po = $repo->getById($poId);
        if ($po === null) {
            throw new \LogicException("Nie znaleziono zamówienia.");
        }
        if ($po->state !== 'sent') {
            throw new \LogicException(
                'Zamówienie może być potwierdzone tylko w stanie „wysłane".'
            );
        }

        // vendor_po_number: null/empty → nie zapisuj; dłuższe niż kolumna → obetnij cicho.
        if ($vendorPoNumber !== null) {
            $vendorPoNumber = trim($vendorPoNumber);
            if ($vendorPoNumber === '') {
                $vendorPoNumber = null;
            } elseif (strlen($vendorPoNumber) > 64) {
                $vendorPoNumber = substr($vendorPoNumber, 0, 64);
            }
        }

        // confirmedAt: ten sam helper co sendRfq/sendPo z etykietą 'potwierdzenia',
        // dzięki czemu komunikaty błędów ('Nieprawidłowy format daty potwierdzenia.'
        // / 'Data potwierdzenia nie może być z przyszłości.') są spójne i po polsku.
        $normalisedConfirmedAt = $this->normaliseSentAt($confirmedAt, "potwierdzenia");

        $MsaDB->db->beginTransaction();
        try {
            if ($vendorPoNumber !== null) {
                $repo->setVendorPoNumber($poId, $vendorPoNumber);
            }
            $repo->setState($poId, 'confirmed');
            $repo->setConfirmedAt($poId, $normalisedConfirmedAt);

            $MsaDB->db->commit();
            return true;
        } catch (\Throwable $e) {
            if ($MsaDB->db->inTransaction()) {
                $MsaDB->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Waliduje i normalizuje datę wysłania/odpowiedzi ('Y-m-d H:i:s').
     * Null/empty → NOW(). Rzuca InvalidArgumentException z polskim
     * komunikatem gdy format zły lub data z przyszłości (> +1 dzień).
     * Używane przez sendRfq / sendPo / markRfqResponded.
     */
    private function normaliseSentAt(?string $value, string $label): string {
        if ($value === null || trim($value) === '') {
            return date('Y-m-d H:i:s');
        }
        $value = trim($value);
        $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $value);
        $parseErrors = \DateTime::getLastErrors();
        $hasParseErrors = $parseErrors !== false
            && ($parseErrors['warning_count'] > 0 || $parseErrors['error_count'] > 0);
        if ($dt === false || $hasParseErrors) {
            throw new \InvalidArgumentException(
                "Nieprawidłowy format daty {$label}."
            );
        }
        $maxAllowed = (new \DateTime())->modify('+1 day');
        if ($dt > $maxAllowed) {
            throw new \InvalidArgumentException(
                "Data {$label} nie może być z przyszłości."
            );
        }
        return $dt->format('Y-m-d H:i:s');
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

    /**
     * Record a goods-receipt (PZ) against a confirmed PO.
     *
     * `$documentNumber` is the operator-supplied PZ/WZ number. When null
     * (the common case from the goods-receiving UI) this method
     * auto-allocates the next PZ number from purchase__number_counter
     * inside the transaction — same counter pattern allocateDocumentNumber
     * uses for RFQ/PO. Passing an explicit non-empty string uses it
     * verbatim (no allocation, no uniqueness check — same behaviour as
     * the PO/RFQ paths).
     *
     * `$receivedAt` lets the operator backdate a delivery (e.g. goods
     * arrived yesterday but were booked today). When supplied it stamps
     * BOTH the receipt header AND each inventory__parts row so the
     * stock-ledger date matches the receipt date. Null means "now" —
     * the receipt header falls back to its DEFAULT CURRENT_TIMESTAMP()
     * and the inventory rows use the current PHP timestamp. Rejected
     * when unparseable or more than one day in the future.
     */
    public function createReceipt(
        int $poId,
        int $receivedBy,
        array $items,
        ?string $documentNumber = null,
        ?string $receiptComment = null,
        ?string $receivedAt = null
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
        // Validate receivedAt strictly: must parse as 'Y-m-d H:i:s' AND
        // must not be more than one day in the future. Caller is
        // expected to normalise 'Y-m-d' → 'Y-m-d 00:00:00' before passing.
        if ($receivedAt !== null) {
            $receivedAt = trim($receivedAt);
            if ($receivedAt === '') $receivedAt = null;
        }
        if ($receivedAt !== null) {
            $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $receivedAt);
            $parseErrors = \DateTime::getLastErrors();
            $hasParseErrors = $parseErrors !== false
                && ($parseErrors['warning_count'] > 0 || $parseErrors['error_count'] > 0);
            if ($dt === false || $hasParseErrors) {
                throw new \InvalidArgumentException(
                    "Nieprawidłowy format daty przyjęcia."
                );
            }
            // Future-tolerance: allow today and tomorrow, reject the day
            // after and beyond. (Cheap insurance against typos like a
            // transposed month blowing the year forward by N years.)
            $maxAllowed = (new \DateTime())->modify('+1 day');
            if ($dt > $maxAllowed) {
                throw new \InvalidArgumentException(
                    "Data przyjęcia nie może być z przyszłości."
                );
            }
            // Normalise back through format() so we lose any timezone /
            // microsecond shenanigans and feed MySQL a canonical value.
            $receivedAt = $dt->format('Y-m-d H:i:s');
        }

        $MsaDB = $this->MsaDB;
        $poRepo     = new PurchaseOrderRepository($MsaDB);
        $poItemRepo = new PurchaseOrderItemRepository($MsaDB);
        $vpRepo     = new VendorPartRepository($MsaDB);
        $receiptRepo = new OrderReceiptRepository($MsaDB);
        $transferGroupManager = new TransferGroupManager($MsaDB);

        // 1. Load PO; state guard. Messages are Polish because
        //    receipt-create.php returns them verbatim to the operator.
        $po = $poRepo->getById($poId);
        if ($po === null) {
            throw new \LogicException("Nie znaleziono zamówienia.");
        }
        if (!in_array($po->state, ['confirmed','partially_received'], true)) {
            throw new \LogicException("Zamówienie musi być potwierdzone lub częściowo odebrane, aby przyjąć towar.");
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
                throw new \InvalidArgumentException("Pozycja #{$idx}: brak identyfikatora pozycji zamówienia.");
            }
            if (!isset($poItemsById[$poItemId])) {
                throw new \LogicException("Pozycja #{$idx}: pozycja {$poItemId} nie należy do zamówienia {$poId}.");
            }
            $poItem = $poItemsById[$poItemId];

            if (!is_numeric($qtyRecvRaw) || (float)$qtyRecvRaw <= 0) {
                throw new \InvalidArgumentException("Pozycja #{$idx}: przyjmowana ilość musi być większa od zera.");
            }
            $qtyRecv = (float)$qtyRecvRaw;

            if ($subMag <= 0) {
                throw new \InvalidArgumentException("Pozycja #{$idx}: nie wybrano magazynu.");
            }

            // Active magazine check.
            $magStmt = $MsaDB->db->prepare("SELECT isActive FROM `magazine__list` WHERE sub_magazine_id = ?");
            $magStmt->execute([$subMag]);
            $magRow = $magStmt->fetch(\PDO::FETCH_ASSOC);
            if ($magRow === false) {
                throw new \LogicException("Pozycja #{$idx}: magazyn {$subMag} nie istnieje.");
            }
            if ((int)$magRow['isActive'] !== 1) {
                throw new \LogicException("Pozycja #{$idx}: magazyn {$subMag} jest nieaktywny.");
            }

            // Lenient 110% over-delivery cap.
            $remaining = ((float)$poItem->quantity) - ((float)$poItem->quantityReceived);
            $maxAllowed = $remaining * 1.10 + $epsilon;
            $newRunning = ((float)$poItem->quantityReceived) + $qtyRecv;
            if ($newRunning > $maxAllowed) {
                throw new \LogicException(sprintf(
                    "Pozycja %d (%s): przyjęcie %.4f przekracza dopuszczalne maksimum %.4f (pozostało %.4f × 1,10).",
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
            // 5.0 Auto-allocate the PZ number inside the transaction so the
            // counter increment is atomic with the header insert. allocateDocumentNumber
            // detects the open transaction and joins it (FOR UPDATE relies on it).
            // When the caller passed a non-empty documentNumber we use it verbatim —
            // no allocation, no uniqueness check (matches the PO/RFQ path).
            if ($documentNumber === null) {
                $documentNumber = $this->allocateDocumentNumber('pz');
            }

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

            // 5.3 Create the receipt header. Passing $receivedAt null lets the
            // column's CURRENT_TIMESTAMP() default fire — null means "stamp
            // now at the DB" (consistent with the original behaviour).
            $receiptId = $receiptRepo->create($poId, $receivedBy, $documentNumber, $receiptComment, $receivedAt);

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
                    throw new \LogicException("Nie znaleziono pozycji dostawcy {$poItem->vendorPartId} dla pozycji zamówienia {$poItem->id}.");
                }

                // d) positive inventory ledger row. timestamp follows the receipt date
                //    (backdating the receipt backdates the stock entry too), defaulting
                //    to NOW() when no $receivedAt was supplied. isVerified = 0
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
                    $receivedAt !== null ? $receivedAt : date('Y-m-d H:i:s'),
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
