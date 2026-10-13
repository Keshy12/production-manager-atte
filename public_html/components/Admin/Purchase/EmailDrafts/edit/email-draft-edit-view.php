<?php
/**
 * Edycja / tworzenie pojedynczego szablonu wiadomości e-mail.
 *
 * Wpięte pod /admin/purchase/email-drafts/edit?id=N (mirror
 * vendor-edit / producer-edit). Renderuje formularz na GET, a POST
 * obsługuje email-draft-edit-save.php.
 *
 * Tryby:
 *   - `?id=N` (N > 0) → EDYCJA istniejącego szablonu
 *   - brak / id <= 0  → TWORZENIE nowego (pusty formularz)
 *
 * Pola (mirror tabeli `email_draft`):
 *   - name (wymagane, 255), subject (wymagane, 255), body (wymagane),
 *     applies_to ('rfq' | 'po' | 'both', radio), is_active (radio).
 *
 * Szablony są GLOBALNE — nie należą do żadnego dostawcy. Dostawca
 * wskazuje swój preferowany szablon przez FK na /admin/purchase/vendors/edit.
 * Katalog zmiennych ($edVarCatalog) i renderer „pigułek”
 * (ed_render_chip_group) mieszkały wcześniej w vendor-edit-view.php —
 * przeniesione tutaj razem z całym edytorem.
 *
 * Po udanym CREATE JS przenosi na ?id=N, po UPDATE zostaje na stronie
 * i pokazuje alert sukcesu.
 */
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Master\EmailDraftRepository;

if (!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    header("Location: http://" . BASEURL . "/");
    exit();
}

$id         = (int)($_GET['id'] ?? 0);
$isEditMode = $id > 0;
$draft      = null;

$edName     = '';
$edSubject  = '';
$edBody     = '';
$edIsActive = true;   // domyślnie Aktywny (istotne tylko w trybie tworzenia)
$edCreatedAt = '';
$edUpdatedAt = '';

/** Dozwolone wartości `email_draft.applies_to` — mirror walidacji w save. */
$edAppliesToValues = ['rfq', 'po', 'both'];
// Nowy szablon startuje na „Obie": bez jawnej decyzji operatora
// szablon ma być widoczny wszędzie, a nie zniknąć z połowy wysyłek.
$edAppliesTo = 'both';

if ($isEditMode) {
    $MsaDB  = MsaDB::getInstance();
    $edRepo = new EmailDraftRepository($MsaDB);
    $draft  = $edRepo->getById($id);
    if ($draft === null) {
        echo '<div class="container-fluid w-75 mt-3">'
           .     '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> '
           .         'Szablon #' . htmlspecialchars((string)$id) . ' nie istnieje.'
           .     '</div>'
           . '</div>';
        return;
    }
    $edName      = $draft->name;
    $edSubject   = $draft->subject;
    $edBody      = $draft->body;
    $edIsActive  = $draft->isActive;
    $edCreatedAt = $draft->createdAt;
    $edUpdatedAt = $draft->updatedAt;
    // Wartość spoza enuma (ręczna edycja w bazie) nie może zostawić
    // grupy radio bez zaznaczenia — wtedy „Obie".
    $edAppliesTo = in_array($draft->appliesTo, $edAppliesToValues, true)
        ? $draft->appliesTo
        : 'both';
}

$backUrl = 'http://' . BASEURL . '/admin/purchase/email-drafts';

/**
 * Katalog zmiennych szablonu wiadomości, pogrupowany kategoriami.
 *
 * Składnia w szablonie: {nazwa} — pojedyncze klamry, wyłącznie małe
 * litery, bez spacji w środku. Nieznana zmienna zostaje w treści jako
 * literał (operator zobaczy literówkę w mailu zamiast cichej pustki).
 *
 * Wszystkie klucze muszą mieć odpowiednik w buildTemplateVars() w
 * public_html/components/Admin/Purchase/Documents/documents-send.js —
 * to jest jedyne miejsce, w którym te nazwy są podstawiane naprawdę.
 * {vendor_address} celowo NIE ma tu wpisu: loader kroku 4 nie zwraca
 * już adresu dostawcy, więc chip oferowałby token, który renderowałby
 * się w mailu dosłownie.
 */
