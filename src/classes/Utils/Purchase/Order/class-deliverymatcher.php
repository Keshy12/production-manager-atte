<?php
namespace Atte\Utils\Purchase\Order;

use Atte\DB\MsaDB;

/**
 * Dopasowanie pozycji dostawy (faktury / WZ) do otwartych pozycji zamówień
 * tego samego dostawcy — algorytm z docs/procurement/RECEIPT_INVOICE_FLOW_PLAN.md §5.
 *
 * KLASA TYLKO ODCZYTUJE (poza trybem blokady FOR UPDATE, który nadal nic
 * nie zapisuje) — zwraca plan przydziałów + flagi, a zapis robi
 * PurchaseActionHandler.
 *
 * Reguły:
 *  1. Przydziały ręczne (is_manual = 1) są stosowane najpierw i nigdy nie
 *     są dzielone ponownie. Jeżeli nie mieszczą się już w pojemności
 *     pozycji PO (np. ktoś inny przyjął towar), trafiają do `errors`.
 *  2. Reszta ilości każdej pozycji jest rozdzielana automatycznie:
 *     kandydaci = pozycje PO tego samego vendor_part_id, PO.vendor_id =
 *     dostawca dostawy, stan PO ∈ {confirmed, in_progress,
 *     partially_received}, pozostało = ilość − przyjęto − przydzielone
 *     (przez inne pozycje TEJ dostawy) > 0.
 *  3. Kolejność kandydatów: cena PO = cena faktury (|Δ| < 0.0001) → termin
 *     COALESCE(pozycja, nagłówek PO) rosnąco (NULL na końcu) → PO.confirmed_at
 *     → PO.id.
 *     Realizacja w dwóch przebiegach (przebieg 1: tylko kandydaci z
 *     zgodną ceną dla wszystkich pozycji po kolei; przebieg 2: pozostali).
 *     Dla pojedynczej pozycji wynik jest identyczny z „najpierw cena,
 *     potem termin", a pozycja z wcześniejszego wiersza nie podkrada
 *     pojemności PO, której cenę „zna" późniejszy wiersz.
 *  4. To, co nie zmieściło się w żadnym kandydacie, to nadwyżka (surplus).
 */
class DeliveryMatcher {
    public const OPEN_PO_STATES = ['confirmed', 'in_progress', 'partially_received'];
    public const PRICE_TOLERANCE = 0.0001;
    private const EPS = 1e-6;

    private $MsaDB;

    public function __construct(MsaDB $MsaDB){
        $this->MsaDB = $MsaDB;
    }

