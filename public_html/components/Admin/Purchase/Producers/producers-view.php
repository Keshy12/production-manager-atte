<?php
use Atte\DB\MsaDB;

if(!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    header("Location: http://".BASEURL."/");
    exit();
}

// MsaDB is required by the new AJAX endpoint shape; instantiated here
// so the page-load path stays symmetric with vendor-parts (even though
// the listing is now fully AJAX-driven and reads no DB on initial render).
$MsaDB = MsaDB::getInstance();

include('edit/table-row-template.php');

$prAddUrl = 'http://' . BASEURL . '/admin/purchase/producers/edit';
?>

<style>
    /* Filtry są domyślnie zwinięte (jak w archiwum), więc sam nagłówek
       karty musi mówić, że coś jest ustawione: licznik zmienionych
       filtrów obok tytułu i strzałka, która obraca się po rozwinięciu. */
    #prFilterCard .card-header {
        cursor: pointer;
    }
    #prFilterCard .pr-chevron {
        transition: transform 0.2s ease;
    }
    #prFilterCard .card-header[aria-expanded="true"] .pr-chevron {
        transform: rotate(180deg);
    }
    #prFilterPill {
        font-size: 0.75rem;
        font-weight: 600;
        vertical-align: middle;
    }
    /* Przełączniki oparte o radio — wyglądają jak grupa przycisków,
       a nadal przechodzą przez ten sam kod JS. */
    #prFilterCard .btn-group-toggle .btn input[type="radio"] {
        display: none;
    }
    /* Spinner ukrywany atrybutem hidden — Bootstrap 4 sam tego nie robi. */
    #prSpinner[hidden] { display: none !important; }

    /* ID w kolumnie z cyframi o stałej szerokości — łatwiej skanować
       listę wzrokiem niż przy proporcjonalnych cyfrach. */
    #prTable td.pr-col-id, #prTable th.pr-col-id {
        font-variant-numeric: tabular-nums;
        color: #adb5bd;
    }
    /* Gęsty rytm tabel administracyjnych: lżejsze nagłówki, ciaśniejsze
       wiersze niż domyślny .table-sm. */
    #prTable thead th {
        border-top: 0;
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: #6c757d;
        white-space: nowrap;
    }
    #prTable tbody td {
        vertical-align: middle;
    }
    /* Akcent po lewej przy najechaniu — wiersz „podnosi się” bez
       rozpychania układu. */
    #prTable tbody tr {
        transition: box-shadow 0.12s ease, background-color 0.12s ease;
    }
    #prTable tbody tr:hover {
        box-shadow: inset 3px 0 0 #0d6efd;
    }
    #prTable .pr-name {
        display: block;
        font-weight: 600;
    }
    /* Komentarz nie ma własnej kolumny — to dopisek pod nazwą, który
       tylko podpowiada, o którego producenta chodzi. Jedna linia
       z wielokropkiem, żeby długa notatka nie rozpychała tabeli; pełna
       treść siedzi w title="". */
    #prTable .pr-note {
        display: block;
        max-width: 36rem;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        font-size: 0.78rem;
        color: #6c757d;
    }
    /* Liczba artykułów jako cyfry o stałej szerokości; zero pokazujemy
       jako myślnik, żeby nie wyglądało jak ostrzeżenie. */
    #prTable .pr-col-count {
        font-variant-numeric: tabular-nums;
    }
    /* Wyszarzone wiersze nieaktywnych producentów — ten sam język
       wizualny co szablony wiadomości i lista dostawców. */
    #prTable tr.pr-row--inactive {
        color: #6c757d;
    }
    #prTable tr.pr-row--inactive .pr-name {
        font-weight: 500;
    }
    #prTable tr.pr-row--inactive .pr-note {
        color: #adb5bd;
    }
    /* Nieaktywny = cichy, nie alarmujący. Stonowana plakietka zamiast
       czerwieni, która w tej tabeli znaczyłaby „błąd”. */
    #prTable .pr-badge-off {
        background-color: #e9ecef;
        color: #6c757d;
        font-weight: 500;
    }
    /* Licznik przy nagłówku — plakietka, nie nawias w tekście. */
    #prCountBadge {
        background-color: #e7f1ff;
        color: #0b5ed7;
        font-size: 0.8rem;
        font-weight: 600;
        vertical-align: middle;
        position: relative;
        top: -0.15rem;
    }
    /* Paginacja: mała i cicha, żeby nie konkurowała z tabelą. */
    #prPaginationTop .text-muted,
    #prPaginationBottom .text-muted {
        letter-spacing: 0.01em;
    }
    /* Pusty stan: świadomie duży i spokojny — to jedyna treść pod
       kartą filtrów, gdy nic nie pasuje. */
    #prEmpty {
        border: 1px dashed #dee2e6;
        border-radius: 0.35rem;
        background-color: #fbfbfc;
        padding: 3rem 1rem;
        text-align: center;
    }
    #prEmpty > .bi {
        font-size: 2.25rem;
        color: #ced4da;
        display: block;
        margin-bottom: 0.75rem;
    }
    #prEmpty p {
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
                        Producenci
                        <span class="badge badge-pill ml-1" id="prCountBadge" hidden
                              title="Liczba producentów spełniających filtry">0</span>
                    </h2>
                    <small class="text-muted">Katalog producentów komponentów, do których przypisujesz artykuły u&nbsp;dostawców.</small>
                </div>
                <a href="<?= htmlspecialchars($prAddUrl, ENT_QUOTES) ?>"
                   class="btn btn-primary btn-sm">
                    <i class="bi bi-plus-circle"></i> Dodaj producenta
                </a>
            </div>
            <div id="alertContainer" class="mt-2"></div>
        </div>
    </div>

    <!-- Filtry (zwinięte domyślnie, jak w archiwum) -->
    <div class="card mt-4 mb-3" id="prFilterCard">
        <div class="card-header alert-primary" data-toggle="collapse" data-target="#prFilterCollapse"
             aria-expanded="false" aria-controls="prFilterCollapse">
            <div class="d-flex justify-content-between align-items-center">
                <h6 class="mb-0">
                    <i class="bi bi-funnel"></i> Filtry
                    <span class="badge badge-pill badge-primary ml-1" id="prFilterPill" hidden
                          title="Liczba filtrów innych niż domyślne">0</span>
                </h6>
                <i class="bi bi-chevron-down pr-chevron"></i>
            </div>
        </div>
        <div id="prFilterCollapse" class="collapse alert-info">
            <div class="card-body p-3">

                <div class="form-row mb-2">
                    <div class="col-md-10 col-12 mb-2">
                        <label class="small mb-1" for="prFilterSearch"><strong>Szukaj (nazwa / komentarz):</strong></label>
                        <div class="input-group input-group-sm">
                            <div class="input-group-prepend">
                                <span class="input-group-text"><i class="bi bi-search"></i></span>
                            </div>
                            <input type="text" id="prFilterSearch" class="form-control form-control-sm"
                                   placeholder="np. STM…" autocomplete="off">
                        </div>
                    </div>
                    <div class="col-md-2 col-12 d-flex align-items-end">
                        <button type="button" id="prClearFilters" class="btn btn-danger btn-sm btn-block mb-2">
                            Wyczyść
                        </button>
                    </div>
                </div>

                <hr class="my-2">

                <div class="form-row">
                    <div class="col-md-5 col-12 mb-2">
                        <label class="small mb-1 d-block"><strong>Status:</strong></label>
                        <div class="btn-group btn-group-sm btn-group-toggle" data-toggle="buttons" role="group" aria-label="Status filtra">
                            <label class="btn btn-outline-primary" data-status="all">
                                <input type="radio" name="prFilterStatus" value="all"> Wszystkie
                            </label>
                            <label class="btn btn-outline-success active" data-status="active">
                                <input type="radio" name="prFilterStatus" value="active" checked> Aktywne
                            </label>
                            <label class="btn btn-outline-secondary" data-status="inactive">
                                <input type="radio" name="prFilterStatus" value="inactive"> Nieaktywne
                            </label>
                        </div>
                    </div>
                    <div class="col-md-7 col-12 mb-2">
                        <label class="small mb-1 d-block"><strong>Artykuły u dostawców:</strong></label>
                        <div class="btn-group btn-group-sm btn-group-toggle" data-toggle="buttons" role="group" aria-label="Filtr artykułów">
                            <label class="btn btn-outline-primary active" data-hasarticles="all">
                                <input type="radio" name="prFilterHasArticles" value="all" checked> Wszystkie
                            </label>
                            <label class="btn btn-outline-primary" data-hasarticles="used">
                                <input type="radio" name="prFilterHasArticles" value="used"> Używane
                            </label>
                            <label class="btn btn-outline-primary" data-hasarticles="unused">
                                <input type="radio" name="prFilterHasArticles" value="unused"> Nieużywane
                            </label>
                        </div>
                        <small class="form-text text-muted">
                            „Używane” = przypisany do co najmniej jednego artykułu u dostawcy.
                            „Nieużywane” = brak przypisań (przydatne do porządków w katalogu).
                        </small>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <!-- Podsumowanie nad tabelą -->
    <div class="d-flex justify-content-end align-items-center">
        <small class="text-muted" id="prSummary">
            Wyniki: <strong id="prSummaryCount">0</strong>
        </small>
    </div>

    <!-- Pagination top -->
    <div id="prPaginationTop"></div>

    <!-- Loading spinner (centered, hidden by default) -->
    <div class="d-flex justify-content-center my-2">
        <div id="prSpinner" class="spinner-border text-primary" role="status" hidden>
            <span class="sr-only">Ładowanie...</span>
        </div>
    </div>

    <!-- Pusty stan (wypełniany i pokazywany przez JS) -->
    <div id="prEmpty" class="mt-1" hidden></div>

    <!-- Table -->
    <div class="table-responsive mt-1" id="prTableWrap">
        <table class="table table-sm table-hover" id="prTable">
            <thead class="thead-light">
                <tr>
                    <th class="pr-col-id text-center" style="width: 56px;">ID</th>
                    <th style="min-width: 240px;">Producent</th>
                    <th class="text-center" style="width: 100px;">Artykuły</th>
                    <th class="text-center" style="width: 104px;">Status</th>
                    <th class="text-center" style="width: 64px;">Akcje</th>
                </tr>
            </thead>
            <tbody id="prTableBody">
                <tr>
                    <td colspan="5" class="text-center text-muted py-4">
                        Ładowanie…
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Pagination bottom -->
    <div id="prPaginationBottom" class="mt-3"></div>
</div>

<script>
    // Adres strony dodawania — używany w pustym stanie renderowanym przez JS.
    window.PR_ADD_URL = <?= json_encode($prAddUrl) ?>;
</script>
<script src="<?= asset('public_html/components/Admin/Purchase/Producers/producers-view.js') ?>"></script>