$edVarCatalog = [
    'Dostawca' => [
        'vendor_name'          => 'Nazwa dostawcy',
        'vendor_contact_name'  => 'Imię i nazwisko osoby kontaktowej',
        'vendor_contact_email' => 'E-mail osoby kontaktowej',
    ],
    'Dokument' => [
        'document_type'    => 'Typ dokumentu: zapytanie albo zamówienie',
        'document_number'  => 'Numer dokumentu',
        'vendor_document_number' => 'Numer dokumentu u dostawcy — gdy brak, wstawiamy nasz numer',
        'document_comment' => 'Komentarz z nagłówka dokumentu',
    ],
    // Wypełniane tylko przy PO powstałym z konwersji zapytania. Przy
    // PO kupionym wprost i przy samym RFQ renderują się puste — brak
    // powiązanego zapytania, więc nie ma czego podstawić.
    'Zapytanie powiązane' => [
        'rfq_number'                => 'Numer powiązanego zapytania (RFQ) — tylko przy konwersji na zamówienie',
        'rfq_vendor_document_number' => 'Numer powiązanego zapytania u dostawcy — tylko przy konwersji na zamówienie',
    ],
    'Daty' => [
        'expected_reply_date'    => 'Oczekiwany termin odpowiedzi (zapytanie)',
        'expected_delivery_date' => 'Oczekiwany termin dostawy (zamówienie)',
        'created_at'             => 'Data utworzenia dokumentu',
    ],
    'Pozycje' => [
        'items'      => 'Lista pozycji — blok wieloliniowy',
        'item_count' => 'Liczba pozycji',
    ],
    'Meta' => [
        'created_by_name' => 'Osoba, która utworzyła dokument',
    ],
];

/** Podzbiór sensowny w jednoliniowym temacie wiadomości. */
$edSubjectVars = [
    'vendor_name'            => 'Nazwa dostawcy',
    'document_number'        => 'Numer dokumentu',
    'vendor_document_number' => 'Numer dokumentu u dostawcy',
    'rfq_number'             => 'Numer powiązanego zapytania',
    'document_type'          => 'Typ dokumentu: zapytanie albo zamówienie',
    'expected_reply_date'    => 'Oczekiwany termin odpowiedzi (zapytanie)',
    'expected_delivery_date' => 'Oczekiwany termin dostawy (zamówienie)',
];

/**
 * Renderuje jedną kategorię „pigułek" ze zmiennymi. Klik wstawia
 * token w miejscu kursora w polu $targetSel (obsługa w
 * email-draft-edit-view.js).
 */
function ed_render_chip_group(string $label, array $vars, string $targetSel): string {
    $chips = '';
    foreach ($vars as $token => $desc) {
        $tokenAttr = htmlspecialchars('{' . $token . '}', ENT_QUOTES);
        $descAttr  = htmlspecialchars($desc, ENT_QUOTES);
        $chips .= '<button type="button" class="btn btn-outline-secondary btn-sm rounded-pill ed-chip"'
                . ' data-var="' . $tokenAttr . '"'
                . ' data-target="' . htmlspecialchars($targetSel, ENT_QUOTES) . '"'
                . ' title="' . $descAttr . ' — kliknij, aby wstawić"'
                . ' aria-label="Wstaw zmienną ' . htmlspecialchars($token, ENT_QUOTES)
                . ': ' . $descAttr . '">'
                . '<span class="ed-chip-token">'
                . htmlspecialchars('{' . $token . '}', ENT_QUOTES)
                . '</span></button>';
    }
    return '<div class="ed-chip-group">'
         . '<small class="ed-chip-group-label text-muted text-uppercase" style="letter-spacing:.05em">'
         . htmlspecialchars($label, ENT_QUOTES) . '</small>'
         . '<span class="ed-chip-group-items">' . $chips . '</span>'
         . '</div>';
}