    /**
     * @param int  $deliveryId
     * @param bool $lockForUpdate  true → kandydackie pozycje PO są blokowane
     *                             (SELECT ... FOR UPDATE); wymaga otwartej transakcji.
     * @return array{
     *   lines: array<int, array>,
     *   errors: array<int, array>,
     *   po: array<int, array>,
     *   candidates: array<int, array>
     * }
     */
    public function match(int $deliveryId, bool $lockForUpdate = false): array {
        $deliveryRepo = new DeliveryRepository($this->MsaDB);
        $lineRepo     = new DeliveryLineRepository($this->MsaDB);

        $delivery = $deliveryRepo->getById($deliveryId);
        if ($delivery === null) {
            throw new \LogicException("Nie znaleziono dostawy.");
        }
        $lines = $lineRepo->getByDelivery($deliveryId);

        $plan = ['lines' => [], 'errors' => [], 'po' => [], 'candidates' => []];
        if (empty($lines)) {
            return $plan;
        }

        // ── Kandydaci ────────────────────────────────────────────
        $vpIds = [];
        foreach ($lines as $l) $vpIds[$l->vendorPartId] = true;
        $vpIds = array_keys($vpIds);

        $items = $this->loadCandidateItems($delivery->vendorId, $vpIds, $lockForUpdate);
        $remaining = [];
        foreach ($items as $id => $it) {
            $remaining[$id] = $it['quantity'] - $it['quantity_received'];
        }

        // ── Wstępna struktura wyniku ─────────────────────────────
        foreach ($lines as $l) {
            $plan['lines'][$l->id] = [
                'line_id'     => $l->id,
                'quantity'    => $l->quantity,
                'allocations' => [],
                'allocated'   => 0.0,
                'surplus'     => 0.0,
                'flags'       => [],
            ];
        }

        // ── 1. Przydziały ręczne ─────────────────────────────────
        foreach ($lines as $l) {
            $manualSum = 0.0;
            foreach ($l->allocations as $a) {
                if (!$a->isManual) continue;
                $itemId = $a->poItemId;
                $qty = $a->quantity;
                if (!isset($items[$itemId]) || $items[$itemId]['vendor_part_id'] !== $l->vendorPartId) {
                    $plan['errors'][] = [
                        'line_id' => $l->id, 'po_item_id' => $itemId,
                        'message' => "Pozycja {$l->lineNo}: ręczny przydział wskazuje pozycję zamówienia, która nie jest już dostępna do przyjęcia.",
                    ];
                    continue;
                }
                $remaining[$itemId] -= $qty;
                $manualSum += $qty;
                if ($remaining[$itemId] < -self::EPS) {
                    $plan['errors'][] = [
                        'line_id' => $l->id, 'po_item_id' => $itemId,
                        'message' => "Pozycja {$l->lineNo}: ręczny przydział do {$items[$itemId]['po_number']} przekracza pozostałą ilość tej pozycji zamówienia.",
                    ];
                }
                if ($manualSum > $l->quantity + self::EPS) {
                    $plan['errors'][] = [
                        'line_id' => $l->id, 'po_item_id' => $itemId,
                        'message' => "Pozycja {$l->lineNo}: suma ręcznych przydziałów przekracza ilość z faktury.",
                    ];
                }
                $this->pushAllocation($plan, $l, $items[$itemId], $qty, true);
            }
        }

        // ── 2. Przydziały automatyczne (dwa przebiegi) ───────────
        foreach ([true, false] as $pricePass) {
            foreach ($lines as $l) {
                $need = $l->quantity - $plan['lines'][$l->id]['allocated'];
                if ($need <= self::EPS) continue;
                foreach ($items as $itemId => $it) {
                    if ($need <= self::EPS) break;
                    if ($it['vendor_part_id'] !== $l->vendorPartId) continue;
                    if ($remaining[$itemId] <= self::EPS) continue;
                    $matches = $this->pricesMatch($it['unit_price'], $l->unitPrice);
                    if ($matches !== $pricePass) continue;
                    $take = min($need, $remaining[$itemId]);
                    $remaining[$itemId] -= $take;
                    $need -= $take;
                    $this->pushAllocation($plan, $l, $it, $take, false);
                }
            }
        }

        // ── 3. Flagi + podgląd PO ────────────────────────────────
        $vpCounts = [];
        foreach ($lines as $l) $vpCounts[$l->vendorPartId][] = $l->id;

        $anyOpenForPart = [];
        foreach ($items as $it) {
            if ($it['quantity'] - $it['quantity_received'] > self::EPS) {
                $anyOpenForPart[$it['vendor_part_id']] = true;
            }
        }

        $touched = [];  // po_id => [po_item_id => allocated]
        foreach ($lines as $l) {
            $entry =& $plan['lines'][$l->id];
            $entry['allocated'] = round($entry['allocated'], 10);
            $surplus = round($l->quantity - $entry['allocated'], 10);
            $entry['surplus'] = $surplus > self::EPS ? $surplus : 0.0;

            $priceMismatch = [];
            $priceMissing = [];
            $currencyMismatch = [];
            foreach ($entry['allocations'] as $a) {
                $touched[$a['po_id']][$a['po_item_id']] = ($touched[$a['po_id']][$a['po_item_id']] ?? 0.0) + $a['quantity'];
                if ($l->unitPrice !== null) {
                    if ($a['unit_price'] === null) {
                        $priceMissing[$a['po_item_id']] = $a['po_item_id'];
                    } elseif (!$this->pricesMatch($a['unit_price'], $l->unitPrice)) {
                        $delta = $a['unit_price'] != 0.0
                            ? round(($l->unitPrice - $a['unit_price']) / $a['unit_price'] * 100, 2)
                            : null;
                        $priceMismatch[] = [
                            'po_item_id' => $a['po_item_id'],
                            'po_number'  => $a['po_number'],
                            'po_price'   => $a['unit_price'],
                            'line_price' => $l->unitPrice,
                            'delta_pct'  => $delta,
                        ];
                    }
                }
                if (strcasecmp((string)$a['currency'], $delivery->currency) !== 0) {
                    $currencyMismatch[] = [
                        'po_item_id'   => $a['po_item_id'],
                        'po_number'    => $a['po_number'],
                        'po_currency'  => $a['currency'],
                        'doc_currency' => $delivery->currency,
                    ];
                }
            }
            if ($priceMismatch)    $entry['flags']['price_mismatch'] = $priceMismatch;
            if ($priceMissing)     $entry['flags']['price_missing_on_po'] = array_values($priceMissing);
            if ($currencyMismatch) $entry['flags']['currency_mismatch'] = $currencyMismatch;
            if ($entry['surplus'] > 0) $entry['flags']['surplus'] = $entry['surplus'];
            if (empty($anyOpenForPart[$l->vendorPartId]) && $entry['allocated'] <= self::EPS) {
                $entry['flags']['not_on_po'] = true;
            }
            if (count($vpCounts[$l->vendorPartId]) > 1) {
                $entry['flags']['duplicate_line'] = array_values(array_diff($vpCounts[$l->vendorPartId], [$l->id]));
            }
            unset($entry);
        }

        $plan['po'] = $this->buildPoPreview($touched, $items);
        $plan['candidates'] = $items;
        return $plan;
    }

