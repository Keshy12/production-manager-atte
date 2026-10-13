<?php
/**
 * Lista globalnych szablonów wiadomości e-mail.
 *
 * Wpięta pod /admin/purchase/email-drafts (mirror
 * /admin/purchase/vendors). W odróżnieniu od listy dostawców ten
 * widok renderuje wiersze po stronie serwera — katalog szablonów
 * jest z natury mały (kilka–kilkanaście pozycji), więc filtry,
 * paginacja i osobny endpoint AJAX byłyby kosztem bez zysku.
 *
 * Szablony są GLOBALNE. Dostawca wskazuje swój preferowany szablon
 * przez FK (`preferred_email_draft_rfq_id` / `..._po_id`) na karcie
 * „Preferowane szablony wiadomości” w /admin/purchase/vendors/edit.
 * Kreator wysyłki (/admin/purchase/documents/send, krok 4) rozwiązuje
 * ten FK i podstawia zmienne przez applyTemplate().
 */
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Master\EmailDraftRepository;

if (!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    header("Location: http://" . BASEURL . "/");
    exit();
}

$MsaDB  = MsaDB::getInstance();
$edRepo = new EmailDraftRepository($MsaDB);
$drafts = $edRepo->getAll(false);   // aktywne + nieaktywne

// Ile dostawców wskazuje na każdy szablon przez własny wybór
// (preferred_email_draft_rfq_id / _po_id). To priorytet wyższy niż
// domyślny globalny, więc licznik pokazujemy tylko tam, gdzie globalnego
// domyślnego nie ma — inaczej plakietka „Domyślny" i licznik gadałyby
// o dwóch różnych rzeczach naraz.
$edUsage = $edRepo->getVendorUsageCounts();

$editUrl = 'http://' . BASEURL . '/admin/purchase/email-drafts/edit';

$edActiveCount = 0;
foreach ($drafts as $d) {
    if ($d->isActive) { $edActiveCount++; }
}
$edInactiveCount = count($drafts) - $edActiveCount;

/**
 * Plakietki `email_draft.applies_to`. Kolory są dokładnie te same co
 * znaczniki typu na liście dokumentów (/purchase/documents,
 * documents-ui.css → .dx-type-tag), żeby RFQ wyglądało tak samo
 * niezależnie od tego, gdzie się na nie patrzy:
 *   RFQ — ciepła glina/karmel (jasne tło + obrys),
 *   PO  — chłodny łupkowy błękit (samo tło).
 * „RFQ + PO" zostaje neutralne — nie zawęża niczego, więc nie powinno
 * krzyczeć kolorem.
 */
$edTypeBadges = [
    'rfq' => [
        'class' => 'ed-badge-type--rfq',
        'icon'  => 'bi-patch-question',
        'label' => 'RFQ',
        'title' => 'Widoczny w kreatorze wysyłki tylko dla zapytań ofertowych.',
    ],
    'po' => [
        'class' => 'ed-badge-type--po',
        'icon'  => 'bi-cart-check',
        'label' => 'PO',
        'title' => 'Widoczny w kreatorze wysyłki tylko dla zamówień.',
    ],
    'both' => [
        'class' => 'badge-light ed-badge-type-both',
        'icon'  => 'bi-collection',
        'label' => 'RFQ + PO',
        'title' => 'Widoczny w kreatorze wysyłki dla zapytań ofertowych i zamówień.',
    ],
];

/**
 * Plakietki „domyślny globalnie” (`is_default_rfq` / `is_default_po`).
 * Ten sam kolor co typ, tyle że w wariancie pełnym (mocniejsze tło,
 * węższy odcień): to nie jest informacja o tym, „czego dotyczy
 * szablon" (to mówi kolumna Typ), tylko „kto wybiera go w pierwszej
 * kolejności". Ten sam odcień rodziny, więc RFQ w obu plakietkach
 * wygląda tak samo, a odróżniają je ikona (gwiazdka) i etykieta.
 */