$edBodyLen = mb_strlen($edBody);
?>
<style>
    /* Karta formularza w tym samym języku wizualnym co vp-edit-card /
       pr-edit-card / vr-edit-card. */
    .ed-edit-card .card-header {
        background-color: #cfe2ff;
    }

    /* ---- Panel „pigułek" ze zmiennymi --------------------------- */
    /* Zwijalny — po kilku szablonach operator zna tokeny na pamięć
       i chce mieć puste pole nad textareą. Domyślnie rozwinięty. */
    #edVarsPanel {
        border: 1px solid #e3e6ea;
        border-radius: 0.25rem;
        background-color: #fcfcfd;
        margin-bottom: 0.5rem;
    }
    .ed-vars-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0.3rem 0.6rem;
        cursor: pointer;
        -webkit-user-select: none;
        user-select: none;
        color: #6c757d;
        transition: color 0.15s ease, background-color 0.15s ease;
    }
    .ed-vars-header:hover {
        color: #495057;
        background-color: #f4f6f8;
    }
    .ed-vars-header .bi-chevron-down,
    .ed-vars-header .bi-chevron-up {
        font-size: 0.7rem;
    }
    .ed-vars-body {
        padding: 0.2rem 0.6rem 0.45rem;
        border-top: 1px solid #edeff2;
    }
    /* Kategorie w osobnych wierszach, żeby tokeny dało się przeczytać,
       a nie tylko przeskanować. */
    .ed-chip-row {
        margin-bottom: 0.4rem;
    }
    .ed-chip-group {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 0.25rem 0.4rem;
        padding: 0.3rem 0;
    }
    .ed-chip-group + .ed-chip-group {
        border-top: 1px dashed #e9ecef;
    }
    .ed-chip-group-label {
        flex: 0 0 5.5rem;
        font-size: 0.68rem;
        font-weight: 600;
        line-height: 1.2;
    }
    .ed-chip-group-items {
        display: flex;
        flex-wrap: wrap;
        gap: 0.25rem 0.3rem;
        min-width: 0;
    }
    /* Pigułka: Bootstrapowy btn-outline-secondary btn-sm rounded-pill,
       dociśnięty do rozmiaru tokenu — ma czytać się jak etykieta, nie
       jak przycisk akcji. */
    .ed-chip.btn {
        padding: 0.05rem 0.55rem;
        line-height: 1.55;
        border-color: #d3d8de;
        transition: transform 0.12s ease, border-color 0.15s ease,
                    background-color 0.15s ease, color 0.15s ease;
    }
    .ed-chip .ed-chip-token {
        font-family: SFMono-Regular, Consolas, "Liberation Mono", Menlo, monospace;
        font-size: 0.74rem;
    }
    .ed-chip.btn:hover,
    .ed-chip.btn:focus {
        background-color: #e7f1ff;
        border-color: #9ec5fe;
        color: #0b5ed7;
    }
    .ed-chip.btn:active {
        transform: scale(0.94);
    }

    /* ---- Typ dokumentu (segmentowany przełącznik) --------------- */
    /* Trzy wzajemnie wykluczające się wartości czytają się lepiej jako
       jeden pasek niż jako trzy luźne kropki radio. Ikona niesie ten
       sam komunikat co etykieta — „koszyk" = zamówienie, „znak
       zapytania" = zapytanie ofertowe. */
    .ed-applies-to .btn {
        padding: 0.2rem 0.9rem;
        font-size: 0.82rem;
        font-weight: 600;
        letter-spacing: 0.02em;
    }
    .ed-applies-to .btn .bi {
        margin-right: 0.2rem;
    }
    /* Bootstrap chowa natywny input w .btn-group-toggle (clip do zera),
       więc pierścień fokusu musi wisieć na etykiecie. .focus dokłada
       Bootstrap, :focus-within łapie przeglądarki bez jego JS. */
    .ed-applies-to .btn.focus,
    .ed-applies-to .btn:focus-within {
        z-index: 2;
        box-shadow: 0 0 0 0.18rem rgba(13, 110, 253, 0.25);
    }

    /* ---- Domyślny globalnie (checkboxy) ------------------------- */
    /* Zaznaczony checkbox Bootstrapa to mały niebieski kwadrat — łatwo
       go przeoczyć. Etykieta dostaje więc pogrubienie i niebieski
       kolor, w tym samym tonie co zaznaczony segment „Typ dokumentu”. */
    .ed-default-group .custom-control-input:checked ~ .custom-control-label {
        font-weight: 600;
        color: #0b5ed7;
    }
    .ed-default-group .custom-control-input:checked ~ .custom-control-label .bi {
        color: #0b5ed7 !important;
    }

    /* Pola szablonu monospace — operator liczy znaki i pilnuje klamer. */
    .ed-template-field {
        font-family: SFMono-Regular, Consolas, "Liberation Mono", Menlo, monospace;
        font-size: 0.85rem;
    }
    .ed-template-counter {
        display: block;
        text-align: right;
        font-size: 0.72rem;
        font-variant-numeric: tabular-nums;
        color: #adb5bd;
        margin-top: 0.15rem;
        transition: color 0.15s ease;
    }
    .ed-template-counter.is-over {
        color: #dc3545;
        font-weight: 600;
    }

    /* ---- Podgląd ------------------------------------------------ */
    /* Ma wyglądać jak szkic maila, nie jak kolejne pole formularza:
       cienki pasek u góry + nagłówek + bryła treści. */
    .ed-template-preview {
        border: 1px solid #dee2e6;
        border-radius: 0.25rem;
        background-color: #fff;
        overflow: hidden;
        box-shadow: 0 1px 2px rgba(16, 24, 40, 0.04);
    }
    .ed-template-preview-stripe {
        height: 3px;
        background: linear-gradient(90deg, #0d6efd 0%, #6ea8fe 55%, #cfe2ff 100%);
    }
    .ed-template-preview-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.5rem;
        padding: 0.4rem 0.75rem;
        border-bottom: 1px solid #edeff2;
        background-color: #fbfbfc;
        font-size: 0.72rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #6c757d;
    }
    .ed-template-preview-subject {
        padding: 0.5rem 0.75rem;
        border-bottom: 1px solid #edeff2;
    }
    .ed-template-preview-key {
        display: block;
        font-size: 0.68rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #adb5bd;
    }
    #edPreviewSubject {
        font-weight: 600;
        word-break: break-word;
    }
    #edPreviewSubject:empty::before,
    #edPreviewBody:empty::before {
        content: "— pusto —";
        color: #ced4da;
        font-weight: 400;
    }
    .ed-template-preview-body {
        margin: 0;
        padding: 0.75rem;
        background: transparent;
        border: 0;
        font-family: SFMono-Regular, Consolas, "Liberation Mono", Menlo, monospace;
        font-size: 0.8rem;
        line-height: 1.6;
        color: #212529;
        white-space: pre-wrap;
        word-break: break-word;
    }

    /* Metryczka „utworzono / zmieniono” w stopce karty. */
    .ed-meta {
        font-variant-numeric: tabular-nums;
    }

    /* ---- Sygnał niezapisanych zmian ----------------------------- */
    /* Dwa razy pulsuje przy pierwszej edycji, potem zostaje sam
       delikatny pierścień — wystarczy, żeby przyciągnąć wzrok raz. */
    @keyframes edDirtyPulse {
        0%   { box-shadow: 0 0 0 0    rgba(13, 110, 253, 0.45); }
        70%  { box-shadow: 0 0 0 0.4rem rgba(13, 110, 253, 0); }
        100% { box-shadow: 0 0 0 0    rgba(13, 110, 253, 0); }
    }
    #edSubmitBtn.ed-dirty {
        box-shadow: 0 0 0 0.18rem rgba(13, 110, 253, 0.25);
        animation: edDirtyPulse 1.1s ease-out 2;
    }
    @media (prefers-reduced-motion: reduce) {
        #edSubmitBtn.ed-dirty { animation: none; }
        .ed-chip.btn:active   { transform: none; }
    }
    #edDirtyHint {
        font-size: 0.75rem;
    }
