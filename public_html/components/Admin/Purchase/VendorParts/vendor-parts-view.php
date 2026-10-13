<?php
use Atte\DB\MsaDB;
use Atte\Utils\ComponentRenderer\SelectRenderer;

if(!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    header("Location: http://".BASEURL."/");
    exit();
}

$MsaDB = MsaDB::getInstance();
$selectRenderer = new SelectRenderer($MsaDB);

// Source lists for the filter pickers. Pulled from the same tables the
// LEFT JOINs in VendorPartRepository use, so the picker values always
// correspond to real FKs.
$vendor_list   = $MsaDB->readIdName('list__vendor',   'id', 'name', 'ORDER BY name ASC');
$producer_list = $MsaDB->readIdName('list__producer', 'id', 'name', 'ORDER BY name ASC');

// Część dropdown needs the description next to every part so each
// <option> can render a bootstrap-select `data-subtext` (the canonical
// second-line under each option label). `readIdName` only returns
// id+name, so fetch the trio explicitly.
$parts_rows = $MsaDB->db->query(
    "SELECT id, name, description FROM `list__parts` ORDER BY name ASC",
    \PDO::FETCH_ASSOC
)->fetchAll();
$part_names        = [];
$part_descriptions = [];
foreach ($parts_rows as $pr) {
    $id = (int)$pr['id'];
    $part_names[$id]        = $pr['name'];
    $part_descriptions[$id] = (string)($pr['description'] ?? '');
}

$vpAddUrl = 'http://' . BASEURL . '/admin/purchase/vendor-parts/edit';

include('table-row-template.php');
?>

<style>
    /* Filtry są schowane domyślnie, żeby lista była pierwszą rzeczą na
       stronie. Żeby schowany filtr nie był niewidoczny, nagłówek karty
       pokazuje licznik aktywnych filtrów, a strzałka obraca się po
       rozwinięciu. */
    #vpFilterCard .card-header {
        cursor: pointer;
    }
    #vpFilterCard .vp-chevron {
        transition: transform 0.15s ease;
    }
    #vpFilterCard .card-header[aria-expanded="true"] .vp-chevron {
        transform: rotate(180deg);
    }
    #vpFilterCard #vpFilterCount {
        font-weight: 600;
        vertical-align: middle;
    }
    /* min-width: 0 na kolumnach formularza — bez tego picker z długą
       opcją rozpycha kolumnę poza siatkę. */
    #vpFilterCard .form-row > [class*="col-"] {
        min-width: 0;
    }
    #vpFilterCard .bootstrap-select {
        min-width: 0;
    }
    /* Przełącznik statusu to grupa przycisków oparta na radiach, żeby
       dane szły zwykłym formularzem AJAX. Same radia są ukryte. */
    #vpFilterCard .vp-status-group .btn {
        font-size: 0.8rem;
    }
    #vpFilterCard .vp-status-group .btn input[type="radio"] {
        display: none;
    }

    /* Licznik przy nagłówku — plakietka, nie nawias w tekście. */
    .vp-count-badge {
        background-color: #e7f1ff;
        color: #0b5ed7;
        font-size: 0.8rem;
        font-weight: 600;
        vertical-align: middle;
        position: relative;
        top: -0.15rem;
    }

    /* Gęsty rytm tabel administracyjnych: lżejsze nagłówki, ciaśniejsze
       wiersze niż domyślny .table-sm. */
    #vpTable thead th {
        border-top: 0;
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: #6c757d;
        white-space: nowrap;
    }
    #vpTable tbody td {
        vertical-align: middle;
    }
    /* Akcent po lewej przy najechaniu — wiersz „podnosi się” bez
       rozpychania układu. */
    #vpTable tbody tr {
        transition: box-shadow 0.12s ease, background-color 0.12s ease;
    }
    #vpTable tbody tr:hover {
        box-shadow: inset 3px 0 0 #0d6efd;
    }
    /* ID w kolumnie z cyframi o stałej szerokości — łatwiej skanować
       listę wzrokiem niż przy proporcjonalnych cyfrach. */
    #vpTable td.vp-col-id, #vpTable th.vp-col-id {
        font-variant-numeric: tabular-nums;
        color: #adb5bd;
    }
    /* Numer części u dostawcy to to, po czym admin rozpoznaje wiersz —
       dlatego jest główną linią, w monospace (kody czyta się jako
       tokeny), jedna linia z wielokropkiem; pełna wartość w title="". */
    #vpTable .vp-code {
        display: block;
        max-width: 24rem;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        font-family: SFMono-Regular, Consolas, "Liberation Mono", Menlo, monospace;
        font-size: 0.85rem;
        font-weight: 600;
        color: #212529;
    }
    /* Numer producenta pokazujemy tylko gdy różni się od numeru
       dostawcy — jest wtedy drugim kodem, więc ciszej i mniej. */
    #vpTable .vp-code--sub {
        font-size: 0.75rem;
        font-weight: 400;
        color: #6c757d;
    }
    /* Nazwa części z katalogu to opis pomocniczy pod kodem. */
    #vpTable .vp-sub {
        display: block;
        max-width: 24rem;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        font-size: 0.8rem;
        color: #6c757d;
    }
    #vpTable .vp-name {
        font-weight: 600;
    }
    /* Ikona komentarza zamiast kolumny: komentarz to wolny tekst, który
       w większości wierszy jest pusty — kolumna marnowała szerokość.
       Pełna treść jest w title="". */
    #vpTable .vp-comment-icon {
        color: #adb5bd;
        margin-left: 0.3rem;
        font-size: 0.8rem;
        cursor: help;
    }
    /* Opakowania: małe, ciche plakietki z jednostką po nich — ilość
       bez jednostki nic nie znaczy, więc czytamy je razem. */
    #vpTable .vp-pack-badge {
        display: inline-block;
        font-size: 0.75rem;
        font-weight: 500;
        padding: 0.15rem 0.45rem;
        margin-right: 0.2rem;
        background: #e9ecef;
        color: #495057;
        border-radius: 0.25rem;
        line-height: 1.4;
        font-variant-numeric: tabular-nums;
    }
    #vpTable .vp-pack-unit {
        font-size: 0.8rem;
        color: #6c757d;
    }
    #vpTable .vp-none {
        color: #adb5bd;
    }
    /* Wyszarzone wiersze nieaktywnych artykułów — ten sam język
       wizualny co lista dostawców i szablonów wiadomości. */
    #vpTable tr.vp-row--inactive {
        color: #6c757d;
    }
    #vpTable tr.vp-row--inactive .vp-name {
        font-weight: 500;
    }
    #vpTable tr.vp-row--inactive .vp-code {
        font-weight: 500;
        color: #adb5bd;
    }
    #vpTable tr.vp-row--inactive .vp-pack-badge {
        opacity: 0.6;
    }
    /* Nieaktywny = cichy, nie alarmujący. Stonowana plakietka zamiast
       czerwieni, która w tej tabeli znaczyłaby „błąd”. */
    #vpTable .vp-badge-off {
        background-color: #e9ecef;
        color: #6c757d;
        font-weight: 500;
    }

    /* Pusty stan: świadomie duży i spokojny — to jedyna treść na
       stronie, gdy nic nie pasuje do filtrów. */
    #vpEmpty {
        border: 1px dashed #dee2e6;
        border-radius: 0.35rem;
        background-color: #fbfbfc;
        padding: 3rem 1rem;
        text-align: center;
    }
    #vpEmpty .bi-box-seam {
        font-size: 2.25rem;
        color: #ced4da;
        display: block;
        margin-bottom: 0.75rem;
    }
    #vpEmpty p {
        max-width: 32rem;
        margin-left: auto;
        margin-right: auto;
    }
    /* Spinner chowany atrybutem hidden (Bootstrap 4 sam tego nie robi). */
    #vpSpinner[hidden] { display: none !important; }
