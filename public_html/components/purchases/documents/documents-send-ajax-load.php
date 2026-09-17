<?php
/**
 * AJAX (POST): ładuje pełny snapshot dokumentu dla wizarda
 * "Wyślij dokument" (Admin/Purchase/Documents/documents-send.php).
 *
 * Kontrakt wejścia/wyjścia zamrożony — frontend (documents-send.js) z
 * niego korzysta bezpośrednio. Kształt odpowiedzi (snake_case w
 * kluczach głównych + camelCase w `doc` + polskie `state_label` /
 * `action_label`) jest obowiązujący.
 *
 * GET (query string):
 *   type   'rfq' | 'po'   (wymagane)
 *   id     int            (wymagane, > 0)
 *
 * Odpowiedź sukces:
 *   {
 *     "success": true,
 *     "doc": {
 *       "type":                 'rfq' | 'po',
 *       "id":                   int,
 *       "number":               'RFQ/2026/0007' | 'PO/2026/0042' | null,
 *       "vendorName":           string,
 *       "vendorPoNumber":       string | null,    // tylko PO
 *       "state":                'draft' | 'sent' | 'responded' | 'cancelled' | 'confirmed' | 'partially_received' | 'received' | 'converted',
 *       "stateLabel":           'Szkic' | 'Wysłane' | 'Odpowiedź' | …,
 *       "createdAt":            'Y-m-d H:i:s' | null,
 *       "sentAt":               'Y-m-d H:i:s' | null,
 *       "respondedAt":          'Y-m-d H:i:s' | null,  // tylko RFQ
 *       "expectedReplyDate":    'Y-m-d' | null,        // tylko RFQ
 *       "expectedDeliveryDate": 'Y-m-d' | null,        // tylko PO
 *       "lines": [
 *         {"vendorPartNo":"…", "producerPartNo":"…", "partName":"…",
 *          "producerName":"…", "unitName":"…", "quantityOrdered":float,
 *          "unitPrice":float|null, "currency":"PLN"}
 *       ],
 *       "valueBreakdown": [{"currency":"PLN","total":float}],
 *       "suppliers": [
 *         {"id":int, "name":"…", "jobTitle":"…", "email":"…",
 *          "phone":"…", "comment":"…", "isPrimary":0|1}
 *       ],
 *       "sendable":      bool,           // stan pozwala na wysyłkę / oznaczenie odpowiedzi
 *       "actionLabel":   'Wyślij zapytanie' | 'Wyślij zapytanie (ponownie)'
 *                       | 'Oznacz jako otrzymaną odpowiedź'
 *                       | 'Wyślij zamówienie' | 'Wyślij zamówienie (ponownie)'
 *     }
 *   }
 *
 * Błędy:
 *   {success: false, error: '…'}    HTTP 200 (zgodnie z konwencją AGENTS.md)
 *   {success: false, error: '…'}    HTTP 403 / 405 przy braku uprawnień / złej metodzie
 *
 * Dlaczego surowe SQL-ki obok repozytoriów:
 *   - `responded_at` zostało dodane migracją P5+ i nie jest jeszcze
 *     zmapowane w RFQRepository::buildSelectJoin() / VendorSupplier
 *     entity. Repo nie można modyfikować z tej roli (zakres tylko
 *     documents-send-get.php + wizard), więc czytamy brakujące
 *     kolumny osobnymi zapytaniami (responded_at) i SELECT z JOINem
 *     obejmującym `is_primary` (supplierzy).
 *   - valueBreakdown to dosłowna kopia GROUP_CONCAT-subquery z
 *     documents-table.php (linie 278-305 / 308-335), żeby format
 *     raportowany do UI był identyczny jak w tabeli /purchase/documents.
 */
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Order\RFQRepository;
use Atte\Utils\Purchase\Order\RFQItemRepository;
use Atte\Utils\Purchase\Order\PurchaseOrderRepository;
use Atte\Utils\Purchase\Order\PurchaseOrderItemRepository;
use Atte\Utils\Purchase\Master\VendorSupplierRepository;

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Brak uprawnień.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Metoda nieobsługiwana.']);
    exit;
}

$type = (string)($_POST['type'] ?? '');
$id   = (int)($_POST['id'] ?? 0);

if (!in_array($type, ['rfq', 'po'], true)) {
    echo json_encode(['success' => false, 'error' => 'Nieprawidłowy typ dokumentu.']);
    exit;
}
if ($id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Nieprawidłowy identyfikator dokumentu.']);
    exit;
}