$edDefaultBadges = [
    'rfq' => [
        'class' => 'ed-badge-default--rfq',
        'icon'  => 'bi-star-fill',
        'label' => 'RFQ',
        'title' => 'Domyślny dla zapytań ofertowych — używany, gdy dostawca nie ma własnego wyboru.',
    ],
    'po' => [
        'class' => 'ed-badge-default--po',
        'icon'  => 'bi-star-fill',
        'label' => 'PO',
        'title' => 'Domyślny dla zamówień — używany, gdy dostawca nie ma własnego wyboru.',
    ],
    'both' => [
        'class' => 'ed-badge-default--both',
        'icon'  => 'bi-star-fill',
        'label' => 'RFQ + PO',
        'title' => 'Domyślny dla zapytań ofertowych i zamówień — używany, gdy dostawca nie ma własnego wyboru.',
    ],
];

/**
 * Chip „N dostawców" przy jednym typie dokumentu. Krótki, bo komórka ma
 * szerokość jednej kolumny: „RFQ 4" mówi to samo co „4 dostawcy mają
 * ten szablon jako domyślny dla zapytań", a pełne zdanie wisi w
 * title="" na hover.
 */
if (!function_exists('ed_vendor_chip')) {
    function ed_vendor_chip(string $typeLabel, int $count, string $draftName): string {
        $typeText  = $typeLabel === 'RFQ' ? 'zapytań ofertowych' : 'zamówień';
        $title = sprintf(
            '%d dostawców ma ten szablon jako domyślny dla %s.',
            $count,
            $typeText
        );
        return '<span class="ed-vendor-count" title="'
            . htmlspecialchars($title . ' Szablon: ' . $draftName, ENT_QUOTES) . '">'
            . '<span class="ed-vendor-count__n">' . $count . '</span> '
            . htmlspecialchars($typeLabel, ENT_QUOTES)
            . '</span>';
    }
}
?>
<style>
    /* ID w kolumnie z cyframi o stałej szerokości — łatwiej skanować
       listę wzrokiem niż przy proporcjonalnych cyfrach. */
    #edTable td.ed-col-id, #edTable th.ed-col-id {
        font-variant-numeric: tabular-nums;
        color: #adb5bd;
    }
    /* Gęsty rytm tabel administracyjnych: lżejsze nagłówki, ciaśniejsze
       wiersze niż domyślny .table-sm. */
    #edTable thead th {
        border-top: 0;
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: #6c757d;
        white-space: nowrap;
    }
    #edTable tbody td {
        vertical-align: middle;
    }
    /* Akcent po lewej przy najechaniu — wiersz „podnosi się” bez
       rozpychania układu. */
    #edTable tbody tr {
        transition: box-shadow 0.12s ease, background-color 0.12s ease;
    }
    #edTable tbody tr:hover {
        box-shadow: inset 3px 0 0 #0d6efd;
    }
    .ed-name {
        font-weight: 600;
    }
    /* Temat to techniczny ciąg ze zmiennymi w klamrach — monospace
       sprawia, że {vendor_name} czyta się jako token, a nie jako tekst.
       Jedna linia z wielokropkiem: kolumna nigdy nie rozpycha tabeli,
       a pełna treść siedzi w title="". */
    .ed-subject {
        display: block;
        max-width: 34rem;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        font-family: SFMono-Regular, Consolas, "Liberation Mono", Menlo, monospace;
        font-size: 0.78rem;
        color: #495057;
    }
    /* Wyszarzone wiersze nieaktywnych szablonów — ten sam język
       wizualny co lista dostawców i artykułów u dostawców. */
    tr.ed-row--inactive {
        color: #6c757d;
    }
    tr.ed-row--inactive .ed-name {
        font-weight: 500;
    }
    tr.ed-row--inactive .ed-subject {
        color: #adb5bd;
    }
    /* Plakietka typu dokumentu — jedna linia, nigdy nie łamie kolumny. */
    .ed-badge-type {
        font-weight: 600;
        white-space: nowrap;
    }
    .ed-badge-type .bi {
        margin-right: 0.1rem;
    }
    /* Kolory typu — kopiowane z listy dokumentów (documents-ui.css,
       .dx-type-tag--rfq / --po), żeby RFQ i PO wyglądały tak samo na
       obu stronach. RFQ: ciepła glina/karmel — jasne tło + obrys.
       PO: chłodny łupkowy błękit — samo tło. */
    .ed-badge-type--rfq {
        background-color: #f7ebdd;
        border: 1px solid #ebd3b8;
        color: #8a5228;
    }
    .ed-badge-type--po {
        background-color: #dfe7f1;
        color: #34506f;
    }
    /* „RFQ + PO” to brak ograniczenia, nie wyróżnienie: jasne tło
       z obrysem, żeby plakietka nie znikła na białym wierszu, ale i nie
       konkurowała kolorem z RFQ / PO. */
    .ed-badge-type-both {
        border: 1px solid #ced4da;
        color: #6c757d;
    }
    /* Nieaktywny wiersz przygasza też plakietkę typu — inaczej kolor
       ciągnie wzrok do szablonu, który i tak nigdzie się nie pojawi. */
    tr.ed-row--inactive .ed-badge-type {
        opacity: 0.6;
    }
    /* Plakietka „domyślny” — ta sama zasada jednej linii co typ. */
    .ed-badge-default {
        font-weight: 600;
        white-space: nowrap;
    }
    .ed-badge-default .bi {
        margin-right: 0.1rem;
        font-size: 0.7rem;
    }
    /* Domyślny globalnie — ta sama rodzina kolorów co typ, w wariancie
       pełnym (mocniejsze tło + obrys). RFQ zostaje ciepła glina, PO
       chłodny błękit, „RFQ + PO" neutralny. */
    .ed-badge-default--rfq {
        background-color: #e8c9a5;
        border: 1px solid #cf8a4f;
        color: #6f4119;
    }
    .ed-badge-default--po {
        background-color: #b9c8da;
        border: 1px solid #4f6d92;
        color: #2c4560;
    }
    .ed-badge-default--both {
        background-color: #e2e6ea;
        border: 1px solid #b6bfc7;
        color: #495057;
    }
    /* Licznik dostawców, którym ten szablon jest domyślny. Celowo
       najciszej: pusty, bez tła, z cienką ramką — to liczba
       kontekstowa przy plakietce, nie kolejna informacja główna. */
    .ed-vendor-count {
        display: inline-flex;
        align-items: baseline;
        gap: 0.15rem;
        margin-left: 0.3rem;
        padding: 0.08rem 0.3rem;
        border: 1px solid #ced4da;
        border-radius: 0.2rem;
        background-color: #f8f9fa;
        font-size: 0.62rem;
        font-weight: 600;
        color: #6c757d;
        white-space: nowrap;
        vertical-align: middle;
    }
    .ed-vendor-count .ed-vendor-count__n {
        font-variant-numeric: tabular-nums;
        color: #495057;
    }
    /* Nieaktywny szablon nie bierze udziału w wyborze (kreator go
       pomija), więc domyślność też przygasamy — tak samo jak typ.
       0.7 zamiast 0.6: pełny niebieski zostaje czytelny na szarym
       tle wiersza, a nadal widać, że to nie działa. */
    tr.ed-row--inactive .ed-badge-default {
        opacity: 0.7;
    }
    /* Licznik dostawców przygasza tak samo jak plakietki wyżej. */
    tr.ed-row--inactive .ed-vendor-count {
        opacity: 0.7;
    }
    /* Nieaktywny = cichy, nie alarmujący. Stonowana plakietka zamiast
       czerwieni, która w tej tabeli znaczyłaby „błąd”. */
    .ed-badge-off {
        background-color: #e9ecef;
        color: #6c757d;
        font-weight: 500;
    }
    .ed-col-created {
        font-variant-numeric: tabular-nums;
        white-space: nowrap;
        font-size: 0.8rem;
        color: #6c757d;
    }
    /* Licznik przy nagłówku — plakietka, nie nawias w tekście. */
    .ed-count-badge {
        background-color: #e7f1ff;
        color: #0b5ed7;
        font-size: 0.8rem;
        font-weight: 600;
        vertical-align: middle;
        position: relative;
        top: -0.15rem;
    }
    /* Pusty stan: świadomie duży i spokojny — to jedyna treść na
       stronie, gdy katalog jest jeszcze pusty. */
    .ed-empty {
        border: 1px dashed #dee2e6;
        border-radius: 0.35rem;
        background-color: #fbfbfc;
        padding: 3rem 1rem;
        text-align: center;
    }
    .ed-empty .bi-envelope-paper {
        font-size: 2.25rem;
        color: #ced4da;
        display: block;
        margin-bottom: 0.75rem;
    }
    .ed-empty p {
        max-width: 32rem;
        margin-left: auto;
        margin-right: auto;
    }
