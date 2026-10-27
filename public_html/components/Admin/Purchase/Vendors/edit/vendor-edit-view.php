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
 *     (FK → email_draft.id; 0 = brak preferencji). Sam szablon
 *     edytuje się w osobnym module /admin/purchase/email-drafts —
 *     tutaj wskazuje się tylko, którego użyć.
 *
 * In EDIT mode the page also renders:
 *   - Inline supplier CRUD (form + list) — replaces the old
 *     "Szczegóły" modal. "Dodaj osobę kontaktową" + per-row
 *     "Edytuj" repurpose a single shared form.
 *   - Read-only list of vendor parts using this vendor (mirrors
 *     the producer edit page's read-only VPs).
 *
 * On a successful CREATE the JS redirects to ?id=N so the operator
 * lands on the edit page for the freshly-created vendor. On a
 * successful UPDATE the JS stays on the page and shows an inline
 * success alert. On validation errors the save endpoint returns
 * JSON and the JS shows an inline alert at the top of the page.
 *
 * Wygląd: ten sam język co lista dostawców (/admin/purchase/vendors) —
 * receipts-ui.css jako baza (tylko do odczytu) + vendor-edit-ui.css.
 * Formularz jest podzielony na nazwane sekcje w jednym panelu .rx-panel
 * (Dane podstawowe / Uwagi i status), a trzy sekcje wtórne (szablony
 * wiadomości, osoby kontaktowe, artykuły) są zwijanymi panelami.
 */
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Master\VendorRepository;
use Atte\Utils\Purchase\Master\VendorSupplierRepository;
use Atte\Utils\Purchase\Master\VendorPartRepository;
use Atte\Utils\Purchase\Master\CurrencyRepository;

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
$vendorPartCount        = 0;      // for the edit-mode hint
$vendorParts            = [];     // read-only VP list (active + inactive)
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

        // Read-only vendor-parts list (active + inactive). The repo's
        // LEFT JOINs fill in vendorName, producerName, partName, unitName.
        $vpRepo     = new VendorPartRepository($MsaDB);
        $vendorParts = $vpRepo->getByVendor($id, false);
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
$vendorPreferredCount = 0;
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
    $vendorPreferredCount = ($vendorPreferredRfqId > 0 ? 1 : 0)
                          + ($vendorPreferredPoId  > 0 ? 1 : 0);
}

$backUrl = 'http://' . BASEURL . '/admin/purchase/vendors';

// Format a float without trailing zeros (e.g. 10.000 -> "10").
// Mirrors fmtQty() in vendor-parts-view.js / producer-edit-view.php.
function vr_fmt_qty($n) {
    $f = is_numeric($n) ? (float)$n : null;
    if ($f === null) return htmlspecialchars((string)($n ?? '—'), ENT_QUOTES);
    return (float)number_format($f, 4, '.', '') == (float)(int)$f
        ? (string)(int)$f
        : rtrim(rtrim(number_format($f, 4, '.', ''), '0'), '.');
}

function vr_render_packs(array $packs): string {
    if ($packs === []) {
        return '<span class="text-muted">—</span>';
    }
    $badges = '';
    foreach ($packs as $p) {
        $badges .= '<span class="vr-pack-badge">' . htmlspecialchars(vr_fmt_qty($p), ENT_QUOTES) . '</span>';
    }
    return $badges;
}

?>
<link rel="stylesheet" href="<?= asset('public_html/components/purchases/receipts/receipts-ui.css') ?>">
<link rel="stylesheet" href="<?= asset('public_html/components/Admin/Purchase/Vendors/edit/vendor-edit-ui.css') ?>">

