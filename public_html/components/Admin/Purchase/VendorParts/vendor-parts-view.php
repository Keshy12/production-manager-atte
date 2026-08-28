<?php
use Atte\DB\MsaDB;
use Atte\Utils\ComponentRenderer\SelectRenderer;

if(!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    header("Location: http://".BASEURL."/");
    exit();
}

$MsaDB = MsaDB::getInstance();
$selectRenderer = new SelectRenderer($MsaDB);

// Source lists for the filter card multi-selects. Pulled from the same
// tables the LEFT JOINs in VendorPartRepository use, so the picker values
// always correspond to real FKs.
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

include('table-row-template.php');
?>

<style>
    /* Filter card visual language — mirrors /archive so the operator's
       muscle memory carries over. alert-primary header + alert-info body
       keeps the card visibly grouped with the rest of the admin UI. */
    #vpFilterCard .card-header {
        cursor: pointer;
    }
    #vpFilterCard .card-header h6 {
        letter-spacing: 0.02em;
    }
    #vpFilterCard .form-row + .form-row {
        border-top: 1px dashed rgba(0, 0, 0, 0.08);
        padding-top: 0.5rem;
        margin-top: 0.5rem;
    }
    /* Status segmented control: visually a button group but backed by
       radio inputs so it submits cleanly with form-encoded AJAX. */
    .vp-status-group .btn {
        font-size: 0.875rem;
    }
    .vp-status-group .btn input[type="radio"] {
        display: none;
    }
    /* Packs cell: small inline badges, muted, comma-separated. Empty when
       the row has no packs. */
    .vp-pack-badge {
        display: inline-block;
        font-size: 0.75rem;
        font-weight: 500;
        padding: 0.15rem 0.45rem;
        margin-right: 0.2rem;
        margin-bottom: 0.15rem;
        background: #e9ecef;
        color: #495057;
        border-radius: 0.25rem;
        line-height: 1.4;
    }
    .vp-pack-badge--empty {
        background: transparent;
        color: #adb5bd;
    }
    /* Inactive rows get the existing table-secondary treatment; we also
       dim the action buttons subtly so the status reads at a glance. */
    tr.vp-row--inactive {
        color: #6c757d;
    }
    tr.vp-row--inactive .btn-group .btn {
        opacity: 0.85;
    }
    /* Compact pagination — matches the archive style. */
    .vp-pagination .text-muted {
        letter-spacing: 0.01em;
    }
    /* ID column tabular figures so digits line up across rows. */
    #vpTable td.vp-col-id, #vpTable th.vp-col-id {
        font-variant-numeric: tabular-nums;
    }
    /* Hide spinner once it finishes (Bootstrap 4 doesn't auto-hide
       spinner-border without .show / d-none toggles). */
    .vp-spinner[hidden] { display: none !important; }
</style>