</style>

<div class="container-fluid w-75 mt-3">
    <div class="row">
        <div class="col-12 my-2">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-1">
                        Szablony wiadomości<?php if ($drafts !== []): ?>
                        <span class="badge badge-pill ed-count-badge ml-1"><?= count($drafts) ?></span>
                        <?php endif; ?>
                    </h2>
                    <small class="text-muted">
                        Globalne szablony e-maili używane przy wysyłce zapytań i&nbsp;zamówień.
                        Dostawca wskazuje swój szablon na karcie „Preferowane szablony wiadomości”.
                        Szablon domyślny globalnie jest używany tylko wtedy, gdy dostawca nie ma
                        własnego wyboru dla danego typu dokumentu.
                    </small>
                </div>
                <a href="<?= htmlspecialchars($editUrl, ENT_QUOTES) ?>"
                   class="btn btn-primary btn-sm">
                    <i class="bi bi-plus-circle"></i> Nowy szablon
                </a>
            </div>
            <div id="alertContainer" class="mt-2"></div>
        </div>
    </div>

    <?php if ($drafts === []): ?>
        <div class="ed-empty mt-4">
            <i class="bi bi-envelope-paper"></i>
            <p class="mb-3 text-muted">
                Brak szablonów wiadomości — kliknij <strong>Nowy szablon</strong> powyżej,
                aby utworzyć pierwszy.
            </p>
            <a href="<?= htmlspecialchars($editUrl, ENT_QUOTES) ?>" class="btn btn-primary btn-sm">
                <i class="bi bi-plus-circle"></i> Nowy szablon
            </a>
        </div>
    <?php else: ?>
        <div class="d-flex justify-content-end align-items-center mt-3">
            <small class="text-muted">
                Aktywne: <strong class="text-success"><?= (int)$edActiveCount ?></strong>
                <span class="mx-1">·</span>
                Nieaktywne: <strong><?= (int)$edInactiveCount ?></strong>
            </small>
        </div>

        <div class="table-responsive mt-1">
            <table class="table table-sm table-hover" id="edTable">
                <thead class="thead-light">
                    <tr>
                        <th class="ed-col-id text-center" style="width: 56px;">ID</th>
                        <th style="min-width: 200px;">Nazwa</th>
                        <th>Temat</th>
                        <th class="text-center" style="width: 112px;">Typ</th>
                        <th class="text-center" style="width: 112px;"
                            title="Domyślny globalnie (gwiazdka). Cyfra obok — liczba dostawców, którym ten szablon jest domyślny, mimo że nie jest domyślny globalnie.">Domyślny</th>
                        <th class="text-center" style="width: 104px;">Status</th>
                        <th style="width: 130px;">Utworzono</th>
                        <th class="text-center" style="width: 64px;">Akcje</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($drafts as $d):
                    $rowClass = $d->isActive ? '' : 'ed-row--inactive table-secondary';

                    // Skracanie robi CSS (text-overflow) — PHP oddaje pełny
                    // temat, żeby title="" i tekst w komórce nie rozjechały się.
                    $subject = trim($d->subject);
                    $subjectCell = $subject === ''
                        ? '<span class="text-muted">—</span>'
                        : '<span class="ed-subject" title="'
                          . htmlspecialchars($subject, ENT_QUOTES) . '">'
                          . htmlspecialchars($subject, ENT_QUOTES) . '</span>';

                    $statusBadge = $d->isActive
                        ? '<span class="badge badge-success">Aktywny</span>'
                        : '<span class="badge ed-badge-off">Nieaktywny</span>';

                    // Wartość spoza enuma (ręczna edycja w bazie) czytamy
                    // jako 'both' — tak samo jak encja i kreator wysyłki.
                    $typeMeta = $edTypeBadges[$d->appliesTo] ?? $edTypeBadges['both'];
                    $typeBadge = '<span class="badge ed-badge-type ' . $typeMeta['class'] . '"'
                        . ' title="' . htmlspecialchars($typeMeta['title'], ENT_QUOTES) . '">'
                        . '<i class="bi ' . $typeMeta['icon'] . '" aria-hidden="true"></i> '
                        . htmlspecialchars($typeMeta['label'], ENT_QUOTES)
                        . '</span>';

                    // Dwie niezależne flagi → trzy stany plus „brak”. Brak
                    // to myślnik, tak jak w pozostałych pustych komórkach.
                    $defaultKey = $d->isDefaultRfq && $d->isDefaultPo ? 'both'
                        : ($d->isDefaultRfq ? 'rfq' : ($d->isDefaultPo ? 'po' : null));
                    if ($defaultKey === null) {
                        $defaultBadge = '<span class="text-muted">—</span>';
                    } else {
                        $defMeta = $edDefaultBadges[$defaultKey];
                        $defaultBadge = '<span class="badge ed-badge-default ' . $defMeta['class'] . '"'
                            . ' title="' . htmlspecialchars($defMeta['title'], ENT_QUOTES) . '">'
                            . '<i class="bi ' . $defMeta['icon'] . '" aria-hidden="true"></i> '
                            . htmlspecialchars($defMeta['label'], ENT_QUOTES)
                            . '</span>';
                    }

                    // Licznik dostawców, którym ten szablon jest domyślny.
                    // Tylko gdy NIE jest domyślny globalnie — wtedy ta
                    // komórka opisuje dokładnie jeden fakt („nie jest
                    // niczyim domyślny"), a licznik dopowiada, ilu
                    // dostawców mimo to go wybrało. Typ: chip per typ
                    // zgodnie z applies_to, bo „dostawca wybrał ten
                    // szablon do RFQ" i „do PO" to dwie różne liczby.
                    $usage = $edUsage[(int)$d->id] ?? ['rfqCount' => 0, 'poCount' => 0];
                    if ($defaultKey === null) {
                        $chips = '';
                        if ($d->appliesTo !== 'po' && $usage['rfqCount'] > 0) {
                            $chips .= self::ed_vendor_chip('RFQ', $usage['rfqCount'], $d->name);
                        }
                        if ($d->appliesTo !== 'rfq' && $usage['poCount'] > 0) {
                            $chips .= self::ed_vendor_chip('PO', $usage['poCount'], $d->name);
                        }
                        $defaultBadge = '<span class="text-muted">—</span>' . $chips;
                    }

                    // created_at przychodzi jako 'Y-m-d H:i:s'; w tabeli
                    // wystarczy data + godzina bez sekund.
                    $created = trim($d->createdAt);
                    $createdCell = $created !== ''
                        ? htmlspecialchars(mb_substr($created, 0, 16), ENT_QUOTES)
                        : '<span class="text-muted">—</span>';

                    $rowEditUrl = $editUrl . '?id=' . (int)$d->id;
                ?>
                    <tr class="<?= $rowClass ?>">
                        <td class="ed-col-id text-center"><?= (int)$d->id ?></td>
                        <td class="ed-name"><?= htmlspecialchars($d->name, ENT_QUOTES) ?></td>
                        <td><?= $subjectCell ?></td>
                        <td class="text-center"><?= $typeBadge ?></td>
                        <td class="text-center"><?= $defaultBadge ?></td>
                        <td class="text-center"><?= $statusBadge ?></td>
                        <td class="ed-col-created"><?= $createdCell ?></td>
                        <td class="text-center">
                            <a class="btn btn-sm btn-warning"
                               href="<?= htmlspecialchars($rowEditUrl, ENT_QUOTES) ?>"
                               title="Edytuj szablon „<?= htmlspecialchars($d->name, ENT_QUOTES) ?>”"
                               aria-label="Edytuj szablon">
                                <i class="bi bi-pencil"></i>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