</style>

<div class="container-fluid w-75 mt-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0">
            <?php if ($isEditMode): ?>
                <i class="bi bi-pencil-square"></i>
                Edytuj szablon
                <span class="text-muted">#<?= (int)$draft->id ?></span>
            <?php else: ?>
                <i class="bi bi-plus-circle"></i>
                Nowy szablon
            <?php endif; ?>
        </h4>
        <a href="<?= htmlspecialchars($backUrl, ENT_QUOTES) ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> Wróć do listy
        </a>
    </div>

    <?php // AJAX: błędy wracają jako JSON, a JS pokazuje alert
          // na górze strony. Flash z sesji nie jest potrzebny. ?>

    <form method="post" id="edEditForm" autocomplete="off">
        <input type="hidden" name="id" value="<?= $isEditMode ? (int)$draft->id : 0 ?>">

        <div class="card ed-edit-card">
            <div class="card-header">
                <h6 class="mb-0">
                    <i class="bi bi-envelope-paper"></i>
                    Treść szablonu
                </h6>
            </div>
            <div class="card-body">

                <p class="text-muted small">
                    Szablonu używa krok „Wiadomość do dostawcy” kreatora wysyłki
                    (<code>/admin/purchase/documents/send</code>) — szablon trafia do
                    pickera przy każdej wysyłce pasującego typu, a&nbsp;u&nbsp;dostawców,
                    którzy wskazali go jako preferowany, jest od&nbsp;razu wybrany.
                    Zmienne zapisuje się
                    w&nbsp;klamrach, wyłącznie małymi literami i&nbsp;bez spacji, np.
                    <code>{vendor_name}</code>. Literówka w&nbsp;nazwie zostanie
                    w&nbsp;treści dosłownie — <code>{vandor_name}</code> trafi do maila
                    tak, jak tu widać.
                </p>

                <!-- ---- Nazwa ---- -->
                <div class="form-group">
                    <label for="ed_name">Nazwa szablonu: <span class="text-danger">*</span></label>
                    <input type="text" id="ed_name" name="name"
                           class="form-control" required maxlength="255"
                           placeholder="np. Zapytanie ofertowe — standardowe"
                           value="<?= htmlspecialchars($edName, ENT_QUOTES) ?>">
                    <small class="form-text text-muted">
                        Widoczna tylko wewnętrznie — na liście szablonów i&nbsp;w&nbsp;pickerach
                        na karcie dostawcy. Nie trafia do wiadomości.
                    </small>
                </div>

                <!-- ---- Typ dokumentu ---- -->
                <?php // Klasyfikacja szablonu stoi tuż przy nazwie: obie
                      // odpowiadają na pytanie „co to za szablon", zanim
                      // operator zejdzie do pisania tematu i treści. ?>
                <div class="form-group">
                    <label class="d-block mb-1" id="ed_applies_to_label">Typ dokumentu:</label>
                    <div class="btn-group btn-group-toggle ed-applies-to"
                         data-toggle="buttons"
                         role="group"
                         aria-labelledby="ed_applies_to_label">
                        <label class="btn btn-outline-primary<?= $edAppliesTo === 'rfq' ? ' active' : '' ?>">
                            <input type="radio" name="applies_to" value="rfq"
                                   autocomplete="off"<?= $edAppliesTo === 'rfq' ? ' checked' : '' ?>>
                            <i class="bi bi-patch-question" aria-hidden="true"></i> RFQ
                        </label>
                        <label class="btn btn-outline-primary<?= $edAppliesTo === 'po' ? ' active' : '' ?>">
                            <input type="radio" name="applies_to" value="po"
                                   autocomplete="off"<?= $edAppliesTo === 'po' ? ' checked' : '' ?>>
                            <i class="bi bi-cart-check" aria-hidden="true"></i> PO
                        </label>
                        <label class="btn btn-outline-primary<?= $edAppliesTo === 'both' ? ' active' : '' ?>">
                            <input type="radio" name="applies_to" value="both"
                                   autocomplete="off"<?= $edAppliesTo === 'both' ? ' checked' : '' ?>>
                            <i class="bi bi-collection" aria-hidden="true"></i> Obie
                        </label>
                    </div>
                    <small class="form-text text-muted">
                        Szablon pojawi się w&nbsp;kroku 4 wysyłki tylko dla wybranych typów
                        dokumentów. <strong>RFQ</strong> — zapytania ofertowe,
                        <strong>PO</strong> — zamówienia, <strong>Obie</strong> — jedno
                        i&nbsp;drugie.
                    </small>
                </div>

                <!-- ---- Temat ---- -->
                <div class="form-group">
                    <label for="ed_subject">Temat: <span class="text-danger">*</span></label>
                    <div class="ed-chip-row">
                        <?= ed_render_chip_group('Zmienne', $edSubjectVars, '#ed_subject') ?>
                    </div>
                    <input type="text"
                           id="ed_subject"
                           name="subject"
                           class="form-control ed-template-field"
                           required
                           maxlength="255"
                           placeholder="np. {document_type} {document_number} — {vendor_name}"
                           value="<?= htmlspecialchars($edSubject, ENT_QUOTES) ?>">
                    <small class="form-text text-muted">
                        Jedna linia, do 255 znaków. Bez znaków &lt; i&nbsp;&gt;.
                    </small>
                </div>

                <!-- ---- Treść ---- -->
                <div class="form-group">
                    <label for="ed_body">Treść: <span class="text-danger">*</span></label>

                    <?php // Panel zmiennych: zwijalny, ale domyślnie otwarty —
                          // tokeny muszą być widoczne przy pierwszym kontakcie,
                          // a operator z wprawą może je schować. ?>
                    <div id="edVarsPanel">
                        <div class="ed-vars-header"
                             data-toggle="collapse"
                             data-target="#edVarsCollapse"
                             role="button"
                             aria-expanded="true"
                             aria-controls="edVarsCollapse">
                            <small class="text-uppercase font-weight-bold" style="letter-spacing:.05em">
                                <i class="bi bi-braces"></i> Dostępne zmienne
                            </small>
                            <i class="bi bi-chevron-up"></i>
                        </div>
                        <div class="collapse show ed-vars-body" id="edVarsCollapse">
                            <div class="ed-chip-row mb-0">
                                <?php foreach ($edVarCatalog as $edGroupLabel => $edGroupVars): ?>
                                    <?= ed_render_chip_group($edGroupLabel, $edGroupVars, '#ed_body') ?>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <textarea id="ed_body"
                              name="body"
                              class="form-control ed-template-field"
                              rows="10"
                              required
                              placeholder="Szanowni Państwo,&#10;&#10;zwracamy się z prośbą o ofertę na następujące części:&#10;&#10;{items}&#10;&#10;Z poważaniem,&#10;{created_by_name}"><?= htmlspecialchars($edBody, ENT_QUOTES) ?></textarea>
                    <span class="ed-template-counter" id="edTemplateCounter"><?= (int)$edBodyLen ?> znaków</span>
                    <small class="form-text text-muted mt-0">
                        <code>{items}</code> wstawia listę pozycji — przy pustym dokumencie
                        pojawi się tekst zastępczy. Bez znaków &lt; i&nbsp;&gt;.
                    </small>
                </div>

                <!-- ---- Podgląd ---- -->
                <div class="custom-control custom-checkbox mt-3">
                    <input type="checkbox" class="custom-control-input" id="edPreviewToggle">
                    <label class="custom-control-label" for="edPreviewToggle">
                        <i class="bi bi-arrow-counterclockwise text-muted"></i>
                        Podgląd z przykładowymi danymi
                    </label>
                </div>
                <div class="collapse mt-2" id="edPreviewWrap">
                    <div class="ed-template-preview">
                        <div class="ed-template-preview-stripe"></div>
                        <div class="ed-template-preview-header">
                            <span>
                                <i class="bi bi-eye"></i>
                                Podgląd z przykładowymi danymi
                            </span>
                            <?php // Numer z ED_SAMPLE_VARS — operator od razu wie,
                                  // że patrzy na atrapę, a nie na realny dokument. ?>
                            <span class="font-weight-normal text-lowercase"
                                  style="letter-spacing:0">
                                RFQ/2026/0001
                            </span>
                        </div>
                        <div class="ed-template-preview-subject">
                            <span class="ed-template-preview-key">Temat</span>
                            <span id="edPreviewSubject"></span>
                        </div>
                        <pre class="ed-template-preview-body mb-0" id="edPreviewBody"></pre>
                    </div>
                    <small class="form-text text-muted">
                        Prawdziwe wartości podstawi kreator wysyłki.
                    </small>
                </div>

                <!-- ---- Status ---- -->
                <div class="form-group mt-4 mb-0">
                    <label>Status:</label>
                    <div>
                        <label class="mr-3 mb-0">
                            <input type="radio" name="isActive" value="1" <?= $edIsActive ? 'checked' : '' ?>>
                            Aktywny
                        </label>
                        <label class="mb-0">
                            <input type="radio" name="isActive" value="0" <?= !$edIsActive ? 'checked' : '' ?>>
                            Nieaktywny
                        </label>
                    </div>
                    <small class="form-text text-muted">
                        Nieaktywny szablon znika z&nbsp;pickerów — i&nbsp;na karcie dostawcy,
                        i&nbsp;w&nbsp;kroku 4 wysyłki — nawet jeśli dostawca nadal go
                        wskazuje. Kreator sięgnie wtedy po pierwszy pasujący szablon
                        z&nbsp;katalogu.
                    </small>
                </div>

                <!-- ---- Domyślny globalnie ---- -->
                <?php // Dwie niezależne flagi — jeden szablon może być domyślny
                      // i dla RFQ, i dla PO. Odznaczony checkbox nie trafia do
                      // zwykłego POST-a; endpoint (email-draft-edit-save.php)
                      // traktuje brak klucza jako false. JS i tak wysyła '1' / '0'
                      // jawnie (edTemplatePostData). ?>
                <div class="form-group mt-3 mb-0">
                    <label class="d-block mb-1" id="ed_default_label">Domyślny globalnie:</label>
                    <div class="d-flex flex-wrap ed-default-group"
                         role="group"
                         aria-labelledby="ed_default_label">
                        <div class="custom-control custom-checkbox mr-4">
                            <input type="checkbox" class="custom-control-input"
                                   id="ed_default_rfq" name="isDefaultRfq" value="1"
                                   <?= ($draft !== null && $draft->isDefaultRfq) ? 'checked' : '' ?>>
                            <label class="custom-control-label" for="ed_default_rfq">
                                <i class="bi bi-patch-question text-muted" aria-hidden="true"></i>
                                Domyślny dla RFQ
                            </label>
                        </div>
                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" class="custom-control-input"
                                   id="ed_default_po" name="isDefaultPo" value="1"
                                   <?= ($draft !== null && $draft->isDefaultPo) ? 'checked' : '' ?>>
                            <label class="custom-control-label" for="ed_default_po">
                                <i class="bi bi-cart-check text-muted" aria-hidden="true"></i>
                                Domyślny dla PO
                            </label>
                        </div>
                    </div>
                    <small class="form-text text-muted">
                        Kolejność wyboru szablonu w&nbsp;kreatorze wysyłki: szablon
                        wskazany przez dostawcę → domyślny globalny → pierwszy aktywny
                        z&nbsp;katalogu. Domyślny globalny działa więc tylko wtedy, gdy
                        dostawca nie ma własnego wyboru dla danego typu dokumentu.
                    </small>
                </div>

            </div>
            <div class="card-footer d-flex justify-content-between align-items-center">
                <small class="text-muted">
                    Pola oznaczone <span class="text-danger">*</span> są wymagane.
                    <?php if ($isEditMode && $edCreatedAt !== ''): ?>
                        <span class="ed-meta d-block">
                            <?php if ($edUpdatedAt !== '' && $edUpdatedAt !== $edCreatedAt): ?>
                                Ostatnia edycja: <?= htmlspecialchars(mb_substr($edUpdatedAt, 0, 16), ENT_QUOTES) ?>
                                <span class="mx-1">·</span>
                            <?php endif; ?>
                            Utworzono: <?= htmlspecialchars(mb_substr($edCreatedAt, 0, 16), ENT_QUOTES) ?>
                        </span>
                    <?php endif; ?>
                </small>
                <div class="d-flex align-items-center">
                    <span id="edDirtyHint" class="text-muted mr-2 d-none">
                        <i class="bi bi-pencil-fill"></i> niezapisane zmiany
                    </span>
                    <a href="<?= htmlspecialchars($backUrl, ENT_QUOTES) ?>" class="btn btn-secondary mr-2">
                        <i class="bi bi-arrow-left"></i> Powrót
                    </a>
                    <button type="submit" class="btn btn-primary" id="edSubmitBtn" disabled>
                        <i class="bi bi-check2-circle"></i> Zapisz szablon
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>

<script src="<?= asset('public_html/assets/js/shared/items-format.js') ?>"></script>
<script src="<?= asset('public_html/components/Admin/Purchase/EmailDrafts/edit/email-draft-edit-view.js') ?>"></script>