</style>

<div class="container-fluid w-75 mt-3">
    <div class="row">
        <div class="col-12 my-2">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-1">
                        Artykuły u dostawców
                        <span class="badge badge-pill vp-count-badge ml-1" id="vpCountBadge">…</span>
                    </h2>
                    <small class="text-muted">Który dostawca sprzedaje który artykuł, od jakiego producenta i w jakich opakowaniach.</small>
                </div>
                <a href="<?= htmlspecialchars($vpAddUrl, ENT_QUOTES) ?>"
                   class="btn btn-primary btn-sm">
                    <i class="bi bi-plus-circle"></i> Dodaj artykuł
                </a>
            </div>
            <div id="alertContainer" class="mt-2"></div>
        </div>
    </div>

    <!-- Filtry (zwijane, jak w /archive) -->
    <div class="card mt-4 mb-3" id="vpFilterCard">
        <div class="card-header alert-primary" data-toggle="collapse" data-target="#vpFilterCollapse" aria-expanded="false" aria-controls="vpFilterCollapse">
            <div class="d-flex justify-content-between align-items-center">
                <h6 class="mb-0">
                    <i class="bi bi-funnel"></i> Filtry
                    <span class="badge badge-pill badge-primary ml-2" id="vpFilterCount" title="Liczba aktywnych filtrów" hidden>0</span>
                </h6>
                <i class="bi bi-chevron-down vp-chevron"></i>
            </div>
        </div>
        <div id="vpFilterCollapse" class="collapse alert-info">
            <div class="card-body p-3">

                <!-- Dostawca / Producent / Część -->
                <div class="form-row mb-2">
                    <div class="col-md-4 col-12 mb-2" id="vpVendorWrap">
                        <label class="small mb-1" for="vpFilterVendors"><strong>Dostawca:</strong></label>
                        <select id="vpFilterVendors" class="selectpicker form-control form-control-sm"
                                data-width="100%"
                                data-live-search="true"
                                data-size="10"
                                data-container="#vpVendorWrap"
                                title="Wybierz dostawcę...">
                            <?= $selectRenderer->renderArraySelect($vendor_list) ?>
                        </select>
                    </div>
                    <div class="col-md-4 col-12 mb-2" id="vpProducerWrap">
                        <label class="small mb-1" for="vpFilterProducers"><strong>Producent:</strong></label>
                        <select id="vpFilterProducers" class="selectpicker form-control form-control-sm"
                                data-width="100%"
                                data-live-search="true"
                                data-size="10"
                                data-container="#vpProducerWrap"
                                title="Wybierz producenta...">
                            <?= $selectRenderer->renderArraySelect($producer_list) ?>
                        </select>
                    </div>
                    <div class="col-md-4 col-12 mb-2" id="vpPartWrap">
                        <label class="small mb-1" for="vpFilterParts"><strong>Część:</strong></label>
                        <select id="vpFilterParts" class="selectpicker form-control form-control-sm"
                                data-width="100%"
                                data-live-search="true"
                                data-size="10"
                                data-container="#vpPartWrap"
                                title="Wybierz część...">
                            <?= $selectRenderer->renderArraySelectWithSubtext($part_names, $part_descriptions) ?>
                        </select>
                    </div>
                </div>

                <hr class="my-2">

                <!-- Szukaj / Status / Data dodania -->
                <div class="form-row mb-2">
                    <div class="col-md-5 col-12 mb-2">
                        <label class="small mb-1" for="vpFilterSearch"><strong>Szukaj (numer części / producent):</strong></label>
                        <div class="input-group input-group-sm">
                            <div class="input-group-prepend">
                                <span class="input-group-text"><i class="bi bi-search"></i></span>
                            </div>
                            <input type="text" id="vpFilterSearch" class="form-control"
                                   placeholder="np. STM32F103…"
                                   autocomplete="off">
                        </div>
                    </div>
                    <div class="col-md-3 col-12 mb-2">
                        <label class="small mb-1 d-block"><strong>Status:</strong></label>
                        <div class="btn-group btn-group-sm btn-group-toggle vp-status-group" data-toggle="buttons" role="group" aria-label="Status filtra">
                            <label class="btn btn-outline-secondary" data-status="all">
                                <input type="radio" name="vpFilterStatus" value="all"> Wszystkie
                            </label>
                            <label class="btn btn-outline-secondary active" data-status="active">
                                <input type="radio" name="vpFilterStatus" value="active" checked> Aktywne
                            </label>
                            <label class="btn btn-outline-secondary" data-status="inactive">
                                <input type="radio" name="vpFilterStatus" value="inactive"> Nieaktywne
                            </label>
                        </div>
                    </div>
                    <div class="col-md-2 col-6 mb-2">
                        <label class="small mb-1" for="vpFilterDateFrom"><strong>Dodano od:</strong></label>
                        <input type="date" id="vpFilterDateFrom" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-2 col-6 mb-2">
                        <label class="small mb-1" for="vpFilterDateTo"><strong>do:</strong></label>
                        <input type="date" id="vpFilterDateTo" class="form-control form-control-sm">
                    </div>
                </div>

                <div class="d-flex justify-content-end">
                    <button type="button" id="vpClearAll" class="btn btn-danger btn-sm">
                        Wyczyść
                    </button>
                </div>

            </div>
        </div>
    </div>

    <!-- Pagination top -->
    <div id="vpPaginationTop"></div>

    <!-- Loading spinner (centered, hidden by default) -->
    <div class="d-flex justify-content-center my-2">
        <div id="vpSpinner" class="spinner-border text-primary" role="status" hidden>
            <span class="sr-only">Ładowanie...</span>
        </div>
    </div>

    <!-- Pusty stan (wypełniany i pokazywany z JS) -->
    <div id="vpEmpty" class="mt-3" hidden>
        <i class="bi bi-box-seam" aria-hidden="true"></i>
        <p class="mb-3 text-muted" id="vpEmptyText"></p>
        <a href="<?= htmlspecialchars($vpAddUrl, ENT_QUOTES) ?>" class="btn btn-primary btn-sm" id="vpEmptyCta">
            <i class="bi bi-plus-circle"></i> Dodaj artykuł
        </a>
    </div>

    <!-- Table -->
    <div class="table-responsive mt-2" id="vpTableWrap">
        <table class="table table-sm table-hover" id="vpTable">
            <thead class="thead-light">
                <tr>
                    <th class="vp-col-id text-center" style="width: 56px;">ID</th>
                    <th style="min-width: 240px;">Artykuł</th>
                    <th style="min-width: 160px;">Dostawca / Producent</th>
                    <th style="min-width: 140px;">Opakowania</th>
                    <th class="text-center" style="width: 104px;">Status</th>
                    <th class="text-center" style="width: 64px;">Akcje</th>
                </tr>
            </thead>
            <tbody id="vpTableBody">
                <tr>
                    <td colspan="6" class="text-center text-muted py-4">
                        Ładowanie…
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Pagination bottom -->
    <div id="vpPaginationBottom" class="mt-3"></div>
</div>

<script src="<?= asset('public_html/components/Admin/Purchase/VendorParts/vendor-parts-view.js') ?>"></script>
