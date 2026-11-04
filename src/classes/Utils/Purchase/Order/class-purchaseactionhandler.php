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

    public function allocateDocumentNumber(string $type, int $vendorId): string {
        $valid = ['rfq','po','grn'];
        if (!in_array($type, $valid, true)) {
            throw new \InvalidArgumentException("type must be one of: " . implode(', ', $valid));
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
                "INSERT INTO `purchase__number_counter` (`type`, `vendor_id`, `last_value`)
                 VALUES (?, ?, 1)
                 ON DUPLICATE KEY UPDATE `last_value` = `last_value` + 1"
            );
            $stmt->execute([$type, $vendorId]);

            $stmt = $MsaDB->db->prepare(
                "SELECT `last_value` FROM `purchase__number_counter`
                 WHERE `type` = ? AND `vendor_id` = ?
                 FOR UPDATE"
            );
            $stmt->execute([$type, $vendorId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            $n = $row ? (int)$row['last_value'] : 1;

            if ($ownedTransaction) {
                $MsaDB->db->commit();
            }
            if ($type === 'grn') {
                return sprintf('GRN/%06d', $n);
            }
            return sprintf('%s/%05d/%05d', strtoupper($type), $vendorId, $n);
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

        $MsaDB->db->beginTransaction();
        try {
            if ($type === 'rfq') {
                $number = $this->allocateDocumentNumber('rfq', $vendorId);
                $repo   = new RFQRepository($MsaDB);
                $id     = $repo->create($vendorId, $userId, null, null);
                $repo->setRfqNumber($id, $number);
                $MsaDB->db->commit();
                return $id;
            }
            // type === 'po'
            $number = $this->allocateDocumentNumber('po', $vendorId);
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
     *   - unit_price          float|null (optional, null/blank allowed — null
     *                          oznacza "cena nieustalona" w RFQ i w PO)
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
        // unit_price may legitimately be null/blank — for RFQs (target price)
        // and for POs (price not negotiated yet).
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
                $unitPrice, // null ok — pozycja bez ceny ("cena nieustalona")
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
    public function sendRfq(int $rfqId, int $userId, ?string $sentAt = null, ?bool $generatePdfPlaceholder = true, ?string $expectedDate = null, bool $applyExpectedDate = false): bool {
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
            // Wizard step 3: expected reply date (null/'' clears it).
            if ($applyExpectedDate) {
                $repo->setExpectedReplyDate($rfqId, $expectedDate);
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
    public function sendPo(int $poId, int $userId, ?string $sentAt = null, ?bool $generatePdfPlaceholder = true, ?string $expectedDate = null, bool $applyExpectedDate = false): bool {
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
            // Wizard step 3 sends the final date explicitly (possibly empty
            // = cleared on purpose) — then the lead-time default is skipped.
            if ($applyExpectedDate) {
                $repo->setExpectedDeliveryDate($poId, $expectedDate);
            }
            // Default "Planowana dostawa" (callers that don't pass a date):
            // when the operator hasn't entered
            // one, derive it as send date + vendor lead_time_days. A date
            // that was set by hand is never overwritten (e.g. on re-send).
            elseif ($po->expectedDeliveryDate === null) {
                $leadStmt = $MsaDB->db->prepare("SELECT `lead_time_days` FROM `list__vendor` WHERE `id` = ?");
                $leadStmt->execute([$po->vendorId]);
                $leadDays = $leadStmt->fetchColumn();
                if ($leadDays !== false && $leadDays !== null && (int)$leadDays > 0) {
                    $base = new \DateTime(substr($normalisedSentAt, 0, 10));
                    $base->modify('+' . (int)$leadDays . ' days');
                    $repo->setExpectedDeliveryDate($poId, $base->format('Y-m-d'));
                }
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
     * `responded_at` + `responded_by`. Pusty `$items` → zachowanie
     * historyczne (bez aktualizacji pozycji); odpowiedź używana przez
     * wizarda "Wyślij dokument" przekazuje puste `items`. Wariant z
     * pozycjami (z cenami od dostawcy) realizuje dedykowana metoda
     * {@see markRfqRespondedWithPrices} — tam dla każdego wpisu
     * aktualizowane jest `unit_price`/`currency`/`comment` i dopiero
     * potem przechodzimy do stanu `responded`.
     *
     * @param int         $rfqId
     * @param int         $userId
     * @param string|null $respondedAt         'Y-m-d H:i:s' lub null/empty → NOW()
     * @return bool
     *
     * Uwaga: ta metoda nie przyjmuje `$vendorReference` ("Numer u
     * dostawcy") — wizard "Wyślij dokument", który z niej korzysta,
     * nie ma takiego pola. Callery chcący ustawić numer referencyjny
     * muszą wywołać {@see markRfqRespondedWithPrices()} bezpośrednio.
     */
    public function markRfqResponded(int $rfqId, int $userId, ?string $respondedAt = null): bool {
        return $this->markRfqRespondedWithPrices($rfqId, $userId, [], $respondedAt);
    }

    /**
     * Wariant z aktualizacją cen/waluty/uwag z odpowiedzi dostawcy —
     * używany przez stronę "Oznacz odpowiedź" (documents-respond.php),
     * gdzie operator ręcznie wpisuje wynegocjowane ceny na każdej
     * pozycji RFQ. Dla każdego wpisu w `$items` wywołuje
     * `RFQItemRepository::update()` z istniejącymi polami (vendorPartId,
     * quantity, quantityUnitId, pickedPackSize) + nowymi trzema
     * (unit_price/currency/comment). Gdy `$items` jest puste, metoda
     * zachowuje się jak {@see markRfqResponded} — wizard "Wyślij
     * dokument" przekazuje `[]`, dedykowana strona odpowiedzi przekazuje
     * mapę per pozycja.
     *
     * Walidacja per pozycja:
     *   - `item_id` > 0 (wymagane)
     *   - `unit_price` gdy podane — `>= 0` (ujemna → InvalidArgumentException);
     *     puste/null/'' → null (cena nie ustalona)
     *   - `currency` — niepusty string, max 8 znaków (szerokość kolumny
     *     `purchase__rfq_item.currency` per schema); domyślnie `PLN`
     *   - `comment` — dowolny string, trim + null gdy pusty
     *   - pozycja musi należeć do `$rfqId` (walidacja ownership —
     *     `LogicException`, żeby UI nie dało się oszukać cudzym id)
     *
     * Wybór `update()` zamiast dedykowanego `updateResponseFields()`:
     * repo nie ma metody tylko dla trzech pól, a `update()` jest już
     * transakcyjny i waliduje (vendorPartId/quantity/quantityUnitId
     * > 0; pickedPackSize > 0 gdy podane). Cenę/walutę/komentarz
     * zapisujemy w jednym UPDATE razem z istniejącymi polami — mniej
     * kodu, mniejsza szansa na rozbieżność (np. że jeden z trzech
     * pól nie trafi na dysk). Cena jest często częścią tej samej
     * odpowiedzi co korekty uwag, więc atomowy zapis wszystkich trzech
     * jest pożądany.
     *
     * `$vendorReference` ("Numer oferty u dostawcy") to opcjonalny numer
     * referencyjny nadany sprawie przez dostawcę — zapisywany razem ze
     * stanem `responded`, żeby później można się było do niego odwołać
     * w korespondencji. Trimowany; null/empty → czyści kolumnę;
     * dłuższy niż 128 znaków → \InvalidArgumentException (komunikat po
     * polsku). Walidowany PRZED otwarciem transakcji (ten sam wzorzec
     * co pozycje), a następnie ponownie w `RFQRepository::setVendorReference()`
     * — defensywne podwójne sprawdzenie jest tanie (no DB roundtrip)
     * i zgodne z istniejącym kontraktem.
     *
     * @param int         $rfqId
     * @param int         $userId
     * @param array       $items             lista map per pozycja (pusta = bez aktualizacji pozycji):
     *                                          ['item_id' => int, 'unit_price' => ?float, 'currency' => string, 'comment' => ?string,
     *                                           'quantity' => ?float,         // null = keep existing
     *                                           'quantity_unit_id' => ?int]   // null = keep existing
     * @param string|null $respondedAt       'Y-m-d H:i:s' lub null/empty → NOW()
     * @param string|null $vendorReference   "Numer oferty u dostawcy" (max 128 zn.); null/empty → NULL w kolumnie
     * @return bool
     */
    public function markRfqRespondedWithPrices(
        int $rfqId,
        int $userId,
        array $items,
        ?string $respondedAt = null,
        ?string $vendorReference = null
    ): bool {
        $MsaDB     = $this->MsaDB;
        $repo      = new RFQRepository($MsaDB);
        $itemRepo  = new RFQItemRepository($MsaDB);

        // Wczesna walidacja id (zachowana jak w istniejącym markRfqResponded):
        // zapytanie musi istnieć i być w stanie 'sent'. Stan końcowy po
        // wywołaniu to zawsze 'responded'.
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
        $normalisedVendorReference = self::normaliseVendorReference($vendorReference);

        // Pre-walidacja pozycji: najpierw zbierz wszystkie błędy walidacji,
        // żeby operator widział pełną listę (albo chociaż pierwszą sensowną)
        // zamiast fail-at-first. Walidacja musi się odbyć PRZED otwarciem
        // transakcji, żeby złe dane wejściowe niepotrzebnie nie blokowały
        // sesji DB. getById na każdej pozycji (poniżej) robi SELECT — przy
        // N≈kilkunastu pozycjach to koszt pomijalny.
        foreach ($items as $idx => $it) {
            if (!is_array($it)) {
                throw new \InvalidArgumentException("Pozycja #{$idx}: nieprawidłowy format danych.");
            }
            $itemId = (int)($it['item_id'] ?? 0);
            if ($itemId <= 0) {
                throw new \InvalidArgumentException("Pozycja #{$idx}: brak identyfikatora pozycji.");
            }
            // unit_price: nieujemna lub null. Rzucamy InvalidArgumentException
            // dla jawnego błędu wartości (komunikat po polsku, trafia prosto
            // do UI przez endpoint AJAX).
            if (array_key_exists('unit_price', $it) && $it['unit_price'] !== null && $it['unit_price'] !== '') {
                if (!is_numeric($it['unit_price']) || (float)$it['unit_price'] < 0) {
                    throw new \InvalidArgumentException(
                        "Pozycja #{$idx} ({$itemId}): cena jednostkowa musi być liczbą nieujemną."
                    );
                }
            }
            if (isset($it['currency'])) {
                if (!is_string($it['currency']) || trim($it['currency']) === '') {
                    throw new \InvalidArgumentException(
                        "Pozycja #{$idx} ({$itemId}): waluta nie może być pusta."
                    );
                }
                if (strlen((string)$it['currency']) > 8) {
                    throw new \InvalidArgumentException(
                        "Pozycja #{$idx} ({$itemId}): kod waluty jest zbyt długi (max 8 znaków)."
                    );
                }
            }
            // quantity: opcjonalne; gdy podane musi być numeryczne > 0.
            // null/absent = fallback do istniejącej wartości w wierszu.
            if (array_key_exists('quantity', $it) && $it['quantity'] !== null) {
                if (!is_numeric($it['quantity']) || (float)$it['quantity'] <= 0) {
                    throw new \InvalidArgumentException(
                        "Pozycja #{$idx} ({$itemId}): ilość musi być > 0."
                    );
                }
            }
            // quantity_unit_id: opcjonalne; gdy podane musi być dodatnim intem.
            // null/absent = fallback do istniejącej wartości w wierszu.
            if (array_key_exists('quantity_unit_id', $it) && $it['quantity_unit_id'] !== null) {
                $uidVal = $it['quantity_unit_id'];
                $uidOk  = is_int($uidVal)
                    || (is_string($uidVal) && ctype_digit($uidVal))
                    || (is_numeric($uidVal) && (float)$uidVal == (int)$uidVal);
                if (!$uidOk || (int)$uidVal <= 0) {
                    throw new \InvalidArgumentException(
                        "Pozycja #{$idx} ({$itemId}): nieprawidłowa jednostka miary."
                    );
                }
            }
        }

        $MsaDB->db->beginTransaction();
        try {
            // Aktualizuj każdą pozycję po kolei. Najpierw pobierz istniejącą
            // encję (ownership check + odczyt pól, których nie zmieniamy),
            // potem wywołaj update() z istniejącymi polami i trzema nowymi.
            // update() wewnętrznie waliduje vendorPartId/quantity/quantityUnitId
            // > 0 i pickedPackSize > 0 — wszystkie te wartości pochodzą z
            // istniejącej encji, więc tu nie mogą się zmienić.
            foreach ($items as $idx => $it) {
                $itemId = (int)($it['item_id']);
                $existing = $itemRepo->getById($itemId);
                if ($existing === null || $existing->rfqId !== $rfqId) {
                    throw new \LogicException(
                        "Pozycja #{$idx}: pozycja {$itemId} nie należy do zapytania {$rfqId}."
                    );
                }

                // Normalizacja: cena pusty/null → null; w przeciwnym razie float.
                $unitPrice = null;
                if (array_key_exists('unit_price', $it) && $it['unit_price'] !== null && $it['unit_price'] !== '') {
                    $unitPrice = (float)$it['unit_price'];
                }

                // Waluta: gdy podana, użyj jej; w przeciwnym razie zostaw
                // istniejącą (operator może chcieć zmienić tylko cenę).
                $currency = isset($it['currency']) && trim((string)$it['currency']) !== ''
                    ? (string)$it['currency']
                    : $existing->currency;

                // Komentarz: trim, pusty → null (wyczyść kolumnę). Brak
                // klucza 'comment' = nie ruszaj istniejącego (formularz
                // odpowiedzi tego pola nie wysyła). Tak samo jak w wariancie
                // PO (confirmPoWithPrices) — pusta wartość czyści kolumnę.
                $comment = $existing->comment;
                if (array_key_exists('comment', $it)) {
                    $trimmed = trim((string)($it['comment'] ?? ''));
                    $comment = $trimmed === '' ? null : $trimmed;
                }

                // Quantity: użyj podanej gdy obecna i różna od null; w przeciwnym
                // razie zachowaj istniejącą (send-wizard caller wysyła items=[],
                // więc ta gałąź się w ogóle nie wykonuje — explicit backward compat).
                $newQty = (array_key_exists('quantity', $it) && $it['quantity'] !== null)
                    ? (float)$it['quantity']
                    : (float)$existing->quantity;

                // Quantity_unit_id: jak wyżej — fallback do istniejącej.
                $newUnitId = (array_key_exists('quantity_unit_id', $it) && $it['quantity_unit_id'] !== null)
                    ? (int)$it['quantity_unit_id']
                    : (int)$existing->quantityUnitId;

                $itemRepo->update(
                    $existing->id,
                    $existing->vendorPartId,
                    $newQty,
                    $newUnitId,
                    $unitPrice,
                    $currency,
                    $comment,
                    $existing->pickedPackSize
                );
            }

            $repo->setState($rfqId, 'responded');

            // "Numer oferty u dostawcy" — zawsze wywołujemy (puste/null czyści
            // kolumnę, więc ponowne oznaczenie odpowiedzi może nadpisać
            // wcześniejszy numer). Walidacja długości/formatu już
            // odbyła się w normaliseVendorReference() przed otwarciem
            // transakcji — repo i tak waliduje defensywnie ponownie.
            $repo->setVendorReference($rfqId, $normalisedVendorReference);

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
     * Stan `confirmed` wymaga, żeby KAŻDA pozycja zamówienia miała cenę
     * > 0 (`unit_price` jest `DECIMAL(30,10) DEFAULT NULL`, więc pozycja
     * zna stan "niewycenione" — NULL = "cena nieustalona"; "brak ceny" to
     * NULL albo cena 0).
     * Bez tej kontroli dałoby się potwierdzić zamówienie z pozycjami
     * bez ceny. Kontrola dotyczy momentu potwierdzenia — pozycje
     * zamówienia w stanie `confirmed` da się jeszcze edytować na stronie
     * edycji (`allowedEditStates('po')` zawiera 'confirmed').
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

        // Bez cen nie potwierdzamy — sprawdzamy WSZYSTKIE pozycje zamówienia.
        $this->assertPoItemsHavePrice(
            new PurchaseOrderItemRepository($MsaDB),
            $poId,
            []
        );

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
     * Wariant potwierdzenia zamówienia (PO) Z CENAMI — używany przez
     * dedykowaną stronę "Potwierdź odpowiedź" (documents-respond.php
     * z `?type=po`), gdzie operator ręcznie wpisuje wynegocjowane
     * przez dostawcę ceny na każdej pozycji zamówienia. Metoda łączy
     * dwie rzeczy, których wcześniej nie dało się zrobić naraz:
     *   1) zapisanie odpowiedzi dostawcy na pozycjach PO
     *      (cena / waluta / uwaga / ilość / jednostka),
     *   2) przejście stanu `sent → confirmed` w tej samej transakcji.
     *
     * Powtórzenie `confirmPo()` ({@see confirmPo}) rozszerzone o
     * aktualizację pozycji — wersja bez cen pozostaje dostępna jako
     * osobna metoda (wołana przez punkt końcowy
     * `purchases/documents/document-confirm-ajax.php`, który nie jest
     * montowany w UI).
     *
     * Różnica względem {@see markRfqRespondedWithPrices} (wariant RFQ):
     *   - `unit_price` jest `DECIMAL(30,10) DEFAULT NULL` — pozycja PO
     *     zna stan "niewycenione" (NULL = "cena nieustalona"), tak jak
     *     pozycja RFQ. Puste pole ceny w formularzu oznacza więc
     *     "zachowaj cenę istniejącą", a NIE "0"; istniejąca cena może
     *     być NULL — wtedy pozycja zostaje niewyceniona, a potwierdzenie
     *     odrzuci ją w {@see assertPoItemsHavePrice()}.
     *   - pusta waluta znaczy "zachowaj istniejącą" (w RFQ pusta
     *     waluta byłaby błędem).
     *
     * Walidacja per pozycja (PRZED otwarciem transakcji — ten sam
     * wzorzec co w `markRfqRespondedWithPrices`):
     *   - `item_id` > 0 (wymagane)
     *   - `unit_price` gdy podane — numeryczne i `>= 0` (puste/null/''
     *     → zachowaj cenę istniejącą)
     *   - `currency` — gdy podana i niepusta: max 8 znaków (szerokość
     *     kolumny `purchase__order_item.currency` per schema)
     *   - `comment` — dowolny string, trim + null gdy pusty (czyści
     *     kolumnę); brak klucza → zachowaj istniejący komentarz
     *   - `quantity` — gdy podane: numeryczne i `> 0`
     *   - `quantity_unit_id` — gdy podane: dodatni int
     *   - `expected_delivery_date` — gdy podane: 'Y-m-d' (termin dostawy
     *     TEJ pozycji) albo pusty string (wyczyść termin). Brak klucza =
     *     nie ruszaj terminu zapisanego w bazie. Dotyczy wyłącznie PO —
     *     pozycja RFQ nie ma terminu dostawy.
     *   - pozycja musi należeć do `$poId` (walidacja ownership —
     *     `LogicException`, żeby UI nie dało się oszukać cudzym id)
     *   - KAŻDA pozycja zamówienia musi mieć cenę > 0 po zastosowaniu
     *     podanych cen (puste pole = zachowaj cenę zapisaną w bazie) —
     *     inaczej `InvalidArgumentException`; patrz
     *     {@see assertPoItemsHavePrice()}
     *
     * `$vendorPoNumber` ("Numer zamówienia u dostawcy") — ta sama
     * normalizacja co w `confirmPo()`: trim; pusty → null (kolumna
     * pozostaje bez zmian — NIE jest czyszczona, inaczej niż
     * `vendor_reference` w RFQ); dłuższy niż 64 znaki → ciche
     * obcięcie (szerokość kolumny `purchase__order.vendor_po_number`).
     *
     * `$confirmedAt` — walidowany przez `normaliseSentAt($value,
     * "potwierdzenia")`: 'Y-m-d H:i:s' albo null/empty → NOW();
     * komunikaty błędów ('Nieprawidłowy format daty potwierdzenia.' /
     * 'Data potwierdzenia nie może być z przyszłości.') są wspólne
     * z `confirmPo()`, `sendRfq()` i `sendPo()`.
     *
     * @param int         $poId
     * @param int         $userId
     * @param array       $items             lista map per pozycja (pusta = bez aktualizacji pozycji):
     *                                          ['item_id' => int, 'unit_price' => ?float, 'currency' => ?string, 'comment' => ?string,
     *                                           'quantity' => ?float,         // null = keep existing
     *                                           'quantity_unit_id' => ?int,    // null = keep existing
     *                                           'expected_delivery_date' => ?string] // 'Y-m-d' | '' = clear | absent = keep
     * @param string|null $vendorPoNumber    numer PO u dostawcy (max 64 zn.); null/empty → kolumna bez zmian
     * @param string|null $confirmedAt       'Y-m-d H:i:s' lub null/empty → NOW()
     * @return bool
     */
    public function confirmPoWithPrices(
        int $poId,
        int $userId,
        array $items,
        ?string $vendorPoNumber = null,
        ?string $confirmedAt = null
    ): bool {
        $MsaDB    = $this->MsaDB;
        $repo     = new PurchaseOrderRepository($MsaDB);
        $itemRepo = new PurchaseOrderItemRepository($MsaDB);

        // Zamówienie musi istnieć i być w stanie 'sent'. Stan końcowy
        // po wywołaniu to zawsze 'confirmed'.
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
        // (Identycznie z confirmPo() — cicha normalizacja jest przyjaźniejsza
        // dla operatora niż błąd walidacji, którego nie widzi w formularzu.)
        if ($vendorPoNumber !== null) {
            $vendorPoNumber = trim($vendorPoNumber);
            if ($vendorPoNumber === '') {
                $vendorPoNumber = null;
            } elseif (strlen($vendorPoNumber) > 64) {
                $vendorPoNumber = substr($vendorPoNumber, 0, 64);
            }
        }

        // confirmedAt: ten sam helper co sendRfq/sendPo/confirmPo z etykietą
        // 'potwierdzenia', dzięki czemu komunikaty błędów są spójne i po polsku.
        $normalisedConfirmedAt = $this->normaliseSentAt($confirmedAt, "potwierdzenia");

        // Pre-walidacja pozycji — PRZED otwarciem transakcji, żeby złe dane
        // wejściowe nie blokowały sesji DB i żeby operator dostał czytelny
        // komunikat (komunikaty po polsku trafiają wprost do UI przez
        // endpoint AJAX).
        foreach ($items as $idx => $it) {
            if (!is_array($it)) {
                throw new \InvalidArgumentException("Pozycja #{$idx}: nieprawidłowy format danych.");
            }
            $itemId = (int)($it['item_id'] ?? 0);
            if ($itemId <= 0) {
                throw new \InvalidArgumentException("Pozycja #{$idx}: brak identyfikatora pozycji.");
            }
            // unit_price: nieujemna liczba albo puste/null/'' (= zachowaj
            // cenę istniejącą — która może być NULL, czyli pozycja
            // niewyceniona).
            if (array_key_exists('unit_price', $it) && $it['unit_price'] !== null && $it['unit_price'] !== '') {
                if (!is_numeric($it['unit_price']) || (float)$it['unit_price'] < 0) {
                    throw new \InvalidArgumentException(
                        "Pozycja #{$idx} ({$itemId}): cena jednostkowa musi być liczbą nieujemną."
                    );
                }
            }
            // currency: pusta wartość jest DOZWOLONA i oznacza "zachowaj
            // istniejącą walutę"; niepusta musi mieścić się w 8 znakach.
            if (isset($it['currency'])) {
                $currencyRaw = trim((string)$it['currency']);
                if ($currencyRaw !== '' && strlen($currencyRaw) > 8) {
                    throw new \InvalidArgumentException(
                        "Pozycja #{$idx} ({$itemId}): kod waluty jest zbyt długi (max 8 znaków)."
                    );
                }
            }
            // quantity: opcjonalne; gdy podane musi być numeryczne > 0.
            // null/absent = fallback do istniejącej wartości w wierszu.
            if (array_key_exists('quantity', $it) && $it['quantity'] !== null) {
                if (!is_numeric($it['quantity']) || (float)$it['quantity'] <= 0) {
                    throw new \InvalidArgumentException(
                        "Pozycja #{$idx} ({$itemId}): ilość musi być > 0."
                    );
                }
            }
            // quantity_unit_id: opcjonalne; gdy podane musi być dodatnim intem.
            // null/absent = fallback do istniejącej wartości w wierszu.
            if (array_key_exists('quantity_unit_id', $it) && $it['quantity_unit_id'] !== null) {
                $uidVal = $it['quantity_unit_id'];
                $uidOk  = is_int($uidVal)
                    || (is_string($uidVal) && ctype_digit($uidVal))
                    || (is_numeric($uidVal) && (float)$uidVal == (int)$uidVal);
                if (!$uidOk || (int)$uidVal <= 0) {
                    throw new \InvalidArgumentException(
                        "Pozycja #{$idx} ({$itemId}): nieprawidłowa jednostka miary."
                    );
                }
            }
            // expected_delivery_date: opcjonalne; gdy podane — 'Y-m-d' albo
            // pusty string (= wyczyść termin pozycji). Brak klucza = nie
            // ruszaj terminu zapisanego w bazie.
            if (array_key_exists('expected_delivery_date', $it) && $it['expected_delivery_date'] !== null) {
                $etaRaw = trim((string)$it['expected_delivery_date']);
                if ($etaRaw !== '') {
                    $etaD = \DateTime::createFromFormat('Y-m-d', $etaRaw);
                    if ($etaD === false || $etaD->format('Y-m-d') !== $etaRaw) {
                        throw new \InvalidArgumentException(
                            "Pozycja #{$idx} ({$itemId}): data dostawy musi mieć format RRRR-MM-DD."
                        );
                    }
                }
            }
        }

        // Ceny: KAŻDA pozycja zamówienia musi mieć cenę > 0. Puste pole
        // ceny w formularzu oznacza "zachowaj cenę istniejącą", więc
        // wystarczy, że cena zapisana w bazie jest dodatnia (NULL = brak
        // ceny → brak potwierdzenia). Sprawdzamy
        // wszystkie pozycje zamówienia (także te pominięte w formularzu).
        $this->assertPoItemsHavePrice($itemRepo, $poId, $items);

        // Terminy dostawy: żadna pozycja nie może mieć daty w przeszłości
        // (w UI wiersz dostaje wtedy flagę „po terminie"). Pusta data =
        // brak terminu dla pozycji = OK. Sprawdzamy wszystkie pozycje
        // zamówienia, także te pominięte w formularzu.
        $this->assertPoItemsHaveFutureDeliveryDate($itemRepo, $poId, $items);

        $MsaDB->db->beginTransaction();
        try {
            // Aktualizuj każdą pozycję po kolei: najpierw odczyt istniejącej
            // encji (ownership check + odczyt pól, których nie zmieniamy),
            // potem update() z istniejącymi polami i nowymi wartościami.
            foreach ($items as $idx => $it) {
                $itemId = (int)($it['item_id']);
                $existing = $itemRepo->getById($itemId);
                if ($existing === null || $existing->poId !== $poId) {
                    throw new \LogicException(
                        "Pozycja #{$idx}: pozycja {$itemId} nie należy do zamówienia {$poId}."
                    );
                }

                // Cena: gdy podana — nowa; gdy pusta/null — ZACHOWAJ istniejącą.
                // (Istniejąca cena może być NULL — pozycja wtedy pozostaje
                // niewyceniona, a brak ceny pilnuje assertPoItemsHavePrice.)
                $unitPrice = $existing->unitPrice;
                if (array_key_exists('unit_price', $it) && $it['unit_price'] !== null && $it['unit_price'] !== '') {
                    $unitPrice = (float)$it['unit_price'];
                }

                // Waluta: gdy podana i niepusta — użyj jej; w przeciwnym razie
                // zostaw istniejącą (operator może chcieć zmienić tylko cenę).
                $currency = isset($it['currency']) && trim((string)$it['currency']) !== ''
                    ? (string)$it['currency']
                    : $existing->currency;

                // Komentarz: trim, pusty → null (czyści kolumnę). Jeśli klucza
                // `comment` nie ma w ogóle w wierszu — zachowaj istniejący
                // (np. żeby formularz nie nadpisał komentarza wartością pustą).
                $comment = $existing->comment;
                if (array_key_exists('comment', $it)) {
                    $trimmed = trim((string)($it['comment'] ?? ''));
                    $comment = $trimmed === '' ? null : $trimmed;
                }

                // Quantity: użyj podanej gdy obecna i różna od null; w przeciwnym
                // razie zachowaj istniejącą.
                $newQty = (array_key_exists('quantity', $it) && $it['quantity'] !== null)
                    ? (float)$it['quantity']
                    : (float)$existing->quantity;

                // Quantity_unit_id: jak wyżej — fallback do istniejącej.
                $newUnitId = (array_key_exists('quantity_unit_id', $it) && $it['quantity_unit_id'] !== null)
                    ? (int)$it['quantity_unit_id']
                    : (int)$existing->quantityUnitId;

                $itemRepo->update(
                    $existing->id,
                    $existing->vendorPartId,
                    $newQty,
                    $newUnitId,
                    $unitPrice,
                    $currency,
                    $comment,
                    $existing->pickedPackSize
                );

                // Termin dostawy TEJ pozycji. Klucz obecny w formularzu =
                // operator go ustawił (albo wyczyścił pustym polem);
                // brak klucza = nie ruszaj tego, co jest w bazie.
                // Zapisywany OSOBNYM UPDATE'em, bo `update()` świadomie
                // nie dotyka tej kolumny (jest też wołany przez
                // inline-edytor pozycji na stronie edycji).
                if (array_key_exists('expected_delivery_date', $it)) {
                    $etaRaw = $it['expected_delivery_date'];
                    $eta = ($etaRaw === null) ? null : trim((string)$etaRaw);
                    $itemRepo->setExpectedDeliveryDate($existing->id, $eta);
                }
            }

            // Numer PO u dostawcy — tylko gdy podano (puste/null = kolumna
            // bez zmian). Walidacja długości odbyła się wyżej.
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
     * Kontrola cen pozycji zamówienia. `unit_price` jest
     * `DECIMAL(30,10) DEFAULT NULL`, więc pozycja PO zna stan
     * "niewycenione" (NULL = "cena nieustalona"), a "brak ceny" to
     * NULL albo cena <= 0. Potwierdzenie `sent → confirmed`
     * wymaga, żeby KAŻDA pozycja miała cenę > 0 — inaczej zamówienie
     * w stanie `confirmed` zawierałoby pozycje bez ceny.
     *
     * `$items` to pozycje z formularza (format jak w `confirmPoWithPrices`:
     * ['item_id' => int, 'unit_price' => ?float]). Puste `unit_price`
     * oznacza "zachowaj cenę istniejącą" — wtedy decyduje cena z bazy.
     * Sprawdzamy WSZYSTKIE pozycje zamówienia, nie tylko te z formularza:
     * pozycja pominięta w formularzu zachowuje swoją cenę, a ta może
     * wynosić NULL albo 0.
     *
     * @param PurchaseOrderItemRepository $itemRepo
     * @param int                          $poId
     * @param array                        $items
     * @throws \InvalidArgumentException gdy któraś pozycja nie ma ceny
     */
    private function assertPoItemsHavePrice(
        PurchaseOrderItemRepository $itemRepo,
        int $poId,
        array $items
    ): void {
        // Mapa cen z formularza: item_id => cena (tylko jawne wartości).
        $pricesFromForm = [];
        foreach ($items as $it) {
            if (!is_array($it)) {
                continue;
            }
            $itemId = (int)($it['item_id'] ?? 0);
            if ($itemId <= 0) {
                continue;
            }
            $raw = $it['unit_price'] ?? null;
            if ($raw === null || $raw === '') {
                continue;   // puste = zachowaj cenę zapisaną w bazie
            }
            $pricesFromForm[$itemId] = (float)$raw;
        }

        // Pozycje zamówienia w kolejności dokumentu (getByPo: ORDER BY id).
        $missing  = [];
        $position = 0;
        foreach ($itemRepo->getByPo($poId) as $poItem) {
            $position++;
            $itemId = (int)$poItem->id;
            $price  = array_key_exists($itemId, $pricesFromForm)
                ? $pricesFromForm[$itemId]
                : ($poItem->unitPrice === null ? null : (float)$poItem->unitPrice);
            if ($price === null || $price <= 0) {
                // Etykieta: numer części u dostawcy (albo nasza nazwa) —
                // operator musi znaleźć tę pozycję na liście.
                $label = trim((string)($poItem->vendorPartNo ?? ''));
                if ($label === '') {
                    $label = trim((string)($poItem->partName ?? ''));
                }
                $suffix = ($label !== '') ? (', ' . $label) : '';
                $missing[] = 'poz. ' . $position . ' (ID ' . $itemId . $suffix . ')';
            }
        }

        if (empty($missing)) {
            return;
        }

        $count = count($missing);
        if ($count === 1) {
            $noun = 'pozycję bez ceny';
            $verb = 'uzupełnij cenę';
        } else {
            // Polska liczba mnoga: 2–4 → "pozycje" (oprócz 12–14), reszta
            // → "pozycji".
            $mod10  = $count % 10;
            $mod100 = $count % 100;
            $twoToFour = ($mod10 >= 2 && $mod10 <= 4) && !($mod100 >= 12 && $mod100 <= 14);
            $noun = $twoToFour ? 'pozycje bez ceny' : 'pozycji bez ceny';
            $verb = 'uzupełnij ceny';
        }
        throw new \InvalidArgumentException(
            "Zamówienie ma {$count} {$noun} — {$verb} przed potwierdzeniem: "
            . implode(', ', $missing) . '.'
        );
    }

    /**
     * Kontrola terminów dostawy pozycji zamówienia. Data dostawy
     * (`purchase__order_item.expected_delivery_date`) nie może być
     * w przeszłości — w UI taki wiersz dostaje flagę „PO TERMINIE"
     * i blokuje potwierdzenie zamówienia.
     *
     * Pusta data = brak własnego terminu dla pozycji = OK (UI pokazuje
     * wtedy domyślną datę z nagłówka zamówienia).
     *
     * `$items` to pozycje z formularza (format jak w `confirmPoWithPrices`:
     * ['item_id' => int, 'expected_delivery_date' => ?string]). Brak klucza
     * = termin zapisany w bazie.
     *
     * @param PurchaseOrderItemRepository $itemRepo
     * @param int                          $poId
     * @param array                        $items
     * @throws \InvalidArgumentException gdy któraś pozycja ma datę w przeszłości
     */
    private function assertPoItemsHaveFutureDeliveryDate(
        PurchaseOrderItemRepository $itemRepo,
        int $poId,
        array $items
    ): void {
        // Daty z formularza: item_id => data (tylko jawne wartości).
        $datesFromForm = [];
        foreach ($items as $it) {
            if (!is_array($it)) {
                continue;
            }
            $itemId = (int)($it['item_id'] ?? 0);
            if ($itemId <= 0) {
                continue;
            }
            if (!array_key_exists('expected_delivery_date', $it)) {
                continue;   // brak klucza = zachowaj termin z bazy
            }
            $raw = trim((string)($it['expected_delivery_date'] ?? ''));
            $datesFromForm[$itemId] = ($raw === '') ? null : $raw;
        }

        $today = date('Y-m-d');

        // Pozycje zamówienia w kolejności dokumentu (getByPo: ORDER BY id).
        $past      = [];
        $position  = 0;
        foreach ($itemRepo->getByPo($poId) as $poItem) {
            $position++;
            $itemId = (int)$poItem->id;
            if (array_key_exists($itemId, $datesFromForm)) {
                $date = $datesFromForm[$itemId];
            } else {
                $date = $poItem->expectedDeliveryDate;
            }
            if ($date === null || trim((string)$date) === '') {
                continue;   // brak terminu = OK
            }
            if (trim((string)$date) >= $today) {
                continue;   // dziś lub później = OK
            }
            $label = trim((string)($poItem->vendorPartNo ?? ''));
            if ($label === '') {
                $label = trim((string)($poItem->partName ?? ''));
            }
            $suffix = ($label !== '') ? (', ' . $label) : '';
            $past[] = 'poz. ' . $position . ' (ID ' . $itemId . $suffix . ')';
        }

        if (empty($past)) {
            return;
        }

        $count = count($past);
        if ($count === 1) {
            $noun = 'pozycję z terminem dostawy w przeszłości';
            $verb = 'popraw termin';
        } else {
            // Polska liczba mnoga: 2–4 → "pozycje" (oprócz 12–14), reszta
            // → "pozycji".
            $mod10  = $count % 10;
            $mod100 = $count % 100;
            $twoToFour = ($mod10 >= 2 && $mod10 <= 4) && !($mod100 >= 12 && $mod100 <= 14);
            $noun = $twoToFour ? 'pozycje z terminem dostawy w przeszłości' : 'pozycji z terminem dostawy w przeszłości';
            $verb = 'popraw terminy';
        }
        throw new \InvalidArgumentException(
            "Zamówienie ma {$count} {$noun} — {$verb} przed potwierdzeniem: "
            . implode(', ', $past) . '.'
        );
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

    /**
     * Waliduje i normalizuje numer referencyjny u dostawcy ("Numer u
     * dostawcy") przekazywany do `markRfqRespondedWithPrices()`.
     * Trimuje whitespace; null/empty → null (czyszczenie kolumny);
     * dłuższy niż 128 znaków → \InvalidArgumentException (komunikat po
     * polsku, bo trafia prosto do UI przez endpoint AJAX).
     */
    private static function normaliseVendorReference(?string $value): ?string {
        if ($value === null) return null;
        $trimmed = trim($value);
        if ($trimmed === '') return null;
        if (strlen($trimmed) > 128) {
            throw new \InvalidArgumentException(
                "Numer oferty u dostawcy jest zbyt długi (max 128 znaków)."
            );
        }
        return $trimmed;
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

        $MsaDB->db->beginTransaction();
        try {
            $number = $this->allocateDocumentNumber('po', $rfq->vendorId);

            $poId = $poRepo->create($rfq->vendorId, $userId, $rfqId);
            $poRepo->setPoNumber($poId, $number);

            foreach ($items as $item) {
                $poItemRepo->create(
                    $poId,
                    $item->vendorPartId,
                    $item->quantity,
                    $item->quantityUnitId,
                    $item->unitPrice, // null ok — pozycja bez ceny zostaje bez ceny
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
     * Record a goods-receipt (GRN) against a confirmed PO.
     *
     * `$documentNumber` is the operator-supplied GRN/WZ number. When null
     * (the common case from the goods-receiving UI) this method
     * auto-allocates the next GRN number from purchase__number_counter
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
        // 'in_progress' is the "operator accepted the receipt header
        // (vendor doc #, comment, date) but hasn't booked items yet"
        // state. The handler has to accept it as a starting state
        // because the natural next step from in_progress is to actually
        // book the items — that's the entire point of the state.
        if (!in_array($po->state, ['confirmed','partially_received','in_progress'], true)) {
            throw new \LogicException("Zamówienie musi być potwierdzone, częściowo przyjęte lub częściowo odebrane, aby przyjąć towar.");
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

            // Over-delivery is NOT blocked: the receive UI only warns when
            // the quantity is far above the remaining amount (> 110%, see
            // maxAllowed in receipt-po-get.php), but the reception is saved.

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
            // 5.0 Auto-allocate the GRN number inside the transaction so the
            // counter increment is atomic with the header insert. allocateDocumentNumber
            // detects the open transaction and joins it (FOR UPDATE relies on it).
            // When the caller passed a non-empty documentNumber we use it verbatim —
            // no allocation, no uniqueness check (matches the PO/RFQ path).
            if ($documentNumber === null) {
                $documentNumber = $this->allocateDocumentNumber('grn', 0);
            }

            // 5.1 Resolve input_type_id.
            //
            // Resolution order (most-specific first):
            //   1. exact name 'Przyjęcie z zamówienia PO'  — the new
            //      production label inserted by
            //      migrate-production-to-purchase-schema.php
            //   2. name LIKE 'purchase%'                   — legacy
            //      "Przyjęcie z purchase order ..." rows from older
            //      installs
            //   3. exact name 'Przyjecie z zamowienia PO'   — ASCII
            //      fallback created by the dev DB's old migration
            //      (Polish diacritics were stripped)
            //   4. id = 1 fallback                          — keeps the
            //      historical "Aktualizacja z Google Sheets" row
            //      resolvable so dev doesn't break on a fresh DB
            //      without the new label
            //
            // Implemented as one prepared statement with an ORDER BY
            // CASE that maps each match to a priority (0..3) and
            // breaks ties on id ASC so the pick is deterministic.
            $typeStmt = $MsaDB->db->prepare(
                "SELECT id FROM `inventory__input_type`
                 WHERE name LIKE 'purchase%'
                    OR name = 'Przyjęcie z zamówienia PO'
                    OR name = 'Przyjecie z zamowienia PO'
                    OR id = 1
                 ORDER BY CASE
                            WHEN name = 'Przyjęcie z zamówienia PO' THEN 0
                            WHEN name LIKE 'purchase%'              THEN 1
                            WHEN name = 'Przyjecie z zamowienia PO' THEN 2
                            ELSE 3
                          END ASC,
                          id ASC
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

    /**
     * Zamyka częściowo dostarczone / „częściowo przyjęte" zamówienie (PO)
     * jako w pełni odebrane (stan `received`). Obsługiwany scenariusz:
     * dostawca dostarczył mniej niż zamówiono i nie dostarczy reszty (np.
     * brak w magazynie, koniec serii, anulacja pozycji) — operator chce
     * zakończyć PO bez oczekiwania na brakujące pozycje.
     *
     * Dozwolone wyłącznie ze stanów `in_progress` lub
     * `partially_received`. Wymaga co najmniej jednego zapisanego
     * przyjęcia (`purchase__order_receipt`) — szkic `in_progress`
     * (utworzony przez acceptReceiptMetadata) też się liczy. Bez tego
     * operator „zamykałby" PO, na którym nic nie przyjął.
     *
     * Uwaga: stan `received` jest traktowany jako terminalny —
     * `OrderReceiptRepository::delete()` odmawia kasowania przyjęcia
     * dla PO w tym stanie (zob. delete() w
     * class-orderreceiptrepository.php), więc po wywołaniu tej metody
     * nie da się już cofnąć historii przyjęcia dla tego zamówienia.
     *
     * Komunikaty błędów po polsku — trafiają do operatora przez
     * endpoint AJAX.
     *
     * @param int $poId
     * @return array{poId: int, poState: string}
     */
    public function closePoAsReceived(int $poId): array {
        if ($poId <= 0) {
            throw new \InvalidArgumentException("poId must be positive.");
        }

        $MsaDB  = $this->MsaDB;
        $poRepo = new PurchaseOrderRepository($MsaDB);

        $po = $poRepo->getById($poId);
        if ($po === null) {
            throw new \LogicException("Nie znaleziono zamówienia.");
        }
        if (!in_array($po->state, ['in_progress', 'partially_received'], true)) {
            throw new \LogicException(
                "Zamówienie jest w stanie '{$po->state}'. Zamknięcie jako odebrane jest możliwe tylko ze stanu „częściowo przyjęte\" lub „częściowo odebrane\"."
            );
        }

        // Wymóg co najmniej jednego zapisanego przyjęcia (szkic in_progress
        // też się liczy) — bez tego operator „zamykałby" PO, na którym nic
        // nie zostało przyjęte.
        $existsStmt = $MsaDB->db->prepare(
            "SELECT 1 FROM `purchase__order_receipt` WHERE `po_id` = ? LIMIT 1"
        );
        $existsStmt->execute([$poId]);
        if ($existsStmt->fetchColumn() === false) {
            throw new \LogicException(
                "Nie można zamknąć zamówienia jako odebranego bez zapisanego przyjęcia."
            );
        }

        $MsaDB->db->beginTransaction();
        try {
            $poRepo->setState($poId, 'received');
            $MsaDB->db->commit();
            return ['poId' => $poId, 'poState' => 'received'];
        } catch (\Throwable $e) {
            if ($MsaDB->db->inTransaction()) {
                $MsaDB->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Zamyka zamówienie (PO) ze strony formularza przyjęcia — operator
     * sam wybiera stan wynikowy (`received` albo `cancelled`) w oknie
     * potwierdzenia. Obsługiwane scenariusze:
     *   - część pozycji nie zostanie dostarczona (np. dostawca wysłał
     *     zamiennik) i operator chce zakończyć PO jako w pełni odebrane
     *     (`received`) bez zapisywania braków;
     *   - dostawa się nie odbędzie / cofnęliśmy płatność i trzeba
     *     zamknąć PO jako anulowane (`cancelled`), nawet jeśli nic nie
     *     zostało przyjęte.
     *
     * Dozwolone ze stanów `confirmed`, `in_progress`,
     * `partially_received`. W odróżnieniu od {@see closePoAsReceived()}
     * metoda NIE wymaga istniejącego wiersza `purchase__order_receipt`
     * — cały sens tego wejścia to umożliwienie zamknięcia PO, na
     * którym nie przyjęto jeszcze nic (np. `confirmed` → `cancelled`).
     *
     * Jest to CELOWE rozszerzenie węższego guarda {@see cancelPo()},
     * który blokuje `partially_received → cancelled` (strona edycji PO
     * zakazuje anulowania po przyjęciu choćby części dostawy — chroni
     * to rozliczone pozycje przed wycofaniem). Tutaj operator jawnie
     * wybiera wynik w oknie potwierdzenia, więc dopuszczamy ten ruch;
     * nadal odrzucamy stany `draft`/`sent` (zamówienie nie zostało
     * jeszcze potwierdzone przez dostawcę — anulowanie takiego PO
     * należy do cancelPo na stronie edycji) oraz stany już terminalne
     * (`received`, `cancelled`).
     *
     * Oba stany wynikowe są traktowane jako terminalne — po `received`
     * `OrderReceiptRepository::delete()` odmawia kasowania przyjęcia
     * dla tego PO (zob. delete() w
     * class-orderreceiptrepository.php), więc nie da się już cofnąć
     * historii przyjęcia. `cancelled` wyłącza PO z kolejki i listy
     * aktywnych dokumentów.
     *
     * Transakcja: tak jak `createReceipt()` — dołączamy otwartą
     * transakcję wywołującego zamiast otwierać własną
     * (`$ownedTransaction = !$MsaDB->db->inTransaction();`). Dziś żaden
     * endpoint nie otacza tego wywołania własną transakcją, ale wzorzec
     * zostaje spójny z resztą handlera.
     *
     * Komunikaty błędów po polsku — trafiają do operatora przez
     * endpoint AJAX (`receipt-close-po.php`).
     *
     * @param int    $poId
     * @param int    $userId
     * @param string $targetState    'received' (domyślnie) lub 'cancelled'
     * @return array{poId: int, poState: string}
     */
    public function closePoFromReceive(int $poId, int $userId, string $targetState = 'received'): array {
        if ($poId <= 0) {
            throw new \InvalidArgumentException("poId must be positive.");
        }
        if ($userId <= 0) {
            throw new \InvalidArgumentException("userId must be positive.");
        }
        if (!in_array($targetState, ['received', 'cancelled'], true)) {
            throw new \InvalidArgumentException(
                "Stan zamknięcia musi być 'received' albo 'cancelled', podano: {$targetState}."
            );
        }

        $MsaDB  = $this->MsaDB;
        $poRepo = new PurchaseOrderRepository($MsaDB);

        $po = $poRepo->getById($poId);
        if ($po === null) {
            throw new \LogicException("Nie znaleziono zamówienia.");
        }
        if (!in_array($po->state, ['confirmed', 'in_progress', 'partially_received'], true)) {
            throw new \LogicException(
                "Zamówienie jest w stanie '{$po->state}'. Zamknięcie z tej strony jest możliwe tylko dla zamówień potwierdzonych, częściowo przyjęte lub częściowo odebranych."
            );
        }

        // Tu NIE sprawdzamy istnienia wiersza purchase__order_receipt —
        // cały sens tej ścieżki to umożliwienie zamknięcia PO, na
        // którym nie przyjęto jeszcze nic (np. confirmed → cancelled).

        $ownedTransaction = !$MsaDB->db->inTransaction();
        if ($ownedTransaction) {
            $MsaDB->db->beginTransaction();
        }

        try {
            $poRepo->setState($poId, $targetState);
            if ($ownedTransaction) {
                $MsaDB->db->commit();
            }
            return ['poId' => $poId, 'poState' => $targetState];
        } catch (\Throwable $e) {
            if ($ownedTransaction && $MsaDB->db->inTransaction()) {
                $MsaDB->db->rollBack();
            }
            throw $e;
        }
    }

    // ═════════════════════════════════════════════════════════════════
    //  Dostawy: przyjęcie towaru wg faktury / WZ dostawcy
    //  (docs/procurement/RECEIPT_INVOICE_FLOW_PLAN.md §6)
    //
    //  Jedna dostawa = jeden dokument dostawcy, pozycje wpisywane jak na
    //  fakturze, automatycznie dopasowywane do otwartych PO przez
    //  DeliveryMatcher. Przy zatwierdzeniu powstaje po jednym
    //  purchase__order_receipt na każde dotknięte PO (rzut dostawy na
    //  zamówienie), a stan magazynu zmieniają wyłącznie wiersze
    //  inventory__parts (liczą triggery MySQL).
    //
    //  Wyjątki: InvalidArgumentException = błędne dane wejściowe,
    //  LogicException = stan / reguła biznesowa. Komunikaty po polsku —
    //  endpointy AJAX zwracają je operatorowi wprost.
    // ═════════════════════════════════════════════════════════════════

    /** Uruchamia $fn w transakcji (dołącza do otwartej transakcji wywołującego). */
    private function inDeliveryTransaction(callable $fn) {
        $db = $this->MsaDB->db;
        $owned = !$db->inTransaction();
        if ($owned) {
            $db->beginTransaction();
        }
        try {
            $result = $fn();
            if ($owned) {
                $db->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($owned && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /** Wczytuje dostawę z blokadą wiersza (FOR UPDATE) i wymaga stanu draft. */
    private function lockDraftDelivery(DeliveryRepository $repo, int $deliveryId): Delivery {
        if ($deliveryId <= 0) {
            throw new \InvalidArgumentException("deliveryId must be positive.");
        }
        $delivery = $repo->lockById($deliveryId);
        if ($delivery === null) {
            throw new \LogicException("Nie znaleziono dostawy.");
        }
        if ($delivery->state !== 'draft') {
            throw new \LogicException("Dostawa nie jest już szkicem — nie można jej zmieniać.");
        }
        return $delivery;
    }

    /** Magazyn musi istnieć i być aktywny. */
    private function assertDeliveryMagazineActive(int $subMagazineId, string $prefix = ''): void {
        $stmt = $this->MsaDB->db->prepare("SELECT isActive FROM `magazine__list` WHERE sub_magazine_id = ?");
        $stmt->execute([$subMagazineId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new \LogicException($prefix . "magazyn {$subMagazineId} nie istnieje.");
        }
        if ((int)$row['isActive'] !== 1) {
            throw new \LogicException($prefix . "magazyn {$subMagazineId} jest nieaktywny.");
        }
    }

    /** Liczba z formularza (dopuszcza przecinek). null dla pustego. */
    private function parseDeliveryNumber($value, string $label, bool $allowNull) {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            if ($allowNull) return null;
            throw new \InvalidArgumentException("{$label}: wartość jest wymagana.");
        }
        if (is_string($value)) {
            $value = str_replace([' ', ','], ['', '.'], trim($value));
        }
        if (!is_numeric($value)) {
            throw new \InvalidArgumentException("{$label}: nieprawidłowa liczba.");
        }
        return (float)$value;
    }

    /**
     * Data/czas z formularza → 'Y-m-d H:i:s'. Akceptuje 'Y-m-d',
     * 'Y-m-d H:i[:s]' oraz 'Y-m-dTH:i[:s]'. Odrzuca daty z przyszłości
     * (tolerancja jak w createReceipt: +1 dzień).
     */
    private function parseDeliveryDateTime(?string $value): ?string {
        if ($value === null) return null;
        $value = trim($value);
        if ($value === '') return null;
        $dt = null;
        foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d\TH:i:s', 'Y-m-d\TH:i', 'Y-m-d'] as $fmt) {
            $try = \DateTime::createFromFormat($fmt, $value);
            $errors = \DateTime::getLastErrors();
            $clean = $errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0);
            if ($try !== false && $clean && $try->format($fmt) === $value) {
                $dt = $try;
                if ($fmt === 'Y-m-d') {
                    $dt->setTime(0, 0, 0);
                }
                break;
            }
        }
        if ($dt === null) {
            throw new \InvalidArgumentException("Nieprawidłowy format daty przyjęcia.");
        }
        if ($dt > (new \DateTime())->modify('+1 day')) {
            throw new \InvalidArgumentException("Data przyjęcia nie może być z przyszłości.");
        }
        return $dt->format('Y-m-d H:i:s');
    }

    private function parseDeliveryDate(?string $value): ?string {
        if ($value === null) return null;
        $value = trim($value);
        if ($value === '') return null;
        $d = \DateTime::createFromFormat('Y-m-d', $value);
        if ($d === false || $d->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException("Data dokumentu musi być w formacie RRRR-MM-DD.");
        }
        return $value;
    }

    /**
     * Normalizuje nagłówek dostawy. Zwraca tylko klucze obecne w $header
     * (kolumna => wartość). Klucze: document_type, vendor_document_number,
     * document_date, currency, default_sub_magazine_id, received_at, comment.
     */
    private function normaliseDeliveryHeader(array $header): array {
        $out = [];
        if (array_key_exists('document_type', $header)) {
            $type = (string)$header['document_type'];
            if (!in_array($type, ['invoice', 'wz'], true)) {
                throw new \InvalidArgumentException("Typ dokumentu musi być: faktura (invoice) albo WZ (wz).");
            }
            $out['document_type'] = $type;
        }
        if (array_key_exists('vendor_document_number', $header)) {
            $nr = $header['vendor_document_number'] === null ? '' : trim((string)$header['vendor_document_number']);
            if (mb_strlen($nr) > 100) {
                throw new \InvalidArgumentException("Numer dokumentu dostawcy może mieć najwyżej 100 znaków.");
            }
            $out['vendor_document_number'] = $nr === '' ? null : $nr;
        }
        if (array_key_exists('document_date', $header)) {
            $out['document_date'] = $this->parseDeliveryDate($header['document_date'] === null ? null : (string)$header['document_date']);
        }
        if (array_key_exists('currency', $header)) {
            $cur = strtoupper(trim((string)($header['currency'] ?? '')));
            if ($cur === '' || strlen($cur) > 8) {
                throw new \InvalidArgumentException("Waluta dokumentu jest wymagana (maks. 8 znaków).");
            }
            $out['currency'] = $cur;
        }
        if (array_key_exists('default_sub_magazine_id', $header)) {
            // Magazyn 0 jest prawdziwym magazynem: null / '' = brak (NULL),
            // każda liczba całkowita (także 0) = konkretny magazyn.
            $rawMag = $header['default_sub_magazine_id'];
            if (is_string($rawMag)) {
                $rawMag = trim($rawMag);
            }
            if ($rawMag === null || $rawMag === '') {
                $out['default_sub_magazine_id'] = null;
            } else {
                $mag = filter_var($rawMag, FILTER_VALIDATE_INT);
                if ($mag === false) {
                    throw new \InvalidArgumentException("Magazyn domyślny: nieprawidłowy identyfikator magazynu.");
                }
                $this->assertDeliveryMagazineActive($mag, 'Magazyn domyślny: ');
                $out['default_sub_magazine_id'] = $mag;
            }
        }
        if (array_key_exists('received_at', $header)) {
            $out['received_at'] = $this->parseDeliveryDateTime($header['received_at'] === null ? null : (string)$header['received_at']);
        }
        if (array_key_exists('comment', $header)) {
            $c = $header['comment'] === null ? '' : trim((string)$header['comment']);
            $out['comment'] = $c === '' ? null : $c;
        }
        return $out;
    }

    /**
     * Waliduje dane pozycji dostawy (draft). Zwraca znormalizowane wartości.
     *
     * @return array{vendorPartId:int, quantity:float, unitPrice:?float, subMagazineId:?int, comment:?string}
     */
    private function normaliseDeliveryLineInput(Delivery $delivery, int $vendorPartId, $quantity, $unitPrice, ?int $subMagazineId, ?string $comment): array {
        $lineRepo = new DeliveryLineRepository($this->MsaDB);

        if ($vendorPartId <= 0) {
            throw new \InvalidArgumentException("Nie wybrano artykułu.");
        }
        $vp = $lineRepo->getVendorPartInfo($vendorPartId);
        if ($vp === null) {
            throw new \LogicException("Nie znaleziono artykułu dostawcy {$vendorPartId}.");
        }
        if ((int)$vp['vendorId'] !== $delivery->vendorId) {
            throw new \LogicException("Artykuł {$vp['vendorPartNo']} nie należy do dostawcy tej dostawy.");
        }
        if ((int)$vp['isActive'] !== 1) {
            throw new \LogicException("Artykuł {$vp['vendorPartNo']} jest nieaktywny.");
        }

        $qty = $this->parseDeliveryNumber($quantity, 'Ilość', false);
        if ($qty <= 0) {
            throw new \InvalidArgumentException("Ilość musi być większa od zera.");
        }
        $price = $this->parseDeliveryNumber($unitPrice, 'Cena', true);
        if ($price !== null && $price < 0) {
            throw new \InvalidArgumentException("Cena nie może być ujemna.");
        }

        // null = brak magazynu na pozycji (użyj domyślnego); 0 to prawdziwy
        // magazyn, więc walidujemy każdą liczbę całkowitą.
        if ($subMagazineId !== null) {
            $this->assertDeliveryMagazineActive($subMagazineId);
        }
        if ($comment !== null) {
            $comment = trim($comment);
            if ($comment === '') $comment = null;
        }
        return [
            'vendorPartId'  => $vendorPartId,
            'quantity'      => $qty,
            'unitPrice'     => $price,
            'subMagazineId' => $subMagazineId,
            'comment'       => $comment,
        ];
    }

    /**
     * Zapisuje przydziały automatyczne z planu matchera (poprzednie
     * automatyczne całej dostawy są kasowane; ręczne zostają).
     */
    private function persistAutoAllocations(int $deliveryId, array $plan): void {
        $lineRepo = new DeliveryLineRepository($this->MsaDB);
        $lineRepo->deleteAutoAllocationsByDelivery($deliveryId);
        foreach ($plan['lines'] as $lineId => $entry) {
            foreach ($entry['allocations'] as $a) {
                if ($a['is_manual']) continue;
                $lineRepo->insertAllocation((int)$lineId, (int)$a['po_item_id'], (float)$a['quantity'], false);
            }
        }
    }

    /** Przelicza przydziały automatyczne całego szkicu i zwraca plan. */
    private function rematchDraft(int $deliveryId): array {
        $plan = (new DeliveryMatcher($this->MsaDB))->match($deliveryId);
        $this->persistAutoAllocations($deliveryId, $plan);
        return $plan;
    }

    /** Waluta domyślna dostawcy (kod), 'PLN' gdy brak. */
    private function vendorDefaultCurrencyCode(int $vendorId): string {
        $stmt = $this->MsaDB->db->prepare(
            "SELECT c.code FROM `list__vendor` v JOIN `list__currency` c ON c.id = v.default_currency WHERE v.id = ?"
        );
        $stmt->execute([$vendorId]);
        $code = $stmt->fetchColumn();
        return ($code !== false && $code !== null && $code !== '') ? strtoupper((string)$code) : 'PLN';
    }

    // ── Szkic: nagłówek ─────────────────────────────────────────

    /**
     * Tworzy szkic dostawy dla dostawcy. Domyślna waluta = waluta
     * dostawcy, data przyjęcia pusta (przy zatwierdzeniu = teraz).
     * $header: patrz normaliseDeliveryHeader(). Zwraca id dostawy.
     */
    public function createDeliveryDraft(int $vendorId, int $userId, array $header = []): int {
        if ($vendorId <= 0) throw new \InvalidArgumentException("vendorId must be positive.");
        if ($userId <= 0)   throw new \InvalidArgumentException("userId must be positive.");

        $stmt = $this->MsaDB->db->prepare("SELECT isActive FROM `list__vendor` WHERE id = ?");
        $stmt->execute([$vendorId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new \LogicException("Nie znaleziono dostawcy.");
        }
        if ((int)$row['isActive'] !== 1) {
            throw new \LogicException("Dostawca jest nieaktywny.");
        }

        $cols = $this->normaliseDeliveryHeader($header);
        if (!isset($cols['currency'])) {
            $cols['currency'] = $this->vendorDefaultCurrencyCode($vendorId);
        }
        return $this->inDeliveryTransaction(function () use ($vendorId, $userId, $cols) {
            return (new DeliveryRepository($this->MsaDB))->create($vendorId, $userId, $cols);
        });
    }

    /** Zmienia nagłówek szkicu. Zwraca pełny stan szkicu. */
    public function updateDeliveryHeader(int $deliveryId, array $header): array {
        $cols = $this->normaliseDeliveryHeader($header);
        return $this->inDeliveryTransaction(function () use ($deliveryId, $cols) {
            $repo = new DeliveryRepository($this->MsaDB);
            $this->lockDraftDelivery($repo, $deliveryId);
            if (!empty($cols)) {
                $repo->updateHeader($deliveryId, $cols);
            }
            return $this->buildDeliveryState($deliveryId);
        });
    }

    // ── Szkic: pozycje ──────────────────────────────────────────

    /**
     * Dodaje pozycję faktury, dopasowuje całą dostawę i zwraca pełny stan.
     * $quantity / $unitPrice: liczba lub tekst (dopuszcza przecinek).
     * Ilość w jednostce pozycji PO.
     */
    public function addDeliveryLine(int $deliveryId, int $vendorPartId, $quantity, $unitPrice = null, ?int $subMagazineId = null, ?string $comment = null): array {
        return $this->inDeliveryTransaction(function () use ($deliveryId, $vendorPartId, $quantity, $unitPrice, $subMagazineId, $comment) {
            $repo = new DeliveryRepository($this->MsaDB);
            $delivery = $this->lockDraftDelivery($repo, $deliveryId);
            $in = $this->normaliseDeliveryLineInput($delivery, $vendorPartId, $quantity, $unitPrice, $subMagazineId, $comment);

            (new DeliveryLineRepository($this->MsaDB))->create(
                $deliveryId, $in['vendorPartId'], $in['quantity'], $in['unitPrice'], $in['subMagazineId'], $in['comment']
            );
            $plan = $this->rematchDraft($deliveryId);
            $repo->touch($deliveryId);
            return $this->buildDeliveryState($deliveryId, $plan);
        });
    }

    /**
     * Edytuje pozycję szkicu i dopasowuje całość od nowa. Gdy zmienił się
     * artykuł albo ilość spadła poniżej sumy przydziałów ręcznych, ręczne
     * przydziały tej pozycji są kasowane (wraca podział automatyczny).
     */
    public function updateDeliveryLine(int $lineId, int $vendorPartId, $quantity, $unitPrice = null, ?int $subMagazineId = null, ?string $comment = null): array {
        return $this->inDeliveryTransaction(function () use ($lineId, $vendorPartId, $quantity, $unitPrice, $subMagazineId, $comment) {
            $repo = new DeliveryRepository($this->MsaDB);
            $lineRepo = new DeliveryLineRepository($this->MsaDB);

            $line = $lineRepo->getById($lineId);
            if ($line === null) {
                throw new \LogicException("Nie znaleziono pozycji dostawy.");
            }
            $delivery = $this->lockDraftDelivery($repo, $line->deliveryId);
            $line = $lineRepo->getById($lineId);  // po blokadzie
            if ($line === null) {
                throw new \LogicException("Nie znaleziono pozycji dostawy.");
            }
            $in = $this->normaliseDeliveryLineInput($delivery, $vendorPartId, $quantity, $unitPrice, $subMagazineId, $comment);

            $manualSum = 0.0;
            foreach ($line->allocations as $a) {
                if ($a->isManual) $manualSum += $a->quantity;
            }
            $lineRepo->update($lineId, $in['vendorPartId'], $in['quantity'], $in['unitPrice'], $in['subMagazineId'], $in['comment']);
            if ($in['vendorPartId'] !== $line->vendorPartId || $manualSum > $in['quantity'] + 1e-6) {
                $lineRepo->deleteAllocationsByLine($lineId);
            }
            $plan = $this->rematchDraft($line->deliveryId);
            $repo->touch($line->deliveryId);
            return $this->buildDeliveryState($line->deliveryId, $plan);
        });
    }

    /** Usuwa pozycję szkicu, dopasowuje resztę od nowa, zwraca pełny stan. */
    public function removeDeliveryLine(int $lineId): array {
        return $this->inDeliveryTransaction(function () use ($lineId) {
            $repo = new DeliveryRepository($this->MsaDB);
            $lineRepo = new DeliveryLineRepository($this->MsaDB);

            $line = $lineRepo->getById($lineId);
            if ($line === null) {
                throw new \LogicException("Nie znaleziono pozycji dostawy.");
            }
            $this->lockDraftDelivery($repo, $line->deliveryId);
            $lineRepo->delete($lineId);
            $plan = $this->rematchDraft($line->deliveryId);
            $repo->touch($line->deliveryId);
            return $this->buildDeliveryState($line->deliveryId, $plan);
        });
    }

    /**
     * Ręczny podział pozycji na pozycje zamówień. $allocations =
     * [[po_item_id, qty], ...] (albo [['po_item_id'=>..,'quantity'=>..], ...]).
     * Pusta tablica kasuje przydziały ręczne tej pozycji (powrót do
     * automatu). Reszta ilości pozycji (ponad podane) jest nadal
     * rozdzielana automatycznie. Walidacja: pozycja PO tego dostawcy, ten
     * sam artykuł, PO w stanie otwartym, ilość mieści się w pozostałej
     * (z uwzględnieniem ręcznych przydziałów innych pozycji).
     */
    public function setLineAllocation(int $lineId, array $allocations): array {
        return $this->inDeliveryTransaction(function () use ($lineId, $allocations) {
            $repo = new DeliveryRepository($this->MsaDB);
            $lineRepo = new DeliveryLineRepository($this->MsaDB);

            $line = $lineRepo->getById($lineId);
            if ($line === null) {
                throw new \LogicException("Nie znaleziono pozycji dostawy.");
            }
            $delivery = $this->lockDraftDelivery($repo, $line->deliveryId);

            // Normalizacja wejścia.
            $wanted = [];
            $sum = 0.0;
            $n = 0;
            foreach ($allocations as $raw) {
                $n++;
                $itemId = (int)($raw['po_item_id'] ?? ($raw[0] ?? 0));
                $qty = $this->parseDeliveryNumber($raw['quantity'] ?? ($raw[1] ?? null), "Przydział #{$n}: ilość", false);
                if ($itemId <= 0) {
                    throw new \InvalidArgumentException("Przydział #{$n}: brak pozycji zamówienia.");
                }
                if ($qty <= 0) {
                    throw new \InvalidArgumentException("Przydział #{$n}: ilość musi być większa od zera.");
                }
                if (isset($wanted[$itemId])) {
                    throw new \InvalidArgumentException("Ta sama pozycja zamówienia występuje w przydziale więcej niż raz.");
                }
                $wanted[$itemId] = $qty;
                $sum += $qty;
            }
            if ($sum > $line->quantity + 1e-6) {
                throw new \InvalidArgumentException("Suma przydziałów przekracza ilość z faktury.");
            }

            // Pojemność: pozostało − ręczne przydziały INNYCH pozycji.
            $usedByOthers = [];
            foreach ($lineRepo->getByDelivery($line->deliveryId) as $other) {
                if ($other->id === $lineId) continue;
                foreach ($other->allocations as $a) {
                    if ($a->isManual) {
                        $usedByOthers[$a->poItemId] = ($usedByOthers[$a->poItemId] ?? 0.0) + $a->quantity;
                    }
                }
            }
            if (!empty($wanted)) {
                $ids = array_keys($wanted);
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $states = DeliveryMatcher::OPEN_PO_STATES;
                $sph = implode(',', array_fill(0, count($states), '?'));
                $stmt = $this->MsaDB->db->prepare(
                    "SELECT i.id, i.vendor_part_id, i.quantity, i.quantity_received, po.vendor_id, po.state, po.po_number
                       FROM `purchase__order_item` i
                       JOIN `purchase__order` po ON i.po_id = po.id
                      WHERE i.id IN ({$ph})
                      ORDER BY i.id ASC
                      FOR UPDATE"
                );
                $stmt->execute($ids);
                $found = [];
                foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                    $found[(int)$r['id']] = $r;
                }
                foreach ($wanted as $itemId => $qty) {
                    if (!isset($found[$itemId])) {
                        throw new \LogicException("Pozycja zamówienia {$itemId} nie istnieje.");
                    }
                    $r = $found[$itemId];
                    $label = $r['po_number'] ?? ('#' . $itemId);
                    if ((int)$r['vendor_id'] !== $delivery->vendorId) {
                        throw new \LogicException("Zamówienie {$label} należy do innego dostawcy.");
                    }
                    if ((int)$r['vendor_part_id'] !== $line->vendorPartId) {
                        throw new \LogicException("Pozycja zamówienia w {$label} dotyczy innego artykułu.");
                    }
                    if (!in_array($r['state'], $states, true)) {
                        throw new \LogicException("Zamówienie {$label} nie jest otwarte do przyjęcia.");
                    }
                    $free = (float)$r['quantity'] - (float)$r['quantity_received'] - ($usedByOthers[$itemId] ?? 0.0);
                    if ($qty > $free + 1e-6) {
                        throw new \LogicException("Zamówienie {$label}: do przyjęcia pozostało tylko " . rtrim(rtrim(number_format(max(0.0, $free), 10, '.', ''), '0'), '.') . ".");
                    }
                }
            }

            $lineRepo->deleteAllocationsByLine($lineId);
            foreach ($wanted as $itemId => $qty) {
                $lineRepo->insertAllocation($lineId, $itemId, $qty, true);
            }
            $plan = $this->rematchDraft($line->deliveryId);
            $repo->touch($line->deliveryId);
            return $this->buildDeliveryState($line->deliveryId, $plan);
        });
    }

    /** Kasuje szkic (kaskadowo pozycje i przydziały). Nic nie zaksięgowano. */
    public function discardDeliveryDraft(int $deliveryId): bool {
        return $this->inDeliveryTransaction(function () use ($deliveryId) {
            $repo = new DeliveryRepository($this->MsaDB);
            $this->lockDraftDelivery($repo, $deliveryId);
            return $repo->deleteDraft($deliveryId);
        });
    }

    // ── Pełny stan dostawy ──────────────────────────────────────

    /**
     * Pełny stan dostawy dla UI: nagłówek, pozycje z przydziałami i flagami,
     * podgląd per PO (przed/po), błędy ręcznych przydziałów, blokady
     * zatwierdzenia. Dla szkicu liczone na żywo przez DeliveryMatcher
     * (tylko odczyt); dla dostawy zaksięgowanej / anulowanej — z zapisanych
     * danych, plus podsumowanie PO.
     */
    public function getDeliveryState(int $deliveryId): array {
        return $this->buildDeliveryState($deliveryId);
    }

    private function buildDeliveryState(int $deliveryId, ?array $plan = null): array {
        $deliveryRepo = new DeliveryRepository($this->MsaDB);
        $lineRepo = new DeliveryLineRepository($this->MsaDB);

        $delivery = $deliveryRepo->getById($deliveryId);
        if ($delivery === null) {
            throw new \LogicException("Nie znaleziono dostawy.");
        }
        $lines = $lineRepo->getByDelivery($deliveryId);
        $isDraft = $delivery->state === 'draft';
        if ($isDraft && $plan === null) {
            $plan = (new DeliveryMatcher($this->MsaDB))->match($deliveryId);
        }

        $outLines = [];
        $warningLines = 0;
        foreach ($lines as $l) {
            $allocs = [];
            $flags = [];
            $allocated = 0.0;
            $surplus = 0.0;
            if ($isDraft && isset($plan['lines'][$l->id])) {
                $entry = $plan['lines'][$l->id];
                $allocs = $entry['allocations'];
                $flags = $entry['flags'];
                $allocated = $entry['allocated'];
                $surplus = $entry['surplus'];
            } else {
                foreach ($l->allocations as $a) {
                    $allocs[] = [
                        'id'          => $a->id,
                        'po_item_id'  => $a->poItemId,
                        'po_id'       => $a->poId,
                        'po_number'   => $a->poNumber,
                        'quantity'    => $a->quantity,
                        'is_manual'   => $a->isManual,
                        'unit_price'  => $a->poUnitPrice,
                        'currency'    => $a->poCurrency,
                    ];
                    $allocated += $a->quantity;
                }
                $surplus = max(0.0, round($l->quantity - $allocated, 10));
            }
            if (!empty(array_intersect_key($flags, array_flip(['price_mismatch', 'surplus', 'not_on_po', 'currency_mismatch', 'duplicate_line'])))) {
                $warningLines++;
            }
            $outLines[] = [
                'id'                       => $l->id,
                'line_no'                  => $l->lineNo,
                'vendor_part_id'           => $l->vendorPartId,
                'vendor_part_no'           => $l->vendorPartNo,
                'producer_part_no'         => $l->producerPartNo,
                'part_name'                => $l->partName,
                'quantity'                 => $l->quantity,
                'unit_price'               => $l->unitPrice,
                'sub_magazine_id'          => $l->subMagazineId,
                'sub_magazine_name'        => $l->subMagazineName,
                'effective_sub_magazine_id'=> $l->subMagazineId ?? $delivery->defaultSubMagazineId,
                'comment'                  => $l->comment,
                'inventory_parts_id'       => $l->inventoryPartsId,
                'allocations'              => $allocs,
                'allocated'                => $allocated,
                'surplus'                  => $surplus,
                'flags'                    => $flags,
            ];
        }

        $pos = [];
        $errors = [];
        $blockers = [];
        if ($isDraft) {
            $pos = array_values($plan['po'] ?? []);
            $errors = $plan['errors'] ?? [];
            $blockers = $this->collectDeliveryBlockers($delivery, $lines, $plan ?? ['lines' => [], 'errors' => []]);
        } else {
            $stmt = $this->MsaDB->db->prepare(
                "SELECT DISTINCT po_id FROM `purchase__order_receipt` WHERE delivery_id = ? ORDER BY po_id ASC"
            );
            $stmt->execute([$deliveryId]);
            foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $poId) {
                $pos[] = $this->summariseDeliveryPo((int)$poId);
            }
        }

        $duplicates = [];
        if ($isDraft && $delivery->vendorDocumentNumber !== null) {
            foreach ($deliveryRepo->findByVendorDocumentNumber($delivery->vendorId, $delivery->vendorDocumentNumber, $deliveryId) as $d) {
                $duplicates[] = ['id' => $d->id, 'state' => $d->state, 'grn_number' => $d->grnNumber];
            }
        }

        return [
            'delivery' => [
                'id'                       => $delivery->id,
                'vendor_id'                => $delivery->vendorId,
                'vendor_name'              => $delivery->vendorName,
                'state'                    => $delivery->state,
                'document_type'            => $delivery->documentType,
                'vendor_document_number'   => $delivery->vendorDocumentNumber,
                'document_date'            => $delivery->documentDate,
                'grn_number'               => $delivery->grnNumber,
                'currency'                 => $delivery->currency,
                'default_sub_magazine_id'  => $delivery->defaultSubMagazineId,
                'default_sub_magazine_name'=> $delivery->defaultSubMagazineName,
                'received_at'              => $delivery->receivedAt,
                'transfer_group_id'        => $delivery->transferGroupId,
                'created_by'               => $delivery->createdBy,
                'created_by_name'          => $delivery->createdByName,
                'posted_by'                => $delivery->postedBy,
                'created_at'               => $delivery->createdAt,
                'posted_at'                => $delivery->postedAt,
                'updated_at'               => $delivery->updatedAt,
                'comment'                  => $delivery->comment,
            ],
            'lines'              => $outLines,
            'pos'                => $pos,
            'errors'             => $errors,
            'duplicate_document' => $duplicates,
            'summary'            => [
                'line_count'    => count($outLines),
                'po_count'      => count($pos),
                'warning_count' => $warningLines,
                'can_post'      => $isDraft && empty($blockers),
                'blockers'      => $blockers,
            ],
        ];
    }

    /**
     * Lista powodów, dla których dostawy nie wolno zatwierdzić (Polskie
     * komunikaty). Pusta = można księgować. Wspólna dla podglądu
     * (getDeliveryState) i postDelivery().
     *
     * @param DeliveryLine[] $lines
     */
    private function collectDeliveryBlockers(Delivery $delivery, array $lines, array $plan): array {
        $blockers = [];
        if (empty($lines)) {
            $blockers[] = "Dostawa nie ma żadnych pozycji.";
        }
        if ($delivery->vendorDocumentNumber === null || trim($delivery->vendorDocumentNumber) === '') {
            $blockers[] = "Podaj numer faktury / WZ dostawcy.";
        }
        foreach ($plan['errors'] ?? [] as $err) {
            $blockers[] = $err['message'];
        }

        $magCache = [];
        foreach ($lines as $l) {
            $label = "Pozycja {$l->lineNo} (" . ($l->vendorPartNo ?? ('vp#' . $l->vendorPartId)) . ")";
            $magId = $l->subMagazineId ?? $delivery->defaultSubMagazineId;
            if ($magId === null) {
                $blockers[] = "{$label}: nie wybrano magazynu.";
            } else {
                if (!array_key_exists($magId, $magCache)) {
                    $stmt = $this->MsaDB->db->prepare("SELECT isActive FROM `magazine__list` WHERE sub_magazine_id = ?");
                    $stmt->execute([$magId]);
                    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                    $magCache[$magId] = $row === false ? 'missing' : ((int)$row['isActive'] === 1 ? 'ok' : 'inactive');
                }
                if ($magCache[$magId] === 'missing') {
                    $blockers[] = "{$label}: magazyn {$magId} nie istnieje.";
                } elseif ($magCache[$magId] === 'inactive') {
                    $blockers[] = "{$label}: magazyn {$magId} jest nieaktywny.";
                }
            }

            $flags = $plan['lines'][$l->id]['flags'] ?? [];
            if (!empty($flags['currency_mismatch'])) {
                $po = $flags['currency_mismatch'][0];
                $blockers[] = "{$label}: waluta zamówienia {$po['po_number']} ({$po['po_currency']}) różni się od waluty dokumentu ({$po['doc_currency']}).";
            }
            if ((!empty($flags['surplus']) || !empty($flags['not_on_po'])) && ($l->comment === null || trim($l->comment) === '')) {
                $blockers[] = "{$label}: ilość ponad zamówienie / artykuł spoza zamówień wymaga komentarza.";
            }
        }
        return $blockers;
    }

    /** DECIMAL → float, wspólna dla podsumowań PO. */
    private function poTotals(int $poId): array {
        $stmt = $this->MsaDB->db->prepare(
            "SELECT COALESCE(SUM(quantity),0) AS ordered, COALESCE(SUM(quantity_received),0) AS received
               FROM `purchase__order_item` WHERE po_id = ?"
        );
        $stmt->execute([$poId]);
        $r = $stmt->fetch(\PDO::FETCH_ASSOC);
        return ['ordered' => (float)$r['ordered'], 'received' => (float)$r['received']];
    }

    /**
     * Stan PO wyliczany PER POZYCJA (ścieżki dostaw): nadwyżka na jednej
     * pozycji nie może maskować braku na innej.
     *   received           — KAŻDA pozycja ma quantity_received + 1e-6 >= quantity
     *   partially_received — w przeciwnym razie, gdy którakolwiek ma quantity_received > 1e-6
     *   confirmed          — nic nie przyjęto
     * Wywołać w transakcji, po zablokowaniu pozycji PO.
     *
     * @return array{state:string, incomplete_lines:int, line_count:int}
     */
    private function deriveDeliveryPoState(int $poId): array {
        $stmt = $this->MsaDB->db->prepare(
            "SELECT COUNT(*) AS line_count,
                    COALESCE(SUM(quantity_received + 0.000001 < quantity), 0) AS incomplete,
                    COALESCE(SUM(quantity_received > 0.000001), 0) AS any_received
               FROM `purchase__order_item` WHERE po_id = ?"
        );
        $stmt->execute([$poId]);
        $r = $stmt->fetch(\PDO::FETCH_ASSOC);
        $lineCount  = (int)$r['line_count'];
        $incomplete = (int)$r['incomplete'];
        if ($lineCount > 0 && $incomplete === 0) {
            $state = 'received';
        } elseif ((int)$r['any_received'] > 0) {
            $state = 'partially_received';
        } else {
            $state = 'confirmed';
        }
        return ['state' => $state, 'incomplete_lines' => $incomplete, 'line_count' => $lineCount];
    }

    /**
     * Podsumowanie PO po przyjęciu: stan, zamówiono/przyjęto, braki.
     *
     * @return array{po_id:int, po_number:?string, new_state:string, ordered:float, received:float, missing_lines:array}
     */
    private function summariseDeliveryPo(int $poId): array {
        $po = (new PurchaseOrderRepository($this->MsaDB))->getById($poId);
        $totals = $this->poTotals($poId);
        $missing = [];
        foreach ((new PurchaseOrderItemRepository($this->MsaDB))->getByPo($poId) as $it) {
            $rem = $it->quantity - $it->quantityReceived;
            if ($rem > 1e-6) {
                $missing[] = [
                    'po_item_id'     => $it->id,
                    'vendor_part_no' => $it->vendorPartNo,
                    'part_name'      => $it->partName,
                    'remaining'      => round($rem, 10),
                ];
            }
        }
        return [
            'po_id'         => $poId,
            'po_number'     => $po !== null ? $po->poNumber : null,
            'new_state'     => $po !== null ? $po->state : 'unknown',
            'ordered'       => $totals['ordered'],
            'received'      => $totals['received'],
            // „Kompletne" = każda pozycja przyjęta (nie suma — nadwyżka nie maskuje braków).
            'complete'      => empty($missing),
            'missing_lines' => $missing,
        ];
    }

    /** id typu wejścia „Przyjęcie z zamówienia PO" (ta sama kolejność rezolucji co w createReceipt). */
    private function resolvePurchaseInputTypeId(): int {
        $typeStmt = $this->MsaDB->db->prepare(
            "SELECT id FROM `inventory__input_type`
             WHERE name LIKE 'purchase%'
                OR name = 'Przyjęcie z zamówienia PO'
                OR name = 'Przyjecie z zamowienia PO'
                OR id = 1
             ORDER BY CASE
                        WHEN name = 'Przyjęcie z zamówienia PO' THEN 0
                        WHEN name LIKE 'purchase%'              THEN 1
                        WHEN name = 'Przyjecie z zamowienia PO' THEN 2
                        ELSE 3
                      END ASC,
                      id ASC
             LIMIT 1"
        );
        $typeStmt->execute();
        $typeRow = $typeStmt->fetch(\PDO::FETCH_ASSOC);
        if ($typeRow === false) {
            throw new \RuntimeException("No inventory__input_type row - please configure at least one input type");
        }
        return (int)$typeRow['id'];
    }

    // ── Zatwierdzenie ───────────────────────────────────────────

    /**
     * Księguje szkic dostawy w JEDNEJ transakcji (plan §6.3).
     *
     * @return array{delivery_id:int, grn_number:string, pos:array<int,array>}
     */
    public function postDelivery(int $deliveryId, int $userId): array {
        if ($deliveryId <= 0) throw new \InvalidArgumentException("deliveryId must be positive.");
        if ($userId <= 0)     throw new \InvalidArgumentException("userId must be positive.");

        return $this->inDeliveryTransaction(function () use ($deliveryId, $userId) {
            $MsaDB = $this->MsaDB;
            $deliveryRepo = new DeliveryRepository($MsaDB);
            $lineRepo     = new DeliveryLineRepository($MsaDB);
            $poRepo       = new PurchaseOrderRepository($MsaDB);

            // 1. Blokada dostawy + pozycji PO; 2. ponowne dopasowanie pod blokadą.
            $delivery = $this->lockDraftDelivery($deliveryRepo, $deliveryId);
            $plan = (new DeliveryMatcher($MsaDB))->match($deliveryId, true);
            $lines = $lineRepo->getByDelivery($deliveryId);

            // 3. Walidacja.
            $blockers = $this->collectDeliveryBlockers($delivery, $lines, $plan);
            if (!empty($blockers)) {
                throw new \LogicException(implode("\n", $blockers));
            }

            $this->persistAutoAllocations($deliveryId, $plan);
            $lines = $lineRepo->getByDelivery($deliveryId);  // z id przydziałów

            // 4. Data przyjęcia + numer GRN.
            $receivedAt = $delivery->receivedAt !== null
                ? $this->parseDeliveryDateTime($delivery->receivedAt)
                : date('Y-m-d H:i:s');
            $grn = $this->allocateDocumentNumber('grn', 0);
            $inputTypeId = $this->resolvePurchaseInputTypeId();
            $docLabel = $delivery->documentType === 'wz' ? 'WZ' : 'FV';

            // 5. Jedna grupa transferowa na dokument.
            $transferGroupId = (int)(new TransferGroupManager($MsaDB))->createTransferGroup(
                $userId,
                'purchase_delivery',
                [
                    'delivery_id' => $deliveryId,
                    'vendor_id'   => $delivery->vendorId,
                    'grn'         => $grn,
                    'vendor'      => $delivery->vendorName ?? ('#' . $delivery->vendorId),
                ]
            );

            // 6. Po jednym wierszu inventory__parts na pozycję (cała ilość, z nadwyżką).
            $invCols = [
                'parts_id', 'sub_magazine_id', 'qty', 'commission_id',
                'transfer_group_id', 'is_cancelled', 'input_type_id',
                'comment', 'timestamp', 'isVerified',
            ];
            $magByLine = [];
            foreach ($lines as $l) {
                $vp = $lineRepo->getVendorPartInfo($l->vendorPartId);
                if ($vp === null) {
                    throw new \LogicException("Nie znaleziono pozycji dostawcy {$l->vendorPartId}.");
                }
                $magId = (int)($l->subMagazineId ?? $delivery->defaultSubMagazineId);
                $magByLine[$l->id] = $magId;

                $comment = sprintf('Przyjęcie %s / %s %s (%s)', $grn, $docLabel, $delivery->vendorDocumentNumber, $vp['vendorPartNo']);
                if ($l->comment !== null && trim($l->comment) !== '') {
                    $comment .= ' — ' . trim($l->comment);
                }
                $invId = (int)$MsaDB->insert('inventory__parts', $invCols, [
                    (int)$vp['partsId'], $magId, DeliveryLineRepository::dec($l->quantity), null,
                    $transferGroupId, 0, $inputTypeId, $comment, $receivedAt, 0,
                ]);
                $lineRepo->setInventoryPartsId($l->id, $invId);
            }

            // 7. Przydziały pogrupowane po PO → przyjęcie na PO + pozycje.
            $byPo = [];  // po_id => list of [line, allocation, poItemRow]
            foreach ($lines as $l) {
                foreach ($l->allocations as $a) {
                    $byPo[$a->poId ?? 0][] = [$l, $a];
                }
            }
            ksort($byPo);

            $receiptComment = 'Nr dok. dostawcy: ' . $delivery->vendorDocumentNumber;
            if ($delivery->comment !== null && trim($delivery->comment) !== '') {
                $receiptComment .= "\n" . trim($delivery->comment);
            }

            $touchedPoIds = [];
            foreach ($byPo as $poId => $entries) {
                $poId = (int)$poId;
                if ($poId <= 0) {
                    throw new \LogicException("Przydział wskazuje nieistniejące zamówienie.");
                }
                $touchedPoIds[] = $poId;
                $receiptId = (int)$MsaDB->insert(
                    'purchase__order_receipt',
                    ['po_id', 'document_number', 'received_by', 'received_at', 'comment', 'delivery_id'],
                    [$poId, $grn, $userId, $receivedAt, $receiptComment, $deliveryId]
                );

                foreach ($entries as [$l, $a]) {
                    // a) pozycja przyjęcia ↔ przydział (1:1)
                    $MsaDB->insert(
                        'purchase__order_receipt_item',
                        ['receipt_id', 'po_item_id', 'quantity_received', 'sub_magazine_id', 'comment', 'delivery_allocation_id'],
                        [$receiptId, $a->poItemId, DeliveryLineRepository::dec($a->quantity), $magByLine[$l->id], $l->comment, $a->id]
                    );
                    // b) bieżąca suma przyjętych (atomowo, pozycja jest zablokowana)
                    $upd = $MsaDB->db->prepare(
                        "UPDATE `purchase__order_item` SET quantity_received = quantity_received + ? WHERE id = ?"
                    );
                    $upd->execute([DeliveryLineRepository::dec($a->quantity), $a->poItemId]);
                    // c) cena z faktury trafia na PO tylko gdy PO nie miało ceny
                    if ($l->unitPrice !== null) {
                        $priceStmt = $MsaDB->db->prepare(
                            "UPDATE `purchase__order_item` SET unit_price = ? WHERE id = ? AND unit_price IS NULL"
                        );
                        $priceStmt->execute([DeliveryLineRepository::dec($l->unitPrice), $a->poItemId]);
                    }
                }
            }

            // 8. Stan każdego dotkniętego PO (reguła jak w createReceipt).
            $summary = [];
            foreach ($touchedPoIds as $poId) {
                // Po przyjęciu stan to received albo partially_received (confirmed
                // nie jest możliwy, bo właśnie bumpnęliśmy quantity_received).
                $poRepo->setState($poId, $this->deriveDeliveryPoState($poId)['state']);
                $summary[] = $this->summariseDeliveryPo($poId);
            }

            // 9. Dostawa zaksięgowana.
            $deliveryRepo->markPosted($deliveryId, $grn, $receivedAt, $transferGroupId, $userId);

            return [
                'delivery_id' => $deliveryId,
                'grn_number'  => $grn,
                'pos'         => $summary,
            ];
        });
    }

    // ── Wycofanie zaksięgowanej dostawy ─────────────────────────

    /**
     * Cofa zaksięgowaną dostawę w całości (plan §6.4): anuluje wiersze
     * magazynowe (triggery przywracają stan), zmniejsza quantity_received,
     * kasuje wygenerowane przyjęcia PO i przelicza stany PO. Odmawia, gdy
     * któreś dotknięte PO jest anulowane lub zamknięte ręcznie (`received`
     * przy sumie przyjętych < zamówionych). Cena zapisana na PO z faktury
     * (gdy PO jej nie miało) NIE jest cofana.
     *
     * @return array{delivery_id:int, pos:array<int,array>}
     */
    public function cancelPostedDelivery(int $deliveryId, int $userId): array {
        if ($deliveryId <= 0) throw new \InvalidArgumentException("deliveryId must be positive.");
        if ($userId <= 0)     throw new \InvalidArgumentException("userId must be positive.");

        return $this->inDeliveryTransaction(function () use ($deliveryId, $userId) {
            $MsaDB = $this->MsaDB;
            $db = $MsaDB->db;
            $deliveryRepo = new DeliveryRepository($MsaDB);
            $lineRepo     = new DeliveryLineRepository($MsaDB);
            $poRepo       = new PurchaseOrderRepository($MsaDB);

            if ($deliveryId <= 0) {
                throw new \InvalidArgumentException("deliveryId must be positive.");
            }
            $delivery = $deliveryRepo->lockById($deliveryId);
            if ($delivery === null) {
                throw new \LogicException("Nie znaleziono dostawy.");
            }
            if ($delivery->state !== 'posted') {
                throw new \LogicException("Tylko zaksięgowaną dostawę można wycofać.");
            }

            // PO dotknięte dostawą (zablokowane w stałej kolejności).
            $stmt = $db->prepare("SELECT id, po_id FROM `purchase__order_receipt` WHERE delivery_id = ? ORDER BY id ASC FOR UPDATE");
            $stmt->execute([$deliveryId]);
            $receiptIds = [];
            $poIds = [];
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $receiptIds[] = (int)$r['id'];
                $poIds[(int)$r['po_id']] = true;
            }
            $poIds = array_keys($poIds);
            sort($poIds);

            $poRows = [];
            $itemReceived = [];
            if (!empty($poIds)) {
                $ph = implode(',', array_fill(0, count($poIds), '?'));
                $pstmt = $db->prepare("SELECT id, state, po_number FROM `purchase__order` WHERE id IN ({$ph}) ORDER BY id ASC FOR UPDATE");
                $pstmt->execute($poIds);
                foreach ($pstmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                    $poRows[(int)$r['id']] = $r;
                }
                $istmt = $db->prepare("SELECT id, po_id, quantity, quantity_received FROM `purchase__order_item` WHERE po_id IN ({$ph}) ORDER BY id ASC FOR UPDATE");
                $istmt->execute($poIds);
                $ordered = [];
                $received = [];
                $incompleteLines = [];
                foreach ($istmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                    $pid = (int)$r['po_id'];
                    $ordered[$pid] = ($ordered[$pid] ?? 0.0) + (float)$r['quantity'];
                    $received[$pid] = ($received[$pid] ?? 0.0) + (float)$r['quantity_received'];
                    if ((float)$r['quantity_received'] + 1e-6 < (float)$r['quantity']) {
                        $incompleteLines[$pid] = ($incompleteLines[$pid] ?? 0) + 1;
                    }
                    $itemReceived[(int)$r['id']] = (float)$r['quantity_received'];
                }
                foreach ($poIds as $pid) {
                    $row = $poRows[$pid] ?? null;
                    if ($row === null) {
                        throw new \LogicException("Nie znaleziono zamówienia #{$pid} powiązanego z dostawą.");
                    }
                    $label = $row['po_number'] ?? ('#' . $pid);
                    if ($row['state'] === 'cancelled') {
                        throw new \LogicException("Zamówienie {$label} zostało anulowane — nie można wycofać tej dostawy.");
                    }
                    // „Zamknięte ręcznie" = received, choć nie każda pozycja jest kompletna (per pozycja, nie sumą).
                    if ($row['state'] === 'received' && ($incompleteLines[$pid] ?? 0) > 0) {
                        throw new \LogicException("Zamówienie {$label} zostało zamknięte ręcznie jako odebrane — nie można wycofać tej dostawy.");
                    }
                }
            }

            // Pozycje przyjęć do cofnięcia.
            $riRows = [];
            if (!empty($receiptIds)) {
                $ph = implode(',', array_fill(0, count($receiptIds), '?'));
                $ristmt = $db->prepare("SELECT id, po_item_id, quantity_received FROM `purchase__order_receipt_item` WHERE receipt_id IN ({$ph})");
                $ristmt->execute($receiptIds);
                $riRows = $ristmt->fetchAll(\PDO::FETCH_ASSOC);
            }
            $decrement = [];
            foreach ($riRows as $ri) {
                $decrement[(int)$ri['po_item_id']] = ($decrement[(int)$ri['po_item_id']] ?? 0.0) + (float)$ri['quantity_received'];
            }
            foreach ($decrement as $itemId => $qty) {
                if (($itemReceived[$itemId] ?? 0.0) + 1e-6 < $qty) {
                    throw new \LogicException("Niespójne dane: przyjęta ilość pozycji zamówienia {$itemId} jest mniejsza niż ilość z tej dostawy.");
                }
            }

            // Wiersze magazynowe: wszystkie muszą być jeszcze aktywne.
            $lines = $lineRepo->getByDelivery($deliveryId);
            $invIds = [];
            foreach ($lines as $l) {
                if ($l->inventoryPartsId !== null) $invIds[] = $l->inventoryPartsId;
            }
            $now = date('Y-m-d H:i:s');
            if (!empty($invIds)) {
                $iph = implode(',', array_fill(0, count($invIds), '?'));
                $cstmt = $db->prepare("SELECT COUNT(*) FROM `inventory__parts` WHERE id IN ({$iph}) AND is_cancelled = 0 FOR UPDATE");
                $cstmt->execute($invIds);
                if ((int)$cstmt->fetchColumn() !== count($invIds)) {
                    throw new \LogicException("Wpisy magazynowe tej dostawy zostały już częściowo lub całkowicie anulowane — nie można jej wycofać.");
                }
                $ustmt = $db->prepare("UPDATE `inventory__parts` SET is_cancelled = 1, cancelled_at = ?, cancelled_by = ? WHERE id IN ({$iph})");
                $ustmt->execute(array_merge([$now, $userId], $invIds));
            }
            if ($delivery->transferGroupId !== null) {
                $MsaDB->update('inventory__transfer_groups', [
                    'is_cancelled' => 1, 'cancelled_at' => $now, 'cancelled_by' => $userId,
                ], 'id', $delivery->transferGroupId);
            }

            // quantity_received −, kasowanie przyjęć PO.
            $dec = $db->prepare("UPDATE `purchase__order_item` SET quantity_received = quantity_received - ? WHERE id = ?");
            foreach ($decrement as $itemId => $qty) {
                $dec->execute([DeliveryLineRepository::dec($qty), $itemId]);
            }
            if (!empty($receiptIds)) {
                $ph = implode(',', array_fill(0, count($receiptIds), '?'));
                $db->prepare("DELETE FROM `purchase__order_receipt_item` WHERE receipt_id IN ({$ph})")->execute($receiptIds);
                $db->prepare("DELETE FROM `purchase__order_receipt` WHERE id IN ({$ph})")->execute($receiptIds);
            }

            // Stany PO na nowo.
            $summary = [];
            foreach ($poIds as $pid) {
                $poRepo->setState($pid, $this->deriveDeliveryPoState($pid)['state']);
                $summary[] = $this->summariseDeliveryPo($pid);
            }

            $deliveryRepo->markCancelled($deliveryId);
            return ['delivery_id' => $deliveryId, 'pos' => $summary];
        });
    }
}
