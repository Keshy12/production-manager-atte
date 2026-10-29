<?php
/**
 * AJAX: potwierdza zamówienie (PO) razem z cenami od dostawcy —
 * operator zarejestrował odpowiedź na wysłane zamówienie i wpisuje
 * wynegocjowane ceny/waluty per pozycja. Używany przez stronę
 * "Potwierdź odpowiedź" (Admin/Purchase/Documents/documents-respond.php
 * z `?type=po`).
 *
 * Bliźniak `document-rfq-respond-ajax.php` — różnica jest jedna:
 * zamiast `responded` ustawiamy `confirmed` (zamówienie), a cena pusta
 * oznacza "zachowaj cenę istniejącą" (pozycja PO nie zna stanu
 * "niewycenione", w odróżnieniu od pozycji RFQ).
 *
 * POST (form-encoded):
 *   id                int                    (wymagane, > 0)
 *   vendor_po_number  string                 opcjonalne — "Numer zamówienia
 *                                     u dostawcy" (max 64 zn.; handler
 *                                     obcina w razie potrzeby).
 *                                     Trimowany; pusty → null (kolumna
 *                                     pozostaje bez zmian).
 *   confirmed_at      'Y-m-d H:i:s' | 'Y-m-d' | 'Y-m-d\TH:i' | 'Y-m-d\TH:i:s' | ''
 *                                     opcjonalne — data potwierdzenia.
 *                                     'Y-m-d' normalizowane do
 *                                     'Y-m-d 00:00:00'; wariant
 *                                     'datetime-local' z separatorem 'T'
 *                                     ('Y-m-d\TH:i' → 'Y-m-d H:i:00',
 *                                     'Y-m-d\TH:i:s' → 'Y-m-d H:i:s');
 *                                     puste → null, handler użyje NOW().
 *   items[<item_id>][...]              opcjonalne — pozycje zamówienia.
 *                                     Klucz tablicy to id pozycji PO
 *                                     (tak jak serializuje strona
 *                                     odpowiedzi). Akceptujemy też
 *                                     items[i][item_id].
 *     items[..][item_id]          int             (gdy podane, > 0)
 *     items[..][unit_price]       string|empty    opcjonalne; akceptujemy '.'
 *                                     lub ',' jako separator dziesiętny
 *                                     (projekt miesza locale — patrz
 *                                     audits/DISCREPANCIES); puste → null
 *                                     (= zachowaj cenę istniejącą)
 *     items[..][currency]         string|empty    opcjonalne; max 8 znaków;
 *                                     puste → null (= zachowaj istniejącą)
 *     items[..][comment]          string|empty    OPCJONALNE i nieobowiązkowe:
 *                                     brak klucza = "nie ruszaj zapisanej
 *                                     uwagi pozycji" (handler sprawdza
 *                                     array_key_exists); pusta wartość =
 *                                     wyczyść kolumnę. Strona odpowiedzi
 *                                     (documents-respond.php) tego pola nie
 *                                     wysyła — uwagi do artykułu
 *                                     (list__vendor_part.comment) zapisywane
 *                                     są osobnym POSTem do
 *                                     purchases/cart/vendor-part-comment.php.
 *     items[..][quantity]         string|empty    decimal (locale-tolerant
 *                                     via ,→. normalize) > 0; puste → null
 *                                     (= zachowaj istniejącą)
 *     items[..][quantity_unit_id] int             > 0; puste → null
 *                                     (= zachowaj istniejącą)
 *
 *   jQuery $.ajax({ data: { items: [...] } }) emituje form-encoding
 *   `items[0][item_id]=…`, PHP parsuje to do $_POST['items'][0]['item_id']
 *   automatycznie — żadnego JSON po stronie klienta.
 *
 * Odpowiedź sukces:
 *   {
 *     success:           true,
 *     document_number:   string|null,    // 'PO/00001/00001'
 *     state:             'confirmed',
 *     state_label:       'Potwierdzone',
 *     vendor_po_number:  string|null
 *   }
 *
 * Obsługa wyjątków:
 *   - \LogicException / \InvalidArgumentException z handlera mają
 *     polskie komunikaty przeznaczone dla operatora → oddajemy w JSON
 *     z HTTP 200 (zgodnie z konwencją AGENTS.md dla endpointów).
 *   - Inny \Throwable → generyczny komunikat + error_log.
 */
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Order\PurchaseActionHandler;
use Atte\Utils\Purchase\Order\PurchaseOrderRepository;

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

