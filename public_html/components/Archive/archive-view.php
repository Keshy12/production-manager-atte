<?php
use Atte\DB\MsaDB;
use Atte\Utils\ComponentRenderer\SelectRenderer;

$MsaDB = MsaDB::getInstance();
$selectRenderer = new SelectRenderer($MsaDB);

$list__input_type = $MsaDB->readIdName("inventory__input_type");
$magazine_list = $MsaDB->readIdName("magazine__list", "sub_magazine_id", "sub_magazine_name", "ORDER BY type_id ASC");
?>

<style>
    .group-row {
        font-weight: bold;
        background-color: #f8f9fa;
    }
    .group-row[data-toggle="collapse"] {
        cursor: pointer;
    }
    .group-row[data-toggle="collapse"]:hover {
        background-color: #e9ecef;
    }
    .group-row .toggle-icon {
        transition: transform 0.2s;
        display: inline-block;
        font-size: 14px;
    }
    .group-row[aria-expanded="true"] .toggle-icon {
        transform: rotate(90deg);
    }
    .expanded-active {
        background-color: #e7f1ff !important;
        border-left: 4px solid #007bff;
    }
    .d-none-simple {
        display: none !important;
    }
    .selected-row {
        background-color: #ffcccc !important;
    }
    .indent-cell {
        padding-left: 30px !important;
    }
    .indent-cell-2 {
        padding-left: 60px !important;
    }
    /* Device row styling (Level 2) */
    .device-row {
        background-color: #f0f4f8;
        cursor: pointer;
    }
    .device-row:hover {
        background-color: #e3e9f0;
    }
    .device-row .toggle-icon-device {
        transition: transform 0.2s;
        display: inline-block;
        font-size: 12px;
        color: #6c757d;
    }
    .device-row[aria-expanded="true"] .toggle-icon-device {
        transform: rotate(90deg);
    }
    /* Detail row Level 2 styling */
    .detail-row-level-2 {
        background-color: #fff;
    }
    /* Qty breakdown in group header */
    .qty-breakdown {
        font-size: 0.85em;
    }
    .qty-breakdown-item {
        display: inline-block;
        margin-right: 10px;
        white-space: nowrap;
    }
    .badge-count {
        font-size: 0.85em;
        vertical-align: middle;
    }
    .badge-cancelled-partial {
        font-size: 0.75em;
        vertical-align: middle;
    }
    .badge-device-type {
        font-size: 0.75em;
        margin-right: 4px;
        vertical-align: middle;
    }
    .cancelled-row {
        background-color: #f8d7da !important;
        opacity: 0.8;
    }
    .cancelled-group {
        background-color: #f5c6cb !important;
        opacity: 0.85;
    }
    .cancelled-row .custom-control-input:disabled ~ .custom-control-label,
    .cancelled-group .custom-control-input:disabled ~ .custom-control-label {
        opacity: 0.5;
        cursor: not-allowed;
    }
    .cancelled-row .custom-control-input:disabled ~ .custom-control-label::before,
    .cancelled-group .custom-control-input:disabled ~ .custom-control-label::before {
        background-color: #e9ecef;
        border-color: #adb5bd;
        cursor: not-allowed;
    }
</style>

<div class="d-flex justify-content-center">
    <div id="ajaxResult" class="my-4 position-fixed" style="z-index: 100; max-width: 75%;"></div>
</div>

<!-- Hidden selects for device lists -->
<select id="list__sku" hidden>
    <?= $selectRenderer->renderSKUSelect() ?>
</select>
<select id="list__tht" hidden>
    <?= $selectRenderer->renderTHTSelect() ?>
</select>
<select id="list__smd" hidden>
    <?= $selectRenderer->renderSMDSelect() ?>
</select>
<select id="list__parts" hidden>
    <?= $selectRenderer->renderPartsSelect() ?>
</select>

