<?php
use Atte\DB\MsaDB;
use Atte\Utils\ComponentRenderer\SelectRenderer;

$MsaDB = MsaDB::getInstance();

$selectRenderer = new SelectRenderer($MsaDB);

$list__part_unit = $MsaDB -> readIdName("part__unit");
$list__part_type = $MsaDB -> readIdName("part__type");
$list__part_group = $MsaDB -> readIdName("part__group");
$list__laminate = $MsaDB -> readIdName("list__laminate");
$list__laminate_desc = $MsaDB -> readIdName("list__laminate", "id", "description");
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

<select id="list__laminate_hidden" hidden>
    <?= $selectRenderer->renderArraySelectWithSubtext($list__laminate, $list__laminate_desc) ?>
</select>
<select id="list__parts_hidden" hidden>
    <?= $selectRenderer->renderPartsSelect() ?>
</select>

<div class="d-flex justify-content-center">
    <div id="ajaxResult" class="mt-4 position-fixed" 
    style="z-index: 100; 
    max-width: 75%;">
    </div>
</div>

<div class="d-flex justify-content-center my-4">
    <select id="deviceType" class="selectpicker" data-title="Wybierz typ...">
        <option value="sku">SKU</option>
        <option value="tht">THT</option>
        <option value="smd">SMD</option>
        <option value="laminate">Laminaty</option>
        <option value="parts">Parts</option>
    </select>
</div>


<div class="d-flex justify-content-center my-4">
    <button id="previousItem" class="btn btn-light mx-2" disabled><b>&lsaquo;</b></button>
    <select id="list__device" data-show-subtext="true" data-title="Wybierz komponent..."
            data-width="500px" class="selectpicker" data-live-search="true" disabled>
    </select>
    <button id="deselect" class="btn btn-light mx-2" disabled>&times;</button>
    <button id="nextItem" class="btn btn-light" disabled><b>&rsaquo;</b></button>
</div>


