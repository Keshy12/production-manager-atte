<?php
/**
 * Dedicated edit/create page for a single vendor.
 *
 * Wired at /admin/purchase/vendors/edit?id=N (mirrors the
 * producer-edit / vendor-parts/edit convention). Renders the form
 * on GET and lets vendor-edit-save.php handle the POST submission.
 *
 * Modes:
 *   - `?id=N` (N > 0) → EDIT existing vendor (pre-populated fields)
 *   - absent / id<=0  → CREATE new vendor (empty form)
 *
 * Vendor form fields (mirror list__vendor):
 *   - name (required), address, additional_data, lead_time_days,
 *     default_currency (FK → list__currency.id; the form sends the
 *     3-letter ISO code which the repository resolves to the id),
 *     comment, isActive radio,
 *   - preferred_email_draft_rfq_id / preferred_email_draft_po_id
 *     (FK → email_draft.id; NULL = dostawca podąża za globalnym
 *     domyślnym). Sam szablon edytuje się w osobnym module
 *     /admin/purchase/email-drafts — tutaj wskazuje się tylko,
 *     którego użyć.
 *
 * In EDIT mode the page also renders:
 *   - Inline supplier CRUD (form + list) — replaces the old
 *     "Szczegóły" modal. "Dodaj osobę kontaktową" + per-row
 *     "Edytuj" repurpose a single shared form.
 *   - Read-only list of vendor parts using this vendor (mirrors
 *     the producer edit page's read-only VPs).
 *   Oba zestawy mieszkają w JEDNYM modalu (#vrRelationsModal)
 *   otwieranym przyciskiem „Powiązania" w nagłówku strony. Liczby
 *   (osoby, artykuły) widać na samym przycisku, bez otwierania
 *   modala. Po zmianie u osoby kontaktowej strona przeładowuje się
 *   z powrotem otwartym modalem (?ok=… + #vrRelations), więc
 *   operator nie traci miejsca.
 *
 * Formularz to cztery osobne karty (każda z ikoną, tytułem i jednym
 * zdaniem opisu): Dane podstawowe, Status, Preferowane szablony
 * wiadomości oraz „Więcej" (rzadkie pola). WSZYSTKIE startują zwinięte
 * i pokazują jednolinijkowe podsumowanie bieżących wartości (tylko do
 * odczytu); kliknięcie nagłówka rozwija edytowalne pola. Podsumowanie
 * leży POZA przyciskiem nagłówka (nie wchodzi do jego nazwy dla
 * czytników ekranu), a odświeża je vendor-edit-view.js przy każdej
 * zmianie pola. Wszystkie karty leżą w jednym #vrEditForm — zwinięcie
 * karty chowa pola tylko CSS-em, więc serialize() nadal je wysyła.
 *
 * On a successful CREATE the JS redirects to ?id=N so the operator
 * lands on the edit page for the freshly-created vendor. On a
 * successful UPDATE the JS stays on the page and shows an inline
 * success alert. On validation errors the save endpoint returns
 * JSON and the JS shows an inline alert at the top of the page.
 *
 * Wygląd: ten sam język co lista dostawców (/admin/purchase/vendors) —
 * receipts-ui.css jako baza (tylko do odczytu) + vendor-edit-ui.css.
 * Cała strona stoi w jednej kolumnie o szerokości 75%.
 */
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Master\VendorRepository;
use Atte\Utils\Purchase\Master\VendorSupplierRepository;
use Atte\Utils\Purchase\Master\CurrencyRepository;
use Atte\Utils\Purchase\Master\EmailDraftRepository;

if (!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    header("Location: http://".BASEURL."/");
    exit();
}

$id = (int)($_GET['id'] ?? 0);
$isEditMode = $id > 0;
$vendor = null;
$notFound = false;
$vendorName             = '';
$vendorAddress          = '';
$vendorAdditionalData   = '';
$vendorShopUrl          = '';
$vendorLeadTimeDays     = '';
$vendorDefaultCurrency  = 'PLN';
$vendorComment          = '';
$vendorIsActive         = true;   // default: Aktywny (only meaningful in create mode)
$suppliers              = [];     // supplier rows for inline CRUD
$vendorPartCount        = 0;      // liczba wszystkich artykułów (przycisk „Powiązania")
$vendorPreferredRfqId   = 0;      // FK → email_draft.id (RFQ); 0 = brak
$vendorPreferredPoId    = 0;      // FK → email_draft.id (PO);  0 = brak

if ($isEditMode) {
    $MsaDB = MsaDB::getInstance();
    $repo  = new VendorRepository($MsaDB);
    $vendor = $repo->getById($id);
    if ($vendor === null) {
        // Nie ma takiego dostawcy — strona pokazuje pusty stan zamiast
        // formularza (który i tak nie miałby czego zapisywać).
        $notFound = true;
    } else {
        $vendorName             = $vendor->name;
        $vendorAddress          = $vendor->address          ?? '';
        $vendorAdditionalData   = $vendor->additionalData   ?? '';
        $vendorShopUrl          = $vendor->shopUrl          ?? '';
        $vendorLeadTimeDays     = $vendor->leadTimeDays     ?? '';
        // Prefer the joined code (string); fall back to 'PLN' if the JOIN
        // somehow missed (e.g. the FK points at an inactive currency row).
        $vendorDefaultCurrency  = $vendor->defaultCurrencyCode ?: 'PLN';
        $vendorComment          = $vendor->comment          ?? '';
        $vendorIsActive         = $vendor->isActive;
        $vendorPartCount        = $repo->countVendorParts($id);

        // Preferowane szablony wiadomości — same wskaźniki (FK), nie treść.
        // Treść żyje w globalnym katalogu /admin/purchase/email-drafts.
        $vendorPreferredRfqId   = (int)($vendor->preferredEmailDraftRfqId ?? 0);
        $vendorPreferredPoId    = (int)($vendor->preferredEmailDraftPoId  ?? 0);

        // Inline supplier CRUD — fetch all suppliers (active + inactive)
        // and hydrate them as JS-readable JSON so the edit-page JS can
        // populate the shared form when "Edytuj" is clicked on a row.
        $vsRepo    = new VendorSupplierRepository($MsaDB);
        $suppliers = $vsRepo->getByVendor($id, false);

        // Lista artykułów u dostawcy NIE jest tu ładowana — dostawca może
        // mieć ich tysiące. Blok „Artykuły u dostawców" w modalu
        // powiązań renderuje się z AJAX-u (edit/vendor-parts-modal-list.php)
        // razem z filtrowaniem i stronicowaniem. W PHP zostaje tylko
        // $vendorPartCount na licznik na przycisku „Powiązania" i w nagłówku modala.
    }
}