<div class="rx-page vxe-page" id="vendorEditView">

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
        <div class="vxe-head-links">
            <?php if ($isEditMode && !$notFound): ?>
                <span class="rx-count vxe-head-id" title="Identyfikator dostawcy">#<?= (int)$vendor->id ?></span>
            <?php endif; ?>
            <a href="<?= htmlspecialchars($backUrl, ENT_QUOTES) ?>" class="btn btn-sm rx-btn-quiet">
                <i class="bi bi-arrow-left"></i> Wróć do listy
            </a>
        </div>
    </header>

    <!-- Host na komunikaty AJAX (błąd zapisu, potwierdzenie). Pusty,
         dopiero JS wstawia tu alert — dlatego osobny, stały punkt
         zaczepienia zamiast dopisywania do kontenera strony. -->
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

        <!-- vxe-form-panel: panel z pickerami (waluta, szablony
             wiadomości) — musi mieć overflow: visible, bo menu
             bootstrap-select wychodzi poza panel. Patrz vendor-edit-ui.css. -->
        <section class="rx-panel vxe-panel vxe-form-panel">
            <div class="rx-panel-head">
                <h2 class="rx-panel-title"><i class="bi bi-pencil-square"></i> Dane dostawcy</h2>
                <span class="vxe-panel-hint">
                    Pola oznaczone <span class="vxe-required">*</span> są wymagane.
                </span>
            </div>

            <?php if ($isEditMode && !$notFound): ?>
                <p class="vxe-usage">
                    <i class="bi bi-info-circle"></i>
                    Ten dostawca ma przypisane <strong><?= (int)$vendorPartCount ?></strong>
                    artykuł<?= $vendorPartCount === 1 ? '' : 'ów' ?> u dostawców
                    i <strong><?= count($suppliers) ?></strong>
                    osób<?= count($suppliers) === 1 ? 'ę' : '' ?> kontaktow<?= count($suppliers) === 1 ? 'ą' : 'ych' ?>.
                </p>
            <?php endif; ?>

            <!-- ===== Dane podstawowe ===== -->
            <div class="vxe-section">
                <h3 class="vxe-section-label">Dane podstawowe</h3>
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
                        <label class="vxe-label" for="vr_edit_lead_time">Lead time (dni)</label>
                        <input type="number" id="vr_edit_lead_time" name="lead_time_days"
                               class="form-control" min="0" step="1"
                               value="<?= htmlspecialchars((string)$vendorLeadTimeDays, ENT_QUOTES) ?>">
                        <p class="vxe-help">Przewidywany czas dostawy w dniach roboczych.</p>
                    </div>

                    <div class="vxe-field vxe-field--12">
                        <label class="vxe-label" for="vr_edit_address">Adres</label>
                        <input type="text" id="vr_edit_address" name="address"
                               class="form-control"
                               value="<?= htmlspecialchars($vendorAddress, ENT_QUOTES) ?>">
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
                        <label class="vxe-label" for="vr_edit_additional_data">Dodatkowe dane</label>
                        <input type="text" id="vr_edit_additional_data" name="additional_data"
                               class="form-control"
                               value="<?= htmlspecialchars($vendorAdditionalData, ENT_QUOTES) ?>">
                    </div>

                </div>
            </div>

            <!-- ===== Uwagi i status ===== -->
            <div class="vxe-section">
                <h3 class="vxe-section-label">Uwagi i status</h3>
                <div class="vxe-grid">

                    <div class="vxe-field vxe-field--12">
                        <label class="vxe-label" for="vr_edit_comment">Komentarz</label>
                        <textarea id="vr_edit_comment" name="comment" class="form-control" rows="3"
                                  placeholder="Opcjonalne uwagi wewnętrzne"><?= htmlspecialchars($vendorComment, ENT_QUOTES) ?></textarea>
                        <p class="vxe-help">
                            Uwagi wewnętrzne — widoczne też na liście dostawców, pod nazwą.
                        </p>
                    </div>

                    <div class="vxe-field vxe-field--12">
                        <fieldset class="vxe-fieldset">
                            <legend class="vxe-label">Status</legend>
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
            </div>

            <!-- ================================================================
                 Preferowane szablony wiadomości
                 Dostawca tylko WSKAZUJE szablon z globalnego katalogu
                 (/admin/purchase/email-drafts) — treści nie edytuje się
                 tutaj. Krok 4 kreatora wysyłki rozwiązuje ten FK i
                 podstawia zmienne; przy braku wskazania albo gdy szablon
                 jest wyłączony, wraca do szablonu systemowego.

                 Sekcja jest ZNIEŻDŻONA wewnątrz #vrEditForm, więc
                 $form.serialize() widzi oba selecty i nie trzeba ich
                 doklejać ręcznie do POST-a.
                 ================================================================ -->
            <div class="vxe-collapse" id="vrDraftCard">
                <div class="vxe-collapse-head" data-toggle="collapse" data-target="#vrDraftCollapse"
                     aria-expanded="false" role="button" tabindex="0">
                    <span class="vxe-collapse-title">
                        <i class="bi bi-envelope-paper"></i> Preferowane szablony wiadomości
                    </span>
                    <i class="bi bi-info-circle vxe-collapse-info"
                       data-toggle="tooltip"
                       data-placement="top"
                       title="Szablon używany w kroku 4 wysyłki zapytania. Pozostaw puste, aby użyć wbudowanego domyślnego."></i>
                    <span class="vxe-collapse-count<?= $vendorPreferredCount > 0 ? ' is-on' : '' ?>" id="vrDraftCount">
                        <?= $vendorPreferredCount > 0 ? (int)$vendorPreferredCount . ' z 2' : 'domyślne' ?>
                    </span>
                    <i class="bi bi-chevron-down vxe-chevron"></i>
                </div>
                <div id="vrDraftCollapse" class="collapse">
                    <div class="vxe-collapse-body">

                        <p class="vxe-table-note">
                            Wybierz szablon, którego kreator wysyłki ma użyć dla tego dostawcy.
                            Brak wyboru oznacza szablon systemowy. Treść szablonów edytuje się w&nbsp;module
                            <a href="<?= 'http://' . BASEURL . '/admin/purchase/email-drafts' ?>">Szablony wiadomości</a>.
                        </p>

                        <?php
                        // Pickery renderują się ZAWSZE — także przy pustym
                        // katalogu. Pusty katalog sygnalizuje niewybieralna
                        // opcja w środku, a nie osobny blok alertu: operator
                        // widzi wtedy, gdzie ta wartość ma trafić, zamiast
                        // patrzeć na dziurę w formularzu.
                        $vrDraftPickers = [
                            [
                                'id'    => 'vr_pref_draft_rfq',
                                'cell'  => 'vrDraftRfqCell',
                                'name'  => 'preferred_email_draft_rfq_id',
                                'main'  => 'zapytań ofertowych',
                                'abbr'  => 'RFQ',
                                'value' => $vendorPreferredRfqId,
                            ],
                            [
                                'id'    => 'vr_pref_draft_po',
                                'cell'  => 'vrDraftPoCell',
                                'name'  => 'preferred_email_draft_po_id',
                                'main'  => 'zamówień',
                                'abbr'  => 'PO',
                                'value' => $vendorPreferredPoId,
                            ],
                        ];
                        ?>
                        <div class="vxe-grid">
                            <?php foreach ($vrDraftPickers as $p): ?>
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
                                                title="— brak —">
                                            <option value="0" <?= $p['value'] === 0 ? 'selected' : '' ?>>— brak —</option>
                                            <?php if ($emailDrafts === []): ?>
                                                <option value="0" disabled>
                                                    -- brak zdefiniowanych szablonów — utwórz w /admin/purchase/email-drafts --
                                                </option>
                                            <?php else: ?>
                                                <?php foreach ($emailDrafts as $d): ?>
                                                    <option value="<?= (int)$d->id ?>"
                                                            <?= ((int)$d->id === $p['value']) ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($d->name, ENT_QUOTES) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </select>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <?php if ($emailDrafts === []): ?>
                            <p class="vxe-help" style="margin-top:.75rem;">
                                <i class="bi bi-info-circle"></i>
                                Katalog szablonów jest pusty —
                                <a href="<?= 'http://' . BASEURL . '/admin/purchase/email-drafts/edit' ?>">dodaj pierwszy szablon</a>,
                                aby móc go tu wskazać.
                            </p>
                        <?php endif; ?>

                    </div>
                </div>
            </div>

            <!-- ===== Stopka: zapis zmian ===== -->
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
        </section>
    </form>

    <?php if ($isEditMode): ?>
        <!-- ================================================================
             Inline supplier CRUD
             A single shared form handles both "add new" and "edit
             existing". Clicking "Edytuj" on a row populates the form and
             highlights the row; "Anuluj" clears it. Saves via the existing
             supplier-add.php / supplier-update.php endpoints — no new
             backend required.

             UWAGA: ten formularz jest OSOBNYM formularzem (#vrSupplierForm)
             i NIE może leżeć wewnątrz #vrEditForm — inaczej serialize()
             wysłałby pola osoby kontaktowej razem z polami dostawcy.
             ================================================================ -->
        <section class="rx-panel vxe-panel" id="vrSupplierCard">
            <div class="vxe-collapse-head" data-toggle="collapse" data-target="#vrSupplierCollapse"
                 aria-expanded="false" role="button" tabindex="0">
                <span class="vxe-collapse-title">
                    <i class="bi bi-person-lines-fill"></i> Osoby kontaktowe
                </span>
                <span class="vxe-collapse-count"><?= count($suppliers) ?></span>
                <i class="bi bi-chevron-down vxe-chevron"></i>
            </div>
            <div id="vrSupplierCollapse" class="collapse">
                <div class="vxe-collapse-body">

                    <div class="vxe-toolbar-row">
                        <button type="button" id="vrSupplierAddBtn" class="btn btn-sm btn-primary">
                            <i class="bi bi-plus-circle"></i> Dodaj osobę kontaktową
                        </button>
                        <span class="vxe-help" style="margin:0;">
                            Dane osób kontaktowych są osobnym formularzem — zapis dostawcy ich nie obejmuje.
                        </span>
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

                </div>
            </div>
        </section>

        <!-- ================================================================
             Read-only list of vendor parts using this vendor. Same shape
             as the producer edit page's read-only VPs. Collapsed by
             default — operator is here to manage the vendor record, not
             the vendor-part catalog (that's a separate module).
             ================================================================ -->
        <section class="rx-panel vxe-panel" id="vrVpCard">
            <div class="vxe-collapse-head" data-toggle="collapse" data-target="#vrVpCollapse"
                 aria-expanded="false" role="button" tabindex="0">
                <span class="vxe-collapse-title">
                    <i class="bi bi-box-seam"></i> Artykuły u dostawców
                </span>
                <span class="vxe-collapse-count"><?= (int)$vendorPartCount ?></span>
                <i class="bi bi-chevron-down vxe-chevron"></i>
            </div>
            <div id="vrVpCollapse" class="collapse">
                <div class="vxe-collapse-body">
                    <p class="vxe-table-note">
                        Tylko podgląd — edycja artykułów odbywa się w&nbsp;module „Artykuły u&nbsp;dostawców”.
                    </p>

                    <?php if ($vendorParts === []): ?>
                        <div class="rx-empty rx-empty--compact">
                            <div class="rx-empty-icon"><i class="bi bi-box-seam"></i></div>
                            <div class="rx-empty-title">Brak artykułów</div>
                            <div class="rx-empty-text">
                                Ten dostawca nie ma jeszcze przypisanych artykułów.
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
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
                                <tbody>
                                <?php foreach ($vendorParts as $vp):
                                    $isActive = $vp->isActive === true || $vp->isActive === 1 || $vp->isActive === '1';
                                    $rowClass = $isActive ? 'vr-vp-row--active' : 'vr-vp-row--inactive';

                                    $artykulCell = htmlspecialchars($vp->partName ?? '', ENT_QUOTES);
                                    $vn = $vp->vendorPartNo ?? '';
                                    $pn = $vp->producerPartNo ?? '';
                                    if ($vn !== '' && $pn !== '' && $vn === $pn) {
                                        $artykulCell .= '<span class="vr-vp-sub">Nr dost./prod.: <span class="vr-vp-code">'
                                                      . htmlspecialchars($vn, ENT_QUOTES) . '</span></span>';
                                    } else {
                                        if ($vn !== '') {
                                            $artykulCell .= '<span class="vr-vp-sub">Nr dost.: <span class="vr-vp-code">'
                                                          . htmlspecialchars($vn, ENT_QUOTES) . '</span></span>';
                                        }
                                        if ($pn !== '') {
                                            $artykulCell .= '<span class="vr-vp-sub">Nr prod.: <span class="vr-vp-code">'
                                                          . htmlspecialchars($pn, ENT_QUOTES) . '</span></span>';
                                        }
                                    }

                                    $COMMENT_TRUNCATE_AT = 200;
                                    $rawCmt = trim((string)($vp->comment ?? ''));
                                    if ($rawCmt === '') {
                                        $komentarzCell = '<span class="text-muted">—</span>';
                                    } elseif (mb_strlen($rawCmt) > $COMMENT_TRUNCATE_AT) {
                                        $visible = htmlspecialchars(mb_substr($rawCmt, 0, $COMMENT_TRUNCATE_AT), ENT_QUOTES) . '…';
                                        $komentarzCell = '<small class="vr-vp-comment" title="'
                                                       . htmlspecialchars($rawCmt, ENT_QUOTES) . '">'
                                                       . '<i class="bi bi-journal-text"></i> ' . $visible . '</small>';
                                    } else {
                                        $komentarzCell = '<small class="vr-vp-comment">'
                                                       . '<i class="bi bi-journal-text"></i> '
                                                       . htmlspecialchars($rawCmt, ENT_QUOTES) . '</small>';
                                    }
                                ?>
                                    <tr class="<?= $rowClass ?>">
                                        <td class="vr-vp-col-id text-center"><?= (int)$vp->id ?></td>
                                        <td><?= $artykulCell ?></td>
                                        <td><?= htmlspecialchars($vp->producerName ?? '—', ENT_QUOTES) ?></td>
                                        <td><?= htmlspecialchars($vp->unitName ?? '—', ENT_QUOTES) ?></td>
                                        <td><?= vr_render_packs($vp->packQuantities) ?></td>
                                        <td><?= $komentarzCell ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <!-- ================================================================
             Toggle supplier confirm modal (rendered once, embedded in the
             page so the supplier-list JS can show it without a second
             include). Mirrors the legacy inline toggle UX (button + tiny
             confirm modal) — keeps the "is this destructive?" friction
             intentional.
             ================================================================ -->
        <div class="modal fade" id="vrToggleSupplierModal" tabindex="-1" role="dialog">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <input type="hidden" id="vrToggleSupplierId">
                    <input type="hidden" id="vrToggleSupplierIsActive">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-exclamation-triangle"></i> Potwierdź</h5>
                        <button type="button" class="close" data-dismiss="modal"><span aria-hidden="true">&times;</span></button>
                    </div>
                    <div class="modal-body" id="vrToggleSupplierBody">
                        Czy na pewno chcesz zmienić status tej osoby kontaktowej?
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Anuluj</button>
                        <button type="button" id="vrConfirmToggleSupplier" class="btn btn-primary">Tak</button>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
    <?php endif; ?>

</div>

<script src="<?= asset('public_html/components/Admin/Purchase/Vendors/edit/vendor-edit-view.js') ?>"></script>