$id               = (int)($_POST['id'] ?? 0);
$vendorPoNumberRaw = $_POST['vendor_po_number'] ?? null;
$confirmedAtRaw    = $_POST['confirmed_at'] ?? null;

if ($id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Nieprawidłowy identyfikator zamówienia.']);
    exit;
}

// Normalizacja vendor_po_number: trim; puste → null. Handler dodatkowo
// obcina do 64 znaków (szerokość kolumny purchase__order.vendor_po_number)
// — nie odrzucamy tu zbyt długiej wartości, bo kontrakt handlera to
// ciche przycięcie, a nie błąd.
$vendorPoNumber = null;
if ($vendorPoNumberRaw !== null) {
    $vendorPoNumberRaw = trim((string)$vendorPoNumberRaw);
    if ($vendorPoNumberRaw !== '') {
        $vendorPoNumber = $vendorPoNumberRaw;
    }
}

// Normalizacja confirmed_at: 'Y-m-d' → 'Y-m-d 00:00:00';
// 'Y-m-d\TH:i' → 'Y-m-d H:i:00'; 'Y-m-d\TH:i:s' → 'Y-m-d H:i:s';
// puste → null. Handler waliduje ściśle 'Y-m-d H:i:s', więc endpoint jest
// właścicielem tej konwersji (identycznie jak document-confirm-ajax.php).
// Format z 'T' wariantem 'datetime-local' obsługujemy tu, bo <input
// type="datetime-local"> wysyła surowy 'YYYY-MM-DDTHH:MM' — normalna
// ścieżka (JS) zamienia separator na spację przed wysłaniem, ale
// request bez JS-a albo ręcznie zrobiony POST musi przejść bez błędu.
$confirmedAt = null;
if ($confirmedAtRaw !== null) {
    $confirmedAtRaw = trim((string)$confirmedAtRaw);
    if ($confirmedAtRaw !== '') {
        $confirmedAt = $confirmedAtRaw;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $confirmedAt)) {
            $confirmedAt .= ' 00:00:00';
        } elseif (preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})$/', $confirmedAt, $m)) {
            // 'YYYY-MM-DDTHH:MM' → 'YYYY-MM-DD HH:MM:00'
            $confirmedAt = $m[1] . ' ' . $m[2] . ':00';
        } elseif (preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2}:\d{2})$/', $confirmedAt, $m)) {
            // 'YYYY-MM-DDTHH:MM:SS' → 'YYYY-MM-DD HH:MM:SS'
            $confirmedAt = $m[1] . ' ' . $m[2];
        }
    }
}

