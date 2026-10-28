<?php
/**
 * Dedicated edit/create page for a single producer.
 *
 * Wired at /admin/purchase/producers/edit?id=N (mirrors the
 * vendor-parts/edit convention). Renders the form on GET and lets
 * producer-edit-save.php handle the POST submission.
 *
 * Modes:
 *   - `?id=N` (N > 0) → EDIT existing producer (pre-populated fields)
 *   - absent / id<=0  → CREATE new producer (empty form)
 *
 * Form fields mirror list__producer:
 *   - name (text, required)
 *   - comment (textarea, optional)
 *   - isActive (radio: Aktywny / Nieaktywny, default Aktywny on create)
 *
 * Formularz to trzy osobne karty (każda z ikoną, tytułem i jednym zdaniem
 * opisu): Dane podstawowe (nazwa), Status oraz „Więcej" (komentarz).
 * WSZYSTKIE startują zwinięte i pokazują jednolinijkowe podsumowanie
 * bieżących wartości (tylko do odczytu); kliknięcie nagłówka rozwija
 * edytowalne pola. Podsumowanie leży POZA przyciskiem nagłówka, a odświeża
 * je producer-edit-view.js przy każdej zmianie pola — format musi być
 * identyczny po obu stronach. Wszystkie karty leżą w jednym #prEditForm:
 * zwinięcie karty chowa pola tylko CSS-em, więc serialize() nadal je wysyła.
 *
 * In EDIT mode the header carries an „Artykuły" button with the parts
 * count; it opens a modal (#prVpModal) holding the read-only list of
 * vendor parts using this producer. The list stays server-rendered
 * (no AJAX endpoint, no pagination).
 *
 * On a successful CREATE the JS redirects to ?id=N so the operator
 * lands on the edit page for the freshly-created producer. On a
 * successful UPDATE the JS stays on the page and shows an inline
 * success alert. On validation errors the save endpoint returns
 * JSON and the JS shows an inline alert in #prFormAlertHost.
 *
 * Wygląd: ten sam język co edycja dostawcy (/admin/purchase/vendors/edit) —
 * receipts-ui.css jako baza (tylko do odczytu) + producer-edit-ui.css
 * (świadoma kopia systemu kart dostawcy, prefiks `pre-`). Cała strona
 * stoi w jednej kolumnie o szerokości 75%.
 */
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Master\ProducerRepository;
use Atte\Utils\Purchase\Master\VendorPartRepository;

if (!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    header("Location: http://" . BASEURL . "/");
    exit();
}

$id = (int)($_GET['id'] ?? 0);
$isEditMode = $id > 0;
$producer = null;
$notFound = false;
$producerName = '';
$producerComment = '';
$producerIsActive = true;   // default: Aktywny (only meaningful in create mode)
$vendorPartCount = 0;        // count on the header button + modal chip
$vendorParts = [];           // read-only display: vendor parts using this producer

if ($isEditMode) {
    $MsaDB = MsaDB::getInstance();
    $repo  = new ProducerRepository($MsaDB);
    $producer = $repo->getById($id);
    if ($producer === null) {
        // Nie ma takiego producenta — strona pokazuje pusty stan zamiast
        // formularza (który i tak nie miałby czego zapisywać).
        $notFound = true;
    } else {
        $producerName     = $producer->name;
        $producerComment  = $producer->comment ?? '';
        $producerIsActive = $producer->isActive;
        $vendorPartCount  = $repo->countVendorParts($id);

        // Fetch the actual rows for the read-only table in the modal.
        // Both active and inactive rows so the operator sees the full
        // picture (matches the vendor-parts listing's behaviour with
        // $onlyActive=false). The repo's LEFT JOINs fill in vendorName,
        // partName, unitName so we don't need extra queries.
        $vpRepo      = new VendorPartRepository($MsaDB);
        $vendorParts = $vpRepo->getByProducer($id, false);
    }
}

$backUrl = 'http://' . BASEURL . '/admin/purchase/producers';

// Format a float without trailing zeros (e.g. 10.000 -> "10").
// Mirrors fmtQty() in vendor-parts-view.js so server- and client-
// rendered pack badges read identically.
function pr_fmt_qty($n) {
    $f = is_numeric($n) ? (float)$n : null;
    if ($f === null) return htmlspecialchars((string)($n ?? '—'), ENT_QUOTES);
    return (float)number_format($f, 4, '.', '') == (float)(int)$f
        ? (string)(int)$f
        : rtrim(rtrim(number_format($f, 4, '.', ''), '0'), '.');
}

