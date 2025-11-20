<?php
use Atte\DB\MsaDB;
use Atte\Utils\ComponentRenderer\SelectRenderer;
use Atte\Utils\ComponentRenderer\PaginationRenderer;

$list__state = [
    'active' => 'Aktywne',
    'completed' => 'Ukończone',
    'returned' => 'Zwrócone',
    'cancelled' => 'Anulowane'
];

$list__priority = [
    'critical' => 'Krytyczny',
    'urgent' => 'Pilny',
    'standard' => 'Standardowy',
    'none' => 'Brak'
];

include('modals.php');
include('commissions-card-template.php');

$MsaDB = MsaDB::getInstance();

$selectRenderer = new SelectRenderer($MsaDB);

$users_name = $MsaDB -> readIdName('user', 'user_id', 'name');
$users_surname = $MsaDB -> readIdName('user', 'user_id', 'surname');
$users_submag = $MsaDB -> readIdName('user', 'user_id', 'sub_magazine_id');
$currentUser = isset($_SESSION["userid"]) ? $_SESSION["userid"] : "";

$submagazine_list = $MsaDB -> readIdName("magazine__list", "sub_magazine_id", "sub_magazine_name", "ORDER BY type_id ASC");

$currentPage = 1;
$totalItems = 0;
$itemsPerPage = 20;

$paginationRenderer = new PaginationRenderer($currentPage, $totalItems, $itemsPerPage, [
    'useAjax' => true,
    'size' => 'sm',
    'maxVisiblePages' => 5,
    'showFirstLast' => true,
    'showItemsInfo' => true,
    'showPageSelect' => true,
    'buttonClass' => 'btn-outline-primary',
    'activeButtonClass' => 'btn-primary'
]);

?>

<div class="d-flex justify-content-center">
    <div id="ajaxResult" class="my-4 position-fixed" style="z-index: 100; max-width: 75%;"></div>
</div>

<select id="list__sku" hidden>
    <?= $selectRenderer -> renderSKUBomSelect() ?>
</select>
<select id="list__tht" hidden>
    <?= $selectRenderer -> renderTHTBomSelect() ?>
</select>
<select id="list__smd" hidden>
    <?= $selectRenderer -> renderSMDBomSelect() ?>
</select>

