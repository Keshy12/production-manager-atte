<?php
if (!isset($_SESSION['userid'])) {
    header('Location: http://' . BASEURL . '/login');
    exit;
}

if (!isset($_SESSION['isAdmin']) || !$_SESSION['isAdmin']) {
    header('Location: http://' . BASEURL . '/unauthorized');
    exit;
}
?>
<div class="container mt-4">
    <h1 class="mb-4">
        <i class="bi bi-file-earmark-spreadsheet mr-2"></i>Flowpin - Arkusze
    </h1>

    <ul class="nav nav-tabs mb-4" id="sheetsTabs" role="tablist">
        <li class="nav-item">
            <a class="nav-link active" id="integration-tab" data-toggle="tab" href="#integration" role="tab">Integracja z Google Sheets</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" id="update-prices-tab" data-toggle="tab" href="#update-prices" role="tab">Aktualizacja Cen</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" id="import-orders-tab" data-toggle="tab" href="#import-orders" role="tab">Import Zamówień</a>
        </li>
    </ul>

    <div class="tab-content" id="sheetsTabContent">
        <div class="tab-pane fade show active" id="integration" role="tabpanel">
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="bi bi-google"></i> Integracja z Google Sheets</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <div class="card h-100">
                                <div class="card-body">
                                    <h6 class="card-title">Magazyn</h6>
                                    <button class="btn btn-sm btn-primary btn-block mb-2" id="sendWarehousesToGS">
                                        <i class="bi bi-box mr-1"></i>Wyślij Stan Magazynowy
                                    </button>
                                    <small class="text-muted">
                                        <i class="bi bi-clock mr-1"></i>Ostatnia aktualizacja:
                                        <br><b><span id="GSWarehouseDate">Brak danych</span></b>
                                    </small>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-3 mb-3">
                            <div class="card h-100">
                                <div class="card-body">
                                    <h6 class="card-title">BOM</h6>
                                    <button class="btn btn-sm btn-primary btn-block mb-2" id="sendBomFlatToGS">
                                        <i class="bi bi-file-earmark-text mr-1"></i>BOM_FLAT
                                    </button>
                                    <button class="btn btn-sm btn-primary btn-block mb-2" id="sendBomFlatSkuToGS">
                                        <i class="bi bi-upc mr-1"></i>BOM_FLAT_SKU
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-3 mb-3">
                            <div class="card h-100">
                                <div class="card-body">
                                    <h6 class="card-title">Analiza</h6>
                                    <button class="btn btn-sm btn-info btn-block mb-2" id="sendWarehouseComparisonToGS">
                                        <i class="bi bi-bar-chart mr-1"></i>Porównanie Stanów
                                    </button>
                                    <small class="text-muted">
                                        Analiza różnic między systemami
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="update-prices" role="tabpanel">
            <div class="container mt-5">
                <div class="row justify-content-center">
                    <div class="col-md-8 text-center">
                        <h1 class="mb-4">Aktualizacja cen komponentów</h1>
                        <p class="text-muted mb-4">
                            Ta funkcja pobiera najnowsze ceny zakupu części z arkusza Google Sheets (ceny_tmp). 
                            Wszystkie powiązane urządzenia (SMD, THT, SKU) zostaną automatycznie przeliczone, 
                            jeśli cena którejkolwiek z ich części składowych ulegnie zmianie.
                        </p>
                        
                        <div id="ajaxResult" class="mb-4"></div>
                        
                        <div class="card shadow-sm">
                            <div class="card-body py-5">
                                <button id="startSync" class="btn btn-primary btn-lg px-5">
                                    <i class="bi bi-arrow-repeat mr-2"></i> Rozpocznij synchronizację
                                </button>
                                <div id="syncLoader" style="display:none;" class="mt-4">
                                    <div class="spinner-border text-primary" role="status">
                                        <span class="sr-only">Ładowanie...</span>
                                    </div>
                                    <p class="mt-2 text-primary font-weight-bold">Synchronizowanie cen... Proszę czekać.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="import-orders" role="tabpanel">
            <?php
            use Atte\DB\MsaDB;

            $MsaDB = MsaDB::getInstance();

            $queryResult = $MsaDB->query("SELECT * FROM `ref__timestamp` WHERE `id` = 3", PDO::FETCH_ASSOC);
            $timestamp = $queryResult[0]['last_timestamp'];
            $lastReadCell = (int)$queryResult[0]['params'] - 1;
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
        </div>
    </div>
</div>

<div class="modal fade" id="importOrderModal" tabindex="-1" role="dialog" aria-labelledby="importOrderModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="importOrderModalLabel">Importuj zamówienie</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Zamknij">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body" id="importOrderModalBody">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Anuluj</button>
                <button type="button" class="btn btn-primary" id="confirmImport">Importuj</button>
            </div>
        </div>
    </div>
</div>

<script>
    function postData(url, data) {
        return fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: new URLSearchParams(data)
        });
    }
</script>
<script src="<?= asset('public_html/components/admin/components/updateprices/update-prices-view.js') ?>"></script>
<script src="<?= asset('public_html/components/admin/components/fromorders/from-orders-view.js') ?>"></script>