<div id="componentFormContainer" class="container mt-4" style="display: none; max-width: 800px;">
    <h1 id="header" class="text-center">Dodaj komponent</h1>
    <div id="cloneField" class="text-center">
        <button id="cloneDevice" class="btn btn-primary">Klonuj</button>
        <div id="cloneSelect" style="display: none;">
            <select id="list__device_to_clone" data-show-subtext="true" data-title="Wybierz komponent do sklonowania..."
                    data-width="500px" class="selectpicker" data-live-search="true">
            </select>
            <button id="hideCloneSelect" class="btn btn-light mx-2">&times;</button>
        </div>
    </div>
    <form id="componentForm" method="post" enctype="multipart/form-data" 
            action="http://<?=BASEURL?>/public_html/components/admin/components/edit/edit-component.php">
        <div id="isActiveField" style="display: none;" class="form-check text-center mx-2">
            <input class="form-check-input" type="checkbox" name="isActive" id="isActiveCheckbox">
            <label class="form-check-label" for="isActiveCheckbox">
                Aktywny?
            </label>
        </div>
        <div class="form-group">
            <label>Nazwa</label>
            <input type="text" id="name" name="name" class="form-control" required>
        </div>
        <div class="form-group">
            <label>Opis</label>
            <textarea class="form-control" id="description" name="description" rows="3"></textarea>
        </div>
        <div id="priceField" style="display: none;" class="form-group">
            <label id="priceLabel">Cena za sztukę (PLN) - TYLKO DO ODCZYTU</label>
            <input type="number" step="0.01" min="0" id="price" name="price" class="form-control" readonly>
        </div>
        <div id="defaultBomField" style="display: none;" class="form-group">
            <label>Domyślna wersja BOM (do analizy cen)</label>
            <div class="d-flex">
                <span id="defaultLaminateField" style="display:none;" class="mr-2">
                    <select id="defaultLaminateSelect" data-width="200px" 
                            data-title="Laminat..." class="selectpicker">
                    </select>
                </span>
                <span id="defaultVersionField" style="display:none;">
                    <select id="defaultVersionSelect" data-width="200px" 
                            data-title="Wersja..." class="selectpicker">
                    </select>
                </span>
            </div>
            <input type="hidden" id="defaultBomId" name="defaultBomId">
        </div>


        <div id="thtAdditionalFields" style="display: none;" class="text-center">
            <div class="d-inline">
                <label for="circleCheckbox"><img style="max-width: 100px;" class="img-fluid m-1" src="/atte_ms_new/public_html/assets/img/production/tht/marking/marking1.png"></label>
                <input class="form-check-input" type="checkbox" name="marking[]" value="circle_checked" id="circleCheckbox">
                <label for="triangleCheckbox"><img style="max-width: 100px;" class="img-fluid m-1" src="/atte_ms_new/public_html/assets/img/production/tht/marking/marking2.png"></label>
                <input class="form-check-input" type="checkbox" name="marking[]" value="triangle_checked" id="triangleCheckbox">
                <label for="squareCheckbox"><img style="max-width: 100px;" class="img-fluid m-1" src="/atte_ms_new/public_html/assets/img/production/tht/marking/marking3.png"></label>
                <input class="form-check-input" type="checkbox" name="marking[]" value="square_checked" id="squareCheckbox">
            </div>
        </div>
        <div class="mt-2 text-center" id="autoProduceFields" style="">
            <div class="d-inline justify-content-center">
                <input class="form-check-input" type="checkbox" name="autoProduce" value="autoProduce" id="autoProduceCheckbox">
                <label for="autoProduceCheckbox">Automatycznie produkuj w przypadku braku na magazynie</label>
            </div>
            <br>
            <span id="autoProduceVersionField" class="mt-4" style="display: none;">
                <select id="autoProduceVersionSelect" data-width="100px"
                        data-title="Wersja..." class="selectpicker" name="autoProduceVersion">
                </select>
            </span>
        </div>
        <div id="partsAdditionalFields" style="display: none;">
            <div class="d-flex justify-content-center form-inline">
                <label for="partgroup">Grupa:</label>
                <select class="selectpicker mx-2" data-title="Wybierz grupę" 
                        data-live-search="true" data-width="300px" data-style-base="form-control" 
                        data-style="" name="partGroup" id="partGroup">
                    <?php $selectRenderer -> renderArraySelect($list__part_group) ?>
                </select>
                <label for="parttype">Typ:</label>
                <select class="selectpicker mx-2" data-title="Wybierz typ" 
                        data-style-base="form-control" data-style="" 
                        name="partType" id="partType" data-width="100px">
                        <option value="0">Brak</option>
                    <?php $selectRenderer -> renderArraySelect($list__part_type) ?>
                </select>
                <label for="jm">Jednostka miary</label>
                <select class="selectpicker mx-2" data-title="Wybierz jednostkę" 
                        data-style-base="form-control" data-style=""
                        name="jm" id="jm" data-width="100px">
                    <?php $selectRenderer -> renderArraySelect($list__part_unit) ?>
                </select>
            </div>
        </div>
        <div class="image-upload d-flex align-items-center justify-content-center mt-4">
            <label for="file-input">
                <img id="deviceImage" class="border border-secondary" 
                        style="max-width: 500px;" 
                        src="<?= asset('public_html/assets/img/production/default.webp') ?>"/>
            </label>
            <input class="d-none" id="file-input" name="image" type="file" />
        </div>
        <div class="d-flex align-items-center justify-content-center">
            <button id="saveChange" class="btn btn-primary my-4 mr-2" style="display: none;" type="submit">Zapisz</button>
            <button id="addDevice" class="btn btn-primary my-4 mr-2" type="submit">Dodaj</button>
        </div>
    </form>
</div>

<script src="<?= asset('public_html/components/admin/components/edit/edit-component-view.js') ?>"></script>
