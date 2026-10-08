<?php
use Atte\DB\MsaDB;

if(!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    header("Location: http://".BASEURL."/");
    exit();
}

// MsaDB is required by the new AJAX endpoint shape; instantiated here
// so the page-load path stays symmetric with vendor-parts / producers
// (even though the listing is now fully AJAX-driven and reads no DB
// on initial render).
$MsaDB = MsaDB::getInstance();

include('edit/table-row-template.php');

$vrAddUrl = 'http://' . BASEURL . '/admin/purchase/vendors/edit';
?>

<style>
    /* Gęsty rytm tabel administracyjnych — ten sam co na liście
       szablonów wiadomości: lekkie, wersalikowe nagłówki, żeby dane
       w wierszach były ważniejsze niż etykiety kolumn. */
    #vrTable thead th {
        border-top: 0;
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: #6c757d;
        white-space: nowrap;
    }
    #vrTable tbody td {
        vertical-align: middle;
    }
    /* Akcent po lewej przy najechaniu — wiersz „podnosi się” bez
       rozpychania układu. */
    #vrTable tbody tr {
        transition: box-shadow 0.12s ease, background-color 0.12s ease;
    }
    #vrTable tbody tr:hover {
        box-shadow: inset 3px 0 0 #0d6efd;
    }
    /* ID blade, w cyfrach o stałej szerokości: to identyfikator
       techniczny, nie treść, do której oko ma wracać. */
    #vrTable td.vr-col-id, #vrTable th.vr-col-id {
        font-variant-numeric: tabular-nums;
        color: #adb5bd;
    }
    #vrTable td.vr-col-num {
        font-variant-numeric: tabular-nums;
        white-space: nowrap;
    }
    #vrTable .vr-name {
        font-weight: 600;
    }
    /* Adres to długi tekst swobodny, więc ląduje w drugiej, ściszonej
       linii pod nazwą — wiersz zostaje niski, a nazwa nadal jest
       pierwszą rzeczą, którą widać. Jedna linia z wielokropkiem; pełna
       treść w title="". */
    #vrTable .vr-sub {
        display: block;
        max-width: 28rem;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        font-size: 0.78rem;
        font-weight: 400;
        color: #6c757d;
    }
    /* Ikona komentarza zamiast samego tekstu: komentarz bywa długi,
       a w liście wystarczy wiedzieć, że istnieje (treść w title). */
    #vrTable .vr-note {
        color: #adb5bd;
        margin-left: 0.25rem;
        cursor: help;
    }
    /* Powiązania w jednej komórce: dwie liczby z ikonami czytają się
       jak „ile osób / ile artykułów”, bez dwóch osobnych kolumn. Zero
       jest przygaszone, żeby dostawcy bez powiązań odstawali. */
    #vrTable .vr-links > span {
        display: inline-block;
        margin: 0 0.35rem;
        cursor: help;
    }
    #vrTable .vr-links .bi {
        color: #adb5bd;
        margin-right: 0.15rem;
    }
    #vrTable .vr-links .vr-zero {
        color: #ced4da;
    }
    /* Wyszarzone wiersze nieaktywnych dostawców — ten sam język
       wizualny co lista szablonów i artykułów u dostawców. */
    #vrTable tr.vr-row--inactive {
        color: #6c757d;
    }
    #vrTable tr.vr-row--inactive .vr-name {
        font-weight: 500;
    }
    #vrTable tr.vr-row--inactive .vr-sub {
        color: #adb5bd;
    }
    /* Nieaktywny = cichy, nie alarmujący. Stonowana plakietka zamiast
       czerwieni, która w tej tabeli znaczyłaby „błąd”. */
    #vrTable .vr-badge-off {
        background-color: #e9ecef;
        color: #6c757d;
        font-weight: 500;
    }
    /* Licznik przy nagłówku — plakietka, nie nawias w tekście. */
    .vr-count-badge {
        background-color: #e7f1ff;
        color: #0b5ed7;
        font-size: 0.8rem;
        font-weight: 600;
        vertical-align: middle;
        position: relative;
        top: -0.15rem;
    }
    /* Karta filtrów jest domyślnie zwinięta, więc to, że coś jest
       włączone, musi być widać na nagłówku — inaczej lista wygląda na
       „pustą” bez wyjaśnienia. */
    #vrFilterCard .card-header {
        cursor: pointer;
    }
    #vrFilterCard .vr-chevron {
        transition: transform 0.15s ease;
    }
    #vrFilterCard .card-header:not(.collapsed) .vr-chevron {
        transform: rotate(180deg);
    }
    #vrFilterCard .btn-group-toggle .btn input[type="radio"] {
        display: none;
    }
    /* Pusty stan: świadomie duży i spokojny — gdy lista jest pusta,
       to jedyna treść pod filtrami. */
    .vr-empty {
        border: 1px dashed #dee2e6;
        border-radius: 0.35rem;
        background-color: #fbfbfc;
        padding: 3rem 1rem;
        text-align: center;
    }
    .vr-empty .bi-truck {
        font-size: 2.25rem;
        color: #ced4da;
        display: block;
        margin-bottom: 0.75rem;
    }
    .vr-empty p {
        max-width: 32rem;
        margin-left: auto;
        margin-right: auto;
    }
    /* Spinner nie zajmuje miejsca, gdy się nie kręci (Bootstrap 4 nie
       ukrywa go sam). */
    .vr-spinner[hidden] { display: none !important; }
    .vr-spinner {
        width: 1rem;
        height: 1rem;
        border-width: 0.15rem;
    }