    // ── Helpers ────────────────────────────────────────────────

    private function pricesMatch(?float $poPrice, ?float $linePrice): bool {
        if ($poPrice === null || $linePrice === null) return false;
        return abs($poPrice - $linePrice) < self::PRICE_TOLERANCE;
    }

    private function pushAllocation(array &$plan, DeliveryLine $l, array $it, float $qty, bool $manual): void {
        $plan['lines'][$l->id]['allocations'][] = [
            'po_item_id'     => $it['id'],
            'quantity'       => $qty,
            'is_manual'      => $manual,
            'po_id'          => $it['po_id'],
            'po_number'      => $it['po_number'],
            'po_state'       => $it['po_state'],
            'unit_price'     => $it['unit_price'],
            'currency'       => $it['currency'],
            'vendor_part_no' => $it['vendor_part_no'],
            'due_date'       => $it['due_date'],
            'price_match'    => $this->pricesMatch($it['unit_price'], $l->unitPrice),
        ];
        $plan['lines'][$l->id]['allocated'] += $qty;
    }

    /**
     * Ładuje pozycje PO dla kandydatów. W trybie blokady najpierw blokuje
     * wiersze w stałej kolejności (ORDER BY i.id — unika zakleszczeń), a
     * potem czyta dane z FOR UPDATE, żeby zobaczyć najnowsze
     * quantity_received niezależnie od migawki transakcji.
     *
     * @return array<int, array> id pozycji => dane (w kolejności priorytetu kandydatów)
     */
    private function loadCandidateItems(int $vendorId, array $vpIds, bool $lock): array {
        if (empty($vpIds)) return [];
        $db = $this->MsaDB->db;
        $vpPlaceholders = implode(',', array_fill(0, count($vpIds), '?'));
        $statePlaceholders = implode(',', array_fill(0, count(self::OPEN_PO_STATES), '?'));
        $params = array_merge([$vendorId], self::OPEN_PO_STATES, $vpIds);

        if ($lock) {
            $lockStmt = $db->prepare(
                "SELECT i.id
                   FROM `purchase__order_item` i
                   JOIN `purchase__order` po ON i.po_id = po.id
                  WHERE po.vendor_id = ? AND po.state IN ({$statePlaceholders})
                    AND i.vendor_part_id IN ({$vpPlaceholders})
                  ORDER BY i.id ASC
                  FOR UPDATE"
            );
            $lockStmt->execute($params);
            $lockStmt->fetchAll();
        }

        $sql = "SELECT i.id, i.po_id, i.vendor_part_id, i.quantity, i.quantity_received, i.unit_price, i.currency,
                       COALESCE(i.expected_delivery_date, po.expected_delivery_date) AS due_date,
                       po.po_number, po.state AS po_state, po.confirmed_at,
                       vp.vendor_part_no, lp.name AS part_name
                  FROM `purchase__order_item` i
                  JOIN `purchase__order` po        ON i.po_id = po.id
                  LEFT JOIN `list__vendor_part` vp ON i.vendor_part_id = vp.id
                  LEFT JOIN `list__parts` lp       ON vp.parts_id = lp.id
                 WHERE po.vendor_id = ? AND po.state IN ({$statePlaceholders})
                   AND i.vendor_part_id IN ({$vpPlaceholders})
                 ORDER BY (COALESCE(i.expected_delivery_date, po.expected_delivery_date) IS NULL) ASC,
                          COALESCE(i.expected_delivery_date, po.expected_delivery_date) ASC,
                          (po.confirmed_at IS NULL) ASC, po.confirmed_at ASC,
                          po.id ASC, i.id ASC"
            . ($lock ? " FOR UPDATE" : "");
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        $items = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $id = (int)$r['id'];
            $items[$id] = [
                'id'                => $id,
                'po_id'             => (int)$r['po_id'],
                'po_number'         => $r['po_number'] ?? ('#' . $r['po_id']),
                'po_state'          => $r['po_state'],
                'vendor_part_id'    => (int)$r['vendor_part_id'],
                'quantity'          => (float)$r['quantity'],
                'quantity_received' => (float)$r['quantity_received'],
                'unit_price'        => $r['unit_price'] !== null ? (float)$r['unit_price'] : null,
                'currency'          => (string)$r['currency'],
                'due_date'          => $r['due_date'],
                'confirmed_at'      => $r['confirmed_at'],
                'vendor_part_no'    => $r['vendor_part_no'],
                'part_name'         => $r['part_name'],
            ];
        }
        return $items;
    }

    /**
     * Podgląd per PO: ile pozycji dotyka ta dostawa i ile zostanie przed/po.
     *
     * @param array<int, array<int, float>> $touched po_id => [po_item_id => allocated]
     */
    private function buildPoPreview(array $touched, array $items): array {
        if (empty($touched)) return [];
        $poIds = array_keys($touched);
        $ph = implode(',', array_fill(0, count($poIds), '?'));
        $stmt = $this->MsaDB->db->prepare(
            "SELECT po_id, COALESCE(SUM(quantity),0) AS ordered, COALESCE(SUM(quantity_received),0) AS received
               FROM `purchase__order_item` WHERE po_id IN ({$ph}) GROUP BY po_id"
        );
        $stmt->execute($poIds);
        $totals = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $totals[(int)$r['po_id']] = ['ordered' => (float)$r['ordered'], 'received' => (float)$r['received']];
        }

        $preview = [];
        foreach ($touched as $poId => $itemAlloc) {
            $lines = [];
            $allocTotal = 0.0;
            $poNumber = null;
            $poState = null;
            foreach ($itemAlloc as $itemId => $alloc) {
                $it = $items[$itemId];
                $poNumber = $it['po_number'];
                $poState = $it['po_state'];
                $before = $it['quantity'] - $it['quantity_received'];
                $lines[] = [
                    'po_item_id'       => $itemId,
                    'vendor_part_no'   => $it['vendor_part_no'],
                    'part_name'        => $it['part_name'],
                    'ordered'          => $it['quantity'],
                    'received'         => $it['quantity_received'],
                    'remaining_before' => round($before, 10),
                    'allocated'        => round($alloc, 10),
                    'remaining_after'  => round($before - $alloc, 10),
                ];
                $allocTotal += $alloc;
            }
            $ordered = $totals[$poId]['ordered'] ?? 0.0;
            $received = $totals[$poId]['received'] ?? 0.0;
            $preview[$poId] = [
                'po_id'          => $poId,
                'po_number'      => $poNumber,
                'po_state'       => $poState,
                'ordered_total'  => $ordered,
                'received_total' => $received,
                'received_after' => round($received + $allocTotal, 10),
                'lines'          => $lines,
            ];
        }
        return $preview;
    }
}