// Render the packs cell — small inline badges, comma-separated. Empty
// state shows a muted em-dash so the column doesn't collapse.
function pr_render_packs(array $packs): string {
    if ($packs === []) {
        return '<span class="text-muted">—</span>';
    }
    $badges = '';
    foreach ($packs as $p) {
        $badges .= '<span class="pr-pack-badge">' . htmlspecialchars(pr_fmt_qty($p), ENT_QUOTES) . '</span>';
    }
    return $badges;
}

// Polska liczba mnoga: 1 artykuł / 2 artykuły / 5 artykułów.
function pr_count_label(int $n, string $one, string $few, string $many): string {
    if ($n === 1) { return $one; }
    $mod10  = $n % 10;
    $mod100 = $n % 100;
    if ($mod10 >= 2 && $mod10 <= 4 && !($mod100 >= 12 && $mod100 <= 14)) {
        return $few;
    }
    return $many;
}

// Przycisk „Artykuły" w nagłówku: liczba artykułów u dostawców.
$prPartCount = (int)$vendorPartCount;
$prPartsFull = $prPartCount . ' ' . pr_count_label($prPartCount, 'artykuł', 'artykuły', 'artykułów');

// ---- Linijki podsumowania zwiniętych kart --------------------------
// Wszystkie karty startują zwinięte, więc podsumowanie musi być gotowe
// przy pierwszym malowaniu strony (bez czekania na JS). producer-edit-view.js
// odświeża te same teksty przy każdej zmianie pola — format musi być
// identyczny po obu stronach.
// Każde podsumowanie to para [html, tekst]: html idzie do strony (z
// <strong> przy nazwie), tekst do atrybutu title (jawna lista „pole: wartość").
$prEsc = static function ($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES);
};
// Pole bez wartości: sama krótka nazwa w stylu „brak" (kursywa + wyblakły
// kolor). Wypełnione = jego wartość. Tooltip: „pole: wartość", „—" gdy puste.
$prMissing = static function (string $name) use ($prEsc): string {
    return '<span class="pre-sum-missing">' . $prEsc($name) . '</span>';
};
// Spacje/nowe linie w wartości do jednej linii.
$prOneLine = static function ($s): string {
    return trim((string)preg_replace('/\s+/u', ' ', (string)$s));
};

$prName = trim($producerName);
$prCom  = $prOneLine(trim($producerComment));

// Dane podstawowe: nazwa.
$prSumBasic = [
    $prName !== '' ? '<strong>' . $prEsc($prName) . '</strong>' : $prMissing('nazwa'),
    'nazwa: ' . ($prName !== '' ? $prOneLine($prName) : '—'),
];

// Status.
$prSumStatusLabel = $producerIsActive ? 'Aktywny' : 'Nieaktywny';
$prSumStatus = [
    '<span class="pre-sum-dot ' . ($producerIsActive ? 'is-on' : 'is-off') . '" aria-hidden="true"></span>'
        . $prSumStatusLabel,
    $prSumStatusLabel,
];

// Więcej: komentarz.
$prSumMore = [
    $prCom !== '' ? $prEsc($prCom) : $prMissing('komentarz'),
    'komentarz: ' . ($prCom !== '' ? $prCom : '—'),
];

?>
<link rel="stylesheet" href="<?= asset('public_html/components/purchases/receipts/receipts-ui.css') ?>">
<link rel="stylesheet" href="<?= asset('public_html/components/Admin/Purchase/Producers/edit/producer-edit-ui.css') ?>">