</style>

<div class="container-fluid w-75 mt-3">
    <div class="row">
        <div class="col-12 my-2">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-1">
                        Dostawcy
                        <span class="badge badge-pill vr-count-badge ml-1" id="vrCountBadge" hidden>0</span>
                    </h2>
                    <small class="text-muted">
                        Katalog dostawców z osobami kontaktowymi i&nbsp;powiązaniami z artykułami.
                    </small>
                </div>
                <a href="<?= htmlspecialchars($vrAddUrl, ENT_QUOTES) ?>"
                   class="btn btn-primary btn-sm">
                    <i class="bi bi-plus-circle"></i> Dodaj dostawcę
                </a>
            </div>
            <div id="alertContainer" class="mt-2"></div>
        </div>
    </div>

    <!-- Filtry (zwijana karta, jak w /archive) -->
    <div class="card mt-4 mb-3" id="vrFilterCard">
        <div class="card-header alert-primary collapsed" data-toggle="collapse" data-target="#vrFilterCollapse" aria-expanded="false" aria-controls="vrFilterCollapse">
            <div class="d-flex justify-content-between align-items-center">
                <h6 class="mb-0">
                    <i class="bi bi-funnel"></i> Filtry
                    <span class="badge badge-pill badge-primary ml-2" id="vrFilterPill" hidden
                          title="Liczba włączonych filtrów">0</span>
                </h6>
                <i class="bi bi-chevron-down vr-chevron"></i>
            </div>
        </div>
        <div id="vrFilterCollapse" class="collapse alert-info">
            <div class="card-body p-3">

                <div class="form-row mb-2">
                    <div class="col-md-4 col-12 mb-2">
                        <label class="small mb-1 d-block"><strong>Status:</strong></label>
                        <div class="btn-group btn-group-sm btn-group-toggle" data-toggle="buttons" role="group" aria-label="Status filtra">
                            <label class="btn btn-outline-primary" data-status="all">
                                <input type="radio" name="vrFilterStatus" value="all"> Wszystkie
                            </label>
                            <label class="btn btn-outline-primary active" data-status="active">
                                <input type="radio" name="vrFilterStatus" value="active" checked> Aktywne
                            </label>
                            <label class="btn btn-outline-primary" data-status="inactive">
                                <input type="radio" name="vrFilterStatus" value="inactive"> Nieaktywne
                            </label>
                        </div>
                    </div>
                    <div class="col-md-4 col-12 mb-2">
                        <label class="small mb-1 d-block"><strong>Artykuły u dostawcy:</strong></label>
                        <div class="btn-group btn-group-sm btn-group-toggle" data-toggle="buttons" role="group" aria-label="Filtr artykułów">
                            <label class="btn btn-outline-primary active" data-hasarticles="all">
                                <input type="radio" name="vrFilterHasArticles" value="all" checked> Wszystkie
                            </label>
                            <label class="btn btn-outline-primary" data-hasarticles="used"
                                   title="Dostawca przypisany do co najmniej jednego artykułu">
                                <input type="radio" name="vrFilterHasArticles" value="used"> Używane
                            </label>
                            <label class="btn btn-outline-primary" data-hasarticles="unused"
                                   title="Brak przypisań — przydatne do porządków w katalogu">
                                <input type="radio" name="vrFilterHasArticles" value="unused"> Nieużywane
                            </label>
                        </div>
                    </div>
                    <div class="col-md-4 col-12 mb-2">
                        <label class="small mb-1 d-block"><strong>Osoby kontaktowe:</strong></label>
                        <div class="btn-group btn-group-sm btn-group-toggle" data-toggle="buttons" role="group" aria-label="Filtr osób kontaktowych">
                            <label class="btn btn-outline-primary active" data-hassuppliers="all">
                                <input type="radio" name="vrFilterHasSuppliers" value="all" checked> Wszystkie
                            </label>
                            <label class="btn btn-outline-primary" data-hassuppliers="used"
                                   title="Dostawca ma co najmniej jedną osobę kontaktową">
                                <input type="radio" name="vrFilterHasSuppliers" value="used"> Z osobami
                            </label>
                            <label class="btn btn-outline-primary" data-hassuppliers="unused"
                                   title="Brak przypisanych osób kontaktowych">
                                <input type="radio" name="vrFilterHasSuppliers" value="unused"> Bez osób
                            </label>
                        </div>
                    </div>
                </div>

                <hr class="my-2">

                <div class="form-row">
                    <div class="col-md-4 col-12 mb-2">
                        <label class="small mb-1" for="vrFilterSearch"><strong>Szukaj (nazwa / adres / komentarz):</strong></label>
                        <div class="input-group input-group-sm">
                            <div class="input-group-prepend">
                                <span class="input-group-text"><i class="bi bi-search"></i></span>
                            </div>
                            <input type="text" id="vrFilterSearch" class="form-control"
                                   placeholder="np. TME…" autocomplete="off">
                        </div>
                    </div>
                    <div class="col-md-3 col-12 mb-2">
                        <label class="small mb-1" for="vrFilterDateFrom"><strong>Data dodania — od:</strong></label>
                        <input type="date" id="vrFilterDateFrom" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-3 col-12 mb-2">
                        <label class="small mb-1" for="vrFilterDateTo"><strong>Data dodania — do:</strong></label>
                        <input type="date" id="vrFilterDateTo" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-2 col-12 d-flex align-items-end">
                        <button type="button" id="vrClearAll" class="btn btn-danger btn-sm btn-block mb-2"
                                title="Przywróć domyślne filtry (tylko aktywni dostawcy)">
                            Wyczyść
                        </button>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <!-- Wiersz podsumowania: zakres wyników po prawej, jak licznik na liście szablonów -->
    <div class="d-flex justify-content-end align-items-center" style="min-height: 1.5rem;">
        <div id="vrSpinner" class="spinner-border text-primary vr-spinner mr-2" role="status" hidden>
            <span class="sr-only">Ładowanie...</span>
        </div>
        <small class="text-muted" id="vrSummary"></small>
    </div>

    <!-- Pusty stan (pokazywany przez JS zamiast tabeli) -->
    <div id="vrEmpty" class="vr-empty mt-3" hidden>
        <i class="bi bi-truck"></i>
        <p class="mb-3 text-muted" id="vrEmptyText"></p>
        <a href="<?= htmlspecialchars($vrAddUrl, ENT_QUOTES) ?>" id="vrEmptyAdd" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-circle"></i> Dodaj dostawcę
        </a>
        <button type="button" id="vrEmptyClear" class="btn btn-outline-secondary btn-sm" hidden>
            <i class="bi bi-x-circle"></i> Wyczyść filtry
        </button>
    </div>

    <!-- Tabela -->
    <div class="table-responsive mt-1" id="vrTableWrap">
        <table class="table table-sm table-hover" id="vrTable">
            <thead class="thead-light">
                <tr>
                    <th class="vr-col-id text-center" style="width: 56px;">ID</th>
                    <th style="min-width: 240px;">Dostawca</th>
                    <th class="text-center" style="width: 96px;">Lead&nbsp;time</th>
                    <th class="text-center" style="width: 140px;">Powiązania</th>
                    <th class="text-center" style="width: 104px;">Status</th>
                    <th class="text-center" style="width: 64px;">Akcje</th>
                </tr>
            </thead>
            <tbody id="vrTableBody">
                <tr>
                    <td colspan="6" class="text-center text-muted py-4">
                        Ładowanie…
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Paginacja -->
    <div id="vrPaginationBottom" class="vr-pagination mt-2"></div>
</div>

<script src="<?= asset('public_html/components/Admin/Purchase/Vendors/vendors-view.js') ?>"></script>