// Currency dropdown — always loaded so CREATE mode also offers the
// canonical list. getActiveEnsuring() brings back a stale/inactive
// vendor currency into view rather than rendering a blank select.
// $MsaDB must exist; in edit mode it's set above; in create mode we
// instantiate it now (cheap singleton).
if (!isset($MsaDB)) {
    $MsaDB = MsaDB::getInstance();
}
$currencies = [];
$emailDrafts = [];
$defaultDraftIds = ['rfq' => 0, 'po' => 0];
$vrDraftPickers = [];
$vrCustomDraftCount = 0;
if (!$notFound) {
    $currencies = (new CurrencyRepository($MsaDB))->getActiveEnsuring($vendorDefaultCurrency);

    // Preferowane szablony wiadomości — lekka projekcja {id, name} dla
    // pickerów. Ładowana także w trybie tworzenia, bo preferencję da się
    // ustawić od razu przy zakładaniu dostawcy (create() w repozytorium
    // przyjmuje oba FK). Lista zawiera wyłącznie aktywne szablony; jeśli
    // dostawca wskazuje szablon, który ktoś w międzyczasie wyłączył, jego
    // id nie ma w opcjach i picker pokaże „— brak —". To jest zgodne
    // z zachowaniem kreatora wysyłki, który dla nieaktywnego szablonu i
    // tak wraca do domyślnego systemowego.
    if (!isset($repo)) {
        $repo = new VendorRepository($MsaDB);
    }
    $emailDrafts = $repo->getActiveEmailDrafts();

    // Identyfikatory GLOBALNYCH domyślnych (`email_draft_default`,
    // PK = doc_type → po jednym wskaźniku na typ dokumentu).
    $defaultDraftIds = (new EmailDraftRepository($MsaDB))->getDefaultDraftIds();

    // Dwa niezależne pickery — po jednym na typ dokumentu. Każdy dostaje
    // swój katalog (filtrowany po applies_to tak samo jak katalog
    // kreatora wysyłki) i swoją pozycję: preferencja dostawcy, jeśli
    // nadal jest w katalogu, w przeciwnym razie globalny domyślny.
    // Dzięki temu picker NIGDY nie stoi na „— brak —" — pokazuje szablon,
    // który realnie zostanie użyty przy wysyłce.
    $vrDraftPickers = [
        [
            'id'    => 'vr_pref_draft_rfq',
            'cell'  => 'vrDraftRfqCell',
            'name'  => 'preferred_email_draft_rfq_id',
            'main'  => 'zapytań ofertowych',
            'abbr'  => 'RFQ',
            'type'  => 'rfq',
            'prefId' => $vendorPreferredRfqId,
        ],
        [
            'id'    => 'vr_pref_draft_po',
            'cell'  => 'vrDraftPoCell',
            'name'  => 'preferred_email_draft_po_id',
            'main'  => 'zamówień',
            'abbr'  => 'PO',
            'type'  => 'po',
            'prefId' => $vendorPreferredPoId,
        ],
    ];
    foreach ($vrDraftPickers as &$vrPicker) {
        $vrState = EmailDraftRepository::resolvePickerState(
            $vrPicker['type'],
            (int)$vrPicker['prefId'],
            $emailDrafts,
            $defaultDraftIds
        );
        $vrPicker['options']    = $vrState['options'];
        $vrPicker['selectedId'] = $vrState['selectedId'];
        $vrPicker['defaultId']  = $vrState['defaultId'];

        // „Własny" = wybór dostawcy RÓŻNIĄCY się od domyślnego. Tylko
        // taki liczy chip w nagłówku sekcji: szablon równy domyślnemu
        // to wciąż „podążanie za domyślnym", a nie własny wybór.
        if ($vrPicker['selectedId'] > 0 && $vrPicker['selectedId'] !== $vrPicker['defaultId']) {
            $vrCustomDraftCount++;
        }
    }
    unset($vrPicker);
}

$backUrl = 'http://' . BASEURL . '/admin/purchase/vendors';

// Komunikat po powrocie z przeładowania (dodanie / edycja / zmiana
// statusu osoby kontaktowej). Przekazujemy kod, nie dowolny tekst —
// treść siedzi tu, w jednym miejscu.
$vrOkMessages = [
    'supplier-added'   => 'Dodano osobę kontaktową.',
    'supplier-updated' => 'Zapisano osobę kontaktową.',
    'supplier-toggled' => 'Zmieniono status osoby kontaktowej.',
];
$vrOkMessage = $vrOkMessages[(string)($_GET['ok'] ?? '')] ?? '';

// Polska liczba mnoga: 1 osoba / 2 osoby / 5 osób.
function vr_count_label(int $n, string $one, string $few, string $many): string {
    if ($n === 1) { return $one; }
    $mod10  = $n % 10;
    $mod100 = $n % 100;
    if ($mod10 >= 2 && $mod10 <= 4 && !($mod100 >= 12 && $mod100 <= 14)) {
        return $few;
    }
    return $many;
}

// Przycisk „Powiązania" w nagłówku: liczby osób i artykułów.
$vrSupplierCount = count($suppliers);
$vrPartCount     = (int)$vendorPartCount;
$vrRelFull = $vrSupplierCount . ' '
    . vr_count_label($vrSupplierCount, 'osoba kontaktowa', 'osoby kontaktowe', 'osób kontaktowych')
    . ', ' . $vrPartCount . ' '
    . vr_count_label($vrPartCount, 'artykuł', 'artykuły', 'artykułów');

// Karta „Więcej": rzadko używane pola. Licznik w nagłówku mówi, ile z
// nich ma wartość; to, które to są, mówi linijka podsumowania.
$vrMoreValues = [$vendorLeadTimeDays, $vendorShopUrl, $vendorAdditionalData, $vendorComment];
$vrMoreTotal  = count($vrMoreValues);
$vrMoreFilled = 0;
foreach ($vrMoreValues as $vrMoreValue) {
    if (trim((string)$vrMoreValue) !== '') {
        $vrMoreFilled++;
    }
}

// ---- Linijki podsumowania zwiniętych kart --------------------------
// Wszystkie karty startują zwinięte, więc podsumowanie musi być gotowe
// przy pierwszym malowaniu strony (bez czekania na JS). vendor-edit-view.js
// odświeża te same teksty przy każdej zmianie pola — format musi być
// identyczny po obu stronach (separator „ · ").
// Każde podsumowanie to para [html, tekst]: html idzie do strony (z
// <strong> przy nazwie), tekst do atrybutu title (pełna treść, gdy
// linijka jest ucięta wielokropkiem).
$vrEsc = static function ($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES);
};

// Reguła: karta z polami tekstowymi wylicza WSZYSTKIE swoje pola, w
// kolejności z karty. Pole wypełnione = jego wartość (zwykły tekst);
// puste = krótka nazwa pola w stylu „brak" (<span class="vxe-sum-missing">,
// kursywa + wyblakły kolor). Tooltip to jawna lista „pole: wartość",
// z „—" przy pustym polu. Ten sam format buduje vendor-edit-view.js.
$vrMissing = static function (string $name) use ($vrEsc): string {
    return '<span class="vxe-sum-missing">' . $vrEsc($name) . '</span>';
};
// Spacje/nowe linie w wartości do jednej linii (tooltip „pole: wartość").
$vrOneLine = static function ($s): string {
    return trim((string)preg_replace('/\s+/u', ' ', (string)$s));
};