<div class="container-fluid">
    <div class="row justify-content-center">
        <div class="col-xl-10 col-lg-11 col-12">

            <div class="card mt-4 mb-3">
                <div class="card-header alert-primary" style="cursor: pointer;" data-toggle="collapse" data-target="#filterCollapse">
                    <div class="d-flex justify-content-between align-items-center">
                        <h6 class="mb-0"><i class="bi bi-funnel"></i> Filtry</h6>
                        <i class="bi bi-chevron-down"></i>
                    </div>
                </div>
                <div id="filterCollapse" class="collapse alert-info">
                    <div class="card-body p-3">

                        <div class="form-row mb-2">
                            <div class="col-md-5 col-12 mb-2">
                                <label class="small mb-1"><strong>Zlecenie z:</strong></label>
                                <select id="transferFrom" data-title="Wybierz magazyn" class="selectpicker form-control form-control-sm" data-width="100%">
                                    <?= $selectRenderer -> renderArraySelect($submagazine_list) ?>
                                </select>
                            </div>
                            <div class="col-md-5 col-12 mb-2">
                                <label class="small mb-1"><strong>Zlecenie do:</strong></label>
                                <select id="transferTo" data-title="Wybierz magazyn" class="selectpicker form-control form-control-sm" data-width="100%">
                                    <?= $selectRenderer -> renderArraySelect($submagazine_list) ?>
                                </select>
                            </div>
                            <div class="col-md-2 col-12 d-flex align-items-end">
                                <button type="button" id="clearMagazine" class="btn btn-danger btn-sm btn-block mb-2">
                                    Wyczyść
                                </button>
                            </div>
                        </div>

                        <hr class="my-2">

                        <div class="form-row mb-2">
                            <div class="col-md-2 col-6 mb-2">
                                <label class="small mb-1"><strong>Typ:</strong></label>
                                <select id="type" data-title="Typ" class="selectpicker form-control form-control-sm" data-width="100%">
                                    <option value="sku">SKU</option>
                                    <option value="tht">THT</option>
                                    <option value="smd">SMD</option>
                                </select>
                            </div>
                            <div class="col-md-4 col-12 mb-2">
                                <label class="small mb-1"><strong>Urządzenie:</strong></label>
                                <select id="list__device" data-title="Wybierz urządzenie" data-live-search="true"
                                        class="selectpicker form-control form-control-sm" data-width="100%" disabled></select>
                            </div>
                            <div id="list__laminate" class="col-md-2 col-6 mb-2" style="display: none;">
                                <label class="small mb-1"><strong>Laminat:</strong></label>
                                <select id="laminate" data-title="Lam" class="selectpicker form-control form-control-sm" data-width="100%" disabled></select>
                            </div>
                            <div class="col-md-2 col-6 mb-2">
                                <label class="small mb-1"><strong>Wersja:</strong></label>
                                <select id="version" data-title="Ver" class="selectpicker form-control form-control-sm" data-width="100%" disabled></select>
                            </div>
                            <div class="col-md-2 col-12 d-flex align-items-end">
                                <button type="button" id="clearDevice" class="btn btn-danger btn-sm btn-block mb-2">
                                    Wyczyść
                                </button>
                            </div>
                        </div>

                        <hr class="my-2">

                        <div class="form-row mb-2">
                            <div class="col-md-4 col-12 mb-2">
                                <label class="small mb-1"><strong>Zlecono dla:</strong></label>
                                <select class="selectpicker form-control form-control-sm" id="user" title="Wybierz użytkowników" multiple
                                        data-selected-text-format="count > 2" data-actions-box="true"
                                        data-hide-disabled="true" data-width="100%">
                                    <?php foreach($users_name as $id => $name) {
                                        $surname = $users_surname[$id];
                                        $submag = $users_submag[$id];
                                        echo "<option data-submag-id=\"$submag\" value=\"$id\">".$name." ".$surname."</option>";
                                    }  ?>
                                </select>
                            </div>
                            <div class="col-md-4 col-12 mb-2">
                                <label class="small mb-1"><strong>Status:</strong></label>
                                <select class="selectpicker form-control form-control-sm" id="state" title="Wybierz status"
                                        data-selected-text-format="count > 1" multiple data-width="100%">
                                    <?= $selectRenderer -> renderArraySelect($list__state) ?>
                                </select>
                            </div>
                            <div class="col-md-4 col-12 mb-2">
                                <label class="small mb-1"><strong>Priorytet:</strong></label>
                                <select class="selectpicker form-control form-control-sm" id="priority" title="Wybierz priorytet"
                                        data-selected-text-format="count > 1" multiple data-width="100%">
                                    <?= $selectRenderer -> renderArraySelect($list__priority) ?>
                                </select>
                            </div>
                        </div>

                        <hr class="my-2">

                        <div class="form-row mb-2">
                            <div class="col-md-4 col-12 mb-2">
                                <label class="small mb-1"><strong>Data od:</strong></label>
                                <input type="date" class="form-control form-control-sm" id="dateFrom">
                            </div>
                            <div class="col-md-4 col-12 mb-2">
                                <label class="small mb-1"><strong>Data do:</strong></label>
                                <input type="date" class="form-control form-control-sm" id="dateTo">
                            </div>
                            <div class="col-md-4 col-12 d-flex align-items-end">
                                <button type="button" id="clearDates" class="btn btn-danger btn-sm btn-block mb-2">
                                    Wyczyść daty
                                </button>
                            </div>
                        </div>

                        <hr class="my-2">

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
                                    <input type="checkbox" class="custom-control-input" id="groupTogether">
                                    <label class="custom-control-label" for="groupTogether">
                                        Grupuj identyczne
                                    </label>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            </div>

            <div class="card mb-3" id="statsBar" style="display: none;">
                <div class="card-body p-3">
                    <div class="row text-center">
                        <div class="col-6 col-md-3 mb-2 mb-md-0">
                            <div class="d-flex flex-column align-items-center">
                                <h4 class="text-primary mb-1" id="statTotal">0</h4>
                                <small class="text-muted">Wszystkich</small>
                            </div>
                        </div>
                        <div class="col-6 col-md-3 mb-2 mb-md-0">
                            <div class="d-flex flex-column align-items-center">
                                <h4 class="text-success mb-1" id="statActive">0</h4>
                                <small class="text-muted">Aktywnych</small>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="d-flex flex-column align-items-center">
                                <h4 class="text-info mb-1" id="statCompleted">0</h4>
                                <small class="text-muted">Ukończonych</small>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="d-flex flex-column align-items-center">
                                <h4 class="text-warning mb-1" id="statReturned">0</h4>
                                <small class="text-muted">Zwróconych</small>
                            </div>
                        </div>
                    </div>
                    <div id="statsGrouped" class="row text-center mt-2 pt-2 border-top" style="display: none;">
                        <div class="col-12">
                            <div class="d-flex flex-column align-items-center">
                                <h5 class="text-secondary mb-1" id="statGrouped">0</h5>
                                <small class="text-muted">Zgrupowanych zleceń</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div id="paginationTop"></div>

            <div class="d-flex justify-content-center mb-3">
                <div style="display: none;" id="transferSpinner" class="spinner-border text-primary" role="status">
                    <span class="sr-only">Ładowanie...</span>
                </div>
            </div>

            <div class="d-flex flex-wrap justify-content-center" id="container"></div>

            <div id="paginationBottom" class="mt-3"></div>

        </div>
    </div>
</div>

<script>
    const PAGINATION_CONFIG = {
        itemsPerPage: <?= $itemsPerPage ?>,
        currentPage: 1,
        totalItems: 0
    };
</script>

<script src="http://<?=BASEURL?>/public_html/components/commissions/commissions-view-renderer.js"></script>
<script src="http://<?=BASEURL?>/public_html/components/commissions/commissions-view-main.js"></script>
<script src="http://<?=BASEURL?>/public_html/components/commissions/commissions-view-cancel.js"></script>