$MsaDB = MsaDB::getInstance();

// Polskie etykiety stanów — mirror documents-view.php + document-send-ajax.php.
$rfqStateLabels = [
    'draft'     => 'Szkic',
    'sent'      => 'Wysłane',
    'responded' => 'Odpowiedź',
    'converted' => 'Przekonwertowane',
    'cancelled' => 'Anulowane',
];
$poStateLabels = [
    'draft'              => 'Szkic',
    'sent'               => 'Wysłane',
    'confirmed'          => 'Potwierdzone',
    'partially_received' => 'Częściowo odebrane',
    'received'           => 'Odebrane',
    'cancelled'          => 'Anulowane',
];

try {
    if ($type === 'rfq') {
        $docRepo  = new RFQRepository($MsaDB);
        $itemRepo = new RFQItemRepository($MsaDB);
        $doc      = $docRepo->getById($id);
        if ($doc === null) {
            echo json_encode(['success' => false, 'error' => 'Nie znaleziono zapytania.']);
            exit;
        }
        $items = $itemRepo->getByRfq($id);
        $number        = $doc->rfqNumber;
        $sentAt        = $doc->sentAt;
        $expectedDate  = $doc->expectedReplyDate;
        $vendorPoNumber = null;

        // responded_at nie jest jeszcze mapowane w RFQRepository::buildSelectJoin().
        // Czytamy bezpośrednio (kolumna dodana migracją P5+).
        $respondedAt = null;
        $respStmt = $MsaDB->db->prepare(
            "SELECT responded_at FROM `purchase__rfq` WHERE id = ?"
        );
        $respStmt->execute([$id]);
        $respRow = $respStmt->fetch(\PDO::FETCH_ASSOC);
        if ($respRow !== false && !empty($respRow['responded_at'])) {
            $respondedAt = (string)$respRow['responded_at'];
        }

        $vendorId = $doc->vendorId;

        // valueBreakdown — kopia GROUP_CONCAT-subquery z documents-table.php
        // (linie 278-305) dla purchase__rfq_item.
        $valueBreakdown = [];
        $valStmt = $MsaDB->db->prepare(
            "SELECT sub.currency,
                    CAST(sub.total AS DECIMAL(30, 10)) AS total
               FROM (
                     SELECT currency,
                            SUM(quantity * COALESCE(unit_price, 0)) AS total
                       FROM `purchase__rfq_item`
                      WHERE rfq_id = ?
                      GROUP BY currency
                    ) AS sub
              ORDER BY sub.currency ASC"
        );
        $valStmt->execute([$id]);
        foreach ($valStmt->fetchAll(\PDO::FETCH_ASSOC) as $vr) {
            $valueBreakdown[] = [
                'currency' => (string)$vr['currency'],
                'total'    => (float)$vr['total'],
            ];
        }
    } else { // po
        $docRepo  = new PurchaseOrderRepository($MsaDB);
        $itemRepo = new PurchaseOrderItemRepository($MsaDB);
        $doc      = $docRepo->getById($id);
        if ($doc === null) {
            echo json_encode(['success' => false, 'error' => 'Nie znaleziono zamówienia.']);
            exit;
        }
        $items = $itemRepo->getByPo($id);
        $number         = $doc->poNumber;
        $sentAt         = $doc->sentAt;
        $respondedAt    = null;
        $expectedDate   = $doc->expectedDeliveryDate;
        $vendorPoNumber = $doc->vendorPoNumber;
        $vendorId       = $doc->vendorId;

        // valueBreakdown — kopia GROUP_CONCAT-subquery z documents-table.php
        // (linie 308-335) dla purchase__order_item.
        $valueBreakdown = [];
        $valStmt = $MsaDB->db->prepare(
            "SELECT sub.currency,
                    CAST(sub.total AS DECIMAL(30, 10)) AS total
               FROM (
                     SELECT currency,
                            SUM(quantity * COALESCE(unit_price, 0)) AS total
                       FROM `purchase__order_item`
                      WHERE po_id = ?
                      GROUP BY currency
                    ) AS sub
              ORDER BY sub.currency ASC"
        );
        $valStmt->execute([$id]);
        foreach ($valStmt->fetchAll(\PDO::FETCH_ASSOC) as $vr) {
            $valueBreakdown[] = [
                'currency' => (string)$vr['currency'],
                'total'    => (float)$vr['total'],
            ];
        }
    }

    // Linie dokumentu. Pole encji `quantity` → `quantityOrdered` w
    // kontrakcie (camelCase; nazwa jawnie mówi "ile zamówiono",
    // ponieważ przy PO istnieje też `quantityReceived`, które tu nie
    // raportujemy — UI etykiety wierszy nie muszą rozróżniać typów).
    $lines = [];
    foreach ($items as $i) {
        $lines[] = [
            'vendorPartNo'    => $i->vendorPartNo,
            'producerPartNo'  => $i->producerPartNo,
            'partName'        => $i->partName,
            'producerName'    => $i->producerName,
            'unitName'        => $i->unitName,
            'quantityOrdered' => (float)$i->quantity,
            'unitPrice'       => $i->unitPrice === null ? null : (float)$i->unitPrice,
            'currency'        => (string)($i->currency ?? 'PLN'),
        ];
    }

    // Supplierzy — listByVendor sortuje kontakty główne na górze i
    // zwraca encje, ale encja VendorSupplier nie eksponuje `is_primary`.
    // Doczytujemy kolumnę w jednym SELECT-cie (już posortowane po
    // is_primary DESC, name ASC) i budujemy minimalny kształt JSONu.
    $suppliers = [];
    $supStmt = $MsaDB->db->prepare(
        "SELECT id, name, job_title AS jobTitle, email, phone, comment,
                is_primary AS isPrimary
           FROM `list__vendor_supplier`
          WHERE vendor_id = ? AND isActive = 1
          ORDER BY is_primary DESC, name ASC"
    );
    $supStmt->execute([$vendorId]);
    foreach ($supStmt->fetchAll(\PDO::FETCH_ASSOC) as $sr) {
        $suppliers[] = [
            'id'        => (int)$sr['id'],
            'name'      => (string)$sr['name'],
            'jobTitle'  => $sr['jobTitle']  !== null ? (string)$sr['jobTitle']  : null,
            'email'     => $sr['email']     !== null ? (string)$sr['email']     : null,
            'phone'     => $sr['phone']     !== null ? (string)$sr['phone']     : null,
            'comment'   => $sr['comment']   !== null ? (string)$sr['comment']   : null,
            'isPrimary' => (int)$sr['isPrimary'],
        ];
    }

    // Maszyna stanów wizarda — sendable + actionLabel zgodnie z sekcją C.
    $state     = (string)$doc->state;
    $stateLbl  = ($type === 'rfq') ? ($rfqStateLabels[$state] ?? $state)
                                    : ($poStateLabels[$state]   ?? $state);
    $sendable  = false;
    $actionLbl = '';
    if ($type === 'rfq') {
        if (in_array($state, ['draft', 'responded'], true)) {
            $sendable = true;
            $actionLbl = ($state === 'responded') ? 'Wyślij zapytanie (ponownie)' : 'Wyślij zapytanie';
        } elseif ($state === 'sent') {
            $sendable  = true; // mark-respond też wymaga read+write
            $actionLbl = 'Oznacz jako otrzymaną odpowiedź';
        }
    } else { // po
        if (in_array($state, ['draft', 'confirmed'], true)) {
            $sendable  = true;
            $actionLbl = ($state === 'confirmed') ? 'Wyślij zamówienie (ponownie)' : 'Wyślij zamówienie';
        }
        // PO w stanie `sent`: nie pozwala na ponowną wysyłkę ani oznaczenie
        // — kolejny stan to confirmed/cancelled/received. Wizard powinien
        // pokazać tylko podsumowanie z disabled CTA.
    }

    echo json_encode([
        'success' => true,
        'doc'     => [
            'type'                 => $type,
            'id'                   => $id,
            'number'               => $number,
            'vendorId'             => (int)$vendorId,
            'vendorName'           => $doc->vendorName,
            'vendorPoNumber'       => $vendorPoNumber,
            'state'                => $state,
            'stateLabel'           => $stateLbl,
            'createdAt'            => $doc->createdAt,
            'sentAt'               => $sentAt,
            'respondedAt'          => $respondedAt,
            'expectedReplyDate'    => $type === 'rfq' ? $expectedDate : null,
            'expectedDeliveryDate' => $type === 'po'  ? $expectedDate : null,
            'lines'                => $lines,
            'valueBreakdown'       => $valueBreakdown,
            'suppliers'            => $suppliers,
            'sendable'             => $sendable,
            'actionLabel'          => $actionLbl,
        ],
    ]);
} catch (\Throwable $e) {
    error_log('documents-send-get error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Błąd ładowania dokumentu.']);
}
exit;