// Parsowanie per-item pól. Strona odpowiedzi serializuje formularz jako
// items[<item_id>][pole] (klucz = id pozycji, brak osobnego pola
// item_id); inni wołający mogą wysłać items[i][item_id]. Iterujemy z
// kluczami i gdy item_id brak w wierszu — używamy klucza.
$normalizedItems = [];
if (isset($_POST['items']) && is_array($_POST['items']) && !empty($_POST['items'])) {
    // Kształt payloadu ustalamy RAZ, dla całego requestu: strona
    // odpowiedzi wysyła items[<item_id>][pole] (klucz = id pozycji, bez
    // osobnego pola item_id), a inni wołający items[i][item_id].
    // Mieszanie obu w jednym requestcie byłoby niejednoznaczne (klucze
    // listy 0,1,2… wyglądałyby jak id pozycji) — wtedy wiersz bez
    // item_id jest błędem, a nie pozycją o id równym kluczowi.
    $hasExplicitItemIds = false;
    foreach ($_POST['items'] as $rawRow) {
        if (is_array($rawRow) && isset($rawRow['item_id']) && (int)$rawRow['item_id'] > 0) {
            $hasExplicitItemIds = true;
            break;
        }
    }
    foreach ($_POST['items'] as $idx => $raw) {
        if (!is_array($raw)) {
            echo json_encode(['success' => false, 'error' => "Pozycja #{$idx}: nieprawidłowy format danych."]);
            exit;
        }
        $itemId = (int)($raw['item_id'] ?? 0);
        if ($itemId <= 0) {
            if ($hasExplicitItemIds) {
                echo json_encode(['success' => false, 'error' => "Pozycja #{$idx}: brak identyfikatora pozycji."]);
                exit;
            }
            // Klucz requestu = id pozycji (forma strony odpowiedzi).
            $itemId = (int)$idx;
        }
        if ($itemId <= 0) {
            echo json_encode(['success' => false, 'error' => "Pozycja #{$idx}: brak identyfikatora pozycji."]);
            exit;
        }

        // unit_price: akceptujemy '.' albo ',' jako separator dziesiętny
        // (projekt miesza locale w inputach liczbowych). Puste/null →
        // null (= zachowaj cenę istniejącą — pozycja PO nie zna stanu
        // "niewycenione", więc puste NIE oznacza 0).
        $unitPrice = null;
        if (array_key_exists('unit_price', $raw) && $raw['unit_price'] !== null && $raw['unit_price'] !== '') {
            $priceRaw = trim((string)$raw['unit_price']);
            // Zamiana ',' na '.' TYLKO gdy brak '.' już w stringu —
            // chroni przed przypadkiem „1,234.56" (separator tysięcy +
            // decimal). Bezpieczniejsze niż str_replace globalny.
            if (strpos($priceRaw, ',') !== false && strpos($priceRaw, '.') === false) {
                $priceRaw = str_replace(',', '.', $priceRaw);
            }
            if (!is_numeric($priceRaw)) {
                echo json_encode([
                    'success' => false,
                    'error'   => "Pozycja #{$idx} ({$itemId}): cena jednostkowa musi być liczbą.",
                ]);
                exit;
            }
            $unitPrice = (float)$priceRaw;
            if ($unitPrice < 0) {
                echo json_encode([
                    'success' => false,
                    'error'   => "Pozycja #{$idx} ({$itemId}): cena jednostkowa nie może być ujemna.",
                ]);
                exit;
            }
        }

        // currency: opcjonalne, max 8 znaków. Pusta (lub sama biała
        // spacja) wartość jest dozwolona i oznacza "zachowaj istniejącą
        // walutę".
        $itemCurrency = null;
        if (isset($raw['currency'])) {
            $cur = trim((string)$raw['currency']);
            if ($cur !== '') {
                if (strlen($cur) > 8) {
                    echo json_encode([
                        'success' => false,
                        'error'   => "Pozycja #{$idx} ({$itemId}): nieprawidłowy kod waluty.",
                    ]);
                    exit;
                }
                $itemCurrency = $cur;
            }
        }

        // quantity: locale-aware decimal, must be > 0 when present.
        // Brak / pusty → null (= zachowaj istniejącą ilość w wierszu).
        $itemQty = null;
        if (array_key_exists('quantity', $raw) && $raw['quantity'] !== null) {
            $qtyRaw = trim((string)$raw['quantity']);
            if ($qtyRaw !== '') {
                // Akceptujemy '.' albo ',' jako separator dziesiętny (projekt
                // miesza locale — analogicznie do unit_price powyżej).
                if (strpos($qtyRaw, ',') !== false && strpos($qtyRaw, '.') === false) {
                    $qtyRaw = str_replace(',', '.', $qtyRaw);
                }
                if (!is_numeric($qtyRaw)) {
                    echo json_encode([
                        'success' => false,
                        'error'   => "Pozycja #{$idx} ({$itemId}): ilość musi być liczbą.",
                    ]);
                    exit;
                }
                $itemQty = (float)$qtyRaw;
                if ($itemQty <= 0) {
                    echo json_encode([
                        'success' => false,
                        'error'   => "Pozycja #{$idx} ({$itemId}): ilość musi być > 0.",
                    ]);
                    exit;
                }
            }
        }

        // quantity_unit_id: dodatni int gdy podany; brak → null (fallback
        // do istniejącej jednostki w handlerze).
        $itemUnitId = null;
        if (array_key_exists('quantity_unit_id', $raw) && $raw['quantity_unit_id'] !== null) {
            $uidRaw = trim((string)$raw['quantity_unit_id']);
            if ($uidRaw !== '') {
                if (!ctype_digit($uidRaw)) {
                    echo json_encode([
                        'success' => false,
                        'error'   => "Pozycja #{$idx} ({$itemId}): nieprawidłowa jednostka miary.",
                    ]);
                    exit;
                }
                $itemUnitId = (int)$uidRaw;
                if ($itemUnitId <= 0) {
                    echo json_encode([
                        'success' => false,
                        'error'   => "Pozycja #{$idx} ({$itemId}): nieprawidłowa jednostka miary.",
                    ]);
                    exit;
                }
            }
        }

        // Uwaga do pozycji dokumentu: klucz 'comment' dokładamy TYLKO wtedy,
        // gdy klient go faktycznie wysłał. Brak klucza = "nie ruszaj
        // zapisanej uwagi" (handler: array_key_exists('comment', …));
        // klucz z pustą wartością = wyczyść kolumnę. Strona odpowiedzi
        // (documents-respond.php) NIE wysyła tego pola — uwagi do pozycji
        // dokumentu są tam zastąpione przez „Uwagi do artykułu"
        // (list__vendor_part.comment), zapisywane osobnym POSTem.
        $itemRow = [
            'item_id'          => $itemId,
            'unit_price'       => $unitPrice,        // null = keep existing (handler fallback)
            'currency'         => $itemCurrency,                // null = keep existing
            'quantity'         => $itemQty,         // null = keep existing
            'quantity_unit_id' => $itemUnitId,      // null = keep existing
        ];
        if (array_key_exists('comment', $raw)) {
            $cmt = trim((string)$raw['comment']);
            $itemRow['comment'] = $cmt === '' ? null : $cmt;   // null = wyczyść kolumnę
        }
        $normalizedItems[] = $itemRow;
    }
}

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Brak zalogowanego użytkownika.']);
    exit;
}