<div class="container-fluid w-75 mt-3">
    <div class="row">
        <div class="col-12 my-2">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-1">Artykuły u dostawców</h2>
                    <small class="text-muted">Zarządzaj mapowaniami vendor → produkt i pełnymi opakowaniami.</small>
                </div>
                <a href="<?= 'http://' . BASEURL . '/admin/purchase/vendor-parts/edit' ?>"
                   class="btn btn-primary btn-sm">
                    <i class="bi bi-plus-circle"></i> Dodaj artykuł
                </a>
            </div>
            <div id="alertContainer" class="mt-2"></div>
        </div>
    </div>

    <!-- Filter card (collapsible, mirrors /archive) -->
    <div class="card mt-3" id="vpFilterCard">
        <div class="card-header alert-primary" data-toggle="collapse" data-target="#vpFilterCollapse" aria-expanded="false">
            <div class="d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-funnel"></i> Filtry</h6>
                <i class="bi bi-chevron-down"></i>
            </div>
        </div>
        <div id="vpFilterCollapse" class="collapse alert-info">
            <div class="card-body p-3">

                <!-- Dostawca + Producent -->
                <div class="form-row mb-2">
                    <div class="col-md-5 col-12 mb-2">
                        <label class="small mb-1"><strong>Dostawca:</strong></label>
                        <select id="vpFilterVendors" class="selectpicker form-control form-control-sm"
                                data-width="100%"
                                data-live-search="true"
                                data-size="10"
                                data-container="body"
                                title="Wybierz dostawcę...">
                            <?= $selectRenderer->renderArraySelect($vendor_list) ?>
                        </select>
                    </div>
                    <div class="col-md-5 col-12 mb-2">
                        <label class="small mb-1"><strong>Producent:</strong></label>
                        <select id="vpFilterProducers" class="selectpicker form-control form-control-sm"
                                data-width="100%"
                                data-live-search="true"
                                data-size="10"
                                data-container="body"
                                title="Wybierz producenta...">
                            <?= $selectRenderer->renderArraySelect($producer_list) ?>
                        </select>
                    </div>
                    <div class="col-md-2 col-12 d-flex align-items-end">
                        <button type="button" id="vpClearVendorProducer"
                                class="btn btn-danger btn-sm btn-block mb-2">
                            Wyczyść
                        </button>
                    </div>
                </div>

                <!-- Część -->
                <div class="form-row mb-2">
                    <div class="col-md-10 col-12 mb-2">
                        <label class="small mb-1"><strong>Część:</strong></label>
                        <select id="vpFilterParts" class="selectpicker form-control form-control-sm"
                                data-live-search="true"
                                data-size="10"
                                data-container="body"
                                data-width="100%"
                                title="Wybierz część...">
                            <?= $selectRenderer->renderArraySelectWithSubtext($part_names, $part_descriptions) ?>
                        </select>
                    </div>
                    <div class="col-md-2 col-12 d-flex align-items-end">
                        <button type="button" id="vpClearPart"
                                class="btn btn-danger btn-sm btn-block mb-2">
                            Wyczyść
                        </button>
                    </div>
                </div>

                <!-- Status (segmented radio buttons) -->
                <div class="form-row mb-2">
                    <div class="col-md-10 col-12 mb-2">
                        <label class="small mb-1 d-block"><strong>Status:</strong></label>
                        <div class="btn-group btn-group-sm btn-group-toggle vp-status-group" data-toggle="buttons" role="group" aria-label="Status filtra">
                            <label class="btn btn-outline-primary" data-status="all">
                                <input type="radio" name="vpFilterStatus" value="all"> Wszystkie
                            </label>
                            <label class="btn btn-outline-success active" data-status="active">
                                <input type="radio" name="vpFilterStatus" value="active" checked> Aktywne
                            </label>
                            <label class="btn btn-outline-danger" data-status="inactive">
                                <input type="radio" name="vpFilterStatus" value="inactive"> Nieaktywne
                            </label>
                        </div>
                    </div>
                    <div class="col-md-2 col-12 d-flex align-items-end">
                        <button type="button" id="vpClearStatus"
                                class="btn btn-danger btn-sm btn-block mb-2">
                            Wyczyść
                        </button>
                    </div>
                </div>

                <!-- Szukaj -->
                <div class="form-row mb-2">
                    <div class="col-md-10 col-12 mb-2">
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
                    <div class="col-md-2 col-12 d-flex align-items-end">
                        <button type="button" id="vpClearSearch"
                                class="btn btn-danger btn-sm btn-block mb-2">
                            Wyczyść
                        </button>
                    </div>
                </div>

                <!-- Data dodania -->
                <div class="form-row">
                    <div class="col-md-5 col-12 mb-2">
                        <label class="small mb-1" for="vpFilterDateFrom"><strong>Data dodania — od:</strong></label>
                        <input type="date" id="vpFilterDateFrom" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-5 col-12 mb-2">
                        <label class="small mb-1" for="vpFilterDateTo"><strong>Data dodania — do:</strong></label>
                        <input type="date" id="vpFilterDateTo" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-2 col-12 d-flex align-items-end">
                        <button type="button" id="vpClearDates"
                                class="btn btn-danger btn-sm btn-block mb-2">
                            Wyczyść daty
                        </button>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <!-- Pagination top -->
    <div id="vpPaginationTop" class="vp-pagination"></div>

    <!-- Loading spinner (centered, hidden by default) -->
    <div class="d-flex justify-content-center my-2">
        <div id="vpSpinner" class="spinner-border text-primary vp-spinner" role="status" hidden>
            <span class="sr-only">Ładowanie...</span>
        </div>
    </div>

    <!-- Table -->
    <div class="table-responsive mt-3">
        <table class="table table-hover" id="vpTable">
            <thead class="thead-light">
                <tr>
                    <th class="vp-col-id text-center" style="width: 70px;">ID</th>
                    <th>Artykuł</th>
                    <th>
                        Dostawca
                        <small class="d-block text-muted">Producent</small>
                    </th>
                    <th>JM</th>
                    <th>Opak.</th>
                    <th style="min-width: 180px;">Komentarz</th>
                    <th style="width: 170px;">Akcje</th>
                </tr>
            </thead>
            <tbody id="vpTableBody">
                <tr>
                    <td colspan="7" class="text-center text-muted py-4">
                        Ładowanie…
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Pagination bottom -->
    <div id="vpPaginationBottom" class="vp-pagination mt-3"></div>
</div>

<script src="<?= asset('public_html/components/Admin/Purchase/VendorParts/vendor-parts-view.js') ?>"></script>