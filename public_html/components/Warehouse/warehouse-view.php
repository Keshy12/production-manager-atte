<?php
use Atte\DB\MsaDB;
use Atte\Utils\ComponentRenderer\SelectRenderer;
include('warehouse-table-item-template.php');

$MsaDB = MsaDB::getInstance();

$selectRenderer = new SelectRenderer($MsaDB);
?>

<select id="list__sku" hidden><!--sku list-->
    <?= $selectRenderer -> renderSKUSelect() ?>
</select>
<select id="list__tht" hidden><!--tht list-->
    <?= $selectRenderer -> renderTHTSelect() ?>
</select>
<select id="list__smd" hidden><!--smd list-->
    <?= $selectRenderer -> renderSMDSelect() ?>
</select>
<select id="list__parts" hidden><!--parts list-->
    <?= $selectRenderer -> renderPartsSelect() ?>
</select>

<div class="modal fade" id="correctMagazineModal" tabindex="-1" role="dialog">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Koryguj magazyn</h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div id="correctMagazineInputGroup" class="modal-body">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Zamknij</button>
                <button class="btn btn-primary" id="correctMagazineSubmit" type="button"
                    data-dismiss="modal">Zapisz</button>
            </div>
        </div>
    </div>
</div>

<div class="d-flex flex-column align-items-center justify-content-center mt-4">
    <div class="d-flex w-75">
        <div class="btn-group" role="group">
            <button type="button" value="sku" class="magazineoption btn btn-outline-secondary">SKU</button>
            <button type="button" value="tht" class="magazineoption btn btn-outline-secondary">THT</button>
            <button type="button" value="smd" class="magazineoption btn btn-outline-secondary">SMD</button>
            <button type="button" value="parts" class="magazineoption btn btn-secondary">Parts</button>
        </div>
        <select id="magazinecomponent" 
                data-title="Typ:" 
                data-width="10%" 
                class="form-control selectpicker">
            <option value="sku">SKU</option>
            <option value="tht">THT</option>
            <option value="smd">SMD</option>
            <option value="parts" selected>Parts</option>
        </select>
        <select id="list__components" 
                data-title="Urządzenie:" 
                data-width="90%" data-live-search="true"
                data-actions-box="true" 
                data-selected-text-format="count > 3"
                class="form-control selectpicker" 
                multiple>
            <?= $selectRenderer -> renderPartsSelect() ?>
        </select>
    </div>
    <div class="d-flex justify-content-center mt-4">
        <button id="previouspage" class="btn btn-light" disabled><b>&lsaquo;</b></button>
        <button id="currentpage" class="btn btn-light" disabled>1</button>
        <button id="nextpage" class="btn btn-light" disabled><b>&rsaquo;</b></button>
    </div>
    <div class="w-75" id="container">
        <table class="table mt-4 table-striped text-center text-nowrap">
            <thead class="thead-light">
                <tr>
                    <th style="width: 30%" scope="col">Komponent</th>
                    <th style="width: 20%" scope="col">Magazyny główne</th>
                    <th style="width: 20%" scope="col">Magazyny zewnętrzne</th>
                    <th style="width: 10%" scope="col">Suma</th>
                </tr>
            </thead>
            <tbody id="warehouseTable">

            </tbody>
        </table>
    </div>
</div>

<script src="<?= asset('public_html/components/warehouse/warehouse-view.js') ?>"></script>