// Dane podstawowe: nazwa · waluta · adres.
$vrName = trim($vendorName);
$vrCur  = trim($vendorDefaultCurrency);
$vrAddr = trim($vendorAddress);
$vrSumBasic = [
    implode(' · ', [
        $vrName !== '' ? '<strong>' . $vrEsc($vrName) . '</strong>' : $vrMissing('nazwa'),
        $vrCur  !== '' ? $vrEsc($vrCur)  : $vrMissing('waluta'),
        $vrAddr !== '' ? $vrEsc($vrAddr) : $vrMissing('adres'),
    ]),
    implode(' · ', [
        'nazwa: '  . ($vrName !== '' ? $vrOneLine($vrName) : '—'),
        'waluta: ' . ($vrCur  !== '' ? $vrOneLine($vrCur)  : '—'),
        'adres: '  . ($vrAddr !== '' ? $vrOneLine($vrAddr) : '—'),
    ]),
];

// Status.
$vrSumStatusLabel = $vendorIsActive ? 'Aktywny' : 'Nieaktywny';
$vrSumStatus = [
    '<span class="vxe-sum-dot ' . ($vendorIsActive ? 'is-on' : 'is-off') . '" aria-hidden="true"></span>'
        . $vrSumStatusLabel,
    $vrSumStatusLabel,
];

// Preferowane szablony: „RFQ: Nazwa ★ · PO: Nazwa ★" — nazwa szablonu,
// który realnie zostanie użyty, ★ przy globalnym domyślnym. Dane z już
// wczytanego $vrDraftPickers (options / selectedId), bez nowego zapytania.
$vrSumDraftText = [];
foreach ($vrDraftPickers as $vrP) {
    $vrLabel = 'brak szablonu';
    foreach ($vrP['options'] as $vrO) {
        if ((int)$vrO['id'] === (int)$vrP['selectedId']) {
            $vrLabel = (string)$vrO['name'] . ($vrO['isGlobalDefault'] ? ' ★' : '');
            break;
        }
    }
    $vrSumDraftText[] = $vrP['abbr'] . ': ' . $vrLabel;
}
$vrSumDraftPlain = implode(' · ', $vrSumDraftText);
$vrSumDraft = [$vrEsc($vrSumDraftPlain), $vrSumDraftPlain];

// Więcej: lead time · adres sklepu · dane dodatkowe · komentarz. Trzy
// długie pola w linijce to tylko nazwa (wartość jest w tooltipie), więc
// to styl „brak" odróżnia puste od wypełnionego.
$vrLt   = trim((string)$vendorLeadTimeDays);
$vrShop = trim($vendorShopUrl);
$vrAdd  = trim($vendorAdditionalData);
$vrCom  = trim($vendorComment);
$vrLtUnit = $vrLt === '1' ? 'dzień' : 'dni';
$vrSumMore = [
    implode(' · ', [
        $vrLt   !== '' ? $vrEsc('Lead time ' . $vrLt . ' ' . $vrLtUnit) : $vrMissing('lead time'),
        $vrShop !== '' ? 'adres sklepu'   : $vrMissing('adres sklepu'),
        $vrAdd  !== '' ? 'dane dodatkowe' : $vrMissing('dane dodatkowe'),
        $vrCom  !== '' ? 'komentarz'      : $vrMissing('komentarz'),
    ]),
    implode(' · ', [
        'lead time: '      . ($vrLt   !== '' ? $vrOneLine($vrLt) . ' ' . $vrLtUnit : '—'),
        'adres sklepu: '   . ($vrShop !== '' ? $vrOneLine($vrShop) : '—'),
        'dane dodatkowe: ' . ($vrAdd  !== '' ? $vrOneLine($vrAdd)  : '—'),
        'komentarz: '      . ($vrCom  !== '' ? $vrOneLine($vrCom)  : '—'),
    ]),
];

?>
<link rel="stylesheet" href="<?= asset('public_html/components/purchases/receipts/receipts-ui.css') ?>">
<link rel="stylesheet" href="<?= asset('public_html/components/Admin/Purchase/Vendors/edit/vendor-edit-ui.css') ?>">

<div class="rx-page vxe-page" id="vendorEditView">

  <!-- Cała strona w jednej kolumnie (75%) — okruszki, nagłówek,
       formularz i pasek powiązań mają tę samą szerokość. -->
  <div class="vxe-column">

    <!-- ===== Ścieżka nawigacji ===== -->
    <nav aria-label="Ścieżka">
        <ol class="rx-crumbs">
            <li><a href="<?= htmlspecialchars($backUrl, ENT_QUOTES) ?>">Dostawcy</a></li>
            <li aria-hidden="true"><i class="bi bi-chevron-right"></i></li>
            <li aria-current="page">
                <?= $isEditMode && !$notFound
                    ? htmlspecialchars($vendorName, ENT_QUOTES)
                    : 'Nowy dostawca' ?>
            </li>
        </ol>
    </nav>

    <!-- ===== Nagłówek strony ===== -->
    <header class="rx-head">
        <div class="rx-head-main">
            <div class="rx-eyebrow"><i class="bi bi-truck"></i> Zamówienia komponentów</div>
            <h1 class="rx-title">
                <?= $isEditMode && !$notFound ? 'Edytuj dostawcę' : 'Dodaj dostawcę' ?>
            </h1>
            <p class="rx-lead">
                <?php if ($isEditMode && !$notFound): ?>
                    Dane dostawcy, osoby kontaktowe i&nbsp;powiązane artykuły.
                    Zmiany zapisujesz przyciskiem na&nbsp;dole formularza.
                <?php else: ?>
                    Wpisz dane nowego dostawcy — osoby kontaktowe i&nbsp;artykuły dodasz
                    później, w&nbsp;kartach tego samego dostawcy.
                <?php endif; ?>
            </p>
        </div>
        <?php if ($isEditMode && !$notFound): ?>
            <!-- Id dostawcy + przycisk „Powiązania". Liczby osób i artykułów
                 są na samym przycisku — widać je bez otwierania modala.
                 „Wróć do listy" jest w pasku zapisu pod formularzem oraz
                 w okruszkach, więc w nagłówku go nie dublujemy. -->
            <div class="vxe-head-links">
                <span class="rx-count vxe-head-id" title="Identyfikator dostawcy">#<?= (int)$vendor->id ?></span>
                <button type="button" class="btn vxe-rel-btn"
                        data-toggle="modal" data-target="#vrRelationsModal"
                        aria-haspopup="dialog"
                        aria-label="Powiązania dostawcy: <?= htmlspecialchars($vrRelFull, ENT_QUOTES) ?>"
                        title="<?= htmlspecialchars($vrRelFull, ENT_QUOTES) ?> — kliknij, aby zobaczyć i edytować">
                    <span class="vxe-rel-main"><i class="bi bi-diagram-3"></i> Powiązania</span>
                    <span class="vxe-rel-counts">
                        <span class="vxe-rel-chip<?= $vrSupplierCount === 0 ? ' is-zero' : '' ?>">
                            <strong><?= $vrSupplierCount ?></strong>
                            <?= vr_count_label($vrSupplierCount, 'osoba', 'osoby', 'osób') ?>
                        </span>
                        <span class="vxe-rel-chip<?= $vrPartCount === 0 ? ' is-zero' : '' ?>">
                            <strong><?= $vrPartCount ?></strong>
                            <?= vr_count_label($vrPartCount, 'artykuł', 'artykuły', 'artykułów') ?>
                        </span>
                    </span>
                </button>
            </div>
        <?php endif; ?>
    </header>

    <!-- Host na komunikaty AJAX (błąd zapisu dostawcy). Pusty,
         dopiero JS wstawia tu alert — dlatego osobny, stały punkt
         zaczepienia zamiast dopisywania do kontenera strony.
         Komunikaty pooperacyjne (dodanie osoby kontaktowej) trafiają
         do modala — patrz #vrModalAlertHost. -->
    <div id="vrFormAlertHost" class="rx-alerts"></div>