<div class="rx-page pre-page" id="producerEditView">

  <!-- Cała strona w jednej kolumnie (75%) — okruszki, nagłówek i
       formularz mają tę samą szerokość. -->
  <div class="pre-column">

    <!-- ===== Ścieżka nawigacji ===== -->
    <nav aria-label="Ścieżka">
        <ol class="rx-crumbs">
            <li><a href="<?= htmlspecialchars($backUrl, ENT_QUOTES) ?>">Producenci</a></li>
            <li aria-hidden="true"><i class="bi bi-chevron-right"></i></li>
            <li aria-current="page">
                <?php if ($notFound): ?>
                    Producent #<?= (int)$id ?>
                <?php elseif ($isEditMode): ?>
                    <?= htmlspecialchars($producerName, ENT_QUOTES) ?>
                <?php else: ?>
                    Nowy producent
                <?php endif; ?>
            </li>
        </ol>
    </nav>

    <!-- ===== Nagłówek strony ===== -->
    <header class="rx-head">
        <div class="rx-head-main">
            <div class="rx-eyebrow"><i class="bi bi-building"></i> Zamówienia komponentów</div>
            <h1 class="rx-title">
                <?= !$notFound && !$isEditMode ? 'Dodaj producenta' : 'Edytuj producenta' ?>
            </h1>
            <p class="rx-lead">
                <?php if ($notFound): ?>
                    Nie znaleziono producenta o&nbsp;podanym identyfikatorze.
                <?php elseif ($isEditMode): ?>
                    Nazwa, status i&nbsp;komentarz producenta oraz lista powiązanych artykułów.
                    Zmiany zapisujesz przyciskiem na&nbsp;dole formularza.
                <?php else: ?>
                    Wpisz dane nowego producenta — przypisanie go do&nbsp;artykułów robisz
                    później, w&nbsp;module „Artykuły u&nbsp;dostawców”.
                <?php endif; ?>
            </p>
        </div>
        <?php if ($isEditMode && !$notFound): ?>
            <!-- Id producenta + przycisk „Artykuły". Liczba artykułów jest
                 na samym przycisku — widać ją bez otwierania modala.
                 „Wróć do listy" jest w pasku zapisu pod formularzem oraz
                 w okruszkach, więc w nagłówku go nie dublujemy. -->
            <div class="pre-head-links">
                <span class="rx-count pre-head-id" title="Identyfikator producenta">#<?= (int)$producer->id ?></span>
                <button type="button" class="btn pre-rel-btn"
                        data-toggle="modal" data-target="#prVpModal"
                        aria-haspopup="dialog"
                        aria-label="Artykuły producenta: <?= htmlspecialchars($prPartsFull, ENT_QUOTES) ?>"
                        title="<?= htmlspecialchars($prPartsFull, ENT_QUOTES) ?> — kliknij, aby zobaczyć">
                    <span class="pre-rel-main"><i class="bi bi-box-seam"></i> Artykuły</span>
                    <span class="pre-rel-counts">
                        <span class="pre-rel-chip<?= $prPartCount === 0 ? ' is-zero' : '' ?>">
                            <strong><?= $prPartCount ?></strong>
                            <?= pr_count_label($prPartCount, 'artykuł', 'artykuły', 'artykułów') ?>
                        </span>
                    </span>
                </button>
            </div>
        <?php endif; ?>
    </header>

    <!-- Host na komunikaty AJAX (wynik zapisu). Pusty, dopiero JS wstawia
         tu alert — dlatego osobny, stały punkt zaczepienia. -->
    <div id="prFormAlertHost" class="rx-alerts"></div>

<?php if ($notFound): ?>

    <!-- ===== Nie ma takiego producenta ===== -->
    <section class="rx-panel pre-panel">
        <div class="rx-empty rx-empty--page">
            <div class="rx-empty-icon rx-empty-icon--danger"><i class="bi bi-exclamation-triangle"></i></div>
            <div class="rx-empty-title">Producent nie istnieje</div>
            <div class="rx-empty-text">
                Producent #<?= htmlspecialchars((string)$id, ENT_QUOTES) ?> nie został znaleziony
                albo został usunięty.
            </div>
            <a href="<?= htmlspecialchars($backUrl, ENT_QUOTES) ?>" class="btn btn-sm btn-primary">
                <i class="bi bi-arrow-left"></i> Wróć do listy
            </a>
        </div>
    </section>

