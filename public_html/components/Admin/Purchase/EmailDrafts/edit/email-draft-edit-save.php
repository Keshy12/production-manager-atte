<?php
/**
 * POST handler dla strony edycji szablonu wiadomości e-mail.
 *
 * Wpięty pod /public_html/components/Admin/Purchase/EmailDrafts/edit/email-draft-edit-save.php
 * — realny plik .php pod public_html/components, więc reguła POST
 * w .htaccess przerywa dalsze przepisywanie i Apache serwuje go
 * bezpośrednio. Zwraca JSON, żeby email-draft-edit-view.js mógł
 * pokazać błędy inline bez przeładowania strony. Patrz AGENTS.md
 * „AJAX endpoints (component-side)”.
 *
 * Jeden endpoint obsługuje tworzenie i edycję:
 *   - `id` brak albo <= 0 → CREATE (zwraca `new_id` + `edit_url`)
 *   - `id` > 0            → UPDATE
 *
 * Komunikat błędu z bazy wraca w payloadzie, żeby programista widział
 * dokładnie, na co narzeka baza, bez dostępu do logów serwera.
 * Komunikaty ogólne zostają po polsku.
 */
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Master\EmailDraftRepository;

header('Content-Type: application/json; charset=utf-8');

$listingUrl = 'http://' . BASEURL . '/admin/purchase/email-drafts';

// --- Auth gate ----------------------------------------------------------
if (!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Brak uprawnień.']);
    exit();
}

// --- Method gate --------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Metoda nieobsługiwana.']);
    exit();
}

// --- Read & sanitise inputs --------------------------------------------
$id          = (int)($_POST['id']       ?? 0);
$name        = $_POST['name']           ?? null;
$subject     = $_POST['subject']        ?? null;
$body        = $_POST['body']           ?? null;
$isActiveRaw = $_POST['isActive']       ?? null;
$appliesTo   = $_POST['applies_to']     ?? 'both';

// Trim + null-ify puste stringi. Treść przycinamy tylko z brzegów —
// wewnętrzne puste linie są częścią układu wiadomości.
foreach (['name' => &$name, 'subject' => &$subject, 'body' => &$body] as $k => &$v) {
    if ($v !== null) {
        $v = trim((string)$v);
        if ($v === '') { $v = null; }
    }
}
unset($v);

// --- Validation --------------------------------------------------------
if ($name === null) {
    echo json_encode(['success' => false, 'error' => 'Nazwa szablonu jest wymagana.']);
    exit();
}
if (mb_strlen($name) > 255) {
    echo json_encode(['success' => false, 'error' => 'Nazwa szablonu może mieć maksymalnie 255 znaków.']);
    exit();
}

if ($subject === null) {
    echo json_encode(['success' => false, 'error' => 'Temat szablonu jest wymagany.']);
    exit();
}
if (mb_strlen($subject) > 255) {
    echo json_encode(['success' => false, 'error' => 'Temat szablonu może mieć maksymalnie 255 znaków.']);
    exit();
}

if ($body === null) {
    echo json_encode(['success' => false, 'error' => 'Treść szablonu jest wymagana.']);
    exit();
}

// Blokada znaków < i >. Treść maila jest plain-textem od początku do
// końca: kreator wysyłki wkleja ją do mailto: i do <pre>, więc żaden
// znacznik nigdy nie zostanie zinterpretowany. Odrzucamy je na wejściu,
// żeby operator nie budował szablonu w oparciu o HTML, który i tak
// pojedzie do dostawcy dosłownie.
if (strpos($subject, '<') !== false || strpos($subject, '>') !== false) {
    echo json_encode([
        'success' => false,
        'error'   => 'Temat szablonu nie może zawierać znaków < ani > — to pole przyjmuje tylko zwykły tekst.',
    ]);
    exit();
}
if (strpos($body, '<') !== false || strpos($body, '>') !== false) {
    echo json_encode([
        'success' => false,
        'error'   => 'Treść szablonu nie może zawierać znaków < ani > — to pole przyjmuje tylko zwykły tekst.',
    ]);
    exit();
}

// isActive: radio wysyła '0' albo '1'. Cokolwiek innego odrzucamy —
// w odróżnieniu od dostawcy tutaj status decyduje o tym, czy szablon
// w ogóle trafi do maila, więc ciche domyślne "1" byłoby mylące.
if ($isActiveRaw !== '0' && $isActiveRaw !== '1' && $isActiveRaw !== 0 && $isActiveRaw !== 1) {
    echo json_encode(['success' => false, 'error' => 'Nieprawidłowy status szablonu.']);
    exit();
}
$isActive = ($isActiveRaw === '1' || $isActiveRaw === 1);

// applies_to: enum-like ('rfq' | 'po' | 'both'). Brak klucza w POST
// traktujemy jako 'both' (legacy POST-y / nowe szablony nie wymagają
// jawnego ustawiania, a domyślne 'both' zachowuje dotychczasowe
// zachowanie pickera — szablon widoczny dla obu typów dokumentów).
// Wartości spoza dozwolonego zestawu odrzucamy — po stronie JS
// radio / select pilnuje typu, więc ciche domyślne 'both' byłoby tu
// mylące (analogicznie do odrzucenia nierozpoznanego isActive).
if (!in_array($appliesTo, ['rfq', 'po', 'both'], true)) {
    echo json_encode([
        'success' => false,
        'error'   => 'Typ szablonu musi być jednym z: rfq, po, both.',
    ]);
    exit();
}

// --- Persist ------------------------------------------------------------
try {
    $MsaDB  = MsaDB::getInstance();
    $edRepo = new EmailDraftRepository($MsaDB);

    if ($id <= 0) {
        // CREATE — nazwa nie ma UNIQUE na poziomie schematu, duplikaty
        // przechodzą po cichu. Operator porządkuje je z poziomu listy.
        $newId = $edRepo->create($name, $subject, $body, $isActive, $appliesTo);
        echo json_encode([
            'success'  => true,
            'message'  => 'Szablon dodany pomyślnie.',
            'new_id'   => $newId,
            'edit_url' => $listingUrl . '/edit?id=' . $newId,
        ]);
        exit();
    }

    // UPDATE
    $ok = $edRepo->update($id, $name, $subject, $body, $isActive, $appliesTo);

    if (!$ok && $edRepo->getById($id) === null) {
        echo json_encode(['success' => false, 'error' => 'Nie znaleziono szablonu #' . $id . '.']);
        exit();
    }

    echo json_encode([
        'success' => true,
        'message' => 'Szablon zapisany.',
    ]);
} catch (\InvalidArgumentException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (\PDOException $e) {
    echo json_encode([
        'success' => false,
        'error'   => 'Błąd bazy danych: ' . $e->getMessage() . ' (SQLSTATE ' . $e->getCode() . ')',
    ]);
} catch (\Throwable $e) {
    echo json_encode([
        'success' => false,
        'error'   => 'Wystąpił nieoczekiwany błąd: ' . $e->getMessage(),
    ]);
}