<?php if ($notFound): ?>

    <!-- ===== Nie ma takiego dostawcy ===== -->
    <section class="rx-panel vxe-panel">
        <div class="rx-empty rx-empty--page">
            <div class="rx-empty-icon rx-empty-icon--danger"><i class="bi bi-exclamation-triangle"></i></div>
            <div class="rx-empty-title">Dostawca nie istnieje</div>
            <div class="rx-empty-text">
                Dostawca #<?= htmlspecialchars((string)$id, ENT_QUOTES) ?> nie został znaleziony
                albo został usunięty.
            </div>
            <a href="<?= htmlspecialchars($backUrl, ENT_QUOTES) ?>" class="btn btn-sm btn-primary">
                <i class="bi bi-arrow-left"></i> Wróć do listy
            </a>
        </div>
    </section>

<?php else: ?>

    <!-- ================================================================
         Formularz dostawcy. Wszystkie pola (także pickery preferowanych
         szablonów) leżą WEWNĄTRZ #vrEditForm, więc $form.serialize()
         widzi komplet — nic nie trzeba doklejać ręcznie do POST-a.
         ================================================================ -->
    <form method="post" id="vrEditForm" autocomplete="off">
        <input type="hidden" name="id" value="<?= $isEditMode ? (int)$vendor->id : 0 ?>">

        <!-- Wszystkie cztery karty mają ten sam wzór (zwijanie Bootstrap 4):
             <h2> NA ZEWNĄTRZ przycisku (przycisk przyjmuje tylko treść
             frazową), w nim ikona + tytuł + opis + strzałka; pod nagłówkiem
             linijka podsumowania (też poza przyciskiem — długi tekst nie
             ma być nazwą przycisku dla czytnika ekranu); potem ciało
             .collapse. Karty startują ZWINIĘTE (klasa `collapsed` na
             przycisku, brak `show` na ciele). Podsumowanie widać tylko
             przy zwiniętej karcie, opis tylko przy rozwiniętej — patrz
             vendor-edit-ui.css. -->

        <!-- Przełącznik wszystkich kart naraz. -->
        <div class="vxe-form-tools">
            <button type="button" class="btn rx-link-btn" id="vrToggleAllCards">
                <i class="bi bi-arrows-expand" aria-hidden="true"></i>
                <span class="vxe-tools-label">Rozwiń wszystko</span>
            </button>
        </div>

        <!-- ===== Karta 1: Dane podstawowe ===== -->
        <section class="rx-panel vxe-panel vxe-card" id="vrBasicCard">
            <h2 class="vxe-card-toggle-h">
                <button type="button" class="vxe-card-head vxe-card-head--toggle collapsed"
                        data-toggle="collapse" data-target="#vrBasicBody"
                        aria-expanded="false" aria-controls="vrBasicBody">
                    <span class="vxe-card-icon" aria-hidden="true"><i class="bi bi-building"></i></span>
                    <span class="vxe-card-text">
                        <span class="vxe-section-label">Dane podstawowe</span>
                        <span class="vxe-card-desc">Nazwa, adres i&nbsp;waluta, w&nbsp;której rozliczasz się z&nbsp;dostawcą.</span>
                    </span>
                    <span class="vxe-card-aside">
                        <i class="bi bi-chevron-down vxe-card-chevron" aria-hidden="true"></i>
                    </span>
                </button>
            </h2>
            <p class="vxe-card-summary" id="vrBasicSummary" title="<?= $vrEsc($vrSumBasic[1]) ?>"><?= $vrSumBasic[0] ?></p>
            <div class="collapse" id="vrBasicBody">
              <div class="vxe-card-body">
                <div class="vxe-grid">

                    <div class="vxe-field vxe-field--8">
                        <label class="vxe-label" for="vr_edit_name">
                            Nazwa <span class="vxe-required">*</span>
                        </label>
                        <input type="text" id="vr_edit_name" name="name"
                               class="form-control" required maxlength="255"
                               value="<?= htmlspecialchars($vendorName, ENT_QUOTES) ?>">
                    </div>

                    <div class="vxe-field vxe-field--4">
                        <label class="vxe-label" for="vr_edit_currency">Domyślna waluta</label>
                        <!-- AGENTS.md: data-width="100%" + data-container na
                             komórce + min-width: 0 na komórce. -->
                        <div class="vxe-picker" id="vrCurrencyCell">
                            <select id="vr_edit_currency" name="default_currency"
                                    class="selectpicker"
                                    data-width="100%"
                                    data-container="#vrCurrencyCell"
                                    data-live-search="true"
                                    data-style="rx-picker-btn"
                                    title="Wybierz walutę…">
                                <?php foreach ($currencies as $cur):
                                    $code = (string)$cur['code'];
                                    $name = (string)$cur['name'];
                                    $isSelected = ($code === $vendorDefaultCurrency);
                                ?>
                                    <option value="<?= htmlspecialchars($code, ENT_QUOTES) ?>"
                                            <?= $isSelected ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($code, ENT_QUOTES) ?> — <?= htmlspecialchars($name, ENT_QUOTES) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <p class="vxe-help">Domyślna waluta dla nowych zamówień u&nbsp;tego dostawcy.</p>
                    </div>

                    <div class="vxe-field vxe-field--12">
                        <label class="vxe-label" for="vr_edit_address">Adres</label>
                        <input type="text" id="vr_edit_address" name="address"
                               class="form-control"
                               value="<?= htmlspecialchars($vendorAddress, ENT_QUOTES) ?>">
                    </div>

                </div>
                <p class="vxe-help vxe-required-note">Pola oznaczone <span class="vxe-required">*</span> są wymagane.</p>
              </div>
            </div>
        </section>

        <!-- ===== Karta 2: Status ===== -->
        <section class="rx-panel vxe-panel vxe-card" id="vrStatusCard">
            <h2 class="vxe-card-toggle-h">
                <button type="button" class="vxe-card-head vxe-card-head--toggle collapsed"
                        data-toggle="collapse" data-target="#vrStatusBody"
                        aria-expanded="false" aria-controls="vrStatusBody">
                    <span class="vxe-card-icon" aria-hidden="true"><i class="bi bi-toggle-on"></i></span>
                    <span class="vxe-card-text">
                        <span class="vxe-section-label">Status</span>
                        <span class="vxe-card-desc">Czy dostawca jest dostępny w&nbsp;codziennej pracy.</span>
                    </span>
                    <span class="vxe-card-aside">
                        <i class="bi bi-chevron-down vxe-card-chevron" aria-hidden="true"></i>
                    </span>
                </button>
            </h2>
            <p class="vxe-card-summary" id="vrStatusSummary" title="<?= $vrEsc($vrSumStatus[1]) ?>"><?= $vrSumStatus[0] ?></p>
            <div class="collapse" id="vrStatusBody">
              <div class="vxe-card-body">
                <fieldset class="vxe-fieldset vxe-status-row">
                    <legend class="sr-only">Status dostawcy</legend>
                    <div class="vxe-seg">
                        <label class="vxe-seg-item">
                            <input type="radio" name="isActive" value="1" <?= $vendorIsActive ? 'checked' : '' ?>>
                            <span class="vxe-seg-face vxe-seg-face--active">
                                <span class="vxe-seg-dot"></span>Aktywny
                            </span>
                        </label>
                        <label class="vxe-seg-item">
                            <input type="radio" name="isActive" value="0" <?= !$vendorIsActive ? 'checked' : '' ?>>
                            <span class="vxe-seg-face vxe-seg-face--inactive">
                                <span class="vxe-seg-dot"></span>Nieaktywny
                            </span>
                        </label>
                    </div>
                    <p class="vxe-help">
                        Nieaktywni dostawcy nie pojawiają się w&nbsp;domyślnym widoku listy
                        ani w&nbsp;wyszukiwarkach artykułów u&nbsp;dostawców.
                    </p>
                </fieldset>
              </div>
            </div>
        </section>

        <!-- ================================================================
             Karta 3: Preferowane szablony wiadomości
             Dostawca tylko WSKAZUJE szablon z globalnego katalogu
             (/admin/purchase/email-drafts) — treści nie edytuje się
             tutaj. Krok 4 kreatora wysyłki rozwiązuje ten FK i
             podstawia zmienne.

             Picker NIE stoi na „— brak —". Pokazuje szablon, który
             realnie zostanie użyty: preferencję dostawcy, a gdy
             jej nie ma — globalny domyślny dla danego typu
             dokumentu. Pozostawienie globalnego domyślnego w
             pickerze znaczy „dostawca podąża za domyślnym" (w bazie
             zostaje NULL), więc zmiana domyślnego w module
             szablonów obejmuje wtedy również tego dostawcę.

             Karta jest zwijana jak pozostałe, ale oba selecty leżą
             WEWNĄTRZ #vrEditForm — zwinięcie to tylko CSS, więc
             $form.serialize() nadal je widzi. Chip #vrDraftCount siedzi
             w nagłówku i jest widoczny w obu stanach.
             ================================================================ -->
        <section class="rx-panel vxe-panel vxe-card" id="vrDraftCard">
            <h2 class="vxe-card-toggle-h">
                <button type="button" class="vxe-card-head vxe-card-head--toggle collapsed"
                        data-toggle="collapse" data-target="#vrDraftBody"
                        aria-expanded="false" aria-controls="vrDraftBody">
                    <span class="vxe-card-icon" aria-hidden="true"><i class="bi bi-envelope-paper"></i></span>
                    <span class="vxe-card-text">
                        <span class="vxe-card-titlerow">
                            <span class="vxe-section-label">Preferowane szablony wiadomości</span>
                            <i class="bi bi-info-circle vxe-section-info"
                               data-toggle="tooltip"
                               data-placement="top"
                               title="Szablon używany w kroku 4 wysyłki zapytania. Szablon oznaczony gwiazdką ★ to globalny domyślny — dostawca podąża za nim, dopóki nie wybierzesz innego."></i>
                        </span>
                        <span class="vxe-card-desc">Jakiego szablonu użyje kreator wysyłki dla tego dostawcy.</span>
                    </span>
                    <span class="vxe-card-aside">
                        <span class="vxe-count-chip<?= $vrCustomDraftCount > 0 ? ' is-on' : '' ?>"
                              id="vrDraftCount"
                              title="Liczba typów dokumentów, dla których dostawca ma własny szablon (różny od globalnego domyślnego).">
                            <?php if ($vrCustomDraftCount === 0): ?>
                                domyślne
                            <?php elseif ($vrCustomDraftCount === 1): ?>
                                1 własny
                            <?php else: ?>
                                <?= (int)$vrCustomDraftCount ?> własne
                            <?php endif; ?>
                        </span>
                        <i class="bi bi-chevron-down vxe-card-chevron" aria-hidden="true"></i>
                    </span>
                </button>
            </h2>
            <p class="vxe-card-summary" id="vrDraftSummary" title="<?= $vrEsc($vrSumDraft[1]) ?>"><?= $vrSumDraft[0] ?></p>

            <div class="collapse" id="vrDraftBody">
              <div class="vxe-card-body">
                <p class="vxe-table-note">
                    Szablon oznaczony gwiazdką ★ to globalny domyślny — pozostawienie
                    go oznacza, że dostawca podąża za zmianami domyślnego.
                    Treść szablonów edytuje się w&nbsp;module
                    <a href="<?= 'http://' . BASEURL . '/admin/purchase/email-drafts' ?>">Szablony wiadomości</a>.
                </p>

                <?php
                // Pickery renderują się ZAWSZE — także przy pustym
                // katalogu. Pusty katalog sygnalizuje niewybieralna
                // opcja w środku, a nie osobny blok alertu: operator
                // widzi wtedy, gdzie ta wartość ma trafić, zamiast
                // patrzeć na dziurę w formularzu.
                //
                // $vrDraftPickers (z rozszerzonym stanem: options /
                // selectedId / defaultId) jest budowany wyżej, przy
                // wczytywaniu danych — tam, gdzie znamy już katalog
                // szablonów i globalne domyślne.
                ?>
                <div class="vxe-grid">
                    <?php foreach ($vrDraftPickers as $p):
                        $pOptions = $p['options'];
                        $pDefaultName = '';
                        foreach ($pOptions as $o) {
                            if ((int)$o['id'] === (int)$p['defaultId']) {
                                $pDefaultName = (string)$o['name'];
                                break;
                            }
                        }
                    ?>
                        <div class="vxe-field vxe-field--6">
                            <label class="vxe-label" for="<?= $p['id'] ?>">
                                Preferowany szablon dla <?= htmlspecialchars($p['main'], ENT_QUOTES) ?>
                                <span class="vxe-abbr">(<?= $p['abbr'] ?>)</span>
                            </label>
                            <div class="vxe-picker" id="<?= $p['cell'] ?>">
                                <select id="<?= $p['id'] ?>"
                                        name="<?= $p['name'] ?>"
                                        class="selectpicker"
                                        data-width="100%"
                                        data-container="#<?= $p['cell'] ?>"
                                        data-live-search="true"
                                        data-style="rx-picker-btn"
                                        data-default-id="<?= (int)$p['defaultId'] ?>"
                                        data-abbr="<?= $vrEsc($p['abbr']) ?>">
                                    <?php if ($pOptions === []): ?>
                                        <option value="0" selected disabled>
                                            — brak aktywnych szablonów dla <?= htmlspecialchars($p['abbr'], ENT_QUOTES) ?> —
                                        </option>
                                    <?php else: ?>
                                        <?php foreach ($pOptions as $o): ?>
                                            <option value="<?= (int)$o['id'] ?>"
                                                    <?= ((int)$o['id'] === (int)$p['selectedId']) ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($o['name'], ENT_QUOTES) ?><?= $o['isGlobalDefault'] ? ' ★' : '' ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <p class="vxe-help">
                                <?php if ($pOptions === []): ?>
                                    Dodaj szablon w&nbsp;module
                                    <a href="<?= 'http://' . BASEURL . '/admin/purchase/email-drafts/edit' ?>">Szablony wiadomości</a>,
                                    aby móc go tu wskazać.
                                <?php elseif ((int)$p['selectedId'] === (int)$p['defaultId']): ?>
                                    Podąża za globalnym domyślnym.
                                <?php else: ?>
                                    Własny wybór dostawcy — globalny domyślny to „<?= htmlspecialchars($pDefaultName, ENT_QUOTES) ?>”.
                                <?php endif; ?>
                            </p>
                        </div>
                    <?php endforeach; ?>
                </div>
              </div>
            </div>
        </section>

        <!-- ================================================================
             Karta 4: Więcej
             Rzadkie pola: lead time, adres sklepu, dane dodatkowe, komentarz.
             Zwinięcie to tylko CSS (Bootstrap collapse) — pola zostają w
             DOM i w #vrEditForm, więc serialize() wysyła je także z
             zamkniętej karty. Karta zawsze startuje zwinięta; to, co jest
             wypełnione, mówi linijka podsumowania i chip #vrMoreCount.
             ================================================================ -->
        <section class="rx-panel vxe-panel vxe-card vxe-card--more" id="vrMoreCard">
            <h2 class="vxe-card-toggle-h">
                <button type="button"
                        class="vxe-card-head vxe-card-head--toggle collapsed"
                        data-toggle="collapse" data-target="#vrMoreBody"
                        aria-expanded="false"
                        aria-controls="vrMoreBody">
                    <span class="vxe-card-icon" aria-hidden="true"><i class="bi bi-sliders"></i></span>
                    <span class="vxe-card-text">
                        <span class="vxe-section-label">Więcej</span>
                        <span class="vxe-card-desc">Czas dostawy, sklep internetowy, dane dodatkowe i&nbsp;komentarz.</span>
                    </span>
                    <span class="vxe-card-aside">
                        <span class="vxe-count-chip<?= $vrMoreFilled > 0 ? ' is-on' : '' ?>"
                              id="vrMoreCount"
                              data-total="<?= (int)$vrMoreTotal ?>"
                              title="Ile z&nbsp;czterech rzadkich pól ma już wartość.">
                            <?= $vrMoreFilled > 0 ? $vrMoreFilled . ' z ' . $vrMoreTotal . ' wypełnione' : 'puste' ?>
                        </span>
                        <i class="bi bi-chevron-down vxe-card-chevron" aria-hidden="true"></i>
                    </span>
                </button>
            </h2>
            <p class="vxe-card-summary" id="vrMoreSummary" title="<?= $vrEsc($vrSumMore[1]) ?>"><?= $vrSumMore[0] ?></p>
            <div class="collapse" id="vrMoreBody">
                <div class="vxe-card-body">
                    <div class="vxe-grid">

                        <div class="vxe-field vxe-field--4">
                            <label class="vxe-label" for="vr_edit_lead_time">Lead time (dni)</label>
                            <input type="number" id="vr_edit_lead_time" name="lead_time_days"
                                   class="form-control" min="0" step="1"
                                   value="<?= htmlspecialchars((string)$vendorLeadTimeDays, ENT_QUOTES) ?>">
                            <p class="vxe-help">Przewidywany czas dostawy w dniach roboczych.</p>
                        </div>

                        <div class="vxe-field vxe-field--8">
                            <label class="vxe-label" for="vr_edit_shop_url">Adres sklepu (URL)</label>
                            <div class="input-group">
                                <input type="url" id="vr_edit_shop_url" name="shop_url"
                                       class="form-control" maxlength="500"
                                       placeholder="https://www.sklep-dostawcy.pl"
                                       value="<?= htmlspecialchars($vendorShopUrl, ENT_QUOTES) ?>">
                                <?php if ($vendorShopUrl !== ''): ?>
                                    <div class="input-group-append">
                                        <a class="btn btn-sm rx-btn-quiet" target="_blank" rel="noopener"
                                           href="<?= htmlspecialchars($vendorShopUrl, ENT_QUOTES) ?>"
                                           title="Otwórz sklep w nowej karcie">
                                            <i class="bi bi-box-arrow-up-right"></i> Otwórz
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <p class="vxe-help">
                                Link do sklepu dostawcy. W&nbsp;koszyku (wiersz wariantu części)
                                przycisk „Sklep” otwiera go w&nbsp;nowej karcie.
                            </p>
                        </div>

                        <div class="vxe-field vxe-field--12">
                            <label class="vxe-label" for="vr_edit_additional_data">Dodatkowe dane</label>
                            <input type="text" id="vr_edit_additional_data" name="additional_data"
                                   class="form-control"
                                   value="<?= htmlspecialchars($vendorAdditionalData, ENT_QUOTES) ?>">
                        </div>

                        <div class="vxe-field vxe-field--12">
                            <label class="vxe-label" for="vr_edit_comment">Komentarz</label>
                            <textarea id="vr_edit_comment" name="comment" class="form-control" rows="3"
                                      placeholder="Opcjonalne uwagi wewnętrzne"><?= htmlspecialchars($vendorComment, ENT_QUOTES) ?></textarea>
                            <p class="vxe-help">
                                Uwagi wewnętrzne — widoczne też na liście dostawców, pod nazwą.
                            </p>
                        </div>

                    </div>
                </div>
            </div>
        </section>

        <!-- ===== Pasek zapisu ===== -->
        <div class="vxe-panel-foot">
            <span class="vxe-foot-hint">
                <?= $isEditMode
                    ? 'Zmiany zapisujesz przyciskiem po prawej.'
                    : 'Po zapisaniu otworzy się karta nowego dostawcy.' ?>
            </span>
            <div class="vxe-foot-actions">
                <a href="<?= htmlspecialchars($backUrl, ENT_QUOTES) ?>" class="btn rx-btn-quiet">
                    <i class="bi bi-arrow-left"></i> Wróć do listy
                </a>
                <button type="submit" class="btn btn-primary vxe-save-btn" id="vrSubmitBtn" disabled>
                    <?php if ($isEditMode): ?>
                        <i class="bi bi-check2-circle"></i> Zapisz zmiany
                    <?php else: ?>
                        <i class="bi bi-plus-circle"></i> Dodaj dostawcę
                    <?php endif; ?>
                </button>
            </div>
        </div>
    </form>

