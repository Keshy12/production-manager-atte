<?php
/**
 * AJAX: oznacza zapytanie ofertowe (RFQ) jako `responded` —
 * operator zarejestrował odpowiedź od dostawcy. Współdzielony przez:
 *   - wizard "Wyślij dokument" (Admin/Purchase/Documents/documents-send.php)
 *     → POST { id, responded_at } bez pozycji, kompatybilność wstecz
 *   - stronę "Oznacz odpowiedź" (Admin/Purchase/Documents/documents-respond.php)
 *     → POST { id, responded_at, items[i][item_id|unit_price|currency|comment] }
 *
 * Kontrakt wejścia/wyjścia zamrożony dla wizarda (fronted przez
 * send-view.js). Wariant z `items` dodaje per-item pola dla dedykowanej
 * strony odpowiedzi; gdy ich brak, zachowanie jest identyczne z
 * historycznym (handler dostaje pustą listę pozycji).
 *
 * POST (form-encoded):
 *   id                int                    (wymagane, > 0)
 *   responded_at      'Y-m-d H:i:s' | 'Y-m-d' | ''
 *                                     opcjonalne — data odpowiedzi.
 *                                     'Y-m-d' normalizowane do
 *                                     'Y-m-d 00:00:00'; puste → null,
 *                                     handler użyje NOW().
 *   items[i][item_id]          int             (wymagane gdy items obecne, > 0)
 *   items[i][unit_price]       string|empty    opcjonalne; akceptujemy '.' lub
 *                                     ',' jako separator dziesiętny (projekt
 *                                     miesza locale — patrz audits/DISCREPANCIES),
 *                                     pusty → null
 *   items[i][currency]         string          opcjonalne; max 8 znaków
 *   items[i][comment]          string|empty    opcjonalne; pusty → null
 *   items[i][quantity]         string          decimal (locale-tolerant via ,→. normalize) > 0
 *   items[i][quantity_unit_id] int             > 0
 *
 *   jQuery $.ajax({ data: { items: [...] } }) emituje form-encoding
 *   `items[0][item_id]=…`, PHP parsuje to do $_POST['items'][0]['item_id']
 *   automatycznie — żadnego JSON po stronie klienta.
 *
 * Odpowiedź sukces:
 *   {
 *     success:     true,
 *     state:       'responded',
 *     state_label: 'Odpowiedź'
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

$id             = (int)($_POST['id'] ?? 0);
$respondedAtRaw = $_POST['responded_at'] ?? null;

if ($id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Nieprawidłowy identyfikator zapytania.']);
    exit;
}

// Normalizacja responded_at: 'Y-m-d' → 'Y-m-d 00:00:00'; puste → null.
// Handler waliduje ściśle 'Y-m-d H:i:s', więc endpoint jest właścicielem
// tej konwersji (identycznie jak document-send-ajax.php dla sent_at).
$respondedAt = null;
if ($respondedAtRaw !== null) {
    $respondedAtRaw = trim((string)$respondedAtRaw);
    if ($respondedAtRaw !== '') {
        $respondedAt = $respondedAtRaw;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $respondedAt)) {
            $respondedAt .= ' 00:00:00';
        }
    }
}

// Parsowanie per-item pól. items[i] to tablica asocjacyjna
// (item_id/unit_price/currency/comment) sparsowana przez PHP z
// form-encoding `items[i][…]`. Akceptujemy zarówno listę (`$_POST['items']`
// jest indexed array) jak i mapę (asocjacyjna po kluczach item_id —
// teoretyczny przypadek gdyby frontend wysłał to inaczej); normalizujemy
// do listy przed walidacją.
$normalizedItems = [];
if (isset($_POST['items']) && is_array($_POST['items']) && !empty($_POST['items'])) {
    // indexed array -> bierzemy wprost; assoc -> przepakowujemy do listy wartości.
    $isList = array_keys($_POST['items']) === range(0, count($_POST['items']) - 1);
    $rawItems = $isList ? $_POST['items'] : array_values($_POST['items']);
    foreach ($rawItems as $idx => $raw) {
        if (!is_array($raw)) {
            echo json_encode(['success' => false, 'error' => "Pozycja #{$idx}: nieprawidłowy format danych."]);
            exit;
        }
        $itemId = (int)($raw['item_id'] ?? 0);
        if ($itemId <= 0) {
            echo json_encode(['success' => false, 'error' => "Pozycja #{$idx}: brak identyfikatora pozycji."]);
            exit;
        }

        // unit_price: akceptujemy '.' albo ',' jako separator dziesiętny
        // (audyt wskazał, że projekt miesza locale w inputach liczbowych).
        // Pusty/null → null (cena nie ustalona przez dostawcę).
        // Trimujemy whitespace; walidujemy is_numeric po normalizacji.
        $unitPrice = null;
        if (array_key_exists('unit_price', $raw) && $raw['unit_price'] !== null && $raw['unit_price'] !== '') {
            $priceRaw = trim((string)$raw['unit_price']);
            // Zamiana ',' na '.' TYLKO gdy brak '.' już w stringu —
            // chroni przed przypadkiem „1,234.56" (separator tysięcy + decimal),
            // choć w naszych inputach to się nie zdarza. Bezpieczniejsze niż
            // str_replace globalny.
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

        // currency: opcjonalne, max 8 znaków. Handler też waliduje, ale
        // zwrócenie ładniejszego komunikatu tutaj oszczędza wywołania.
        if (isset($raw['currency']) && $raw['currency'] !== '') {
            $cur = trim((string)$raw['currency']);
            if ($cur === '' || strlen($cur) > 8) {
                echo json_encode([
                    'success' => false,
                    'error'   => "Pozycja #{$idx} ({$itemId}): nieprawidłowy kod waluty.",
                ]);
                exit;
            }
        }

        // quantity: locale-aware decimal, must be > 0 when present.
        // Backward compat: jeśli brak → null; handler użyje istniejącej
        // wartości z wiersza RFQ (czyste zachowanie historyczne dla
        // send-wizard caller, który nigdy nie wysyła tego pola).
        $itemQty = null;
        if (array_key_exists('quantity', $raw) && $raw['quantity'] !== null) {
            $qtyRaw = trim((string)$raw['quantity']);
            if ($qtyRaw !== '') {
                // Akceptujemy '.' albo ',' jako separator dziesiętny (projekt
                // miesza locale — analogicznie do unit_price powyżej). Chronimy
                // się przed przypadkiem „1,234.56" (separator tysięcy + decimal)
                // sprawdzając, czy oba separatory nie współistnieją.
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
        // w handlerze). Polityka walidacji spójna z polami wyżej.
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

        $normalizedItems[] = [
            'item_id'          => $itemId,
            'unit_price'       => $unitPrice,
            'currency'         => isset($raw['currency']) && $raw['currency'] !== ''
                ? trim((string)$raw['currency'])
                : null,
            'comment'          => isset($raw['comment']) && $raw['comment'] !== ''
                ? (string)$raw['comment']
                : null,
            'quantity'         => $itemQty,        // null = keep existing (handler fallback)
            'quantity_unit_id' => $itemUnitId,     // null = keep existing (handler fallback)
        ];
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
    // Jeden handler obsługuje oba warianty: items=[] zachowuje stare
    // zachowanie (czysta zmiana stanu, bez ruszania pozycji); items>0
    // aktualizuje ceny/waluty/uwagi i dopiero potem przechodzi w responded.
    $handler->markRfqRespondedWithPrices($id, $userId, $normalizedItems, $respondedAt);

    echo json_encode([
        'success'     => true,
        'state'       => 'responded',
        'state_label' => 'Odpowiedź',
    ]);
} catch (\InvalidArgumentException $e) {
    // Osobny catch dla InvalidArgumentException: komunikat jest już po
    // polsku i skierowany do operatora (np. ujemna cena, za długi kod
    // waluty). Oddajemy wprost — bez error_log (to nie jest bug).
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (\LogicException $e) {
    // Stan dokumentu nie pozwala na operację; komunikat po polsku od
    // handlera trafia prosto do operatora.
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (\Throwable $e) {
    error_log('document-rfq-respond error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Błąd oznaczania odpowiedzi.']);
}
exit;