<?php else: ?>

    <!-- ================================================================
         Formularz producenta. Wszystkie pola leżą WEWNĄTRZ #prEditForm,
         więc $form.serialize() widzi komplet (id, name, comment, isActive)
         także wtedy, gdy wszystkie karty są zwinięte.
         ================================================================ -->
    <form method="post" id="prEditForm" autocomplete="off">
        <input type="hidden" name="id" value="<?= $isEditMode ? (int)$producer->id : 0 ?>">

        <!-- Wszystkie trzy karty mają ten sam wzór (zwijanie Bootstrap 4):
             <h2> NA ZEWNĄTRZ przycisku (przycisk przyjmuje tylko treść
             frazową), w nim ikona + tytuł + opis + strzałka; pod nagłówkiem
             linijka podsumowania (też poza przyciskiem — długi tekst nie
             ma być nazwą przycisku dla czytnika ekranu); potem ciało
             .collapse. Karty startują ZWINIĘTE (klasa `collapsed` na
             przycisku, brak `show` na ciele). Podsumowanie widać tylko
             przy zwiniętej karcie, opis tylko przy rozwiniętej — patrz
             producer-edit-ui.css. -->

        <!-- Przełącznik wszystkich kart naraz. -->
        <div class="pre-form-tools">
            <button type="button" class="btn rx-link-btn" id="prToggleAllCards">
                <i class="bi bi-arrows-expand" aria-hidden="true"></i>
                <span class="pre-tools-label">Rozwiń wszystko</span>
            </button>
        </div>

        <!-- ===== Karta 1: Dane podstawowe ===== -->
        <section class="rx-panel pre-panel pre-card" id="prBasicCard">
            <h2 class="pre-card-toggle-h">
                <button type="button" class="pre-card-head pre-card-head--toggle collapsed"
                        data-toggle="collapse" data-target="#prBasicBody"
                        aria-expanded="false" aria-controls="prBasicBody">
                    <span class="pre-card-icon" aria-hidden="true"><i class="bi bi-building"></i></span>
                    <span class="pre-card-text">
                        <span class="pre-section-label">Dane podstawowe</span>
                        <span class="pre-card-desc">Nazwa, pod którą producent występuje na liście i&nbsp;w&nbsp;artykułach.</span>
                    </span>
                    <span class="pre-card-aside">
                        <i class="bi bi-chevron-down pre-card-chevron" aria-hidden="true"></i>
                    </span>
                </button>
            </h2>
            <p class="pre-card-summary" id="prBasicSummary" title="<?= $prEsc($prSumBasic[1]) ?>"><?= $prSumBasic[0] ?></p>
            <div class="collapse" id="prBasicBody">
              <div class="pre-card-body">
                <div class="pre-grid">
                    <div class="pre-field">
                        <label class="pre-label" for="pr_edit_name">
                            Nazwa <span class="pre-required">*</span>
                        </label>
                        <input type="text" id="pr_edit_name" name="name"
                               class="form-control" required maxlength="255"
                               value="<?= htmlspecialchars($producerName, ENT_QUOTES) ?>">
                    </div>
                </div>
                <p class="pre-help pre-required-note">Pola oznaczone <span class="pre-required">*</span> są wymagane.</p>
              </div>
            </div>
        </section>

        <!-- ===== Karta 2: Status ===== -->
        <section class="rx-panel pre-panel pre-card" id="prStatusCard">
            <h2 class="pre-card-toggle-h">
                <button type="button" class="pre-card-head pre-card-head--toggle collapsed"
                        data-toggle="collapse" data-target="#prStatusBody"
                        aria-expanded="false" aria-controls="prStatusBody">
                    <span class="pre-card-icon" aria-hidden="true"><i class="bi bi-toggle-on"></i></span>
                    <span class="pre-card-text">
                        <span class="pre-section-label">Status</span>
                        <span class="pre-card-desc">Czy producent jest dostępny w&nbsp;codziennej pracy.</span>
                    </span>
                    <span class="pre-card-aside">
                        <i class="bi bi-chevron-down pre-card-chevron" aria-hidden="true"></i>
                    </span>
                </button>
            </h2>
            <p class="pre-card-summary" id="prStatusSummary" title="<?= $prEsc($prSumStatus[1]) ?>"><?= $prSumStatus[0] ?></p>
            <div class="collapse" id="prStatusBody">
              <div class="pre-card-body">
                <fieldset class="pre-fieldset pre-status-row">
                    <legend class="sr-only">Status producenta</legend>
                    <div class="pre-seg">
                        <label class="pre-seg-item">
                            <input type="radio" name="isActive" value="1" <?= $producerIsActive ? 'checked' : '' ?>>
                            <span class="pre-seg-face pre-seg-face--active">
                                <span class="pre-seg-dot"></span>Aktywny
                            </span>
                        </label>
                        <label class="pre-seg-item">
                            <input type="radio" name="isActive" value="0" <?= !$producerIsActive ? 'checked' : '' ?>>
                            <span class="pre-seg-face pre-seg-face--inactive">
                                <span class="pre-seg-dot"></span>Nieaktywny
                            </span>
                        </label>
                    </div>
                    <p class="pre-help">
                        Nieaktywni producenci nie pojawiają się w&nbsp;domyślnym widoku listy
                        ani w&nbsp;wyszukiwarkach artykułów u&nbsp;dostawców.
                    </p>
                </fieldset>
              </div>
            </div>
        </section>

        <!-- ================================================================
             Karta 3: Więcej
             Rzadkie pole: komentarz. Zwinięcie to tylko CSS (Bootstrap
             collapse) — pole zostaje w DOM i w #prEditForm, więc serialize()
             wysyła je także z zamkniętej karty. Karta zawsze startuje zwinięta.
             ================================================================ -->
        <section class="rx-panel pre-panel pre-card" id="prMoreCard">
            <h2 class="pre-card-toggle-h">
                <button type="button" class="pre-card-head pre-card-head--toggle collapsed"
                        data-toggle="collapse" data-target="#prMoreBody"
                        aria-expanded="false" aria-controls="prMoreBody">
                    <span class="pre-card-icon" aria-hidden="true"><i class="bi bi-sliders"></i></span>
                    <span class="pre-card-text">
                        <span class="pre-section-label">Więcej</span>
                        <span class="pre-card-desc">Komentarz — uwagi wewnętrzne o&nbsp;producencie.</span>
                    </span>
                    <span class="pre-card-aside">
                        <i class="bi bi-chevron-down pre-card-chevron" aria-hidden="true"></i>
                    </span>
                </button>
            </h2>
            <p class="pre-card-summary" id="prMoreSummary" title="<?= $prEsc($prSumMore[1]) ?>"><?= $prSumMore[0] ?></p>
            <div class="collapse" id="prMoreBody">
                <div class="pre-card-body">
                    <div class="pre-grid">
                        <div class="pre-field">
                            <label class="pre-label" for="pr_edit_comment">Komentarz</label>
                            <textarea id="pr_edit_comment" name="comment" class="form-control" rows="3"
                                      placeholder="Opcjonalne uwagi wewnętrzne"><?= htmlspecialchars($producerComment, ENT_QUOTES) ?></textarea>
                            <p class="pre-help">
                                Uwagi wewnętrzne — widoczne też na liście producentów.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- ===== Pasek zapisu ===== -->
        <div class="pre-panel-foot">
            <span class="pre-foot-hint">
                <?= $isEditMode
                    ? 'Zmiany zapisujesz przyciskiem po prawej.'
                    : 'Po zapisaniu otworzy się karta nowego producenta.' ?>
            </span>
            <div class="pre-foot-actions">
                <a href="<?= htmlspecialchars($backUrl, ENT_QUOTES) ?>" class="btn rx-btn-quiet">
                    <i class="bi bi-arrow-left"></i> Wróć do listy
                </a>
                <button type="submit" class="btn btn-primary pre-save-btn" id="prSubmitBtn" disabled>
                    <?php if ($isEditMode): ?>
                        <i class="bi bi-check2-circle"></i> Zapisz zmiany
                    <?php else: ?>
                        <i class="bi bi-plus-circle"></i> Dodaj producenta
                    <?php endif; ?>
                </button>
            </div>
        </div>
    </form>