<div class="container-fluid">
    <div class="row justify-content-center">
        <div class="col-xl-10 col-lg-11 col-12">

            <!-- Filter Card -->
            <div class="card mt-4 mb-3">
                <div class="card-header alert-primary" style="cursor: pointer;" data-toggle="collapse" data-target="#filterCollapse">
                    <div class="d-flex justify-content-between align-items-center">
                        <h6 class="mb-0"><i class="bi bi-funnel"></i> Filtry</h6>
                        <i class="bi bi-chevron-down"></i>
                    </div>
                </div>
                <div id="filterCollapse" class="collapse alert-info">
                    <div class="card-body p-3">

                        <!-- Device Type Selection -->
                        <div class="form-row mb-2">
                            <div class="col-md-3 col-12 mb-2">
                                <label class="small mb-1"><strong>Typ urządzenia:</strong></label>
                                <select class="selectpicker form-control form-control-sm" id="deviceType" data-width="100%" title="Wybierz typ...">
                                    <option value="all">Wszystkie</option>
                                    <option value="sku">SKU</option>
                                    <option value="tht">THT</option>
                                    <option value="smd">SMD</option>
                                    <option value="parts">Parts</option>
                                </select>
                            </div>
                            <div class="col-md-7 col-12 mb-2">
                                <label class="small mb-1"><strong>Urządzenie:</strong></label>
                                <select class="selectpicker form-control form-control-sm" id="list__device"
                                        title="Wybierz urządzenie..." data-live-search="true"
                                        data-selected-text-format="count > 2" data-actions-box="true"
                                        multiple data-width="100%" disabled>
                                </select>
                            </div>
                            <div class="col-md-2 col-12 d-flex align-items-end">
                                <button type="button" id="clearDevice" class="btn btn-danger btn-sm btn-block mb-2">
                                    Wyczyść
                                </button>
                            </div>
                        </div>

                        <hr class="my-2">

                        <!-- Magazine and User Filters -->
                        <div class="form-row mb-2">
                            <div class="col-md-5 col-12 mb-2">
                                <label class="small mb-1"><strong>Magazyn:</strong></label>
                                <select class="selectpicker form-control form-control-sm" id="magazine"
                                        title="Wybierz magazyn..." data-selected-text-format="count > 2"
                                        data-actions-box="true" multiple data-width="100%">
                                    <?= $selectRenderer->renderArraySelect($magazine_list) ?>
                                </select>
                            </div>
                            <div class="col-md-5 col-12 mb-2">
                                <label class="small mb-1"><strong>Użytkownik:</strong></label>
                                <select class="selectpicker form-control form-control-sm" id="user"
                                        title="Wybierz użytkownika..." multiple
                                        data-selected-text-format="count > 2" data-actions-box="true"
                                        data-width="100%">
                                    <?= $selectRenderer->renderUserSelect() ?>
                                </select>
                            </div>
                            <div class="col-md-2 col-12 d-flex align-items-end">
                                <button type="button" id="clearMagazineUser" class="btn btn-danger btn-sm btn-block mb-2">
                                    Wyczyść
                                </button>
                            </div>
                        </div>

                        <hr class="my-2">

                        <!-- Input Type Filter -->
                        <div class="form-row mb-2">
                            <div class="col-md-10 col-12 mb-2">
                                <label class="small mb-1"><strong>Typ operacji:</strong></label>
                                <select class="selectpicker form-control form-control-sm" id="input_type"
                                        title="Wybierz typ..." data-selected-text-format="count > 2"
                                        data-actions-box="true" multiple data-width="100%">
                                    <?= $selectRenderer->renderArraySelect($list__input_type) ?>
                                </select>
                            </div>
                            <div class="col-md-2 col-12 d-flex align-items-end">
                                <button type="button" id="clearInputType" class="btn btn-danger btn-sm btn-block mb-2">
                                    Wyczyść
                                </button>
                            </div>
                        </div>

                        <hr class="my-2">

                        <!-- FlowPin Update Session Filter -->
                        <div class="form-row mb-2">
                            <div class="col-md-10 col-12 mb-2">
                                <label class="small mb-1"><strong>FlowPin Update Session:</strong></label>
                                <select class="selectpicker form-control form-control-sm" id="flowpinSession"
                                        title="Wszystkie sesje..." data-live-search="true" data-width="100%">
                                    <?php
                                    // Fetch recent FlowPin update sessions
                                    $sessions = $MsaDB->query("
                                        SELECT id, session_id, started_at, created_transfer_count, created_group_count
                                        FROM ref__flowpin_update_progress
                                        WHERE status = 'completed' AND created_transfer_count > 0
                                        ORDER BY started_at DESC
                                        LIMIT 50
                                    ");
                                    foreach ($sessions as $session) {
                                        $label = htmlspecialchars($session['session_id']) .
                                                 ' (' . date('Y-m-d H:i', strtotime($session['started_at'])) . ')' .
                                                 ' - ' . $session['created_transfer_count'] . ' transfers, ' .
                                                 $session['created_group_count'] . ' groups';
                                        echo '<option value="' . $session['id'] . '">' . $label . '</option>';
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="col-md-2 col-12 d-flex align-items-end">
                                <button type="button" id="clearSessionFilter" class="btn btn-danger btn-sm btn-block mb-2">
                                    Wyczyść
                                </button>
                            </div>
                        </div>

                        <hr class="my-2">

                        <!-- Date Range Filter -->
                        <div class="form-row mb-2">
                            <div class="col-md-5 col-12 mb-2">
                                <label class="small mb-1"><strong>Data od:</strong></label>
                                <input type="date" class="form-control form-control-sm" id="dateFrom">
                            </div>
                            <div class="col-md-5 col-12 mb-2">
                                <label class="small mb-1"><strong>Data do:</strong></label>
                                <input type="date" class="form-control form-control-sm" id="dateTo">
                            </div>
                            <div class="col-md-2 col-12 d-flex align-items-end">
                                <button type="button" id="clearDates" class="btn btn-danger btn-sm btn-block mb-2">
                                    Wyczyść daty
                                </button>
                            </div>
                        </div>

                        <hr class="my-2">

                        <!-- Checkboxes -->
                        <div class="form-row">
                            <div class="col-md-6 col-12 mb-2 mb-md-0">
                                <div class="custom-control custom-checkbox">
                                    <input type="checkbox" class="custom-control-input" id="showCancelled">
                                    <label class="custom-control-label" for="showCancelled">
                                        Pokaż anulowane
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-6 col-12">
                                <div class="custom-control custom-checkbox">
                                    <input type="checkbox" class="custom-control-input" id="noGrouping">
                                    <label class="custom-control-label" for="noGrouping">
                                        Nie grupuj
                                    </label>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            </div>

            <!-- Pagination Top -->
            <div id="paginationTop"></div>

            <!-- Cancel Selected Button (Fixed Position) -->
            <button type="button" id="cancelSelectedBtn" class="btn btn-danger btn-lg" style="display: none; position: fixed; bottom: 30px; right: 30px; z-index: 1000; box-shadow: 0 4px 8px rgba(0,0,0,0.3);">
                <i class="bi bi-x-circle"></i> Anuluj zaznaczone
                <span class="badge badge-light ml-2">0</span>
            </button>

            <!-- Loading Spinner -->
            <div class="d-flex justify-content-center mb-3">
                <div style="display: none;" id="transferSpinner" class="spinner-border text-primary" role="status">
                    <span class="sr-only">Ładowanie...</span>
                </div>
            </div>

            <!-- Quick Controls -->
            <div class="card">
                <div class="card-body p-2">
                    <div class="row align-items-center">
                        <div class="col-md-3 col-12 mb-2 mb-md-0">
                            <label class="small mb-1"><strong>Typ urządzenia:</strong></label>
                            <select class="form-control form-control-sm" id="quickDeviceType">
                                <option value="">Wybierz typ...</option>
                                <option value="all">Wszystkie</option>
                                <option value="sku">SKU</option>
                                <option value="tht">THT</option>
                                <option value="smd">SMD</option>
                                <option value="parts">Parts</option>
                            </select>
                        </div>
                        <div class="col-md-9 col-12">
                            <div class="d-flex align-items-center justify-content-md-end">
                                <div class="custom-control custom-checkbox mr-3">
                                    <input type="checkbox" class="custom-control-input" id="quickShowCancelled">
                                    <label class="custom-control-label small" for="quickShowCancelled">
                                        Pokaż anulowane
                                    </label>
                                </div>
                                <div class="custom-control custom-checkbox">
                                    <input type="checkbox" class="custom-control-input" id="quickNoGrouping" checked>
                                    <label class="custom-control-label small" for="quickNoGrouping">
                                        Nie grupuj
                                    </label>
                                </div>
                                <button type="button" id="refreshArchive" class="btn btn-primary btn-sm ml-3">
                                    <i class="bi bi-arrow-clockwise"></i> Odśwież
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-footer py-1 small text-muted">
                    <i class="bi bi-info-circle"></i> Domyślnie wyświetlane są dane z ostatniego miesiąca (ze względu na optymalizację). Możesz zmienić zakres dat w filtrach.
                </div>
            </div>

            <!-- Archive Table -->
            <div class="d-flex justify-content-center">
                <table class="table w-100" id="archiveTable">
                    <thead class="thead-light">
                        <tr>
                            <th scope="col" style="width: 30px;" class="text-center">
                                <i class="bi bi-check-square" title="Zaznacz"></i>
                            </th>
                            <th scope="col">Użytkownik</th>
                            <th scope="col">Magazyn</th>
                            <th scope="col">Urządzenie</th>
                            <th scope="col">Typ operacji</th>
                            <th scope="col">Ilość</th>
                            <th scope="col">Data</th>
                            <th scope="col">Komentarz</th>
                        </tr>
                    </thead>
                    <tbody id="archiveTableBody">
                        <tr>
                            <td colspan="8" class="text-center text-muted">
                                Wybierz typ urządzenia aby wyświetlić historię transferów
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination Bottom -->
            <div id="paginationBottom" class="mt-3"></div>

        </div>
    </div>
</div>

<?php include_once 'modals.php'; ?>

<script src="<?= asset('public_html/components/archive/archive-view.js') ?>"></script>