$MsaDB   = MsaDB::getInstance();
$handler = new PurchaseActionHandler($MsaDB);

try {
    // Handler zapisuje wynegocjowane ceny/waluty na pozycjach PO
    // i w tej samej transakcji przenosi zamówienie `sent → confirmed`.
    $handler->confirmPoWithPrices($id, $userId, $normalizedItems, $vendorPoNumber, $confirmedAt);

    $po             = (new PurchaseOrderRepository($MsaDB))->getById($id);
    $documentNumber = $po !== null ? ($po->poNumber ?? null) : null;
    $vendorPoOut    = $po !== null ? ($po->vendorPoNumber ?? null) : null;

    echo json_encode([
        'success'          => true,
        'document_number'  => $documentNumber,
        'state'            => 'confirmed',
        'state_label'      => 'Potwierdzone',
        'vendor_po_number' => $vendorPoOut,
    ]);
} catch (\InvalidArgumentException $e) {
    // Osobny catch dla InvalidArgumentException: komunikat jest już po
    // polsku i skierowany do operatora (np. ujemna cena, za długi kod
    // waluty). Oddajemy wprost — bez error_log (to nie jest bug).
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (\LogicException $e) {
    // Stan dokumentu nie pozwala na operację; komunikat po polsku z
    // handlera trafia prosto do operatora.
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (\Throwable $e) {
    error_log('document-po-respond error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Błąd potwierdzania odpowiedzi.']);
}
exit;
