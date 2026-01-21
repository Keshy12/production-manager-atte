<?php
use Atte\DB\MsaDB;

$MsaDB = MsaDB::getInstance();

$queryResult = $MsaDB -> query("SELECT * FROM `ref__timestamp` WHERE `id` = 3", PDO::FETCH_ASSOC);
$timestamp = $queryResult[0]['last_timestamp'];
$lastReadCell = (int)$queryResult[0]['params'] - 1;

include('table-row-template.php');
?>

<div class="d-flex justify-content-center mt-4">
    <div class="card my-2">
        <div class="card-header text-center">
            Data ostatniej aktualizacji:
        </div>
        <ul class="list-group list-group-flush text-center">
            <li class="list-group-item">
                <?= $timestamp ?>
            </li>
        </ul>
    </div>
    <div class="card my-2">
        <div class="card-header text-center">
            Ostatnia zaimportowana komórka:
        </div>
        <ul class="list-group list-group-flush text-center">
            <li class="list-group-item" id="lastFoundCell">
                <?= $lastReadCell ?>
            </li>
        </ul>
    </div>
</div>

<div class="d-flex justify-content-center">
    <button id="downloadOrders" class="btn btn-secondary my-2 mx-2">Pobierz zamówienia</button>
    <button id="importOrders" class="btn btn-primary my-2 mx-2" disabled>Zaimportuj zamówienia</button>
</div>

<div class="container-fluid">
    <div class="row justify-content-center">
        <div class="col-xl-8 col-lg-10 col-12">

            <!-- Filter Card -->
            <div class="card mt-4 mb-3">
                <div class="card-header alert-primary" style="cursor: pointer;" data-toggle="collapse" data-target="#filterCollapse">
                    <div class="d-flex justify-content-between align-items-center">
                        <h6 class="mb-0"><i class="bi bi-funnel"></i> Filtry</h6>
                        <i class="bi bi-chevron-down"></i>
                    </div>
                </div>
                <div id="filterCollapse" class="collapse">
                    <div class="card-body p-3">

                        <div class="form-row mb-2">
                            <div class="col-md-6 col-12 mb-2">
                                <label class="small mb-1"><strong>GRN ID:</strong></label>
                                <select id="filterGrnId" data-title="Wybierz GRN" class="selectpicker form-control form-control-sm" data-width="100%" data-live-search="true">
                                    <option value="">Wszystkie</option>
                                </select>
                            </div>
                            <div class="col-md-6 col-12 mb-2">
                                <label class="small mb-1"><strong>PO ID:</strong></label>
                                <select id="filterPoId" data-title="Wybierz PO" class="selectpicker form-control form-control-sm" data-width="100%" data-live-search="true">
                                    <option value="">Wszystkie</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-row mb-2">
                            <div class="col-md-12 col-12 mb-2">
                                <label class="small mb-1"><strong>Nazwa części:</strong></label>
                                <select id="filterPartName" data-title="Wybierz części" class="selectpicker form-control form-control-sm" data-width="100%" multiple data-live-search="true" data-actions-box="true">
                                </select>
                            </div>
                        </div>

                        <div class="form-row mb-2">
                            <div class="col-md-5 col-12 mb-2">
                                <label class="small mb-1"><strong>Data od:</strong></label>
                                <input type="date" class="form-control form-control-sm" id="filterDateFrom">
                            </div>
                            <div class="col-md-5 col-12 mb-2">
                                <label class="small mb-1"><strong>Data do:</strong></label>
                                <input type="date" class="form-control form-control-sm" id="filterDateTo">
                            </div>
                            <div class="col-md-2 col-12 d-flex align-items-end">
                                <button type="button" id="clearFilters" class="btn btn-danger btn-sm btn-block mb-2">
                                    Wyczyść
                                </button>
                            </div>
                        </div>

                    </div>
                </div>
            </div>

            <!-- Stats Bar -->
            <div class="card mb-3" id="statsBar" style="display: none;">
                <div class="card-body p-3">
                    <div class="row text-center">
                        <div class="col-4">
                            <div class="d-flex flex-column align-items-center">
                                <h4 class="text-primary mb-1" id="statTotal">0</h4>
                                <small class="text-muted">Wszystkich</small>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="d-flex flex-column align-items-center">
                                <h4 class="text-warning mb-1" id="statMissing">0</h4>
                                <small class="text-muted">Brakujące części</small>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="d-flex flex-column align-items-center">
                                <h4 class="text-success mb-1" id="statReady">0</h4>
                                <small class="text-muted">Gotowe do importu</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<div class="d-flex justify-content-center my-2">
    <div class="alert alert-danger w-75" id="missingPartsAlert" style="display: none;" role="alert">
        Uwaga! Pobranie danych jest niemożliwe. Część z wykrytych parts nie istnieje w bazie danych.
        <br> <b id="missingParts"></b> 
        <br> <a href="http://<?=BASEURL?>/admin/components/detect-new-parts">Proszę dodać brakujące parts do bazy danych.</a>
    </div>
    <div class="alert alert-danger w-75" id="errorAlert" style="display: none;" role="alert"></div>
    <div class="alert alert-success w-75" id="successAlert" style="display: none;" role="alert"></div>
</div>

<!-- Top Pagination -->
<div class="d-flex justify-content-center my-3">
    <div id="paginationTop"></div>
</div>

<div class="d-flex justify-content-center mt-2">
    <table class="table mt-4 table-striped w-75">
        <thead class="thead-light">
            <tr>
                <th scope="col">GRN ID</th>
                <th scope="col">PO ID</th>
                <th scope="col">Part</th>
                <th scope="col">Qty</th>
                <th scope="col">Vendor JM</th>
            </tr>
        </thead>
        <tbody id="fromOrdersTBody">
        </tbody>
    </table>
</div>

<!-- Bottom Pagination -->
<div class="d-flex justify-content-center my-3">
    <div id="paginationBottom"></div>
</div>

<div class="d-flex justify-content-center mt-2">
    <span class="mt-1 spinnerFromOrders" style="display: none;">Ładowanie zamówień...</span>
    <div class="spinner-border mt-1 spinnerFromOrders" role="status" style="display: none;"></div>
</div>
<div class="d-flex justify-content-center">
    <small class="text-center spinnerFromOrders" style="display: none;">
        Czasem pobieranie danych z Google Spreadsheet nie działa za pierwszym razem.
        Jeśli czekasz już długo, odśwież stronę.
    </small>
</div>

<!-- Loading Overlay -->
<div id="loadingOverlay" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.6); z-index: 9999; justify-content: center; align-items: center;">
    <div class="text-center">
        <div class="spinner-border text-light" role="status" style="width: 4rem; height: 4rem;">
            <span class="sr-only">Ładowanie...</span>
        </div>
        <div class="text-light mt-3">
            <h5>Ładowanie zamówień...</h5>
            <p>Proszę czekać</p>
        </div>
    </div>
</div>

<?php include('modals.php'); ?>

<script src="<?= asset('public_html/components/admin/components/fromorders/from-orders-renderer.js') ?>"></script>
<script src="<?= asset('public_html/components/admin/components/fromorders/from-orders-view.js') ?>"></script>
