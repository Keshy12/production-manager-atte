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
?>

<style>
    /* Filter card visual language — mirrors /vendor-parts so the
       operator's muscle memory carries over. alert-primary header +
       alert-info body keeps the card visibly grouped with the rest
       of the admin UI. */
    #prFilterCard .card-header {
        cursor: pointer;
    }
    #prFilterCard .card-header h6 {
        letter-spacing: 0.02em;
    }
    #prFilterCard .form-row + .form-row {
        border-top: 1px dashed rgba(0, 0, 0, 0.08);
        padding-top: 0.5rem;
        margin-top: 0.5rem;
    }
    /* Status segmented control: visually a button group but backed by
       radio inputs so it submits cleanly with form-encoded AJAX. */
    .pr-status-group .btn {
        font-size: 0.875rem;
    }
    .pr-status-group .btn input[type="radio"] {
        display: none;
    }
    /* Compact pagination — matches the archive / vendor-parts style. */
    .pr-pagination .text-muted {
        letter-spacing: 0.01em;
    }
    /* ID column tabular figures so digits line up across rows. */
    #prTable td.pr-col-id, #prTable th.pr-col-id {
        font-variant-numeric: tabular-nums;
    }
    /* Hide spinner once it finishes (Bootstrap 4 doesn't auto-hide
       spinner-border without .show / d-none toggles). */
    .pr-spinner[hidden] { display: none !important; }
    /* Inactive rows get the existing table-secondary treatment; we also
       dim the action button subtly so the status reads at a glance. */
    tr.pr-row--inactive {
        color: #6c757d;
    }
    tr.pr-row--inactive .btn-group .btn {
        opacity: 0.85;
    }
</style>

<div class="container-fluid w-75 mt-3">
    <div class="row">
        <div class="col-12 my-2">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-1">Producenci</h2>
                    <small class="text-muted">Zarządzaj katalogiem producentów komponentów.</small>
                </div>
                <a href="<?= 'http://' . BASEURL . '/admin/purchase/producers/edit' ?>"
                   class="btn btn-primary btn-sm">
                    <i class="bi bi-plus-circle"></i> Dodaj producenta
                </a>
            </div>
            <div id="alertContainer" class="mt-2"></div>
        </div>
    </div>

    <!-- Filter card (collapsible, mirrors /vendor-parts) -->
    <div class="card mt-3" id="prFilterCard">
        <div class="card-header alert-primary" data-toggle="collapse" data-target="#prFilterCollapse" aria-expanded="false">
            <div class="d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-funnel"></i> Filtry</h6>
                <i class="bi bi-chevron-down"></i>
            </div>
        </div>
        <div id="prFilterCollapse" class="collapse alert-info">
            <div class="card-body p-3">

                <!-- Status (segmented radio buttons) -->
                <div class="form-row mb-2">
                    <div class="col-md-10 col-12 mb-2">
                        <label class="small mb-1 d-block"><strong>Status:</strong></label>
                        <div class="btn-group btn-group-sm btn-group-toggle pr-status-group" data-toggle="buttons" role="group" aria-label="Status filtra">
                            <label class="btn btn-outline-primary" data-status="all">
                                <input type="radio" name="prFilterStatus" value="all"> Wszystkie
                            </label>
                            <label class="btn btn-outline-success active" data-status="active">
                                <input type="radio" name="prFilterStatus" value="active" checked> Aktywne
                            </label>
                            <label class="btn btn-outline-danger" data-status="inactive">
                                <input type="radio" name="prFilterStatus" value="inactive"> Nieaktywne
                            </label>
                        </div>
                    </div>
                    <div class="col-md-2 col-12 d-flex align-items-end">
                        <button type="button" id="prClearStatus"
                                class="btn btn-danger btn-sm btn-block mb-2">
                            Wyczyść
                        </button>
                    </div>
                </div>

                <!-- Szukaj -->
                <div class="form-row mb-2">
                    <div class="col-md-10 col-12 mb-2">
                        <label class="small mb-1" for="prFilterSearch"><strong>Szukaj (nazwa / komentarz):</strong></label>
                        <div class="input-group input-group-sm">
                            <div class="input-group-prepend">
                                <span class="input-group-text"><i class="bi bi-search"></i></span>
                            </div>
                            <input type="text" id="prFilterSearch" class="form-control"
                                   placeholder="np. STM…"
                                   autocomplete="off">
                        </div>
                    </div>
                    <div class="col-md-2 col-12 d-flex align-items-end">
                        <button type="button" id="prClearSearch"
                                class="btn btn-danger btn-sm btn-block mb-2">
                            Wyczyść
                        </button>
                    </div>
                </div>

                <!-- Tylko z artykułami -->
                <div class="form-row">
                    <div class="col-md-10 col-12 mb-2">
                        <label class="small mb-1 d-block"><strong>Tylko z artykułami u dostawców:</strong></label>
                        <div class="btn-group btn-group-sm btn-group-toggle pr-status-group" data-toggle="buttons" role="group" aria-label="Filtr artykułów">
                            <label class="btn btn-outline-primary active" data-hasarticles="all">
                                <input type="radio" name="prFilterHasArticles" value="all" checked> Wszystkie
                            </label>
                            <label class="btn btn-outline-primary" data-hasarticles="used">
                                <input type="radio" name="prFilterHasArticles" value="used"> Używane
                            </label>
                            <label class="btn btn-outline-primary" data-hasarticles="unused">
                                <input type="radio" name="prFilterHasArticles" value="unused"> nieużywane
                            </label>
                        </div>
                        <small class="form-text text-muted">
                            „Używane" = producent przypisany do co najmniej jednego artykułu u dostawcy.
                            „nieużywane" = brak przypisań (przydatne do porządków w katalogu).
                        </small>
                    </div>
                    <div class="col-md-2 col-12 d-flex align-items-end">
                        <button type="button" id="prClearHasArticles"
                                class="btn btn-danger btn-sm btn-block mb-2">
                            Wyczyść
                        </button>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <!-- Pagination top -->
    <div id="prPaginationTop" class="pr-pagination"></div>

    <!-- Loading spinner (centered, hidden by default) -->
    <div class="d-flex justify-content-center my-2">
        <div id="prSpinner" class="spinner-border text-primary pr-spinner" role="status" hidden>
            <span class="sr-only">Ładowanie...</span>
        </div>
    </div>

    <!-- Table -->
    <div class="table-responsive mt-3">
        <table class="table table-hover" id="prTable">
            <thead class="thead-light">
                <tr>
                    <th class="pr-col-id text-center" style="width: 70px;">ID</th>
                    <th>Nazwa</th>
                    <th style="min-width: 180px;">Komentarz</th>
                    <th class="text-center" style="width: 140px;">Liczba artykułów</th>
                    <th style="width: 110px;">Akcje</th>
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
    <div id="prPaginationBottom" class="pr-pagination mt-3"></div>
</div>

<script src="<?= asset('public_html/components/Admin/Purchase/Producers/producers-view.js') ?>"></script>