<?php endif; ?>

  </div><!-- /.vxe-column -->

<?php if ($isEditMode && !$notFound): ?>
    <!-- ================================================================
         Modal powiązań: osoby kontaktowe (z pełnym CRUD-em) + artykuły
         u dostawców (podgląd). Stoi w .rx-page, więc dziedziczy zmienne
         --rx-*; jest poza #vrEditForm, więc serialize() nie widzi
         pól osoby kontaktowej.
         ================================================================ -->
    <div class="modal fade vxe-modal" id="vrRelationsModal" tabindex="-1" role="dialog"
         aria-labelledby="vrRelationsTitle">
        <div class="modal-dialog modal-xl" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="vrRelationsTitle">
                        <i class="bi bi-diagram-3"></i> Powiązania dostawcy
                    </h5>
                    <div class="vxe-modal-head-right">
                        <span class="vxe-modal-chip">
                            <?= count($suppliers) ?>
                            <?= vr_count_label(count($suppliers), 'osoba', 'osoby', 'osób') ?>
                        </span>
                        <span class="vxe-modal-chip">
                            <?= (int)$vendorPartCount ?>
                            <?= vr_count_label((int)$vendorPartCount, 'artykuł', 'artykuły', 'artykułów') ?>
                        </span>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Zamknij">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                </div>

                <div class="modal-body">
                    <!-- Komunikaty wewnątrz modalu: błędy zapisu osoby
                         oraz potwierdzenie po powrocie z przeładowania
                         (?ok=…). Trafiają tutaj, bo modal po powrocie
                         otwiera się sam (#vrRelations), a zasłona zakryłaby
                         komunikat z góry strony. -->
                    <div id="vrModalAlertHost" class="rx-alerts">
                        <?php if ($vrOkMessage !== ''): ?>
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                <i class="bi bi-check-circle-fill"></i>
                                <?= htmlspecialchars($vrOkMessage, ENT_QUOTES) ?>
                                <button type="button" class="close" data-dismiss="alert" aria-label="Zamknij">
                                    <span aria-hidden="true">&times;</span>
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- ===== Osoby kontaktowe ===== -->
                    <section class="vxe-modal-block">
                        <div class="vxe-modal-block-head">
                            <h3 class="vxe-modal-block-title">
                                <i class="bi bi-person-lines-fill"></i> Osoby kontaktowe
                            </h3>
                            <span class="vxe-count-chip"><?= count($suppliers) ?></span>
                        </div>

                        <div class="vxe-toolbar-row">
                            <button type="button" id="vrSupplierAddBtn" class="btn btn-sm btn-primary">
                                <i class="bi bi-plus-circle"></i> Dodaj osobę kontaktową
                            </button>
                            <span class="vxe-help" style="margin:0;">
                                Osobny formularz — zapis dostawcy go nie obejmuje.
                            </span>
                        </div>

                        <!-- Potwierdzenie zmiany statusu osoby. Pasek w modalu,
                             zamiast drugiego modalu w modalu (Bootstrap 4
                             daje wtedy dwie zasłony i wygląda to zepsute). -->
                        <div class="vxe-confirm d-none" id="vrToggleConfirm" role="alert">
                            <input type="hidden" id="vrToggleSupplierId">
                            <input type="hidden" id="vrToggleSupplierIsActive">
                            <span class="vxe-confirm-text" id="vrToggleSupplierBody">
                                Czy na pewno chcesz zmienić status tej osoby kontaktowej?
                            </span>
                            <button type="button" id="vrCancelToggleSupplier" class="btn btn-sm rx-btn-quiet">
                                Anuluj
                            </button>
                            <button type="button" id="vrConfirmToggleSupplier" class="btn btn-sm btn-primary">
                                Potwierdź
                            </button>
                        </div>

                        <form id="vrSupplierForm" autocomplete="off" class="vxe-subform d-none">
                            <input type="hidden" name="id"        id="vrSupplierId"        value="">
                            <input type="hidden" name="vendor_id" id="vrSupplierVendorId" value="<?= (int)$vendor->id ?>">
                            <div class="vxe-subform-title" id="vrSupplierFormTitle">Dodaj osobę kontaktową</div>
                            <div class="vxe-grid">
                                <div class="vxe-field vxe-field--6">
                                    <label class="vxe-label" for="vr_supplier_name">
                                        Imię i nazwisko <span class="vxe-required">*</span>
                                    </label>
                                    <input type="text" id="vr_supplier_name" name="name"
                                           class="form-control" required maxlength="255">
                                </div>
                                <div class="vxe-field vxe-field--6">
                                    <label class="vxe-label" for="vr_supplier_job_title">Stanowisko</label>
                                    <input type="text" id="vr_supplier_job_title" name="job_title"
                                           class="form-control" maxlength="255">
                                </div>
                                <div class="vxe-field vxe-field--6">
                                    <label class="vxe-label" for="vr_supplier_phone">Telefon</label>
                                    <input type="text" id="vr_supplier_phone" name="phone"
                                           class="form-control" maxlength="64">
                                </div>
                                <div class="vxe-field vxe-field--6">
                                    <label class="vxe-label" for="vr_supplier_email">Email</label>
                                    <input type="email" id="vr_supplier_email" name="email"
                                           class="form-control" maxlength="255">
                                </div>
                                <div class="vxe-field vxe-field--12">
                                    <label class="vxe-label" for="vr_supplier_comment">Komentarz</label>
                                    <textarea id="vr_supplier_comment" name="comment" class="form-control" rows="2"></textarea>
                                </div>
                                <div class="vxe-field vxe-field--12">
                                    <fieldset class="vxe-fieldset">
                                        <legend class="vxe-label">Status</legend>
                                        <div class="vxe-seg">
                                            <label class="vxe-seg-item">
                                                <input type="radio" name="isActive" value="1" checked>
                                                <span class="vxe-seg-face vxe-seg-face--active">
                                                    <span class="vxe-seg-dot"></span>Aktywna
                                                </span>
                                            </label>
                                            <label class="vxe-seg-item">
                                                <input type="radio" name="isActive" value="0">
                                                <span class="vxe-seg-face vxe-seg-face--inactive">
                                                    <span class="vxe-seg-dot"></span>Wyłączona
                                                </span>
                                            </label>
                                        </div>
                                    </fieldset>
                                </div>
                            </div>
                            <div class="vxe-subform-actions">
                                <button type="button" id="vrSupplierCancelBtn" class="btn btn-sm rx-btn-quiet d-none">
                                    <i class="bi bi-x-circle"></i> Anuluj
                                </button>
                                <button type="submit" class="btn btn-sm btn-primary" id="vrSupplierSubmitBtn" disabled>
                                    <i class="bi bi-check2-circle"></i> Zapisz osobę
                                </button>
                            </div>
                        </form>

                        <?php if ($suppliers === []): ?>
                            <div class="rx-empty rx-empty--compact">
                                <div class="rx-empty-icon"><i class="bi bi-person-lines-fill"></i></div>
                                <div class="rx-empty-title">Brak osób kontaktowych</div>
                                <div class="rx-empty-text">
                                    Ten dostawca nie ma jeszcze przypisanych osób kontaktowych.
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table rx-table mb-0" id="vrSupplierTable">
                                    <thead>
                                        <tr>
                                            <th>Nazwa</th>
                                            <th>Stanowisko</th>
                                            <th>Telefon</th>
                                            <th>Email</th>
                                            <th style="min-width: 160px;">Komentarz</th>
                                            <th class="text-center" style="width: 110px;">Status</th>
                                            <th style="width: 100px;">Akcje</th>
                                        </tr>
                                    </thead>
                                    <tbody id="vrSupplierTBody">
                                    <?php foreach ($suppliers as $s):
                                        $isActive = $s->isActive === true || $s->isActive === 1 || $s->isActive === '1';
                                        $rowClass = $isActive ? 'vr-supplier-row--active' : 'vr-supplier-row--inactive';
                                        $statusBadge = $isActive
                                            ? '<span class="rx-badge rx-badge--green">Aktywna</span>'
                                            : '<span class="rx-badge rx-badge--red">Wyłączona</span>';

                                        // Encode the supplier as JSON for the JS
                                        // "Edytuj" handler to pick up without a
                                        // second round-trip.
                                        $supplierJson = htmlspecialchars(json_encode([
                                            'id'       => (int)$s->id,
                                            'name'     => $s->name,
                                            'jobTitle' => $s->jobTitle ?? '',
                                            'phone'    => $s->phone ?? '',
                                            'email'    => $s->email ?? '',
                                            'comment'  => $s->comment ?? '',
                                            'isActive' => $isActive,
                                        ], JSON_UNESCAPED_UNICODE), ENT_QUOTES);
                                    ?>
                                        <tr class="<?= $rowClass ?>" data-supplier-json="<?= $supplierJson ?>">
                                            <td><?= htmlspecialchars($s->name, ENT_QUOTES) ?></td>
                                            <td><?= htmlspecialchars($s->jobTitle ?? '', ENT_QUOTES) ?></td>
                                            <td><?= htmlspecialchars($s->phone ?? '', ENT_QUOTES) ?></td>
                                            <td><?= htmlspecialchars($s->email ?? '', ENT_QUOTES) ?></td>
                                            <td>
                                                <?php if (($s->comment ?? '') !== ''): ?>
                                                    <small class="vr-vp-comment" title="<?= htmlspecialchars($s->comment, ENT_QUOTES) ?>">
                                                        <i class="bi bi-journal-text"></i>
                                                        <?= htmlspecialchars($s->comment, ENT_QUOTES) ?>
                                                    </small>
                                                <?php else: ?>
                                                    <span class="text-muted">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center"><?= $statusBadge ?></td>
                                            <td>
                                                <div class="btn-group btn-group-sm" role="group">
                                                    <button type="button" class="btn rx-btn-quiet vr-supplier-edit-btn" title="Edytuj">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <button type="button" class="btn rx-btn-quiet vr-supplier-toggle-btn"
                                                            data-id="<?= (int)$s->id ?>"
                                                            data-is-active="<?= $isActive ? '1' : '0' ?>"
                                                            data-name="<?= htmlspecialchars($s->name, ENT_QUOTES) ?>"
                                                            title="<?= $isActive ? 'Wyłącz' : 'Włącz' ?>">
                                                        <i class="bi bi-<?= $isActive ? 'x-circle' : 'check-circle' ?>"
                                                           class="<?= $isActive ? 'text-danger' : 'text-success' ?>"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </section>

                    <!-- ===== Artykuły u dostawców =====
                         Lista jest rysowana przez JS z AJAX-u
                         (edit/vendor-parts-modal-list.php) — dostawca może
                         mieć tysiące artykułów, więc strona ładuje tylko
                         jedną stronę wyników. PHP zostawia skorupę tabeli
                         i nagłówek kolumn. -->
                    <section class="vxe-modal-block" id="vrVpBlock" data-vendor-id="<?= (int)$vendor->id ?>">
                        <div class="vxe-modal-block-head">
                            <h3 class="vxe-modal-block-title">
                                <i class="bi bi-box-seam"></i> Artykuły u dostawców
                            </h3>
                            <span class="vxe-count-chip" id="vrVpCountChip"
                                  data-vp-all="<?= (int)$vendorPartCount ?>"
                                  title="Wszystkie artykuły tego dostawcy"><?= (int)$vendorPartCount ?></span>
                        </div>
                        <p class="vxe-table-note">
                            Tylko podgląd — edycja artykułów odbywa się w&nbsp;module „Artykuły u&nbsp;dostawców”.
                        </p>

                        <!-- Filtry bloku: wyszukiwarka + dwa przełączniki. -->
                        <div class="rx-toolbar vxe-vp-toolbar" role="search">
                            <div class="rx-search">
                                <i class="bi bi-search" aria-hidden="true"></i>
                                <input type="search" id="vrVpSearch" class="form-control form-control-sm"
                                       placeholder="Szukaj: nazwa, numer artykułu, producent"
                                       title="Szuka w nazwie części, numerze artykułu u dostawcy, numerze producenta i nazwie producenta"
                                       aria-label="Szukaj artykułu" autocomplete="off">
                            </div>

                            <div class="vxe-vp-group">
                                <span class="vxe-vp-group-label" aria-hidden="true">Status</span>
                                <div class="rx-seg" role="group" aria-label="Status artykułu" id="vrVpSegStatus">
                                    <button type="button" data-vp-dim="status" data-vp-value="all" class="is-active" aria-pressed="true">Wszystkie</button>
                                    <button type="button" data-vp-dim="status" data-vp-value="active" aria-pressed="false">Aktywne</button>
                                    <button type="button" data-vp-dim="status" data-vp-value="inactive" aria-pressed="false">Nieaktywne</button>
                                </div>
                            </div>

                            <div class="vxe-vp-group">
                                <span class="vxe-vp-group-label" aria-hidden="true">Opakowanie</span>
                                <div class="rx-seg" role="group" aria-label="Opakowanie artykułu" id="vrVpSegPacks">
                                    <button type="button" data-vp-dim="packs" data-vp-value="all" class="is-active" aria-pressed="true">Wszystkie</button>
                                    <button type="button" data-vp-dim="packs" data-vp-value="with" aria-pressed="false">Z opakowaniem</button>
                                    <button type="button" data-vp-dim="packs" data-vp-value="without" aria-pressed="false">Bez opakowania</button>
                                </div>
                            </div>
                        </div>

                        <!-- Linijka wyników + pager. -->
                        <div class="rx-filterbar vxe-vp-filterbar">
                            <div class="vxe-vp-summary">
                                <span id="vrVpSummary" aria-live="polite">Wczytywanie artykułów…</span>
                                <button type="button" class="rx-link-btn d-none" id="vrVpClear"
                                        title="Wyczyść filtry listy artykułów">
                                    <i class="bi bi-x-circle"></i> Wyczyść filtry
                                </button>
                            </div>
                            <div id="vrVpPagerTop"></div>
                        </div>

                        <div id="vrVpEmpty" hidden></div>

                        <div class="table-responsive vxe-vp-table-wrap" id="vrVpTableWrap">
                            <table class="table rx-table mb-0" id="vrVpTable">
                                <thead>
                                    <tr>
                                        <th class="vr-vp-col-id text-center" style="width: 70px;">ID</th>
                                        <th>Artykuł</th>
                                        <th>Producent</th>
                                        <th>JM</th>
                                        <th>Opak.</th>
                                        <th style="min-width: 180px;">Komentarz</th>
                                    </tr>
                                </thead>
                                <tbody id="vrVpTBody"></tbody>
                            </table>
                        </div>
                    </section>
                </div>

                <div class="modal-footer">
                    <a href="<?= htmlspecialchars($backUrl, ENT_QUOTES) ?>" class="btn rx-btn-quiet">
                        <i class="bi bi-arrow-left"></i> Wróć do listy
                    </a>
                    <button type="button" class="btn btn-primary" data-dismiss="modal">Zamknij</button>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

</div>

<script src="<?= asset('public_html/components/Admin/Purchase/Vendors/edit/vendor-edit-view.js') ?>"></script>
