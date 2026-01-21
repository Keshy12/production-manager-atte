<?php
use Atte\DB\MsaDB;
use Atte\Utils\ComponentRenderer\SelectRenderer;

$MsaDB = MsaDB::getInstance();

$selectRenderer = new SelectRenderer($MsaDB);

include('table-row-template.php');
include('modals.php');  

?>

<select id="list__sku_hidden" hidden>
    <?= $selectRenderer->renderSKUBomSelect(isLeftJoin: true) ?>
</select>
<select id="list__tht_hidden" hidden>
    <?= $selectRenderer->renderTHTBomSelect(isLeftJoin: true) ?>
</select>
<select id="list__smd_hidden" hidden>
    <?= $selectRenderer->renderSMDBomSelect() ?>
</select>
<select id="list__parts_hidden" hidden>
    <?= $selectRenderer->renderPartsSelect() ?>
</select>

<div class="d-flex justify-content-center">
    <div id="ajaxResult" class="my-4 position-fixed" 
        style="z-index: 100; 
        max-width: 75%;">
    </div>
</div>

<div class="d-flex justify-content-center mt-4">
    <select id="bomTypeSelect" data-title="Wybierz typ..." class="selectpicker">
        <option value="sku">BOM_SKU</option>
        <option value="tht">BOM_THT</option>
        <option value="smd">BOM_SMD</option>
    </select>
</div>

<div class="d-flex justify-content-center mt-4">
    <button id="previousBom" class="btn btn-light mx-2" disabled><b>&lsaquo;</b></button>
    <select id="list__device" data-show-subtext="true" data-title="Wybierz komponent..."
            data-width="500px" class="selectpicker" data-live-search="true" disabled>
    </select>
    <button id="nextBom" class="btn btn-light mx-2" disabled><b>&rsaquo;</b></button>
</div>

<div class="d-flex justify-content-center">
    <span id="laminateField" class="mt-4" style="display:none;">
        <select id="laminateSelect" data-width="100px" 
                data-title="Laminat..." class="selectpicker" disabled>
        </select>
    </span>
    <span id="versionField" class="mt-4" style="display:none;">
        <select id="versionSelect" data-width="100px" 
                data-title="Wersja..." class="selectpicker" disabled>
        </select>
    </span>
</div>

<div class="d-flex justify-content-center">
    <button id="createNewBomFields" class="btn btn-outline-secondary mt-4" style="display:none;">
        Dodaj nową pozycję
    </button>
</div>

<div class="d-flex justify-content-center">
    <span class="mt-4" id="isActiveField" style="display:none;">
        <input type="checkbox" id="isActive">
        <label for="isActive">Aktywny?</label>
    </span>
</div>

<div class="d-flex justify-content-center mt-2" id="alerts"></div>

<div class="d-flex justify-content-center mt-4">
    <table class="table table-bordered table-sm text-center" style="max-width: 800px;">
        <thead>
            <tr>
                <th style="width:70%" id="valuePackageCol" scope="col">Komponent</th>
                <th style="width:15%" id="componentCol" scope="col">Ilość</th>
                <th style="width:15%" id="editButtonsCol" scope="col"></th>
            </tr>
        </thead>
        <tbody id="editBomTBody">
        </tbody>
    </table>
</div>
<script src="<?= asset('public_html/components/admin/bom/edit/edit-bom-view.js') ?>"></script>