<?php endif; ?>

  </div><!-- /.pre-column -->

<?php if ($isEditMode && !$notFound): ?>
    <!-- ================================================================
         Modal artykułów: tylko podgląd listy artykułów u dostawców, które
         używają tego producenta. Stoi w .rx-page, więc dziedziczy zmienne
         --rx-*; jest poza #prEditForm. Tabela jest renderowana po stronie
         serwera (bez AJAX-u i stronicowania). Modal otwiera przycisk
         „Artykuły" w nagłówku (id #prVpCard zostaje na treści modala).
         ================================================================ -->
    <div class="modal fade pre-modal" id="prVpModal" tabindex="-1" role="dialog"
         aria-labelledby="prVpTitle">
        <div class="modal-dialog modal-xl" role="document">
            <div class="modal-content" id="prVpCard">
                <div class="modal-header">
                    <h5 class="modal-title" id="prVpTitle">
                        <i class="bi bi-box-seam"></i> Artykuły producenta
                    </h5>
                    <div class="pre-modal-head-right">
                        <span class="pre-modal-chip"><?= htmlspecialchars($prPartsFull, ENT_QUOTES) ?></span>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Zamknij">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                </div>

                <div class="modal-body" id="prVpCollapse">
                    <p class="pre-table-note">
                        Tylko podgląd — edycja artykułów odbywa się w&nbsp;module „Artykuły u&nbsp;dostawców".
                    </p>

                    <?php if ($vendorParts === []): ?>
                        <div class="rx-empty rx-empty--compact">
                            <div class="rx-empty-icon"><i class="bi bi-box-seam"></i></div>
                            <div class="rx-empty-title">Brak artykułów</div>
                            <div class="rx-empty-text">
                                Ten producent nie jest jeszcze przypisany do żadnego artykułu u&nbsp;dostawców.
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table rx-table mb-0" id="prVpTable">
                                <thead>
                                    <tr>
                                        <th class="pr-vp-col-id text-center" style="width: 70px;">ID</th>
                                        <th>Artykuł</th>
                                        <th>Dostawca</th>
                                        <th>JM</th>
                                        <th>Opak.</th>
                                        <th style="min-width: 180px;">Komentarz</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($vendorParts as $vp):
                                    $isActive = $vp->isActive === true || $vp->isActive === 1 || $vp->isActive === '1';
                                    $rowClass = $isActive ? '' : 'pr-vp-row--inactive';

                                    // Artykuł cell — part name + merged-or-separate
                                    // vendor/producer part numbers. Same shape as
                                    // vendor-parts-view.js renderRow().
                                    $artykulCell = htmlspecialchars($vp->partName ?? '', ENT_QUOTES);
                                    $vn = $vp->vendorPartNo ?? '';
                                    $pn = $vp->producerPartNo ?? '';
                                    if ($vn !== '' && $pn !== '' && $vn === $pn) {
                                        $artykulCell .= '<span class="pr-vp-sub">Nr dost./prod.: <span class="pr-vp-code">'
                                                      . htmlspecialchars($vn, ENT_QUOTES) . '</span></span>';
                                    } else {
                                        if ($vn !== '') {
                                            $artykulCell .= '<span class="pr-vp-sub">Nr dost.: <span class="pr-vp-code">'
                                                          . htmlspecialchars($vn, ENT_QUOTES) . '</span></span>';
                                        }
                                        if ($pn !== '') {
                                            $artykulCell .= '<span class="pr-vp-sub">Nr prod.: <span class="pr-vp-code">'
                                                          . htmlspecialchars($pn, ENT_QUOTES) . '</span></span>';
                                        }
                                    }

                                    // Dostawca cell — vendor name only. Sub-line
                                    // "Producent" is suppressed because we're
                                    // already on this producer's page (no point
                                    // repeating it on every row).
                                    $dostawcaCell = htmlspecialchars($vp->vendorName ?? '—', ENT_QUOTES);

                                    // Komentarz cell — same truncate-at-200 + title
                                    // tooltip as the listing.
                                    $COMMENT_TRUNCATE_AT = 200;
                                    $rawCmt = trim((string)($vp->comment ?? ''));
                                    if ($rawCmt === '') {
                                        $komentarzCell = '<span class="text-muted">—</span>';
                                    } elseif (mb_strlen($rawCmt) > $COMMENT_TRUNCATE_AT) {
                                        $visible = htmlspecialchars(mb_substr($rawCmt, 0, $COMMENT_TRUNCATE_AT), ENT_QUOTES) . '…';
                                        $komentarzCell = '<small class="pr-vp-comment" title="'
                                                       . htmlspecialchars($rawCmt, ENT_QUOTES) . '">'
                                                       . '<i class="bi bi-journal-text"></i> ' . $visible . '</small>';
                                    } else {
                                        $komentarzCell = '<small class="pr-vp-comment">'
                                                       . '<i class="bi bi-journal-text"></i> '
                                                       . htmlspecialchars($rawCmt, ENT_QUOTES) . '</small>';
                                    }
                                ?>
                                    <tr class="<?= $rowClass ?>">
                                        <td class="pr-vp-col-id text-center"><?= (int)$vp->id ?></td>
                                        <td><?= $artykulCell ?></td>
                                        <td><?= $dostawcaCell ?></td>
                                        <td><?= htmlspecialchars($vp->unitName ?? '—', ENT_QUOTES) ?></td>
                                        <td><?= pr_render_packs($vp->packQuantities) ?></td>
                                        <td><?= $komentarzCell ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" data-dismiss="modal">Zamknij</button>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

</div>

<script src="<?= asset('public_html/components/Admin/Purchase/Producers/edit/producer-edit-view.js') ?>"></script>
