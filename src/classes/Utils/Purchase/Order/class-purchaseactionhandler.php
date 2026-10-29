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
     *   - pozycja PO nie zna pojęcia "niewycenione" — `unit_price` jest
     *     `DECIMAL(30,10) DEFAULT 0` (NOT NULL w praktyce), a
     *     `PurchaseOrderItemRepository::update()` przyjmuje
     *     `float $unitPrice` (non-nullable). Puste pole ceny w formularzu
     *     oznacza więc "zachowaj cenę istniejącą", a NIE "0" i NIE
     *     NULL. Uwaga: to świadoma różnica semantyczna względem RFQ,
     *     gdzie null = cena nieustalona przez dostawcę.
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
     *   - pozycja musi należeć do `$poId` (walidacja ownership —
     *     `LogicException`, żeby UI nie dało się oszukać cudzym id)
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
     *                                           'quantity_unit_id' => ?int]   // null = keep existing
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
            // cenę istniejącą — pozycja PO nie ma stanu "niewycenione").
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
        }

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
                // (Nie 0 i nie NULL — pozycja PO nie zna "niewycenione",
                // a update() wymaga float.)
                $unitPrice = (float)$existing->unitPrice;
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
            throw new \LogicException("Zamówienie musi być potwierdzone, w trakcie przyjęcia lub częściowo odebrane, aby przyjąć towar.");
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
     * Zamyka częściowo dostarczone / „w trakcie przyjęcia" zamówienie (PO)
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
                "Zamówienie jest w stanie '{$po->state}'. Zamknięcie jako odebrane jest możliwe tylko ze stanu „w trakcie przyjęcia\" lub „częściowo odebrane\"."
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
                "Zamówienie jest w stanie '{$po->state}'. Zamknięcie z tej strony jest możliwe tylko dla zamówień potwierdzonych, w trakcie przyjęcia lub częściowo odebranych."
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

    /**
     * Persist receipt-header metadata (vendor doc number, comment, date)
     * and transition the PO into 'in_progress', without booking any items.
     *
     * Storage choice: the header lives in `purchase__order_receipt` as a
     * DRAFT — a row with no children in `purchase__order_receipt_item`.
     * No new schema columns, no separate table; the existing 'document_number
     * is null' convention (allocated only on real save) plus the
     * "Nr dok. dostawcy: ..." comment prefix (mirrored from receipt-create.php
     * lines 153-156) carry everything the frontend needs to repaint the
     * form on the next visit.
     *
     * Idempotency: clicking "Akceptuję" twice for the same PO updates the
     * existing draft (preserving received_by from the original record —
     * we don't want the "operator who pressed the button last" to
     * overwrite the audit trail), and only re-runs the state transition
     * if it's still worth doing (PO currently `confirmed`).
     *
     * State machine integration: this method is the SOLE producer of
     * the `confirmed → in_progress` edge. The reverse + the
     * `in_progress → partially_received|received` edges are owned by
     * createReceipt, which now accepts `in_progress` as a starting
     * state (see the state guard widened above).
     *
     * Returns:
     *   - receiptId: the (new or updated) draft row id
     *   - state: the PO state after this call ('in_progress' or its
     *     pre-existing value if the transition was skipped)
     *   - stateLabel: Polish label for the state (currently hardcoded
     *     to "W trakcie przyjęcia" for the new state — if more states
     *     arrive, lift this to a shared map).
     *   - wasUpdated: true if an existing draft was updated, false if a
     *     new one was inserted. Frontend can use this to choose between
     *     "Zapisano" and "Zaktualizowano" wording if it cares.
     */
    public function acceptReceiptMetadata(
        int $poId,
        int $userId,
        ?string $vendorDocumentNumber = null,
        ?string $comment = null,
        ?string $receivedAt = null
    ): array {
        if ($poId   <= 0) throw new \InvalidArgumentException("poId must be positive.");
        if ($userId <= 0) throw new \InvalidArgumentException("userId must be positive.");

        // Mirror receipt-create.php's trim/null-coerce on the text inputs.
        if ($vendorDocumentNumber !== null) {
            $vendorDocumentNumber = trim((string)$vendorDocumentNumber);
            if ($vendorDocumentNumber === '') $vendorDocumentNumber = null;
        }
        if ($comment !== null) {
            $comment = trim((string)$comment);
            if ($comment === '') $comment = null;
        }
        // receivedAt: same parse + future-tolerance rules as createReceipt.
        // Null/empty → null (the column's DEFAULT CURRENT_TIMESTAMP() stamps now).
        if ($receivedAt !== null) {
            $receivedAt = trim((string)$receivedAt);
            if ($receivedAt === '') $receivedAt = null;
        }
        if ($receivedAt !== null) {
            $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $receivedAt);
            $parseErrors = \DateTime::getLastErrors();
            $hasParseErrors = $parseErrors !== false
                && ($parseErrors['warning_count'] > 0 || $parseErrors['error_count'] > 0);
            if ($dt === false || $hasParseErrors) {
                throw new \InvalidArgumentException("Nieprawidłowy format daty przyjęcia.");
            }
            $maxAllowed = (new \DateTime())->modify('+1 day');
            if ($dt > $maxAllowed) {
                throw new \InvalidArgumentException("Data przyjęcia nie może być z przyszłości.");
            }
            $receivedAt = $dt->format('Y-m-d H:i:s');
        }

        $MsaDB = $this->MsaDB;
        $poRepo      = new PurchaseOrderRepository($MsaDB);
        $receiptRepo = new OrderReceiptRepository($MsaDB);

        // Load PO; state guard. Same message string as receipt-po-get.php
        // uses — the operator sees this verbatim in the JS alert.
        $po = $poRepo->getById($poId);
        if ($po === null) {
            throw new \LogicException("Nie znaleziono zamówienia.");
        }
        // The accept-metadata action is a softer transition than a full
        // receive: it's allowed from any state that is *receive-eligible*
        // (i.e. the frontend would have let the operator open the form
        // for it). 'partially_received' is included so the operator can
        // re-affirm header metadata on a follow-up partial delivery —
        // it just won't change the state (already past in_progress).
        if (!in_array($po->state, ['confirmed','in_progress','partially_received'], true)) {
            throw new \LogicException("Zamówienie nie jest w stanie pozwalającym na przyjęcie towaru.");
        }

        // Vendor doc number gets the same "Nr dok. dostawcy: <nr>\n" prefix
        // convention that receipt-create.php uses (lines 153-156). The
        // parsing logic in receipt-po-get.php reverses it for prefill.
        if ($vendorDocumentNumber !== null) {
            $prefix = 'Nr dok. dostawcy: ' . $vendorDocumentNumber;
            $comment = $comment === null ? $prefix : ($prefix . "\n" . $comment);
        }

        $MsaDB->db->beginTransaction();
        try {
            // Look for an existing draft for this PO — a receipt row with
            // zero items. ORDER BY id DESC for "most recent wins" if the
            // operator somehow has more than one draft (multiple Akceptuję
            // cycles without ever saving items).
            $draftId = null;
            $draftStmt = $MsaDB->db->prepare(
                "SELECT r.id
                   FROM `purchase__order_receipt` r
                   LEFT JOIN `purchase__order_receipt_item` ri ON ri.receipt_id = r.id
                  WHERE r.po_id = ? AND ri.id IS NULL
                  ORDER BY r.id DESC
                  LIMIT 1"
            );
            $draftStmt->execute([$poId]);
            $draftId = $draftStmt->fetchColumn() ?: null;

            if ($draftId !== null) {
                // Update the existing draft. received_by is intentionally
                // NOT in the UPDATE — it preserves who first accepted the
                // PO. received_at is updated to the latest accepted value
                // (or NOW if the operator didn't open the date toggle).
                $updateColumns = ['comment'];
                $updateValues  = [$comment];
                if ($receivedAt !== null) {
                    $updateColumns[] = 'received_at';
                    $updateValues[]  = $receivedAt;
                }
                $MsaDB->update(
                    'purchase__order_receipt',
                    array_combine($updateColumns, $updateValues),
                    'id',
                    $draftId
                );
                $wasUpdated = true;
            } else {
                // First accept for this PO. documentNumber stays null — GRN
                // allocation only happens on the real Zapisz save (so we
                // don't burn numbers on drafts that may never get items).
                $draftId = $receiptRepo->create($poId, $userId, null, $comment, $receivedAt);
                $wasUpdated = false;
            }

            // State transition: ONLY when currently confirmed. The other
            // allowed starting states (in_progress, partially_received)
            // already are or have been past this edge, so re-running it
            // would either be a no-op (in_progress → in_progress) or
            // would rewind the state machine (partially_received → in_progress
            // would lie about items already booked). Monotonic.
            $newState = $po->state;
            if ($po->state === 'confirmed') {
                $poRepo->setState($poId, 'in_progress');
                $newState = 'in_progress';
            }

            $MsaDB->db->commit();
            return [
                'receiptId'  => (int)$draftId,
                'state'      => $newState,
                'stateLabel' => 'W trakcie przyjęcia',
                'wasUpdated' => $wasUpdated,
            ];
        } catch (\Throwable $e) {
            if ($MsaDB->db->inTransaction()) {
                $MsaDB->db->rollBack();
            }
            throw $e;
        }
    }
}
